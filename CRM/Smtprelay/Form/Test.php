<?php
declare(strict_types = 1);

use CRM_Smtprelay_ExtensionUtil as E;

/**
 * civicrm/admin/setting/smtprelay/test - handshake with the relay without sending a message.
 *
 * A standard CiviCRM form: opening it runs nothing; the handshake runs on submit, which QuickForm only
 * accepts with the session-bound qfKey (CSRF) and a valid e-mail address. The 'submit' action shows
 * the form again with the result.
 */
class CRM_Smtprelay_Form_Test extends CRM_Core_Form {

  /**
   * Sender to test: the default From address of the domain.
   *
   * @var string
   */
  protected $from;

  public function preProcess(): void {
    $this->setTitle(E::ts('SMTP Relay: test connection'));
    $this->from = (string) CRM_Core_BAO_Domain::getNameAndEmail(TRUE)[1];
  }

  public function buildQuickForm(): void {
    $this->add('email', 'to', E::ts('Recipient'), ['size' => 40], TRUE);
    $this->addRule('to', E::ts('The recipient is not a valid e-mail address.'), 'email');
    $this->addButtons([['type' => 'submit', 'name' => E::ts('Run test'), 'isDefault' => TRUE]]);

    // 'mode' is a reserved template variable of CiviCRM forms; show the translated label of the mode
    // ("Off", Dutch "Uit") as relayMode.
    $mode    = CRM_Smtprelay_Decision::mode();
    $options = \Civi\Core\SettingsMetadata::getMetadata(['name' => ['smtprelay_mode']], NULL, TRUE)['smtprelay_mode']['options'] ?? [];
    $this->assign('relayMode', trim(explode(' - ', (string) ($options[$mode] ?? $mode), 2)[0]));
    $this->assign('from', $this->from);
    $this->assign('steps', []);
    $this->assign('settingsUrl', CRM_Utils_System::url('civicrm/admin/setting/smtprelay', 'reset=1'));
  }

  public function setDefaultValues(): array {
    return ['to' => $this->from];
  }

  public function postProcess(): void {
    $steps = CRM_Smtprelay_Handshake::run($this->from, (string) $this->getSubmittedValue('to'));
    $this->assign('steps', $steps);
    $this->assign('ok', !in_array(FALSE, array_column($steps, 'ok'), TRUE));
  }

}
