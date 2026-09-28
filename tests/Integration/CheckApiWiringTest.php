<?php

use PHPUnit\Framework\TestCase;

/**
 * Static wiring check for the R4 controller ruling (task-16-brief.md dispatch): `preview_run` must use
 * CheckJobStore::previewRun() (one snapshot, one lock) rather than calling affectedFindings()/
 * proposedConfig()/revision() separately, and `save_map` must use the atomic saveMapAndSettings()
 * rather than saveMap()+saveSettings() as two operations. The functional difference between the atomic
 * and split variants is only observable under concurrency (already covered by Task 13's multi-process
 * CheckJobStoreTest fixtures), so this test enforces the ruling directly at the source level as a
 * companion to CheckApiTest's real end-to-end HTTP checks — it needs no server or database, so it always
 * runs (never SKIPPED).
 */
class CheckApiWiringTest extends TestCase
{
    public function testSaveMapUsesTheAtomicSaveMapAndSettings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../check_api.php');
        $this->assertMatchesRegularExpression('/\$store->saveMapAndSettings\(/', $source,
            'save_map must call CheckJobStore::saveMapAndSettings() (Task 13, R4)');
        $this->assertDoesNotMatchRegularExpression('/\$store->saveMap\(/', $source,
            'save_map must not call the non-atomic saveMap() separately (Task 13, R4)');
        $this->assertDoesNotMatchRegularExpression('/\$store->saveSettings\(/', $source,
            'save_map must not call the non-atomic saveSettings() separately (Task 13, R4)');
    }

    public function testPreviewRunUsesTheAtomicPreviewRun(): void
    {
        $source = file_get_contents(__DIR__ . '/../../check_api.php');
        $this->assertMatchesRegularExpression('/\$store->previewRun\(/', $source,
            'preview_run must call CheckJobStore::previewRun() (Task 13, R4)');
        $this->assertDoesNotMatchRegularExpression('/\$store->affectedFindings\(/', $source,
            'preview_run must not call affectedFindings() separately from previewRun() (Task 13, R4)');
        $this->assertDoesNotMatchRegularExpression('/\$store->proposedConfig\(/', $source,
            'preview_run must not call proposedConfig() separately from previewRun() (Task 13, R4)');
    }
}
