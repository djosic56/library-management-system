<?php
// check.php - AI korektura knjige: popis poslova i upload (svi prijavljeni korisnici)
require_once 'bootstrap.php';
require_once 'functions.php';
require_once 'pdf_config.php';
require_login();

use App\Exceptions\ValidationException;
use App\Repositories\CheckJobRepository;
use App\Services\CheckJobStore;

$store = check_job_store();
$repo = new CheckJobRepository(getDatabase()->getConnection());
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && CheckJobStore::isOversizedPost($_SERVER, $_POST, $_FILES)) {
    $error = 'The PDF is too large — maximum 200 MB';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (empty($_FILES['pdf'])) {
            throw new ValidationException('pdf', 'The PDF is too large — maximum 200 MB', 422);
        }
        $bookId = ($_POST['book_id'] ?? '') !== '' ? (int) $_POST['book_id'] : null;
        $bookTitle = null;
        foreach ($bookId !== null ? $repo->books() : [] as $b) {
            if ((int) $b['id'] === $bookId) { $bookTitle = $b['title']; break; }
        }
        $name = trim($_POST['name'] ?? '') ?: ($bookTitle ?? pathinfo($_FILES['pdf']['name'] ?? 'PDF', PATHINFO_FILENAME));
        $id = $store->create($_FILES['pdf'], mb_substr($name, 0, 255), $bookId, $_POST['english'] ?? 'en-GB',
            $_POST['instructions'] ?? null);
        $repo->insert($id, mb_substr($name, 0, 255), $bookId, (int) $_SESSION['user_id']);
        $store->start($id, 'check_map');
        log_action($_SESSION['user_id'], 'check_upload', $_SERVER['REMOTE_ADDR'] ?? '', $id);
        header('Location: check_job.php?id=' . $id);
        exit;
    } catch (ValidationException $e) {
        $error = $e->getUserMessage();
    }
}

$jobs = $repo->all();
$books = $repo->books();
$stateLabels = ['queued' => 'Queued', 'check_map' => 'Mapping…', 'map_review' => 'Check the map', 'check_run' => 'Checking…',
                'review' => 'Review', 'check_build' => 'Building PDF…', 'done' => 'Done', 'failed' => 'Error'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Book Check - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1 class="h3 mb-2"><i class="bi bi-spellcheck"></i> Book Check</h1>
		<p class="text-muted mb-4">AI proofreading of a book proof: spelling, grammar, punctuation, citations against the bibliography,
			figure and table references and leftover placeholders. You confirm the chapter map, review every finding (accept, reject or edit),
			and get a PDF with comments for the editor to apply manually in InDesign, plus a report.</p>
		<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

		<div class="card mb-4">
			<div class="card-header">New check</div>
			<div class="card-body">
				<form method="post" enctype="multipart/form-data" class="row g-3">
					<?php echo csrf_field(); ?>
					<div class="col-12 col-md-6">
						<label class="form-label" for="pdf">PDF proof (maximum 200 MB)</label>
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
					<div class="col-12 col-md-6">
						<label class="form-label" for="english">English</label>
						<select class="form-select" id="english" name="english">
							<option value="en-GB" selected>British English</option>
							<option value="en-US">American English</option>
						</select>
					</div>
					<div class="col-12">
						<label class="form-label" for="instructions">Special instructions for this book (optional)</label>
						<textarea class="form-control" id="instructions" name="instructions" rows="2" maxlength="2000"
							placeholder="e.g. The publisher uses -ize spellings; Harvard references"></textarea>
					</div>
					<div class="col-12 col-md-4 ms-auto">
						<button class="btn btn-primary w-100" type="submit"><i class="bi bi-upload"></i> Upload and map the book</button>
					</div>
				</form>
			</div>
		</div>

		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead><tr><th>Name</th><th>Book</th><th>Status</th><th class="text-end">Findings</th>
					<th class="text-end">Reviewed</th><th class="text-end">Accepted</th><th class="text-end">AI $</th><th>Date</th></tr></thead>
				<tbody>
				<?php foreach ($jobs as $j):
					try { $state = $store->status($j['id'])['state'] ?? $j['state']; }
					catch (\Throwable $e) { $state = 'failed'; }
					$n = fn ($k) => $j[$k] === null ? '' : (int) $j[$k]; ?>
					<tr>
						<td><a href="check_job.php?id=<?php echo urlencode($j['id']); ?>"><?php echo htmlspecialchars($j['name']); ?></a></td>
						<td><?php echo htmlspecialchars($j['book_title'] ?? ''); ?></td>
						<td><?php echo htmlspecialchars($stateLabels[$state] ?? $state); ?></td>
						<td class="text-end"><?php echo $n('findings'); ?></td>
						<td class="text-end"><?php echo $n('reviewed'); ?></td>
						<td class="text-end"><?php echo $n('accepted'); ?></td>
						<td class="text-end"><?php echo $j['cost_usd'] === null ? '' : number_format((float) $j['cost_usd'], 2); ?></td>
						<td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($j['created_at']))); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$jobs): ?><tr><td colspan="8" class="text-muted">No checks yet.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>
