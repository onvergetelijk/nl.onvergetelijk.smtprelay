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
- **Relay** - host, port, encryption (STARTTLS / implicit TLS / none), EHLO name (default: the sender
  domain; Google rejects `localhost`).
- **Authentication**
  - *None* - the relay trusts your server's IP address;
  - *Username and password* - the password may be stored encrypted with CiviCRM's `crypto.token`;
  - *OAuth* - reuses the system token of the
    [OAuth Outgoing Mail](https://civicrm.org/extensions/oauth-outgoing-mail) extension (XOAUTH2), so no
    password is stored. Tested with the Google Workspace SMTP relay.
- **Envelope sender** - optional rewrite of `Return-Path`, for when the VERP addresses of your domain
  have no mailbox. `X-CiviMail-Bounce` stays, so CiviCRM still recognises bounces.
- **On failure** - *Refuse* (default: CiviMail retries in a later run) or *Fall back* to the normal
  route. Refuse is safest when the reason for the relay is a quota on the normal route.

**Test without sending:** `civicrm/admin/setting/smtprelay/test` connects, authenticates and issues
`MAIL FROM` and `RCPT TO`, then `RSET` - it never sends `DATA`.

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
| `RouterTest` | the router as a mailer with fake transports: hook in/out, envelope, refuse vs fall back, no host |
| `HookOrderTest` | the router stays the outermost mailer when another extension wraps the mailer (as OAuth Outgoing Mail does) |
| `WireTest` | what reaches a relay, measured with a local SMTP sink (`tests/phpunit/fixtures/smtp-sink.php`): EHLO, MAIL FROM, no AUTH, VERP header |
| `CredentialsTest` | transport parameters, password (plain and crypto.token), OAuth without token |
| `SettingsEdgeTest` | unknown mode = off, mailing ID list parsing, connection test without host |
| `MenuTest` | one translatable Administer > System Settings link |
| `TranslationTest` | every string in the code is in the .pot and translated in each .po and .mo |

Not automated: the OAuth happy path against a real relay (use the connection test page).

```bash
phpunit9 --configuration=phpunit.xml.dist
```

## License

AGPL-3.0. Developed by Stichting Onvergetelijke Zomerkampen (NL).
