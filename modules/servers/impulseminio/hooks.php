<?php
/**
 * ImpulseMinio — Module Hooks
 *
 * Handles addon storage recalculation on billing lifecycle events.
 *
 * NOTE: The client area display hook (suspension banner + sidebar hiding)
 * lives in includes/hooks/impulseminio_hooks.php — it MUST be there because
 * Lagom and some themes skip calling the module on suspended services, so a
 * module-level hook would never fire when it's needed most.
 *
 * @package ImpulseMinio
 */

use WHMCS\Database\Capsule;

/**
 * Addon Storage Hook — Automatically adjusts disk limits when storage addons
 * are activated, updated, or cancelled on ImpulseMinio services.
 *
 * Addon names must start with "Extra" and contain a size like "100 GB" or "1 TB".
 * The hook calculates total addon storage and adds it to the base product limit.
 */
add_hook('AddonActivation', 1, function($vars) {
    impulseminio_recalcAddonStorage((int)($vars['serviceid'] ?? 0));
});

add_hook('AddonSuspension', 1, function($vars) {
    impulseminio_recalcAddonStorage((int)($vars['serviceid'] ?? 0));
});

add_hook('AddonUnsuspension', 1, function($vars) {
    impulseminio_recalcAddonStorage((int)($vars['serviceid'] ?? 0));
});

add_hook('AddonTermination', 1, function($vars) {
    impulseminio_recalcAddonStorage((int)($vars['serviceid'] ?? 0));
});

add_hook('AddonCancellation', 1, function($vars) {
    impulseminio_recalcAddonStorage((int)($vars['serviceid'] ?? 0));
});

/**
 * Recalculate total disk limit for an ImpulseMinio service based on
 * base product configoption1 + all active storage addons.
 */
function impulseminio_recalcAddonStorage(int $serviceId): void
{
    if (!$serviceId) return;

    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.id', $serviceId)
        ->select('tblproducts.servertype', 'tblproducts.configoption1')
        ->first();

    if (!$service || $service->servertype !== 'impulseminio') return;

    $baseGB = (int)($service->configoption1 ?? 0);

    // Sum all active storage addons for this service
    $addons = Capsule::table('tblhostingaddons')
        ->join('tbladdons', 'tbladdons.id', '=', 'tblhostingaddons.addonid')
        ->where('tblhostingaddons.hostingid', $serviceId)
        ->where('tblhostingaddons.status', 'Active')
        ->where('tbladdons.name', 'LIKE', 'Extra%Storage')
        ->select('tbladdons.name', 'tblhostingaddons.qty')
        ->get();

    $addonGB = 0;
    foreach ($addons as $addon) {
        $qty = max(1, (int)$addon->qty);
        $name = $addon->name;
        if (preg_match('/(\d+)\s*TB/i', $name, $m)) {
            $addonGB += (int)$m[1] * 1000 * $qty;
        } elseif (preg_match('/(\d+)\s*GB/i', $name, $m)) {
            $addonGB += (int)$m[1] * $qty;
        }
    }

    $totalGB = $baseGB + $addonGB;
    $totalMB = $totalGB > 0 ? $totalGB * 1024 : 0;

    Capsule::table('tblhosting')->where('id', $serviceId)->update([
        'disklimit' => $totalMB,
    ]);

    logModuleCall('impulseminio', 'addonStorageRecalc', [
        'serviceId' => $serviceId, 'baseGB' => $baseGB,
        'addonGB' => $addonGB, 'totalGB' => $totalGB,
    ], 'Updated disklimit to ' . $totalMB . ' MB');
}
