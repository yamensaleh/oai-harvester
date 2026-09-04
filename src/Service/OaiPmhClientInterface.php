<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;

/**
 * OAI-PMH protocol client contract.
 */
interface OaiPmhClientInterface {

  /**
   *
   */
  public function identify(OaiSourceInterface $source): array;

  /**
   *
   */
  public function listMetadataFormats(OaiSourceInterface $source): array;

  /**
   *
   */
  public function listSets(OaiSourceInterface $source, ?string $token = NULL): array;

  /**
   *
   */
  public function listIdentifiers(OaiSourceInterface $source, array $parameters = []): array;

  /**
   *
   */
  public function listRecords(OaiSourceInterface $source, array $parameters = []): array;

  /**
   *
   */
  public function getRecord(OaiSourceInterface $source, string $identifier): string;

}
