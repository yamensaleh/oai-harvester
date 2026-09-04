<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers field transformations.
 */
final class FieldTransformManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/FieldTransform', $namespaces, $module_handler, FieldTransformInterface::class, 'Drupal\islandora_oai_harvester\Annotation\FieldTransform');
    $this->setCacheBackend($cache_backend, 'islandora_oai_field_transform_plugins');
  }

}
