<?php

// The PEAR_MAIL_SMTP_ERROR_* constants, also when no relay transport has been built yet.
require_once 'Mail/smtp.php';

/**
 * Outermost mailer: per message either the relay or the mailer CiviCRM built (which may already be
 * wrapped by other extensions, such as oauth_outgoing_mail).
 *
 * Hooked in by smtprelay_alter_mailer() with priority -1000 on hook_civicrm_alterMailer, so it runs
 * after extensions that use the default priority and ends up as the outermost layer.
 */
class CRM_Smtprelay_Router extends Mail {

  /**
   * Marker that FlexMailer's DefaultSender::isTemporaryError() recognises as a temporary error: it
   * then retries the batch and, after 5 in a row, stops the job until the next cron run. Without it a
   * relay problem (connection, login, policy, quota, configuration) would be recorded as a bounce for
   * every recipient, and enough bounces put those contacts on hold.
   */
  public const TEMPORARY = 'Failed to write to socket';

  /** @var Mail */
  protected $default;

  /** @var Mail[] Relay transports per sender address, built on the first relayed message of this process. */
  protected $relays = [];

  public function __construct($default) {
    $this->default = $default;
  }

  // Properties and other methods belong to the wrapped mailer; keep the router transparent.
  public function &__get($name) {
    return $this->default->$name;
  }

  public function __set($name, $value) {
    $this->default->$name = $value;
  }

  public function __call($name, $args) {
    return call_user_func_array([$this->default, $name], $args);
  }

  /**
   * @return mixed TRUE or a PEAR_Error, like every PEAR mailer.
   */
  public function send($recipients, $headers, $body) {
    $decision = CRM_Smtprelay_Decision::decide(
      is_array($headers) ? $headers : [],
      CRM_Smtprelay_Decision::mode(),
      (int) (\Civi::settings()->get('smtprelay_min_recipients') ?? 0),
      CRM_Smtprelay_Decision::listedMailings(),
      ['CRM_Smtprelay_Decision', 'mailingSize'],
      (string) (CRM_Core_Config::singleton()->verpSeparator ?: '.')
    );

    if ($decision['would_relay']) {
      \Civi::log('smtprelay')->info(sprintf('%s: %s (job %d, mailing %s, %s recipients)',
        strtoupper($decision['route']), $decision['reason'], $decision['job'],
        $decision['mailing_id'] ?? '-', $decision['recipients'] ?? '-'));
    }

    if ($decision['route'] !== 'relay') {
      return $this->default->send($recipients, $headers, $body);
    }

    $original = $headers;
    $envelope = trim((string) \Civi::settings()->get('smtprelay_envelope_from'));
    if ($envelope !== '') {
      // PEAR Mail_smtp uses Return-Path as MAIL FROM. X-CiviMail-Bounce stays for bounce processing.
      $headers['Return-Path'] = $envelope;
    }

    $mailer = $this->relayMailer((string) ($headers['From'] ?? ''));
    $result = is_a($mailer, 'PEAR_Error') ? $mailer : $mailer->send($recipients, $headers, $body);
    if (!is_a($result, 'PEAR_Error')) {
      return $result;
    }

    $class = self::classify($result);
    \Civi::log('smtprelay')->error(sprintf('Relay failed (%s): %s', $class, CRM_Smtprelay_Policy::redact($result->getMessage())));

    // A broken connection may be half open; start clean on the next message.
    if ($class !== 'recipient') {
      $this->dropRelay((string) ($headers['From'] ?? ''));
    }

    // A refused address is a bounce on any route; never spend the normal route's quota on it.
    if ($class === 'recipient') {
      return $result;
    }

    // Fall back only when the relay certainly did not take the message (before DATA), otherwise the
    // recipient could get it twice.
    if ($result->getCode() !== PEAR_MAIL_SMTP_ERROR_DATA
        && (string) \Civi::settings()->get('smtprelay_on_failure') === 'fallback') {
      return $this->default->send($recipients, $original, $body);
    }

    // A configuration or policy error will not go away by retrying. Pause the mailing (as the Pause
    // button in CiviMail does), so later cron runs skip it instead of stopping on it every time;
    // resume it in CiviMail after fixing the settings.
    if ($result->getCode() === CRM_Smtprelay_Policy::CONFIG && $decision['mailing_id']) {
      $this->pauseMailing((int) $decision['mailing_id']);
    }
    return PEAR::raiseError('smtprelay: ' . self::TEMPORARY . ' (relay unavailable or refused the job; the mailing resumes in a later run): '
      . CRM_Smtprelay_Policy::redact($result->getMessage()), $result->getCode());
  }

  /**
   * Pauses a mailing once per process, with an error in the log (channel smtprelay).
   */
  protected function pauseMailing(int $mailingId): void {
    static $paused = [];
    if (isset($paused[$mailingId])) {
      return;
    }
    $paused[$mailingId] = TRUE;
    try {
      CRM_Mailing_BAO_MailingJob::pause($mailingId);
      \Civi::log('smtprelay')->error("Mailing $mailingId paused: the relay is not usable with the current settings. Fix the SMTP Relay settings (or set the mode to Off) and resume the mailing in CiviMail.");
    }
    catch (\Throwable $e) {
      \Civi::log('smtprelay')->error("Mailing $mailingId could not be paused: " . $e->getMessage());
    }
  }

