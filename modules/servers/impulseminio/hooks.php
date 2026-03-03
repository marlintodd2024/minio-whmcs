<?php
/**
 * Impulse Hosting - MinIO Module Hooks
 *
 * Handles:
 * - Bandwidth overage billing (monthly invoice generation)
 * - Welcome email data injection
 * - Service cancellation cleanup
 *
 * @package ImpulseMinio
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

/**
 * Hook: After the daily cron runs, check for bandwidth overages and generate
 * overage invoice line items.
 *
 * This runs after UsageUpdate has populated tblhosting with current bandwidth data.
 * Only processes services where the product has a non-zero overage rate (configoption5).
 */
add_hook('AfterCronJob', 1, function ($vars) {
    // Only run on the 1st of each month (process previous month's overages)
    if (date('j') !== '1') {
        return;
    }

    try {
        // Find all active impulseminio services with bandwidth overages
        $services = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblproducts.servertype', 'impulseminio')
            ->where('tblhosting.domainstatus', 'Active')
            ->where('tblhosting.bwlimit', '>', 0)
            ->whereRaw('tblhosting.bwusage > tblhosting.bwlimit')
            ->select([
                'tblhosting.id as serviceid',
                'tblhosting.userid',
                'tblhosting.bwusage',
                'tblhosting.bwlimit',
                'tblproducts.configoption5 as overage_rate',
                'tblproducts.name as product_name',
            ])
            ->get();

        foreach ($services as $service) {
            $overageRate = (float) $service->overage_rate;

            // Skip if no overage rate configured
            if ($overageRate <= 0) {
                continue;
            }

            // Calculate overage in GB
            $overageGB = round(($service->bwusage - $service->bwlimit) / 1024, 2); // MB to GB
            $overageAmount = round($overageGB * $overageRate, 2);

            if ($overageAmount <= 0) {
                continue;
            }

            $previousMonth = date('F Y', strtotime('-1 month'));

            // Use WHMCS API to create an invoice
            $command = 'CreateInvoice';
            $postData = [
                'userid'      => $service->userid,
                'status'      => 'Unpaid',
                'sendinvoice' => '1',
                'date'        => date('Y-m-d'),
                'duedate'     => date('Y-m-d', strtotime('+7 days')),
                'itemdescription1' => sprintf(
                    'Bandwidth Overage - %s (%s): %.2f GB over limit @ $%.2f/GB',
                    $service->product_name,
                    $previousMonth,
                    $overageGB,
                    $overageRate
                ),
                'itemamount1'  => $overageAmount,
                'itemtaxed1'   => '0',
            ];

            $results = localAPI($command, $postData);

            logModuleCall('impulseminio', 'BandwidthOverage', [
                'serviceId'    => $service->serviceid,
                'overageGB'    => $overageGB,
                'overageAmount' => $overageAmount,
            ], $results);
        }

    } catch (\Exception $e) {
        logActivity('ImpulseMinio Overage Hook Error: ' . $e->getMessage());
    }
});

/**
 * Hook: Inject S3 credentials into welcome email merge fields.
 *
 * Makes the following merge fields available in email templates:
 * {$service_custom_field_MinIO_Username}
 * {$service_custom_field_MinIO_Password}
 * {$service_custom_field_Bucket_Name}
 * {$service_custom_field_S3_Endpoint}
 *
 * These are automatically available via service properties, but this hook
 * ensures they're also available as standard merge fields.
 */
add_hook('EmailPreSend', 1, function ($vars) {
    // Only process for product welcome emails
    if (!in_array($vars['messagename'], ['Product Welcome Email', 'New Product Information'])) {
        return;
    }

    $serviceId = $vars['relid'] ?? 0;
    if (!$serviceId) {
        return;
    }

    // Check if this is an impulseminio service
    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $serviceId)
        ->where('tblproducts.servertype', 'impulseminio')
        ->first();

    if (!$service) {
        return;
    }

    // Get service properties (custom fields)
    $fields = Capsule::table('tblcustomfieldsvalues')
        ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
        ->where('tblcustomfieldsvalues.relid', $serviceId)
        ->pluck('tblcustomfieldsvalues.value', 'tblcustomfields.fieldname');

    $mergeFields = [];
    foreach ($fields as $name => $value) {
        // Convert field name to merge field format
        $key = 'minio_' . strtolower(str_replace([' ', '(', ')'], ['_', '', ''], $name));
        $mergeFields[$key] = $value;
    }

    return $mergeFields;
});
