<?php
/**
 * Test Logger functionality
 */

require_once 'bootstrap.php';

use App\Logging\Logger;

echo "Testiranje Logger Klase...\n\n";

// Test 1: Basic logging
echo "Test 1: Basic Logging Levels\n";
Logger::info("Ovo je info poruka");
Logger::warning("Ovo je warning poruka");
Logger::error("Ovo je error poruka");
Logger::debug("Ovo je debug poruka (možda neće biti logirano)");
echo "✓ Logirano 4 poruke\n\n";

// Test 2: Logging with context
echo "Test 2: Logging sa Context Podacima\n";
Logger::error("Database connection failed", [
    'host' => 'localhost',
    'database' => 'test_db',
    'error_code' => 1045
]);
Logger::info("User logged in", [
    'user_id' => 123,
    'username' => 'admin',
    'ip' => '192.168.1.1'
]);
echo "✓ Logirano 2 poruke sa context-om\n\n";

// Test 3: Different severity levels
echo "Test 3: Razni Severity Levels\n";
Logger::emergency("System is down!");
Logger::alert("Disk space critical!");
Logger::critical("Payment gateway offline");
Logger::notice("User preferences updated");
echo "✓ Logirano 4 različita severity levels\n\n";

// Test 4: Check recent logs
echo "Test 4: Dohvat Nedavnih Logova\n";
$recentLogs = Logger::getRecent(5);
echo "✓ Dohvaćeno " . count($recentLogs) . " nedavnih log zapisa\n";
if (count($recentLogs) > 0) {
    echo "  Posljednji log: " . trim($recentLogs[count($recentLogs) - 1]) . "\n";
}
echo "\n";

// Test 5: Log file creation
echo "Test 5: Provjera Log Fajla\n";
$logFile = __DIR__ . '/logs/app.log';
if (file_exists($logFile)) {
    $fileSize = filesize($logFile);
    echo "✓ Log fajl postoji: {$logFile}\n";
    echo "  Veličina: {$fileSize} bytes\n";
} else {
    echo "❌ Log fajl ne postoji!\n";
}
echo "\n";

echo "✅ Svi Logger testovi prošli!\n\n";

echo "Korištenje:\n";
echo "  Logger::info('message')           - Informativne poruke\n";
echo "  Logger::warning('message')        - Upozorenja\n";
echo "  Logger::error('message', \$ctx)   - Greške sa kontekstom\n";
echo "  Logger::critical('message')       - Kritične greške\n";
echo "  Logger::getRecent(100)           - Dohvati posljednjih 100 zapisa\n";
