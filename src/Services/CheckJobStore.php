<?php

namespace App\Services;

use App\Exceptions\NotFoundException;

/**
 * Check poslovi (AI korektura knjige): mapa knjige, zaključana konfiguracija runa, nalazi i pregled.
 * Obradu radi Python (faze check_map, check_run, check_build); ovdje su samo JSON-i posla.
 * Pravila poništavanja moraju ostati ista kao u check_run.run_book (Python).
 *
 * Sve upravljačke operacije rade unutar withJobLock: stanje se provjerava unutar locka, sve se
 * validira prije prvog zapisa, a odbijena operacija ne mijenja nijednu datoteku.
 */
class CheckJobStore extends JobStore
{
    protected const KIND = 'check';
    protected const PHASES = ['check_map', 'check_run', 'check_build'];
    protected const RUNNING = ['queued', 'check_map', 'check_run', 'check_build'];
    protected const EDIT_LOCKED = ['queued', 'check_map', 'check_run', 'check_build'];
    public const UNIT_KEYS = ['title', 'author', 'start', 'end', 'language', 'kind', 'notes', 'bibliography_start', 'bibliography_end'];
    public const KINDS = ['front', 'chapter', 'back', 'skip'];
    public const NOTES = ['footnotes', 'endnotes', 'none'];
    public const STATUSES = ['CORRECTION', 'QUERY', 'OPTIONAL STYLE'];
    public const REVIEWS = ['pending', 'accepted', 'rejected'];
    public const ENGLISH = ['en-GB', 'en-US'];
    private const MAX_INSTRUCTIONS = 2000;
    private const TEXT_FIELDS = ['category' => 100, 'original' => 500, 'context_before' => 200, 'context_after' => 200,
        'replacement' => 1000, 'reason' => 1000, 'formatting' => 500, 'related' => 500];
    private const LOCATION_FIELDS = ['page', 'original', 'context_before', 'context_after'];
    private const EDITOR_OUTPUTS = ['review_comments.pdf', 'review_report.html'];
    /**
     * Marker nedovršenog zapisa para map.json + job.json (svaki fajl je atomski, par nije): postoji od
     * prije prvog do nakon zadnjeg zapisa. Dok postoji, pregled i pokretanje su odbijeni, a novo
     * spremanje mape i postavki (ili ponovno otvaranje mape) ga uklanja.
     */
    private const PENDING = 'config.pending';

    public function create(array $upload, string $name, ?int $bookId, string $english, ?string $instructions): string
    {
        $settings = self::cleanSettings($english, $instructions);
        return $this->createJob($upload, ['name' => $name, 'book_id' => $bookId] + $settings);
    }

    public function map(string $id): array
    {
        return $this->readJson($this->dir($id) . '/map.json', []);
    }

    public function runConfig(string $id): ?array
    {
        $path = $this->dir($id) . '/run_config.json';
        return is_file($path) ? $this->readJson($path, []) : null;
    }

    public function units(string $id): array
    {
        return $this->readJson($this->dir($id) . '/units.json', ['units' => []])['units'] ?? [];
    }

    public function findings(string $id): array
    {
        return $this->readJson($this->dir($id) . '/findings.json', ['findings' => []])['findings'] ?? [];
    }

    /**
     * Samo mapa (zasebno, npr. za testove i interne pozive). API mora koristiti saveMapAndSettings —
     * ova metoda ne uklanja marker nedovršenog zapisa (config.pending).
     */
    public function saveMap(string $id, array $units, ?int $sharedStart, ?int $sharedEnd): array
    {
        return $this->withJobLock($id, function () use ($id, $units, $sharedStart, $sharedEnd) {
            $this->assertState($id, ['map_review'], 'The map can only be changed before the check — use “Change map / settings”');
            $changes = $this->prepareMap($id, $units, $sharedStart, $sharedEnd);
            return $this->mergeJson($this->dir($id) . '/map.json', $changes);
        });
    }

