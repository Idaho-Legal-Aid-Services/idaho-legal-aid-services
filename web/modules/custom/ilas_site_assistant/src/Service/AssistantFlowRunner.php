<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Governs config-backed assistant flows that are still controller-rendered.
 */
class AssistantFlowRunner {

  /**
   * Legacy/default office follow-up TTL in seconds.
   */
  private const DEFAULT_TTL_SECONDS = 1800;

  /**
   * Legacy/default office follow-up turn budget.
   */
  private const DEFAULT_MAX_TURNS = 2;

  /**
   * Legacy/default trigger intent list.
   */
  private const DEFAULT_TRIGGER_INTENTS = ['apply'];

  /**
   * Constructs an AssistantFlowRunner.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly OfficeLocationResolver $officeLocationResolver,
    private readonly CacheBackendInterface $conversationCache,
  ) {}

  /**
   * Evaluates pending office follow-up state before normal routing continues.
   */
  public function evaluatePending(array $context): array {
    $conversation_id = (string) ($context['conversation_id'] ?? '');
    if ($conversation_id === '' || !$this->isOfficeFollowupEnabled()) {
      return $this->buildContinueDecision();
    }

    $followup_state = $this->loadOfficeFollowupState($conversation_id);
    if ($followup_state === NULL) {
      return $this->buildContinueDecision();
    }

    $user_message = (string) ($context['user_message'] ?? '');
    $office = $this->officeLocationResolver->resolve($user_message);
    $is_location_like = !empty($context['is_location_like']);
    $is_explicit_office_followup = !empty($context['is_explicit_office_followup']);

    if ($office !== NULL) {
      return $this->buildDecision(
        status: 'handled',
        action: 'resolve',
        state_operation: 'clear',
        extras: ['office' => $office],
      );
    }

    if ($is_location_like || $is_explicit_office_followup) {
      return $this->buildDecision(
        status: 'handled',
        action: 'clarify',
        state_operation: 'clear',
        extras: ['offices' => $this->officeLocationResolver->getAllOffices()],
      );
    }

    $followup_state['remaining_turns'] = max(0, ((int) ($followup_state['remaining_turns'] ?? 1)) - 1);
    if ((int) $followup_state['remaining_turns'] > 0) {
      return $this->buildDecision(
        status: 'continue',
        state_operation: 'save',
        state_payload: $followup_state,
      );
    }

    return $this->buildDecision(
      status: 'continue',
      state_operation: 'clear',
    );
  }

  /**
   * Evaluates whether the latest response should arm office follow-up state.
   */
  public function evaluatePostResponse(array $context): array {
    $conversation_id = (string) ($context['conversation_id'] ?? '');
    if ($conversation_id === '' || !$this->isOfficeFollowupEnabled()) {
      return $this->buildContinueDecision();
    }

    $normalized_intent = (string) ($context['intent_type'] ?? '');
    $flow_config = $this->getOfficeFollowupConfig();
    if ($normalized_intent === '' || !in_array($normalized_intent, $flow_config['trigger_intents'], TRUE)) {
      return $this->buildContinueDecision();
    }

    $has_followup_prompt = !empty($context['has_followup_prompt']);
    if ($flow_config['require_followup_prompt'] && !$has_followup_prompt) {
      return $this->buildContinueDecision();
    }

    return $this->buildDecision(
      status: 'continue',
      action: 'arm',
      state_operation: 'save',
      state_payload: [
        'type' => 'office_location',
        'origin_intent' => $normalized_intent,
        'remaining_turns' => $flow_config['max_turns'],
        'created_at' => time(),
      ],
    );
  }

  /**
   * Loads pending office follow-up state for a conversation.
   */
  public function loadOfficeFollowupState(string $conversation_id): ?array {
    $cached = $this->conversationCache->get('ilas_conv_followup:' . $conversation_id);
    if (!$cached || !is_array($cached->data)) {
      return NULL;
    }

    $data = $cached->data;
    if (($data['type'] ?? '') !== 'office_location') {
      return NULL;
    }

    $created_at = (int) ($data['created_at'] ?? $data['timestamp'] ?? 0);
    $remaining_turns = (int) ($data['remaining_turns'] ?? 1);
    if ($created_at <= 0) {
      $this->clearOfficeFollowupState($conversation_id);
      return NULL;
    }

    if ((time() - $created_at) > $this->getOfficeFollowupConfig()['ttl_seconds'] || $remaining_turns <= 0) {
      $this->clearOfficeFollowupState($conversation_id);
      return NULL;
    }

    return [
      'type' => 'office_location',
      'origin_intent' => $data['origin_intent'] ?? 'apply',
      'remaining_turns' => $remaining_turns,
      'created_at' => $created_at,
    ];
  }

