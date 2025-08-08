<?php

namespace WHMCS\Module\Gateway\Voltxt\Lib;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * VOLTXT API Client for WHMCS
 *
 * Handles all API communication with the VOLTXT service.
 * Compatible with PHP 7.4-8.4
 * Updated to support Dynamic Payment Controller
 */
class ApiClient
{
    protected $apiKey;
    protected $apiUrl;
    protected $network;
    protected $timeout;
    protected $connectTimeout;

    /**
     * Constructor
     *
     * @param string $apiKey The VOLTXT API key
     * @param string $apiUrl The VOLTXT API URL
     * @param string $network The network to use (testnet|mainnet)
     * @param int $timeout Request timeout in seconds
     * @param int $connectTimeout Connection timeout in seconds
     */
    public function __construct($apiKey, $apiUrl, $network, $timeout = 30, $connectTimeout = 10)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->network = $network;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Creates a new dynamic payment session on the VOLTXT service.
     *
     * @param array $params Payment creation parameters.
     * @return array The API response.
     */
    public function createDynamicPayment(array $params)
    {
        // Validate and sanitize expiry hours
        $expiryHours = isset($params['expiryHours']) ? (int)$params['expiryHours'] : 24;
        if ($expiryHours < 1 || $expiryHours > 168) {
            $expiryHours = 24; // Default to 24 hours
        }

        // Build callback URL
        $callbackUrl = $this->buildCallbackUrl($params['systemurl']);
        
        // Prepare customer name
        $customerName = trim(
            ($params['clientdetails']['firstname'] ?? '') . ' ' . 
            ($params['clientdetails']['lastname'] ?? '')
        );
        if (empty($customerName)) {
            $customerName = 'WHMCS Customer';
        }

        $payload = [
            'api_key'             => $this->apiKey,
            'network'             => $this->network,
            'platform'            => 'whmcs',
            'external_payment_id' => 'whmcs_invoice_' . $params['invoiceid'],
            'amount_type'         => 'fiat',
            'amount'              => (float)$params['amount'],
            'fiat_currency'       => $params['currency'],
            'expiry_hours'        => $expiryHours,
            'description'         => $this->buildDescription($params),
            'customer_email'      => $params['clientdetails']['email'] ?? '',
            'customer_name'       => $customerName,
            'callback_url'        => $callbackUrl,
            'success_url'         => $this->buildSuccessUrl($params),
            'cancel_url'          => $this->buildCancelUrl($params),
            'metadata'            => [
                'invoice_id'     => $params['invoiceid'],
                'customer_id'    => $params['clientdetails']['userid'] ?? '',
                'site_url'       => $params['systemurl'] ?? '',
                'network'        => $this->network,
                'whmcs_version'  => $GLOBALS['CONFIG']['Version'] ?? 'Unknown',
                'gateway_version' => '2.0.0',
                'created_at'     => date('c'),
                'platform'       => 'whmcs',
                'store_name'     => urlencode($GLOBALS['CONFIG']['CompanyName'] ?? 'WHMCS'),
                'return_url'     => urlencode($this->buildSuccessUrl($params)),
                'payment_type'   => 'dynamic',
            ]
        ];

        $response = $this->makeRequest('/api/dynamic-payment/initiate', 'POST', $payload);
        
        // Transform URLs in response
        return $this->transformResponseUrls($response, 'dynamic');
    }

    /**
     * Get dynamic payment session status
     *
     * @param string $sessionId The dynamic payment session ID
     * @return array The API response
     */
    public function getDynamicPaymentStatus($sessionId)
    {
        $url = '/api/dynamic-payment/' . urlencode($sessionId) . '/status';
        $queryParams = [
            'api_key' => $this->apiKey,
        ];
        
        $url .= '?' . http_build_query($queryParams);
        
        return $this->makeRequest($url, 'GET');
    }

    /**
     * Get dynamic payment session details
     *
     * @param string $sessionId The dynamic payment session ID
     * @return array The API response
     */
    public function getDynamicPaymentSession($sessionId)
    {
        $url = '/api/dynamic-payment/' . urlencode($sessionId);
        
        return $this->makeRequest($url, 'GET');
    }

