<?php

namespace App\Repositories;

use PDO;

/**
 * Author Repository
 * Handles database operations for authors
 */
class AuthorRepository extends Repository
{
    protected string $table = 'author';

    /**
     * Get authors with search and pagination
     */
    public function getAuthors(
        ?string $search = null,
        int $page = 1,
        string $sortBy = 'id',
        string $sortOrder = 'DESC',
        int $limit = 20
    ): array {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT a.id, a.name, a.fname, a.email,
                       GROUP_CONCAT(b.title ORDER BY b.title SEPARATOR ', ') as books
                FROM author a
                LEFT JOIN book_author ba ON a.id = ba.id_author
                LEFT JOIN book b ON ba.id_book = b.id
                WHERE 1=1";

        if ($search) {
            $sql .= " AND (a.name LIKE ? OR a.fname LIKE ? OR a.email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY a.id";

        // Validate and sanitize sort parameters
        $validSortColumns = ['id', 'name', 'fname', 'email'];
        $sortBy = in_array($sortBy, $validSortColumns) ? $sortBy : 'id';
        $sortOrder = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

        $sql .= " ORDER BY a.$sortBy $sortOrder LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);

        // Bind parameters
        $paramIndex = 1;
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get authors count with search
     */
    public function getAuthorsCount(?string $search = null): int
    {
        $params = [];
        $sql = "SELECT COUNT(*) FROM author WHERE 1=1";

        if ($search) {
            $sql .= " AND (name LIKE ? OR fname LIKE ? OR email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Search authors by name (for autocomplete)
     */
    public function searchByName(string $query, int $limit = 10): array
    {
        $sql = "SELECT id, CONCAT(fname, ' ', name) as label
                FROM author
                WHERE (fname LIKE ? OR name LIKE ?)
                ORDER BY fname ASC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(2, "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get recent authors
     */
    public function getRecent(int $limit = 5): array
    {
        $sql = "SELECT id, CONCAT(fname, ' ', name) as name
                FROM author
                ORDER BY id DESC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Check if author has books
     */
    public function hasBooks(int $authorId): bool
    {
        $sql = "SELECT COUNT(*) FROM book_author ba
                JOIN book b ON ba.id_book = b.id
                WHERE ba.id_author = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$authorId]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get book count for author
     */
    public function getBookCount(int $authorId): int
    {
        $sql = "SELECT COUNT(*) FROM book_author ba
                JOIN book b ON ba.id_book = b.id
                WHERE ba.id_author = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$authorId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get author's books
     */
    public function getBooks(int $authorId): array
    {
        $sql = "SELECT b.*
                FROM book b
                JOIN book_author ba ON b.id = ba.id_book
                WHERE ba.id_author = ?
                ORDER BY b.title";

        return $this->query($sql, [$authorId]);
    }
}
