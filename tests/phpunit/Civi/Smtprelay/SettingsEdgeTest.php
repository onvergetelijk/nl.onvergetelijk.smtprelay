<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Edge cases of reading the settings, and the connection test without a host.
 *
 * - A mode value that is not off/shadow/on counts as off (never as on).
 * - The mailing ID list accepts commas, semicolons and spaces, and ignores rubbish.
 * - The connection test without a relay host reports a single failed "settings" step.
 *
 * @group e2e
 */
class SettingsEdgeTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  public function tearDown(): void {
    foreach (['smtprelay_mode', 'smtprelay_mailing_ids', 'smtprelay_host'] as $k) {
      \Civi::settings()->revert($k);
    }
    parent::tearDown();
  }

  public function testUnknownModeIsOff(): void {
    \Civi::settings()->set('smtprelay_mode', 'yes please');
    $this->assertSame('off', \CRM_Smtprelay_Decision::mode());
    \Civi::settings()->set('smtprelay_mode', 'shadow');
    $this->assertSame('shadow', \CRM_Smtprelay_Decision::mode());
  }

  public function testMailingListParsing(): void {
    \Civi::settings()->set('smtprelay_mailing_ids', " 12;13 ,x, 14\n15 ");
    $this->assertSame([12, 13, 14, 15], \CRM_Smtprelay_Decision::listedMailings());
    \Civi::settings()->set('smtprelay_mailing_ids', '');
    $this->assertSame([], \CRM_Smtprelay_Decision::listedMailings());
  }

  public function testHandshakeWithoutHost(): void {
    \Civi::settings()->revert('smtprelay_host');
    $steps = \CRM_Smtprelay_Handshake::run('info@example.org', 'x@example.org');
    $this->assertCount(1, $steps);
    $this->assertSame('settings', $steps[0]['step']);
    $this->assertFalse($steps[0]['ok']);
    $this->assertStringContainsString('no relay host', $steps[0]['reply']);
  }

}
