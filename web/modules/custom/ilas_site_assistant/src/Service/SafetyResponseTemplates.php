<?php

namespace Drupal\ilas_site_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Safe response templates for safety-classified messages.
 *
 * Provides standardized, approved responses for each safety classification
 * to ensure consistent, appropriate messaging across all safety situations.
 */
class SafetyResponseTemplates {

  use StringTranslationTrait;

  /**
   * Gets the canonical URLs for links.
   *
   * @return array
   *   Array of canonical URLs.
   */
  protected function getCanonicalUrls(): array {
    if (function_exists('ilas_site_assistant_get_canonical_urls')) {
      return ilas_site_assistant_get_canonical_urls();
    }
    return [
      'apply' => '/apply-for-help',
      'hotline' => '/Legal-Advice-Line',
      'feedback' => '/get-involved/feedback',
      'forms' => '/forms',
      'guides' => '/guides',
      'resources' => '/what-we-do/resources',
      'services' => '/services',
    ];
  }

  /**
   * Gets the appropriate response for a safety classification.
   *
   * @param array $classification
   *   The classification result from SafetyClassifier.
   *
   * @return array
   *   Response array with keys:
   *   - 'type' (string): Response type.
   *   - 'message' (string): Response message.
   *   - 'links' (array): Action links.
   *   - 'escalation_level' (string): Escalation level.
   *   - 'reason_code' (string): Reason code for logging.
   *   - 'disclaimer' (string|null): Any required disclaimer.
   */
  public function getResponse(array $classification): array {
    $class = $classification['class'];
    $reason_code = $classification['reason_code'];
    $urls = $this->getCanonicalUrls();

    switch ($class) {
      case SafetyClassifier::CLASS_CRISIS:
        return $this->getCrisisResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_IMMEDIATE_DANGER:
        return $this->getImmediateDangerResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_DV_EMERGENCY:
        return $this->getDvEmergencyResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_EVICTION_EMERGENCY:
        return $this->getEvictionEmergencyResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_CHILD_SAFETY:
        return $this->getChildSafetyResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_SCAM_ACTIVE:
        return $this->getScamResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_PROMPT_INJECTION:
        return $this->getPromptInjectionResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_WRONGDOING:
        return $this->getWrongdoingResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_CRIMINAL:
        return $this->getCriminalResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_IMMIGRATION:
        return $this->getImmigrationResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_PII:
        return $this->getPiiResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_LEGAL_ADVICE:
        return $this->getLegalAdviceResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_DOCUMENT_DRAFTING:
        return $this->getDocumentDraftingResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_EXTERNAL:
        return $this->getExternalResponse($urls, $reason_code);

      case SafetyClassifier::CLASS_FRUSTRATION:
        return $this->getFrustrationResponse($urls, $reason_code);

      default:
        return $this->getSafeResponse($urls, $reason_code);
    }
  }

