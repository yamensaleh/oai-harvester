<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers metadata parser plugins.
 */
final class MetadataParserManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/MetadataParser', $namespaces, $module_handler, MetadataParserInterface::class, 'Drupal\oai_harvester\Annotation\MetadataParser');
    $this->alterInfo('islandora_oai_metadata_parser_info');
    $this->setCacheBackend($cache_backend, 'islandora_oai_metadata_parser_plugins');
  }

  /**
   *
   */
  public function forPrefix(string $prefix): MetadataParserInterface {
    foreach ($this->getDefinitions() as $id => $definition) {
      if (($definition['metadata_prefix'] ?? '') === $prefix) {
        return $this->createInstance($id);
      }
    }
    throw new \InvalidArgumentException(sprintf('No metadata parser supports "%s".', $prefix));
  }

}
