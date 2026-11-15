<?php

/**
 * Login data for the relay. Never logs or returns secrets other than to the transport.
 */
class CRM_Smtprelay_Credentials {

  /**
   * @return array|PEAR_Error|null
   *   NULL when no authentication is configured, ['auth' => method, 'username' => ..., 'password' => ...]
   *   otherwise, or a PEAR_Error when the configured method cannot produce credentials.
   */
  public static function forSender(string $from) {
    $s = \Civi::settings();
    switch ((string) ($s->get('smtprelay_auth') ?: 'none')) {
      case 'password':
        $password = (string) $s->get('smtprelay_password');
        if ($password !== '' && \Civi::container()->has('crypto.token')) {
          try {
            // Decrypts a crypto.token value; a plain value comes back unchanged.
            $password = \Civi::service('crypto.token')->decrypt($password, '*');
          }
          catch (\Throwable $e) {
            return PEAR::raiseError('smtprelay: password cannot be decrypted: ' . $e->getMessage());
          }
        }
        return ['auth' => TRUE, 'username' => (string) $s->get('smtprelay_username'), 'password' => $password];

      case 'oauth':
        return self::oauth($from);

      default:
        return NULL;
    }
  }

  /**
   * XOAUTH2 with the system token that oauth_outgoing_mail keeps for this sender.
   *
   * Follows CRM_OauthOutgoingMail_Mailer::send(): From header -> SiteEmailAddress -> token tagged
   * FromMail:<id>, else the token of the default address; refresh; username from the provider mail
   * settings (for Google: the mailbox address). Google rejects the display name (535 BadCredentials).
   * Net_SMTP builds "user=<u>\1auth=<password>\1\1", so the password is 'Bearer <token>'.
   *
   * @return array|PEAR_Error
   */
  public static function oauth(string $from) {
    if (!function_exists('_oauth_outgoing_mail_get_mailsettings')) {
      return PEAR::raiseError('smtprelay: Authentication = OAuth needs the extension oauth_outgoing_mail');
    }
    $ids = [];
    $addresses = \Civi\Api4\SiteEmailAddress::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('is_default', 'DESC')
      ->addOrderBy('display_name', 'ASC')
      ->setLimit(0)
      ->execute();
    foreach ($addresses as $a) {
      if ($a['email'] && stripos($from, $a['email']) !== FALSE) {
        array_unshift($ids, $a['id']);
      }
      else {
        $ids[] = $a['id'];
      }
    }
    $token = NULL;
    foreach (array_unique($ids) as $id) {
      $token = civicrm_api4('OAuthSysToken', 'get', [
        'checkPermissions' => FALSE,
        'where'            => [['tag', '=', 'FromMail:' . $id]],
      ])->first();
      if ($token) {
        break;
      }
    }
    if (!$token) {
      return PEAR::raiseError('smtprelay: no OAuth system token (tag FromMail:*) for ' . $from);
    }
    try {
      $fresh = civicrm_api4('OAuthSysToken', 'refresh', [
        'checkPermissions' => FALSE,
        'where'            => [['id', '=', $token['id']]],
      ])->first() ?: $token;
    }
    catch (\Throwable $e) {
      \Civi::log('smtprelay')->warning('Token refresh failed, using the current token: ' . $e->getMessage());
      $fresh = $token;
    }
    if (empty($fresh['access_token']) || (int) $fresh['expires'] < CRM_Utils_Time::time()) {
      return PEAR::raiseError('smtprelay: OAuth token missing or expired');
    }
    $settings = _oauth_outgoing_mail_get_mailsettings($token);
    $user = (string) ($settings['username'] ?? '');
    if (strpos($user, '@') === FALSE) {
      preg_match('/[\w.+-]+@[\w.-]+/', $from, $m);
      $user = $m[0] ?? '';
    }
    if ($user === '') {
      return PEAR::raiseError('smtprelay: cannot determine the OAuth username');
    }
    return ['auth' => 'XOAUTH2', 'username' => $user, 'password' => 'Bearer ' . $fresh['access_token']];
  }

}