    /**
     * Update dynamic payment amount (for real-time price changes)
     *
     * @param string $sessionId The dynamic payment session ID
     * @param float $newAmount The new amount
     * @param string $amountType The amount type (fiat|sol)
     * @param string $fiatCurrency The fiat currency (if amount_type is fiat)
     * @return array The API response
     */
    public function updateDynamicPaymentAmount($sessionId, $newAmount, $amountType = 'fiat', $fiatCurrency = null)
    {
        $payload = [
            'api_key' => $this->apiKey,
            'amount' => (float)$newAmount,
            'amount_type' => $amountType,
        ];

        if ($amountType === 'fiat' && $fiatCurrency) {
            $payload['fiat_currency'] = $fiatCurrency;
        }

        $url = '/api/dynamic-payment/' . urlencode($sessionId) . '/update-amount';
        
        return $this->makeRequest($url, 'POST', $payload);
    }

    /**
     * Creates a new invoice on the VOLTXT service (traditional method).
     *
     * @param array $params Invoice creation parameters.
     * @return array The API response.
     */
    public function createInvoice(array $params)
    {
        // Validate and sanitize expiry hours
        $expiryHours = isset($params['expiryHours']) ? (int)$params['expiryHours'] : 24;
        if ($expiryHours < 1 || $expiryHours > 168) {
            $expiryHours = 24; // Default to 24 hours
        }

        // Build callback URL
        $callbackUrl = $this->buildCallbackUrl($params['systemurl']);
        
        // Prepare customer name
        $customerName = trim(
            ($params['clientdetails']['firstname'] ?? '') . ' ' . 
            ($params['clientdetails']['lastname'] ?? '')
        );
        if (empty($customerName)) {
            $customerName = 'WHMCS Customer';
        }

        $payload = [
            'api_key'             => $this->apiKey,
            'network'             => $this->network,
            'platform'            => 'whmcs',
            'external_invoice_id' => 'whmcs_invoice_' . $params['invoiceid'],
            'amount_type'         => 'fiat',
            'amount'              => (float)$params['amount'],
            'fiat_currency'       => $params['currency'],
            'expiry_hours'        => $expiryHours,
            'description'         => $this->buildDescription($params),
            'customer_email'      => $params['clientdetails']['email'] ?? '',
            'customer_name'       => $customerName,
            'callback_url'        => $callbackUrl,
            'success_url'         => $this->buildSuccessUrl($params),
            'cancel_url'          => $this->buildCancelUrl($params),
            'metadata'            => [
                'invoice_id'     => $params['invoiceid'],
                'customer_id'    => $params['clientdetails']['userid'] ?? '',
                'site_url'       => $params['systemurl'] ?? '',
                'network'        => $this->network,
                'whmcs_version'  => $GLOBALS['CONFIG']['Version'] ?? 'Unknown',
                'gateway_version' => '2.0.0',
                'created_at'     => date('c'),
                'platform'       => 'whmcs',
                'store_name'     => urlencode($GLOBALS['CONFIG']['CompanyName'] ?? 'WHMCS'),
                'return_url'     => urlencode($this->buildSuccessUrl($params)),
                'payment_type'   => 'traditional',
            ]
        ];

        $response = $this->makeRequest('/api/plugin/invoice/create', 'POST', $payload);
        
        // Transform URLs in response
        return $this->transformResponseUrls($response, 'traditional');
    }

    /**
     * Tests the API connection.
     *
     * @param string $storeName The name of the WHMCS installation.
     * @return array The API response.
     */
    public function testConnection($storeName)
    {
        $payload = [
            'api_key'    => $this->apiKey,
            'store_name' => $storeName,
            'network'    => $this->network,
            'platform'   => 'whmcs',
            'version'    => $GLOBALS['CONFIG']['Version'] ?? 'Unknown',
        ];

        return $this->makeRequest('/api/plugin/test-connection', 'POST', $payload);
    }

