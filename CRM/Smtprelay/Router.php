<?php

/**
 * Outermost mailer: per message either the relay or the mailer CiviCRM built (which may already be
 * wrapped by other extensions, such as oauth_outgoing_mail).
 *
 * Hooked in by smtprelay_alter_mailer() with priority -1000 on hook_civicrm_alterMailer, so it runs
 * after extensions that use the default priority and ends up as the outermost layer.
 */
class CRM_Smtprelay_Router extends Mail {

  /** @var Mail */
  protected $default;

  /** @var Mail|null Relay transport, built on the first relayed message of this process. */
  protected $relay = NULL;

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

    \Civi::log('smtprelay')->error('Relay failed: ' . $result->getMessage());
    if ((string) \Civi::settings()->get('smtprelay_on_failure') === 'fallback') {
      // The message as CiviCRM built it, without the relay envelope.
      return $this->default->send($recipients, $original, $body);
    }
    return $result;
  }

  /**
   * Builds the relay transport once per process (persist: one SMTP connection for the whole run).
   * Built with Mail::factory directly, NOT CRM_Utils_Mail::_createMailer(): that would call
   * hook_civicrm_alterMailer again, and other extensions would wrap the relay too.
   *
   * @return Mail|PEAR_Error
   */
  protected function relayMailer(string $from) {
    if ($this->relay) {
      return $this->relay;
    }
    $params = self::transportParams($from);
    if (is_a($params, 'PEAR_Error')) {
      return $params;
    }
    $mailer = Mail::factory('smtp', $params);
    if (is_a($mailer, 'PEAR_Error')) {
      return $mailer;
    }
    // Same filters as core's CRM_Utils_Mail::_createMailer(), without the hook.
    $mailer = new CRM_Utils_Mail_FilteredPearMailer('smtp', $params, $mailer);
    $mailer->addFilter('2000_log', ['CRM_Utils_Mail_Logger', 'filter']);
    $mailer->addFilter('2100_validate', function ($mailer, &$recipients, &$headers, &$body) {
      if (!is_array($headers)) {
        return PEAR::raiseError('$headers must be an array');
      }
    });
    $this->relay = $mailer;
    return $this->relay;
  }

  /**
   * PEAR Mail_smtp parameters from the settings, including credentials.
   *
   * @return array|PEAR_Error
   */
  public static function transportParams(string $from) {
    $s    = \Civi::settings();
    $host = trim((string) $s->get('smtprelay_host'));
    if ($host === '') {
      return PEAR::raiseError('smtprelay: no relay host configured');
    }
    $security = (string) ($s->get('smtprelay_security') ?: 'starttls');
    $helo     = trim((string) $s->get('smtprelay_helo'));
    if ($helo === '' && preg_match('/@([\w.-]+)/', $from, $m)) {
      $helo = $m[1];
    }
    $params = [
      'host'      => ($security === 'ssl' ? 'ssl://' : '') . $host,
      'port'      => (int) ($s->get('smtprelay_port') ?: 587),
      'auth'      => FALSE,
      'localhost' => $helo ?: 'localhost',
      'starttls'  => $security === 'starttls',
      'persist'   => TRUE,
      'timeout'   => 30,
    ];
    $credentials = CRM_Smtprelay_Credentials::forSender($from);
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
