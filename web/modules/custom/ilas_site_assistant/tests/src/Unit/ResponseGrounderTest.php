<?php

namespace Drupal\Tests\ilas_site_assistant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ResponseGrounder.
 *
 * Covers:
 * - F-12: Citation URL behavior
 * - F-13: _requires_review enforcement for legal-advice patterns
 * - Official phone/address validation
 * - Caveat logic
 * - Complete grounding flows
 */
#[CoversClass(ResponseGrounder::class)]
#[Group('ilas_site_assistant')]
class ResponseGrounderTest extends TestCase {

  /**
   * The service under test.
   */
  protected ResponseGrounder $grounder;

  /**
   * Builds a source-governance service with default citation policy.
   */
  private function buildSourceGovernanceService(): SourceGovernanceService {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key) => $key === 'source_governance' ? NULL : NULL);

    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ilas_site_assistant.settings')
      ->willReturn($config);

    $state = $this->createStub(StateInterface::class);
    $logger = $this->createStub(LoggerInterface::class);

    return new SourceGovernanceService($configFactory, $state, $logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->grounder = new ResponseGrounder($this->buildSourceGovernanceService());
  }

  // -----------------------------------------------------------------------
  // addCitations() tests
  // -----------------------------------------------------------------------

  /**
   * Tests that addCitations adds sources array from results with URLs.
   *   */
  public function testAddCitationsGeneratesSourcesArray(): void {
    $results = [
      ['title' => 'Eviction Guide', 'url' => 'https://idaholegalaid.org/guides/eviction', 'type' => 'guide'],
      ['title' => 'Tenant Rights', 'url' => 'https://idaholegalaid.org/guides/rights', 'type' => 'resource'],
      ['title' => 'FAQ Entry', 'url' => '/faq/apply#entry', 'type' => 'faq'],
    ];

    $response = ['message' => 'Some safe info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('sources', $result);
    $this->assertCount(3, $result['sources']);
    $this->assertEquals('Eviction Guide', $result['sources'][0]['title']);
    $this->assertEquals('https://idaholegalaid.org/guides/eviction', $result['sources'][0]['url']);
  }

  /**
   * Tests that addCitations limits sources to 3.
   *   */
  public function testAddCitationsMaxThreeSources(): void {
    $results = [];
    for ($i = 1; $i <= 5; $i++) {
      $results[] = ['title' => "Result $i", 'url' => "/resources/$i"];
    }

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertCount(3, $result['sources']);
  }

  /**
   * Tests that results without URLs are excluded from sources.
   *   */
  public function testAddCitationsExcludesResultsWithoutUrls(): void {
    $results = [
      ['title' => 'Has URL', 'url' => '/faq#page'],
      ['title' => 'No URL'],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertCount(1, $result['sources']);
    $this->assertEquals('Has URL', $result['sources'][0]['title']);
  }

  /**
   * Tests that unsafe or off-domain citation URLs are stripped.
   *   */
  #[DataProvider('disallowedCitationUrlProvider')]
  public function testCitationUrlRejectsDisallowedValues(string $url): void {
    $results = [
      ['title' => 'Unsafe', 'url' => $url],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayNotHasKey('sources', $result);
    $this->assertArrayNotHasKey('citation_text', $result);
  }

  /**
   * Tests that approved ILAS citation URLs are preserved.
   *   */
  #[DataProvider('allowedCitationUrlProvider')]
  public function testCitationUrlAllowsApprovedValues(string $url): void {
    $results = [
      ['title' => 'Approved', 'url' => $url],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('sources', $result);
    $this->assertSame($url, $result['sources'][0]['url']);
  }

  /**
   * Tests mixed citation batches keep only approved URLs and still cap at 3.
   *   */
  public function testAddCitationsFiltersMixedUrlsAndCapsAtThree(): void {
    $results = [
      ['title' => 'Bad JS', 'url' => 'javascript:alert(1)'],
      ['title' => 'Allowed 1', 'url' => '/faq#housing'],
      ['title' => 'Bad Off Domain', 'url' => 'https://attacker.example.com/phish'],
      ['title' => 'Allowed 2', 'url' => 'https://idaholegalaid.org/guides/tenant-rights'],
      ['title' => 'Allowed 3', 'url' => '/forms'],
      ['title' => 'Allowed 4', 'url' => '/services'],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('sources', $result);
    $this->assertCount(3, $result['sources']);
    $this->assertSame(['Allowed 1', 'Allowed 2', 'Allowed 3'], array_column($result['sources'], 'title'));
    $this->assertSame(['/faq#housing', 'https://idaholegalaid.org/guides/tenant-rights', '/forms'], array_column($result['sources'], 'url'));
  }

  /**
   * Tests that citation text is generated.
   *   */
  public function testAddCitationsGeneratesCitationText(): void {
    $results = [
      ['title' => 'Guide One', 'url' => '/guides/1'],
      ['title' => 'Guide Two', 'url' => '/guides/2'],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('citation_text', $result);
    $this->assertStringContainsString('Guide One', $result['citation_text']);
    $this->assertStringContainsString('Guide Two', $result['citation_text']);
  }

  /**
   * Tests that long titles are truncated in citations.
   *   */
  public function testCitationTitleTruncation(): void {
    $long_title = str_repeat('A', 100);
    $results = [['title' => $long_title, 'url' => '/guides/long']];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertLessThanOrEqual(60, mb_strlen($result['sources'][0]['title']));
  }

  public static function allowedCitationUrlProvider(): array {
    return [
      'relative' => ['/faq#housing'],
      'absolute ilas' => ['https://idaholegalaid.org/faq/housing'],
    ];
  }

  public static function disallowedCitationUrlProvider(): array {
    return [
      'javascript' => ['javascript:alert(1)'],
      'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
      'off-domain' => ['https://attacker.example.com/phish'],
      'malformed' => ['not a valid url'],
      'protocol-relative' => ['//attacker.example.com/phish'],
      'http ilas' => ['http://idaholegalaid.org/faq/housing'],
      'http www ilas' => ['http://www.idaholegalaid.org/faq/housing'],
    ];
  }

  // -----------------------------------------------------------------------
  // validateInformation() tests
  // -----------------------------------------------------------------------

  /**
   * Tests that unofficial phone numbers are replaced with safe text.
   *   */
  public function testValidateInfoReplacesUnofficialPhones(): void {
    $response = [
      'message' => 'Call (555) 123-4567 for help.',
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertStringNotContainsString('(555) 123-4567', $result['message']);
    $this->assertStringContainsString('contact information available on our website', $result['message']);
    $this->assertArrayHasKey('_validation_warnings', $result);
  }

  /**
   * Tests that official phone numbers are preserved.
   *   */
  #[DataProvider('officialPhoneProvider')]
  public function testValidateInfoPreservesOfficialPhones(string $phone): void {
    $response = [
      'message' => "Call $phone for help.",
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertStringContainsString($phone, $result['message']);
  }

  public static function officialPhoneProvider(): array {
    return [
      'Boise' => ['(208) 345-0106'],
      'Pocatello' => ['(208) 233-0079'],
      'Twin Falls' => ['(208) 734-7024'],
      'Lewiston (hotline)' => ['(208) 746-7541'],
      'Idaho Falls' => ['(208) 524-3660'],
    ];
  }

  /**
   * Tests that legal-advice patterns set _requires_review = TRUE (F-13).
   *   */
  #[DataProvider('legalAdvicePatternProvider')]
  public function testValidateInfoSetsRequiresReviewForLegalAdvice(string $message): void {
    $response = [
      'message' => $message,
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertTrue(
      $result['_requires_review'] ?? FALSE,
      "Expected _requires_review=TRUE for: $message"
    );
  }

  public static function legalAdvicePatternProvider(): array {
    return [
      'you should file' => ['you should file a complaint with the court'],
      'you must go to court' => ['you must go to court to contest this'],
      'your case will win' => ['your case will win if you present this evidence'],
      'your case should succeed' => ['your case should succeed based on these facts'],
      'the judge will likely' => ['the judge will likely rule in your favor'],
      'you cannot be deported' => ['you cannot be deported if you have this status'],
    ];
  }

  /**
   * Tests that safe text does NOT trigger _requires_review.
   *   */
  public function testValidateInfoSafeTextNoReview(): void {
    $response = [
      'message' => 'Idaho Legal Aid provides free legal help to low-income Idahoans.',
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertFalse($result['_requires_review'] ?? FALSE);
  }

  // -----------------------------------------------------------------------
  // addCaveats() tests
  // -----------------------------------------------------------------------

  /**
   * Tests that FAQ/resources/topic types get caveat.
   *   */
  #[DataProvider('caveatTypeProvider')]
  public function testAddCaveatsForContentTypes(string $type): void {
    $response = [
      'message' => 'Some information.',
      'type' => $type,
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayHasKey('caveat', $result);
    $this->assertStringContainsString('general guidance', $result['caveat']);
  }

  public static function caveatTypeProvider(): array {
    return [
      'faq' => ['faq'],
      'resources' => ['resources'],
      'topic' => ['topic'],
      'eligibility' => ['eligibility'],
      'services_overview' => ['services_overview'],
    ];
  }

  /**
   * Tests that emergency-type responses do NOT get caveat.
   *   */
  public function testNoCaveatForEmergencyType(): void {
    $response = [
      'message' => 'Call 911 immediately.',
      'type' => 'emergency',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayNotHasKey('caveat', $result);
  }

  /**
   * Tests that escalation-type responses do NOT get caveat.
   *   */
  public function testNoCaveatForEscalationType(): void {
    $response = [
      'message' => 'Please call our hotline.',
      'type' => 'escalation',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayNotHasKey('caveat', $result);
  }

  /**
   * Tests eligibility caveat added for eligibility type.
   *   */
  public function testEligibilityCaveatAdded(): void {
    $response = [
      'message' => 'You may be eligible for our services.',
      'type' => 'eligibility',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayHasKey('eligibility_caveat', $result);
    $this->assertStringContainsString('Applying is the best way', $result['eligibility_caveat']);
  }

  // -----------------------------------------------------------------------
  // isOfficialPhone() tests
  // -----------------------------------------------------------------------

  /**
   * Tests all official phone numbers are recognized.
   *   */
  public function testAllOfficialPhonesRecognized(): void {
    // We test via validateInformation — official phones should NOT be flagged.
    $official_numbers = [
      '(208) 345-0106',  // Boise
      '(208) 233-0079',  // Pocatello
      '(208) 734-7024',  // Twin Falls
      '(208) 746-7541',  // Lewiston / Hotline
      '(208) 524-3660',  // Idaho Falls
    ];

    foreach ($official_numbers as $number) {
      $response = [
        'message' => "Contact us at $number.",
        'type' => 'faq',
      ];
      $result = $this->grounder->groundResponse($response);
      $this->assertStringContainsString(
        $number,
        $result['message'],
        "Official number $number should be preserved"
      );
    }
  }

  /**
   * Tests toll-free number recognized as official.
   *   */
  public function testTollFreeRecognizedAsOfficial(): void {
    $response = [
      'message' => 'Call 1-866-345-0106 toll-free.',
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    // The toll-free number should not be flagged as unofficial.
    $this->assertArrayNotHasKey('_validation_warnings', $result);
  }

  // -----------------------------------------------------------------------
  // isOfficialAddress() tests
  // -----------------------------------------------------------------------

  /**
   * Tests official address match.
   *   */
  public function testOfficialAddressNotFlagged(): void {
    $response = [
      'message' => 'Visit us at 310 N 5th Street, Boise, ID 83702.',
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayNotHasKey('address_caveat', $result);
  }

  /**
   * Tests non-official address flagged.
   *   */
  public function testNonOfficialAddressFlagged(): void {
    $response = [
      'message' => 'Visit us at 999 Fake Boulevard, Boise, ID 83701.',
      'type' => 'faq',
    ];

    $result = $this->grounder->groundResponse($response);

    $this->assertArrayHasKey('address_caveat', $result);
    $this->assertStringContainsString('offices page', $result['address_caveat']);
  }

  // -----------------------------------------------------------------------
  // groundFaqResponse() — complete flow
  // -----------------------------------------------------------------------

  /**
   * Tests groundFaqResponse complete flow.
   *   */
  public function testGroundFaqResponseFlow(): void {
    $faq = [
      'question' => 'How do I apply?',
      'answer' => 'You can apply online or by phone.',
      'url' => 'https://idaholegalaid.org/faq/apply',
    ];

    $result = $this->grounder->groundFaqResponse($faq);

    $this->assertEquals('faq', $result['type']);
    $this->assertEquals('You can apply online or by phone.', $result['message']);
    $this->assertArrayHasKey('sources', $result);
    $this->assertArrayHasKey('caveat', $result);
    $this->assertTrue($result['_grounded']);
    $this->assertEquals('1.0', $result['_grounding_version']);
  }

  /**
   * Tests groundFaqResponse strips disallowed legacy source URLs.
   *   */
  public function testGroundFaqResponseStripsDisallowedLegacyUrl(): void {
    $faq = [
      'question' => 'Unsafe source',
      'answer' => 'Use the FAQ page.',
      'url' => 'https://attacker.example.com/phish',
    ];

    $result = $this->grounder->groundFaqResponse($faq);

    $this->assertArrayNotHasKey('url', $result);
    $this->assertArrayNotHasKey('sources', $result);
  }

  // -----------------------------------------------------------------------
  // groundResourceResponse() — complete flow
  // -----------------------------------------------------------------------

  /**
   * Tests groundResourceResponse complete flow.
   *   */
  public function testGroundResourceResponseFlow(): void {
    $resources = [
      ['title' => 'Eviction Guide', 'url' => 'https://idaholegalaid.org/guides/eviction'],
      ['title' => 'Tenant Rights', 'url' => 'https://idaholegalaid.org/guides/tenant-rights'],
    ];

    $result = $this->grounder->groundResourceResponse($resources);

    $this->assertEquals('resources', $result['type']);
    $this->assertStringContainsString('resources that might help', $result['message']);
    $this->assertArrayHasKey('sources', $result);
    $this->assertCount(2, $result['sources']);
    $this->assertArrayHasKey('caveat', $result);
    $this->assertTrue($result['_grounded']);
  }

  /**
   * Tests groundResourceResponse with custom intro message.
   *   */
  public function testGroundResourceResponseCustomIntro(): void {
    $resources = [
      ['title' => 'Form A', 'url' => 'https://idaholegalaid.org/forms/a'],
    ];

    $result = $this->grounder->groundResourceResponse($resources, 'Here are some forms:');

    $this->assertEquals('Here are some forms:', $result['message']);
  }

  // -----------------------------------------------------------------------
  // validateGrounding() tests
  // -----------------------------------------------------------------------

  /**
   * Tests validateGrounding flags phone not in corpus.
   *   */
  public function testValidateGroundingFlagsPhoneNotInCorpus(): void {
    $content = [
      ['answer' => 'Call us for help with housing.'],
    ];

    $result = $this->grounder->validateGrounding(
      'Call (555) 987-6543 for help.',
      $content
    );

    $this->assertFalse($result['valid']);
    $this->assertNotEmpty($result['issues']);
    $this->assertStringContainsString('Phone number', $result['issues'][0]);
  }

  /**
   * Tests validateGrounding flags dollar amount not in corpus.
   *   */
  public function testValidateGroundingFlagsDollarNotInCorpus(): void {
    $content = [
      ['answer' => 'Filing fees vary by county.'],
    ];

    $result = $this->grounder->validateGrounding(
      'The filing fee is $250.00.',
      $content
    );

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Dollar amount', $result['issues'][0]);
  }

  /**
   * Tests validateGrounding flags date not in corpus.
   *   */
  public function testValidateGroundingFlagsDateNotInCorpus(): void {
    $content = [
      ['answer' => 'Deadlines depend on your case.'],
    ];

    $result = $this->grounder->validateGrounding(
      'The deadline is March 15, 2026.',
      $content
    );

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Date', $result['issues'][0]);
  }

  /**
   * Tests validateGrounding passes when info is in corpus.
   *   */
  public function testValidateGroundingPassesWhenInCorpus(): void {
    $content = [
      ['answer' => 'Call (208) 345-0106 for help. The fee is $50.'],
    ];

    $result = $this->grounder->validateGrounding(
      'Call (208) 345-0106 for help. The fee is $50.',
      $content
    );

    $this->assertTrue($result['valid']);
    $this->assertEmpty($result['issues']);
  }

  // -----------------------------------------------------------------------
  // getOfficialContacts() tests
  // -----------------------------------------------------------------------

  /**
   * Tests getOfficialContacts returns all contacts.
   *   */
  public function testGetOfficialContactsAll(): void {
    $contacts = $this->grounder->getOfficialContacts('all');

    $this->assertArrayHasKey('hotline', $contacts);
    $this->assertArrayHasKey('offices', $contacts);
    $this->assertArrayHasKey('emergency', $contacts);
    $this->assertCount(5, $contacts['offices']);
  }

  /**
   * Tests getOfficialContacts by type.
   *   */
  public function testGetOfficialContactsByType(): void {
    $hotline = $this->grounder->getOfficialContacts('hotline');
    $this->assertArrayHasKey('number', $hotline);
    $this->assertArrayHasKey('toll_free', $hotline);
  }

  /**
   * Tests grounding metadata added.
   *   */
  public function testGroundingMetadata(): void {
    $response = ['message' => 'Safe text.', 'type' => 'faq'];
    $result = $this->grounder->groundResponse($response);

    $this->assertTrue($result['_grounded']);
    $this->assertEquals('1.0', $result['_grounding_version']);
  }

  // -----------------------------------------------------------------------
  // PHARD-03: Weak grounding flag tests
  // -----------------------------------------------------------------------

  /**
   * Tests answerable response with off-domain-only URLs gets weak grounding flag.
   *   */
  public function testAnswerableResponseWithoutCitationsGetsWeakGroundingFlag(): void {
    $results = [
      ['title' => 'Off Domain', 'url' => 'https://attacker.example.com/page'],
      ['title' => 'Also Off Domain', 'url' => 'https://other.example.com/info'],
    ];

    $response = ['message' => 'Some info', 'type' => 'faq'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertTrue($result['_grounding_weak'] ?? FALSE);
    $this->assertSame('citation_required_type_without_citations', $result['_grounding_weak_reason']);
    $this->assertArrayNotHasKey('sources', $result);
  }

  /**
   * Tests navigation response without citations is NOT flagged.
   *   */
  public function testNavigationResponseWithoutCitationsNotFlagged(): void {
    $response = ['message' => 'Navigate here', 'type' => 'navigation'];
    $result = $this->grounder->groundResponse($response, []);

    $this->assertArrayNotHasKey('_grounding_weak', $result);
  }

  /**
   * Tests answerable response with valid citations is NOT flagged.
   *   */
  public function testAnswerableResponseWithValidCitationsNotFlagged(): void {
    $results = [
      ['title' => 'FAQ', 'url' => 'https://idaholegalaid.org/faq/housing'],
    ];

    $response = ['message' => 'Some info', 'type' => 'faq'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayNotHasKey('_grounding_weak', $result);
    $this->assertArrayHasKey('sources', $result);
  }

  // -----------------------------------------------------------------------
  // PHARD-03: Citation freshness propagation tests
  // -----------------------------------------------------------------------

  /**
   * Tests that freshness status is propagated to citation sources.
   *   */
  public function testCitationsFreshnessStatusPropagated(): void {
    $results = [
      [
        'title' => 'Fresh FAQ',
        'url' => '/faq/housing',
        'freshness' => ['status' => 'fresh', 'age_days' => 10],
      ],
      [
        'title' => 'Stale Resource',
        'url' => '/resources/old-guide',
        'freshness' => ['status' => 'stale', 'age_days' => 200],
      ],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('sources', $result);
    $this->assertSame('fresh', $result['sources'][0]['freshness']);
    $this->assertSame('stale', $result['sources'][1]['freshness']);
  }

  /**
   * Tests that all-stale citations set _all_citations_stale flag.
   *   */
  public function testAllStaleCitationsFlag(): void {
    $results = [
      [
        'title' => 'Stale 1',
        'url' => '/faq/old1',
        'freshness' => ['status' => 'stale'],
      ],
      [
        'title' => 'Stale 2',
        'url' => '/faq/old2',
        'freshness' => ['status' => 'stale'],
      ],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertTrue($result['_all_citations_stale'] ?? FALSE);
    $this->assertSame(2, $result['_stale_citation_count']);
  }

  /**
   * Tests that mixed freshness does NOT set _all_citations_stale flag.
   *   */
  public function testMixedFreshnessNoAllStaleFlag(): void {
    $results = [
      [
        'title' => 'Fresh',
        'url' => '/faq/new',
        'freshness' => ['status' => 'fresh'],
      ],
      [
        'title' => 'Stale',
        'url' => '/faq/old',
        'freshness' => ['status' => 'stale'],
      ],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayNotHasKey('_all_citations_stale', $result);
    $this->assertSame(1, $result['_stale_citation_count']);
  }

  // -------------------------------------------------------------------
  // AFRP-20: Freshness enforcement via groundResponse().
  // -------------------------------------------------------------------

  /**
   * Tests that groundResponse() adds freshness_profile for citation-required types.
   */
  public function testGroundResponseAddsFreshnessProfileForCitationTypes(): void {
    $results = [
      [
        'title' => 'Guide',
        'url' => '/resources/guide',
        'freshness' => ['status' => 'fresh'],
      ],
    ];

    $response = ['message' => 'Info', 'type' => 'faq'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertArrayHasKey('freshness_profile', $result);
    $this->assertSame(1, $result['freshness_profile']['fresh']);
    $this->assertSame(0, $result['freshness_profile']['stale']);
    $this->assertSame(0, $result['freshness_profile']['unknown']);
    $this->assertSame(1, $result['freshness_profile']['total']);
  }

  /**
   * Tests that stale citations trigger freshness enforcement flags.
   */
  public function testGroundResponseSetsFreshnessEnforcementForStale(): void {
    $results = [
      [
        'title' => 'Old Guide',
        'url' => '/resources/old',
        'freshness' => ['status' => 'stale'],
      ],
    ];

    $response = ['message' => 'Info', 'type' => 'resources'];
    $result = $this->grounder->groundResponse($response, $results);

    $this->assertSame('all_non_fresh', $result['_freshness_enforcement']);
    $this->assertSame(0.5, $result['_freshness_confidence_cap']);
  }

}
