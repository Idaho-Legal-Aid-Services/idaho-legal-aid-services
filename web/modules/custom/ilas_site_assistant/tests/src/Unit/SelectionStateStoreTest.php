<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\ilas_site_assistant\Service\SelectionStateStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers session-bound selection state persistence.
 */
#[Group('ilas_site_assistant')]
final class SelectionStateStoreTest extends TestCase {

  /**
   * Matching session fingerprints preserve stored selection state.
   */
  public function testMatchingFingerprintLoadsSavedState(): void {
    $cache = new SelectionStateStoreInMemoryCacheBackend();
    $store = new SelectionStateStore($cache);

    $selection = [
      'button_id' => 'forms_family',
      'label' => 'Family & Custody',
      'parent_button_id' => 'forms',
      'source' => 'selection',
    ];
    $store->save('conv-1', $selection, 'menu-signature', 'session-a');

    $loaded = $store->load('conv-1', 'session-a');

    $this->assertSame($selection, $loaded['active_selection']);
    $this->assertSame('menu-signature', $loaded['last_menu_signature']);
  }

  /**
   * Mismatched session fingerprints clear stale selection state.
   */
  public function testFingerprintMismatchReturnsDefaultStateAndClearsEntry(): void {
    $cache = new SelectionStateStoreInMemoryCacheBackend();
    $store = new SelectionStateStore($cache);

    $selection = [
      'button_id' => 'forms_family',
      'label' => 'Family & Custody',
      'parent_button_id' => 'forms',
      'source' => 'selection',
    ];
    $store->save('conv-2', $selection, 'menu-signature', 'session-a');

    $loaded = $store->load('conv-2', 'session-b');

    $this->assertSame([
      'active_selection' => NULL,
      'last_menu_signature' => '',
    ], $loaded);
    $this->assertFalse($cache->get('ilas_conv_selection:conv-2'));
  }

}

/**
 * In-memory cache backend for SelectionStateStore unit tests.
 */
final class SelectionStateStoreInMemoryCacheBackend implements CacheBackendInterface {

  /**
   * Stored entries keyed by cache ID.
   *
   * @var array<string, object>
   */
  private array $storage = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->storage[$cid] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $results = [];
    foreach ($cids as $cid) {
      if (isset($this->storage[$cid])) {
        $results[$cid] = $this->storage[$cid];
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->storage[$cid] = (object) [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
      'valid' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      $this->set(
        $cid,
        $item['data'] ?? NULL,
        $item['expire'] ?? Cache::PERMANENT,
        $item['tags'] ?? []
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      unset($this->storage[$cid]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->storage = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    unset($this->storage[$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {}

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->deleteAll();
  }

}
