<?php
// modules/orders/order_detail.php - Order detail view
// Created 2008-06-15
// There's a mix of OrderManager calls AND direct SQL in this file.
// The direct SQL was added when OrderManager didn't have the method we needed
// and "we'll move it to OrderManager later" - we never did - Dave 2010

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/orders/OrderManager.php');
require_once(APP_ROOT . '/modules/freight/FreightCalc.php');

$om = new OrderManager();
$fc = new FreightCalc();

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$orderId) {
    echo '<p style="color:red;">No order ID specified.</p>';
    return;
}

// Handle inline actions from this page
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            case 'update_status':
                $newSt = $_POST['new_status'];
                $result = $om->updateOrderStatus($orderId, $newSt);
                if ($result === true) {
                    setFlash('Status updated to: ' . $newSt, 'success');
                } elseif ($result === -1) {
                    setFlash('Cannot update: invoice already exists for this order.', 'error');
                } else {
                    setFlash('Failed to update status.', 'error');
                }
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;

            case 'add_item':
                $productId = (int)$_POST['product_id'];
                $qty       = (int)$_POST['item_qty'];
                $price     = (float)$_POST['item_price'];
                $result    = $om->addOrderItem($orderId, $productId, $qty, $price);
                if ($result) {
                    setFlash('Item added to order.', 'success');
                } else {
                    setFlash('Failed to add item.', 'error');
                }
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;

            case 'remove_item':
                $itemId = (int)$_POST['item_id'];
                $om->removeOrderItem($orderId, $itemId);
                setFlash('Item removed.', 'success');
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;

            case 'apply_discount':
                $code   = $_POST['discount_code'];
                $result = $om->applyDiscount($orderId, $code);
                if ($result) {
                    setFlash('Discount applied: ' . $result['pct'] . '% off (' . $code . ')', 'success');
                } else {
                    setFlash('Invalid or expired discount code.', 'error');
                }
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;

            case 'update_tracking':
                $trackNum  = $_POST['tracking_number']; // NOT sanitized
                $carrierId = (int)$_POST['carrier_id'];
                // Direct inline SQL - should use OrderManager::updateOrder() but
                // "it was faster to just write the SQL" - Bob 2010
                $sql = "UPDATE orders SET
                        tracking_number='" . mysql_real_escape_string($trackNum) . "',
                        carrier_id=$carrierId,
                        updated_at=NOW()
                        WHERE id=$orderId";
                query($sql);
                setFlash('Tracking number updated.', 'success');
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;

            case 'update_notes':
                $notes = $_POST['order_notes']; // NOT sanitized
                $sql   = "UPDATE orders SET notes='" . mysql_real_escape_string($notes) . "', updated_at=NOW() WHERE id=$orderId";
                query($sql);
                setFlash('Notes updated.', 'success');
                header('Location: index.php?page=order_detail&id=' . $orderId);
                exit;
                break;
        }
    }
}

// Fetch order using OrderManager
$order = $om->getOrder($orderId);
if (!$order) {
    echo '<p style="color:red;">Order not found. ID: ' . $orderId . '</p>';
    return;
}

// Fetch order items using OrderManager
$items = $om->getOrderItems($orderId);

// Fetch invoice status - direct query bypassing InvoiceGen because "faster"
// NOTE: this duplicates InvoiceGen::checkInvoiceExists() and getInvoice() - 2012
$invoice = query_row("SELECT * FROM invoices WHERE order_id=$orderId AND status != 'voided' LIMIT 1");

// Fetch freight estimate - calls 4 methods on FreightCalc
// This is why order_detail.php is slow - multiple freight calc calls - 2013
$freightEstimate  = null;
$estimatedDays    = null;
$zoneInfo         = null;

if ($order['carrier_id'] && !empty($order['ship_to_zip'])) {
    // Get origin zip from a hardcoded warehouse zip
    // TODO: get this from the carriers table (2011 - never done)
    $warehouseZip = '19103'; // Philadelphia, PA - our warehouse
    $freightEstimate = $fc->calculateRate($orderId, $order['carrier_id']);
    $estimatedDays   = $fc->estimateDeliveryDays($order['carrier_id'], $warehouseZip, $order['ship_to_zip']);
    $zoneInfo        = $fc->calculateZoneRate($warehouseZip, $order['ship_to_zip']);
}

// Direct inline SQL for products dropdown (not using OrderManager because it
// doesn't have a getProducts() method - TODO: add it - 2010)
$products = query_all("SELECT id, name, sku, price, weight_lbs FROM products WHERE active=1 ORDER BY name");
$carriers = query_all("SELECT id, name FROM carriers WHERE active=1 ORDER BY name");

