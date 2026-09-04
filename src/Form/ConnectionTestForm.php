<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;
use Drupal\islandora_oai_harvester\Service\OaiPmhClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CSRF-protected connection test.
 */
final class ConnectionTestForm extends ConfirmFormBase {

  private OaiSourceInterface $source;

  public function __construct(private readonly OaiPmhClientInterface $client) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('islandora_oai_harvester.client'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'islandora_oai_connection_test';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->t('Test the connection to %source?', ['%source' => $this->source->label()]);
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return $this->source->toUrl('collection');
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OaiSourceInterface $islandora_oai_source = NULL): array {
    if (!$islandora_oai_source) {
      throw new \InvalidArgumentException('Source is required.');
    }
    $this->source = $islandora_oai_source;
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $identity = $this->client->identify($this->source);
      $formats = $this->client->listMetadataFormats($this->source);
      $setPage = $this->client->listSets($this->source);
      $sets = $setPage['sets'];
      $pages = 1;
      while (!empty($setPage['resumption_token']) && $pages++ < 10) {
        $setPage = $this->client->listSets($this->source, $setPage['resumption_token']);
        $sets = array_merge($sets, $setPage['sets']);
      }
      $this->source->set('repository_info', $identity)->set('available_formats', $formats)->set('available_sets', $sets)->save();
      if (!empty($setPage['resumption_token'])) {
        $this->messenger()->addWarning($this->t('Set discovery was limited to ten pages; additional setSpec values can be entered manually.'));
      }
      $this->messenger()->addStatus($this->t('Connected to %repository using OAI-PMH %version. Earliest record: %date. Deleted-record policy: %deleted.', ['%repository' => $identity['repositoryName'], '%version' => $identity['protocolVersion'], '%date' => $identity['earliestDatestamp'], '%deleted' => $identity['deletedRecord']]));
      $this->messenger()->addStatus($this->t('Formats: %formats. Sets discovered: %sets.', ['%formats' => implode(', ', array_column($formats, 'prefix')), '%sets' => implode(', ', array_column($sets, 'spec')) ?: $this->t('none')]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Connection failed: @message', ['@message' => $exception->getMessage()]));
    }
    $form_state->setRedirectUrl($this->source->toUrl('collection'));
  }

}
