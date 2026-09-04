<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures a complete OAI-PMH source and its destination mapping.
 */
final class OaiSourceForm extends EntityForm {

  private const DC_ELEMENTS = ['title', 'creator', 'subject', 'description', 'publisher', 'contributor', 'date', 'type', 'format', 'identifier', 'source', 'language', 'relation', 'coverage', 'rights'];

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager, private readonly EntityFieldManagerInterface $entityFieldManager) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('entity_field.manager'));
  }

  /**
   *
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\islandora_oai_harvester\Entity\OaiSourceInterface $source */
    $source = $this->entity;
    $settings = $source->toRuntimeConfig();
    $form['label'] = ['#type' => 'textfield', '#title' => $this->t('Source name'), '#default_value' => $source->label(), '#required' => TRUE];
    $form['id'] = ['#type' => 'machine_name', '#default_value' => $source->id(), '#machine_name' => ['exists' => '\Drupal\islandora_oai_harvester\Entity\OaiSource::load'], '#disabled' => !$source->isNew()];
    $form['status'] = ['#type' => 'checkbox', '#title' => $this->t('Enabled'), '#default_value' => $source->status()];

    $form['connection'] = ['#type' => 'details', '#title' => $this->t('1. Connection'), '#open' => TRUE];
    $form['connection']['endpoint'] = ['#type' => 'url', '#title' => $this->t('OAI-PMH endpoint'), '#default_value' => $settings['endpoint'], '#required' => TRUE, '#description' => $this->t('The endpoint is checked for public-network resolution before every request.')];
    $form['connection']['auth_key_id'] = ['#type' => 'entity_autocomplete', '#title' => $this->t('Optional authentication key'), '#target_type' => 'key', '#default_value' => !empty($settings['auth_key_id']) ? $this->entityTypeManager->getStorage('key')->load($settings['auth_key_id']) : NULL, '#description' => $this->t('Use a Key entity whose value is username:password. Secrets are never copied into this source configuration.')];
    $form['connection']['timeout'] = ['#type' => 'number', '#title' => $this->t('Request timeout (seconds)'), '#default_value' => $settings['timeout'], '#min' => 1, '#max' => 300, '#required' => TRUE];
    $form['connection']['max_retries'] = ['#type' => 'number', '#title' => $this->t('HTTP attempts'), '#default_value' => $settings['max_retries'], '#min' => 1, '#max' => 10, '#required' => TRUE];
    $form['connection']['rate_limit_ms'] = ['#type' => 'number', '#title' => $this->t('Minimum delay between requests (milliseconds)'), '#default_value' => $settings['rate_limit_ms'], '#min' => 0, '#max' => 60000];

    $form['selection'] = ['#type' => 'details', '#title' => $this->t('2. Content selection'), '#open' => TRUE];
    $form['selection']['metadata_prefix'] = ['#type' => 'select', '#title' => $this->t('Metadata format'), '#options' => ['oai_dc' => 'oai_dc'], '#default_value' => $settings['metadata_prefix'], '#required' => TRUE, '#description' => $this->t('Additional parser plugins can provide more formats later.')];
    $form['selection']['granularity'] = ['#type' => 'select', '#title' => $this->t('Datestamp granularity'), '#options' => ['seconds' => 'YYYY-MM-DDThh:mm:ssZ', 'day' => 'YYYY-MM-DD'], '#default_value' => $settings['granularity'] ?? 'seconds', '#description' => $this->t('Match the granularity reported by Identify.')];
    if (!empty($settings['repository_info'])) {
      $identity = $settings['repository_info'];
      $form['selection']['identity'] = ['#type' => 'item', '#title' => $this->t('Repository identity'), '#markup' => $this->t('@name; protocol @protocol; earliest @earliest; granularity @granularity', ['@name' => $identity['repositoryName'] ?? '', '@protocol' => $identity['protocolVersion'] ?? '', '@earliest' => $identity['earliestDatestamp'] ?? '', '@granularity' => $identity['granularity'] ?? ''])];
    }
    if (!empty($settings['available_formats'])) {
      $form['selection']['formats_found'] = ['#type' => 'item', '#title' => $this->t('Formats reported by repository'), '#markup' => $this->t('@formats', ['@formats' => implode(', ', array_column($settings['available_formats'], 'prefix'))])];
    }
    $setOptions = [];
    foreach ($settings['available_sets'] ?? [] as $set) {
      $setOptions[$set['spec']] = $set['name'] . ' (' . $set['spec'] . ')';
    }
    if ($setOptions) {
      $form['selection']['set_choices'] = ['#type' => 'checkboxes', '#title' => $this->t('Available OAI sets'), '#options' => $setOptions, '#default_value' => array_values(array_intersect($settings['set_specs'], array_keys($setOptions)))];
    }
    $manualSets = array_values(array_diff($settings['set_specs'], array_keys($setOptions)));
    $form['selection']['set_specs'] = ['#type' => 'textarea', '#title' => $this->t('Additional OAI set specifications'), '#default_value' => implode("\n", $manualSets), '#description' => $this->t('One setSpec per line. Leave all choices empty to harvest all records. Test the connection to refresh available sets.')];

    $bundles = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $bundle) {
      $bundles[$bundle->id()] = $bundle->label();
    }
    $form['destination'] = ['#type' => 'details', '#title' => $this->t('3. Islandora destination'), '#open' => TRUE];
    $form['destination']['bundle'] = ['#type' => 'select', '#title' => $this->t('Content type'), '#options' => $bundles, '#default_value' => $settings['bundle'], '#required' => TRUE];
    $form['destination']['model_tid'] = ['#type' => 'entity_autocomplete', '#title' => $this->t('Islandora model'), '#target_type' => 'taxonomy_term', '#selection_settings' => ['target_bundles' => ['islandora_models']], '#default_value' => !empty($settings['model_tid']) ? $this->entityTypeManager->getStorage('taxonomy_term')->load($settings['model_tid']) : NULL, '#required' => TRUE];
    $form['destination']['collection_nid'] = ['#type' => 'entity_autocomplete', '#title' => $this->t('Target collection'), '#target_type' => 'node', '#selection_settings' => ['target_bundles' => [$settings['bundle']]], '#default_value' => !empty($settings['collection_nid']) ? $this->entityTypeManager->getStorage('node')->load($settings['collection_nid']) : NULL];
    $form['destination']['default_status'] = ['#type' => 'checkbox', '#title' => $this->t('Publish new records'), '#default_value' => $settings['default_status'], '#description' => $this->t('Disabled by default; new records are unpublished.')];

    $form['mapping'] = ['#type' => 'details', '#title' => $this->t('4. Field mapping'), '#open' => TRUE];
    $fields = ['' => $this->t('- Do not map -'), 'title' => $this->t('Title (required)')];
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $settings['bundle']) as $name => $definition) {
      $unsupportedReference = $definition->getType() === 'entity_reference' && $definition->getFieldStorageDefinition()->getSetting('target_type') !== 'taxonomy_term';
      if (!$definition->getFieldStorageDefinition()->isBaseField() && !$unsupportedReference && !$definition->isComputed() && !$definition->isReadOnly()) {
        $fields[$name] = $definition->getLabel() . ' (' . $name . ')';
      }
    }
    $vocabularies = ['' => $this->t('- Not taxonomy -')];
    foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $vocabulary) {
      $vocabularies[$vocabulary->id()] = $vocabulary->label();
    }
    $existing = [];
    foreach ($source->getMappings() as $mapping) {
      $existing[$mapping['source']] = $mapping;
    }
    $form['mapping']['help'] = ['#markup' => '<p>' . $this->t('Taxonomy mappings create or reuse exact-name terms. “First” truncates multiple source values; field cardinality is also enforced.') . '</p>'];
    $form['mapping']['mappings'] = ['#type' => 'table', '#header' => [$this->t('OAI element'), $this->t('Sample value'), $this->t('Target Drupal field'), $this->t('Behavior'), $this->t('Cardinality'), $this->t('Transformation'), $this->t('Taxonomy'), $this->t('Skip empty')], '#tree' => TRUE];
    foreach (self::DC_ELEMENTS as $element) {
      $key = 'dc:' . $element;
      $mapping = $existing[$key] ?? [];
      $form['mapping']['mappings'][$element]['source'] = ['#plain_text' => $key];
      $form['mapping']['mappings'][$element]['sample'] = ['#plain_text' => $this->t('Shown in preview')];
      $form['mapping']['mappings'][$element]['target'] = ['#type' => 'select', '#options' => $fields, '#default_value' => $mapping['target'] ?? ($element === 'title' ? 'title' : '')];
      $form['mapping']['mappings'][$element]['behavior'] = ['#type' => 'select', '#options' => ['replace' => $this->t('Replace'), 'append' => $this->t('Append')], '#default_value' => $mapping['behavior'] ?? 'replace'];
      $form['mapping']['mappings'][$element]['cardinality'] = ['#type' => 'select', '#options' => ['all' => $this->t('All values'), 'first' => $this->t('First value')], '#default_value' => $mapping['cardinality'] ?? 'all'];
      $form['mapping']['mappings'][$element]['transform'] = ['#type' => 'select', '#options' => ['none' => $this->t('None'), 'trim' => $this->t('Trim whitespace'), 'lowercase' => $this->t('Lowercase'), 'language_code' => $this->t('Normalize language code')], '#default_value' => $mapping['transform'] ?? 'trim'];
      $form['mapping']['mappings'][$element]['taxonomy_vocabulary'] = ['#type' => 'select', '#options' => $vocabularies, '#default_value' => $mapping['taxonomy_vocabulary'] ?? ''];
      $form['mapping']['mappings'][$element]['skip_empty'] = ['#type' => 'checkbox', '#default_value' => $mapping['skip_empty'] ?? TRUE];
    }

    $file = $settings['file_settings'];
    $form['files'] = ['#type' => 'details', '#title' => $this->t('5. Files'), '#open' => FALSE];
    $form['files']['notice'] = ['#markup' => '<p>' . $this->t('Standard OAI-PMH exposes metadata, not files. Enable this only when a mapped element contains direct downloadable URLs.') . '</p>'];
    $form['files']['enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Import explicitly mapped files'), '#default_value' => $file['enabled'] ?? FALSE];
    $form['files']['source_element'] = ['#type' => 'select', '#title' => $this->t('File URL element'), '#options' => array_combine(array_map(fn($v) => 'dc:' . $v, self::DC_ELEMENTS), array_map(fn($v) => 'dc:' . $v, self::DC_ELEMENTS)), '#default_value' => $file['source_element'] ?? 'dc:identifier'];
    $form['files']['allowed_domains'] = ['#type' => 'textarea', '#title' => $this->t('Allowed download domains'), '#default_value' => implode("\n", $file['allowed_domains'] ?? []), '#description' => $this->t('One exact hostname per line, or .example.org to allow subdomains.')];
    $form['files']['allowed_mime_types'] = ['#type' => 'textfield', '#title' => $this->t('Allowed MIME types'), '#default_value' => implode(', ', $file['allowed_mime_types'] ?? []), '#description' => $this->t('Comma-separated exact MIME types.')];
    $form['files']['allowed_extensions'] = ['#type' => 'textfield', '#title' => $this->t('Allowed extensions'), '#default_value' => implode(', ', $file['allowed_extensions'] ?? []), '#description' => $this->t('Comma-separated, without dots.')];
    $form['files']['max_bytes'] = ['#type' => 'number', '#title' => $this->t('Maximum file size (bytes)'), '#default_value' => $file['max_bytes'] ?? 104857600, '#min' => 1];
    $mediaTypes = [];
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      $mediaTypes[$type->id()] = $type->label();
    }
    $form['files']['media_bundle'] = ['#type' => 'select', '#title' => $this->t('Media type'), '#options' => $mediaTypes, '#default_value' => $file['media_bundle'] ?? 'file'];
    $form['files']['file_field'] = ['#type' => 'textfield', '#title' => $this->t('Media file field'), '#default_value' => $file['file_field'] ?? 'field_media_file'];
    $form['files']['media_of_field'] = ['#type' => 'textfield', '#title' => $this->t('Media-to-object field'), '#default_value' => $file['media_of_field'] ?? 'field_media_of'];

    $form['schedule'] = ['#type' => 'details', '#title' => $this->t('6. Schedule and update rules'), '#open' => FALSE];
    $form['schedule']['frequency'] = ['#type' => 'select', '#title' => $this->t('Harvest frequency'), '#options' => ['manual' => $this->t('Manual'), 'hourly' => $this->t('Hourly'), 'daily' => $this->t('Daily'), 'weekly' => $this->t('Weekly')], '#default_value' => $settings['frequency']];
    $form['schedule']['batch_size'] = ['#type' => 'number', '#title' => $this->t('Preferred processing batch size'), '#default_value' => $settings['batch_size'], '#min' => 1, '#max' => 1000, '#description' => $this->t('OAI servers control response page sizes; this value is retained for worker orchestration.')];
    $form['schedule']['update_policy'] = ['#type' => 'select', '#title' => $this->t('Existing records'), '#options' => ['replace_mapped' => $this->t('Update mapped fields'), 'skip_existing' => $this->t('Skip existing records')], '#default_value' => $settings['update_policy']];
    $form['schedule']['deletion_policy'] = ['#type' => 'select', '#title' => $this->t('Deleted source records'), '#options' => ['unpublish' => $this->t('Unpublish locally'), 'ignore' => $this->t('Keep unchanged')], '#default_value' => $settings['deletion_policy']];

    $form['preview'] = ['#type' => 'details', '#title' => $this->t('7. Preview and confirmation'), '#open' => FALSE, 'text' => ['#markup' => '<p>' . $this->t('Save first, then use Test and Preview from the source actions. Preview never writes repository objects.') . '</p>']];
    return $form;
  }

  /**
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $endpoint = (string) $form_state->getValue('endpoint');
    $parts = parse_url($endpoint);
    if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], TRUE) || !empty($parts['user'])) {
      $form_state->setErrorByName('endpoint', $this->t('Enter an absolute HTTP(S) endpoint without embedded credentials.'));
    }
    $mappings = $form_state->getValue('mappings') ?? [];
    $hasTitle = FALSE;
    foreach ($mappings as $element => $mapping) {
      if (($mapping['target'] ?? '') === 'title') {
        $hasTitle = TRUE;
      }
    }
    if (!$hasTitle) {
      $form_state->setErrorByName('mappings', $this->t('One OAI element must map to the required node title.'));
    }
    $bundle = (string) $form_state->getValue('bundle');
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($definitions['field_model']) || $definitions['field_model']->getType() !== 'entity_reference' || $definitions['field_model']->getFieldStorageDefinition()->getSetting('target_type') !== 'taxonomy_term') {
      $form_state->setErrorByName('bundle', $this->t('The selected content type does not have the verified Islandora model field field_model.'));
    }
    if (!isset($definitions['field_member_of']) || $definitions['field_member_of']->getType() !== 'entity_reference' || $definitions['field_member_of']->getFieldStorageDefinition()->getSetting('target_type') !== 'node') {
      $form_state->setErrorByName('bundle', $this->t('The selected content type does not have the verified collection relationship field field_member_of.'));
    }
    foreach ($mappings as $element => $mapping) {
      $target = (string) ($mapping['target'] ?? '');
      if ($target !== '' && $target !== 'title' && !isset($definitions[$target])) {
        $form_state->setErrorByName('mappings][' . $element . '][target', $this->t('The selected destination field does not exist on the content type.'));
      }
      if ($target !== '' && $target !== 'title' && isset($definitions[$target]) && $definitions[$target]->getType() === 'entity_reference' && empty($mapping['taxonomy_vocabulary'])) {
        $form_state->setErrorByName('mappings][' . $element . '][taxonomy_vocabulary', $this->t('Choose a taxonomy vocabulary for entity reference mappings.'));
      }
    }
    if ($form_state->getValue('enabled') && trim((string) $form_state->getValue('allowed_domains')) === '') {
      $form_state->setErrorByName('allowed_domains', $this->t('At least one domain is required when file import is enabled.'));
    }
    if ($form_state->getValue('enabled')) {
      $mediaBundle = (string) $form_state->getValue('media_bundle');
      $mediaDefinitions = $this->entityFieldManager->getFieldDefinitions('media', $mediaBundle);
      $fileField = (string) $form_state->getValue('file_field');
      $mediaOfField = (string) $form_state->getValue('media_of_field');
      if (!isset($mediaDefinitions[$fileField]) || !in_array($mediaDefinitions[$fileField]->getType(), ['file', 'image'], TRUE)) {
        $form_state->setErrorByName('file_field', $this->t('The configured media file field does not exist or is not a file/image field.'));
      }
      if (!isset($mediaDefinitions[$mediaOfField]) || $mediaDefinitions[$mediaOfField]->getType() !== 'entity_reference' || $mediaDefinitions[$mediaOfField]->getFieldStorageDefinition()->getSetting('target_type') !== 'node') {
        $form_state->setErrorByName('media_of_field', $this->t('The configured media relationship field must reference nodes.'));
      }
      if (trim((string) $form_state->getValue('allowed_mime_types')) === '' || trim((string) $form_state->getValue('allowed_extensions')) === '') {
        $form_state->setErrorByName('allowed_mime_types', $this->t('Allowed MIME types and extensions are required when file import is enabled.'));
      }
    }
  }

  /**
   *
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $values = $form_state->getValues();
    $mappings = [];
    foreach ($values['mappings'] ?? [] as $element => $mapping) {
      if (!empty($mapping['target'])) {
        $mapping['source'] = 'dc:' . $element;
        $mapping['skip_empty'] = (bool) $mapping['skip_empty'];
        $mappings[] = $mapping;
      }
    }
    $lineList = static fn(string $value): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
    $commaList = static fn(string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));
    $this->entity
      ->set('label', $values['label'])->set('id', $values['id'])->set('status', (bool) $values['status'])
      ->set('endpoint', $values['endpoint'])->set('auth_key_id', $values['auth_key_id'] ?: NULL)
      ->set('timeout', (int) $values['timeout'])->set('max_retries', (int) $values['max_retries'])->set('rate_limit_ms', (int) $values['rate_limit_ms'])
      ->set('metadata_prefix', $values['metadata_prefix'])->set('granularity', $values['granularity'])->set('set_specs', array_values(array_unique(array_merge(array_values(array_filter($values['set_choices'] ?? [])), $lineList((string) $values['set_specs'])))))
      ->set('bundle', $values['bundle'])->set('model_tid', $values['model_tid'] ? (int) $values['model_tid'] : NULL)->set('collection_nid', $values['collection_nid'] ? (int) $values['collection_nid'] : NULL)->set('default_status', (bool) $values['default_status'])
      ->set('mappings', $mappings)->set('frequency', $values['frequency'])->set('batch_size', (int) $values['batch_size'])->set('update_policy', $values['update_policy'])->set('deletion_policy', $values['deletion_policy'])
      ->set('file_settings', ['enabled' => (bool) $values['enabled'], 'source_element' => $values['source_element'], 'allowed_domains' => $lineList((string) $values['allowed_domains']), 'allowed_mime_types' => $commaList((string) $values['allowed_mime_types']), 'allowed_extensions' => $commaList((string) $values['allowed_extensions']), 'max_bytes' => (int) $values['max_bytes'], 'media_bundle' => $values['media_bundle'], 'file_field' => $values['file_field'], 'media_of_field' => $values['media_of_field']]);
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved OAI-PMH source %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
