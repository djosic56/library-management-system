<?php

namespace App\Models;

/**
 * Book Model
 * Represents a book in the library system
 */
class Book extends Model
{
    /**
     * Fillable attributes for mass assignment
     */
    protected array $fillable = [
        'title',
        'pages',
        'date_start',
        'date_finish',
        'id_status',
        'id_formating',
        'invoice',
        'note',
        'changed'
    ];

    /**
     * Hidden attributes (excluded from array/JSON output)
     */
    protected array $hidden = [];

    /**
     * Get book ID
     */
    public function getId(): ?int
    {
        return $this->getAttribute('id');
    }

    /**
     * Get book title
     */
    public function getTitle(): ?string
    {
        return $this->getAttribute('title');
    }

    /**
     * Set book title
     */
    public function setTitle(string $title): self
    {
        $this->setAttribute('title', $title);
        return $this;
    }

    /**
     * Get number of pages
     */
    public function getPages(): ?int
    {
        return $this->getAttribute('pages');
    }

    /**
     * Set number of pages
     */
    public function setPages(?int $pages): self
    {
        $this->setAttribute('pages', $pages);
        return $this;
    }

    /**
     * Get start date
     */
    public function getDateStart(): ?string
    {
        return $this->getAttribute('date_start');
    }

    /**
     * Set start date
     */
    public function setDateStart(?string $date): self
    {
        $this->setAttribute('date_start', $date);
        return $this;
    }

    /**
     * Get finish date
     */
    public function getDateFinish(): ?string
    {
        return $this->getAttribute('date_finish');
    }

    /**
     * Set finish date
     */
    public function setDateFinish(?string $date): self
    {
        $this->setAttribute('date_finish', $date);
        return $this;
    }

    /**
     * Get status ID
     */
    public function getStatusId(): ?int
    {
        return $this->getAttribute('id_status');
    }

    /**
     * Set status ID
     */
    public function setStatusId(?int $statusId): self
    {
        $this->setAttribute('id_status', $statusId);
        return $this;
    }

    /**
     * Get formatting ID
     */
    public function getFormatingId(): ?int
    {
        return $this->getAttribute('id_formating');
    }

    /**
     * Set formatting ID
     */
    public function setFormatingId(?int $formatingId): self
    {
        $this->setAttribute('id_formating', $formatingId);
        return $this;
    }

    /**
     * Get invoice flag
     */
    public function getInvoice(): int
    {
        return (int) $this->getAttribute('invoice', 0);
    }

    /**
     * Set invoice flag
     */
    public function setInvoice(int $invoice): self
    {
        $this->setAttribute('invoice', $invoice);
        return $this;
    }

    /**
     * Check if book has invoice
     */
    public function hasInvoice(): bool
    {
        return $this->getInvoice() === 1;
    }

    /**
     * Get note
     */
    public function getNote(): ?string
    {
        return $this->getAttribute('note');
    }

    /**
     * Set note
     */
    public function setNote(?string $note): self
    {
        $this->setAttribute('note', $note);
        return $this;
    }

    /**
     * Get changed timestamp
     */
    public function getChanged(): ?string
    {
        return $this->getAttribute('changed');
    }
}
