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
    // Before the setting-admin mixin (-1000) adds its menu item: add ours with a translatable label.
    \Civi::dispatcher()->addListener('hook_civicrm_navigationMenu', 'smtprelay_navigation_label', -100);
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

/**
 * Implements hook_civicrm_buildForm().
 *
 * The setting-admin mixin titles the page with ts('%1 Settings'), which core does not translate
 * (and the title is stored when the menu is rebuilt, in whatever language that process ran). Set a
 * translatable title at display time instead.
 */
function smtprelay_civicrm_buildForm($formName, &$form): void {
  if ($formName === 'CRM_Admin_Form_Generic' && CRM_Utils_System::currentPath() === 'civicrm/admin/setting/smtprelay') {
    $form->setTitle(E::ts('SMTP Relay settings'));
  }
}

/**
 * Listener on hook_civicrm_navigationMenu: add the Administer > System Settings link ourselves, with a
 * translatable label. The setting-admin mixin adds the same link at priority -1000 with the
 * untranslated title "%1 Settings", but skips it when an item for this path already exists; this
 * listener runs earlier (-100), so ours wins.
 */
function smtprelay_navigation_label(\Civi\Core\Event\GenericHookEvent $e): void {
  $path  = 'civicrm/admin/setting/smtprelay';
  $found = FALSE;
  $walk  = function (array &$items, callable $visit) use (&$walk): void {
    foreach ($items as &$item) {
      $visit($item);
      if (!empty($item['child'])) {
        $walk($item['child'], $visit);
      }
    }
  };
  $walk($e->params, function (&$item) use ($path, &$found) {
    if (strpos((string) ($item['attributes']['url'] ?? ''), $path) === 0) {
      $item['attributes']['label'] = E::ts('SMTP Relay settings');
      $found = TRUE;
    }
  });
  if ($found) {
    return;
  }
  $walk($e->params, function (&$item) use ($path, &$found) {
    if (!$found && ($item['attributes']['name'] ?? '') === 'System Settings') {
      $item['child'][] = ['attributes' => [
        'label'               => E::ts('SMTP Relay settings'),
        'name'                => 'smtprelay_setting_admin',
        'url'                 => $path . '?reset=1',
        'permission'          => 'administer CiviCRM,administer smtprelay',
        'permission_operator' => 'OR',
        'active'              => 1,
      ]];
      $found = TRUE;
    }
  });
}
