<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for data retrieval functions
 */
class DataFunctionsTest extends TestCase
{
    protected $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $GLOBALS['pdo'] = $this->pdo;

        require_once __DIR__ . '/../../config.php';
        require_once __DIR__ . '/../../functions.php';
    }

    /**
     * Test get_statuses() returns all statuses
     */
    public function testGetStatusesReturnsAllStatuses()
    {
        $expectedStatuses = [
            ['id' => 1, 'name' => 'Editing'],
            ['id' => 2, 'name' => 'Correction'],
            ['id' => 3, 'name' => 'Finished'],
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($expectedStatuses);

        $this->pdo->method('query')->willReturn($stmt);

        $result = get_statuses();

        $this->assertEquals($expectedStatuses, $result);
    }

    /**
     * Test get_formatings() returns all formats
     */
    public function testGetFormatingsReturnsAllFormats()
    {
        $expectedFormats = [
            ['id' => 1, 'name' => 'ePub', 'shortname' => 'epub'],
            ['id' => 2, 'name' => 'PDF', 'shortname' => 'pdf'],
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($expectedFormats);

        $this->pdo->method('query')->willReturn($stmt);

        $result = get_formatings();

        $this->assertEquals($expectedFormats, $result);
    }

    /**
     * Test get_books() with no filters
     */
    public function testGetBooksWithNoFilters()
    {
        $expectedBooks = [
            [
                'id' => 1,
                'title' => 'Test Book',
                'pages' => 100,
                'authors' => 'John Doe',
                'status_name' => 'Editing',
            ],
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($expectedBooks);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmt);

        $result = get_books('', '', '', '', 1);

        $this->assertEquals($expectedBooks, $result);
    }

    /**
     * Test get_books() with title search
     */
    public function testGetBooksWithTitleSearch()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('b.title LIKE ?'))
            ->willReturn($stmt);

        get_books('Test', '', '', '', 1);
    }

    /**
     * Test get_books() with author search
     */
    public function testGetBooksWithAuthorSearch()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('a.fname LIKE ?'))
            ->willReturn($stmt);

        get_books('', 'John', '', '', 1);
    }

    /**
     * Test get_books() with status filter
     */
    public function testGetBooksWithStatusFilter()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('b.id_status = ?'))
            ->willReturn($stmt);

        get_books('', '', '1', '', 1);
    }

    /**
     * Test get_books() with invoice filter
     */
    public function testGetBooksWithInvoiceFilter()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('b.invoice = ?'))
            ->willReturn($stmt);

        get_books('', '', '', '1', 1);
    }

    /**
     * Test get_books() validates sort column
     */
    public function testGetBooksValidatesSortColumn()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        // Invalid sort column should default to 'id'
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ORDER BY b.id'))
            ->willReturn($stmt);

        get_books('', '', '', '', 1, 'invalid_column');
    }

    /**
     * Test get_books() validates sort order
     */
    public function testGetBooksValidatesSortOrder()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        // Invalid sort order should default to DESC
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DESC'))
            ->willReturn($stmt);

        get_books('', '', '', '', 1, 'id', 'INVALID');
    }

    /**
     * Test get_books() applies pagination
     */
    public function testGetBooksAppliesPagination()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('LIMIT ? OFFSET ?'))
            ->willReturn($stmt);

        get_books('', '', '', '', 2); // Page 2
    }

    /**
     * Test get_books_count() with no filters
     */
    public function testGetBooksCountWithNoFilters()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(42);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturn($stmt);

        $count = get_books_count('', '', '', '');

        $this->assertEquals(42, $count);
    }

    /**
     * Test get_books_count() with filters
     */
    public function testGetBooksCountWithFilters()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(10);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('b.title LIKE ?'),
                $this->stringContains('b.id_status = ?')
            ))
            ->willReturn($stmt);

        $count = get_books_count('Test', '', '1', '');

        $this->assertEquals(10, $count);
    }

    /**
     * Test get_books() with all filters combined
     */
    public function testGetBooksWithAllFiltersCombined()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('b.title LIKE ?'),
                $this->stringContains('a.fname LIKE ?'),
                $this->stringContains('b.id_status = ?'),
                $this->stringContains('b.invoice = ?')
            ))
            ->willReturn($stmt);

        get_books('Book', 'Author', '1', '1', 1);
    }

    /**
     * Test get_books() allows valid sort columns
     */
    public function testGetBooksAllowsValidSortColumns()
    {
        $validColumns = ['id', 'title', 'date_start', 'date_finish'];

        foreach ($validColumns as $column) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('bindValue')->willReturn(true);
            $stmt->method('execute')->willReturn(true);

            $this->pdo->expects($this->once())
                ->method('prepare')
                ->with($this->stringContains("ORDER BY b.$column"))
                ->willReturn($stmt);

            get_books('', '', '', '', 1, $column);

            // Reset mock
            $this->setUp();
        }
    }

    /**
     * Test get_books() handles ASC sort order
     */
    public function testGetBooksHandlesAscSortOrder()
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ASC'))
            ->willReturn($stmt);

        get_books('', '', '', '', 1, 'id', 'ASC');
    }
}
