<?php

//Faucet testnet: https://coinfaucet.eu/en/btc-testnet/

declare(strict_types=1);

// Start the session early to allow storing invoice data
session_start();

// ------------------------------
// Production settings and error handling
// ------------------------------
// Disable error display (errors are logged to the server's error log)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ------------------------------
// Custom exception for BTCPay errors
// ------------------------------
class BTCPayException extends Exception {}

// ------------------------------
// BTCPayClient: Encapsulates BTCPay Greenfield API interactions
// ------------------------------
class BTCPayClient
{
    private string $btcpayUrl;
    private string $apiKey;
    private string $storeId;

    public function __construct(string $btcpayUrl, string $apiKey, string $storeId)
    {
        $this->btcpayUrl = rtrim($btcpayUrl, '/');
        $this->apiKey    = $apiKey;
        $this->storeId   = $storeId;
    }

    /**
     * Creates an invoice at the BTCPay server.
     *
     * @param array $invoiceData The invoice data to be sent.
     * @return array The decoded invoice response.
     * @throws BTCPayException If a communication or API error occurs.
     */
    public function createInvoice(array $invoiceData): array
    {
        $url = "{$this->btcpayUrl}/api/v1/stores/{$this->storeId}/invoices";
        $payload = json_encode($invoiceData);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: token {$this->apiKey}"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30-second timeout

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new BTCPayException("cURL error: " . $errorMsg);
        }
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorData = json_decode($result, true);
            $errorMessage = $errorData['message'] ?? $result;
            throw new BTCPayException("Error creating invoice (HTTP $httpCode): " . $errorMessage);
        }

        $invoice = json_decode($result, true);
        if (!isset($invoice['checkoutLink'])) {
            throw new BTCPayException("Invoice created but no checkout link received.");
        }

        return $invoice;
    }
}

// ------------------------------
// Configuration settings (store these securely in production)
// ------------------------------

$config = [
    'btcpay_url' => "https://testnet.demo.btcpayserver.org",  // BTCPay instance URL
    'api_key'    => "API_KEY_URL",  // Your Greenfield API key
    'store_id'   => "STORE_ID", // Your Store ID
    'invoice'    => [
        'amount'   => 1.99,         // Product price in USD
        'currency' => 'USD',
        'metadata' => [
            'itemDesc' => 'Blockchain Course' // Product description
        ],
        'checkout' => [
            // Adjust this URL for your environment (e.g. production domain)
            'redirectURL' => "/thankyou-page.php"
        ]
    ]
];

// ------------------------------
// Controller Logic: Process invoice request and handle errors
// ------------------------------
$errorMessage = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
        // Check for a previously created invoice in the session
        if (isset($_SESSION['invoice']) && !empty($_SESSION['invoice']['checkoutLink'])) {
            $invoiceData = $_SESSION['invoice'];
        } else {
            // Create BTCPayClient instance with configuration
            $btcpayClient = new BTCPayClient(
                $config['btcpay_url'],
                $config['api_key'],
                $config['store_id']
            );

            // Create a new invoice and store it in the session to prevent duplicate creation
            $invoiceData = $btcpayClient->createInvoice($config['invoice']);
            $_SESSION['invoice'] = $invoiceData;
        }
        // Redirect the user to the BTCPay checkout page
        header("Location: " . $invoiceData['checkoutLink']);
        exit;
    }
} catch (BTCPayException $ex) {
    // Log the detailed error for debugging without exposing sensitive details to the user
    error_log("BTCPay error: " . $ex->getMessage());
    $errorMessage = "There was an error processing your payment. Please try again later.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Blockchain Course Payment</title>
  <!-- Tailwind CSS via CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
<div class="max-w-md mx-auto mt-20 p-6 bg-white shadow rounded text-center">
  <?php if ($errorMessage): ?>
    <!-- Display error message -->
    <h2 class="text-xl font-semibold text-red-500 mb-4">Error</h2>
    <p class="text-gray-800"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="mt-4 inline-block text-blue-600 hover:underline">Go back</a>
  <?php else: ?>
    <!-- Landing page content -->
    <h1 class="text-2xl font-bold mb-4">Blockchain Course - $1.99</h1>
    <p class="text-gray-700 mb-6">Learn the fundamentals of blockchain technology with this introductory course.</p>
    <form method="post" novalidate>
      <button type="submit" name="pay" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded">
        Pay with Bitcoin via BTCPay
      </button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
