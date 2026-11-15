<?php

/**
 * Tests the relay without sending anything: connect, (STARTTLS), authenticate, MAIL FROM, RCPT TO,
 * RSET, QUIT. DATA is never sent. Secrets are never part of the output.
 */
class CRM_Smtprelay_Handshake {

  /**
   * @param string $from  Sender to test (for example the default From address of the domain).
   * @param string $to    Recipient to test.
   *
   * @return array List of ['step' => string, 'ok' => bool, 'reply' => string].
   */
  public static function run(string $from, string $to): array {
    $steps = [];
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $add   = function (string $step, $result, $smtp = NULL) use (&$steps) {
      $ok = !is_a($result, 'PEAR_Error');
      $reply = $smtp ? implode(' ', (array) $smtp->getResponse()) : '';
      if (!$ok && $reply === '') {
        $reply = $result->getMessage();
      }
      $steps[] = ['step' => $step, 'ok' => $ok, 'reply' => trim(preg_replace('/\s+/', ' ', $reply))];
      return $ok;
    };

    $params = CRM_Smtprelay_Router::transportParams($from);
    if (is_a($params, 'PEAR_Error')) {
      $add('settings', $params);
      return $steps;
    }
    $smtp = new Net_SMTP($params['host'], $params['port'], $params['localhost'], FALSE, 15);
    if (!$add('connect', $smtp->connect(15), $smtp)) {
      return $steps;
    }
    if ($params['auth']) {
      $method = is_string($params['auth']) ? $params['auth'] : NULL;
      $ok = $add('auth' . ($method ? " $method" : ''), $smtp->auth($params['username'], $params['password'], $method, (bool) $params['starttls']), $smtp);
    }
    elseif ($params['starttls']) {
      $ok = $add('starttls', $smtp->starttls(), $smtp);
    }
    else {
      $ok = TRUE;
    }
    if ($ok
        && $add('MAIL FROM ' . self::address($from), $smtp->mailFrom(self::address($from)), $smtp)
        && $add('RCPT TO ' . self::address($to), $smtp->rcptTo(self::address($to)), $smtp)) {
      $add('RSET', $smtp->rset(), $smtp);
    }
    $smtp->disconnect();
    return $steps;
  }

  /**
   * Bare address from "Name <address>".
   */
  public static function address(string $value): string {
    return preg_match('/[\w.+-]+@[\w.-]+/', $value, $m) ? $m[0] : $value;
  }

}
