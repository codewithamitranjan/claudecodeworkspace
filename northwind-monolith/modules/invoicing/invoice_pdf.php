<?php
// modules/invoicing/invoice_pdf.php - PDF Invoice Generation
// Created 2010-03-15
// TODO: install fpdf library (added 2010 - STILL not done in 2013)
// The plan was to use FPDF to generate proper PDF invoices
// We got as far as putting the TODO comment in. - Dave 2013
//
// Currently falls back to HTML print view
// Customer has complained about this 3 times. Management says "it's on the roadmap."
// The roadmap hasn't been updated since 2011. - Bob 2013

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

require_once(APP_ROOT . '/modules/invoicing/InvoiceGen.php');

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$invoiceId) {
    die('<p style="color:red;">No invoice ID specified.</p>');
}

$ig      = new InvoiceGen();
$invoice = $ig->getInvoice($invoiceId);

if (!$invoice) {
    die('<p style="color:red;">Invoice not found: ' . $invoiceId . '</p>');
}

// Try to use FPDF if available
// TODO: actually install FPDF (2010)
// The autoloader for FPDF would go here if we had it
// The font path is defined in config.php as FPDF_FONTPATH
// We've defined the constant but the library isn't there

if (file_exists(APP_ROOT . '/lib/fpdf/fpdf.php')) {
    // TODO: implement proper PDF generation (2010)
    // This block was never reached because fpdf.php was never installed
    // require_once(APP_ROOT . '/lib/fpdf/fpdf.php');
    //
    // class PDF extends FPDF {
    //     function Header() { ... }
    //     function Footer() { ... }
    // }
    // $pdf = new PDF();
    // ... etc
    //
    // But since the file doesn't exist, we fall through to HTML fallback below

    // Actually even if it existed, the code above is commented out
    // So we'd still fall through. This is a TODO that has nested TODOs. - 2013
    require_once(APP_ROOT . '/lib/fpdf/fpdf.php');
    // (this line would fail if we somehow reached here, but we won't)
}

// FPDF not available (it never is) - fall back to HTML print view
// This has been the "fallback" since 2010. It is the primary implementation.
// The word "fallback" implies there's a primary. There isn't.

