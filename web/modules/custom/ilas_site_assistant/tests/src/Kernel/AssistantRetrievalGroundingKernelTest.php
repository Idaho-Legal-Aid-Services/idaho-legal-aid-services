<?php

declare(strict_types=1);

namespace Drupal\Tests\ilas_site_assistant\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\ilas_site_assistant\Service\FaqIndex;
use Drupal\ilas_site_assistant\Service\RankingEnhancer;
use Drupal\ilas_site_assistant\Service\RerankerInterface;
use Drupal\ilas_site_assistant\Service\ResourceFinder;
use Drupal\ilas_site_assistant\Service\ResponseGrounder;
use Drupal\ilas_site_assistant\Service\RetrievalConfigurationService;
use Drupal\ilas_site_assistant\Service\SourceGovernanceService;
use Drupal\ilas_site_assistant\Service\TopicResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * Fixture-backed retrieval and citation-support tests for assistant quality.
 */
#[Group('ilas_site_assistant')]
final class AssistantRetrievalGroundingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'file',
    'text',
    'taxonomy',
    'language',
    'path',
    'path_alias',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'datetime',
    'ilas_site_assistant',
  ];

  /**
   * Parsed quality fixture.
   *
   * @var array<string, mixed>
   */
  private array $fixture = [];

  /**
   * Fixture source IDs keyed by source URL.
   *
   * @var array<string, string>
   */
  private array $sourceIdByUrl = [];

  /**
   * Fixture source IDs keyed by source title/question.
   *
   * @var array<string, string>
   */
  private array $sourceIdByTitle = [];

  /**
   * Fixture source IDs keyed by entity IDs.
   *
   * @var array<string, string>
   */
  private array $sourceIdByEntityKey = [];

  /**
   * Topic term IDs keyed by lower-case topic name.
   *
   * @var array<string, int>
   */
  private array $topicIds = [];

  private SourceGovernanceService $sourceGovernance;

  private ResponseGrounder $grounder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('path_alias');
    // Node re-saves (review-attestation case) touch node_access grants.
    $this->installSchema('node', ['node_access']);

    $this->fixture = $this->loadFixture();
    $this->createContentModel();
    $this->createLanguages();
    $this->createTopicTerms();
    $this->createSourceFixtures();

    $this->sourceGovernance = new SourceGovernanceService(
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->container->get('logger.factory')->get('ilas_site_assistant'),
    );
    $this->grounder = new ResponseGrounder($this->sourceGovernance);
  }

  /**
   * Proves common queries retrieve and cite supporting fixture sources.
   */
  public function testFixtureBackedQueryToSourceRelevanceAndCitationSupport(): void {
    foreach ([
      'eviction_notice',
      'security_deposit',
      'custody_forms',
      'divorce_guide',
      'consumer_debt',
      'benefits_ssi',
      'protection_order_dv',
      'boise_office',
      'twin_falls_office',
    ] as $case_id) {
      $case = $this->qualityCase($case_id);
      $results = $this->retrieveForCase($case);

      $this->assertRetrievedExpectedSources($case_id, $case, $results);
      $grounded = $this->groundResultsForCase($case, $results);

      $this->assertCitationExistsForCase($case_id, $case, $grounded);
      $this->assertCitedSourceSupportsCase($case_id, $case, $grounded);
      $this->assertNoGenericHomepageCitation($grounded);
    }
  }

  /**
   * A review attestation keeps unchanged content fresh end to end (PHP-9Z).
   *
   * Content untouched since migration must not age out when a content owner
   * has set field_last_reviewed; a sibling without the attestation still goes
   * stale. Covers both the resource path (buildResourceItem) and the FAQ path
   * (getParentInfo on the host node).
   */
  public function testReviewAttestationKeepsUnchangedContentFresh(): void {
    $old_changed = time() - (300 * 86400);
    $review_date = gmdate('Y-m-d', time() - (10 * 86400));

    // Resource reviewed 10 days ago but not edited for 300 days.
    $reviewed = $this->nodeForSource('resource_custody_forms');
    $reviewed->set(SourceGovernanceService::REVIEW_FIELD, $review_date);
    $reviewed->setChangedTime($old_changed);
    $reviewed->save();

    // Resource neither edited nor reviewed for 300 days.
    $unreviewed = $this->nodeForSource('resource_divorce_guide');
    $unreviewed->setChangedTime($old_changed);
    $unreviewed->save();

    // FAQ host page reviewed 10 days ago, host changed 300 days ago.
    $faq_host = $this->nodeForSource('faq_eviction_notice');
    $faq_host->set(SourceGovernanceService::REVIEW_FIELD, $review_date);
    $faq_host->setChangedTime($old_changed);
    $faq_host->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertSame($old_changed, $this->nodeForSource('resource_custody_forms')->getChangedTime(), 'Fixture keeps the old changed timestamp.');

    $reviewed_item = $this->itemForSource(
      $this->retrieveForCase($this->qualityCase('custody_forms')),
      'resource_custody_forms'
    );
    $this->assertSame('fresh', $reviewed_item['freshness']['status']);
    $this->assertSame('reviewed', $reviewed_item['freshness']['basis']);
    $this->assertSame($old_changed, $reviewed_item['freshness']['updated_at']);
    $this->assertSame($review_date, gmdate('Y-m-d', $reviewed_item['freshness']['reviewed_at']));
    $this->assertNotContains('stale_source', $reviewed_item['governance_flags']);

    $unreviewed_item = $this->itemForSource(
      $this->retrieveForCase($this->qualityCase('divorce_guide')),
      'resource_divorce_guide'
    );
    $this->assertSame('stale', $unreviewed_item['freshness']['status']);
    $this->assertSame('changed', $unreviewed_item['freshness']['basis']);
    $this->assertNull($unreviewed_item['freshness']['reviewed_at']);
    $this->assertContains('stale_source', $unreviewed_item['governance_flags']);

    $faq_item = $this->itemForSource(
      $this->retrieveForCase($this->qualityCase('eviction_notice')),
      'faq_eviction_notice'
    );
    $this->assertSame('fresh', $faq_item['freshness']['status']);
    $this->assertSame('reviewed', $faq_item['freshness']['basis']);
    $this->assertSame($review_date, gmdate('Y-m-d', $faq_item['freshness']['reviewed_at']));

    // Observation snapshot exposes the never_reviewed signal.
    $this->sourceGovernance->recordObservationBatch([$reviewed_item, $unreviewed_item, $faq_item]);
    $snapshot = $this->sourceGovernance->getSnapshot();
    $this->assertSame(3, $snapshot['total']);
    $this->assertSame(1, $snapshot['stale']);
    $this->assertSame(1, $snapshot['never_reviewed']);
  }

  /**
   * Loads the fixture node backing a source ID.
   */
  private function nodeForSource(string $source_id): Node {
    foreach ($this->sourceIdByEntityKey as $entity_key => $mapped_source_id) {
      if ($mapped_source_id === $source_id && str_starts_with($entity_key, 'node_')) {
        $node = Node::load((int) substr($entity_key, 5));
        $this->assertInstanceOf(Node::class, $node);
        return $node;
      }
    }
    $this->fail("No node fixture for $source_id.");
  }

  /**
   * Finds the retrieval item for a fixture source ID.
   *
   * @param array<int, array<string, mixed>> $results
   *   Retrieval results.
   */
  private function itemForSource(array $results, string $source_id): array {
    foreach ($results as $result) {
      if ($this->sourceIdForResult($result) === $source_id) {
        return $result;
      }
    }
    $this->fail("Retrieval did not return $source_id.");
  }

  /**
   * Missing ILAS content must produce a safe no-answer response.
   */
  public function testMissingContentDoesNotFabricateCitation(): void {
    $case = $this->qualityCase('missing_space_law');
    $faq_results = $this->buildFaqIndex()->search($case['query'], 5);
    $resource_payload = $this->buildResourceFinder(['enabled' => FALSE])->searchFixtureResources($case['query'], NULL, 5);

    $this->assertSame([], $faq_results);
    $this->assertSame([], $resource_payload['items']);

    $grounded = $this->grounder->groundResponse([
      'type' => 'search_results',
      'message' => 'I cannot find a specific ILAS resource for Idaho space law. You can apply for help or contact ILAS if you need review of a real civil legal problem.',
    ], []);

    $this->assertArrayNotHasKey('sources', $grounded);
    foreach ($case['safe_no_answer_phrases'] as $phrase) {
      $this->assertStringContainsString($this->normalize($phrase), $this->normalize($grounded['message']));
    }
  }

  /**
   * Single-keyword lexical overlap should not become a confident answer.
   */
  public function testLexicalNoiseDoesNotBecomeGroundedAnswer(): void {
    $case = $this->qualityCase('lexical_noise_custody_phone');
    $payload = $this->buildResourceFinder(['enabled' => FALSE])->searchFixtureResources($case['query'], NULL, 5);

    $this->assertSame([], $payload['items'], 'The fixture retriever requires more than one meaningful overlap before grounding a specific source.');

    $grounded = $this->grounder->groundResponse([
      'type' => 'clarification',
      'message' => 'I need more information about the custody issue before I can point you to a specific ILAS resource.',
    ], []);

    $this->assertArrayNotHasKey('sources', $grounded);
    foreach ($case['clarify_phrases'] as $phrase) {
      $this->assertStringContainsString($this->normalize($phrase), $this->normalize($grounded['message']));
    }
  }

  /**
   * Retrieved page text is untrusted and cannot inject assistant behavior.
   */
  public function testPoisonedRetrievedContentIsUntrusted(): void {
    $case = $this->qualityCase('poisoned_retrieval');
    $results = $this->retrieveForCase($case);

    $this->assertRetrievedExpectedSources('poisoned_retrieval', $case, $results);

    $grounded = $this->grounder->groundFaqResponse($results[0]);
    $combined_text = $this->normalize(json_encode($grounded, JSON_THROW_ON_ERROR));

    foreach ($case['forbidden_phrases'] as $phrase) {
      $this->assertStringNotContainsString($this->normalize($phrase), $combined_text);
    }
    $this->assertStringContainsString('housing information', $this->normalize($grounded['message']));
    $this->assertCitationExistsForCase('poisoned_retrieval', $case, $grounded);
    $this->assertCitedSourceSupportsCase('poisoned_retrieval', $case, $grounded);
  }

  /**
   * Lexical retrieval remains safe when vector search and rerank are disabled.
   */
  public function testVectorDisabledFallsBackToSafeLexicalBehavior(): void {
    $case = $this->qualityCase('consumer_debt');
    $finder = $this->buildResourceFinder(['enabled' => FALSE]);

    $payload = $finder->searchFixtureResources($case['query'], NULL, 5);

    $this->assertFalse($payload['decision']['enabled']);
    $this->assertFalse($payload['vector_outcome']['attempted']);
    $this->assertSame('disabled', $payload['decision']['reason']);
    $this->assertRetrievedExpectedSources('consumer_debt', $case, $payload['items']);

    $grounded = $this->groundResultsForCase($case, $payload['items']);
    $this->assertCitedSourceSupportsCase('consumer_debt', $case, $grounded);

    $missing = $this->qualityCase('missing_space_law');
    $missing_payload = $finder->searchFixtureResources($missing['query'], NULL, 5);
    $this->assertSame([], $missing_payload['items']);
  }

  /**
   * Mocked vector and rerank behavior proves merge, dedupe, and support checks.
   */
  public function testVectorEnabledMockMergesDedupeLanguageAndSourceSelection(): void {
    $case = $this->qualityCase('consumer_debt');
    $vector_items = [
      $this->buildVectorResourceItem('resource_debt_collection', 95.0),
      [
        'id' => 999999,
        'title' => 'Spanish Debt Collection Resource',
        'url' => '/es/resources/consumer-debt-collection',
        'source_url' => '/es/resources/consumer-debt-collection',
        'type' => 'resource',
        'description' => 'Spanish language debt collection resource.',
        'topics' => ['consumer', 'debt'],
        'score' => 98.0,
        'source' => 'vector',
        'source_class' => 'resource_vector',
      ],
    ];
    $finder = $this->buildResourceFinder([
      'enabled' => TRUE,
      'fallback_threshold' => 2,
      'min_lexical_score' => 0,
    ], $vector_items);

    $payload = $finder->searchFixtureResources($case['query'], NULL, 5);

    $this->assertTrue($payload['decision']['enabled']);
    $this->assertTrue($payload['vector_outcome']['attempted']);
    $this->assertSame('healthy', $payload['vector_outcome']['status']);
    $this->assertCount(1, $payload['items'], 'The duplicate English vector item should replace lexical, and the Spanish item should be filtered.');
    $this->assertSame('resource_vector', $payload['items'][0]['source_class']);
    $this->assertSame('vector', $payload['items'][0]['source']);
    $this->assertFalse(str_starts_with((string) $payload['items'][0]['url'], '/es/'));

    $grounded = $this->groundResultsForCase($case, $payload['items']);
    $this->assertCitedSourceSupportsCase('consumer_debt', $case, $grounded);
  }

  /**
   * A local reranker mock can reorder candidates without losing grounding.
   */
  public function testMockRerankPreservesGroundedCitationSupport(): void {
    $case = $this->qualityCase('consumer_debt');
    $items = [
      $this->buildVectorResourceItem('resource_divorce_guide', 92.0),
      $this->buildVectorResourceItem('resource_debt_collection', 35.0),
    ];
    $reranker = new AssistantQualityRerankerDouble('resource_debt_collection');

    $reranked = $reranker->rerank($case['query'], $items);

    $this->assertTrue($reranked['meta']['applied']);
    $this->assertTrue($reranked['meta']['order_changed']);
    $this->assertSame('resource_debt_collection', $reranked['items'][0]['fixture_source_id']);

    $grounded = $this->groundResultsForCase($case, [$reranked['items'][0]]);
    $this->assertCitedSourceSupportsCase('consumer_debt', $case, $grounded);
  }

  /**
   * Loads the YAML quality fixture.
   *
   * @return array<string, mixed>
   *   Parsed fixture.
   */
  private function loadFixture(): array {
    $path = dirname(__DIR__, 2) . '/fixtures/assistant_quality_cases.yml';
    $fixture = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($fixture);
    $this->assertArrayHasKey('sources', $fixture);
    $this->assertArrayHasKey('cases', $fixture);
    return $fixture;
  }

  /**
   * Creates bundles and fields needed by the retrieval services.
   */
  private function createContentModel(): void {
    NodeType::create([
      'type' => 'standard_page',
      'name' => 'Standard Page',
    ])->save();
    NodeType::create([
      'type' => 'resource',
      'name' => 'Resource',
    ])->save();

    Vocabulary::create([
      'vid' => 'topics',
      'name' => 'Topics',
    ])->save();

    foreach (['faq_item' => 'FAQ Item', 'faq_smart_section' => 'FAQ Smart Section'] as $id => $label) {
      ParagraphsType::create([
        'id' => $id,
        'label' => $label,
      ])->save();
    }

    $this->createFieldStorage('paragraph', 'field_faq_question', 'string');
    $this->createFieldStorage('paragraph', 'field_faq_answer', 'string_long');
    $this->createFieldStorage('paragraph', 'field_anchor_id', 'string');
    $this->createFieldStorage('paragraph', 'field_faq_items', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);
    $this->createFieldStorage('node', 'field_faq_section', 'entity_reference_revisions', [
      'target_type' => 'paragraph',
    ], -1);
    $this->createFieldStorage('node', 'field_main_content', 'text_long');
    $this->createFieldStorage('node', SourceGovernanceService::REVIEW_FIELD, 'datetime', [
      'datetime_type' => 'date',
    ]);
    $this->createFieldStorage('node', 'field_topics', 'entity_reference', [
      'target_type' => 'taxonomy_term',
    ], -1);

    $this->createField('paragraph', 'faq_item', 'field_faq_question', 'FAQ Question');
    $this->createField('paragraph', 'faq_item', 'field_faq_answer', 'FAQ Answer');
    $this->createField('paragraph', 'faq_item', 'field_anchor_id', 'Anchor ID');
    $this->createField('paragraph', 'faq_smart_section', 'field_faq_items', 'FAQ Items', [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['faq_item' => 'faq_item']],
    ]);
    $this->createField('node', 'standard_page', 'field_faq_section', 'FAQ Section', [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['faq_smart_section' => 'faq_smart_section']],
    ]);
    $this->createField('node', 'resource', 'field_main_content', 'Main Content');
    $this->createField('node', 'resource', SourceGovernanceService::REVIEW_FIELD, 'Last reviewed');
    $this->createField('node', 'standard_page', SourceGovernanceService::REVIEW_FIELD, 'Last reviewed');
    $this->createField('node', 'resource', 'field_topics', 'Topics', [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['topics' => 'topics']],
    ]);
  }

  /**
   * Creates additional languages for vector language filtering assertions.
   */
  private function createLanguages(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->container->get('language_manager')->reset();
  }

  /**
   * Creates topic terms used by resource fixtures.
   */
  private function createTopicTerms(): void {
    $topics = [];
    foreach ($this->fixture['sources'] as $source) {
      foreach ($source['topics'] ?? [] as $topic) {
        $topics[$this->normalizeTopicName($topic)] = (string) $topic;
      }
    }

    foreach ($topics as $key => $name) {
      $term = Term::create([
        'vid' => 'topics',
        'name' => $name,
      ]);
      $term->save();
      $this->topicIds[$key] = (int) $term->id();
    }
  }

  /**
   * Creates FAQ paragraph and resource node fixtures from YAML.
   */
  private function createSourceFixtures(): void {
    foreach ($this->fixture['sources'] as $source_id => $source) {
      $this->sourceIdByUrl[$source['url']] = $source_id;
      $this->sourceIdByTitle[$source['title']] = $source_id;
      if (!empty($source['question'])) {
        $this->sourceIdByTitle[$source['question']] = $source_id;
      }

      if ($source['kind'] === 'faq') {
        $this->createFaqSource($source_id, $source);
      }
      elseif ($source['kind'] === 'resource') {
        $this->createResourceSource($source_id, $source);
      }
    }
  }

  /**
   * Creates one FAQ source as nested paragraphs under a standard page.
   *
   * @param string $source_id
   *   Fixture source ID.
   * @param array<string, mixed> $source
   *   Fixture source.
   */
  private function createFaqSource(string $source_id, array $source): void {
    $url_parts = parse_url($source['url']);
    $alias = (string) ($url_parts['path'] ?? '/faq');
    $anchor = (string) ($url_parts['fragment'] ?? $this->slug((string) $source['question']));

    $faq_item = Paragraph::create([
      'type' => 'faq_item',
      'langcode' => 'en',
      'field_faq_question' => $source['question'],
      'field_faq_answer' => $source['answer'],
      'field_anchor_id' => $anchor,
    ]);
    $faq_item->save();

    $faq_section = Paragraph::create([
      'type' => 'faq_smart_section',
      'langcode' => 'en',
      'field_faq_items' => [[
        'target_id' => $faq_item->id(),
        'target_revision_id' => $faq_item->getRevisionId(),
      ]
],
    ]);
    $faq_section->save();

    $node = Node::create([
      'type' => 'standard_page',
      'title' => $source['title'],
      'langcode' => 'en',
      'status' => Node::PUBLISHED,
      'field_faq_section' => [[
        'target_id' => $faq_section->id(),
        'target_revision_id' => $faq_section->getRevisionId(),
      ]
],
    ]);
    $node->save();

    $this->createAlias('/node/' . $node->id(), $alias);
    $this->sourceIdByEntityKey['faq_' . $faq_item->id()] = $source_id;
    $this->sourceIdByEntityKey['node_' . $node->id()] = $source_id;
  }

  /**
   * Creates one resource source as a published resource node.
   *
   * @param string $source_id
   *   Fixture source ID.
   * @param array<string, mixed> $source
   *   Fixture source.
   */
  private function createResourceSource(string $source_id, array $source): void {
    $topic_refs = [];
    foreach ($source['topics'] ?? [] as $topic) {
      $key = $this->normalizeTopicName($topic);
      if (isset($this->topicIds[$key])) {
        $topic_refs[] = ['target_id' => $this->topicIds[$key]];
      }
    }

    $node = Node::create([
      'type' => 'resource',
      'title' => $source['title'],
      'langcode' => 'en',
      'status' => Node::PUBLISHED,
      'field_main_content' => [
        'value' => $source['body'],
        'format' => 'plain_text',
      ],
      'field_topics' => $topic_refs,
    ]);
    $node->save();

    $this->createAlias('/node/' . $node->id(), $source['url']);
    $this->sourceIdByEntityKey['node_' . $node->id()] = $source_id;
  }

  /**
   * Creates a path alias.
   */
  private function createAlias(string $path, string $alias): void {
    PathAlias::create([
      'path' => $path,
      'alias' => $alias,
      'langcode' => 'en',
    ])->save();
  }

  /**
   * Runs the deterministic retriever for a fixture case.
   *
   * @param array<string, mixed> $case
   *   Fixture case.
   *
   * @return array<int, array<string, mixed>>
   *   Retrieval results.
   */
  private function retrieveForCase(array $case): array {
    return match ($case['mode']) {
      'faq', 'poisoned' => $this->buildFaqIndex()->search($case['query'], 5),
      'form' => $this->buildResourceFinder(['enabled' => FALSE])->searchFixtureResources($case['query'], 'form', 5)['items'],
      'guide' => $this->buildResourceFinder(['enabled' => FALSE])->searchFixtureResources($case['query'], 'guide', 5)['items'],
      'resource' => $this->buildResourceFinder(['enabled' => FALSE])->searchFixtureResources($case['query'], NULL, 5)['items'],
      'office' => $this->officeResults($case['query']),
      default => [],
    };
  }

  /**
   * Builds a grounded response from fixture retrieval results.
   *
   * @param array<string, mixed> $case
   *   Fixture case.
   * @param array<int, array<string, mixed>> $results
   *   Retrieval results.
   *
   * @return array<string, mixed>
   *   Grounded response.
   */
  private function groundResultsForCase(array $case, array $results): array {
    $this->assertNotEmpty($results, 'Grounded answer cases need at least one retrieval result.');

    if (($case['mode'] ?? '') === 'faq' || ($case['mode'] ?? '') === 'poisoned') {
      return $this->grounder->groundFaqResponse($results[0]);
    }

    return $this->grounder->groundResourceResponse($results, 'I found ILAS information that may help:');
  }

  /**
   * Returns office contact results from the current hardcoded resolver model.
   *
   * @return array<int, array<string, mixed>>
   *   Office result item.
   */
  private function officeResults(string $query): array {
    $office_key = str_contains($this->normalize($query), 'twin falls') ? 'twin_falls' : 'boise';
    $contacts = ResponseGrounder::OFFICIAL_CONTACTS['offices'][$office_key];
    $source_id = $office_key === 'twin_falls' ? 'office_twin_falls' : 'office_boise';
    $source = $this->fixture['sources'][$source_id];

    return [[
      'id' => $source_id,
      'fixture_source_id' => $source_id,
      'title' => $source['title'],
      'url' => $source['url'],
      'source_url' => $source['url'],
      'type' => 'office',
      'description' => sprintf('%s office contact: %s. Phone: %s.', $source['title'], $contacts['address'], $contacts['phone']),
      'source' => 'office_resolver',
      'freshness' => ['status' => 'fresh'],
    ]
];
  }

  /**
   * Builds the FAQ service double.
   */
  private function buildFaqIndex(array $vector_config = ['enabled' => FALSE], array $vector_items = []): AssistantQualityFaqIndexDouble {
    $retrieval_configuration = new RetrievalConfigurationService(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
    );

    return new AssistantQualityFaqIndexDouble(
      $this->container->get('entity_type.manager'),
      new MemoryBackend($this->container->get('datetime.time')),
      $this->container->get('config.factory'),
      $this->container->get('language_manager'),
      $retrieval_configuration,
      NULL,
      $this->sourceGovernance,
      new AssistantQualityFaqIndex($this->loadFaqParagraphs()),
      $vector_config,
      $vector_items,
    );
  }

  /**
   * Builds the resource finder double.
   */
  private function buildResourceFinder(array $vector_config, array $vector_items = []): AssistantQualityResourceFinderDouble {
    $retrieval_configuration = new RetrievalConfigurationService(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
    );
    $cache = new MemoryBackend($this->container->get('datetime.time'));
    $topic_resolver = new TopicResolver(
      $this->container->get('entity_type.manager'),
      $cache,
      $retrieval_configuration,
    );

    return new AssistantQualityResourceFinderDouble(
      $this->container->get('entity_type.manager'),
      $topic_resolver,
      $cache,
      $this->container->get('language_manager'),
      NULL,
      $this->container->get('config.factory'),
      $retrieval_configuration,
      $this->sourceGovernance,
      NULL,
      NULL,
      $vector_config,
      $vector_items,
      $this->sourceIdByEntityKey,
    );
  }

  /**
   * Loads all FAQ item paragraphs created from fixture sources.
   *
   * @return array<int, \Drupal\paragraphs\Entity\Paragraph>
   *   FAQ paragraphs.
   */
  private function loadFaqParagraphs(): array {
    $paragraph_ids = [];
    foreach ($this->sourceIdByEntityKey as $entity_key => $source_id) {
      if (str_starts_with($entity_key, 'faq_')) {
        $paragraph_ids[] = (int) substr($entity_key, 4);
      }
    }
    return array_values($this->container->get('entity_type.manager')->getStorage('paragraph')->loadMultiple($paragraph_ids));
  }

  /**
   * Builds a vector resource item from one fixture source.
   *
   * @return array<string, mixed>
   *   Vector item.
   */
  private function buildVectorResourceItem(string $source_id, float $score): array {
    $source = $this->fixture['sources'][$source_id];
    $node_id = NULL;
    foreach ($this->sourceIdByEntityKey as $entity_key => $mapped_source_id) {
      if ($mapped_source_id === $source_id && str_starts_with($entity_key, 'node_')) {
        $node_id = (int) substr($entity_key, 5);
        break;
      }
    }
    $this->assertNotNull($node_id, "Expected node fixture for $source_id.");

    $item = [
      'id' => $node_id,
      'fixture_source_id' => $source_id,
      'title' => $source['title'],
      'url' => $source['url'],
      'source_url' => $source['url'],
      'type' => str_contains(strtolower($source['title']), 'guide') ? 'guide' : 'resource',
      'description' => $source['body'],
      'topics' => $source['topics'] ?? [],
      'score' => $score,
      'source' => 'vector',
      'updated_at' => time(),
    ];

    return $this->sourceGovernance->annotateResult($item, 'resource_vector');
  }

  /**
   * Asserts expected sources were retrieved.
   *
   * @param array<string, mixed> $case
   *   Fixture case.
   * @param array<int, array<string, mixed>> $results
   *   Retrieval results.
   */
  private function assertRetrievedExpectedSources(string $case_id, array $case, array $results): void {
    $retrieved_ids = array_values(array_filter(array_map(fn(array $result): ?string => $this->sourceIdForResult($result), $results)));
    foreach ($case['expected_source_ids'] as $source_id) {
      $this->assertContains($source_id, $retrieved_ids, "Case $case_id should retrieve $source_id.");
    }
    foreach ($case['forbidden_source_ids'] ?? [] as $source_id) {
      $this->assertNotContains($source_id, $retrieved_ids, "Case $case_id should not retrieve $source_id.");
    }
  }

  /**
   * Asserts a citation exists when the fixture requires one.
   *
   * @param array<string, mixed> $case
   *   Fixture case.
   * @param array<string, mixed> $grounded
   *   Grounded response.
   */
  private function assertCitationExistsForCase(string $case_id, array $case, array $grounded): void {
    if (!empty($case['expect_citation'])) {
      $this->assertNotEmpty($grounded['sources'] ?? [], "Case $case_id should emit citations.");
      return;
    }
    $this->assertEmpty($grounded['sources'] ?? [], "Case $case_id should not emit citations.");
  }

  /**
   * Asserts cited fixture content contains the phrases needed for support.
   *
   * This is a pragmatic support check, not full semantic entailment: the
   * citation must map to a fixture source and that source must contain the
   * case's configured support phrases.
   *
   * @param array<string, mixed> $case
   *   Fixture case.
   * @param array<string, mixed> $grounded
   *   Grounded response.
   */
  private function assertCitedSourceSupportsCase(string $case_id, array $case, array $grounded): void {
    $sources = $grounded['sources'] ?? [];
    $this->assertNotEmpty($sources, "Case $case_id should have sources before support can be checked.");

    foreach ($case['expected_source_ids'] as $expected_source_id) {
      $matching_citation = NULL;
      foreach ($sources as $source) {
        if ($this->sourceIdForResult($source) === $expected_source_id) {
          $matching_citation = $source;
          break;
        }
      }

      $this->assertNotNull($matching_citation, "Case $case_id should cite expected source $expected_source_id.");
      $source_text = $this->sourceSupportText($expected_source_id);
      foreach ($case['required_phrases'] ?? [] as $phrase) {
        $this->assertStringContainsString(
          $this->normalize((string) $phrase),
          $source_text,
          "Case $case_id citation $expected_source_id should support phrase '$phrase'.",
        );
      }
    }
  }

  /**
   * Asserts citations do not use generic homepages for specific claims.
   *
   * @param array<string, mixed> $grounded
   *   Grounded response.
   */
  private function assertNoGenericHomepageCitation(array $grounded): void {
    foreach ($grounded['sources'] ?? [] as $source) {
      $url = rtrim((string) ($source['url'] ?? ''), '/');
      $this->assertNotContains($url, ['', '/', 'https://idaholegalaid.org', 'https://www.idaholegalaid.org']);
    }
  }

  /**
   * Resolves one retrieval or citation item to its fixture source ID.
   *
   * @param array<string, mixed> $result
   *   Result or citation item.
   */
  private function sourceIdForResult(array $result): ?string {
    if (!empty($result['fixture_source_id'])) {
      return (string) $result['fixture_source_id'];
    }

    if (!empty($result['id'])) {
      $entity_key = is_numeric($result['id']) ? 'node_' . $result['id'] : (string) $result['id'];
      if (isset($this->sourceIdByEntityKey[$entity_key])) {
        return $this->sourceIdByEntityKey[$entity_key];
      }
    }

    $title = (string) ($result['title'] ?? $result['question'] ?? '');
    if ($title !== '' && isset($this->sourceIdByTitle[$title])) {
      return $this->sourceIdByTitle[$title];
    }

    $url = (string) ($result['url'] ?? $result['source_url'] ?? '');
    if ($url !== '' && isset($this->sourceIdByUrl[$url])) {
      return $this->sourceIdByUrl[$url];
    }

    return NULL;
  }

  /**
   * Returns normalized source support text for a fixture source.
   */
  private function sourceSupportText(string $source_id): string {
    $source = $this->fixture['sources'][$source_id];
    return $this->normalize(implode(' ', [
      $source['title'] ?? '',
      $source['question'] ?? '',
      $source['answer'] ?? '',
      $source['body'] ?? '',
      implode(' ', $source['topics'] ?? []),
    ]));
  }

  /**
   * Returns one fixture case.
   *
   * @return array<string, mixed>
   *   Fixture case.
   */
  private function qualityCase(string $case_id): array {
    $this->assertArrayHasKey($case_id, $this->fixture['cases']);
    return $this->fixture['cases'][$case_id];
  }

  /**
   * Creates one field storage definition.
   */
  private function createFieldStorage(
    string $entity_type,
    string $field_name,
    string $type,
    array $settings = [],
    int $cardinality = 1,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
  }

  /**
   * Creates one bundle field definition.
   */
  private function createField(
    string $entity_type,
    string $bundle,
    string $field_name,
    string $label,
    array $settings = [],
  ): void {
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'label' => $label,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Normalizes a support phrase or text blob for stable assertions.
   */
  private function normalize(string $text): string {
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
  }

  /**
   * Normalizes a topic name for lookup.
   */
  private function normalizeTopicName(string $topic): string {
    return $this->normalize($topic);
  }

  /**
   * Creates a slug for fallback anchors.
   */
  private function slug(string $text): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
    return trim((string) $slug, '-');
  }

}

