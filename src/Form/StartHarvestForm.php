<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;
use Drupal\islandora_oai_harvester\Service\RunManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts an asynchronous harvest and returns immediately.
 */
final class StartHarvestForm extends ConfirmFormBase {
  private OaiSourceInterface $source;

  public function __construct(private readonly RunManagerInterface $runManager) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('islandora_oai_harvester.run_manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'islandora_oai_start_harvest';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->t('Start a harvest for %source?', ['%source' => $this->source->label()]);
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
    $this->source = $islandora_oai_source ?? throw new \InvalidArgumentException('Source is required.');
    $form['mode'] = ['#type' => 'radios', '#title' => $this->t('Mode'), '#options' => ['incremental' => $this->t('Incremental'), 'full' => $this->t('Full')], '#default_value' => 'incremental'];
    $form['from'] = ['#type' => 'textfield', '#title' => $this->t('Optional from datestamp'), '#description' => $this->t('ISO 8601 OAI datestamp; overrides the last successful harvest for incremental mode.')];
    $form['until'] = ['#type' => 'textfield', '#title' => $this->t('Optional until datestamp')];
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['from', 'until'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '' && strtotime($value) === FALSE) {
        $form_state->setErrorByName($key, $this->t('Enter a valid OAI datestamp.'));
      }
    }
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $run = $this->runManager->start($this->source, (string) $form_state->getValue('mode'), trim((string) $form_state->getValue('from')) ?: NULL, trim((string) $form_state->getValue('until')) ?: NULL);
      $this->messenger()->addStatus($this->t('Harvest run @id was queued.', ['@id' => $run]));
      $form_state->setRedirect('islandora_oai_harvester.run', ['run_id' => $run]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRedirectUrl($this->source->toUrl('collection'));
    }
  }

}
