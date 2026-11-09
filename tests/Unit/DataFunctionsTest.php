<?php

use PHPUnit\Framework\TestCase;

/**
 * Lookup functions from functions.php that books.php and index.php still call,
 * run against the test database and the rows tests/setup_test_database.sql seeds.
 */
class DataFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../config.php';
        require_once __DIR__ . '/../../functions.php';

        TestHelper::database($this);
    }

    public function testGetStatusesReturnsEveryStatusSortedByName()
    {
        $this->assertEquals([
            ['id' => 3, 'name' => 'Correction'],
            ['id' => 1, 'name' => 'Editing'],
            ['id' => 4, 'name' => 'Finished'],
            ['id' => 2, 'name' => 'Review'],
        ], get_statuses());
    }

    public function testGetFormatingsReturnsEveryFormatSortedByName()
    {
        $this->assertEquals([
            ['id' => 1, 'name' => 'ePub', 'shortname' => 'epub'],
            ['id' => 3, 'name' => 'Mobi', 'shortname' => 'mobi'],
            ['id' => 2, 'name' => 'PDF', 'shortname' => 'pdf'],
        ], get_formatings());
    }
}