/**
 * Kernel-friendly FAQ service double backed by real paragraph entities.
 */
final class AssistantQualityFaqIndexDouble extends FaqIndex {

  /**
   * Constructs the FAQ fixture double.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LanguageManagerInterface $language_manager,
    RetrievalConfigurationService $retrieval_configuration,
    ?RankingEnhancer $ranking_enhancer,
    ?SourceGovernanceService $source_governance,
    private readonly AssistantQualityFaqIndex $fixtureIndex,
    private readonly array $vectorConfig,
    private readonly array $vectorItems,
  ) {
    parent::__construct(
      $entity_type_manager,
      $cache,
      $config_factory,
      $language_manager,
      $retrieval_configuration,
      $ranking_enhancer,
      $source_governance,
    );
    $this->index = $this->fixtureIndex;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQueryCacheKey(string $query, int $limit, ?string $type): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFaqDependencySnapshot(): array {
    return [
      'dependency_key' => 'faq_index',
      'classification' => 'required',
      'active' => TRUE,
      'status' => 'healthy',
      'failure_code' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchVector(string $query, int $limit, ?string $type = NULL): array {
    return $this->vectorItems;
  }

}

/**
 * Minimal FAQ index double with token-overlap matching.
 */
final class AssistantQualityFaqIndex {

