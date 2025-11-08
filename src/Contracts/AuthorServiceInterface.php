<?php

namespace App\Contracts;

/**
 * Author Service Interface
 * Defines business logic operations for authors
 */
interface AuthorServiceInterface extends ServiceInterface
{
    /**
     * Get authors with filters and pagination
     */
    public function getAuthors(
        ?string $search = null,
        int $page = 1,
        string $sortBy = 'id',
        string $sortOrder = 'DESC',
        int $limit = 20
    ): array;

    /**
     * Get authors count
     */
    public function getAuthorsCount(?string $search = null): int;

    /**
     * Get author by ID
     */
    public function getAuthor(int $authorId): ?array;

    /**
     * Search authors by name
     */
    public function searchByName(string $query): array;

    /**
     * Get recent authors
     */
    public function getRecent(int $limit = 5): array;

    /**
     * Validate author data
     */
    public function validateAuthor(array $data, bool $isEdit = false): bool;

    /**
     * Create new author
     */
    public function createAuthor(array $data): ?int;

    /**
     * Update author
     */
    public function updateAuthor(int $authorId, array $data): bool;

    /**
     * Delete author
     */
    public function deleteAuthor(int $authorId): bool;

    /**
     * Get validation errors
     */
    public function getErrors(): array;

    /**
     * Get first validation error
     */
    public function getFirstError(): ?string;
}
