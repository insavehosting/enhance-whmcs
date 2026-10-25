<?php
/**
 * Enhance WHMCS hooks (v1.0.2-insave.1)
 *
 * ClientEdit                  — renames the linked Enhance organisation when the client's
 *                               name/company changes. It never creates organisations, logins or
 *                               credential e-mails, and does nothing for clients without an
 *                               Active/Suspended Enhance service.
 * AdminClientProfileTabFields — Enhance widget on the admin client profile (CSRF-protected).
 *                               No Enhance API calls for clients without Enhance hosting unless
 *                               the admin explicitly opens the "link organisation" form.
 *
 * Daily sync of imported services lives in enhance_importer_daily_sync.php (one DailyCronJob).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

$__enhanceApiFile = dirname(__DIR__, 2) . '/modules/servers/enhance/EnhanceApi.php';
if (!is_file($__enhanceApiFile)) {
    // Module removed: do not take the whole WHMCS down with a fatal require.
    return;
}
require_once $__enhanceApiFile;
unset($__enhanceApiFile);

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('_ehClientServer')) {
    /**
     * Enhance server of the client's Enhance service (newest first).
     * $liveOnly = true → only Active/Suspended services count.
     */
    function _ehClientServer(int $userId, bool $liveOnly): ?object
    {
        $q = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->join('tblservers as s', 's.id', '=', 'h.server')
            ->where('h.userid', $userId)
            ->where('p.servertype', 'enhance')
            ->where('s.type', 'enhance')
            ->where('s.disabled', 0);

        if ($liveOnly) {
            $q->whereIn('h.domainstatus', ['Active', 'Suspended']);
        }

        $row = $q->orderBy('h.id', 'desc')
            ->select('s.id', 's.hostname', 's.username', 's.accesshash')
            ->first();

        return $row ?: null;
    }
}

if (!function_exists('_ehDefaultServer')) {
    function _ehDefaultServer(): ?object
    {
        $row = Capsule::table('tblservers')
            ->where('type', 'enhance')
            ->where('disabled', 0)
            ->orderBy('id')
            ->select('id', 'hostname', 'username', 'accesshash')
            ->first();

        return $row ?: null;
    }
}

if (!function_exists('_ehAlert')) {
    function _ehAlert(string $type, string $msg): string
    {
        return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES) . '" role="alert">'
             . htmlspecialchars($msg, ENT_QUOTES) . '</div>';
    }
}

if (!function_exists('_ehBadge')) {
    function _ehBadge(string $class, string $icon, $count, string $label): string
    {
        $class = htmlspecialchars($class, ENT_QUOTES);
        $icon  = htmlspecialchars($icon, ENT_QUOTES);
        $count = htmlspecialchars((string) $count, ENT_QUOTES);
        $label = htmlspecialchars($label, ENT_QUOTES);
        return <<<HTML
<div class="health-status-block {$class} clearfix" style="min-width:130px;">
    <div class="icon" style="float:right;"><i class="fas {$icon}"></i></div>
    <div class="detail">
        <span class="count">{$count}</span>
        <span class="desc">{$label}</span>
    </div>
</div>
HTML;
    }
}

if (!function_exists('_ehBtn')) {
    function _ehBtn(int $userId, string $action, string $cls, string $label, string $token, bool $confirm = false): string
    {
        $action = htmlspecialchars($action, ENT_QUOTES);
        $cls    = htmlspecialchars($cls, ENT_QUOTES);
        $label  = htmlspecialchars($label, ENT_QUOTES);
        $token  = htmlspecialchars($token, ENT_QUOTES);
        $conf   = $confirm ? ' onclick="return confirm(\'Are you sure? This cannot be undone.\')"' : '';
        return <<<HTML
<form method="POST" style="display:inline;">
    <input type="hidden" name="enhance_action" value="{$action}">
    <input type="hidden" name="userid"         value="{$userId}">
    <input type="hidden" name="token"          value="{$token}">
    <button type="submit" class="btn {$cls} btn-sm"{$conf}>{$label}</button>
</form>
HTML;
    }
}