    /**
     * Samo postavke. API mora koristiti saveMapAndSettings — ova metoda ne uklanja marker
     * nedovršenog zapisa (config.pending).
     */
    public function saveSettings(string $id, string $english, ?string $instructions): array
    {
        return $this->withJobLock($id, function () use ($id, $english, $instructions) {
            $this->assertState($id, ['map_review'], 'Settings can only be changed before the check — use “Change map / settings”');
            $settings = self::cleanSettings($english, $instructions);
            return $this->mergeJson($this->dir($id) . '/job.json', $settings);
        });
    }

    /**
     * Mapa i postavke kao jedna upravljačka operacija: oboje se validira prije prvog zapisa, a oba
     * zapisa idu pod istim upravljačkim lockom (uz marker nedovršenog zapisa, vidi PENDING).
     * @return array{map: array, job: array}
     */
    public function saveMapAndSettings(string $id, array $units, ?int $sharedStart, ?int $sharedEnd,
                                       string $english, ?string $instructions): array
    {
        return $this->withJobLock($id, function () use ($id, $units, $sharedStart, $sharedEnd, $english, $instructions) {
            $this->assertState($id, ['map_review'], 'The map and settings can only be changed before the check — use “Change map / settings”');
            $changes = $this->prepareMap($id, $units, $sharedStart, $sharedEnd);
            $settings = self::cleanSettings($english, $instructions);
            return $this->writeConfig($id, $changes, $settings);
        });
    }

    /** Konfiguracija koju bi „Check the book“ sada zaključao. */
    public function proposedConfig(string $id): array
    {
        return $this->withJobLock($id, fn () => self::configOf($this->map($id), $this->job($id)));
    }

    /** Otisak predložene i zadnje zaključane konfiguracije (i nalaza) — pregled posljedica i pokretanje moraju se slagati. */
    public function revision(string $id): string
    {
        return $this->withJobLock($id, fn () => self::revisionOf($this->snapshot($id)));
    }

    /** Koliko postojećih nalaza nestaje ako se pokrene ova konfiguracija (ista pravila kao run_book). */
    public function affectedFindings(string $id, array $config): int
    {
        return $this->withJobLock($id, fn () => self::affectedOf($this->units($id), $this->findings($id), $config));
    }

    /**
     * Pregled prije „Check the book“: `affected` i `revision` iz ISTOG snimka pod jednim lockom —
     * spremanje druge kartice ne može upasti između dvaju čitanja.
     * @return array{affected: int, revision: string}
     */
    public function previewRun(string $id): array
    {
        return $this->withJobLock($id, function () use ($id) {
            $this->assertState($id, ['map_review'], 'The check can only be started from the map');
            $snapshot = $this->snapshot($id);
            $this->assertRunnable($id, $snapshot);
            return [
                'affected' => self::affectedOf($snapshot['units'], $snapshot['findings'], self::configOf($snapshot['map'], $snapshot['job'])),
                'revision' => self::revisionOf($snapshot),
            ];
        });
    }

    public function startRun(string $id, string $revision): void
    {
        $this->withJobLock($id, function () use ($id, $revision) {
            // map_review isključuje i postojeću rezervaciju (queued) i runner koji radi
            $this->assertState($id, ['map_review'], 'The check can only be started from the map');
            $snapshot = $this->snapshot($id);
            if (!hash_equals(self::revisionOf($snapshot), $revision)) {
                throw self::invalid('revision', 'The map or settings changed after the preview — click “Check the book” again');
            }
            $this->assertRunnable($id, $snapshot);
            $config = self::configOf($snapshot['map'], $snapshot['job']);
            $config['locked_at'] = self::now();
            $path = $this->dir($id) . '/run_config.json';
            $previous = is_file($path) ? (string) file_get_contents($path) : null;
            $this->writeJson($path, $config);
            try {
                $this->start($id, 'check_run');       // start() pri grešci launchera sam vraća status
            } catch (\Throwable $e) {
                $this->restoreFile($path, $previous);
                throw $e;
            }
        });
    }