// Calculate item totals for display
$displaySubtotal = 0;
foreach ($items as $item) {
    $displaySubtotal += $item['quantity'] * $item['unit_price'];
}

?>

<h2>Order: <?php echo h($order['order_number']); ?>
    <span style="font-size:13px;font-weight:normal;">
        &nbsp;&mdash;&nbsp;
        <span style="background:<?php
            $statusColors = array(
                'new'=>'#5bc0de','processing'=>'#f0ad4e','shipped'=>'#5cb85c',
                'delivered'=>'#3c763d','cancelled'=>'#999','on_hold'=>'#ff8800',
                'disputed'=>'#cc0000','archived'=>'#666'
            );
            echo isset($statusColors[$order['status']]) ? $statusColors[$order['status']] : '#999';
        ?>;color:white;padding:2px 8px;">
            <?php echo strtoupper($order['status']); ?>
        </span>
    </span>
</h2>

<div style="float:right;margin-top:-35px;">
    <a href="index.php?page=pick_list&id=<?php echo $orderId; ?>" class="btn" target="_blank">Print Pick List</a>
    <?php if ($invoice): ?>
    <a href="index.php?page=invoice_detail&id=<?php echo $invoice['id']; ?>" class="btn">View Invoice</a>
    <?php else: ?>
    <a href="index.php?page=invoices" class="btn btn-success"
       onclick="document.getElementById('quick_action_form').elements['invoice_order_id'].value=<?php echo $orderId; ?>;submitQuickAction('quick_invoice');return false;">
       Generate Invoice
    </a>
    <?php endif; ?>
</div>

<!-- Two-column layout: order info + status/actions -->
<table style="width:100%;border-collapse:collapse;">
<tr>
<td style="width:60%;vertical-align:top;padding-right:20px;">

    <!-- Order Information -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#003366;color:white;">
            <th colspan="4" style="padding:6px;">Order Information</th>
        </tr>
        <tr>
            <td style="padding:5px;font-weight:bold;width:130px;">Order Number:</td>
            <td style="padding:5px;"><?php echo h($order['order_number']); ?></td>
            <td style="padding:5px;font-weight:bold;width:130px;">Date Created:</td>
            <td style="padding:5px;"><?php echo formatDateTime($order['created_at']); ?></td>
        </tr>
        <tr style="background:#f5f5f5;">
            <td style="padding:5px;font-weight:bold;">Customer:</td>
            <td style="padding:5px;">
                <a href="index.php?page=customer_detail&id=<?php echo $order['customer_id']; ?>">
                    <?php echo h($order['company_name']); ?>
                </a>
                (<?php echo h($order['account_number']); ?>)
            </td>
            <td style="padding:5px;font-weight:bold;">PO Number:</td>
            <td style="padding:5px;"><?php echo h($order['po_number']); ?></td>
        </tr>
        <tr>
            <td style="padding:5px;font-weight:bold;">Carrier:</td>
            <td style="padding:5px;"><?php echo h($order['carrier_name']); ?></td>
            <td style="padding:5px;font-weight:bold;">Payment Terms:</td>
            <td style="padding:5px;">Net <?php echo $order['payment_terms']; ?></td>
        </tr>
        <tr style="background:#f5f5f5;">
            <td style="padding:5px;font-weight:bold;">Tracking #:</td>
            <td style="padding:5px;">
                <?php if ($order['tracking_number']): ?>
                <a href="index.php?page=tracking&track=<?php echo urlencode($order['tracking_number']); ?>&carrier=<?php echo $order['carrier_id']; ?>">
                    <?php echo h($order['tracking_number']); ?>
                </a>
                <?php else: ?>
                <em style="color:#999;">Not yet assigned</em>
                <?php endif; ?>
            </td>
            <td style="padding:5px;font-weight:bold;">Last Updated:</td>
            <td style="padding:5px;"><?php echo formatDateTime($order['updated_at']); ?></td>
        </tr>
    </table>

    <!-- Ship To Address -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#004080;color:white;">
            <th style="padding:6px;">Ship To Address</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <strong><?php echo h($order['ship_to_name']); ?></strong><br/>
                <?php echo h($order['ship_to_addr1']); ?><br/>
                <?php if ($order['ship_to_addr2']): ?>
                <?php echo h($order['ship_to_addr2']); ?><br/>
                <?php endif; ?>
                <?php echo h($order['ship_to_city']); ?>, <?php echo h($order['ship_to_state']); ?> <?php echo h($order['ship_to_zip']); ?>
            </td>
        </tr>
    </table>

    <!-- Freight Info from FreightCalc - shows 4 different calculated values -->
    <?php if ($freightEstimate !== null || $estimatedDays !== null): ?>
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#336699;color:white;">
            <th colspan="2" style="padding:6px;">Freight Information (Calculated)</th>
        </tr>
        <tr>
            <td style="padding:5px;font-weight:bold;width:50%;">Calculated Rate:</td>
            <td style="padding:5px;"><?php echo formatMoney($freightEstimate); ?>
                <span style="color:#999;font-size:10px;">(incl. fuel surcharge)</span>
            </td>
        </tr>
        <tr style="background:#f5f5f5;">
            <td style="padding:5px;font-weight:bold;">Estimated Transit Days:</td>
            <td style="padding:5px;"><?php echo $estimatedDays ? $estimatedDays . ' business days' : 'N/A'; ?></td>
        </tr>
        <?php if ($zoneInfo): ?>
        <tr>
            <td style="padding:5px;font-weight:bold;">Shipping Zone:</td>
            <td style="padding:5px;">Zone <?php echo $zoneInfo; ?></td>
        </tr>
        <?php endif; ?>
        <tr style="background:#f5f5f5;">
            <td style="padding:5px;font-weight:bold;">Billed Freight Amount:</td>
            <td style="padding:5px;"><strong><?php echo formatMoney($order['freight_amount']); ?></strong></td>
        </tr>
        <?php if ($freightEstimate && abs($freightEstimate - $order['freight_amount']) > 0.50): ?>
        <tr>
            <td colspan="2" style="padding:5px;background:#fcf8e3;color:#8a6d3b;font-size:11px;">
                &#9888; Note: Calculated rate differs from billed rate by
                <?php echo formatMoney(abs($freightEstimate - $order['freight_amount'])); ?>.
                This may indicate the rate was set manually or the rate table has changed.
            </td>
        </tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

