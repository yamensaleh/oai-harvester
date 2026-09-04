<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;

/**
 * Enables or disables a source using a CSRF-protected confirmation.
 */
final class ToggleSourceForm extends ConfirmFormBase {
  private OaiSourceInterface $source;

  /**
   *
   */
  public function getFormId(): string {
    return 'islandora_oai_toggle_source';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->source->status() ? $this->t('Disable %source?', ['%source' => $this->source->label()]) : $this->t('Enable %source?', ['%source' => $this->source->label()]);
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.islandora_oai_source.collection');
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OaiSourceInterface $islandora_oai_source = NULL): array {
    $this->source = $islandora_oai_source ?? throw new \InvalidArgumentException('Source is required.');
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->source->status() ? $this->source->disable() : $this->source->enable();
    $this->source->save();
    $this->messenger()->addStatus($this->t('The source is now @status.', ['@status' => $this->source->status() ? $this->t('enabled') : $this->t('disabled')]));
    $form_state->setRedirect('entity.islandora_oai_source.collection');
  }

}
