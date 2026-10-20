<?php

declare(strict_types=1);

namespace Drupal\ilas_site_assistant_governance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\ilas_site_assistant_governance\Service\CandidateExportScheduler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reviewer surface for the weekly promptfoo candidate export.
 */
final class CandidateExportController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected CandidateExportScheduler $scheduler,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ilas_site_assistant_governance.candidate_export_scheduler'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Shows the last export run and the download / run-now actions.
   */
  public function page(): array {
    $last = $this->scheduler->getLastRun();
    $path = $this->scheduler->getRealPath();

    $rows = [
      [$this->t('Cadence'), $this->t('Weekly, on cron (also available on demand below).')],
      [$this->t('Last run'), $last ? $this->dateFormatter->format((int) $last['ran_at'], 'medium') : $this->t('Never')],
      [$this->t('Candidate cases'), $last ? (string) ($last['candidates'] ?? 0) : '-'],
      [$this->t('Items considered'), $last ? (string) ($last['considered'] ?? 0) : '-'],
      [$this->t('File'), $path !== NULL ? $this->scheduler->getUri() : $this->t('Not written yet')],
      [$this->t('Last error'), !empty($last['error']) ? (string) $last['error'] : $this->t('None')],
    ];

    $build = [
      'intro' => [
        '#markup' => '<p>' . $this->t('Reviewed and resolved gap items are exported as <em>candidate</em> promptfoo cases. Download the file, review each case for privacy and overfitting, then promote the good ones by hand into <code>promptfoo-evals/tests/</code>. Nothing here changes CI on its own.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Field'), $this->t('Value')],
        '#rows' => $rows,
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-actions']],
      ],
    ];

    if ($path !== NULL) {
      $build['actions']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download candidate YAML'),
        '#url' => Url::fromRoute('ilas_site_assistant_governance.candidate_export_download'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    if ($this->currentUser()->hasPermission('edit assistant gap items')) {
      $build['actions']['run'] = [
        '#type' => 'link',
        '#title' => $this->t('Run export now'),
        '#url' => Url::fromRoute('ilas_site_assistant_governance.candidate_export_run'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $build;
  }

  /**
   * Runs the export immediately.
   */
  public function run(): RedirectResponse {
    $summary = $this->scheduler->runIfDue(TRUE);
    if (!empty($summary['written'])) {
      $this->messenger()->addStatus($this->t('Exported @count candidate case(s).', ['@count' => (int) ($summary['candidates'] ?? 0)]));
    }
    else {
      $this->messenger()->addError($this->t('Export failed: @error', ['@error' => (string) ($summary['error'] ?? 'unknown error')]));
    }
    return $this->redirect('ilas_site_assistant_governance.candidate_export');
  }

  /**
   * Streams the candidate YAML file.
   */
  public function download(): BinaryFileResponse {
    $path = $this->scheduler->getRealPath();
    if ($path === NULL) {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/yaml; charset=utf-8'], FALSE);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, CandidateExportScheduler::FILENAME);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
