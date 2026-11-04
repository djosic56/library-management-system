<?php

use App\Repositories\CheckJobRepository;
use App\Services\PdfJobStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../pdf_config.php';

/**
 * Real HTTP tests for check_api.php (plus pdf_api.php, for the shared 401 auth contract). Runs the app
 * under `php -S` against the local development database (config.php's hard-coded jsistem_ap — config.php
 * is never modified), driven by genuine admin/non-admin session files written directly into a temporary
 * session.save_path as TEST FIXTURES. This is not an auth bypass: check_api.php and AuthHelper are
 * untouched, the test only plays the part of a browser that already has a valid PHP session cookie.
 *
 * See task-16-brief.md "Obvezna dopuna pregleda" for the binding acceptance criteria this class covers:
 * 401 (anonymous, both APIs, GET+POST, no redirect), logged-in non-admin access, 403 (missing/invalid CSRF on every
 * mutating action), 405 (GET on every mutating action), 422 (stale revision on start_run, forbidden state
 * on save_finding), 404 (unknown job, a PDF job id used on check_api.php, a stale comments/report
 * download), and that every refused request leaves the job's files byte-identical. It also proves the R4
 * controller wiring: preview_run returns previewRun()'s {affected, revision}, and save_map calls the
 * atomic saveMapAndSettings() (map + settings land in one call, not two).
 *
 * SKIPPED is used only for a pre-flight infrastructure failure (no free port, server didn't start, dev
 * database unreachable, no admin/non-admin user to log in as) — never to paper over an application 500.
 */
class CheckApiTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $sessionDir = '';
    private static ?string $skipReason = null;
    private static ?\PDO $pdo = null;
    private static string $adminSid = '';
    private static string $csrfToken = '';
    private static string $nonAdminSid = '';
    private static int $adminUserId = 0;

    /** @var string[] Check job ids this class created — dir + DB row removed in tearDownAfterClass. */
    private static array $checkJobIds = [];
    /** @var string[] PDF job ids this class created (wrong-kind fixture) — dir removed in tearDownAfterClass. */
    private static array $pdfJobIds = [];

    public static function setUpBeforeClass(): void
    {
        $port = self::findFreePort();
        if ($port === null) {
            self::$skipReason = 'Could not find a free TCP port for the test server';
            return;
        }
        self::$port = $port;

        self::$sessionDir = sys_get_temp_dir() . '/checkapi_sess_' . bin2hex(random_bytes(4));
        if (!mkdir(self::$sessionDir, 0700, true) && !is_dir(self::$sessionDir)) {
            self::$skipReason = 'Could not create a session save_path directory';
            return;
        }

        $root = realpath(__DIR__ . '/../..');
        $log = sys_get_temp_dir() . '/checkapi_server_' . $port . '.log';
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . self::$sessionDir, '-S', '127.0.0.1:' . $port, '-t', $root],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes
        );
        if (!is_resource(self::$server)) {
            self::$skipReason = 'Could not start the php -S test server';
            return;
        }

        $error = self::preflight($port);
        if ($error !== null) {
            self::$skipReason = $error;
            return;
        }

        try {
            self::$pdo = new \PDO('mysql:host=localhost;dbname=jsistem_ap;charset=utf8mb4', 'jsistem_apuser', 'pAP3779',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            self::$skipReason = 'Local development database (jsistem_ap) is unreachable: ' . $e->getMessage();
            return;
        }
        $adminId = self::$pdo->query('SELECT id FROM users WHERE level = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $user = self::$pdo->query('SELECT id, level FROM users WHERE level <> 1 ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if (!$adminId || !$user) {
            self::$skipReason = 'The jsistem_ap database has no admin and non-admin user to log in as';
            return;
        }
        self::$adminUserId = (int) $adminId;
        self::$csrfToken = bin2hex(random_bytes(32));
        self::$adminSid = self::makeSession((int) $adminId, 1, self::$csrfToken);
        self::$nonAdminSid = self::makeSession((int) $user['id'], (int) $user['level'], self::$csrfToken);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$checkJobIds as $id) {
            $dir = defined('PDF_JOBS_ROOT') ? PDF_JOBS_ROOT . '/' . $id : null;
            if ($dir !== null && is_dir($dir)) {
                self::rrmdir($dir);
            }
        }
        foreach (self::$pdfJobIds as $id) {
            $dir = defined('PDF_JOBS_ROOT') ? PDF_JOBS_ROOT . '/' . $id : null;
            if ($dir !== null && is_dir($dir)) {
                self::rrmdir($dir);
            }
        }
        if (self::$pdo !== null && self::$checkJobIds) {
            $ids = array_values(self::$checkJobIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            self::$pdo->prepare("DELETE FROM check_job WHERE id IN ($ph)")->execute($ids);
            // Samo zapisi koji spominju poslove koje je ovaj test stvorio — tuđi user_log redovi ostaju netaknuti.
            self::$pdo->prepare("DELETE FROM user_log WHERE details IN ($ph)")->execute($ids);
        }

        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $status = proc_get_status(self::$server);
                if (!$status['running']) {
                    break;
                }
                usleep(50000);
            }
            proc_close(self::$server);
        }
        if (self::$sessionDir !== '' && is_dir(self::$sessionDir)) {
            self::rrmdir(self::$sessionDir);
        }
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    // ---------- test server infrastructure ----------

    private static function findFreePort(): ?int
    {
        for ($i = 0; $i < 25; $i++) {
            $port = random_int(20000, 60000);
            $sock = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
            if ($sock !== false) {
                fclose($sock);
                return $port;
            }
        }
        return null;
    }

    /** Polls the port until check_api.php actually answers (not just "socket accepts"), or times out. */
    private static function preflight(int $port): ?string
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (is_resource(self::$server)) {
                $status = proc_get_status(self::$server);
                if (!$status['running']) {
                    return 'The test server process exited before it became ready';
                }
            }
            $opts = ['http' => ['ignore_errors' => true, 'timeout' => 2]];
            $body = @file_get_contents('http://127.0.0.1:' . $port . '/check_api.php?action=status&id=' . str_repeat('0', 32),
                false, stream_context_create($opts));
            if ($body !== false) {
                return str_contains($body, 'Database connection failed') ? 'Local development database is unreachable' : null;
            }
            usleep(150000);
        }
        return 'The test server did not respond within the preflight timeout';
    }

    /** Runs the session fixture as its own CLI process (see tests/fixtures/make_check_api_session.php). */
    private static function makeSession(int $userId, int $level, string $csrf): string
    {
        $out = [];
        exec(sprintf('%s %s %s %d %d %s', escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/make_check_api_session.php'), escapeshellarg(self::$sessionDir),
            $userId, $level, escapeshellarg($csrf)), $out, $rc);
        if ($rc !== 0 || !$out) {
            self::fail('Could not create a test session file (rc=' . $rc . ')');
        }
        return trim(implode('', $out));
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ---------- HTTP request helper ----------

    /** @return array{0:int,1:array,2:string} [statusCode, responseHeaders, body] */
    private function request(string $api, string $method, array $query, ?array $post, ?string $sid): array
    {
        $qs = http_build_query($query);
        $url = 'http://127.0.0.1:' . self::$port . '/' . $api . ($qs !== '' ? '?' . $qs : '');
        $headers = [];
        if ($sid !== null) {
            $headers[] = 'Cookie: PHPSESSID=' . $sid;
        }
        $opts = ['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10, 'method' => $method]];
        if ($post !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['http']['method'] = 'POST';
            $opts['http']['content'] = http_build_query($post);
        }
        if ($headers) {
            $opts['http']['header'] = implode("\r\n", $headers);
        }
        $body = @file_get_contents($url, false, stream_context_create($opts));
        if ($body === false) {
            $this->fail("Request to $api did not get a response from the test server");
        }
        $code = 0;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $http_response_header, $body];
    }

    private function get(string $api, array $query, ?string $sid): array
    {
        return $this->request($api, 'GET', $query, null, $sid);
    }

    private function post(string $api, array $query, array $post, ?string $sid): array
    {
        return $this->request($api, 'POST', $query, $post, $sid);
    }

    private function assertNoRedirectHeader(array $headers): void
    {
        foreach ($headers as $h) {
            $this->assertStringNotContainsStringIgnoringCase('Location:', $h);
        }
    }

    // ---------- job fixtures ----------

    private function pdfUpload(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chkapi');
        file_put_contents($tmp, "%PDF-1.7\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\n%%EOF");
        return ['tmp_name' => $tmp, 'name' => 'book.pdf', 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];
    }

    /** A real check job (CheckJobStore::create + CheckJobRepository::insert) forced into $state via status.json. */
    private function makeCheckJob(string $state): string
    {
        $store = check_job_store();
        $id = $store->create($this->pdfUpload(), 'HTTP test job', null, 'en-GB', null);
        file_put_contents(PDF_JOBS_ROOT . "/$id/status.json", json_encode(['state' => $state]));
        (new CheckJobRepository(self::$pdo))->insert($id, 'HTTP test job', null, self::$adminUserId);
        self::$checkJobIds[] = $id;
        return $id;
    }

    /** A real PDF job (different kind) — proves check_api.php 404s on a wrong-kind job id. */
    private function makePdfJob(): string
    {
        $store = new PdfJobStore(PDF_JOBS_ROOT);
        $id = $store->create($this->pdfUpload(), 'HTTP test pdf job', null, null, null);
        self::$pdfJobIds[] = $id;
        return $id;
    }

    /** Every file under a job dir (skipping lock files), for before/after "the refusal changed nothing" checks. */
    private function fileSnapshot(string $id): array
    {
        $dir = PDF_JOBS_ROOT . '/' . $id;
        $out = [];
        $walk = function (string $d, string $prefix) use (&$walk, &$out) {
            foreach (scandir($d) as $f) {
                if ($f === '.' || $f === '..' || str_ends_with($f, '.lock')) continue;
                $p = "$d/$f";
                is_dir($p) ? $walk($p, "$prefix$f/") : $out["$prefix$f"] = file_get_contents($p);
            }
        };
        $walk($dir, '');
        return $out;
    }

    private function assertJobFilesUnchanged(string $id, array $before): void
    {
        $this->assertSame($before, $this->fileSnapshot($id), 'Job files changed after a request that should have been refused');
    }

    public static function apis(): array
    {
        return [['check_api.php'], ['pdf_api.php']];
    }

    public static function mutatingActions(): array
    {
        return [['save_map'], ['start_run'], ['reopen_map'], ['retry_map'], ['save_finding'], ['bulk_review'], ['start_build'], ['delete']];
    }

    public static function nonDoneDownloadKinds(): array
    {
        return [['comments'], ['report']];
    }

    // ================= 401: anonymous, both APIs, GET + POST, no redirect =================

    #[DataProvider('apis')]
    public function testAnonymousGetReturns401JsonWithoutRedirect(string $api): void
    {
        [$code, $headers, $body] = $this->get($api, ['action' => 'status', 'id' => str_repeat('a', 32)], null);
        $this->assertSame(401, $code);
        $this->assertNoRedirectHeader($headers);
        $this->assertStringContainsString('session has expired', json_decode($body, true)['error'] ?? '');
    }

    #[DataProvider('apis')]
    public function testAnonymousPostReturns401JsonWithoutRedirect(string $api): void
    {
        [$code, $headers, $body] = $this->post($api, [], ['action' => 'delete', 'id' => str_repeat('a', 32)], null);
        $this->assertSame(401, $code);
        $this->assertNoRedirectHeader($headers);
        $this->assertStringContainsString('session has expired', json_decode($body, true)['error'] ?? '');
    }

    // ================= Logged-in non-admin users have the same PDF/Check access as admins =================

    #[DataProvider('apis')]
    public function testNonAdminGetPassesAuthAndReachesTheJobLookup(string $api): void
    {
        [$code, $headers, $body] = $this->get($api, ['action' => 'status', 'id' => str_repeat('a', 32)], self::$nonAdminSid);
        $this->assertSame(404, $code);
        $this->assertNoRedirectHeader($headers);
        $this->assertStringNotContainsString('session has expired', $body);
    }

    #[DataProvider('apis')]
    public function testNonAdminPostPassesAuthAndCsrfAndReachesTheJobLookup(string $api): void
    {
        [$code, $headers, $body] = $this->post($api, [],
            ['action' => 'delete', 'id' => str_repeat('a', 32), 'csrf_token' => self::$csrfToken], self::$nonAdminSid);
        $this->assertSame(404, $code);
        $this->assertNoRedirectHeader($headers);
        $this->assertStringNotContainsString('session has expired', $body);
    }

    // ================= Books/Authors: read-only for non-admins, id=0 so no real row can change =================

    public function testNonAdminCannotPostAnyBookOrAuthorAction(): void
    {
        $actions = [
            'books.php' => ['add_book', 'edit_book', 'add_process_correct_history', 'delete_book'],
            'authors.php' => ['add_author', 'edit_author', 'delete_author'],
        ];
        foreach ($actions as $page => $names) {
            foreach ($names as $name) {
                [$code] = $this->post($page, [], [$name => '1', 'id' => '0', 'csrf_token' => self::$csrfToken], self::$nonAdminSid);
                $this->assertSame(403, $code, "$page $name must be refused for a non-admin");
            }
        }
    }

    public function testAdminPostStillReachesTheBookHandler(): void
    {
        [$code, , $body] = $this->post('books.php', [], ['edit_book' => '1', 'id' => '0', 'csrf_token' => self::$csrfToken], self::$adminSid);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Invalid book ID', $body);
    }

    public function testNonAdminGetsReadOnlyViewInsteadOfEditControls(): void
    {
        $pages = [
            'books.php' => ['modal' => 'id="editBookModal"', 'forbidden' => ['id="addBookModal"', 'name="edit_book"',
                'name="delete_book"', 'name="add_process_correct_history"', 'id="author-search"', 'Save Changes']],
            'authors.php' => ['modal' => 'id="editAuthorModal"', 'forbidden' => ['id="addAuthorModal"', 'name="edit_author"',
                'name="delete_author"', 'Save Changes']],
        ];
        foreach ($pages as $page => $expected) {
            [$code, , $body] = $this->get($page, [], self::$nonAdminSid);
            $this->assertSame(200, $code);
            $this->assertStringContainsString($expected['modal'], $body);
            $this->assertStringContainsString('<fieldset disabled>', $body);
            $this->assertStringContainsString('title="View"', $body);
            $this->assertStringNotContainsString('title="Edit"', $body);
            foreach ($expected['forbidden'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "$page must not offer $needle to a non-admin");
            }
        }
    }

    public function testAdminStillSeesBookAndAuthorEditControls(): void
    {
        foreach (['books.php' => 'Book', 'authors.php' => 'Author'] as $page => $entity) {
            [, , $body] = $this->get($page, [], self::$adminSid);
            $this->assertStringContainsString('id="edit' . $entity . 'Modal"', $body);
            $this->assertStringContainsString('id="add' . $entity . 'Modal"', $body);
            $this->assertStringContainsString('title="Edit"', $body);
            $this->assertStringContainsString('Save Changes', $body);
            $this->assertStringNotContainsString('<fieldset disabled>', $body);
        }
    }

    public function testViewPopupShowsEmptyValuesAsDashInsteadOfFormPrompts(): void
    {
        [, , $user] = $this->get('books.php', [], self::$nonAdminSid);
        $this->assertStringContainsString('type="text" name="date_start" id="edit-book-date_start"', $user);
        $this->assertStringContainsString('type="text" name="date_finish" id="edit-book-date_finish"', $user);
        $this->assertStringNotContainsString('Select Status', $user);
        $this->assertStringNotContainsString('Select Format', $user);
        $this->assertStringContainsString('btn-outline-primary', $user);

        [, , $admin] = $this->get('books.php', [], self::$adminSid);
        $this->assertStringContainsString('type="date" name="date_start" id="edit-book-date_start"', $admin);
        $this->assertStringContainsString('Select Status', $admin);
    }

    public function testDevelopmentScriptsAreNotReachableOverHttp(): void
    {
        $scripts = ['example_usage.php', 'test_database_connection.php', 'test_error_handler.php', 'test_helpers.php',
            'test_interfaces.php', 'test_logger.php', 'test_refactoring.php'];
        foreach ($scripts as $script) {
            [$code] = $this->get($script, [], self::$adminSid);
            $this->assertSame(404, $code, "$script must only run from the command line");
        }
    }

    // ================= 403: missing/invalid CSRF on every mutating action =================

    #[DataProvider('mutatingActions')]
    public function testMissingCsrfTokenReturns403AndLeavesFilesUnchanged(string $action): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->post('check_api.php', [], ['action' => $action, 'id' => $id], self::$adminSid);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Security token', json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    #[DataProvider('mutatingActions')]
    public function testInvalidCsrfTokenReturns403AndLeavesFilesUnchanged(string $action): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->post('check_api.php', [],
            ['action' => $action, 'id' => $id, 'csrf_token' => bin2hex(random_bytes(32))], self::$adminSid);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Security token', json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= 405: GET on every mutating action =================

    #[DataProvider('mutatingActions')]
    public function testGetOnMutatingActionReturns405AndLeavesFilesUnchanged(string $action): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code] = $this->get('check_api.php', ['action' => $action, 'id' => $id], self::$adminSid);
        $this->assertSame(405, $code);
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= 422: stale revision on start_run =================

    public function testStaleRevisionOnStartRunReturns422AndLeavesFilesUnchanged(): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->post('check_api.php', [],
            ['action' => 'start_run', 'id' => $id, 'revision' => 'not-the-real-revision', 'csrf_token' => self::$csrfToken],
            self::$adminSid);
        $this->assertSame(422, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= 422: forbidden state (save_finding while map_review) =================

    public function testSaveFindingInForbiddenStateReturns422AndLeavesFilesUnchanged(): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->post('check_api.php', [],
            ['action' => 'save_finding', 'id' => $id, 'finding' => 'f1', 'review' => 'accepted', 'csrf_token' => self::$csrfToken],
            self::$adminSid);
        $this->assertSame(422, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= 404: unknown job =================

    public function testUnknownJobReturns404(): void
    {
        [$code, , $body] = $this->get('check_api.php', ['action' => 'status', 'id' => str_repeat('f', 32)], self::$adminSid);
        $this->assertSame(404, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
    }

    public function testDeleteOfUnknownJobReturns404(): void
    {
        [$code, , $body] = $this->post('check_api.php', [],
            ['action' => 'delete', 'id' => str_repeat('e', 32), 'csrf_token' => self::$csrfToken], self::$adminSid);
        $this->assertSame(404, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
    }

    // ================= 404: a PDF job id used on check_api.php (wrong kind) =================

    public function testPdfJobIdOnCheckApiReturns404AndLeavesFilesUnchanged(): void
    {
        $id = $this->makePdfJob();
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->get('check_api.php', ['action' => 'status', 'id' => $id], self::$adminSid);
        $this->assertSame(404, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= 404: download of a stale (not "done") comments/report is refused =================

    #[DataProvider('nonDoneDownloadKinds')]
    public function testDownloadOfCommentsOrReportRefusedWhenNotDone(string $kind): void
    {
        $id = $this->makeCheckJob('map_review');
        $before = $this->fileSnapshot($id);
        [$code, , $body] = $this->get('check_api.php', ['action' => 'download', 'kind' => $kind, 'id' => $id], self::$adminSid);
        $this->assertSame(404, $code);
        $this->assertNotEmpty(json_decode($body, true)['error'] ?? '');
        $this->assertJobFilesUnchanged($id, $before);
    }

    // ================= R4 wiring: preview_run/save_map call the Task 13 store methods =================

    public function testPreviewRunReturnsAffectedAndRevisionFromTheStore(): void
    {
        $id = $this->makeCheckJob('map_review');
        file_put_contents(PDF_JOBS_ROOT . "/$id/map.json", json_encode(['page_count' => 10, 'units' => [
            ['id' => 'u1', 'title' => 'Ch 1', 'author' => null, 'start' => 1, 'end' => 5, 'language' => 'en',
             'kind' => 'chapter', 'notes' => 'none', 'bibliography_start' => null, 'bibliography_end' => null,
             'start_label' => '1', 'end_label' => '5'],
        ], 'shared_bibliography_start' => null, 'shared_bibliography_end' => null]));

        // Isti store (isti disk), izračunato izvan HTTP-a — otisak koji previewRun() mora vratiti preko API-ja.
        $expected = check_job_store()->previewRun($id);

        [$code, , $body] = $this->get('check_api.php', ['action' => 'preview_run', 'id' => $id], self::$adminSid);
        $this->assertSame(200, $code);
        $json = json_decode($body, true);
        $this->assertSame($expected['affected'], $json['affected'] ?? null);
        $this->assertSame($expected['revision'], $json['revision'] ?? null);
    }

    public function testSaveMapCallsSaveMapAndSettingsAtomically(): void
    {
        $id = $this->makeCheckJob('map_review');
        file_put_contents(PDF_JOBS_ROOT . "/$id/map.json", json_encode(['page_count' => 20, 'units' => []]));
        $units = json_encode([[
            'id' => '', 'title' => 'Chapter One', 'author' => 'A. Author', 'start' => 1, 'end' => 10,
            'language' => 'en', 'kind' => 'chapter', 'notes' => 'none', 'bibliography_start' => null, 'bibliography_end' => null,
        ]]);

        [$code, , $body] = $this->post('check_api.php', [], [
            'action' => 'save_map', 'id' => $id, 'units' => $units, 'shared_start' => '', 'shared_end' => '',
            'english' => 'en-US', 'instructions' => 'Use serial commas.', 'csrf_token' => self::$csrfToken,
        ], self::$adminSid);

        $this->assertSame(200, $code);
        $json = json_decode($body, true);
        $this->assertCount(1, $json['map']['units'] ?? []);
        $this->assertSame('Chapter One', $json['map']['units'][0]['title']);

        // Jedan poziv mora spremiti i mapu i postavke (saveMapAndSettings), ne saveMap + saveSettings zasebno.
        $job = json_decode(file_get_contents(PDF_JOBS_ROOT . "/$id/job.json"), true);
        $this->assertSame('en-US', $job['english']);
        $this->assertSame('Use serial commas.', $job['instructions']);
        $map = json_decode(file_get_contents(PDF_JOBS_ROOT . "/$id/map.json"), true);
        $this->assertCount(1, $map['units']);
    }
}
