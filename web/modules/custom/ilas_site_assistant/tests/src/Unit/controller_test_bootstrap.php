<?php

/**
 * @file
 * Bootstrap for controller-level unit tests.
 *
 * Defines module function stubs needed when testing AssistantApiController
 * outside of a full Drupal bootstrap.
 */

if (!function_exists('ilas_site_assistant_get_canonical_urls')) {

  /**
   * Stub for unit testing — returns default canonical URLs.
   */
  function ilas_site_assistant_get_canonical_urls(): array {
    return [
      'apply' => '/apply-for-help',
      'online_application' => 'https://example.com/intake',
      'hotline' => '/Legal-Advice-Line',
      'offices' => '/contact/offices',
      'donate' => '/donate',
      'feedback' => '/get-involved/feedback',
      'resources' => '/what-we-do/resources',
      'forms' => '/forms',
      'guides' => '/guides',
      'senior_risk_detector' => '/resources/legal-risk-detector',
      'faq' => '/faq',
      'services' => '/services',
      'service_areas' => [
        'housing' => '/legal-help/housing',
        'family' => '/legal-help/family',
        'seniors' => '/legal-help/seniors',
        'health' => '/legal-help/health',
        'consumer' => '/legal-help/consumer',
        'civil_rights' => '/legal-help/civil-rights',
      ],
    ];
  }

}
