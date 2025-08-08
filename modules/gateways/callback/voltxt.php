<?php
/**
 * VOLTXT Webhook Callback Handler for WHMCS
 *
 * This file handles incoming webhook notifications from VOLTXT
 * when payment status changes occur for both dynamic and traditional payments.
 *
 * Compatible with WHMCS 8.10.1 and PHP 7.4-8.4
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../voltxt/lib/ApiClient.php';

use WHMCS\Module\Gateway\Voltxt\Lib\ApiClient;
use WHMCS\Database\Capsule;

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Get raw webhook payload
$rawPayload = file_get_contents('php://input');

// Die if no payload
if (empty($rawPayload)) {
    die("No webhook payload received");
}

// Log raw webhook for debugging
logActivity('VOLTXT Webhook Raw Payload: ' . substr($rawPayload, 0, 500));

// Decode JSON payload
$webhookData = json_decode($rawPayload, true);

// Die if invalid JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Invalid JSON payload: " . json_last_error_msg());
}

// Log decoded webhook data
logActivity('VOLTXT Webhook Decoded: ' . json_encode($webhookData));

// Initialize variables for WHMCS callback processing
$success = false;
$invoiceId = null;
$transactionId = null;
$paymentAmount = 0;
$paymentFee = 0;
$transactionStatus = 'Pending';

try {
    // Determine payment type and extract data
    if (isset($webhookData['session_id'])) {
        // Dynamic payment webhook
        $paymentType = 'dynamic';
        $sessionId = $webhookData['session_id'];
        
        // Extract invoice ID from external_payment_id
        if (isset($webhookData['external_payment_id'])) {
            $invoiceId = (int) str_replace('whmcs_invoice_', '', $webhookData['external_payment_id']);
        }
        
        // Build transaction ID
        $transactionId = $sessionId . '-' . time();
        
    } else {
        // Traditional payment webhook
        $paymentType = 'traditional';
        
        // Extract invoice ID from external_invoice_id
        if (isset($webhookData['external_invoice_id'])) {
            $invoiceId = (int) str_replace('whmcs_invoice_', '', $webhookData['external_invoice_id']);
        }
        
        // Use payment_tx_id or invoice_number for transaction ID
        $transactionId = $webhookData['payment_tx_id'] ?? $webhookData['invoice_number'] ?? 'voltxt-' . time();
    }
    
    // Validate required fields
    if (!$invoiceId) {
        throw new Exception('No valid invoice ID found in webhook');
    }
    
    if (!$transactionId) {
        throw new Exception('No valid transaction ID found in webhook');
    }
    
    // Extract payment amount
    if (isset($webhookData['amount_fiat']) && $webhookData['amount_fiat'] > 0) {
        $paymentAmount = (float) $webhookData['amount_fiat'];
    } elseif (isset($webhookData['amount']) && $webhookData['amount'] > 0) {
        $paymentAmount = (float) $webhookData['amount'];
    } else {
        // Try to get amount from stored session/invoice data
        $storedData = getStoredPaymentData($invoiceId, $paymentType);
        if ($storedData && isset($storedData['amount_fiat']) && $storedData['amount_fiat'] > 0) {
            $paymentAmount = (float) $storedData['amount_fiat'];
        }
    }
    
    if ($paymentAmount <= 0) {
        throw new Exception('No valid payment amount found');
    }
    
    // Determine if payment was successful based on event type
    $eventType = $webhookData['event_type'] ?? '';
    $success = in_array($eventType, ['payment_completed', 'payment_received']);
    
    if ($success) {
        $transactionStatus = 'Success - ' . ucfirst($paymentType) . ' Payment Completed';
    } else {
        $transactionStatus = 'Info - ' . ucfirst($eventType);
    }
    
    logActivity("VOLTXT Webhook Processing: Invoice={$invoiceId}, Transaction={$transactionId}, Amount={$paymentAmount}, Type={$paymentType}, Event={$eventType}");
    
} catch (Exception $e) {
    $transactionStatus = 'Error - ' . $e->getMessage();
    logActivity('VOLTXT Webhook Error: ' . $e->getMessage());
    
    // Log the error transaction but don't die
    logTransaction($gatewayParams['name'], $webhookData, $transactionStatus);
    die("Webhook processing error: " . $e->getMessage());
}

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 *
 * Performs a die upon encountering an invalid Invoice ID.
 *
 * Returns a normalised invoice ID.
 */
try {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
} catch (Exception $e) {
    logActivity('VOLTXT Webhook Invoice Validation Failed: ' . $e->getMessage());
    logTransaction($gatewayParams['name'], $webhookData, 'Invalid Invoice ID');
    die("Invalid Invoice ID: " . $e->getMessage());
}

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.
 */
try {
    checkCbTransID($transactionId);
} catch (Exception $e) {
    // If transaction ID exists, create a unique one
    $originalTransactionId = $transactionId;
    $transactionId = $transactionId . '-' . uniqid();
    logActivity("VOLTXT Webhook: Modified transaction ID from {$originalTransactionId} to {$transactionId} due to duplicate");
    
    // Try again with new ID
    try {
        checkCbTransID($transactionId);
    } catch (Exception $e2) {
        logActivity('VOLTXT Webhook Transaction ID Validation Failed: ' . $e2->getMessage());
        logTransaction($gatewayParams['name'], $webhookData, 'Duplicate Transaction ID');
        die("Transaction ID validation failed: " . $e2->getMessage());
    }
}

