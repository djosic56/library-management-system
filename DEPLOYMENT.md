# Deployment Upute

## Struktura Projekta

```
/
├── index.php, books.php, authors.php...    # UI layer
├── config.php                               # DB konfiguracija
├── functions.php                            # Legacy helpers
├── bootstrap.php                            # App inicijalizacija
├── autoload.php                             # Custom PSR-4 autoloader
└── src/
    ├── Database/Database.php
    ├── Models/                              # Book, Author, User
    ├── Repositories/                        # Data access layer
    ├── Services/                            # Business logic
    └── Validators/                          # Input validation
```

## Deployment na Server

### 1. Priprema
Uredi `config.php`:
```php
define('DB_NAME', 'jsistem_apcl');  // Produkcijska baza
```

### 2. Upload
- Kopiraj sve fajlove na server (FTP/SFTP)
- Struktura: `src/` folder + svi root PHP fajlovi

### 3. Baza podataka
Provjeri da postoji tablica `login_attempt`:
```sql
CREATE TABLE IF NOT EXISTS `login_attempt` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4. Test
Otvori stranicu i testiraj login.

## Autoloader

**autoload.php** - Custom PSR-4 autoloader (bez Composera):
- Automatski učitava klase iz `src/`
- `App\Services\BookService` → `src/Services/BookService.php`
- Nema potrebe za `vendor/` ili Composer instalacijom

## Troubleshooting

**Class not found:**
- Provjeri da postoji `autoload.php` i `bootstrap.php`

**Database error:**
- Provjeri DB credentials u `config.php`

**HTTP 500:**
- Dodaj na vrh `index.php`:
  ```php
  error_reporting(E_ALL);
  ini_set('display_errors', 1);
  ```