  /**
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Paragraph fixtures.
   */
  public function __construct(private readonly array $paragraphs) {}

  /**
   * Matches Search API's enabled-index check.
   */
  public function status(): bool {
    return TRUE;
  }

  /**
   * Returns a query double.
   */
  public function query(): AssistantQualityFaqQuery {
    return new AssistantQualityFaqQuery($this->paragraphs);
  }

}

/**
 * Minimal query double over FAQ paragraph fixtures.
 */
final class AssistantQualityFaqQuery {

  private string $keys = '';

  private int $rangeStart = 0;

  private int $rangeLength = 1000;

  /**
   * @var array<string, mixed>
   */
  private array $conditions = [];

  /**
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Paragraph fixtures.
   */
  public function __construct(private readonly array $paragraphs) {}

  /**
   * Stores the fulltext search keys.
   */
  public function keys(string $query): self {
    $this->keys = $query;
    return $this;
  }

  /**
   * Stores range.
   */
  public function range(int $start, int $length): self {
    $this->rangeStart = $start;
    $this->rangeLength = $length;
    return $this;
  }

  /**
   * Stores a condition.
   */
  public function addCondition(string $field, mixed $value): self {
    $this->conditions[$field] = $value;
    return $this;
  }

  /**
   * Executes the query.
   */
  public function execute(): AssistantQualityFaqResultSet {
    $query_tokens = $this->tokens($this->keys);
    $matches = [];

    foreach ($this->paragraphs as $paragraph) {
      if (($this->conditions['paragraph_type'] ?? NULL) !== NULL && $paragraph->bundle() !== $this->conditions['paragraph_type']) {
        continue;
      }
      if (($this->conditions['search_api_language'] ?? NULL) !== NULL && $paragraph->language()->getId() !== $this->conditions['search_api_language']) {
        continue;
      }

      $question = (string) ($paragraph->get('field_faq_question')->value ?? '');
      $answer = (string) ($paragraph->get('field_faq_answer')->value ?? '');
      $score = count(array_intersect($query_tokens, $this->tokens($question . ' ' . $answer)));
      if ($score >= 2) {
        $matches[] = ['paragraph' => $paragraph, 'score' => $score];
      }
    }

    usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $paragraphs = array_map(static fn(array $match): Paragraph => $match['paragraph'], $matches);

    return new AssistantQualityFaqResultSet(array_slice($paragraphs, $this->rangeStart, $this->rangeLength));
  }

