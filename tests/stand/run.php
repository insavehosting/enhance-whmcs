<?php
// Scenarios for the fork's behaviour on top of upstream: run through run.sh,
// which starts fake_enhance.py and passes curl.cainfo for its certificate.

require __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;

const HOST   = 'localhost:8443';
const MASTER = '00000000-0000-4000-8000-000000000001';
const TOKEN  = 'stand-api-token';

$GLOBALS['failed'] = 0;

function check(string $name, bool $ok, $detail = null): void
{
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : '  -- ' . json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $GLOBALS['failed']++;
}

function fake(string $path, array $body = []): array
{
    $ch = curl_init('https://' . HOST . $path);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']];
    if ($path !== '/__state') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $state = json_decode((string) $raw, true);
    if (!is_array($state)) {
        fwrite(STDERR, "fake Enhance did not answer {$path}: " . curl_error($ch) . "\n");
        exit(2);
    }
    return $state;
}

function requests(?string $method = null, ?string $pathRegex = null): array
{
    return array_values(array_filter(fake('/__state')['requests'], function ($r) use ($method, $pathRegex) {
        return ($method === null || $r['method'] === $method) && ($pathRegex === null || preg_match('#^' . $pathRegex . '$#', $r['path']));
    }));
}

function client(int $id, string $email, string $first = 'Test', string $last = 'Client', string $company = ''): void
{
    Capsule::table('tblclients')->insert(['id' => $id, 'firstname' => $first, 'lastname' => $last, 'companyname' => $company, 'email' => $email, 'status' => 'Active']);
}

function service(int $id, int $clientId, string $domain, string $status = 'Pending', int $packageId = 1): void
{
    // WHMCS puts a random password and a generated username on new hosting services.
    Capsule::table('tblhosting')->insert(['id' => $id, 'userid' => $clientId, 'packageid' => $packageId, 'server' => 1, 'domain' => $domain,
        'domainstatus' => $status, 'username' => 'whmcs' . $id, 'password' => encrypt('Random-WHMCS-' . $id)]);
}

function params(int $serviceId, array $over = []): array
{
    $h = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    $c = Capsule::table('tblclients')->where('id', $h->userid)->first();
    $p = Capsule::table('tblproducts')->where('id', $h->packageid)->first();
    return array_merge([
        'serviceid' => $serviceId, 'packageid' => (int) $h->packageid, 'userid' => (int) $h->userid, 'domain' => $h->domain, 'status' => $h->domainstatus,
        'configoption1' => $p->configoption1, 'configoption2' => $p->configoption2, 'configoption3' => $p->configoption3, 'configoption4' => $p->configoption4,
        'serverid' => 1, 'serverhostname' => HOST, 'serverip' => '', 'serverusername' => MASTER, 'serverpassword' => '', 'serveraccesshash' => TOKEN,
        'clientsdetails' => ['userid' => (int) $c->id, 'id' => (int) $c->id, 'firstname' => $c->firstname, 'lastname' => $c->lastname,
            'fullname' => trim($c->firstname . ' ' . $c->lastname), 'email' => $c->email, 'companyname' => $c->companyname],
    ], $over);
}

function links_to(string $email): int
{
    return count(array_filter(fake('/__state')['recoveries'], fn($e) => strcasecmp($e, $email) === 0));
}

function hosting(int $serviceId): object
{
    return Capsule::table('tblhosting')->where('id', $serviceId)->first();
}

function link_of(int $serviceId): ?object
{
    return Capsule::table('mod_enhance_services')->where('service_id', $serviceId)->first();
}

function org_of(int $clientId): ?string
{
    return Capsule::table('mod_enhance_clients')->where('client_id', $clientId)->value('org_id');
}

function create(int $serviceId): string
{
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Pending']);
    $result = enhance_CreateAccount(params($serviceId));
    if ($result === 'success') {
        Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => 'Active']);
    }
    return $result;
}

