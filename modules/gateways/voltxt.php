<?php
/**
 * VOLTXT Crypto Payment Gateway for WHMCS
 * 
 * Compatible with WHMCS 8.10.1 and PHP 7.4-8.4
 * Updated to use Dynamic Payment Controller
 * 
 * @author VOLTXT
 * @version 2.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/voltxt/lib/ApiClient.php';

use WHMCS\Module\Gateway\Voltxt\Lib\ApiClient;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

/**
 * Define gateway metadata.
 * This function is REQUIRED for WHMCS to discover the module.
 *
 * @return array
 */
function voltxt_MetaData()
{
    return [
        'DisplayName' => 'VOLTXT Crypto Payments',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Runs on module activation.
 * No database table required - uses WHMCS invoice metadata.
 *
 * @return array Success message.
 */
function voltxt_activate()
{
    return [
        'status' => 'success',
        'description' => 'VOLTXT gateway activated successfully. Ready to accept Solana (SOL) payments.',
    ];
}

/**
 * Runs on module deactivation.
 * Nothing to clean up as we use WHMCS invoice metadata.
 *
 * @return array Success message.
 */
function voltxt_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'VOLTXT gateway deactivated successfully.',
    ];
}

/**
 * Define gateway configuration options.
 *
 * @return array
 */
function voltxt_config()
{
    // Connection test
    $connectionStatusHtml = '';
    try {
        $gatewayParams = getGatewayVariables('voltxt');
        if (!empty($gatewayParams['apiKey']) && !empty($gatewayParams['apiUrl'])) {
            $network = ($gatewayParams['testMode'] === 'on') ? 'testnet' : 'mainnet';
            $apiClient = new ApiClient($gatewayParams['apiKey'], $gatewayParams['apiUrl'], $network);
            $companyName = Setting::getValue('CompanyName') ?: 'WHMCS Installation';
            $testResult = $apiClient->testConnection($companyName);
            
            if (isset($testResult['success']) && $testResult['success']) {
                $networkDisplay = ($network === 'mainnet') ? 
                    '<strong style="color: #d9534f;">MAINNET (LIVE)</strong>' : 
                    '<strong style="color: #5cb85c;">TESTNET</strong>';
                $details = "✓ Successfully connected to VOLTXT on the {$networkDisplay} network.";
                
                if (!empty($testResult['data']['store']['name'])) {
                    $details .= "<br>Store: <strong>" . htmlspecialchars($testResult['data']['store']['name']) . "</strong>";
                }
                if (!empty($testResult['data']['user']['email'])) {
                    $details .= "<br>Account: <strong>" . htmlspecialchars($testResult['data']['user']['email']) . "</strong>";
                }
                if (isset($testResult['data']['store']['has_destination_wallet'])) {
                    $walletStatus = $testResult['data']['store']['has_destination_wallet'] ? 
                        '<span style="color: #5cb85c;">✓ Configured</span>' : 
                        '<span style="color: #d9534f;">✗ Not Configured</span>';
                    $details .= "<br>Destination Wallet: {$walletStatus}";
                }
                
                $connectionStatusHtml = '<div class="alert alert-success" style="margin: 15px 0;">' . $details . '</div>';
            } else {
                $errorMessage = isset($testResult['message']) ? $testResult['message'] : 'Unknown error occurred';
                $errorCode = isset($testResult['error_code']) ? ' (' . $testResult['error_code'] . ')' : '';
                $connectionStatusHtml = '<div class="alert alert-danger" style="margin: 15px 0;"><strong>✗ Connection Failed:</strong> ' . htmlspecialchars($errorMessage) . $errorCode . '</div>';
            }
        } else {
            $connectionStatusHtml = '<div class="alert alert-warning" style="margin: 15px 0;"><strong>Configuration Required:</strong> Please enter your API credentials below and save to test the connection.</div>';
        }
    } catch (Exception $e) {
        $connectionStatusHtml = '<div class="alert alert-danger" style="margin: 15px 0;"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'VOLTXT Crypto Payments (SOL)'],
        'connectionStatus' => [
            'Type' => 'description', 
            'Description' => $connectionStatusHtml . '<p><strong>Note:</strong> The connection will be tested automatically when you save your configuration.</p>'
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://api.voltxt.io',
            'Description' => 'Your VOLTXT API endpoint URL.',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Your VOLTXT API Key from your account dashboard.',
        ],
        'testMode' => [
            'FriendlyName' => 'Testnet Mode',
            'Type' => 'yesno',
            'Description' => 'Enable for testing with Solana Testnet. Disable for live transactions on Mainnet.',
        ],
        'expiryHours' => [
            'FriendlyName' => 'Payment Expiry (Hours)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '24',
            'Description' => 'Hours until payment expires (1-168 hours).',
        ],
        'showInstructions' => [
            'FriendlyName' => 'Show Payment Instructions',
            'Type' => 'yesno',
            'Description' => 'Show additional payment instructions to customers.',
            'Default' => 'on',
        ],
    ];
}

