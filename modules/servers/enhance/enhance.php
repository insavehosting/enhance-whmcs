<?php
/**
 * Enhance WHMCS Server Module v1.0.2-insave.1 (based on webdighost v1.0.2)
 *
 * Supports: account creation, suspension, termination, plan change, SSO,
 * admin services tab, dynamic plan dropdown, test connection.
 *
 * Credentials are read per-request from WHMCS server $params — multiple
 * Enhance servers are fully supported.
 * Credentials are read from $params per request — multiple servers are supported.
 */

require_once __DIR__ . '/EnhanceApi.php';

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// =============================================================================
// Helpers
// =============================================================================

function _enhance_api(array $params): EnhanceApi
{
    return new EnhanceApi(
        $params['serverhostname'],
        $params['serverusername'],   // orgId in WHMCS server "Username"
        $params['serveraccesshash']  // token in WHMCS server "Access Hash"
    );
}

function _enhance_api_error(array $resp): ?string
{
    if (!empty($resp['code'])) {
        return "[{$resp['code']}] " . ($resp['detail'] ?? $resp['message'] ?? '');
    }
    return null;
}

function _enhance_client_name(array $client): string
{
    $name = trim((string)($client["fullname"] ?? ""));
    if ($name !== "") return $name;
    $first = trim((string)($client["firstname"] ?? ""));
    $last  = trim((string)($client["lastname"] ?? ""));
    return trim($first . " " . $last);
}

/**
 * $allowCreate must be true only in CreateAccount. All other module calls work with the
 * organisation already linked to the client and never create or relink one.
 */
function _enhance_sync_customer(EnhanceApi $api, array $params, bool $allowCreate = false): array
{
    $client = $params["clientsdetails"];
    return $api->syncCustomerFromWhmcs(
        (int) $client["id"],
        _enhance_client_name($client),
        (string) $client["email"],
        (string) ($client["companyname"] ?? ""),
        $allowCreate
    );
}

/**
 * Finds the Enhance subscription of this WHMCS service.
 *
 * 1. Stored SUBSCRIPTION_ID: used if Enhance confirms it. Cleared only on a definite 404,
 *    never on a timeout/5xx (that used to drop the mapping and fall back to guessing).
 * 2. Guessing ($allowGuess): only among the client's subscriptions that are NOT already
 *    mapped to another WHMCS service — otherwise an unprovisioned service could be mapped
 *    to (and then suspend/terminate) the subscription of another paid service.
 */
function _enhance_resolve_subscription(EnhanceApi $api, array $params, bool $allowGuess = true): array
{
    $sync = _enhance_sync_customer($api, $params, false);
    if (empty($sync["success"]) || empty($sync["orgId"])) {
        throw new Exception($sync["message"] ?? "Could not resolve customer organisation.");
    }

    $orgId     = (string) $sync["orgId"];
    $packageId = (int) $params["packageid"];
    $serviceId = (int) $params["serviceid"];
    $subId     = $api->getServiceSubscriptionId($packageId, $serviceId);

    if ($subId !== null) {
        $sub = $api->getSubscription($orgId, $subId);
        if (empty($sub["code"])) {
            return ["orgId" => $orgId, "subId" => $subId, "subscription" => $sub, "synced" => $sync];
        }
        if (!$api->isNotFound($sub)) {
            throw new Exception("Could not load subscription {$subId}: " . $api->errorText($sub));
        }
        if (!$allowGuess) {
            throw new Exception("Subscription {$subId} was not found in organisation {$orgId} (404).");
        }
        $api->clearServiceSubscriptionId($packageId, $serviceId);
        logActivity("Enhance: SUBSCRIPTION_ID {$subId} of service #{$serviceId} returned 404 and was cleared");
        $subId = null;
    }

    if (!$allowGuess) {
        throw new Exception("SUBSCRIPTION_ID is empty for service #{$serviceId}; refusing to guess the Enhance subscription.");
    }

    $subs = $api->getCustomerSubscriptions($orgId);
    if ($err = _enhance_api_error($subs)) {
        throw new Exception("Could not list customer subscriptions: " . $err);
    }

    // Candidates: subscriptions of this org that no other WHMCS service already owns.
    $candidates = [];
    foreach ($subs["items"] ?? [] as $item) {
        $id = (string) ($item["id"] ?? "");
        if ($id !== "" && !$api->servicesUsingSubscription($id, $serviceId)) {
            $candidates[] = $item;
        }
    }

    if (count($candidates) === 1) {
        $subId = (string) $candidates[0]["id"];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);
        logActivity("Enhance: service #{$serviceId} mapped to the only unmapped subscription {$subId}");
        return ["orgId" => $orgId, "subId" => $subId, "subscription" => $candidates[0], "synced" => $sync];
    }

    $planId  = (int) ($params["configoption1"] ?? 0);
    $byPlan  = [];
    foreach ($candidates as $item) {
        $itemPlanId = (int) ($item["planId"] ?? ($item["plan"]["id"] ?? 0));
        if ($planId > 0 && $itemPlanId === $planId) {
            $byPlan[] = $item;
        }
    }
    if (count($byPlan) === 1) {
        $subId = (string) $byPlan[0]["id"];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);
        logActivity("Enhance: service #{$serviceId} mapped to subscription {$subId} by plan {$planId}");
        return ["orgId" => $orgId, "subId" => $subId, "subscription" => $byPlan[0], "synced" => $sync];
    }

    throw new Exception("Subscription not found or ambiguous: set SUBSCRIPTION_ID on the service manually.");
}

