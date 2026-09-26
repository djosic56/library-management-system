<?php

namespace App\Repositories;

/** Popis PDF poslova u bazi; izvor istine za stanje je status.json (PdfJobStore). */
class PdfJobRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(string $id, string $name, ?int $bookId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO pdf_job (id, name, book_id, state, created_by) VALUES (?, ?, ?, 'queued', ?)");
        $stmt->execute([$id, $name, $bookId, $userId]);
    }

    public function all(): array
    {
        return $this->pdo->query(
            'SELECT j.*, b.title AS book_title FROM pdf_job j LEFT JOIN book b ON b.id = j.book_id
             ORDER BY j.created_at DESC')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pdf_job WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function syncStatus(string $id, array $status, ?array $descriptions, ?array $summary): void
    {
        $pdfua = $status['pdfua_ok'] ?? null;
        $stmt = $this->pdo->prepare(
            'UPDATE pdf_job SET state = ?, images = COALESCE(?, images), cost_usd = COALESCE(?, cost_usd),
             pdfua_ok = ? WHERE id = ?');
        $stmt->execute([
            $status['state'] ?? 'queued',
            $descriptions !== null ? count($descriptions['images'] ?? []) : null,
            $descriptions['usage']['estimated_cost_usd'] ?? null,
            $pdfua === null ? null : (int) (bool) $pdfua,
            $id,
        ]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM pdf_job WHERE id = ?')->execute([$id]);
    }

    public function books(): array
    {
        return $this->pdo->query('SELECT id, title FROM book ORDER BY title')->fetchAll(\PDO::FETCH_ASSOC);
    }
}