/**
 * Generate the payment link/button for the invoice.
 *
 * @param array $params Payment gateway parameters.
 * @return string The HTML for the payment button.
 */
function voltxt_link($params)
{
    // Validate required parameters
    if (empty($params['apiKey']) || empty($params['apiUrl'])) {
        return '<div class="alert alert-danger">Gateway not configured properly. Please contact support.</div>';
    }

    $network = ($params['testMode'] === 'on') ? 'testnet' : 'mainnet';
    $invoiceId = $params['invoiceid'];
    
    // Dynamic payments are always enabled (no option to disable)
    $useDynamicPayments = true;

    try {
        $apiClient = new ApiClient($params['apiKey'], $params['apiUrl'], $network);
        
        if ($useDynamicPayments) {
            // Use Dynamic Payment Controller
            $paymentUrl = createDynamicPayment($params, $apiClient);
        } else {
            // Use Traditional Invoice Controller (fallback)
            $paymentUrl = createTraditionalInvoice($params, $apiClient);
        }
        
        // Generate payment button HTML
        $networkBadge = ($network === 'testnet') ? 
            '<span class="badge badge-success" style="margin-left: 10px;">TESTNET</span>' : 
            '<span class="badge badge-danger" style="margin-left: 10px;">LIVE</span>';

        $htmlOutput = '<div class="voltxt-payment-section" style="margin: 20px 0;">';
        $htmlOutput .= '<div class="row">';
        $htmlOutput .= '<div class="col-md-12">';
        
        // Payment button
        $htmlOutput .= '<a href="' . htmlspecialchars($paymentUrl) . '" class="btn btn-primary btn-lg btn-block" target="_blank" rel="noopener noreferrer">';
        $htmlOutput .= '<i class="fas fa-coins"></i> Pay with Solana (SOL)';
        $htmlOutput .= '</a>';
        
        $htmlOutput .= $networkBadge;
        
        // Show instructions if enabled
        if (isset($params['showInstructions']) && $params['showInstructions'] === 'on') {
            $htmlOutput .= '<div class="alert alert-info" style="margin-top: 15px;">';
            $htmlOutput .= '<h5><i class="fas fa-info-circle"></i> Payment Instructions</h5>';
            $htmlOutput .= '<ul class="mb-0">';
            $htmlOutput .= '<li>Click the button above to open the VOLTXT payment page</li>';
            $htmlOutput .= '<li>You will be provided with a Solana wallet address to send payment to</li>';
            $htmlOutput .= '<li>Send the exact SOL amount shown on the payment page</li>';
            if ($network === 'testnet') {
                $htmlOutput .= '<li><strong>This is a test transaction using Testnet SOL (no real value)</strong></li>';
            }
            $htmlOutput .= '<li>Your order will be processed automatically once payment is confirmed</li>';
            $htmlOutput .= '</ul>';
            $htmlOutput .= '</div>';
        }
        
        // Add dynamic payment info since it's always enabled
        $htmlOutput .= '<div class="alert alert-success" style="margin-top: 10px; font-size: 12px;">';
        $htmlOutput .= '<i class="fas fa-bolt"></i> <strong>Dynamic Payment:</strong> Real-time pricing and instant processing enabled.';
        $htmlOutput .= '</div>';
        
        $htmlOutput .= '</div>';
        $htmlOutput .= '</div>';
        $htmlOutput .= '</div>';

        return $htmlOutput;

    } catch (Exception $e) {
        logActivity('VOLTXT Plugin Exception for WHMCS Invoice #' . $invoiceId . ': ' . $e->getMessage());
        return '<div class="alert alert-danger">A critical error occurred. Please contact support. Reference: ' . $invoiceId . '</div>';
    }
}

/**
 * Create a dynamic payment session
 *
 * @param array $params Payment parameters
 * @param ApiClient $apiClient API client instance
 * @return string Payment URL
 */
