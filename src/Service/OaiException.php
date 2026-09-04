<?php

declare(strict_types=1);

namespace Drupal\islandora_oai_harvester\Service;

/**
 * Represents a protocol or transport failure without retaining credentials.
 */
final class OaiException extends \RuntimeException {

  public function __construct(string $message, private readonly ?string $oaiCode = NULL, ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
  }

  /**
   *
   */
  public function getOaiCode(): ?string {
    return $this->oaiCode;
  }

}
