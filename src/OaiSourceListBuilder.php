<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists configured sources.
 */
final class OaiSourceListBuilder extends ConfigEntityListBuilder {

  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, private readonly Connection $database) {
    parent::__construct($entity_type, $storage);
  }

  /**
   *
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()), $container->get('database'));
  }

  /**
   *
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Source name'),
      'endpoint' => $this->t('Endpoint'),
      'sets' => $this->t('Selected sets'),
      'format' => $this->t('Metadata format'),
      'last' => $this->t('Last successful harvest'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   *
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\islandora_oai_harvester\Entity\OaiSourceInterface $entity */
    $config = $entity->toRuntimeConfig();
    $row = [
      'label' => $entity->label(),
      'endpoint' => $config['endpoint'],
      'sets' => $config['set_specs'] ? implode(', ', $config['set_specs']) : $this->t('All'),
      'format' => $config['metadata_prefix'],
      'last' => $this->lastSuccessfulHarvest($entity),
      'status' => $this->currentStatus($entity),
    ];
    return $row + parent::buildRow($entity);
  }

  /**
   *
   */
  private function currentStatus(EntityInterface $entity): string {
    if (!$entity->status()) {
      return (string) $this->t('Disabled');
    }
    $state = $this->database->select('islandora_oai_harvest_run', 'r')
      ->fields('r', ['state'])
      ->condition('source_id', $entity->id())
      ->orderBy('started', 'DESC')
      ->range(0, 1)
      ->execute()->fetchField();
    return $state ? ucfirst(str_replace('_', ' ', (string) $state)) : (string) $this->t('Ready');
  }

  /**
   *
   */
  private function lastSuccessfulHarvest(EntityInterface $entity): string {
    $completed = $this->database->select('islandora_oai_harvest_run', 'r')
      ->fields('r', ['completed'])
      ->condition('source_id', $entity->id())
      ->condition('state', ['completed', 'completed_with_warnings'], 'IN')
      ->condition('failed_count', 0)
      ->orderBy('completed', 'DESC')
      ->range(0, 1)
      ->execute()->fetchField();
    return $completed ? date('Y-m-d H:i:s T', (int) $completed) : (string) $this->t('Never');
  }

  /**
   *
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $id = $entity->id();
    $operations['test'] = ['title' => $this->t('Test'), 'url' => Url::fromRoute('islandora_oai_harvester.source_test', ['islandora_oai_source' => $id]), 'weight' => 10];
    $operations['harvest'] = ['title' => $this->t('Harvest now'), 'url' => Url::fromRoute('islandora_oai_harvester.source_harvest', ['islandora_oai_source' => $id]), 'weight' => 11];
    $operations['history'] = ['title' => $this->t('History'), 'url' => Url::fromRoute('islandora_oai_harvester.source_history', ['islandora_oai_source' => $id]), 'weight' => 12];
    $operations['preview'] = ['title' => $this->t('Preview'), 'url' => Url::fromRoute('islandora_oai_harvester.source_preview', ['islandora_oai_source' => $id]), 'weight' => 13];
    $operations['toggle'] = [
      'title' => $entity->status() ? $this->t('Disable') : $this->t('Enable'),
      'url' => Url::fromRoute('islandora_oai_harvester.source_toggle', ['islandora_oai_source' => $id]),
      'weight' => 14,
    ];
    return $operations;
  }

}