function createDynamicPayment($params, $apiClient)
{
    $invoiceId = $params['invoiceid'];
    $network = $apiClient->getNetwork();
    
    // Check for existing dynamic payment session
    $existingSession = getExistingDynamicSession($invoiceId, $network, $params);
    
    if ($existingSession && !isDynamicSessionExpired($existingSession)) {
        // Use existing valid session - return URL as-is from API
        return $existingSession['payment_url'];
    }
    
    // Clear any old session data
    clearDynamicSessionData($invoiceId);
    
    // Create new dynamic payment session
    $response = $apiClient->createDynamicPayment($params);
    
    if (isset($response['success']) && $response['success']) {
        $sessionData = $response['data'];
        $paymentUrl = $sessionData['payment_url']; // Use URL as returned by API
        $sessionId = $sessionData['session_id'];
        
        // Store session data
        storeDynamicSessionData($invoiceId, $sessionData, $network);
        
        logTransaction($params['name'], [
            'WHMCS Invoice ID' => $invoiceId,
            'Dynamic Session ID' => $sessionId,
            'Network' => strtoupper($network),
            'Amount' => $params['amount'] . ' ' . $params['currency']
        ], 'Dynamic Session Created');
        
        return $paymentUrl;
    } else {
        $errorMessage = isset($response['message']) ? $response['message'] : 'Unknown error occurred';
        logActivity('VOLTXT Dynamic Payment Creation Failed for WHMCS Invoice #' . $invoiceId . ': ' . $errorMessage);
        throw new Exception('Could not create payment session. Error: ' . $errorMessage);
    }
}

/**
 * Create a traditional invoice (fallback method)
 *
 * @param array $params Payment parameters
 * @param ApiClient $apiClient API client instance
 * @return string Payment URL
 */
function createTraditionalInvoice($params, $apiClient)
{
    $invoiceId = $params['invoiceid'];
    $network = $apiClient->getNetwork();
    
    // Check for existing traditional invoice
    $existingInvoice = getExistingTraditionalInvoice($invoiceId, $network, $params);
    
    if ($existingInvoice && !isTraditionalInvoiceExpired($existingInvoice)) {
        // Use existing valid invoice, but ensure URL uses app domain and correct path
        $paymentUrl = $existingInvoice['payment_url'];
        $paymentUrl = str_replace('api.voltxt.io', 'app.voltxt.io', $paymentUrl);
        $paymentUrl = str_replace('/invoice/', '/pay/', $paymentUrl);
        return $paymentUrl;
    }
    
    // Clear any old data
    clearTraditionalInvoiceData($invoiceId);
    
    // Create new traditional invoice
    $response = $apiClient->createInvoice($params);
    
    if (isset($response['success']) && $response['success']) {
        $invoiceData = $response['invoice'];
        $paymentUrl = $invoiceData['payment_url'];
        $voltxtInvoiceNumber = $invoiceData['invoice_number'];
        
        // Store invoice data
        storeTraditionalInvoiceData($invoiceId, $invoiceData, $network);
        
        logTransaction($params['name'], [
            'WHMCS Invoice ID' => $invoiceId,
            'VOLTXT Invoice' => $voltxtInvoiceNumber,
            'Network' => strtoupper($network),
            'Amount' => $params['amount'] . ' ' . $params['currency']
        ], 'Traditional Invoice Created');
        
        return $paymentUrl;
    } else {
        $errorMessage = isset($response['message']) ? $response['message'] : 'Unknown error occurred';
        logActivity('VOLTXT Traditional Invoice Creation Failed for WHMCS Invoice #' . $invoiceId . ': ' . $errorMessage);
        throw new Exception('Could not create payment invoice. Error: ' . $errorMessage);
    }
}

/**
 * Get existing dynamic payment session from WHMCS metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param string $network Current network (testnet/mainnet)
 * @param array $params Current gateway parameters
 * @return array|null Session data or null
 */
function getExistingDynamicSession($invoiceId, $network, $params)
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
            $sessionInfo = unserialize($serializedData);
            
            if ($sessionInfo && is_array($sessionInfo)) {
                // Check if network matches and invoice amount/currency matches
                if (isset($sessionInfo['network']) && $sessionInfo['network'] === $network &&
                    isset($sessionInfo['amount_fiat']) && $sessionInfo['amount_fiat'] == $params['amount'] &&
                    isset($sessionInfo['currency']) && $sessionInfo['currency'] === $params['currency']) {
                    return $sessionInfo;
                }
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error retrieving existing dynamic session data: ' . $e->getMessage());
        return null;
    }
}

/**
 * Store dynamic payment session data in WHMCS invoice metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $sessionData Session data
 * @param string $network Network used
 */
