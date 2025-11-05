# Quick Reference

## Setup

```php
require_once 'bootstrap.php';
$bookService = getBookService();
$authorService = getAuthorService();
```

## Books

```php
// List books
$books = $bookService->getBooks(searchTitle: 'PHP', page: 1);
$count = $bookService->getBooksCount();

// Get one book
$book = $bookService->getBook(1);

// Create
$id = $bookService->createBook([
    'title' => 'Book Title',
    'pages' => 200
], authorIds: [1, 2]);

// Update
$bookService->updateBook(1, ['title' => 'New Title'], [1]);

// Delete
$bookService->deleteBook(1);

// Validation errors
if (!$id) {
    $errors = $bookService->getErrors();
}
```

## Authors

```php
// List authors
$authors = $authorService->getAuthors(search: 'Smith');

// Autocomplete search
$results = $authorService->searchByName('John');

// Create
$id = $authorService->createAuthor([
    'fname' => 'John',
    'name' => 'Doe',
    'email' => 'john@example.com'
]);

// Update
$authorService->updateAuthor(1, ['email' => 'new@email.com']);

// Delete
if (!$authorService->hasBooks(1)) {
    $authorService->deleteAuthor(1);
}
```

## Direct Repository Access

```php
use App\Repositories\BookRepository;

$db = getDatabase();
$bookRepo = new BookRepository($db);

// Find by ID
$book = $bookRepo->find(1);

// Custom query
$books = $bookRepo->findAll(['id_status' => 1], 'title ASC', 20);

// Count
$total = $bookRepo->count(['invoice' => 0]);
```

## Validation

```php
use App\Validators\Validator;

$validator = new Validator();

$validator->required($value, 'Field name');
$validator->email($email, 'Email');
$validator->min($number, 0, 'Pages');
$validator->dateRange($start, $end);

if ($validator->fails()) {
    $errors = $validator->getErrors();
}
```
