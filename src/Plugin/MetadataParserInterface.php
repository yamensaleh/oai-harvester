<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Plugin;

/**
 * Parses one OAI record into normalized values.
 */
interface MetadataParserInterface {

  /**
   * @return array{identifier:string,datestamp:?string,sets:array,deleted:bool,values:array}
   */
  public function parse(string $recordXml): array;

}
