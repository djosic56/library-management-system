<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\BookService;
use App\Database\Database;

/**
 * Example unit test for BookService
 */
class BookServiceTest extends TestCase
{
    private BookService $bookService;

    protected function setUp(): void
    {
        // Initialize with test database
        $config = [
            'host' => 'localhost',
            'database' => 'jsistem_ap_test',
            'username' => 'jsistem_apuser',
            'password' => 'pAP3779',
        ];

        $database = Database::getInstance($config);
        $this->bookService = new BookService($database);
    }

    public function testValidateBookWithValidData()
    {
        $data = [
            'title' => 'Test Book',
            'pages' => 100,
            'date_start' => '2025-01-01',
            'date_finish' => '2025-03-01'
        ];

        $result = $this->bookService->validateBook($data);
        $this->assertTrue($result);
    }

    public function testValidateBookWithMissingTitle()
    {
        $data = [
            'title' => '',
            'pages' => 100
        ];

        $result = $this->bookService->validateBook($data);
        $this->assertFalse($result);
        $this->assertNotEmpty($this->bookService->getErrors());
    }

    public function testValidateBookWithNegativePages()
    {
        $data = [
            'title' => 'Test Book',
            'pages' => -10
        ];

        $result = $this->bookService->validateBook($data);
        $this->assertFalse($result);
    }

    public function testValidateBookWithInvalidDateRange()
    {
        $data = [
            'title' => 'Test Book',
            'date_start' => '2025-03-01',
            'date_finish' => '2025-01-01'
        ];

        $result = $this->bookService->validateBook($data);
        $this->assertFalse($result);
    }
}
