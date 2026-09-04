<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;

/**
 * Imports normalized metadata into a destination repository.
 */
interface DestinationImporterInterface {

  /**
   *
   */
  public function import(OaiSourceInterface $source, string $recordXml, int $runId, int $rawId): array;

}
