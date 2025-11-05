<?php

namespace App\Services;

use App\Database\Database;
use App\Models\Book;
use App\Repositories\BookRepository;
use App\Validators\Validator;

/**
 * Book Service
 * Handles business logic for book operations
 */
class BookService
{
    private BookRepository $bookRepository;
    private Validator $validator;
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
        $this->bookRepository = new BookRepository($database);
        $this->validator = new Validator();
    }

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
        return $this->bookRepository->getBooks(
            $searchTitle,
            $searchAuthor,
            $filterStatus,
            $filterInvoice,
            $page,
            $sortBy,
            $sortOrder,
            $limit
        );
    }

    /**
     * Get books count
     */
    public function getBooksCount(
        ?string $searchTitle = null,
        ?string $searchAuthor = null,
        ?int $filterStatus = null,
        ?int $filterInvoice = null
    ): int {
        return $this->bookRepository->getBooksCount(
            $searchTitle,
            $searchAuthor,
            $filterStatus,
            $filterInvoice
        );
    }

    /**
     * Get book by ID
     */
    public function getBook(int $bookId): ?array
    {
        return $this->bookRepository->findWithAuthors($bookId);
    }

    /**
     * Get book authors
     */
    public function getBookAuthors(int $bookId): array
    {
        return $this->bookRepository->getBookAuthors($bookId);
    }

    /**
     * Validate book data
     */
    public function validateBook(array $data, bool $isEdit = false): bool
    {
        $this->validator->clearErrors();

        // Validate title
        if (!$this->validator->required($data['title'] ?? '', 'Title')) {
            return false;
        }

        if (!empty($data['title']) && !$this->validator->length($data['title'], 1, 255, 'Title')) {
            return false;
        }

        // Validate pages (optional, but must be >= 0 if provided)
        if (isset($data['pages']) && $data['pages'] !== '' && $data['pages'] !== null) {
            if (!$this->validator->numeric($data['pages'], 'Pages')) {
                return false;
            }
            if (!$this->validator->min($data['pages'], 0, 'Pages')) {
                return false;
            }
        }

        // Validate dates
        if (!empty($data['date_start']) && !$this->validator->date($data['date_start'], 'Y-m-d', 'Start date')) {
            return false;
        }

        if (!empty($data['date_finish']) && !$this->validator->date($data['date_finish'], 'Y-m-d', 'Finish date')) {
            return false;
        }

        // Validate date range
        if (!$this->validator->dateRange($data['date_start'] ?? null, $data['date_finish'] ?? null)) {
            return false;
        }

        return $this->validator->passes();
    }

    /**
     * Create new book
     */
    public function createBook(array $data, array $authorIds = []): ?int
    {
        if (!$this->validateBook($data)) {
            return null;
        }

        try {
            $this->database->beginTransaction();

            // Prepare book data
            $bookData = [
                'title' => $data['title'],
                'pages' => $data['pages'] ?: null,
                'date_start' => $data['date_start'] ?: null,
                'date_finish' => $data['date_finish'] ?: null,
                'id_status' => $data['id_status'] ?: null,
                'id_formating' => $data['id_formating'] ?: null,
                'invoice' => $data['invoice'] ?? 0,
                'note' => $data['note'] ?: null,
            ];

            // Insert book
            $bookId = $this->bookRepository->insert($bookData);

            // Add authors
            if (!empty($authorIds)) {
                foreach ($authorIds as $authorId) {
                    $this->bookRepository->addAuthor($bookId, $authorId);
                }
            }

            // Add history if status is set
            if (!empty($data['id_status'])) {
                $this->addHistory($bookId, $data['id_status']);
            }

            $this->database->commit();

            return $bookId;
        } catch (\Exception $e) {
            if ($this->database->inTransaction()) {
                $this->database->rollback();
            }
            error_log("Book creation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update book
     */
    public function updateBook(int $bookId, array $data, array $authorIds = []): bool
    {
        if (!$this->validateBook($data, true)) {
            return false;
        }

        try {
            $this->database->beginTransaction();

            // Get old status for history tracking
            $oldBook = $this->bookRepository->find($bookId);
            $oldStatus = $oldBook['id_status'] ?? null;

            // Prepare book data
            $bookData = [
                'title' => $data['title'],
                'pages' => $data['pages'] ?: null,
                'date_start' => $data['date_start'] ?: null,
                'date_finish' => $data['date_finish'] ?: null,
                'id_status' => $data['id_status'] ?: null,
                'id_formating' => $data['id_formating'] ?: null,
                'invoice' => $data['invoice'] ?? 0,
                'note' => $data['note'] ?: null,
            ];

            // Update book
            $this->bookRepository->update($bookId, $bookData);

            // Update authors
            $this->bookRepository->removeAllAuthors($bookId);
            if (!empty($authorIds)) {
                foreach ($authorIds as $authorId) {
                    $this->bookRepository->addAuthor($bookId, $authorId);
                }
            }

            // Add history if status changed
            $newStatus = $data['id_status'] ?? null;
            if ($newStatus && $oldStatus != $newStatus) {
                $this->addHistory($bookId, $newStatus);
            }

            $this->database->commit();

            return true;
        } catch (\Exception $e) {
            if ($this->database->inTransaction()) {
                $this->database->rollback();
            }
            error_log("Book update failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete book
     */
    public function deleteBook(int $bookId): bool
    {
        try {
            return $this->bookRepository->delete($bookId);
        } catch (\Exception $e) {
            error_log("Book deletion failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add book history record
     */
    private function addHistory(int $bookId, int $statusId): void
    {
        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare("INSERT INTO history (id_book, id_status) VALUES (?, ?)");
        $stmt->execute([$bookId, $statusId]);
    }

    /**
     * Get books by status
     */
    public function getBooksByStatus(int $statusId, ?string $fromDate = null): array
    {
        return $this->bookRepository->getBooksByStatus($statusId, $fromDate);
    }

    /**
     * Get books by status without invoice
     */
    public function getBooksByStatusWithoutInvoice(int $statusId, ?string $fromDate = null): array
    {
        return $this->bookRepository->getBooksByStatusWithoutInvoice($statusId, $fromDate);
    }

    /**
     * Get total pages by status
     */
    public function getTotalPagesByStatus(int $statusId, ?string $fromDate = null): int
    {
        return $this->bookRepository->getTotalPagesByStatus($statusId, $fromDate);
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->validator->getErrors();
    }

    /**
     * Get first validation error
     */
    public function getFirstError(): ?string
    {
        return $this->validator->getFirstError();
    }
}
