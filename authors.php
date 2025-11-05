<?php
// authors.php - Refactored with services
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

// Handle change password action
if (isset($_GET['action']) && $_GET['action'] === 'change_password') {
	require_change_password();
}

// Services
$authorService = getAuthorService();

// Prevent caching
header("Cache-Control: no-cache, must-revalidate");

$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$sort_by = filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_STRING) ?? 'id';
$sort_order = filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_STRING) ?? 'DESC';
$error = '';
$success = '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	verify_csrf();
	
	// ADD AUTHOR
	if (isset($_POST['add_author']) && is_admin()) {
		$authorData = [
			'name' => trim($_POST['name'] ?? ''),
			'fname' => trim($_POST['fname'] ?? ''),
			'email' => trim($_POST['email'] ?? '')
		];

		$author_id = $authorService->createAuthor($authorData);

		if ($author_id) {
			log_action($_SESSION['user_id'], 'add_author', $_SERVER['REMOTE_ADDR'], "Added author ID: $author_id");
			header('Location: authors.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
			exit;
		} else {
			$error = implode(', ', $authorService->getErrors()) ?: "Failed to add author.";
		}
	}
	
	// EDIT AUTHOR
	elseif (isset($_POST['edit_author']) && is_admin()) {
		$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

		if (!$id) {
			$error = "Invalid author ID.";
		} else {
			$authorData = [
				'name' => trim($_POST['name'] ?? ''),
				'fname' => trim($_POST['fname'] ?? ''),
				'email' => trim($_POST['email'] ?? '')
			];

			if ($authorService->updateAuthor($id, $authorData)) {
				log_action($_SESSION['user_id'], 'edit_author', $_SERVER['REMOTE_ADDR'], "Edited author ID: $id");
				header('Location: authors.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
				exit;
			} else {
				$error = implode(', ', $authorService->getErrors()) ?: "Failed to update author.";
			}
		}
	}
	
	// DELETE AUTHOR
	elseif (isset($_POST['delete_author']) && is_admin()) {
		$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

		if (!$id) {
			$error = "Invalid author ID.";
		} elseif ($authorService->deleteAuthor($id)) {
			log_action($_SESSION['user_id'], 'delete_author', $_SERVER['REMOTE_ADDR'], "Deleted author ID: $id");
			header('Location: authors.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
			exit;
		} else {
			$error = implode(', ', $authorService->getErrors()) ?: "Failed to delete author.";
		}
	}
}

try {
	// Fetch authors
	$authors = $authorService->getAuthors($search, $page, $sort_by, $sort_order);
	$total_authors = $authorService->getAuthorsCount($search);
	$total_pages = ceil($total_authors / ITEMS_PER_PAGE);

} catch (PDOException $e) {
	$error = "Database error: " . $e->getMessage();
	error_log("Database error in authors.php: " . $e->getMessage());
}

$max_pages_to_show = 5;
$half_range = floor($max_pages_to_show / 2);
$start_page = max(1, $page - $half_range);
$end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
if ($end_page - $start_page + 1 < $max_pages_to_show) {
	$start_page = max(1, $end_page - $max_pages_to_show + 1);
}

$next_sort_order_id = ($sort_by === 'id' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
$next_sort_order_name = ($sort_by === 'name' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Authors - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1><i class="bi bi-people"></i> Authors</h1>
		
		<?php if ($error) { ?>
			<div class="alert alert-danger alert-dismissible fade show">
				<i class="bi bi-exclamation-triangle"></i>
				<?php echo htmlspecialchars($error); ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
		<?php } ?>
		
		<?php if ($success) { ?>
			<div class="alert alert-success alert-dismissible fade show">
				<i class="bi bi-check-circle"></i>
				<?php echo htmlspecialchars($success); ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
		<?php } ?>
		
		<form method="GET" class="mb-3">
			<div class="row g-3">
				<div class="col-md-6">
					<label class="form-label">Search</label>
					<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
						   class="form-control" placeholder="Search by name, first name, or email">
				</div>
				<div class="col-md-3 d-flex align-items-end">
					<button type="submit" class="btn btn-primary me-2">
						<i class="bi bi-search"></i> Search
					</button>
					<a href="authors.php" class="btn btn-secondary">
						<i class="bi bi-x-circle"></i> Clear
					</a>
				</div>
				<?php if (is_admin()) { ?>
				<div class="col-md-3 d-flex align-items-end justify-content-end">
					<button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAuthorModal">
						<i class="bi bi-plus-circle"></i> Add Author
					</button>
				</div>
				<?php } ?>
			</div>
			<input type="hidden" name="page" value="1">
			<input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
			<input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
		</form>
		
		<!-- Pagination -->
		<nav aria-label="Page navigation" class="mb-3">
			<ul class="pagination">
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=1">First</a>
				</li>
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page - 1; ?>">Previous</a>
				</li>
				<?php for ($i = $start_page; $i <= $end_page; $i++) { ?>
					<li class="page-item <?php if ($i == $page) echo 'active'; ?>">
						<a class="page-link" href="?search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
					</li>
				<?php } ?>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page + 1; ?>">Next</a>
				</li>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?search=<?php echo urlencode($search); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $total_pages; ?>">Last</a>
				</li>
			</ul>
		</nav>
		
		<!-- Authors Table -->
		<div class="table-responsive">
			<table class="table table-striped table-hover">
				<thead class="table-dark">
					<tr>
						<th style="width: 80px">
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=id&sort_order=<?php echo $sort_by === 'id' ? $next_sort_order_id : 'ASC'; ?>&page=1">
								ID <?php echo $sort_by === 'id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=name&sort_order=<?php echo $sort_by === 'name' ? $next_sort_order_name : 'ASC'; ?>&page=1">
								Name <?php echo $sort_by === 'name' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>First Name</th>
						<th>Email</th>
						<th>Books</th>
						<th style="width: 120px">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($authors)) { ?>
						<tr>
							<td colspan="6" class="text-center text-muted">
								<i class="bi bi-inbox"></i> No authors found
							</td>
						</tr>
					<?php } else { ?>
						<?php foreach ($authors as $author) { ?>
						<tr>
							<td><?php echo $author['id']; ?></td>
							<td><?php echo htmlspecialchars($author['name']); ?></td>
							<td><?php echo htmlspecialchars($author['fname']); ?></td>
							<td><?php echo htmlspecialchars($author['email'] ?? '-'); ?></td>
							<td>
								<?php if ($author['books']) { ?>
									<small class="text-muted"><?php echo htmlspecialchars($author['books']); ?></small>
								<?php } else { ?>
									<span class="text-muted">-</span>
								<?php } ?>
							</td>
							<td>
								<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editAuthorModal"
										data-id="<?php echo $author['id']; ?>"
										data-name="<?php echo htmlspecialchars($author['name']); ?>"
										data-fname="<?php echo htmlspecialchars($author['fname']); ?>"
										data-email="<?php echo htmlspecialchars($author['email'] ?? ''); ?>"
										title="Edit">
									<i class="bi bi-pencil"></i>
								</button>
								<?php if (is_admin()) { ?>
								<form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this author?');">
									<?php echo csrf_field(); ?>
									<input type="hidden" name="delete_author" value="1">
									<input type="hidden" name="id" value="<?php echo $author['id']; ?>">
									<button type="submit" class="btn btn-sm btn-danger" title="Delete">
										<i class="bi bi-trash"></i>
									</button>
								</form>
								<?php } ?>
							</td>
						</tr>
						<?php } ?>
					<?php } ?>
				</tbody>
			</table>
		</div>
		
		<a href="index.php" class="btn btn-secondary">
			<i class="bi bi-arrow-left"></i> Back to Home
		</a>
	</div>

	<!-- Add Author Modal -->
	<div class="modal fade" id="addAuthorModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">
						<i class="bi bi-person-plus"></i> Add Author
					</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="add_author" value="1">
						<div class="mb-3">
							<label class="form-label">Name <span class="text-danger">*</span></label>
							<input type="text" name="name" class="form-control" required maxlength="50">
						</div>
						<div class="mb-3">
							<label class="form-label">First Name <span class="text-danger">*</span></label>
							<input type="text" name="fname" class="form-control" required maxlength="50">
						</div>
						<div class="mb-3">
							<label class="form-label">Email</label>
							<input type="email" name="email" class="form-control" maxlength="100">
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary">
							<i class="bi bi-save"></i> Add Author
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Edit Author Modal -->
	<div class="modal fade" id="editAuthorModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">
						<i class="bi bi-pencil"></i> Edit Author
					</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="edit_author" value="1">
						<input type="hidden" name="id" id="edit-author-id">
						<div class="mb-3">
							<label class="form-label">Name <span class="text-danger">*</span></label>
							<input type="text" name="name" id="edit-author-name" class="form-control" required maxlength="50">
						</div>
						<div class="mb-3">
							<label class="form-label">First Name <span class="text-danger">*</span></label>
							<input type="text" name="fname" id="edit-author-fname" class="form-control" required maxlength="50">
						</div>
						<div class="mb-3">
							<label class="form-label">Email</label>
							<input type="email" name="email" id="edit-author-email" class="form-control" maxlength="100">
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary">
							<i class="bi bi-save"></i> Save Changes
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Handle edit modal
		$('#editAuthorModal').on('show.bs.modal', function (event) {
			var button = $(event.relatedTarget);
			var modal = $(this);
			modal.find('#edit-author-id').val(button.data('id'));
			modal.find('#edit-author-name').val(button.data('name'));
			modal.find('#edit-author-fname').val(button.data('fname'));
			modal.find('#edit-author-email').val(button.data('email'));
		});
		
		// Auto-dismiss alerts after 5 seconds
		setTimeout(function() {
			$('.alert').fadeOut('slow');
		}, 5000);
		
		// Check if we should open add modal (from hash)
		if (window.location.hash === '#openAddModal') {
			$('#addAuthorModal').modal('show');
			// Remove hash from URL
			history.replaceState(null, null, ' ');
		}
	});
	</script>
<?php include 'footer.php'; ?>	
</body>
</html>