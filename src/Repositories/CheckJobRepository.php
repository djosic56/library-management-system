<?php

namespace App\Repositories;

/** Popis Check poslova u bazi; izvor istine su JSON-i posla (CheckJobStore). */
class CheckJobRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(string $id, string $name, ?int $bookId, int $userId): void
    {
        $this->pdo->prepare("INSERT INTO check_job (id, name, book_id, state, created_by) VALUES (?, ?, ?, 'queued', ?)")
            ->execute([$id, $name, $bookId, $userId]);
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT j.*, b.title AS book_title FROM check_job j LEFT JOIN book b ON b.id = j.book_id
             ORDER BY j.created_at DESC')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM check_job WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function syncStatus(string $id, array $status, array $metrics): void
    {
        $this->pdo->prepare('UPDATE check_job SET state = ?, findings = ?, reviewed = ?, accepted = ?, rejected = ?,
             edited = ?, not_placed = ?, failed_units = ?, cost_usd = ? WHERE id = ?')
            ->execute([$status['state'] ?? 'queued', $metrics['findings'], $metrics['reviewed'], $metrics['accepted'],
                $metrics['rejected'], $metrics['edited'], $metrics['not_placed'], $metrics['failed_units'],
                $metrics['cost_usd'], $id]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM check_job WHERE id = ?')->execute([$id]);
    }

    public function books(): array
    {
        return $this->pdo->query('SELECT id, title FROM book ORDER BY title')->fetchAll(\PDO::FETCH_ASSOC);
    }
}
