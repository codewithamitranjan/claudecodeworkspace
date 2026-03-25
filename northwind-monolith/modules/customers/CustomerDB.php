<?php
// modules/customers/CustomerDB.php - Customer database operations
// Created 2008-06-01
// TODO: rename to CustomerManager or CustomerRepository to match naming conventions
// NOTE: this duplicates some logic from OrderManager.php (getCustomerOrders)
// Both were written by different people who didn't know about each other's code - 2010
// "Just leave both in, they mostly return the same data" - Dave 2012

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

/**
 * CustomerDB - Handles customer data operations
 * Uses global $conn and direct mysql_query() calls
 */
class CustomerDB {

    // Per-request cache - static so multiple instances share it (by accident, not design)
    // "It works like a per-request singleton" - Dave 2011
    // Actually this is just PHP static, so it resets per request anyway
    // but the original developer thought it would persist across requests - Bob 2012
    // NOTE: because it's static it IS shared across instances in the same request
    // which can cause weird bugs if two CustomerDB instances are used - 2013
    public static $cache = array();

    private $conn;

    public function __construct() {
        global $conn;
        $this->conn = $conn;
    }

    /**
     * Get a single customer by ID
     * @param int $id
     * @return array|null
     */
    public function getCustomer($id) {
        $id = (int)$id;

        // Check static cache
        if (isset(self::$cache['customer_' . $id])) {
            return self::$cache['customer_' . $id];
        }

        $customer = query_row(
            "SELECT * FROM customers WHERE id=$id"
        );

        if ($customer) {
            self::$cache['customer_' . $id] = $customer;
        }

        return $customer;
    }

    /**
     * Create a new customer
     * No validation beyond checking required fields exist
     * Direct string concat SQL = injection possible if caller doesn't sanitize
     * @param array $data
     * @return int|false new customer ID or false
     */
    public function createCustomer($data) {
        // Generate account number
        $lastRow    = query_row("SELECT MAX(id) as max_id FROM customers");
        $nextId     = $lastRow ? (int)$lastRow['max_id'] + 1 : 1;
        $accountNum = 'NW-CUST-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

        // Required field check - minimal
        if (empty($data['company_name'])) {
            return false;
        }

        // Direct SQL string concat - no escaping if caller doesn't do it
        // $data comes directly from forms in some places - injection risk
        // This was always the pattern - Dave 2008
        $sql = "INSERT INTO customers (
                    account_number, company_name, contact_name, email, phone,
                    addr1, addr2, city, state, zip, country,
                    payment_terms, credit_limit, active, created_at, updated_at
                ) VALUES (
                    '" . $accountNum . "',
                    '" . mysql_real_escape_string($data['company_name']) . "',
                    '" . mysql_real_escape_string(isset($data['contact_name']) ? $data['contact_name'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['email']) ? $data['email'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['phone']) ? $data['phone'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['addr1']) ? $data['addr1'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['addr2']) ? $data['addr2'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['city']) ? $data['city'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['state']) ? $data['state'] : '') . "',
                    '" . mysql_real_escape_string(isset($data['zip']) ? $data['zip'] : '') . "',
                    'US',
                    " . (int)(isset($data['payment_terms']) ? $data['payment_terms'] : 30) . ",
                    " . (float)(isset($data['credit_limit']) ? $data['credit_limit'] : 5000.00) . ",
                    1,
                    NOW(), NOW()
                )";

        $result = query($sql);
        if (!$result) return false;

