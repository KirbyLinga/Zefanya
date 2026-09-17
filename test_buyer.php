<?php
// test_buyer.php — Buyer registration end-to-end test
// Run: php test_buyer.php

require __DIR__ . '/vendor/autoload.php';

$baseUrl = 'http://127.0.0.1:8000';

// Step 1: GET the register page (following redirect to /register?open=buyer)
$ch = curl_init($baseUrl . '/register/ buyer');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_COOKIEJAR => 'cookie_jar.txt',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
]);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
$headerSize = $info['header_size'];
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
$httpCode = $info['http_code'];
$effectiveUrl = $info['url'];
curl_close($ch);

echo "=== STEP 1: GET /register/buyer (follow redirects) ===\n";
echo "HTTP Code: $httpCode\n";
echo "Final URL: $effectiveUrl\n";
echo "Content-Type: " . (preg_match('/Content-Type: ([^\r\n]+)/', $headers, $m) ? $m[1] : 'N/A') . "\n";

$dom = new DOMDocument();
@$dom->loadHTML($body);
$inputs = $dom->getElementsByTagName('input');
$csrf = null;
foreach ($inputs as $inp) {
    if (($inp->getAttribute('name') ?? '') === '_token') {
        $csrf = $inp->getAttribute('value');
        break;
    }
}
echo "CSRF token found: " . ($csrf ? "YES (length=" . strlen($csrf) . ")" : 'NO') . "\n";
echo "Page title: " . (($dom->getElementsByTagName('title')->item(0)?->textContent) ?? 'N/A') . "\n\n";

if (!$csrf) {
    echo "FAIL: Cannot get CSRF token. Here is the response body:\n$body\n";
    exit(1);
}

// Step 2: Submit registration
$postData = [
    '_token'        => $csrf,
    'email'         => 'test.buyer@example.com',
    'password'      => 'BuyerTest@2026!',
    'password_confirmation' => 'BuyerTest@2026!',
    'first_name'    => 'Test',
    'last_name'     => 'Buyer',
    'middle_initial'=> 'A',
    'sex'           => 'male',
    'contact_no'    => '09123456789',
    'birthday'      => '1995-06-15',
    'province'      => '13',
    'municipality'  => '1301',
    'barangay'      => '130101001',
    'street'        => 'Test Street',
    'house_number'  => '123',
    'address_detail'=> 'Unit 1',
    'id_upload'     => new CURLFile('/dev/null', 'text/plain', 'test_id.txt'),
];

$ch = curl_init($baseUrl . '/register/buyer');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => 'cookie_jar.txt',
    CURLOPT_COOKIEJAR => 'cookie_jar.txt',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
$code = $info['http_code'];
curl_close($ch);

echo "=== STEP 2: POST /register/buyer ===\n";
echo "HTTP Code: $code\n";

$contentType = null;
if (preg_match('/Content-Type: ([^\r\n]+)/', $resp, $m)) {
    $contentType = $m[1];
}
echo "Content-Type: " . ($contentType ?? 'N/A') . "\n";

if ($code === 200 || $code === 302) {
    echo "Status: REDIRECT or OK\n";
    if (preg_match('/Location: ([^\r\n]+)/', $resp, $m)) {
        echo "Redirect to: " . $m[1] . "\n";
    }
    // Grab new cookies from redirect
    $newCh = curl_init($baseUrl . '/register/buyer/verify');
    curl_setopt_array($newCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => 'cookie_jar.txt',
        CURLOPT_COOKIEJAR => 'cookie_jar.txt',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $verifyPage = curl_exec($newCh);
    curl_close($newCh);
    echo "Verified page HTTP: " . curl_getinfo($newCh)['http_code'] . "\n";
    echo "--- Verify page excerpt ---\n";
    echo substr($verifyPage, 0, 500) . "\n\n";
    $bodyText = strip_tags($verifyPage);
    echo "--- Text excerpt ---\n";
    echo substr($bodyText, 0, 300) . "\n\n";
} elseif ($code >= 400) {
    echo "--- Error response ---\n";
    echo substr($resp, 0, 800) . "\n\n";
    exit(1);
}

echo "\n=== DONE ===\n";
echo "Now checking database...\n";
