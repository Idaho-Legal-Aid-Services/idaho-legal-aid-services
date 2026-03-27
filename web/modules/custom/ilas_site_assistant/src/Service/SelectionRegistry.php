<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Resolves structured assistant selections and branch hierarchies.
 */
class SelectionRegistry {

  /**
   * Registry definitions keyed by canonical button ID.
   *
   * @var array<string, array<string, mixed>>|null
   */
  private ?array $definitions = NULL;

  /**
   * Constructs a SelectionRegistry.
   */
  public function __construct(
    private readonly TopIntentsPack $topIntentsPack,
  ) {}

  /**
   * Returns the canonical selection definition for a button ID.
   */
  public function get(string $button_id): ?array {
    $definitions = $this->buildDefinitions();
    if (!isset($definitions[$button_id])) {
      return NULL;
    }

    return $this->finalizeSelection($definitions[$button_id]);
  }

  /**
   * Resolves a normalized structured selection by ID or exact label.
   */
  public function resolve(?string $button_id, ?string $label = NULL, ?string $parent_button_id = NULL, string $source = 'selection'): ?array {
    $button_id = trim((string) $button_id);
    $label = trim((string) $label);
    $parent_button_id = trim((string) $parent_button_id);

    if ($button_id !== '') {
      $selection = $this->get($button_id);
      if ($selection !== NULL) {
        return $this->applyRequestMetadata($selection, $label, $parent_button_id, $source);
      }
    }

    if ($label === '') {
      return NULL;
    }

    $definitions = $this->buildDefinitions();
    $needle = mb_strtolower($label);
    $candidates = [];
    foreach ($definitions as $definition) {
      $candidate = $this->finalizeSelection($definition);
      $candidate_label = mb_strtolower((string) ($candidate['label'] ?? ''));
      $candidate_query_label = mb_strtolower(trim((string) ($candidate['query_label'] ?? '')));
      if ($candidate_label !== $needle && $candidate_query_label !== $needle) {
        continue;
      }
      $candidates[] = $candidate;
    }

    if ($candidates === []) {
      return NULL;
    }

    if ($parent_button_id !== '') {
      foreach ($candidates as $candidate) {
        if (($candidate['parent_button_id'] ?? '') === $parent_button_id) {
          return $this->applyRequestMetadata($candidate, $label, $parent_button_id, $source);
        }
      }
    }

    usort($candidates, static fn(array $a, array $b): int => (int) ($b['depth'] ?? 0) <=> (int) ($a['depth'] ?? 0));

    return $this->applyRequestMetadata($candidates[0], $label, $parent_button_id, $source);
  }

  /**
   * Resolves a typed child selection within the current active branch.
   */
  public function matchTypedChildSelection(?array $active_selection, string $message): ?array {
    if (!is_array($active_selection) || ($active_selection['kind'] ?? '') !== 'resource_parent') {
      return NULL;
    }

    $message = trim($message);
    if ($message === '') {
      return NULL;
    }

    $children = $this->getChildren((string) ($active_selection['button_id'] ?? ''));
    if ($children === []) {
      return NULL;
    }

    $normalized_message = mb_strtolower($message);

    foreach ($children as $child) {
      if ($normalized_message === mb_strtolower((string) ($child['label'] ?? ''))) {
        return $this->applyRequestMetadata($child, $child['label'], $child['parent_button_id'] ?? '', 'typed_child_selection');
      }

      $query_label = trim((string) ($child['query_label'] ?? ''));
      if ($query_label !== '' && $normalized_message === mb_strtolower($query_label)) {
        return $this->applyRequestMetadata($child, $child['label'], $child['parent_button_id'] ?? '', 'typed_child_selection');
      }
    }

    $pack_match = $this->topIntentsPack->matchSynonyms($normalized_message);
    if ($pack_match === NULL) {
      return NULL;
    }

    foreach ($children as $child) {
      if (($child['target_intent'] ?? '') === $pack_match) {
        return $this->applyRequestMetadata($child, $child['label'], $child['parent_button_id'] ?? '', 'typed_child_selection');
      }
    }

    return NULL;
  }

