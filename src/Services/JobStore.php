<?php

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Poslovi na disku (<jobsRoot>/<id>/) zajednički za PDF i Check: ID, JSON uz lock, pokretanje
 * Python runnera, otkrivanje prekinutih procesa. Stanje obrade piše runner u status.json.
 */
abstract class JobStore
{
    public const MAX_BYTES = 200 * 1024 * 1024;
    public const STALE_SECONDS = 300;
    protected const KIND = '';
    protected const PHASES = [];
    protected const RUNNING = ['queued'];
    protected const EDIT_LOCKED = ['queued'];

    /** @var callable */
    private $launcher;

    public function __construct(protected string $jobsRoot, ?callable $launcher = null)
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
            throw new NotFoundException('job', 0);
        }
        // PDF i Check poslovi dijele korijen — posao druge vrste se ne otvara (stari PDF poslovi nemaju kind)
        if (($this->readJson($dir . '/job.json', [])['kind'] ?? 'pdf') !== static::KIND) {
            throw new NotFoundException('job', 0);
        }
        return $dir;
    }

    /** @var array<string,bool> poslovi čiji upravljački lock ovaj proces već drži (ugniježđeni pozivi) */
    private array $heldLocks = [];

    /**
     * Upravljačke operacije jednog posla (provjera stanja + zapis + pokretanje faze) idu jedna po jedna:
     * stanje se provjerava UNUTAR locka, pa dva istodobna zahtjeva ne mogu oba proći provjeru.
     */
    protected function withJobLock(string $id, callable $fn): mixed
    {
        if (isset($this->heldLocks[$id])) {
            return $fn();
        }
        $lock = fopen($this->dir($id) . '/.control.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Ne mogu zaključati posao');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('Ne mogu zaključati posao');
        }
        $this->heldLocks[$id] = true;
        try {
            return $fn();
        } finally {
            unset($this->heldLocks[$id]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Upload: provjere, spremanje source.pdf, job.json (+ kind, otisak izvornika), status queued. */
    protected function createJob(array $upload, array $job): string
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
        $this->writeJson($dir . '/job.json', array_merge($job, [
            'kind' => static::KIND,
            'source_sha256' => hash_file('sha256', $target),
            'source_size' => filesize($target),
        ]));
        $this->writeJson($dir . '/status.json', ['state' => 'queued', 'created' => self::now()]);
        return $id;
    }

    public function start(string $id, string $phase): void
    {
        if (!in_array($phase, static::PHASES, true)) {
            throw self::invalid('phase', 'Unknown phase');
        }
        $this->withJobLock($id, function () use ($id, $phase) {
            $status = $this->status($id);
            $state = $status['state'] ?? '';
            if (in_array($state, static::RUNNING, true) && isset($status['queued_at'])) {
                throw self::invalid('phase', 'Processing is already running');
            }
            if (in_array($state, array_diff(static::RUNNING, ['queued']), true)) {
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
        });
    }

    public function status(string $id): array
    {
        $path = $this->dir($id) . '/status.json';
        $status = $this->readJson($path, ['state' => 'queued']);
        if (in_array($status['state'] ?? '', static::RUNNING, true)) {
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

    /**
     * Isti protokol kao start(): odluka (rezervirano/radi -> odbij) provjerava se unutar istog
     * upravljačkog locka kao start(), pa se brisanje i pokretanje ne mogu preklopiti. Sámo brisanje
     * s diska ide tek nakon otključavanja — .control.lock je unutar direktorija posla, a Windows ne
     * dopušta brisanje otvorenog fajla, pa se direktorij ne može ukloniti dok ga ovaj proces drži.
     */
    public function delete(string $id): void
    {
        $dir = $this->dir($id);
        $this->withJobLock($id, function () use ($id) {
            $status = $this->status($id);
            $state = $status['state'] ?? '';
            $reserved = in_array($state, static::RUNNING, true) && isset($status['queued_at']);
            $running = in_array($state, array_diff(static::RUNNING, ['queued']), true);
            if ($reserved || $running) {
                throw self::invalid('state', 'A job cannot be deleted while it is processing');
            }
        });
        $this->removeTree($dir);
    }

    /**
     * Launcher za server na kojem PHP ne smije pokretati procese (disable_functions):
     * zapiše request.json, a job_worker.py (korisnički cron) pokrene runner.
     */
    public static function requestLauncher(string $jobsRoot): callable
    {
        return function (string $id, string $phase) use ($jobsRoot): void {
            $path = $jobsRoot . '/' . $id . '/request.json';
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, json_encode(['phase' => $phase, 'requested' => self::now()]));
            if (!rename($tmp, $path)) {
                throw new \RuntimeException('Ne mogu zatražiti obradu');
            }
        };
    }

    /** Default launcher (lokalno): runner u pozadini, odvojen od web zahtjeva. */
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

    protected static function invalid(string $field, string $message): ValidationException
    {
        return new ValidationException($field, $message, 422);
    }

    protected function assertEditable(string $id): void
    {
        if (in_array($this->status($id)['state'] ?? '', static::EDIT_LOCKED, true)) {
            throw self::invalid('state', 'The job is being processed — edits are not possible until it finishes');
        }
    }

    protected static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s.000000\Z');
    }

    protected function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : $default;
    }

    protected function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    protected function mergeJson(string $path, array $fields): array
    {
        $result = [];
        $this->withLockedJson($path, function (array $data) use ($fields, &$result) {
            return $result = array_merge($data, $fields);
        });
        return $result;
    }

    /** flock na .lock uz fajl, pa tmp + rename — spremanje s dva uređaja ne kvari JSON. */
    protected function withLockedJson(string $path, callable $change): void
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

    protected function removeTree(string $dir): void
    {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? $this->removeTree($p) : unlink($p);
        }
        rmdir($dir);
    }
}
