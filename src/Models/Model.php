<?php

namespace App\Models;

use JsonSerializable;

/**
 * Base Model class
 * Provides common functionality for all models
 */
abstract class Model implements JsonSerializable
{
    /**
     * Model attributes
     */
    protected array $attributes = [];

    /**
     * Original attributes (for dirty checking)
     */
    protected array $original = [];

    /**
     * Fillable attributes (mass assignment protection)
     */
    protected array $fillable = [];

    /**
     * Guarded attributes (mass assignment protection)
     */
    protected array $guarded = ['id'];

    /**
     * Hidden attributes (excluded from JSON/Array conversion)
     */
    protected array $hidden = [];

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->syncOriginal();
    }

    /**
     * Fill model with attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Check if attribute is fillable
     */
    protected function isFillable(string $key): bool
    {
        // If fillable is specified, check if key is in it
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable);
        }

        // Otherwise, check if it's not guarded
        return !in_array($key, $this->guarded);
    }

    /**
     * Set attribute value
     */
    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get attribute value
     */
    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if attribute exists
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Get all attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Sync original attributes
     */
    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Check if model is dirty (has changes)
     */
    public function isDirty(string $key = null): bool
    {
        if ($key !== null) {
            return ($this->original[$key] ?? null) !== ($this->attributes[$key] ?? null);
        }

        return $this->original !== $this->attributes;
    }

    /**
     * Get dirty attributes
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if ($this->isDirty($key)) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Convert model to array
     */
    public function toArray(): array
    {
        $array = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden)) {
                $array[$key] = $value;
            }
        }
        return $array;
    }

    /**
     * Convert model to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * JsonSerializable implementation
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Magic getter
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Magic setter
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic isset
     */
    public function __isset(string $key): bool
    {
        return $this->hasAttribute($key);
    }

    /**
     * Magic unset
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}