// ---------------------------------------------------------------------------
fake('/__reset', ['master' => MASTER]);
// WHMCS keeps the access hash encrypted in tblservers and passes it decrypted to module calls.
Capsule::table('tblservers')->insert(['id' => 1, 'name' => 'Enhance', 'type' => 'enhance', 'hostname' => HOST, 'username' => MASTER, 'accesshash' => encrypt(TOKEN), 'active' => 1]);
Capsule::table('tblproducts')->insert(['id' => 1, 'name' => 'Shared Start', 'servertype' => 'enhance', 'configoption1' => '2', 'configoption2' => 'soft']);
Capsule::table('tblproducts')->insert(['id' => 2, 'name' => 'VPS', 'servertype' => 'solusvm']);

// Legacy data of fork 1.0.2-insave.1 and a field of another module with a generic name.
$legacyOrg = '11111111-1111-4111-8111-111111111111';
fake('/__seed', ['customers' => [['id' => $legacyOrg, 'name' => 'Legacy Co']], 'subscriptions' => [$legacyOrg => [['id' => 7, 'planId' => 2, 'friendlyName' => 'WHMCS #90']]]]);
client(9, 'legacy@example.test');
service(90, 9, 'legacy.example', 'Active');
Capsule::table('tblhosting')->where('id', 90)->update(['subscriptionid' => 'wfp_recurring_123']);
service(91, 9, '', 'Active', 2);
Capsule::table('tblcustomfields')->insert(['id' => 1, 'type' => 'client', 'relid' => 0, 'fieldname' => 'EnhanceOrgId', 'fieldtype' => 'text', 'adminonly' => 'on']);
Capsule::table('tblcustomfields')->insert(['id' => 2, 'type' => 'product', 'relid' => 1, 'fieldname' => 'SUBSCRIPTION_ID', 'fieldtype' => 'text']);
Capsule::table('tblcustomfields')->insert(['id' => 3, 'type' => 'product', 'relid' => 2, 'fieldname' => 'subscriptionId', 'fieldtype' => 'text']);
Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => 1, 'relid' => 9, 'value' => $legacyOrg]);
Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => 2, 'relid' => 90, 'value' => '7']);
Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => 3, 'relid' => 91, 'value' => '555']);

require ROOTDIR . '/modules/servers/enhance/enhance.php';
require ROOTDIR . '/includes/hooks/enhance.php';
require ROOTDIR . '/includes/hooks/enhance_importer_daily_sync.php';

// --- migration -------------------------------------------------------------
EnhanceStore::ensure();
check('migration: client org moved to mod_enhance_clients', org_of(9) === $legacyOrg, org_of(9));
check('migration: SUBSCRIPTION_ID moved to mod_enhance_services', (string) (link_of(90)->subscription_id ?? '') === '7', link_of(90));
check("migration: another module's subscriptionId field is not read", link_of(91) === null, link_of(91));
check('migration: tblhosting.subscriptionid (payment gateway) untouched', hosting(90)->subscriptionid === 'wfp_recurring_123', hosting(90)->subscriptionid);
check('no "Enhance Panel Access" e-mail templates are created', Capsule::table('tblemailtemplates')->count() === 0, Capsule::table('tblemailtemplates')->pluck('name'));

// --- S1: first service of a new client --------------------------------------
client(1, 'alice@example.test', 'Alice', 'Doe');
service(101, 1, 'alice.example');
$result = create(101);
$logins = array_values(array_filter(fake('/__state')['logins'], fn($l) => $l['email'] === 'alice@example.test'));
$h = hosting(101);
check('S1 create: success', $result === 'success', $result);
check('S1 create: one new login for the client e-mail', count($logins) === 1, $logins);
check('S1 create: no password on the service (order-time WHMCS password cleared)', $h->password === '', $h->password);
check('S1 create: service username = login e-mail', $h->username === 'alice@example.test', $h->username);
check('S1 create: generated password is masked in the module log',
    $logins && (bool) array_filter($GLOBALS['__stand']['module'], fn($m) => in_array($logins[0]['password'], (array) $m['replace'], true)));
check('S1 create: subscription and website linked', link_of(101) && link_of(101)->subscription_id && link_of(101)->website_id, link_of(101));
check('S1 create: Enhance sends one password-setting link to the new login', links_to('alice@example.test') === 1, fake('/__state')['recoveries']);
check('S1 create: no WHMCS SendEmail and no template', !array_filter($GLOBALS['__stand']['localapi'], fn($c) => $c[0] === 'SendEmail') && Capsule::table('tblemailtemplates')->count() === 0);

