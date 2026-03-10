<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\PiiRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PiiRedactor.
 *
 * @group ilas_site_assistant
 * @coversDefaultClass \Drupal\ilas_site_assistant\Service\PiiRedactor
 */
class PiiRedactorTest extends TestCase {

  /**
   * Tests email redaction.
   *
   * @covers ::redact
   */
  #[DataProvider('emailProvider')]
  public function testRedactsEmails(string $input, string $expected): void {
    $this->assertSame($expected, PiiRedactor::redact($input));
  }

  public static function emailProvider(): array {
    return [
      'simple email' => [
        'Contact john@example.com today',
        'Contact ' . PiiRedactor::TOKEN_EMAIL . ' today',
      ],
      'email with dots' => [
        'Send to first.last@domain.co.uk',
        'Send to ' . PiiRedactor::TOKEN_EMAIL,
      ],
      'email with plus' => [
        'user+tag@gmail.com is mine',
        PiiRedactor::TOKEN_EMAIL . ' is mine',
      ],
    ];
  }

  /**
   * Tests SSN redaction (dashed format).
   *
   * @covers ::redact
   */
  public function testRedactsSsnDashed(): void {
    $this->assertSame(
      'My SSN is ' . PiiRedactor::TOKEN_SSN,
      PiiRedactor::redact('My SSN is 123-45-6789')
    );
  }

  /**
   * Tests SSN redaction (spaced format).
   *
   * @covers ::redact
   */
  public function testRedactsSsnSpaced(): void {
    $this->assertSame(
      'My SSN is ' . PiiRedactor::TOKEN_SSN,
      PiiRedactor::redact('My SSN is 123 45 6789')
    );
  }

  /**
   * Tests SSN redaction (keyword-gated no-separator).
   *
   * @covers ::redact
   */
  public function testRedactsSsnKeywordGated(): void {
    $result = PiiRedactor::redact('my ssn 123456789');
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $result);

