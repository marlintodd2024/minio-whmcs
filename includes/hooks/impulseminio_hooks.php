<?php
/**
 * ImpulseMinio — Client Area Display Hook
 *
 * MUST be placed in: /includes/hooks/impulseminio_hooks.php
 *
 * This hook runs on every client area page load via ClientAreaFooterOutput.
 * It handles two things that cannot reliably run from the module directory:
 *
 *   1. Suspension banner — Lagom (and some other themes) intercept suspended
 *      services at the theme level and never call the module's ClientArea
 *      function. This hook injects the banner via JS regardless of theme behavior.
 *
 *   2. Sidebar link hiding — Custom button array entries (Create Bucket, etc.)
 *      appear in the Lagom sidebar. We hide them via JS since they're handled
 *      by the module's own dashboard UI.
 *
 * The module-level hooks.php handles addon storage recalculation (billing
 * events that don't depend on theme rendering).
 *
 * @package ImpulseMinio
 */

if (defined('IMPULSEMINIO_DISPLAY_HOOK_LOADED')) return;
define('IMPULSEMINIO_DISPLAY_HOOK_LOADED', true);

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

        // Hide everything after the banner
        $js .= '  var el = document.getElementById("impulseminio-suspended");';
        $js .= '  if (el) {';
        $js .= '    var node = el;';
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
