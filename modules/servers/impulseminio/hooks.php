<?php
/**
 * ImpulseMinio — Client Area Hooks
 *
 * Injects suspension notice and hides sidebar action links for
 * ImpulseMinio services via footer output hook that fires
 * regardless of whether Lagom renders the module template.
 *
 * @package ImpulseMinio
 */

use WHMCS\Database\Capsule;

add_hook('ClientAreaFooterOutput', 1, function($vars) {
    if (($vars['filename'] ?? '') !== 'clientarea' || ($_GET['action'] ?? '') !== 'productdetails') {
        return;
    }

    $serviceId = (int)($_GET['id'] ?? 0);
    if (!$serviceId) return;

    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.id', $serviceId)
        ->select('tblhosting.domainstatus', 'tblhosting.suspendreason', 'tblproducts.servertype')
        ->first();

    if (!$service || $service->servertype !== 'impulseminio') return;

    $isSuspended = strtolower($service->domainstatus) === 'suspended';

    $js = 'document.addEventListener("DOMContentLoaded", function() {';

    // Always hide module action links from sidebar
    $js .= '  var hideLabels = ["Create Bucket", "Delete Bucket", "Create Access Key", "Delete Access Key", "Toggle Versioning", "Reset Password", "List Objects", "Download Object", "Delete Object", "Create Folder", "Get Upload URL"];';
    $js .= '  document.querySelectorAll("a").forEach(function(a) {';
    $js .= '    var t = a.textContent.trim();';
    $js .= '    if (hideLabels.indexOf(t) !== -1) {';
    $js .= '      var li = a.closest("li, .list-group-item");';
    $js .= '      if (li) li.style.display = "none"; else a.style.display = "none";';
    $js .= '    }';
    $js .= '  });';

    if ($isSuspended) {
        $reason = addslashes(htmlspecialchars($service->suspendreason ?? '', ENT_QUOTES, 'UTF-8'));
        $reasonLine = $reason ? '<p style=\"margin-top:12px;margin-bottom:0;font-size:14px;\"><strong>Reason:</strong> ' . $reason . '</p>' : '';

        $js .= '  var bannerHtml = \'';
        $js .= '<div id="impulseminio-suspended" style="max-width:600px;margin:60px auto;padding:45px 35px;text-align:center;background:#fff;border:1px solid #e0e0e0;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);">';
        $js .= '<div style="width:64px;height:64px;margin:0 auto 20px;background:#f8d7da;border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-lock" style="font-size:28px;color:#721c24;"></i></div>';
        $js .= '<h2 style="margin:0 0 12px;color:#333;font-weight:600;">Service Suspended</h2>';
        $js .= '<p style="font-size:15px;color:#666;margin-bottom:5px;">Your cloud storage service is currently suspended.</p>';
        $js .= '<p style="font-size:15px;color:#666;">Please contact support or settle any outstanding invoices to restore access.</p>';
        $js .= $reasonLine;
        $js .= '<a href="clientarea.php?action=invoices" class="btn btn-primary" style="margin-top:20px;padding:10px 30px;font-size:15px;border-radius:6px;"><i class="fas fa-file-invoice-dollar" style="margin-right:8px;"></i>View Invoices</a>';
        $js .= '<a href="submitticket.php" class="btn btn-outline-secondary" style="margin-top:20px;margin-left:10px;padding:10px 30px;font-size:15px;border-radius:6px;border:1px solid #6c757d;color:#6c757d;background:transparent;text-decoration:none;"><i class="fas fa-envelope" style="margin-right:8px;"></i>Contact Support</a>';
        $js .= '</div>\';';

        $js .= '  var banner = document.createElement("div");';
        $js .= '  banner.innerHTML = bannerHtml;';

        // Insert after breadcrumb row, hide everything after
        $js .= '  var heading = document.querySelector("h1, .page-title, .breadcrumb");';
        $js .= '  if (heading) {';
        $js .= '    var parent = heading.closest(".row, .container, .container-fluid, section, header");';
        $js .= '    if (parent && parent.parentNode) {';
        $js .= '      parent.parentNode.insertBefore(banner, parent.nextSibling);';
        $js .= '    }';
        $js .= '  } else {';
        $js .= '    var main = document.querySelector("main, .main-content, #main-body");';
        $js .= '    if (main) main.insertBefore(banner, main.firstChild);';
        $js .= '  }';

        // Hide everything after the banner - walk up parents until we find siblings to hide
        $js .= '  var el = document.getElementById("impulseminio-suspended");';
        $js .= '  if (el) {';
        $js .= '    var node = el;';
        // Walk up until we find a parent that has sibling elements after it
        $js .= '    for (var i = 0; i < 5; i++) {';
        $js .= '      if (node.parentNode && node.parentNode.nextElementSibling) { node = node.parentNode; break; }';
        $js .= '      if (node.parentNode) node = node.parentNode;';
        $js .= '    }';
        $js .= '    var sibling = node.nextElementSibling;';
        $js .= '    while (sibling) {';
        $js .= '      sibling.style.display = "none";';
        $js .= '      sibling = sibling.nextElementSibling;';
        $js .= '    }';
        $js .= '  }';
    }

    $js .= '});';

    return '<script>' . $js . '</script>';
});

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
        // Parse size from addon name: "Extra 100 GB Storage", "Extra 250 GB Storage", "Extra 1 TB Storage"
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
