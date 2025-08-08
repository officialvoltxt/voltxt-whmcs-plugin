<?php
/**
 * VOLTXT Status Refresh Endpoint
 * 
 * This endpoint allows admins to manually refresh the status
 * of a VOLTXT payment from the admin panel.
 * Updated to support both Dynamic and Traditional payments.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/ApiClient.php';

use WHMCS\Module\Gateway\Voltxt\Lib\ApiClient;
use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

// Set JSON content type
header('Content-Type: application/json');

// Check if user is authenticated admin
if (!CurrentUser::admin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $postData = json_decode(file_get_contents('php://input'), true);
    if (!$postData) {
        $postData = $_POST;
    }
    
    $invoiceId = isset($postData['invoice_id']) ? (int)$postData['invoice_id'] : 0;
    
    if (!$invoiceId) {
        throw new Exception('Invalid invoice ID');
    }
    
    // Get gateway configuration
    $gatewayParams = getGatewayVariables('voltxt');
    if (!$gatewayParams['type']) {
        throw new Exception('VOLTXT gateway not activated');
    }
    
    if (empty($gatewayParams['apiKey']) || empty($gatewayParams['apiUrl'])) {
        throw new Exception('VOLTXT gateway not configured');
    }
    
    // Initialize API client
    $network = ($gatewayParams['testMode'] === 'on') ? 'testnet' : 'mainnet';
    $apiClient = new ApiClient($gatewayParams['apiKey'], $gatewayParams['apiUrl'], $network);
    
    // Determine payment type and get stored data
    $paymentType = determinePaymentType($invoiceId);
    
    if ($paymentType === 'dynamic') {
        $result = refreshDynamicPaymentStatus($invoiceId, $apiClient, $gatewayParams);
    } elseif ($paymentType === 'traditional') {
        $result = refreshTraditionalPaymentStatus($invoiceId, $apiClient, $gatewayParams);
    } else {
        throw new Exception('No VOLTXT payment data found for this invoice');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    logActivity('VOLTXT Status Refresh Error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Determine payment type (dynamic or traditional) for an invoice
 *
 * @param int $invoiceId WHMCS invoice ID
 * @return string|null Payment type or null if none found
 */
