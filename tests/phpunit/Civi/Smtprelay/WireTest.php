<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * What actually goes over the wire to the relay, measured with a local SMTP sink
 * (tests/phpunit/fixtures/smtp-sink.php, a separate PHP process on 127.0.0.1; nothing is delivered).
 *
 * - EHLO uses the domain of the sender, not "localhost".
 * - MAIL FROM is the configured envelope sender; the X-CiviMail-Bounce header is kept.
 * - With Authentication = none there is no AUTH command.
 *
 * The relay transport is taken from the router with the CiviCRM log filter peeled off
 * (FilteredPearMailer::getDelegate()), because a development site may define CIVICRM_MAIL_LOG, which
 * makes that filter swallow every message instead of sending it.
 *
 * @group e2e
 */
class WireTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private const KEYS = ['smtprelay_mode', 'smtprelay_min_recipients', 'smtprelay_host', 'smtprelay_port',
    'smtprelay_security', 'smtprelay_auth', 'smtprelay_helo', 'smtprelay_envelope_from'];

  private $process;

  private $log;

  public function tearDown(): void {
    foreach (self::KEYS as $k) {
      \Civi::settings()->revert($k);
    }
    if (is_resource($this->process)) {
      proc_terminate($this->process);
    }
    if ($this->log && file_exists($this->log)) {
      unlink($this->log);
    }
    parent::tearDown();
  }

  public function testRelayedMessageOnTheWire(): void {
    $job = (int) \CRM_Core_DAO::singleValueQuery("SELECT j.id FROM civicrm_mailing_job j
        WHERE j.is_test = 0 AND j.parent_id IS NULL
          AND EXISTS (SELECT 1 FROM civicrm_mailing_recipients r WHERE r.mailing_id = j.mailing_id)
        ORDER BY j.id DESC LIMIT 1");
    if (!$job) {
      $this->markTestSkipped('no non-test mailing job with recipients on this site');
    }

    $this->log = tempnam(sys_get_temp_dir(), 'smtprelay-sink-');
    $this->process = proc_open([PHP_BINARY, __DIR__ . '/../../fixtures/smtp-sink.php', $this->log],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $this->assertIsResource($this->process, 'cannot start the SMTP sink');
    $port = (int) trim((string) fgets($pipes[1]));
    $this->assertGreaterThan(0, $port, 'the SMTP sink did not report a port');

    $s = \Civi::settings();
    $s->set('smtprelay_mode', 'on');
    $s->set('smtprelay_min_recipients', 1);
    $s->set('smtprelay_host', '127.0.0.1');
    $s->set('smtprelay_port', $port);
    $s->set('smtprelay_security', 'none');
    $s->set('smtprelay_auth', 'none');
    $s->set('smtprelay_envelope_from', 'bounces@example.org');

    $verp    = "b.$job.1.0123456789abcdef@example.org";
    $headers = ['From' => '"Example" <info@example.org>', 'To' => 'x@example.org', 'Subject' => 'WireTest',
      'Precedence' => 'bulk', 'X-CiviMail-Bounce' => $verp, 'Return-Path' => $verp];
    // The default route must not be used here; this one would fail the test if it were.
    $default = new class extends \Mail {

      public function send($recipients, $headers, $body) {
        return \PEAR::raiseError('default route used instead of the relay');
      }

    };
    $router = new UnfilteredRouter($default);
    $result = $router->send(['x@example.org'], $headers, "Body\n");
    $this->assertTrue($result === TRUE, is_object($result) ? $result->getMessage() : 'send failed');
    $router->disconnect();

    // Wait for the sink to finish the session.
    for ($i = 0; $i < 50 && proc_get_status($this->process)['running']; $i++) {
      usleep(100000);
    }
    $entries  = array_map(fn($l) => json_decode($l, TRUE), array_filter(explode("\n", (string) file_get_contents($this->log))));
    $commands = array_column(array_filter($entries, fn($e) => isset($e['cmd'])), 'cmd');
    $message  = array_values(array_filter($entries, fn($e) => isset($e['headers'])))[0]['headers'] ?? [];

    $this->assertContains('EHLO example.org', $commands, 'EHLO with the sender domain: ' . implode(' | ', $commands));
    $this->assertContains('MAIL FROM:<bounces@example.org>', $commands, 'envelope sender: ' . implode(' | ', $commands));
    $this->assertContains('RCPT TO:<x@example.org>', $commands);
    $this->assertEmpty(array_filter($commands, fn($c) => stripos($c, 'AUTH') === 0), 'no AUTH with Authentication = none');
    $this->assertSame($verp, $message['X-CiviMail-Bounce'] ?? NULL, 'VERP header kept for bounce processing');
  }

}

/**
 * Router whose relay transport is the bare Mail_smtp (log filter peeled off), so it really connects.
 */
class UnfilteredRouter extends \CRM_Smtprelay_Router {

  protected function relayMailer(string $from) {
    $mailer = parent::relayMailer($from);
    return is_a($mailer, 'CRM_Utils_Mail_FilteredPearMailer') ? $mailer->getDelegate() : $mailer;
  }

  public function disconnect(): void {
    if ($this->relay) {
      $smtp = $this->relay->getDelegate();
      if (method_exists($smtp, 'disconnect')) {
        $smtp->disconnect();
      }
    }
  }

}
