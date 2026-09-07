<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\oai_harvester\Entity\OaiSourceInterface;

/**
 * Imports normalized metadata into a destination repository.
 */
interface DestinationImporterInterface {

  /**
   *
   */
  public function import(OaiSourceInterface $source, string $recordXml, int $runId, int $rawId): array;

}
