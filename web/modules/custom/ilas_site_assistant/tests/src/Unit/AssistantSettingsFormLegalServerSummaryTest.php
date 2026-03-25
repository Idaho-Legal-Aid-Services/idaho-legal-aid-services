<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ilas_site_assistant\Form\AssistantSettingsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LegalServer runtime messaging on the settings form.
 */
#[Group('ilas_site_assistant')]
final class AssistantSettingsFormLegalServerSummaryTest extends TestCase {

  /**
   * The form summarizes missing runtime LegalServer config without the URL.
   */
  public function testRuntimeNoticeSummarizesMissingLegalServerConfig(): void {
    $form = $this->buildFormHarness();

    $notice = $form->publicBuildLegalServerRuntimeNotice(FALSE, [
      'configured' => FALSE,
      'https' => FALSE,
      'required_query_keys' => [
        'pid' => FALSE,
        'h' => FALSE,
      ],
    ]);

    $this->assertStringContainsString('ILAS_LEGALSERVER_ONLINE_APPLICATION_URL', $notice);
    $this->assertStringContainsString('Current validation: <strong>missing</strong>.', $notice);
  }

  /**
   * The form summarizes non-secret validation failures for configured URLs.
   */
  public function testRuntimeNoticeSummarizesLegalServerValidationFailures(): void {
    $form = $this->buildFormHarness();

    $notice = $form->publicBuildLegalServerRuntimeNotice(TRUE, [
      'configured' => TRUE,
      'https' => FALSE,
      'required_query_keys' => [
        'pid' => FALSE,
        'h' => TRUE,
      ],
    ]);

    $this->assertStringContainsString('Validation: <strong>non_https, missing_pid</strong>.', $notice);
  }

  /**
   * The form reports healthy LegalServer runtime validation clearly.
   */
  public function testRuntimeNoticeSummarizesHealthyLegalServerValidation(): void {
    $form = $this->buildFormHarness();

    $notice = $form->publicBuildLegalServerRuntimeNotice(TRUE, [
      'configured' => TRUE,
      'https' => TRUE,
      'required_query_keys' => [
        'pid' => TRUE,
        'h' => TRUE,
      ],
    ]);

    $this->assertStringContainsString('Validation: <strong>healthy</strong>.', $notice);
  }

  /**
   * Builds a form harness that exposes the protected summary helpers.
   */
  private function buildFormHarness(): AssistantSettingsForm {
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $typedConfigManager = $this->createStub(TypedConfigManagerInterface::class);

    $form = new class($configFactory, $typedConfigManager) extends AssistantSettingsForm {

      public function publicBuildLegalServerRuntimeNotice(bool $runtimeConfigured, ?array $check): string {
        return $this->buildLegalServerRuntimeNotice($runtimeConfigured, $check);
      }

    };

    $form->setStringTranslation(new class implements TranslationInterface {

      public function translate($string, array $args = [], array $options = []) {
        return strtr((string) $string, array_map(static fn(mixed $value): string => (string) $value, $args));
      }

      public function translateString($translated_string, array $options = []) {
        if (is_object($translated_string) && method_exists($translated_string, 'getUntranslatedString')) {
          $string = (string) $translated_string->getUntranslatedString();
          $args = method_exists($translated_string, 'getArguments')
            ? $translated_string->getArguments()
            : [];
          return strtr($string, array_map(static fn(mixed $value): string => (string) $value, $args));
        }

        return (string) $translated_string;
      }

      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
        $template = $count == 1 ? $singular : $plural;
        return strtr((string) $template, array_map(static fn(mixed $value): string => (string) $value, $args));
      }

    });

    return $form;
  }

}
