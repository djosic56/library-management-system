<?php

namespace App\Contracts;

/**
 * Book Repository Interface
 * Defines data access operations for books
 */
interface BookRepositoryInterface extends RepositoryInterface
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
     * Get books count with filters
     */
    public function getBooksCount(
        ?string $searchTitle = null,
        ?string $searchAuthor = null,
        ?int $filterStatus = null,
        ?int $filterInvoice = null
    ): int;

    /**
     * Find book with authors
     */
    public function findWithAuthors(int $bookId): ?array;

    /**
     * Get book authors
     */
    public function getBookAuthors(int $bookId): array;

    /**
     * Add author to book
     */
    public function addAuthor(int $bookId, int $authorId): bool;

    /**
     * Remove all authors from book
     */
    public function removeAllAuthors(int $bookId): bool;

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
}
