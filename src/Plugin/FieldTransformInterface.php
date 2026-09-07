<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Plugin;

/**
 * Transforms an individual metadata value before mapping.
 */
interface FieldTransformInterface {

  /**
   *
   */
  public function transform(string $value, array $configuration = []): ?string;

}
