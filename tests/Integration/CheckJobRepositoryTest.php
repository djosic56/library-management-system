<?php

use App\Repositories\CheckJobRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class CheckJobRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CheckJobRepository $repo;

    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                getenv('DB_USER'), getenv('DB_PASS'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Lokalni MySQL nije dostupan: ' . $e->getMessage());
        }
        $this->pdo->exec('DELETE FROM check_job');
        $this->repo = new CheckJobRepository($this->pdo);
    }

    public function testInsertSyncListDelete(): void
    {
        $id = str_repeat('d', 32);
        $this->repo->insert($id, 'Nantet', null, 1);
        $this->repo->syncStatus($id, ['state' => 'review'], ['findings' => 303, 'reviewed' => 287, 'pending' => 16,
            'accepted' => 241, 'rejected' => 40, 'edited' => 6, 'not_placed' => 4, 'failed_units' => 0, 'cost_usd' => 7.4]);
        $row = $this->repo->find($id);
        $this->assertSame(['review', 303, 287, 241, 4], [$row['state'], (int) $row['findings'], (int) $row['reviewed'],
            (int) $row['accepted'], (int) $row['not_placed']]);
        $this->assertSame('7.4000', (string) $row['cost_usd']);
        $this->assertCount(1, $this->repo->all());
        $this->repo->delete($id);
        $this->assertNull($this->repo->find($id));
    }
}
