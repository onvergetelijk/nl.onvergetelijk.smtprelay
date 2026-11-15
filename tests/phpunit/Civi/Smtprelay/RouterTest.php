<?php

namespace Civi\Smtprelay;

use Civi\Core\Event\GenericHookEvent;
use Civi\Test\EndToEndInterface;

/**
 * The router as a mailer, with recording fake mailers (nothing is sent).
 *
 * - Mode 'shadow' hooks in (that 'off' does not is covered by HookOrderTest).
 * - Reminders and mailings below the rule go to the default mailer; shadow sends everything there.
 * - A matching mailing goes to the relay with the configured envelope sender; X-CiviMail-Bounce stays.
 * - Relay failure: 'refuse' returns the error without touching the default route; 'fallback' sends
 *   the original message (without the relay envelope) via the default route, but never after DATA.
 * - Only a refused address or message (5.1.x, 5.2.x, 5.3.4, 5.6.x) is a bounce; every other relay
 *   error pauses the job (FlexMailer's isTemporaryError) instead of bouncing every recipient.
 * - disconnect() closes the relay connections and the default route.
 *
 * Settings are set per test and reverted in tearDown.
 *
 * @group e2e
 */
class RouterTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private const KEYS = ['smtprelay_mode', 'smtprelay_min_recipients', 'smtprelay_mailing_ids', 'smtprelay_envelope_from', 'smtprelay_on_failure'];

  /**
   * Job of a real mailing on this site that has recipients.
   *
   * @var int
   */
  private $job;

  /**
   * Its recipient count.
   *
   * @var int
   */
  private $count;

  public function setUp(): void {
    parent::setUp();
    $row = \CRM_Core_DAO::executeQuery("SELECT j.id, (SELECT COUNT(*) FROM civicrm_mailing_recipients r WHERE r.mailing_id = j.mailing_id) n
        FROM civicrm_mailing_job j WHERE j.is_test = 0 AND j.parent_id IS NULL HAVING n >= 2 ORDER BY j.id DESC LIMIT 1");
    if (!$row->fetch()) {
      $this->markTestSkipped('no non-test mailing job with recipients on this site');
    }
    $this->job   = (int) $row->id;
    $this->count = (int) $row->n;
  }

  public function tearDown(): void {
    foreach (self::KEYS as $k) {
      \Civi::settings()->revert($k);
    }
    parent::tearDown();
  }

  private function set(string $mode, int $minimum, string $onFailure = 'refuse'): void {
    \Civi::settings()->set('smtprelay_mode', $mode);
    \Civi::settings()->set('smtprelay_min_recipients', $minimum);
    \Civi::settings()->set('smtprelay_envelope_from', 'bounces@example.org');
    \Civi::settings()->set('smtprelay_on_failure', $onFailure);
  }

  private function bulk(): array {
    $verp = "b.{$this->job}.1.0123456789abcdef@example.org";
    return [
      'From' => '"Example" <info@example.org>',
      'To' => 'x@example.org',
      'Subject' => 'RouterTest',
      'Precedence' => 'bulk',
      'X-CiviMail-Bounce' => $verp,
      'Return-Path' => $verp,
    ];
  }

  private function router(FakeMailer $default, ?FakeMailer $relay): TestableRouter {
    $r = new TestableRouter($default);
    $r->fakeRelay = $relay;
    return $r;
  }

  public function testShadowHooksIn(): void {
    $this->set('shadow', 1);
    $e = GenericHookEvent::create(['mailer' => new FakeMailer(), 'driver' => 'smtp', 'params' => []]);
    smtprelay_alter_mailer($e);
    $this->assertInstanceOf(\CRM_Smtprelay_Router::class, $e->mailer);
  }

  public function testReminderGoesToDefault(): void {
    $this->set('on', 1);
    $default = new FakeMailer(); $relay = new FakeMailer();
    $h = ['From' => 'info@example.org', 'To' => 'x@example.org', 'Subject' => 'reminder', 'Return-Path' => 'info@example.org'];
    $this->assertTrue($this->router($default, $relay)->send(['x@example.org'], $h, 'text'));
    $this->assertCount(1, $default->sent);
    $this->assertCount(0, $relay->sent);
  }

  public function testLargeMailingGoesToRelayWithEnvelope(): void {
    $this->set('on', $this->count);
    $default = new FakeMailer(); $relay = new FakeMailer();
    $this->assertTrue($this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text'));
    $this->assertCount(0, $default->sent);
    $this->assertCount(1, $relay->sent);
    $this->assertSame('bounces@example.org', $relay->sent[0]['headers']['Return-Path']);
    $this->assertSame("b.{$this->job}.1.0123456789abcdef@example.org", $relay->sent[0]['headers']['X-CiviMail-Bounce']);
  }

  public function testBelowRuleGoesToDefault(): void {
    $this->set('on', $this->count + 1);
    $default = new FakeMailer(); $relay = new FakeMailer();
    $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertCount(1, $default->sent);
    $this->assertCount(0, $relay->sent);
  }

  public function testListedMailingGoesToRelay(): void {
    $this->set('on', 0);
    $mailing = (int) \CRM_Core_DAO::singleValueQuery('SELECT mailing_id FROM civicrm_mailing_job WHERE id = %1', [1 => [$this->job, 'Integer']]);
    \Civi::settings()->set('smtprelay_mailing_ids', "999999, $mailing");
    $default = new FakeMailer(); $relay = new FakeMailer();
    $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertCount(1, $relay->sent);
  }

  public function testShadowSendsEverythingViaDefault(): void {
    $this->set('shadow', 1);
    $default = new FakeMailer(); $relay = new FakeMailer();
    $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertCount(1, $default->sent);
    $this->assertCount(0, $relay->sent);
  }

  public function testRefuseReturnsErrorWithoutFallback(): void {
    $this->set('on', 1, 'refuse');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $default = new FakeMailer(); $relay = new FakeMailer(); $relay->error = 'relay says no';
    $result = $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertInstanceOf(\PEAR_Error::class, $result);
    $this->assertCount(0, $default->sent);
    $this->assertTrue($this->temporary($result), 'a relay failure pauses the job, it is not a bounce: ' . $result->getMessage());
  }

  /**
   * FlexMailer's own classifier decides bounce or retry; the router must steer it.
   */
  private function temporary(\PEAR_Error $e): bool {
    $sender = new class extends \Civi\FlexMailer\Listener\DefaultSender {

      public function isTemp($m) {
        return $this->isTemporaryError($m);
      }

    };
    return $sender->isTemp($e->getMessage());
  }

  /**
   * Which relay errors bounce the recipient, and which pause the job. A policy refusal by the relay
   * (550 5.7.1 at MAIL FROM, a quota 5.4.5) on a large mailing would otherwise bounce every recipient
   * and put them on hold.
   */
  public function testErrorClassification(): void {
    $this->set('on', 1, 'refuse');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $cases = [
      // [code, message, bounce?]
      [PEAR_MAIL_SMTP_ERROR_SENDER, 'Failed to set sender: x (code: 550, response: 5.7.1 Invalid credentials for relay)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_CONNECT, 'Failed to connect to relay:587 [SMTP: Failed to connect socket]', FALSE],
      [PEAR_MAIL_SMTP_ERROR_AUTH, 'XOAUTH2 authentication failure (code: 535, response: 5.7.8 BadCredentials)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_RECIPIENT, 'Failed to add recipient: x (code: 550, response: 5.4.5 Daily SMTP relay limit exceeded)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_RECIPIENT, 'Failed to add recipient: x (code: 550, response: 5.7.1 Relaying denied)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_RECIPIENT, 'Failed to add recipient: x (code: 450, response: 4.2.1 Try later)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_RECIPIENT, 'Failed to add recipient: x (code: 550, response: 5.1.1 No such user)', TRUE],
      [PEAR_MAIL_SMTP_ERROR_DATA, 'Failed to send data (code: 552, response: 5.3.4 Message too big)', TRUE],
      [PEAR_MAIL_SMTP_ERROR_DATA, 'Failed to send data (code: 550, response: 5.7.0 Policy)', FALSE],
      [PEAR_MAIL_SMTP_ERROR_RECIPIENT, 'Failed to add recipient: x (code: 550, response: No such user here)', TRUE],
      [PEAR_MAIL_SMTP_ERROR_SENDER, 'Failed to set sender: x (code: 550, response: Sender rejected)', FALSE],
      [0, 'smtprelay: no relay host configured', FALSE],
    ];
    foreach ($cases as [$code, $message, $bounce]) {
      $default = new FakeMailer(); $relay = new FakeMailer(); $relay->error = $message; $relay->code = $code;
      $result = $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
      $this->assertInstanceOf(\PEAR_Error::class, $result, $message);
      $this->assertSame(!$bounce, $this->temporary($result), ($bounce ? 'bounce' : 'pause the job') . ': ' . $message);
      $this->assertStringNotContainsString('x@example.org', $result->getMessage());
    }
  }

  public function testNoFallbackAfterData(): void {
    $this->set('on', 1, 'fallback');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $default = new FakeMailer(); $relay = new FakeMailer();
    $relay->error = 'Failed to send data [SMTP: Failed to write to socket]'; $relay->code = PEAR_MAIL_SMTP_ERROR_DATA;
    $result = $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertInstanceOf(\PEAR_Error::class, $result);
    $this->assertCount(0, $default->sent, 'the relay may already have the message; sending it again would duplicate it');
  }

  public function testRefusedAddressDoesNotFallBack(): void {
    $this->set('on', 1, 'fallback');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $default = new FakeMailer(); $relay = new FakeMailer();
    $relay->error = 'Failed to add recipient: x (code: 550, response: 5.1.1 No such user)'; $relay->code = PEAR_MAIL_SMTP_ERROR_RECIPIENT;
    $this->assertInstanceOf(\PEAR_Error::class, $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text'));
    $this->assertCount(0, $default->sent, 'a refused address bounces; no second attempt via the normal route');
  }

  /**
   * A configuration error pauses the mailing (so later cron runs skip it); a connection error does not.
   */
  public function testConfigurationErrorPausesMailing(): void {
    $this->set('on', 1, 'refuse');
    $scope   = \CRM_Core_TemporaryErrorScope::ignoreException();
    $mailing = (int) \CRM_Core_DAO::singleValueQuery('SELECT mailing_id FROM civicrm_mailing_job WHERE id = %1', [1 => [$this->job, 'Integer']]);

    \Civi::settings()->revert('smtprelay_host');
    $router = new TestableRouter(new FakeMailer());
    $result = $router->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertTrue($this->temporary($result), 'no bounce');
    $this->assertSame([$mailing], $router->paused);

    $default = new FakeMailer(); $relay = new FakeMailer();
    $relay->error = 'Failed to connect to relay:587 [SMTP: Failed to connect socket]'; $relay->code = PEAR_MAIL_SMTP_ERROR_CONNECT;
    $router = $this->router($default, $relay);
    $router->send(['x@example.org'], $this->bulk(), 'text');
    $this->assertSame([], $router->paused, 'a connection problem may pass; the mailing keeps running');
  }

  public function testDisconnectClosesRelayAndDefault(): void {
    $this->set('on', 1);
    $default = new FakeMailer(); $relay = new FakeMailer();
    $router = $this->router($default, $relay);
    $router->send(['x@example.org'], $this->bulk(), 'text');
    $router->disconnect();
    $this->assertSame(1, $default->disconnects);
    $this->assertSame(1, $relay->disconnects);
  }

  public function testFallbackSendsOriginalViaDefault(): void {
    $this->set('on', 1, 'fallback');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $default = new FakeMailer(); $relay = new FakeMailer(); $relay->error = 'relay says no';
    $this->assertTrue($this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text'));
    $this->assertCount(1, $default->sent);
    $this->assertSame("b.{$this->job}.1.0123456789abcdef@example.org", $default->sent[0]['headers']['Return-Path'],
      'On fallback the message goes out as CiviCRM built it, without the relay envelope.');
  }

  public function testNoHostMeansNoRelayMailer(): void {
    $this->set('on', 1);
    \Civi::settings()->revert('smtprelay_host');
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $this->assertInstanceOf(\PEAR_Error::class, (new TestableRouter(new FakeMailer()))->realRelayMailer('info@example.org'));
  }

}

/**
 * Recording fake mailer: sends nothing. With $error set it returns a PEAR_Error.
 */
class FakeMailer extends \Mail {

  public $sent = [];

  public $error = NULL;

  public $code = NULL;

  public $disconnects = 0;

  public function send($recipients, $headers, $body) {
    if ($this->error) {
      return \PEAR::raiseError($this->error, $this->code);
    }
    $this->sent[] = ['recipients' => $recipients, 'headers' => $headers, 'body' => $body];
    return TRUE;
  }

  public function disconnect() {
    $this->disconnects++;
    return TRUE;
  }

}

/**
 * Router with a replaceable relay transport, so tests make no SMTP connection.
 */
class TestableRouter extends \CRM_Smtprelay_Router {

  public $fakeRelay = NULL;

  /**
   * Mailings that would have been paused (the test does not touch real mailings).
   *
   * @var int[]
   */
  public $paused = [];

  protected function pauseMailing(int $mailingId): void {
    $this->paused[] = $mailingId;
  }

  protected function relayMailer(string $from) {
    if ($this->fakeRelay) {
      // Registered like a real transport, so disconnect() reaches it.
      return $this->relays[\CRM_Smtprelay_Policy::address($from)] = $this->fakeRelay;
    }
    return parent::relayMailer($from);
  }

  public function realRelayMailer(string $from) {
    return parent::relayMailer($from);
  }

}