    /**
     * Creates a new invoice with a custom external ID.
     *
     * @param array $params Invoice creation parameters.
     * @param string $customExternalId Custom external invoice ID.
     * @return array The API response.
     */
    public function createInvoiceWithCustomId(array $params, $customExternalId)
    {
        // Validate and sanitize expiry hours
        $expiryHours = isset($params['expiryHours']) ? (int)$params['expiryHours'] : 24;
        if ($expiryHours < 1 || $expiryHours > 168) {
            $expiryHours = 24; // Default to 24 hours
        }

        // Build callback URL
        $callbackUrl = $this->buildCallbackUrl($params['systemurl']);
        
        // Prepare customer name
        $customerName = trim(
            ($params['clientdetails']['firstname'] ?? '') . ' ' . 
            ($params['clientdetails']['lastname'] ?? '')
        );
        if (empty($customerName)) {
            $customerName = 'WHMCS Customer';
        }

        $payload = [
            'api_key'             => $this->apiKey,
            'network'             => $this->network,
            'platform'            => 'whmcs',
            'external_invoice_id' => $customExternalId,
            'amount_type'         => 'fiat',
            'amount'              => (float)$params['amount'],
            'fiat_currency'       => $params['currency'],
            'expiry_hours'        => $expiryHours,
            'description'         => $this->buildDescription($params),
            'customer_email'      => $params['clientdetails']['email'] ?? '',
            'customer_name'       => $customerName,
            'callback_url'        => $callbackUrl,
            'success_url'         => $params['returnurl'] ?? '',
            'cancel_url'          => $params['returnurl'] ?? '',
            'metadata'            => [
                'invoice_id'     => $params['invoiceid'],
                'customer_id'    => $params['clientdetails']['userid'] ?? '',
                'site_url'       => $params['systemurl'] ?? '',
                'network'        => $this->network,
                'whmcs_version'  => $GLOBALS['CONFIG']['Version'] ?? 'Unknown',
                'gateway_version' => '2.0.0',
                'created_at'     => date('c'),
            ]
        ];

        $response = $this->makeRequest('/api/plugin/invoice/create', 'POST', $payload);
        
        // Transform URLs in response
        return $this->transformResponseUrls($response, 'traditional');
    }

    /**
     * Get invoice by external ID.
     *
     * @param string $externalId The external invoice ID.
     * @return array The API response.
     */
    public function getInvoiceByExternalId($externalId)
    {
        $url = '/api/plugin/invoice/by-external-id/' . urlencode($externalId);
        $queryParams = [
            'network' => $this->network,
            'api_key' => $this->apiKey,
        ];
        
        $url .= '?' . http_build_query($queryParams);
        
        return $this->makeRequest($url, 'GET');
    }

    /**
     * Get invoice details from VOLTXT.
     *
     * @param string $invoiceNumber The VOLTXT invoice number.
     * @return array The API response.
     */
    public function getInvoice($invoiceNumber)
    {
        $url = '/api/plugin/invoice/' . urlencode($invoiceNumber) . '/status';
        $queryParams = [
            'api_key' => $this->apiKey,
        ];
        
        $url .= '?' . http_build_query($queryParams);
        
        return $this->makeRequest($url, 'GET');
    }

    /**
     * Build a description for the invoice.
     *
     * @param array $params The payment parameters.
     * @return string The description.
     */
    private function buildDescription(array $params)
    {
        $description = 'WHMCS Invoice #' . $params['invoiceid'];
        
        if (!empty($params['description'])) {
            $description = $params['description'];
        } elseif (!empty($params['companyname'])) {
            $description .= ' - ' . $params['companyname'];
        }
        
        return $description;
    }

    /**
     * Build the success URL for completed payments.
     *
     * @param array $params The payment parameters.
     * @return string The success URL.
     */
    private function buildSuccessUrl(array $params)
    {
        $systemUrl = rtrim($params['systemurl'], '/');
        $invoiceId = $params['invoiceid'];
        
        // Return to WHMCS invoice view page
        return $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    }

    /**
     * Build the cancel URL for cancelled payments.
     *
     * @param array $params The payment parameters.
     * @return string The cancel URL.
     */
    private function buildCancelUrl(array $params)
    {
        $systemUrl = rtrim($params['systemurl'], '/');
        $invoiceId = $params['invoiceid'];
        
        // Return to WHMCS invoice view page
        return $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    }

    /**
     * Build the callback URL for payment notifications.
     *
     * @param string $systemUrl The WHMCS system URL.
     * @return string The callback URL.
     */
    private function buildCallbackUrl($systemUrl)
    {
        $systemUrl = rtrim($systemUrl, '/');
        return $systemUrl . '/modules/gateways/callback/voltxt.php';
    }