function determinePaymentType($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        // Check for dynamic payment session
        if (preg_match('/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/', $adminNotes)) {
            return 'dynamic';
        }
        
        // Check for traditional invoice data
        if (preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes)) {
            return 'traditional';
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Refresh dynamic payment status
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param ApiClient $apiClient API client instance
 * @param array $gatewayParams Gateway configuration
 * @return array Result data
 */
function refreshDynamicPaymentStatus($invoiceId, $apiClient, $gatewayParams)
{
    // Get stored dynamic session data
    $sessionData = getStoredDynamicSessionData($invoiceId);
    if (!$sessionData) {
        throw new Exception('Dynamic payment session data not found');
    }
    
    $sessionId = $sessionData['session_id'];
    $previousStatus = $sessionData['status'] ?? 'unknown';
    
    // Get latest session status from VOLTXT
    $response = $apiClient->getDynamicPaymentStatus($sessionId);
    
    if (!isset($response['success']) || !$response['success']) {
        $errorMessage = isset($response['message']) ? $response['message'] : 'Failed to retrieve session status';
        throw new Exception($errorMessage);
    }
    
    $sessionInfo = $response['session'];
    $newStatus = $sessionInfo['status'];
    
    // Update stored session metadata with latest data
    $updateData = [
        'status' => $newStatus,
        'last_updated' => date('Y-m-d H:i:s'),
    ];
    
    // Update additional fields if available
    if (!empty($sessionInfo['amount_sol'])) {
        $updateData['amount_sol'] = $sessionInfo['amount_sol'];
    }
    
    updateDynamicSessionMetadata($invoiceId, $updateData);
    
    // Log the status refresh
    logActivity("VOLTXT: Dynamic session status refreshed for invoice {$invoiceId}. Status: {$previousStatus} → {$newStatus}");
    
    // If status changed to completed and WHMCS invoice is still unpaid, process payment
    $paymentProcessed = false;
    if ($newStatus === 'completed' && $previousStatus !== 'completed') {
        $paymentProcessed = processCompletedDynamicPayment($invoiceId, $sessionInfo, $gatewayParams);
    }
    
    // Prepare response data
    $message = $previousStatus !== $newStatus ? 
        "Dynamic session status updated from '{$previousStatus}' to '{$newStatus}'" : 
        "Dynamic session status confirmed as '{$newStatus}' (no change)";
    
    if ($paymentProcessed) {
        $message .= " and payment applied to WHMCS invoice";
    }
    
    return [
        'success' => true,
        'message' => $message,
        'data' => [
            'payment_type' => 'dynamic',
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'session_data' => [
                'session_id' => $sessionId,
                'status' => $newStatus,
                'amount_sol' => $sessionInfo['amount_sol'] ?? null,
                'amount_fiat' => $sessionInfo['amount_fiat'] ?? null,
                'fiat_currency' => $sessionInfo['fiat_currency'] ?? null,
                'expiry_date' => $sessionInfo['expiry_date'] ?? null,
                'network' => $sessionInfo['network'] ?? null,
                'last_updated' => $updateData['last_updated'],
            ]
        ]
    ];
}

/**
 * Refresh traditional payment status
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param ApiClient $apiClient API client instance
 * @param array $gatewayParams Gateway configuration
 * @return array Result data
 */
function refreshTraditionalPaymentStatus($invoiceId, $apiClient, $gatewayParams)
{
    // Get stored traditional invoice data
    $invoiceData = getStoredTraditionalInvoiceData($invoiceId);
    if (!$invoiceData) {
        throw new Exception('Traditional invoice data not found');
    }
    
    $voltxtInvoiceNumber = $invoiceData['voltxt_invoice_number'];
    $previousStatus = $invoiceData['status'] ?? 'unknown';
    
    // Get latest invoice status from VOLTXT
    $response = $apiClient->getInvoice($voltxtInvoiceNumber);
    
    if (!isset($response['success']) || !$response['success']) {
        $errorMessage = isset($response['message']) ? $response['message'] : 'Failed to retrieve invoice status';
        throw new Exception($errorMessage);
    }
    
    $invoiceInfo = $response['invoice'];
    $newStatus = $invoiceInfo['status'];
    
    // Update stored invoice metadata with latest data
    $updateData = [
        'status' => $newStatus,
        'last_updated' => date('Y-m-d H:i:s'),
    ];
    
    // Update transaction IDs if available
    if (!empty($invoiceInfo['payment_tx_id'])) {
        $updateData['payment_tx_id'] = $invoiceInfo['payment_tx_id'];
    }
    
    if (!empty($invoiceInfo['auto_process_tx_id'])) {
        $updateData['auto_process_tx_id'] = $invoiceInfo['auto_process_tx_id'];
    }
    
    // Update crypto amount if available
    if (!empty($invoiceInfo['amount_crypto'])) {
        $updateData['amount_crypto'] = $invoiceInfo['amount_crypto'];
    }
    
    updateTraditionalInvoiceMetadata($invoiceId, $updateData);
    
    // Log the status refresh
    logActivity("VOLTXT: Traditional invoice status refreshed for invoice {$invoiceId}. Status: {$previousStatus} → {$newStatus}");
    
    // If status changed to paid and WHMCS invoice is still unpaid, process payment
    $paymentProcessed = false;
    if (in_array($newStatus, ['paid', 'auto_processed']) && !in_array($previousStatus, ['paid', 'auto_processed'])) {
        $paymentProcessed = processCompletedTraditionalPayment($invoiceId, $invoiceInfo, $gatewayParams);
    }
    
    // Prepare response data
    $message = $previousStatus !== $newStatus ? 
        "Traditional invoice status updated from '{$previousStatus}' to '{$newStatus}'" : 
        "Traditional invoice status confirmed as '{$newStatus}' (no change)";
    
    if ($paymentProcessed) {
        $message .= " and payment applied to WHMCS invoice";
    }
    
    return [
        'success' => true,
        'message' => $message,
        'data' => [
            'payment_type' => 'traditional',
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'invoice_data' => [
                'invoice_number' => $invoiceInfo['invoice_number'],
                'status' => $invoiceInfo['status'],
                'amount_fiat' => $invoiceInfo['amount_fiat'] ?? null,
                'amount_crypto' => $invoiceInfo['amount_crypto'] ?? null,
                'amount_received_crypto' => $invoiceInfo['amount_received_crypto'] ?? null,
                'payment_progress_percentage' => $invoiceInfo['payment_progress_percentage'] ?? null,
                'payment_tx_id' => $invoiceInfo['payment_tx_id'] ?? null,
                'auto_process_tx_id' => $invoiceInfo['auto_process_tx_id'] ?? null,
                'network' => $invoiceInfo['network'] ?? null,
                'is_expired' => $invoiceInfo['is_expired'] ?? false,
                'is_overpaid' => $invoiceInfo['is_overpaid'] ?? false,
                'last_updated' => $updateData['last_updated'],
            ]
        ]
    ];
}

/**
 * Process completed dynamic payment and add to WHMCS
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $sessionInfo Session information
 * @param array $gatewayParams Gateway configuration
 * @return bool True if payment was processed
 */
function processCompletedDynamicPayment($invoiceId, $sessionInfo, $gatewayParams)
{
    try {
        // Check if WHMCS invoice is still unpaid
        $whmcsInvoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first();
            
        if ($whmcsInvoice && $whmcsInvoice->status !== 'Paid') {
            $paymentAmount = $sessionInfo['amount_fiat'] ?? 0;
            $transactionId = $sessionInfo['session_id'] ?? ('dynamic-' . time());
            
            // Add payment to WHMCS
            addInvoicePayment(
                $invoiceId,
                $transactionId,
                $paymentAmount,
                0, // fees
                'voltxt'
            );
            
            logTransaction('voltxt', [
                'invoice_id' => $invoiceId,
                'session_id' => $sessionInfo['session_id'] ?? null,
                'amount' => $paymentAmount,
                'network' => strtoupper($sessionInfo['network'] ?? 'unknown')
            ], 'Dynamic Payment Added via Status Refresh');
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        logActivity("VOLTXT: Failed to apply dynamic payment during status refresh for invoice {$invoiceId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Process completed traditional payment and add to WHMCS
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $invoiceInfo Invoice information
 * @param array $gatewayParams Gateway configuration
 * @return bool True if payment was processed
 */
function processCompletedTraditionalPayment($invoiceId, $invoiceInfo, $gatewayParams)
{
    try {
        // Check if WHMCS invoice is still unpaid
        $whmcsInvoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first();
            
        if ($whmcsInvoice && $whmcsInvoice->status !== 'Paid') {
            $paymentAmount = $invoiceInfo['amount_fiat'] ?? 0;
            $transactionId = $invoiceInfo['payment_tx_id'] ?? $invoiceInfo['invoice_number'];
            
            // Add payment to WHMCS
            addInvoicePayment(
                $invoiceId,
                $transactionId,
                $paymentAmount,
                0, // fees
                'voltxt'
            );
            
            logTransaction('voltxt', [
                'invoice_id' => $invoiceId,
                'voltxt_invoice' => $invoiceInfo['invoice_number'],
                'payment_tx_id' => $transactionId,
                'amount' => $paymentAmount,
                'network' => strtoupper($invoiceInfo['network'] ?? 'unknown')
            ], 'Traditional Payment Added via Status Refresh');
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        logActivity("VOLTXT: Failed to apply traditional payment during status refresh for invoice {$invoiceId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get stored dynamic payment session data from WHMCS invoice metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @return array|null Session data or null
 */
function getStoredDynamicSessionData($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        // Extract VOLTXT dynamic session data from admin notes
        if (preg_match('/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $sessionData = unserialize($serializedData);
            
            if ($sessionData && is_array($sessionData)) {
                return $sessionData;
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get stored traditional invoice data from WHMCS invoice metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @return array|null Invoice data or null
 */
function getStoredTraditionalInvoiceData($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        // Extract VOLTXT traditional invoice data from admin notes
        if (preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $invoiceData = unserialize($serializedData);
            
            if ($invoiceData && is_array($invoiceData)) {
                return $invoiceData;
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Update dynamic payment session data in WHMCS metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $updateData Data to update
 */
function updateDynamicSessionMetadata($invoiceId, $updateData)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if ($adminNotes && preg_match('/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $sessionData = unserialize($serializedData);
            
            if ($sessionData && is_array($sessionData)) {
                // Merge update data
                $sessionData = array_merge($sessionData, $updateData);
                
                // Re-serialize and update
                $newSerializedData = base64_encode(serialize($sessionData));
                $newAdminNotes = preg_replace('/VOLTXT_DYNAMIC:[A-Za-z0-9+\/=]+/', "VOLTXT_DYNAMIC:{$newSerializedData}", $adminNotes);
                
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(['adminonly' => $newAdminNotes]);
            }
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to update dynamic session metadata: ' . $e->getMessage());
    }
}

/**
 * Update traditional invoice data in WHMCS metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $updateData Data to update
 */
function updateTraditionalInvoiceMetadata($invoiceId, $updateData)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if ($adminNotes && preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $invoiceData = unserialize($serializedData);
            
            if ($invoiceData && is_array($invoiceData)) {
                // Merge update data
                $invoiceData = array_merge($invoiceData, $updateData);
                
                // Re-serialize and update
                $newSerializedData = base64_encode(serialize($invoiceData));
                $newAdminNotes = preg_replace('/VOLTXT_DATA:[A-Za-z0-9+\/=]+/', "VOLTXT_DATA:{$newSerializedData}", $adminNotes);
                
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(['adminonly' => $newAdminNotes]);
            }
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to update traditional invoice metadata: ' . $e->getMessage());
    }
}