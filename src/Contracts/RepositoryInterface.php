<?php

namespace App\Contracts;

/**
 * Base Repository Interface
 * Defines common CRUD operations
 */
interface RepositoryInterface
{
    /**
     * Find record by ID
     */
    public function find(int $id): ?array;

    /**
     * Find all records
     */
    public function findAll(): array;

    /**
     * Insert new record
     */
    public function insert(array $data): int;

    /**
     * Update existing record
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete record
     */
    public function delete(int $id): bool;
}
