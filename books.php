<?php
// books.php - Refactored with services
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

// Handle change password action
if (isset($_GET['action']) && $_GET['action'] === 'change_password') {
	require_change_password();
}

// Services
$bookService = getBookService();
$authorService = getAuthorService();

// Handle autocomplete request
if (isset($_GET['autocomplete']) && $_GET['autocomplete'] == 1) {
	header('Content-Type: application/json');
	$query = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_STRING) ?? '';
	try {
		$results = $authorService->searchByName($query);
		echo json_encode($results);
	} catch (Exception $e) {
		error_log("Autocomplete error: " . $e->getMessage());
		echo json_encode([]);
	}
	exit;
}

// Prevent caching
header("Cache-Control: no-cache, must-revalidate");

$search_title = filter_input(INPUT_GET, 'search_title', FILTER_SANITIZE_STRING) ?? '';
$search_author = filter_input(INPUT_GET, 'search_author', FILTER_SANITIZE_STRING) ?? '';
$filter_status = filter_input(INPUT_GET, 'filter_status', FILTER_SANITIZE_STRING) ?? '';
$filter_invoice = filter_input(INPUT_GET, 'filter_invoice', FILTER_SANITIZE_STRING) ?? '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$sort_by = filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_STRING) ?? 'id';
$sort_order = filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_STRING) ?? 'DESC';
$error = '';
$success = '';

try {
	$books = $bookService->getBooks($search_title, $search_author, $filter_status !== '' ? (int)$filter_status : null, $filter_invoice !== '' ? (int)$filter_invoice : null, $page, $sort_by, $sort_order);
	$total_books = $bookService->getBooksCount($search_title, $search_author, $filter_status !== '' ? (int)$filter_status : null, $filter_invoice !== '' ? (int)$filter_invoice : null);
	$statuses = get_statuses();
	$formatings = get_formatings();

	// Get recent authors
	$recent_authors = $authorService->getRecent(5);

	// Fetch authors for each book
	foreach ($books as &$book) {
		$book['authors_list'] = $bookService->getBookAuthors($book['id']);
		$book['authors'] = !empty($book['authors_list']) ? implode(', ', array_column($book['authors_list'], 'name')) : 'None';
		$book['note'] = $book['note'] ?? '';
	}
} catch (Exception $e) {
	$error = "Database error occurred.";
	error_log("Database error in books.php: " . $e->getMessage());
}

$total_pages = ceil($total_books / ITEMS_PER_PAGE);

// Pagination range
$max_pages_to_show = 5;
$half_range = floor($max_pages_to_show / 2);
$start_page = max(1, $page - $half_range);
$end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
if ($end_page - $start_page + 1 < $max_pages_to_show) {
	$start_page = max(1, $end_page - $max_pages_to_show + 1);
}