  /**
   * Persists office follow-up state with config-backed lifecycle metadata.
   */
  public function saveOfficeFollowupState(string $conversation_id, array $state): void {
    $flow_config = $this->getOfficeFollowupConfig();
    $created_at = (int) ($state['created_at'] ?? time());
    if ($created_at <= 0) {
      $created_at = time();
    }

    $payload = [
      'type' => 'office_location',
      'origin_intent' => $state['origin_intent'] ?? 'apply',
      'remaining_turns' => max(0, (int) ($state['remaining_turns'] ?? $flow_config['max_turns'])),
      'created_at' => $created_at,
    ];

    $this->conversationCache->set(
      'ilas_conv_followup:' . $conversation_id,
      $payload,
      $created_at + $flow_config['ttl_seconds'],
    );
  }

  /**
   * Clears pending office follow-up state for a conversation.
   */
  public function clearOfficeFollowupState(string $conversation_id): void {
    $this->conversationCache->delete('ilas_conv_followup:' . $conversation_id);
  }

  /**
   * Exposes the shared office resolver for future slices.
   */
  public function getOfficeLocationResolver(): OfficeLocationResolver {
    return $this->officeLocationResolver;
  }

  /**
   * Returns TRUE when the office follow-up flow is enabled.
   */
  private function isOfficeFollowupEnabled(): bool {
    $config = $this->getOfficeFollowupConfig();
    return $config['flows_enabled'] && $config['enabled'];
  }

  /**
   * Returns the normalized office follow-up flow config.
   *
   * @return array{
   *   flows_enabled: bool,
   *   enabled: bool,
   *   trigger_intents: array<int, string>,
   *   require_followup_prompt: bool,
   *   max_turns: int,
   *   ttl_seconds: int
   * }
   *   Normalized flow config.
   */
  private function getOfficeFollowupConfig(): array {
    $config = $this->configFactory->get('ilas_site_assistant.settings');

    $flows_enabled = $config->get('flows.enabled');
    $flow_enabled = $config->get('flows.office_followup.enabled');
    $trigger_intents = $config->get('flows.office_followup.trigger_intents');
    $require_followup_prompt = $config->get('flows.office_followup.require_followup_prompt');
    $max_turns = $config->get('flows.office_followup.max_turns');
    $ttl_seconds = $config->get('flows.office_followup.ttl_seconds');

    $trigger_intents = is_array($trigger_intents) && $trigger_intents !== []
      ? array_values(array_filter(array_map('strval', $trigger_intents), static fn (string $intent): bool => $intent !== ''))
      : self::DEFAULT_TRIGGER_INTENTS;

    return [
      'flows_enabled' => is_bool($flows_enabled) ? $flows_enabled : TRUE,
      'enabled' => is_bool($flow_enabled) ? $flow_enabled : TRUE,
      'trigger_intents' => $trigger_intents !== [] ? $trigger_intents : self::DEFAULT_TRIGGER_INTENTS,
      'require_followup_prompt' => is_bool($require_followup_prompt) ? $require_followup_prompt : TRUE,
      'max_turns' => max(1, (int) ($max_turns ?? self::DEFAULT_MAX_TURNS)),
      'ttl_seconds' => max(1, (int) ($ttl_seconds ?? self::DEFAULT_TTL_SECONDS)),
    ];
  }

  /**
   * Builds a normalized flow decision payload.
   */
  private function buildDecision(
    string $status,
    string $action = 'none',
    string $state_operation = 'none',
    array $state_payload = [],
    array $extras = [],
  ): array {
    return $extras + [
      'status' => $status,
      'flow_id' => 'office_followup',
      'action' => $action,
      'state_operation' => $state_operation,
      'state_payload' => $state_payload,
    ];
  }

  /**
   * Builds the normalized no-op decision contract.
   */
  private function buildContinueDecision(): array {
    return $this->buildDecision('continue');
  }

}
