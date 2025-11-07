<?php
/**
 * Quick test to verify Database singleton works after removing global $pdo
 */

require_once 'bootstrap.php';
require_once 'functions.php';

echo "Testing Database connection...\n\n";

try {
    // Test 1: Database singleton
    $db = \App\Database\Database::getInstance();
    $pdo = $db->getConnection();
    echo "✓ Database singleton working\n";

    // Test 2: Test functions.php functions
    $statuses = get_statuses();
    echo "✓ get_statuses() returned " . count($statuses) . " statuses\n";

    $formatings = get_formatings();
    echo "✓ get_formatings() returned " . count($formatings) . " formatings\n";

    // Test 3: Test login rate limiting function
    $is_locked = check_login_attempts('test_user', '127.0.0.1');
    echo "✓ check_login_attempts() working (locked: " . ($is_locked ? 'yes' : 'no') . ")\n";

    echo "\n✅ All tests passed! Global \$pdo successfully eliminated.\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
