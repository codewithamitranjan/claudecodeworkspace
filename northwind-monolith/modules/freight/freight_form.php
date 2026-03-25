<?php
// modules/freight/freight_form.php - Freight Estimate Form
// Created 2008-07-15
// Posts to itself - the classic PHP pattern of the era
// TODO: make this use AJAX so it doesn't reload the page (2010 - never done)

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/freight/FreightCalc.php');

$fc = new FreightCalc();

$originZip  = '';
$destZip    = '';
$weight     = '';
$carrierId  = 3; // default UPS Ground
$results    = array();
$formErrors = array();
$showResults = false;

// Handle form submission - posts to self
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['calc_freight'])) {

    // Get POST values - minimal validation
    $originZip = trim($_POST['origin_zip']);
    $destZip   = trim($_POST['dest_zip']);
    $weight    = trim($_POST['weight_lbs']);
    $carrierId = isset($_POST['carrier_id']) ? (int)$_POST['carrier_id'] : 0;

    // Validate
    if (empty($originZip)) {
        $formErrors[] = 'Origin ZIP code is required.';
    } elseif (!isValidZip($originZip)) {
        $formErrors[] = 'Invalid origin ZIP code format.';
    }

    if (empty($destZip)) {
        $formErrors[] = 'Destination ZIP code is required.';
    } elseif (!isValidZip($destZip)) {
        $formErrors[] = 'Invalid destination ZIP code format.';
    }

    if (empty($weight) || !is_numeric($weight) || (float)$weight <= 0) {
        $formErrors[] = 'Weight must be a positive number.';
    } elseif ((float)$weight > MAX_WEIGHT_LBS) {
        $formErrors[] = 'Weight exceeds maximum of ' . formatWeight(MAX_WEIGHT_LBS) . '. Please contact us for LTL freight.';
    }

    if (empty($formErrors)) {
        $weight = (float)$weight;
        $showResults = true;

        // Calculate rates for all carriers (or selected carrier)
        $carrierRates = $fc->getCarrierRates();

        if ($carrierId > 0) {
            // Calculate for specific carrier only
            // We need a fake order ID since calculateRate() takes an order ID
            // This is a known design flaw - FreightCalc is tied to orders
            // For estimates we hack it by passing 0 and intercepting in FreightCalc
            // Actually it will fail because getOrder(0) returns null
            // So for estimates we use the helpers.php version instead
            // Which gives slightly different numbers. Nobody has complained yet. - Bob 2012
            $estimateRate = calcFreightEstimate($weight, $originZip, $destZip, $carrierId);
            $transitDays  = $fc->estimateDeliveryDays($carrierId, $originZip, $destZip);
            $zone         = $fc->calculateZoneRate($originZip, $destZip);

            // Find carrier name
            $carrierName = 'Selected Carrier';
            foreach ($carrierRates as $cKey => $cData) {
                if ($cData['id'] == $carrierId) {
                    $carrierName = $cData['name'];
                    break;
                }
            }

            $results[] = array(
                'carrier_id'   => $carrierId,
                'carrier_name' => $carrierName,
                'rate'         => $estimateRate,
                'transit_days' => $transitDays,
                'zone'         => $zone,
            );
        } else {
            // Calculate for all active carriers
            foreach ($carrierRates as $cKey => $cData) {
                if (!$cData['active']) continue;
                if ($cData['id'] == 99) continue; // skip will call

                $rate         = calcFreightEstimate($weight, $originZip, $destZip, $cData['id']);
                $transitDays  = $fc->estimateDeliveryDays($cData['id'], $originZip, $destZip);
                $zone         = $fc->calculateZoneRate($originZip, $destZip);

                if ($rate !== false) {
                    $results[] = array(
                        'carrier_id'   => $cData['id'],
                        'carrier_name' => $cData['name'],
                        'rate'         => $rate,
                        'transit_days' => $transitDays,
                        'zone'         => $zone,
                    );
                }
            }

            // Sort by rate ascending
            usort($results, function($a, $b) {
                return $a['rate'] > $b['rate'] ? 1 : -1;
            });
        }
    }
}

