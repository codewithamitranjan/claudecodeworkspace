<?php
// modules/invoicing/InvoiceGen.php - Invoice Generation for Northwind Logistics
// Created 2008-08-01 by Dave
// Updated 2010, 2011, 2012, 2013
//
// CIRCULAR DEPENDENCY (part of the triangle):
// InvoiceGen requires FreightCalc (to calculate freight cost for invoice total)
// FreightCalc requires OrderManager
// OrderManager requires InvoiceGen (to check invoice before updating order)
//
// require_once guards prevent actual infinite loops:
// - First include loads InvoiceGen -> FreightCalc -> OrderManager -> InvoiceGen (no-op) -> done
// - All three classes defined after first require chain completes
//
// TODO: break this dependency chain somehow (2012 - everyone agrees, nobody has time)

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

// CIRCULAR DEP part 1: InvoiceGen requires FreightCalc
require_once(APP_ROOT . '/modules/freight/FreightCalc.php');
// This triggers FreightCalc.php to require OrderManager.php
// Which tries to require InvoiceGen.php but require_once says "already included"
// So OrderManager is defined without InvoiceGen being re-run
// Then FreightCalc is defined, then we get back here and InvoiceGen is defined

// Also require OrderManager directly (for markAsPaid calling back into orders)
// NOTE: this is redundant because FreightCalc already required it, but
// "defensive includes" was the style in 2009 - Bob
require_once(APP_ROOT . '/modules/orders/OrderManager.php');

// Global invoice sequence - breaks under concurrent requests
// Two simultaneous requests can get the same invoice number
// This happened in production on Black Friday 2012 - incident report filed, never fixed
// TODO: use DB AUTO_INCREMENT or SELECT ... FOR UPDATE (2012 - not done)
global $invoice_sequence;
$invoice_sequence = 0; // resets per request which makes it useless but here it is

/**
 * InvoiceGen - Invoice generation and management
 */
class InvoiceGen {

    private $conn;

    public function __construct() {
        global $conn;
        $this->conn = $conn;
    }

    // =========================================================
    // CHECK IF INVOICE EXISTS
    // =========================================================

    /**
     * Check if an invoice exists for an order
     * Called by OrderManager::updateOrder() - THIS IS THE CIRCULAR DEP in practice
     * @param int $orderId
     * @return bool
     */
    public function checkInvoiceExists($orderId) {
        $orderId = (int)$orderId;
        $row = query_row("SELECT id FROM invoices WHERE order_id=$orderId AND status != 'voided' LIMIT 1");
        return $row !== null;
    }

    // =========================================================
    // GENERATE INVOICE
    // =========================================================

    /**
     * Generate an invoice for an order
     * Calls FreightCalc to get freight total
     * Calls OrderManager to get order details
     * @param int $orderId
     * @return array|false invoice data or false on failure
     */
    public function generateInvoice($orderId) {
        $orderId = (int)$orderId;

        // Check if invoice already exists
        if ($this->checkInvoiceExists($orderId)) {
            // Return existing invoice
            return query_row("SELECT * FROM invoices WHERE order_id=$orderId AND status != 'voided' LIMIT 1");
        }

        // Get order details via OrderManager
        $om    = new OrderManager();
        $order = $om->getOrder($orderId);

        if (!$order) {
            return false;
        }

        // Get freight cost via FreightCalc
        $fc         = new FreightCalc();
        $freightCost = 0.00;

        if ($order['carrier_id']) {
            $calculatedFreight = $fc->calculateRate($orderId, $order['carrier_id']);
            if ($calculatedFreight !== false) {
                $freightCost = (float)$calculatedFreight;
            }
        }

        // If order already has a freight amount saved (manually set), use the higher value
        // "Always charge the higher of calculated vs manually set" - Dave 2011
        // This seems like it could overcharge customers but management approved it
        $billedFreight = max($freightCost, (float)$order['freight_amount']);

        // Get order items for line items
        $items    = $om->getOrderItems($orderId);
        $subtotal = 0.00;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }

        $discountAmt = (float)$order['discount_amount'];
        $taxAmt      = 0.00; // B2B no tax (see OrderManager for the TODO about this)
        $totalAmt    = $subtotal + $billedFreight - $discountAmt + $taxAmt;

