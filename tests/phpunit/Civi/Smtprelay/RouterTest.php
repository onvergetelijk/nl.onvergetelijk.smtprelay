<?php

namespace Civi\Smtprelay;

use Civi\Core\Event\GenericHookEvent;
use Civi\Test\EndToEndInterface;

/**
 * The router as a mailer, with recording fake mailers (nothing is sent).
 *
 * - Mode 'off' does not hook in; 'shadow' does.
 * - Reminders and mailings below the rule go to the default mailer; shadow sends everything there.
 * - A matching mailing goes to the relay with the configured envelope sender; X-CiviMail-Bounce stays.
 * - Relay failure: 'refuse' returns the error without touching the default route; 'fallback' sends
 *   the original message (without the relay envelope) via the default route.
 *
 * Settings are set per test and reverted in tearDown.
 *
 * @group e2e
 */
class RouterTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private const KEYS = ['smtprelay_mode', 'smtprelay_min_recipients', 'smtprelay_mailing_ids', 'smtprelay_envelope_from', 'smtprelay_on_failure'];

  /** @var int Job of a real mailing on this site that has recipients. */
  private $job;

  /** @var int Its recipient count. */
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
    return ['From' => '"Example" <info@example.org>', 'To' => 'x@example.org', 'Subject' => 'RouterTest',
      'Precedence' => 'bulk', 'X-CiviMail-Bounce' => $verp, 'Return-Path' => $verp];
  }

  private function router(FakeMailer $default, ?FakeMailer $relay): TestableRouter {
    $r = new TestableRouter($default);
    $r->fakeRelay = $relay;
    return $r;
  }

  public function testOffDoesNotHookIn(): void {
    $this->set('off', 1);
    $original = new FakeMailer();
    $e = GenericHookEvent::create(['mailer' => $original, 'driver' => 'smtp', 'params' => []]);
    smtprelay_alter_mailer($e);
    $this->assertSame($original, $e->mailer);
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
    $this->assertInstanceOf(\PEAR_Error::class, $this->router($default, $relay)->send(['x@example.org'], $this->bulk(), 'text'));
    $this->assertCount(0, $default->sent);
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

  public function send($recipients, $headers, $body) {
    if ($this->error) {
      return \PEAR::raiseError($this->error);
    }
    $this->sent[] = ['recipients' => $recipients, 'headers' => $headers, 'body' => $body];
    return TRUE;
  }

}

/**
 * Router with a replaceable relay transport, so tests make no SMTP connection.
 */
class TestableRouter extends \CRM_Smtprelay_Router {

  public $fakeRelay = NULL;

  protected function relayMailer(string $from) {
    return $this->fakeRelay ?? parent::relayMailer($from);
  }

  public function realRelayMailer(string $from) {
    return parent::relayMailer($from);
  }

}
