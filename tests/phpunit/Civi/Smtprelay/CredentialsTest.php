<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Credentials and transport parameters.
 *
 * - none: no credentials; STARTTLS by default, implicit TLS adds ssl://, EHLO from the sender domain.
 * - password: plain and crypto.token-encrypted values both end up as the plain password.
 * - oauth: without oauth_outgoing_mail or without a token, an error instead of a relay without login.
 *
 * @group e2e
 */
class CredentialsTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private const KEYS = ['smtprelay_auth', 'smtprelay_username', 'smtprelay_password', 'smtprelay_host',
    'smtprelay_port', 'smtprelay_security', 'smtprelay_helo'];

  public function tearDown(): void {
    foreach (self::KEYS as $k) {
      \Civi::settings()->revert($k);
    }
    parent::tearDown();
  }

  public function testNoneGivesNoCredentialsAndSaneTransport(): void {
    \Civi::settings()->set('smtprelay_host', 'relay.example.org');
    $this->assertNull(\CRM_Smtprelay_Credentials::forSender('info@example.org'));
    $p = \CRM_Smtprelay_Router::transportParams('"Example" <info@example.org>');
    $this->assertSame('relay.example.org', $p['host']);
    $this->assertSame(587, $p['port']);
    $this->assertTrue($p['starttls']);
    $this->assertFalse($p['auth']);
    $this->assertSame('example.org', $p['localhost'], 'EHLO comes from the sender domain, not "localhost".');
  }

  public function testImplicitTlsPrefixesHost(): void {
    \Civi::settings()->set('smtprelay_host', 'relay.example.org');
    \Civi::settings()->set('smtprelay_security', 'ssl');
    \Civi::settings()->set('smtprelay_port', 465);
    $p = \CRM_Smtprelay_Router::transportParams('info@example.org');
    $this->assertSame('ssl://relay.example.org', $p['host']);
    $this->assertFalse($p['starttls']);
  }

  public function testPlainPassword(): void {
    \Civi::settings()->set('smtprelay_auth', 'password');
    \Civi::settings()->set('smtprelay_username', 'relayuser');
    \Civi::settings()->set('smtprelay_password', 'plain-secret');
    $c = \CRM_Smtprelay_Credentials::forSender('info@example.org');
    $this->assertSame(['auth' => TRUE, 'username' => 'relayuser', 'password' => 'plain-secret'], $c);
  }

  public function testEncryptedPassword(): void {
    if (!\Civi::container()->has('crypto.token')) {
      $this->markTestSkipped('crypto.token not available');
    }
    \Civi::settings()->set('smtprelay_auth', 'password');
    \Civi::settings()->set('smtprelay_password', \Civi::service('crypto.token')->encrypt('encrypted-secret', 'CRED'));
    $this->assertSame('encrypted-secret', \CRM_Smtprelay_Credentials::forSender('info@example.org')['password']);
  }

  public function testOauthWithoutExtensionOrTokenIsAnError(): void {
    if (function_exists('_oauth_outgoing_mail_get_mailsettings')
        && civicrm_api4('OAuthSysToken', 'get', ['checkPermissions' => FALSE, 'where' => [['tag', 'LIKE', 'FromMail:%']]])->count()) {
      $this->markTestSkipped('this site has an OAuth token; this test covers the case without one');
    }
    \Civi::settings()->set('smtprelay_auth', 'oauth');
    \Civi::settings()->set('smtprelay_host', 'relay.example.org');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Credentials::forSender('info@example.org'));
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Router::transportParams('info@example.org'));
  }

}