    /** „Change map / settings“: natrag na mapu, koja kreće od zadnje zaključane konfiguracije. */
    public function reopenMap(string $id): void
    {
        $this->withJobLock($id, function () use ($id) {
            $this->assertState($id, ['review', 'done', 'failed'], 'The map cannot be reopened now');
            $config = $this->runConfig($id);
            if ($config === null || !is_file($this->dir($id) . '/map.json')) {
                throw self::invalid('state', 'There is no map yet — start the mapping again');
            }
            $changes = $this->prepareMap($id, $config['units'] ?? [], $config['shared_bibliography_start'] ?? null,
                $config['shared_bibliography_end'] ?? null);
            $settings = self::cleanSettings((string) ($config['english'] ?? ''), $config['instructions'] ?? null);
            $this->writeConfig($id, $changes, $settings, ['state' => 'map_review', 'message' => null]);
        });
    }

    /** Mapiranje nije uspjelo (nema map.json) → pokreni ga ponovno. */
    public function retryMap(string $id): void
    {
        $this->withJobLock($id, function () use ($id) {
            $this->assertState($id, ['failed'], 'Mapping can only be retried after an error');
            if (is_file($this->dir($id) . '/map.json')) {
                throw self::invalid('state', 'The map exists — use “Change map / settings”');
            }
            $this->start($id, 'check_map');
        });
    }

    public function startBuild(string $id): void
    {
        $this->withJobLock($id, function () use ($id) {
            $this->assertState($id, ['review', 'done'], 'The PDF can be built after the check');
            $this->start($id, 'check_build');
        });
    }

    public function updateFinding(string $id, string $findingId, array $fields): array
    {
        return $this->withJobLock($id, function () use ($id, $findingId, $fields) {
            $this->assertEditable($id);
            $this->assertState($id, ['review', 'done'], 'Findings can be reviewed after the check');
            $changes = $this->cleanFindingFields($id, $fields);
            $review = null;
            if (array_key_exists('review', $fields)) {
                if (!in_array($fields['review'], self::REVIEWS, true)) {
                    throw self::invalid('review', 'Unknown review decision');
                }
                $review = $fields['review'];
            }
            $labels = $this->map($id)['page_labels'] ?? [];
            $unitRanges = [];
            foreach (($this->runConfig($id) ?? [])['units'] ?? [] as $u) {
                $unitRanges[(string) ($u['id'] ?? '')] = [$u['start'] ?? null, $u['end'] ?? null];
            }
            $updated = null;
            $changed = false;
            $this->withLockedJson($this->dir($id) . '/findings.json', function (array $data) use ($findingId, $changes, $review, $labels, $unitRanges, &$updated, &$changed) {
                foreach ($data['findings'] ?? [] as $i => $f) {
                    if ($f['id'] !== $findingId) {
                        continue;
                    }
                    // nalaz smije ostati samo unutar cjeline koju je AI stvarno provjerio (isto kao Python)
                    if (array_key_exists('page', $changes)) {
                        [$lo, $hi] = $unitRanges[(string) ($f['unit'] ?? '')] ?? [null, null];
                        if ($lo === null || $changes['page'] < $lo || $changes['page'] > $hi) {
                            throw self::invalid('page', 'The page must stay inside the finding’s unit');
                        }
                    }
                    $original = $f;
                    $moved = array_filter(self::LOCATION_FIELDS, fn ($k) => array_key_exists($k, $changes) && $changes[$k] !== ($f[$k] ?? null));
                    $f = array_merge($f, $changes);
                    if ($moved) {
                        unset($f['placed'], $f['placed_page'], $f['rect'], $f['place_status']);
                        $f['page_label'] = $labels[$f['page'] - 1] ?? (string) $f['page'];
                        $f['crop_stale'] = true;
                    }
                    if ($review !== null) {
                        $f['review'] = $review;
                    } elseif ($changes && ($f['review'] ?? 'pending') !== 'rejected') {
                        $f['review'] = 'edited';
                    }
                    // npr. ponovni klik na „Accept“ na već prihvaćenom nalazu — ništa se stvarno ne mijenja,
                    // pa PDF za urednika/izvještaj ne smiju biti obrisani (M2)
                    $changed = $f !== $original;
                    $data['findings'][$i] = $updated = $f;
                    return $data;
                }
                throw new NotFoundException('finding', 0);   // prije zapisa — findings.json ostaje isti
            });
            if ($changed) {
                $this->markOutputsStale($id);
            }
            return $updated;
        });
    }

