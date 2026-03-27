<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Persists active selection state for multi-turn navigation recovery.
 */
class SelectionStateStore {

  /**
   * Selection-state cache TTL in seconds.
   */
  private const TTL_SECONDS = 1800;

  /**
   * Constructs a SelectionStateStore.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Loads the stored selection state for a conversation.
   *
   * @param string $conversation_id
   *   Conversation UUID.
   * @param string $session_fingerprint
   *   Session ownership fingerprint. When both the stored and provided
   *   fingerprints are present and differ, the state is treated as stale.
   *
   * @return array{active_selection: ?array, last_menu_signature: string}
   *   Normalized selection state.
   */
  public function load(string $conversation_id, string $session_fingerprint = ''): array {
    if ($conversation_id === '') {
      return $this->defaultState();
    }

    $cached = $this->cache->get($this->cacheId($conversation_id));
    if (!$cached || !is_array($cached->data)) {
      return $this->defaultState();
    }

    $data = $cached->data;
    $stored_fingerprint = (string) ($data['session_fingerprint'] ?? '');
    if ($stored_fingerprint !== '' && $session_fingerprint !== '' && !hash_equals($stored_fingerprint, $session_fingerprint)) {
      $this->clear($conversation_id);
      return $this->defaultState();
    }

    return [
      'active_selection' => isset($data['active_selection']) && is_array($data['active_selection']) ? $data['active_selection'] : NULL,
      'last_menu_signature' => (string) ($data['last_menu_signature'] ?? ''),
    ];
  }

  /**
   * Persists the active selection state for a conversation.
   *
   * @param string $conversation_id
   *   Conversation UUID.
   * @param array|null $active_selection
   *   Active selection payload.
   * @param string $last_menu_signature
   *   Stable signature of the last rendered topic menu.
   * @param string $session_fingerprint
   *   Session ownership fingerprint.
   */
  public function save(string $conversation_id, ?array $active_selection, string $last_menu_signature = '', string $session_fingerprint = ''): void {
    if ($conversation_id === '') {
      return;
    }

    $this->cache->set(
      $this->cacheId($conversation_id),
      [
        'active_selection' => $active_selection,
        'last_menu_signature' => $last_menu_signature,
        'session_fingerprint' => $session_fingerprint,
        'updated_at' => time(),
      ],
      time() + self::TTL_SECONDS,
    );
  }

  /**
   * Clears the stored selection state for a conversation.
   */
  public function clear(string $conversation_id): void {
    if ($conversation_id === '') {
      return;
    }

    $this->cache->delete($this->cacheId($conversation_id));
  }

  /**
   * Returns the normalized default state.
   *
   * @return array{active_selection: null, last_menu_signature: string}
   *   Empty state payload.
   */
  private function defaultState(): array {
    return [
      'active_selection' => NULL,
      'last_menu_signature' => '',
    ];
  }

  /**
   * Builds the dedicated selection-state cache ID.
   */
  private function cacheId(string $conversation_id): string {
    return 'ilas_conv_selection:' . $conversation_id;
  }

}
