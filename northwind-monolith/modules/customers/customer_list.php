<?php
// modules/customers/customer_list.php - Customer list view
// Created 2008-06-10
// Mixed PHP and HTML throughout
// The search form posts to this same page (classic PHP self-post)

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/customers/CustomerDB.php');

$cdb = new CustomerDB();

// Handle new customer form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_customer_submit'])) {
    // NOTE: data comes straight from $_POST into createCustomer()
    // createCustomer does escape most fields but not all - 2013
    $newData = array(
        'company_name'  => $_POST['company_name'],
        'contact_name'  => $_POST['contact_name'],
        'email'         => $_POST['email'],
        'phone'         => $_POST['phone'],
        'addr1'         => $_POST['addr1'],
        'addr2'         => isset($_POST['addr2']) ? $_POST['addr2'] : '',
        'city'          => $_POST['city'],
        'state'         => $_POST['state'],
        'zip'           => $_POST['zip'],
        'payment_terms' => isset($_POST['payment_terms']) ? (int)$_POST['payment_terms'] : 30,
        'credit_limit'  => isset($_POST['credit_limit']) ? $_POST['credit_limit'] : 5000.00,
    );

    $newCustId = $cdb->createCustomer($newData);

    if ($newCustId) {
        setFlash('Customer created successfully!', 'success');
        header('Location: index.php?page=customer_detail&id=' . $newCustId);
        exit;
    } else {
        setFlash('Failed to create customer. Company name is required.', 'error');
    }
}

// Search
$searchTerm = '';
if (isset($_GET['search']) || (isset($_POST['search_term']) && !isset($_POST['new_customer_submit']))) {
    $searchTerm = isset($_GET['search']) ? $_GET['search'] : $_POST['search_term'];
}

// Get customers
if ($searchTerm) {
    $customers = $cdb->searchCustomers($searchTerm); // SQL injection in here
} else {
    $customers = $cdb->getAllCustomers(true, 'company_name');
}

// Pagination
$currentPage = isset($_GET['cpage']) ? max(1, (int)$_GET['cpage']) : 1;
$perPage     = RECORDS_PER_PAGE;
$totalCusts  = count($customers);
$totalPages  = max(1, ceil($totalCusts / $perPage));
$offset      = ($currentPage - 1) * $perPage;
$pageCustomers = array_slice($customers, $offset, $perPage);

?>

<h2>Customers</h2>

<!-- Search form - posts to self -->
<form method="GET" action="index.php" style="margin-bottom:15px;">
    <input type="hidden" name="page" value="customers" />
    <input type="text" name="search" value="<?php echo h($searchTerm); ?>"
           placeholder="Search by name, email, account #..."
           style="border:1px solid #ccc;padding:5px;width:300px;" />
    <input type="submit" value="Search" style="padding:5px 10px;" />
    <?php if ($searchTerm): ?>
    <a href="index.php?page=customers" style="margin-left:10px;">Clear Search</a>
    <!-- Search term echoed directly in results header without escaping - XSS possible -->
    <?php endif; ?>
</form>

<?php if ($searchTerm): ?>
<p>Search results for: <strong><?php echo $searchTerm; /* XSS - noted in 2012 audit */ ?></strong>
   (<?php echo $totalCusts; ?> found)</p>
<?php endif; ?>

<div style="float:right;margin-bottom:10px;">
    <a href="#new_customer_form" class="btn btn-success">+ Add New Customer</a>
</div>

