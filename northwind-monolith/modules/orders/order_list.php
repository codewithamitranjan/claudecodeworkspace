<?php
// modules/orders/order_list.php - Order list view
// Created 2008-06-10
// Mixed PHP and HTML because that was "how we did it" in 2008
// The pagination here uses $_GET['page'] which CONFLICTS with the main router
// See index.php's switch - both the router and this file use $_GET['page']
// The pagination has to use a different variable name or it breaks navigation
// TODO: fix this conflict (noted 2010, workaround added 2011 - using $_GET['opage'] now)
// But some links still use $_GET['page'] for pagination so it's broken sometimes - 2013

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/orders/OrderManager.php');

$om = new OrderManager();

// Filters from GET - not sanitized properly
$filterStatus   = isset($_GET['status'])    ? $_GET['status']    : '';
$filterCustomer = isset($_GET['cust_id'])   ? (int)$_GET['cust_id'] : 0;
$filterStart    = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterEnd      = isset($_GET['date_to'])   ? $_GET['date_to']   : '';
$sortBy         = isset($_GET['sort'])      ? $_GET['sort']      : 'created_at';
$sortDir        = isset($_GET['dir'])       ? $_GET['dir']       : 'DESC';

// Pagination - using 'opage' to avoid conflict with main router's 'page' parameter
// But this is confusing and some bookmarked URLs still use 'page' for this - 2011
$currentPage = isset($_GET['opage']) ? max(1, (int)$_GET['opage']) : 1;
$perPage     = RECORDS_PER_PAGE;

// Handle bulk actions (form POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction  = $_POST['bulk_action'];
    $selectedIds = isset($_POST['selected_orders']) ? $_POST['selected_orders'] : array();

    if (!empty($selectedIds) && !empty($bulkAction)) {
        foreach ($selectedIds as $selId) {
            $selId = (int)$selId;
            if ($selId <= 0) continue;

            switch ($bulkAction) {
                case 'mark_processing':
                    $om->updateOrderStatus($selId, 'processing');
                    break;
                case 'mark_shipped':
                    $om->updateOrderStatus($selId, 'shipped');
                    break;
                case 'mark_cancelled':
                    $om->updateOrderStatus($selId, 'cancelled');
                    break;
                case 'delete':
                    $om->deleteOrder($selId); // soft delete
                    break;
                case 'archive':
                    $om->updateOrderStatus($selId, 'archived');
                    break;
            }
        }
        setFlash('Bulk action "' . htmlspecialchars($bulkAction) . '" applied to ' . count($selectedIds) . ' orders.', 'success');
        header('Location: index.php?page=orders');
        exit;
    }
}

// Build the main orders query
// String concat SQL - the $sortBy is from $_GET and could be injection if not validated
// We have a whitelist check but it's after the variable is set, not before use - 2011
$allowedSortCols = array('id', 'order_number', 'created_at', 'status', 'total_amount', 'company_name');
if (!in_array($sortBy, $allowedSortCols)) {
    $sortBy = 'created_at'; // default
}
$sortDir = ($sortDir == 'ASC') ? 'ASC' : 'DESC';

$sql = "SELECT o.*, c.company_name, c.account_number, cr.name as carrier_name
        FROM orders o
        LEFT JOIN customers c  ON o.customer_id = c.id
        LEFT JOIN carriers  cr ON o.carrier_id  = cr.id
        WHERE 1=1";

// Filter by status
if ($filterStatus) {
    $sql .= " AND o.status = '" . $filterStatus . "'"; // NOT escaped - $filterStatus from $_GET
}

// Filter by customer
if ($filterCustomer) {
    $sql .= " AND o.customer_id = " . $filterCustomer;
}

// Date filters
if ($filterStart) {
    $sql .= " AND o.created_at >= '" . $filterStart . "'"; // NOT escaped
}
if ($filterEnd) {
    $sql .= " AND o.created_at <= '" . $filterEnd . " 23:59:59'"; // NOT escaped
}

// Never show archived in the main list unless explicitly filtered
if ($filterStatus !== 'archived') {
    $sql .= " AND o.status != 'archived'";
}

$sql .= " ORDER BY " . $sortBy . " " . $sortDir;

// Get total count for pagination
$countSql = str_replace("SELECT o.*, c.company_name, c.account_number, cr.name as carrier_name", "SELECT COUNT(*) as total_count", $sql);
// NOTE: the above str_replace is fragile. If the SELECT changes it breaks pagination count.
// It broke in 2012 when we added cr.name and nobody noticed for 3 weeks. - Dave 2012
// TODO: use a proper count query
$countRow = query_row($countSql);
$totalOrders = $countRow ? (int)$countRow['total_count'] : 0;
$totalPages  = max(1, ceil($totalOrders / $perPage));

// Apply LIMIT/OFFSET for pagination
$offset = ($currentPage - 1) * $perPage;
$sql .= " LIMIT $perPage OFFSET $offset";

$orders = query_all($sql);

// Get customers list for filter dropdown
$customers = query_all("SELECT id, company_name FROM customers WHERE active=1 ORDER BY company_name");

