<?php

/**
 * Tests the relay without sending anything: connect, TLS, authenticate, MAIL FROM, RCPT TO, RSET,
 * QUIT. DATA is never sent. Secrets are never part of the output.
 *
 * Connect, TLS and login go through CRM_Smtprelay_Transport, the same code as real relayed mail, so
 * the test cannot log in where a mailing would not (and never without TLS).
 */
class CRM_Smtprelay_Handshake {

  /**
   * @param string $from  Sender to test (for example the default From address of the domain).
   * @param string $to    Recipient to test (a valid e-mail address; the caller validates it).
   *
   * @return array List of ['step' => string, 'ok' => bool, 'reply' => string].
   */
  public static function run(string $from, string $to): array {
    $steps = [];
    $scope = \CRM_Core_TemporaryErrorScope::ignoreException();
    $add   = function (string $step, $result, $smtp = NULL) use (&$steps) {
      $ok = !is_a($result, 'PEAR_Error');
      $reply = $smtp ? implode(' ', (array) $smtp->getResponse()) : '';
      if (!$ok && ($reply === '' || $reply === ' ')) {
        $reply = $result->getMessage();
      }
      $steps[] = ['step' => $step, 'ok' => $ok, 'reply' => trim(preg_replace('/\s+/', ' ', (string) $reply))];
      return $ok;
    };

    $params = CRM_Smtprelay_Router::transportParams($from);
    if (is_a($params, 'PEAR_Error')) {
      $add('settings', $params);
      return $steps;
    }
    $transport = new CRM_Smtprelay_Transport(['persist' => FALSE, 'timeout' => 15] + $params);
    $label     = 'connect' . ($params['smtprelay_security'] !== 'none' ? ', TLS' : '') . ($params['auth'] ? ', login' . (is_string($params['auth']) ? ' ' . $params['auth'] : '') : '');
    $smtp      = $transport->getSMTPObject();
    if (!$add($label, $smtp)) {
      return $steps;
    }
    $fromAddress = CRM_Smtprelay_Policy::address($from);
    $toAddress   = CRM_Smtprelay_Policy::address($to);
    if ($add('MAIL FROM', $smtp->mailFrom($fromAddress), $smtp)
        && $add('RCPT TO', $smtp->rcptTo($toAddress), $smtp)) {
      $add('RSET', $smtp->rset(), $smtp);
    }
    $transport->disconnect();
    return $steps;
  }

  /**
   * Bare address from "Name <address>".
   *
   * @deprecated since 1.1, use CRM_Smtprelay_Policy::address().
   */
  public static function address(string $value): string {
    return CRM_Smtprelay_Policy::address($value);
  }

}
