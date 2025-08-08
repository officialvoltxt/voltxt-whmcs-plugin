<?php
/**
 * VOLTXT Admin Hooks for WHMCS
 * 
 * This file contains hooks for enhancing the admin interface
 * with VOLTXT payment information for both Dynamic and Traditional payments.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Voltxt\Lib\ApiClient;

/**
 * Hook to add VOLTXT payment information to admin invoice view
 */
add_hook('AdminInvoicesControlsOutput', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];
    
    // Get VOLTXT data from invoice metadata
    $voltxtData = getVoltxtPaymentData($invoiceId);
    if (!$voltxtData) {
        return '';
    }
    
    // Get gateway configuration for explorer URLs
    $gatewayParams = getGatewayVariables('voltxt');
    if (!$gatewayParams['type']) {
        return '';
    }
    
    $paymentType = $voltxtData['type'];
    $network = $voltxtData['data']['network'];
    $networkDisplay = strtoupper($network);
    $networkClass = ($network === 'mainnet') ? 'label-danger' : 'label-success';
    
    // Build explorer URLs
    $explorerBaseUrl = 'https://explorer.solana.com';
    $clusterParam = ($network === 'testnet') ? '?cluster=testnet' : '';
    
    $paymentTxUrl = '';
    $autoProcessTxUrl = '';
    
    if (!empty($voltxtData['data']['payment_tx_id'])) {
        $paymentTxUrl = $explorerBaseUrl . '/tx/' . urlencode($voltxtData['data']['payment_tx_id']) . $clusterParam;
    }
    
    if (!empty($voltxtData['data']['auto_process_tx_id'])) {
        $autoProcessTxUrl = $explorerBaseUrl . '/tx/' . urlencode($voltxtData['data']['auto_process_tx_id']) . $clusterParam;
    }
    
    // Status badge
    $statusClass = 'label-default';
    $statusText = ucfirst($voltxtData['data']['status']);
    
    switch ($voltxtData['data']['status']) {
        case 'paid':
        case 'auto_processed':
        case 'completed':
            $statusClass = 'label-success';
            break;
        case 'pending':
            $statusClass = 'label-warning';
            break;
        case 'expired':
            $statusClass = 'label-danger';
            break;
        case 'overpaid':
            $statusClass = 'label-info';
            $statusText = 'Overpaid';
            break;
        case 'partial':
            $statusClass = 'label-warning';
            $statusText = 'Partial Payment';
            break;
    }
    
    // Format expiry date
    $expiryDisplay = 'N/A';
    if (!empty($voltxtData['data']['expires_at'])) {
        $expiryDate = new DateTime($voltxtData['data']['expires_at']);
        $now = new DateTime();
        $isExpired = $expiryDate < $now;
        
        $expiryDisplay = $expiryDate->format('Y-m-d H:i:s T');
        if ($isExpired) {
            $expiryDisplay .= ' <span class="label label-danger">EXPIRED</span>';
        }
    }
    
    // Payment type badge
    $typeClass = ($paymentType === 'dynamic') ? 'label-info' : 'label-primary';
    $typeText = ($paymentType === 'dynamic') ? 'DYNAMIC' : 'TRADITIONAL';
    
    $html = '
    <div class="panel panel-default voltxt-panel" style="margin-top: 20px;">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="fas fa-coins"></i> VOLTXT Crypto Payment Information
                <span class="label ' . $networkClass . ' pull-right">' . $networkDisplay . '</span>
                <span class="label ' . $typeClass . ' pull-right" style="margin-right: 5px;">' . $typeText . '</span>
            </h3>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-condensed">';
    
    if ($paymentType === 'dynamic') {
        $html .= '
                        <tr>
                            <th width="40%">Session ID:</th>
                            <td><code>' . htmlspecialchars($voltxtData['data']['session_id']) . '</code></td>
                        </tr>';
    } else {
        $html .= '
                        <tr>
                            <th width="40%">VOLTXT Invoice:</th>
                            <td><code>' . htmlspecialchars($voltxtData['data']['voltxt_invoice_number']) . '</code></td>
                        </tr>';
    }
    
    $html .= '
                        <tr>
                            <th>Status:</th>
                            <td><span class="label ' . $statusClass . '">' . $statusText . '</span></td>
                        </tr>
                        <tr>
                            <th>Payment Type:</th>
                            <td><span class="label ' . $typeClass . '">' . $typeText . '</span></td>
                        </tr>
                        <tr>
                            <th>Network:</th>
                            <td><span class="label ' . $networkClass . '">' . $networkDisplay . '</span></td>
                        </tr>
                        <tr>
                            <th>Created:</th>
                            <td>' . date('Y-m-d H:i:s T', strtotime($voltxtData['data']['created_at'])) . '</td>
                        </tr>
                        <tr>
                            <th>Expires:</th>
                            <td>' . $expiryDisplay . '</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-condensed">
                        <tr>
                            <th width="40%">Fiat Amount:</th>
                            <td>' . number_format($voltxtData['data']['amount_fiat'], 2) . ' ' . $voltxtData['data']['currency'] . '</td>
                        </tr>
                        <tr>
                            <th>Crypto Amount:</th>
                            <td>' . ($voltxtData['data']['amount_sol'] ? number_format($voltxtData['data']['amount_sol'], 8) . ' SOL' : 'N/A') . '</td>
                        </tr>';
    
    if (!empty($voltxtData['data']['payment_tx_id'])) {
        $html .= '
                        <tr>
                            <th>Payment TX:</th>
                            <td>
                                <a href="' . $paymentTxUrl . '" target="_blank" class="btn btn-xs btn-info">
                                    <i class="fas fa-external-link-alt"></i> View on Explorer
                                </a>
                            </td>
                        </tr>';
    }
    
    if (!empty($voltxtData['data']['auto_process_tx_id'])) {
        $html .= '
                        <tr>
                            <th>Process TX:</th>
                            <td>
                                <a href="' . $autoProcessTxUrl . '" target="_blank" class="btn btn-xs btn-info">
                                    <i class="fas fa-external-link-alt"></i> View on Explorer
                                </a>
                            </td>
                        </tr>';
    }
    
    // Add deposit address for dynamic payments
    if ($paymentType === 'dynamic' && !empty($voltxtData['data']['deposit_address'])) {
        $depositAddressUrl = $explorerBaseUrl . '/address/' . urlencode($voltxtData['data']['deposit_address']) . $clusterParam;
        $html .= '
                        <tr>
                            <th>Deposit Address:</th>
                            <td>
                                <a href="' . $depositAddressUrl . '" target="_blank" class="btn btn-xs btn-success">
                                    <i class="fas fa-external-link-alt"></i> View Address
                                </a>
                            </td>
                        </tr>';
    }
    
    $html .= '
                    </table>
                </div>
            </div>
            
            <div class="row" style="margin-top: 15px;">
                <div class="col-md-12">
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-default" onclick="refreshVoltxtStatus(' . $invoiceId . ')">
                            <i class="fas fa-sync"></i> Refresh Status
                        </button>';
    
    if (!empty($voltxtData['data']['payment_url'])) {
        $html .= '
                        <a href="' . htmlspecialchars($voltxtData['data']['payment_url']) . '" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt"></i> View Payment Page
                        </a>';
    }
    
    if (!empty($voltxtData['data']['status_check_url'])) {
        $html .= '
                        <a href="' . htmlspecialchars($voltxtData['data']['status_check_url']) . '" target="_blank" class="btn btn-sm btn-info">
                            <i class="fas fa-chart-line"></i> Status API
                        </a>';
    }
    
    $html .= '
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function refreshVoltxtStatus(invoiceId) {
        var btn = $("button:contains(\'Refresh Status\')");
        var originalText = btn.html();
        btn.prop("disabled", true).html("<i class=\"fas fa-spinner fa-spin\"></i> Refreshing...");
        
        $.post("modules/gateways/voltxt/refresh_status.php", {
            invoice_id: invoiceId
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("Error: " + response.message);
            }
        })
        .fail(function() {
            alert("Failed to refresh status. Please try again.");
        })
        .always(function() {
            btn.prop("disabled", false).html(originalText);
        });
    }
    </script>';
    
    return $html;
});

