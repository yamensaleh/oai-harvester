<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a metadata parser plugin annotation.
 *
 * @Annotation
 */
final class MetadataParser extends Plugin {
  public string $metadata_prefix;

}
