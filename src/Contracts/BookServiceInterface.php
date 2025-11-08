<?php

namespace App\Contracts;

/**
 * Book Service Interface
 * Defines business logic operations for books
 */
interface BookServiceInterface extends ServiceInterface
{
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
    ): array;

    /**
     * Get books count
     */
    public function getBooksCount(
        ?string $searchTitle = null,
        ?string $searchAuthor = null,
        ?int $filterStatus = null,
        ?int $filterInvoice = null
    ): int;

    /**
     * Get book by ID
     */
    public function getBook(int $bookId): ?array;

    /**
     * Get book authors
     */
    public function getBookAuthors(int $bookId): array;

    /**
     * Validate book data
     */
    public function validateBook(array $data, bool $isEdit = false): bool;

    /**
     * Create new book
     */
    public function createBook(array $data, array $authorIds = []): ?int;

    /**
     * Update book
     */
    public function updateBook(int $bookId, array $data, array $authorIds = []): bool;

    /**
     * Delete book
     */
    public function deleteBook(int $bookId): bool;

    /**
     * Get books by status
     */
    public function getBooksByStatus(int $statusId, ?string $fromDate = null): array;

    /**
     * Get books by status without invoice
     */
    public function getBooksByStatusWithoutInvoice(int $statusId, ?string $fromDate = null): array;

    /**
     * Get total pages by status
     */
    public function getTotalPagesByStatus(int $statusId, ?string $fromDate = null): int;

    /**
     * Get validation errors
     */
    public function getErrors(): array;

    /**
     * Get first validation error
     */
    public function getFirstError(): ?string;
}
