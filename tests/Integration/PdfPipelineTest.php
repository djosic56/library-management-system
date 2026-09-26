<?php

use App\Services\PdfJobStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../pdf_config.php';

/**
 * End-to-end: PdfJobStore + pravi launcher + pravi Python runner (provider offline — bez troška).
 * Traži Python alat na PDF_TOOL_DIR; posao se radi u PDF_JOBS_ROOT i na kraju briše.
 */
class PdfPipelineTest extends TestCase
{
    private ?string $jobId = null;
    private PdfJobStore $store;

    protected function setUp(): void
    {
        if (!is_file(PDF_TOOL_DIR . '/job_runner.py')) {
            $this->markTestSkipped('Python alat nije na ' . PDF_TOOL_DIR);
        }
        $this->store = pdf_job_store();
    }

    protected function tearDown(): void
    {
        if ($this->jobId !== null && is_dir(PDF_JOBS_ROOT . '/' . $this->jobId)) {
            $this->waitFor($this->jobId, ['review', 'done', 'failed'], 120, false);
            $this->store->delete($this->jobId);
        }
    }

    private function samplePdf(): string
    {
        $pdf = str_replace('\\', '/', sys_get_temp_dir()) . '/pdfpipe_' . bin2hex(random_bytes(4)) . '.pdf';
        // jednostruki navodnici: escapeshellarg na Windowsu izbaci dvostruke
        $code = sprintf("from tests.pdf_fixtures import make_tagged_pdf; make_tagged_pdf('%s', [0, 1])", $pdf);
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
            $this->fail('Timeout; zadnje stanje: ' . json_encode($status) . "\nlog: "
                . @file_get_contents($this->store->dir($id) . '/log.txt'));
        }
        return $status;
    }

    public function testUploadPhase1EditPhase2Download(): void
    {
        $upload = ['tmp_name' => $this->samplePdf(), 'name' => 'sample.pdf', 'error' => UPLOAD_ERR_OK];
        $id = $this->jobId = $this->store->create($upload, 'Integracija', 'Test Book', 'en', null);
        $jobFile = PDF_JOBS_ROOT . "/$id/job.json";
        $job = json_decode(file_get_contents($jobFile), true);
        $job['provider'] = 'offline';
        file_put_contents($jobFile, json_encode($job));

        $this->store->start($id, 'phase1');
        $status = $this->waitFor($id, ['review', 'failed']);
        $this->assertSame('review', $status['state'], json_encode($status) . "\n"
            . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));

        $images = $this->store->descriptions($id)['images'];
        $this->assertCount(2, $images);
        $this->assertFileExists($this->store->filePath($id, 'thumb:' . $images[0]['xref']));
        $this->store->updateImage($id, (int) $images[0]['xref'], ['description' => 'Red square, edited in PHP.']);
        $this->store->updateImage($id, (int) $images[1]['xref'], ['description' => 'Blue square.']);

        $this->store->start($id, 'phase2');
        $status = $this->waitFor($id, ['done', 'failed']);
        $this->assertSame('done', $status['state'], json_encode($status) . "\n"
            . @file_get_contents(PDF_JOBS_ROOT . "/$id/log.txt"));
        $this->assertArrayHasKey('pdfua_ok', $status);
        $this->assertFileExists($this->store->filePath($id, 'output'));
        $this->assertStringContainsString('Izvještaj', file_get_contents($this->store->filePath($id, 'report')));
        $summary = json_decode(file_get_contents(PDF_JOBS_ROOT . "/$id/fix_summary.json"), true);
        $this->assertSame(2, $summary['described']);
    }
}
