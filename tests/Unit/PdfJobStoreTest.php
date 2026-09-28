<?php

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\PdfJobStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class PdfJobStoreTest extends TestCase
{
    private string $root;
    private array $launched = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pdfjobs_' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function store(): PdfJobStore
    {
        return new PdfJobStore($this->root, function (string $id, string $phase) {
            $this->launched[] = [$id, $phase];
        });
    }

    private function upload(string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $content);
        return ['tmp_name' => $tmp, 'name' => 'book.pdf', 'size' => strlen($content), 'error' => $error];
    }

    private function job(array $images = [['xref' => 7, 'description' => 'Map', 'decorative' => false]]): string
    {
        $id = $this->store()->create($this->upload("%PDF-1.7\n..."), 'Knjiga', 'Naslov', 'en', null);
        file_put_contents("{$this->root}/$id/descriptions.json", json_encode(['images' => $images]));
        $this->setState($id, 'review');
        return $id;
    }

    private function setState(string $id, string $state, ?string $heartbeat = null): void
    {
        $status = ['state' => $state];
        if ($heartbeat !== null) {
            $status['heartbeat'] = $heartbeat;
        }
        file_put_contents("{$this->root}/$id/status.json", json_encode($status));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            is_dir("$dir/$f") ? $this->rrmdir("$dir/$f") : unlink("$dir/$f");
        }
        rmdir($dir);
    }

    public function testIdValidation(): void
    {
        $this->assertTrue(PdfJobStore::isValidId(str_repeat('a', 32)));
        $this->assertFalse(PdfJobStore::isValidId('../../etc/passwd'));
        $this->assertFalse(PdfJobStore::isValidId(str_repeat('A', 32)));
        $this->expectException(NotFoundException::class);
        $this->store()->dir('../x');
    }

    public function testCreateStoresPdfAndQueuesJob(): void
    {
        $id = $this->store()->create($this->upload("%PDF-1.7\nbody"), 'Knjiga', 'Naslov', 'hr', 5);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertFileExists("{$this->root}/$id/source.pdf");
        $job = json_decode(file_get_contents("{$this->root}/$id/job.json"), true);
        $this->assertSame([
            'name' => 'Knjiga', 'title' => 'Naslov', 'lang' => 'hr', 'book_id' => 5,
            'kind' => 'pdf', 'source_sha256' => hash('sha256', "%PDF-1.7\nbody"), 'source_size' => 13,
        ], $job);
        $this->assertSame('queued', $this->store()->status($id)['state']);
        $this->assertSame([], $this->launched, 'create() must not start processing');
    }

    public function testCreateRejectsNonPdf(): void
    {
        $this->expectException(ValidationException::class);
        $this->store()->create($this->upload("GIF89a"), 'x', null, null, null);
    }

    public function testCreateExplainsUploadLimit(): void
    {
        try {
            $this->store()->create($this->upload('', UPLOAD_ERR_INI_SIZE), 'x', null, null, null);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('200 MB', $e->getUserMessage());
        }
    }

    public function testPostDroppedByPostMaxSizeIsRecognised(): void
    {
        // PHP drops both $_POST and $_FILES (incl. csrf_token) when the body exceeds post_max_size
        $this->assertTrue(PdfJobStore::isOversizedPost(['CONTENT_LENGTH' => '250000000'], [], []));
        $this->assertFalse(PdfJobStore::isOversizedPost(['CONTENT_LENGTH' => '1200'], ['csrf_token' => 'x'], []));
        $this->assertFalse(PdfJobStore::isOversizedPost([], [], []));
    }

    public function testStartLaunchesAndRefusesWhileRunning(): void
    {
        $id = $this->job();
        $this->store()->start($id, 'phase2');
        $this->assertSame([[$id, 'phase2']], $this->launched);
        $this->assertSame('queued', $this->store()->status($id)['state']);

        $this->expectException(ValidationException::class);
        $this->store()->start($id, 'phase2');
    }

    public function testLauncherFailureRestoresPreviousStatus(): void
    {
        // oba launchera bacaju samo kad ništa nije pokrenuto — rezervacija se poništi, posao ostaje editabilan
        $id = $this->job();
        $this->setState($id, 'review', gmdate('Y-m-d\TH:i:s.000000\Z', time() - 3600));
        $before = file_get_contents("{$this->root}/$id/status.json");
        $failing = new PdfJobStore($this->root, function (string $id, string $phase) {
            throw new \RuntimeException('Ne mogu pokrenuti obradu');
        });

        try {
            $failing->start($id, 'phase2');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ne mogu pokrenuti obradu', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents("{$this->root}/$id/status.json"));
        $this->store()->updateImage($id, 7, ['description' => 'Still editable']);
        $this->store()->start($id, 'phase2');
        $this->assertSame([[$id, 'phase2']], $this->launched);
    }

    public function testWorkerModeWritesRequestInsteadOfStartingProcess(): void
    {
        // server: PHP ne smije pokretati procese — zahtjev preuzima job_worker.py (cron)
        $store = new PdfJobStore($this->root, PdfJobStore::requestLauncher($this->root));
        $id = $store->create($this->upload("%PDF-1.7\n..."), 'Knjiga', null, null, null);
        $store->start($id, 'phase1');

        $request = json_decode(file_get_contents("{$this->root}/$id/request.json"), true);
        $this->assertSame('phase1', $request['phase']);
        $this->assertSame('queued', $store->status($id)['state']);
        $this->assertSame([], glob("{$this->root}/$id/*.tmp"), 'request is written atomically');
    }

    public function testStartAfterLongReviewIsNotMistakenForDeadProcess(): void
    {
        $id = $this->job();
        // phase 1 finished hours ago: its heartbeat, pid and result fields are still in status.json
        file_put_contents("{$this->root}/$id/status.json", json_encode([
            'state' => 'review', 'pid' => 4242, 'finished' => 'x',
            'heartbeat' => gmdate('Y-m-d\TH:i:s.000000\Z', time() - 8 * 3600),
            'pdfua_ok' => true, 'pdfua_failed' => [],
        ]));

        $this->store()->start($id, 'phase2');
        $status = $this->store()->status($id);

        $this->assertSame('queued', $status['state']);
        $this->assertNull($status['heartbeat'] ?? null);
        $this->assertNull($status['pid'] ?? null);
        $this->assertNull($status['pdfua_ok'] ?? null, 'old PDF/UA result must not survive a re-run');
    }

    public function testTitleTypedByUserIsNoLongerMarkedAsSuggestion(): void
    {
        $id = $this->job();
        $jobFile = "{$this->root}/$id/job.json";
        $job = json_decode(file_get_contents($jobFile), true);
        $job['title_source'] = 'title_page';
        file_put_contents($jobFile, json_encode($job));

        $updated = $this->store()->updateDocument($id, 'Ritual in the Roman World', 'en');

        $this->assertSame('user', $updated['title_source']);
    }

    public function testStaleRunningJobBecomesFailed(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase1', gmdate('Y-m-d\TH:i:s.000000\Z', time() - 600));

        $status = $this->store()->status($id);

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('interrupted', $status['message']);
    }

    /** M5: a cron worker (job_worker.py) reading request.json later must not run a stale, already-paid job. */
    public function testStaleRunningJobRemovesPendingRequestFile(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase1', gmdate('Y-m-d\TH:i:s.000000\Z', time() - 600));
        file_put_contents("{$this->root}/$id/request.json", json_encode(['phase' => 'phase1', 'requested' => 'x']));

        $status = $this->store()->status($id);

        $this->assertSame('failed', $status['state']);
        $this->assertFileDoesNotExist("{$this->root}/$id/request.json");
    }

    public function testQueuedJobThatNeverStartedBecomesFailed(): void
    {
        $id = $this->store()->create($this->upload("%PDF-1.7\n"), 'x', null, null, null);
        $status = json_decode(file_get_contents("{$this->root}/$id/status.json"), true);
        $status['created'] = gmdate('Y-m-d\TH:i:s.000000\Z', time() - 600);
        file_put_contents("{$this->root}/$id/status.json", json_encode($status));

        $this->assertSame('failed', $this->store()->status($id)['state']);
    }

    public function testFreshHeartbeatStaysRunning(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase1', gmdate('Y-m-d\TH:i:s.000000\Z'));

        $this->assertSame('phase1', $this->store()->status($id)['state']);
    }

    public function testUpdateImageChangesOnlyAllowedFields(): void
    {
        $id = $this->job();

        $image = $this->store()->updateImage($id, 7, [
            'description' => 'New', 'decorative' => true, 'language' => 'fr', 'xref' => 99, 'evil' => 'x',
        ]);

        $this->assertSame(['xref' => 7, 'description' => 'New', 'decorative' => true, 'language' => 'fr'], $image);
        $data = json_decode(file_get_contents("{$this->root}/$id/descriptions.json"), true);
        $this->assertSame($image, $data['images'][0]);
    }

    public function testUpdateImageRejectedWhilePhase2Runs(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase2', gmdate('Y-m-d\TH:i:s.000000\Z'));

        $this->expectException(ValidationException::class);
        $this->store()->updateImage($id, 7, ['description' => 'late edit']);
    }

    public function testUpdateUnknownImage(): void
    {
        $id = $this->job();
        $this->expectException(NotFoundException::class);
        $this->store()->updateImage($id, 12345, ['description' => 'x']);
    }

    public function testSourcePdfIsNeverServed(): void
    {
        $id = $this->job();
        $this->expectException(NotFoundException::class);
        $this->store()->filePath($id, 'source');
    }

    public function testDeleteRefusedWhileRunningAllowedWhenDone(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase1', gmdate('Y-m-d\TH:i:s.000000\Z'));
        try {
            $this->store()->delete($id);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertDirectoryExists("{$this->root}/$id");
        }

        $this->setState($id, 'done');
        $this->store()->delete($id);
        $this->assertDirectoryDoesNotExist("{$this->root}/$id");
    }

    public function testUploadStoresKindAndFingerprint(): void
    {
        $id = $this->store()->create($this->upload("%PDF-1.7\nabc"), 'Knjiga', null, null, null);
        $job = $this->store()->job($id);
        $this->assertSame('pdf', $job['kind']);
        $this->assertSame(hash('sha256', "%PDF-1.7\nabc"), $job['source_sha256']);
        $this->assertSame(12, $job['source_size']);
    }

    public function testOldJobWithoutKindStillOpensAndStarts(): void
    {
        $id = $this->job();
        $job = json_decode(file_get_contents("{$this->root}/$id/job.json"), true);
        unset($job['kind'], $job['source_sha256'], $job['source_size']);        // posao iz vremena prije Checka
        file_put_contents("{$this->root}/$id/job.json", json_encode($job));
        $this->assertSame('review', $this->store()->status($id)['state']);
        $this->store()->start($id, 'phase2');
        $this->assertSame([[$id, 'phase2']], $this->launched);
        $this->expectException(ValidationException::class);                    // posao je sada u redu (queued)
        $this->store()->updateImage($id, 7, ['description' => 'x']);
    }

    public function testJobOfAnotherKindIsNotFound(): void
    {
        $id = $this->job();
        $job = json_decode(file_get_contents("{$this->root}/$id/job.json"), true);
        $job['kind'] = 'check';
        file_put_contents("{$this->root}/$id/job.json", json_encode($job));
        $this->expectException(NotFoundException::class);
        $this->store()->status($id);
    }

    public function testDeleteAllowedForUnreservedQueuedJob(): void
    {
        // create() upload nije rezerviran (nema queued_at) — brisanje mora proći
        $id = $this->store()->create($this->upload("%PDF-1.7\nabc"), 'Knjiga', null, null, null);
        $this->assertSame('queued', $this->store()->status($id)['state']);
        $this->store()->delete($id);
        $this->assertDirectoryDoesNotExist("{$this->root}/$id");
    }

    public function testDeleteRefusedWhileReservationPending(): void
    {
        // start() je upravo rezervirao posao (queued_at postavljen) — istrčao je prije runnera
        $id = $this->job();
        $this->store()->start($id, 'phase2');
        $this->expectException(ValidationException::class);
        $this->store()->delete($id);
    }

    public function testDeletedTombstoneMakesJobNotFoundUntilRemoved(): void
    {
        // simulira prozor između withJobLock-a u delete() (upisan .deleted) i removeTree() s diska
        $id = $this->job();
        $statusBefore = file_get_contents("{$this->root}/$id/status.json");
        file_put_contents("{$this->root}/$id/.deleted", '1');

        try {
            $this->store()->start($id, 'phase2');
            $this->fail('start(): expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        $this->assertSame([], $this->launched, 'start() must not launch a tombstoned job');
        $this->assertSame($statusBefore, file_get_contents("{$this->root}/$id/status.json"),
            'status.json must be untouched');

        try {
            $this->store()->status($id);
            $this->fail('status(): expected NotFoundException');
        } catch (NotFoundException $e) {
        }

        try {
            $this->store()->updateImage($id, 7, ['description' => 'x']);
            $this->fail('updateImage(): expected NotFoundException');
        } catch (NotFoundException $e) {
        }
    }

    public function testControlLockDirectoryMakesFopenFailCleanly(): void
    {
        // fopen('c') na direktoriju vraća false — withJobLock mora prekinuti prije zapisa/pokretanja
        $id = $this->job();
        $statusBefore = file_get_contents("{$this->root}/$id/status.json");
        mkdir("{$this->root}/$id/.control.lock");

        try {
            $this->store()->start($id, 'phase2');
            $this->fail('start(): expected RuntimeException');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame([], $this->launched, 'start() must not launch when the lock cannot be taken');
        $this->assertSame($statusBefore, file_get_contents("{$this->root}/$id/status.json"),
            'status.json must be untouched');

        try {
            $this->store()->delete($id);
            $this->fail('delete(): expected RuntimeException');
        } catch (\RuntimeException $e) {
        }
        $this->assertDirectoryExists("{$this->root}/$id", 'a failed lock must leave the job directory alone');
    }

    /**
     * Ne dokazuje samo da se dogodi točno jedno pokretanje (to bi mogla proći i bez locka, ovisno
     * o redoslijedu OS schedulera) — test sam prvo uzme upravljački lock i drži ga dok oba djeteta
     * ne pokušaju startati, pa dokaže da oba čekaju (record fajl još ne postoji) prije nego ga pusti.
     */
    public function testTwoConcurrentProcessesOnlyOneLaunches(): void
    {
        $id = $this->job();
        $script = __DIR__ . '/../fixtures/start_job.php';
        $recordFile = $this->root . '/launch_record.txt';
        $lockPath = "{$this->root}/$id/.control.lock";

        $testLock = fopen($lockPath, 'c');
        $this->assertNotFalse($testLock, 'test must be able to open the control lock file');
        $this->assertTrue(flock($testLock, LOCK_EX), 'test must be able to take the control lock first');

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($this->root) . ' ' . escapeshellarg($id) . ' ' . escapeshellarg($recordFile);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p1 = proc_open($cmd, $descriptors, $pipes1);
        $p2 = proc_open($cmd, $descriptors, $pipes2);
        $this->assertIsResource($p1);
        $this->assertIsResource($p2);

        usleep(500_000);   // dovoljno vremena da oba djeteta stignu do flock() i zablokiraju se
        $this->assertFileDoesNotExist($recordFile,
            'both children must still be blocked on the control lock while the test holds it');

        flock($testLock, LOCK_UN);
        fclose($testLock);

        $out1 = stream_get_contents($pipes1[1]);
        $err1 = stream_get_contents($pipes1[2]);
        $out2 = stream_get_contents($pipes2[1]);
        $err2 = stream_get_contents($pipes2[2]);
        foreach ([$pipes1, $pipes2] as $pipes) {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        $rc1 = proc_close($p1);
        $rc2 = proc_close($p2);

        $results = [trim($out1), trim($out2)];
        sort($results);
        $this->assertSame(['already_running', 'launched'], $results,
            "rc1=$rc1 err1=$err1 rc2=$rc2 err2=$err2");
        $this->assertTrue(is_file($recordFile), 'launcher must have run exactly once');
        $records = array_filter(explode("\n", trim((string) file_get_contents($recordFile))));
        $this->assertCount(1, $records, 'exactly one process must have launched the runner');
    }
}
