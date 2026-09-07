<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\oai_harvester\Entity\OaiSourceInterface;
use Drupal\oai_harvester\Plugin\MetadataParserManager;

/**
 * Extracts URLs only from the administrator-selected metadata element.
 */
final class MappedFileUrlExtractor implements FileUrlExtractorInterface {

  public function __construct(private readonly MetadataParserManager $parserManager) {}

  /**
   *
   */
  public function extract(OaiSourceInterface $source, string $recordXml): array {
    $element = (string) ($source->getFileSettings()['source_element'] ?? '');
    if ($element === '') {
      return [];
    }
    $record = $this->parserManager->forPrefix($source->getMetadataPrefix())->parse($recordXml);
    return array_values(array_unique(array_filter(array_map('trim', $record['values'][$element] ?? []), static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_URL) !== FALSE)));
  }

}
