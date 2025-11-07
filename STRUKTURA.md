# Struktura Direktorija

## 📂 ROOT - Glavni PHP fajlovi

```
index.php           # Dashboard
books.php           # Upravljanje knjigama
authors.php         # Upravljanje autorima
login.php/logout.php
statistics.php      # Admin statistike
manage_users.php    # Admin - korisnici
users.php           # Admin - logovi
backup.php          # Admin - backup DB
change_password.php

config.php          # DB konfiguracija
functions.php       # Legacy helper funkcije
bootstrap.php       # App inicijalizacija
autoload.php        # Custom PSR-4 autoloader
```

## 📂 src/ - Service arhitektura

```
src/
├── Database/
│   └── Database.php         # PDO singleton connection
│
├── Models/                  # Data objekti
│   ├── Model.php           # Base model
│   ├── Book.php
│   ├── Author.php
│   └── User.php
│
├── Repositories/            # Database queries
│   ├── Repository.php      # Base repository
│   ├── BookRepository.php
│   ├── AuthorRepository.php
│   └── UserRepository.php
│
├── Services/                # Business logic
│   ├── BookService.php
│   └── AuthorService.php
│
└── Validators/
    └── Validator.php        # Input validacija
```

## 📂 Ostali direktoriji

```
arh/                # Deployment arhive (arh15.zip...)
.claude/            # Claude Code config
config/             # App konfiguracija
tests/              # PHPUnit testovi
vendor/             # Composer dependencies (ne committa se)
deployment-no-composer/  # Temp folder
```

## 📂 Dokumentacija

```
CLAUDE.md              # Projekt upute za Claude
DEPLOYMENT.md          # Deployment upute
README_REFACTORED.md   # Refactoring dokumentacija
QUICK_REFERENCE.md     # Brzi pregled
STRUKTURA.md           # Struktura direktorija (ovaj fajl)
```

---

## Kako radi:

**Request flow:**
```
books.php
  → bootstrap.php (učitava autoload.php + config.php)
  → getBookService()
    → BookService
      → BookRepository
        → Database (PDO)
```

**Autoloader:**
- `App\Services\BookService` → `src/Services/BookService.php`
- Nema potrebe za `require_once` - automatski!
