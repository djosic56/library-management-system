<?php
/**
 * Fixture for the two-process reservation test (see PdfJobStoreTest::testTwoConcurrentProcessesOnlyOneLaunches).
 * Usage: php start_job.php <jobsRoot> <jobId> <recordFile>
 * Calls PdfJobStore::start($jobId, 'phase2') with a launcher that appends to $recordFile and sleeps ~300ms
 * to widen the race window, then prints "launched" or "already_running" to stdout.
 */

require_once __DIR__ . '/../../autoload.php';

use App\Exceptions\ValidationException;
use App\Services\PdfJobStore;

[$script, $jobsRoot, $jobId, $recordFile] = $argv;

$store = new PdfJobStore($jobsRoot, function (string $id, string $phase) use ($recordFile): void {
    usleep(300_000);
    file_put_contents($recordFile, getmypid() . "\n", FILE_APPEND | LOCK_EX);
});

try {
    $store->start($jobId, 'phase2');
    echo 'launched';
} catch (ValidationException $e) {
    echo 'already_running';
}
