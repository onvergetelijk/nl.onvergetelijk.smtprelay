<?php

/**
 * Login data for the relay. Never logs or returns secrets other than to the transport.
 */
class CRM_Smtprelay_Credentials {

  /**
   * @param string $from    From header of the message.
   * @param array  $target  Validated target from CRM_Smtprelay_Policy::target().
   *
   * @return array|PEAR_Error|null
   *   NULL when no authentication is configured, ['auth' => method, 'username' => ..., 'password' => ...]
   *   otherwise, or a PEAR_Error when the configured method cannot produce credentials.
   */
  public static function forSender(string $from, array $target) {
    $s = \Civi::settings();
    switch ($target['auth']) {
      case 'none':
        return NULL;

      case 'password':
        $password = (string) $s->get('smtprelay_password');
        if ($password !== '' && \Civi::container()->has('crypto.token')) {
          try {
            // Decrypts a crypto.token value; a plain value (stored before 1.1) comes back unchanged.
            $password = \Civi::service('crypto.token')->decrypt($password, '*');
          }
          catch (\Throwable $e) {
            return PEAR::raiseError('smtprelay: password cannot be decrypted (is the CRED key still configured?)', CRM_Smtprelay_Policy::CONFIG);
          }
        }
        return ['auth' => TRUE, 'username' => (string) $s->get('smtprelay_username'), 'password' => $password];

      case 'oauth':
        return self::oauth($from, $target);

      default:
        // CRM_Smtprelay_Policy::target() already refuses unknown values; never fall through to "none".
        return PEAR::raiseError('smtprelay: unknown authentication setting', CRM_Smtprelay_Policy::CONFIG);
    }
  }

  /**
   * XOAUTH2 with the system token that oauth_outgoing_mail keeps for this sender.
   *
   * Follows CRM_OauthOutgoingMail_Mailer::send(): From address -> SiteEmailAddress with exactly that
   * address -> token tagged FromMail:<id>, else the token of the default address. The token's provider
   * must have a relay profile that matches the target (CRM_Smtprelay_Policy::OAUTH_PROFILES); that is
   * checked BEFORE the token is refreshed or handed out, so a token never goes to another host.
   * Username from the provider mail settings (for Google: the mailbox address; Google rejects the
   * display name with 535 BadCredentials). Net_SMTP builds "user=<u>\1auth=<password>\1\1", so the
   * password is 'Bearer <token>'.
   *
   * @return array|PEAR_Error
   */
  public static function oauth(string $from, array $target) {
    if (!function_exists('_oauth_outgoing_mail_get_mailsettings')) {
      return PEAR::raiseError('smtprelay: Authentication = OAuth needs the extension oauth_outgoing_mail', CRM_Smtprelay_Policy::CONFIG);
    }
    $address   = CRM_Smtprelay_Policy::address($from);
    $addresses = \Civi\Api4\SiteEmailAddress::get(FALSE)
      ->addSelect('id', 'email')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('is_default', 'DESC')
      ->addOrderBy('display_name', 'ASC')
      ->setLimit(0)
      ->execute()
      ->getArrayCopy();
    $token = NULL;
    foreach (CRM_Smtprelay_Policy::tokenOrder($from, $addresses) as $id) {
      $token = civicrm_api4('OAuthSysToken', 'get', [
        'checkPermissions' => FALSE,
        'select'           => ['*', 'client_id.provider'],
        'where'            => [['tag', '=', 'FromMail:' . $id]],
      ])->first();
      if ($token) {
        break;
      }
    }
    if (!$token) {
      return PEAR::raiseError('smtprelay: no OAuth system token (tag FromMail:*) for this sender', CRM_Smtprelay_Policy::CONFIG);
    }

    $allowed = CRM_Smtprelay_Policy::checkOauthTarget((string) ($token['client_id.provider'] ?? ''), $target);
    if (is_a($allowed, 'PEAR_Error')) {
      return $allowed;
    }

    try {
      $fresh = civicrm_api4('OAuthSysToken', 'refresh', [
        'checkPermissions' => FALSE,
        'where'            => [['id', '=', $token['id']]],
      ])->first() ?: $token;
    }
    catch (\Throwable $e) {
      \Civi::log('smtprelay')->warning('Token refresh failed, using the current token');
      $fresh = $token;
    }
    if (empty($fresh['access_token']) || (int) $fresh['expires'] < CRM_Utils_Time::time()) {
      return PEAR::raiseError('smtprelay: OAuth token missing or expired');
    }
    // The username belongs to the token's mailbox. No fallback to the From address: with the default
    // token that could be another mailbox, and the relay would answer 535.
    $settings = _oauth_outgoing_mail_get_mailsettings($token);
    $user = (string) ($settings['username'] ?? '');
    if (strpos($user, '@') === FALSE) {
      return PEAR::raiseError('smtprelay: cannot determine the mailbox of the OAuth token', CRM_Smtprelay_Policy::CONFIG);
    }
    return ['auth' => 'XOAUTH2', 'username' => $user, 'password' => 'Bearer ' . $fresh['access_token']];
  }

}
