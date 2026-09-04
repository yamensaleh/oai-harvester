<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_oai_harvester\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\islandora_oai_harvester\Entity\OaiSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests destination idempotency, updates, validation, and taxonomy reuse.
 *
 * @group islandora_oai_harvester
 */
final class IslandoraImporterTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy', 'file', 'image', 'media', 'options', 'key', 'islandora_oai_harvester'];

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('islandora_oai_harvester', ['islandora_oai_harvest_run', 'islandora_oai_discovery_page', 'islandora_oai_raw_record', 'islandora_oai_record', 'islandora_oai_imported_file']);
    NodeType::create(['type' => 'islandora_object', 'name' => 'Repository Item'])->save();
    Vocabulary::create(['vid' => 'subject', 'name' => 'Subject'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_subject', 'type' => 'entity_reference', 'cardinality' => -1, 'settings' => ['target_type' => 'taxonomy_term']])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'islandora_object', 'field_name' => 'field_subject', 'label' => 'Subject', 'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['subject' => 'subject']]]])->save();
  }

  /**
   *
   */
  public function testCreateDuplicateDeliveryUpdateAndTaxonomyReuse(): void {
    $source = $this->source();
    $xml = $this->firstRecordXml();
    $database = $this->container->get('database');
    $runId = (int) $database->insert('islandora_oai_harvest_run')->fields(['source_id' => 'test', 'mode' => 'full', 'state' => 'importing', 'started' => 1])->execute();
    $rawId = (int) $database->insert('islandora_oai_raw_record')->fields(['run_id' => $runId, 'source_id' => 'test', 'oai_identifier' => 'oai:example:1', 'xml' => $xml, 'status' => 'processing', 'created' => 1, 'changed' => 1])->execute();
    $importer = $this->container->get('islandora_oai_harvester.importer');

    $created = $importer->import($source, $xml, $runId, $rawId);
    self::assertSame('created', $created['result']);
    self::assertSame(1, $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->count()->execute());

    $duplicate = $importer->import($source, $xml, $runId, $rawId);
    self::assertSame('skipped', $duplicate['result']);
    self::assertSame($created['node_id'], $duplicate['node_id']);

    $changedXml = str_replace('First title', 'Changed title', $xml);
    $updated = $importer->import($source, $changedXml, $runId, $rawId);
    self::assertSame('updated', $updated['result']);
    self::assertSame($created['node_id'], $updated['node_id']);
    self::assertSame('Changed title', $this->container->get('entity_type.manager')->getStorage('node')->load($updated['node_id'])->label());
    self::assertSame(1, $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   *
   */
  public function testMissingRequiredTitleFails(): void {
    $source = $this->source([['source' => 'dc:missing', 'target' => 'title', 'behavior' => 'replace', 'cardinality' => 'first', 'transform' => 'trim', 'taxonomy_vocabulary' => '', 'skip_empty' => TRUE]]);
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('required title');
    $this->container->get('islandora_oai_harvester.importer')->import($source, $this->firstRecordXml(), 1, 1);
  }

  /**
   *
   */
  public function testRetryAfterFailureQueuesRecord(): void {
    $this->source();
    $database = $this->container->get('database');
    $runId = (int) $database->insert('islandora_oai_harvest_run')->fields(['source_id' => 'test', 'mode' => 'full', 'state' => 'completed_with_warnings', 'started' => 1, 'completed' => 2, 'failed_count' => 1])->execute();
    $database->insert('islandora_oai_raw_record')->fields(['run_id' => $runId, 'source_id' => 'test', 'oai_identifier' => 'oai:example:1', 'datestamp' => '2026-09-01', 'xml' => $this->firstRecordXml(), 'status' => 'failed', 'attempts' => 3, 'created' => 1, 'changed' => 2])->execute();
    self::assertSame(1, $this->container->get('islandora_oai_harvester.run_manager')->retry($runId));
    self::assertSame(1, $this->container->get('queue')->get('islandora_oai_import')->numberOfItems());
  }

  /**
   *
   */
  private function source(?array $mappings = NULL): OaiSource {
    $mappings ??= [
      ['source' => 'dc:title', 'target' => 'title', 'behavior' => 'replace', 'cardinality' => 'first', 'transform' => 'trim', 'taxonomy_vocabulary' => '', 'skip_empty' => TRUE],
      ['source' => 'dc:subject', 'target' => 'field_subject', 'behavior' => 'replace', 'cardinality' => 'all', 'transform' => 'trim', 'taxonomy_vocabulary' => 'subject', 'skip_empty' => TRUE],
    ];
    $source = OaiSource::load('test') ?: OaiSource::create(['id' => 'test', 'label' => 'Test', 'status' => TRUE, 'endpoint' => 'https://93.184.216.34/oai', 'bundle' => 'islandora_object']);
    $source->set('mappings', $mappings)->save();
    return $source;
  }

  /**
   *
   */
  private function firstRecordXml(): string {
    $document = new \DOMDocument();
    $document->load(__DIR__ . '/../../fixtures/list-records.xml');
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    return $document->saveXML($xpath->query('//oai:record')->item(0));
  }

}