  /**
   * Returns the parent selection for a given selection, if one exists.
   */
  public function getParent(?array $selection): ?array {
    if (!is_array($selection)) {
      return NULL;
    }

    $parent_button_id = trim((string) ($selection['parent_button_id'] ?? ''));
    if ($parent_button_id === '') {
      return NULL;
    }

    $parent = $this->get($parent_button_id);
    if ($parent === NULL) {
      return NULL;
    }

    return $this->applyRequestMetadata($parent, $parent['label'] ?? '', $parent['parent_button_id'] ?? '', 'selection_back');
  }

  /**
   * Returns the direct child selections for a parent button.
   *
   * @return array<int, array<string, mixed>>
   *   Ordered child selections.
   */
  public function getChildren(string $parent_button_id): array {
    $children = [];
    foreach ($this->buildDefinitions() as $definition) {
      if (($definition['parent_button_id'] ?? '') !== $parent_button_id) {
        continue;
      }
      $children[] = $this->finalizeSelection($definition);
    }

    usort($children, static fn(array $a, array $b): int => (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0));

    return $children;
  }

  /**
   * Decorates response topic suggestions with structured selection payloads.
   *
   * @param array<int, array<string, mixed>> $suggestions
   *   Assistant topic suggestions.
   *
   * @return array<int, array<string, mixed>>
   *   Decorated suggestions.
   */
  public function decorateSuggestions(array $suggestions): array {
    $decorated = [];
    foreach ($suggestions as $suggestion) {
      if (!is_array($suggestion)) {
        continue;
      }

      if (!isset($suggestion['selection']) && !empty($suggestion['action'])) {
        $selection = $this->resolve(
          (string) $suggestion['action'],
          isset($suggestion['label']) ? (string) $suggestion['label'] : NULL,
          isset($suggestion['parent_button_id']) ? (string) $suggestion['parent_button_id'] : NULL,
          'response',
        );
        if ($selection !== NULL) {
          $suggestion['selection'] = $this->buildSelectionPayload($selection);
        }
      }
      elseif (isset($suggestion['selection']) && is_array($suggestion['selection'])) {
        $selection = $this->resolve(
          isset($suggestion['selection']['button_id']) ? (string) $suggestion['selection']['button_id'] : NULL,
          isset($suggestion['selection']['label']) ? (string) $suggestion['selection']['label'] : NULL,
          isset($suggestion['selection']['parent_button_id']) ? (string) $suggestion['selection']['parent_button_id'] : NULL,
          isset($suggestion['selection']['source']) ? (string) $suggestion['selection']['source'] : 'response',
        );
        if ($selection !== NULL) {
          $suggestion['selection'] = $this->buildSelectionPayload($selection);
        }
      }

      $decorated[] = $suggestion;
    }

    return $decorated;
  }

  /**
   * Builds the public request/response selection payload.
   */
  public function buildSelectionPayload(array $selection): array {
    return [
      'button_id' => (string) ($selection['button_id'] ?? ''),
      'label' => (string) ($selection['label'] ?? ''),
      'parent_button_id' => (string) ($selection['parent_button_id'] ?? ''),
      'source' => (string) ($selection['source'] ?? 'selection'),
    ];
  }

  /**
   * Returns TRUE when the selection is deeper than the current active branch.
   */
  public function isDeeperThan(?array $selection, ?array $active_selection): bool {
    $selection_depth = (int) ($selection['depth'] ?? -1);
    $active_depth = (int) ($active_selection['depth'] ?? -1);

    return $selection_depth > $active_depth;
  }

  /**
   * Builds the in-memory selection definition map.
   *
   * @return array<string, array<string, mixed>>
   *   Selection definitions keyed by button ID.
   */
  private function buildDefinitions(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }

    $definitions = $this->baseDefinitions();
    foreach ($this->buildResourceChildren() as $button_id => $definition) {
      $definitions[$button_id] = $definition;
    }

