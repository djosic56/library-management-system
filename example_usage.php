<?php
/**
 * Example: Using the new refactored architecture
 */

require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

// ============================================
// EXAMPLE 1: Working with Books
// ============================================

$bookService = getBookService();

// Get books with filters
$books = $bookService->getBooks(
    searchTitle: 'PHP',
    filterStatus: 1,
    page: 1,
    sortBy: 'title',
    sortOrder: 'ASC'
);

// Get total count
$totalBooks = $bookService->getBooksCount(
    searchTitle: 'PHP'
);

// Get single book with authors
$book = $bookService->getBook(1);

// Create new book
$newBookData = [
    'title' => 'New Book Title',
    'pages' => 300,
    'date_start' => '2025-01-01',
    'date_finish' => '2025-03-01',
    'id_status' => 1,
    'id_formating' => 1,
    'invoice' => 0,
    'note' => 'Sample note'
];

$bookId = $bookService->createBook($newBookData, authorIds: [1, 2]);

if (!$bookId) {
    // Validation failed
    $errors = $bookService->getErrors();
    echo "Errors: " . implode(', ', $errors);
}

// Update book
$updateData = [
    'title' => 'Updated Title',
    'pages' => 350,
    'date_start' => '2025-01-01',
    'id_status' => 2
];

$success = $bookService->updateBook(1, $updateData, authorIds: [1, 3]);

// Delete book
$bookService->deleteBook($bookId);

// ============================================
// EXAMPLE 2: Working with Authors
// ============================================

$authorService = getAuthorService();

// Get authors
$authors = $authorService->getAuthors(
    search: 'Smith',
    page: 1,
    sortBy: 'name'
);

// Search for autocomplete
$searchResults = $authorService->searchByName('John', limit: 10);

// Create author
$authorData = [
    'fname' => 'John',
    'name' => 'Doe',
    'email' => 'john@example.com'
];

$authorId = $authorService->createAuthor($authorData);

if (!$authorId) {
    $errors = $authorService->getErrors();
}

// Update author
$authorService->updateAuthor(1, [
    'fname' => 'Jane',
    'name' => 'Smith',
    'email' => 'jane@example.com'
]);

// Check if author has books before deletion
if (!$authorService->hasBooks($authorId)) {
    $authorService->deleteAuthor($authorId);
}

// ============================================
// EXAMPLE 3: Using Models Directly
// ============================================

use App\Models\Book;

$book = new Book([
    'title' => 'Sample Book',
    'pages' => 200
]);

$book->setTitle('Updated Title');
$book->setPages(250);

echo $book->getTitle(); // "Updated Title"
echo $book->toJson();   // JSON representation
