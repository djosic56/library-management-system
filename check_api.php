<?php
// check_api.php - JSON API za Check poslove (svi prijavljeni korisnici)
require_once 'bootstrap.php';   // učitava config.php, koji pokreće sesiju
require_once 'functions.php';
require_once 'pdf_config.php';

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CheckJobRepository;

function check_json(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Bez redirecta na login: AJAX mora dobiti 401 da JS kaže „prijava je istekla“
if (!isset($_SESSION['user_id'])) {
    check_json(401, ['error' => 'Your session has expired — please log in again']);
}

$store = check_job_store();
$repo = new CheckJobRepository(getDatabase()->getConnection());
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id = $_GET['id'] ?? $_POST['id'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$int = fn ($v) => ($v === null || $v === '') ? null : (int) $v;

try {
    if ($post && !validate_csrf_token($_POST['csrf_token'] ?? '')) {
        check_json(403, ['error' => 'Security token expired — please reload the page']);
    }
    $needPost = ['save_map', 'start_run', 'reopen_map', 'retry_map', 'save_finding', 'bulk_review', 'start_build', 'delete'];
    if (in_array($action, $needPost, true) && !$post) {
        check_json(405, ['error' => 'POST']);
    }

    switch ($action) {
        case 'status':
            $status = $store->status($id);
            $metrics = $store->metrics($id);
            $repo->syncStatus($id, $status, $metrics);
            check_json(200, ['status' => $status, 'metrics' => $metrics, 'units' => $store->units($id)]);

        case 'save_map':
            // Mapa i postavke idu u JEDNU operaciju (saveMapAndSettings): oboje se validira prije
            // prvog zapisa i oba zapisa idu pod istim upravljačkim lockom (vidi Task 13, R4).
            $units = json_decode($_POST['units'] ?? '[]', true);
            if (!is_array($units)) {
                throw new ValidationException('map', 'The map could not be read', 422);
            }
            $result = $store->saveMapAndSettings($id, $units, $int($_POST['shared_start'] ?? null),
                $int($_POST['shared_end'] ?? null), $_POST['english'] ?? 'en-GB', $_POST['instructions'] ?? null);
            check_json(200, ['map' => $result['map']]);

        case 'preview_run':
            // affected i revision iz istog snimka pod jednim lockom (Task 13, R4) — ne affectedFindings +
            // proposedConfig/revision zasebno, jer bi spremanje druge kartice moglo upasti između čitanja.
            check_json(200, $store->previewRun($id));

        case 'start_run':
            $store->startRun($id, (string) ($_POST['revision'] ?? ''));
            log_action($_SESSION['user_id'], 'check_run', $_SERVER['REMOTE_ADDR'] ?? '', $id);
            check_json(200, ['ok' => true]);

        case 'reopen_map':
            $store->reopenMap($id);
            check_json(200, ['ok' => true]);

        case 'retry_map':
            $store->retryMap($id);
            check_json(200, ['ok' => true]);

        case 'save_finding':
            $fields = array_intersect_key($_POST, array_flip(['review', 'status', 'category', 'original', 'context_before',
                'context_after', 'replacement', 'reason', 'formatting', 'related', 'page']));
            check_json(200, ['finding' => $store->updateFinding($id, (string) ($_POST['finding'] ?? ''), $fields)]);

        case 'bulk_review':
            check_json(200, ['count' => $store->bulkReview($id, (string) ($_POST['category'] ?? ''), (string) ($_POST['review'] ?? ''))]);

        case 'start_build':
            $store->startBuild($id);
            log_action($_SESSION['user_id'], 'check_build', $_SERVER['REMOTE_ADDR'] ?? '', $id);
            check_json(200, ['ok' => true]);

        case 'delete':
            $store->delete($id);
            $repo->delete($id);
            log_action($_SESSION['user_id'], 'check_delete', $_SERVER['REMOTE_ADDR'] ?? '', $id);
            check_json(200, ['ok' => true]);

        case 'crop':
            // Cache-Control ostaje privatan/dugotrajan: stranica (Task 17) razbija keš s ?v=<crop_rev>
            $path = $store->filePath($id, 'crop:' . ($_GET['finding'] ?? ''));
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            readfile($path);
            exit;

        case 'download':
            $kind = $_GET['kind'] ?? '';
            $path = $store->filePath($id, $kind);
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $store->job($id)['name'] ?? 'book');
            $suffix = ['all' => '_all_findings.pdf', 'comments' => '_review_comments.pdf', 'report' => '_review_report.html'][$kind];
            header('Content-Type: ' . ($kind === 'report' ? 'text/html; charset=utf-8' : 'application/pdf'));
            header('Content-Disposition: attachment; filename="' . $name . $suffix . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
            exit;

        default:
            check_json(400, ['error' => 'Unknown action']);
    }
} catch (ValidationException $e) {
    check_json(422, ['error' => $e->getUserMessage()]);
} catch (NotFoundException $e) {
    check_json(404, ['error' => 'Not found (job, finding or file)']);
} catch (\Throwable $e) {
    error_log('check_api: ' . $e->getMessage());
    check_json(500, ['error' => 'Server error']);
}