  /**
   * Tokenizes text for deterministic fixture matching.
   *
   * @return array<int, string>
   *   Meaningful tokens.
   */
  private function tokens(string $text): array {
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    $words = preg_split('/\s+/', trim((string) $text)) ?: [];
    $stop = array_flip([
      'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'from', 'how',
      'i', 'idaho', 'ilas', 'is', 'law', 'legal', 'my', 'of', 'or', 'our',
      'please', 'services', 'the', 'to', 'what', 'when', 'where', 'with',
      'you', 'your',
    ]);
    return array_values(array_unique(array_filter($words, static fn(string $word): bool => strlen($word) >= 3 && !isset($stop[$word]))));
  }

}

/**
 * Minimal result set double returning Search API-like result items.
 */
final class AssistantQualityFaqResultSet {

  /**
   * @param array<int, \Drupal\paragraphs\Entity\Paragraph> $paragraphs
   *   Paragraph fixtures.
   */
  public function __construct(private readonly array $paragraphs) {}

  /**
   * @return array<int, AssistantQualityFaqResultItem>
   *   Result item doubles.
   */
  public function getResultItems(): array {
    return array_map(
      static fn(Paragraph $paragraph): AssistantQualityFaqResultItem => new AssistantQualityFaqResultItem($paragraph),
      $this->paragraphs,
    );
  }

}

/**
 * Minimal Search API result item double wrapping a paragraph entity.
 */
final class AssistantQualityFaqResultItem {