if (!function_exists('_ehInject')) {
    /** Renders $content under the EnhanceOrgId custom field of the profile form. */
    function _ehInject(int $fieldId, string $content): array
    {
        $fieldKey    = 'customfield' . $fieldId;
        $contentJson = json_encode($content, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return [
            '' => <<<JS
<script>
document.addEventListener("DOMContentLoaded", function () {
    var field = document.getElementById("{$fieldKey}");
    if (!field) return;
    var tr = field.closest("tr");
    if (tr) {
        var newRow = document.createElement("tr");
        newRow.innerHTML = "<td colspan='2'>" + {$contentJson} + "</td>";
        tr.parentNode.insertBefore(newRow, tr.nextSibling);
    } else {
        var wrap = document.createElement("div");
        wrap.style.marginTop = "14px";
        wrap.innerHTML = {$contentJson};
        field.parentNode.appendChild(wrap);
    }
});
</script>
JS,
        ];
    }
}

// =============================================================================
// ClientEdit — rename only, never create
// =============================================================================

add_hook('ClientEdit', 1, function (array $vars): void {
    $userId = (int) ($vars['userid'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    // Only name/company are mirrored to Enhance. Phone, address, e-mail etc. → no API call.
    $old = (isset($vars['olddata']) && is_array($vars['olddata'])) ? $vars['olddata'] : null;
    if ($old !== null) {
        $changed = false;
        foreach (['firstname', 'lastname', 'companyname'] as $key) {
            if (array_key_exists($key, $vars) && trim((string) ($old[$key] ?? '')) !== trim((string) $vars[$key])) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return;
        }
    }

    // No Active/Suspended Enhance hosting → nothing to sync.
    $server = _ehClientServer($userId, true);
    if (!$server) {
        return;
    }

    try {
        $client = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$client) {
            return;
        }

        $api  = new EnhanceApi((string) $server->hostname, (string) $server->username, (string) $server->accesshash);
        $name = trim((string) ($client->firstname ?? '') . ' ' . (string) ($client->lastname ?? ''));
        $api->syncOrgName($userId, $name, (string) $client->email, (string) ($client->companyname ?? ''));
    } catch (Throwable $e) {
        logActivity('Enhance: organisation rename failed for client #' . $userId . ': ' . $e->getMessage());
    }
});

// =============================================================================
// Admin client profile widget
// =============================================================================

add_hook('AdminClientProfileTabFields', 1, function (array $vars): array {
    $userId = (int) ($vars['userid'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    $clientServer = _ehClientServer($userId, false);
    $server       = $clientServer ?: _ehDefaultServer();
    if (!$server) {
        return [];
    }

    try {
        $api = new EnhanceApi((string) $server->hostname, (string) $server->username, (string) $server->accesshash);
    } catch (Throwable $e) {
        return [];
    }

    $orgId    = $api->getClientOrgId($userId);
    $action   = (string) ($_POST['enhance_action'] ?? '');
    $wantLink = !empty($_REQUEST['enhance_link']);

    // Client without Enhance hosting and without a linked org: render a link, call no API.
    if (!$orgId && !$clientServer && !$wantLink && $action === '') {
        $href = htmlspecialchars('clientsprofile.php?userid=' . $userId . '&enhance_link=1', ENT_QUOTES);
        return _ehInject($api->clientOrgFieldId,
            '<a href="' . $href . '" class="btn btn-default btn-sm">Link Enhance organisation…</a>');
    }

    // -------------------------------------------------------------------------
    // POST actions (CSRF-protected). Always act on the org linked to THIS client,
    // never on an org ID taken from the request.
    // -------------------------------------------------------------------------

    $feedback = '';

    if ($action !== '') {
        if (empty($_POST['token']) || !check_token('CSRF_enhance_' . $userId, $_POST['token'])) {
            $feedback = _ehAlert('danger', 'Invalid security token.');
        } elseif ($action === 'linkOrg') {
            $newOrgId = trim((string) ($_POST['newOrgId'] ?? ''));
            $owner    = $newOrgId !== ''
                ? Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $api->clientOrgFieldId)
                    ->where('value', $newOrgId)
                    ->where('relid', '<>', $userId)
                    ->value('relid')
                : null;

            if ($orgId) {
                $feedback = _ehAlert('warning', 'An organisation is already linked. Unlink it first (clear the EnhanceOrgId field).');
            } elseif (!EnhanceApi::isUuid($newOrgId)) {
                $feedback = _ehAlert('danger', 'Select a valid organisation.');
            } elseif ($owner) {
                $feedback = _ehAlert('danger', "This organisation is already linked to client #{$owner}.");
            } else {
                $org = $api->getOrg($newOrgId);
                if (!empty($org['code']) || empty($org['id'])) {
                    $feedback = _ehAlert('danger', 'Organisation not found in Enhance: ' . $api->errorText($org));
                } else {
                    $api->saveClientOrgId($userId, $newOrgId);
                    logActivity("Enhance: client #{$userId} manually linked to organisation {$newOrgId}");
                    $orgId    = $newOrgId;
                    $feedback = _ehAlert('success', 'Organisation linked.') . '<meta http-equiv="refresh" content="2">';
                }
            }
        } elseif (!$orgId) {
            $feedback = _ehAlert('danger', 'No organisation is linked to this client.');
        } else {
            switch ($action) {
                case 'resetPassword':
                    $orgInfo  = $api->getOrg($orgId);
                    $email    = $orgInfo['ownerEmail'] ?? '';
                    $resp     = $email ? $api->triggerPasswordRecovery($email) : ['code' => 'no_owner_email'];
                    $feedback = empty($resp['code'])
                        ? _ehAlert('success', 'Password reset email sent.')
                        : _ehAlert('danger', 'Error: ' . $api->errorText($resp));
                    break;

                case 'suspendOrg':
                case 'reactivateOrg':
                    $resp     = $api->setOrgSuspended($orgId, $action === 'suspendOrg');
                    $feedback = empty($resp['code'])
                        ? _ehAlert('success', $action === 'suspendOrg' ? 'Organisation suspended.' : 'Organisation reactivated.')
                          . '<meta http-equiv="refresh" content="2">'
                        : _ehAlert('danger', 'Error: ' . $api->errorText($resp));
                    break;

                case 'deleteOrg':
                    if (_ehClientServer($userId, true)) {
                        $feedback = _ehAlert('danger', 'The client has Active/Suspended Enhance services. Terminate them first.');
                        break;
                    }
                    $resp = $api->deleteOrg($orgId);
                    if (empty($resp['code']) || $api->isNotFound($resp)) {
                        Capsule::table('tblcustomfieldsvalues')
                            ->where(['fieldid' => $api->clientOrgFieldId, 'relid' => $userId])
                            ->delete();
                        logActivity("Enhance: organisation {$orgId} deleted from client #{$userId} profile");
                        $orgId    = null;
                        $feedback = _ehAlert('success', 'Organisation deleted.') . '<meta http-equiv="refresh" content="2">';
                    } else {
                        $feedback = _ehAlert('danger', 'Error: ' . $api->errorText($resp));
                    }
                    break;

                default:
                    $feedback = _ehAlert('danger', 'Unknown action.');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Build UI
    // -------------------------------------------------------------------------

    $token   = generate_token('plain');
    $content = $feedback;

    if ($orgId) {
        $orgInfo   = $api->getOrg($orgId);
        $emailInfo = $api->getEmails($orgId);

        if (isset($orgInfo['id'])) {
            $suspended   = !empty($orgInfo['suspendedBy']);
            $statusLabel = $suspended ? 'Suspended' : ucfirst((string) ($orgInfo['status'] ?? 'active'));
            $subCount    = (int) ($orgInfo['subscriptionsCount'] ?? 0);
            $siteCount   = (int) ($orgInfo['websitesCount'] ?? 0);
            $emailCount  = (int) ($emailInfo['total'] ?? 0);

            $badges = _ehBadge('status-badge-green', 'fa-thermometer-empty', $statusLabel, 'Status')
                    . _ehBadge('status-badge-orange', 'fa-users', $subCount, 'Subscriptions')
                    . _ehBadge('status-badge-pink', 'fa-globe', $siteCount, 'Websites')
                    . _ehBadge('status-badge-cyan', 'fa-envelope', $emailCount, 'Emails');

            $content .= '<div style="font-weight:600;margin-bottom:8px;">Enhance organisation</div>'
                      . '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">' . $badges . '</div>';

            $content .= '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            $content .= $suspended
                ? _ehBtn($userId, 'reactivateOrg', 'btn-success', 'Reactivate', $token)
                : _ehBtn($userId, 'suspendOrg', 'btn-warning', 'Suspend', $token);
            $content .= _ehBtn($userId, 'resetPassword', 'btn-primary', 'Reset Password', $token);
            $content .= _ehBtn($userId, 'deleteOrg', 'btn-danger', 'Delete Org', $token, true);
            $content .= '</div>';
        } else {
            $content .= _ehAlert('warning', "Could not load organisation {$orgId}: " . $api->errorText($orgInfo));
        }
    } else {
        $customers = $api->getCustomers();
        $options   = '<option value="">— Select —</option>';
        foreach ($customers['items'] ?? [] as $c) {
            $cid    = htmlspecialchars((string) ($c['id'] ?? ''), ENT_QUOTES);
            $cname  = htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES);
            $cemail = htmlspecialchars((string) ($c['ownerEmail'] ?? ''), ENT_QUOTES);
            $options .= "<option value=\"{$cid}\">{$cname} — {$cemail}</option>";
        }
        $tokenSafe = htmlspecialchars($token, ENT_QUOTES);

        $content .= <<<HTML
<form method="POST" style="margin-top:8px;">
    <input type="hidden" name="enhance_action" value="linkOrg">
    <input type="hidden" name="token" value="{$tokenSafe}">
    <input type="hidden" name="userid" value="{$userId}">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <select name="newOrgId" class="form-control" style="max-width:340px;">{$options}</select>
        <button type="submit" class="btn btn-primary btn-sm">Link Enhance organisation</button>
    </div>
</form>
HTML;
    }

    return _ehInject($api->clientOrgFieldId, $content);
});