  /**
   * Is this relay error about this one recipient or message ('recipient': a bounce is right), or about
   * the relay, its configuration or its policy ('job': pause the job, never bounce)?
   *
   * Only a permanent (5xx) refusal of the recipient or the message with an enhanced status code about
   * the address or the content (5.1.x, 5.2.x, 5.3.4, 5.6.x) is a bounce. Policy, relaying, quota and
   * authentication refusals (5.7.x, 5.4.5, 4xx) and every connection or configuration error are 'job'.
   */
  public static function classify(PEAR_Error $error): string {
    if (!in_array($error->getCode(), [PEAR_MAIL_SMTP_ERROR_RECIPIENT, PEAR_MAIL_SMTP_ERROR_DATA], TRUE)) {
      return 'job';
    }
    if (!preg_match('/ \(code: (\d\d\d), response: ([^)]*)/', $error->getMessage(), $m) || $m[1][0] !== '5') {
      return 'job';
    }
    // With an enhanced status code: only address and content refusals bounce.
    if (preg_match('/^(\d)\.(\d{1,3})\.(\d{1,3})\b/', trim($m[2]), $e)) {
      return preg_match('/^5\.(1\.\d+|2\.\d+|3\.4|6\.\d+)$/', "$e[1].$e[2].$e[3]") ? 'recipient' : 'job';
    }
    // Without one (servers without ENHANCEDSTATUSCODES) a 5xx on RCPT or DATA is about this message;
    // retrying it would keep the job from ever finishing.
    return 'recipient';
  }

  /**
   * Closes the relay connections and the default route (FlexMailer calls this between retries).
   */
  public function disconnect() {
    foreach ($this->relays as $relay) {
      self::closeTransport($relay);
    }
    $this->relays = [];
    if (is_callable([$this->default, 'disconnect'])) {
      return $this->default->disconnect();
    }
    return TRUE;
  }

  protected function dropRelay(string $from): void {
    $key = CRM_Smtprelay_Policy::address($from);
    if (isset($this->relays[$key])) {
      self::closeTransport($this->relays[$key]);
      unset($this->relays[$key]);
    }
  }

  protected static function closeTransport($mailer): void {
    $smtp = is_a($mailer, 'CRM_Utils_Mail_FilteredPearMailer') ? $mailer->getDelegate() : $mailer;
    if (is_object($smtp) && method_exists($smtp, 'disconnect')) {
      $smtp->disconnect();
    }
  }

  /**
   * Builds the relay transport once per sender address per process (persist: one SMTP connection for
   * the whole run; a second sender with other credentials gets its own connection and login).
   * Built directly, NOT with CRM_Utils_Mail::_createMailer(): that would call hook_civicrm_alterMailer
   * again, and other extensions would wrap the relay too.
   *
   * @return Mail|PEAR_Error
   */
  protected function relayMailer(string $from) {
    $key = CRM_Smtprelay_Policy::address($from);
    if (isset($this->relays[$key])) {
      return $this->relays[$key];
    }
    $params = static::transportParams($from);
    if (is_a($params, 'PEAR_Error')) {
      return $params;
    }
    $mailer = new CRM_Smtprelay_Transport($params);
    // Same filters as core's CRM_Utils_Mail::_createMailer(), without the hook.
    $mailer = new CRM_Utils_Mail_FilteredPearMailer('smtp', $params, $mailer);
    $mailer->addFilter('2000_log', ['CRM_Utils_Mail_Logger', 'filter']);
    $mailer->addFilter('2100_validate', function ($mailer, &$recipients, &$headers, &$body) {
      if (!is_array($headers)) {
        return PEAR::raiseError('$headers must be an array');
      }
    });
    $this->relays[$key] = $mailer;
    return $mailer;
  }

  /**
   * Allow a relay host on a private or loopback address. Only for tests against a local SMTP sink.
   */
  protected static function allowLocalHosts(): bool {
    return FALSE;
  }

  /**
   * Transport parameters from the validated settings, including credentials.
   *
   * @return array|PEAR_Error
   */
  public static function transportParams(string $from) {
    $target = CRM_Smtprelay_Policy::target(static::allowLocalHosts());
    if (is_a($target, 'PEAR_Error')) {
      return $target;
    }
    $helo = CRM_Smtprelay_Policy::helo($from);
    if (is_a($helo, 'PEAR_Error')) {
      return $helo;
    }
    // Pinned to the checked IPv4 address (CRM_Smtprelay_Policy::target); certificate checked against
    // the host name.
    $connectTo = $target['ip'] ?? $target['host'];
    $params = [
      'host'               => ($target['security'] === 'ssl' ? 'ssl://' : '') . $connectTo,
      'socket_options'     => ['ssl' => ['peer_name' => $target['host'], 'SNI_server_name' => $target['host'], 'verify_peer' => TRUE, 'verify_peer_name' => TRUE]],
      'port'               => $target['port'],
      'auth'               => FALSE,
      'localhost'          => $helo,
      'starttls'           => $target['security'] === 'starttls',
      'smtprelay_security' => $target['security'],
      'persist'            => TRUE,
      'timeout'            => 30,
    ];
    $credentials = CRM_Smtprelay_Credentials::forSender($from, $target);
    if (is_a($credentials, 'PEAR_Error')) {
      return $credentials;
    }
    if ($credentials) {
      $params['auth']     = $credentials['auth'];
      $params['username'] = $credentials['username'];
      $params['password'] = $credentials['password'];
    }
    return $params;
  }

}
