# SMTP Relay (nl.onvergetelijk.smtprelay)

Send **large CiviMail mailings through a second SMTP route** - an SMTP relay or a bulk provider -
while **all other mail keeps using your normal outbound route**: receipts, scheduled reminders,
CiviRules, webform mail, small mailings and test mails.

## Why

CiviCRM has one outbound mail route. If that route has a daily limit - for example a Google Workspace
mailbox via `smtp.gmail.com` (2,000 messages per rolling 24 hours, after which the mailbox cannot send
anything for up to a day) - one mailing to your whole constituency can block your transactional mail.

Many providers offer a separate relay with its own, higher limit (for Google Workspace:
`smtp-relay.gmail.com`, 10,000 messages per day). This extension sends only the mailings you choose
through that relay, per message, without touching anything else.

## What it does

The extension wraps CiviCRM's mailer (via `hook_civicrm_alterMailer`, as the outermost layer, after
extensions such as OAuth Outgoing Mail). For every message it decides:

| Message | Route |
|---|---|
| CiviMail mailing with at least *N* recipients, or a mailing you listed | relay |
| CiviMail mailing below *N*, CiviMail test mail | normal route |
| Scheduled reminders, CiviRules, receipts, webform and other single mails | normal route, always |

CiviMail bulk mail is recognised by its VERP header `X-CiviMail-Bounce` (which carries the job ID)
plus `Precedence: bulk`. The recipient count comes from `civicrm_mailing_recipients`.

## Settings

**Administer > System Settings > SMTP Relay** (`civicrm/admin/setting/smtprelay`):

- **Mode** - *Off* (default: the extension does nothing), *Shadow* (decide and log, send everything
  the normal way), *On*.
- **Rules** - minimum number of recipients (default 1000, 0 = off) and/or a list of mailing IDs.
- **Relay** - host, port (25, 465, 587 or 2525), encryption (STARTTLS / implicit TLS / none), EHLO
  name (default: the sender domain; Google rejects `localhost`). The host must resolve to public
  addresses only.
- **Authentication**
  - *None* - the relay trusts your server's IP address;
  - *Username and password* - stored encrypted with CiviCRM's `crypto.token` (key tag `CRED`, like
    core's SMTP password; without `CIVICRM_CRED_KEYS` in `civicrm.settings.php` the value stays readable)
    and never shown again; leave the field empty to keep it. After changing host, port or username
    the form asks for the password again, so a stored password cannot be redirected to another server;
  - *OAuth* - reuses the system token of the
    [OAuth Outgoing Mail](https://civicrm.org/extensions/oauth-outgoing-mail) extension (XOAUTH2), so no
    password is stored. The token only goes to the relay of its own provider (see Security).
- **Envelope sender** - optional rewrite of `Return-Path`, for when the VERP addresses of your domain
  have no mailbox. `X-CiviMail-Bounce` stays, so CiviCRM still recognises bounces.
- **On failure** - *Refuse* (default: the mailing pauses and CiviMail resumes it in a later run) or
  *Fall back* to the normal route. Refuse is safest when the reason for the relay is a quota on the
  normal route.

**Test without sending:** `civicrm/admin/setting/smtprelay/test` shows a form; on *Run test* it connects,
starts TLS, authenticates and issues `MAIL FROM` and `RCPT TO`, then `RSET` - it never sends `DATA`.

## Security

Since 1.1 (security review of 15-11-2026; 1.0.0 should not be used with OAuth):

- **OAuth token only to its own provider.** With Authentication = OAuth the relay must be one of the
  hosts fixed in code for the token's provider (`CRM_Smtprelay_Policy::OAUTH_PROFILES`; Google:
  `smtp-relay.gmail.com` or `smtp.gmail.com`, 587 with STARTTLS or 465 with implicit TLS). This is
  checked before a token is looked up or refreshed, for mailings and for the connection test alike.
  Choosing OAuth, or changing its host, port or encryption on the settings form, requires the
  permission *manage OAuth client secrets*. A Google token with scope `https://mail.google.com/` gives
  full access to the mailbox; treat whoever may change these settings accordingly.
- **Credentials only over TLS.** STARTTLS is performed first and must succeed before `AUTH`;
  Net_SMTP on its own would log in without TLS when a server (or a man in the middle) does not offer
  STARTTLS. Encryption *none* is only accepted with Authentication *None*. Implicit TLS verifies the
  server certificate (PHP default).
- **Relay problems never bounce recipients.** Only a permanent refusal of the address or message is
  recorded as a bounce: `5.1.x`, `5.2.x`, `5.3.4`, `5.6.x`, or a `5xx` on `RCPT`/`DATA` without an
  enhanced status code. Connection, TLS, login, relay policy (`5.7.x`), quota (`5.4.5`) and temporary
  (`4xx`) errors are handed to CiviMail as temporary: it retries, and after five in a row it stops the
  run (core behaviour, `CRM_Utils_System::civiExit()`) and continues in the next cron run.
- **Configuration errors pause the mailing.** A relay that cannot be used with the current settings
  (no host, a host or port outside the policy, OAuth without a token, an unreadable password) pauses
  the mailing, like the Pause button in CiviMail, so later cron runs skip it instead of stopping on it.
  Fix the settings (or set the mode to *Off*) and resume the mailing in CiviMail. Watch the log channel
  `smtprelay`.
- **No duplicate delivery via fallback.** *Fall back* only happens when the relay certainly did not take
  the message (an error before `DATA`); a refused address is never retried via the normal route. A
  CiviMail retry after a broken connection during `DATA` can still deliver a message twice (core
  behaviour for any SMTP route).
