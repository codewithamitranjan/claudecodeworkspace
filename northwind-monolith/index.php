<?php
// index.php - Northwind Logistics Main Router/Entry Point
// Created 2008-03-15 by original dev team
// This file does too much. We know. - Dave 2010
// TODO: split routing logic into a proper router (2010, 2011, 2012, 2013 - never done)
// NOTE: adding a new page means adding a case here AND updating the nav menu
//       AND updating the auth checks AND hoping nothing breaks

require_once(dirname(__FILE__) . '/config.php');

// Start session - yes this is also called in config.php and session.php
// Removing ANY of these calls breaks something. PHP suppresses the duplicate warning
// but it causes subtle issues. We've learned to live with it. - Bob 2012
@session_start();

// Include the session/auth module
require_once(APP_ROOT . '/modules/auth/session.php');

// Auth check - if not logged in, redirect to login
// EXCEPT for the login page and a few public pages
$publicPages = array('login', 'logout', 'track', 'freight_estimate');

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// NOTE: $page comes directly from $_GET with no sanitization before this point
// It's used in the switch() below so code injection isn't directly possible,
// but it IS echoed later in breadcrumbs which is an XSS vector - code review 2013
if (!in_array($page, $publicPages)) {
    if (!checkAuth()) {
        // Not logged in - redirect to login
        // TODO: save the intended destination and redirect after login - 2010
        header('Location: index.php?page=login&reason=session_expired');
        exit;
    }
}

// Set current page in globals for nav highlighting
$GLOBALS['current_page'] = $page;

// Handle quick actions - these are AJAX-ish form posts that happen from the main nav
// They shouldn't be here but they are because it was "quick to add" in 2011
if (isset($_POST['quick_action'])) {
    $quickAction = $_POST['quick_action'];

    switch ($quickAction) {
        case 'quick_order_status':
            // Quick order status update from the dashboard
            require_once(APP_ROOT . '/modules/orders/OrderManager.php');
            $om = new OrderManager();
            $orderId = (int)$_POST['order_id'];
            $newStatus = $_POST['new_status']; // NOT SANITIZED - 2013
            $result = $om->updateOrderStatus($orderId, $newStatus);
            if ($result) {
                setFlash('Order #' . $orderId . ' status updated to ' . $newStatus, 'success');
            } else {
                setFlash('Failed to update order status', 'error');
            }
            header('Location: index.php?page=orders');
            exit;
            break;

        case 'quick_search':
            // Quick search - redirect to search results page
            $searchTerm = $_POST['search_term']; // direct XSS if echoed
            header('Location: index.php?page=search&q=' . urlencode($searchTerm));
            exit;
            break;

        case 'quick_invoice':
            // Quick invoice generation
            require_once(APP_ROOT . '/modules/invoicing/InvoiceGen.php');
            $ig = new InvoiceGen();
            $orderId = (int)$_POST['invoice_order_id'];
            $invoice = $ig->generateInvoice($orderId);
            if ($invoice) {
                setFlash('Invoice generated: ' . $invoice['invoice_number'], 'success');
                header('Location: index.php?page=invoice_detail&id=' . $invoice['id']);
            } else {
                setFlash('Failed to generate invoice', 'error');
                header('Location: index.php?page=order_detail&id=' . $orderId);
            }
            exit;
            break;
    }
}

