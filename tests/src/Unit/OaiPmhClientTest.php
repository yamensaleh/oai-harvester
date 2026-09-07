<?php

declare(strict_types=1);

namespace Drupal\Tests\oai_harvester\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oai_harvester\Entity\OaiSourceInterface;
use Drupal\oai_harvester\Service\OaiException;
use Drupal\oai_harvester\Service\OaiPmhClient;
use Drupal\oai_harvester\Service\UrlGuard;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\oai_harvester\Service\OaiPmhClient
 * @group oai_harvester
 */
final class OaiPmhClientTest extends UnitTestCase {

  /**
   * @covers ::listRecords */
  public function testResumptionTokenPaginationResponse(): void {
    $client = $this->client(file_get_contents(__DIR__ . '/../../fixtures/list-records.xml'));
    $page = $client->listRecords($this->source());
    self::assertCount(2, $page['records']);
    self::assertSame('next-page-token', $page['resumption_token']);
  }

  /**
   * @covers ::listRecords */
  public function testOaiErrorResponse(): void {
    $client = $this->client(file_get_contents(__DIR__ . '/../../fixtures/oai-error.xml'));
    $this->expectException(OaiException::class);
    $this->expectExceptionMessage('invalid argument');
    $client->listRecords($this->source());
  }

  /**
   *
   */
  private function client(string $body): OaiPmhClient {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
    return new OaiPmhClient($http, new UrlGuard(), $this->createMock(EntityTypeManagerInterface::class), new NullLogger());
  }

  /**
   *
   */
  private function source(): OaiSourceInterface {
    $source = $this->createMock(OaiSourceInterface::class);
    $source->method('getEndpoint')->willReturn('https://93.184.216.34/oai');
    $source->method('getMetadataPrefix')->willReturn('oai_dc');
    $source->method('toRuntimeConfig')->willReturn(['timeout' => 5, 'max_retries' => 1, 'rate_limit_ms' => 0, 'auth_key_id' => NULL]);
    return $source;
  }

}