  public function __construct(private readonly Paragraph $paragraph) {}

  /**
   * Returns a Search API-like original object wrapper.
   */
  public function getOriginalObject(): object {
    return new class($this->paragraph) {

      public function __construct(private readonly Paragraph $paragraph) {}

      /**
       *
       */
      public function getValue(): Paragraph {
        return $this->paragraph;
      }

    };
  }

}

/**
 * Resource finder double over real resource nodes with mocked vector results.
 */
final class AssistantQualityResourceFinderDouble extends ResourceFinder {

  /**
   * Constructs the resource fixture double.
   *
   * @param array<string, string> $sourceIdByEntityKey
   *   Fixture source IDs keyed by entity key.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TopicResolver $topic_resolver,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager,
    ?RankingEnhancer $ranking_enhancer,
    ?ConfigFactoryInterface $config_factory,
    ?RetrievalConfigurationService $retrieval_configuration,
    ?SourceGovernanceService $source_governance,
    mixed $top_intents_pack,
    mixed $file_url_generator,
    private readonly array $vectorConfig,
    private readonly array $vectorItems,
    private readonly array $sourceIdByEntityKey,
  ) {
    parent::__construct(
      $entity_type_manager,
      $topic_resolver,
      $cache,
      $language_manager,
      $ranking_enhancer,
      $config_factory,
      $retrieval_configuration,
      $source_governance,
      $top_intents_pack,
      $file_url_generator,
    );
  }

  /**
   * Searches real resource node fixtures with deterministic token matching.
   *
   * @return array<string, mixed>
   *   Detailed retrieval payload.
   */
  public function searchFixtureResources(string $query, ?string $type, int $limit): array {
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'resource',
      'status' => Node::PUBLISHED,
    ]);
    $query_tokens = $this->tokens($query);
    $items = [];