    public function bulkReview(string $id, string $category, string $review): int
    {
        return $this->withJobLock($id, function () use ($id, $category, $review) {
            $this->assertEditable($id);
            $this->assertState($id, ['review', 'done'], 'Findings can be reviewed after the check');
            if (!in_array($review, ['accepted', 'rejected'], true)) {
                throw self::invalid('review', 'Unknown review decision');
            }
            $count = 0;
            $this->withLockedJson($this->dir($id) . '/findings.json', function (array $data) use ($category, $review, &$count) {
                foreach ($data['findings'] ?? [] as $i => $f) {
                    if (($f['category'] ?? null) === $category && ($f['review'] ?? 'pending') === 'pending') {
                        $data['findings'][$i]['review'] = $review;
                        $count++;
                    }
                }
                return $data;
            });
            if ($count > 0) {
                $this->markOutputsStale($id);
            }
            return $count;
        });
    }

    /** cost_usd = sva mapiranja (map_usage.json) + sve provjere cjelina (units.json); map.json `usage` nije izvor. */
    public function metrics(string $id): array
    {
        $dir = $this->dir($id);
        $findings = $this->findings($id);
        $count = fn (string $r) => count(array_filter($findings, fn ($f) => ($f['review'] ?? 'pending') === $r));
        $units = $this->units($id);
        $mapSpent = (float) ($this->readJson($dir . '/map_usage.json', [])['spent_usd'] ?? 0);
        $unitsSpent = (float) ($this->readJson($dir . '/units.json', [])['spent_usd'] ?? 0);
        return [
            'findings' => count($findings), 'reviewed' => count($findings) - $count('pending'),
            'pending' => $count('pending'), 'accepted' => $count('accepted'), 'rejected' => $count('rejected'),
            'edited' => $count('edited'),
            'not_placed' => count(array_filter($findings, fn ($f) => ($f['placed'] ?? null) === false)),
            'failed_units' => count(array_filter($units, fn ($u) => ($u['state'] ?? '') === 'failed')),
            'cost_usd' => round($mapSpent + $unitsSpent, 2),
        ];
    }

    public function filePath(string $id, string $kind): string
    {
        $dir = $this->dir($id);
        $files = ['all' => 'all_findings.pdf', 'comments' => 'review_comments.pdf', 'report' => 'review_report.html'];
        if (isset($files[$kind])) {
            // PDF za urednika i izvještaj vrijede samo dok su usklađeni s nalazima (stanje done)
            if ($kind !== 'all' && ($this->status($id)['state'] ?? '') !== 'done') {
                throw new NotFoundException('check_file', 0);
            }
            $path = $dir . '/' . $files[$kind];
        } elseif (preg_match('/^crop:(BK-\d{3,5})\z/', $kind, $m)) {
            $path = $dir . '/crops/' . $m[1] . '.jpg';
        } else {
            throw new NotFoundException('check_file', 0);
        }
        if (!is_file($path)) {
            throw new NotFoundException('check_file', 0);
        }
        return $path;
    }

    /** Jedno čitanje svih ulaza pregleda/pokretanja (poziva se unutar withJobLock). */
    private function snapshot(string $id): array
    {
        return ['map' => $this->map($id), 'job' => $this->job($id), 'run' => $this->runConfig($id), 'findings' => $this->findings($id),
            'units' => $this->units($id)];
    }