function _enhance_subscription_is_suspended(array $sub): ?bool
{
    if (array_key_exists("isSuspended", $sub)) return (bool) $sub["isSuspended"];
    if (array_key_exists("suspended", $sub)) return (bool) $sub["suspended"];
    if (!empty($sub["suspendedBy"])) return true;
    if (($sub["status"] ?? "") === "suspended") return true;
    return null;
}

// =============================================================================
// Metadata
// =============================================================================

function enhance_MetaData(): array
{
    return [
        'DisplayName'              => 'Enhance',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => 'Login to Panel',
        'AdminSingleSignOnLabel'   => 'Login to Panel',
    ];
}

// =============================================================================
// Config options
// =============================================================================

function enhance_ConfigOptions(): array
{
    return [
        'plan' => [
            'FriendlyName' => 'Hosting Package',
            'Type'         => 'text',
            'Size'         => '255',
            'Loader'       => 'enhance_LoadPlans',
            'SimpleMode'   => true,
        ],
        'hardDelete' => [
            'FriendlyName' => 'Termination Mode',
            'Type'         => 'dropdown',
            'Options'      => 'soft,hard',
            'Description'  => 'soft = keep data | hard = delete all websites + subscription permanently',
            'Default'      => 'soft',
        ],
    ];
}

