<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oai_harvester\Entity\OaiSourceInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Hardened OAI-PMH 2.0 client using Drupal's HTTP client.
 */
final class OaiPmhClient implements OaiPmhClientInterface {

  private int $lastRequestMs = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly UrlGuard $urlGuard,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   *
   */
  public function identify(OaiSourceInterface $source): array {
    $xpath = $this->request($source, 'Identify');
    return [
      'repositoryName' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:repositoryName)')),
      'baseURL' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:baseURL)')),
      'protocolVersion' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:protocolVersion)')),
      'earliestDatestamp' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:earliestDatestamp)')),
      'deletedRecord' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:deletedRecord)')),
      'granularity' => trim((string) $xpath->evaluate('string(//oai:Identify/oai:granularity)')),
    ];
  }

  /**
   *
   */
  public function listMetadataFormats(OaiSourceInterface $source): array {
    $xpath = $this->request($source, 'ListMetadataFormats');
    $formats = [];
    foreach ($xpath->query('//oai:ListMetadataFormats/oai:metadataFormat') as $node) {
      $formats[] = [
        'prefix' => trim((string) $xpath->evaluate('string(oai:metadataPrefix)', $node)),
        'schema' => trim((string) $xpath->evaluate('string(oai:schema)', $node)),
        'namespace' => trim((string) $xpath->evaluate('string(oai:metadataNamespace)', $node)),
      ];
    }
    return $formats;
  }

  /**
   *
   */
  public function listSets(OaiSourceInterface $source, ?string $token = NULL): array {
    $xpath = $this->request($source, 'ListSets', $token ? ['resumptionToken' => $token] : []);
    $sets = [];
    foreach ($xpath->query('//oai:ListSets/oai:set') as $node) {
      $sets[] = [
        'spec' => trim((string) $xpath->evaluate('string(oai:setSpec)', $node)),
        'name' => trim((string) $xpath->evaluate('string(oai:setName)', $node)),
      ];
    }
    return ['sets' => $sets, 'resumption_token' => $this->token($xpath, '//oai:ListSets/oai:resumptionToken')];
  }

  /**
   *
   */
  public function listIdentifiers(OaiSourceInterface $source, array $parameters = []): array {
    $xpath = $this->request($source, 'ListIdentifiers', $this->normalizeListParameters($source, $parameters));
    $headers = [];
    foreach ($xpath->query('//oai:ListIdentifiers/oai:header') as $node) {
      $headers[] = $this->header($xpath, $node);
    }
    return ['headers' => $headers, 'resumption_token' => $this->token($xpath, '//oai:ListIdentifiers/oai:resumptionToken')];
  }

  /**
   *
   */
  public function listRecords(OaiSourceInterface $source, array $parameters = []): array {
    $xpath = $this->request($source, 'ListRecords', $this->normalizeListParameters($source, $parameters));
    $records = [];
    foreach ($xpath->query('//oai:ListRecords/oai:record') as $node) {
      $records[] = $node->ownerDocument->saveXML($node);
    }
    return ['records' => $records, 'resumption_token' => $this->token($xpath, '//oai:ListRecords/oai:resumptionToken')];
  }

  /**
   *
   */
  public function getRecord(OaiSourceInterface $source, string $identifier): string {
    $xpath = $this->request($source, 'GetRecord', ['identifier' => $identifier, 'metadataPrefix' => $source->getMetadataPrefix()]);
    $record = $xpath->query('//oai:GetRecord/oai:record')->item(0);
    if (!$record) {
      throw new OaiException('The response did not contain the requested record.');
    }
    return $record->ownerDocument->saveXML($record);
  }

  /**
   *
   */
  private function request(OaiSourceInterface $source, string $verb, array $parameters = []): \DOMXPath {
    $settings = $source->toRuntimeConfig();
    $this->urlGuard->assertSafe($source->getEndpoint());
    $options = [
      'query' => ['verb' => $verb] + $parameters,
      'timeout' => max(1, (int) $settings['timeout']),
      'connect_timeout' => min(10, max(1, (int) $settings['timeout'])),
      'allow_redirects' => FALSE,
      'headers' => ['Accept' => 'application/xml, text/xml;q=0.9'],
      'http_errors' => TRUE,
    ];
    if (!empty($settings['auth_key_id'])) {
      $key = $this->entityTypeManager->getStorage('key')->load($settings['auth_key_id']);
      if (!$key) {
        throw new OaiException('The configured authentication key does not exist.');
      }
      $secret = (string) $key->getKeyValue();
      [$username, $password] = array_pad(explode(':', $secret, 2), 2, '');
      $options['auth'] = [$username, $password];
    }
    $attempts = max(1, (int) $settings['max_retries']);
    $delay = max(0, (int) $settings['rate_limit_ms']);
    $exception = NULL;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        $elapsed = (int) floor(microtime(TRUE) * 1000) - $this->lastRequestMs;
        if ($delay > $elapsed) {
          usleep(($delay - $elapsed) * 1000);
        }
        $response = $this->httpClient->request('GET', $source->getEndpoint(), $options);
        $this->lastRequestMs = (int) floor(microtime(TRUE) * 1000);
        return $this->parse((string) $response->getBody());
      }
      catch (OaiException $exception) {
        throw $exception;
      }
      catch (\Throwable $exception) {
        if ($attempt < $attempts) {
          usleep(min(2000000, 100000 * (2 ** ($attempt - 1))));
        }
      }
    }
    $this->logger->warning('OAI-PMH {verb} request failed after {attempts} attempts: {message}', [
      'verb' => $verb,
      'attempts' => $attempts,
      'message' => $exception?->getMessage() ?? 'Unknown error',
    ]);
    throw new OaiException(sprintf('%s request failed after %d attempts.', $verb, $attempts), NULL, $exception);
  }

  /**
   *
   */
  private function parse(string $xml): \DOMXPath {
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
      throw new OaiException('Unsafe XML declarations are not permitted.');
    }
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \DOMDocument();
      if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
        throw new OaiException('The endpoint returned invalid XML.');
      }
      $xpath = new \DOMXPath($document);
      $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
      if ($error = $xpath->query('/oai:OAI-PMH/oai:error')->item(0)) {
        throw new OaiException(trim($error->textContent), $error instanceof \DOMElement ? $error->getAttribute('code') : NULL);
      }
      return $xpath;
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  /**
   *
   */
  private function normalizeListParameters(OaiSourceInterface $source, array $parameters): array {
    if (!empty($parameters['resumptionToken'])) {
      return ['resumptionToken' => (string) $parameters['resumptionToken']];
    }
    return array_filter([
      'metadataPrefix' => $source->getMetadataPrefix(),
      'set' => $parameters['set'] ?? NULL,
      'from' => $parameters['from'] ?? NULL,
      'until' => $parameters['until'] ?? NULL,
    ], static fn($value): bool => $value !== NULL && $value !== '');
  }

  /**
   *
   */
  private function token(\DOMXPath $xpath, string $expression): ?string {
    $token = trim((string) $xpath->evaluate('string(' . $expression . ')'));
    return $token !== '' ? $token : NULL;
  }

  /**
   *
   */
  private function header(\DOMXPath $xpath, \DOMNode $node): array {
    $sets = [];
    foreach ($xpath->query('oai:setSpec', $node) as $set) {
      $sets[] = trim($set->textContent);
    }
    return [
      'identifier' => trim((string) $xpath->evaluate('string(oai:identifier)', $node)),
      'datestamp' => trim((string) $xpath->evaluate('string(oai:datestamp)', $node)),
      'sets' => $sets,
      'deleted' => $node instanceof \DOMElement && strtolower($node->getAttribute('status')) === 'deleted',
    ];
  }

}
