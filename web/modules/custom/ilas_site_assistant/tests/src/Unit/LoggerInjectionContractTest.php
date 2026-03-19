<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\ilas_site_assistant\Service\AnalyticsLogger;
use Drupal\ilas_site_assistant\Service\ConversationLogger;
use Drupal\ilas_site_assistant\Service\ObservabilityPayloadMinimizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies analytics and conversation loggers use injected logger channels.
 */
#[CoversClass(AnalyticsLogger::class)]
#[CoversClass(ConversationLogger::class)]
#[Group('ilas_site_assistant')]
class LoggerInjectionContractTest extends TestCase {

  public function testAnalyticsLoggerLogsStatsWriteFailuresThroughInjectedLogger(): void {
    $exception = new \Exception('DB connection lost');
    $update = new class($exception) {

      public function __construct(private \Exception $exception) {}

      public function expression(string $field, string $expression): self {
        return $this;
      }

      public function condition(string $field, mixed $value, ?string $operator = NULL): self {
        return $this;
      }

      public function execute(): int {
        throw $this->exception;
      }

    };

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('update')
      ->with('ilas_site_assistant_stats')
      ->willReturn($update);
    $database->expects($this->never())->method('insert');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Analytics logging failed: @class @error_signature', [
        '@class' => \Exception::class,
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($exception),
      ]);

    $service = new AnalyticsLogger(
      $database,
      $this->createConfigFactory(['enable_logging' => TRUE]),
      $this->createTime(),
      $logger
    );

    $service->log('chat_open', '');
  }

  public function testAnalyticsLoggerLogsNoAnswerFailuresThroughInjectedLogger(): void {
    $exception = new \Exception('No-answer write failed');
    $failedNoAnswerUpdate = new class($exception) {

      public function __construct(private \Exception $exception) {}

      public function expression(string $field, string $expression): self {
        return $this;
      }

      public function fields(array $fields): self {
        return $this;
      }

      public function condition(string $field, mixed $value, ?string $operator = NULL): self {
        return $this;
      }

      public function execute(): int {
        throw $this->exception;
      }

    };
    $successfulStatsUpdate = new class {

      public function expression(string $field, string $expression): self {
        return $this;
      }

      public function condition(string $field, mixed $value, ?string $operator = NULL): self {
        return $this;
      }

      public function execute(): int {
        return 1;
      }

    };

    $database = $this->createMock(Connection::class);
    $database->expects($this->exactly(2))
      ->method('update')
      ->willReturnCallback(static function (string $table) use ($failedNoAnswerUpdate, $successfulStatsUpdate) {
        return match ($table) {
          'ilas_site_assistant_no_answer' => $failedNoAnswerUpdate,
          'ilas_site_assistant_stats' => $successfulStatsUpdate,
        };
      });
    $database->expects($this->never())->method('insert');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('No-answer logging failed: @class @error_signature', [
        '@class' => \Exception::class,
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($exception),
      ]);

    $service = new AnalyticsLogger(
      $database,
      $this->createConfigFactory(['enable_logging' => TRUE]),
      $this->createTime(),
      $logger
    );

    $service->logNoAnswer('eviction help near me');
  }

  public function testAnalyticsLoggerLogsCleanupFailuresThroughInjectedLogger(): void {
    $exception = new \Exception('Cleanup read failed');
    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('select')
      ->with('ilas_site_assistant_stats', 's')
      ->willThrowException($exception);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Analytics cleanup failed: @class @error_signature', [
        '@class' => \Exception::class,
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($exception),
      ]);

    $service = new AnalyticsLogger(
      $database,
      $this->createConfigFactory(['log_retention_days' => 90]),
      $this->createTime(),
      $logger
    );

    $service->cleanupOldData();
  }

  public function testConversationLoggerLogsExchangeFailuresThroughInjectedLogger(): void {
    $exception = new \Exception('DB write failed');
    $schema = $this->createStub(Schema::class);
    $schema->method('tableExists')->with('ilas_site_assistant_conversations')->willReturn(TRUE);

    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->expects($this->once())
      ->method('insert')
      ->with('ilas_site_assistant_conversations')
      ->willThrowException($exception);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Conversation logging failed: @class @error_signature', [
        '@class' => \Exception::class,
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($exception),
      ]);

    $service = new ConversationLogger(
      $database,
      $this->createConfigFactory(['conversation_logging.enabled' => TRUE]),
      $this->createTime(),
      $logger
    );

    $service->logExchange(
      '11111111-1111-4111-8111-111111111111',
      'Need eviction help',
      'Here is a resource.',
      'faq',
      'faq'
    );
  }

  public function testConversationLoggerLogsCleanupFailuresThroughInjectedLogger(): void {
    $exception = new \Exception('Cleanup query failed');
    $schema = $this->createStub(Schema::class);
    $schema->method('tableExists')->with('ilas_site_assistant_conversations')->willReturn(TRUE);

    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->expects($this->once())
      ->method('select')
      ->with('ilas_site_assistant_conversations', 'c')
      ->willThrowException($exception);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Conversation cleanup failed: @class @error_signature', [
        '@class' => \Exception::class,
        '@error_signature' => ObservabilityPayloadMinimizer::exceptionSignature($exception),
      ]);

    $service = new ConversationLogger(
      $database,
      $this->createConfigFactory([
        'conversation_logging.enabled' => TRUE,
        'conversation_logging.retention_hours' => 72,
      ]),
      $this->createTime(),
      $logger
    );

    $service->cleanup();
  }

  public function testConversationLoggerResolvedConfigForcesPrivacyInvariants(): void {
    $database = $this->createStub(Connection::class);
    $logger = $this->createStub(LoggerInterface::class);

    $service = new ConversationLogger(
      $database,
      $this->createConfigFactory([
        'conversation_logging.enabled' => TRUE,
        'conversation_logging.retention_hours' => 9999,
        'conversation_logging.redact_pii' => FALSE,
        'conversation_logging.show_user_notice' => FALSE,
      ]),
      $this->createTime(),
      $logger
    );

    $resolved = $service->getResolvedConfig();

    $this->assertTrue($resolved['enabled']);
    $this->assertSame(ConversationLogger::MAX_RETENTION_HOURS, $resolved['retention_hours']);
    $this->assertTrue($resolved['redact_pii']);
    $this->assertTrue($resolved['show_user_notice']);
  }

  /**
   * Creates a config factory stub with the provided values.
   */
  private function createConfigFactory(array $values): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key) => $values[$key] ?? NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Creates a fixed time stub.
   */
  private function createTime(int $timestamp = 1700000000): TimeInterface {
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($timestamp);
    return $time;
  }

}
