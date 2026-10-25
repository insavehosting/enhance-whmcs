## [1.0.2] - 2026-05-16

### Fixed
- **Critical** `createWebsite`: `subscriptionId` enviado como string em vez de `int` causava o erro
  `Json deserialize error: invalid type: string "X", expected i32` na API do Enhance — corrigido
  com cast explícito `(int)` antes de serializar o JSON.
- Typo `YespleMode` → `SimpleMode` em `enhance_ConfigOptions()` (a chave inválida era ignorada
  silenciosamente pelo WHMCS, o que desactivava o modo de selecção simples do dropdown de planos).
- Chave de config `'plano'` renomeada para `'plan'` para consistência com os padrões WHMCS.

### Added
- `composer.json` adicionado ao módulo (o README mencionava `composer install` mas o ficheiro
  não existia — embora o módulo não tenha dependências externas, o ficheiro é necessário para
  não causar confusão).
- Suporte a **Server Groups**: função `enhance_GetServerGroups()` adicionada, permitindo que o
  WHMCS associe produtos a grupos de servidores Enhance específicos.
- README actualizado para clarificar que `composer install` é opcional (sem dependências externas).

# Changelog

## Version 1.0.2-insave.1 (fork of webdighost v1.0.2)

### Fixed

- ClientEdit no longer creates Enhance organisations/logins or sends credential e-mails.
  It only renames an already linked organisation, only when first/last/company name changed,
  and only for clients with an Active/Suspended Enhance service.
- Organisations are created only by CreateAccount. Suspend, Unsuspend, Terminate, ChangePackage
  and SSO work with the linked organisation and fail instead of creating/relinking one.
- An Enhance API error other than 404 no longer drops the stored EnhanceOrgId / SUBSCRIPTION_ID
  (previously a timeout or 5xx could relink the client or create a duplicate organisation).
- Automatic linking by organisation name removed; linking by owner e-mail is off by default
  (`ENHANCE_AUTO_LINK_BY_EMAIL` in config.php) — it allowed handing another customer's organisation
  or login to a client who changed their e-mail.
- Terminate never guesses: without SUBSCRIPTION_ID it refuses; a 404 subscription counts as removed.
  Subscription guessing (suspend/unsuspend/change package) skips subscriptions mapped to other services.
- includes/hooks/enhance.php: removed the DailyCronJob that called
  enhance_importer_run_daily_sync(true, 0) and threw a TypeError on every daily cron.
- includes/hooks/enhance_importer_daily_sync.php: wrong require path (includes/modules/...) that
  caused a fatal error on every WHMCS page; files are now loaded lazily inside the hook.
- Daily sync only mirrors Active <-> Suspended and never revives Terminated/Cancelled services.
- New org: if the Owner membership cannot be created, the org is rolled back.
- Module log: generated login passwords are masked, one-time SSO URLs are redacted.
- Client SSO is refused for non-Active services.
- Admin profile widget: actions use the org linked to the client, never an org ID from the request;
  Delete Org is refused while the client has Active/Suspended Enhance services; Link Org validates
  the UUID, checks the org exists and is not linked to another client; no API calls for clients
  without Enhance hosting unless the admin opens the link form; output escaping.

## Version 1.0.1

### Fixed

- Final client SSO implementation corrected.
- Removed invalid fallback endpoints:
  - `/members/{id}/ssoToken`
  - `/members/{id}/login`
- Enforced the official Enhance SSO endpoint:
  - `/orgs/{orgId}/members/{memberId}/sso`
- Fixed client area **Login to Panel** authentication failures.
- Confirmed production compatibility with live Enhance environments.

### Notes

This version should be considered the official GitHub release for public use.

---

## Version 1.0.0

Initial stable public release

### Added

- WHMCS server module for Enhance Control Panel
- Client Single Sign-On (SSO)
- Direct admin login to Enhance panel
- Automatic EnhanceOrgId creation
- Automatic SUBSCRIPTION_ID creation
- Client synchronization
- Package provisioning
- Robust suspend / unsuspend actions
- Safe termination modes
- Package importer addon
- Manual package selection before import
- Existing service importer
- Import using EnhanceOrgId as primary match
- Owner email fallback detection
- Primary domain auto-detection
- Multiple domain dropdown selection
- Daily synchronization system
- Manual sync execution
- Duplicate prevention logic
- CSRF protection for admin actions
- Admin client profile Enhance widget
- Package mapping between Enhance and WHMCS
- Import validation and logging
- Automatic database table creation
- Better product display inside importer
- Priority ordering for main domains over technical subdomains

### Improved

- Admin login behavior now uses direct panel access
- Service import logic now uses EnhanceOrgId instead of email-first matching
- Domain detection expanded to include full account domains
- Importer UI improved with clearer product information
- Technical resource summaries made readable
- Existing product detection improved

### Fixed

- Broken admin SSO logic
- Missing importer tabs
- SQL missing table error
- Incorrect service matching by owner email
- Raw API output shown in technical summary
- Nested addon folder installation issue
- Import failures caused by missing import log tables

### Notes

This release replaces previous internal development builds and should be considered the first stable production release.
