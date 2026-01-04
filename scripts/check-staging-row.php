<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$uuid = '45416480-e89d-11f0-9caa-06db59a42104';

$stmt = $db->prepare('SELECT staging_uuid, import_batch_uuid, row_number, industry_resolution FROM org_import_staging WHERE staging_uuid = ?');
$stmt->execute([$uuid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "Row gefunden:\n";
    echo "UUID: {$row['staging_uuid']}\n";
    echo "Batch: {$row['import_batch_uuid']}\n";
    echo "Row Number: {$row['row_number']}\n";
    echo "\nIndustry Resolution:\n";
    $resolution = json_decode($row['industry_resolution'], true);
    print_r($resolution);
} else {
    echo "Row NICHT gefunden\n";
}