    private static function configOf(array $map, array $job): array
    {
        return [
            'units' => array_map(fn ($u) => self::cleanMapUnit(is_array($u) ? $u : []), $map['units'] ?? []),
            'shared_bibliography_start' => $map['shared_bibliography_start'] ?? null,
            'shared_bibliography_end' => $map['shared_bibliography_end'] ?? null,
            'english' => $job['english'] ?? 'en-GB',
            'instructions' => $job['instructions'] ?? '',
        ];
    }

    private static function revisionOf(array $snapshot): string
    {
        return sha1(json_encode([self::configOf($snapshot['map'], $snapshot['job']), $snapshot['run'], $snapshot['findings'], $snapshot['units']]));
    }

    /**
     * Isto kao run_book (R15): nalaz preživi samo ako je njegova cjelina u units.json „completed“ i
     * njezin „checked“ (s čime je stvarno provjerena) jednak je predloženoj konfiguraciji — ista cjelina
     * (po id-u, vrijednosti UNIT_KEYS istog tipa), zajednička bibliografija i postavke. Sve ostalo
     * (cjelina uklonjena/promijenjena, nedovršena, stariji posao bez „checked“) run_book briše.
     * Model i verzija uputa ne poništavaju ništa.
     */
    private static function affectedOf(array $units, array $findings, array $config): int
    {
        $proposed = [];
        foreach ($config['units'] ?? [] as $u) {
            $proposed[(string) ($u['id'] ?? '')] = [
                'unit' => self::unitValues($u),
                'shared' => [$config['shared_bibliography_start'] ?? null, $config['shared_bibliography_end'] ?? null],
                'settings' => [$config['english'] ?? null, $config['instructions'] ?? null],
            ];
        }
        $kept = [];
        foreach ($units as $uid => $state) {
            $checked = $state['checked'] ?? null;
            if (($state['state'] ?? '') !== 'completed' || !is_array($checked) || !isset($proposed[$uid])) {
                continue;
            }
            $was = ['unit' => self::unitValues(is_array($checked['unit'] ?? null) ? $checked['unit'] : []),
                'shared' => $checked['shared'] ?? null, 'settings' => $checked['settings'] ?? null];
            if ($was === $proposed[$uid]) {
                $kept[(string) $uid] = true;
            }
        }
        return count(array_filter($findings, fn ($f) => !isset($kept[(string) ($f['unit'] ?? '')])));
    }

    /** Oporavak/validacija prije pokretanja: cijela predložena konfiguracija mora biti potpuna i valjana. */
    private function assertRunnable(string $id, array $snapshot): void
    {
        if (is_file($this->dir($id) . '/' . self::PENDING)) {
            throw self::invalid('map', 'The last save of the map and settings did not finish — save them again');
        }
        $config = self::configOf($snapshot['map'], $snapshot['job']);
        foreach ($config['units'] as $u) {
            if (!preg_match('/^u\d+\z/', (string) $u['id'])) {
                throw self::invalid('map', 'A unit of the map has no id — save the map again');
            }
        }
        self::validateMap($config['units'], $config['shared_bibliography_start'], $config['shared_bibliography_end'],
            (int) ($snapshot['map']['page_count'] ?? 0));
        if (!in_array($config['english'], self::ENGLISH, true)) {
            throw self::invalid('english', 'Choose British or American English');
        }
        if (!is_string($config['instructions']) || mb_strlen($config['instructions']) > self::MAX_INSTRUCTIONS) {
            throw self::invalid('instructions', 'The instructions are too long — save the settings again');
        }
    }

    /** Validira mapu i vraća izmjene za map.json (cjeline s trajnim id-om i oznakama stranica) — ništa ne piše. */
    private function prepareMap(string $id, array $units, ?int $sharedStart, ?int $sharedEnd): array
    {
        $map = $this->map($id);
        $clean = array_map(fn ($u) => self::cleanUnit(is_array($u) ? $u : []), $units);
        usort($clean, fn ($a, $b) => $a['start'] <=> $b['start']);
        self::validateMap($clean, $sharedStart, $sharedEnd, (int) ($map['page_count'] ?? 0));
        // nova cjelina dobiva sljedeći slobodni trajni ID
        $next = self::maxUnitNumber(array_merge($map['units'] ?? [], $this->runConfig($id)['units'] ?? [], $clean));
        $labels = $map['page_labels'] ?? [];
        foreach ($clean as &$u) {
            if ($u['id'] === '') {
                $u['id'] = 'u' . (++$next);
            }
            $u['start_label'] = $labels[$u['start'] - 1] ?? (string) $u['start'];
            $u['end_label'] = $labels[$u['end'] - 1] ?? (string) $u['end'];
        }
        unset($u);
        return ['units' => $clean, 'shared_bibliography_start' => $sharedStart, 'shared_bibliography_end' => $sharedEnd];
    }

