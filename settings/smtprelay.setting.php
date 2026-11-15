<?php
/**
 * Settings for SMTP Relay. They appear automatically on Administer > System Settings > SMTP Relay
 * (civicrm/admin/setting/smtprelay) through the setting-admin mixin, because group = 'smtprelay'.
 *
 * Safe defaults: mode 'off' and no relay host. Installing the extension changes nothing.
 */
$base = [
  'group_name' => 'SMTP Relay Preferences',
  'group'      => 'smtprelay',
  'is_domain'  => 1,
  'is_contact' => 0,
  'add'        => '1.0',
];

return [
  'smtprelay_mode' => $base + [
    'name'        => 'smtprelay_mode',
    'type'        => 'String',
    'default'     => 'off',
    'html_type'   => 'select',
    'options'     => [
      'off'    => 'Off - nothing is routed, the relay is not even hooked in',
      'shadow' => 'Shadow - decide and log what would go via the relay, but send everything the normal way',
      'on'     => 'On - matching mailings are sent via the relay',
    ],
    'title'       => 'Mode',
    'description' => 'Start with Shadow during a real mailing and check the log (channel smtprelay) before switching to On. Test the relay without sending anything at civicrm/admin/setting/smtprelay/test.',
    'settings_pages' => ['smtprelay' => ['weight' => 10]],
  ],
  'smtprelay_min_recipients' => $base + [
    'name'        => 'smtprelay_min_recipients',
    'type'        => 'Integer',
    'default'     => 1000,
    'html_type'   => 'text',
    'title'       => 'Rule: CiviMail mailings with at least this many recipients',
    'description' => 'A non-test CiviMail mailing with at least this many recipients goes via the relay. 0 switches this rule off. Test mailings, scheduled reminders, CiviRules and other single mails never match: only CiviMail bulk mail (VERP header X-CiviMail-Bounce plus Precedence: bulk) can be routed.',
    'settings_pages' => ['smtprelay' => ['weight' => 20]],
  ],
  'smtprelay_mailing_ids' => $base + [
    'name'        => 'smtprelay_mailing_ids',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'text',
    'title'       => 'Rule: always these mailing IDs',
    'description' => 'Comma-separated CiviMail mailing IDs that always go via the relay, whatever their size (for example a single large campaign). Leave empty to use only the size rule.',
    'settings_pages' => ['smtprelay' => ['weight' => 30]],
  ],
  'smtprelay_host' => $base + [
    'name'        => 'smtprelay_host',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'text',
    'title'       => 'Relay host',
    'description' => 'For example smtp-relay.gmail.com (Google Workspace SMTP relay service) or the SMTP host of a bulk provider. Empty = relay not configured: matching mailings are refused (or fall back, see On failure).',
    'settings_pages' => ['smtprelay' => ['weight' => 40]],
  ],
  'smtprelay_port' => $base + [
    'name'        => 'smtprelay_port',
    'type'        => 'Integer',
    'default'     => 587,
    'html_type'   => 'text',
    'title'       => 'Relay port',
    'settings_pages' => ['smtprelay' => ['weight' => 50]],
  ],
  'smtprelay_security' => $base + [
    'name'        => 'smtprelay_security',
    'type'        => 'String',
    'default'     => 'starttls',
    'html_type'   => 'select',
    'options'     => [
      'starttls' => 'STARTTLS (usually port 587)',
      'ssl'      => 'Implicit TLS (usually port 465)',
      'none'     => 'None (local testing only)',
    ],
    'title'       => 'Encryption',
    'settings_pages' => ['smtprelay' => ['weight' => 60]],
  ],
  'smtprelay_helo' => $base + [
    'name'        => 'smtprelay_helo',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'text',
    'title'       => 'EHLO name',
    'description' => 'Name sent in EHLO. Empty = the domain of the sender address. Google rejects "localhost".',
    'settings_pages' => ['smtprelay' => ['weight' => 70]],
  ],
  'smtprelay_auth' => $base + [
    'name'        => 'smtprelay_auth',
    'type'        => 'String',
    'default'     => 'none',
    'html_type'   => 'select',
    'options'     => [
      'none'     => 'None - the relay trusts the server IP address',
      'password' => 'Username and password',
      'oauth'    => 'OAuth token of oauth_outgoing_mail (XOAUTH2)',
    ],
    'title'       => 'Authentication',
    'description' => 'OAuth reuses the system token that the OAuth Outgoing Mail extension keeps for the sender address; no password is stored. Tested with the Google Workspace SMTP relay (smtp-relay.gmail.com accepts XOAUTH2 with the username set to the mailbox address).',
    'settings_pages' => ['smtprelay' => ['weight' => 80]],
  ],
  'smtprelay_username' => $base + [
    'name'        => 'smtprelay_username',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'text',
    'title'       => 'Username',
    'description' => 'Only for Authentication = Username and password. With OAuth the username comes from the token (the mailbox address).',
    'settings_pages' => ['smtprelay' => ['weight' => 90]],
  ],
  'smtprelay_password' => $base + [
    'name'        => 'smtprelay_password',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'password',
    'title'       => 'Password',
    'description' => 'Only for Authentication = Username and password. Values encrypted with CiviCRM crypto.token are decrypted on use; plain values work too but are stored as entered.',
    'settings_pages' => ['smtprelay' => ['weight' => 100]],
  ],
  'smtprelay_envelope_from' => $base + [
    'name'        => 'smtprelay_envelope_from',
    'type'        => 'String',
    'default'     => '',
    'html_type'   => 'text',
    'title'       => 'Envelope sender (bounces)',
    'description' => 'Rewrites the envelope sender (Return-Path) of relayed mail. Empty = keep the CiviMail VERP address. Set it to a monitored mailbox (for example the one your bounce processing reads) if the VERP addresses of your domain have no mailbox. The X-CiviMail-Bounce header stays, so CiviCRM still recognises the bounce.',
    'settings_pages' => ['smtprelay' => ['weight' => 110]],
  ],
  'smtprelay_on_failure' => $base + [
    'name'        => 'smtprelay_on_failure',
    'type'        => 'String',
    'default'     => 'refuse',
    'html_type'   => 'select',
    'options'     => [
      'refuse'   => 'Refuse - return the error; CiviMail retries in a later run',
      'fallback' => 'Fall back - send that message via the normal route',
    ],
    'title'       => 'On failure of the relay',
    'description' => 'Refuse is safest when the reason for the relay is a daily quota on the normal route: falling back would use up exactly that quota.',
    'settings_pages' => ['smtprelay' => ['weight' => 120]],
  ],
];
