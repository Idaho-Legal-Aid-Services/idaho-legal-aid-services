<?php

namespace Drupal\ilas_site_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Site Assistant admin reports.
 */
class AssistantReportController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs an AssistantReportController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Renders the admin report page.
   *
   * @return array
   *   A render array.
   */
  public function report() {
    $build = [];

    // Summary stats.
    $build['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Summary Statistics'),
      '#open' => TRUE,
    ];

    $build['summary']['table'] = $this->buildSummaryTable();

    // Top topics.
    $build['topics'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Topics Selected'),
      '#open' => TRUE,
    ];

    $build['topics']['table'] = $this->buildTopTopicsTable();

    // Top destinations.
    $build['destinations'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Clicked Destinations'),
      '#open' => TRUE,
    ];

    $build['destinations']['table'] = $this->buildTopDestinationsTable();

    // No-answer queries.
    $build['no_answer'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Gaps (No-Answer Queries)'),
      '#open' => TRUE,
    ];

    $build['no_answer']['description'] = [
      '#markup' => '<p>' . $this->t('Queries that did not find matching content. Consider creating content to address these topics.') . '</p>',
    ];

    $build['no_answer']['table'] = $this->buildNoAnswerTable();

    return $build;
  }

  /**
   * Builds the summary statistics table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildSummaryTable() {
    $header = [
      $this->t('Metric'),
      $this->t('Last 7 Days'),
      $this->t('Last 30 Days'),
      $this->t('All Time'),
    ];

    $rows = [];

    // Chat opens.
    $rows[] = [
      $this->t('Chats Opened'),
      $this->getEventCount('chat_open', 7),
      $this->getEventCount('chat_open', 30),
      $this->getEventCount('chat_open', NULL),
    ];

    // Topics selected.
    $rows[] = [
      $this->t('Topics Selected'),
      $this->getEventCount('topic_selected', 7),
      $this->getEventCount('topic_selected', 30),
      $this->getEventCount('topic_selected', NULL),
    ];

    // Resource clicks.
    $rows[] = [
      $this->t('Resource Clicks'),
      $this->getEventCount('resource_click', 7),
      $this->getEventCount('resource_click', 30),
      $this->getEventCount('resource_click', NULL),
    ];

    // Apply clicks.
    $rows[] = [
      $this->t('Apply Clicks'),
      $this->getEventCount('apply_click', 7),
      $this->getEventCount('apply_click', 30),
      $this->getEventCount('apply_click', NULL),
    ];

    // Hotline clicks.
    $rows[] = [
      $this->t('Hotline Clicks'),
      $this->getEventCount('hotline_click', 7),
      $this->getEventCount('hotline_click', 30),
      $this->getEventCount('hotline_click', NULL),
    ];

    // No-answer queries.
    $rows[] = [
      $this->t('No-Answer Queries'),
      $this->getEventCount('no_answer', 7),
      $this->getEventCount('no_answer', 30),
      $this->getEventCount('no_answer', NULL),
    ];

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No data available yet.'),
    ];
  }

  /**
   * Builds the top topics table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildTopTopicsTable() {
    $header = [
      $this->t('Topic'),
      $this->t('Count'),
    ];

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'topic_selected')
      ->groupBy('event_value')
      ->orderBy('total', 'DESC')
      ->range(0, 10);
    $query->addExpression('SUM(count)', 'total');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->event_value ?: $this->t('(unknown)'),
        $row->total,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No topic data available yet.'),
    ];
  }

  /**
   * Builds the top destinations table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildTopDestinationsTable() {
    $header = [
      $this->t('URL Path'),
      $this->t('Count'),
    ];

    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->fields('s', ['event_value'])
      ->condition('event_type', 'resource_click')
      ->groupBy('event_value')
      ->orderBy('total', 'DESC')
      ->range(0, 10);
    $query->addExpression('SUM(count)', 'total');

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->event_value ?: $this->t('(unknown)'),
        $row->total,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No destination data available yet.'),
    ];
  }

  /**
   * Builds the no-answer queries table.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildNoAnswerTable() {
    $header = [
      $this->t('Query (Sanitized)'),
      $this->t('Count'),
      $this->t('Last Seen'),
    ];

    $query = $this->database->select('ilas_site_assistant_no_answer', 'n')
      ->fields('n', ['sanitized_query', 'count', 'last_seen'])
      ->orderBy('count', 'DESC')
      ->range(0, 20);

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->sanitized_query,
        $row->count,
        $this->dateFormatter->format($row->last_seen, 'short'),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No unmatched queries recorded yet.'),
    ];
  }

  /**
   * Gets the count for an event type within a date range.
   *
   * @param string $event_type
   *   The event type.
   * @param int|null $days
   *   Number of days to look back, or NULL for all time.
   *
   * @return int
   *   The count.
   */
  protected function getEventCount(string $event_type, ?int $days) {
    $query = $this->database->select('ilas_site_assistant_stats', 's')
      ->condition('event_type', $event_type);
    $query->addExpression('SUM(count)', 'total');

    if ($days !== NULL) {
      $start_date = date('Y-m-d', strtotime("-{$days} days"));
      $query->condition('date', $start_date, '>=');
    }

    $result = $query->execute()->fetchField();
    return (int) $result;
  }

}