// --- S2: second service of the same client ----------------------------------
service(102, 1, 'alice-two.example');
$loginsBefore = count(fake('/__state')['logins']);
$result = create(102);
$h = hosting(102);
check('S2 second service: success, no new login', $result === 'success' && count(fake('/__state')['logins']) === $loginsBefore, $result);
check('S2 second service: no password on the service', $h->password === '', $h->password);
check('S2 second service: no second password-setting link', links_to('alice@example.test') === 1, fake('/__state')['recoveries']);
check('S2 second service: username = login e-mail', $h->username === 'alice@example.test', $h->username);

// --- S3: the client's e-mail already has a panel login ----------------------
fake('/__seed', ['logins' => [['id' => '33333333-3333-4333-8333-333333333333', 'email' => 'bob@example.test']]]);
client(2, 'bob@example.test', 'Bob', 'Roe');
service(201, 2, 'bob.example');
$result = create(201);
$state = fake('/__state');
$org = $state['orgs'][org_of(2)] ?? [];
check('S3 existing login: success, linked as owner of a new organisation', $result === 'success' && $org && org_of(2) !== null, $result);
check('S3 existing login: no second login created', count(array_filter($state['logins'], fn($l) => $l['email'] === 'bob@example.test')) === 1);
check('S3 existing login: no password on the service', hosting(201)->password === '', hosting(201)->password);
check('S3 existing login (not created by the module): no password-setting link', links_to('bob@example.test') === 0, fake('/__state')['recoveries']);

// --- S4: organisation owned by the e-mail exists outside WHMCS (auto-link off)
$outsideOrg = '44444444-4444-4444-8444-444444444444';
fake('/__seed', [
    'customers' => [['id' => $outsideOrg, 'name' => 'Outside', 'ownerId' => '44444444-0000-4000-8000-000000000001']],
    'logins'    => [['id' => '44444444-0000-4000-8000-0000000000aa', 'email' => 'carol@example.test']],
    'members'   => [$outsideOrg => [['id' => '44444444-0000-4000-8000-000000000001', 'loginId' => '44444444-0000-4000-8000-0000000000aa', 'roles' => ['Owner']]]],
]);
client(3, 'carol@example.test', 'Carol', 'Poe');
service(301, 3, 'carol.example');
$result = create(301);
check('S4 auto-link off: a new organisation, not the outside one', $result === 'success' && org_of(3) !== null && org_of(3) !== $outsideOrg, org_of(3));
check('S4 existing login of an outside organisation: no password-setting link', links_to('carol@example.test') === 0, fake('/__state')['recoveries']);

// --- S5: retry after a failure that follows the login creation --------------
fake('/__fail', ['method' => 'POST', 'path' => '/api/orgs/' . MASTER . '/customers/[^/]+/subscriptions', 'status' => 500, 'times' => 1]);
client(4, 'dave@example.test', 'Dave', 'Moe');
service(401, 4, 'dave.example');
$first = create(401);
$linksAfterFailure = links_to('dave@example.test');
$second = create(401);
check('S5 retry: first attempt fails, no link yet', $first !== 'success' && $linksAfterFailure === 0, [$first, $linksAfterFailure]);
check('S5 retry: second attempt succeeds and sends the link once', $second === 'success' && links_to('dave@example.test') === 1, [$second, fake('/__state')['recoveries']]);
check('S5 retry: no password on the service', hosting(401)->password === '', hosting(401)->password);