function storeDynamicSessionData($invoiceId, $sessionData, $network)
{
    try {
        $dynamicData = [
            'session_id' => $sessionData['session_id'],
            'payment_url' => $sessionData['payment_url'], // Store URL as returned by API
            'status_check_url' => $sessionData['status_check_url'],
            'network' => $network,
            'amount_sol' => $sessionData['amount_sol'],
            'amount_fiat' => $sessionData['amount_fiat'] ?? null,
            'currency' => $sessionData['fiat_currency'],
            'platform_fee_amount' => $sessionData['platform_fee_amount'],
            'expires_at' => $sessionData['expiry_date'],
            'deposit_address' => $sessionData['deposit_address'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s'),
        ];
        
        // Store as serialized data in admin-only notes
        $serializedData = base64_encode(serialize($dynamicData));
        
        $currentAdminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly') ?: '';
            
        // Add dynamic session data marker
        $dynamicDataMarker = "VOLTXT_DYNAMIC:{$serializedData}";
        
        // Remove any existing VOLTXT data first
        $currentAdminNotes = preg_replace('/VOLTXT_DYNAMIC:[A-Za-z0-9+\/=]+/', '', $currentAdminNotes);
        $currentAdminNotes = preg_replace('/VOLTXT_DATA:[A-Za-z0-9+\/=]+/', '', $currentAdminNotes);
        
        // Add new dynamic session data
        $newAdminNotes = trim($currentAdminNotes . "\n" . $dynamicDataMarker);
        
        Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['adminonly' => $newAdminNotes]);
            
    } catch (Exception $e) {
        logActivity('VOLTXT: Error storing dynamic session data: ' . $e->getMessage());
    }
}

/**
 * Check if dynamic payment session has expired
 *
 * @param array $sessionData Session data
 * @return bool True if expired
 */
function isDynamicSessionExpired($sessionData)
{
    if (!isset($sessionData['expires_at']) || !$sessionData['expires_at']) {
        return false;
    }
    
    try {
        $expiryTime = strtotime($sessionData['expires_at']);
        return $expiryTime < time();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Clear dynamic payment session data from WHMCS metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 */
function clearDynamicSessionData($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if ($adminNotes) {
            // Remove VOLTXT dynamic session data from admin notes
            $cleanedNotes = preg_replace('/VOLTXT_DYNAMIC:[A-Za-z0-9+\/=]+/', '', $adminNotes);
            $cleanedNotes = trim($cleanedNotes);
            
            // Update admin notes
            Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update(['adminonly' => $cleanedNotes]);
        }
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error clearing dynamic session data: ' . $e->getMessage());
    }
}

/**
 * Update dynamic payment session data in WHMCS metadata
 *
 * @param int $invoiceId WHMCS invoice ID
 * @param array $updateData Data to update
 */
function updateDynamicSessionData($invoiceId, $updateData)
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
                $sessionData['last_updated'] = date('Y-m-d H:i:s');
                
                // Re-serialize and update
                $newSerializedData = base64_encode(serialize($sessionData));
                $newAdminNotes = preg_replace('/VOLTXT_DYNAMIC:[A-Za-z0-9+\/=]+/', "VOLTXT_DYNAMIC:{$newSerializedData}", $adminNotes);
                
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(['adminonly' => $newAdminNotes]);
            }
        }
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error updating dynamic session data: ' . $e->getMessage());
    }
}

// Legacy functions for traditional invoice support
function getExistingTraditionalInvoice($invoiceId, $network, $params)
{
    // Implementation for backward compatibility
    return getExistingVoltxtInvoice($invoiceId, $network, $params);
}

function storeTraditionalInvoiceData($invoiceId, $invoiceData, $network)
{
    // Implementation for backward compatibility
    return storeVoltxtInvoiceData($invoiceId, $invoiceData, $network);
}

function isTraditionalInvoiceExpired($invoiceData)
{
    // Implementation for backward compatibility
    return isVoltxtInvoiceExpired($invoiceData);
}

function clearTraditionalInvoiceData($invoiceId)
{
    // Implementation for backward compatibility
    return clearVoltxtInvoiceData($invoiceId);
}

// Keep existing legacy functions for backward compatibility
function getExistingVoltxtInvoice($invoiceId, $network, $params)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        if (preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $voltxtInfo = unserialize($serializedData);
            
            if ($voltxtInfo && is_array($voltxtInfo)) {
                if (isset($voltxtInfo['network']) && $voltxtInfo['network'] === $network &&
                    isset($voltxtInfo['amount_fiat']) && $voltxtInfo['amount_fiat'] == $params['amount'] &&
                    isset($voltxtInfo['currency']) && $voltxtInfo['currency'] === $params['currency']) {
                    return $voltxtInfo;
                }
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error retrieving existing invoice data: ' . $e->getMessage());
        return null;
    }
}

