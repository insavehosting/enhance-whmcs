<?php
/**
 * Enhance Importer Daily Sync Hook (v1.0.2-insave.1)
 * Runs with the WHMCS daily cron. It only synchronises already imported services.
 *
 * Fixes vs upstream:
 * - upstream required includes/modules/... (wrong path) → fatal error on every WHMCS page;
 * - files are loaded lazily inside the hook and skipped if the module/addon is not installed;
 * - this is the only DailyCronJob of the module (the broken duplicate in enhance.php is removed).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

add_hook('DailyCronJob', 1, function () {
    try {
        $root      = dirname(__DIR__, 2);
        $apiFile   = $root . '/modules/servers/enhance/EnhanceApi.php';
        $addonFile = $root . '/modules/addons/enhance_importer/enhance_importer.php';
        if (!is_file($apiFile) || !is_file($addonFile)) {
            return;
        }
        require_once $apiFile;
        require_once $addonFile;

        if (!function_exists('enhance_importer_run_daily_sync')
            || !Capsule::schema()->hasTable('mod_enhance_service_import_log')) {
            return;
        }

        $serverIds = Capsule::table('mod_enhance_service_import_log')
            ->select('server_id')
            ->distinct()
            ->pluck('server_id')
            ->all();

        foreach ($serverIds as $serverId) {
            $server = Capsule::table('tblservers')
                ->where('id', (int) $serverId)
                ->where('type', 'enhance')
                ->where('disabled', 0)
                ->first();

            if (!$server) {
                continue;
            }

            $api    = new EnhanceApi((string) $server->hostname, (string) $server->username, (string) $server->accesshash);
            $result = enhance_importer_run_daily_sync((int) $server->id, $api);
            logActivity('Enhance Importer daily sync: server #' . (int) $server->id . ', checked ' . (int) $result['checked'] . ', updated ' . (int) $result['updated']);
        }
    } catch (Throwable $e) {
        logActivity('Enhance Importer daily sync failed: ' . $e->getMessage());
    }
});