// Summary counts by status (for display badges) - inline queries in the view. sorry. - 2011
$statusCounts = array();
$statusCountResult = query("SELECT status, COUNT(*) as cnt FROM orders WHERE status != 'archived' GROUP BY status");
if ($statusCountResult) {
    while ($scRow = mysql_fetch_assoc($statusCountResult)) {
        $statusCounts[$scRow['status']] = $scRow['cnt'];
    }
}

?>

<h2>Orders
    <?php if ($totalOrders > 0): ?>
    <span style="font-size:13px;font-weight:normal;color:#666;">(<?php echo $totalOrders; ?> total)</span>
    <?php endif; ?>
</h2>

<!-- Status filter badges - inline style, as God intended in 2009 -->
<div style="margin-bottom:12px;">
    <a href="index.php?page=orders" style="text-decoration:none;">
        <span style="background:#003366;color:white;padding:3px 8px;font-size:11px;">All</span>
    </a>
    <?php
    $badgeColors = array(
        'new'        => '#5bc0de',
        'processing' => '#f0ad4e',
        'shipped'    => '#5cb85c',
        'delivered'  => '#3c763d',
        'on_hold'    => '#ff8800',
        'disputed'   => '#cc0000',
        'cancelled'  => '#999999',
    );
    foreach ($badgeColors as $st => $color):
    $cnt = isset($statusCounts[$st]) ? $statusCounts[$st] : 0;
    ?>
    <a href="index.php?page=orders&status=<?php echo $st; ?>" style="text-decoration:none;">
        <span style="background:<?php echo $color; ?>;color:white;padding:3px 8px;font-size:11px;">
            <?php echo ucfirst($st); ?> (<?php echo $cnt; ?>)
        </span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filter form -->
<form method="GET" action="index.php" style="background:#f5f5f5;border:1px solid #ddd;padding:10px;margin-bottom:12px;">
    <input type="hidden" name="page" value="orders" />
    <strong>Filters:</strong>&nbsp;
    Customer:
    <select name="cust_id" style="font-size:11px;">
        <option value="">-- All --</option>
        <?php foreach ($customers as $fc): ?>
        <option value="<?php echo $fc['id']; ?>" <?php echo ($filterCustomer == $fc['id']) ? 'selected="selected"' : ''; ?>>
            <?php echo h($fc['company_name']); ?>
        </option>
        <?php endforeach; ?>
    </select>
    &nbsp;
    Status:
    <select name="status" style="font-size:11px;">
        <option value="">All</option>
        <option value="new"        <?php echo $filterStatus=='new'        ? 'selected' : ''; ?>>New</option>
        <option value="processing" <?php echo $filterStatus=='processing' ? 'selected' : ''; ?>>Processing</option>
        <option value="shipped"    <?php echo $filterStatus=='shipped'    ? 'selected' : ''; ?>>Shipped</option>
        <option value="delivered"  <?php echo $filterStatus=='delivered'  ? 'selected' : ''; ?>>Delivered</option>
        <option value="on_hold"    <?php echo $filterStatus=='on_hold'    ? 'selected' : ''; ?>>On Hold</option>
        <option value="cancelled"  <?php echo $filterStatus=='cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
        <option value="archived"   <?php echo $filterStatus=='archived'   ? 'selected' : ''; ?>>Archived</option>
    </select>
    &nbsp;
    From: <input type="text" name="date_from" value="<?php echo h($filterStart); ?>" size="10" placeholder="YYYY-MM-DD" style="font-size:11px;" />
    To:   <input type="text" name="date_to"   value="<?php echo h($filterEnd); ?>"   size="10" placeholder="YYYY-MM-DD" style="font-size:11px;" />
    &nbsp;
    <input type="submit" value="Filter" style="font-size:11px;padding:2px 8px;" />
    <a href="index.php?page=orders" style="font-size:11px;margin-left:8px;">Clear</a>
</form>

<!-- Bulk action form wrapper -->
<form method="POST" action="index.php?page=orders" id="bulk_form">
<div style="margin-bottom:8px;">
    Bulk Action:
    <select name="bulk_action" style="font-size:11px;">
        <option value="">-- Select Action --</option>
        <option value="mark_processing">Mark Processing</option>
        <option value="mark_shipped">Mark Shipped</option>
        <option value="mark_cancelled">Cancel</option>
        <option value="archive">Archive</option>
        <option value="delete">Delete (Soft)</option>
    </select>
    <button type="submit" style="font-size:11px;padding:2px 8px;"
            onclick="return confirm('Apply bulk action to selected orders?');">Apply</button>
    &nbsp;
    <a href="index.php?page=order_new" class="btn btn-success">+ New Order</a>
</div>