// --- S6: login created, then owner membership fails (password never stored) -
fake('/__fail', ['method' => 'POST', 'path' => '/api/orgs/[^/]+/members', 'status' => 500, 'times' => 1]);
client(5, 'erin@example.test', 'Erin', 'Loe');
service(501, 5, 'erin.example');
$first = create(501);
$second = create(501);
check('S6 interrupted login: first attempt fails', $first !== 'success', $first);
check('S6 interrupted login: retry succeeds, no password on the service', $second === 'success' && hosting(501)->password === '', [$second, hosting(501)->password]);
check('S6 interrupted login: Enhance sends the password-setting link once', links_to('erin@example.test') === 1, fake('/__state')['recoveries']);
service(502, 5, 'erin-two.example');
check('S6 later plan of the same client: no second link', create(502) === 'success' && links_to('erin@example.test') === 1, fake('/__state')['recoveries']);

// --- S7: profile edits ------------------------------------------------------
client(6, 'frank@example.test', 'Frank', 'Noe');
$before = count(requests());
run_hook('ClientEdit', ['userid' => 6]);
check('S7 profile edit, client without Enhance: no API call', count(requests()) === $before, array_slice(requests(), $before));
Capsule::table('tblclients')->where('id', 1)->update(['email' => 'alice.new@example.test']);
$before = count(requests());
run_hook('ClientEdit', ['userid' => 1]);
$calls = array_slice(requests(), $before);
check('S7 e-mail change: nothing created or renamed', !array_filter($calls, fn($r) => $r['method'] !== 'GET'), $calls);
Capsule::table('tblclients')->where('id', 1)->update(['firstname' => 'Alicia']);
$before = count(requests());
run_hook('ClientEdit', ['userid' => 1]);
$calls = array_slice(requests(), $before);
check('S7 name change: only a rename of the linked organisation',
    count(array_filter($calls, fn($r) => $r['method'] === 'PATCH' && $r['path'] === '/api/orgs/' . org_of(1))) === 1
    && !array_filter($calls, fn($r) => in_array($r['method'], ['POST', 'PUT', 'DELETE'], true)), $calls);

// --- S8: single sign-on -----------------------------------------------------
unset($_SESSION['adminid']);
$sso = enhance_ServiceSingleSignOn(params(101));
check('S8 client SSO, Active service: link', ($sso['success'] ?? false) && strpos((string) ($sso['redirectTo'] ?? ''), 'https://') === 0, $sso);
$before = count(requests(null, '.*/sso'));
$sso = enhance_ServiceSingleSignOn(params(101, ['status' => 'Suspended']));
check('S8 client SSO, Suspended service: refused, no SSO request', !($sso['success'] ?? true) && count(requests(null, '.*/sso')) === $before, $sso);
$_SESSION['adminid'] = 1;
$sso = enhance_ServiceSingleSignOn(params(101, ['status' => 'Suspended']));
check('S8 admin SSO, Suspended service: allowed', ($sso['success'] ?? false), $sso);
unset($_SESSION['adminid']);

// --- S9: suspend and unsuspend -----------------------------------------------
$sub101 = link_of(101)->subscription_id;
$suspend = enhance_SuspendAccount(params(101));
$subState = fake('/__state')['subs'][org_of(1)][$sub101] ?? [];
check('S9 suspend: subscription suspended', $suspend === 'success' && !empty($subState['isSuspended']), [$suspend, $subState]);
$unsuspend = enhance_UnsuspendAccount(params(101));
$subState = fake('/__state')['subs'][org_of(1)][$sub101] ?? [];
check('S9 unsuspend: subscription active again', $unsuspend === 'success' && empty($subState['isSuspended']), [$unsuspend, $subState]);

// --- S10: resend access = Enhance's own reset link only ------------------------
$sendBefore = count(array_filter($GLOBALS['__stand']['localapi'], fn($c) => $c[0] === 'SendEmail'));
$resend = enhance_ResendAccess(params(102));
check('S10 resend access: one more Enhance reset link to the login e-mail', $resend === 'success' && links_to('alice@example.test') === 2, [$resend, fake('/__state')['recoveries']]);
check('S10 resend access: no WHMCS e-mail', count(array_filter($GLOBALS['__stand']['localapi'], fn($c) => $c[0] === 'SendEmail')) === $sendBefore);

