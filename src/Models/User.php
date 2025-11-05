<?php

namespace App\Models;

/**
 * User Model
 * Represents a user in the library system
 */
class User extends Model
{
    /**
     * Fillable attributes for mass assignment
     */
    protected array $fillable = [
        'username',
        'email',
        'level'
    ];

    /**
     * Hidden attributes (excluded from array/JSON output)
     */
    protected array $hidden = ['password'];

    /**
     * User level constants
     */
    public const LEVEL_ADMIN = 1;
    public const LEVEL_USER = 2;

    /**
     * Get user ID
     */
    public function getId(): ?int
    {
        return $this->getAttribute('id');
    }

    /**
     * Get username
     */
    public function getUsername(): ?string
    {
        return $this->getAttribute('username');
    }

    /**
     * Set username
     */
    public function setUsername(string $username): self
    {
        $this->setAttribute('username', $username);
        return $this;
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
     * Get user level
     */
    public function getLevel(): int
    {
        return (int) $this->getAttribute('level', self::LEVEL_USER);
    }

    /**
     * Set user level
     */
    public function setLevel(int $level): self
    {
        $this->setAttribute('level', $level);
        return $this;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->getLevel() === self::LEVEL_ADMIN;
    }

    /**
     * Check if user is regular user
     */
    public function isUser(): bool
    {
        return $this->getLevel() === self::LEVEL_USER;
    }

    /**
     * Get password hash
     */
    public function getPassword(): ?string
    {
        return $this->getAttribute('password');
    }

    /**
     * Set password hash
     */
    public function setPassword(string $password): self
    {
        $this->setAttribute('password', $password);
        return $this;
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->getPassword());
    }

    /**
     * Hash and set password
     */
    public function hashPassword(string $password): self
    {
        $this->setPassword(password_hash($password, PASSWORD_DEFAULT));
        return $this;
    }
}
