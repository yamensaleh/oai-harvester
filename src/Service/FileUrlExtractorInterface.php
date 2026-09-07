<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\oai_harvester\Entity\OaiSourceInterface;

/**
 * Finds explicitly configured file URLs in source metadata.
 */
interface FileUrlExtractorInterface {

  /**
   * @return string[] */
  public function extract(OaiSourceInterface $source, string $recordXml): array;

}