    /**
     * Zapis već validiranog para mapa + postavke (i po potrebi statusa) uz marker nedovršenog zapisa.
     * @return array{map: array, job: array}
     */
    private function writeConfig(string $id, array $mapChanges, array $settings, ?array $status = null): array
    {
        $dir = $this->dir($id);
        if (file_put_contents($dir . '/' . self::PENDING, self::now()) === false) {
            throw new \RuntimeException('Ne mogu spremiti mapu');
        }
        $map = $this->mergeJson($dir . '/map.json', $mapChanges);
        $job = $this->mergeJson($dir . '/job.json', $settings);
        if ($status !== null) {
            $this->mergeJson($dir . '/status.json', $status);
        }
        unlink($dir . '/' . self::PENDING);
        return ['map' => $map, 'job' => $job];
    }

    /** Nakon izmjene nalaza PDF za urednika i izvještaj više nisu točni — ukloni ih i vrati posao u review. */
    private function markOutputsStale(string $id): void
    {
        if (($this->status($id)['state'] ?? '') !== 'done') {
            return;
        }
        foreach (self::EDITOR_OUTPUTS as $file) {
            @unlink($this->dir($id) . '/' . $file);
        }
        $this->mergeJson($this->dir($id) . '/status.json',
            ['state' => 'review', 'message' => 'Findings changed — build the PDF for the editor again.']);
    }

    private function cleanFindingFields(string $id, array $fields): array
    {
        $changes = [];
        foreach (self::TEXT_FIELDS as $key => $max) {
            if (array_key_exists($key, $fields)) {
                $value = trim((string) $fields[$key]);
                if (in_array($key, ['original', 'category', 'reason'], true) && $value === '') {
                    throw self::invalid($key, ucfirst($key) . ' cannot be empty');
                }
                $changes[$key] = $value === '' ? null : mb_substr($value, 0, $max);
            }
        }
        if (array_key_exists('status', $fields)) {
            if (!in_array($fields['status'], self::STATUSES, true)) {
                throw self::invalid('status', 'Unknown status');
            }
            $changes['status'] = $fields['status'];
        }
        if (array_key_exists('page', $fields)) {
            $page = (int) $fields['page'];
            $pages = (int) ($this->map($id)['page_count'] ?? 0);
            if ($page < 1 || ($pages > 0 && $page > $pages)) {
                throw self::invalid('page', 'The page is outside the document');
            }
            $changes['page'] = $page;
        }
        return $changes;
    }

    private function assertState(string $id, array $states, string $message): void
    {
        if (!in_array($this->status($id)['state'] ?? '', $states, true)) {
            throw self::invalid('state', $message);
        }
    }

    /** Vrijednosti UNIT_KEYS redom (strogo uspoređivanje: 5 i "5" se razlikuju, kao u Pythonu). */
    private static function unitValues(array $u): array
    {
        return array_map(fn ($k) => array_key_exists($k, $u) ? $u[$k] : null, self::UNIT_KEYS);
    }

