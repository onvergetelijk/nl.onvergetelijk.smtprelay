<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'smtprelay.civix.php';
// phpcs:enable

use CRM_Smtprelay_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function smtprelay_civicrm_config(\CRM_Core_Config $config): void {
  _smtprelay_civix_civicrm_config($config);

  // Hook in on hook_civicrm_alterMailer with priority -1000: after extensions with the default
  // priority (such as oauth_outgoing_mail), so the router is the outermost mailer. The static guard
  // prevents double registration when hook_civicrm_config fires more than once per request.
  static $registered = FALSE;
  if (!$registered) {
    $registered = TRUE;
    \Civi::dispatcher()->addListener('hook_civicrm_alterMailer', 'smtprelay_alter_mailer', -1000);
  }
}

/**
 * Listener on hook_civicrm_alterMailer. In mode 'off' nothing happens: the mailer stays exactly what
 * core and other extensions made of it.
 */
function smtprelay_alter_mailer(\Civi\Core\Event\GenericHookEvent $e): void {
  if (CRM_Smtprelay_Decision::mode() === 'off') {
    return;
  }
  $e->mailer = new CRM_Smtprelay_Router($e->mailer);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function smtprelay_civicrm_install(): void {
  _smtprelay_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function smtprelay_civicrm_enable(): void {
  _smtprelay_civix_civicrm_enable();
}
