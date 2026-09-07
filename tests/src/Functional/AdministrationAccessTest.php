<?php

declare(strict_types=1);

namespace Drupal\Tests\oai_harvester\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies that harvester administration is not publicly accessible.
 *
 * @group oai_harvester
 */
final class AdministrationAccessTest extends BrowserTestBase {
  protected static $modules = ['oai_harvester'];
  protected $defaultTheme = 'stark';

  /**
   *
   */
  public function testAnonymousAccessDenied(): void {
    $this->drupalGet('/admin/islandora/oai-harvester');
    $this->assertSession()->statusCodeEquals(403);
  }

}
