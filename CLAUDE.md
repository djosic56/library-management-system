# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.
# Projekt Standardi

## Tech Stack
- Frontend: HTML5/CSS3/JavaScript, Bootstrap 5+
- Backend: PHP 8+, MySQL
- Jezik: Hrvatski

## Coding Standardi
- Implementiraj proper error handling i input validaciju
- PHP best practices za type safety
- Bootstrap 5+ komponente

## Struktura projekta



## Project Overview

**Library Management System** - PHP-based web application for managing books, authors, and publishing workflow.

- **Type:** Publishing/Editorial workflow management
- **Stack:** PHP 8+ with PDO, MySQL, Bootstrap 5, jQuery
- **Database:** `jsistem_ap` (local) / `jsistem_apcl` (production server)
- **Server:** Windows environment (D:\home\sites\j-sistem\web\ap_claude)

## Database Configuration

Database connection is configured in `config.php`:
- Local: `jsistem_ap` database
- Production: `jsistem_apcl` database
- Connection: PDO with UTF-8 charset
- User constants: `USER_LEVEL_ADMIN = 1`, `USER_LEVEL_USER = 2`

### Key Database Tables

- `users` - User accounts (admin/regular users)
- `book` - Books with status, pages, dates, formatting, invoice flag
- `author` - Author information (fname, name, email)
- `book_author` - Many-to-many relationship between books and authors
- `status` - Book workflow statuses (editing, correction, finished, etc.)
- `formating` - Book format types
- `history` - Book status change history
- `user_log` - Action logging
- `login_attempt` - Login attempt tracking for rate limiting

**IMPORTANT:** The `login_attempt` table was missing on production server initially. If deploying to new environment, ensure this table exists (see structure in `functions.php` comments or use provided SQL).

## Architecture & File Structure

### Core Files

- **config.php** - Database connection, session configuration, constants
- **functions.php** - Shared utility functions (auth, CSRF, validation, data retrieval)
- **header.php** - Navigation bar (shows Admin menu only for level 1 users)
- **footer.php** - Page footer
- **login.php** - User authentication with rate limiting and CSRF protection

### Main Application Pages

- **index.php** - Dashboard with book status overview, counts, and quick actions
- **books.php** - Book management (CRUD operations, search, filtering, pagination)
- **authors.php** - Author management (CRUD operations, search, pagination)
- **statistics.php** - Statistics by status, year, and format (admin only)
- **manage_users.php** - User management (admin only)
- **users.php** - User activity logs (admin only)
- **backup.php** - Database backup functionality (admin only)
- **change_password.php** - Password change for logged-in users

### Data Export Files

- **export.php** - Export functionality
- **export_books_csv.php** - CSV export for books
- **get_book_history.php** - Retrieve book status history

## Key Functions (functions.php)

### Authentication & Authorization

- `require_login()` - Redirect to login if not authenticated
- `is_admin()` - Check if user has admin privileges (level === 1)
- `require_admin()` - Require admin access or redirect
- `check_login_attempts($username, $ip)` - Rate limiting check
- `log_login_attempt($username, $user_id, $ip, $success)` - Log login attempts

**CRITICAL:** `is_admin()` uses strict comparison `(int)$_SESSION['level'] === USER_LEVEL_ADMIN` to prevent type coercion issues.

### Security Functions

- `generate_csrf_token()` - Generate CSRF token for forms
- `validate_csrf_token($token)` - Validate CSRF token
- `csrf_field()` - Output hidden CSRF input field
- `verify_csrf()` - Verify CSRF on POST requests (dies on failure)

### Data Functions

- `get_books($search_title, $search_author, $filter_status, $filter_invoice, $page, $sort_by, $sort_order)` - Retrieve books with filters
- `get_books_count(...)` - Count total books matching filters
- `get_statuses()` - Get all book statuses
- `get_formatings()` - Get all formatting types

### Validation & Sanitization

- `validate_email($email)` - Email format validation
- `sanitize_string($input)` - HTML escape and trim input

## Application Workflow

### Book Status Management

