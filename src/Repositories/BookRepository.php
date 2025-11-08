<?php

namespace App\Repositories;

use App\Models\Book;
use App\Contracts\BookRepositoryInterface;
use PDO;

/**
 * Book Repository
 * Handles database operations for books
 */
class BookRepository extends Repository implements BookRepositoryInterface
{
    protected string $table = 'book';

    /**
     * Get books with filters and pagination
     */
    public function getBooks(
        ?string $searchTitle = null,
        ?string $searchAuthor = null,
        ?int $filterStatus = null,
        ?int $filterInvoice = null,
        int $page = 1,
        string $sortBy = 'id',
        string $sortOrder = 'DESC',
        int $limit = 20
    ): array {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT b.id, b.title, b.pages, b.date_start, b.date_finish,
                       b.id_status, b.id_formating, b.invoice, b.note,
                       s.name as status_name,
                       f.shortname as format_shortname,
                       GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors
                FROM book b
                LEFT JOIN status s ON b.id_status = s.id
                LEFT JOIN formating f ON b.id_formating = f.id
                LEFT JOIN book_author ba ON b.id = ba.id_book
                LEFT JOIN author a ON ba.id_author = a.id
                WHERE 1=1";

        if ($searchTitle) {
            $sql .= " AND b.title LIKE ?";
            $params[] = "%$searchTitle%";
        }

        if ($searchAuthor) {
            $sql .= " AND (a.fname LIKE ? OR a.name LIKE ?)";
            $params[] = "%$searchAuthor%";
            $params[] = "%$searchAuthor%";
        }

        if ($filterStatus !== null) {
            $sql .= " AND b.id_status = ?";
            $params[] = $filterStatus;
        }

        if ($filterInvoice !== null) {
            $sql .= " AND b.invoice = ?";
            $params[] = $filterInvoice;
        }

        $sql .= " GROUP BY b.id";

        // Validate and sanitize sort parameters
        $validSortColumns = ['id', 'title', 'date_start', 'date_finish'];
        $sortBy = in_array($sortBy, $validSortColumns) ? $sortBy : 'id';
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY b.$sortBy $sortOrder LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);

        // Bind parameters
        $paramIndex = 1;
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get books count with filters
     */
    public function getBooksCount(
        ?string $searchTitle = null,
        ?string $searchAuthor = null,
        ?int $filterStatus = null,
        ?int $filterInvoice = null
    ): int {
        $params = [];

        $sql = "SELECT COUNT(DISTINCT b.id) as total
                FROM book b
                LEFT JOIN book_author ba ON b.id = ba.id_book
                LEFT JOIN author a ON ba.id_author = a.id
                WHERE 1=1";

        if ($searchTitle) {
            $sql .= " AND b.title LIKE ?";
            $params[] = "%$searchTitle%";
        }

        if ($searchAuthor) {
            $sql .= " AND (a.fname LIKE ? OR a.name LIKE ?)";
            $params[] = "%$searchAuthor%";
            $params[] = "%$searchAuthor%";
        }

        if ($filterStatus !== null) {
            $sql .= " AND b.id_status = ?";
            $params[] = $filterStatus;
        }

        if ($filterInvoice !== null) {
            $sql .= " AND b.invoice = ?";
            $params[] = $filterInvoice;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get book with authors
     */
    public function findWithAuthors(int $bookId): ?array
    {
        $sql = "SELECT b.*,
                       s.name as status_name,
                       f.name as format_name,
                       f.shortname as format_shortname,
                       GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors
                FROM book b
                LEFT JOIN status s ON b.id_status = s.id
                LEFT JOIN formating f ON b.id_formating = f.id
                LEFT JOIN book_author ba ON b.id = ba.id_book
                LEFT JOIN author a ON ba.id_author = a.id
                WHERE b.id = ?
                GROUP BY b.id";

        return $this->queryOne($sql, [$bookId]);
    }

    /**
     * Get book authors
     */
    public function getBookAuthors(int $bookId): array
    {
        $sql = "SELECT a.id, a.email, CONCAT(a.fname, ' ', a.name) as name
                FROM author a
                JOIN book_author ba ON a.id = ba.id_author
                WHERE ba.id_book = ?";

        return $this->query($sql, [$bookId]);
    }

    /**
     * Add author to book
     */
    public function addAuthor(int $bookId, int $authorId, int $tipId = 1): bool
    {
        $sql = "INSERT INTO book_author (id_book, id_author, id_tip) VALUES (?, ?, ?)";
        return $this->execute($sql, [$bookId, $authorId, $tipId]);
    }

    /**
     * Remove all authors from book
     */
    public function removeAllAuthors(int $bookId): bool
    {
        $sql = "DELETE FROM book_author WHERE id_book = ?";
        return $this->execute($sql, [$bookId]);
    }

    /**
     * Get books by status
     */
    public function getBooksByStatus(int $statusId, ?string $fromDate = null): array
    {
        $sql = "SELECT b.id, b.title, b.pages,
                       GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors,
                       (SELECT h.inserted
                        FROM history h
                        WHERE h.id_book = b.id
                        ORDER BY h.inserted DESC
                        LIMIT 1) as last_status_change
                FROM book b
                LEFT JOIN book_author ba ON b.id = ba.id_book
                LEFT JOIN author a ON ba.id_author = a.id
                WHERE b.id_status = ?";

        $params = [$statusId];

        if ($fromDate) {
            $sql .= " AND b.changed >= ?";
            $params[] = $fromDate;
        }

        $sql .= " GROUP BY b.id ORDER BY b.title";

        return $this->query($sql, $params);
    }

    /**
     * Get books by status without invoice
     */
    public function getBooksByStatusWithoutInvoice(int $statusId, ?string $fromDate = null): array
    {
        $sql = "SELECT b.id, b.title, b.pages,
                       GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors,
                       (SELECT h.inserted
                        FROM history h
                        WHERE h.id_book = b.id
                        ORDER BY h.inserted DESC
                        LIMIT 1) as last_status_change
                FROM book b
                LEFT JOIN book_author ba ON b.id = ba.id_book
                LEFT JOIN author a ON ba.id_author = a.id
                WHERE b.id_status = ? AND b.invoice = 0";

        $params = [$statusId];

        if ($fromDate) {
            $sql .= " AND b.changed >= ?";
            $params[] = $fromDate;
        }

        $sql .= " GROUP BY b.id ORDER BY b.title";

        return $this->query($sql, $params);
    }

    /**
     * Get total pages by status
     */
    public function getTotalPagesByStatus(int $statusId, ?string $fromDate = null): int
    {
        $sql = "SELECT COALESCE(SUM(pages), 0) FROM book WHERE id_status = ?";
        $params = [$statusId];

        if ($fromDate) {
            $sql .= " AND changed >= ?";
            $params[] = $fromDate;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
