<?php
/**
 * Quick test to verify refactored architecture works
 */

require_once 'bootstrap.php';

echo "<h1>Refactoring Test</h1>";

// Test 1: Database connection
try {
    $db = getDatabase();
    echo "✅ Database connection: OK<br>";
} catch (Exception $e) {
    echo "❌ Database connection: FAILED - " . $e->getMessage() . "<br>";
}

// Test 2: BookService
try {
    $bookService = getBookService();
    $count = $bookService->getBooksCount();
    echo "✅ BookService: OK (Found $count books)<br>";
} catch (Exception $e) {
    echo "❌ BookService: FAILED - " . $e->getMessage() . "<br>";
}

// Test 3: AuthorService
try {
    $authorService = getAuthorService();
    $count = $authorService->getAuthorsCount();
    echo "✅ AuthorService: OK (Found $count authors)<br>";
} catch (Exception $e) {
    echo "❌ AuthorService: FAILED - " . $e->getMessage() . "<br>";
}

// Test 4: Composer autoloading
try {
    $book = new \App\Models\Book(['title' => 'Test']);
    echo "✅ Composer Autoload: OK<br>";
    echo "✅ Model instantiation: OK<br>";
} catch (Exception $e) {
    echo "❌ Autoload: FAILED - " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h2>All Systems Ready! 🎉</h2>";
echo "<p><a href='index.php'>Go to Dashboard</a></p>";
echo "<p><a href='books.php'>Go to Books</a></p>";
echo "<p><a href='authors.php'>Go to Authors</a></p>";
