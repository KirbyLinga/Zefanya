<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=Zefanya', 'root', 'kirby@12');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Query users table
$stmt = $pdo->query("SELECT id, email, name, role FROM users WHERE role != 'admin' LIMIT 30");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== USERS TABLE ===\n";
foreach ($results as $row) {
    echo $row['email'].' - '.$row['name'].' ('.$row['role'].')'."\n";
}

// Query buyers table
echo "\n=== BUYERS TABLE ===\n";
$stmt = $pdo->query('SELECT id, email, name, province_code, province_name, municipality_code, municipality_name, barangay_code, barangay_name, status FROM buyers');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo $row['email'].' - '.$row['name'].' | Province: '.($row['province_name'] ?? 'null').' | Municipality: '.($row['municipality_name'] ?? 'null').' | Barangay: '.($row['barangay_name'] ?? 'null').' | Status: '.($row['status'] ?? 'null')."\n";
}

// Query sellers table
echo "\n=== SELLERS TABLE ===\n";
$stmt = $pdo->query('SELECT id, email, name, business_name, province_code, province_name, municipality_code, municipality_name, barangay_code, barangay_name, status FROM sellers');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo $row['email'].' - '.$row['business_name'].' | Province: '.($row['province_name'] ?? 'null').' | Municipality: '.($row['municipality_name'] ?? 'null').' | Barangay: '.($row['barangay_name'] ?? 'null').' | Status: '.($row['status'] ?? 'null')."\n";
}

// Query logistics providers table
echo "\n=== LOGISTICS PROVIDERS TABLE ===\n";
$stmt = $pdo->query('SELECT id, email, name, first_name, last_name, province_code, province_name, municipality_code, municipality_name, status, email_verified_at FROM logistics_providers');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo $row['email'].' - '.$row['business_name'].' ('.($row['first_name'].' '.$row['last_name'] ?? '').') | Province: '.($row['province_name'] ?? 'null').' | Status: '.($row['status'] ?? 'null').' | Verified: '.($row['email_verified_at'] ? 'yes' : 'no')."\n";
}

// Query riders table
echo "\n=== RIDERS TABLE ===\n";
$stmt = $pdo->query('SELECT id, email, name, phone, vehicle_type, max_weight_grams, status, provider_id FROM riders');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo $row['email'].' - '.$row['name'].' | Phone: '.($row['phone'] ?? 'null').' | Vehicle: '.($row['vehicle_type'] ?? 'null').' | MaxWeight: '.($row['max_weight_grams'] ?? 'null').' | Status: '.($row['status'] ?? 'null').' | Provider: '.($row['provider_id'] ?? 'null')."\n";
}

// Query couriers table
echo "\n=== COURIERS TABLE ===\n";
$stmt = $pdo->query('SELECT id, name, phone, logistics_provider_id, rider_id, area_id, status FROM couriers');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo $row['name'].' | Phone: '.($row['phone'] ?? 'null').' | LogisticsProvider: '.($row['logistics_provider_id'] ?? 'null').' | Rider: '.($row['rider_id'] ?? 'null').' | Area: '.($row['area_id'] ?? 'null').' | Status: '.($row['status'] ?? 'null')."\n";
}

echo "\n=== DONE ===\n";
