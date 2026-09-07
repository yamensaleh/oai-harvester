<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Plugin\FieldTransform;

use Drupal\Component\Plugin\PluginBase;
use Drupal\oai_harvester\Plugin\FieldTransformInterface;

/**
 * Lowercases metadata values.
 *
 * @FieldTransform(id = "lowercase", label = @Translation("Lowercase"))
 */
final class Lowercase extends PluginBase implements FieldTransformInterface {

  /**
   *
   */
  public function transform(string $value, array $configuration = []): ?string {
    $value = trim($value);
    return $value === '' ? NULL : mb_strtolower($value);
  }

}
