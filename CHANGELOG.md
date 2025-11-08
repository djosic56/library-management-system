# Changelog

Sve važne promjene u ovom projektu dokumentirane su ovdje.

---

## [Verzija 2.0.0] - 2025-11-08

### 🎉 **Major Refactoring - Service-Based Architecture**

Kompletna arhitekturna transformacija aplikacije sa poboljšanjima u sigurnosti, maintainability-u i organizaciji koda.

---

## 🔴 **P0 - KRITIČNE IZMJENE**

### P0 #1: Eliminacija Global State
**Commit:** `22f41d3`, `83a8a4e`

**Promjene:**
- Zamijenjen `global $pdo` sa `Database::getInstance()->getConnection()`
- 7 funkcija u `functions.php` refaktorirano
- Dodana automatska inicijalizacija Database singleton-a u `bootstrap.php`

**Benefit:**
- ✅ Eliminiran global state
- ✅ Bolja testabilnost
- ✅ Thread-safe (za buduće skaliranje)

---

### P0 #2: Standardizovano Error Handling
**Commit:** `ecd4aad`

**Nove klase (src/Exceptions/):**
- `AppException` - Base exception sa user/developer messages
- `DatabaseException` - DB greške (500)
- `ValidationException` - Input validacija (400)
- `AuthenticationException` - Auth failures (401)
- `AuthorizationException` - Access denied (403)
- `NotFoundException` - Resource not found (404)
- `ErrorHandler` - Centralizirani exception handler

**Features:**
- Odvojene poruke za usere vs developere
- AJAX-aware (JSON responses)
- Graceful error pages
- Automatsko logiranje

---

### P0 #3: Security Audit i XSS Fix
**Commit:** `a044937`

**Dokumentacija:**
- `SECURITY_AUDIT.md` - Detaljna security analiza

**Ispravljeno:**
- **XSS #1:** `header.php:79` - dodato `htmlspecialchars($_SESSION['username'])`
- **XSS #2:** `index.php:210` - dodato `htmlspecialchars($username)`

**Verifikovano:**
- ✅ CSRF protection - potpuno implementirano
- ✅ SQL Injection - prepared statements svugdje
- ✅ Authorization - strict comparison u `is_admin()`
- ✅ Session security - HTTPOnly, SameSite=Strict

**Ocjena:** 🟢 IZVRSNA

---

## 🟡 **P1 - VAŽNE IZMJENE**

### P1 #4: Refactor functions.php → Helper Klase
**Commit:** `7f3aad8`

**Nove klase (src/Helpers/):**
- `SecurityHelper` - CSRF protection (generate, validate, verify)
- `AuthHelper` - Authentication i authorization
- `ValidationHelper` - Input validation
- `LogHelper` - User action logging

**Promjene:**
- `functions.php` - refaktorirano u wrapper funkcije
- Backward compatibility: ✅ OČUVANA
- 213 linija → organizirano u 4 namjenske klase

---

### P1 #5: Dodati Interfejse
**Commit:** `2005761`

**Novi interfejsi (src/Contracts/):**
- `ServiceInterface` - Base marker interface
- `RepositoryInterface` - CRUD operations
- `BookServiceInterface` - Book business logic contract
- `AuthorServiceInterface` - Author business logic contract
- `BookRepositoryInterface` - Book data access contract
- `AuthorRepositoryInterface` - Author data access contract

**Implementirano:**
- `BookService implements BookServiceInterface`
- `AuthorService implements AuthorServiceInterface`
- `BookRepository implements BookRepositoryInterface`
- `AuthorRepository implements AuthorRepositoryInterface`

**Benefit:**
- Type safety
- Dependency Injection friendly
- Lakše mockanje za testove
- Loose coupling

---

### P1 #6: Custom Logger System
**Commit:** `fddf0f5`

**Nova klasa (src/Logging/):**
- `Logger` - PSR-3 inspired logger

**Features:**
- 8 severity levels (EMERGENCY → DEBUG)
- File logging (`logs/app.log`)
- Context support (JSON)
- Static metode za jednostavno korištenje
- Integration sa ErrorHandler i LogHelper

