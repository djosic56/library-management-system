<?php
// pdf_api.php - JSON API za PDF poslove (samo admin)
require_once 'bootstrap.php';   // učitava config.php, koji pokreće sesiju
require_once 'functions.php';
require_once 'pdf_config.php';

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\PdfJobRepository;

function pdf_json(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Bez redirecta na login: AJAX mora dobiti 401 da JS kaže „prijava je istekla“
if (!isset($_SESSION['user_id']) || !is_admin()) {
    pdf_json(401, ['error' => 'Your session has expired — please log in again']);
}

$store = pdf_job_store();
$repo = new PdfJobRepository(getDatabase()->getConnection());
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id = $_GET['id'] ?? $_POST['id'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validate_csrf_token($_POST['csrf_token'] ?? '')) {
        pdf_json(403, ['error' => 'Security token expired — please reload the page']);
    }

    switch ($action) {
        case 'status':
            $status = $store->status($id);
            $dir = $store->dir($id);
            $summary = is_file("$dir/fix_summary.json")
                ? json_decode(file_get_contents("$dir/fix_summary.json"), true) : null;
            $repo->syncStatus($id, $status, in_array($status['state'] ?? '', ['review', 'done'], true)
                ? $store->descriptions($id) : null, $summary);
            pdf_json(200, ['status' => $status, 'summary' => $summary]);

        case 'save_image':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdf_json(405, ['error' => 'POST']);
            $fields = array_intersect_key($_POST, array_flip(['description', 'decorative', 'language']));
            pdf_json(200, ['image' => $store->updateImage($id, (int) ($_POST['xref'] ?? 0), $fields)]);

        case 'save_document':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdf_json(405, ['error' => 'POST']);
            pdf_json(200, ['job' => $store->updateDocument($id, $_POST['title'] ?? null, $_POST['lang'] ?? null)]);

        case 'start_phase2':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdf_json(405, ['error' => 'POST']);
            $store->start($id, 'phase2');
            log_action($_SESSION['user_id'], 'pdf_phase2', $_SERVER['REMOTE_ADDR'] ?? '', $id);
            pdf_json(200, ['ok' => true]);

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdf_json(405, ['error' => 'POST']);
            $store->delete($id);
            $repo->delete($id);
            log_action($_SESSION['user_id'], 'pdf_delete', $_SERVER['REMOTE_ADDR'] ?? '', $id);
            pdf_json(200, ['ok' => true]);

        case 'thumb':
            $path = $store->filePath($id, 'thumb:' . (int) ($_GET['xref'] ?? 0));
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            readfile($path);
            exit;

        case 'download':
            $kind = $_GET['kind'] ?? '';
            $path = $store->filePath($id, $kind);
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $store->job($id)['name'] ?? 'dokument');
            header('Content-Type: ' . ($kind === 'output' ? 'application/pdf' : 'text/html; charset=utf-8'));
            header('Content-Disposition: attachment; filename="' . $name . ($kind === 'output' ? '_pristupacno.pdf' : '_izvjestaj.html') . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
            exit;

        default:
            pdf_json(400, ['error' => 'Unknown action']);
    }
} catch (ValidationException $e) {
    pdf_json(422, ['error' => $e->getUserMessage()]);
} catch (NotFoundException $e) {
    pdf_json(404, ['error' => 'Not found (job, image or file)']);
} catch (\Throwable $e) {
    error_log('pdf_api: ' . $e->getMessage());
    pdf_json(500, ['error' => 'Server error']);
}
