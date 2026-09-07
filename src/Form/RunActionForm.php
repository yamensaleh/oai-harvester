<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Form;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\oai_harvester\Service\RunManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms pause, cancellation, or retry operations.
 */
final class RunActionForm extends ConfirmFormBase {
  private int $runId;
  private string $operation;

  public function __construct(private readonly RunManager $runManager) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('oai_harvester.run_manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'islandora_oai_run_action';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->t('@operation harvest run @id?', ['@operation' => ucfirst($this->operation), '@id' => $this->runId]);
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('oai_harvester.run', ['run_id' => $this->runId]);
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $run_id = NULL, ?string $operation = NULL): array {
    $this->runId = $run_id ?? 0;
    $this->operation = $operation ?? '';
    if (!$this->runManager->load($this->runId) || !in_array($this->operation, ['pause', 'resume', 'cancel', 'retry'], TRUE)) {
      throw new NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->operation === 'retry') {
      $count = $this->runManager->retry($this->runId);
      $this->messenger()->addStatus($this->t('Queued @count failed records for retry.', ['@count' => $count]));
    }
    else {
      $run = $this->runManager->load($this->runId);
      $state = match ($this->operation) {
        'pause' => 'paused',
        'resume' => (int) $run['discovery_pending'] > 0 ? 'discovering' : 'importing',
        default => 'cancelled',
      };
      $this->runManager->setState($this->runId, $state);
      $this->messenger()->addStatus($this->t('Harvest run @id is now @state.', ['@id' => $this->runId, '@state' => $state]));
    }
    $form_state->setRedirect('oai_harvester.run', ['run_id' => $this->runId]);
  }

}
