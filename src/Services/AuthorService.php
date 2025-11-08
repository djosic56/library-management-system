<?php

namespace App\Services;

use App\Database\Database;
use App\Models\Author;
use App\Repositories\AuthorRepository;
use App\Validators\Validator;
use App\Contracts\AuthorServiceInterface;

/**
 * Author Service
 * Handles business logic for author operations
 */
class AuthorService implements AuthorServiceInterface
{
    private AuthorRepository $authorRepository;
    private Validator $validator;

    public function __construct(Database $database)
    {
        $this->authorRepository = new AuthorRepository($database);
        $this->validator = new Validator();
    }

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
        return $this->authorRepository->getAuthors($search, $page, $sortBy, $sortOrder, $limit);
    }

    /**
     * Get authors count
     */
    public function getAuthorsCount(?string $search = null): int
    {
        return $this->authorRepository->getAuthorsCount($search);
    }

    /**
     * Get author by ID
     */
    public function getAuthor(int $authorId): ?array
    {
        return $this->authorRepository->find($authorId);
    }

    /**
     * Search authors by name (for autocomplete)
     */
    public function searchByName(string $query, int $limit = 10): array
    {
        if (strlen($query) < 3) {
            return [];
        }

        return $this->authorRepository->searchByName($query, $limit);
    }

    /**
     * Get recent authors
     */
    public function getRecent(int $limit = 5): array
    {
        return $this->authorRepository->getRecent($limit);
    }

    /**
     * Validate author data
     */
    public function validateAuthor(array $data, bool $isEdit = false): bool
    {
        $this->validator->clearErrors();

        // Validate first name
        if (!$this->validator->required($data['fname'] ?? '', 'First name')) {
            return false;
        }

        if (!empty($data['fname']) && !$this->validator->length($data['fname'], 1, 100, 'First name')) {
            return false;
        }

        // Validate last name
        if (!$this->validator->required($data['name'] ?? '', 'Last name')) {
            return false;
        }

        if (!empty($data['name']) && !$this->validator->length($data['name'], 1, 100, 'Last name')) {
            return false;
        }

        // Validate email (optional)
        if (!empty($data['email']) && !$this->validator->email($data['email'], 'Email')) {
            return false;
        }

        return $this->validator->passes();
    }

    /**
     * Create new author
     */
    public function createAuthor(array $data): ?int
    {
        if (!$this->validateAuthor($data)) {
            return null;
        }

        try {
            $authorData = [
                'name' => trim($data['name']),
                'fname' => trim($data['fname']),
                'email' => !empty($data['email']) ? trim($data['email']) : null,
            ];

            return $this->authorRepository->insert($authorData);
        } catch (\Exception $e) {
            error_log("Author creation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update author
     */
    public function updateAuthor(int $authorId, array $data): bool
    {
        if (!$this->validateAuthor($data, true)) {
            return false;
        }

        try {
            $authorData = [
                'name' => trim($data['name']),
                'fname' => trim($data['fname']),
                'email' => !empty($data['email']) ? trim($data['email']) : null,
            ];

            return $this->authorRepository->update($authorId, $authorData);
        } catch (\Exception $e) {
            error_log("Author update failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete author
     */
    public function deleteAuthor(int $authorId): bool
    {
        try {
            // Check if author has books
            if ($this->authorRepository->hasBooks($authorId)) {
                $this->validator->errors['author'] = "Cannot delete author with associated books.";
                return false;
            }

            return $this->authorRepository->delete($authorId);
        } catch (\Exception $e) {
            error_log("Author deletion failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if author has books
     */
    public function hasBooks(int $authorId): bool
    {
        return $this->authorRepository->hasBooks($authorId);
    }

    /**
     * Get book count for author
     */
    public function getBookCount(int $authorId): int
    {
        return $this->authorRepository->getBookCount($authorId);
    }

    /**
     * Get author's books
     */
    public function getBooks(int $authorId): array
    {
        return $this->authorRepository->getBooks($authorId);
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
