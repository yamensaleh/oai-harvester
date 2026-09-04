<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;
use Drupal\islandora_oai_harvester\Plugin\FieldTransformManager;
use Drupal\islandora_oai_harvester\Plugin\MetadataParserManager;
use Psr\Log\LoggerInterface;

/**
 * Idempotently creates and updates Islandora nodes via entity APIs.
 */
final class IslandoraImporter implements DestinationImporterInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly MetadataParserManager $parserManager,
    private readonly FieldTransformManager $transformManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   *
   */
  public function import(OaiSourceInterface $source, string $recordXml, int $runId, int $rawId): array {
    $record = $this->parserManager->forPrefix($source->getMetadataPrefix())->parse($recordXml);
    $lockName = 'islandora_oai:' . hash('sha256', $source->id() . "\0" . $record['identifier']);
    if (!$this->lock->acquire($lockName, 120.0)) {
      throw new \RuntimeException('The same OAI record is already being imported.');
    }
    try {
      return $this->doImport($source, $record, $recordXml, $runId, $rawId);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   *
   */
  private function doImport(OaiSourceInterface $source, array $record, string $recordXml, int $runId, int $rawId): array {
    $now = $this->time->getRequestTime();
    $tracking = $this->database->select('islandora_oai_record', 'r')->fields('r')->condition('source_id', $source->id())->condition('oai_identifier', $record['identifier'])->execute()->fetchAssoc();
    $node = !empty($tracking['node_id']) ? $this->entityTypeManager->getStorage('node')->load($tracking['node_id']) : NULL;
    if ($tracking && !$node) {
      $this->logger->warning('Tracked node {nid} for {identifier} no longer exists; a replacement will be created.', ['nid' => $tracking['node_id'], 'identifier' => $record['identifier']]);
    }
    $settings = $source->toRuntimeConfig();
    if ($record['deleted']) {
      if ($node && $settings['deletion_policy'] === 'unpublish') {
        $node->setUnpublished()->save();
        $this->saveTracking($tracking, $source->id(), $record, (int) $node->id(), $runId, $rawId, $now, 'deleted', hash('sha256', $recordXml));
        return ['result' => 'updated', 'node_id' => (int) $node->id(), 'warnings' => []];
      }
      $this->saveTracking($tracking, $source->id(), $record, $node ? (int) $node->id() : NULL, $runId, $rawId, $now, 'deleted', hash('sha256', $recordXml));
      return ['result' => 'skipped', 'node_id' => $node ? (int) $node->id() : NULL, 'warnings' => []];
    }
    if ($tracking && hash_equals((string) ($tracking['metadata_hash'] ?? ''), hash('sha256', $recordXml))) {
      $this->saveTracking($tracking, $source->id(), $record, $node ? (int) $node->id() : NULL, $runId, $rawId, $now, 'synchronized', hash('sha256', $recordXml));
      return ['result' => 'skipped', 'node_id' => $node ? (int) $node->id() : NULL, 'warnings' => []];
    }
    if ($node && $settings['update_policy'] === 'skip_existing') {
      $this->saveTracking($tracking, $source->id(), $record, (int) $node->id(), $runId, $rawId, $now, 'skipped', hash('sha256', $recordXml));
      return ['result' => 'skipped', 'node_id' => (int) $node->id(), 'warnings' => []];
    }

    $title = $this->mappedTitle($source, $record['values']);
    if ($title === NULL || trim($title) === '') {
      throw new \UnexpectedValueException('The record has no mapped required title.');
    }
    $created = !$node;
    if (!$node) {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => $settings['bundle'],
        'title' => $title,
        'status' => (int) $settings['default_status'],
      ]);
    }
    else {
      $node->setTitle($title);
    }
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $settings['bundle']);
    $warnings = [];
    foreach ($source->getMappings() as $mapping) {
      $target = (string) ($mapping['target'] ?? '');
      if ($target === '' || $target === 'title') {
        continue;
      }
      if (!isset($definitions[$target]) || !$node->hasField($target)) {
        $warnings[] = sprintf('Mapped target field "%s" does not exist.', $target);
        continue;
      }
      $values = $this->mappedValues($mapping, $record['values'][(string) ($mapping['source'] ?? '')] ?? []);
      if (!$values && !empty($mapping['skip_empty'])) {
        continue;
      }
      $field = $definitions[$target];
      $cardinality = $field->getFieldStorageDefinition()->getCardinality();
      if (($mapping['cardinality'] ?? 'all') === 'first' || $cardinality === 1) {
        $values = array_slice($values, 0, 1);
      }
      if ($field->getType() === 'entity_reference') {
        $targetType = $field->getFieldStorageDefinition()->getSetting('target_type');
        if ($targetType !== 'taxonomy_term' || empty($mapping['taxonomy_vocabulary'])) {
          $warnings[] = sprintf('Entity reference field "%s" requires a taxonomy vocabulary mapping.', $target);
          continue;
        }
        $values = $this->taxonomyValues($values, (string) $mapping['taxonomy_vocabulary']);
      }
      else {
        $values = array_map(fn(string $value): array => $this->primitiveValue($field->getType(), $value), $values);
      }
      if (($mapping['behavior'] ?? 'replace') === 'append' && !$node->get($target)->isEmpty()) {
        foreach ($values as $value) {
          $node->get($target)->appendItem($value);
        }
      }
      else {
        $node->set($target, $values);
      }
    }
    if ($node->hasField('field_model') && !empty($settings['model_tid'])) {
      $node->set('field_model', ['target_id' => (int) $settings['model_tid']]);
    }
    if ($node->hasField('field_category') && !empty($settings['category_tid'])) {
      $node->set('field_category', ['target_id' => (int) $settings['category_tid']]);
    }
    if ($node->hasField('field_member_of') && !empty($settings['collection_nid'])) {
      $node->set('field_member_of', ['target_id' => (int) $settings['collection_nid']]);
    }
    $violations = $node->validate();
    if ($violations->count()) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      throw new \UnexpectedValueException('Destination validation failed: ' . implode('; ', $messages));
    }
    $node->save();
    $this->saveTracking($tracking, $source->id(), $record, (int) $node->id(), $runId, $rawId, $now, 'synchronized', hash('sha256', $recordXml));
    return ['result' => $created ? 'created' : 'updated', 'node_id' => (int) $node->id(), 'warnings' => $warnings];
  }

  /**
   *
   */
  private function mappedTitle(OaiSourceInterface $source, array $values): ?string {
    foreach ($source->getMappings() as $mapping) {
      if (($mapping['target'] ?? '') === 'title') {
        return $this->mappedValues($mapping, $values[$mapping['source']] ?? [])[0] ?? NULL;
      }
    }
    return NULL;
  }

  /**
   * @return string[] */
  private function mappedValues(array $mapping, array $values): array {
    $result = [];
    foreach ($values as $value) {
      $value = (string) $value;
      $pluginId = (string) ($mapping['transform'] ?? 'trim');
      if ($pluginId !== 'none') {
        $value = $this->transformManager->createInstance($pluginId)->transform($value, $mapping);
      }
      if ($value !== NULL && ($value !== '' || empty($mapping['skip_empty']))) {
        $result[] = $value;
      }
    }
    return array_values(array_unique($result));
  }

  /**
   *
   */
  private function taxonomyValues(array $values, string $vocabulary): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $result = [];
    foreach ($values as $value) {
      $termLock = 'islandora_oai_term:' . hash('sha256', $vocabulary . "\0" . mb_strtolower($value));
      if (!$this->lock->acquire($termLock, 30.0)) {
        $this->lock->wait($termLock, 5);
        if (!$this->lock->acquire($termLock, 30.0)) {
          throw new \RuntimeException('Could not acquire taxonomy term lock.');
        }
      }
      try {
        $terms = [];
        if ($vocabulary === 'language') {
          $terms = $storage->loadByProperties(['vid' => $vocabulary, 'field_iso_code' => $value]);
        }
        $terms = $terms ?: $storage->loadByProperties(['vid' => $vocabulary, 'name' => $value]);
        $term = $terms ? reset($terms) : $storage->create(['vid' => $vocabulary, 'name' => $value]);
        if ($term->isNew()) {
          if ($vocabulary === 'language' && $term->hasField('field_iso_code')) {
            $term->set('field_iso_code', $value);
          }
          $term->save();
        }
        $result[] = ['target_id' => (int) $term->id()];
      }
      finally {
        $this->lock->release($termLock);
      }
    }
    return $result;
  }

  /**
   *
   */
  private function primitiveValue(string $fieldType, string $value): array {
    return match ($fieldType) {
      'link' => ['uri' => $value],
      'datetime' => ['value' => preg_replace('/Z$/', '', $value)],
      'timestamp', 'integer' => ['value' => (int) $value],
      'decimal', 'float' => ['value' => (float) $value],
      'boolean' => ['value' => in_array(strtolower($value), ['1', 'true', 'yes'], TRUE) ? 1 : 0],
      default => ['value' => $value],
    };
  }

  /**
   *
   */
  private function saveTracking(array|false $tracking, string $sourceId, array $record, ?int $nodeId, int $runId, int $rawId, int $now, string $status, string $hash): void {
    $fields = [
      'source_id' => $sourceId,
      'oai_identifier' => $record['identifier'],
      'node_id' => $nodeId,
      'source_datestamp' => $record['datestamp'],
      'set_specs' => json_encode($record['sets'], JSON_THROW_ON_ERROR),
      'last_harvested' => $now,
      'last_sync' => $now,
      'sync_status' => $status,
      'last_run_id' => $runId,
      'raw_id' => $rawId,
      'metadata_hash' => $hash,
    ];
    if ($tracking) {
      $this->database->update('islandora_oai_record')->fields($fields)->condition('id', $tracking['id'])->execute();
    }
    else {
      $fields['first_harvested'] = $now;
      $this->database->insert('islandora_oai_record')->fields($fields)->execute();
    }
  }

}
