<?php

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\CheckJobStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class CheckJobStoreTest extends TestCase
{
    private string $root;
    private array $launched = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/checkjobs_' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function store(): CheckJobStore
    {
        return new CheckJobStore($this->root, function (string $id, string $phase) {
            $this->launched[] = [$id, $phase];
        });
    }

    /** Launcher koji ništa nije pokrenuo i baci grešku (oba prava launchera tako rade). */
    private function failingStore(): CheckJobStore
    {
        return new CheckJobStore($this->root, function (string $id, string $phase) {
            throw new \RuntimeException('Ne mogu pokrenuti obradu');
        });
    }

    private function upload(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.7\n...");
        return ['tmp_name' => $tmp, 'name' => 'book.pdf', 'size' => 12, 'error' => UPLOAD_ERR_OK];
    }

    private function unit(array $kw = []): array
    {
        return array_merge(['id' => 'u1', 'title' => 'Ships', 'author' => null, 'start' => 1, 'end' => 4, 'language' => 'en',
            'kind' => 'chapter', 'notes' => 'footnotes', 'bibliography_start' => null, 'bibliography_end' => null], $kw);
    }

    private function put(string $id, string $file, array $data): void
    {
        file_put_contents("{$this->root}/$id/$file", json_encode($data));
    }

    private function read(string $id, string $file): array
    {
        return json_decode(file_get_contents("{$this->root}/$id/$file"), true);
    }

    private function state(string $id, string $state): void
    {
        $this->put($id, 'status.json', ['state' => $state, 'heartbeat' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /** Sadržaj svih datoteka posla (bez praznih .lock datoteka) — za provjeru da odbijena operacija ništa ne mijenja. */
    private function files(string $id): array
    {
        $out = [];
        $walk = function (string $dir, string $prefix) use (&$walk, &$out) {
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || str_ends_with($f, '.lock')) continue;
                is_dir("$dir/$f") ? $walk("$dir/$f", "$prefix$f/") : $out["$prefix$f"] = file_get_contents("$dir/$f");
            }
        };
        $walk("{$this->root}/$id", '');
        ksort($out);
        return $out;
    }

    private function assertRefused(callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            if ($message !== '') {
                $this->assertStringContainsString($message, $e->getUserMessage());
            }
        }
    }

    /** Posao u map_review s mapom od dvije cjeline (u1: str. 1–4, u2: str. 5–8). */
    private function mapped(): string
    {
        $id = $this->store()->create($this->upload(), 'Nantet', null, 'en-GB', 'Keep -ize.');
        // map.json `usage` NIJE izvor troška (R13) — namjerno drugačiji iznos od map_usage.json
        $this->put($id, 'map.json', ['units' => [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8])],
            'shared_bibliography_start' => null, 'shared_bibliography_end' => null, 'page_count' => 8,
            'page_labels' => ['i', 'ii', '1', '2', '3', '4', '5', '6'], 'page_words' => array_fill(0, 8, 100),
            'prices' => ['input_per_m' => 2, 'output_per_m' => 8], 'usage' => ['cost_usd' => 0.25]]);
        $this->put($id, 'map_usage.json', ['spent_usd' => 0.01, 'attempts' => 1]);
        $this->state($id, 'map_review');
        return $id;
    }

    /** Posao u review s nalazima u obje cjeline. */
    private function reviewed(): string
    {
        $id = $this->mapped();
        $this->store()->startRun($id, $this->store()->revision($id));
        $this->put($id, 'findings.json', ['next_id' => 4, 'findings' => [
            ['id' => 'BK-001', 'unit' => 'u1', 'page' => 2, 'page_label' => 'ii', 'status' => 'CORRECTION', 'category' => 'Typo',
             'original' => 'teh', 'replacement' => 'the', 'reason' => 'Typo.', 'review' => 'accepted', 'placed' => true,
             'placed_page' => 2, 'rect' => [1, 2, 3, 4]],
            ['id' => 'BK-002', 'unit' => 'u2', 'page' => 6, 'status' => 'QUERY', 'category' => 'Typo', 'original' => 'Smtih',
             'replacement' => null, 'reason' => 'Name?', 'review' => 'pending', 'placed' => false],
            ['id' => 'BK-003', 'unit' => 'u2', 'page' => 7, 'status' => 'CORRECTION', 'category' => 'Grammar', 'original' => 'was',
             'replacement' => 'were', 'reason' => 'Agreement.', 'review' => 'pending', 'placed' => true],
        ]]);
        $this->put($id, 'units.json', ['units' => ['u1' => ['state' => 'completed', 'cost_usd' => 0.5],
            'u2' => ['state' => 'failed', 'cost_usd' => 0.1]], 'spent_usd' => 0.6]);
        $this->state($id, 'review');
        return $id;
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

    /** Pokrene tests/fixtures/check_job.php kao zaseban proces. */
    private function spawn(string $id, string $record, string $op, string $revision = ''): array
    {
        $cmd = implode(' ', array_map('escapeshellarg',
            [PHP_BINARY, __DIR__ . '/../fixtures/check_job.php', $this->root, $id, $record, $op, $revision]));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        return [$proc, $pipes];
    }

    private function finish(array $child): array
    {
        [$proc, $pipes] = $child;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [trim($out), $err, proc_close($proc)];
    }

    /** Test uzme upravljački lock prije djece, kao PdfJobStoreTest::testTwoConcurrentProcessesOnlyOneLaunches. */
    private function holdControlLock(string $id)
    {
        $lock = fopen("{$this->root}/$id/.control.lock", 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX));
        return $lock;
    }

    public function testCreateStoresSettingsAndRequestsMap(): void
    {
        $id = $this->store()->create($this->upload(), 'Nantet', 7, 'en-GB', ' Keep -ize. ');
        $job = $this->store()->job($id);
        $this->assertSame(['check', 'en-GB', 'Keep -ize.', 7], [$job['kind'], $job['english'], $job['instructions'], $job['book_id']]);
        $this->store()->start($id, 'check_map');
        $this->assertSame([[$id, 'check_map']], $this->launched);
    }

    public function testCreateRejectsUnknownEnglish(): void
    {
        $this->expectException(ValidationException::class);
        $this->store()->create($this->upload(), 'X', null, 'en-AU', null);
    }

    public function testSaveMapValidatesAddsLabelsAndAssignsIdsToNewUnits(): void
    {
        $id = $this->mapped();
        $map = $this->store()->saveMap($id, [$this->unit(['end' => 2]), $this->unit(['id' => '', 'title' => 'New', 'start' => 3, 'end' => 4]),
            $this->unit(['id' => 'u2', 'start' => 5, 'end' => 8, 'language' => 'fr'])], null, null);
        $this->assertSame(['u1', 'u3', 'u2'], array_column($map['units'], 'id'));
        $this->assertSame(['i', 'ii'], [$map['units'][0]['start_label'], $map['units'][0]['end_label']]);
        $this->assertSame('fr', $map['units'][2]['language']);
    }

    #[DataProvider('badMaps')]
    public function testSaveMapRejectsBadMaps(array $units, string $message): void
    {
        $id = $this->mapped();
        $before = file_get_contents("{$this->root}/$id/map.json");
        try {
            $this->store()->saveMap($id, $units, null, null);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getUserMessage());
        }
        $this->assertSame($before, file_get_contents("{$this->root}/$id/map.json"));
    }

    public static function badMaps(): array
    {
        $u = fn (array $kw) => array_merge(['id' => 'u1', 'title' => 'A', 'author' => null, 'start' => 1, 'end' => 2, 'language' => 'en',
            'kind' => 'chapter', 'notes' => 'none', 'bibliography_start' => null, 'bibliography_end' => null], $kw);
        return [
            'empty' => [[], 'no units'],
            'outside' => [[$u(['end' => 99])], 'outside'],
            'overlap' => [[$u([]), $u(['id' => 'u2', 'start' => 2, 'end' => 3])], 'overlap'],
            'language' => [[$u(['language' => 'English'])], 'language'],
            'kind' => [[$u(['kind' => 'appendix'])], 'kind'],
            'bibliography' => [[$u(['bibliography_start' => 5, 'bibliography_end' => 5])], 'bibliography'],
            'duplicate id' => [[$u([]), $u(['start' => 3, 'end' => 4])], 'duplicate'],
        ];
    }

    public function testStartRunLocksConfigAndMapBecomesReadOnly(): void
    {
        $id = $this->mapped();
        $this->store()->startRun($id, $this->store()->revision($id));
        $config = $this->store()->runConfig($id);
        $this->assertSame(['en-GB', 'Keep -ize.'], [$config['english'], $config['instructions']]);
        $this->assertSame(['id', 'title', 'author', 'start', 'end', 'language', 'kind', 'notes', 'bibliography_start',
            'bibliography_end'], array_keys($config['units'][0]));
        $this->assertSame([$id, 'check_run'], end($this->launched));
        $this->state($id, 'review');
        $this->expectException(ValidationException::class);
        $this->store()->saveMap($id, [$this->unit()], null, null);
    }

    public function testStartRunWithStaleRevisionChangesNothing(): void
    {
        $id = $this->mapped();
        $revision = $this->store()->revision($id);
        $this->store()->saveSettings($id, 'en-US', null);          // promjena nakon pregleda
        try {
            $this->store()->startRun($id, $revision);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('changed after the preview', $e->getUserMessage());
        }
        $this->assertFileDoesNotExist("{$this->root}/$id/run_config.json");
        $this->assertSame([], $this->launched);
    }

    public function testRefusedStartRunWhileRunningChangesNothing(): void
    {
        $id = $this->reviewed();
        $before = file_get_contents("{$this->root}/$id/run_config.json");
        $this->state($id, 'check_run');
        try {
            $this->store()->startRun($id, $this->store()->revision($id));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame($before, file_get_contents("{$this->root}/$id/run_config.json"));
    }

    public function testReopenCountsOnlyFindingsOfChangedUnits(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $this->assertSame('map_review', $this->store()->status($id)['state']);
        $this->store()->saveMap($id, [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8, 'language' => 'fr'])], null, null);
        $this->assertSame(2, $this->store()->affectedFindings($id, $this->store()->proposedConfig($id)));
        $this->store()->saveSettings($id, 'en-US', 'Keep -ize.');
        $this->assertSame(3, $this->store()->affectedFindings($id, $this->store()->proposedConfig($id)));
    }

    public function testInsertingAUnitBeforeKeepsLaterFindings(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        // u1 razdvojen na novi (str. 1–2) i u1 (str. 3–4); u2 nepromijenjen
        $this->store()->saveMap($id, [$this->unit(['id' => '', 'title' => 'Preface', 'end' => 2]), $this->unit(['start' => 3]),
            $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8])], null, null);
        $this->assertSame(1, $this->store()->affectedFindings($id, $this->store()->proposedConfig($id)));   // samo BK-001 (u1)
    }

    public function testAffectedMirrorsPythonInvalidation(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $this->assertSame(0, $this->store()->previewRun($id)['affected'], 'reopened map = locked config: nothing lost');
        // uklonjena cjelina gubi svoje nalaze
        $this->store()->saveMap($id, [$this->unit()], null, null);
        $this->assertSame(2, $this->store()->previewRun($id)['affected']);
        // zajednička bibliografija poništava sve
        $this->store()->saveMap($id, [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8])], 7, 8);
        $this->assertSame(3, $this->store()->previewRun($id)['affected']);
        // promjena samo uputa poništava sve
        $this->store()->saveMapAndSettings($id, [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8])],
            null, null, 'en-GB', 'Keep -ise.');
        $this->assertSame(3, $this->store()->previewRun($id)['affected']);
    }

    public function testPreviewRunMatchesSeparateReadsOfTheSameSnapshot(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $this->store()->saveMap($id, [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8, 'language' => 'fr'])], null, null);
        $preview = $this->store()->previewRun($id);
        $this->assertSame(['affected' => 2, 'revision' => $this->store()->revision($id)], $preview);
        $this->store()->startRun($id, $preview['revision']);
        $this->assertSame([$id, 'check_run'], end($this->launched));
    }

    /** Pregled uzima upravljački lock: dok ga drži „spremanje“ (ovdje test), pregled čeka i vidi tek završeno stanje. */
    public function testPreviewCannotInterleaveWithASave(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $revisionBefore = $this->store()->revision($id);
        $record = $this->root . '/preview_record.txt';

        $lock = $this->holdControlLock($id);
        $child = $this->spawn($id, $record, 'preview');
        usleep(500_000);
        $this->assertFileDoesNotExist($record, 'previewRun must wait for the control lock');
        // „spremanje druge kartice“ dok drži lock: u2 dobiva drugi jezik, pa postavke
        $map = $this->read($id, 'map.json');
        $map['units'][1]['language'] = 'fr';
        $this->put($id, 'map.json', $map);
        usleep(100_000);
        $job = $this->read($id, 'job.json');
        $job['english'] = 'en-US';
        $this->put($id, 'job.json', $job);
        flock($lock, LOCK_UN);
        fclose($lock);

        [$out, $err, $rc] = $this->finish($child);
        $this->assertSame(0, $rc, $err);
        $preview = json_decode($out, true);
        $this->assertNotSame($revisionBefore, $preview['revision']);
        $this->assertSame(['affected' => 3, 'revision' => $this->store()->revision($id)], $preview,
            'affected and revision must both describe the completed save');
    }

    public function testTwoTabsStaleRevisionIsRefusedAndNothingChanges(): void
    {
        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $tabA = $this->store()->previewRun($id);
        $saved = $this->store()->saveMapAndSettings($id,          // kartica B
            [$this->unit(), $this->unit(['id' => 'u2', 'title' => 'Ports', 'start' => 5, 'end' => 8, 'language' => 'fr'])],
            null, null, 'en-US', 'B');
        $this->assertSame(['fr', 'en-US', 'B'], [$saved['map']['units'][1]['language'], $saved['job']['english'], $saved['job']['instructions']]);
        $launched = $this->launched;
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->startRun($id, $tabA['revision']), 'changed after the preview');
        $this->assertSame($before, $this->files($id));
        $this->assertSame($launched, $this->launched);
        $this->assertSame('map_review', $this->store()->status($id)['state']);
    }

    public function testInvalidSettingsWithValidMapWritesNothing(): void
    {
        $id = $this->mapped();
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->saveMapAndSettings($id,
            [$this->unit(['language' => 'fr']), $this->unit(['id' => 'u2', 'start' => 5, 'end' => 8])], null, null, 'en-AU', 'x'), 'English');
        $this->assertSame($before, $this->files($id));
        $this->assertRefused(fn () => $this->store()->saveMapAndSettings($id, [$this->unit(['end' => 99])], null, null, 'en-US', 'x'), 'outside');
        $this->assertSame($before, $this->files($id), 'invalid map must not save the settings either');
    }

    public function testSaveMapAndSettingsRefusedOutsideMapReview(): void
    {
        $id = $this->reviewed();
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->saveMapAndSettings($id, [$this->unit()], null, null, 'en-US', null));
        $this->assertRefused(fn () => $this->store()->saveSettings($id, 'en-US', null));
        $this->assertSame($before, $this->files($id));
    }

    /** Oporavak prekinutog spremanja para map.json + job.json: pokretanje je blokirano dok se ne spremi ponovno. */
    public function testInterruptedSaveBlocksStartUntilSavedAgain(): void
    {
        $id = $this->mapped();
        file_put_contents("{$this->root}/$id/config.pending", '1');   // proces je pao između dva zapisa
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->previewRun($id), 'did not finish');
        $this->assertRefused(fn () => $this->store()->startRun($id, $this->store()->revision($id)), 'did not finish');
        $this->assertSame($before, $this->files($id));
        $this->assertSame([], $this->launched);

        $this->store()->saveMapAndSettings($id, [$this->unit(), $this->unit(['id' => 'u2', 'start' => 5, 'end' => 8])], null, null, 'en-GB', null);
        $this->assertFileDoesNotExist("{$this->root}/$id/config.pending");
        $this->store()->startRun($id, $this->store()->previewRun($id)['revision']);
        $this->assertSame([[$id, 'check_run']], $this->launched);
    }

    public function testStartRunRevalidatesWholeConfig(): void
    {
        $id = $this->mapped();
        $map = $this->read($id, 'map.json');
        $map['units'][1]['start'] = 3;                              // preklapanje u1 (1–4)
        $this->put($id, 'map.json', $map);
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->startRun($id, $this->store()->revision($id)), 'overlap');
        $this->assertSame($before, $this->files($id));

        $map['units'][1]['start'] = 5;
        $this->put($id, 'map.json', $map);
        $job = $this->read($id, 'job.json');
        $job['english'] = 'en-AU';
        $this->put($id, 'job.json', $job);
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->startRun($id, $this->store()->revision($id)), 'English');
        $this->assertSame($before, $this->files($id));
        $this->assertSame([], $this->launched);
    }

    public function testTwoConcurrentStartRunsLaunchOnce(): void
    {
        $id = $this->mapped();
        $revision = $this->store()->revision($id);
        $record = $this->root . '/launch_record.txt';

        $lock = $this->holdControlLock($id);
        $c1 = $this->spawn($id, $record, 'start', $revision);
        $c2 = $this->spawn($id, $record, 'start', $revision);
        usleep(500_000);
        $this->assertFileDoesNotExist($record, 'both children must wait for the control lock');
        $this->assertFileDoesNotExist("{$this->root}/$id/run_config.json");
        flock($lock, LOCK_UN);
        fclose($lock);

        [$out1, $err1, $rc1] = $this->finish($c1);
        [$out2, $err2, $rc2] = $this->finish($c2);
        $results = [$out1, $out2];
        sort($results);
        $this->assertSame(['launched', 'refused'], $results, "rc1=$rc1 err1=$err1 rc2=$rc2 err2=$err2");
        $records = array_filter(explode("\n", trim((string) file_get_contents($record))));
        $this->assertCount(1, $records, 'exactly one process must have launched check_run');
        $this->assertStringEndsWith(' check_run', reset($records));
        $this->assertSame('queued', $this->store()->status($id)['state']);
    }

    public function testLauncherFailureRestoresStatusAndRunConfig(): void
    {
        $id = $this->mapped();
        $before = $this->files($id);
        try {
            $this->failingStore()->startRun($id, $this->store()->revision($id));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame($before, $this->files($id), 'first run: run_config.json absent again, status as before');

        $id = $this->reviewed();
        $this->store()->reopenMap($id);
        $this->store()->saveSettings($id, 'en-US', null);
        $before = $this->files($id);
        try {
            $this->failingStore()->startRun($id, $this->store()->revision($id));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame($before, $this->files($id), 'previous run_config.json restored');
        $this->assertSame('map_review', $this->store()->status($id)['state']);
        $this->store()->startRun($id, $this->store()->revision($id));   // nije ostala rezervacija
        $this->assertSame('en-US', $this->store()->runConfig($id)['english']);
    }

    public function testBuildLauncherFailureLeavesJobReviewable(): void
    {
        $id = $this->reviewed();
        $before = $this->files($id);
        try {
            $this->failingStore()->startBuild($id);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame($before, $this->files($id));
        $this->store()->updateFinding($id, 'BK-002', ['review' => 'accepted']);
    }

    public function testEditsRefusedWhileBuildRunsWithFreshHeartbeat(): void
    {
        $id = $this->reviewed();
        $this->state($id, 'check_build');                       // runner živ: svjež heartbeat
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-002', ['review' => 'accepted']));
        $this->assertRefused(fn () => $this->store()->bulkReview($id, 'Typo', 'accepted'));
        $this->assertRefused(fn () => $this->store()->delete($id));
        $this->assertRefused(fn () => $this->store()->reopenMap($id));
        $this->assertRefused(fn () => $this->store()->startBuild($id));
        $this->assertSame('check_build', $this->store()->status($id)['state'], 'a status read must not free a live runner');
        $this->assertSame($before, $this->files($id));
        $this->assertSame([[$id, 'check_run']], $this->launched);
    }

    public function testReopenRestoresLockedConfigAndRefusalsChangeNothing(): void
    {
        $id = $this->reviewed();
        $job = $this->read($id, 'job.json');
        $job['english'] = 'en-US';                               // npr. zaostatak nekog starijeg spremanja
        $this->put($id, 'job.json', $job);
        $this->store()->reopenMap($id);
        $this->assertSame('en-GB', $this->store()->job($id)['english']);
        $this->assertSame(['u1', 'u2'], array_column($this->store()->map($id)['units'], 'id'));
        $this->assertSame('3', $this->store()->map($id)['units'][1]['start_label']);

        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->reopenMap($id));        // već map_review
        $this->assertRefused(fn () => $this->store()->retryMap($id));
        $this->assertRefused(fn () => $this->store()->startBuild($id));
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-002', ['review' => 'accepted']));
        $this->assertRefused(fn () => $this->store()->bulkReview($id, 'Typo', 'accepted'));
        $this->assertSame($before, $this->files($id));
    }

    public function testRetryMapOnlyWithoutMapAndBuildFromReview(): void
    {
        $id = $this->store()->create($this->upload(), 'X', null, 'en-GB', null);
        $this->state($id, 'failed');
        $this->store()->retryMap($id);
        $this->assertSame([[$id, 'check_map']], $this->launched);

        $id = $this->reviewed();
        $this->state($id, 'failed');
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->retryMap($id), 'map exists');
        $this->assertSame($before, $this->files($id));

        $this->state($id, 'review');
        $this->store()->startBuild($id);
        $this->assertSame([$id, 'check_build'], end($this->launched));
        $this->assertSame('queued', $this->store()->status($id)['state']);
    }

    public function testUpdateFindingMarksEditedKeepsRejectedAndValidates(): void
    {
        $id = $this->reviewed();
        $f = $this->store()->updateFinding($id, 'BK-002', ['status' => 'CORRECTION', 'replacement' => 'Smith']);
        $this->assertSame(['CORRECTION', 'Smith', 'edited'], [$f['status'], $f['replacement'], $f['review']]);
        $this->store()->updateFinding($id, 'BK-003', ['review' => 'rejected']);
        $f = $this->store()->updateFinding($id, 'BK-003', ['reason' => 'Late edit arriving after Reject.']);
        $this->assertSame('rejected', $f['review']);
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-003', ['status' => 'MAYBE']));
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-003', ['original' => ' ']));
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-003', ['page' => 9]));
        $this->assertRefused(fn () => $this->store()->updateFinding($id, 'BK-003', ['review' => 'edited']));
        $this->assertSame($before, $this->files($id));
    }

    public function testLocationEditResetsPlacement(): void
    {
        $id = $this->reviewed();
        $f = $this->store()->updateFinding($id, 'BK-001', ['page' => '1']);
        $this->assertSame([1, 'i', true], [$f['page'], $f['page_label'], $f['crop_stale']]);
        $this->assertArrayNotHasKey('placed_page', $f);
        $this->assertArrayNotHasKey('rect', $f);
        $f = $this->store()->updateFinding($id, 'BK-003', ['reason' => 'Subject is plural.']);
        $this->assertTrue($f['placed'], 'a non-location edit keeps the placement');
        $this->assertArrayNotHasKey('crop_stale', $f);
    }

    public function testEditAfterDoneMakesOutputsStale(): void
    {
        $id = $this->reviewed();
        foreach (['review_comments.pdf', 'review_report.html'] as $file) {
            file_put_contents("{$this->root}/$id/$file", 'x');
        }
        $this->state($id, 'done');
        $this->assertFileExists($this->store()->filePath($id, 'comments'));
        $this->store()->updateFinding($id, 'BK-001', ['review' => 'rejected']);
        $this->assertSame('review', $this->store()->status($id)['state']);
        $this->assertStringContainsString('build the PDF', $this->store()->status($id)['message']);
        $this->assertFileDoesNotExist("{$this->root}/$id/review_comments.pdf");
        $this->expectException(NotFoundException::class);
        $this->store()->filePath($id, 'report');
    }

    public function testBulkAfterDoneMakesOutputsStale(): void
    {
        $id = $this->reviewed();
        file_put_contents("{$this->root}/$id/review_report.html", 'x');
        $this->state($id, 'done');
        $this->assertSame(0, $this->store()->bulkReview($id, 'Spelling', 'accepted'));
        $this->assertSame('done', $this->store()->status($id)['state'], 'nothing changed — outputs stay valid');
        $this->assertSame(1, $this->store()->bulkReview($id, 'Grammar', 'rejected'));
        $this->assertSame('review', $this->store()->status($id)['state']);
        $this->assertFileDoesNotExist("{$this->root}/$id/review_report.html");
    }

    public function testUpdateFindingRefusedWhileBuilding(): void
    {
        $id = $this->reviewed();
        $this->state($id, 'check_build');
        $before = file_get_contents("{$this->root}/$id/findings.json");
        try {
            $this->store()->updateFinding($id, 'BK-002', ['review' => 'accepted']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame($before, file_get_contents("{$this->root}/$id/findings.json"));
    }

    public function testUnknownFinding(): void
    {
        $id = $this->reviewed();
        $before = $this->files($id);
        try {
            $this->store()->updateFinding($id, 'BK-999', ['review' => 'accepted']);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        $this->assertSame($before, $this->files($id));
    }

    public function testBulkAcceptsOnlyPendingInCategory(): void
    {
        $id = $this->reviewed();
        $this->assertSame(1, $this->store()->bulkReview($id, 'Typo', 'accepted'));
        $reviews = array_column($this->store()->findings($id), 'review', 'id');
        $this->assertSame(['BK-001' => 'accepted', 'BK-002' => 'accepted', 'BK-003' => 'pending'], $reviews);
        $before = $this->files($id);
        $this->assertRefused(fn () => $this->store()->bulkReview($id, 'Grammar', 'pending'));
        $this->assertSame($before, $this->files($id));
    }

    public function testMetricsUseTotalSpent(): void
    {
        $id = $this->reviewed();
        $this->store()->updateFinding($id, 'BK-003', ['review' => 'rejected']);
        // 0,01 (map_usage.json) + 0,6 (units.json); map.json `usage` se ne broji
        $this->assertSame(['findings' => 3, 'reviewed' => 2, 'pending' => 1, 'accepted' => 1, 'rejected' => 1,
            'edited' => 0, 'not_placed' => 1, 'failed_units' => 1, 'cost_usd' => 0.61], $this->store()->metrics($id));
    }

    public function testFilePathKindsAndCheckJobIsNotAPdfJob(): void
    {
        $id = $this->reviewed();
        mkdir("{$this->root}/$id/crops");
        file_put_contents("{$this->root}/$id/crops/BK-001.jpg", 'x');
        file_put_contents("{$this->root}/$id/all_findings.pdf", 'x');
        file_put_contents("{$this->root}/$id/review_comments.pdf", 'x');
        $this->assertStringEndsWith('crops/BK-001.jpg', $this->store()->filePath($id, 'crop:BK-001'));
        $this->assertStringEndsWith('all_findings.pdf', $this->store()->filePath($id, 'all'));
        try {
            $this->store()->filePath($id, 'comments');              // postoji, ali posao nije done
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        try {
            $this->store()->filePath($id, 'crop:../job');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        $this->expectException(NotFoundException::class);
        (new \App\Services\PdfJobStore($this->root))->status($id);
    }
}