- **Connection test** is a standard CiviCRM form: it runs only on submit with the session-bound
  `qfKey` (CSRF) and a valid recipient address, and the relay host may not resolve to private, loopback, reserved, carrier-grade NAT or
  IPv4-translating addresses (checked via the resolver including `/etc/hosts`, and via DNS). The
  connection goes to the checked IPv4 address, with the certificate verified against the host name, so
  a second DNS answer cannot redirect it (DNS rebinding). Residual: the server's own public address is
  not refused.
- **Permissions.** Settings page, menu link and connection test all require *administer CiviCRM* or
  *administer smtprelay*.
- **Logs** contain no e-mail addresses or secrets.

**Rotating the OAuth token** (if a token may have reached another server): in Google, revoke the
grant of the CiviCRM OAuth client for that mailbox (Google Account > Security > Third-party access,
or Admin console > Security > API controls), then reconnect the mailbox in OAuth Outgoing Mail
(Administer > CiviMail > OAuth / Outbound Mail) so a new refresh token is stored. Access tokens expire
after an hour; revoking the grant also invalidates the refresh token.

## Google Workspace example

1. Admin console > Apps > Google Workspace > Gmail > Routing > **SMTP relay service**: add a rule with
   *Allowed senders: Only addresses in my domains*, *Require SMTP Authentication*, *Require TLS*.
2. Install [OAuth Outgoing Mail](https://civicrm.org/extensions/oauth-outgoing-mail) and connect the
   sending mailbox (its token needs the scope `https://mail.google.com/`).
3. SMTP Relay settings: host `smtp-relay.gmail.com`, port `587`, STARTTLS, Authentication *OAuth*.
4. Run the connection test; expect `235 2.7.0 Accepted` and `250` for MAIL FROM and RCPT TO.
5. Mode *Shadow* during a real mailing, check the log (channel `smtprelay`), then *On*.

Notes from production use: Google relay mail does not appear in the sender's *Sent* folder (unless
*Comprehensive mail storage* is on); the relay announces `LIMITS MAILMAX=1000` per connection; with
the username set to the display name instead of the mailbox address, Google answers `535 BadCredentials`.

## Compatibility and limits

- CiviCRM 6.0 or later (uses the `setting-admin` mixin, available since 5.68). OAuth mode needs
  OAuth Outgoing Mail (CiviCRM 6.2 or later).
- Only CiviMail bulk mail can be routed; without VERP the size rule cannot see the job and the message
  stays on the normal route.
- Combining with another extension that replaces the mailer (for example an SMTP router per sender
  address, SES or SparkPost) depends on hook order; test that combination.
- Not (yet): routing on a daily counter of the normal route.

## Translations

Dutch (`nl_NL`) is included in `l10n/nl_NL/LC_MESSAGES/smtprelay.mo`; `l10n/smtprelay.pot` is the template for
other languages. With `CIVICRM_GETTEXT_NATIVE` the operating system needs the locale (for Dutch:
`nl_NL.UTF-8` in `/etc/locale.gen`, then `locale-gen`), otherwise every CiviCRM string stays English. Native gettext also keeps a loaded `.mo` in memory per
PHP-FPM worker: after updating a translation, restart PHP-FPM (a cache flush is not enough).

## Development

Tests (end-to-end against a development site; nothing is delivered):

| Test | Covers |
|---|---|
| `DecisionTest` | the routing decision: CiviMail bulk only, size rule, listed mailings, test mails, reminders, shadow, off |
| `RouterTest` | the router as a mailer with fake transports: hook in/out, envelope, refuse vs fall back, no fallback after DATA, which errors bounce and which pause the job (checked against FlexMailer's own classifier), disconnect |
| `HookOrderTest` | the router stays the outermost mailer when another extension wraps the mailer (as OAuth Outgoing Mail does) |
| `WireTest` | what reaches a relay, measured with a local SMTP sink (`tests/phpunit/fixtures/smtp-sink.php`): EHLO, MAIL FROM, no AUTH, VERP header; no AUTH when the server does not offer STARTTLS |
| `CredentialsTest` | transport parameters, password stored encrypted, OAuth refused for any host outside the provider profile (before a token is looked up), exact sender match |
| `SettingsEdgeTest` | unknown mode = off, mailing ID list parsing, connection test without host; fail-closed on unknown encryption/authentication, credentials without TLS, ports, private hosts, EHLO |
| `MenuTest` | one translatable Administer > System Settings link |
| `TranslationTest` | every string in the code is in the .pot and translated in each .po and .mo |

Not automated: the OAuth happy path against a real relay (use the connection test page), and the
settings form and connection test page in a browser (checked with headless Chromium for 1.1.1: opening
the test runs nothing; an invalid address or a forged `qfKey` runs nothing; the password field stays
empty; saving with an empty field keeps the stored password; changing the host with an empty field
asks for the password again; a port or OAuth host outside the policy is not saved).

Only one test needs DNS (`CredentialsTest::testHostNameIsPinnedToCheckedAddress`, skipped without it);
the others use IP literals or the network-free rules (`CRM_Smtprelay_Policy::violations()`).

```bash
phpunit9 --configuration=phpunit.xml.dist
civilint $(git ls-files '*.php')   # buildkit: Drupal coding standard, clean since 1.1.1
```

## License

AGPL-3.0. Developed by Stichting Onvergetelijke Zomerkampen (NL).