<?php if ($orders): ?>
<table class="data-table" cellpadding="0" cellspacing="0">
    <thead>
    <tr>
        <th width="25"><input type="checkbox" id="select_all" onclick="
            var chks=document.getElementsByName('selected_orders[]');
            for(var i=0;i<chks.length;i++){chks[i].checked=this.checked;}
        " /></th>
        <th><a href="index.php?page=orders&sort=order_number&dir=<?php echo ($sortBy=='order_number' && $sortDir=='ASC') ? 'DESC' : 'ASC'; ?>" style="color:white;">Order #</a></th>
        <th><a href="index.php?page=orders&sort=company_name&dir=<?php echo ($sortBy=='company_name' && $sortDir=='ASC') ? 'DESC' : 'ASC'; ?>" style="color:white;">Customer</a></th>
        <th><a href="index.php?page=orders&sort=status&dir=<?php echo ($sortBy=='status' && $sortDir=='ASC') ? 'DESC' : 'ASC'; ?>" style="color:white;">Status</a></th>
        <th>Carrier</th>
        <th>Tracking #</th>
        <th><a href="index.php?page=orders&sort=total_amount&dir=<?php echo ($sortBy=='total_amount' && $sortDir=='ASC') ? 'DESC' : 'ASC'; ?>" style="color:white;">Total</a></th>
        <th><a href="index.php?page=orders&sort=created_at&dir=<?php echo ($sortBy=='created_at' && $sortDir=='ASC') ? 'DESC' : 'ASC'; ?>" style="color:white;">Date</a></th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $order): ?>
    <?php
    // Status colors - inline style again
    $rowBg = '';
    switch ($order['status']) {
        case 'on_hold':    $rowBg = 'background:#fff3cd;'; break;
        case 'disputed':   $rowBg = 'background:#f2dede;'; break;
        case 'cancelled':  $rowBg = 'background:#f5f5f5;color:#999;'; break;
    }
    ?>
    <tr style="<?php echo $rowBg; ?>">
        <td><input type="checkbox" name="selected_orders[]" value="<?php echo $order['id']; ?>" /></td>
        <td>
            <a href="index.php?page=order_detail&id=<?php echo $order['id']; ?>">
                <?php echo h($order['order_number']); ?>
            </a>
        </td>
        <!-- XSS: company_name is echoed directly from DB without escaping in older code.
             We added h() in 2012 but there might still be places without it. - 2013 -->
        <td>
            <a href="index.php?page=customer_detail&id=<?php echo $order['customer_id']; ?>">
                <?php echo h($order['company_name']); ?>
            </a>
        </td>
        <td>
            <span style="background:<?php echo isset($badgeColors[$order['status']]) ? $badgeColors[$order['status']] : '#999'; ?>;
                         color:white;padding:1px 5px;font-size:10px;">
                <?php echo h($order['status']); ?>
            </span>
        </td>
        <td><?php echo h($order['carrier_name']); ?></td>
        <td>
            <?php if ($order['tracking_number']): ?>
            <a href="index.php?page=tracking&track=<?php echo urlencode($order['tracking_number']); ?>&carrier=<?php echo $order['carrier_id']; ?>">
                <?php echo h($order['tracking_number']); ?>
            </a>
            <?php else: ?>
            <span style="color:#999;font-style:italic;">none</span>
            <?php endif; ?>
        </td>
        <td style="text-align:right;"><?php echo formatMoney($order['total_amount']); ?></td>
        <td><?php echo formatDate($order['created_at']); ?></td>
        <td>
            <a href="index.php?page=order_detail&id=<?php echo $order['id']; ?>" class="btn">View</a>
            <a href="index.php?page=pick_list&id=<?php echo $order['id']; ?>" class="btn" target="_blank">Pick List</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- Pagination links - uses 'opage' instead of 'page' to avoid router conflict
     But this means the URL has both ?page=orders&opage=2 which is ugly
     Someone in 2011 "fixed" this by using opage but it's still ugly - Dave -->
<div style="margin-top:10px;">
    <?php if ($currentPage > 1): ?>
    <a href="index.php?page=orders&opage=<?php echo $currentPage - 1; ?>&status=<?php echo urlencode($filterStatus); ?>&cust_id=<?php echo $filterCustomer; ?>" class="btn">&laquo; Prev</a>
    <?php endif; ?>

    <?php for ($pg = max(1, $currentPage-3); $pg <= min($totalPages, $currentPage+3); $pg++): ?>
    <?php if ($pg == $currentPage): ?>
    <strong style="padding:3px 7px;background:#003366;color:white;"><?php echo $pg; ?></strong>
    <?php else: ?>
    <a href="index.php?page=orders&opage=<?php echo $pg; ?>&status=<?php echo urlencode($filterStatus); ?>&cust_id=<?php echo $filterCustomer; ?>" class="btn"><?php echo $pg; ?></a>
    <?php endif; ?>
    <?php endfor; ?>

    <?php if ($currentPage < $totalPages): ?>
    <a href="index.php?page=orders&opage=<?php echo $currentPage + 1; ?>&status=<?php echo urlencode($filterStatus); ?>&cust_id=<?php echo $filterCustomer; ?>" class="btn">Next &raquo;</a>
    <?php endif; ?>

    &nbsp;<span style="font-size:11px;color:#666;">
        Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
        (<?php echo $totalOrders; ?> orders)
    </span>
</div>

<?php else: ?>
<p style="color:#666;font-style:italic;">No orders found matching the current filters.</p>
<?php endif; ?>

</form><!-- end bulk action form -->
