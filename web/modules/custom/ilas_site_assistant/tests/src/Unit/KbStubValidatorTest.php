<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\KbStubValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FAQ stubs are importable only with a finished, attorney-approved answer.
 */
#[Group('ilas_site_assistant')]
final class KbStubValidatorTest extends TestCase {

  /**
   * A complete, signed-off stub.
   */
  private static function approvedStub(array $overrides = []): array {
    return $overrides + [
      'id' => 'faq_housing_security_deposit',
      'type' => 'faq_item',
      'anchor_id' => 'can-my-landlord-keep-my-deposit',
      'question' => 'Can my landlord keep my security deposit?',
      'answer' => 'A landlord may only keep a deposit for the reasons the lease and Idaho law allow.',
      'review_status' => 'approved',
      'reviewed_by' => 'Staff Attorney',
      'reviewed_on' => '2026-09-18',
    ];
  }

  /**
   * The reference "today" used by every date check.
   */
  private static function today(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-09-18 12:00:00', new \DateTimeZone('UTC'));
  }

  /**
   * A finished stub with a sign-off is importable.
   */
  public function testApprovedStubIsImportable(): void {
    $this->assertSame([], KbStubValidator::blockingReasons(self::approvedStub(), self::today()));
  }

  /**
   * Symfony YAML turns an unquoted date into a timestamp; accept it.
   */
  public function testUnquotedYamlDateIsAccepted(): void {
    $parsed = Yaml::parse("reviewed_on: 2026-09-01\n");
    $this->assertIsInt($parsed['reviewed_on']);
    $this->assertSame([], KbStubValidator::blockingReasons(self::approvedStub($parsed), self::today()));
  }

  /**
   * Each missing or wrong piece blocks the import with a specific reason.
   */
  #[DataProvider('blockedStubProvider')]
  public function testBlockedStub(array $overrides, string $expected_reason): void {
    $reasons = KbStubValidator::blockingReasons(self::approvedStub($overrides), self::today());
    $this->assertContains($expected_reason, $reasons);
  }

  /**
   * Data provider for testBlockedStub().
   */
  public static function blockedStubProvider(): array {
    return [
      'todo answer' => [['answer' => "TODO (attorney review): explain deposits.\n"], 'answer still contains a TODO placeholder'],
      'lowercase todo' => [['answer' => 'Deposits: todo'], 'answer still contains a TODO placeholder'],
      'empty answer' => [['answer' => "  \n"], 'missing answer'],
      'no question' => [['question' => ''], 'missing question'],
      'unreviewed' => [['review_status' => 'unreviewed'], "review_status is 'unreviewed', not 'approved'"],
      'no status' => [['review_status' => NULL], 'no review_status (attorney sign-off required)'],
      'no reviewer' => [['reviewed_by' => ' '], 'missing reviewed_by'],
      'no date' => [['reviewed_on' => NULL], 'missing reviewed_on'],
      'bad date' => [['reviewed_on' => 'last week'], 'reviewed_on is not a YYYY-MM-DD date'],
      'impossible date' => [['reviewed_on' => '2026-02-30'], 'reviewed_on is not a YYYY-MM-DD date'],
      'future date' => [['reviewed_on' => '2026-09-19'], 'reviewed_on is in the future'],
    ];
  }

  /**
   * "TODO" inside a longer word is not a placeholder.
   */
  public function testTodoMustBeAWholeWord(): void {
    $stub = self::approvedStub(['answer' => 'Contact the Mastodon-hosted tenant union.']);
    $this->assertSame([], KbStubValidator::blockingReasons($stub, self::today()));
  }

  /**
   * One blocked stub is reported by id; malformed entries by position.
   */
  public function testBlockedStubsAreKeyedByIdOrPosition(): void {
    $blocked = KbStubValidator::blockedStubs([
      self::approvedStub(),
      self::approvedStub(['id' => 'faq_b', 'review_status' => 'unreviewed']),
      'not a mapping',
    ], self::today());

    $this->assertSame(['faq_b', '#3'], array_keys($blocked));
    $this->assertSame(['stub is not a mapping'], $blocked['#3']);
  }

  /**
   * No shipped stub file is importable until an attorney signs it off.
   *
   * This is the guard against publishing drafted-but-unreviewed legal
   * answers. When an attorney approves a stub this test must be updated
   * deliberately, in the same change that records the sign-off.
   */
  public function testShippedStubFilesAreAllAwaitingSignOff(): void {
    $files = glob(dirname(__DIR__, 3) . '/config/kb-stubs/*.yml') ?: [];
    $this->assertNotEmpty($files);

    foreach ($files as $file) {
      $stubs = Yaml::parseFile($file)['faq_stubs'] ?? [];
      $this->assertNotEmpty($stubs, basename($file));
      $this->assertCount(
        count($stubs),
        KbStubValidator::blockedStubs($stubs, self::today()),
        basename($file) . ' contains a stub that would import without a recorded attorney sign-off.',
      );
    }
  }

}