    private static function maxUnitNumber(array $units): int
    {
        $max = 0;
        foreach ($units as $u) {
            if (preg_match('/^u(\d+)\z/', (string) ($u['id'] ?? ''), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }

    /** @return array{english: string, instructions: string} */
    private static function cleanSettings(string $english, ?string $instructions): array
    {
        if (!in_array($english, self::ENGLISH, true)) {
            throw self::invalid('english', 'Choose British or American English');
        }
        return ['english' => $english, 'instructions' => mb_substr(trim((string) $instructions), 0, self::MAX_INSTRUCTIONS)];
    }

    /**
     * cleanUnit primijenjen na već spremljenu cjelinu iz map.json, s id-om zadržanim doslovno (već je
     * dodijeljen — ne prolazi regex provjeru novog unosa iz forme). Bez ovoga stariji map.json (upisan
     * prije nego je Python build_map počeo normalizirati, ili općenito bilo koja sirova vrijednost)
     * razlikuje se od onoga što reopenMap/prepareMap upišu nakon poništavanja bez izmjena, pa
     * affectedOf/previewRun lažno prijave promjenu (I1).
     */
    private static function cleanMapUnit(array $u): array
    {
        $clean = self::cleanUnit($u);
        $clean['id'] = (string) ($u['id'] ?? '');
        return $clean;
    }

    private static function cleanUnit(array $u): array
    {
        $int = fn ($v) => ($v === null || $v === '') ? null : (int) $v;
        return [
            'id' => preg_match('/^u\d+\z/', (string) ($u['id'] ?? '')) ? (string) $u['id'] : '',
            'title' => mb_substr(trim((string) ($u['title'] ?? '')), 0, 300) ?: 'Untitled',
            'author' => ($a = trim((string) ($u['author'] ?? ''))) === '' ? null : mb_substr($a, 0, 300),
            'start' => (int) ($u['start'] ?? 0), 'end' => (int) ($u['end'] ?? 0),
            'language' => (string) ($u['language'] ?? ''), 'kind' => (string) ($u['kind'] ?? ''),
            'notes' => (string) ($u['notes'] ?? 'none'),
            'bibliography_start' => $int($u['bibliography_start'] ?? null),
            'bibliography_end' => $int($u['bibliography_end'] ?? null),
        ];
    }

    /** Ista pravila kao check_map.validate_map u Pythonu (+ jedinstveni ID-ovi). */
    private static function validateMap(array $units, ?int $sharedStart, ?int $sharedEnd, int $pages): void
    {
        if (!$units) {
            throw self::invalid('map', 'The map has no units');
        }
        $ids = array_filter(array_column($units, 'id'), fn ($i) => $i !== '');
        if (count($ids) !== count(array_unique($ids))) {
            throw self::invalid('map', 'The map has a duplicate unit id — reload the page');
        }
        usort($units, fn ($a, $b) => $a['start'] <=> $b['start']);
        $previousEnd = 0;
        foreach ($units as $u) {
            $name = '“' . $u['title'] . '”';
            if (!($u['start'] >= 1 && $u['start'] <= $u['end'] && $u['end'] <= $pages)) {
                throw self::invalid('map', "$name: pages {$u['start']}–{$u['end']} are outside the document (1–$pages)");
            }
            if ($u['start'] <= $previousEnd) {
                throw self::invalid('map', "$name: pages overlap the previous unit");
            }
            $previousEnd = $u['end'];
            if (!preg_match('/^[a-z]{2}\z/', (string) $u['language'])) {
                throw self::invalid('map', "$name: language must be a two-letter code such as en or fr");
            }
            if (!in_array($u['kind'], self::KINDS, true) || !in_array($u['notes'], self::NOTES, true)) {
                throw self::invalid('map', "$name: unknown kind or notes");
            }
            $bs = $u['bibliography_start'];
            $be = $u['bibliography_end'];
            if (($bs === null) !== ($be === null) || ($bs !== null && !($u['start'] <= $bs && $bs <= $be && $be <= $u['end']))) {
                throw self::invalid('map', "$name: bibliography pages must be inside the unit (both first and last page)");
            }
        }
        if (($sharedStart === null) !== ($sharedEnd === null)
            || ($sharedStart !== null && !(1 <= $sharedStart && $sharedStart <= $sharedEnd && $sharedEnd <= $pages))) {
            throw self::invalid('map', 'Shared bibliography pages are outside the document');
        }
    }
}
