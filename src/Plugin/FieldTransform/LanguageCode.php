<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Plugin\FieldTransform;

use Drupal\Component\Plugin\PluginBase;
use Drupal\islandora_oai_harvester\Plugin\FieldTransformInterface;

/**
 * Normalizes an ISO language code before taxonomy lookup.
 *
 * @FieldTransform(id = "language_code", label = @Translation("Language code"))
 */
final class LanguageCode extends PluginBase implements FieldTransformInterface {

  /**
   *
   */
  public function transform(string $value, array $configuration = []): ?string {
    $value = strtolower(trim(str_replace('_', '-', $value)));
    return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $value) ? $value : NULL;
  }

}
