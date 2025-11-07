# Tech Lead Analiza

## 1. PREGLED ARHITEKTURE

### ✅ Što radi dobro:

**Service Layer Pattern**
- Dobra separacija: UI → Service → Repository → Database
- Business logic izolirana u Services
- Testabilnost (već imaš PHPUnit setup)

**Security**
- CSRF protection implementiran
- Rate limiting za login
- Prepared statements (SQL injection zaštita)
- Session management s pravilnim postavkama

**Deployment Strategy**
- Custom autoloader omogućava rad bez Composera
- Dokumentacija prisutna

### ⚠️ Arhitekturni problemi:

**1. Hybrid Legacy/Modern arhitektura**
```php
books.php:
  require_once 'bootstrap.php';  // Modern
  require_once 'functions.php';  // Legacy

  $bookService = getBookService();  // Modern service
  $statuses = get_statuses();       // Legacy function
```
**Rizik:** Konfuzija, duplicirani kod, teže održavanje

**2. Global state**
```php
global $pdo;  // U functions.php I bootstrap.php
```
**Rizik:** Side effects, teško testiranje

**3. Nedostaje Dependency Injection**
```php
function getBookService(): BookService {
    return new BookService(getDatabase());  // Hard-coded dependency
}
```
**Rizik:** Teško mockanje za testove

**4. Mješani responsibility layeri**
- `functions.php` - ima I DB queries I business logic I UI helpers
- UI fajlovi direktno pozivaju I services I legacy funkcije

**5. Nedostaju interfejsi**
```php
class BookService {
    // Nema interface - tight coupling
}
```

**6. Error handling nekonzistentan**
- Negdje try/catch, negdje direktno
- Error messages ponekad exposed user-u

### 🏗️ Preporučena arhitektura (za budućnost):

```
┌─────────────────────────────────────┐
│  Presentation Layer (views/)        │
│  - Pure HTML/PHP templates          │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│  Controller Layer                   │
│  - Request handling                 │
│  - Validation                       │
│  - Response formatting              │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│  Service Layer (business logic)     │
│  - BookService, AuthorService       │
│  - Uses interfaces                  │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│  Repository Layer (data access)     │
│  - BookRepository interface         │
│  - PDO implementation               │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│  Database                           │
└─────────────────────────────────────┘
```

---

## 2. ROADMAP & PRIORITIZACIJA

### 🔴 P0 - KRITIČNO (sljedećih 1-2 tjedna)

**1. Eliminiraj global $pdo**
- Rizik: Hard to debug, test failures
- Akcija: Sve prebaci na Database::getInstance()
- Effort: 2-3h

**2. Standardiziraj error handling**
```php
// Trenutno:
try { ... } catch (Exception $e) { error_log(...); }

// Trebalo bi:
- Custom exception klase
- Centralizirani error handler
- User-friendly vs. developer messages
```
- Effort: 4-6h

**3. Security audit**
- XSS zaštita - provjeri sva output mjesta
- File upload validacija (ako postoji)
- Authorization checks (is_admin() svugdje gdje treba?)
- Effort: 3-4h

### 🟡 P1 - VAŽNO (sljedećih mjesec dana)

**4. Refactor functions.php**
Podijeli u:
```
src/
├── Helpers/
│   ├── SessionHelper.php
│   ├── ValidationHelper.php
│   └── SecurityHelper.php
└── Legacy/
    └── DatabaseHelpers.php  # get_statuses(), get_formatings()
```
- Effort: 6-8h

**5. Dodaj interfejse**
```php
interface BookServiceInterface {
    public function getBooks(...): array;
}

interface BookRepositoryInterface {
    public function findAll(): array;
}
```
- Benefit: Testabilnost, loose coupling
- Effort: 4h

**6. Logging system**
```php
// Umjesto:
error_log("Message");

// Koristiti:
Logger::error("Message", ['context' => ...]);
```
- Monolog ili custom
- Effort: 3-4h

**7. Input validation layer**
- Centraliziraj sve validacije u Validator klase
- Trenutno razasuto po fajlovima
- Effort: 6h

### 🟢 P2 - NICE TO HAVE (sljedeća 2-3 mjeseca)

**8. API layer**
- REST API endpoints za frontend integrations
- JSON responses
- Effort: 10-15h

**9. Caching layer**
```php
// Za get_statuses(), get_formatings()
Cache::remember('statuses', 3600, fn() => ...);
```
- Redis ili file-based
- Effort: 4-6h

**10. Front-end modernizacija**
- Trenutno: jQuery + Bootstrap
- Razmotriti: Alpine.js ili Vue.js za interaktivnost
- Effort: 20-30h

**11. Database migrations**
- Umjesto ručnih SQL skripti
- Version control za DB schema
- Phinx ili custom
- Effort: 6-8h

**12. Automated testing**
- Unit tests za Services
- Integration tests za Repositories
- E2E tests (Playwright/Selenium)
- Effort: ongoing

---

## 📊 Quick Wins (brzo + velik impact):

1. **Eliminiraj global $pdo** (2h, velik benefit za testove)
2. **XSS audit** (3h, security kritično)
3. **Centraliziraj get_statuses/get_formatings** (1h, performance)
4. **Add .env file za credentials** (30min, security)

---

## 🚨 Rizici:

| Rizik | Vjerojatnost | Impact | Mitigation |
|-------|--------------|--------|------------|
| Global state bugs | Srednja | Visok | P0 #1 |
| XSS vulnerabilities | Niska | Kritičan | P0 #3 |
| Tech debt overload | Visoka | Srednji | Incremental refactor |
| Server bez Composer | Niska | Visok | ✅ Riješeno (custom autoloader) |

---

## 💡 Preporuka za sljedeći sprint:

**Tjedan 1-2:**
- P0 #1: Eliminiraj global $pdo
- P0 #3: Security audit

**Tjedan 3-4:**
- P1 #4: Refactor functions.php (50%)
- P1 #7: Input validation

**Paralelno (ongoing):**
- Nove features po potrebi
- Dokumentacija