    $this->definitions = $definitions;
    return $this->definitions;
  }

  /**
   * Returns the static top-level and parent branch definitions.
   *
   * @return array<string, array<string, mixed>>
   *   Static definitions keyed by button ID.
   */
  private function baseDefinitions(): array {
    return [
      'forms' => [
        'button_id' => 'forms',
        'label' => 'Forms',
        'kind' => 'direct_intent',
        'target_intent' => 'forms_inventory',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 0,
      ],
      'guides' => [
        'button_id' => 'guides',
        'label' => 'Guides',
        'kind' => 'direct_intent',
        'target_intent' => 'guides_inventory',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 1,
      ],
      'faq' => [
        'button_id' => 'faq',
        'label' => 'FAQs',
        'kind' => 'direct_intent',
        'target_intent' => 'faq',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 2,
      ],
      'apply' => [
        'button_id' => 'apply',
        'label' => 'Apply',
        'kind' => 'direct_intent',
        'target_intent' => 'apply_for_help',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 3,
      ],
      'hotline' => [
        'button_id' => 'hotline',
        'label' => 'Hotline',
        'kind' => 'direct_intent',
        'target_intent' => 'legal_advice_line',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 4,
      ],
      'topics' => [
        'button_id' => 'topics',
        'label' => 'Services',
        'kind' => 'direct_intent',
        'target_intent' => 'services_inventory',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 5,
      ],
      'forms_housing' => [
        'button_id' => 'forms_housing',
        'label' => 'Housing & Eviction',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_housing',
        'query_label' => 'housing eviction',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 10,
      ],
      'forms_family' => [
        'button_id' => 'forms_family',
        'label' => 'Family & Custody',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_family',
        'query_label' => 'family divorce custody',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 11,
      ],
      'forms_consumer' => [
        'button_id' => 'forms_consumer',
        'label' => 'Consumer & Debt',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_consumer',
        'query_label' => 'consumer debt',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 12,
      ],
      'forms_seniors' => [
        'button_id' => 'forms_seniors',
        'label' => 'Seniors & Guardianship',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_seniors',
        'query_label' => 'seniors guardianship estate planning',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 13,
      ],
      'forms_safety' => [
        'button_id' => 'forms_safety',
        'label' => 'Safety & Protection Orders',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_family_protection_order',
        'query_label' => 'protection order',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 14,
      ],
      'forms_benefits' => [
        'button_id' => 'forms_benefits',
        'label' => 'Health & Benefits',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_health',
        'query_label' => 'health benefits',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 15,
      ],
      'forms_employment' => [
        'button_id' => 'forms_employment',
        'label' => 'Employment',
        'kind' => 'resource_parent',
        'resource_kind' => 'forms',
        'topic_intent' => 'topic_employment',
        'query_label' => 'employment',
        'parent_button_id' => 'forms',
        'depth' => 1,
        'order' => 16,
      ],
      'guides_housing' => [
        'button_id' => 'guides_housing',
        'label' => 'Housing & Eviction',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_housing',
        'query_label' => 'housing eviction',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 20,
      ],
      'guides_family' => [
        'button_id' => 'guides_family',
        'label' => 'Family & Custody',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_family',
        'query_label' => 'family divorce custody',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 21,
      ],
      'guides_consumer' => [
        'button_id' => 'guides_consumer',
        'label' => 'Consumer & Debt',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_consumer',
        'query_label' => 'consumer debt',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 22,
      ],
      'guides_seniors' => [
        'button_id' => 'guides_seniors',
        'label' => 'Seniors & Guardianship',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_seniors',
        'query_label' => 'seniors guardianship estate planning',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 23,
      ],
      'guides_employment' => [
        'button_id' => 'guides_employment',
        'label' => 'Employment & Safety',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_employment',
        'query_label' => 'employment',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 24,
      ],
      'guides_benefits' => [
        'button_id' => 'guides_benefits',
        'label' => 'Health & Benefits',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_health',
        'query_label' => 'health benefits',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 25,
      ],
      'guides_safety' => [
        'button_id' => 'guides_safety',
        'label' => 'Protection Orders',
        'kind' => 'resource_parent',
        'resource_kind' => 'guides',
        'topic_intent' => 'topic_family_protection_order',
        'query_label' => 'protection order',
        'parent_button_id' => 'guides',
        'depth' => 1,
        'order' => 26,
      ],
      'apply_for_help' => [
        'button_id' => 'apply_for_help',
        'label' => 'Apply for help',
        'kind' => 'direct_intent',
        'target_intent' => 'apply_for_help',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 30,
      ],
      'legal_advice_line' => [
        'button_id' => 'legal_advice_line',
        'label' => 'Call the legal advice line',
        'kind' => 'direct_intent',
        'target_intent' => 'legal_advice_line',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 31,
      ],
      'offices_contact' => [
        'button_id' => 'offices_contact',
        'label' => 'Find an office near me',
        'kind' => 'direct_intent',
        'target_intent' => 'offices_contact',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 32,
      ],
      'eligibility' => [
        'button_id' => 'eligibility',
        'label' => 'Do I qualify for help?',
        'kind' => 'direct_intent',
        'target_intent' => 'eligibility',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 33,
      ],
      'risk_detector' => [
        'button_id' => 'risk_detector',
        'label' => 'Take the legal risk assessment',
        'kind' => 'direct_intent',
        'target_intent' => 'risk_detector',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 34,
      ],
      'forms_finder' => [
        'button_id' => 'forms_finder',
        'label' => 'Find a form',
        'kind' => 'direct_intent',
        'target_intent' => 'forms_finder',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 35,
      ],
      'guides_finder' => [
        'button_id' => 'guides_finder',
        'label' => 'Find a guide',
        'kind' => 'direct_intent',
        'target_intent' => 'guides_finder',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 36,
      ],
      'services_overview' => [
        'button_id' => 'services_overview',
        'label' => 'What services do you offer?',
        'kind' => 'direct_intent',
        'target_intent' => 'services_overview',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 37,
      ],
      'feedback' => [
        'button_id' => 'feedback',
        'label' => 'Give feedback',
        'kind' => 'direct_intent',
        'target_intent' => 'feedback',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 38,
      ],
      'donations' => [
        'button_id' => 'donations',
        'label' => 'How can I donate?',
        'kind' => 'direct_intent',
        'target_intent' => 'donations',
        'parent_button_id' => '',
        'depth' => 0,
        'order' => 39,
      ],
      'topic_housing' => [
        'button_id' => 'topic_housing',
        'label' => 'Housing',
        'kind' => 'service_area',
        'area' => 'housing',
        'target_intent' => 'topic_housing',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 40,
      ],
      'topic_family' => [
        'button_id' => 'topic_family',
        'label' => 'Family',
        'kind' => 'service_area',
        'area' => 'family',
        'target_intent' => 'topic_family',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 41,
      ],
      'topic_consumer' => [
        'button_id' => 'topic_consumer',
        'label' => 'Consumer',
        'kind' => 'service_area',
        'area' => 'consumer',
        'target_intent' => 'topic_consumer',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 42,
      ],
      'topic_seniors' => [
        'button_id' => 'topic_seniors',
        'label' => 'Seniors',
        'kind' => 'service_area',
        'area' => 'seniors',
        'target_intent' => 'topic_seniors',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 43,
      ],
      'topic_health' => [
        'button_id' => 'topic_health',
        'label' => 'Health & Benefits',
        'kind' => 'service_area',
        'area' => 'health',
        'target_intent' => 'topic_health',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 44,
      ],
      'topic_civil_rights' => [
        'button_id' => 'topic_civil_rights',
        'label' => 'Civil Rights',
        'kind' => 'service_area',
        'area' => 'civil_rights',
        'target_intent' => 'topic_civil_rights',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 45,
      ],
      'topic_employment' => [
        'button_id' => 'topic_employment',
        'label' => 'Employment',
        'kind' => 'service_area',
        'area' => 'employment',
        'target_intent' => 'topic_employment',
        'parent_button_id' => 'topics',
        'depth' => 1,
        'order' => 46,
      ],
    ];
  }

  /**
   * Builds clarifier-backed resource child definitions.
   *
   * @return array<string, array<string, mixed>>
   *   Generated child definitions keyed by button ID.
   */
  private function buildResourceChildren(): array {
    $definitions = [];
    foreach ($this->baseDefinitions() as $definition) {
      if (($definition['kind'] ?? '') !== 'resource_parent') {
        continue;
      }

      $topic_intent = (string) ($definition['topic_intent'] ?? '');
      $clarifier = $topic_intent !== '' ? $this->topIntentsPack->getClarifier($topic_intent) : NULL;
      if ($clarifier === NULL) {
        continue;
      }

      $resource_kind = (string) ($definition['resource_kind'] ?? '');
      foreach (($clarifier['options'] ?? []) as $index => $option) {
        if (!is_array($option) || empty($option['intent']) || empty($option['label'])) {
          continue;
        }

        $target_intent = (string) $option['intent'];
        $query_label = trim((string) $option['label']);
        $button_id = $resource_kind . '_' . $target_intent;
        $label = $query_label . ' ' . $resource_kind;

        $definitions[$button_id] = [
          'button_id' => $button_id,
          'label' => $label,
          'kind' => 'resource_child',
          'resource_kind' => $resource_kind,
          'target_intent' => $target_intent,
          'topic_intent' => $topic_intent,
          'query_label' => $query_label,
          'parent_button_id' => (string) $definition['button_id'],
          'depth' => ((int) ($definition['depth'] ?? 0)) + 1,
          'order' => $index,
        ];
      }
    }

    foreach ($this->topIntentsPack->getAllKeys() as $intent_key) {
      if (!str_starts_with($intent_key, 'topic_') || isset($definitions[$intent_key])) {
        continue;
      }

      $entry = $this->topIntentsPack->lookup($intent_key);
      if ($entry === NULL) {
        continue;
      }

      $root_parent = $this->resolveTopicParent($intent_key);
      $definitions[$intent_key] = [
        'button_id' => $intent_key,
        'label' => (string) ($entry['label'] ?? $intent_key),
        'kind' => $root_parent !== '' ? 'topic_subtopic' : 'service_area',
        'target_intent' => $intent_key,
        'parent_button_id' => $root_parent,
        'depth' => $root_parent !== '' ? 2 : 1,
        'order' => 100,
      ];
    }

    return $definitions;
  }

  /**
   * Returns the root topic parent for a sub-topic intent.
   */
  private function resolveTopicParent(string $intent_key): string {
    foreach (['topic_family', 'topic_housing', 'topic_consumer'] as $root_parent) {
      if ($intent_key !== $root_parent && str_starts_with($intent_key, $root_parent . '_')) {
        return $root_parent;
      }
    }

    return '';
  }

  /**
   * Applies normalized request metadata to a resolved selection.
   */
  private function applyRequestMetadata(array $selection, string $label, string $parent_button_id, string $source): array {
    if ($label !== '') {
      $selection['label'] = $label;
    }
    if ($parent_button_id !== '') {
      $selection['parent_button_id'] = $parent_button_id;
    }
    if ($source !== '') {
      $selection['source'] = $source;
    }

    return $selection;
  }

  /**
   * Finalizes a definition with default metadata keys.
   */
  private function finalizeSelection(array $definition): array {
    $definition['button_id'] = (string) ($definition['button_id'] ?? '');
    $definition['label'] = (string) ($definition['label'] ?? $definition['button_id']);
    $definition['parent_button_id'] = (string) ($definition['parent_button_id'] ?? '');
    $definition['source'] = (string) ($definition['source'] ?? 'selection');
    $definition['depth'] = (int) ($definition['depth'] ?? 0);
    $definition['order'] = (int) ($definition['order'] ?? 0);

    return $definition;
  }

}
