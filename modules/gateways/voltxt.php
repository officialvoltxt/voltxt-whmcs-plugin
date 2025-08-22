<?php
/**
 * Voltxt Solana Payment Gateway Module for WHMCS
 * Simple redirect-based gateway that integrates with Voltxt dynamic payment API
 *
 * @package    WHMCS
 * @author     Voltxt
 * @copyright  2025 Voltxt
 * @version    1.0.0
 * @link       https://voltxt.io
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Ensure Capsule is available
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Gateway metadata
 */
function voltxt_MetaData()
{
    return [
        'DisplayName' => 'Voltxt Solana Payment Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Gateway configuration fields
 */
function voltxt_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Voltxt Solana Payment Gateway',
        ],
        'api_key' => [
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your 32-character Voltxt API Key from app.voltxt.io',
        ],
        'network' => [
            'FriendlyName' => 'Network',
            'Type' => 'dropdown',
            'Options' => [
                'testnet' => 'Testnet (for testing)',
                'mainnet' => 'Mainnet (live payments)',
            ],
            'Default' => 'testnet',
            'Description' => 'Select Solana network',
        ],
        'expiry_hours' => [
            'FriendlyName' => 'Payment Expiry (Hours)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '24',
            'Description' => 'Hours before payment expires (1-168)',
        ],
        'debug_mode' => [
            'FriendlyName' => 'Debug Mode',
            'Type' => 'yesno',
            'Default' => 'off',
            'Description' => 'Enable debug logging',
        ],
        'test_connection' => [
            'FriendlyName' => 'Test Connection',
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => '<style>input[name="field[test_connection]"] { display: none; }</style>
                <button type="button" id="voltxt-test-btn" onclick="testVoltxtConnection()">Test API Connection</button>
                <div id="voltxt-test-result" style="margin-top: 10px;"></div>
                <script>
                function testVoltxtConnection() {
                    const btn = document.getElementById("voltxt-test-btn");
                    const result = document.getElementById("voltxt-test-result");
                    const apiKey = document.querySelector("input[name=\'field[api_key]\']").value;
                    const network = document.querySelector("select[name=\'field[network]\']").value;
                    
                    if (!apiKey) {
                        result.innerHTML = "<div style=\'color: orange;\'>Please enter API key first</div>";
                        return;
                    }
                    
                    btn.disabled = true;
                    btn.textContent = "Testing...";
                    result.innerHTML = "<div style=\'color: blue;\'>Testing connection...</div>";
                    
                    fetch("../modules/gateways/callback/voltxt.php?action=test", {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: "api_key=" + encodeURIComponent(apiKey) + "&network=" + encodeURIComponent(network)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            result.innerHTML = "<div style=\'color: green;\'>✓ Connected! Store: " + data.store_name + "</div>";
                        } else {
                            result.innerHTML = "<div style=\'color: red;\'>✗ Failed: " + data.error + "</div>";
                        }
                    })
                    .catch(error => {
                        result.innerHTML = "<div style=\'color: red;\'>✗ Error: " + error.message + "</div>";
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = "Test API Connection";
                    });
                }
                </script>',
        ],
    ];
}

/**
 * Payment processing - show payment button instead of immediate redirect
 */
