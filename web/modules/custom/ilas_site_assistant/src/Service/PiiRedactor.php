<?php

namespace Drupal\ilas_site_assistant\Service;

/**
 * Static utility for redacting PII from text.
 *
 * Centralizes all PII detection and replacement logic into a single source
 * of truth. Patterns are ordered to avoid conflicts (e.g., SSN before phone).
 *
 * Usage:
 *   PiiRedactor::redact($text)             — redact only, no truncation
 *   PiiRedactor::redactForStorage($text)   — redact + truncate for DB fields
 *   PiiRedactor::redactForLog($text)       — redact + truncate for watchdog
 */
class PiiRedactor {

  /**
   * Redaction tokens.
   */
  const TOKEN_EMAIL   = '[REDACTED-EMAIL]';
  const TOKEN_PHONE   = '[REDACTED-PHONE]';
  const TOKEN_SSN     = '[REDACTED-SSN]';
  const TOKEN_CC      = '[REDACTED-CC]';
  const TOKEN_DOB     = '[REDACTED-DOB]';
  const TOKEN_DATE    = '[REDACTED-DATE]';
  const TOKEN_ADDRESS = '[REDACTED-ADDRESS]';
  const TOKEN_NAME    = '[REDACTED-NAME]';
  const TOKEN_CASE    = '[REDACTED-CASE]';

  /**
   * Redacts PII patterns from text without truncation.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   Text with PII replaced by redaction tokens.
   */
  public static function redact(string $text): string {
    if ($text === '') {
      return '';
    }

    $name_pattern = self::fullNamePattern();

    // 1. Email addresses.
    $text = preg_replace(
      '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
      self::TOKEN_EMAIL,
      $text
    );

    // 2. SSN (dashed): ###-##-#### — must run before phone.
    $text = preg_replace(
      '/\b\d{3}-\d{2}-\d{4}\b/',
      self::TOKEN_SSN,
      $text
    );

    // 3. SSN (spaced): ### ## ####.
    $text = preg_replace(
      '/\b\d{3}\s\d{2}\s\d{4}\b/',
      self::TOKEN_SSN,
      $text
    );

    // 4. SSN (no separator, keyword-gated): "ssn" or "social security" + 9 digits.
    $text = preg_replace(
      '/\b(ssn|social\s*security(?:\s*number)?)\s*[:=#]?\s*(\d{9})\b/i',
      '$1 ' . self::TOKEN_SSN,
      $text
    );

    // 5. Credit card numbers (13-19 digits in groups, with Luhn validation).
    $text = preg_replace_callback(
      '/\b(\d[ \-]?){12,18}\d\b/',
      function ($match) {
        $digits = preg_replace('/\D/', '', $match[0]);
        if (strlen($digits) >= 13 && strlen($digits) <= 19 && self::luhnCheck($digits)) {
          return self::TOKEN_CC;
        }
        return $match[0];
      },
      $text
    );

    // 6. Phone numbers, including optional country-code prefixes.
    // Use (?<!\d) / (?!\d) instead of \b to handle parenthesized format.
    $text = preg_replace(
      '/(?<!\d)(?:\+\d{1,3}[-.\s]?)?(?:\d{3}[-.\s]?\d{3}[-.\s]?\d{4}|\(\d{3}\)\s*\d{3}[-.\s]?\d{4})(?!\d)/',
      self::TOKEN_PHONE,
      $text
    );

    // 7. DOB (keyword-gated): English/Spanish context + date pattern.
    $text = preg_replace(
      '/\b(born\s*(?:on)?|dob|date\s*of\s*birth|fecha\s+de\s+nacimiento|nacido\s+(?:el|en))\s*[:=]?\s*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}/iu',
      '$1 ' . self::TOKEN_DOB,
      $text
    );

    // 8. Standalone dates (MM/DD/YYYY + YYYY-MM-DD variants).
    $text = preg_replace(
      '/(?:\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b|\b\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\b)/',
      self::TOKEN_DATE,
      $text
    );

    // 9. Idaho court case numbers (CV/DR/CR/JV/MH/SP-##-####).
    $text = preg_replace(
      '/\b(CV|DR|CR|JV|MH|SP)-\d{2,4}-\d{2,8}\b/i',
      self::TOKEN_CASE,
      $text
    );

    // 10. Case/docket/file numbers (keyword-gated).
    $text = preg_replace(
      '/\b(case|docket|file)\s*(number|no\.?|#)?\s*[:=]?\s*[\w\-]+/i',
      self::TOKEN_CASE,
      $text
    );

    // 11. Idaho driver's license numbers, gated by license context.
    $text = preg_replace_callback(
      '/\b(?P<context>(?i:(?:idaho\s+)?driver\'?s?\s+licen[sc]e(?:\s+(?:number|no\.?|#|is))?|idaho\s+licen[sc]e(?:\s+(?:number|no\.?|#|is))?|licen[sc]e\s+(?:number|no\.?|#)|dl\s+(?:number|no\.?|#)|licencia(?:\s+de\s+conducir)?(?:\s+(?:n(?:u|\x{FA})mero|no\.?|#|es))?))\s*[:=#]?\s*(?P<identifier>[A-Z]{2}\d{6}[A-Z])\b/u',
      static fn(array $matches): string => $matches['context'] . ' ' . self::TOKEN_CASE,
      $text
    );

    // 12. Address (contextual): English/Spanish phrases + content through ZIP.
    $text = preg_replace(
      '/\b(my\s+address\s+is|i\s+live\s+at|mi\s+direcci(?:o|\x{F3})n\s+es|vivo\s+en)\s+[^.!?\n]{0,120}?\d{5}(?:-\d{4})?\b/iu',
      self::TOKEN_ADDRESS,
      $text
    );

    // 13. Street addresses (number + words + suffix).
    $text = preg_replace(
      '/\b\d{1,5}\s+[\w\s]{1,40}\b(street|st|avenue|ave|road|rd|drive|dr|lane|ln|court|ct|boulevard|blvd|way|place|pl)\b/i',
      self::TOKEN_ADDRESS,
      $text
    );

    // 14. Name (contextual): English/Spanish self-identification phrases.
    $text = preg_replace_callback(
      '/\b(?P<context>(?i:my\s+name\s+is|i\'?m\s+called|me\s+llamo|mi\s+nombre\s+es))\s+(?P<name>' . $name_pattern . ')\b/u',
      static fn(array $matches): string => $matches['context'] . ' ' . self::TOKEN_NAME,
      $text
    );

    // 15. Name (role-gated): English/Spanish role labels followed by a name.
    $text = preg_replace_callback(
      '/\b(?P<context>(?i:client|tenant|applicant|cliente|inquilino|solicitante))(?:\s+(?i:name|nombre)(?:\s+(?i:is|es))?)?\s+(?P<name>' . $name_pattern . ')\b/u',
      static fn(array $matches): string => $matches['context'] . ' ' . self::TOKEN_NAME,
      $text
    );

    // 16. Name (compact context): "name John Smith" / "name is John Smith".
    $text = preg_replace_callback(
      '/\b(?P<context>(?i:name(?:\s+is)?))\s+(?P<name>' . $name_pattern . ')\b/u',
      static fn(array $matches): string => $matches['context'] . ' ' . self::TOKEN_NAME,
      $text
    );

    return $text;
  }