<table class="data-table" style="width:100%;">
    <thead>
    <tr>
        <th>Account #</th>
        <th>Company Name</th>
        <th>Contact</th>
        <th>City, State</th>
        <th>Phone</th>
        <th>Orders</th>
        <th>Balance</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($pageCustomers): ?>
    <?php foreach ($pageCustomers as $cust): ?>
    <tr>
        <td><?php echo h($cust['account_number']); ?></td>
        <td>
            <a href="index.php?page=customer_detail&id=<?php echo $cust['id']; ?>">
                <strong><?php echo h($cust['company_name']); ?></strong>
            </a>
        </td>
        <td><?php echo h($cust['contact_name']); ?></td>
        <td><?php echo h($cust['city']); ?>, <?php echo h($cust['state']); ?></td>
        <td><?php echo h($cust['phone']); ?></td>
        <td style="text-align:center;">
            <?php echo isset($cust['order_count']) ? (int)$cust['order_count'] : '?'; ?>
        </td>
        <td style="text-align:right;">
            <?php
            // Customer balance shown here - this triggers a DB query per row
            // On a list of 500 customers this is 500 extra queries. Classic N+1 problem.
            // TODO: fix with a join in getAllCustomers() (2011 - not done)
            // Actually we do have it in the SQL above via subquery, so it's N subqueries
            // which is only slightly better - 2013
            $balance = $cdb->getCustomerBalance($cust['id']);
            $overLimit = $cdb->isOverCreditLimit($cust['id']);
            ?>
            <span style="<?php echo $overLimit ? 'color:red;font-weight:bold;' : ''; ?>">
                <?php echo formatMoney($balance); ?>
                <?php if ($overLimit): ?> &#9888;<?php endif; ?>
            </span>
        </td>
        <td>
            <a href="index.php?page=customer_detail&id=<?php echo $cust['id']; ?>" class="btn">View</a>
            <a href="index.php?page=orders&cust_id=<?php echo $cust['id']; ?>" class="btn">Orders</a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php else: ?>
    <tr>
        <td colspan="8" style="text-align:center;color:#999;padding:20px;font-style:italic;">
            <?php echo $searchTerm ? 'No customers found matching your search.' : 'No customers found.'; ?>
        </td>
    </tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="margin-top:10px;">
    <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
    <?php if ($pg == $currentPage): ?>
    <strong style="padding:3px 7px;background:#003366;color:white;"><?php echo $pg; ?></strong>
    <?php else: ?>
    <a href="index.php?page=customers&cpage=<?php echo $pg; ?>&search=<?php echo urlencode($searchTerm); ?>"
       style="padding:3px 7px;border:1px solid #ccc;text-decoration:none;"><?php echo $pg; ?></a>
    <?php endif; ?>
    <?php endfor; ?>
    <span style="font-size:11px;color:#666;">(<?php echo $totalCusts; ?> total customers)</span>
</div>
<?php endif; ?>

<!-- Add New Customer Form -->
<hr style="margin:30px 0;"/>
<h3 id="new_customer_form">Add New Customer</h3>

<form method="POST" action="index.php?page=customers" style="background:#f9f9f9;border:1px solid #ddd;padding:15px;">
    <table style="width:100%;border-collapse:collapse;">
    <tr>
        <td style="width:50%;vertical-align:top;padding-right:20px;">
            <div class="form-row">
                <label>Company Name: <span class="required">*</span></label>
                <input type="text" name="company_name" size="35"
                       value="<?php echo isset($_POST['company_name']) ? h($_POST['company_name']) : ''; ?>" />
            </div>
            <div class="form-row">
                <label>Contact Name:</label>
                <input type="text" name="contact_name" size="35"
                       value="<?php echo isset($_POST['contact_name']) ? h($_POST['contact_name']) : ''; ?>" />
            </div>
            <div class="form-row">
                <label>Email:</label>
                <input type="text" name="email" size="35"
                       value="<?php echo isset($_POST['email']) ? h($_POST['email']) : ''; ?>" />
            </div>
            <div class="form-row">
                <label>Phone:</label>
                <input type="text" name="phone" size="20"
                       value="<?php echo isset($_POST['phone']) ? h($_POST['phone']) : ''; ?>" />
            </div>
        </td>
        <td style="width:50%;vertical-align:top;">
            <div class="form-row">
                <label>Address Line 1:</label>
                <input type="text" name="addr1" size="35"
                       value="<?php echo isset($_POST['addr1']) ? h($_POST['addr1']) : ''; ?>" />
            </div>
            <div class="form-row">
                <label>Address Line 2:</label>
                <input type="text" name="addr2" size="35"
                       value="<?php echo isset($_POST['addr2']) ? h($_POST['addr2']) : ''; ?>" />
            </div>
            <div class="form-row">
                <label>City:</label>
                <input type="text" name="city" size="25"
                       value="<?php echo isset($_POST['city']) ? h($_POST['city']) : ''; ?>" />
                &nbsp;State:
                <select name="state">
                    <option value="">--</option>
                    <?php
                    $stateAbbrs = array('AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
                                        'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
                                        'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
                                        'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
                                        'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC');
                    foreach ($stateAbbrs as $sa):
                    ?>
                    <option value="<?php echo $sa; ?>" <?php echo (isset($_POST['state']) && $_POST['state']==$sa) ? 'selected' : ''; ?>><?php echo $sa; ?></option>
                    <?php endforeach; ?>
                </select>
                &nbsp;Zip: <input type="text" name="zip" size="10"
                                   value="<?php echo isset($_POST['zip']) ? h($_POST['zip']) : ''; ?>" />
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
                <label>Credit Limit: $</label>
                <input type="text" name="credit_limit" size="12" value="5000.00" />
            </div>
        </td>
    </tr>
    </table>
    <div style="margin-top:10px;">
        <input type="submit" name="new_customer_submit" value="Add Customer" class="btn btn-success" />
    </div>
</form>
