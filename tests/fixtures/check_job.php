<?php
/**
 * Fixture for the multi-process CheckJobStore tests (see CheckJobStoreTest).
 * Usage: php check_job.php <jobsRoot> <jobId> <recordFile> <op> [<revision>]
 *   op = start:   startRun($jobId, $revision) with a launcher that sleeps ~300ms and appends to $recordFile;
 *                 prints "launched" or "refused".
 *   op = preview: previewRun($jobId); appends the JSON result to $recordFile and prints it.
 */

require_once __DIR__ . '/../../autoload.php';

use App\Exceptions\ValidationException;
use App\Services\CheckJobStore;

[$script, $jobsRoot, $jobId, $recordFile, $op] = $argv;
$revision = $argv[5] ?? '';

$store = new CheckJobStore($jobsRoot, function (string $id, string $phase) use ($recordFile): void {
    usleep(300_000);
    file_put_contents($recordFile, getmypid() . " $phase\n", FILE_APPEND | LOCK_EX);
});

if ($op === 'preview') {
    $json = json_encode($store->previewRun($jobId));
    file_put_contents($recordFile, $json, FILE_APPEND | LOCK_EX);
    echo $json;
    exit;
}

try {
    $store->startRun($jobId, $revision);
    echo 'launched';
} catch (ValidationException $e) {
    echo 'refused';
}