// Send headers to make browser think it's a PDF
// NOTE: this sends HTML with PDF headers, which browsers handle differently
// Firefox opens a download dialog. Chrome shows HTML in-browser. IE crashes. - 2012
// We stopped setting PDF headers and just show a print-friendly HTML page instead - 2013
// header('Content-Type: application/pdf');  // commented out because it breaks IE
// header('Content-Disposition: attachment; filename="invoice_' . $invoice['invoice_number'] . '.pdf"');

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
    <title>Invoice <?php echo h($invoice['invoice_number']); ?> - <?php echo APP_NAME; ?></title>
    <style type="text/css">
        /* Print-friendly styles */
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; padding: 20px; background: white; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
            @page { margin: 1cm; }
        }
        .invoice-header { border-bottom: 2px solid #003366; padding-bottom: 15px; margin-bottom: 15px; overflow: hidden; }
        .company-name { font-size: 22px; font-weight: bold; color: #003366; }
        .invoice-title { font-size: 18px; font-weight: bold; color: #003366; float: right; text-align: right; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #003366; color: white; padding: 6px; text-align: left; }
        td { padding: 5px 6px; }
        .total-row { font-weight: bold; background: #f0f0f0; }
        .grand-total { font-size: 14px; font-weight: bold; background: #003366; color: white; }
        .address-box { width: 48%; display: inline-block; vertical-align: top; }
        .paid-stamp { border: 4px solid green; color: green; font-size: 36px; font-weight: bold;
                      text-align: center; padding: 5px; display: inline-block; transform: rotate(-15deg);
                      position: absolute; top: 200px; right: 100px; opacity: 0.6; }
        .void-stamp { border: 4px solid red; color: red; font-size: 36px; font-weight: bold;
                      text-align: center; padding: 5px; display: inline-block; transform: rotate(-15deg);
                      position: absolute; top: 200px; right: 100px; opacity: 0.6; }
        .footer-note { margin-top: 30px; font-size: 10px; color: #666; border-top: 1px solid #ccc; padding-top: 10px; }
    </style>
</head>
<body>

<!-- Print/Close buttons - not shown when printing -->
<div class="no-print" style="margin-bottom:15px;padding:10px;background:#f5f5f5;border:1px solid #ddd;">
    <strong>Note:</strong> PDF generation is not yet available.
    <!-- TODO: install fpdf library - this note has been here since 2010 -->
    This is a print-friendly HTML version. Use your browser's Print function (Ctrl+P / Cmd+P)
    to print or save as PDF.
    &nbsp;&nbsp;
    <button onclick="window.print();" style="padding:4px 10px;">Print</button>
    &nbsp;
    <button onclick="window.close();" style="padding:4px 10px;">Close</button>
    &nbsp;
    <a href="index.php?page=invoice_detail&id=<?php echo $invoiceId; ?>" style="font-size:11px;">Back to Invoice</a>
</div>

<div style="position:relative;">

<?php if ($invoice['status'] == 'paid'): ?>
<div class="paid-stamp">PAID</div>
<?php elseif ($invoice['status'] == 'voided'): ?>
<div class="void-stamp">VOID</div>
<?php endif; ?>

<!-- Invoice Header -->
<div class="invoice-header">
    <div style="float:left;">
        <div class="company-name"><?php echo APP_NAME; ?></div>
        <div style="font-size:10px;color:#666;margin-top:5px;">
            1234 Industrial Blvd, Suite 100<br/>
            Philadelphia, PA 19103<br/>
            Tel: (215) 555-0100 &nbsp; Fax: (215) 555-0101<br/>
            Email: <?php echo ADMIN_EMAIL; ?><br/>
            Website: <?php echo APP_URL; ?>
        </div>
    </div>
    <div class="invoice-title">
        INVOICE<br/>
        <span style="font-size:12px;font-weight:normal;">
            #<?php echo h($invoice['invoice_number']); ?><br/>
            Date: <?php echo formatDate($invoice['created_at']); ?><br/>
            Due: <?php echo formatDate($invoice['due_date']); ?><br/>
            <?php if ($invoice['payment_terms'] == 0): ?>
            Terms: COD
            <?php else: ?>
            Terms: Net <?php echo $invoice['payment_terms']; ?>
            <?php endif; ?>
        </span>
    </div>
    <div style="clear:both;"></div>
</div>

<!-- Bill To / Ship To -->
<table style="margin-bottom:15px;">
<tr>
    <td style="width:50%;vertical-align:top;padding-right:15px;">
        <strong style="color:#003366;font-size:11px;">BILL TO:</strong><br/>
        <strong><?php echo h($invoice['company_name']); ?></strong><br/>
        <?php if ($invoice['contact_name']): ?><?php echo h($invoice['contact_name']); ?><br/><?php endif; ?>
        <?php echo h($invoice['addr1']); ?><br/>
        <?php if ($invoice['addr2']): ?><?php echo h($invoice['addr2']); ?><br/><?php endif; ?>
        <?php echo h($invoice['city']); ?>, <?php echo h($invoice['state']); ?> <?php echo h($invoice['zip']); ?><br/>
        <small>Account #: <?php echo h($invoice['account_number']); ?></small>
    </td>
    <td style="width:50%;vertical-align:top;">
        <strong style="color:#003366;font-size:11px;">SHIP TO:</strong><br/>
        <strong><?php echo h($invoice['ship_to_name']); ?></strong><br/>
        <?php echo h($invoice['ship_to_addr1']); ?><br/>
        <?php if ($invoice['ship_to_addr2']): ?><?php echo h($invoice['ship_to_addr2']); ?><br/><?php endif; ?>
        <?php echo h($invoice['ship_to_city']); ?>, <?php echo h($invoice['ship_to_state']); ?> <?php echo h($invoice['ship_to_zip']); ?><br/>
        <?php if ($invoice['tracking_number']): ?>
        <small>Tracking: <?php echo h($invoice['tracking_number']); ?> (<?php echo h(getCarrierName($invoice['carrier_id'])); ?>)</small>
        <?php endif; ?>
    </td>
</tr>
</table>

<!-- Order Reference -->
<div style="background:#f0f0f0;padding:5px;margin-bottom:10px;font-size:11px;">
    <strong>Your Order Reference:</strong> <?php echo h($invoice['order_number']); ?>
</div>

<!-- Line Items -->
<?php
$om    = new OrderManager();
$items = $om->getOrderItems($invoice['order_id']);
?>
<table style="margin-bottom:15px;">
    <tr>
        <th style="text-align:left;">Item Description</th>
        <th style="text-align:left;width:80px;">SKU</th>
        <th style="text-align:center;width:50px;">Qty</th>
        <th style="text-align:right;width:90px;">Unit Price</th>
        <th style="text-align:right;width:90px;">Amount</th>
    </tr>
    <?php foreach ($items as $i => $item): ?>
    <tr style="background:<?php echo ($i % 2 == 0) ? '#ffffff' : '#f9f9f9'; ?>;">
        <td><?php echo h($item['product_name']); ?></td>
        <td><?php echo h($item['sku']); ?></td>
        <td style="text-align:center;"><?php echo (int)$item['quantity']; ?></td>
        <td style="text-align:right;"><?php echo formatMoney($item['unit_price']); ?></td>
        <td style="text-align:right;"><?php echo formatMoney($item['quantity'] * $item['unit_price']); ?></td>
    </tr>
    <?php endforeach; ?>

    <!-- Blank rows for manual additions - holds space for handwriting? (2009 design) -->
    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>

    <!-- Summary -->
    <tr class="total-row">
        <td colspan="4" style="text-align:right;">Subtotal:</td>
        <td style="text-align:right;"><?php echo formatMoney($invoice['subtotal']); ?></td>
    </tr>
    <?php if ($invoice['freight_amount'] > 0): ?>
    <tr class="total-row">
        <td colspan="4" style="text-align:right;">Freight &amp; Handling:</td>
        <td style="text-align:right;"><?php echo formatMoney($invoice['freight_amount']); ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($invoice['discount_amount'] > 0): ?>
    <tr class="total-row">
        <td colspan="4" style="text-align:right;color:green;">Discount:</td>
        <td style="text-align:right;color:green;">-<?php echo formatMoney($invoice['discount_amount']); ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($invoice['tax_amount'] > 0): ?>
    <tr class="total-row">
        <td colspan="4" style="text-align:right;">Sales Tax:</td>
        <td style="text-align:right;"><?php echo formatMoney($invoice['tax_amount']); ?></td>
    </tr>
    <?php endif; ?>
    <tr class="grand-total">
        <td colspan="4" style="text-align:right;padding:8px;">TOTAL AMOUNT DUE:</td>
        <td style="text-align:right;padding:8px;"><?php echo formatMoney($invoice['total_amount']); ?></td>
    </tr>
</table>

<!-- Payment Info -->
<div style="border:1px solid #ccc;padding:10px;font-size:10px;margin-bottom:20px;">
    <strong>REMITTANCE INFORMATION</strong><br/>
    Please remit payment within <?php echo $invoice['payment_terms'] == 0 ? 'payment upon delivery (COD)' : $invoice['payment_terms'] . ' days of invoice date'; ?>.<br/>
    <br/>
    <strong>Check:</strong> Payable to "Northwind Logistics Inc." &mdash;
    Mail to: 1234 Industrial Blvd, Suite 100, Philadelphia, PA 19103<br/>
    <strong>Wire Transfer:</strong> Bank: First National Bank of PA &mdash;
    ABA/Routing: 021000021 &mdash; Account: 4471882930<br/>
    <!-- TODO: remove bank account from template, use separate document - 2013 -->
    <strong>Questions?</strong> billing@northwind-logistics.com &mdash; (215) 555-0102
</div>

<!-- Footer -->
<div class="footer-note">
    This invoice was generated by <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> on <?php echo date('Y-m-d H:i:s'); ?>.<br/>
    Thank you for your business. All sales subject to Northwind Logistics standard terms and conditions.<br/>
    Late payments subject to 1.5% monthly finance charge. Returned check fee: $35.00.
</div>

</div><!-- end relative wrapper for stamps -->

</body>
</html>
<?php
// Stop execution here so index.php footer doesn't render
exit;
?>