    foreach ($nodes as $node) {
      $resource_type = $this->determineResourceType($node);
      if ($type !== NULL && $resource_type !== $type) {
        continue;
      }

      $item = $this->buildResourceItem($node);
      $item['type'] = $resource_type;
      $item['fixture_source_id'] = $this->sourceIdByEntityKey['node_' . $node->id()] ?? NULL;
      $item['score'] = count(array_intersect($query_tokens, $this->tokens(implode(' ', [
        $item['title'] ?? '',
        $item['description'] ?? '',
        implode(' ', $item['topics'] ?? []),
      ])))) * 20.0;

      if ($item['score'] >= 40.0) {
        $items[] = $item;
      }
    }

    usort($items, static fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $items = array_slice($items, 0, $limit);

    return $this->supplementWithVectorResultsDetailed($items, $query, $type, $limit);
  }

  /**
   * {@inheritdoc}
   */
  protected function getVectorSearchConfig(): array {
    return $this->vectorConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function findByTypeVector(string $query, ?string $type, int $limit): array {
    return $this->vectorItems;
  }

  /**
   * Tokenizes text for deterministic resource matching.
   *
   * @return array<int, string>
   *   Meaningful tokens.
   */
  private function tokens(string $text): array {
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    $words = preg_split('/\s+/', trim((string) $text)) ?: [];
    $stop = array_flip([
      'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'from', 'how',
      'i', 'idaho', 'ilas', 'is', 'issue', 'law', 'legal', 'my', 'of', 'or',
      'our', 'phone', 'please', 'services', 'the', 'to', 'what', 'when',
      'where', 'with', 'you', 'your',
    ]);
    return array_values(array_unique(array_filter($words, static fn(string $word): bool => strlen($word) >= 3 && !isset($stop[$word]))));
  }

}

/**
 * Local reranker mock for kernel coverage without external API calls.
 */
final class AssistantQualityRerankerDouble implements RerankerInterface {

  public function __construct(private readonly string $preferredSourceId) {}

  /**
   * {@inheritdoc}
   */
  public function rerank(string $query, array $items, array $options = []): array {
    $before = array_column($items, 'fixture_source_id');
    usort($items, function (array $a, array $b): int {
      $a_preferred = ($a['fixture_source_id'] ?? '') === $this->preferredSourceId;
      $b_preferred = ($b['fixture_source_id'] ?? '') === $this->preferredSourceId;
      if ($a_preferred !== $b_preferred) {
        return $a_preferred ? -1 : 1;
      }
      return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    return [
      'items' => $items,
      'meta' => [
        'applied' => TRUE,
        'model' => 'fixture-reranker',
        'order_changed' => $before !== array_column($items, 'fixture_source_id'),
        'latency_ms' => 0,
      ],
    ];
  }

}
