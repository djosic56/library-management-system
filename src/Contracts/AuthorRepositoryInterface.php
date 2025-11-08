<?php

namespace App\Contracts;

/**
 * Author Repository Interface
 * Defines data access operations for authors
 */
interface AuthorRepositoryInterface extends RepositoryInterface
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
     * Search authors by name
     */
    public function searchByName(string $query): array;

    /**
     * Get recent authors
     */
    public function getRecent(int $limit = 5): array;
}
