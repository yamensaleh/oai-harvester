<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Plugin\QueueWorker;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\islandora_oai_harvester\Plugin\MetadataParserManager;
use Drupal\islandora_oai_harvester\Service\OaiPmhClientInterface;
use Drupal\islandora_oai_harvester\Service\RunManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discovers and persists pages of OAI records.
 *
 * @QueueWorker(
 *   id = "islandora_oai_discovery",
 *   title = @Translation("Islandora OAI record discovery"),
 *   cron = {"time" = 60}
 * )
 */
final class DiscoveryQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly OaiPmhClientInterface $client, private readonly MetadataParserManager $parserManager, private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database, private readonly QueueFactory $queueFactory, private readonly RunManager $runManager, private readonly TimeInterface $time, private readonly LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_oai_harvester.client'), $container->get('plugin.manager.islandora_oai_metadata_parser'), $container->get('entity_type.manager'), $container->get('database'), $container->get('queue'), $container->get('islandora_oai_harvester.run_manager'), $container->get('datetime.time'), $container->get('logger.channel.islandora_oai_harvester'));
  }

  /**
   *
   */
  public function processItem($data): void {
    $runId = (int) $data['run_id'];
    if ($this->runManager->getState($runId) === 'paused') {
      throw new DelayedRequeueException(60, 'Harvest run is paused.');
    }
    if ($this->runManager->isStopped($runId)) {
      return;
    }
    $pageKey = hash('sha256', json_encode([$data['set'] ?? NULL, $data['resumption_token'] ?? NULL, $data['from'] ?? NULL, $data['until'] ?? NULL], JSON_THROW_ON_ERROR));
    try {
      $this->database->insert('islandora_oai_discovery_page')->fields(['run_id' => $runId, 'page_key' => $pageKey, 'status' => 'processing', 'changed' => $this->time->getRequestTime()])->execute();
    }
    catch (IntegrityConstraintViolationException) {
      $marker = $this->database->select('islandora_oai_discovery_page', 'p')->fields('p')->condition('run_id', $runId)->condition('page_key', $pageKey)->execute()->fetchAssoc();
      if (($marker['status'] ?? '') === 'completed') {
        return;
      }
      if ((int) ($marker['changed'] ?? 0) > $this->time->getRequestTime() - 600) {
        throw new RequeueException('This discovery page is already being processed.');
      }
      $this->database->update('islandora_oai_discovery_page')->fields(['status' => 'processing', 'changed' => $this->time->getRequestTime()])->condition('id', $marker['id'])->execute();
    }
    $source = $this->entityTypeManager->getStorage('islandora_oai_source')->load($data['source_id']);
    if (!$source || !$source->status()) {
      $this->runManager->setState($runId, 'failed', 'Source is missing or disabled.');
      return;
    }
    $this->runManager->setState($runId, 'discovering');
    try {
      $page = $this->client->listRecords($source, array_filter([
        'resumptionToken' => $data['resumption_token'] ?? NULL,
        'set' => $data['set'] ?? NULL,
        'from' => $data['from'] ?? NULL,
        'until' => $data['until'] ?? NULL,
      ], static fn($value): bool => $value !== NULL && $value !== ''));
      $parser = $this->parserManager->forPrefix($source->getMetadataPrefix());
      $importQueue = $this->queueFactory->get('islandora_oai_import', TRUE);
      foreach ($page['records'] as $xml) {
        $record = $parser->parse($xml);
        $now = $this->time->getRequestTime();
        $existing = $this->database->select('islandora_oai_raw_record', 'raw')->fields('raw', ['id', 'status'])->condition('run_id', $runId)->condition('source_id', $source->id())->condition('oai_identifier', $record['identifier'])->execute()->fetchAssoc();
        if ($existing) {
          if (in_array($existing['status'], ['queued', 'processing', 'completed'], TRUE)) {
            continue;
          }
          $rawId = (int) $existing['id'];
          $this->database->update('islandora_oai_raw_record')->fields(['xml' => $xml, 'status' => 'queued', 'changed' => $now, 'message' => NULL])->condition('id', $rawId)->execute();
        }
        else {
          $rawId = (int) $this->database->insert('islandora_oai_raw_record')->fields([
            'run_id' => $runId,
            'source_id' => $source->id(),
            'oai_identifier' => $record['identifier'],
            'datestamp' => $record['datestamp'],
            'set_specs' => json_encode($record['sets'], JSON_THROW_ON_ERROR),
            'deleted' => (int) $record['deleted'],
            'xml' => $xml,
            'status' => 'queued',
            'created' => $now,
            'changed' => $now,
          ])->execute();
          $this->runManager->increment($runId, 'discovered');
          $this->runManager->increment($runId, 'queued');
        }
        $importQueue->createItem(['run_id' => $runId, 'source_id' => $source->id(), 'oai_identifier' => $record['identifier'], 'datestamp' => $record['datestamp'], 'raw_id' => $rawId]);
      }
      if ($page['resumption_token']) {
        $this->queueFactory->get('islandora_oai_discovery', TRUE)->createItem([
          'run_id' => $runId,
          'source_id' => $source->id(),
          'resumption_token' => $page['resumption_token'],
          'set' => $data['set'] ?? NULL,
        ]);
        $this->database->update('islandora_oai_harvest_run')->fields(['resumption_token' => $page['resumption_token']])->condition('id', $runId)->execute();
      }
      else {
        $this->runManager->increment($runId, 'discovery_pending', -1);
      }
      $this->database->update('islandora_oai_discovery_page')->fields(['status' => 'completed', 'changed' => $this->time->getRequestTime()])->condition('run_id', $runId)->condition('page_key', $pageKey)->execute();
      $this->runManager->finishIfReady($runId);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Discovery failed for run {run}: {message}', ['run' => $runId, 'message' => $exception->getMessage()]);
      $this->database->update('islandora_oai_discovery_page')->fields(['status' => 'failed', 'changed' => $this->time->getRequestTime()])->condition('run_id', $runId)->condition('page_key', $pageKey)->execute();
      $this->runManager->setState($runId, 'failed', $exception->getMessage());
    }
  }

}
