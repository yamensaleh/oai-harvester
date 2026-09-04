<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;

/**
 * Imports explicitly mapped remote files as Drupal media.
 */
interface FileImporterInterface {

  /**
   * @return string[] Warnings that do not invalidate the metadata import. */
  public function import(OaiSourceInterface $source, string $recordXml, int $nodeId, string $oaiIdentifier): array;

}
