<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_oai_harvester\Unit;

use Drupal\islandora_oai_harvester\Service\UrlGuard;
use Drupal\Tests\UnitTestCase;

/**
 * @group islandora_oai_harvester */
final class UrlGuardTest extends UnitTestCase {

  /**
   * @dataProvider unsafeUrls */
  public function testRejectsPrivateAndInvalidUrls(string $url): void {
    $this->expectException(\InvalidArgumentException::class);
    (new UrlGuard())->assertSafe($url);
  }

  /**
   *
   */
  public static function unsafeUrls(): array {
    return [['file:///etc/passwd'], ['http://127.0.0.1/file'], ['http://[::1]/file'], ['https://user:secret@example.org/file']];
  }

}