function enhance_LoadPlans(array $params): array
{
    try {
        $result = _enhance_api($params)->getPlans();
        $out    = [];
        foreach ($result['items'] ?? [] as $plan) {
            $out[$plan['id']] = "{$plan['id']} — {$plan['name']}";
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// =============================================================================
// Server Groups (required for WHMCS server group assignment)
// =============================================================================

/**
 * Returns available server groups from Enhance to allow WHMCS admins to
 * assign products to specific server groups.
 *
 * In WHMCS, a "Server Group" maps to an Enhance master organisation / server.
 * This function is called by WHMCS when the admin configures a product so they
 * can pick which server (group) should be used for provisioning.
 *
 * @return array  Associative array of [groupId => groupName]
 */
function enhance_GetServerGroups(array $params): array
{
    // WHMCS server groups are managed inside WHMCS itself (System Settings →
    // Products/Services → Servers → Server Groups).  This function simply
    // returns an empty array to signal to WHMCS that the module supports the
    // feature; the admin defines and assigns groups inside the WHMCS UI.
    //
    // If you want to pre-populate groups from Enhance you could call the API
    // here and return an array like ['group-uuid' => 'Group Name'].
    return [];
}

// =============================================================================
// Test connection
// =============================================================================

function enhance_TestConnection(array $params): array
{
    try {
        $resp = _enhance_api($params)->getLicense();

        if (($resp['code'] ?? '') === 'unauthorized') {
            return ['success' => false, 'error' => 'Not authenticated — check credentials.'];
        }

        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// =============================================================================
// Create account
// =============================================================================

function enhance_CreateAccount(array $params): string
{
    try {
        $api       = _enhance_api($params);
        $client    = $params['clientsdetails'];
        $packageId = (int) $params['packageid'];
        $serviceId = (int) $params['serviceid'];
        $planId    = (int) $params['configoption1'];
        $domain    = $params['domain'];

        if ($api->getServiceSubscriptionId($packageId, $serviceId) !== null) {
            return 'Already provisioned — SUBSCRIPTION_ID is already set.';
        }

        if ($planId <= 0) {
            return 'Hosting Package (configoption1) is not set on the product.';
        }

        $sync = _enhance_sync_customer($api, $params, true);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            return $sync['message'] ?? 'Could not sync customer organisation.';
        }
        $orgId = $sync['orgId'];

        $sub = $api->createSubscription($orgId, $planId);
        if (empty($sub['id'])) {
            return 'Failed to create subscription: ' . (_enhance_api_error($sub) ?? json_encode($sub));
        }

        $subId = $sub['id'];
        $api->saveServiceSubscriptionId($packageId, $serviceId, $subId);

        if ($domain !== '') {
            $site = $api->createWebsite($orgId, $subId, $domain);
            if (!empty($site['code'])) {
                $api->deleteSubscription($orgId, $subId, true);
                $api->clearServiceSubscriptionId($packageId, $serviceId);
                return 'Website creation failed (subscription rolled back): ' . _enhance_api_error($site);
            }
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Suspend / Unsuspend
// =============================================================================

function enhance_SuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $current  = _enhance_subscription_is_suspended($resolved['subscription']);

        if ($current === true) {
            return 'success';
        }

        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], true);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return 'Suspension sent but verification failed: ' . $err;

        $verified = _enhance_subscription_is_suspended($verify);
        if ($verified === false) {
            return 'Suspension request accepted, but Enhance still reports the subscription as active.';
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function enhance_UnsuspendAccount(array $params): string
{
    try {
        $api      = _enhance_api($params);
        $resolved = _enhance_resolve_subscription($api, $params);
        $org      = $api->getOrg($resolved['orgId']);

        if (empty($org['code']) && (!empty($org['suspendedBy']) || (($org['status'] ?? '') === 'suspended'))) {
            $orgResp = $api->setOrgSuspended($resolved['orgId'], false);
            if ($err = _enhance_api_error($orgResp)) return 'Could not reactivate organisation: ' . $err;
        }

        $current = _enhance_subscription_is_suspended($resolved['subscription']);
        if ($current === false) {
            return 'success';
        }

        $resp = $api->setSubscriptionSuspended($resolved['orgId'], $resolved['subId'], false);
        if ($err = _enhance_api_error($resp)) return $err;

        $verify = $api->getSubscription($resolved['orgId'], $resolved['subId']);
        if ($err = _enhance_api_error($verify)) return 'Unsuspension sent but verification failed: ' . $err;

        $verified = _enhance_subscription_is_suspended($verify);
        if ($verified === true) {
            return 'Unsuspension request accepted, but Enhance still reports the subscription as suspended.';
        }

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Terminate
// =============================================================================

function enhance_TerminateAccount(array $params): string
{
    try {
        $api        = _enhance_api($params);
        $packageId  = (int) $params['packageid'];
        $serviceId  = (int) $params['serviceid'];
        $hardDelete = (($params['configoption2'] ?? 'soft') === 'hard');

        // Deleting is irreversible: only the stored SUBSCRIPTION_ID is acted on, never a guess.
        $storedSubId = $api->getServiceSubscriptionId($packageId, $serviceId);
        if ($storedSubId === null) {
            return 'SUBSCRIPTION_ID is empty for this service — refusing to guess which Enhance subscription to delete. '
                 . 'Set it on the service (or terminate without running the module command).';
        }

        $sync = _enhance_sync_customer($api, $params, false);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            return $sync['message'] ?? 'Could not resolve customer organisation.';
        }
        $orgId = (string) $sync['orgId'];

        $sub = $api->getSubscription($orgId, $storedSubId);
        if ($api->isNotFound($sub)) {
            // Already gone in Enhance (or belongs to another org): nothing to delete here.
            $api->clearServiceSubscriptionId($packageId, $serviceId);
            logActivity("Enhance: terminate service #{$serviceId} — subscription {$storedSubId} not found in org {$orgId} (404), treated as already removed");
            return 'success';
        }
        if ($err = _enhance_api_error($sub)) return $err;

        $subId = $storedSubId;

        if ($hardDelete) {
            $sites = $api->listWebsitesBySubscription($orgId, $subId);
            if ($err = _enhance_api_error($sites)) return $err;
            foreach ($sites['items'] ?? [] as $site) {
                $api->deleteWebsite($orgId, $site['id']);
            }
        }

        $resp = $api->deleteSubscription($orgId, $subId, $hardDelete);
        if ($err = _enhance_api_error($resp)) return $err;

        $api->clearServiceSubscriptionId($packageId, $serviceId);
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// Change package
// =============================================================================

function enhance_ChangePackage(array $params): string
{
    try {
        $api       = _enhance_api($params);
        $resolved  = _enhance_resolve_subscription($api, $params);
        $orgId     = $resolved['orgId'];
        $subId     = $resolved['subId'];
        $newPlanId = (int) $params['configoption1'];

        $resp = $api->updateSubscriptionPlan($orgId, $subId, $newPlanId);
        return ($err = _enhance_api_error($resp)) ? $err : 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// =============================================================================
// SSO
// =============================================================================

function enhance_ServiceSingleSignOn(array $params): array
{
    try {
        if (isset($params['status']) && $params['status'] !== 'Active') {
            throw new Exception('The hosting panel is available only for active services.');
        }

        $api  = _enhance_api($params);
        $sync = _enhance_sync_customer($api, $params, false);
        if (empty($sync['success']) || empty($sync['orgId'])) {
            throw new Exception($sync['message'] ?? 'Organisation not found.');
        }
        $orgId = $sync['orgId'];

        $url = $api->getOwnerSsoUrl($orgId);
        if ($url) return ['success' => true, 'redirectTo' => $url];

        throw new Exception('Could not generate SSO link.');
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function enhance_AdminSingleSignOn(array $params): array
{
    try {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            throw new Exception('Server hostname is empty.');
        }

        // Server-level admin access in WHMCS is not the same as service/client SSO.
        // For Enhance, the admin button should open the configured Enhance panel directly.
        // PT: O acesso admin abre diretamente o painel Enhance configurado no servidor.
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }

        return [
            'success'    => true,
            'redirectTo' => rtrim($host, '/'),
        ];
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function enhance_AdminLink(array $params): string
{
    $host = trim((string) ($params['serverhostname'] ?? ''));
    if ($host === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $host)) {
        $host = 'https://' . $host;
    }

    $url = htmlspecialchars(rtrim($host, '/'), ENT_QUOTES, 'UTF-8');

    return '<a class="btn btn-default" href="' . $url . '" target="_blank" rel="noopener noreferrer">Login to Panel</a>';
}

// =============================================================================
// Client area
// =============================================================================

function enhance_ClientArea(array $params): array
{
    $systemUrl = Setting::getValue('SystemURL');
    $ssoUrl    = "{$systemUrl}/clientarea.php?action=productdetails&id={$params['serviceid']}&dosinglesignon=1";

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => [
            'ssoUrl' => $ssoUrl,
            'domain' => $params['domain'],
        ],
    ];
}

// =============================================================================
// Admin services tab
// =============================================================================

function enhance_AdminServicesTabFields(array $params): array
{
    try {
        $api       = _enhance_api($params);
        $orgId     = $api->getClientOrgId((int) $params['clientsdetails']['id']);
        $websiteId = $params['customfields']['WEBSITE_ID'] ?? '';

        if (!$orgId || !$websiteId) return [];

        $site = $api->getWebsite($orgId, $websiteId);
        if (!isset($site['id'])) return [];

        $color   = htmlspecialchars($site['colorCode']                   ?? '999999', ENT_QUOTES);
        $status  = htmlspecialchars($site['status']                      ?? 'unknown', ENT_QUOTES);
        $domain  = htmlspecialchars($site['domain']['domain']            ?? $params['domain'], ENT_QUOTES);
        $ipv4    = htmlspecialchars($site['serverIps'][0]['ip']          ?? 'N/A', ENT_QUOTES);
        $ipv6    = htmlspecialchars($site['appServerIpv6']               ?? 'N/A', ENT_QUOTES);
        $php     = htmlspecialchars($site['phpVersion']                  ?? 'N/A', ENT_QUOTES);
        $mysql   = htmlspecialchars($site['canUse']['mysqlKind']         ?? 'N/A', ENT_QUOTES);

        $html = <<<HTML
<div style="background:#f2f5ff;border-radius:8px;padding:16px;margin:8px 0;">
    <h4 style="margin:0 0 6px;">{$domain}
        <span style="color:#{$color};font-size:.85em;margin-left:8px;">
            <i class="fa fa-circle"></i> {$status}
        </span>
    </h4>
    <div style="display:flex;flex-wrap:wrap;gap:14px;background:#fff;padding:8px 12px;border-radius:5px;margin-top:8px;">
        <span><strong>IPv4:</strong> {$ipv4}</span>
        <span><strong>IPv6:</strong> {$ipv6}</span>
        <span><img width="18" src="../modules/servers/enhance/templates/imagens/icones/php.png" style="vertical-align:middle;margin-right:3px;"> {$php}</span>
        <span><img width="18" src="../modules/servers/enhance/templates/imagens/icones/mysql.png" style="vertical-align:middle;margin-right:3px;"> {$mysql}</span>
    </div>
</div>
HTML;

        return ['Website / Website' => $html];
    } catch (Exception $e) {
        return [];
    }
}