        // Generate invoice number
        $invoiceNum = generateInvoiceNumber();

        // Calculate due date based on payment terms
        $paymentTerms = (int)$order['payment_terms'];
        if ($paymentTerms == 0) {
            $dueDate = date('Y-m-d'); // COD - due today
        } else {
            $dueDate = date('Y-m-d', strtotime('+' . $paymentTerms . ' days'));
        }

        // Save invoice to DB
        $invoiceData = array(
            'invoice_number'  => $invoiceNum,
            'order_id'        => $orderId,
            'customer_id'     => $order['customer_id'],
            'subtotal'        => $subtotal,
            'freight_amount'  => $billedFreight,
            'discount_amount' => $discountAmt,
            'tax_amount'      => $taxAmt,
            'total_amount'    => $totalAmt,
            'status'          => 'pending',
            'due_date'        => $dueDate,
            'payment_terms'   => $paymentTerms,
            'notes'           => '',
        );

        $invId = $this->saveInvoice($invoiceData);

        if (!$invId) {
            return false;
        }

        // Update the order's freight_amount to match the billed freight
        // (in case they were different)
        if ($billedFreight != $order['freight_amount']) {
            query("UPDATE orders SET freight_amount=$billedFreight, total_amount=$totalAmt, updated_at=NOW() WHERE id=$orderId");
        }

        auditLog('generate_invoice', 'invoice', $invId, 'Invoice ' . $invoiceNum . ' generated for order ' . $orderId);

