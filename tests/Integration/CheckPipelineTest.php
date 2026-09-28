<?php

use App\Services\CheckJobStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../pdf_config.php';

/**
 * End-to-end: CheckJobStore + pravi launcher + pravi Python runner (provider offline — bez troška).
 */
class CheckPipelineTest extends TestCase
{
    private ?string $jobId = null;
    private CheckJobStore $store;

    protected function setUp(): void
    {
        if (!is_file(PDF_TOOL_DIR . '/job_runner.py')) {
            $this->markTestSkipped('Python alat nije na ' . PDF_TOOL_DIR);
        }
        $this->store = check_job_store();
    }

    protected function tearDown(): void
    {
        if ($this->jobId !== null && is_dir(PDF_JOBS_ROOT . '/' . $this->jobId)) {
            $this->waitFor($this->jobId, ['map_review', 'review', 'done', 'failed'], 120, false);
            $this->store->delete($this->jobId);
        }
    }

    private function samplePdf(): string
    {
        $pdf = str_replace('\\', '/', sys_get_temp_dir()) . '/checkpipe_' . bin2hex(random_bytes(4)) . '.pdf';
        $code = sprintf("from tests.check_fixtures import make_text_pdf; make_text_pdf('%s', [['the the harbour was small'], ['a clean page']])", $pdf);
        exec(sprintf('cd %s && %s -c %s 2>&1', escapeshellarg(PDF_TOOL_DIR), PDF_PYTHON, escapeshellarg($code)), $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        return $pdf;
    }

    private function waitFor(string $id, array $states, int $seconds = 180, bool $failOnTimeout = true): array
    {
        $deadline = time() + $seconds;
        do {
            $status = $this->store->status($id);
            if (in_array($status['state'], $states, true)) return $status;
            sleep(1);
        } while (time() < $deadline);
        if ($failOnTimeout) {
            $this->fail('Timeout; zadnje stanje: ' . json_encode($status) . "\nlog: " . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));
        }
        return $status;
    }

    public function testMapCheckReviewBuild(): void
    {
        $upload = ['tmp_name' => $this->samplePdf(), 'name' => 'sample.pdf', 'error' => UPLOAD_ERR_OK];
        $id = $this->jobId = $this->store->create($upload, 'Check integration', null, 'en-GB', null);
        $jobFile = PDF_JOBS_ROOT . "/$id/job.json";
        $job = json_decode(file_get_contents($jobFile), true);
        $job['provider'] = 'offline';
        file_put_contents($jobFile, json_encode($job));

        $this->store->start($id, 'check_map');
        $status = $this->waitFor($id, ['map_review', 'failed']);
        $this->assertSame('map_review', $status['state'], json_encode($status) . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));
        $this->assertCount(1, $this->store->map($id)['units']);

        $preview = $this->store->previewRun($id);
        $this->store->startRun($id, $preview['revision']);
        $status = $this->waitFor($id, ['review', 'failed']);
        $this->assertSame('review', $status['state'], json_encode($status) . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));
        $findings = $this->store->findings($id);
        $this->assertSame('the the', $findings[0]['original']);
        $this->assertFileExists($this->store->filePath($id, 'all'));
        $this->assertFileExists($this->store->filePath($id, 'crop:' . $findings[0]['id']));

        $this->store->updateFinding($id, $findings[0]['id'], ['review' => 'accepted']);
        $this->store->startBuild($id);
        $status = $this->waitFor($id, ['done', 'failed']);
        $this->assertSame('done', $status['state'], json_encode($status) . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));
        $this->assertStringContainsString('Editorial review report', file_get_contents($this->store->filePath($id, 'report')));
        $this->assertFileExists($this->store->filePath($id, 'comments'));
        $this->assertSame(1, $this->store->metrics($id)['accepted']);
    }
}
