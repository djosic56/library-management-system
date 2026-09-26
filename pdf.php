<?php
// pdf.php - PDF pristupačnost: popis poslova i upload (samo admin)
require_once 'bootstrap.php';
require_once 'functions.php';
require_once 'pdf_config.php';
require_admin();

use App\Exceptions\ValidationException;
use App\Repositories\PdfJobRepository;
use App\Services\PdfJobStore;

$store = pdf_job_store();
$repo = new PdfJobRepository(getDatabase()->getConnection());
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && PdfJobStore::isOversizedPost($_SERVER, $_POST, $_FILES)) {
    $error = 'The PDF is too large — maximum 200 MB';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (empty($_FILES['pdf'])) {
            // post_max_size prekoračen → PHP odbaci cijeli POST i $_FILES je prazan
            throw new ValidationException('pdf', 'The PDF is too large — maximum 200 MB', 422);
        }
        $bookId = ($_POST['book_id'] ?? '') !== '' ? (int) $_POST['book_id'] : null;
        $title = null;
        if ($bookId !== null) {
            foreach ($repo->books() as $b) {
                if ((int) $b['id'] === $bookId) { $title = $b['title']; break; }
            }
        }
        $name = trim($_POST['name'] ?? '') ?: ($title ?? pathinfo($_FILES['pdf']['name'] ?? 'PDF', PATHINFO_FILENAME));
        $id = $store->create($_FILES['pdf'], mb_substr($name, 0, 255), $title, $_POST['lang'] ?? null, $bookId);
        $repo->insert($id, mb_substr($name, 0, 255), $bookId, (int) $_SESSION['user_id']);
        $store->start($id, 'phase1');
        log_action($_SESSION['user_id'], 'pdf_upload', $_SERVER['REMOTE_ADDR'] ?? '', $id);
        header('Location: pdf_job.php?id=' . $id);
        exit;
    } catch (ValidationException $e) {
        $error = $e->getUserMessage();
    }
}

$jobs = $repo->all();
$books = $repo->books();
$stateLabels = ['queued' => 'Queued', 'phase1' => 'AI descriptions…', 'review' => 'Review', 'phase2' => 'Building PDF…',
                'done' => 'Done', 'failed' => 'Error'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>PDF Accessibility - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1 class="h3 mb-4"><i class="bi bi-universal-access"></i> PDF Accessibility</h1>

		<?php if ($error): ?>
			<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
		<?php endif; ?>

		<div class="card mb-4">
			<div class="card-header">New PDF</div>
			<div class="card-body">
				<form method="post" enctype="multipart/form-data" class="row g-3">
					<?php echo csrf_field(); ?>
					<div class="col-12 col-md-6">
						<label class="form-label" for="pdf">PDF (maximum 200 MB)</label>
						<input class="form-control" type="file" id="pdf" name="pdf" accept="application/pdf" required>
					</div>
					<div class="col-12 col-md-6">
						<label class="form-label" for="book_id">Book (optional)</label>
						<select class="form-select" id="book_id" name="book_id">
							<option value="">— no book —</option>
							<?php foreach ($books as $b): ?>
								<option value="<?php echo (int) $b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-12 col-md-6">
						<label class="form-label" for="name">Job name (optional)</label>
						<input class="form-control" id="name" name="name" maxlength="255">
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label" for="lang">Language (optional)</label>
						<input class="form-control" id="lang" name="lang" placeholder="e.g. en-US" pattern="[a-z]{2}(-[A-Z]{2})?">
					</div>
					<div class="col-6 col-md-3 d-flex align-items-end">
						<button class="btn btn-primary w-100" type="submit"><i class="bi bi-upload"></i> Upload and process</button>
					</div>
				</form>
			</div>
		</div>

		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead><tr><th>Name</th><th>Book</th><th>Status</th><th class="text-end">Images</th>
					<th class="text-end">AI $</th><th>PDF/UA</th><th>Date</th></tr></thead>
				<tbody>
				<?php foreach ($jobs as $j):
					try { $state = $store->status($j['id'])['state'] ?? $j['state']; }
					catch (\Throwable $e) { $state = 'failed'; } ?>
					<tr>
						<td><a href="pdf_job.php?id=<?php echo urlencode($j['id']); ?>"><?php echo htmlspecialchars($j['name']); ?></a></td>
						<td><?php echo htmlspecialchars($j['book_title'] ?? ''); ?></td>
						<td><?php echo htmlspecialchars($stateLabels[$state] ?? $state); ?></td>
						<td class="text-end"><?php echo $j['images'] === null ? '' : (int) $j['images']; ?></td>
						<td class="text-end"><?php echo $j['cost_usd'] === null ? '' : number_format((float) $j['cost_usd'], 2); ?></td>
						<td><?php echo $j['pdfua_ok'] === null ? '' : ((int) $j['pdfua_ok'] ? '<span class="badge bg-success">passes</span>' : '<span class="badge bg-warning text-dark">fails</span>'); ?></td>
						<td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($j['created_at']))); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$jobs): ?><tr><td colspan="7" class="text-muted">No jobs yet.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>
