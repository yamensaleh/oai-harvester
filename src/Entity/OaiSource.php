<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines an OAI-PMH source.
 *
 * @ConfigEntityType(
 *   id = "islandora_oai_source",
 *   label = @Translation("OAI-PMH source"),
 *   label_collection = @Translation("OAI-PMH sources"),
 *   handlers = {
 *     "list_builder" = "Drupal\islandora_oai_harvester\OaiSourceListBuilder",
 *     "form" = {
 *       "add" = "Drupal\islandora_oai_harvester\Form\OaiSourceForm",
 *       "edit" = "Drupal\islandora_oai_harvester\Form\OaiSourceForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "source",
 *   admin_permission = "administer islandora oai sources",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id", "label", "status", "endpoint", "auth_key_id",
 *     "metadata_prefix", "granularity", "set_specs", "repository_info",
 *     "available_formats", "available_sets", "bundle", "model_tid",
 *     "collection_nid", "default_status", "mappings", "frequency",
 *     "batch_size", "update_policy", "deletion_policy", "timeout",
 *     "max_retries", "rate_limit_ms", "file_settings"
 *   },
 *   links = {
 *     "collection" = "/admin/islandora/oai-harvester",
 *     "add-form" = "/admin/islandora/oai-harvester/source/add",
 *     "edit-form" = "/admin/islandora/oai-harvester/source/{islandora_oai_source}",
 *     "delete-form" = "/admin/islandora/oai-harvester/source/{islandora_oai_source}/delete"
 *   }
 * )
 */
final class OaiSource extends ConfigEntityBase implements OaiSourceInterface {

  protected string $label;
  protected string $endpoint = '';
  protected ?string $auth_key_id = NULL;
  protected string $metadata_prefix = 'oai_dc';
  protected string $granularity = 'seconds';
  protected array $set_specs = [];
  protected array $repository_info = [];
  protected array $available_formats = [];
  protected array $available_sets = [];
  protected string $bundle = 'islandora_object';
  protected ?int $model_tid = NULL;
  protected ?int $collection_nid = NULL;
  protected bool $default_status = FALSE;
  protected array $mappings = [];
  protected string $frequency = 'daily';
  protected int $batch_size = 100;
  protected string $update_policy = 'replace_mapped';
  protected string $deletion_policy = 'unpublish';
  protected int $timeout = 30;
  protected int $max_retries = 3;
  protected int $rate_limit_ms = 0;
  protected array $file_settings = [
    'enabled' => FALSE,
    'source_element' => 'dc:identifier',
    'allowed_domains' => [],
    'allowed_mime_types' => [],
    'allowed_extensions' => [],
    'max_bytes' => 104857600,
    'media_bundle' => 'file',
    'file_field' => 'field_media_file',
    'media_of_field' => 'field_media_of',
  ];

  /**
   *
   */
  public function getEndpoint(): string {
    return $this->endpoint;
  }

  /**
   *
   */
  public function getMetadataPrefix(): string {
    return $this->metadata_prefix;
  }

  /**
   *
   */
  public function getSetSpecs(): array {
    return $this->set_specs;
  }

  /**
   *
   */
  public function getMappings(): array {
    return $this->mappings;
  }

  /**
   *
   */
  public function getFileSettings(): array {
    return $this->file_settings;
  }

  /**
   *
   */
  public function toRuntimeConfig(): array {
    return [
      'id' => $this->id(),
      'endpoint' => $this->endpoint,
      'auth_key_id' => $this->auth_key_id,
      'metadata_prefix' => $this->metadata_prefix,
      'granularity' => $this->granularity,
      'set_specs' => $this->set_specs,
      'repository_info' => $this->repository_info,
      'available_formats' => $this->available_formats,
      'available_sets' => $this->available_sets,
      'bundle' => $this->bundle,
      'model_tid' => $this->model_tid,
      'collection_nid' => $this->collection_nid,
      'default_status' => $this->default_status,
      'mappings' => $this->mappings,
      'frequency' => $this->frequency,
      'batch_size' => $this->batch_size,
      'update_policy' => $this->update_policy,
      'deletion_policy' => $this->deletion_policy,
      'timeout' => $this->timeout,
      'max_retries' => $this->max_retries,
      'rate_limit_ms' => $this->rate_limit_ms,
      'file_settings' => $this->file_settings,
    ];
  }

}
