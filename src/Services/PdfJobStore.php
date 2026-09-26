<?php

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * PDF poslovi na disku (~/pdf_jobs/<id>/). Ne radi PDF obradu — samo JSON i pokretanje
 * Python runnera. Stanje obrade piše runner u status.json.
 */
class PdfJobStore
{
    public const MAX_BYTES = 200 * 1024 * 1024;
    public const STALE_SECONDS = 300;
    private const RUNNING = ['queued', 'phase1', 'phase2'];

    /** @var callable */
    private $launcher;

    public function __construct(private string $jobsRoot, ?callable $launcher = null)
    {
        $this->launcher = $launcher ?? [self::class, 'launchRunner'];
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }

    /**
     * POST veći od post_max_size: PHP odbaci i $_POST i $_FILES (pa i csrf_token) — prepoznaj
     * to prije CSRF provjere, da korisnik dobije „prevelik“ umjesto „CSRF 403“.
     */
    public static function isOversizedPost(array $server, array $post, array $files): bool
    {
        return empty($post) && empty($files) && (int) ($server['CONTENT_LENGTH'] ?? 0) > 0;
    }

    public function dir(string $id): string
    {
        $dir = $this->jobsRoot . '/' . $id;
        if (!self::isValidId($id) || !is_dir($dir)) {
            throw new NotFoundException('pdf_job', 0);
        }
        return $dir;
    }

