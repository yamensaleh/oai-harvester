<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\islandora_oai_harvester\Service\DestinationImporterInterface;
use Drupal\islandora_oai_harvester\Service\FileImporterInterface;
use Drupal\islandora_oai_harvester\Service\RunManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports one persisted OAI record.
 *
 * @QueueWorker(
 *   id = "islandora_oai_import",
 *   title = @Translation("Islandora OAI record import"),
 *   cron = {"time" = 60}
 * )
 */
final class ImportQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly DestinationImporterInterface $importer, private readonly FileImporterInterface $fileImporter, private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database, private readonly RunManager $runManager, private readonly TimeInterface $time, private readonly LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_oai_harvester.importer'), $container->get('islandora_oai_harvester.file_importer'), $container->get('entity_type.manager'), $container->get('database'), $container->get('islandora_oai_harvester.run_manager'), $container->get('datetime.time'), $container->get('logger.channel.islandora_oai_harvester'));
  }

  /**
   *
   */
  public function processItem($data): void {
    $runId = (int) $data['run_id'];
    $rawId = (int) $data['raw_id'];
    if ($this->runManager->getState($runId) === 'paused') {
      throw new DelayedRequeueException(60, 'Harvest run is paused.');
    }
    if ($this->runManager->isStopped($runId)) {
      return;
    }
    $raw = $this->database->select('islandora_oai_raw_record', 'raw')->fields('raw')->condition('id', $rawId)->execute()->fetchAssoc();
    if (!$raw || $raw['status'] === 'completed') {
      return;
    }
    $source = $this->entityTypeManager->getStorage('islandora_oai_source')->load($data['source_id']);
    if (!$source) {
      $this->fail($rawId, $runId, 'The configured source no longer exists.', TRUE);
      return;
    }
    $attempt = (int) $raw['attempts'] + 1;
    $this->database->update('islandora_oai_raw_record')->fields(['status' => 'processing', 'attempts' => $attempt, 'changed' => $this->time->getRequestTime()])->condition('id', $rawId)->execute();
    try {
      $result = $this->importer->import($source, (string) $raw['xml'], $runId, $rawId);
      $warnings = $result['warnings'] ?? [];
      if (!empty($result['node_id'])) {
        $fileWarnings = $this->fileImporter->import($source, (string) $raw['xml'], (int) $result['node_id'], (string) $raw['oai_identifier']);
        $warnings = array_merge($warnings, $fileWarnings);
      }
      $this->database->update('islandora_oai_raw_record')->fields([
        'status' => 'completed',
        'result' => $result['result'],
        'message' => $warnings ? implode('; ', $warnings) : NULL,
        'changed' => $this->time->getRequestTime(),
      ])->condition('id', $rawId)->execute();
      $this->runManager->increment($runId, 'processed');
      $counter = match ($result['result']) {
        'created' => 'created_count',
        'updated' => 'updated_count',
        default => 'skipped_count',
      };
      $this->runManager->increment($runId, $counter);
      if ($warnings) {
        $this->runManager->increment($runId, 'warning_count', count($warnings));
      }
      $this->runManager->finishIfReady($runId);
    }
    catch (\Throwable $exception) {
      $maximum = max(1, (int) $source->toRuntimeConfig()['max_retries']);
      $terminal = $attempt >= $maximum || $exception instanceof \UnexpectedValueException;
      $this->fail($rawId, $runId, $exception->getMessage(), $terminal);
      $this->logger->error('Import failed for run {run}, record {identifier}: {message}', ['run' => $runId, 'identifier' => $raw['oai_identifier'], 'message' => $exception->getMessage()]);
      if (!$terminal) {
        throw new RequeueException('Temporary OAI import failure; item requeued.', 0, $exception);
      }
    }
  }

  /**
   *
   */
  private function fail(int $rawId, int $runId, string $message, bool $terminal): void {
    $this->database->update('islandora_oai_raw_record')->fields([
      'status' => $terminal ? 'failed' : 'queued',
      'result' => $terminal ? 'failed' : NULL,
      'message' => mb_substr($message, 0, 65535),
      'changed' => $this->time->getRequestTime(),
    ])->condition('id', $rawId)->execute();
    if ($terminal) {
      $this->runManager->increment($runId, 'processed');
      $this->runManager->increment($runId, 'failed_count');
      $this->runManager->finishIfReady($runId);
    }
  }

}
