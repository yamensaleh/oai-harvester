<?php

declare(strict_types=1);

namespace Drupal\Tests\oai_harvester\Unit;

use Drupal\oai_harvester\Plugin\MetadataParser\OaiDcParser;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\oai_harvester\Plugin\MetadataParser\OaiDcParser
 * @group oai_harvester
 */
final class OaiDcParserTest extends UnitTestCase {

  /**
   * @covers ::parse */
  public function testNamespacesAndMultipleValues(): void {
    $document = new \DOMDocument();
    $document->load(__DIR__ . '/../../fixtures/list-records.xml');
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    $recordXml = $document->saveXML($xpath->query('//oai:record')->item(0));
    $parser = new OaiDcParser([], 'oai_dc', []);
    $record = $parser->parse($recordXml);
    self::assertSame('oai:example:1', $record['identifier']);
    self::assertSame(['First title', 'Second title'], $record['values']['dc:title']);
    self::assertSame(['books'], $record['sets']);
    self::assertFalse($record['deleted']);
  }

  /**
   * @covers ::parse */
  public function testDeletedRecord(): void {
    $document = new \DOMDocument();
    $document->load(__DIR__ . '/../../fixtures/list-records.xml');
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    $recordXml = $document->saveXML($xpath->query('//oai:record')->item(1));
    $record = (new OaiDcParser([], 'oai_dc', []))->parse($recordXml);
    self::assertTrue($record['deleted']);
    self::assertSame([], $record['values']);
  }

  /**
   * @covers ::parse */
  public function testRejectsDoctype(): void {
    $this->expectException(\UnexpectedValueException::class);
    (new OaiDcParser([], 'oai_dc', []))->parse('<!DOCTYPE record [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><record>&xxe;</record>');
  }

}
