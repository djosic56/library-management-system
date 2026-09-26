<?php

use App\Repositories\PdfJobRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class PdfJobRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdfJobRepository $repo;

    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                getenv('DB_USER'), getenv('DB_PASS'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Lokalni MySQL nije dostupan: ' . $e->getMessage());
        }
        $this->pdo->exec('DELETE FROM pdf_job');
        $this->repo = new PdfJobRepository($this->pdo);
    }

    public function testInsertSyncAndList(): void
    {
        $id = str_repeat('b', 32);
        $this->repo->insert($id, 'Knjiga', null, 1);
        $this->repo->syncStatus($id, ['state' => 'done', 'pdfua_ok' => false],
            ['images' => [[], [], []], 'usage' => ['estimated_cost_usd' => 0.2183]], null);

        $row = $this->repo->find($id);
        $this->assertSame('done', $row['state']);
        $this->assertSame(3, (int) $row['images']);
        $this->assertSame('0.2183', (string) $row['cost_usd']);
        $this->assertSame(0, (int) $row['pdfua_ok']);
        $this->assertCount(1, $this->repo->all());

        $this->repo->delete($id);
        $this->assertNull($this->repo->find($id));
    }

    public function testPdfuaUnknownIsNull(): void
    {
        $id = str_repeat('c', 32);
        $this->repo->insert($id, 'Knjiga', null, 1);
        $this->repo->syncStatus($id, ['state' => 'done', 'pdfua_ok' => null], null, null);

        $this->assertNull($this->repo->find($id)['pdfua_ok']);
    }
}