    /**
     * Makes a request to the VOLTXT API.
     *
     * @param string $endpoint The API endpoint to call.
     * @param string $method   The HTTP method (e.g., 'POST', 'GET').
     * @param array  $data     The data to send with the request.
     * @return array The decoded JSON response.
     */
    private function makeRequest($endpoint, $method = 'POST', array $data = [])
    {
        $url = $this->apiUrl . $endpoint;
        
        // Initialize cURL
        $ch = curl_init();
        
        if ($ch === false) {
            return [
                'success' => false,
                'message' => 'Failed to initialize cURL',
                'error_code' => 'CURL_INIT_ERROR'
            ];
        }

        // Build headers
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WHMCS-VOLTXT-Gateway/2.0 (PHP/' . PHP_VERSION . ')',
        ];

        // Basic cURL options
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        // Set method-specific options
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            if (!empty($data)) {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } elseif ($method === 'GET') {
            $curlOptions[CURLOPT_HTTPGET] = true;
        }

        // Apply options
        curl_setopt_array($ch, $curlOptions);

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        curl_close($ch);

        // Handle cURL errors
        if ($response === false || $curlErrno !== 0) {
            return [
                'success' => false,
                'message' => 'Connection Error: ' . ($curlError ?: 'Unknown cURL error'),
                'error_code' => 'CONNECTION_ERROR',
                'curl_errno' => $curlErrno,
            ];
        }

