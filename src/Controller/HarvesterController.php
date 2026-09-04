<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\islandora_oai_harvester\Entity\OaiSourceInterface;
use Drupal\islandora_oai_harvester\Plugin\MetadataParserManager;
use Drupal\islandora_oai_harvester\Service\OaiPmhClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides previews, history, run details, raw metadata, and exports.
 */
final class HarvesterController extends ControllerBase {

  public function __construct(private readonly Connection $database, private readonly OaiPmhClientInterface $client, private readonly MetadataParserManager $parserManager) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'), $container->get('islandora_oai_harvester.client'), $container->get('plugin.manager.islandora_oai_metadata_parser'));
  }

  /**
   *
   */
  public function runActionAccess(AccountInterface $account, string $operation): AccessResult {
    $permission = $operation === 'retry' ? 'retry islandora oai records' : 'pause islandora oai harvests';
    return AccessResult::allowedIfHasPermission($account, $permission);
  }

  /**
   *
   */
  public function preview(OaiSourceInterface $islandora_oai_source): array {
    try {
      $page = $this->client->listRecords($islandora_oai_source, $islandora_oai_source->getSetSpecs() ? ['set' => reset($islandora_oai_source->getSetSpecs())] : []);
      $parser = $this->parserManager->forPrefix($islandora_oai_source->getMetadataPrefix());
      $rows = [];
      foreach (array_slice($page['records'], 0, 5) as $xml) {
        $record = $parser->parse($xml);
        $mapped = [];
        $warnings = [];
        foreach ($islandora_oai_source->getMappings() as $mapping) {
          $values = $record['values'][$mapping['source']] ?? [];
          $mapped[] = $mapping['source'] . ' → ' . $mapping['target'] . ': ' . implode(' | ', $values);
          if (($mapping['target'] ?? '') === 'title' && !$values) {
            $warnings[] = $this->t('Missing required title');
          }
        }
        $fileElement = $islandora_oai_source->getFileSettings()['source_element'] ?? '';
        $hasFile = FALSE;
        foreach ($record['values'][$fileElement] ?? [] as $value) {
          if (filter_var($value, FILTER_VALIDATE_URL)) {
            $hasFile = TRUE;
            break;
          }
        }
        $rows[] = [$record['identifier'], $record['datestamp'] ?? '', ['data' => ['#theme' => 'item_list', '#items' => $mapped]], $warnings ? implode('; ', $warnings) : $this->t('None'), $hasFile ? $this->t('Yes') : $this->t('No')];
      }
      return [
        'notice' => ['#markup' => '<p>' . $this->t('Preview is read-only and never creates Islandora objects. Use Harvest now to explicitly queue imports.') . '</p>'],
        'table' => ['#type' => 'table', '#header' => [$this->t('OAI identifier'), $this->t('Datestamp'), $this->t('Parsed and mapped values'), $this->t('Validation warnings'), $this->t('Downloadable URL detected')], '#rows' => $rows, '#empty' => $this->t('No records returned.')],
        'harvest' => Link::createFromRoute($this->t('Harvest now'), 'islandora_oai_harvester.source_harvest', ['islandora_oai_source' => $islandora_oai_source->id()])->toRenderable(),
      ];
    }
    catch (\Throwable $exception) {
      return ['error' => ['#theme' => 'status_messages', '#message_list' => ['error' => [$this->t('Preview failed: @message', ['@message' => $exception->getMessage()])]]]];
    }
  }

  /**
   *
   */
  public function sourceHistory(OaiSourceInterface $islandora_oai_source): array {
    $query = $this->database->select('islandora_oai_harvest_run', 'r')->fields('r')->condition('source_id', $islandora_oai_source->id())->orderBy('started', 'DESC')->range(0, 100);
    $rows = [];
    foreach ($query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC) as $run) {
      $rows[] = [Link::createFromRoute('#' . $run['id'], 'islandora_oai_harvester.run', ['run_id' => $run['id']])->toRenderable(), $run['mode'], $run['state'], date('Y-m-d H:i:s T', (int) $run['started']), $run['completed'] ? date('Y-m-d H:i:s T', (int) $run['completed']) : '', $run['processed'] . ' / ' . $run['queued'], $run['failed_count']];
    }
    return ['table' => ['#type' => 'table', '#header' => [$this->t('Run'), $this->t('Mode'), $this->t('State'), $this->t('Started'), $this->t('Completed'), $this->t('Processed / queued'), $this->t('Failed')], '#rows' => $rows, '#empty' => $this->t('No harvest runs yet.')]];
  }

  /**
   *
   */
  public function run(int $run_id): array {
    $run = $this->database->select('islandora_oai_harvest_run', 'r')->fields('r')->condition('id', $run_id)->execute()->fetchAssoc();
    if (!$run) {
      throw new NotFoundHttpException();
    }
    $fields = ['state', 'discovered', 'queued', 'processed', 'created_count', 'updated_count', 'skipped_count', 'warning_count', 'failed_count', 'from_date', 'until_date', 'resumption_token', 'message'];
    $rows = [];
    foreach ($fields as $field) {
      $rows[] = [ucfirst(str_replace('_', ' ', $field)), (string) ($run[$field] ?? '')];
    }
    $records = [];
    $query = $this->database->select('islandora_oai_raw_record', 'raw')->fields('raw', ['id', 'oai_identifier', 'datestamp', 'status', 'result', 'attempts', 'message'])->condition('run_id', $run_id)->orderBy('id')->range(0, 500);
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $record) {
      $records[] = [Link::createFromRoute($record['oai_identifier'], 'islandora_oai_harvester.raw', ['raw_id' => $record['id']])->toRenderable(), $record['datestamp'], $record['status'], $record['result'], $record['attempts'], $record['message']];
    }
    $actions = [];
    if ($this->currentUser()->hasPermission('pause islandora oai harvests') && !in_array($run['state'], ['completed', 'completed_with_warnings', 'failed', 'cancelled'], TRUE)) {
      $actions[] = Link::createFromRoute($run['state'] === 'paused' ? $this->t('Resume') : $this->t('Pause'), 'islandora_oai_harvester.run_action', ['run_id' => $run_id, 'operation' => $run['state'] === 'paused' ? 'resume' : 'pause'])->toRenderable();
      $actions[] = Link::createFromRoute($this->t('Cancel'), 'islandora_oai_harvester.run_action', ['run_id' => $run_id, 'operation' => 'cancel'])->toRenderable();
    }
    if ($this->currentUser()->hasPermission('retry islandora oai records')) {
      $actions[] = Link::createFromRoute($this->t('Retry failed records'), 'islandora_oai_harvester.run_action', ['run_id' => $run_id, 'operation' => 'retry'])->toRenderable();
    }
    $actions[] = Link::createFromRoute($this->t('Export warnings and failures (CSV)'), 'islandora_oai_harvester.run_csv', ['run_id' => $run_id])->toRenderable();
    return [
      'summary' => ['#type' => 'table', '#header' => [$this->t('Metric'), $this->t('Value')], '#rows' => $rows],
      'actions' => ['#theme' => 'item_list', '#items' => $actions],
      'records' => ['#type' => 'table', '#header' => [$this->t('Identifier'), $this->t('Datestamp'), $this->t('Status'), $this->t('Result'), $this->t('Attempts'), $this->t('Message')], '#rows' => $records, '#empty' => $this->t('No records discovered yet.')],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   *
   */
  public function raw(int $raw_id): Response {
    $xml = $this->database->select('islandora_oai_raw_record', 'raw')->fields('raw', ['xml'])->condition('id', $raw_id)->execute()->fetchField();
    if ($xml === FALSE) {
      throw new NotFoundHttpException();
    }
    return new Response((string) $xml, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
  }

  /**
   *
   */
  public function csv(int $run_id): StreamedResponse {
    if (!$this->database->select('islandora_oai_harvest_run', 'r')->condition('id', $run_id)->countQuery()->execute()->fetchField()) {
      throw new NotFoundHttpException();
    }
    $database = $this->database;
    $response = new StreamedResponse(static function () use ($database, $run_id): void {
      $output = fopen('php://output', 'wb');
      fputcsv($output, ['OAI identifier', 'datestamp', 'status', 'result', 'attempts', 'message']);
      $query = $database->select('islandora_oai_raw_record', 'raw')->fields('raw', ['oai_identifier', 'datestamp', 'status', 'result', 'attempts', 'message'])->condition('run_id', $run_id);
      $or = $query->orConditionGroup()->condition('status', 'failed')->isNotNull('message');
      $query->condition($or);
      foreach ($query->execute() as $row) {
        $values = array_values((array) $row);
        foreach ($values as &$value) {
          if (is_string($value) && preg_match('/^[=+\-@]/', $value)) {
            $value = "'" . $value;
          }
        }
        fputcsv($output, $values);
      }
      fclose($output);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="oai-harvest-' . $run_id . '-errors.csv"');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