</td>
<td style="width:40%;vertical-align:top;">

    <!-- Status update form -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#003366;color:white;">
            <th colspan="2" style="padding:6px;">Update Status</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>">
                    <input type="hidden" name="action" value="update_status" />
                    <select name="new_status" style="font-size:12px;">
                        <?php
                        $statuses = array('new','processing','shipped','delivered','on_hold','disputed','cancelled','archived');
                        foreach ($statuses as $st):
                        ?>
                        <option value="<?php echo $st; ?>" <?php echo ($order['status'] == $st) ? 'selected="selected"' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="submit" value="Update" class="btn" />
                </form>
            </td>
        </tr>
    </table>

    <!-- Invoice status -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#003366;color:white;">
            <th style="padding:6px;">Invoice Status</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <?php if ($invoice): ?>
                Invoice: <a href="index.php?page=invoice_detail&id=<?php echo $invoice['id']; ?>">
                    <strong><?php echo h($invoice['invoice_number']); ?></strong>
                </a><br/>
                Amount: <?php echo formatMoney($invoice['total_amount']); ?><br/>
                Status: <strong><?php echo h($invoice['status']); ?></strong><br/>
                Due: <?php echo formatDate($invoice['due_date']); ?>
                <?php if ($invoice['status'] == 'pending' && strtotime($invoice['due_date']) < time()): ?>
                <span style="color:red;font-weight:bold;"> OVERDUE</span>
                <?php endif; ?>
                <?php else: ?>
                <em style="color:#999;">No invoice generated yet.</em><br/>
                <small>Move to "processing" status to auto-generate.</small>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- Tracking number update form -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#336600;color:white;">
            <th style="padding:6px;">Update Tracking</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>">
                    <input type="hidden" name="action" value="update_tracking" />
                    Tracking #: <input type="text" name="tracking_number"
                                       value="<?php echo h($order['tracking_number']); ?>"
                                       size="18" /><br/>
                    Carrier:
                    <select name="carrier_id" style="font-size:11px;">
                        <?php foreach ($carriers as $car): ?>
                        <option value="<?php echo $car['id']; ?>" <?php echo ($car['id'] == $order['carrier_id']) ? 'selected' : ''; ?>>
                            <?php echo h($car['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select><br/><br/>
                    <input type="submit" value="Save Tracking" class="btn" />
                </form>
            </td>
        </tr>
    </table>

    <!-- Notes -->
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#663300;color:white;">
            <th style="padding:6px;">Order Notes</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>">
                    <input type="hidden" name="action" value="update_notes" />
                    <!-- NOTE: order notes from DB echoed directly - XSS via stored notes - 2012 audit finding -->
                    <textarea name="order_notes" rows="4" cols="30" style="font-size:11px;"><?php echo h($order['notes']); ?></textarea><br/>
                    <input type="submit" value="Save Notes" class="btn" />
                </form>
            </td>
        </tr>
    </table>

    <!-- Discount code form -->
    <?php if (!$invoice): ?>
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:15px;">
        <tr style="background:#660066;color:white;">
            <th style="padding:6px;">Apply Discount Code</th>
        </tr>
        <tr>
            <td style="padding:10px;">
                <?php if ($order['discount_code']): ?>
                <p>Applied: <strong><?php echo h($order['discount_code']); ?></strong>
                   (-<?php echo formatMoney($order['discount_amount']); ?>)</p>
                <?php else: ?>
                <form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>">
                    <input type="hidden" name="action" value="apply_discount" />
                    Code: <input type="text" name="discount_code" size="12" placeholder="e.g. SUMMER2013" />
                    <input type="submit" value="Apply" class="btn" />
                </form>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>

</td>
</tr>
</table>

<!-- Order Items Table -->
<h3>Order Items</h3>
<table class="data-table">
    <tr>
        <th>SKU</th><th>Product</th><th>Qty</th>
        <th>Unit Price</th><th>Line Total</th><th>Weight</th><th>Actions</th>
    </tr>
    <?php foreach ($items as $item): ?>
    <tr>
        <td><?php echo h($item['sku']); ?></td>
        <td><?php echo h($item['product_name']); ?></td>
        <td style="text-align:center;"><?php echo (int)$item['quantity']; ?></td>
        <td style="text-align:right;"><?php echo formatMoney($item['unit_price']); ?></td>
        <td style="text-align:right;"><?php echo formatMoney($item['line_total']); ?></td>
        <td><?php echo formatWeight($item['weight_lbs'] * $item['quantity']); ?></td>
        <td>
            <?php if ($order['status'] == 'new' || $order['status'] == 'processing'): ?>
            <form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>" style="display:inline;">
                <input type="hidden" name="action" value="remove_item" />
                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>" />
                <input type="submit" value="Remove" class="btn btn-danger"
                       onclick="return confirmDelete('Remove this item?');" />
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f5f5f5;font-weight:bold;">
        <td colspan="4" style="text-align:right;padding:5px;">Subtotal:</td>
        <td style="text-align:right;"><?php echo formatMoney($order['subtotal']); ?></td>
        <td colspan="2"></td>
    </tr>
    <tr style="background:#f5f5f5;">
        <td colspan="4" style="text-align:right;padding:5px;">Freight:</td>
        <td style="text-align:right;"><?php echo formatMoney($order['freight_amount']); ?></td>
        <td colspan="2"></td>
    </tr>
    <?php if ($order['discount_amount'] > 0): ?>
    <tr style="background:#f5f5f5;color:green;">
        <td colspan="4" style="text-align:right;padding:5px;">Discount (<?php echo h($order['discount_code']); ?>):</td>
        <td style="text-align:right;">-<?php echo formatMoney($order['discount_amount']); ?></td>
        <td colspan="2"></td>
    </tr>
    <?php endif; ?>
    <tr style="background:#e0e0e0;font-weight:bold;font-size:13px;">
        <td colspan="4" style="text-align:right;padding:5px;">TOTAL:</td>
        <td style="text-align:right;"><?php echo formatMoney($order['total_amount']); ?></td>
        <td colspan="2"></td>
    </tr>
</table>

<!-- Add Item Form -->
<?php if (in_array($order['status'], array('new', 'processing'))): ?>
<h3>Add Item</h3>
<form method="POST" action="index.php?page=order_detail&id=<?php echo $orderId; ?>">
    <input type="hidden" name="action" value="add_item" />
    Product:
    <select name="product_id" style="font-size:11px;" onchange="
        var prices = <?php
            $priceMap = array();
            foreach ($products as $p) { $priceMap[$p['id']] = $p['price']; }
            echo json_encode($priceMap);
        ?>;
        document.getElementById('item_price_field').value = prices[this.value] || '';
    ">
        <option value="">-- Select Product --</option>
        <?php foreach ($products as $prod): ?>
        <option value="<?php echo $prod['id']; ?>">
            [<?php echo h($prod['sku']); ?>] <?php echo h($prod['name']); ?> - <?php echo formatMoney($prod['price']); ?>
        </option>
        <?php endforeach; ?>
    </select>
    Qty: <input type="number" name="item_qty" value="1" min="1" size="5" />
    Price: $<input type="text" name="item_price" id="item_price_field" size="8" placeholder="0.00" />
    <input type="submit" value="Add Item" class="btn btn-success" />
</form>
<?php endif; ?>
