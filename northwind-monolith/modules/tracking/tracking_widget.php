<?php
// modules/tracking/tracking_widget.php - Tracking status display
// Created 2009-01-20
// Used both by the public tracking page and the internal tracking view
// The ?debug=1 parameter shows raw XML which is a security issue on the public page
// TODO: disable debug output on public pages (2011 - not done)

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/tracking/TrackingService.php');

$ts = new TrackingService();

// Get tracking number from GET (public form) or query parameters
$trackingNumber = '';
$carrierParam   = '';
$trackResult    = null;
$trackError     = '';

if (isset($_GET['track'])) {
    $trackingNumber = strtoupper(trim($_GET['track']));
}
if (isset($_POST['tracking_number'])) {
    $trackingNumber = strtoupper(trim($_POST['tracking_number']));
}
if (isset($_GET['carrier'])) {
    $carrierParam = $_GET['carrier'];
}
if (isset($_POST['carrier'])) {
    $carrierParam = $_POST['carrier'];
}

// If tracking number provided, fetch status
if (!empty($trackingNumber)) {
    // Auto-detect carrier from tracking number format if not provided
    if (empty($carrierParam)) {
        // UPS tracking numbers start with 1Z
        if (substr($trackingNumber, 0, 2) === '1Z') {
            $carrierParam = 'ups';
        }
        // FedEx tracking is 12 or 15 digits
        elseif (preg_match('/^\d{12}$/', $trackingNumber) || preg_match('/^\d{15}$/', $trackingNumber)) {
            $carrierParam = 'fedex';
        }
        // USPS tracking starts with 94, 93, 92, 1 (various formats)
        elseif (preg_match('/^(94|93|92|1)\d+/', $trackingNumber)) {
            $carrierParam = 'usps';
        }
        // Default to UPS
        else {
            $carrierParam = 'ups';
        }
    }

    $trackResult = $ts->getTrackingStatus($trackingNumber, $carrierParam);

    if (!$trackResult) {
        $trackError = 'Could not retrieve tracking information for ' . htmlspecialchars($trackingNumber)
                    . '. Please check the tracking number and try again, or visit the carrier\'s website directly.';
    }
}

// Debug mode - shows raw XML/response data
// Should be admin-only but isn't - known issue 2012
$debugMode = APP_DEBUG && (isset($_GET['debug']) && $_GET['debug'] == '1');

?>

<h2>Shipment Tracking</h2>

<!-- Tracking Form -->
<form method="POST" action="index.php?page=tracking" style="background:#f5f5f5;border:1px solid #ddd;padding:15px;margin-bottom:20px;">
    <strong>Track Your Shipment</strong><br/><br/>
    <label style="font-weight:bold;">Tracking Number:</label>
    <input type="text" name="tracking_number"
           value="<?php echo h($trackingNumber); ?>"
           size="30" placeholder="Enter tracking number..."
           style="border:1px solid #ccc;padding:5px;font-size:13px;" />
    &nbsp;
    <label style="font-weight:bold;">Carrier:</label>
    <select name="carrier" style="font-size:12px;padding:4px;border:1px solid #ccc;">
        <option value="">Auto-Detect</option>
        <option value="ups"   <?php echo $carrierParam=='ups'   ? 'selected' : ''; ?>>UPS</option>
        <option value="fedex" <?php echo $carrierParam=='fedex' ? 'selected' : ''; ?>>FedEx</option>
        <option value="usps"  <?php echo $carrierParam=='usps'  ? 'selected' : ''; ?>>USPS</option>
        <option value="dhl"   <?php echo $carrierParam=='dhl'   ? 'selected' : ''; ?>>DHL</option>
    </select>
    &nbsp;
    <input type="submit" value="Track Shipment"
           style="background:#003366;color:white;border:none;padding:6px 15px;cursor:pointer;font-size:13px;" />
    &nbsp;
    <?php if (APP_DEBUG): ?>
    <label style="font-size:11px;color:#999;">
        <input type="checkbox" name="debug" value="1" <?php echo $debugMode ? 'checked' : ''; ?> />
        Debug output
    </label>
    <?php endif; ?>
</form>