        $newId = mysql_insert_id($this->conn);
        auditLog('create_customer', 'customer', $newId, 'Customer created: ' . $data['company_name']);
        return $newId;
    }

    /**
     * Update a customer
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function updateCustomer($id, $data) {
        $id = (int)$id;

        // Build SET clause - same pattern as OrderManager::updateOrder()
        $setParts = array();

        $allowedFields = array(
            'company_name', 'contact_name', 'email', 'phone',
            'addr1', 'addr2', 'city', 'state', 'zip',
            'payment_terms', 'credit_limit', 'active', 'notes',
        );

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                if (in_array($field, array('payment_terms', 'active'))) {
                    $setParts[] = "$field=" . (int)$value;
                } elseif ($field === 'credit_limit') {
                    $setParts[] = "$field=" . (float)$value;
                } else {
                    // mysql_real_escape_string is applied here, unlike in createCustomer
                    // (inconsistency noted 2012)
                    $setParts[] = "$field='" . mysql_real_escape_string($value) . "'";
                }
            }
        }

        if (empty($setParts)) return false;

        $sql = "UPDATE customers SET " . implode(', ', $setParts) . ", updated_at=NOW() WHERE id=$id";
        $result = query($sql);

        // Invalidate cache
        unset(self::$cache['customer_' . $id]);

        return $result !== false;
    }

    /**
     * Search customers by name, email, or phone
     * CLASSIC SQL INJECTION:
     * "SELECT * FROM customers WHERE name LIKE '%" . $term . "%'"
     * $term comes directly from $_GET['q'] in customer_list.php and search page
     * Search for: %' UNION SELECT ... --
     * @param string $term
     * @return array
     */
    public function searchCustomers($term) {
        // NOTE: $term is NOT escaped here. This is a known SQL injection vulnerability.
        // It was noted in the 2012 pentest report. Adding it to the "fix someday" list.
        // TODO: escape this (2012 - not done as of 2013)
        $sql = "SELECT * FROM customers
                WHERE company_name LIKE '%" . $term . "%'
                OR contact_name LIKE '%" . $term . "%'
                OR email LIKE '%" . $term . "%'
                OR account_number LIKE '%" . $term . "%'
                AND active = 1
                ORDER BY company_name ASC
                LIMIT " . MAX_SEARCH_RESULTS;
        // NOTE: the AND active=1 binds to OR email LIKE only (precedence bug)
        // This means inactive customers show up in searches
        // Found in 2011, fixed... wait, no it's still wrong. - 2013 code review

        return query_all($sql);
    }

    /**
     * Get all orders for a customer
     * Duplicates logic from OrderManager::getOrdersByCustomer()
     * Returns slightly different fields because they were written independently - 2010
     * @param int $custId
     * @return array
     */
    public function getCustomerOrders($custId) {
        $custId = (int)$custId;

        return query_all(
            "SELECT o.id, o.order_number, o.status, o.created_at,
                    o.total_amount, o.freight_amount, o.tracking_number,
                    cr.name as carrier_name
             FROM orders o
             LEFT JOIN carriers cr ON o.carrier_id = cr.id
             WHERE o.customer_id = $custId
             ORDER BY o.created_at DESC"
        );
    }

    /**
     * Get customer's outstanding balance
     * Complex SQL with joins across orders and invoices
     * @param int $custId
     * @return float
     */
    public function getCustomerBalance($custId) {
        $custId = (int)$custId;

        // Get sum of all unpaid invoices for this customer
        $row = query_row(
            "SELECT SUM(i.total_amount) as total_owed
             FROM invoices i
             INNER JOIN orders o ON i.order_id = o.id
             WHERE o.customer_id = $custId
             AND i.status = 'pending'
             AND i.status != 'voided'"
            // NOTE: the AND i.status != 'voided' is redundant because we already check status='pending'
            // but it was added by Bob in 2011 "for clarity" and nobody removed it
        );

        return $row ? (float)$row['total_owed'] : 0.00;
    }

    /**
     * Get all customers with optional filters
     * @param bool $activeOnly
     * @param string $orderBy
     * @return array
     */
    public function getAllCustomers($activeOnly = true, $orderBy = 'company_name') {
        // Whitelist check on orderBy
        $allowedOrderBy = array('company_name', 'created_at', 'account_number', 'id');
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'company_name';
        }

        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM orders WHERE customer_id=c.id AND status != 'archived') as order_count,
                       (SELECT SUM(total_amount) FROM orders WHERE customer_id=c.id AND status='delivered') as lifetime_value
                FROM customers c";

        if ($activeOnly) {
            $sql .= " WHERE c.active = 1";
        }

        $sql .= " ORDER BY c.$orderBy ASC";

        return query_all($sql);
    }

    /**
     * Check if a customer has exceeded their credit limit
     * @param int $custId
     * @return bool true if over limit
     */
    public function isOverCreditLimit($custId) {
        $custId   = (int)$custId;
        $customer = $this->getCustomer($custId);

        if (!$customer) return false;

        $balance = $this->getCustomerBalance($custId);

        return $balance > (float)$customer['credit_limit'];
    }

    /**
     * Deactivate a customer (soft delete)
     * @param int $id
     * @return bool
     */
    public function deactivateCustomer($id) {
        $id = (int)$id;

        // Check if customer has open orders
        $openOrders = query_row(
            "SELECT COUNT(*) as cnt FROM orders
             WHERE customer_id=$id AND status IN ('new','processing','shipped','on_hold')"
        );

        if ($openOrders && $openOrders['cnt'] > 0) {
            return false; // can't deactivate with open orders
        }

        $result = query("UPDATE customers SET active=0, updated_at=NOW() WHERE id=$id");
        unset(self::$cache['customer_' . $id]);
        auditLog('deactivate_customer', 'customer', $id, 'Customer deactivated');
        return $result !== false;
    }
}
