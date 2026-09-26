CREATE TABLE IF NOT EXISTS `pdf_job` (
  `id` CHAR(32) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `book_id` INT NULL,
  `state` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `images` INT NULL,
  `cost_usd` DECIMAL(8,4) NULL,
  `pdfua_ok` TINYINT(1) NULL,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pdf_job_book` (`book_id`),
  CONSTRAINT `fk_pdf_job_book` FOREIGN KEY (`book_id`) REFERENCES `book` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
