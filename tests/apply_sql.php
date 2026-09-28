<?php
// php tests/apply_sql.php migrations/<file>.sql — primijeni SQL na test bazu iz phpunit.xml
$xml = simplexml_load_file(__DIR__ . '/../phpunit.xml');
$env = [];
foreach ($xml->php->env as $e) {
    $env[(string) $e['name']] = (string) $e['value'];
}
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents($argv[1]));
echo "applied {$argv[1]} to {$env['DB_NAME']}\n";
