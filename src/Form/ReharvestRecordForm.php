<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\oai_harvester\Plugin\MetadataParserManager;
use Drupal\oai_harvester\Service\OaiPmhClientInterface;
use Drupal\oai_harvester\Service\RunManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Retrieves and queues one tracked source record.
 */
final class ReharvestRecordForm extends ConfirmFormBase {
  private NodeInterface $node;
  private array $tracking;

  public function __construct(private readonly Connection $database, private readonly EntityTypeManagerInterface $entityTypeManager, private readonly OaiPmhClientInterface $client, private readonly MetadataParserManager $parserManager, private readonly RunManagerInterface $runManager) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'), $container->get('entity_type.manager'), $container->get('oai_harvester.client'), $container->get('plugin.manager.islandora_oai_metadata_parser'), $container->get('oai_harvester.run_manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'islandora_oai_reharvest_record';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->t('Retrieve and re-harvest %identifier?', ['%identifier' => $this->tracking['oai_identifier']]);
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return $this->node->toUrl();
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node) {
      throw new NotFoundHttpException();
    }
    $this->node = $node;
    $tracking = $this->database->select('islandora_oai_record', 'r')->fields('r')->condition('node_id', $node->id())->execute()->fetchAssoc();
    if (!$tracking) {
      throw new NotFoundHttpException();
    }
    $this->tracking = $tracking;
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $this->entityTypeManager->getStorage('islandora_oai_source')->load($this->tracking['source_id']);
    if (!$source) {
      $this->messenger()->addError($this->t('The source no longer exists.'));
      return;
    }
    try {
      $xml = $this->client->getRecord($source, $this->tracking['oai_identifier']);
      $record = $this->parserManager->forPrefix($source->getMetadataPrefix())->parse($xml);
      $runId = $this->runManager->queueRecord($source, $record['identifier'], $record['datestamp'], $record['sets'], $record['deleted'], $xml);
      $this->messenger()->addStatus($this->t('Record queued in harvest run @id.', ['@id' => $runId]));
      $form_state->setRedirect('oai_harvester.run', ['run_id' => $runId]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Could not retrieve record: @message', ['@message' => $exception->getMessage()]));
      $form_state->setRedirectUrl($this->node->toUrl());
    }
  }

}
