<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Edge cases of reading the settings, and the connection test without a host.
 *
 * - A mode value that is not off/shadow/on counts as off (never as on).
 * - The mailing ID list accepts commas, semicolons and spaces, and ignores rubbish.
 * - The connection test without a relay host reports a single failed "settings" step.
 * - Fail closed: unknown encryption or authentication values, credentials without TLS, ports outside
 *   the list, hosts on private/loopback addresses and invalid EHLO names are errors, never defaults.
 *
 * @group e2e
 */
class SettingsEdgeTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  public function tearDown(): void {
    foreach (['smtprelay_mode', 'smtprelay_mailing_ids', 'smtprelay_host', 'smtprelay_port', 'smtprelay_security',
      'smtprelay_auth', 'smtprelay_helo'] as $k) {
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

  /**
   * @dataProvider refusedSettings
   */
  public function testRefusedSettings(array $settings, string $expect): void {
    $s = \Civi::settings();
    $s->set('smtprelay_host', 'smtp-relay.gmail.com');
    foreach ($settings as $k => $v) {
      $s->set($k, $v);
    }
    $scope  = \CRM_Core_TemporaryErrorScope::ignoreException();
    $result = \CRM_Smtprelay_Router::transportParams('info@example.org');
    $this->assertInstanceOf(\PEAR_Error::class, $result, json_encode($settings));
    $this->assertStringContainsString($expect, $result->getMessage());
  }

  public function refusedSettings(): array {
    return [
      'unknown encryption'     => [['smtprelay_security' => 'tls'], 'unknown encryption'],
      'unknown authentication' => [['smtprelay_auth' => 'cram'], 'unknown authentication'],
      'password without TLS'   => [['smtprelay_security' => 'none', 'smtprelay_auth' => 'password'], 'only sent over TLS'],
      'port outside the list'  => [['smtprelay_port' => 8080], 'not allowed'],
      'loopback host'          => [['smtprelay_host' => '127.0.0.1'], 'private or reserved'],
      'private host'           => [['smtprelay_host' => '10.1.2.3'], 'private or reserved'],
      'localhost name'         => [['smtprelay_host' => 'localhost'], 'not a valid host name'],
      'invalid EHLO'           => [['smtprelay_helo' => 'bad name!'], 'EHLO'],
    ];
  }

  public function testPublicAddressCheck(): void {
    foreach (['8.8.8.8', '2001:4860:4860::8888'] as $ip) {
      $this->assertTrue(\CRM_Smtprelay_Policy::isPublicIp($ip), $ip);
    }
    foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.1.1', '100.64.0.1', '198.18.0.1', '192.0.0.8', '0.1.2.3',
      '::1', 'fe80::1', 'fc00::1', '64:ff9b::a00:1', '2002:a00:1::1', '::ffff:10.0.0.1'] as $ip) {
      $this->assertFalse(\CRM_Smtprelay_Policy::isPublicIp($ip), $ip);
    }
  }

  public function testEhloFromSenderOrSetting(): void {
    $this->assertSame('example.org', \CRM_Smtprelay_Policy::helo('"X" <info@Example.org>'));
    \Civi::settings()->set('smtprelay_helo', 'mail.example.org');
    $this->assertSame('mail.example.org', \CRM_Smtprelay_Policy::helo('info@other.example'));
  }

  public function testRedactRemovesAddresses(): void {
    $this->assertSame('Failed to add recipient: <address> (code: 550)', \CRM_Smtprelay_Policy::redact('Failed to add recipient: someone@example.org (code: 550)'));
  }

}
