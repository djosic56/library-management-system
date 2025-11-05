<?php
// index.php - Refactored with services
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

$username = 'User';
$password_changed = false;

if (isset($_SESSION['password_changed'])) {
	$password_changed = true;
	unset($_SESSION['password_changed']);
}

try {
	// Services
	$bookService = getBookService();
	$authorService = getAuthorService();

	// Fetch username
	$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
	$stmt->execute([$_SESSION['user_id']]);
	$user = $stmt->fetch();
	$username = $user ? htmlspecialchars($user['username']) : 'User';

	// Date filters
	$status_filter_date = '2025-08-13';
	$three_months_ago = date('Y-m-d', strtotime('-3 months'));

	// Get data for statuses 1-4
	$status_counts = [];
	$status_books = [];
	$status_pages = [];

	for ($status = 1; $status <= 4; $status++) {
		$dateFilter = ($status == 4) ? $status_filter_date : null;

		$status_books[$status] = $bookService->getBooksByStatus($status, $dateFilter);
		$status_counts[$status] = count($status_books[$status]);
		$status_pages[$status] = $bookService->getTotalPagesByStatus($status, $dateFilter);
	}

	// Status 4 without invoice
	$status_books['4_no_invoice'] = $bookService->getBooksByStatusWithoutInvoice(4, $status_filter_date);
	$status_counts['4_no_invoice'] = count($status_books['4_no_invoice']);

	$total_pages_no_invoice = 0;
	foreach ($status_books['4_no_invoice'] as $book) {
		$total_pages_no_invoice += (int)$book['pages'];
	}

	// Books from last 3 months (status 4)
	$books_3months = $bookService->getBooksByStatus(4, $three_months_ago);

	// Status names
	$status_names = [];
	foreach (get_statuses() as $status) {
		$status_names[$status['id']] = htmlspecialchars($status['name']);
	}

	// Totals
	$total_books = $bookService->getBooksCount();
	$total_authors = $authorService->getAuthorsCount();

} catch (Exception $e) {
	$error = "Database error occurred. Please contact administrator.";
	error_log("Index error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Library Home - Dashboard</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
	<style>
		/* Hero section styling */
		.hero-section {
			background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), 
						url('https://images.unsplash.com/photo-1507842217343-583bb727ad02?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
			background-size: cover;
			background-position: center;
			background-attachment: fixed;
			color: white;
			text-align: center;
			padding: 30px 20px;
			margin-bottom: 20px;
			border-radius: 0 0 15px 15px;
		}
		.hero-section h1 {
			font-size: 2.5rem;
			font-weight: bold;
			text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
		}
		.hero-section p {
			font-size: 1.25rem;
			text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
		}
		/* Card hover effect */
		.card {
			transition: transform 0.3s, box-shadow 0.3s;
			border: none;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}
		.card:hover {
			transform: translateY(-5px);
			box-shadow: 0 6px 20px rgba(0,0,0,0.15);
		}
		/* Status card styling */
		.status-card {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
		}
		.status-card .card-title {
			font-size: 1.1rem;
			font-weight: 600;
		}
		.status-card .title-list {
			max-height: 150px;
			overflow-y: auto;
			font-size: 0.9rem;
			padding-left: 20px;
			margin-bottom: 0;
			text-align: left;
			background: rgba(255,255,255,0.1);
			padding: 10px;
			border-radius: 5px;
		}
		.status-card-4 {
			background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
		}
		.title-list4 {
			max-height: 400px;
			overflow-y: auto;
			font-size: 0.9rem;
			padding-left: 20px;
			margin-bottom: 0;
			text-align: left;
			background: rgba(255,255,255,0.1);
			padding: 10px;
			border-radius: 5px;
		}
		.status-card .title-list:empty::before,
		.title-list4:empty::before {
			content: "No books";
			color: rgba(255,255,255,0.7);
		}
		.status-card .authors {
			font-size: 0.8rem;
			color: rgba(255,255,255,0.8);
			margin-top: 0.2rem;
			margin-bottom: 0.3rem;
		}
		.status-card .authors:empty::before {
			content: "No authors";
			color: rgba(255,255,255,0.6);
		}
		.nav-card {
			background: white;
			border-radius: 10px;
		}
		.nav-card i {
			color: #667eea;
		}
		.stats-badge {
			background: #667eea;
			color: white;
			padding: 5px 15px;
			border-radius: 20px;
			font-weight: 600;
		}
		.pages-badge {
			background: rgba(255,255,255,0.2);
			padding: 4px 12px;
			border-radius: 15px;
			font-size: 0.85rem;
			font-weight: 600;
			display: inline-block;
			margin-top: 5px;
		}
		/* Scrollbar styling */
		.title-list::-webkit-scrollbar,
		.title-list4::-webkit-scrollbar {
			width: 8px;
		}
		.title-list::-webkit-scrollbar-track,
		.title-list4::-webkit-scrollbar-track {
			background: rgba(255,255,255,0.1);
			border-radius: 10px;
		}
		.title-list::-webkit-scrollbar-thumb,
		.title-list4::-webkit-scrollbar-thumb {
			background: rgba(255,255,255,0.3);
			border-radius: 10px;
		}
		.title-list::-webkit-scrollbar-thumb:hover,
		.title-list4::-webkit-scrollbar-thumb:hover {
			background: rgba(255,255,255,0.5);
		}
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container-fluid p-0">
		<!-- Hero Section -->
		<div class="hero-section">
			<h1><i class="bi bi-book"></i> Welcome, <?php echo $username; ?>!</h1>
			<p>Manage your library collection with ease</p>
			<?php if (isset($total_books) && isset($total_authors)) { ?>
			<div class="mt-3">
				<span class="stats-badge me-2">
					<i class="bi bi-book"></i> <?php echo $total_books; ?> Books
				</span>
				<span class="stats-badge">
					<i class="bi bi-people"></i> <?php echo $total_authors; ?> Authors
				</span>
			</div>
			<?php } ?>
		</div>

		<!-- Main Content -->
		<div class="container">
			<?php if ($password_changed) { ?>
				<div class="alert alert-success alert-dismissible fade show">
					<i class="bi bi-check-circle"></i>
					<strong>Success!</strong> Your password has been changed successfully.
					<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
				</div>
			<?php } ?>
			
			<?php if (isset($error)) { ?>
				<div class="alert alert-danger alert-dismissible fade show">
					<i class="bi bi-exclamation-triangle"></i>
					<?php echo htmlspecialchars($error); ?>
					<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
				</div>
			<?php } ?>

			<!-- Navigation Cards -->
			<h2 class="mb-4"><i class="bi bi-grid"></i> Quick Actions</h2>
			<div class="row g-4 mb-5">
				<div class="col-md-6">
					<div class="card h-100 nav-card">
						<div class="card-body text-center">
							<i class="bi bi-person-lines-fill fs-1 mb-3"></i>
							<h5 class="card-title">Authors</h5>
							<p class="card-text">Add or explore authors and their works.</p>
							<a href="authors.php" class="btn btn-outline-primary me-2">
								<i class="bi bi-list"></i> Browse Authors
							</a>
							<?php if (is_admin()) { ?>
							<a href="authors.php#openAddModal" class="btn btn-primary">
								<i class="bi bi-plus-circle"></i> New Author
							</a>
							<?php } ?>
						</div>
					</div>
				</div>
				<div class="col-md-6">
					<div class="card h-100 nav-card">
						<div class="card-body text-center">
							<i class="bi bi-book fs-1 mb-3"></i>
							<h5 class="card-title">Books</h5>
							<p class="card-text">Add or manage our collection of books.</p>
							<a href="books.php" class="btn btn-outline-primary me-2">
								<i class="bi bi-list"></i> Browse Books
							</a>
							<?php if (is_admin()) { ?>
							<a href="books.php#openAddModal" class="btn btn-primary">
								<i class="bi bi-plus-circle"></i> New Book
							</a>
							<?php } ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Book Status Overview -->
			<h2 class="mb-4"><i class="bi bi-bar-chart"></i> Book Status Overview</h2>
			<div class="row g-4 mb-4">
				<?php foreach ([1, 2, 3] as $status): ?>
					<div class="col-md-4 col-lg-3">
						<div class="card h-100 status-card">
							<div class="card-body">
								<h5 class="card-title">
									<i class="bi bi-book fs-4"></i> 
									<?php echo $status_names[$status] ?? "Status $status"; ?>
								</h5>
								<p class="card-text mb-2">
									<strong><?php echo $status_counts[$status] ?? 0; ?></strong> 
									<?php echo ($status_counts[$status] ?? 0) == 1 ? 'book' : 'books'; ?>
								</p>
								<?php if ($status_pages[$status] > 0): ?>
								<div class="pages-badge">
									<i class="bi bi-file-earmark-text"></i>
									<?php echo number_format($status_pages[$status]); ?> pages
								</div>
								<?php endif; ?>
								
								<?php // Email button only for status 3 (correct) ?>
								<?php if ($status == 3 && !empty($status_books[$status])): 
									// Build email content for correct status
									$email_subject = "Corrections";
									$email_body = "Books for correction:\n\n";
									foreach ($status_books[$status] as $book) {
										$email_body .= htmlspecialchars($book['title']) . "\n";
										if ($book['pages']) {
											$email_body .= "Pages: " . $book['pages'] . "\n";
										}
										if ($book['last_status_change']) {
											$email_body .= "Last updated: " . date('d.m.Y', strtotime($book['last_status_change'])) . "\n";
										}
										$email_body .= "\n";
									}
									$email_body .= "Total pages: " . number_format($status_pages[$status]);
									
									// Replace + with %20 (space)
									$mailto_correct = "mailto:?subject=" . str_replace('+', '%20', urlencode($email_subject)) . 
									                  "&body=" . str_replace('+', '%20', urlencode($email_body));
								?>
								<a href="<?php echo $mailto_correct; ?>" class="btn btn-sm btn-light mb-2" title="Send email list">
									<i class="bi bi-envelope"></i> Email List
								</a>
								<?php endif; ?>
								
								<?php if (!empty($status_books[$status])) { ?>
								<ul class="title-list mt-2">
									<?php foreach ($status_books[$status] as $book): ?>
										<li class="mb-2">
											<strong><?php echo htmlspecialchars($book['title']); ?></strong>
											<?php if ($book['pages']) { ?>
												<span style="color: #ffd700; font-weight: 600;">(<?php echo $book['pages']; ?> str.)</span>
											<?php } ?>
											<?php if ($book['last_status_change']) { ?>
												<br><small style="color: rgba(255,255,255,0.7);">
													<i class="bi bi-clock"></i> 
													<?php echo date('d.m.Y', strtotime($book['last_status_change'])); ?>
												</small>
											<?php } ?>
											<?php if ($book['authors']) { ?>
											<div class="authors">
												<i class="bi bi-person"></i> 
												<?php echo htmlspecialchars($book['authors']); ?>
											</div>
											<?php } ?>
										</li>
									<?php endforeach; ?>
								</ul>
								<?php } ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				
				<!-- Status 4 No Invoice -->
				<div class="col-md-4 col-lg-3">
					<div class="card h-100 status-card status-card-4">
						<div class="card-body">
							<h5 class="card-title">
								<i class="bi bi-cart-x fs-4"></i> 
								<?php echo $status_names[4] ?? 'Status 4'; ?> (No Invoice)
							</h5>
							<p class="card-text mb-2">
								<strong><?php echo $status_counts['4_no_invoice'] ?? 0; ?></strong> 
								<?php echo ($status_counts['4_no_invoice'] ?? 0) == 1 ? 'book' : 'books'; ?>
							</p>
							<?php if ($total_pages_no_invoice > 0): ?>
							<div class="pages-badge">
								<i class="bi bi-file-earmark-text"></i>
								<?php echo number_format($total_pages_no_invoice); ?> pages
							</div>
							<?php endif; ?>
							
							<?php if (!empty($status_books['4_no_invoice'])) { 
								// Build email content
								$email_body = "Finished books without invoice:\n\n";
								foreach ($status_books['4_no_invoice'] as $book) {
									$email_body .= htmlspecialchars($book['title']) . "\n";
									$email_body .= "Author: " . htmlspecialchars($book['authors'] ?? 'N/A') . "\n";
									$email_body .= "Pages: " . ($book['pages'] ?? 'N/A') . "\n\n";
								}
								$email_body .= "Total pages: " . number_format($total_pages_no_invoice);
								
								// Replace + with %20 (space)
								$mailto_link = "mailto:?subject=" . str_replace('+', '%20', urlencode("Finished Books - No Invoice")) . 
								               "&body=" . str_replace('+', '%20', urlencode($email_body));
							?>
							<a href="<?php echo $mailto_link; ?>" class="btn btn-sm btn-light mb-2" title="Send email list">
								<i class="bi bi-envelope"></i> Email List
							</a>
							<ul class="title-list">
								<?php foreach ($status_books['4_no_invoice'] as $book): ?>
									<li class="mb-2">
										<strong><?php echo htmlspecialchars($book['title']); ?></strong>
										<?php if ($book['pages']) { ?>
											<span style="color: #ffd700; font-weight: 600;">(<?php echo $book['pages']; ?> str.)</span>
										<?php } ?>
										<?php if ($book['authors']) { ?>
										<div class="authors">
											<i class="bi bi-person"></i> 
											<?php echo htmlspecialchars($book['authors']); ?>
										</div>
										<?php } ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php } ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Full Status 4 List -->
			<div class="row g-4 mb-5">
				<div class="col-12 col-lg-9">
					<div class="card status-card" style="background: linear-gradient(135deg, #434343 0%, #000000 100%);">
						<div class="card-body">
							<h5 class="card-title">
								<i class="bi bi-book fs-4"></i>
								<?php echo $status_names[4] ?? "Status 4"; ?> - All Books (Last 3 Months)
							</h5>
							<p class="card-text mb-3">
								<strong><?php echo count($books_3months); ?></strong> 
								<?php echo count($books_3months) == 1 ? 'book' : 'books'; ?>
								<small class="ms-2">(since <?php echo $three_months_ago; ?>)</small>
							</p>
							<?php if (!empty($books_3months)) { ?>
							<ul class="title-list4">
								<?php foreach ($books_3months as $book): ?>
									<li class="mb-2">
										<strong><?php echo htmlspecialchars($book['title']); ?></strong>
										<?php if ($book['pages']) { ?>
											<span style="color: #ffd700; font-weight: 600;">(<?php echo $book['pages']; ?> str.)</span>
										<?php } ?>
										<?php if ($book['authors']) { ?>
										<div class="authors">
											<i class="bi bi-person"></i> 
											<?php echo htmlspecialchars($book['authors']); ?>
										</div>
										<?php } ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php } ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Smooth scrolling for anchor links (only for actual anchors on page)
		$('a[href*="#"]').on('click', function(e) {
			var href = $(this).attr('href');
			var target = href.split('#')[1];
			
			// Skip if it's #openAddModal (used for modal trigger)
			if (target === 'openAddModal') {
				return true; // Allow default behavior (navigate)
			}
			
			// Only handle if target exists on this page
			if (target && target.length > 0 && $('#' + target).length > 0) {
				e.preventDefault();
				$('html, body').animate({
					scrollTop: $('#' + target).offset().top - 70
				}, 500);
			}
		});
		
		// Auto-dismiss alerts
		setTimeout(function() {
			$('.alert').fadeOut('slow');
		}, 5000);
	});
	</script>
	<?php include 'footer.php'; ?>
</body>
</html>