function storeVoltxtInvoiceData($invoiceId, $invoiceData, $network)
{
    try {
        $voltxtData = [
            'voltxt_invoice_number' => $invoiceData['invoice_number'],
            'payment_url' => $invoiceData['payment_url'],
            'network' => $network,
            'status' => $invoiceData['status'] ?? 'pending',
            'amount_fiat' => $invoiceData['amount_fiat'],
            'currency' => $invoiceData['fiat_currency'],
            'amount_crypto' => $invoiceData['amount_crypto'] ?? null,
            'expires_at' => $invoiceData['expiry_date'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s'),
        ];
        
        // Ensure payment URL uses app domain and correct path
        if (isset($voltxtData['payment_url'])) {
            $voltxtData['payment_url'] = str_replace('api.voltxt.io', 'app.voltxt.io', $voltxtData['payment_url']);
            $voltxtData['payment_url'] = str_replace('/invoice/', '/pay/', $voltxtData['payment_url']);
        }
        
        $serializedData = base64_encode(serialize($voltxtData));
        
        $currentAdminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly') ?: '';
            
        $voltxtDataMarker = "VOLTXT_DATA:{$serializedData}";
        
        $currentAdminNotes = preg_replace('/VOLTXT_DATA:[A-Za-z0-9+\/=]+/', '', $currentAdminNotes);
        
        $newAdminNotes = trim($currentAdminNotes . "\n" . $voltxtDataMarker);
        
        Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['adminonly' => $newAdminNotes]);
            
    } catch (Exception $e) {
        logActivity('VOLTXT: Error storing invoice data: ' . $e->getMessage());
    }
}

function isVoltxtInvoiceExpired($voltxtData)
{
    if (!isset($voltxtData['expires_at']) || !$voltxtData['expires_at']) {
        return false;
    }
    
    try {
        $expiryTime = strtotime($voltxtData['expires_at']);
        return $expiryTime < time();
    } catch (Exception $e) {
        return false;
    }
}

function clearVoltxtInvoiceData($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if ($adminNotes) {
            $cleanedNotes = preg_replace('/VOLTXT_DATA:[A-Za-z0-9+\/=]+/', '', $adminNotes);
            $cleanedNotes = trim($cleanedNotes);
            
            Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update(['adminonly' => $cleanedNotes]);
        }
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error clearing invoice data: ' . $e->getMessage());
    }
}

function updateVoltxtInvoiceData($invoiceId, $updateData)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if ($adminNotes && preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $voltxtData = unserialize($serializedData);
            
            if ($voltxtData && is_array($voltxtData)) {
                $voltxtData = array_merge($voltxtData, $updateData);
                $voltxtData['last_updated'] = date('Y-m-d H:i:s');
                
                $newSerializedData = base64_encode(serialize($voltxtData));
                $newAdminNotes = preg_replace('/VOLTXT_DATA:[A-Za-z0-9+\/=]+/', "VOLTXT_DATA:{$newSerializedData}", $adminNotes);
                
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->update(['adminonly' => $newAdminNotes]);
            }
        }
        
    } catch (Exception $e) {
        logActivity('VOLTXT: Error updating invoice data: ' . $e->getMessage());
    }
}

/**
 * Handle refund requests.
 *
 * @param array $params Payment gateway parameters.
 * @return array The result of the refund attempt.
 */
function voltxt_refund($params)
{
    logTransaction($params['name'], [
        'Transaction ID' => $params['transid'],
        'Refund Amount' => $params['amount'],
        'Currency' => $params['currency'],
        'Reason' => 'Manual refund requested'
    ], 'Refund Request');

    return [
        'status' => 'error',
        'rawdata' => 'Cryptocurrency refunds must be processed manually. Please send the refund directly from your crypto wallet to the customer and record the transaction details here.',
        'transid' => $params['transid'],
    ];
}

/**
 * Capture a previously authorized transaction.
 * (Not applicable for crypto payments)
 *
 * @param array $params Payment gateway parameters.
 * @return array The result of the capture attempt.
 */
function voltxt_capture($params)
{
    return [
        'status' => 'error',
        'rawdata' => 'Capture is not supported for cryptocurrency payments.',
    ];
}

/**
 * Void a transaction.
 * (Not applicable for crypto payments)
 *
 * @param array $params Payment gateway parameters.
 * @return array The result of the void attempt.
 */
function voltxt_void($params)
{
    return [
        'status' => 'error',
        'rawdata' => 'Void is not supported for cryptocurrency payments.',
    ];
}