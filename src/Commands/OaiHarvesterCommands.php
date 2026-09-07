<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oai_harvester\Service\RunManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for OAI-PMH harvest operations.
 */
final class OaiHarvesterCommands extends DrushCommands {

  public function __construct(private readonly RunManagerInterface $runManager, private readonly EntityTypeManagerInterface $entityTypeManager, private readonly Connection $database) {
    parent::__construct();
  }

  /**
   * Queue a full or incremental harvest.
   *
   * @command oai-harvester:run
   * @aliases oai-run
   * @param string $sourceId
   *   Configured source machine name.
   *
   * @option full
   *   Start a full harvest instead of an incremental harvest.
   * @option from
   *   Optional OAI from datestamp.
   * @option until
   *   Optional OAI until datestamp.
   * @usage drush oai-harvester:run university_repository
   */
  public function run(string $sourceId, array $options = ['full' => FALSE, 'from' => NULL, 'until' => NULL]): int {
    $source = $this->entityTypeManager->getStorage('islandora_oai_source')->load($sourceId);
    if (!$source) {
      $this->logger()->error('Unknown OAI-PMH source: {source}', ['source' => $sourceId]);
      return self::EXIT_FAILURE;
    }
    try {
      $runId = $this->runManager->start($source, !empty($options['full']) ? 'full' : 'incremental', $options['from'] ?: NULL, $options['until'] ?: NULL);
      $this->output()->writeln(sprintf('Queued harvest run %d for %s.', $runId, $sourceId));
      return self::EXIT_SUCCESS;
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Show progress for a harvest run.
   *
   * @command oai-harvester:status
   * @aliases oai-status
   * @usage drush oai-harvester:status 123
   */
  public function status(int $runId): int {
    $run = $this->database->select('islandora_oai_harvest_run', 'r')->fields('r')->condition('id', $runId)->execute()->fetchAssoc();
    if (!$run) {
      $this->logger()->error('Harvest run {run} was not found.', ['run' => $runId]);
      return self::EXIT_FAILURE;
    }
    foreach ($run as $key => $value) {
      $this->output()->writeln(sprintf('%-20s %s', $key . ':', (string) $value));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Requeue failed records from a harvest run.
   *
   * @command oai-harvester:retry
   * @aliases oai-retry
   * @usage drush oai-harvester:retry 123
   */
  public function retry(int $runId): int {
    try {
      $count = $this->runManager->retry($runId);
      $this->output()->writeln(sprintf('Queued %d failed record(s).', $count));
      return self::EXIT_SUCCESS;
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }
  }

}
