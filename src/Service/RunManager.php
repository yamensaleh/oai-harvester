<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\oai_harvester\Entity\OaiSourceInterface;

/**
 * Creates harvest runs and keeps aggregate progress transactionally.
 */
final class RunManager implements RunManagerInterface {

  private const COUNTERS = ['discovery_pending', 'discovered', 'queued', 'processed', 'created_count', 'updated_count', 'skipped_count', 'warning_count', 'failed_count'];
  private const TERMINAL = ['completed', 'completed_with_warnings', 'failed', 'cancelled'];

  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   *
   */
  public function start(OaiSourceInterface $source, string $mode = 'incremental', ?string $from = NULL, ?string $until = NULL): int {
    if (!$source->status()) {
      throw new \InvalidArgumentException('The OAI-PMH source is disabled.');
    }
    $lockName = 'islandora_oai_start:' . $source->id();
    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new \RuntimeException('Another process is starting a harvest for this source.');
    }
    try {
      $active = $this->database->select('islandora_oai_harvest_run', 'r')
        ->condition('source_id', $source->id())
        ->condition('state', self::TERMINAL, 'NOT IN')
        ->countQuery()->execute()->fetchField();
      if ($active) {
        throw new \RuntimeException('This source already has an active harvest.');
      }
      $settings = $source->toRuntimeConfig();
      if ($mode === 'incremental' && $from === NULL) {
        $from = $this->database->select('islandora_oai_harvest_run', 'previous')
          ->fields('previous', ['until_date'])
          ->condition('source_id', $source->id())
          ->condition('state', ['completed', 'completed_with_warnings'], 'IN')
          ->condition('failed_count', 0)
          ->isNotNull('until_date')
          ->orderBy('completed', 'DESC')
          ->range(0, 1)
          ->execute()->fetchField() ?: NULL;
      }
      $until ??= ($settings['granularity'] ?? 'seconds') === 'day' ? gmdate('Y-m-d', $this->time->getRequestTime()) : gmdate('Y-m-d\TH:i:s\Z', $this->time->getRequestTime());
      $sets = $source->getSetSpecs() ?: [NULL];
      $runId = (int) $this->database->insert('islandora_oai_harvest_run')->fields([
        'source_id' => $source->id(),
        'mode' => $mode,
        'from_date' => $mode === 'full' ? NULL : $from,
        'until_date' => $until,
        'set_specs' => json_encode($source->getSetSpecs(), JSON_THROW_ON_ERROR),
        'state' => 'pending',
        'started' => $this->time->getRequestTime(),
        'discovery_pending' => count($sets),
      ])->execute();
      $queue = $this->queueFactory->get('islandora_oai_discovery', TRUE);
      foreach ($sets as $set) {
        $queue->createItem(['run_id' => $runId, 'source_id' => $source->id(), 'set' => $set, 'from' => $mode === 'full' ? NULL : $from, 'until' => $until]);
      }
      return $runId;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   *
   */
  public function retry(int $runId): int {
    $run = $this->load($runId);
    if (!$run) {
      throw new \InvalidArgumentException('Harvest run not found.');
    }
    $rows = $this->database->select('islandora_oai_raw_record', 'raw')
      ->fields('raw', ['id', 'source_id', 'oai_identifier', 'datestamp'])
      ->condition('run_id', $runId)
      ->condition('status', 'failed')
      ->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    $queue = $this->queueFactory->get('islandora_oai_import', TRUE);
    foreach ($rows as $row) {
      $this->database->update('islandora_oai_raw_record')->fields(['status' => 'queued', 'attempts' => 0, 'message' => NULL])->condition('id', $row['id'])->execute();
      $queue->createItem([
        'run_id' => $runId,
        'source_id' => $row['source_id'],
        'oai_identifier' => $row['oai_identifier'],
        'datestamp' => $row['datestamp'],
        'raw_id' => (int) $row['id'],
      ]);
    }
    if ($rows) {
      $this->database->update('islandora_oai_harvest_run')->fields(['state' => 'importing', 'completed' => NULL, 'failed_count' => 0])->condition('id', $runId)->execute();
    }
    return count($rows);
  }

  /**
   *
   */
  public function queueRecord(OaiSourceInterface $source, string $identifier, ?string $datestamp, array $sets, bool $deleted, string $xml): int {
    $now = $this->time->getRequestTime();
    $runId = (int) $this->database->insert('islandora_oai_harvest_run')->fields([
      'source_id' => $source->id(),
      'mode' => 'record',
      'set_specs' => json_encode($sets, JSON_THROW_ON_ERROR),
      'state' => 'importing',
      'started' => $now,
      'discovery_pending' => 0,
      'discovered' => 1,
      'queued' => 1,
    ])->execute();
    $rawId = (int) $this->database->insert('islandora_oai_raw_record')->fields([
      'run_id' => $runId,
      'source_id' => $source->id(),
      'oai_identifier' => $identifier,
      'datestamp' => $datestamp,
      'set_specs' => json_encode($sets, JSON_THROW_ON_ERROR),
      'deleted' => (int) $deleted,
      'xml' => $xml,
      'status' => 'queued',
      'created' => $now,
      'changed' => $now,
    ])->execute();
    $this->queueFactory->get('islandora_oai_import', TRUE)->createItem(['run_id' => $runId, 'source_id' => $source->id(), 'oai_identifier' => $identifier, 'datestamp' => $datestamp, 'raw_id' => $rawId]);
    return $runId;
  }

  /**
   *
   */
  public function setState(int $runId, string $state, ?string $message = NULL): void {
    $fields = ['state' => $state];
    if ($message !== NULL) {
      $fields['message'] = mb_substr($message, 0, 65535);
    }
    if (in_array($state, self::TERMINAL, TRUE)) {
      $fields['completed'] = $this->time->getRequestTime();
    }
    $this->database->update('islandora_oai_harvest_run')->fields($fields)->condition('id', $runId)->execute();
  }

  /**
   *
   */
  public function increment(int $runId, string $counter, int $amount = 1): void {
    if (!in_array($counter, self::COUNTERS, TRUE)) {
      throw new \InvalidArgumentException('Unknown harvest counter.');
    }
    $this->database->update('islandora_oai_harvest_run')
      ->expression($counter, $counter . ' + :amount', [':amount' => $amount])
      ->condition('id', $runId)->execute();
  }

  /**
   *
   */
  public function finishIfReady(int $runId): void {
    $run = $this->load($runId);
    if (!$run || in_array($run['state'], self::TERMINAL, TRUE) || (int) $run['discovery_pending'] > 0) {
      return;
    }
    $outstanding = $this->database->select('islandora_oai_raw_record', 'raw')
      ->condition('run_id', $runId)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->countQuery()->execute()->fetchField();
    if ($outstanding) {
      $this->setState($runId, 'importing');
      return;
    }
    $state = ((int) $run['failed_count'] > 0 || (int) $run['warning_count'] > 0) ? 'completed_with_warnings' : 'completed';
    $this->setState($runId, $state);
  }

  /**
   *
   */
  public function load(int $runId): array|false {
    return $this->database->select('islandora_oai_harvest_run', 'r')->fields('r')->condition('id', $runId)->execute()->fetchAssoc();
  }

  /**
   *
   */
  public function isStopped(int $runId): bool {
    $state = $this->getState($runId);
    return in_array($state, ['paused', 'cancelled', 'failed'], TRUE);
  }

  /**
   *
   */
  public function getState(int $runId): string|false {
    return $this->database->select('islandora_oai_harvest_run', 'r')->fields('r', ['state'])->condition('id', $runId)->execute()->fetchField();
  }

}
