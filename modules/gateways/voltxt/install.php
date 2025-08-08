<?php
/**
 * VOLTXT Gateway Installation Script
 * 
 * This script helps verify and install the VOLTXT gateway for WHMCS.
 * Run this script from your WHMCS root directory.
 * 
 * Usage: php install.php [--check-only] [--force]
 */

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Parse command line arguments
$options = getopt('', ['check-only', 'force', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$checkOnly = isset($options['check-only']);
$force = isset($options['force']);

echo "VOLTXT Gateway Installation Script\n";
echo "=================================\n\n";

// Check if we're in the WHMCS directory
if (!file_exists('configuration.php')) {
    echo "❌ Error: This script must be run from your WHMCS root directory.\n";
    exit(1);
}

echo "✓ Running from WHMCS directory\n";

// Check PHP version
$phpVersion = PHP_VERSION;
$minPhpVersion = '7.4.0';
$maxPhpVersion = '8.4.99';

if (version_compare($phpVersion, $minPhpVersion, '<')) {
    echo "❌ Error: PHP {$minPhpVersion} or higher required. Current version: {$phpVersion}\n";
    exit(1);
}

if (version_compare($phpVersion, $maxPhpVersion, '>')) {
    echo "⚠️  Warning: PHP version {$phpVersion} may not be fully tested. Recommended: 7.4-8.4\n";
} else {
    echo "✓ PHP version {$phpVersion} is compatible\n";
}

// Check required PHP extensions
$requiredExtensions = ['curl', 'json', 'pdo', 'openssl'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (!empty($missingExtensions)) {
    echo "❌ Error: Missing required PHP extensions: " . implode(', ', $missingExtensions) . "\n";
    exit(1);
}

echo "✓ All required PHP extensions are available\n";

// Check recommended extensions
$recommendedExtensions = ['mbstring', 'intl'];
$missingRecommended = [];

foreach ($recommendedExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingRecommended[] = $ext;
    }
}

if (!empty($missingRecommended)) {
    echo "⚠️  Warning: Missing recommended PHP extensions: " . implode(', ', $missingRecommended) . "\n";
}

// Check WHMCS version (basic check)
if (file_exists('vendor/whmcs/whmcs-foundation/lib/Application.php')) {
    echo "✓ WHMCS installation detected\n";
} else {
    echo "⚠️  Warning: Could not detect WHMCS version. Ensure you're running WHMCS 8.0+\n";
}

// Define file structure
$fileStructure = [
    'modules/gateways/voltxt.php' => 'Main gateway file',
    'modules/gateways/voltxt/lib/ApiClient.php' => 'API client library',
    'modules/gateways/voltxt/hooks.php' => 'Admin interface hooks',
    'modules/gateways/voltxt/refresh_status.php' => 'Status refresh endpoint',
    'modules/gateways/callback/voltxt.php' => 'Webhook callback handler',
];

// Check if files exist
$missingFiles = [];
$existingFiles = [];

foreach ($fileStructure as $file => $description) {
    if (file_exists($file)) {
        $existingFiles[] = $file;
        echo "✓ Found: {$file}\n";
    } else {
        $missingFiles[] = $file;
        echo "❌ Missing: {$file} ({$description})\n";
    }
}

if (!empty($missingFiles)) {
    echo "\n❌ Error: Missing required files. Please ensure all VOLTXT files are uploaded.\n";
    echo "Missing files:\n";
    foreach ($missingFiles as $file) {
        echo "  - {$file}\n";
    }
    exit(1);
}

echo "\n✓ All required files are present\n";

// Check file permissions
echo "\nChecking file permissions...\n";
$permissionIssues = [];

foreach (array_keys($fileStructure) as $file) {
    if (!is_readable($file)) {
        $permissionIssues[] = "{$file} is not readable";
    }
    
    // Check if callback is writable (for logs)
    if (strpos($file, 'callback') !== false && !is_writable(dirname($file))) {
        $permissionIssues[] = dirname($file) . " directory is not writable";
    }
}

if (!empty($permissionIssues)) {
    echo "⚠️  Warning: Permission issues detected:\n";
    foreach ($permissionIssues as $issue) {
        echo "  - {$issue}\n";
    }
    echo "You may need to run: chmod 644 files && chmod 755 directories\n";
} else {
    echo "✓ File permissions look good\n";
}

// Check database connectivity (if not check-only)
if (!$checkOnly) {
    echo "\nChecking database connectivity...\n";
    
    try {
        require_once 'init.php';
        
        // Test database connection
        if (class_exists('WHMCS\Database\Capsule')) {
            $connection = WHMCS\Database\Capsule::connection();
            $connection->getPdo();
            echo "✓ Database connection successful\n";
            
            // Check if VOLTXT table exists
            if (WHMCS\Database\Capsule::schema()->hasTable('tblvoltxt_invoices')) {
                echo "✓ VOLTXT database table already exists\n";
            } else {
                echo "ℹ️  VOLTXT database table will be created on first activation\n";
            }
        } else {
            echo "⚠️  Warning: Could not test database connection (WHMCS not fully loaded)\n";
        }
    } catch (Exception $e) {
        echo "❌ Error: Database connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Check gateway directory structure
echo "\nChecking gateway directory structure...\n";

$directories = [
    'modules/gateways',
    'modules/gateways/voltxt',
    'modules/gateways/voltxt/lib',
    'modules/gateways/callback',
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        echo "❌ Error: Directory {$dir} does not exist\n";
        exit(1);
    } else {
        echo "✓ Directory {$dir} exists\n";
    }
}

// Check for conflicts
echo "\nChecking for potential conflicts...\n";

$conflicts = [];

// Check for other crypto gateways that might conflict
$cryptoGateways = glob('modules/gateways/*crypto*.php');
$cryptoGateways = array_merge($cryptoGateways, glob('modules/gateways/*bitcoin*.php'));
$cryptoGateways = array_merge($cryptoGateways, glob('modules/gateways/*ethereum*.php'));

foreach ($cryptoGateways as $gateway) {
    if (basename($gateway) !== 'voltxt.php') {
        $conflicts[] = "Other crypto gateway detected: " . basename($gateway);
    }
}

if (!empty($conflicts)) {
    echo "⚠️  Warning: Potential conflicts detected:\n";
    foreach ($conflicts as $conflict) {
        echo "  - {$conflict}\n";
    }
    echo "These may not be actual conflicts, but review your gateway configuration.\n";
} else {
    echo "✓ No obvious conflicts detected\n";
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "INSTALLATION SUMMARY\n";
echo str_repeat("=", 50) . "\n";

$totalChecks = 5; // Adjust based on number of major checks
$passedChecks = 0;

if (version_compare($phpVersion, $minPhpVersion, '>=')) $passedChecks++;
if (empty($missingExtensions)) $passedChecks++;
if (empty($missingFiles)) $passedChecks++;
if (empty($permissionIssues)) $passedChecks++;
if (!$checkOnly) $passedChecks++; // Database check

echo "Checks passed: {$passedChecks}/{$totalChecks}\n";

if ($passedChecks === $totalChecks) {
    echo "✅ Installation appears to be ready!\n\n";
    echo "Next steps:\n";
    echo "1. Log in to WHMCS Admin area\n";
    echo "2. Go to Setup → Payments → Payment Gateways\n";
    echo "3. Activate 'VOLTXT Crypto Payments'\n";
    echo "4. Configure your API credentials\n";
    echo "5. Test with a small transaction\n\n";
    
    if (!empty($missingRecommended)) {
        echo "Optional: Install recommended PHP extensions: " . implode(', ', $missingRecommended) . "\n";
    }
} else {
    echo "❌ Installation has issues that need to be resolved.\n";
    echo "Please fix the errors above before proceeding.\n";
    exit(1);
}

echo "\nFor support, visit: https://docs.voltxt.io\n";

/**
 * Show help information
 */
function showHelp()
{
    echo "VOLTXT Gateway Installation Script\n\n";
    echo "Usage: php install.php [options]\n\n";
    echo "Options:\n";
    echo "  --check-only    Only check requirements, don't test database\n";
    echo "  --force         Continue even if warnings are present\n";
    echo "  --help          Show this help message\n\n";
    echo "Examples:\n";
    echo "  php install.php                 # Full installation check\n";
    echo "  php install.php --check-only    # Quick requirements check\n";
}