Books progress through different statuses tracked in the `status` table. The dashboard (index.php) shows:
- Books by status (1-4) with counts and total pages
- Status 4 books filtered by date (configurable: `$status_filter_date`)
- Status 4 books without invoice (special tracking)
- Email integration to send book lists for corrections/invoicing

### User Roles

**Admin (level = 1):**
- Full CRUD operations on books and authors
- User management
- Statistics and reports
- Database backups
- Sees Admin menu in navigation

**Regular User (level = 2):**
- View books and authors
- Search and filter
- View statistics (if granted access)
- No Admin menu visible

### Book Data

Books can have:
- **Pages:** 0 or positive integer (0 is allowed, negative is not)
- **Dates:** Start date and finish date (finish must be >= start)
- **Status:** Workflow status (editing, correction, finished, etc.)
- **Formatting:** Format type (e.g., epub, pdf, etc.)
- **Invoice:** Boolean flag (0 or 1)
- **Note:** Optional text field
- **Multiple authors:** Many-to-many relationship via `book_author` table

## Security Features

### Session Management

- Sessions configured with HTTPOnly, Strict SameSite cookies
- Session regeneration on login (using `session_regenerate_id(false)` to preserve data)
- Session timeout after 30 minutes of inactivity

### CSRF Protection

- All POST forms include CSRF token via `csrf_field()`
- `verify_csrf()` called at start of POST handlers
- Failure results in HTTP 403 and script termination

### Rate Limiting

- Login attempts tracked in `login_attempt` table
- Max 5 failed attempts per username or IP within 15 minutes
- Lockout message displayed when limit exceeded

### Input Validation

- All user input sanitized and validated
- Prepared statements used for all database queries (prevents SQL injection)
- Email validation using `filter_var()`
- Numeric validation for pages, IDs, etc.

## Common Development Tasks

### Adding a New Book

1. Use "Add Book" modal in books.php
2. Required: Title
3. Optional: Pages (0 or positive integer), dates, status, format, invoice flag, note
4. Authors can be added via autocomplete (searches after 3 characters)
5. Form includes CSRF protection

### Modifying Book Status

Status changes are tracked in the `history` table. When changing status:
1. Update `book.id_status`
2. Insert record into `history` table with timestamp
3. Dashboard automatically reflects changes

### Searching & Filtering

Books page supports:
- Search by title
- Search by author name
- Filter by status
- Filter by invoice flag (0/1)
- Sort by: id, title, date_start, date_finish (ASC/DESC)
- Pagination (20 items per page, configurable via `ITEMS_PER_PAGE`)

### Database Backup

Admin users can create SQL backups via backup.php:
- Generates complete SQL dump with structure and data
- Includes all tables
- Downloads as .sql file with timestamp

## Important Notes

### Local vs Production

When deploying to production server:
1. Update database name in `config.php` if different
2. Ensure `login_attempt` table exists
3. Check file permissions for upload/export functionality
4. Verify session directory is writable

### Error Handling

- Database errors logged via `error_log()`
- User-friendly messages shown to end users
- Detailed errors only in server logs (not exposed to users)

### Email Integration

Dashboard includes mailto: links to send book lists via email:
- Status 3 (corrections) - sends list of books needing correction
- Status 4 without invoice - sends list for invoicing
- URL encoding uses `str_replace('+', '%20', urlencode())` for proper spacing

### Performance Considerations

- Pagination used on all list views to limit query results
- GROUP_CONCAT used for author names in book queries
- Indexes on common search fields (username, ip_address, attempted_at)

## Known Issues & Solutions

### Issue: Admin menu visible to non-admin users
**Solution:** Ensure `is_admin()` uses strict comparison: `(int)$_SESSION['level'] === USER_LEVEL_ADMIN`

### Issue: HTTP 500 on login
**Solution:** Check if `login_attempt` table exists. Create it if missing.

### Issue: Pages field doesn't allow 0
**Solution:** Input fields should have `min="0"` and validation should check `$pages !== '' && $pages !== null && $pages < 0`

### Issue: Session lost after login
**Solution:** Use `session_regenerate_id(false)` instead of `true` to preserve session data

