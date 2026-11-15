<?php

require_once 'Mail.php';
require_once 'Mail/smtp.php';
require_once 'Net/SMTP.php';

/**
 * PEAR Mail_smtp that never sends credentials without TLS.
 *
 * Mail_smtp hands STARTTLS to Net_SMTP::auth(), which carries on with AUTH when the server does not
 * offer STARTTLS (Net_SMTP 1.10-1.12, SMTP.php around line 740): a server or a man in the middle that
 * strips STARTTLS gets the password or OAuth token in plain text. Here STARTTLS is done first and must
 * succeed; only then AUTH (without a second TLS attempt). With Encryption = ssl the connection is TLS
 * from the start (ssl:// with peer verification, PHP's default).
 */
class CRM_Smtprelay_Transport extends Mail_smtp {

  /** @var string 'starttls' | 'ssl' | 'none' */
  public $security = 'starttls';

  public function __construct($params) {
    parent::__construct($params);
    $this->security = (string) ($params['smtprelay_security'] ?? 'starttls');
  }

  public function getSMTPObject() {
    if (is_object($this->_smtp)) {
      return $this->_smtp;
    }
    if (!in_array($this->security, CRM_Smtprelay_Policy::SECURITY, TRUE)) {
      return PEAR::raiseError('smtprelay: unknown encryption setting', CRM_Smtprelay_Policy::CONFIG);
    }
    if ($this->auth && $this->security === 'none') {
      return PEAR::raiseError('smtprelay: refusing to authenticate without TLS', CRM_Smtprelay_Policy::CONFIG);
    }

    // Set before anything can fail: Mail_smtp::_error() reads the server reply from $this->_smtp
    // (a NULL there was a fatal "getResponse() on null" on every connect or login failure). Every
    // failure below clears it again, so the next message starts a fresh connection.
    $smtp        = new Net_SMTP($this->host, $this->port, $this->localhost, $this->pipelining, 0, $this->socket_options);
    $this->_smtp = $smtp;
    if (PEAR::isError($res = $smtp->connect($this->timeout))) {
      $error       = $this->_error('Failed to connect to ' . $this->host . ':' . $this->port, $res);
      $this->_smtp = NULL;
      return PEAR::raiseError($error, PEAR_MAIL_SMTP_ERROR_CONNECT);
    }

    if ($this->security === 'starttls') {
      $tls = $smtp->starttls();
      if ($tls !== TRUE) {
        $smtp->disconnect();
        $this->_smtp = NULL;
        $reason = PEAR::isError($tls) ? $tls->getMessage() : 'not offered by the server';
        return PEAR::raiseError('smtprelay: STARTTLS failed (' . $reason . '); nothing sent', PEAR_MAIL_SMTP_ERROR_CONNECT);
      }
    }

    if ($this->auth) {
      $method = is_string($this->auth) ? $this->auth : '';
      // $tls = FALSE: the connection is already encrypted; no second STARTTLS attempt.
      if (PEAR::isError($res = $smtp->auth($this->username, $this->password, $method, FALSE))) {
        $error = $this->_error("$method authentication failure", $res);
        $smtp->disconnect();
        $this->_smtp = NULL;
        return PEAR::raiseError($error, PEAR_MAIL_SMTP_ERROR_AUTH);
      }
    }

    return $this->_smtp;
  }

}
