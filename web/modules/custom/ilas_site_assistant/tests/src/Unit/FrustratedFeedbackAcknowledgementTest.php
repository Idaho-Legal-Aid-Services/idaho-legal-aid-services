<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\ilas_site_assistant\Controller\AssistantApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Frustrated messages routed to feedback must be recognised as complaints.
 *
 * Now that eval traffic reaches the request-time classifier, "this chatbot
 * sucks" routes to the feedback intent; the feedback reply acknowledges the
 * frustration and offers the Legal Advice Line instead of a bare form link.
 */
#[Group('ilas_site_assistant')]
final class FrustratedFeedbackAcknowledgementTest extends TestCase {

  /**
   * Complaints are detected.
   */
  #[DataProvider('frustratedMessages')]
  public function testFrustrationIsDetected(string $message): void {
    $this->assertTrue(AssistantApiController::looksFrustrated($message), "Expected frustration for: $message");
  }

  /**
   * Frustrated phrasings.
   */
  public static function frustratedMessages(): array {
    return [
      'insult' => ['this chatbot sucks'],
      'useless' => ['this is useless'],
      'not helping' => ['you are not helping me at all'],
      'waste' => ['what a waste of time'],
      'frustrated' => ["I'm so frustrated with this"],
    ];
  }

  /**
   * Neutral feedback requests are not complaints.
   */
  #[DataProvider('neutralMessages')]
  public function testNeutralFeedbackIsNotFrustration(string $message): void {
    $this->assertFalse(AssistantApiController::looksFrustrated($message), "Unexpected frustration for: $message");
  }

  /**
   * Neutral phrasings.
   */
  public static function neutralMessages(): array {
    return [
      'give feedback' => ['how do I give feedback'],
      'suggestion' => ['I have a suggestion for the website'],
      'thanks' => ['thanks, that helped'],
    ];
  }

}