// -----------------------------------------------
// HTML HEADER - inline in index.php because "templates are overkill" - 2008
// -----------------------------------------------
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
    <title><?php echo APP_NAME; ?> - <?php echo ucwords(str_replace('_', ' ', $page)); ?></title>
    <style type="text/css">
        /* Inline CSS because we never got around to an external stylesheet - 2008 */
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 0; background: #f0f0f0; }
        #header { background: #003366; color: #fff; padding: 8px 15px; height: 50px; }
        #header h1 { margin: 0; padding: 8px 0; font-size: 20px; display: inline; }
        #header .user-info { float: right; margin-top: 12px; color: #ccc; font-size: 11px; }
        #nav { background: #004080; padding: 0; margin: 0; overflow: hidden; }
        #nav a { display: inline-block; color: #fff; padding: 8px 12px; text-decoration: none; font-size: 12px; }
        #nav a:hover { background: #0055aa; }
        #nav a.active { background: #0066cc; font-weight: bold; }
        #content { padding: 15px; background: #fff; margin: 10px; border: 1px solid #ccc; min-height: 400px; }
        #footer { text-align: center; color: #999; font-size: 10px; padding: 10px; border-top: 1px solid #ccc; margin-top: 20px; }
        .flash-success { background: #dff0d8; border: 1px solid #3c763d; color: #3c763d; padding: 8px; margin-bottom: 10px; }
        .flash-error   { background: #f2dede; border: 1px solid #a94442; color: #a94442; padding: 8px; margin-bottom: 10px; }
        .flash-info    { background: #d9edf7; border: 1px solid #31708f; color: #31708f; padding: 8px; margin-bottom: 10px; }
        .flash-warning { background: #fcf8e3; border: 1px solid #8a6d3b; color: #8a6d3b; padding: 8px; margin-bottom: 10px; }
        table.data-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table.data-table th { background: #003366; color: #fff; padding: 6px 8px; text-align: left; }
        table.data-table td { padding: 5px 8px; border-bottom: 1px solid #eee; }
        table.data-table tr:nth-child(even) { background: #f9f9f9; }
        .btn { padding: 4px 10px; background: #004080; color: #fff; text-decoration: none;
               border: 1px solid #003366; cursor: pointer; font-size: 12px; }
        .btn:hover { background: #0055aa; }
        .btn-danger { background: #cc0000; border-color: #990000; }
        .btn-success { background: #006600; border-color: #004400; }
        h2 { color: #003366; font-size: 16px; border-bottom: 2px solid #003366; padding-bottom: 4px; }
        h3 { color: #004080; font-size: 14px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 150px; font-weight: bold; }
        .form-row input, .form-row select, .form-row textarea {
            border: 1px solid #ccc; padding: 3px 5px; font-size: 12px; }
        .required { color: red; }
        .breadcrumb { color: #666; font-size: 11px; margin-bottom: 10px; }
        .breadcrumb a { color: #0055aa; text-decoration: none; }
        .stats-box { display: inline-block; background: #f5f5f5; border: 1px solid #ccc;
                     padding: 10px 20px; margin: 5px; text-align: center; min-width: 120px; }
        .stats-box .number { font-size: 28px; font-weight: bold; color: #003366; }
        .stats-box .label  { font-size: 11px; color: #666; }
    </style>
    <!-- TODO: add jQuery (was going to do this in 2010, still plain JS) -->
    <script type="text/javascript">
        function confirmDelete(msg) {
            return confirm(msg || 'Are you sure you want to delete this?');
        }
        function submitQuickAction(action) {
            document.getElementById('quick_action_input').value = action;
            document.getElementById('quick_action_form').submit();
        }
    </script>
</head>
<body>

<div id="header">
    <h1><?php echo APP_NAME; ?></h1>
    <span style="color:#aaa;font-size:11px;margin-left:15px;">Freight &amp; Shipping Management System v<?php echo APP_VERSION; ?></span>
    <div class="user-info">
        <?php if (isset($_SESSION['username'])): ?>
            Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            &nbsp;|&nbsp;
            <a href="index.php?page=logout" style="color:#ffcc00;">Logout</a>
        <?php endif; ?>
    </div>
</div>

<div id="nav">
    <?php
    // Navigation - only show if logged in
    if (checkAuth()):
    $navItems = array(
        'dashboard'        => 'Dashboard',
        'orders'           => 'Orders',
        'order_new'        => 'New Order',
        'customers'        => 'Customers',
        'freight'          => 'Freight Rates',
        'invoices'         => 'Invoices',
        'tracking'         => 'Tracking',
        'reports'          => 'Reports',
        'admin'            => 'Admin',
    );
    foreach ($navItems as $navPage => $navLabel):
    $activeClass = ($page == $navPage) ? ' class="active"' : '';
    ?>
    <a href="index.php?page=<?php echo $navPage; ?>"<?php echo $activeClass; ?>><?php echo $navLabel; ?></a>
    <?php endforeach; endif; ?>
</div>

<div id="content">

<?php
// Show flash message if any
$flash = getFlash();
if ($flash):
?>
<div class="flash-<?php echo htmlspecialchars($flash['type']); ?>">
    <?php echo htmlspecialchars($flash['message']); ?>
</div>
<?php endif; ?>

<!-- Breadcrumb - NOTE: $page is from $_GET and echoed here without escaping = XSS -->
<div class="breadcrumb">
    <a href="index.php?page=dashboard">Home</a> &raquo;
    <?php echo ucwords(str_replace('_', ' ', $page)); ?>
    <!-- DEBUG: page param = <?php echo $_GET['page']; // direct XSS - noted in code review 2013 ?> -->
</div>

<?php
// -----------------------------------------------
// PAGE ROUTING - the main switch statement
// NOTE: This file handles 15+ page cases inline
// Each case includes the appropriate module file
// Some cases also have inline logic (mistakes from 2009-2011)
// -----------------------------------------------

switch ($page) {

    // -----------------------------------------------
    case 'dashboard':
    // -----------------------------------------------
        // Dashboard has inline stats queries - should use a DashboardService - 2011
        ?>
        <h2>Dashboard</h2>
        <?php
        // Quick stats - raw queries in index.php, I know - Dave 2011
        $totalOrders    = query_row("SELECT COUNT(*) as cnt FROM orders WHERE status != 'archived'");
        $openOrders     = query_row("SELECT COUNT(*) as cnt FROM orders WHERE status IN ('new','processing','on_hold')");
        $shippedToday   = query_row("SELECT COUNT(*) as cnt FROM orders WHERE status='shipped' AND DATE(updated_at)=CURDATE()");
        $pendingInvoices= query_row("SELECT COUNT(*) as cnt FROM invoices WHERE status='pending'");
        $overdueInvoices= query_row("SELECT COUNT(*) as cnt FROM invoices WHERE status='pending' AND due_date < CURDATE()");
        ?>
        <div style="margin-bottom:20px;">
            <div class="stats-box">
                <div class="number"><?php echo $totalOrders ? $totalOrders['cnt'] : 0; ?></div>
                <div class="label">Total Active Orders</div>
            </div>
            <div class="stats-box">
                <div class="number"><?php echo $openOrders ? $openOrders['cnt'] : 0; ?></div>
                <div class="label">Open Orders</div>
            </div>
            <div class="stats-box">
                <div class="number"><?php echo $shippedToday ? $shippedToday['cnt'] : 0; ?></div>
                <div class="label">Shipped Today</div>
            </div>
            <div class="stats-box">
                <div class="number"><?php echo $pendingInvoices ? $pendingInvoices['cnt'] : 0; ?></div>
                <div class="label">Pending Invoices</div>
            </div>
            <div class="stats-box" style="<?php echo ($overdueInvoices && $overdueInvoices['cnt'] > 0) ? 'background:#f2dede;' : ''; ?>">
                <div class="number" style="<?php echo ($overdueInvoices && $overdueInvoices['cnt'] > 0) ? 'color:red;' : ''; ?>">
                    <?php echo $overdueInvoices ? $overdueInvoices['cnt'] : 0; ?>
                </div>
                <div class="label">Overdue Invoices</div>
            </div>
        </div>

        <h3>Recent Orders</h3>
        <?php
        // Inline query - should be in OrderManager but "it's just the dashboard" - 2011
        $recentOrders = query_all("SELECT o.*, c.company_name
                                   FROM orders o
                                   LEFT JOIN customers c ON o.customer_id = c.id
                                   ORDER BY o.created_at DESC LIMIT 10");
        if ($recentOrders):
        ?>
        <table class="data-table">
            <tr>
                <th>Order #</th><th>Customer</th><th>Status</th>
                <th>Total</th><th>Date</th><th>Actions</th>
            </tr>
            <?php foreach ($recentOrders as $ro): ?>
            <tr>
                <td><a href="index.php?page=order_detail&id=<?php echo $ro['id']; ?>"><?php echo h($ro['order_number']); ?></a></td>
                <td><?php echo h($ro['company_name']); ?></td>
                <td><?php echo h($ro['status']); ?></td>
                <td><?php echo formatMoney($ro['total_amount']); ?></td>
                <td><?php echo formatDate($ro['created_at']); ?></td>
                <td>
                    <a href="index.php?page=order_detail&id=<?php echo $ro['id']; ?>" class="btn">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p>No recent orders found.</p>
        <?php endif; ?>

        <!-- Quick action form - lives here in index.php which is wrong but "convenient" -->
        <h3>Quick Actions</h3>
        <form id="quick_action_form" method="POST" action="index.php">
            <input type="hidden" name="quick_action" id="quick_action_input" value="" />
            Order ID: <input type="text" name="order_id" size="8" />
            New Status: <select name="new_status">
                <option value="processing">Processing</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
                <option value="on_hold">On Hold</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <button type="button" onclick="submitQuickAction('quick_order_status')" class="btn">Update Status</button>
            &nbsp;&nbsp;
            Invoice for Order ID: <input type="text" name="invoice_order_id" size="8" />
            <button type="button" onclick="submitQuickAction('quick_invoice')" class="btn btn-success">Generate Invoice</button>
        </form>
        <?php
        break;

    // -----------------------------------------------
    case 'orders':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/orders/order_list.php');
        break;

    // -----------------------------------------------
    case 'order_detail':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/orders/order_detail.php');
        break;

    // -----------------------------------------------
    case 'order_new':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/orders/OrderManager.php');
        $om = new OrderManager();

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_order'])) {
            // Handle new order form submission
            // NOTE: using extract() here - brings all POST vars into scope
            // This is dangerous but was "convenient" when written - 2010
            extract($_POST); // $customer_id, $ship_name, $ship_addr, etc. all appear magically

            $orderData = array(
                'customer_id'    => (int)$customer_id,
                'ship_to_name'   => $ship_to_name,   // from extract($_POST) - not sanitized
                'ship_to_addr1'  => $ship_to_addr1,
                'ship_to_addr2'  => isset($ship_to_addr2) ? $ship_to_addr2 : '',
                'ship_to_city'   => $ship_to_city,
                'ship_to_state'  => $ship_to_state,
                'ship_to_zip'    => $ship_to_zip,
                'carrier_id'     => isset($carrier_id) ? (int)$carrier_id : DEFAULT_CARRIER_ID,
                'notes'          => isset($order_notes) ? $order_notes : '',
                'payment_terms'  => isset($payment_terms) ? (int)$payment_terms : TERMS_NET30,
            );

            $newOrderId = $om->createOrder($orderData);
            if ($newOrderId) {
                setFlash('Order created successfully! Order ID: ' . $newOrderId, 'success');
                header('Location: index.php?page=order_detail&id=' . $newOrderId);
                exit;
            } else {
                setFlash('Failed to create order. Check all required fields.', 'error');
            }
        }

        // Render the new order form
        $customers = query_all("SELECT id, company_name FROM customers WHERE active=1 ORDER BY company_name");
        $carriers  = query_all("SELECT id, name FROM carriers WHERE active=1 ORDER BY name");
        ?>
        <h2>New Order</h2>
        <form method="POST" action="index.php?page=order_new">
            <div class="form-row">
                <label>Customer: <span class="required">*</span></label>
                <select name="customer_id" required>
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $cust): ?>
                    <option value="<?php echo $cust['id']; ?>"><?php echo h($cust['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>Ship To Name: <span class="required">*</span></label>
                <input type="text" name="ship_to_name" size="40" required />
            </div>
            <div class="form-row">
                <label>Address Line 1: <span class="required">*</span></label>
                <input type="text" name="ship_to_addr1" size="40" required />
            </div>
            <div class="form-row">
                <label>Address Line 2:</label>
                <input type="text" name="ship_to_addr2" size="40" />
            </div>
            <div class="form-row">
                <label>City: <span class="required">*</span></label>
                <input type="text" name="ship_to_city" size="25" required />
                &nbsp;State: <select name="ship_to_state">
                    <option value="AL">AL</option><option value="AK">AK</option>
                    <option value="AZ">AZ</option><option value="AR">AR</option>
                    <option value="CA">CA</option><option value="CO">CO</option>
                    <option value="CT">CT</option><option value="DE">DE</option>
                    <option value="FL">FL</option><option value="GA">GA</option>
                    <option value="IL">IL</option><option value="IN">IN</option>
                    <option value="KY">KY</option><option value="MA">MA</option>
                    <option value="MI">MI</option><option value="MN">MN</option>
                    <option value="MO">MO</option><option value="NJ">NJ</option>
                    <option value="NY">NY</option><option value="NC">NC</option>
                    <option value="OH">OH</option><option value="PA">PA</option>
                    <option value="TN">TN</option><option value="TX">TX</option>
                    <option value="VA">VA</option><option value="WA">WA</option>
                    <option value="WI">WI</option>
                </select>
                &nbsp;Zip: <input type="text" name="ship_to_zip" size="10" required />
            </div>
            <div class="form-row">
                <label>Carrier:</label>
                <select name="carrier_id">
                    <?php foreach ($carriers as $carrier): ?>
                    <option value="<?php echo $carrier['id']; ?>"
                        <?php echo ($carrier['id'] == DEFAULT_CARRIER_ID) ? 'selected="selected"' : ''; ?>>
                        <?php echo h($carrier['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>Payment Terms:</label>
                <select name="payment_terms">
                    <option value="30">Net 30</option>
                    <option value="15">Net 15</option>
                    <option value="60">Net 60</option>
                    <option value="0">COD</option>
                </select>
            </div>
            <div class="form-row">
                <label>Notes:</label>
                <textarea name="order_notes" rows="3" cols="50"></textarea>
            </div>
            <div class="form-row">
                <label>&nbsp;</label>
                <input type="submit" name="submit_order" value="Create Order" class="btn btn-success" />
            </div>
        </form>
        <?php
        break;

    // -----------------------------------------------
    case 'customers':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/customers/customer_list.php');
        break;

    // -----------------------------------------------
    case 'customer_detail':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/customers/CustomerDB.php');
        $cdb = new CustomerDB();
        $custId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $customer = $cdb->getCustomer($custId);
        if (!$customer) {
            echo '<p style="color:red;">Customer not found.</p>';
            break;
        }
        $orders  = $cdb->getCustomerOrders($custId);
        $balance = $cdb->getCustomerBalance($custId);
        ?>
        <h2>Customer: <?php echo h($customer['company_name']); ?></h2>
        <table style="width:100%;margin-bottom:15px;">
            <tr>
                <td style="width:50%;vertical-align:top;">
                    <strong>Contact:</strong> <?php echo h($customer['contact_name']); ?><br/>
                    <strong>Email:</strong> <?php echo h($customer['email']); ?><br/>
                    <strong>Phone:</strong> <?php echo h($customer['phone']); ?><br/>
                    <strong>Account #:</strong> <?php echo h($customer['account_number']); ?>
                </td>
                <td style="width:50%;vertical-align:top;">
                    <strong>Address:</strong><br/>
                    <?php echo h($customer['addr1']); ?><br/>
                    <?php if ($customer['addr2']): ?><?php echo h($customer['addr2']); ?><br/><?php endif; ?>
                    <?php echo h($customer['city']) . ', ' . h($customer['state']) . ' ' . h($customer['zip']); ?><br/>
                </td>
            </tr>
        </table>
        <p><strong>Outstanding Balance:</strong> <?php echo formatMoney($balance); ?></p>
        <h3>Order History</h3>
        <?php if ($orders): ?>
        <table class="data-table">
            <tr><th>Order #</th><th>Date</th><th>Status</th><th>Total</th><th></th></tr>
            <?php foreach ($orders as $ord): ?>
            <tr>
                <td><?php echo h($ord['order_number']); ?></td>
                <td><?php echo formatDate($ord['created_at']); ?></td>
                <td><?php echo h($ord['status']); ?></td>
                <td><?php echo formatMoney($ord['total_amount']); ?></td>
                <td><a href="index.php?page=order_detail&id=<?php echo $ord['id']; ?>" class="btn">View</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p>No orders found for this customer.</p>
        <?php endif; ?>
        <?php
        break;

    // -----------------------------------------------
    case 'freight':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/freight/freight_form.php');
        break;

    // -----------------------------------------------
    case 'invoices':
    // -----------------------------------------------
        // Invoice list - inline in router because we "never got around to" a module file
        require_once(APP_ROOT . '/modules/invoicing/InvoiceGen.php');
        $ig = new InvoiceGen();
        $statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
        $sql = "SELECT i.*, o.order_number, c.company_name
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                LEFT JOIN customers c ON o.customer_id = c.id
                WHERE 1=1";
        if ($statusFilter) {
            $sql .= " AND i.status='" . mysql_real_escape_string($statusFilter) . "'";
        }
        $sql .= " ORDER BY i.created_at DESC LIMIT 200";
        $invoices = query_all($sql);
        ?>
        <h2>Invoices</h2>
        <p>Filter:
            <a href="index.php?page=invoices">All</a> |
            <a href="index.php?page=invoices&status=pending">Pending</a> |
            <a href="index.php?page=invoices&status=paid">Paid</a> |
            <a href="index.php?page=invoices&status=overdue">Overdue</a> |
            <a href="index.php?page=invoices&status=voided">Voided</a>
        </p>
        <table class="data-table">
            <tr><th>Invoice #</th><th>Order #</th><th>Customer</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
            <?php foreach ($invoices as $inv): ?>
            <tr>
                <td><?php echo h($inv['invoice_number']); ?></td>
                <td><a href="index.php?page=order_detail&id=<?php echo $inv['order_id']; ?>"><?php echo h($inv['order_number']); ?></a></td>
                <td><?php echo h($inv['company_name']); ?></td>
                <td><?php echo formatMoney($inv['total_amount']); ?></td>
                <td style="<?php echo (strtotime($inv['due_date']) < time() && $inv['status']=='pending') ? 'color:red;font-weight:bold;' : ''; ?>">
                    <?php echo formatDate($inv['due_date']); ?>
                </td>
                <td><?php echo h($inv['status']); ?></td>
                <td>
                    <a href="index.php?page=invoice_detail&id=<?php echo $inv['id']; ?>" class="btn">View</a>
                    <?php if ($inv['status'] == 'pending'): ?>
                    <a href="index.php?page=invoice_pay&id=<?php echo $inv['id']; ?>" class="btn btn-success">Mark Paid</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php
        break;

    // -----------------------------------------------
    case 'invoice_detail':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/invoicing/InvoiceGen.php');
        $ig = new InvoiceGen();
        $invId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $invoiceHtml = $ig->generateInvoiceHTML($invId);
        echo $invoiceHtml;
        break;

    // -----------------------------------------------
    case 'invoice_pay':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/invoicing/InvoiceGen.php');
        $ig = new InvoiceGen();
        $invId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['payment_ref'])) {
            $payRef = $_POST['payment_ref']; // not sanitized
            $result = $ig->markAsPaid($invId, $payRef);
            if ($result) {
                setFlash('Invoice marked as paid. Reference: ' . $payRef, 'success');
                header('Location: index.php?page=invoice_detail&id=' . $invId);
                exit;
            } else {
                setFlash('Failed to mark invoice as paid.', 'error');
            }
        }

        $invoice = $ig->getInvoice($invId);
        ?>
        <h2>Mark Invoice Paid: <?php echo h($invoice ? $invoice['invoice_number'] : ''); ?></h2>
        <form method="POST" action="index.php?page=invoice_pay&id=<?php echo $invId; ?>">
            <div class="form-row">
                <label>Payment Reference #:</label>
                <input type="text" name="payment_ref" size="30" required />
            </div>
            <div class="form-row">
                <label>&nbsp;</label>
                <input type="submit" value="Mark as Paid" class="btn btn-success" />
            </div>
        </form>
        <?php
        break;

    // -----------------------------------------------
    case 'invoice_pdf':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/invoicing/invoice_pdf.php');
        break;

    // -----------------------------------------------
    case 'tracking':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/tracking/tracking_widget.php');
        break;

    // -----------------------------------------------
    case 'reports':
    // -----------------------------------------------
        // Reports page - mostly inline queries
        // A proper reports module was planned in 2011, never built
        require_once(APP_ROOT . '/modules/orders/OrderManager.php');
        $om = new OrderManager();

        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');
        $rptStatus = isset($_GET['rpt_status']) ? $_GET['rpt_status'] : null;

        $reportData = $om->getOrderReport($startDate, $endDate, $rptStatus);
        ?>
        <h2>Order Reports</h2>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="reports" />
            Start: <input type="text" name="start_date" value="<?php echo h($startDate); ?>" size="12" />
            End: <input type="text" name="end_date" value="<?php echo h($endDate); ?>" size="12" />
            Status: <select name="rpt_status">
                <option value="">All</option>
                <option value="new">New</option>
                <option value="processing">Processing</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <input type="submit" value="Run Report" class="btn" />
        </form>
        <br/>
        <?php if ($reportData): ?>
        <table class="data-table">
            <tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th>Freight</th><th>Total</th></tr>
            <?php
            $grandTotal = 0;
            foreach ($reportData as $rrow):
                $grandTotal += $rrow['total_amount'];
            ?>
            <tr>
                <td><?php echo h($rrow['order_number']); ?></td>
                <td><?php echo h($rrow['company_name']); ?></td>
                <td><?php echo formatDate($rrow['created_at']); ?></td>
                <td><?php echo h($rrow['status']); ?></td>
                <td><?php echo formatMoney($rrow['freight_amount']); ?></td>
                <td><?php echo formatMoney($rrow['total_amount']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="font-weight:bold;background:#e0e0e0;">
                <td colspan="5" align="right">Grand Total:</td>
                <td><?php echo formatMoney($grandTotal); ?></td>
            </tr>
        </table>
        <p><?php echo count($reportData); ?> orders in this period.</p>
        <?php else: ?>
        <p>No orders found for this date range.</p>
        <?php endif; ?>
        <?php
        break;

    // -----------------------------------------------
    case 'search':
    // -----------------------------------------------
        $searchQ = isset($_GET['q']) ? $_GET['q'] : '';
        require_once(APP_ROOT . '/modules/customers/CustomerDB.php');
        $cdb = new CustomerDB();
        $custResults = $cdb->searchCustomers($searchQ); // SQL injection in here
        ?>
        <h2>Search Results for: "<?php echo $searchQ; // XSS - noted in 2013 review ?>"</h2>
        <h3>Customers (<?php echo count($custResults); ?>)</h3>
        <?php if ($custResults): ?>
        <table class="data-table">
            <tr><th>Company</th><th>Contact</th><th>City</th><th>Phone</th><th></th></tr>
            <?php foreach ($custResults as $cr): ?>
            <tr>
                <td><?php echo h($cr['company_name']); ?></td>
                <td><?php echo h($cr['contact_name']); ?></td>
                <td><?php echo h($cr['city']) . ', ' . h($cr['state']); ?></td>
                <td><?php echo h($cr['phone']); ?></td>
                <td><a href="index.php?page=customer_detail&id=<?php echo $cr['id']; ?>" class="btn">View</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p>No customers found.</p>
        <?php endif; ?>

        <?php
        // Also search orders - inline
        if (!empty($searchQ)) {
            $safeQ = mysql_real_escape_string($searchQ);
            $orderResults = query_all("SELECT o.*, c.company_name FROM orders o
                                       LEFT JOIN customers c ON o.customer_id = c.id
                                       WHERE o.order_number LIKE '%" . $safeQ . "%'
                                       OR c.company_name LIKE '%" . $safeQ . "%'
                                       ORDER BY o.created_at DESC LIMIT 50");
            ?>
            <h3>Orders (<?php echo count($orderResults); ?>)</h3>
            <?php if ($orderResults): ?>
            <table class="data-table">
                <tr><th>Order #</th><th>Customer</th><th>Status</th><th>Date</th><th></th></tr>
                <?php foreach ($orderResults as $or): ?>
                <tr>
                    <td><?php echo h($or['order_number']); ?></td>
                    <td><?php echo h($or['company_name']); ?></td>
                    <td><?php echo h($or['status']); ?></td>
                    <td><?php echo formatDate($or['created_at']); ?></td>
                    <td><a href="index.php?page=order_detail&id=<?php echo $or['id']; ?>" class="btn">View</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
            <?php
        }
        break;

    // -----------------------------------------------
    case 'admin':
    // -----------------------------------------------
        // Check if superadmin
        if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            echo '<p style="color:red;">Access denied. Admin only.</p>';
            break;
        }
        ?>
        <h2>Administration</h2>
        <h3>System Information</h3>
        <table style="font-size:11px;">
            <tr><td><strong>PHP Version:</strong></td><td><?php echo phpversion(); ?></td></tr>
            <tr><td><strong>MySQL Version:</strong></td><td><?php echo @mysql_get_server_info(); ?></td></tr>
            <tr><td><strong>Server:</strong></td><td><?php echo $_SERVER['SERVER_SOFTWARE']; ?></td></tr>
            <tr><td><strong>App Version:</strong></td><td><?php echo APP_VERSION; ?></td></tr>
            <tr><td><strong>Total Queries This Request:</strong></td><td><?php echo $GLOBALS['query_count']; ?></td></tr>
            <tr><td><strong>Memory Usage:</strong></td><td><?php echo number_format(memory_get_usage()/1024/1024, 2); ?> MB</td></tr>
            <tr><td><strong>Debug Mode:</strong></td><td><?php echo APP_DEBUG ? 'ON' : 'OFF'; ?></td></tr>
        </table>

        <?php if (APP_DEBUG && !empty($GLOBALS['error_log'])): ?>
        <h3>Query Errors This Request</h3>
        <pre style="background:#fee;border:1px solid #f00;padding:10px;font-size:10px;overflow:auto;">
        <?php print_r($GLOBALS['error_log']); ?>
        </pre>
        <?php endif; ?>

        <h3>Tools</h3>
        <a href="index.php?page=admin&action=archive_orders" class="btn"
           onclick="return confirm('Archive old orders? This may take a while.');">Archive Old Orders</a>
        &nbsp;
        <a href="index.php?page=admin&action=recalc_totals" class="btn">Recalculate All Totals</a>
        &nbsp;
        <a href="index.php?page=admin&action=clear_cache" class="btn">Clear Freight Cache</a>

        <?php
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'archive_orders':
                    require_once(APP_ROOT . '/modules/orders/OrderManager.php');
                    $om = new OrderManager();
                    echo '<p>Running archive... (this may take a while)</p>';
                    $om->archiveOldOrders();
                    echo '<p style="color:green;">Archive complete.</p>';
                    break;
                case 'recalc_totals':
                    require_once(APP_ROOT . '/modules/orders/OrderManager.php');
                    $om = new OrderManager();
                    $om->hackfix_recalculate_totals();
                    echo '<p style="color:green;">Totals recalculated.</p>';
                    break;
                case 'clear_cache':
                    $files = glob(CACHE_DIR . 'freight_*.cache');
                    $count = 0;
                    if ($files) {
                        foreach ($files as $f) {
                            @unlink($f);
                            $count++;
                        }
                    }
                    echo '<p style="color:green;">Cleared ' . $count . ' cache files.</p>';
                    break;
            }
        }
        break;

    // -----------------------------------------------
    case 'login':
    // -----------------------------------------------
        require_once(APP_ROOT . '/modules/auth/login.php');
        break;

    // -----------------------------------------------
    case 'logout':
    // -----------------------------------------------
        @session_start();
        // Log the logout for audit
        if (isset($_SESSION['user_id'])) {
            auditLog('logout', 'user', $_SESSION['user_id'], 'User logged out');
        }
        session_destroy();
        header('Location: index.php?page=login&msg=logged_out');
        exit;
        break;

    // -----------------------------------------------
    case 'track':
    // -----------------------------------------------
        // Public tracking page
        require_once(APP_ROOT . '/modules/tracking/tracking_widget.php');
        break;

    // -----------------------------------------------
    case 'freight_estimate':
    // -----------------------------------------------
        // Public freight estimate - no auth required
        require_once(APP_ROOT . '/modules/freight/freight_form.php');
        break;

    // -----------------------------------------------
    case 'pick_list':
    // -----------------------------------------------
        // Print pick list for warehouse
        require_once(APP_ROOT . '/modules/orders/OrderManager.php');
        $om = new OrderManager();
        $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $om->generatePickList($orderId); // outputs HTML directly
        break;

    // -----------------------------------------------
    default:
    // -----------------------------------------------
        ?>
        <h2>Page Not Found</h2>
        <p>The page "<strong><?php echo h($page); ?></strong>" was not found.</p>
        <p><a href="index.php?page=dashboard">Return to Dashboard</a></p>
        <?php
        break;

} // end switch ($page)
?>

</div><!-- #content -->

<!-- Footer - also inline in index.php -->
<div id="footer">
    <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> &copy; <?php echo date('Y'); ?> Northwind Logistics Inc.
    All rights reserved. &nbsp;|&nbsp;
    <a href="index.php?page=track">Track Shipment</a> &nbsp;|&nbsp;
    <a href="index.php?page=freight_estimate">Freight Estimate</a>
    <?php if (APP_DEBUG): ?>
    <br/><small style="color:#aaa;">
        Queries: <?php echo $GLOBALS['query_count']; ?> |
        Time: <?php echo round((microtime(true) - $GLOBALS['app_start_time']) * 1000); ?>ms |
        Mem: <?php echo number_format(memory_get_usage()/1024/1024, 2); ?>MB
    </small>
    <?php endif; ?>
    <!-- DO NOT REMOVE: Bob's debug mode toggle -->
    <?php if (isset($_GET['sql_debug'])): ?>
    <br/><small style="color:red;">SQL DEBUG MODE ACTIVE</small>
    <?php endif; ?>
</div>

</body>
</html>