        return array_merge($invoiceData, array('id' => $invId));
    }

    // =========================================================
    // SAVE INVOICE
    // =========================================================

    /**
     * Insert invoice into DB
     * @param array $invoiceData
     * @return int|false new invoice ID or false
     */
    public function saveInvoice($invoiceData) {
        global $invoice_sequence;
        $invoice_sequence++;

        $sql = "INSERT INTO invoices (
                    invoice_number, order_id, customer_id,
                    subtotal, freight_amount, discount_amount, tax_amount, total_amount,
                    status, due_date, payment_terms, notes,
                    created_at, updated_at
                ) VALUES (
                    '" . mysql_real_escape_string($invoiceData['invoice_number']) . "',
                    " . (int)$invoiceData['order_id'] . ",
                    " . (int)$invoiceData['customer_id'] . ",
                    " . (float)$invoiceData['subtotal'] . ",
                    " . (float)$invoiceData['freight_amount'] . ",
                    " . (float)$invoiceData['discount_amount'] . ",
                    " . (float)$invoiceData['tax_amount'] . ",
                    " . (float)$invoiceData['total_amount'] . ",
                    'pending',
                    '" . mysql_real_escape_string($invoiceData['due_date']) . "',
                    " . (int)$invoiceData['payment_terms'] . ",
                    '" . mysql_real_escape_string($invoiceData['notes']) . "',
                    NOW(), NOW()
                )";

        $result = query($sql);
        if (!$result) return false;

        return mysql_insert_id($this->conn);
    }

    // =========================================================
    // GET INVOICE
    // =========================================================

    /**
     * Get an invoice by ID with joined customer and order data
     * @param int $invoiceId
     * @return array|null
     */
    public function getInvoice($invoiceId) {
        $invoiceId = (int)$invoiceId;

        return query_row(
            "SELECT i.*,
                    o.order_number, o.ship_to_name, o.ship_to_addr1, o.ship_to_addr2,
                    o.ship_to_city, o.ship_to_state, o.ship_to_zip,
                    o.tracking_number, o.carrier_id,
                    c.company_name, c.contact_name, c.email,
                    c.addr1, c.addr2, c.city, c.state, c.zip,
                    c.account_number
             FROM invoices i
             LEFT JOIN orders o    ON i.order_id = o.id
             LEFT JOIN customers c ON i.customer_id = c.id
             WHERE i.id = $invoiceId"
        );
    }

    // =========================================================
    // MARK AS PAID
    // =========================================================

    /**
     * Mark an invoice as paid
     * Also updates order status to 'delivered' via OrderManager (MORE CIRCULAR DEP)
     * @param int $invoiceId
     * @param string $paymentRef
     * @return bool
     */
    public function markAsPaid($invoiceId, $paymentRef) {
        $invoiceId  = (int)$invoiceId;
        $paymentRef = mysql_real_escape_string($paymentRef);

        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) return false;

        if ($invoice['status'] == 'paid') {
            return false; // already paid
        }

        $sql = "UPDATE invoices SET
                status='paid',
                payment_ref='" . $paymentRef . "',
                paid_at=NOW(),
                updated_at=NOW()
                WHERE id=$invoiceId";

        $result = query($sql);

        if ($result) {
            // Update order status if appropriate
            // This is more circular dependency: InvoiceGen -> OrderManager
            if ($invoice['order_id']) {
                $om = new OrderManager();
                $order = $om->getOrder($invoice['order_id']);
                if ($order && $order['status'] == 'shipped') {
                    // Auto-mark as delivered when payment received?
                    // This is wrong but was "a quick way to close orders" - Dave 2011
                    // Not all shipped orders should be delivered when paid, but...
                    // TODO: decouple payment status from delivery status (2012)
                    // $om->updateOrderStatus($invoice['order_id'], 'delivered'); // commented out after complaints in 2013
                }
            }

            auditLog('invoice_paid', 'invoice', $invoiceId, 'Invoice paid. Ref: ' . $paymentRef);
        }

        return $result !== false;
    }

    // =========================================================
    // GENERATE INVOICE HTML
    // =========================================================

    /**
     * Generate HTML for an invoice
     * Returns a complete HTML document as a string
     * Inline styles because "we might print this" - Dave 2009
     * @param int $invoiceId
     * @return string HTML
     */
    public function generateInvoiceHTML($invoiceId) {
        $invoiceId = (int)$invoiceId;
        $invoice   = $this->getInvoice($invoiceId);

        if (!$invoice) {
            return '<p style="color:red;">Invoice not found: ' . $invoiceId . '</p>';
        }

        // Get order items for line items
        $om    = new OrderManager();
        $items = $om->getOrderItems($invoice['order_id']);

        $html  = '';
        $html .= '<div style="font-family:Arial,sans-serif;font-size:12px;max-width:800px;margin:0 auto;">';

        // Header
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #003366;padding-bottom:10px;margin-bottom:15px;">';
        $html .= '<tr>';
        $html .= '<td style="width:50%;vertical-align:top;">';
        $html .= '<div style="font-size:24px;font-weight:bold;color:#003366;">' . APP_NAME . '</div>';
        $html .= '<div style="font-size:11px;color:#666;margin-top:5px;">';
        $html .= '1234 Industrial Blvd, Suite 100<br/>Philadelphia, PA 19103<br/>';
        $html .= 'Phone: (215) 555-0100<br/>Email: ' . ADMIN_EMAIL . '</div>';
        $html .= '</td>';
        $html .= '<td style="width:50%;text-align:right;vertical-align:top;">';
        $html .= '<div style="font-size:20px;font-weight:bold;color:#003366;">INVOICE</div>';
        $html .= '<div style="margin-top:8px;">';
        $html .= '<strong>Invoice #:</strong> ' . h($invoice['invoice_number']) . '<br/>';
        $html .= '<strong>Date:</strong> ' . formatDate($invoice['created_at']) . '<br/>';
        $html .= '<strong>Due Date:</strong> <span style="' . (strtotime($invoice['due_date']) < time() && $invoice['status'] == 'pending' ? 'color:red;font-weight:bold;' : '') . '">' . formatDate($invoice['due_date']) . '</span><br/>';
        $html .= '<strong>Status:</strong> <span style="text-transform:uppercase;font-weight:bold;">' . h($invoice['status']) . '</span>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr></table>';

        // Bill To / Ship To
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:15px;">';
        $html .= '<tr>';
        $html .= '<td style="width:50%;vertical-align:top;padding-right:20px;">';
        $html .= '<div style="font-weight:bold;color:#003366;margin-bottom:5px;border-bottom:1px solid #ccc;">BILL TO</div>';
        $html .= '<div>' . h($invoice['company_name']) . '</div>';
        $html .= '<div>' . h($invoice['contact_name']) . '</div>';
        $html .= '<div>' . h($invoice['addr1']) . '</div>';
        if ($invoice['addr2']) {
            $html .= '<div>' . h($invoice['addr2']) . '</div>';
        }
        $html .= '<div>' . h($invoice['city']) . ', ' . h($invoice['state']) . ' ' . h($invoice['zip']) . '</div>';
        $html .= '<div>Acct #: ' . h($invoice['account_number']) . '</div>';
        $html .= '</td>';
        $html .= '<td style="width:50%;vertical-align:top;">';
        $html .= '<div style="font-weight:bold;color:#003366;margin-bottom:5px;border-bottom:1px solid #ccc;">SHIP TO</div>';
        $html .= '<div>' . h($invoice['ship_to_name']) . '</div>';
        $html .= '<div>' . h($invoice['ship_to_addr1']) . '</div>';
        if ($invoice['ship_to_addr2']) {
            $html .= '<div>' . h($invoice['ship_to_addr2']) . '</div>';
        }
        $html .= '<div>' . h($invoice['ship_to_city']) . ', ' . h($invoice['ship_to_state']) . ' ' . h($invoice['ship_to_zip']) . '</div>';
        if ($invoice['tracking_number']) {
            $html .= '<div style="margin-top:5px;"><small>Tracking: ' . h($invoice['tracking_number']) . '<br/>Via: ' . h(getCarrierName($invoice['carrier_id'])) . '</small></div>';
        }
        $html .= '</td>';
        $html .= '</tr></table>';

        // Order reference
        $html .= '<div style="margin-bottom:10px;background:#f5f5f5;padding:6px;border:1px solid #ddd;">';
        $html .= '<strong>Order #:</strong> ' . h($invoice['order_number']);
        $html .= ' &nbsp;|&nbsp; <strong>Payment Terms:</strong> ' . ($invoice['payment_terms'] == 0 ? 'COD' : 'Net ' . $invoice['payment_terms']);
        $html .= '</div>';

        // Line items
        $html .= '<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:15px;">';
        $html .= '<tr style="background:#003366;color:white;">';
        $html .= '<th style="text-align:left;">Description</th>';
        $html .= '<th style="text-align:center;width:60px;">Qty</th>';
        $html .= '<th style="text-align:right;width:100px;">Unit Price</th>';
        $html .= '<th style="text-align:right;width:100px;">Amount</th>';
        $html .= '</tr>';

        foreach ($items as $item) {
            $lineTotal = $item['quantity'] * $item['unit_price'];
            $html .= '<tr style="border-bottom:1px solid #eee;">';
            $html .= '<td>' . h($item['product_name']);
            if ($item['sku']) {
                $html .= '<br/><small style="color:#999;">SKU: ' . h($item['sku']) . '</small>';
            }
            $html .= '</td>';
            $html .= '<td style="text-align:center;">' . (int)$item['quantity'] . '</td>';
            $html .= '<td style="text-align:right;">' . formatMoney($item['unit_price']) . '</td>';
            $html .= '<td style="text-align:right;">' . formatMoney($lineTotal) . '</td>';
            $html .= '</tr>';
        }

        // Totals
        $html .= '<tr style="border-top:1px solid #ccc;">';
        $html .= '<td colspan="3" style="text-align:right;padding:6px;"><strong>Subtotal:</strong></td>';
        $html .= '<td style="text-align:right;padding:6px;">' . formatMoney($invoice['subtotal']) . '</td>';
        $html .= '</tr>';

        if ($invoice['freight_amount'] > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="3" style="text-align:right;padding:6px;"><strong>Freight (' . h(getCarrierName($invoice['carrier_id'])) . '):</strong></td>';
            $html .= '<td style="text-align:right;padding:6px;">' . formatMoney($invoice['freight_amount']) . '</td>';
            $html .= '</tr>';
        }

        if ($invoice['discount_amount'] > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="3" style="text-align:right;padding:6px;color:green;"><strong>Discount:</strong></td>';
            $html .= '<td style="text-align:right;padding:6px;color:green;">-' . formatMoney($invoice['discount_amount']) . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr style="background:#f0f0f0;">';
        $html .= '<td colspan="3" style="text-align:right;padding:8px;font-size:14px;font-weight:bold;">TOTAL DUE:</td>';
        $html .= '<td style="text-align:right;padding:8px;font-size:14px;font-weight:bold;">' . formatMoney($invoice['total_amount']) . '</td>';
        $html .= '</tr>';
        $html .= '</table>';

        // Payment instructions
        $html .= '<div style="border:1px solid #ccc;padding:10px;margin-bottom:15px;font-size:11px;">';
        $html .= '<strong>Payment Instructions:</strong><br/>';
        $html .= 'Please remit payment by ' . formatDate($invoice['due_date']) . '. ';
        $html .= 'Make checks payable to <strong>Northwind Logistics Inc.</strong><br/>';
        $html .= 'Wire transfer: Routing 021000021 &nbsp; Account 4471882930<br/>';
        // NOTE: actual bank account numbers are in source code. Security audit 2013 flagged this.
        // TODO: remove from source, put in config (2013 - not done because "nobody sees the source")
        $html .= 'Questions? Email: ' . ADMIN_EMAIL . ' or call (215) 555-0100</div>';

        if ($invoice['status'] == 'paid') {
            $html .= '<div style="border:3px solid green;color:green;text-align:center;font-size:24px;font-weight:bold;padding:10px;margin-bottom:15px;transform:rotate(-5deg);">PAID</div>';
        }
        if ($invoice['status'] == 'voided') {
            $html .= '<div style="border:3px solid red;color:red;text-align:center;font-size:24px;font-weight:bold;padding:10px;margin-bottom:15px;transform:rotate(-5deg);">VOID</div>';
        }

        $html .= '<div style="text-align:right;">';
        $html .= '<a href="index.php?page=invoice_pdf&id=' . $invoiceId . '" class="btn" target="_blank">PDF Version</a>';
        if ($invoice['status'] == 'pending') {
            $html .= ' <a href="index.php?page=invoice_pay&id=' . $invoiceId . '" class="btn btn-success">Mark as Paid</a>';
        }
        $html .= '</div>';

        $html .= '</div>'; // end invoice div

        return $html;
    }

    // =========================================================
    // EMAIL INVOICE
    // =========================================================

    /**
     * Email an invoice to a customer
     * Calls mail() directly
     * @param int $invoiceId
     * @param string $email - override email (if null, uses customer email)
     * @return bool
     */
    public function emailInvoice($invoiceId, $email = null) {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) return false;

        $toEmail = $email ? $email : $invoice['email'];
        if (empty($toEmail)) return false;

        $subject = 'Invoice ' . $invoice['invoice_number'] . ' from ' . APP_NAME;
        $body    = $this->generateInvoiceHTML($invoiceId);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
        $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";

        $sent = @mail($toEmail, $subject, $body, $headers);

        if ($sent) {
            // Log that invoice was emailed
            query("UPDATE invoices SET last_emailed=NOW() WHERE id=$invoiceId");
            auditLog('email_invoice', 'invoice', $invoiceId, 'Invoice emailed to: ' . $toEmail);
        }

        return $sent;
    }

    // =========================================================
    // VOID INVOICE
    // =========================================================

    /**
     * Void an invoice
     * Soft delete with a reason field that is never actually displayed anywhere
     * Bob added the reason field in 2011 after an audit request.
     * The auditors wanted to see void reasons. We added the field.
     * Nobody added it to any UI. The auditors never asked for the report. - Dave 2012
     * @param int $invoiceId
     * @param string $reason
     * @return bool
     */
    public function voidInvoice($invoiceId, $reason = '') {
        $invoiceId = (int)$invoiceId;
        $reason    = mysql_real_escape_string($reason);

        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) return false;

        if ($invoice['status'] == 'paid') {
            // Can't void a paid invoice - need to issue a credit memo (feature not built - 2013)
            return false;
        }

        $sql = "UPDATE invoices SET
                status='voided',
                void_reason='$reason',
                voided_at=NOW(),
                updated_at=NOW()
                WHERE id=$invoiceId";

        $result = query($sql);

        if ($result) {
            auditLog('void_invoice', 'invoice', $invoiceId, 'Invoice voided. Reason: ' . $reason);
        }

        return $result !== false;
    }
}