<?php if ($trackError): ?>
<div style="background:#f2dede;border:1px solid #a94442;color:#a94442;padding:12px;margin-bottom:20px;">
    <?php echo $trackError; // NOTE: trackError contains h()-escaped content from above ?>
    <br/><br/>
    <strong>Alternative Tracking Links:</strong><br/>
    <a href="https://www.ups.com/track?tracknum=<?php echo urlencode($trackingNumber); ?>" target="_blank">Track on UPS.com</a> |
    <a href="https://www.fedex.com/apps/fedextrack/?tracknumbers=<?php echo urlencode($trackingNumber); ?>" target="_blank">Track on FedEx.com</a> |
    <a href="https://tools.usps.com/go/TrackConfirmAction?tLabels=<?php echo urlencode($trackingNumber); ?>" target="_blank">Track on USPS.com</a>
</div>
<?php endif; ?>

<?php if ($trackResult): ?>
<div style="border:1px solid #003366;margin-bottom:20px;">

    <!-- Tracking Header -->
    <div style="background:#003366;color:white;padding:10px;">
        <strong>Tracking #: <?php echo h($trackResult['tracking_number'] ?? $trackingNumber); ?></strong>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        Carrier: <strong><?php echo h(strtoupper($trackResult['carrier'] ?? $carrierParam)); ?></strong>
    </div>

    <!-- Current Status -->
    <div style="padding:15px;border-bottom:1px solid #eee;">
        <table style="width:100%;">
        <tr>
            <td style="width:60%;vertical-align:top;">
                <div style="font-size:18px;font-weight:bold;color:#003366;margin-bottom:8px;">
                    <?php
                    // Map status codes to display text
                    $statusDisplay = h($trackResult['status_desc'] ?? $trackResult['status'] ?? 'Unknown');
                    echo $statusDisplay;
                    ?>
                </div>
                <?php if (!empty($trackResult['last_event'])): ?>
                <div style="color:#666;font-size:12px;">
                    Last Update:
                    <?php
                    $evt = $trackResult['last_event'];
                    echo h($evt['date'] ?? '');
                    if (!empty($evt['time'])) echo ' ' . h($evt['time']);
                    if (!empty($evt['location'])) echo ' &mdash; ' . h($evt['location']);
                    ?>
                </div>
                <?php endif; ?>
            </td>
            <td style="width:40%;text-align:right;vertical-align:top;">
                <?php if (!empty($trackResult['estimated_delivery'])): ?>
                <div style="background:#f5f5f5;border:1px solid #ccc;padding:10px;display:inline-block;">
                    <div style="font-size:11px;color:#666;margin-bottom:4px;">ESTIMATED DELIVERY</div>
                    <div style="font-size:16px;font-weight:bold;color:#006600;">
                        <?php echo formatDate($trackResult['estimated_delivery']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        </table>
    </div>

    <!-- Tracking Events Timeline -->
    <?php if (!empty($trackResult['events'])): ?>
    <div style="padding:15px;">
        <strong style="font-size:13px;">Tracking History</strong>
        <table style="width:100%;margin-top:10px;font-size:11px;" cellpadding="5" cellspacing="0">
            <tr style="background:#f0f0f0;">
                <th style="text-align:left;border-bottom:1px solid #ccc;">Date/Time</th>
                <th style="text-align:left;border-bottom:1px solid #ccc;">Location</th>
                <th style="text-align:left;border-bottom:1px solid #ccc;">Activity</th>
            </tr>
            <?php foreach ($trackResult['events'] as $idx => $event): ?>
            <tr style="<?php echo ($idx % 2 == 0) ? '' : 'background:#f9f9f9;'; ?>border-bottom:1px solid #eee;">
                <td style="white-space:nowrap;">
                    <?php echo h($event['date'] ?? ''); ?>
                    <?php if (!empty($event['time'])): ?><br/><small style="color:#999;"><?php echo h($event['time']); ?></small><?php endif; ?>
                </td>
                <td><?php echo h($event['location'] ?? ''); ?></td>
                <td><?php echo h($event['description'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:15px;color:#666;font-style:italic;">
        No detailed tracking events available at this time.
    </div>
    <?php endif; ?>

    <!-- Source indicator - for debugging -->
    <?php if (isset($trackResult['source'])): ?>
    <div style="padding:5px 15px;background:#fffbe6;border-top:1px solid #f0e0a0;font-size:10px;color:#999;">
        Data source: <?php echo h($trackResult['source']); ?>
        | Retrieved: <?php echo isset($trackResult['retrieved_at']) ? date('Y-m-d H:i:s', $trackResult['retrieved_at']) : 'unknown'; ?>
    </div>
    <?php endif; ?>

</div><!-- end tracking result box -->

<!-- Debug mode: show raw carrier response -->
<!-- WARNING: this shows internal data on the public page if APP_DEBUG is true -->
<!-- TODO: restrict debug to admin users only (2011 - not done) -->
<?php if ($debugMode): ?>
<div style="margin-top:15px;">
    <strong>DEBUG: Raw Tracking Data</strong>
    <pre style="background:#f0f0f0;border:1px solid #ccc;padding:10px;overflow:auto;max-height:400px;font-size:10px;">
<?php print_r($trackResult); ?>
    </pre>

    <?php if (!empty($GLOBALS['tracking_errors'])): ?>
    <strong>Tracking Errors (this request):</strong>
    <pre style="background:#fee;border:1px solid #f00;padding:10px;font-size:10px;">
<?php print_r($GLOBALS['tracking_errors']); ?>
    </pre>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // if trackResult ?>

<!-- External tracking links -->
<?php if (!empty($trackingNumber)): ?>
<div style="font-size:11px;color:#666;margin-top:10px;">
    Also track at:
    <a href="https://www.ups.com/track?tracknum=<?php echo urlencode($trackingNumber); ?>" target="_blank">UPS.com</a> |
    <a href="https://www.fedex.com/apps/fedextrack/?tracknumbers=<?php echo urlencode($trackingNumber); ?>" target="_blank">FedEx.com</a> |
    <a href="https://tools.usps.com/go/TrackConfirmAction?tLabels=<?php echo urlencode($trackingNumber); ?>" target="_blank">USPS.com</a> |
    <a href="https://www.dhl.com/en/express/tracking.html?AWB=<?php echo urlencode($trackingNumber); ?>" target="_blank">DHL.com</a>
</div>
<?php endif; ?>

<!-- Recent shipments (for logged-in users) -->
<?php if (checkAuth()): ?>
<h3>Recent Shipments</h3>
<?php
$recentShipments = query_all(
    "SELECT o.id, o.order_number, o.tracking_number, o.carrier_id, o.status,
            o.updated_at, c.company_name
     FROM orders o
     LEFT JOIN customers c ON o.customer_id = c.id
     WHERE o.status = 'shipped'
     AND o.tracking_number IS NOT NULL
     AND o.tracking_number != ''
     ORDER BY o.updated_at DESC
     LIMIT 10"
);
if ($recentShipments):
?>
<table class="data-table" style="width:100%;">
    <tr>
        <th>Order #</th>
        <th>Customer</th>
        <th>Carrier</th>
        <th>Tracking #</th>
        <th>Shipped</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($recentShipments as $ship): ?>
    <tr>
        <td><a href="index.php?page=order_detail&id=<?php echo $ship['id']; ?>"><?php echo h($ship['order_number']); ?></a></td>
        <td><?php echo h($ship['company_name']); ?></td>
        <td><?php echo h(getCarrierName($ship['carrier_id'])); ?></td>
        <td>
            <a href="index.php?page=tracking&track=<?php echo urlencode($ship['tracking_number']); ?>&carrier=<?php echo (int)$ship['carrier_id']; ?>">
                <?php echo h($ship['tracking_number']); ?>
            </a>
        </td>
        <td><?php echo formatDate($ship['updated_at']); ?></td>
        <td>
            <a href="index.php?page=tracking&track=<?php echo urlencode($ship['tracking_number']); ?>&carrier=<?php echo (int)$ship['carrier_id']; ?>"
               class="btn">Track</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
<p style="color:#666;font-style:italic;">No active shipments found.</p>
<?php endif; ?>
<?php endif; // if checkAuth() ?>
