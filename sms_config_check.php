
<?php
header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/private/sms_config.php';

$token = getenv('KUSHA_SMS_TOKEN')
    ?: ($config['api_token'] ?? '');

if (!is_string($token) || trim($token) === '') {
    exit("API token configuration is missing.\n");
}

if (!function_exists('curl_init')) {
    exit("PHP cURL is not enabled.\n");
}

$ch = curl_init(
    'https://kushasms.com/sms/v4/available-credit'
);

curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
        'auth-token: ' . trim($token),
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "HTTP status: " . $httpCode . "\n";

if ($response === false) {
    echo "Connection failed: " . $error . "\n";
    exit;
}

$data = json_decode($response, true);

if (is_array($data) && isset($data['available_credit'])) {
    echo "Credit check response received.\n";
    echo "Available credits: "
        . $data['available_credit'] . "\n";
} else {
    echo "Unexpected API response. Check Kusha SMS dashboard.\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
}

echo "\nNo SMS was sent.\n";