<?php

/**
 * What the relay may connect to, and with which protection. Every check here fails closed: a value
 * that is missing, unknown or outside the policy is an error, never a silent default.
 *
 * Why this exists (security review 15-11-2026):
 * - With Authentication = OAuth the relay presents a mailbox token (for Google: full mail access).
 *   That token may only go to a host that belongs to the token's provider, fixed in code
 *   (OAUTH_PROFILES), never to a host an administrator types in.
 * - Credentials are only sent over TLS (see CRM_Smtprelay_Transport), so Encryption = none is only
 *   allowed without authentication.
 * - The relay host may not resolve to a private, loopback or reserved address, and only the usual
 *   submission ports are allowed, so the connection test cannot be used to probe the internal network.
 */
class CRM_Smtprelay_Policy {

  /**
   * Relay hosts per OAuth provider (the provider name of the OAuthClient, as oauth_outgoing_mail
   * registers it), with the allowed port => encryption per host. Add a provider only after testing
   * XOAUTH2 against its relay.
   */
  public const OAUTH_PROFILES = [
    'gmail' => [
      'smtp-relay.gmail.com' => [587 => 'starttls', 465 => 'ssl'],
      'smtp.gmail.com'       => [587 => 'starttls', 465 => 'ssl'],
    ],
  ];

  public const PORTS = [25, 465, 587, 2525];

  public const SECURITY = ['starttls', 'ssl', 'none'];

  public const AUTH = ['none', 'password', 'oauth'];

  /**
   * PEAR error code of configuration and policy errors: the relay was not even tried. The router pauses
   * the mailing on these instead of letting every cron run fail on it (see CRM_Smtprelay_Router::send).
   */
  public const CONFIG = 10900;

