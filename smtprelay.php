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
  if (!_smtprelay_is_settings_form($formName)) {
    return;
  }
  $form->setTitle(E::ts('SMTP Relay settings'));

  // The stored password never goes back to the browser (not even encrypted). An empty field on save
  // means "keep the stored password". The form saves the empty value regardless (QuickForm falls back
  // to the element's default), so remember the stored value here and put it back in postProcess.
  // It is never put into the form itself, so a re-displayed form cannot show it.
  if ($form->elementExists('smtprelay_password')) {
    $form->setDefaults(['smtprelay_password' => '']);
    unset(\Civi::$statics['smtprelay']['password_kept']);
    $stored = (string) \Civi::settings()->get('smtprelay_password');
    if ($form->isSubmitted() && $stored !== '' && (string) ($form->_submitValues['smtprelay_password'] ?? '') === '') {
      \Civi::$statics['smtprelay']['password_kept'] = $stored;
    }
  }
}

/**
 * Implements hook_civicrm_postProcess().
 *
 * Restores the stored password after a save with an empty field (see smtprelay_civicrm_buildForm).
 */
function smtprelay_civicrm_postProcess($formName, $form): void {
  if (_smtprelay_is_settings_form($formName) && isset(\Civi::$statics['smtprelay']['password_kept'])) {
    \Civi::settings()->set('smtprelay_password', \Civi::$statics['smtprelay']['password_kept']);
    unset(\Civi::$statics['smtprelay']['password_kept']);
  }
}

/**
 * Implements hook_civicrm_validateForm().
 *
 * Checks on the settings form, so a policy violation is reported when saving instead of when the
 * first mailing tries the relay. The same rules are enforced again at send time (CRM_Smtprelay_Policy).
 * - Choosing OAuth, or changing where an OAuth token goes, requires "manage OAuth client secrets":
 *   whoever controls this setting decides which server receives a mailbox token.
 */
function smtprelay_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors): void {
  if (!_smtprelay_is_settings_form($formName)) {
    return;
  }
  $s        = \Civi::settings();
  $auth     = (string) ($fields['smtprelay_auth'] ?? $s->get('smtprelay_auth'));
  $security = (string) ($fields['smtprelay_security'] ?? $s->get('smtprelay_security'));
  $host     = strtolower(trim((string) ($fields['smtprelay_host'] ?? $s->get('smtprelay_host'))));
  $port     = (int) ($fields['smtprelay_port'] ?? $s->get('smtprelay_port'));

  // The network-free rules of the send path (an empty host is allowed here: it switches the relay off).
  $violations = CRM_Smtprelay_Policy::violations(['host' => $host, 'port' => $port, 'security' => $security, 'auth' => $auth]);
  $messages   = [
    'port'       => ['smtprelay_port', E::ts('Allowed: 25, 465, 587 or 2525.')],
    'tls'        => ['smtprelay_security', E::ts('Credentials are only sent over TLS: choose STARTTLS or implicit TLS, or set Authentication to None.')],
    'oauth_host' => ['smtprelay_host', E::ts('With OAuth the relay host must belong to the token provider (Google: smtp-relay.gmail.com or smtp.gmail.com, port 587 with STARTTLS or 465 with implicit TLS).')],
  ];
  foreach (array_intersect_key($messages, $violations) as [$field, $message]) {
    $errors[$field] = $message;
  }
  // A kept password goes wherever host, port and username point. Changing those with the password
  // left empty would deliver the stored password to the new server (which an administrator could
  // run): ask for it again.
  if ($auth === 'password' && isset(\Civi::$statics['smtprelay']['password_kept'])
      && (strtolower(trim((string) $s->get('smtprelay_host'))) !== $host
        || (int) $s->get('smtprelay_port') !== $port
        || trim((string) $s->get('smtprelay_username')) !== trim((string) ($fields['smtprelay_username'] ?? $s->get('smtprelay_username'))))) {
    $errors['smtprelay_password'] = E::ts('The relay host, port or username changed: enter the password again.');
  }
  if ($auth === 'oauth') {
    $changed = $s->get('smtprelay_auth') !== 'oauth'
      || strtolower(trim((string) $s->get('smtprelay_host'))) !== $host
      || (int) $s->get('smtprelay_port') !== $port
      || (string) $s->get('smtprelay_security') !== $security;
    if ($changed && !CRM_Core_Permission::check('manage OAuth client secrets')) {
      $errors['smtprelay_auth'] = E::ts('Choosing OAuth or changing its relay host, port or encryption requires the permission "manage OAuth client secrets".');
    }
  }
}

function _smtprelay_is_settings_form($formName): bool {
  return $formName === 'CRM_Admin_Form_Generic' && CRM_Utils_System::currentPath() === 'civicrm/admin/setting/smtprelay';
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
      $item['child'][] = [
        'attributes' => [
          'label'               => E::ts('SMTP Relay settings'),
          'name'                => 'smtprelay_setting_admin',
          'url'                 => $path . '?reset=1',
          'permission'          => 'administer CiviCRM,administer smtprelay',
          'permission_operator' => 'OR',
          'active'              => 1,
        ],
      ];
      $found = TRUE;
    }
  });
}
