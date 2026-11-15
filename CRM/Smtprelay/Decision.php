<?php

/**
 * Decides per message whether it goes via the relay. Pure: everything comes in as arguments, so the
 * rules can be unit tested without a database (see tests/phpunit/Civi/Smtprelay/DecisionTest.php).
 */
class CRM_Smtprelay_Decision {

  /**
   * Job ID from the CiviMail VERP bounce header X-CiviMail-Bounce.
   *
   * CiviMail builds it as <localpart>b<sep><job><sep><queue><sep><hash>@<domain>
   * (CRM_Mailing_BAO_Mailing::getVerpAndUrls). The separator is passed in, so a site with another
   * verpSeparator is not silently routed the normal way.
   */
  public static function jobFromHeaders(array $headers, string $sep = '.'): ?int {
    $bounce = $headers['X-CiviMail-Bounce'] ?? NULL;
    if (!is_string($bounce) || $bounce === '') {
      return NULL;
    }
    $s = preg_quote($sep, '/');
    if (preg_match('/(?:^|[^a-z0-9])b' . $s . '(\d+)' . $s . '\d+' . $s . '[0-9a-f]+@/i', $bounce, $m)) {
      return (int) $m[1];
    }
    return NULL;
  }

  /**
   * @param array    $headers      Message headers as the mailer receives them.
   * @param string   $mode         'off' | 'shadow' | 'on'.
   * @param int      $minimum      Size rule: at least this many recipients (0 = rule off).
   * @param int[]    $mailingIds   Mailing IDs that always go via the relay.
   * @param callable $size         fn(int $job): ?array ['mailing_id' => int, 'is_test' => bool, 'recipients' => int]
   * @param string   $sep          verpSeparator.
   *
   * @return array ['route' => 'relay'|'default', 'reason' => string, 'would_relay' => bool,
   *                'job' => ?int, 'mailing_id' => ?int, 'recipients' => ?int]
   */
  public static function decide(array $headers, string $mode, int $minimum, array $mailingIds, callable $size, string $sep = '.'): array {
    $out = ['route' => 'default', 'reason' => '', 'would_relay' => FALSE, 'job' => NULL, 'mailing_id' => NULL, 'recipients' => NULL];

    if ($mode !== 'shadow' && $mode !== 'on') {
      return ['reason' => 'mode off'] + $out;
    }

    // Only CiviMail bulk mail. Scheduled reminders, CiviRules and single mails carry neither the
    // VERP bounce header nor "Precedence: bulk".
    $job = self::jobFromHeaders($headers, $sep);
    if ($job === NULL || strtolower(trim((string) ($headers['Precedence'] ?? ''))) !== 'bulk') {
      return ['reason' => 'not a CiviMail mailing'] + $out;
    }

    $info = $size($job);
    if (!$info) {
      return ['reason' => 'unknown job', 'job' => $job] + $out;
    }
    $base = ['job' => $job, 'mailing_id' => $info['mailing_id'] ?? NULL, 'recipients' => $info['recipients'] ?? NULL];

    if (!empty($info['is_test'])) {
      return ['reason' => 'test mailing'] + $base + $out;
    }

    $listed = in_array((int) ($info['mailing_id'] ?? 0), array_map('intval', $mailingIds), TRUE);
    $large  = $minimum > 0 && (int) ($info['recipients'] ?? 0) >= $minimum;
    if (!$listed && !$large) {
      return ['reason' => $minimum > 0 ? "below $minimum recipients" : 'no rule matches'] + $base + $out;
    }
    $reason = $listed ? 'mailing listed' : "at least $minimum recipients";

    if ($mode === 'shadow') {
      return ['reason' => "shadow: would relay ($reason)", 'would_relay' => TRUE] + $base + $out;
    }
    return ['route' => 'relay', 'reason' => $reason, 'would_relay' => TRUE] + $base + $out;
  }

  /**
   * Mailing behind a job, with its recipient count. One query per job per process: a large mailing
   * sends many messages through the same router.
   */
  public static function mailingSize(int $job): ?array {
    static $cache = [];
    if (!array_key_exists($job, $cache)) {
      $row = CRM_Core_DAO::executeQuery(
        "SELECT j.mailing_id, j.is_test,
                (SELECT COUNT(*) FROM civicrm_mailing_recipients r WHERE r.mailing_id = j.mailing_id) AS recipients
           FROM civicrm_mailing_job j WHERE j.id = %1",
        [1 => [$job, 'Integer']]
      );
      $cache[$job] = $row->fetch()
        ? ['mailing_id' => (int) $row->mailing_id, 'is_test' => (bool) $row->is_test, 'recipients' => (int) $row->recipients]
        : NULL;
    }
    return $cache[$job];
  }

  /**
   * Mailing IDs from the comma-separated setting.
   */
  public static function listedMailings(): array {
    return array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) \Civi::settings()->get('smtprelay_mailing_ids')))));
  }

  /**
   * Current mode; an unknown value counts as 'off', never as 'on'.
   */
  public static function mode(): string {
    $mode = (string) (\Civi::settings()->get('smtprelay_mode') ?? 'off');
    return in_array($mode, ['off', 'shadow', 'on'], TRUE) ? $mode : 'off';
  }

}
