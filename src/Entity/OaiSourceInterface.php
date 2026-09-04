<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines the OAI-PMH source contract.
 */
interface OaiSourceInterface extends ConfigEntityInterface {

  /**
   *
   */
  public function getEndpoint(): string;

  /**
   *
   */
  public function getMetadataPrefix(): string;

  /**
   * @return string[] */
  public function getSetSpecs(): array;

  /**
   * @return array<int, array<string, mixed>> */
  public function getMappings(): array;

  /**
   * @return array<string, mixed> */
  public function getFileSettings(): array;

  /**
   * @return array<string, mixed> */
  public function toRuntimeConfig(): array;

}
