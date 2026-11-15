<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * Credentials and transport parameters.
 *
 * - none: no credentials; STARTTLS by default, implicit TLS adds ssl://, EHLO from the sender domain.
 * - password: a plain value is stored encrypted right after saving; plain (legacy) and encrypted
 *   values both end up as the plain password at the transport.
 * - oauth: the token only goes to a host of its own provider (security review 15-11-2026: before 1.1
 *   any configured host received the mailbox token); without a token, an error instead of a relay
 *   without login.
 *
 * No DNS: transport tests use a public IP literal (PUBLIC_IP), OAuth tests stop at the network-free
 * gate. Only testOauthWithoutExtensionOrTokenIsAnError resolves the Google relay (it is skipped on a
 * site with a token). Nothing connects anywhere: transportParams() only builds parameters.
 *
 * @group e2e
 */
class CredentialsTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private const KEYS = ['smtprelay_auth', 'smtprelay_username', 'smtprelay_password', 'smtprelay_host',
    'smtprelay_port', 'smtprelay_security', 'smtprelay_helo',
  ];

  private const HOST = 'smtp-relay.gmail.com';

  /**
   * A public address, so the host policy passes without a DNS lookup.
   */
  private const PUBLIC_IP = '8.8.8.8';

  public function tearDown(): void {
    foreach (self::KEYS as $k) {
      \Civi::settings()->revert($k);
    }
    parent::tearDown();
  }

  private function target(string $auth = 'none'): array {
    return ['host' => self::HOST, 'port' => 587, 'security' => 'starttls', 'auth' => $auth];
  }

  private function hasOauthToken(): bool {
    return function_exists('_oauth_outgoing_mail_get_mailsettings')
      && civicrm_api4('OAuthSysToken', 'get', ['checkPermissions' => FALSE, 'where' => [['tag', 'LIKE', 'FromMail:%']]])->count() > 0;
  }

  public function testNoneGivesNoCredentialsAndSaneTransport(): void {
    \Civi::settings()->set('smtprelay_host', self::PUBLIC_IP);
    $this->assertNull(\CRM_Smtprelay_Credentials::forSender('info@example.org', $this->target()));
    $p = \CRM_Smtprelay_Router::transportParams('"Example" <info@example.org>');
    $this->assertSame(self::PUBLIC_IP, $p['host'], 'connects to the checked address');
    $this->assertSame(self::PUBLIC_IP, $p['socket_options']['ssl']['peer_name'], 'certificate checked against the configured name');
    $this->assertTrue($p['socket_options']['ssl']['verify_peer']);
    $this->assertSame(587, $p['port']);
    $this->assertTrue($p['starttls']);
    $this->assertSame('starttls', $p['smtprelay_security']);
    $this->assertFalse($p['auth']);
    $this->assertSame('example.org', $p['localhost'], 'EHLO comes from the sender domain, not "localhost".');
  }

  /**
   * A host name is resolved once and the connection goes to that address (DNS rebinding), with the
   * certificate checked against the name. The only test here that needs DNS.
   */
  public function testHostNameIsPinnedToCheckedAddress(): void {
    if (!@gethostbynamel(self::HOST)) {
      $this->markTestSkipped('no DNS');
    }
    \Civi::settings()->set('smtprelay_host', self::HOST);
    $p = \CRM_Smtprelay_Router::transportParams('info@example.org');
    $this->assertNotFalse(filter_var($p['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), 'connects to an address, not to the name again');
    $this->assertSame(self::HOST, $p['socket_options']['ssl']['peer_name']);
  }

  public function testImplicitTlsPrefixesHost(): void {
    \Civi::settings()->set('smtprelay_host', self::PUBLIC_IP);
    \Civi::settings()->set('smtprelay_security', 'ssl');
    \Civi::settings()->set('smtprelay_port', 465);
    $p = \CRM_Smtprelay_Router::transportParams('info@example.org');
    $this->assertSame('ssl://' . self::PUBLIC_IP, $p['host']);
    $this->assertFalse($p['starttls']);
  }

  public function testPlainPasswordIsStoredEncrypted(): void {
    \Civi::settings()->set('smtprelay_auth', 'password');
    \Civi::settings()->set('smtprelay_username', 'relayuser');
    \Civi::settings()->set('smtprelay_password', 'plain-secret');
    if (\Civi::container()->has('crypto.token')) {
      $stored = (string) \CRM_Core_DAO::singleValueQuery("SELECT value FROM civicrm_setting WHERE name = 'smtprelay_password' AND domain_id = %1",
        [1 => [\CRM_Core_Config::domainID(), 'Integer']]);
      $this->assertStringNotContainsString('plain-secret', $stored, 'the password is not stored in plain text');
      $this->assertFalse(\Civi::service('crypto.token')->isPlainText(\Civi::settings()->get('smtprelay_password')));
    }
    $c = \CRM_Smtprelay_Credentials::forSender('info@example.org', $this->target('password'));
    $this->assertSame(['auth' => TRUE, 'username' => 'relayuser', 'password' => 'plain-secret'], $c);
  }

  public function testEncryptedPasswordStaysAsIs(): void {
    if (!\Civi::container()->has('crypto.token')) {
      $this->markTestSkipped('crypto.token not available');
    }
    $encrypted = \Civi::service('crypto.token')->encrypt('encrypted-secret', 'CRED');
    \Civi::settings()->set('smtprelay_password', $encrypted);
    $this->assertSame($encrypted, \Civi::settings()->get('smtprelay_password'), 'an encrypted value is not encrypted twice');
    $this->assertSame('encrypted-secret', \CRM_Smtprelay_Credentials::forSender('info@example.org', $this->target('password'))['password']);
  }

  public function testOauthWithoutExtensionOrTokenIsAnError(): void {
    if ($this->hasOauthToken()) {
      $this->markTestSkipped('this site has an OAuth token; this test covers the case without one');
    }
    \Civi::settings()->set('smtprelay_auth', 'oauth');
    \Civi::settings()->set('smtprelay_host', self::HOST);
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Credentials::forSender('info@example.org', $this->target('oauth')));
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Router::transportParams('info@example.org'));
  }

  /**
   * The finding of the security review: a token must never reach a host outside the provider profile,
   * whatever is configured. Checked on the credentials (no token in the result) and on the transport.
   */
  public function testOauthTokenNeverGoesToAnotherHost(): void {
    if (!$this->hasOauthToken()) {
      $this->markTestSkipped('no OAuth system token on this site');
    }
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    foreach ([
      ['host' => 'smtp.example.org', 'port' => 587, 'security' => 'starttls'],
      ['host' => self::HOST, 'port' => 25, 'security' => 'starttls'],
      ['host' => self::HOST, 'port' => 587, 'security' => 'ssl'],
    ] as $t) {
      $result = \CRM_Smtprelay_Credentials::oauth('info@example.org', $t + ['auth' => 'oauth']);
      $this->assertInstanceOf(\PEAR_Error::class, $result, json_encode($t));
      $this->assertStringNotContainsString('Bearer', $result->getMessage());
    }
    \Civi::settings()->set('smtprelay_auth', 'oauth');
    \Civi::settings()->set('smtprelay_host', 'example.org');
    $params = \CRM_Smtprelay_Router::transportParams('info@example.org');
    $this->assertInstanceOf(\PEAR_Error::class, $params);
    $this->assertStringContainsString('must be one of', $params->getMessage());
  }

  public function testOauthProfileCheck(): void {
    $this->assertTrue(\CRM_Smtprelay_Policy::checkOauthTarget('gmail', ['host' => 'smtp-relay.gmail.com', 'port' => 587, 'security' => 'starttls']));
    $this->assertTrue(\CRM_Smtprelay_Policy::checkOauthTarget('gmail', ['host' => 'smtp.gmail.com', 'port' => 465, 'security' => 'ssl']));
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Policy::checkOauthTarget('gmail', ['host' => 'smtp-relay.gmail.com.evil.example', 'port' => 587, 'security' => 'starttls']));
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Policy::checkOauthTarget('ms-exchange', ['host' => 'smtp.office365.com', 'port' => 587, 'security' => 'starttls']));
  }

  /**
   * The first gate works on every site, also without oauth_outgoing_mail or a token: with OAuth a host
   * outside the provider profiles is refused before any token is looked up, and the local test sink
   * exception does not apply to OAuth.
   */
  public function testOauthTargetRefusedBeforeAnyToken(): void {
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    \Civi::settings()->set('smtprelay_auth', 'oauth');
    foreach ([['example.org', 587, 'starttls'], [self::HOST, 2525, 'starttls'], [self::HOST, 465, 'starttls']] as [$host, $port, $sec]) {
      \Civi::settings()->set('smtprelay_host', $host);
      \Civi::settings()->set('smtprelay_port', $port);
      \Civi::settings()->set('smtprelay_security', $sec);
      $params = \CRM_Smtprelay_Router::transportParams('info@example.org');
      $this->assertInstanceOf(\PEAR_Error::class, $params, "$host:$port $sec");
      $this->assertStringContainsString('with OAuth the relay must be one of', $params->getMessage());
    }
    \Civi::settings()->set('smtprelay_host', '127.0.0.1');
    \Civi::settings()->set('smtprelay_port', 587);
    \Civi::settings()->set('smtprelay_security', 'starttls');
    $this->assertInstanceOf(\PEAR_Error::class, \CRM_Smtprelay_Policy::target(TRUE), 'no token to a local sink either');
    $this->assertSame([], \CRM_Smtprelay_Policy::violations(['host' => self::HOST, 'port' => 587, 'security' => 'starttls', 'auth' => 'oauth']),
      'the Google relay itself passes the first gate');
  }

  public function testTokenOrderUsesExactAddress(): void {
    $addresses = [['id' => 1, 'email' => 'default@example.org'], ['id' => 2, 'email' => 'info@example.org'], ['id' => 3, 'email' => 'Xinfo@example.org']];
    $this->assertSame([3, 1, 2], \CRM_Smtprelay_Policy::tokenOrder('"X" <xinfo@example.org>', $addresses));
    $this->assertSame([2, 1, 3], \CRM_Smtprelay_Policy::tokenOrder('INFO@example.org', $addresses));
    $this->assertSame([1, 2, 3], \CRM_Smtprelay_Policy::tokenOrder('nfo@example.org', $addresses), 'no substring match: default first');
  }

  public function testSenderMatchIsExact(): void {
    $this->assertSame('info@example.org', \CRM_Smtprelay_Policy::address('"Example" <Info@Example.org>'));
    $this->assertSame('xinfo@example.org', \CRM_Smtprelay_Policy::address('xinfo@example.org'));
    $this->assertSame('', \CRM_Smtprelay_Policy::address('"info@example.org" <>'));
  }

}