function voltxt_link($params)
{
    try {
        // Validate configuration
        if (empty($params['api_key']) || strlen($params['api_key']) !== 32) {
            return ['error' => 'Invalid API key configuration'];
        }

        // Check for existing active session first
        $existingSession = getExistingActiveSession($params['invoiceid']);
        $sessionId = null;
        $paymentUrl = null;

        if ($existingSession) {
            // Check if amount matches current invoice
            $amountMatches = abs(floatval($existingSession['amount']) - floatval($params['amount'])) < 0.01;
            $currencyMatches = $existingSession['currency'] === $params['currency'];
            
            if ($amountMatches && $currencyMatches) {
                logActivity("Voltxt Gateway: Found existing session with matching amount - Session ID: " . $existingSession['session_id'], $params['invoiceid']);
                
                // Update callback URL in case system URL changed
                updateSessionCallbackUrl($existingSession['session_id'], $params['systemurl'] . 'modules/gateways/callback/voltxt.php');
                
                // Check session status via API
                $statusResponse = callVoltxtAPI('/api/dynamic-payment/' . $existingSession['session_id'] . '/status?api_key=' . $params['api_key'], [], 'GET');
                
                if ($statusResponse['success'] && $statusResponse['session']['status'] === 'pending') {
                    // Use existing session
                    $sessionId = $existingSession['session_id'];
                    $frontendUrl = 'https://app.voltxt.io';
                    $paymentUrl = $frontendUrl . '/pay-dynamic/' . $sessionId . '?source=whmcs&platform=whmcs&external_id=whmcs_' . $params['invoiceid'];
                }
            } else {
                logActivity("Voltxt Gateway: Existing session found but amount/currency mismatch - creating new session", $params['invoiceid']);
            }
        }

        // Create new session if no existing one found
        if (!$sessionId) {
            $paymentData = [
                'api_key' => $params['api_key'],
                'network' => $params['network'],
                'platform' => 'whmcs',
                'external_payment_id' => 'whmcs_' . $params['invoiceid'] . '_' . time(),
                'amount_type' => 'fiat',
                'amount' => floatval($params['amount']),
                'fiat_currency' => $params['currency'],
                'expiry_hours' => intval($params['expiry_hours']) ?: 24,
                'description' => 'WHMCS Invoice #' . $params['invoiceid'],
                'customer_email' => $params['clientdetails']['email'],
                'customer_name' => trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']),
                'callback_url' => $params['systemurl'] . 'modules/gateways/callback/voltxt.php',
                'success_url' => $params['returnurl'] . '?voltxt_session=[session_id]&voltxt_payment=completed',
                'cancel_url' => $params['returnurl'] . '?voltxt_session=[session_id]&voltxt_payment=cancelled',
                'metadata' => [
                    'invoice_id' => $params['invoiceid'],
                    'client_id' => $params['clientdetails']['userid'],
                    'whmcs_invoice_id' => $params['invoiceid'],
                ],
            ];

            // Call Voltxt API
            $response = callVoltxtAPI('/api/dynamic-payment/initiate', $paymentData);

            if (!$response['success']) {
                logActivity("Voltxt Gateway: Failed to create payment session - " . ($response['error'] ?? 'Unknown error'), $params['invoiceid']);
                return ['error' => 'Payment initialization failed: ' . ($response['error'] ?? 'Please try again')];
            }

            $sessionData = $response['data'];
            $sessionId = $sessionData['session_id'];
            $paymentUrl = $sessionData['payment_url'];
            
            // Store session ID for webhook processing
            storeSessionData($params['invoiceid'], $sessionId, $params);

            logActivity("Voltxt Gateway: Payment session created - Session ID: " . $sessionId, $params['invoiceid']);
        }

        // Return payment form HTML with button
        return generatePaymentForm($paymentUrl, $params, $sessionId);

    } catch (Exception $e) {
        logActivity("Voltxt Gateway: Error - " . $e->getMessage(), $params['invoiceid']);
        return ['error' => 'Payment processing error'];
    }
}

/**
 * Handle return from payment page
 */
function voltxt_return($params)
{
    $sessionId = $_GET['voltxt_session'] ?? '';
    $paymentStatus = $_GET['voltxt_payment'] ?? '';
    $txId = $_GET['voltxt_tx_id'] ?? '';
    
    if ($sessionId && $paymentStatus === 'completed') {
        // Verify payment was actually processed
        $gatewayConfig = getGatewayVariables('voltxt');
        $statusResponse = callVoltxtAPI('/api/dynamic-payment/' . $sessionId . '/status?api_key=' . $gatewayConfig['api_key'], [], 'GET');
        
        if ($statusResponse['success'] && in_array($statusResponse['session']['status'], ['completed', 'paid', 'auto_processed'])) {
            // Payment completed, redirect with success
            $successUrl = $params['returnurl'];
            $successUrl .= (strpos($successUrl, '?') === false ? '?' : '&') . 'paymentsuccess=true';
            if ($txId) {
                $successUrl .= '&txid=' . urlencode($txId);
            }
            header('Location: ' . $successUrl);
            exit;
        }
    }
    
    // Redirect back to invoice
    header('Location: ' . $params['returnurl']);
    exit;
}

/**
 * Generate payment form with Solana pay button
 */