/**
 * Log Transaction.
 *
 * Add an entry to the Gateway Log for debugging purposes.
 */
logTransaction($gatewayParams['name'], array_merge($webhookData, [
    'processed_invoice_id' => $invoiceId,
    'processed_transaction_id' => $transactionId,
    'processed_amount' => $paymentAmount,
    'payment_type' => $paymentType ?? 'unknown',
    'webhook_received_at' => date('c')
]), $transactionStatus);

// Only process payment if it was successful
if ($success) {
    
    // Check if invoice is already paid to avoid duplicate payments
    try {
        $whmcsInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($whmcsInvoice && $whmcsInvoice->status === 'Paid') {
            logActivity("VOLTXT Webhook: Invoice #{$invoiceId} already paid, skipping payment processing");
            logTransaction($gatewayParams['name'], $webhookData, 'Invoice Already Paid');
            echo "Invoice already paid";
            exit;
        }
    } catch (Exception $e) {
        logActivity('VOLTXT Webhook: Error checking invoice status: ' . $e->getMessage());
    }
    
    /**
     * Add Invoice Payment.
     *
     * Applies a payment transaction entry to the given invoice ID.
     */
    try {
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $paymentAmount,
            $paymentFee,
            $gatewayModuleName
        );
        
        logActivity("VOLTXT Webhook: Successfully added payment of {$paymentAmount} to invoice #{$invoiceId} with transaction {$transactionId}");
        
        // Update stored payment data with completion info
        updateStoredPaymentData($invoiceId, $paymentType, [
            'status' => 'completed',
            'payment_tx_id' => $webhookData['payment_tx_id'] ?? null,
            'auto_process_tx_id' => $webhookData['auto_process_tx_id'] ?? null,
            'whmcs_transaction_id' => $transactionId,
            'processed_amount' => $paymentAmount,
            'processed_at' => date('Y-m-d H:i:s')
        ]);
        
        echo "Payment processed successfully";
        
    } catch (Exception $e) {
        logActivity('VOLTXT Webhook: Failed to add payment: ' . $e->getMessage());
        logTransaction($gatewayParams['name'], $webhookData, 'Payment Processing Failed: ' . $e->getMessage());
        die("Payment processing failed: " . $e->getMessage());
    }
    
} else {
    // Log non-payment events (expired, partial, etc.)
    logActivity("VOLTXT Webhook: Non-payment event processed - {$eventType}");
    
    // Update stored payment data with status
    updateStoredPaymentData($invoiceId, $paymentType, [
        'status' => $eventType === 'payment_expired' ? 'expired' : 'pending',
        'last_webhook_event' => $eventType,
        'last_webhook_at' => date('Y-m-d H:i:s')
    ]);
    
    echo "Webhook processed - non-payment event";
}

/**
 * Get stored payment data from WHMCS invoice metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param string $paymentType Payment type (dynamic/traditional)
 * @return array|null Payment data or null
 */
function getStoredPaymentData($invoiceId, $paymentType)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        // Check for the appropriate payment type data
        if ($paymentType === 'dynamic') {
            if (preg_match('/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
                $serializedData = base64_decode($matches[1]);
                $paymentData = unserialize($serializedData);
                return ($paymentData && is_array($paymentData)) ? $paymentData : null;
            }
        } else {
            if (preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
                $serializedData = base64_decode($matches[1]);
                $paymentData = unserialize($serializedData);
                return ($paymentData && is_array($paymentData)) ? $paymentData : null;
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        logActivity('VOLTXT Webhook: Error retrieving stored payment data: ' . $e->getMessage());
        return null;
    }
}

/**
 * Update stored payment data in WHMCS invoice metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param string $paymentType Payment type (dynamic/traditional)
 * @param array $updateData Data to update
 */
function updateStoredPaymentData($invoiceId, $paymentType, $updateData)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return;
        }
        
        $pattern = ($paymentType === 'dynamic') ? '/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/' : '/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/';
        $marker = ($paymentType === 'dynamic') ? 'VOLTXT_DYNAMIC:' : 'VOLTXT_DATA:';
        
        if (preg_match($pattern, $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $paymentData = unserialize($serializedData);
            
            if ($paymentData && is_array($paymentData)) {
                // Merge update data
                $paymentData = array_merge($paymentData, $updateData);
                $paymentData['last_updated'] = date('Y-m-d H:i:s');
                
                // Re-serialize and update
                $newSerializedData = base64_encode(serialize($paymentData));
                $newAdminNotes = preg_replace($pattern, $marker . $newSerializedData, $adminNotes);
                
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(['adminonly' => $newAdminNotes]);
                    
                logActivity("VOLTXT Webhook: Updated {$paymentType} payment data for invoice {$invoiceId}");
            }
        }
        
    } catch (Exception $e) {
        logActivity('VOLTXT Webhook: Error updating stored payment data: ' . $e->getMessage());
    }
}