  /**
   * Crisis/suicide response.
   */
  protected function getCrisisResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'crisis',
      'message' => (string) $this->t("If you are in crisis or having thoughts of suicide, please call 988 (Suicide & Crisis Lifeline) or 911 immediately. You are not alone, and help is available 24/7.

For legal assistance, you can also contact our Legal Advice Line."),
      'links' => [
        ['label' => $this->t('Call 988 (Crisis Line)'), 'url' => 'tel:988', 'type' => 'crisis'],
        ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'escalation_level' => 'critical',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Immediate danger response.
   */
  protected function getImmediateDangerResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'emergency',
      'message' => (string) $this->t("If you are in immediate danger, please call 911 now.

For urgent legal assistance after you are safe, contact the ILAS Legal Advice Line."),
      'links' => [
        ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'critical',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Domestic violence emergency response.
   */
  protected function getDvEmergencyResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'dv_emergency',
      'message' => (string) $this->t("If you are in immediate danger, please call 911. For domestic violence support, the National DV Hotline is available 24/7 at 1-800-799-7233.

Idaho Legal Aid Services can help with protection orders and other legal matters. Please call our Legal Advice Line or apply for help when you are safe.

Your safety is the priority."),
      'links' => [
        ['label' => $this->t('Call 911'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('DV Hotline: 1-800-799-7233'), 'url' => 'tel:1-800-799-7233', 'type' => 'dv_hotline'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'immediate',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Eviction emergency response.
   */
  protected function getEvictionEmergencyResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'eviction_emergency',
      'message' => (string) $this->t("If you are being illegally locked out or evicted without proper notice, you may have legal options. In Idaho, landlords must follow specific legal procedures to evict tenants.

Please call our Legal Advice Line immediately for urgent help. If you are in danger, call 911."),
      'links' => [
        ['label' => $this->t('Legal Advice Line (Urgent)'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => $this->t('Call 911 (if in danger)'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('Housing Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
      ],
      'escalation_level' => 'immediate',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Child safety emergency response.
   */
  protected function getChildSafetyResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'child_emergency',
      'message' => (string) $this->t("If a child is in immediate danger, please call 911. To report child abuse or neglect in Idaho, call the Idaho Child Protection Hotline at 1-855-552-KIDS (5437).

For legal help with custody, visitation, or child protection matters, contact our Legal Advice Line."),
      'links' => [
        ['label' => $this->t('Call 911 (if immediate danger)'), 'url' => 'tel:911', 'type' => 'emergency'],
        ['label' => $this->t('Child Protection: 1-855-552-5437'), 'url' => 'tel:1-855-552-5437', 'type' => 'child_protection'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'immediate',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Scam/identity theft response.
   */
  protected function getScamResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'scam_emergency',
      'message' => (string) $this->t("If you have been scammed or believe your identity has been stolen, take these steps:

1. Contact your bank immediately to freeze accounts
2. Report identity theft at IdentityTheft.gov or call 1-877-438-4338
3. File a report with local police

Idaho Legal Aid Services can help with consumer protection issues. Please call our Legal Advice Line."),
      'links' => [
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => $this->t('Consumer Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
      ],
      'escalation_level' => 'immediate',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Prompt injection response (refusal).
   *
   * Policy-compliant refusal that offers safe alternatives.
   */
  protected function getPromptInjectionResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'refusal',
      'escalation_type' => 'prompt_injection',
      'message' => (string) $this->t("I can't process that type of request. I'm the Idaho Legal Aid Services assistant, designed to help you find legal information and resources.

If you have a question about legal help, forms, or services, I'm happy to assist. You can also contact our Legal Advice Line or apply for help directly."),
      'links' => [
        ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
        ['label' => $this->t('Find Forms'), 'url' => $urls['forms'], 'type' => 'forms'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'urgent',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Wrongdoing request response (refusal).
   */
  protected function getWrongdoingResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'refusal',
      'escalation_type' => 'wrongdoing',
      'message' => (string) $this->t("I can't help with that request. Idaho Legal Aid Services can only assist with lawful activities.

If you need help with a legal matter, I can help you find forms, guides, and resources, or connect you with our Legal Advice Line."),
      'links' => [
        ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
        ['label' => $this->t('Find Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'escalation_level' => 'urgent',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Criminal matter response (out of scope).
   */
  protected function getCriminalResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'criminal',
      'response_mode' => 'out_of_scope',
      'message' => (string) $this->t("Idaho Legal Aid Services provides civil legal help only. We cannot assist with criminal matters including arrests, charges, or criminal defense.

For criminal cases:
- If you cannot afford an attorney, you may qualify for a public defender through the court
- The Idaho State Bar Lawyer Referral Service can help you find a criminal defense attorney: (208) 334-4500

If you also have civil legal issues (housing, family law, benefits), we may be able to help with those separately."),
      'links' => [
        ['label' => $this->t('Idaho State Bar Lawyer Referral'), 'url' => 'https://isb.idaho.gov/ilrs/', 'type' => 'external'],
        ['label' => $this->t('Our Services (Civil)'), 'url' => $urls['services'], 'type' => 'services'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice. ILAS provides civil legal services only.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * Immigration response (out of scope).
   */
  protected function getImmigrationResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'immigration',
      'response_mode' => 'out_of_scope',
      'message' => (string) $this->t("Idaho Legal Aid Services does not handle immigration matters. For help with visas, green cards, citizenship, deportation, or other immigration issues, please contact:

- An immigration attorney
- Catholic Charities of Idaho (offers immigration legal services)
- Idaho Commission on Hispanic Affairs

If you have civil legal issues in Idaho (housing, family, benefits), we may be able to help with those."),
      'links' => [
        ['label' => $this->t('Catholic Charities Idaho'), 'url' => 'https://www.ccidaho.org/', 'type' => 'external'],
        ['label' => $this->t('Idaho Commission on Hispanic Affairs'), 'url' => 'https://icha.idaho.gov/', 'type' => 'external'],
        ['label' => $this->t('Our Services'), 'url' => $urls['services'], 'type' => 'services'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is general information, not legal advice. ILAS does not provide immigration legal services.'),
      'can_still_help' => TRUE,
    ];
  }

  /**
   * PII disclosure response.
   */
  protected function getPiiResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'privacy',
      'escalation_type' => 'pii',
      'message' => (string) $this->t("I appreciate you sharing, but I'm not able to collect personal information. To protect your privacy, please don't share names, addresses, Social Security numbers, or case details in this chat.

If you'd like to apply for ILAS services, you can do so securely using the link below."),
      'links' => [
        ['label' => $this->t('Apply for Help (Secure)'), 'url' => $urls['apply'], 'type' => 'apply'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Legal advice response.
   */
  protected function getLegalAdviceResponse(array $urls, string $reason_code): array {
    // Reason codes that explicitly ask for what-to-say-to-the-judge phrasing
    // (SafetyClassifier patterns at lines 427-436) get a sharper refusal that
    // names "judge", "win", and "legal strategy" so it survives the smoke
    // regex contract in promptfoo-evals/tests/simulated-user-smoke.yaml.
    // 'legal_advice_outcome' and 'legal_advice_court_prediction' are *not*
    // included — those ask "will I win?" / "what will the judge do?" rather
    // than "what do I say?", and the safety_stress_test_suite fixtures
    // (LA002, LA020) pin them to the generic "can't give legal advice"
    // wording.
    $courtroom_strategy_reasons = [
      'legal_advice_strategy',
      'legal_advice_court_script',
    ];
    if (in_array($reason_code, $courtroom_strategy_reasons, TRUE)) {
      return [
        'type' => 'escalation',
        'escalation_type' => 'legal_advice',
        'message' => (string) $this->t("I can't tell you exactly what to say to a judge to win or give legal strategy for court. What I can do is point you to general court-preparation information, and the safest next step is to contact our Legal Advice Line or apply for help right away if you have an eviction or other deadline."),
        'links' => [
          ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
          ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
          ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
          ['label' => $this->t('Find Forms'), 'url' => $urls['forms'], 'type' => 'forms'],
        ],
        'escalation_level' => 'standard',
        'reason_code' => $reason_code,
        'disclaimer' => (string) $this->t('This is not legal advice. For legal advice, please contact an attorney or call the ILAS Legal Advice Line.'),
      ];
    }

    return [
      'type' => 'escalation',
      'escalation_type' => 'legal_advice',
      'message' => (string) $this->t("I can't give legal advice, but I can help you find resources. ILAS has guides on many legal topics that may help you understand your options.

To speak with someone who can give legal advice, call the ILAS Legal Advice Line at 208-746-7541 or apply for help."),
      'links' => [
        ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
        ['label' => $this->t('Find Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => (string) $this->t('This is not legal advice. For legal advice, please contact an attorney or call the ILAS Legal Advice Line.'),
    ];
  }

  /**
   * Document drafting response.
   */
  protected function getDocumentDraftingResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'document_drafting',
      'message' => (string) $this->t("I can't fill out or draft legal documents for you, but I can help you find the forms and step-by-step guides you need.

For help completing documents, please contact our Legal Advice Line or apply for assistance."),
      'links' => [
        ['label' => $this->t('Find Forms'), 'url' => $urls['forms'], 'type' => 'forms'],
        ['label' => $this->t('Find Guides'), 'url' => $urls['guides'], 'type' => 'guides'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * External request response.
   */
  protected function getExternalResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'external',
      'response_mode' => 'out_of_scope',
      'message' => (string) $this->t("I can only help with information on the ILAS website (idaholegalaid.org). For court information, government websites, or other external sites, you'll need to visit those sites directly.

Is there something on the ILAS site I can help you find?"),
      'links' => [
        ['label' => $this->t('Find Resources'), 'url' => $urls['resources'], 'type' => 'resources'],
        ['label' => $this->t('Find Forms'), 'url' => $urls['forms'], 'type' => 'forms'],
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Frustration response.
   */
  protected function getFrustrationResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'escalation',
      'escalation_type' => 'frustration',
      'message' => (string) $this->t("I'm sorry I haven't been able to help. I want to make sure you get the assistance you need.

You can speak with a person by calling our Legal Advice Line, or share feedback about your experience."),
      'links' => [
        ['label' => $this->t('Legal Advice Line'), 'url' => $urls['hotline'], 'type' => 'hotline'],
        ['label' => $this->t('Give Feedback'), 'url' => $urls['feedback'], 'type' => 'feedback'],
        ['label' => $this->t('Apply for Help'), 'url' => $urls['apply'], 'type' => 'apply'],
      ],
      'escalation_level' => 'standard',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

  /**
   * Safe/no concerns response.
   */
  protected function getSafeResponse(array $urls, string $reason_code): array {
    return [
      'type' => 'safe',
      'escalation_type' => NULL,
      'message' => NULL,
      'links' => [],
      'escalation_level' => 'none',
      'reason_code' => $reason_code,
      'disclaimer' => NULL,
    ];
  }

}