  /**
   * Redacts PII and truncates for database storage.
   *
   * @param string $text
   *   The input text.
   * @param int $maxLength
   *   Maximum output length (default 500).
   *
   * @return string
   *   Redacted, truncated, whitespace-normalized text.
   */
  public static function redactForStorage(string $text, int $maxLength = 500): string {
    $text = self::redact($text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return mb_substr($text, 0, $maxLength);
  }

  /**
   * Redacts PII and truncates for log messages.
   *
   * @param string $text
   *   The input text.
   * @param int $maxLength
   *   Maximum output length (default 100).
   *
   * @return string
   *   Redacted, truncated, whitespace-normalized text.
   */
  public static function redactForLog(string $text, int $maxLength = 100): string {
    $text = self::redact($text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return mb_substr($text, 0, $maxLength);
  }

  /**
   * Validates a number string using the Luhn algorithm.
   *
   * @param string $number
   *   Digits-only string.
   *
   * @return bool
   *   TRUE if the number passes Luhn check.
   */
  protected static function luhnCheck(string $number): bool {
    $sum = 0;
    $length = strlen($number);
    $parity = $length % 2;

    for ($i = 0; $i < $length; $i++) {
      $digit = (int) $number[$i];
      if ($i % 2 === $parity) {
        $digit *= 2;
        if ($digit > 9) {
          $digit -= 9;
        }
      }
      $sum += $digit;
    }

    return ($sum % 10) === 0;
  }

  /**
   * Returns a Unicode-aware pattern for a one- or two-part personal name.
   */
  protected static function fullNamePattern(): string {
    $word = '[\p{Lu}][\p{L}\p{M}\'-]{1,}';
    return '(?:' . $word . ')(?:\s+' . $word . ')?';
  }

}
