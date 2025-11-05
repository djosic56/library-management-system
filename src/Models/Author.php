<?php

namespace App\Models;

/**
 * Author Model
 * Represents an author in the library system
 */
class Author extends Model
{
    /**
     * Fillable attributes for mass assignment
     */
    protected array $fillable = [
        'name',
        'fname',
        'email'
    ];

    /**
     * Get author ID
     */
    public function getId(): ?int
    {
        return $this->getAttribute('id');
    }

    /**
     * Get last name
     */
    public function getName(): ?string
    {
        return $this->getAttribute('name');
    }

    /**
     * Set last name
     */
    public function setName(string $name): self
    {
        $this->setAttribute('name', $name);
        return $this;
    }

    /**
     * Get first name
     */
    public function getFirstName(): ?string
    {
        return $this->getAttribute('fname');
    }

    /**
     * Set first name
     */
    public function setFirstName(string $fname): self
    {
        $this->setAttribute('fname', $fname);
        return $this;
    }

    /**
     * Get full name
     */
    public function getFullName(): string
    {
        return trim($this->getFirstName() . ' ' . $this->getName());
    }

    /**
     * Get email
     */
    public function getEmail(): ?string
    {
        return $this->getAttribute('email');
    }

    /**
     * Set email
     */
    public function setEmail(?string $email): self
    {
        $this->setAttribute('email', $email);
        return $this;
    }

    /**
     * Check if author has email
     */
    public function hasEmail(): bool
    {
        return !empty($this->getEmail());
    }
}
