<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\SafetyViolationTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafetyViolationTracker ring-buffer service.
 */
#[CoversClass(SafetyViolationTracker::class)]
#[Group('ilas_site_assistant')]
class SafetyViolationTrackerTest extends TestCase {

  /**
   * Creates a tracker with an in-memory state mock.
   *
   * @return array
   *   [SafetyViolationTracker, &$storage] where $storage is the backing array.
   */
  private function createTracker(): array {
    $storage = [];
    $state = $this->createMock('Drupal\Core\State\StateInterface');
    $state->method('get')
      ->willReturnCallback(function ($key, $default = NULL) use (&$storage) {
        return $storage[$key] ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(function ($key, $value) use (&$storage) {
        $storage[$key] = $value;
      });
    $state->method('delete')
      ->willReturnCallback(function ($key) use (&$storage) {
        unset($storage[$key]);
      });

    $tracker = new SafetyViolationTracker($state);
    return [$tracker, &$storage];
  }

  /**
   * Tests basic record and countSince.
   */
  public function testRecordAndCountSince(): void {
    [$tracker] = $this->createTracker();

    $now = time();
    $tracker->record($now - 3600); // 1 hour ago.
    $tracker->record($now - 1800); // 30 minutes ago.
    $tracker->record($now - 300);  // 5 minutes ago.
    $tracker->record($now);        // Now.

    // Count all since 2 hours ago.
    $this->assertEquals(4, $tracker->countSince($now - 7200));

    // Count since 45 minutes ago (last 3).
    $this->assertEquals(3, $tracker->countSince($now - 2700));

    // Count since 10 minutes ago (last 2).
    $this->assertEquals(2, $tracker->countSince($now - 600));

    // Count since 1 second ago (last 1).
    $this->assertEquals(1, $tracker->countSince($now));

    // Count since future (none).
    $this->assertEquals(0, $tracker->countSince($now + 1));
  }

  /**
   * Tests that countSince returns 0 for empty tracker.
   */
  public function testCountSinceEmpty(): void {
    [$tracker] = $this->createTracker();
    $this->assertEquals(0, $tracker->countSince(0));
  }

  /**
   * Tests prune removes old entries.
   */
  public function testPrune(): void {
    [$tracker] = $this->createTracker();

    $now = time();
    $tracker->record($now - 7200); // 2 hours ago (should be pruned).
    $tracker->record($now - 5400); // 1.5 hours ago (should be pruned).
    $tracker->record($now - 1800); // 30 minutes ago (kept).
    $tracker->record($now);        // Now (kept).

    // Prune entries older than 1 hour.
    $tracker->prune($now - 3600);

    // Only 2 should remain.
    $this->assertEquals(2, $tracker->countSince(0));
    $this->assertEquals(2, $tracker->countSince($now - 3600));
  }

  /**
   * Tests ring-buffer trim at MAX_ENTRIES.
   */
  public function testRingBufferTrim(): void {
    [$tracker] = $this->createTracker();

    $now = time();
    // Fill past the limit.
    for ($i = 0; $i < 510; $i++) {
      $tracker->record($now - (510 - $i));
    }

    // Should be trimmed to MAX_ENTRIES.
    $this->assertEquals(SafetyViolationTracker::MAX_ENTRIES, $tracker->countSince(0));

    // Oldest 10 entries should be dropped; timestamps now start at $now - 500.
    $this->assertEquals(SafetyViolationTracker::MAX_ENTRIES, $tracker->countSince($now - 500));
    $this->assertEquals(SafetyViolationTracker::MAX_ENTRIES - 1, $tracker->countSince($now - 499));
  }

  /**
   * Tests reset clears all data.
   */
  public function testReset(): void {
    [$tracker] = $this->createTracker();

    $tracker->record(time());
    $tracker->record(time());
    $this->assertEquals(2, $tracker->countSince(0));

    $tracker->reset();
    $this->assertEquals(0, $tracker->countSince(0));
  }

  /**
   * Tests that countSince uses >= comparison (inclusive).
   */
  public function testCountSinceInclusive(): void {
    [$tracker] = $this->createTracker();

    $ts = 1700000000;
    $tracker->record($ts);

    // Cutoff exactly equals timestamp — should be counted.
    $this->assertEquals(1, $tracker->countSince($ts));

    // Cutoff one second after — should not be counted.
    $this->assertEquals(0, $tracker->countSince($ts + 1));
  }

}
