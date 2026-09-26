<?php
// pdf_job.php - pregled i uređivanje opisa jednog PDF posla (samo admin)
require_once 'bootstrap.php';
require_once 'functions.php';
require_once 'pdf_config.php';
require_admin();

use App\Exceptions\NotFoundException;

$store = pdf_job_store();
$id = $_GET['id'] ?? '';
try {
    $job = $store->job($id);
    $status = $store->status($id);
    $images = $store->descriptions($id)['images'] ?? [];
} catch (NotFoundException $e) {
    http_response_code(404);
    die('Posao ne postoji');
}

function needs_attention(array $img): bool
{
    if (!empty($img['decorative'])) return false;
    $d = trim($img['description'] ?? '');
    return $d === '' || str_starts_with($d, '[ERROR]')
        || ($img['existing_alt_status'] ?? '') === 'auto' || empty($img['caption']);
}
$attention = array_values(array_filter($images, 'needs_attention'));
$langs = ['' => '—', 'en' => 'en', 'hr' => 'hr', 'de' => 'de', 'fr' => 'fr', 'it' => 'it', 'es' => 'es'];
?>
<!DOCTYPE html>
<html lang="hr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($job['name'] ?? 'PDF'); ?> - PDF pristupačnost</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<style>
		.thumb { max-height: 180px; object-fit: contain; background: #f4f4f4; }
		.save-state { font-size: .8rem; min-height: 1.2em; }
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4" id="pdf-job"
		data-id="<?php echo htmlspecialchars($id); ?>"
		data-csrf="<?php echo htmlspecialchars(generate_csrf_token()); ?>"
		data-state="<?php echo htmlspecialchars($status['state'] ?? ''); ?>">

		<a href="pdf.php" class="btn btn-link px-0"><i class="bi bi-arrow-left"></i> Svi poslovi</a>
		<h1 class="h4"><?php echo htmlspecialchars($job['name'] ?? ''); ?></h1>

		<div id="status-box" class="alert alert-secondary"></div>
		<div id="progress" class="progress mb-3 d-none"><div class="progress-bar" style="width:0%"></div></div>

		<div id="result-box" class="d-none mb-4"></div>

		<div class="card mb-3">
			<div class="card-body row g-2">
				<div class="col-12 col-md-8">
					<label class="form-label" for="doc-title">Naslov dokumenta (PDF/UA ga traži)</label>
					<input class="form-control doc-field" id="doc-title" value="<?php echo htmlspecialchars($job['title'] ?? ''); ?>">
				</div>
				<div class="col-6 col-md-2">
					<label class="form-label" for="doc-lang">Jezik</label>
					<input class="form-control doc-field" id="doc-lang" value="<?php echo htmlspecialchars($job['lang'] ?? ''); ?>" placeholder="en-US">
				</div>
				<div class="col-6 col-md-2 d-flex align-items-end"><span class="save-state" id="doc-save"></span></div>
			</div>
		</div>

		<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
			<div class="btn-group" role="group" aria-label="Filter">
				<button class="btn btn-outline-primary active" data-filter="attention">Treba pažnju (<?php echo count($attention); ?>)</button>
				<button class="btn btn-outline-primary" data-filter="all">Sve (<?php echo count($images); ?>)</button>
				<button class="btn btn-outline-primary" data-filter="decorative">Ukrasi</button>
			</div>
			<button id="make-pdf" class="btn btn-success ms-auto"><i class="bi bi-file-earmark-check"></i> Napravi PDF</button>
		</div>

		<div class="row g-3" id="cards">
		<?php foreach ($images as $img):
			$x = (int) $img['xref']; ?>
			<div class="col-12 col-md-6 col-xl-4 image-card"
				data-xref="<?php echo $x; ?>"
				data-attention="<?php echo needs_attention($img) ? '1' : '0'; ?>"
				data-decorative="<?php echo !empty($img['decorative']) ? '1' : '0'; ?>">
				<div class="card h-100">
					<img loading="lazy" class="card-img-top thumb" alt=""
						src="pdf_api.php?action=thumb&amp;id=<?php echo urlencode($id); ?>&amp;xref=<?php echo $x; ?>">
					<div class="card-body">
						<div class="small text-muted mb-1">Str. <?php echo (int) ($img['page'] ?? 0); ?>
							<?php if (!empty($img['caption'])): ?> · <?php echo htmlspecialchars($img['caption']); ?><?php endif; ?></div>
						<?php if (!empty($img['existing_alt'])): ?>
							<div class="small mb-2"><span class="badge bg-secondary"><?php echo htmlspecialchars($img['existing_alt_status'] ?? ''); ?></span>
								Postojeći: <?php echo htmlspecialchars(mb_substr($img['existing_alt'], 0, 200)); ?></div>
						<?php endif; ?>
						<label class="visually-hidden" for="d<?php echo $x; ?>">Opis slike</label>
						<textarea class="form-control img-field" id="d<?php echo $x; ?>" data-field="description" rows="4"><?php echo htmlspecialchars($img['description'] ?? ''); ?></textarea>
						<div class="d-flex gap-3 align-items-center mt-2">
							<div class="form-check form-switch">
								<input class="form-check-input img-field" type="checkbox" data-field="decorative" id="u<?php echo $x; ?>" <?php echo !empty($img['decorative']) ? 'checked' : ''; ?>>
								<label class="form-check-label" for="u<?php echo $x; ?>">Ukras</label>
							</div>
							<select class="form-select form-select-sm w-auto img-field" data-field="language" aria-label="Jezik opisa">
								<?php foreach ($langs as $v => $l): ?>
									<option value="<?php echo $v; ?>" <?php echo ($img['language'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
								<?php endforeach; ?>
							</select>
							<span class="save-state ms-auto"></span>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>

		<hr class="my-4">
		<button id="delete-job" class="btn btn-outline-danger mb-4"
			data-name="<?php echo htmlspecialchars($job['name'] ?? ''); ?>"><i class="bi bi-trash"></i> Obriši posao</button>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script src="js/pdf_job.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>