        // Decode JSON response
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response from API: ' . json_last_error_msg(),
                'error_code' => 'JSON_DECODE_ERROR',
                'raw_response' => substr($response, 0, 500), // Limit raw response length
                'http_code' => $httpCode,
            ];
        }

        // Handle HTTP errors
        if ($httpCode >= 400) {
            $errorMessage = 'HTTP Error ' . $httpCode;
            
            if (isset($decodedResponse['message'])) {
                $errorMessage = $decodedResponse['message'];
            } elseif (isset($decodedResponse['error'])) {
                $errorMessage = $decodedResponse['error'];
            }
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error_code' => isset($decodedResponse['error_code']) ? 
                    $decodedResponse['error_code'] : 'HTTP_' . $httpCode,
                'http_code' => $httpCode,
                'data' => $decodedResponse,
            ];
        }

        // Handle API-level failures
        if (isset($decodedResponse['success']) && !$decodedResponse['success']) {
            return [
                'success' => false,
                'message' => isset($decodedResponse['message']) ? 
                    $decodedResponse['message'] : 'API request failed',
                'error_code' => isset($decodedResponse['error_code']) ? 
                    $decodedResponse['error_code'] : 'API_ERROR',
                'data' => $decodedResponse,
            ];
        }

        // Log successful API requests in debug mode
        if (defined('VOLTXT_DEBUG') && VOLTXT_DEBUG) {
            $this->logApiRequest($endpoint, $method, $data, $decodedResponse);
        }

        // Return successful response
        return $decodedResponse;
    }

    /**
     * Validate webhook signature (if VOLTXT provides signatures).
     *
     * @param string $payload The raw webhook payload.
     * @param string $signature The signature from headers.
     * @param string $secret The webhook secret.
     * @return bool True if signature is valid.
     */
    public function validateWebhookSignature($payload, $signature, $secret)
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        // Remove any prefix from signature (e.g., "sha256=")
        $signature = preg_replace('/^sha256=/', '', $signature);
        
        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        // Use hash_equals for timing-safe comparison
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get the current network.
     *
     * @return string The network (testnet|mainnet).
     */
    public function getNetwork()
    {
        return $this->network;
    }

    /**
     * Set a new network.
     *
     * @param string $network The network (testnet|mainnet).
     */
    public function setNetwork($network)
    {
        if (in_array($network, ['testnet', 'mainnet'])) {
            $this->network = $network;
        }
    }

    /**
     * Get API key (masked for logging).
     *
     * @return string Masked API key.
     */
    public function getMaskedApiKey()
    {
        if (strlen($this->apiKey) <= 8) {
            return str_repeat('*', strlen($this->apiKey));
        }
        
        return substr($this->apiKey, 0, 4) . str_repeat('*', strlen($this->apiKey) - 8) . substr($this->apiKey, -4);
    }

    /**
     * Log API request for debugging.
     *
     * @param string $endpoint The endpoint called.
     * @param string $method The HTTP method.
     * @param array $data The request data (sensitive data will be masked).
     * @param array $response The response data.
     */
    private function logApiRequest($endpoint, $method, $data, $response)
    {
        // Don't log in production unless debug mode is enabled
        if (!defined('VOLTXT_DEBUG') || !VOLTXT_DEBUG) {
            return;
        }

        // Mask sensitive data
        $logData = $data;
        if (isset($logData['api_key'])) {
            $logData['api_key'] = $this->getMaskedApiKey();
        }

        $logEntry = [
            'timestamp' => date('c'),
            'endpoint' => $endpoint,
            'method' => $method,
            'network' => $this->network,
            'request_data' => $logData,
            'response_success' => isset($response['success']) ? $response['success'] : null,
            'response_message' => isset($response['message']) ? $response['message'] : null,
        ];

        error_log('VOLTXT API Request: ' . json_encode($logEntry));
    }

    /**
     * Get Solana explorer URL for a transaction.
     *
     * @param string $txId The transaction ID.
     * @return string The explorer URL.
     */
    public function getExplorerUrl($txId)
    {
        $baseUrl = 'https://explorer.solana.com/tx/' . urlencode($txId);
        
        if ($this->network === 'testnet') {
            $baseUrl .= '?cluster=testnet';
        }
        
        return $baseUrl;
    }

    /**
     * Get Solana explorer URL for an address.
     *
     * @param string $address The wallet address.
     * @return string The explorer URL.
     */
    public function getAddressExplorerUrl($address)
    {
        $baseUrl = 'https://explorer.solana.com/address/' . urlencode($address);
        
        if ($this->network === 'testnet') {
            $baseUrl .= '?cluster=testnet';
        }
        
        return $baseUrl;
    }

    /**
     * Check if the API client is properly configured.
     *
     * @return bool True if configured.
     */
    public function isConfigured()
    {
        return !empty($this->apiKey) && !empty($this->apiUrl) && !empty($this->network);
    }

    /**
     * Get configuration summary for debugging.
     *
     * @return array Configuration details.
     */
    public function getConfigSummary()
    {
        return [
            'api_url' => $this->apiUrl,
            'api_key' => $this->getMaskedApiKey(),
            'network' => $this->network,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * Transform API response URLs to use correct app domain
     *
     * @param array $response API response
     * @param string $type Response type ('dynamic' or 'traditional')
     * @return array Transformed response
     */
    private function transformResponseUrls($response, $type = 'dynamic')
    {
        if ($type === 'dynamic') {
            // Transform dynamic payment URLs
            if (isset($response['data']['payment_url'])) {
                $response['data']['payment_url'] = str_replace('api.voltxt.io', 'app.voltxt.io', $response['data']['payment_url']);
            }
            if (isset($response['data']['status_check_url'])) {
                // Keep status check URL as API domain since it's an API endpoint
                // No transformation needed
            }
        } else {
            // Transform traditional invoice URLs
            if (isset($response['invoice']['payment_url'])) {
                $response['invoice']['payment_url'] = str_replace('api.voltxt.io', 'app.voltxt.io', $response['invoice']['payment_url']);
                $response['invoice']['payment_url'] = str_replace('/invoice/', '/pay/', $response['invoice']['payment_url']);
            }
            if (isset($response['invoice']['status_check_url'])) {
                // Keep status check URL as API domain since it's an API endpoint
                // No transformation needed
            }
        }
        
        return $response;
    }

    /**
     * Convert error codes to user-friendly messages.
     *
     * @param string $errorCode The error code.
     * @return string User-friendly message.
     */
    public function getErrorMessage($errorCode)
    {
        $errorMessages = [
            'INVALID_API_KEY' => 'Invalid API key. Please check your VOLTXT credentials.',
            'NETWORK_MISMATCH' => 'Network configuration mismatch. Please verify your testnet/mainnet settings.',
            'NO_DESTINATION_WALLET' => 'No destination wallet configured for this network in your VOLTXT account.',
            'VALIDATION_ERROR' => 'Invalid request data. Please contact support.',
            'SESSION_NOT_FOUND' => 'Payment session not found or has expired.',
            'INVOICE_NOT_FOUND' => 'Invoice not found or has expired.',
            'AMOUNT_TOO_LOW' => 'Payment amount is below the minimum threshold.',
            'AMOUNT_TOO_HIGH' => 'Payment amount exceeds the maximum threshold.',
            'EXPIRED_INVOICE' => 'This payment session has expired.',
            'CONNECTION_ERROR' => 'Unable to connect to VOLTXT service. Please try again.',
            'TIMEOUT_ERROR' => 'Request timed out. Please try again.',
            'JSON_DECODE_ERROR' => 'Invalid response from payment service.',
            'HTTP_400' => 'Bad request. Please check your configuration.',
            'HTTP_401' => 'Unauthorized. Please check your API key.',
            'HTTP_403' => 'Access forbidden. Please verify your account permissions.',
            'HTTP_404' => 'Service endpoint not found.',
            'HTTP_429' => 'Too many requests. Please try again later.',
            'HTTP_500' => 'Payment service error. Please try again.',
            'HTTP_502' => 'Payment service temporarily unavailable.',
            'HTTP_503' => 'Payment service maintenance. Please try again later.',
        ];

        return isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : 'An unexpected error occurred.';
    }

    /**
     * Parse webhook data and validate structure for both dynamic and traditional payments.
     *
     * @param array $webhookData The webhook payload.
     * @return array Validation result.
     */
    public function validateWebhookData(array $webhookData)
    {
        // Check if this is a dynamic payment webhook
        if (isset($webhookData['session_id'])) {
            return $this->validateDynamicWebhookData($webhookData);
        }
        
        // Traditional webhook validation
        return $this->validateTraditionalWebhookData($webhookData);
    }

    /**
     * Validate dynamic payment webhook data
     *
     * @param array $webhookData The webhook payload
     * @return array Validation result
     */
    private function validateDynamicWebhookData(array $webhookData)
    {
        $requiredFields = [
            'event_type',
            'session_id',
            'external_payment_id',
            'network'
        ];

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($webhookData[$field]) || $webhookData[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return [
                'valid' => false,
                'error' => 'Missing required fields: ' . implode(', ', $missingFields),
                'missing_fields' => $missingFields,
                'webhook_type' => 'dynamic'
            ];
        }

        // Validate external_payment_id format
        if (strpos($webhookData['external_payment_id'], 'whmcs_invoice_') !== 0) {
            return [
                'valid' => false,
                'error' => 'Invalid external_payment_id format',
                'webhook_type' => 'dynamic'
            ];
        }

        // Validate network
        if (!in_array($webhookData['network'], ['testnet', 'mainnet'])) {
            return [
                'valid' => false,
                'error' => 'Invalid network value',
                'webhook_type' => 'dynamic'
            ];
        }

        return [
            'valid' => true,
            'webhook_type' => 'dynamic',
            'invoice_id' => (int) str_replace('whmcs_invoice_', '', $webhookData['external_payment_id'])
        ];
    }

    /**
     * Validate traditional payment webhook data
     *
     * @param array $webhookData The webhook payload
     * @return array Validation result
     */
    private function validateTraditionalWebhookData(array $webhookData)
    {
        $requiredFields = [
            'event_type',
            'external_invoice_id',
            'invoice_number',
            'status',
            'network'
        ];

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($webhookData[$field]) || $webhookData[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return [
                'valid' => false,
                'error' => 'Missing required fields: ' . implode(', ', $missingFields),
                'missing_fields' => $missingFields,
                'webhook_type' => 'traditional'
            ];
        }

        // Validate external_invoice_id format
        if (strpos($webhookData['external_invoice_id'], 'whmcs_invoice_') !== 0) {
            return [
                'valid' => false,
                'error' => 'Invalid external_invoice_id format',
                'webhook_type' => 'traditional'
            ];
        }

        // Validate network
        if (!in_array($webhookData['network'], ['testnet', 'mainnet'])) {
            return [
                'valid' => false,
                'error' => 'Invalid network value',
                'webhook_type' => 'traditional'
            ];
        }

        // Validate event type
        $validEvents = [
            'payment_received',
            'partial_payment_received',
            'payment_completed',
            'payment_expired',
            'overpayment_detected'
        ];

        if (!in_array($webhookData['event_type'], $validEvents)) {
            return [
                'valid' => false,
                'error' => 'Invalid event_type',
                'webhook_type' => 'traditional'
            ];
        }

        return [
            'valid' => true,
            'webhook_type' => 'traditional',
            'invoice_id' => (int) str_replace('whmcs_invoice_', '', $webhookData['external_invoice_id'])
        ];
    }
}