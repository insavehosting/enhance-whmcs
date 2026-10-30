# Changelog

## 1.4.0-insave.1 - 2026-10-30

Fork of upstream 1.4.0. Upstream now contains most fixes of 1.0.2-insave.1 (profile edits only rename
the linked organisation, organisations are created only by Create, Terminate never guesses, API errors
keep the stored links, passwords and SSO links masked in the module log, daily sync fixed). Kept from
the fork:

- **Your welcome e-mail plus Enhance's password link.** The module sends no e-mail of its own and
  creates no "Enhance Panel Access" templates; the product welcome e-mail carries the panel address and
  login (`{$service_username}`, the owner's e-mail). No password is stored or e-mailed: the password
  WHMCS generated at order time is cleared from the service. After a successful Create, a login this
  module created gets Enhance's password-setting link, once — also when an earlier attempt failed after
  creating the login; a later plan of the same client gets none. Upstream sent its link while linking
  the owner, so a Create that failed after that point never sent it on retry.
  See [docs/welcome-email-template.md](docs/welcome-email-template.md).
- **"Send password reset link"** (service module command, was "Resend panel access") only asks Enhance
  to mail the owner a password-setting link.
- **Client SSO only for Active services.** The client area refuses the panel login for Suspended,
  Pending or Terminated services; admins are not restricted.
- **Terminate:** a stored subscription that Enhance no longer has (404) counts as removed: the link is
  cleared and Terminate succeeds instead of failing.
- **Linking by owner e-mail is off by default.** On Create an existing organisation owned by the
  client's e-mail is linked only with `ENHANCE_AUTO_LINK_BY_EMAIL` in `config.php`; otherwise a new
  organisation is created (an existing login with that e-mail becomes its owner).
- Local test stand: `tests/stand/run.sh` (fake Enhance API over HTTPS, WHMCS stand-ins on SQLite).

## 1.4.0 - 2026-10-29

### Client area
- Direct login buttons per website: **webmail** (Roundcube, per mailbox), **phpMyAdmin** (per database)
  and **WordPress admin** (the app's default user, otherwise the first administrator). Links are
  created only when the client clicks, after checking that the website, mailbox, database or app
  belongs to the service.
- **SSL certificate** of the primary domain with expiry date and days left (highlighted from 14 days).
- **Recent backups** (last 5) with date, type, size and status. Optional **restore** by the client
  (product option, off by default): POST + CSRF token, account owner only, explicit confirmation,
  successful backups only, optional mailboxes. Restores are written to the activity log.
- Every website of the service is listed (1 or 1000), 5 per page, with a domain search; tools are loaded
  only for the websites of the page shown (one API call for the whole list) and never while the
  subscription is suspended. `ENHANCE_CLIENTAREA_WEBSITES_PER_PAGE` in `config.php`.
- Backups show date and time, and the size of the backed up content (files + databases + emails) in
  B/KB/MB/GB instead of the incremental delta rounded to "0 MB"; empty when Enhance reports 0;
  `ENHANCE_CLIENTAREA_BACKUP_SIZE` hides it. Incremental backups with nothing to copy are not shown as failed; "no SSL certificate" is
  only shown when Enhance confirms it; WordPress installed in the website root (`public_html`) shows no
  path; WordPress brand icon fixed for Font Awesome 5.
- Users of the client account need the WHMCS "Single Sign-On" (`productsso`) permission for the
  login buttons; restores are reserved to the account owner.

### Products
- **Server group** option (configoption3, loaded from Enhance): where new websites are created.
- **Client backup restore** option (configoption4).
- The service **username** shows the Enhance login email (the organisation owner).

### Admin
- **Last module action** per service in the service tab (action, result, date) and the **last error**
  with its message, kept until a newer error.
- **Link / repair subscription** in the service tab: choose any subscription of the client's
  organisation (subscriptions linked to other services are shown disabled), or remove the link.
  Applied when the service is saved, under the per-client lock, after validating the subscription.
- Logging in to the panel as the client from the admin service page is written to the activity log;
  admins see the technical reason when it fails.

### Enhance Manager (settings)
- The importer addon is now **Enhance Manager** (same folder and tables) with a new **Settings** tab:
  colours with live preview, language, visible client area sections, websites per page, backups shown,
  cache time and a global backup restore switch. Hidden sections are not requested from Enhance.
- Settings are stored in `mod_enhance_settings`, so updates keep them; `config.php` no longer sets
  defaults and only holds optional overrides, shown as locked in the Settings tab.

### WHMCS integration
- `whmcs.json` for the server module and the importer addon: name, description, features, authors and
  support links in WHMCS > Apps & Integrations, with the module version in the description.
- Module version shown in the importer title, the admin service tab and the client profile panel.
- Messages and documentation point to the exact token page: `https://<panel>/settings/access-tokens`.

### Quality
- Importer split into `lib/packages.php`, `lib/services.php` and `lib/sync.php`.
- 39 API calls, all checked against the official OpenAPI specification (orchd 12.26.3).

## 1.3.1 - 2026-10-29
- Per-client database lock (MySQL `GET_LOCK`) for Create, service import and organisation linking.
- Logins looked up in the master organisation's realm; HTTP 409 on login creation reuses the login.
- Client profile panel: organisation search (name or UUID) instead of listing every customer.
- Importer Existing Services tab: 20 clients per page, 3 API calls per client.
- Owner created directly with the `Owner` role (Enhance refuses `PUT /owner` and the `Owner` role on
  member updates to access tokens); owner-less organisations repaired; existing owners never replaced.
- Migration of the official Enhance module's data (`enhOrgId`, `enhanceSubscriptionId`, `subscriptionId`).

## 1.3.0 - 2026-10-28
- Authentication: HTML entities decoded, spaces/line breaks/`Bearer ` removed from the token, Password
  field used when Access Hash is empty, redirects and HTML pages treated as errors, step-by-step
  Test Connection (public `/version`, 401/403/404, token in the other field, first five characters).
- English base with a complete European Portuguese translation.
- Client profile panel buttons no longer submit the whole profile form (nested forms).
- Profile edits only rename the linked organisation.
- Password recovery uses only `PUT /login/password-recovery`.
- Importer: billing cycles in the format `AddOrder` expects.
- Web-accessible debug log removed.
