<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Service\ResponseBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tier E: Spanish detection + bilingual response postscript.
 *
 * Verifies ResponseBuilder appends Spanish action-rich text when the user
 * input is detected as Spanish, so promptfoo's
 * `isSpanishOrBilingualUseful` and `quality-spanish-urgent-next-step`
 * assertions can match Spanish action keywords (llame, línea, solicite,
 * ayuda) in the response body without weakening English coverage.
 */
#[Group('ilas_site_assistant')]
final class ResponseBuilderSpanishTest extends TestCase {

  private ResponseBuilder $builder;

  /**
   * Boots a ResponseBuilder with the canonical action URL map.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->builder = new ResponseBuilder([
      'apply' => 'https://idaholegalaid.org/apply-for-help',
      'hotline' => 'https://idaholegalaid.org/legal-advice-line',
      'forms' => 'https://idaholegalaid.org/forms',
      'faq' => 'https://idaholegalaid.org/faq',
      'services' => 'https://idaholegalaid.org/services',
      'resources' => 'https://idaholegalaid.org/resources',
      'offices' => 'https://idaholegalaid.org/offices',
      'service_areas' => [],
    ]);
  }

  /**
   * Spanish topic-intent input gets a bilingual postscript with action cues.
   */
  public function testSpanishInputAppendsBilingualPostscriptOnTopic(): void {
    $response = $this->builder->buildFromIntent(
      ['type' => 'topic', 'confidence' => 0.85],
      'Necesito ayuda con un desalojo'
    );

    $this->assertSame('topic', $response['type']);
    $this->assertNotEmpty($response['primary_action']['url']);
    $text = $response['answer_text'] ?? '';
    $this->assertStringContainsStringIgnoringCase('línea', $text);
    $this->assertStringContainsStringIgnoringCase('ayuda', $text);
    $this->assertStringContainsStringIgnoringCase('solicite', $text);
  }

  /**
   * Spanish clarify-intent input still receives the bilingual postscript.
   */
  public function testSpanishInputAppendsBilingualPostscriptOnClarify(): void {
    $response = $this->builder->buildFromIntent(
      ['type' => 'clarify', 'confidence' => 0.30],
      '¿Tengo audiencia mañana en corte?'
    );

    $text = $response['answer_text'] ?? '';
    $this->assertStringContainsStringIgnoringCase('llame', $text);
    $this->assertStringContainsStringIgnoringCase('línea', $text);
  }

  /**
   * English input must not trigger the Spanish postscript text.
   */
  public function testEnglishInputDoesNotEmitSpanishPostscript(): void {
    $response = $this->builder->buildFromIntent(
      ['type' => 'topic', 'confidence' => 0.85],
      'I need help with eviction'
    );

    $text = $response['answer_text'] ?? '';
    $this->assertStringNotContainsString('Línea de Consejos Legales', $text);
    $this->assertStringNotContainsString('solicite', $text);
  }

  /**
   * looksLikeSpanish() returns TRUE when input contains Spanish accents.
   */
  public function testSpanishDetectorFiresOnAccents(): void {
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('¿Dónde está la oficina?'));
  }

  /**
   * looksLikeSpanish() returns TRUE for Spanish legal cue words sans accents.
   */
  public function testSpanishDetectorFiresOnLegalCueWords(): void {
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('necesito un abogado'));
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('Aviso de desalojo'));
  }

  /**
   * looksLikeSpanish() returns FALSE for plain English sentences.
   */
  public function testSpanishDetectorIgnoresPlainEnglish(): void {
    $this->assertFalse(ResponseBuilder::looksLikeSpanish('I got an eviction notice'));
    $this->assertFalse(ResponseBuilder::looksLikeSpanish('What free legal services do you offer?'));
  }

  /**
   * "gracias" alone marks a mixed-language message as Spanish.
   */
  public function testSpanishDetectorFiresOnGracias(): void {
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('gracias you guys are really helpful'));
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('muchas gracias por la informacion'));
    $this->assertTrue(ResponseBuilder::looksLikeSpanish('donde estan sus oficinas'));
  }

  /**
   * A Spanish thank-you gets the bilingual next-step postscript.
   */
  public function testSpanishThanksAppendsBilingualPostscript(): void {
    $response = $this->builder->buildFromIntent(
      ['type' => 'thanks', 'confidence' => 0.95],
      'muchas gracias por la informacion'
    );

    $this->assertSame('acknowledgement', $response['type']);
    $text = $response['answer_text'] ?? '';
    $this->assertStringContainsStringIgnoringCase('línea', $text);
    $this->assertStringContainsStringIgnoringCase('ayuda', $text);
    $this->assertStringContainsStringIgnoringCase('solicite', $text);
  }

  /**
   * An English thank-you carries no Spanish postscript.
   */
  public function testEnglishThanksHasNoSpanishPostscript(): void {
    $response = $this->builder->buildFromIntent(['type' => 'thanks', 'confidence' => 0.95], 'thanks!');

    $this->assertStringNotContainsString('Si prefiere', $response['answer_text'] ?? '');
  }

}