**Primjer:**
```php
Logger::info('User logged in', ['user_id' => 123]);
Logger::error('DB error', ['code' => 1045]);
```

---

## 📂 **NOVA STRUKTURA PROJEKTA**

```
/
├── src/
│   ├── Contracts/          # Interfejsi
│   ├── Database/           # Database singleton
│   ├── Exceptions/         # Custom exceptions + ErrorHandler
│   ├── Helpers/            # Security, Auth, Validation, Log
│   ├── Logging/            # Logger system
│   ├── Models/             # Data models
│   ├── Repositories/       # Data access layer
│   ├── Services/           # Business logic
│   └── Validators/         # Input validation
│
├── index.php, books.php... # UI layer
├── functions.php           # Legacy wrappers (backward compatible)
├── config.php              # DB config
├── bootstrap.php           # App init (sa Composer)
├── bootstrap-no-composer.php # App init (bez Composer)
├── autoload.php            # Custom PSR-4 autoloader
│
└── Dokumentacija:
    ├── CLAUDE.md           # Projekt upute
    ├── DEPLOYMENT.md       # Deployment guide
    ├── STRUKTURA.md        # Directory struktura
    ├── TECH_LEAD_REPORT.md # Tech lead analiza
    ├── SECURITY_AUDIT.md   # Security report
    └── CHANGELOG.md        # Ovaj fajl
```

---

## 🧪 **TESTOVI**

Kreirani test fajlovi:
- `test_database_connection.php` - Database & funkcije
- `test_error_handler.php` - Exception handling
- `test_helpers.php` - Helper refactoring
- `test_interfaces.php` - Interface implementation
- `test_logger.php` - Logger functionality

**Rezultati:** ✅ SVI TESTOVI PROŠLI

---

## 📦 **DEPLOYMENT**

### arh16.zip (~83 KB)
Sadrži:
- Sve aplikacijske fajlove
- `src/` arhitekturu (Contracts, Helpers, Services, itd.)
- `autoload.php` (custom PSR-4)
- Kompletnu dokumentaciju

### Deployment koraci:
1. Raspakuj `arh16.zip`
2. Uredi `config.php` (promijeni DB ime na `jsistem_apcl`)
3. Upload na server
4. ✅ Radi odmah (ne treba Composer!)

---

## 🔧 **BREAKING CHANGES**

**Nema!** Svi refactoring-i su održali backward compatibility.

Stare funkcije rade (delegiraju na nove klase):
```php
verify_csrf()        → SecurityHelper::verifyCsrf()
require_login()      → AuthHelper::requireLogin()
validate_email($e)   → ValidationHelper::validateEmail($e)
```

---

## 📈 **METRИКЕ**

| Metrika | Prije | Poslije |
|---------|-------|---------|
| Global state | ❌ `global $pdo` | ✅ Singleton |
| Error handling | ⚠️ Nedosledno | ✅ Centralizirano |
| XSS ranjivosti | 🔴 2 | ✅ 0 |
| Klasa organizacija | ❌ Sve u functions.php | ✅ Namespaced Helpers |
| Interfejsi | ❌ Nema | ✅ 6 interfejsa |
| Logging | ⚠️ error_log() | ✅ Custom Logger |
| Test coverage | ❌ 0% | ✅ 5 test fajlova |

---

## 🚀 **FUTURE ROADMAP**

### P1 Tasks (preostalo):
- Input validation layer (centralizacija)
- Content Security Policy (CSP headers)
- Password policy enforcement

### P2 Tasks:
- API layer (REST endpoints)
- Caching layer (Redis/File-based)
- Database migrations system
- 2FA za admin usere

---

## 👥 **CONTRIBUTORS**

- Danko Josić - danko.josic@gmail.com
- Claude Code - AI pair programmer

---

## 📝 **LICENCE**

Internal project - j-sistem.hr

---

**Zadnja ažuriranost:** 2025-11-08
**Verzija:** 2.0.0
**Git commits:** 7 major commits (22f41d3 → fddf0f5)
