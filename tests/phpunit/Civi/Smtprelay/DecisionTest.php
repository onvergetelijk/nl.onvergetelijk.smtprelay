<?php

namespace Civi\Smtprelay;

use Civi\Test\EndToEndInterface;

/**
 * The pure decision (CRM_Smtprelay_Decision::decide).
 *
 * - Only CiviMail bulk mail (X-CiviMail-Bounce + Precedence: bulk) can be relayed.
 * - Scheduled reminders, CiviRules and single mails never, also not in mode 'on'.
 * - Test mailings never; below the size rule never, unless the mailing is listed.
 * - 'shadow' decides but sends the normal way; 'off' does not even look up the mailing.
 * - An unknown mode or a size rule of 0 never relays everything by accident.
 *
 * @group e2e
 */
class DecisionTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private function bulk(int $job = 101): array {
    return [
      'From'              => '"Example" <info@example.org>',
      'X-CiviMail-Bounce' => "b.$job.987654.0123456789abcdef@example.org",
      'Precedence'        => 'bulk',
    ];
  }

  /** Jobs: 101 large, 102 small, 103 test, others unknown. */
  private function size(): callable {
    return function (int $job): ?array {
      return [
        101 => ['mailing_id' => 11, 'is_test' => FALSE, 'recipients' => 3320],
        102 => ['mailing_id' => 12, 'is_test' => FALSE, 'recipients' => 438],
        103 => ['mailing_id' => 11, 'is_test' => TRUE,  'recipients' => 3320],
      ][$job] ?? NULL;
    };
  }

  public function testJobFromVerpHeader(): void {
    $this->assertSame(101, \CRM_Smtprelay_Decision::jobFromHeaders($this->bulk()));
    $this->assertNull(\CRM_Smtprelay_Decision::jobFromHeaders(['From' => 'x@example.org']));
    $this->assertNull(\CRM_Smtprelay_Decision::jobFromHeaders(['X-CiviMail-Bounce' => 'info@example.org']));
    $this->assertSame(55, \CRM_Smtprelay_Decision::jobFromHeaders(['X-CiviMail-Bounce' => 'b-55-66-abcdef@example.org'], '-'));
  }

  public function testLargeMailingIsRelayed(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(101), 'on', 1000, [], $this->size());
    $this->assertSame('relay', $d['route']);
    $this->assertSame(11, $d['mailing_id']);
    $this->assertSame(3320, $d['recipients']);
  }

  public function testSmallMailingStaysDefault(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(102), 'on', 1000, [], $this->size());
    $this->assertSame('default', $d['route']);
    $this->assertStringContainsString('below 1000', $d['reason']);
  }

  public function testListedMailingIsRelayedWhateverItsSize(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(102), 'on', 1000, [12], $this->size());
    $this->assertSame('relay', $d['route']);
    $this->assertSame('mailing listed', $d['reason']);
  }

  public function testListOnlyWithSizeRuleOff(): void {
    $this->assertSame('default', \CRM_Smtprelay_Decision::decide($this->bulk(101), 'on', 0, [], $this->size())['route']);
    $this->assertSame('relay', \CRM_Smtprelay_Decision::decide($this->bulk(101), 'on', 0, [11], $this->size())['route']);
  }

  public function testTestMailingNeverRelayed(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(103), 'on', 1, [11], $this->size());
    $this->assertSame('default', $d['route']);
    $this->assertSame('test mailing', $d['reason']);
  }

  public function testReminderNeverRelayed(): void {
    $reminder = ['From' => 'info@example.org', 'Subject' => 'Reminder', 'Return-Path' => 'info@example.org'];
    $d = \CRM_Smtprelay_Decision::decide($reminder, 'on', 1, [11, 12], $this->size());
    $this->assertSame('default', $d['route']);
    $this->assertSame('not a CiviMail mailing', $d['reason']);
  }

  public function testWithoutPrecedenceBulkNotRelayed(): void {
    $h = $this->bulk(101);
    unset($h['Precedence']);
    $this->assertSame('default', \CRM_Smtprelay_Decision::decide($h, 'on', 1000, [], $this->size())['route']);
  }

  public function testShadowDecidesButDoesNotRelay(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(101), 'shadow', 1000, [], $this->size());
    $this->assertSame('default', $d['route']);
    $this->assertTrue($d['would_relay']);
  }

  public function testOffDoesNotLookAnythingUp(): void {
    $called = FALSE;
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(101), 'off', 1000, [11], function () use (&$called) {
      $called = TRUE;
      return NULL;
    });
    $this->assertSame('default', $d['route']);
    $this->assertFalse($called, 'Mode off must not touch the database.');
  }

  public function testUnknownModeIsOff(): void {
    $this->assertSame('default', \CRM_Smtprelay_Decision::decide($this->bulk(101), 'yes', 1, [11], $this->size())['route']);
  }

  public function testUnknownJobStaysDefault(): void {
    $d = \CRM_Smtprelay_Decision::decide($this->bulk(999), 'on', 1000, [], $this->size());
    $this->assertSame('default', $d['route']);
    $this->assertSame('unknown job', $d['reason']);
  }

}
