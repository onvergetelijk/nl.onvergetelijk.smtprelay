<?php

use CRM_Smtprelay_ExtensionUtil as E;

/**
 * civicrm/admin/setting/smtprelay/test - handshake with the relay without sending a message.
 */
class CRM_Smtprelay_Page_Test extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('SMTP Relay: test connection'));
    [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail(TRUE);
    $to    = CRM_Utils_Request::retrieve('to', 'String') ?: $fromEmail;
    $steps = CRM_Smtprelay_Handshake::run((string) $fromEmail, (string) $to);
    $ok    = $steps && !in_array(FALSE, array_column($steps, 'ok'), TRUE);

    $this->assign('steps', $steps);
    $this->assign('ok', $ok);
    $this->assign('from', $fromEmail);
    $this->assign('to', $to);
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
