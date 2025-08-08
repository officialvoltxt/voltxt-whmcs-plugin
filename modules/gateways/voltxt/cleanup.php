<?php
/**
 * VOLTXT Data Cleanup Script
 * 
 * This script removes VOLTXT data from visible invoice notes
 * and cleans up the JSON data that's showing on invoice pages
 * 
 * Usage: Access via browser: https://yourdomain.com/modules/gateways/voltxt/cleanup.php
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

// Security check - only allow admin access via web
if (php_sapi_name() !== 'cli' && !CurrentUser::admin()) {
    die('Access denied. Admin authentication required.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>VOLTXT Data Cleanup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
        .container { max-width: 800px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .invoice-item { border: 1px solid #dee2e6; padding: 10px; margin: 10px 0; border-radius: 4px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
<div class="container">

<h2>🧹 VOLTXT Data Cleanup</h2>

<div class="info">
    <p><strong>What this does:</strong> Removes VOLTXT JSON data from invoice notes that are visible to customers and admins.</p>
    <p><strong>Example of data being removed:</strong></p>
    <pre>{"voltxt":{"voltxt_invoice_number":"EIANNT8F","payment_url":"https://api.voltxt.io/invoice/EIANNT8F","network":"testnet","status":"pending",...}}</pre>
</div>

<?php
if (isset($_POST['confirm_cleanup'])) {
    try {
        $cleanedCount = 0;
        $processedCount = 0;
        
        echo "<h3>🔄 Processing invoices...</h3>\n";
        
        // Get all invoices that have VOLTXT data in notes
        $invoices = Capsule::table('tblinvoices')
            ->whereNotNull('notes')
            ->where('notes', 'LIKE', '%"voltxt":%')
            ->get();
        
        echo "<p>Found " . count($invoices) . " invoices with VOLTXT data in notes.</p>\n";
        
        foreach ($invoices as $invoice) {
            $processedCount++;
            $notes = json_decode($invoice->notes, true);
            
            if ($notes && isset($notes['voltxt'])) {
                echo "<div class='invoice-item'>\n";
                echo "<h4>Invoice #{$invoice->id}</h4>\n";
                echo "<p><strong>VOLTXT Data Found:</strong></p>\n";
                echo "<pre>" . json_encode($notes['voltxt'], JSON_PRETTY_PRINT) . "</pre>\n";
                
                // Remove VOLTXT data
                unset($notes['voltxt']);
                
                // Update the invoice
                $updatedNotes = empty($notes) ? '' : json_encode($notes);
                Capsule::table('tblinvoices')
                    ->where('id', $invoice->id)
                    ->update(['notes' => $updatedNotes]);
                
                $cleanedCount++;
                echo "<p style='color: green;'><strong>✅ Cleaned up!</strong> VOLTXT data removed from invoice notes.</p>\n";
                echo "</div>\n";
            }
        }
        
        // Log the cleanup
        logActivity("VOLTXT: Manual cleanup completed. Cleaned {$cleanedCount} invoice notes.");
        
        echo "<div class='success'>\n";
        echo "<h3>✅ Cleanup Complete!</h3>\n";
        echo "<p><strong>Total invoices processed:</strong> {$processedCount}</p>\n";
        echo "<p><strong>Invoices cleaned:</strong> {$cleanedCount}</p>\n";
        echo "<p><strong>Result:</strong> All VOLTXT JSON data has been removed from visible invoice notes.</p>\n";
        echo "<p><strong>Impact:</strong> Customers and admins will no longer see VOLTXT JSON data on invoice pages.</p>\n";
        echo "</div>\n";
        
    } catch (Exception $e) {
        $errorMsg = 'VOLTXT Cleanup Error: ' . $e->getMessage();
        logActivity($errorMsg);
        
        echo "<div class='error'>\n";
        echo "<h3>❌ Error During Cleanup</h3>\n";
        echo "<p>" . htmlspecialchars($errorMsg) . "</p>\n";
        echo "</div>\n";
    }
} else {
    // Show confirmation form
    $invoiceCount = Capsule::table('tblinvoices')
        ->whereNotNull('notes')
        ->where('notes', 'LIKE', '%"voltxt":%')
        ->count();
        
    echo "<div class='info'>";
    echo "<h3>📊 Current Status</h3>";
    echo "<p><strong>Invoices with VOLTXT data in notes:</strong> {$invoiceCount}</p>";
    if ($invoiceCount > 0) {
        echo "<p>These invoices have VOLTXT JSON data visible in their notes field.</p>";
    } else {
        echo "<p>✅ No VOLTXT data found in invoice notes!</p>";
    }
    echo "</div>";
    
    if ($invoiceCount > 0) {
        echo "<form method='POST'>";
        echo "<p><strong>⚠️ This action will:</strong></p>";
        echo "<ul>";
        echo "<li>Remove VOLTXT JSON data from {$invoiceCount} invoice notes</li>";
        echo "<li>Clean up the visible notes that customers/admins see</li>";
        echo "<li>Keep other notes content intact</li>";
        echo "<li>Log the cleanup activity</li>";
        echo "</ul>";
        echo "<p><input type='checkbox' name='confirm' required> I understand this will modify invoice notes</p>";
        echo "<button type='submit' name='confirm_cleanup' class='btn'>🧹 Start Cleanup</button>";
        echo "</form>";
    }
}
?>

<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 12px;">
    <p><strong>Note:</strong> This cleanup only affects the visible notes field. The VOLTXT gateway will continue to work normally for new payments.</p>
</div>

</div>
</body>
</html>