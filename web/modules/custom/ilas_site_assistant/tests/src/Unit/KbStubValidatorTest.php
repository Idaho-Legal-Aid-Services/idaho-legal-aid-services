<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
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
   * YAML parsing turns an unquoted date into a timestamp; accept it.
   */
  public function testUnquotedYamlDateIsAccepted(): void {
    $parsed = DrupalYaml::decode("reviewed_on: 2026-09-01\n");
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
      'needs changes' => [['review_status' => 'needs_changes'], "review_status is 'needs_changes', not 'approved'"],
      'revised, not re-approved' => [['review_status' => 'revised'], "review_status is 'revised', not 'approved'"],
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
   * partition() keeps importable stubs in file order and reports the rest.
   */
  public function testPartitionSplitsImportableFromBlocked(): void {
    $first = self::approvedStub(['id' => 'faq_a']);
    $third = self::approvedStub(['id' => 'faq_c']);
    $result = KbStubValidator::partition([
      $first,
      self::approvedStub(['id' => 'faq_b', 'review_status' => 'needs_changes']),
      $third,
      'not a mapping',
    ], self::today());

    $this->assertSame([$first, $third], $result['importable']);
    $this->assertSame(['faq_b', '#4'], array_keys($result['blocked']));
    $this->assertContains("review_status is 'needs_changes', not 'approved'", $result['blocked']['faq_b']);
  }

  /**
   * The shipped stub files carry exactly the sign-offs the reviewer recorded.
   *
   * This is the guard against publishing drafted-but-unreviewed legal
   * answers: a stub imports only when it is listed here AND carries a valid
   * sign-off in the YAML. When the reviewer approves (or un-approves) a stub,
   * update this list deliberately, in the same change that records it.
   *
   * Rounds 1 and 2 were recorded on 2026-09-22 from the ILAS FAQ Sign-off
   * Sheet: 11 answers approved as written, 11 revised from the reviewer's
   * notes and then approved. The housing file still holds TODO answers.
   */
  public function testShippedStubSignOffsMatchRecordedDecisions(): void {
    $approved = [
      'top5-gap-faq-stubs.yml' => [
        'faq_employment_fired',
        'faq_employment_wages',
        'faq_utility_shutoff',
        'faq_name_change',
        'faq_mobile_home_rights',
        'faq_veterans_services',
        'faq_expungement',
      ],
      'consumer-debt-faq-stubs.yml' => [
        'faq_debt_collection_rights',
        'faq_debt_dispute',
        'faq_wage_garnishment',
        'faq_credit_report_errors',
        'faq_medical_debt',
        'faq_consumer_scam_recovery',
        'faq_identity_theft',
        'faq_debt_lawsuit',
        'faq_bankruptcy_basics',
        'faq_car_repossession',
        'faq_payday_loans',
        'faq_debt_statute_limitations',
        'faq_bank_account_levy',
        'faq_predatory_lending',
        'faq_debt_after_death',
      ],
      'housing-landlord-tenant-faq-stubs.yml' => [],
    ];

    $files = glob(dirname(__DIR__, 3) . '/config/kb-stubs/*.yml') ?: [];
    $this->assertNotEmpty($files);
    $this->assertEqualsCanonicalizing(array_keys($approved), array_map('basename', $files), 'A stub file was added or removed; record its sign-off state here.');

    // Real sign-off dates are in the past; the synthetic fixtures above use
    // a frozen date, but the shipped files must validate against today.
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    foreach ($files as $file) {
      $name = basename($file);
      $stubs = Yaml::parseFile($file)['faq_stubs'] ?? [];
      $this->assertNotEmpty($stubs, $name);

      $ids = array_map(static fn(array $stub): string => (string) $stub['id'], $stubs);
      $expected_blocked = array_values(array_diff($ids, $approved[$name]));
      $this->assertSame(
        $expected_blocked,
        array_keys(KbStubValidator::blockedStubs($stubs, $now)),
        $name . ': the importable stubs differ from the recorded sign-off decisions.',
      );
      foreach ($stubs as $stub) {
        if (in_array($stub['id'], $approved[$name], TRUE)) {
          $this->assertSame('Evan Curry', $stub['reviewed_by'] ?? NULL, $stub['id']);
        }
        elseif (in_array($stub['review_status'] ?? NULL, ['needs_changes', 'revised'], TRUE)) {
          $this->assertNotEmpty($stub['review_notes'] ?? [], $stub['id'] . ': ' . $stub['review_status'] . ' without review_notes');
        }
      }
    }
  }

}
