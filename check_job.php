<?php
// check_job.php - mapa knjige, napredak i pregled nalaza jednog Check posla (svi prijavljeni korisnici)
require_once 'bootstrap.php';
require_once 'functions.php';
require_once 'pdf_config.php';
require_login();

use App\Exceptions\NotFoundException;
use App\Services\CheckJobStore;

$store = check_job_store();
$id = $_GET['id'] ?? '';
try {
    $job = $store->job($id);
    $status = $store->status($id);
    $map = $store->map($id);
    $config = $store->runConfig($id);
    $findings = $store->findings($id);
    $unitStates = $store->units($id);   // units.json: model i verzija prompta kojom je cjelina provjerena
} catch (NotFoundException $e) {
    http_response_code(404);
    die('Job not found');
}
$state = $status['state'] ?? '';
$editable = $state === 'map_review';
$mapUnits = $map['units'] ?? [];
$unitTitles = array_column($config['units'] ?? $mapUnits, 'title', 'id');
$categories = array_values(array_unique(array_column($findings, 'category')));
sort($categories);
$h = fn ($s) => htmlspecialchars((string) $s);
$q = fn (array $p) => 'check_api.php?' . http_build_query(['id' => $id] + $p);
$dis = $editable ? '' : 'disabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $h($job['name'] ?? 'Check'); ?> - Book Check</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<style>
		.crop { width: 100%; max-height: 140px; object-fit: contain; object-position: left; background: #f4f4f4; }
		.save-state { font-size: .8rem; min-height: 1.2em; }
		.map-table input, .map-table select { min-width: 4rem; }
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4" id="check-job"
		data-id="<?php echo $h($id); ?>" data-csrf="<?php echo $h(generate_csrf_token()); ?>"
		data-state="<?php echo $h($state); ?>"
		data-page-words="<?php echo $h(json_encode($map['page_words'] ?? [])); ?>"
		data-prices="<?php echo $h(json_encode($map['prices'] ?? new stdClass())); ?>">

		<a href="check.php" class="btn btn-link px-0"><i class="bi bi-arrow-left"></i> All checks</a>
		<h1 class="h4"><?php echo $h($job['name'] ?? ''); ?></h1>
		<div id="status-box" class="alert alert-secondary"></div>
		<div id="progress" class="progress mb-3 d-none"><div class="progress-bar" style="width:0%"></div></div>
		<div id="metrics" class="mb-3 fw-semibold"></div>

		<?php if ($state === 'failed' && !$map): ?>
			<button id="retry-map" class="btn btn-primary mb-3"><i class="bi bi-arrow-repeat"></i> Retry mapping</button>
		<?php endif; ?>

		<?php if ($mapUnits): ?>
		<div class="card mb-4">
			<div class="card-header d-flex align-items-center">
				<span>Chapter map <?php echo $editable ? '' : '<span class="badge bg-secondary ms-2">locked</span>'; ?></span>
				<span class="save-state ms-auto" id="map-save"></span>
			</div>
			<div class="card-body">
				<div class="table-responsive">
				<table class="table table-sm align-middle map-table">
					<thead><tr><th>Title</th><th>Author</th><th>From</th><th>To</th><th>Lang</th><th>Kind</th><th>Notes</th><th>Bibl. from</th><th>Bibl. to</th><th>Model</th><th>Prompt</th><th></th></tr></thead>
					<tbody>
					<?php foreach ($mapUnits as $i => $u): ?>
						<tr class="map-row">
							<?php foreach (['title' => 'text', 'author' => 'text', 'start' => 'number', 'end' => 'number', 'language' => 'text'] as $k => $type): ?>
								<td><?php if ($k === 'title'): ?><input type="hidden" class="map-field" data-field="id" value="<?php echo $h($u['id'] ?? ''); ?>"><?php endif; ?>
									<input class="form-control form-control-sm map-field" data-field="<?php echo $k; ?>" type="<?php echo $type; ?>"
									value="<?php echo $h($u[$k] ?? ''); ?>" <?php echo $dis; ?> aria-label="<?php echo $k; ?>">
									<?php if ($k === 'start' || $k === 'end'): ?><div class="form-text">p. <?php echo $h($u[$k . '_label'] ?? ''); ?></div><?php endif; ?></td>
							<?php endforeach; ?>
							<?php foreach (['kind' => CheckJobStore::KINDS, 'notes' => CheckJobStore::NOTES] as $k => $options): ?>
								<td><select class="form-select form-select-sm map-field" data-field="<?php echo $k; ?>" <?php echo $dis; ?> aria-label="<?php echo $k; ?>">
									<?php foreach ($options as $o): ?><option <?php echo ($u[$k] ?? '') === $o ? 'selected' : ''; ?>><?php echo $o; ?></option><?php endforeach; ?>
								</select></td>
							<?php endforeach; ?>
							<?php foreach (['bibliography_start', 'bibliography_end'] as $k): ?>
								<td><input class="form-control form-control-sm map-field" data-field="<?php echo $k; ?>" type="number"
									value="<?php echo $h($u[$k] ?? ''); ?>" <?php echo $dis; ?> aria-label="<?php echo $k; ?>"></td>
							<?php endforeach; ?>
							<?php $checked = $unitStates[$u['id'] ?? ''] ?? []; ?>
							<td class="small text-muted"><?php echo $h($checked['model'] ?? ''); ?></td>
							<td class="small text-muted"><?php echo $h($checked['prompt_version'] ?? ''); ?></td>
							<td><?php if ($editable): ?><button class="btn btn-sm btn-outline-danger remove-unit" data-index="<?php echo (int) $i; ?>"
								aria-label="Remove unit"><i class="bi bi-x"></i></button><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php if ($editable): ?><button id="add-unit" class="btn btn-sm btn-outline-primary mb-3"><i class="bi bi-plus"></i> Add unit</button><?php endif; ?>
				<div id="uncovered" class="text-warning small mb-2"></div>
				<div class="row g-2">
					<div class="col-6 col-md-2"><label class="form-label" for="shared-start">Shared bibl. from</label>
						<input class="form-control" id="shared-start" type="number" value="<?php echo $h($map['shared_bibliography_start'] ?? ''); ?>" <?php echo $dis; ?>></div>
					<div class="col-6 col-md-2"><label class="form-label" for="shared-end">to</label>
						<input class="form-control" id="shared-end" type="number" value="<?php echo $h($map['shared_bibliography_end'] ?? ''); ?>" <?php echo $dis; ?>></div>
					<div class="col-12 col-md-3"><label class="form-label" for="english">English</label>
						<select class="form-select" id="english" <?php echo $dis; ?>>
							<option value="en-GB" <?php echo ($job['english'] ?? 'en-GB') === 'en-GB' ? 'selected' : ''; ?>>British English</option>
							<option value="en-US" <?php echo ($job['english'] ?? '') === 'en-US' ? 'selected' : ''; ?>>American English</option>
						</select></div>
					<div class="col-12 col-md-5"><label class="form-label" for="instructions">Special instructions</label>
						<textarea class="form-control" id="instructions" rows="1" maxlength="2000" <?php echo $dis; ?>><?php echo $h($job['instructions'] ?? ''); ?></textarea></div>
				</div>
				<div class="d-flex flex-wrap gap-2 align-items-center mt-3">
					<span id="estimate" class="text-muted"></span>
					<?php if ($editable): ?>
						<button id="check-book" class="btn btn-success ms-auto"><i class="bi bi-play-fill"></i> Check the book</button>
					<?php elseif (in_array($state, ['review', 'done', 'failed'], true)): ?>
						<button id="reopen-map" class="btn btn-outline-secondary ms-auto"><i class="bi bi-pencil"></i> Change map / settings</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<?php if (in_array($state, ['review', 'check_build', 'done'], true)): ?>
			<div class="d-flex flex-wrap gap-2 mb-3">
				<a class="btn btn-outline-primary" href="<?php echo $h($q(['action' => 'download', 'kind' => 'all'])); ?>"><i class="bi bi-file-earmark-pdf"></i> All findings (PDF)</a>
				<?php if ($state === 'done'): ?>
					<span id="editor-outputs" class="d-flex flex-wrap gap-2">
						<a class="btn btn-primary" href="<?php echo $h($q(['action' => 'download', 'kind' => 'comments'])); ?>"><i class="bi bi-file-earmark-check"></i> PDF for the editor</a>
						<a class="btn btn-outline-primary" href="<?php echo $h($q(['action' => 'download', 'kind' => 'report'])); ?>"><i class="bi bi-file-text"></i> Report</a>
					</span>
				<?php endif; ?>
				<button id="build-pdf" class="btn btn-success ms-auto"><i class="bi bi-hammer"></i> Build PDF for the editor</button>
			</div>

			<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
				<div><label class="form-label small" for="filter-review">Review</label>
					<select class="form-select form-select-sm" id="filter-review">
						<option value="pending" selected>Pending</option><option value="">All</option>
						<option value="accepted">Accepted</option><option value="edited">Edited</option><option value="rejected">Rejected</option>
					</select></div>
				<div><label class="form-label small" for="filter-status">Status</label>
					<select class="form-select form-select-sm" id="filter-status"><option value="">All</option>
						<?php foreach (CheckJobStore::STATUSES as $s): ?><option><?php echo $s; ?></option><?php endforeach; ?></select></div>
				<div><label class="form-label small" for="filter-category">Category</label>
					<select class="form-select form-select-sm" id="filter-category"><option value="">All</option>
						<?php foreach ($categories as $c): ?><option><?php echo $h($c); ?></option><?php endforeach; ?></select></div>
				<div><label class="form-label small" for="filter-unit">Unit</label>
					<select class="form-select form-select-sm" id="filter-unit"><option value="">All</option>
						<?php foreach ($unitTitles as $uid => $t): ?><option value="<?php echo $h($uid); ?>"><?php echo $h(mb_substr($t, 0, 60)); ?></option><?php endforeach; ?></select></div>
				<div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="filter-unplaced">
					<label class="form-check-label small" for="filter-unplaced">Not placed</label></div>
				<button id="bulk-accept" class="btn btn-outline-success btn-sm">Accept all pending in category</button>
				<span id="shown-count" class="text-muted small ms-auto"></span>
			</div>

			<div class="row g-3">
			<?php foreach ($findings as $f):
				$fid = $f['id']; ?>
				<div class="col-12 col-lg-6 finding-card" data-id="<?php echo $h($fid); ?>" data-status="<?php echo $h($f['status']); ?>"
					data-category="<?php echo $h($f['category']); ?>" data-unit="<?php echo $h($f['unit']); ?>"
					data-review="<?php echo $h($f['review'] ?? 'pending'); ?>" data-placed="<?php echo ($f['placed'] ?? true) === false ? '0' : '1'; ?>">
					<div class="card h-100">
						<div class="crop-stale p-2 small text-muted bg-light<?php echo empty($f['crop_stale']) ? ' d-none' : ''; ?>">Location changed — the preview updates after the next “Build PDF for the editor”.</div>
						<?php if (empty($f['crop_stale'])): ?>
							<img loading="lazy" class="crop card-img-top" alt=""
								src="<?php echo $h($q(['action' => 'crop', 'finding' => $fid, 'v' => (int) ($f['crop_rev'] ?? 0)])); ?>">
						<?php endif; ?>
						<div class="card-body">
							<div class="small text-muted mb-1"><?php echo $h($fid); ?> · <b class="f-status"><?php echo $h($f['status']); ?></b> · <span class="f-category"><?php echo $h($f['category']); ?></span>
								· p. <span class="f-page-label"><?php echo $h($f['page_label'] ?? ''); ?></span> (PDF <span class="f-pdf-page"><?php echo (int) ($f['placed_page'] ?? $f['page']); ?></span>)
								· <?php echo $h(mb_substr($unitTitles[$f['unit']] ?? '', 0, 40)); ?>
								<span class="f-unplaced badge bg-warning text-dark<?php echo ($f['placed'] ?? true) === false ? '' : ' d-none'; ?>">not placed exactly</span></div>
							<div><span class="text-muted">Original:</span> “<span class="f-original"><?php echo $h($f['original']); ?></span>”</div>
							<div class="f-replacement-row<?php echo empty($f['replacement']) ? ' d-none' : ''; ?>"><span class="text-muted">Replace with:</span> “<span class="f-replacement"><?php echo $h($f['replacement'] ?? ''); ?></span>”</div>
							<div class="small f-reason"><?php echo $h($f['reason']); ?></div>
							<div class="small text-muted f-related-row<?php echo empty($f['related']) ? ' d-none' : ''; ?>">Related: <span class="f-related"><?php echo $h($f['related'] ?? ''); ?></span></div>
							<div class="d-flex gap-2 align-items-center mt-2">
								<?php $review = $f['review'] ?? 'pending'; ?>
								<button class="btn btn-sm btn-outline-success review-btn<?php echo $review === 'accepted' ? ' active' : ''; ?>" data-review="accepted">Accept</button>
								<button class="btn btn-sm btn-outline-danger review-btn<?php echo $review === 'rejected' ? ' active' : ''; ?>" data-review="rejected">Reject</button>
								<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#e-<?php echo $h($fid); ?>">Edit</button>
								<span class="save-state ms-auto"></span>
							</div>
							<div class="collapse mt-2" id="e-<?php echo $h($fid); ?>">
								<div class="row g-2">
									<div class="col-6"><select class="form-select form-select-sm finding-field" data-field="status" aria-label="Status">
										<?php foreach (CheckJobStore::STATUSES as $s): ?><option <?php echo $f['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
									<div class="col-6"><input class="form-control form-control-sm finding-field" data-field="category" value="<?php echo $h($f['category']); ?>" aria-label="Category"></div>
									<?php foreach (['original' => 'Original', 'replacement' => 'Replace with', 'reason' => 'Reason', 'formatting' => 'Formatting',
										'related' => 'Related', 'context_before' => 'Text before', 'context_after' => 'Text after', 'page' => 'PDF page'] as $k => $label): ?>
										<div class="col-12"><input class="form-control form-control-sm finding-field" data-field="<?php echo $k; ?>"
											value="<?php echo $h($f[$k] ?? ''); ?>" placeholder="<?php echo $label; ?>" aria-label="<?php echo $label; ?>"></div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<hr class="my-4">
		<button id="delete-job" class="btn btn-outline-danger mb-4" data-name="<?php echo $h($job['name'] ?? ''); ?>"><i class="bi bi-trash"></i> Delete check</button>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script src="js/check_job.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>
