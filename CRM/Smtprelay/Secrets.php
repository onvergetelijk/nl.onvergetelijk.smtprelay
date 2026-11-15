<?php
declare(strict_types = 1);

/**
 * Keeps the relay password encrypted at rest.
 */
class CRM_Smtprelay_Secrets {

  /**
   * post_change listener of the setting smtprelay_password: replace a plain value by its crypto.token
   * encryption (key tag CRED, like core's SMTP password). Saving the encrypted value fires this
   * listener again; that value is no longer plain text, so the recursion stops there.
   */
  public static function encryptPassword($oldValue, $newValue, $metadata = [], $domainId = NULL): void {
    if (!is_string($newValue) || $newValue === '' || !\Civi::container()->has('crypto.token')) {
      return;
    }
    $crypto = \Civi::service('crypto.token');
    if (!$crypto->isPlainText($newValue)) {
      return;
    }
    \Civi::settings($domainId)->set('smtprelay_password', $crypto->encrypt($newValue, 'CRED'));
  }

}