function generatePaymentForm($paymentUrl, $params, $sessionId)
{
    $invoiceAmount = VoltxtHelper::formatAmount($params['amount'], $params['currency']);
    
    $html = '
    <div class="voltxt-payment-form" style="max-width: 500px; margin: 20px auto; padding: 25px; border: 2px solid #e1e5e9; border-radius: 12px; background: #ffffff; text-align: center; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
        <div class="voltxt-header" style="margin-bottom: 20px;">
            <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiM5OTQ1RkYiLz4KPHN2ZyB4PSI4IiB5PSI4IiB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzAwRkZBMyI+CjxwYXRoIGQ9Ik0xMiAyQzYuNDggMiAyIDYuNDggMiAxMlM2LjQ4IDIyIDEyIDIyIDIyIDE3LjUyIDIyIDEyUzE3LjUyIDIgMTIgMloiLz4KPC9zdmc+Cjwvc3ZnPgo=" alt="Solana" style="width: 40px; height: 40px; margin-bottom: 15px;">
            <h3 style="margin: 0 0 10px 0; color: #2c3e50; font-size: 22px; font-weight: 600;">Pay with Solana</h3>
            <p style="margin: 0; color: #6c757d; font-size: 14px;">Fast, secure cryptocurrency payment</p>
        </div>
        
        <div class="payment-amount" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin: 20px 0;">
            <div style="font-size: 24px; font-weight: bold;">' . htmlspecialchars($invoiceAmount) . ' ' . htmlspecialchars($params['currency']) . '</div>
            <div style="font-size: 14px; opacity: 0.9; margin-top: 5px;">Invoice #' . htmlspecialchars($params['invoiceid']) . '</div>
        </div>
        
        <div class="payment-info" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; font-size: 14px; color: #6c757d;">
            <p style="margin: 0 0 8px 0;"><strong>Network:</strong> ' . ucfirst($params['network']) . '</p>
            <p style="margin: 0;"><strong>Payment expires in:</strong> ' . ($params['expiry_hours'] ?: 24) . ' hours</p>
        </div>
        
        <div class="payment-actions" style="margin: 25px 0;">
            <a href="' . htmlspecialchars($paymentUrl) . '" 
               class="voltxt-pay-btn" 
               style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px; transition: transform 0.2s ease; border: none; cursor: pointer;"
               onmouseover="this.style.transform=\'translateY(-2px)\'"
               onmouseout="this.style.transform=\'translateY(0px)\'">
                Pay with Solana Wallet
            </a>
        </div>
        
        <div class="payment-security" style="font-size: 12px; color: #6c757d; margin-top: 20px;">
            <p style="margin: 0;">🔒 Secure payment powered by Voltxt</p>
            <p style="margin: 5px 0 0 0;">You will be redirected to complete your payment</p>
        </div>
    </div>
    
    <script>
    // Auto-refresh to check payment status
    var voltxtSessionId = "' . htmlspecialchars($sessionId) . '";
    var voltxtCheckInterval = setInterval(function() {
        fetch("modules/gateways/callback/voltxt.php?session_id=" + encodeURIComponent(voltxtSessionId))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.session && data.session.status === "completed") {
                    clearInterval(voltxtCheckInterval);
                    window.location.reload();
                }
            })
            .catch(error => console.log("Status check error:", error));
    }, 10000); // Check every 10 seconds
    
    // Stop checking after 2 hours
    setTimeout(function() {
        clearInterval(voltxtCheckInterval);
    }, 7200000);
    </script>';

    return $html;
}

/**
 * Call Voltxt API
 */
function callVoltxtAPI($endpoint, $data, $method = 'POST')
{
    $url = 'https://api.voltxt.io' . $endpoint;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WHMCS-Voltxt/1.0',
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'GET' && !empty($data)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Network error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid API response'];
    }

    return $decoded;
}

/**
 * Get existing active session for invoice
 */
function getExistingActiveSession($invoiceId)
{
    try {
        global $db_host, $db_name, $db_username, $db_password;
        
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("
            SELECT * FROM mod_voltxt_sessions 
            WHERE invoice_id = ? 
            AND status IN ('pending', 'payment_received') 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Voltxt: Error checking existing session - " . $e->getMessage());
        return null;
    }
}

/**
 * Simple helper class for formatting
 */
class VoltxtHelper
{
    public static function formatAmount($amount, $currency = 'USD')
    {
        $decimals = ($currency === 'SOL') ? 9 : 2;
        return number_format($amount, $decimals);
    }
}

/**
 * Update callback URL for existing session
 */
function updateSessionCallbackUrl($sessionId, $callbackUrl)
{
    try {
        global $db_host, $db_name, $db_username, $db_password;
        
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // For now, just log it - the callback URL is stored in the backend session
        error_log("Voltxt: Updated callback URL for session {$sessionId}: {$callbackUrl}");
        
    } catch (Exception $e) {
        error_log("Voltxt: Error updating callback URL - " . $e->getMessage());
    }
}

/**
 * Store session data for webhook processing
 */
function storeSessionData($invoiceId, $sessionId, $params)
{
    try {
        // Use WHMCS database configuration
        global $db_host, $db_name, $db_username, $db_password;
        
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mod_voltxt_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                session_id VARCHAR(255) NOT NULL,
                api_key VARCHAR(32) NOT NULL,
                network VARCHAR(20) NOT NULL,
                amount DECIMAL(15,8) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_invoice (invoice_id),
                KEY idx_session_id (session_id)
            )
        ");

        $stmt = $pdo->prepare("
            INSERT INTO mod_voltxt_sessions 
            (invoice_id, session_id, api_key, network, amount, currency) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            session_id = VALUES(session_id),
            updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $invoiceId,
            $sessionId,
            $params['api_key'],
            $params['network'],
            $params['amount'],
            $params['currency']
        ]);

    } catch (Exception $e) {
        error_log("Voltxt: Failed to store session data - " . $e->getMessage());
    }
}

/**
 * Refund processing (manual)
 */
function voltxt_refund($params)
{
    logActivity("Voltxt Gateway: Refund requested for transaction " . $params['transid'], $params['invoiceid']);
    
    return [
        'status' => 'error',
        'rawdata' => 'Solana payments require manual refund processing. Please contact support.',
    ];
}

/**
 * Capture (automatic for Solana)
 */
function voltxt_capture($params)
{
    return [
        'status' => 'success',
        'transid' => $params['transid'],
        'rawdata' => 'Solana payments are automatically captured',
    ];
}