    public function create(array $upload, string $name, ?string $title, ?string $lang, ?int $bookId): string
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw self::invalid('pdf', 'The PDF is too large — maximum 200 MB');
        }
        if ($error !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_file($upload['tmp_name'])) {
            throw self::invalid('pdf', 'Upload failed — please try again');
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            throw self::invalid('pdf', 'The PDF is too large — maximum 200 MB');
        }
        if (file_get_contents($upload['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            throw self::invalid('pdf', 'The file is not a PDF');
        }
        if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            throw self::invalid('lang', 'Invalid language code');
        }

        $id = bin2hex(random_bytes(16));
        $dir = $this->jobsRoot . '/' . $id;
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Ne mogu napraviti direktorij posla');
        }
        $target = $dir . '/source.pdf';
        $moved = is_uploaded_file($upload['tmp_name'])
            ? move_uploaded_file($upload['tmp_name'], $target)
            : rename($upload['tmp_name'], $target);
        if (!$moved) {
            throw new \RuntimeException('Ne mogu spremiti PDF');
        }
        $this->writeJson($dir . '/job.json', [
            'name' => $name, 'title' => $title ?: null, 'lang' => $lang ?: null, 'book_id' => $bookId,
        ]);
        $this->writeJson($dir . '/status.json', ['state' => 'queued', 'created' => self::now()]);
        return $id;
    }

    public function start(string $id, string $phase): void
    {
        if (!in_array($phase, ['phase1', 'phase2'], true)) {
            throw self::invalid('phase', 'Unknown phase');
        }
        $state = $this->status($id)['state'] ?? '';
        if (in_array($state, self::RUNNING, true) && isset($this->status($id)['queued_at'])) {
            throw self::invalid('phase', 'Processing is already running');
        }
        if (in_array($state, ['phase1', 'phase2'], true)) {
            throw self::invalid('phase', 'Processing is already running');
        }
        // Očisti tragove prethodne faze: stari heartbeat bi izgledao kao mrtav proces, a stari
        // PDF/UA rezultat kao rezultat nove obrade
        $this->mergeJson($this->dir($id) . '/status.json', [
            'state' => 'queued', 'queued_at' => self::now(), 'message' => null,
            'heartbeat' => null, 'pid' => null, 'finished' => null, 'progress' => null,
            'pdfua_ok' => null, 'pdfua_failed' => null,
        ]);
        ($this->launcher)($id, $phase);
    }

    public function status(string $id): array
    {
        $path = $this->dir($id) . '/status.json';
        $status = $this->readJson($path, ['state' => 'queued']);
        if (in_array($status['state'] ?? '', self::RUNNING, true)) {
            $times = array_filter(array_map(fn ($k) => isset($status[$k]) ? strtotime($status[$k]) : null,
                ['heartbeat', 'queued_at', 'created']));
            $last = $times ? max($times) : null;
            if ($last === null || time() - $last > self::STALE_SECONDS) {
                $status = $this->mergeJson($path, [
                    'state' => 'failed',
                    'message' => 'Processing was interrupted (the process is not running). Please start it again.',
                ]);
            }
        }
        return $status;
    }

    public function job(string $id): array
    {
        return $this->readJson($this->dir($id) . '/job.json', []);
    }

    public function descriptions(string $id): array
    {
        return $this->readJson($this->dir($id) . '/descriptions.json', ['images' => []]);
    }

    public function updateImage(string $id, int $xref, array $fields): array
    {
        $this->assertEditable($id);
        $clean = [];
        if (array_key_exists('description', $fields)) {
            $clean['description'] = mb_substr(trim((string) $fields['description']), 0, 2000);
        }
        if (array_key_exists('decorative', $fields)) {
            $clean['decorative'] = filter_var($fields['decorative'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('language', $fields)) {
            $lang = $fields['language'];
            if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}$/', (string) $lang)) {
                throw self::invalid('language', 'Invalid language code');
            }
            $clean['language'] = ($lang === '' ? null : $lang);
        }

        $updated = null;
        $this->withLockedJson($this->dir($id) . '/descriptions.json', function (array $data) use ($xref, $clean, &$updated) {
            foreach ($data['images'] ?? [] as $i => $image) {
                if ((int) $image['xref'] === $xref) {
                    $data['images'][$i] = array_merge($image, $clean);
                    $updated = $data['images'][$i];
                    return $data;
                }
            }
            throw new NotFoundException('pdf_image', $xref);
        });
        return $updated;
    }

    public function updateDocument(string $id, ?string $title, ?string $lang): array
    {
        $this->assertEditable($id);
        if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            throw self::invalid('lang', 'Invalid language code');
        }
        $job = null;
        $this->withLockedJson($this->dir($id) . '/job.json', function (array $data) use ($title, $lang, &$job) {
            $data['title'] = ($title === null || trim($title) === '') ? null : mb_substr(trim($title), 0, 500);
            $data['lang'] = $lang ?: null;
            $data['title_source'] = 'user';   // korisnik ga je upisao/potvrdio — više nije prijedlog
            return $job = $data;
        });
        return $job;
    }

    public function filePath(string $id, string $kind): string
    {
        $dir = $this->dir($id);
        if ($kind === 'output') {
            $path = $dir . '/output.pdf';
        } elseif ($kind === 'report') {
            $path = $dir . '/report.html';
        } elseif (preg_match('/^thumb:(\d+)$/', $kind, $m)) {
            $path = $dir . '/thumbs/' . $m[1] . '.jpg';
        } else {
            throw new NotFoundException('pdf_file', 0);
        }
        if (!is_file($path)) {
            throw new NotFoundException('pdf_file', 0);
        }
        return $path;
    }

    public function delete(string $id): void
    {
        $dir = $this->dir($id);
        if (in_array($this->status($id)['state'] ?? '', self::RUNNING, true)) {
            throw self::invalid('state', 'A job cannot be deleted while it is processing');
        }
        $this->removeTree($dir);
    }

    /** Default launcher: runner u pozadini, odvojen od web zahtjeva. */
    public static function launchRunner(string $id, string $phase): void
    {
        $jobDir = PDF_JOBS_ROOT . '/' . $id;
        // izlaz procesa zasebno: log.txt piše Python (na Windowsu dva pisača istog fajla = PermissionError)
        $log = $jobDir . '/runner_output.txt';
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start "" /B %s %s %s %s >> %s 2>&1',
                escapeshellarg(PDF_PYTHON), escapeshellarg(PDF_TOOL_DIR . '/job_runner.py'),
                $phase, escapeshellarg($jobDir), escapeshellarg($log));
        } else {
            $cmd = sprintf('cd %s && setsid nohup %s job_runner.py %s %s >> %s 2>&1 < /dev/null &',
                escapeshellarg(PDF_TOOL_DIR), escapeshellarg(PDF_PYTHON), $phase,
                escapeshellarg($jobDir), escapeshellarg($log));
        }
        $proc = proc_open($cmd, [], $pipes, PDF_TOOL_DIR);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Ne mogu pokrenuti obradu');
        }
        proc_close($proc);
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return new ValidationException($field, $message, 422);
    }

    private function assertEditable(string $id): void
    {
        if (in_array($this->status($id)['state'] ?? '', ['queued', 'phase2'], true)) {
            throw self::invalid('state', 'The PDF is being built — edits are not possible until it finishes');
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s.000000\Z');
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : $default;
    }

    private function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    private function mergeJson(string $path, array $fields): array
    {
        $result = [];
        $this->withLockedJson($path, function (array $data) use ($fields, &$result) {
            return $result = array_merge($data, $fields);
        });
        return $result;
    }

    /** flock na .lock uz fajl, pa tmp + rename — spremanje s dva uređaja ne kvari JSON. */
    private function withLockedJson(string $path, callable $change): void
    {
        $lock = fopen($path . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->writeJson($path, $change($this->readJson($path, [])));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? $this->removeTree($p) : unlink($p);
        }
        rmdir($dir);
    }
}
