<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;

/**
 * Harvest run lifecycle contract.
 */
interface RunManagerInterface {

  /**
   *
   */
  public function start(OaiSourceInterface $source, string $mode = 'incremental', ?string $from = NULL, ?string $until = NULL): int;

  /**
   *
   */
  public function retry(int $runId): int;

  /**
   *
   */
  public function queueRecord(OaiSourceInterface $source, string $identifier, ?string $datestamp, array $sets, bool $deleted, string $xml): int;

  /**
   *
   */
  public function setState(int $runId, string $state, ?string $message = NULL): void;

  /**
   *
   */
  public function increment(int $runId, string $counter, int $amount = 1): void;

  /**
   *
   */
  public function finishIfReady(int $runId): void;

}