    $result2 = PiiRedactor::redact('social security number: 123456789');
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $result2);
  }

  /**
   * Tests that dashed SSN is not misidentified as phone.
   *
   * @covers ::redact
   */
  public function testSsnNotMisidentifiedAsPhone(): void {
    $result = PiiRedactor::redact('123-45-6789');
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $result);
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_PHONE, $result);
  }

  /**
   * Tests credit card redaction with Luhn validation.
   *
   * @covers ::redact
   */
  public function testRedactsCreditCards(): void {
    // Valid Luhn: 4111111111111111 (Visa test card).
    $result = PiiRedactor::redact('Card: 4111111111111111');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CC, $result);

    // With spaces.
    $result2 = PiiRedactor::redact('Card: 4111 1111 1111 1111');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CC, $result2);

    // With dashes.
    $result3 = PiiRedactor::redact('Card: 4111-1111-1111-1111');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CC, $result3);
  }

  /**
   * Tests that invalid Luhn numbers are NOT redacted as credit cards.
   *
   * @covers ::redact
   */
  public function testDoesNotRedactInvalidLuhn(): void {
    // 1234567890123456 fails Luhn.
    $result = PiiRedactor::redact('Number: 1234567890123456');
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_CC, $result);
  }

  /**
   * Tests phone number redaction.
   *
   * @covers ::redact
   */
  #[DataProvider('phoneProvider')]
  public function testRedactsPhones(string $input): void {
    $result = PiiRedactor::redact($input);
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $result);
  }

  public static function phoneProvider(): array {
    return [
      'dashed' => ['Call 208-555-1234'],
      'dotted' => ['Call 208.555.1234'],
      'parenthesized' => ['Call (208) 555-1234'],
      'no separator' => ['Call 2085551234'],
      'country code' => ['Mi telefono es +52-208-555-1234'],
    ];
  }

  /**
   * Tests that country-code prefixes are consumed with the phone number.
   *
   * @covers ::redact
   */
  public function testInternationalPhonePrefixIsFullyRedacted(): void {
    $result = PiiRedactor::redact('Mi telefono es +52-208-555-1234');
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $result);
    $this->assertStringNotContainsString('+52-208-555-1234', $result);
    $this->assertStringNotContainsString('+52-', $result);
  }

  /**
   * Tests DOB redaction (keyword-gated).
   *
   * @covers ::redact
   */
  public function testRedactsDob(): void {
    $result = PiiRedactor::redact('born on 01/15/1990');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $result);

    $result2 = PiiRedactor::redact('dob: 01/15/1990');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $result2);

    $result3 = PiiRedactor::redact('date of birth 03-22-1985');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $result3);

    $result4 = PiiRedactor::redact('fecha de nacimiento 01/15/1990');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $result4);
    $this->assertStringNotContainsString('01/15/1990', $result4);

    $result5 = PiiRedactor::redact('nacido el 03-22-1985');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DOB, $result5);
    $this->assertStringNotContainsString('03-22-1985', $result5);
  }

  /**
   * Tests standalone date redaction.
   *
   * @covers ::redact
   */
  public function testRedactsStandaloneDates(): void {
    $result = PiiRedactor::redact('Filed on 01/15/2024');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DATE, $result);

    $result2 = PiiRedactor::redact('date 2025-03-15');
    $this->assertStringContainsString(PiiRedactor::TOKEN_DATE, $result2);
  }

  /**
   * Tests Idaho court case number redaction.
   *
   * @covers ::redact
   */
  #[DataProvider('idahoCaseProvider')]
  public function testRedactsIdahoCaseNumbers(string $input): void {
    $result = PiiRedactor::redact($input);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result);
  }

  public static function idahoCaseProvider(): array {
    return [
      'CV case' => ['My case is CV-23-12345'],
      'DR case' => ['case DR-24-00123'],
      'CR case' => ['CR-2024-0001'],
      'JV case' => ['JV-23-456'],
    ];
  }

  /**
   * Tests keyword-gated case/docket/file number redaction.
   *
   * @covers ::redact
   */
  public function testRedactsCaseDocketFileNumbers(): void {
    $result = PiiRedactor::redact('case number: ABC-123');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result);

    $result2 = PiiRedactor::redact('docket no 2024-CV-001');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result2);

    $result3 = PiiRedactor::redact('file # XYZ999');
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result3);
  }

  /**
   * Tests street address redaction.
   *
   * @covers ::redact
   */
  public function testRedactsStreetAddresses(): void {
    $result = PiiRedactor::redact('I live at 123 Main Street');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result);

    $result2 = PiiRedactor::redact('Send to 4567 Oak Ave');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result2);
  }

  /**
   * Tests contextual address redaction.
   *
   * @covers ::redact
   */
  public function testRedactsContextualAddress(): void {
    $result = PiiRedactor::redact('my address is 123 Elm St, Boise, ID 83702');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result);
    $this->assertStringNotContainsString('123 Elm St', $result);
    $this->assertStringNotContainsString('83702', $result);

    $result2 = PiiRedactor::redact('i live at 456 Pine Rd, Apt 2, Idaho 83701');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result2);
    $this->assertStringNotContainsString('456 Pine Rd', $result2);
    $this->assertStringNotContainsString('83701', $result2);

    $result3 = PiiRedactor::redact('Mi direccion es 123 Main Street Boise ID 83702');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result3);
    $this->assertStringNotContainsString('123 Main Street', $result3);
    $this->assertStringNotContainsString('Boise ID 83702', $result3);

    $result4 = PiiRedactor::redact('Vivo en 456 Pine Rd, Apt 2, Idaho 83701');
    $this->assertStringContainsString(PiiRedactor::TOKEN_ADDRESS, $result4);
    $this->assertStringNotContainsString('456 Pine Rd', $result4);
    $this->assertStringNotContainsString('Idaho 83701', $result4);
  }

  /**
   * Tests contextual name redaction.
   *
   * @covers ::redact
   */
  public function testRedactsContextualNames(): void {
    $result = PiiRedactor::redact('my name is John Smith');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result);

    $result2 = PiiRedactor::redact("I'm called Maria");
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result2);

    $result3 = PiiRedactor::redact('name John Smith');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result3);

    $result4 = PiiRedactor::redact('Me llamo Juan Garcia');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result4);
    $this->assertStringNotContainsString('Juan Garcia', $result4);

    $result5 = PiiRedactor::redact('Mi nombre es Juan García');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result5);
    $this->assertStringNotContainsString('Juan García', $result5);

    $result6 = PiiRedactor::redact('Client John Smith needs help with eviction');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result6);
    $this->assertStringNotContainsString('John Smith', $result6);

    $result7 = PiiRedactor::redact('tenant Maria Lopez called yesterday');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result7);
    $this->assertStringNotContainsString('Maria Lopez', $result7);

    $result8 = PiiRedactor::redact('Cliente Juan García necesita ayuda');
    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result8);
    $this->assertStringNotContainsString('Juan García', $result8);
  }

  /**
   * Tests Idaho driver's license redaction when license context is present.
   *
   * @covers ::redact
   */
  #[DataProvider('idahoDriversLicenseProvider')]
  public function testRedactsIdahoDriversLicenses(string $input): void {
    $result = PiiRedactor::redact($input);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result);
    $this->assertStringNotContainsString('AB123456C', $result);
  }

  public static function idahoDriversLicenseProvider(): array {
    return [
      'idaho license is' => ['My Idaho license is AB123456C'],
      'license number' => ['license number AB123456C'],
      'dl number' => ['DL number AB123456C'],
      'driver license' => ["driver's license AB123456C"],
      'spanish license' => ['Mi licencia es AB123456C'],
      'spanish driver license' => ['licencia de conducir AB123456C'],
    ];
  }

  /**
   * Tests false positive avoidance.
   *
   * @covers ::redact
   */
  #[DataProvider('falsePositiveProvider')]
  public function testFalsePositiveAvoidance(string $input, string $description): void {
    $result = PiiRedactor::redact($input);
    // These fixtures should remain unchanged and token-free.
    $this->assertSame($input, $result, "False positive: $description");
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_SSN, $result, "False positive: $description");
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_PHONE, $result, "False positive: $description");
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_NAME, $result, "False positive: $description");
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_CASE, $result, "False positive: $description");
  }

  public static function falsePositiveProvider(): array {
    return [
      'Idaho Code statute' => [
        'Idaho Code 6-303 says landlords must give notice',
        'Idaho Code statute reference should not be SSN',
      ],
      'bare 9 digits without keyword' => [
        'The population is 123456789',
        'Bare 9 digits without SSN keyword should not match',
      ],
      'tenant rights guide' => [
        'tenant rights guide',
        'Topic phrases must not be treated as tenant names',
      ],
      'non-name spanish role phrase' => [
        'cliente necesita ayuda urgente',
        'Spanish role labels must not redact lowercase non-name phrases',
      ],
      'bare idaho dl shape without context' => [
        'Reference AB123456C for the form',
        'Idaho DL shape without license context should not match',
      ],
    ];
  }

  /**
   * Tests that "3-day notice" is not redacted as date/SSN.
   *
   * @covers ::redact
   */
  public function testThreeDayNoticeNotRedacted(): void {
    $result = PiiRedactor::redact('You received a 3-day notice');
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_SSN, $result);
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_DATE, $result);
    $this->assertSame('You received a 3-day notice', $result);
  }

  /**
   * Tests that "call 211" is not redacted as phone.
   *
   * @covers ::redact
   */
  public function testCall211NotRedacted(): void {
    $result = PiiRedactor::redact('call 211 for help');
    $this->assertStringNotContainsString(PiiRedactor::TOKEN_PHONE, $result);
  }

  /**
   * Tests idempotency: redact(redact(x)) === redact(x).
   *
   * @covers ::redact
   */
  public function testIdempotency(): void {
    $input = 'Email john@example.com, SSN 123-45-6789, phone 208-555-1234';
    $once = PiiRedactor::redact($input);
    $twice = PiiRedactor::redact($once);
    $this->assertSame($once, $twice);
  }

  /**
   * Tests combined PII in a single string.
   *
   * @covers ::redact
   */
  public function testCombinedPii(): void {
    $input = 'My name is John Smith, email john@example.com, SSN 123-45-6789, phone 208-555-1234, case CV-23-12345';
    $result = PiiRedactor::redact($input);

    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $result);
    $this->assertStringContainsString(PiiRedactor::TOKEN_EMAIL, $result);
    $this->assertStringContainsString(PiiRedactor::TOKEN_SSN, $result);
    $this->assertStringContainsString(PiiRedactor::TOKEN_PHONE, $result);
    $this->assertStringContainsString(PiiRedactor::TOKEN_CASE, $result);

    $this->assertStringNotContainsString('john@example.com', $result);
    $this->assertStringNotContainsString('123-45-6789', $result);
    $this->assertStringNotContainsString('208-555-1234', $result);
    $this->assertStringNotContainsString('CV-23-12345', $result);
  }

  /**
   * Tests redactForStorage truncation.
   *
   * @covers ::redactForStorage
   */
  public function testRedactForStorageTruncation(): void {
    $long = str_repeat('Hello world. ', 100);
    $result = PiiRedactor::redactForStorage($long, 500);
    $this->assertLessThanOrEqual(500, mb_strlen($result));
  }

  /**
   * Tests redactForStorage default max length.
   *
   * @covers ::redactForStorage
   */
  public function testRedactForStorageDefaultLength(): void {
    $long = str_repeat('a', 1000);
    $result = PiiRedactor::redactForStorage($long);
    $this->assertLessThanOrEqual(500, mb_strlen($result));
  }

  /**
   * Tests redactForLog truncation.
   *
   * @covers ::redactForLog
   */
  public function testRedactForLogTruncation(): void {
    $long = str_repeat('Hello world. ', 100);
    $result = PiiRedactor::redactForLog($long, 100);
    $this->assertLessThanOrEqual(100, mb_strlen($result));
  }

  /**
   * Tests redactForLog default max length.
   *
   * @covers ::redactForLog
   */
  public function testRedactForLogDefaultLength(): void {
    $long = str_repeat('a', 500);
    $result = PiiRedactor::redactForLog($long);
    $this->assertLessThanOrEqual(100, mb_strlen($result));
  }

  /**
   * Tests whitespace normalization in redactForStorage.
   *
   * @covers ::redactForStorage
   */
  public function testWhitespaceNormalization(): void {
    $result = PiiRedactor::redactForStorage("  hello   world\n\ttest  ");
    $this->assertSame('hello world test', $result);
  }

  /**
   * Tests empty string handling.
   *
   * @covers ::redact
   * @covers ::redactForStorage
   * @covers ::redactForLog
   */
  public function testEmptyString(): void {
    $this->assertSame('', PiiRedactor::redact(''));
    $this->assertSame('', PiiRedactor::redactForStorage(''));
    $this->assertSame('', PiiRedactor::redactForLog(''));
  }

  /**
   * Tests that clean text passes through unchanged.
   *
   * @covers ::redact
   */
  public function testCleanTextPassesThrough(): void {
    $input = 'How do I apply for legal help in Idaho?';
    $this->assertSame($input, PiiRedactor::redact($input));
  }

  /**
   * Tests that PII in a typical user message is redacted for cache storage.
   *
   * Regression test for ILAS-AILA-PRIVACY-001: conversation cache must never
   * contain raw PII.
   *
   * @covers ::redactForStorage
   */
  public function testNameRedactedInCachedHistory(): void {
    $cached_text = PiiRedactor::redactForStorage('my name is John Smith and I need help', 200);

    $this->assertStringContainsString(PiiRedactor::TOKEN_NAME, $cached_text,
      'Name should be replaced with [REDACTED-NAME] token');
    $this->assertStringNotContainsString('John', $cached_text,
      'Raw first name must not appear in cached text');
    $this->assertStringNotContainsString('Smith', $cached_text,
      'Raw last name must not appear in cached text');
    $this->assertStringContainsString('and I need help', $cached_text,
      'Non-PII portion of message should be preserved');
  }

}