$next_sort_order_id = ($sort_by === 'id' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
$next_sort_order_title = ($sort_by === 'title' && $sort_order === 'ASC') ? 'DESC' : 'ASC';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	verify_csrf();
	
	// ADD BOOK
	if (isset($_POST['add_book']) && is_admin()) {
		$bookData = [
			'title' => trim($_POST['title'] ?? ''),
			'pages' => trim($_POST['pages'] ?? ''),
			'date_start' => trim($_POST['date_start'] ?? ''),
			'date_finish' => trim($_POST['date_finish'] ?? ''),
			'id_status' => trim($_POST['id_status'] ?? ''),
			'id_formating' => trim($_POST['id_formating'] ?? ''),
			'invoice' => isset($_POST['invoice']) ? 1 : 0,
			'note' => trim($_POST['note'] ?? '')
		];

		$authors = trim($_POST['selected-authors'] ?? '');
		$author_ids = array_filter(array_map('intval', explode(',', $authors)), fn($id) => $id > 0);

		$book_id = $bookService->createBook($bookData, $author_ids);

		if ($book_id) {
			log_action($_SESSION['user_id'], 'add_book', $_SERVER['REMOTE_ADDR'], "Added book ID: $book_id");
			header('Location: books.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
			exit;
		} else {
			$error = implode(', ', $bookService->getErrors()) ?: "Failed to add book.";
		}
	}
	
	// EDIT BOOK
	elseif (isset($_POST['edit_book'])) {
		$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

		if (!$id) {
			$error = "Invalid book ID.";
		} else {
			$bookData = [
				'title' => trim($_POST['title'] ?? ''),
				'pages' => trim($_POST['pages'] ?? ''),
				'date_start' => trim($_POST['date_start'] ?? ''),
				'date_finish' => trim($_POST['date_finish'] ?? ''),
				'id_status' => trim($_POST['id_status'] ?? ''),
				'id_formating' => trim($_POST['id_formating'] ?? ''),
				'invoice' => isset($_POST['invoice']) ? 1 : 0,
				'note' => trim($_POST['note'] ?? '')
			];

			$authors = trim($_POST['selected-authors'] ?? '');
			$author_ids = array_filter(array_map('intval', explode(',', $authors)), fn($id) => $id > 0);

			if ($bookService->updateBook($id, $bookData, $author_ids)) {
				log_action($_SESSION['user_id'], 'edit_book', $_SERVER['REMOTE_ADDR'], "Edited book ID: $id");
				header('Location: books.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
				exit;
			} else {
				$error = implode(', ', $bookService->getErrors()) ?: "Failed to update book.";
			}
		}
	}
	
	// ADD PROCESS AND CORRECT HISTORY
	elseif (isset($_POST['add_process_correct_history'])) {
		try {
			$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

			if (!$id) {
				throw new Exception("Invalid book ID.");
			}

			// Get status IDs for 'process' and 'correct'
			$stmt = $pdo->query("SELECT id, name FROM status");
			$statuses_map = [];
			while ($row = $stmt->fetch()) {
				$statuses_map[strtolower($row['name'])] = $row['id'];
			}

			$process_id = null;
			$correct_id = null;

			// Find process and correct status IDs (check various possible names)
			foreach ($statuses_map as $name => $status_id) {
				if (strpos($name, 'process') !== false || strpos($name, 'editing') !== false) {
					$process_id = $status_id;
				}
				if (strpos($name, 'correct') !== false) {
					$correct_id = $status_id;
				}
			}

			if (!$process_id || !$correct_id) {
				throw new Exception("Could not find 'process' or 'correct' status.");
			}

			$pdo->beginTransaction();

			// Add process history record
			$stmt = $pdo->prepare("INSERT INTO history (id_book, id_status) VALUES (?, ?)");
			$stmt->execute([$id, $process_id]);

			// Add correct history record
			$stmt->execute([$id, $correct_id]);

			$pdo->commit();

			log_action($_SESSION['user_id'], 'add_process_correct_history', $_SERVER['REMOTE_ADDR'], "Added process/correct history for book ID: $id");

			$success = "Process and correct history records added successfully.";
		} catch (Exception $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$error = $e->getMessage();
			error_log("Add process/correct history error: " . $e->getMessage());
		}
	}

	// DELETE BOOK
	elseif (isset($_POST['delete_book']) && is_admin()) {
		$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

		if (!$id) {
			$error = "Invalid book ID.";
		} elseif ($bookService->deleteBook($id)) {
			log_action($_SESSION['user_id'], 'delete_book', $_SERVER['REMOTE_ADDR'], "Deleted book ID: $id");
			header('Location: books.php?page=' . $page . '&sort_by=' . $sort_by . '&sort_order=' . $sort_order);
			exit;
		} else {
			$error = "Failed to delete book.";
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Books - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
	<link rel="stylesheet" href="css/style.css">
	<style>
		.modal-wide {
			max-width: 800px;
		}
		.form-control-short {
			width: 100%;
		}
		.author-chip {
			display: inline-block;
			margin: 2px;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			padding: 6px 12px;
			border-radius: 20px;
			font-size: 0.875rem;
			font-weight: 500;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			transition: all 0.3s;
		}
		.author-chip:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 8px rgba(0,0,0,0.15);
		}
		.author-chip .chip-remove {
			margin-left: 8px;
			cursor: pointer;
			font-weight: bold;
			opacity: 0.8;
			transition: opacity 0.2s;
		}
		.author-chip .chip-remove:hover {
			opacity: 1;
		}
		.author-chips-container {
			border: 2px dashed #ccc;
			padding: 10px;
			border-radius: 8px;
			min-height: 50px;
			margin-bottom: 10px;
			background: #f8f9fa;
			transition: border-color 0.3s;
		}
		.author-chips-container.has-chips {
			border-style: solid;
			border-color: #667eea;
			background: white;
		}
		.author-chips-container:empty::before {
			content: "No authors selected. Search and add authors below.";
			color: #6c757d;
			font-style: italic;
			font-size: 0.9rem;
		}
		#author-search, #add-author-search, #recent-authors, #add-recent-authors {
			width: 100%;
		}
		.author-search-row {
			margin-top: 0;
		}
		.author-search-row .col-md-7,
		.author-search-row .col-md-5 {
			display: flex;
			align-items: stretch;
		}
		.author-search-row .form-control {
			height: 38px;
		}
		.ui-autocomplete {
			position: absolute;
			z-index: 1060 !important;
			background: white;
			border: 1px solid #ddd;
			max-height: 200px;
			overflow-y: auto;
			width: auto !important;
			box-shadow: 0 4px 12px rgba(0,0,0,0.15);
			border-radius: 6px;
		}
		.ui-autocomplete .ui-menu-item {
			padding: 10px 15px;
			cursor: pointer;
			font-size: 0.9rem;
			color: #333;
			border-bottom: 1px solid #f0f0f0;
		}
		.ui-autocomplete .ui-menu-item:last-child {
			border-bottom: none;
		}
		.ui-autocomplete .ui-menu-item:hover,
		.ui-autocomplete .ui-state-active {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
		}
		.table-actions {
			white-space: nowrap;
		}
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1><i class="bi bi-book"></i> Books</h1>
		
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
		
		<!-- Search and Filter Form -->
		<form method="GET" class="mb-3">
			<div class="row g-3">
				<?php if (is_admin()) { ?>
				<div class="col-12 col-md-auto">
					<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addBookModal">
						<i class="bi bi-plus-circle"></i> Add Book
					</button>
				</div>
				<?php } ?>
				<div class="col-12 col-md">
					<input type="text" name="search_title" value="<?php echo htmlspecialchars($search_title); ?>" 
						   class="form-control" placeholder="Search by title">
				</div>
				<div class="col-12 col-md">
					<input type="text" name="search_author" value="<?php echo htmlspecialchars($search_author); ?>" 
						   class="form-control" placeholder="Search by author">
				</div>
				<div class="col-6 col-md-auto">
					<select name="filter_status" class="form-control">
						<option value="">All Statuses</option>
						<?php foreach ($statuses as $status) { ?>
							<option value="<?php echo $status['id']; ?>" <?php echo $filter_status == $status['id'] ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($status['name']); ?>
							</option>
						<?php } ?>
					</select>
				</div>
				<div class="col-6 col-md-auto">
					<select name="filter_invoice" class="form-control">
						<option value="">All Invoices</option>
						<option value="1" <?php echo $filter_invoice === '1' ? 'selected' : ''; ?>>Yes</option>
						<option value="0" <?php echo $filter_invoice === '0' ? 'selected' : ''; ?>>No</option>
					</select>
				</div>
				<div class="col-12 col-md-auto">
					<button type="submit" class="btn btn-primary w-100">
						<i class="bi bi-search"></i> Search
					</button>
				</div>
				<div class="col-12 col-md-auto">
					<a href="books.php" class="btn btn-secondary w-100">
						<i class="bi bi-x-circle"></i> Clear
					</a>
				</div>
				<div class="col-12 col-md-auto">
					<button type="button" class="btn btn-success w-100" id="exportCsvBtn">
						<i class="bi bi-file-earmark-spreadsheet"></i> CSV
					</button>
				</div>
			</div>
			<input type="hidden" name="page" value="1">
			<input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
			<input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
		</form>
		
		<!-- Pagination -->
		<nav aria-label="Page navigation" class="mb-3">
			<ul class="pagination pagination-sm flex-wrap">
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=1">First</a>
				</li>
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page - 1; ?>">Previous</a>
				</li>
				<?php for ($i = $start_page; $i <= $end_page; $i++) { ?>
					<li class="page-item <?php if ($i == $page) echo 'active'; ?>">
						<a class="page-link" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
					</li>
				<?php } ?>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page + 1; ?>">Next</a>
				</li>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $total_pages; ?>">Last</a>
				</li>
			</ul>
		</nav>
		
		<!-- Books Table -->
		<div class="table-responsive">
			<table class="table table-striped table-hover">
				<thead class="table-dark">
					<tr>
						<th style="width: 60px">
							<a class="text-white text-decoration-none" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=id&sort_order=<?php echo $sort_by === 'id' ? $next_sort_order_id : 'ASC'; ?>&page=1">
								ID <?php echo $sort_by === 'id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>
							<a class="text-white text-decoration-none" href="?search_title=<?php echo urlencode($search_title); ?>&search_author=<?php echo urlencode($search_author); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_invoice=<?php echo urlencode($filter_invoice); ?>&sort_by=title&sort_order=<?php echo $sort_by === 'title' ? $next_sort_order_title : 'ASC'; ?>&page=1">
								Title <?php echo $sort_by === 'title' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>Authors</th>
						<th style="width: 80px">Pages</th>
						<th style="width: 110px">Start</th>
						<th style="width: 110px">Finish</th>
						<th style="width: 100px">Status</th>
						<th style="width: 100px">Format</th>
						<th style="width: 80px">Invoice</th>
						<th style="width: 140px" class="table-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($books)) { ?>
						<tr>
							<td colspan="10" class="text-center text-muted">
								<i class="bi bi-inbox"></i> No books found
							</td>
						</tr>
					<?php } else { ?>
						<?php foreach ($books as $book) { ?>
						<tr>
							<td><?php echo $book['id']; ?></td>
							<td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
							<td>
								<small class="text-muted">
									<?php if (!empty($book['authors_list'])) { 
										$author_links = [];
										foreach ($book['authors_list'] as $author) {
											if ($author['email']) {
												// Create email body
												$email_subject = $book['title'];
												$email_body = "Dear Mr. " . $author['name'] . ",\n\n";
												$email_body .= "I have been hired by Archaeopress to prepare your forthcoming book '" . $book['title'] . "' for publication. I have posted a PDF file of the formatted book on Smash, and you will soon receive a notification from them with instructions on how to download the file.\n\n";
												$email_body .= "Please review the PDF to ensure everything is in order and kindly provide any corrections. If you feel that any images need to be enlarged or reduced in size please let me know. Also if you are not satisfied with the image quality, feel free to send me new scans, and I will make the necessary replacements.\n\n";
												$email_body .= "For any corrections, please add your comments directly in the PDF using Adobe Acrobat Reader.\n\n";
												$email_body .= "I would appreciate it if you could get back to me as soon as possible.\n\n";
												$email_body .= "Yours sincerely,\nDanko";
												
												// Replace + with space in both subject and body
												$mailto = "mailto:" . urlencode($author['email']) . 
													"?subject=" . str_replace('+', '%20', urlencode($email_subject)) . 
													"&body=" . str_replace('+', '%20', urlencode($email_body));
												$author_links[] = '<a href="' . $mailto . '" title="Email ' . htmlspecialchars($author['name']) . '">' . htmlspecialchars($author['name']) . '</a>';
											} else {
												$author_links[] = htmlspecialchars($author['name']);
											}
										}
										echo implode(', ', $author_links);
									} else {
										echo 'None';
									} ?>
								</small>
							</td>
							<td><?php echo $book['pages'] ?: '-'; ?></td>
							<td><?php echo $book['date_start'] ?: '-'; ?></td>
							<td><?php echo $book['date_finish'] ?: '-'; ?></td>
							<td><?php echo htmlspecialchars($book['status_name'] ?? '-'); ?></td>
							<td><?php echo htmlspecialchars($book['format_shortname'] ?? '-'); ?></td>
							<td>
								<?php if ($book['invoice']) { ?>
									<span class="badge bg-success">Yes</span>
								<?php } else { ?>
									<span class="badge bg-secondary">No</span>
								<?php } ?>
							</td>
							<td class="table-actions">
								<button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#historyModal"
										data-book-id="<?php echo $book['id']; ?>"
										data-book-title="<?php echo htmlspecialchars($book['title']); ?>"
										title="View History">
									<i class="bi bi-clock-history"></i>
								</button>
								<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editBookModal"
										data-id="<?php echo $book['id']; ?>"
										data-title="<?php echo htmlspecialchars($book['title']); ?>"
										data-pages="<?php echo $book['pages']; ?>"
										data-date_start="<?php echo $book['date_start']; ?>"
										data-date_finish="<?php echo $book['date_finish']; ?>"
										data-id_status="<?php echo $book['id_status']; ?>"
										data-id_formating="<?php echo $book['id_formating']; ?>"
										data-invoice="<?php echo $book['invoice']; ?>"
										data-note="<?php echo htmlspecialchars($book['note']); ?>"
										data-authors='<?php echo htmlspecialchars(json_encode($book['authors_list'] ?? [])); ?>'
										title="Edit">
									<i class="bi bi-pencil"></i>
								</button>
								<?php if (is_admin()) { ?>
								<form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this book?');">
									<?php echo csrf_field(); ?>
									<input type="hidden" name="delete_book" value="1">
									<input type="hidden" name="id" value="<?php echo $book['id']; ?>">
									<button type="submit" class="btn btn-sm btn-danger" title="Delete">
										<i class="bi bi-trash"></i>
									</button>
								</form>
								<?php } ?>
								<?php
								// Show "Add Process/Correct History" button only for books with status "correct"
								$status_name_lower = strtolower($book['status_name'] ?? '');
								if (strpos($status_name_lower, 'correct') !== false) {
								?>
								<form method="POST" style="display:inline;" onsubmit="return confirm('Add process and correct history records for this book?');">
									<?php echo csrf_field(); ?>
									<input type="hidden" name="add_process_correct_history" value="1">
									<input type="hidden" name="id" value="<?php echo $book['id']; ?>">
									<button type="submit" class="btn btn-sm btn-warning" title="Add Process & Correct History">
										<i class="bi bi-plus-circle"></i>
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

	<!-- Add Book Modal -->
	<div class="modal fade" id="addBookModal" tabindex="-1">
		<div class="modal-dialog modal-wide modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header bg-success text-white">
					<h5 class="modal-title">
						<i class="bi bi-plus-circle"></i> Add New Book
					</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST" id="addBookForm">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="add_book" value="1">
						
						<div class="mb-3">
							<label class="form-label">Title <span class="text-danger">*</span></label>
							<input type="text" name="title" class="form-control" required maxlength="100">
						</div>
						
						<div class="row mb-3">
							<div class="col-md-4">
								<label class="form-label">Pages</label>
								<input type="number" name="pages" class="form-control" min="0" value="0">
							</div>
							<div class="col-md-4">
								<label class="form-label">Start Date</label>
								<input type="date" name="date_start" class="form-control" value="<?php echo date('Y-m-d'); ?>">
							</div>
							<div class="col-md-4">
								<label class="form-label">Finish Date</label>
								<input type="date" name="date_finish" class="form-control">
							</div>
						</div>

						<div class="row mb-3">
							<div class="col-md-4">
								<label class="form-label">Status</label>
								<select name="id_status" class="form-control">
									<option value="">Select Status</option>
									<?php foreach ($statuses as $status) {
										// Default to "received" status
										$selected = (strtolower($status['name']) === 'received') ? 'selected' : '';
									?>
										<option value="<?php echo $status['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($status['name']); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Format</label>
								<select name="id_formating" class="form-control">
									<option value="">Select Format</option>
									<?php foreach ($formatings as $format) {
										// Default to "A4" format
										$selected = (strtoupper($format['name']) === 'A4' || strtoupper($format['shortname']) === 'A4') ? 'selected' : '';
									?>
										<option value="<?php echo $format['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($format['name']); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label d-block">Invoice</label>
								<div class="form-check form-switch mt-2">
									<input type="checkbox" name="invoice" class="form-check-input" id="add-invoice" value="1">
									<label class="form-check-label" for="add-invoice">Has Invoice</label>
								</div>
							</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">
								<i class="bi bi-people"></i> Authors
							</label>
							<div id="add-author-chips" class="author-chips-container"></div>
							<div class="row author-search-row">
								<div class="col-md-7">
									<input type="text" id="add-author-search" class="form-control" placeholder="Search authors (min 3 chars)...">
								</div>
								<div class="col-md-5">
									<select id="add-recent-authors" class="form-control">
										<option value="">Recent authors...</option>
										<?php foreach ($recent_authors as $author) { ?>
											<option value="<?php echo $author['id']; ?>" data-name="<?php echo htmlspecialchars($author['name']); ?>">
												<?php echo htmlspecialchars($author['name']); ?>
											</option>
										<?php } ?>
									</select>
								</div>
							</div>
							<input type="hidden" name="selected-authors" id="add-selected-authors">
							<small class="form-text text-muted">
								<i class="bi bi-info-circle"></i> Search or select from recent authors to add them.
							</small>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Note</label>
							<textarea name="note" class="form-control" rows="3" placeholder="Add any additional notes about this book..."></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<i class="bi bi-x-circle"></i> Cancel
						</button>
						<button type="submit" class="btn btn-success">
							<i class="bi bi-save"></i> Add Book
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Edit Book Modal -->
	<div class="modal fade" id="editBookModal" tabindex="-1">
		<div class="modal-dialog modal-wide modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header bg-primary text-white">
					<h5 class="modal-title">
						<i class="bi bi-pencil"></i> Edit Book
					</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST" id="editBookForm">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="edit_book" value="1">
						<input type="hidden" name="id" id="edit-book-id">
						
						<div class="mb-3">
							<label class="form-label">Title <span class="text-danger">*</span></label>
							<input type="text" name="title" id="edit-book-title" class="form-control" required maxlength="100">
						</div>
						
						<div class="row mb-3">
							<div class="col-md-4">
								<label class="form-label">Pages</label>
								<input type="number" name="pages" id="edit-book-pages" class="form-control" min="0">
							</div>
							<div class="col-md-4">
								<label class="form-label">Start Date</label>
								<input type="date" name="date_start" id="edit-book-date_start" class="form-control">
							</div>
							<div class="col-md-4">
								<label class="form-label">Finish Date</label>
								<input type="date" name="date_finish" id="edit-book-date_finish" class="form-control">
							</div>
						</div>
						
						<div class="row mb-3">
							<div class="col-md-4">
								<label class="form-label">Status</label>
								<select name="id_status" id="edit-book-id_status" class="form-control">
									<option value="">Select Status</option>
									<?php foreach ($statuses as $status) { ?>
										<option value="<?php echo $status['id']; ?>"><?php echo htmlspecialchars($status['name']); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Format</label>
								<select name="id_formating" id="edit-book-id_formating" class="form-control">
									<option value="">Select Format</option>
									<?php foreach ($formatings as $format) { ?>
										<option value="<?php echo $format['id']; ?>"><?php echo htmlspecialchars($format['name']); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label d-block">Invoice</label>
								<div class="form-check form-switch mt-2">
									<input type="checkbox" name="invoice" class="form-check-input" id="edit-book-invoice" value="1">
									<label class="form-check-label" for="edit-book-invoice">Has Invoice</label>
								</div>
							</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">
								<i class="bi bi-people"></i> Authors
							</label>
							<div id="author-chips" class="author-chips-container"></div>
							<div class="row author-search-row">
								<div class="col-md-7">
									<input type="text" id="author-search" class="form-control" placeholder="Search authors (min 3 chars)...">
								</div>
								<div class="col-md-5">
									<select id="recent-authors" class="form-control">
										<option value="">Recent authors...</option>
										<?php foreach ($recent_authors as $author) { ?>
											<option value="<?php echo $author['id']; ?>" data-name="<?php echo htmlspecialchars($author['name']); ?>">
												<?php echo htmlspecialchars($author['name']); ?>
											</option>
										<?php } ?>
									</select>
								</div>
							</div>
							<input type="hidden" name="selected-authors" id="selected-authors">
							<small class="form-text text-muted">
								<i class="bi bi-info-circle"></i> Search or select from recent authors to add them.
							</small>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Note</label>
							<textarea name="note" id="edit-book-note" class="form-control" rows="3" placeholder="Add any additional notes about this book..."></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<i class="bi bi-x-circle"></i> Cancel
						</button>
						<button type="submit" class="btn btn-primary">
							<i class="bi bi-save"></i> Save Changes
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- History Modal -->
	<div class="modal fade" id="historyModal" tabindex="-1">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<div class="modal-header bg-info text-white">
					<h5 class="modal-title">
						<i class="bi bi-clock-history"></i> Book History
					</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<h6 id="history-book-title" class="mb-3"></h6>
					<div id="history-content">
						<div class="text-center">
							<div class="spinner-border" role="status">
								<span class="visually-hidden">Loading...</span>
							</div>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Auto-dismiss alerts
		setTimeout(function() {
			$('.alert').fadeOut('slow');
		}, 5000);
		
		/**
		 * Add author chip to container
		 */
		function addAuthorChip(modalPrefix, authorId, authorName) {
			// Validate inputs
			if (!authorId || !authorName || !Number.isInteger(parseInt(authorId))) {
				console.error(`Invalid author data: ID=${authorId}, Name=${authorName}`);
				return false;
			}
			
			// Check if already added
			if ($(`#${modalPrefix}author-chips .author-chip[data-author-id="${authorId}"]`).length) {
				console.log(`Author ${authorId} already added, ignoring.`);
				return false;
			}
			
			// Create chip
			const chip = `<span class="author-chip" data-author-id="${authorId}">
				<i class="bi bi-person-fill"></i> ${authorName}
				<span class="chip-remove" data-author-id="${authorId}">&times;</span>
			</span>`;
			
			$(`#${modalPrefix}author-chips`).append(chip);
			$(`#${modalPrefix}author-chips`).addClass('has-chips');
			console.log(`Added chip: ID=${authorId}, Name=${authorName}`);
			
			updateHiddenAuthors(modalPrefix);
			return true;
		}

		/**
		 * Update hidden input with selected author IDs
		 */
		function updateHiddenAuthors(modalPrefix) {
			const authorIds = $(`#${modalPrefix}author-chips .author-chip`)
				.map(function() {
					const id = $(this).data('author-id');
					return Number.isInteger(parseInt(id)) ? id : null;
				})
				.get()
				.filter(id => id !== null)
				.join(',');
			
			$(`#${modalPrefix}selected-authors`).val(authorIds);
			console.log(`Updated ${modalPrefix}selected-authors: ${authorIds}`);
			
			// Update container styling
			if (authorIds) {
				$(`#${modalPrefix}author-chips`).addClass('has-chips');
			} else {
				$(`#${modalPrefix}author-chips`).removeClass('has-chips');
			}
			
			return authorIds;
		}

		/**
		 * Clear all author chips
		 */
		function clearAuthorChips(modalPrefix) {
			$(`#${modalPrefix}author-chips`).empty().removeClass('has-chips');
			$(`#${modalPrefix}selected-authors`).val('');
			$(`#${modalPrefix}author-search`).val('');
			console.log(`Cleared ${modalPrefix} author chips`);
		}

		/**
		 * Initialize autocomplete for author search
		 */
		function initAutocomplete(modalPrefix) {
			const searchField = $(`#${modalPrefix}author-search`);

			// Destroy existing autocomplete if it exists
			if (searchField.hasClass('ui-autocomplete-input')) {
				searchField.autocomplete('destroy');
				console.log(`Destroyed existing autocomplete for ${modalPrefix}author-search`);
			}

			searchField.autocomplete({
				source: function(request, response) {
					console.log(`Autocomplete search: term=${request.term}`);

					if (request.term.length < 3) {
						response([]);
						return;
					}

					$.ajax({
						url: 'books.php?autocomplete=1',
						dataType: 'json',
						data: { q: request.term },
						success: function(data) {
							console.log('Autocomplete response:', data);
							response(data.map(item => ({
								label: item.label,
								value: item.id
							})));
						},
						error: function(xhr, status, error) {
							console.error(`Autocomplete AJAX error: ${status}, ${error}`);
							response([]);
						}
					});
				},
				minLength: 3,
				select: function(event, ui) {
					console.log(`Selected author: ID=${ui.item.value}, Label=${ui.item.label}`);

					if (addAuthorChip(modalPrefix, ui.item.value, ui.item.label)) {
						$(this).val('');
					}

					return false;
				},
				open: function() {
					$('.ui-autocomplete').css('z-index', 1060);
					console.log('Autocomplete dropdown opened');
				},
				position: {
					my: "left top",
					at: "left bottom",
					collision: "none"
				}
			});

			console.log(`Initialized autocomplete for ${modalPrefix}author-search`);
		}

		// Initialize autocomplete for both modals
		initAutocomplete('');      // Edit modal
		initAutocomplete('add-');  // Add modal

		/**
		 * Handle recent authors dropdown selection - Edit modal
		 */
		$('#recent-authors').on('change', function() {
			const selectedOption = $(this).find('option:selected');
			const authorId = selectedOption.val();
			const authorName = selectedOption.data('name');

			if (authorId && authorName) {
				console.log(`Recent author selected (Edit): ID=${authorId}, Name=${authorName}`);
				addAuthorChip('', authorId, authorName);
				$(this).val(''); // Reset dropdown
			}
		});

		/**
		 * Handle recent authors dropdown selection - Add modal
		 */
		$('#add-recent-authors').on('change', function() {
			const selectedOption = $(this).find('option:selected');
			const authorId = selectedOption.val();
			const authorName = selectedOption.data('name');

			if (authorId && authorName) {
				console.log(`Recent author selected (Add): ID=${authorId}, Name=${authorName}`);
				addAuthorChip('add-', authorId, authorName);
				$(this).val(''); // Reset dropdown
			}
		});

		/**
		 * Remove author chip on click
		 */
		$(document).on('click', '.chip-remove', function() {
			const authorId = $(this).data('author-id');
			const modalPrefix = $(this).closest('.modal').find('input[name="add_book"]').length ? 'add-' : '';

			console.log(`Removing author chip: ${authorId}`);
			$(this).parent('.author-chip').fadeOut(200, function() {
				$(this).remove();
				updateHiddenAuthors(modalPrefix);
			});
		});

		/**
		 * Handle History Modal
		 */
		$('#historyModal').on('show.bs.modal', function(event) {
			const button = $(event.relatedTarget);
			const bookId = button.data('book-id');
			const bookTitle = button.data('book-title');
			
			$('#history-book-title').text(bookTitle);
			$('#history-content').html('<div class="text-center"><div class="spinner-border" role="status"></div></div>');
			
			// Fetch history via AJAX
			$.ajax({
				url: 'get_book_history.php',
				type: 'GET',
				data: { book_id: bookId },
				dataType: 'json',
				success: function(response) {
					if (response.success && response.history.length > 0) {
						let html = '<div class="table-responsive"><table class="table table-striped">';
						html += '<thead><tr><th>Date</th><th>Status</th><th>Note</th></tr></thead><tbody>';
						response.history.forEach(function(item) {
							html += '<tr>';
							html += '<td>' + item.inserted + '</td>';
							html += '<td><span class="badge bg-primary">' + item.status_name + '</span></td>';
							html += '<td>' + (item.note || '-') + '</td>';
							html += '</tr>';
						});
						html += '</tbody></table></div>';
						$('#history-content').html(html);
					} else {
						$('#history-content').html('<div class="alert alert-info">No history found for this book.</div>');
					}
				},
				error: function() {
					$('#history-content').html('<div class="alert alert-danger">Error loading history.</div>');
				}
			});
		});

		/**
		 * Handle Edit Book Modal
		 */
		$('#editBookModal').on('show.bs.modal', function(event) {
			const button = $(event.relatedTarget);
			const modal = $(this);
			
			// Populate form fields
			modal.find('#edit-book-id').val(button.data('id'));
			modal.find('#edit-book-title').val(button.data('title'));
			modal.find('#edit-book-pages').val(button.data('pages'));
			modal.find('#edit-book-date_start').val(button.data('date_start'));
			modal.find('#edit-book-date_finish').val(button.data('date_finish'));
			modal.find('#edit-book-id_status').val(button.data('id_status'));
			modal.find('#edit-book-id_formating').val(button.data('id_formating'));
			modal.find('#edit-book-invoice').prop('checked', button.data('invoice') == 1);
			modal.find('#edit-book-note').val(button.data('note'));
			
			// Clear existing author chips
			clearAuthorChips('');
			
			// Load authors
			const authorsData = button.data('authors');
			console.log('Loading authors for edit:', authorsData);
			
			if (authorsData && Array.isArray(authorsData)) {
				authorsData.forEach(function(author) {
					if (author.id && author.name) {
						addAuthorChip('', author.id, author.name);
					} else {
						console.warn(`Invalid author data: ID=${author.id}, Name=${author.name}`);
					}
				});
			} else {
				console.warn('No authors or invalid authors data:', authorsData);
			}
			
			// Re-initialize autocomplete
			initAutocomplete('');

			// Reset recent authors dropdown
			$('#recent-authors').val('');

			console.log('Edit modal loaded, current authors:', $('#selected-authors').val());
		});

		/**
		 * Handle Add Book Modal
		 */
		$('#addBookModal').on('show.bs.modal', function() {
			// Clear form
			$('#addBookForm')[0].reset();
			clearAuthorChips('add-');

			// Re-initialize autocomplete
			initAutocomplete('add-');

			// Reset recent authors dropdown
			$('#add-recent-authors').val('');
			
			console.log('Add book modal opened');
		});

		/**
		 * Form submission logging
		 */
		$('#editBookForm').on('submit', function(e) {
			const authorIds = $('#selected-authors').val();
			console.log('Submitting edit form, selected-authors:', authorIds || 'none');
			
			if (!authorIds) {
				console.warn('No authors selected for submission');
			}
		});

		$('#addBookForm').on('submit', function(e) {
			const authorIds = $('#add-selected-authors').val();
			console.log('Submitting add form, selected-authors:', authorIds || 'none');
			
			if (!authorIds) {
				console.warn('No authors selected for submission');
			}
		});
		
		// Check if we should open add modal (from hash)
		if (window.location.hash === '#openAddModal') {
			console.log('Hash detected, opening Add Book modal');
			var addModal = new bootstrap.Modal(document.getElementById('addBookModal'));
			addModal.show();
			// Remove hash from URL after modal opens
			setTimeout(function() {
				history.replaceState(null, null, window.location.pathname + window.location.search);
			}, 100);
		}
		
		/**
		 * CSV Export
		 */
		$('#exportCsvBtn').on('click', function() {
			// Get current filter values
			var searchTitle = $('input[name="search_title"]').val();
			var searchAuthor = $('input[name="search_author"]').val();
			var filterStatus = $('select[name="filter_status"]').val();
			var filterInvoice = $('select[name="filter_invoice"]').val();
			
			// Build URL with current filters
			var exportUrl = 'export_books_csv.php?';
			var params = [];
			
			if (searchTitle) params.push('search_title=' + encodeURIComponent(searchTitle));
			if (searchAuthor) params.push('search_author=' + encodeURIComponent(searchAuthor));
			if (filterStatus) params.push('filter_status=' + encodeURIComponent(filterStatus));
			if (filterInvoice !== '') params.push('filter_invoice=' + encodeURIComponent(filterInvoice));
			
			exportUrl += params.join('&');
			
			console.log('Exporting CSV with URL:', exportUrl);
			
			// Trigger download
			window.location.href = exportUrl;
		});
	});
	</script>
<?php include 'footer.php'; ?>	
</body>
</html>