/**
 * Hook to add VOLTXT information to invoice list
 */
add_hook('AdminInvoicesListOutputAddColumns', 1, function($vars) {
    return [
        'VOLTXT Payment' => function($invoice) {
            $voltxtData = getVoltxtPaymentData($invoice['id']);
            if (!$voltxtData) {
                return '';
            }
            
            $paymentType = $voltxtData['type'];
            $network = strtoupper($voltxtData['data']['network']);
            $networkClass = ($voltxtData['data']['network'] === 'mainnet') ? 'label-danger' : 'label-success';
            
            $statusClass = 'label-default';
            switch ($voltxtData['data']['status']) {
                case 'paid':
                case 'auto_processed':
                case 'completed':
                    $statusClass = 'label-success';
                    break;
                case 'pending':
                    $statusClass = 'label-warning';
                    break;
                case 'expired':
                    $statusClass = 'label-danger';
                    break;
                case 'overpaid':
                    $statusClass = 'label-info';
                    break;
                case 'partial':
                    $statusClass = 'label-warning';
                    break;
            }
            
            $typeClass = ($paymentType === 'dynamic') ? 'label-info' : 'label-primary';
            $typeText = ($paymentType === 'dynamic') ? 'DYN' : 'TRAD';
            
            return '
                <div style="text-align: center;">
                    <span class="label ' . $networkClass . '">' . $network . '</span><br>
                    <small style="margin-top: 2px; display: inline-block;">
                        <span class="label ' . $statusClass . '">' . ucfirst($voltxtData['data']['status']) . '</span>
                    </small><br>
                    <small style="margin-top: 2px; display: inline-block;">
                        <span class="label ' . $typeClass . '">' . $typeText . '</span>
                    </small>
                </div>
            ';
        }
    ];
});

/**
 * Hook to add VOLTXT payment information to client area invoice view
 */
