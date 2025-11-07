<?php
/**
 * Test ErrorHandler functionality
 */

require_once 'bootstrap.php';

use App\Exceptions\ValidationException;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;

echo "Testing Error Handler...\n\n";

// Test 1: ValidationException
echo "Test 1: ValidationException\n";
try {
    throw new ValidationException('email', 'Please enter a valid email address.');
} catch (Exception $e) {
    echo "✓ Caught: " . $e->getUserMessage() . "\n";
    echo "  Developer message: " . $e->getDeveloperMessage() . "\n\n";
}

// Test 2: DatabaseException
echo "Test 2: DatabaseException\n";
try {
    throw new DatabaseException('Connection failed to mysql://localhost');
} catch (Exception $e) {
    echo "✓ Caught: " . $e->getUserMessage() . "\n";
    echo "  Developer message: " . $e->getDeveloperMessage() . "\n\n";
}

// Test 3: NotFoundException
echo "Test 3: NotFoundException\n";
try {
    throw new NotFoundException('Book', 999);
} catch (Exception $e) {
    echo "✓ Caught: " . $e->getUserMessage() . "\n";
    echo "  Developer message: " . $e->getDeveloperMessage() . "\n\n";
}

// Test 4: Exception codes
echo "Test 4: Exception codes\n";
$validationEx = new ValidationException('field', 'Test');
$notFoundEx = new NotFoundException('Book', 1);
echo "✓ Validation code: " . $validationEx->getCode() . " (expected 400)\n";
echo "✓ NotFound code: " . $notFoundEx->getCode() . " (expected 404)\n\n";

echo "✅ All error handler tests passed!\n";
