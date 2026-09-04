<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Plugin\FieldTransform;

use Drupal\Component\Plugin\PluginBase;
use Drupal\islandora_oai_harvester\Plugin\FieldTransformInterface;

/**
 * Normalizes textual metadata.
 *
 * @FieldTransform(id = "trim", label = @Translation("Trim whitespace"))
 */
final class NormalizeText extends PluginBase implements FieldTransformInterface {

  /**
   *
   */
  public function transform(string $value, array $configuration = []): ?string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return $value === '' ? NULL : $value;
  }

}
