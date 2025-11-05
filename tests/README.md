# Test Suite for Library Management System

This directory contains comprehensive tests for the Library Management System PHP application.

## Overview

The test suite includes:
- **Unit Tests**: Testing individual functions in isolation
- **Integration Tests**: Testing complete workflows with database interactions

## Test Coverage

### Unit Tests
- `AuthenticationTest.php` - Authentication and authorization functions
- `SecurityTest.php` - CSRF protection, input validation, and sanitization
- `DataFunctionsTest.php` - Data retrieval and query functions

### Integration Tests
- `LoginFlowTest.php` - Complete login flow, rate limiting, and user management

## Requirements

- PHP 8.0 or higher
- PHPUnit 10.5 or higher
- MySQL database
- Composer (recommended)

## Installation

### 1. Install PHPUnit via Composer

```bash
composer require --dev phpunit/phpunit
```

### 2. Set Up Test Database

Run the SQL setup script to create a separate test database:

```bash
mysql -u jsistem_apuser -p < tests/setup_test_database.sql
```

Or manually execute in MySQL:

```sql
source D:/home/sites/j-sistem/web/ap_claude/tests/setup_test_database.sql
```

### 3. Configure Database Credentials

Test database configuration is in `phpunit.xml`. Default settings:
- Database: `jsistem_ap_test`
- User: `jsistem_apuser`
- Password: `pAP3779`

Modify the `<php>` section in `phpunit.xml` if your credentials differ.

## Running Tests

### Run All Tests

```bash
vendor/bin/phpunit
```

Or on Windows:

```bash
vendor\bin\phpunit
```

### Run Specific Test Suite

```bash
# Unit tests only
vendor/bin/phpunit --testsuite "Unit Tests"

# Integration tests only
vendor/bin/phpunit --testsuite "Integration Tests"
```

### Run Specific Test File

```bash
vendor/bin/phpunit tests/Unit/SecurityTest.php
```

### Run with Coverage Report (requires Xdebug)

```bash
vendor/bin/phpunit --coverage-html coverage
```

### Run with Verbose Output

```bash
vendor/bin/phpunit --verbose
```

## Test Database Management

### Important Notes

1. **Separate Database**: Tests use `jsistem_ap_test`, NOT `jsistem_ap`
2. **Automatic Cleanup**: Integration tests clean up test data after each test
3. **Safe Testing**: Production data is never affected

### Manual Cleanup

If needed, clean the test database:

```sql
USE jsistem_ap_test;
DELETE FROM login_attempt;
DELETE FROM user_log;
DELETE FROM book_author;
DELETE FROM book;
DELETE FROM author;
DELETE FROM users;
```

## Test Structure

```
tests/
├── bootstrap.php              # Test environment setup
├── TestHelper.php             # Utility functions for tests
├── setup_test_database.sql    # Database schema for testing
├── Unit/                      # Unit tests (mocked dependencies)
│   ├── AuthenticationTest.php
│   ├── SecurityTest.php
│   └── DataFunctionsTest.php
└── Integration/               # Integration tests (real database)
    └── LoginFlowTest.php
```

## Writing New Tests

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;

class MyNewTest extends TestCase
{
    protected function setUp(): void
    {
        TestHelper::resetSession();
        require_once __DIR__ . '/../../functions.php';
    }

    public function testSomething()
    {
        $result = my_function('input');
        $this->assertEquals('expected', $result);
    }
}
```

### Integration Test Example

```php
use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase
{
    protected $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestHelper::createTestDatabase();
        TestHelper::cleanupTestData($this->pdo);
        $GLOBALS['pdo'] = $this->pdo;
    }

    protected function tearDown(): void
    {
        TestHelper::cleanupTestData($this->pdo);
    }

    public function testDatabaseOperation()
    {
        $userId = TestHelper::createTestUser($this->pdo, 'test', 'pass');
        $this->assertGreaterThan(0, $userId);
    }
}
```

## Continuous Integration

To integrate with CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
- name: Setup test database
  run: mysql -u root -proot < tests/setup_test_database.sql

- name: Run tests
  run: vendor/bin/phpunit --coverage-clover coverage.xml
```

## Troubleshooting

### "Test database not available"

**Solution**: Ensure the test database exists and credentials are correct:

```bash
mysql -u jsistem_apuser -p -e "SHOW DATABASES LIKE 'jsistem_ap_test';"
```

### "Headers already sent" errors

**Solution**: The bootstrap file includes `ob_start()` to prevent this. Ensure you're using the bootstrap.

### PHPUnit not found

**Solution**: Install via Composer:

```bash
composer install
```

Or download PHPUnit manually from https://phar.phpunit.de/

### Database connection errors

**Solution**: Check your MySQL service is running:

```bash
# Windows
net start MySQL

# Check connection
mysql -u jsistem_apuser -p -e "SELECT 1"
```

## Security Testing Notes

The test suite covers:
- CSRF token generation and validation
- SQL injection prevention (parameterized queries)
- XSS prevention (input sanitization)
- Rate limiting for login attempts
- Password hashing security
- Session management
- Admin privilege escalation prevention

## Performance Considerations

- Unit tests run in milliseconds (use mocks)
- Integration tests may take seconds (use real database)
- Use `--stop-on-failure` for faster feedback during development

## Next Steps

1. Add more integration tests for:
   - Book CRUD operations
   - Author management
   - Statistics generation
   - Export functionality

2. Add browser tests with Selenium/Panther for:
   - Login form submission
   - AJAX operations
   - Modal interactions

3. Add API tests if REST endpoints are added

## License

Same as the main application.
