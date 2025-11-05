# Refactoring Guide

## New Architecture

The codebase has been refactored with:

- **Models** (`src/Models/`) - Data representation
- **Repositories** (`src/Repositories/`) - Database operations
- **Services** (`src/Services/`) - Business logic
- **Validators** (`src/Validators/`) - Input validation

## Usage Example

```php
<?php
require_once 'bootstrap.php';

// Using the new architecture
$bookService = getBookService();

// Get books with filters
$books = $bookService->getBooks(
    searchTitle: 'PHP',
    page: 1,
    sortBy: 'title'
);

// Create new book
$bookId = $bookService->createBook([
    'title' => 'My Book',
    'pages' => 250,
    'date_start' => '2025-01-01'
], authorIds: [1, 2]);

// Handle validation errors
if (!$bookId) {
    $errors = $bookService->getErrors();
}
```

## Backward Compatibility

Legacy code still works. New architecture is opt-in via `bootstrap.php`.

## Migration Steps

1. Include `bootstrap.php` instead of `config.php`
2. Use service classes for business logic
3. Replace direct `$pdo` calls with repositories
4. Gradually refactor existing pages

## File Structure

```
src/
├── Database/
│   └── Database.php          # PDO wrapper
├── Models/
│   ├── Model.php             # Base model
│   ├── Book.php
│   ├── Author.php
│   └── User.php
├── Repositories/
│   ├── Repository.php        # Base repository
│   ├── BookRepository.php
│   ├── AuthorRepository.php
│   └── UserRepository.php
├── Services/
│   ├── BookService.php
│   └── AuthorService.php
└── Validators/
    └── Validator.php

config/
└── app.php                   # Centralized config

bootstrap.php                 # App initialization
```
