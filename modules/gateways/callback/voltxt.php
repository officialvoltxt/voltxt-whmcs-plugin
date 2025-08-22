<?php
/**
 * Voltxt Solana Payment Gateway - Callback Handler
 * Handles webhooks and API connection testing
 *
 * @package    WHMCS
 * @author     Voltxt
 * @copyright  2025 Voltxt
 * @version    1.0.0
 */

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// Ensure Capsule is available
use Illuminate\Database\Capsule\Manager as Capsule;

// Get gateway configuration
$gatewayConfig = getGatewayVariables('voltxt');

if (!$gatewayConfig['type']) {
    die('Gateway not configured');
}

try {
    $action = $_GET['action'] ?? 'webhook';
    
    switch ($action) {
        case 'test':
            handleConnectionTest();
            break;
            
        case 'webhook':
            handleWebhook();
            break;
            
        default:
            handleStatusCheck();
    }

} catch (Exception $e) {
    error_log("Voltxt Callback Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Handle API connection test
 */
function handleConnectionTest()
{
    header('Content-Type: application/json');
    
    try {
        // Check admin access
        if (!isset($_SESSION['adminid'])) {
            throw new Exception('Admin access required');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST request required');
        }

        $apiKey = $_POST['api_key'] ?? '';
        $network = $_POST['network'] ?? 'testnet';

        // Basic validation
        if (empty($apiKey)) {
            throw new Exception('API key is required');
        }

        if (strlen($apiKey) !== 32) {
            throw new Exception('API key must be 32 characters');
        }

        if (!in_array($network, ['testnet', 'mainnet'])) {
            throw new Exception('Invalid network');
        }

        // Test API connection
        $testData = [
            'api_key' => $apiKey,
            'network' => $network,
            'store_name' => 'WHMCS Store',
        ];

        $response = callVoltxtTestAPI('/api/plugin/test-connection', $testData);

        if ($response['success']) {
            $store = $response['data']['store'];
            
            echo json_encode([
                'success' => true,
                'store_name' => $store['name'],
                'network' => $store['network'],
                'has_wallet' => $store['has_destination_wallet'],
                'message' => 'Connection successful'
            ]);
        } else {
            throw new Exception($response['error'] ?? 'Connection test failed');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Handle webhook from Voltxt backend
 */
function handleWebhook()
{
    global $gatewayConfig;
    
    $rawPayload = file_get_contents('php://input');
    if (empty($rawPayload)) {
        throw new Exception('Empty webhook payload');
    }

    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload');
    }

    // Log for debugging
    error_log("Voltxt Webhook Payload: " . json_encode($payload));

    $eventType = $payload['event_type'] ?? '';
    $sessionId = $payload['session_id'] ?? $payload['external_invoice_id'] ?? '';
    $status = $payload['status'] ?? '';
    $paymentTxId = $payload['payment_tx_id'] ?? '';
    $amountReceived = $payload['amount_received_crypto'] ?? 0;

    if (empty($sessionId)) {
        throw new Exception('Missing session ID in webhook');
    }

    if (empty($paymentTxId)) {
        error_log("Voltxt Webhook Warning: Missing payment_tx_id for session {$sessionId}");
    }

    $invoiceId = findInvoiceBySession($sessionId);
    if (!$invoiceId) {
        // Try to extract from metadata
        $metadata = $payload['metadata'] ?? [];
        $invoiceId = $metadata['invoice_id'] ?? $metadata['whmcs_invoice_id'] ?? null;
    }
    
    if (!$invoiceId) {
        throw new Exception('Invoice not found for session: ' . $sessionId);
    }

    logActivity("Voltxt Gateway: Webhook received - Event: {$eventType}, Status: {$status}, TxID: {$paymentTxId}", $invoiceId);

    // Process payment completion for any "completed" status
    if ($eventType === 'payment_completed' || in_array($status, ['completed', 'paid', 'auto_processed'])) {
        if (empty($paymentTxId)) {
            throw new Exception('Payment transaction ID required for completed payment');
        }
        processPaymentCompleted($invoiceId, $paymentTxId, $amountReceived, $sessionId);
    } else {
        // Handle other statuses
        updateSessionStatus($sessionId, $status);
        logActivity("Voltxt Gateway: Status updated to {$status}", $invoiceId);
    }

    echo json_encode(['success' => true, 'invoice_id' => $invoiceId]);
}

/**
 * Handle status check from frontend
 */
function handleStatusCheck()
{
    $sessionId = $_GET['session_id'] ?? '';
    
    if (empty($sessionId)) {
        throw new Exception('Session ID required');
    }

    // Get session from database
    $pdo = Capsule::connection()->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM mod_voltxt_sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Session not found');
    }

    // Check if invoice is paid
    $stmt = $pdo->prepare("SELECT status FROM tblinvoices WHERE id = ?");
    $stmt->execute([$session['invoice_id']]);
    $invoiceStatus = $stmt->fetch(PDO::FETCH_COLUMN);

    $response = [
        'success' => true,
        'session' => [
            'session_id' => $sessionId,
            'status' => $invoiceStatus === 'Paid' ? 'completed' : $session['status'],
            'invoice_id' => $session['invoice_id'],
        ]
    ];

    echo json_encode($response);
}

/**
 * Process completed payment
 */
function processPaymentCompleted($invoiceId, $paymentTxId, $amountReceived, $sessionId)
{
    // Check if already paid
    $invoice = Capsule::table('tblinvoices')->find($invoiceId);
    if (!$invoice) {
        throw new Exception('Invoice not found: ' . $invoiceId);
    }
    
    if ($invoice->status === 'Paid') {
        logActivity("Voltxt Gateway: Invoice already paid", $invoiceId);
        return;
    }

    if (empty($paymentTxId)) {
        throw new Exception('Payment transaction ID missing');
    }

    // Add payment to WHMCS using the proper API
    $command = 'AddInvoicePayment';
    $postData = [
        'invoiceid' => $invoiceId,
        'transid' => $paymentTxId,
        'amount' => $invoice->total,
        'gateway' => 'voltxt',
        'date' => date('Y-m-d H:i:s'), // Include time
        'fees' => 0,
    ];

    logActivity("Voltxt Gateway: Adding payment - " . json_encode($postData), $invoiceId);

    $result = localAPI($command, $postData);

    logActivity("Voltxt Gateway: AddInvoicePayment result - " . json_encode($result), $invoiceId);

    if ($result['result'] === 'success') {
        updateSessionStatus($sessionId, 'completed');
        logActivity("Voltxt Gateway: Payment confirmed - TxID: {$paymentTxId}, Amount: {$invoice->total}", $invoiceId);
        
        // Send email confirmation
        try {
            $emailCommand = 'SendEmail';
            $emailData = [
                'messagename' => 'Invoice Payment Confirmation',
                'id' => $invoiceId,
            ];
            $emailResult = localAPI($emailCommand, $emailData);
            
            if ($emailResult['result'] === 'success') {
                logActivity("Voltxt Gateway: Payment confirmation email sent", $invoiceId);
            }
        } catch (Exception $e) {
            logActivity("Voltxt Gateway: Failed to send confirmation email - " . $e->getMessage(), $invoiceId);
        }
        
    } else {
        $errorMsg = $result['message'] ?? json_encode($result);
        logActivity("Voltxt Gateway: Failed to add payment - " . $errorMsg, $invoiceId);
        throw new Exception('Failed to add payment to WHMCS: ' . $errorMsg);
    }
}

/**
 * Call Voltxt API for testing
 */
function callVoltxtTestAPI($endpoint, $data)
{
    $url = 'https://api.voltxt.io' . $endpoint;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WHMCS-Voltxt-Test/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Network error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid response format'];
    }

    return $decoded;
}

/**
 * Helper functions
 */
function updateSessionStatus($sessionId, $status)
{
    try {
        $pdo = Capsule::connection()->getPdo();
        $stmt = $pdo->prepare("UPDATE mod_voltxt_sessions SET status = ? WHERE session_id = ?");
        return $stmt->execute([$status, $sessionId]);
    } catch (Exception $e) {
        return false;
    }
}

function findInvoiceBySession($sessionId)
{
    try {
        $pdo = Capsule::connection()->getPdo();
        $stmt = $pdo->prepare("SELECT invoice_id FROM mod_voltxt_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        return $stmt->fetch(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return null;
    }
}