add_hook('ClientAreaPageViewInvoice', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];
    
    // Get VOLTXT data from invoice metadata
    $voltxtData = getVoltxtPaymentData($invoiceId);
    if (!$voltxtData) {
        return [];
    }
    
    // Only show if payment is completed
    if (!in_array($voltxtData['data']['status'], ['paid', 'auto_processed', 'completed', 'overpaid'])) {
        return [];
    }
    
    $network = $voltxtData['data']['network'];
    $explorerBaseUrl = 'https://explorer.solana.com';
    $clusterParam = ($network === 'testnet') ? '?cluster=testnet' : '';
    
    $paymentTxUrl = '';
    if (!empty($voltxtData['data']['payment_tx_id'])) {
        $paymentTxUrl = $explorerBaseUrl . '/tx/' . urlencode($voltxtData['data']['payment_tx_id']) . $clusterParam;
    }
    
    return [
        'voltxtPaymentInfo' => [
            'type' => $voltxtData['type'],
            'network' => $network,
            'networkDisplay' => strtoupper($network),
            'status' => $voltxtData['data']['status'],
            'sessionId' => $voltxtData['data']['session_id'] ?? null,
            'invoiceNumber' => $voltxtData['data']['voltxt_invoice_number'] ?? null,
            'paymentTxId' => $voltxtData['data']['payment_tx_id'] ?? null,
            'paymentTxUrl' => $paymentTxUrl,
            'amountCrypto' => $voltxtData['data']['amount_sol'] ?? null,
            'isTestnet' => ($network === 'testnet'),
            'isDynamic' => ($voltxtData['type'] === 'dynamic')
        ]
    ];
});

/**
 * Hook to enhance payment gateway selection with network information
 */
add_hook('ClientAreaFooterOutput', 1, function($vars) {
    // Only on checkout pages
    if ($vars['filename'] !== 'cart' && $vars['filename'] !== 'checkout') {
        return '';
    }
    
    // Check if VOLTXT is available
    $gatewayParams = getGatewayVariables('voltxt');
    if (!$gatewayParams['type']) {
        return '';
    }
    
    $network = ($gatewayParams['testMode'] === 'on') ? 'testnet' : 'mainnet';
    $networkDisplay = strtoupper($network);
    $isTestnet = ($network === 'testnet');
    
    // Dynamic payments are always enabled
    $useDynamicPayments = true;
    
    return '
    <script>
    $(document).ready(function() {
        // Add network badge to VOLTXT payment option
        var voltxtOption = $("input[value=\'voltxt\']").closest(".payment-type");
        if (voltxtOption.length > 0) {
            var badge = $("<span class=\"badge pull-right\" style=\"background-color: ' . ($isTestnet ? '#28a745' : '#dc3545') . '; margin-left: 10px;\">' . $networkDisplay . '</span>");
            voltxtOption.find("label").append(badge);
            
            var dynamicBadge = $("<span class=\"badge pull-right\" style=\"background-color: #17a2b8; margin-left: 5px;\">DYNAMIC</span>");
            voltxtOption.find("label").append(dynamicBadge);
            
            ' . ($isTestnet ? '
            var testWarning = $("<div class=\"alert alert-info\" style=\"margin-top: 10px; font-size: 12px;\"><i class=\"fas fa-info-circle\"></i> <strong>Test Mode:</strong> This will create a test transaction using Solana Testnet (no real value).</div>");
            voltxtOption.after(testWarning);
            ' : '') . '
            
            var dynamicInfo = $("<div class=\"alert alert-success\" style=\"margin-top: 10px; font-size: 12px;\"><i class=\"fas fa-bolt\"></i> <strong>Dynamic Payments:</strong> Real-time pricing and instant processing enabled.</div>");
            voltxtOption.after(dynamicInfo);
        }
    });
    </script>';
});

/**
 * Helper function to get VOLTXT payment data from WHMCS invoice metadata
 * Supports both dynamic and traditional payment types
 *
 * @param int $invoiceId WHMCS invoice ID
 * @return array|null Payment data with type or null
 */
function getVoltxtPaymentData($invoiceId)
{
    try {
        $adminNotes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('adminonly');
        
        if (!$adminNotes) {
            return null;
        }
        
        // Check for dynamic payment session first
        if (preg_match('/VOLTXT_DYNAMIC:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $sessionData = unserialize($serializedData);
            
            if ($sessionData && is_array($sessionData)) {
                return [
                    'type' => 'dynamic',
                    'data' => $sessionData
                ];
            }
        }
        
        // Check for traditional invoice data
        if (preg_match('/VOLTXT_DATA:([A-Za-z0-9+\/=]+)/', $adminNotes, $matches)) {
            $serializedData = base64_decode($matches[1]);
            $invoiceData = unserialize($serializedData);
            
            if ($invoiceData && is_array($invoiceData)) {
                return [
                    'type' => 'traditional',
                    'data' => $invoiceData
                ];
            }
        }
        
        // Fallback: check old location (visible notes) for backward compatibility
        $notes = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('notes');
        
        if ($notes) {
            $notesData = json_decode($notes, true);
            if ($notesData && isset($notesData['voltxt'])) {
                return [
                    'type' => 'traditional',
                    'data' => $notesData['voltxt']
                ];
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}