?>

<h2>Freight Rate Estimate</h2>

<p style="color:#666;font-size:11px;">
    <strong>Note:</strong> These are <em>estimates only</em>. Actual rates may vary based on
    actual dimensions, accessorial charges, and current carrier surcharges.
    Estimates use 2011 rate tables. For accurate billing rates, use the freight calculator
    on the order detail page. <!-- yes there are two different calculators that give different numbers - known issue 2013 -->
</p>

<?php if (!empty($formErrors)): ?>
<div style="background:#f2dede;border:1px solid #a94442;color:#a94442;padding:10px;margin-bottom:15px;">
    <strong>Please correct the following:</strong>
    <ul style="margin:5px 0 0 20px;">
        <?php foreach ($formErrors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Form posts to itself -->
<form method="POST" action="index.php?page=freight_estimate" style="background:#f5f5f5;border:1px solid #ddd;padding:15px;margin-bottom:20px;">

    <table style="width:100%;border-collapse:collapse;">
    <tr>
        <td style="width:50%;padding:5px;vertical-align:top;">
            <table style="width:100%;">
                <tr>
                    <td style="padding:5px;"><label style="font-weight:bold;">Origin ZIP Code: <span style="color:red;">*</span></label></td>
                    <td style="padding:5px;">
                        <input type="text" name="origin_zip" value="<?php echo h($originZip); ?>"
                               size="12" maxlength="10" style="border:1px solid #ccc;padding:4px;" />
                        <!-- Default to our warehouse zip for logged-in users -->
                        <?php if (checkAuth()): ?>
                        <small style="color:#999;">(<a href="#" onclick="document.querySelector('[name=origin_zip]').value='19103';return false;">Use warehouse</a>)</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:5px;"><label style="font-weight:bold;">Destination ZIP Code: <span style="color:red;">*</span></label></td>
                    <td style="padding:5px;">
                        <input type="text" name="dest_zip" value="<?php echo h($destZip); ?>"
                               size="12" maxlength="10" style="border:1px solid #ccc;padding:4px;" />
                    </td>
                </tr>
                <tr>
                    <td style="padding:5px;"><label style="font-weight:bold;">Weight (lbs): <span style="color:red;">*</span></label></td>
                    <td style="padding:5px;">
                        <input type="text" name="weight_lbs" value="<?php echo h($weight); ?>"
                               size="10" style="border:1px solid #ccc;padding:4px;" />
                        <small style="color:#999;">Max: <?php echo number_format(MAX_WEIGHT_LBS); ?> lbs</small>
                    </td>
                </tr>
            </table>
        </td>
        <td style="width:50%;padding:5px;vertical-align:top;">
            <table style="width:100%;">
                <tr>
                    <td style="padding:5px;"><label style="font-weight:bold;">Carrier:</label></td>
                    <td style="padding:5px;">
                        <select name="carrier_id" style="font-size:12px;border:1px solid #ccc;padding:4px;">
                            <option value="0">-- All Carriers (compare) --</option>
                            <?php foreach ($fc->getCarrierRates() as $cKey => $cData): ?>
                            <?php if (!$cData['active'] || $cData['id'] == 99) continue; ?>
                            <option value="<?php echo $cData['id']; ?>" <?php echo ($carrierId == $cData['id']) ? 'selected' : ''; ?>>
                                <?php echo h($cData['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td style="padding:5px;" colspan="2">
                        <!-- Accessorial options - for display only, not actually used in calculation
                             TODO: actually add these to the calculation (2011 - not done) -->
                        <label style="font-size:11px;">
                            <input type="checkbox" name="residential" value="1" /> Residential Delivery (+<?php echo formatMoney(RESIDENTIAL_SURCHARGE); ?>)
                        </label><br/>
                        <label style="font-size:11px;">
                            <input type="checkbox" name="liftgate" value="1" /> Liftgate Required (+<?php echo formatMoney(LIFTGATE_FEE); ?>)
                        </label><br/>
                        <label style="font-size:11px;">
                            <input type="checkbox" name="inside_delivery" value="1" /> Inside Delivery (+<?php echo formatMoney(INSIDE_DELIVERY_FEE); ?>)
                        </label><br/>
                        <small style="color:#999;font-style:italic;">
                            Note: Accessorial charges shown but not included in estimate below. TODO: fix this - 2011
                        </small>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding:5px;">
                        <input type="submit" name="calc_freight" value="Calculate Freight Estimate"
                               style="background:#003366;color:white;border:none;padding:8px 15px;cursor:pointer;font-size:13px;" />
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    </table>
</form>

<?php if ($showResults): ?>

<h3>Freight Estimates</h3>
<p style="font-size:11px;color:#666;">
    From ZIP: <strong><?php echo h($originZip); ?></strong> &nbsp;&rarr;&nbsp;
    To ZIP: <strong><?php echo h($destZip); ?></strong> &nbsp;|&nbsp;
    Weight: <strong><?php echo formatWeight((float)$weight); ?></strong>
    <?php if (!empty($results)): ?>
    &nbsp;|&nbsp; Shipping Zone: <strong><?php echo $results[0]['zone']; ?></strong>
    <?php endif; ?>
</p>

<?php if (!empty($results)): ?>

<table class="data-table" style="width:100%;">
    <thead>
    <tr>
        <th>Carrier</th>
        <th>Estimated Rate</th>
        <th>Transit Days</th>
        <th>Zone</th>
        <th>Fuel Surcharge (<?php echo FUEL_SURCHARGE_PCT; ?>%)</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $cheapest = $results[0]['rate'];
    foreach ($results as $idx => $res):
        $isCheapest = ($res['rate'] == $cheapest);
    ?>
    <tr style="<?php echo $isCheapest ? 'background:#dff0d8;' : ''; ?>">
        <td>
            <?php if ($isCheapest): ?>
            <strong><?php echo h($res['carrier_name']); ?></strong>
            <span style="background:green;color:white;font-size:9px;padding:1px 4px;margin-left:5px;">BEST VALUE</span>
            <?php else: ?>
            <?php echo h($res['carrier_name']); ?>
            <?php endif; ?>
        </td>
        <td style="text-align:right;font-size:14px;<?php echo $isCheapest ? 'font-weight:bold;' : ''; ?>">
            <?php echo formatMoney($res['rate']); ?>
        </td>
        <td style="text-align:center;">
            <?php
            if ($res['transit_days'] === 0) {
                echo 'Same Day / Pickup';
            } elseif ($res['transit_days'] === null) {
                echo 'N/A';
            } elseif ($res['transit_days'] == 1) {
                echo '1 business day';
            } else {
                echo $res['transit_days'] . ' business days';
            }
            ?>
        </td>
        <td style="text-align:center;">Zone <?php echo $res['zone']; ?></td>
        <td style="text-align:right;font-size:11px;color:#666;">
            <?php echo formatMoney($res['rate'] - ($res['rate'] / (1 + FUEL_SURCHARGE_PCT/100))); ?>
        </td>
        <td>
            <?php if (checkAuth()): ?>
            <!-- Pre-fill a new order with this carrier - links to order creation -->
            <a href="index.php?page=order_new&carrier_id=<?php echo $res['carrier_id']; ?>&dest_zip=<?php echo urlencode($destZip); ?>"
               class="btn" style="font-size:10px;">Use This Rate</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p style="font-size:10px;color:#999;margin-top:10px;">
    * Estimates are based on 2011 rate tables and may not reflect current carrier pricing.
    Rates do not include dimensional weight adjustments, delivery surcharges, or special handling fees.
    Fuel surcharge of <?php echo FUEL_SURCHARGE_PCT; ?>% applied (last updated 2013-08-01).
    For binding quotes, please contact your carrier representative directly.
</p>

<?php else: ?>
<p style="color:#666;">No rates available for this route. Please contact us for a custom quote.</p>
<?php endif; ?>

<?php endif; // showResults ?>
