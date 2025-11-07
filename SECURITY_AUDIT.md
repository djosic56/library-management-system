# Security Audit Report
**Datum:** 2025-11-07
**Verzija:** P0 #3

---

## Sažetak

| Kategorija | Status | Kritičnost | Broj problema |
|-----------|--------|------------|---------------|
| XSS Zaštita | ⚠️ Parcijalno | **VISOKA** | 2 |
| CSRF Zaštita | ✅ Dobro | Niska | 0 |
| Authorization | ✅ Dobro | Niska | 0 |
| SQL Injection | ✅ Dobro | Niska | 0 |
| Session Security | ✅ Dobro | Niska | 0 |

---

## 🔴 KRITIČNO: XSS Ranjivosti

### 1. Username u navigaciji (header.php:79)

**Lokacija:** `header.php:79`

**Problem:**
```php
<i class="bi bi-person-circle"></i> <?=$_SESSION['username']?> (<?=$_SESSION['level']?>)
```

**Rizik:**
- Ako admin kreira usera sa malicioznim username-om (npr. `<script>alert('XSS')</script>`), kod će se izvršiti u browseru svakog usera
- Stored XSS - izvršava se svaki put kad se učita stranica

**Rješenje:**
```php
<i class="bi bi-person-circle"></i> <?=htmlspecialchars($_SESSION['username'])?> (<?=$_SESSION['level']?>)
```

**Prioritet:** 🔴 **P0 - KRITIČNO**

---

### 2. Username na dashboard-u (index.php:210)

**Lokacija:** `index.php:210`

**Problem:**
```php
<h1><i class="bi bi-book"></i> Welcome, <?php echo $username; ?>!</h1>
```

**Rizik:**
- Isti kao gornji - Stored XSS preko username-a

**Rješenje:**
```php
<h1><i class="bi bi-book"></i> Welcome, <?php echo htmlspecialchars($username); ?>!</h1>
```

**Prioritet:** 🔴 **P0 - KRITIČNO**

---

## ✅ Dobro implementirano

### CSRF Zaštita

**Status:** ✅ **Potpuno implementirano**

**Provjera:**
```bash
grep -r "verify_csrf()" *.php
```

**Rezultat:**
- `login.php` - ✅
- `books.php` - ✅
- `authors.php` - ✅
- `manage_users.php` - ✅
- `backup.php` - ✅
- `change_password.php` - ✅

Sve POST operacije koriste `verify_csrf()`.

**functions.php implementacija:**
```php
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) ||
            !validate_csrf_token($_POST['csrf_token'])) {
            http_response_code(403);
            die('Invalid CSRF token');
        }
    }
}
```

---

### Authorization

**Status:** ✅ **Dobro implementirano**

**Admin-only stranice:**
- `backup.php` - ✅ `require_admin()`
- `manage_users.php` - ✅ `require_admin()`
- `users.php` - ✅ `require_admin()`
- `statistics.php` - ✅ `require_admin()`

**functions.php implementacija:**
```php
function is_admin() {
    return isset($_SESSION['level']) &&
           (int)$_SESSION['level'] === USER_LEVEL_ADMIN; // Strict comparison ✅
}

function require_admin() {
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}
```

**Napomena:** Koristi strict comparison `===` - sprječava type juggling napade ✅

---

### SQL Injection

**Status:** ✅ **Potpuno zaštićeno**

**Provjera:**
- Svi DB upiti koriste **prepared statements** sa PDO
- Parametri se bindaju preko `?` ili named placeholders
- Nema string concatenation-a u SQL upitima

**Primjer (BookRepository.php:74):**
```php
$stmt = $this->db->prepare($sql);
foreach ($params as $value) {
    $stmt->bindValue($paramIndex++, $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
```

---

### Session Security

**Status:** ✅ **Dobro konfigurirano**

**config.php postavke:**
```php
ini_set('session.cookie_httponly', 1);  // ✅ XSS zaštita
ini_set('session.use_strict_mode', 1);  // ✅ Session fixation zaštita
ini_set('session.cookie_samesite', 'Strict'); // ✅ CSRF zaštita
```

**Dodatno:**
- Session timeout: 30 minuta (implementirano u config.php)
- `session_regenerate_id()` nakon logina ✅

---

## 📋 Preporuke

### ODMAH (P0):
1. ✅ **Ispraviti XSS u header.php** - `htmlspecialchars($_SESSION['username'])`
2. ✅ **Ispraviti XSS u index.php** - `htmlspecialchars($username)`

### Uskoro (P1):
3. **Content Security Policy (CSP)** - dodati header:
   ```php
   header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net;");
   ```

4. **Rate limiting za login** - ✅ VEĆ IMPLEMENTIRANO (MAX_LOGIN_ATTEMPTS)

5. **Password policy** - enforce minimalna duljina/kompleksnost pri kreiranju/promjeni passworda

### Kasnije (P2):
6. **2FA** - Two-factor authentication za admin usere
7. **Security headers**:
   ```php
   header("X-Frame-Options: DENY");
   header("X-Content-Type-Options: nosniff");
   header("Referrer-Policy: strict-origin-when-cross-origin");
   ```

---

## Test Plan

### XSS Test:
1. Kreiraj usera sa username-om: `<script>alert('XSS')</script>`
2. Logiraj se kao taj user
3. Provjeri da li se script izvršava (PRIJE fixa - DA, POSLIJE - NE)

### CSRF Test:
1. Pokušaj POST request bez CSRF tokena
2. Očekivani rezultat: HTTP 403 ✅

### Authorization Test:
1. Logiraj se kao regular user (level=2)
2. Pokušaj pristup `/backup.php`
3. Očekivani rezultat: Redirect na index.php ✅

---

## Zaključak

**Ukupna ocjena:** 🟡 **Dobra sa manjim popravkama**

Aplikacija ima **solidnu security osnovu**:
- ✅ CSRF protection
- ✅ SQL injection prevention
- ✅ Authorization checks
- ✅ Session security

**Hitne izmjene:**
- ⚠️ 2 XSS ranjivosti (lako se ispravlja)

Nakon ispravki, ocjena: 🟢 **Izvrsna**
