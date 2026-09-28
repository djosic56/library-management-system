<?php

namespace App\Services;

use App\Exceptions\NotFoundException;

/**
 * PDF poslovi na disku (~/pdf_jobs/<id>/). Ne radi PDF obradu — samo JSON i pokretanje
 * Python runnera. Stanje obrade piše runner u status.json.
 */
class PdfJobStore extends JobStore
{
    protected const KIND = 'pdf';
    protected const PHASES = ['phase1', 'phase2'];
    protected const RUNNING = ['queued', 'phase1', 'phase2'];
    protected const EDIT_LOCKED = ['queued', 'phase2'];

    public function create(array $upload, string $name, ?string $title, ?string $lang, ?int $bookId): string
    {
        if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            throw self::invalid('lang', 'Invalid language code');
        }
        return $this->createJob($upload, [
            'name' => $name, 'title' => $title ?: null, 'lang' => $lang ?: null, 'book_id' => $bookId,
        ]);
    }

    public function descriptions(string $id): array
    {
        return $this->readJson($this->dir($id) . '/descriptions.json', ['images' => []]);
    }

    public function updateImage(string $id, int $xref, array $fields): array
    {
        $this->assertEditable($id);
        $clean = [];
        if (array_key_exists('description', $fields)) {
            $clean['description'] = mb_substr(trim((string) $fields['description']), 0, 2000);
        }
        if (array_key_exists('decorative', $fields)) {
            $clean['decorative'] = filter_var($fields['decorative'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('language', $fields)) {
            $lang = $fields['language'];
            if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}$/', (string) $lang)) {
                throw self::invalid('language', 'Invalid language code');
            }
            $clean['language'] = ($lang === '' ? null : $lang);
        }

        $updated = null;
        $this->withLockedJson($this->dir($id) . '/descriptions.json', function (array $data) use ($xref, $clean, &$updated) {
            foreach ($data['images'] ?? [] as $i => $image) {
                if ((int) $image['xref'] === $xref) {
                    $data['images'][$i] = array_merge($image, $clean);
                    $updated = $data['images'][$i];
                    return $data;
                }
            }
            throw new NotFoundException('pdf_image', $xref);
        });
        return $updated;
    }

    public function updateDocument(string $id, ?string $title, ?string $lang): array
    {
        $this->assertEditable($id);
        if ($lang !== null && $lang !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            throw self::invalid('lang', 'Invalid language code');
        }
        $job = null;
        $this->withLockedJson($this->dir($id) . '/job.json', function (array $data) use ($title, $lang, &$job) {
            $data['title'] = ($title === null || trim($title) === '') ? null : mb_substr(trim($title), 0, 500);
            $data['lang'] = $lang ?: null;
            $data['title_source'] = 'user';   // korisnik ga je upisao/potvrdio — više nije prijedlog
            return $job = $data;
        });
        return $job;
    }

    public function filePath(string $id, string $kind): string
    {
        $dir = $this->dir($id);
        if ($kind === 'output') {
            $path = $dir . '/output.pdf';
        } elseif ($kind === 'report') {
            $path = $dir . '/report.html';
        } elseif (preg_match('/^thumb:(\d+)$/', $kind, $m)) {
            $path = $dir . '/thumbs/' . $m[1] . '.jpg';
        } else {
            throw new NotFoundException('pdf_file', 0);
        }
        if (!is_file($path)) {
            throw new NotFoundException('pdf_file', 0);
        }
        return $path;
    }

    protected function assertEditable(string $id): void
    {
        if (in_array($this->status($id)['state'] ?? '', static::EDIT_LOCKED, true)) {
            throw self::invalid('state', 'The PDF is being built — edits are not possible until it finishes');
        }
    }
}