  /**
   * IPv4 ranges that FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE let through but that are not public
   * Internet hosts (carrier-grade NAT, benchmarking, IETF protocol assignments).
   */
  private const EXTRA_V4 = ['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '0.0.0.0/8'];

  /** IPv6 prefixes that embed or translate IPv4 addresses (and could reach private ones). */
  private const EXTRA_V6 = ['64:ff9b::', '64:ff9b:1:', '2002:', '::ffff:'];

  /**
   * The connection target from the settings, validated.
   *
   * @param bool $allowLocal  TRUE only for tests against a local SMTP sink: allows a private or
   *                          loopback host and any port. Never set from configuration.
   *
   * @return array|PEAR_Error ['host' => string, 'port' => int, 'security' => string, 'auth' => string]
   */
  public static function target(bool $allowLocal = FALSE) {
    $s        = \Civi::settings();
    $host     = strtolower(trim((string) $s->get('smtprelay_host')));
    $port     = (int) $s->get('smtprelay_port');
    $security = (string) $s->get('smtprelay_security');
    $auth     = (string) $s->get('smtprelay_auth');

    if ($host === '') {
      return PEAR::raiseError('smtprelay: no relay host configured', self::CONFIG);
    }
    if (!in_array($security, self::SECURITY, TRUE)) {
      return PEAR::raiseError('smtprelay: unknown encryption setting', self::CONFIG);
    }
    if (!in_array($auth, self::AUTH, TRUE)) {
      return PEAR::raiseError('smtprelay: unknown authentication setting', self::CONFIG);
    }
    if ($auth !== 'none' && $security === 'none') {
      return PEAR::raiseError('smtprelay: credentials are only sent over TLS; Encryption = none needs Authentication = none', self::CONFIG);
    }
    if (!$allowLocal && !in_array($port, self::PORTS, TRUE)) {
      return PEAR::raiseError('smtprelay: port ' . $port . ' is not allowed (allowed: ' . implode(', ', self::PORTS) . ')', self::CONFIG);
    }
    $target = ['host' => $host, 'port' => $port, 'security' => $security, 'auth' => $auth];
    // OAuth: first gate, before any token is looked up - the target must be the relay of SOME known
    // provider. CRM_Smtprelay_Credentials::oauth() then checks it against the token's own provider.
    // Not relaxed by $allowLocal: a token never goes to a test sink either.
    if ($auth === 'oauth') {
      $allowed = self::checkAnyOauthTarget($target);
      if (is_a($allowed, 'PEAR_Error')) {
        return $allowed;
      }
    }
    $ips = self::checkHost($host, $allowLocal);
    if (is_a($ips, 'PEAR_Error')) {
      return $ips;
    }
    // Connect to the address that was checked, not to the name again: a second lookup could return
    // another (internal) address (DNS rebinding). TLS still verifies the certificate against the host
    // name (peer_name). Net_Socket cannot connect to a bare IPv6 literal, so pin IPv4 only.
    $v4 = array_values(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    $target['ip'] = $v4[0] ?? NULL;
    return $target;
  }

  /**
   * Is the target the relay of any provider in OAUTH_PROFILES?
   *
   * @return true|PEAR_Error
   */
  public static function checkAnyOauthTarget(array $target) {
    $hosts = [];
    foreach (self::OAUTH_PROFILES as $provider => $profile) {
      if (self::checkOauthTarget($provider, $target) === TRUE) {
        return TRUE;
      }
      foreach ($profile as $h => $ports) {
        foreach ($ports as $p => $sec) {
          $hosts[] = "$h:$p ($sec)";
        }
      }
    }
    return PEAR::raiseError('smtprelay: with OAuth the relay must be one of: ' . implode(', ', $hosts), self::CONFIG);
  }

  /**
   * Order of site e-mail address IDs to look for an OAuth token: the address that is exactly the
   * sender first (case-insensitive, never a substring: "xinfo@" must not pick the token of "info@"),
   * then the others in the given order (default address first).
   *
   * @param string $from       From header.
   * @param array  $addresses  Rows with 'id' and 'email'.
   *
   * @return int[]
   */
  public static function tokenOrder(string $from, array $addresses): array {
    $address = self::address($from);
    $ids     = [];
    foreach ($addresses as $a) {
      if ($address !== '' && strtolower((string) $a['email']) === $address) {
        array_unshift($ids, (int) $a['id']);
      }
      else {
        $ids[] = (int) $a['id'];
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * OAuth may only be used against a host of the token's own provider, with the encryption that
   * belongs to the port.
   *
   * @return true|PEAR_Error
   */
  public static function checkOauthTarget(string $provider, array $target) {
    $hosts = self::OAUTH_PROFILES[$provider] ?? NULL;
    if (!$hosts) {
      return PEAR::raiseError('smtprelay: OAuth provider "' . $provider . '" has no relay profile in this extension', self::CONFIG);
    }
    $ports = $hosts[$target['host']] ?? NULL;
    if (!$ports) {
      return PEAR::raiseError('smtprelay: with OAuth (' . $provider . ') the relay host must be one of: ' . implode(', ', array_keys($hosts)), self::CONFIG);
    }
    if (($ports[$target['port']] ?? NULL) !== $target['security']) {
      $allowed = [];
      foreach ($ports as $p => $sec) {
        $allowed[] = "$p/$sec";
      }
      return PEAR::raiseError('smtprelay: with OAuth on ' . $target['host'] . ' use port/encryption ' . implode(' or ', $allowed), self::CONFIG);
    }
    return TRUE;
  }

  /**
   * A syntactically valid host name or a public IP address; names must resolve to public addresses
   * only (unless $allowLocal). Both the resolver (getaddrinfo, includes /etc/hosts) and DNS are asked,
   * and every address must pass.
   *
   * @return string[]|PEAR_Error The checked addresses.
   */
  public static function checkHost(string $host, bool $allowLocal = FALSE) {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      $ips = [$host];
    }
    else {
      if (!self::isHostname($host)) {
        return PEAR::raiseError('smtprelay: relay host is not a valid host name', self::CONFIG);
      }
      $ips = (array) @gethostbynamel($host);
      foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
        $ips[] = $r['ip'] ?? $r['ipv6'] ?? NULL;
      }
      $ips = array_values(array_unique(array_filter($ips)));
      if (!$ips) {
        return PEAR::raiseError('smtprelay: relay host does not resolve', self::CONFIG);
      }
    }
    if (!$allowLocal) {
      foreach ($ips as $ip) {
        if (!self::isPublicIp($ip)) {
          return PEAR::raiseError('smtprelay: relay host resolves to a private or reserved address', self::CONFIG);
        }
      }
    }
    return $ips;
  }

  public static function isPublicIp(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return FALSE;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $long = ip2long($ip);
      foreach (self::EXTRA_V4 as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);
        if (($long & $mask) === (ip2long($net) & $mask)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    $full = strtolower((string) inet_ntop(inet_pton($ip)));
    foreach (self::EXTRA_V6 as $prefix) {
      if (strpos($full, $prefix) === 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * EHLO name: the setting, or the domain of the sender; must be a valid host name either way.
   *
   * @return string|PEAR_Error
   */
  public static function helo(string $from) {
    $helo = strtolower(trim((string) \Civi::settings()->get('smtprelay_helo')));
    if ($helo === '') {
      $address = self::address($from);
      $helo    = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    }
    if (!self::isHostname($helo)) {
      return PEAR::raiseError('smtprelay: no valid EHLO name (set one, or use a sender address with a domain)', self::CONFIG);
    }
    return $helo;
  }

  public static function isHostname(string $name): bool {
    return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $name);
  }

  /**
   * Bare address from "Name <address>" or a bare address; '' when there is none.
   */
  public static function address(string $value): string {
    if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m)) {
      return strtolower($m[1]);
    }
    return preg_match('/^\s*([^<>\s"]+@[^<>\s"]+)\s*$/', $value, $m) ? strtolower($m[1]) : '';
  }

  /**
   * Text for the log without e-mail addresses.
   */
  public static function redact(string $text): string {
    return preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '<address>', $text);
  }

}
