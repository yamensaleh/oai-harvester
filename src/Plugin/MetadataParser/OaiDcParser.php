<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Plugin\MetadataParser;

use Drupal\Component\Plugin\PluginBase;
use Drupal\oai_harvester\Plugin\MetadataParserInterface;

/**
 * Parses OAI Dublin Core records with namespace-aware XPath.
 *
 * @MetadataParser(
 *   id = "oai_dc",
 *   label = @Translation("OAI Dublin Core"),
 *   metadata_prefix = "oai_dc"
 * )
 */
final class OaiDcParser extends PluginBase implements MetadataParserInterface {

  /**
   *
   */
  public function parse(string $recordXml): array {
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $recordXml)) {
      throw new \UnexpectedValueException('Unsafe XML declarations are not permitted.');
    }
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \DOMDocument();
      if (!$document->loadXML($recordXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
        $error = libxml_get_last_error();
        throw new \UnexpectedValueException('Invalid OAI XML: ' . ($error ? trim($error->message) : 'unknown parse error'));
      }
      $xpath = new \DOMXPath($document);
      $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
      $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
      $header = $xpath->query('/*[local-name()="record"]/*[local-name()="header"]')->item(0);
      if (!$header) {
        $header = $xpath->query('//*[local-name()="record"]/*[local-name()="header"]')->item(0);
      }
      if (!$header instanceof \DOMElement) {
        throw new \UnexpectedValueException('OAI record has no header.');
      }
      $identifier = trim((string) $xpath->evaluate('string(./*[local-name()="identifier"])', $header));
      if ($identifier === '') {
        throw new \UnexpectedValueException('OAI record has no identifier.');
      }
      $sets = [];
      foreach ($xpath->query('./*[local-name()="setSpec"]', $header) as $set) {
        $sets[] = trim($set->textContent);
      }
      $values = [];
      foreach ($xpath->query('//*[local-name()="metadata"]/*[local-name()="dc"]/*[namespace-uri()="http://purl.org/dc/elements/1.1/"]') as $element) {
        $values['dc:' . $element->localName][] = trim($element->textContent);
      }
      return [
        'identifier' => $identifier,
        'datestamp' => ($date = trim((string) $xpath->evaluate('string(./*[local-name()="datestamp"])', $header))) !== '' ? $date : NULL,
        'sets' => array_values(array_filter($sets, 'strlen')),
        'deleted' => strtolower($header->getAttribute('status')) === 'deleted',
        'values' => $values,
      ];
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

}
