<?php

use CRM_Smtprelay_ExtensionUtil as E;

/**
 * civicrm/admin/setting/smtprelay/test - handshake with the relay without sending a message.
 *
 * Opening the page does nothing but show a form. The handshake runs only on a POST that carries the
 * session-bound key of this page (CRM_Core_Key, the same mechanism as qfKey), so a link or an image
 * elsewhere cannot make a logged-in administrator's browser connect to the relay (CSRF). The recipient
 * must be a valid e-mail address.
 */
class CRM_Smtprelay_Page_Test extends CRM_Core_Page {

  public const KEY_NAME = 'CRM_Smtprelay_Page_Test';

  public function run() {
    CRM_Utils_System::setTitle(E::ts('SMTP Relay: test connection'));
    [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail(TRUE);
    $to    = (string) $fromEmail;
    $steps = [];
    $error = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      $posted = trim((string) ($_POST['to'] ?? ''));
      if (!CRM_Core_Key::validate($_POST['key'] ?? NULL, self::KEY_NAME)) {
        $error = E::ts('The form has expired. Open the page again and retry.');
      }
      elseif (!CRM_Utils_Rule::email($posted)) {
        $error = E::ts('The recipient is not a valid e-mail address.');
        $to    = $posted;
      }
      else {
        $to    = $posted;
        $steps = CRM_Smtprelay_Handshake::run((string) $fromEmail, $to);
      }
    }

    $this->assign('steps', $steps);
    $this->assign('ran', (bool) $steps);
    $this->assign('ok', $steps && !in_array(FALSE, array_column($steps, 'ok'), TRUE));
    $this->assign('error', $error);
    $this->assign('from', $fromEmail);
    $this->assign('to', $to);
    $this->assign('key', CRM_Core_Key::get(self::KEY_NAME));
    $this->assign('action', CRM_Utils_System::url('civicrm/admin/setting/smtprelay/test', 'reset=1'));
    // 'mode' is set by CRM_Core_Page::run() itself and would overwrite ours (showed "null").
    // Show the translated label of the mode ("Off", Dutch "Uit"), not the stored key.
    $mode    = CRM_Smtprelay_Decision::mode();
    $options = \Civi\Core\SettingsMetadata::getMetadata(['name' => ['smtprelay_mode']], NULL, TRUE)['smtprelay_mode']['options'] ?? [];
    $label   = (string) ($options[$mode] ?? $mode);
    $this->assign('relayMode', trim(explode(' - ', $label, 2)[0]));
    $this->assign('settingsUrl', CRM_Utils_System::url('civicrm/admin/setting/smtprelay', 'reset=1'));
    parent::run();
  }

}