// --- S11: daily sync ----------------------------------------------------------
$sub301 = link_of(301)->subscription_id;
fake('/__suspend_subscription', ['org' => org_of(3), 'sub' => $sub301, 'suspended' => true]);
service(302, 3, 'carol-old.example', 'Terminated');
Capsule::table('mod_enhance_services')->insert(['service_id' => 302, 'server_id' => 1, 'org_id' => org_of(3), 'subscription_id' => (string) $sub301 . '0']);
run_hook('DailyCronJob');
check('S11 daily sync: suspension in Enhance mirrored to WHMCS', hosting(301)->domainstatus === 'Suspended', hosting(301)->domainstatus);
check('S11 daily sync: Terminated service untouched', hosting(302)->domainstatus === 'Terminated', hosting(302)->domainstatus);
fake('/__suspend_subscription', ['org' => org_of(3), 'sub' => $sub301, 'suspended' => false]);

// --- S12: terminate -------------------------------------------------------------
$sub102 = link_of(102)->subscription_id;
$term = enhance_TerminateAccount(params(102));
$deleted = fake('/__state')['subs'][org_of(1)][$sub102]['status'] ?? 'gone';
check('S12 terminate: stored subscription deleted, link cleared', $term === 'success' && $deleted === 'deleted' && empty(link_of(102)->subscription_id), [$term, $deleted, link_of(102)]);
fake('/__drop_subscription', ['org' => org_of(1), 'sub' => $sub101]);
$deletesBefore = count(requests('DELETE'));
$term = enhance_TerminateAccount(params(101));
check('S12 terminate: subscription already gone (404) counts as removed', $term === 'success' && empty(link_of(101)->subscription_id) && count(requests('DELETE')) === $deletesBefore, [$term, link_of(101)]);
service(103, 1, 'alice-three.example', 'Active');
$term = enhance_TerminateAccount(params(103));
check('S12 terminate: no stored subscription, refuses to guess', $term !== 'success' && count(requests('DELETE')) === $deletesBefore, $term);

// --- S13: the e-mail model at the end -------------------------------------------
check('S13 no WHMCS SendEmail during the whole run', !array_filter($GLOBALS['__stand']['localapi'], fn($c) => $c[0] === 'SendEmail'));
check('S13 no e-mail templates created during the whole run', Capsule::table('tblemailtemplates')->count() === 0);

// --- S15: API client built from a tblservers row (addon, hooks, daily sync) ----
$row = Capsule::table('tblservers')->where('id', 1)->first();
$org = EnhanceApi::fromServerRow($row)->getOrg(MASTER);
check('S15 server row with an encrypted access hash: authenticated', !EnhanceApi::isError($org) && ($org['id'] ?? '') === MASTER, $org);
$plainRow = clone $row;
$plainRow->accesshash = TOKEN;
$org = EnhanceApi::fromServerRow($plainRow)->getOrg(MASTER);
check('S15 server row with a plain access hash: authenticated', !EnhanceApi::isError($org) && ($org['id'] ?? '') === MASTER, $org);
check('S15 no request was rejected with 401 during the run', fake('/__state')['unauthorized'] === [], fake('/__state')['unauthorized']);

// --- S14: auto-link by e-mail, when enabled in config.php --------------------
define('ENHANCE_AUTO_LINK_BY_EMAIL', true);
$outside2 = '55555555-5555-4555-8555-555555555555';
fake('/__seed', [
    'customers' => [['id' => $outside2, 'name' => 'Outside 2', 'ownerId' => '55555555-0000-4000-8000-000000000001']],
    'logins'    => [['id' => '55555555-0000-4000-8000-0000000000aa', 'email' => 'gina@example.test']],
    'members'   => [$outside2 => [['id' => '55555555-0000-4000-8000-000000000001', 'loginId' => '55555555-0000-4000-8000-0000000000aa', 'roles' => ['Owner']]]],
]);
client(7, 'gina@example.test', 'Gina', 'Hoe');
service(701, 7, 'gina.example');
$result = create(701);
check('S14 auto-link on: the existing organisation is linked', $result === 'success' && org_of(7) === $outside2, [$result, org_of(7)]);

echo "\n" . ($GLOBALS['failed'] ? "FAILED: {$GLOBALS['failed']}" : 'ALL PASS') . "\n";
exit($GLOBALS['failed'] ? 1 : 0);
