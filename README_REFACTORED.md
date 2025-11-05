# Library Management System - Refactored

## What Changed

✅ **Added clean architecture** - Models, Repositories, Services
✅ **PSR-4 autoloading** - Composer-based
✅ **Validation layer** - Centralized input validation
✅ **Backward compatible** - Old code still works

## Quick Start

### Option 1: Use New Architecture

```php
require_once 'bootstrap.php';

$bookService = getBookService();
$books = $bookService->getBooks(searchTitle: 'PHP', page: 1);
```

### Option 2: Keep Using Old Code

```php
require_once 'config.php';
require_once 'functions.php';
// Everything works as before
```

## Key Components

| Component | Purpose | Example |
|-----------|---------|---------|
| **Models** | Data objects | `$book = new Book(['title' => 'Test'])` |
| **Repositories** | DB queries | `$bookRepo->find(1)` |
| **Services** | Business logic | `$bookService->createBook($data)` |
| **Validators** | Input validation | `$validator->email($email)` |

## Benefits

- **Testable** - Unit tests for business logic
- **Maintainable** - Separation of concerns
- **Reusable** - Services can be used anywhere
- **Type-safe** - Better IDE autocomplete

## Running Tests

```bash
composer install
./vendor/bin/phpunit tests/Unit
```

## Files Structure

```
src/                    # New refactored code
├── Models/            # Book, Author, User
├── Repositories/      # Database operations
├── Services/          # Business logic
└── Validators/        # Input validation

config/                # Configuration
bootstrap.php          # Initialize new architecture
example_usage.php      # Usage examples
```

## Migration Guide

See `REFACTORING.md` for detailed migration steps.

## Compatibility

- PHP 8.0+
- MySQL 5.7+
- All existing functionality preserved
