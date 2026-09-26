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
        $this->assertSame(['name' => 'Knjiga', 'title' => 'Naslov', 'lang' => 'hr', 'book_id' => 5], $job);
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

    public function testStaleRunningJobBecomesFailed(): void
    {
        $id = $this->job();
        $this->setState($id, 'phase1', gmdate('Y-m-d\TH:i:s.000000\Z', time() - 600));

        $status = $this->store()->status($id);

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('prekinuta', $status['message']);
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
}
