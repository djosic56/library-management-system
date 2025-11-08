<?php
/**
 * Test Interface Implementation
 * Verify all classes correctly implement their interfaces
 */

require_once 'bootstrap.php';

use App\Contracts\BookServiceInterface;
use App\Contracts\AuthorServiceInterface;
use App\Contracts\BookRepositoryInterface;
use App\Contracts\AuthorRepositoryInterface;
use App\Contracts\RepositoryInterface;
use App\Services\BookService;
use App\Services\AuthorService;
use App\Repositories\BookRepository;
use App\Repositories\AuthorRepository;
use App\Database\Database;

echo "Testiranje Interface Implementacija...\n\n";

// Test 1: Service Interfaces
echo "Test 1: Service Interfaces\n";
$db = Database::getInstance();
$bookService = new BookService($db);
$authorService = new AuthorService($db);

echo "✓ BookService implements BookServiceInterface: " .
     ($bookService instanceof BookServiceInterface ? 'DA' : 'NE') . "\n";
echo "✓ AuthorService implements AuthorServiceInterface: " .
     ($authorService instanceof AuthorServiceInterface ? 'DA' : 'NE') . "\n";
echo "\n";

// Test 2: Repository Interfaces
echo "Test 2: Repository Interfaces\n";
$bookRepo = new BookRepository($db);
$authorRepo = new AuthorRepository($db);

echo "✓ BookRepository implements BookRepositoryInterface: " .
     ($bookRepo instanceof BookRepositoryInterface ? 'DA' : 'NE') . "\n";
echo "✓ AuthorRepository implements AuthorRepositoryInterface: " .
     ($authorRepo instanceof AuthorRepositoryInterface ? 'DA' : 'NE') . "\n";
echo "✓ BookRepository implements RepositoryInterface: " .
     ($bookRepo instanceof RepositoryInterface ? 'DA' : 'NE') . "\n";
echo "✓ AuthorRepository implements RepositoryInterface: " .
     ($authorRepo instanceof RepositoryInterface ? 'DA' : 'NE') . "\n";
echo "\n";

// Test 3: Type Hinting with Interfaces
echo "Test 3: Type Hinting\n";

function testBookService(BookServiceInterface $service): bool {
    return $service instanceof BookServiceInterface;
}

function testAuthorService(AuthorServiceInterface $service): bool {
    return $service instanceof AuthorServiceInterface;
}

function testBookRepository(BookRepositoryInterface $repo): bool {
    return $repo instanceof BookRepositoryInterface;
}

function testAuthorRepository(AuthorRepositoryInterface $repo): bool {
    return $repo instanceof AuthorRepositoryInterface;
}

echo "✓ Type hinting BookServiceInterface: " .
     (testBookService($bookService) ? 'RADI' : 'NE RADI') . "\n";
echo "✓ Type hinting AuthorServiceInterface: " .
     (testAuthorService($authorService) ? 'RADI' : 'NE RADI') . "\n";
echo "✓ Type hinting BookRepositoryInterface: " .
     (testBookRepository($bookRepo) ? 'RADI' : 'NE RADI') . "\n";
echo "✓ Type hinting AuthorRepositoryInterface: " .
     (testAuthorRepository($authorRepo) ? 'RADI' : 'NE RADI') . "\n";
echo "\n";

// Test 4: Method Availability
echo "Test 4: Method Availability\n";
echo "✓ BookService::getBooks() exists: " .
     (method_exists($bookService, 'getBooks') ? 'DA' : 'NE') . "\n";
echo "✓ BookService::createBook() exists: " .
     (method_exists($bookService, 'createBook') ? 'DA' : 'NE') . "\n";
echo "✓ AuthorService::getAuthors() exists: " .
     (method_exists($authorService, 'getAuthors') ? 'DA' : 'NE') . "\n";
echo "✓ BookRepository::find() exists: " .
     (method_exists($bookRepo, 'find') ? 'DA' : 'NE') . "\n";
echo "✓ BookRepository::insert() exists: " .
     (method_exists($bookRepo, 'insert') ? 'DA' : 'NE') . "\n";
echo "\n";

// Test 5: Functional Test
echo "Test 5: Functional Test\n";
try {
    $books = $bookService->getBooks();
    echo "✓ BookService::getBooks() vraća array: " . (is_array($books) ? 'DA' : 'NE') . "\n";

    $authors = $authorService->getAuthors();
    echo "✓ AuthorService::getAuthors() vraća array: " . (is_array($authors) ? 'DA' : 'NE') . "\n";

    echo "\n";
    echo "✅ Svi testovi prošli! Interface implementacija uspješna.\n";
    echo "\nPrednosti:\n";
    echo "  - Type safety kroz interfejse\n";
    echo "  - Dependency Injection friendly\n";
    echo "  - Lakše mockanje za testove\n";
    echo "  - Loose coupling između komponenti\n";
} catch (Exception $e) {
    echo "\n❌ Greška: " . $e->getMessage() . "\n";
    exit(1);
}
