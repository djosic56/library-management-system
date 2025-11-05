<?php
// statistics.php - Statistics page for admin
require_once 'bootstrap.php';
require_once 'functions.php';
require_admin();

try {
	// Statistics by Status
	$sql_status = "SELECT 
					s.id,
					s.name as status_name,
					COUNT(b.id) as book_count,
					COALESCE(SUM(b.pages), 0) as total_pages,
					COALESCE(AVG(b.pages), 0) as avg_pages
				   FROM status s
				   LEFT JOIN book b ON s.id = b.id_status
				   GROUP BY s.id, s.name
				   ORDER BY s.id";
	$stmt = $pdo->query($sql_status);
	$stats_by_status = $stmt->fetchAll();
	
	// Calculate grand totals
	$grand_total_books = 0;
	$grand_total_pages = 0;
	foreach ($stats_by_status as $stat) {
		$grand_total_books += $stat['book_count'];
		$grand_total_pages += $stat['total_pages'];
	}
	
	// Statistics by Year (based on date_finish)
	$sql_year = "SELECT 
					YEAR(b.date_finish) as year,
					COUNT(b.id) as book_count,
					COALESCE(SUM(b.pages), 0) as total_pages,
					COALESCE(AVG(b.pages), 0) as avg_pages
				 FROM book b
				 WHERE b.date_finish IS NOT NULL
				 GROUP BY YEAR(b.date_finish)
				 ORDER BY year DESC";
	$stmt = $pdo->query($sql_year);
	$stats_by_year = $stmt->fetchAll();
	
	// Books without finish date
	$sql_no_date = "SELECT 
					COUNT(b.id) as book_count,
					COALESCE(SUM(b.pages), 0) as total_pages
					FROM book b
					WHERE b.date_finish IS NULL";
	$stmt = $pdo->query($sql_no_date);
	$no_finish_date = $stmt->fetch();
	
	// Additional stats
	$sql_additional = "SELECT 
						COUNT(DISTINCT b.id) as total_books,
						COUNT(DISTINCT a.id) as total_authors,
						COALESCE(SUM(b.pages), 0) as all_pages,
						COALESCE(AVG(b.pages), 0) as avg_pages_all
					   FROM book b
					   LEFT JOIN book_author ba ON b.id = ba.id_book
					   LEFT JOIN author a ON ba.id_author = a.id";
	$stmt = $pdo->query($sql_additional);
	$additional_stats = $stmt->fetch();
	
	// Books per format
	$sql_format = "SELECT 
					f.shortname,
					f.name,
					COUNT(b.id) as book_count,
					COALESCE(SUM(b.pages), 0) as total_pages
				   FROM formating f
				   LEFT JOIN book b ON f.id = b.id_formating
				   GROUP BY f.id, f.shortname, f.name
				   ORDER BY book_count DESC";
	$stmt = $pdo->query($sql_format);
	$stats_by_format = $stmt->fetchAll();
	
} catch (PDOException $e) {
	$error = "Database error occurred.";
	error_log("Statistics error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Statistics - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
	<style>
		.stat-card {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			border-radius: 10px;
			padding: 20px;
			margin-bottom: 20px;
		}
		.stat-card h3 {
			font-size: 2.5rem;
			font-weight: bold;
			margin: 0;
		}
		.stat-card p {
			margin: 0;
			opacity: 0.9;
		}
		.chart-container {
			position: relative;
			height: 300px;
			margin-bottom: 30px;
		}
		.table-stats {
			background: white;
			border-radius: 10px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}
		.table-stats th {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
		}
		.grand-total {
			background: #f8f9fa;
			font-weight: bold;
		}
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1><i class="bi bi-graph-up"></i> Statistics</h1>
		
		<?php if (isset($error)) { ?>
			<div class="alert alert-danger alert-dismissible fade show">
				<i class="bi bi-exclamation-triangle"></i>
				<?php echo htmlspecialchars($error); ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
		<?php } ?>
		
		<!-- Summary Cards -->
		<div class="row mb-4">
			<div class="col-md-3">
				<div class="stat-card">
					<p class="mb-1">Total Books</p>
					<h3><?php echo number_format($additional_stats['total_books']); ?></h3>
				</div>
			</div>
			<div class="col-md-3">
				<div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
					<p class="mb-1">Total Pages</p>
					<h3><?php echo number_format($additional_stats['all_pages']); ?></h3>
				</div>
			</div>
			<div class="col-md-3">
				<div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
					<p class="mb-1">Average Pages</p>
					<h3><?php echo number_format($additional_stats['avg_pages_all'], 0); ?></h3>
				</div>
			</div>
			<div class="col-md-3">
				<div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
					<p class="mb-1">Total Authors</p>
					<h3><?php echo number_format($additional_stats['total_authors']); ?></h3>
				</div>
			</div>
		</div>
		
		<!-- Statistics by Status -->
		<div class="card table-stats mb-4">
			<div class="card-body">
				<h4 class="mb-3"><i class="bi bi-bar-chart"></i> Books by Status</h4>
				<div class="table-responsive">
					<table class="table table-hover">
						<thead>
							<tr>
								<th>Status</th>
								<th class="text-end">Number of Books</th>
								<th class="text-end">Total Pages</th>
								<th class="text-end">Average Pages</th>
								<th class="text-end">% of Total</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($stats_by_status as $stat): ?>
							<tr>
								<td><strong><?php echo htmlspecialchars($stat['status_name']); ?></strong></td>
								<td class="text-end"><?php echo number_format($stat['book_count']); ?></td>
								<td class="text-end"><?php echo number_format($stat['total_pages']); ?></td>
								<td class="text-end"><?php echo number_format($stat['avg_pages'], 0); ?></td>
								<td class="text-end">
									<?php 
									$percentage = $grand_total_books > 0 ? ($stat['book_count'] / $grand_total_books * 100) : 0;
									echo number_format($percentage, 1) . '%'; 
									?>
								</td>
							</tr>
							<?php endforeach; ?>
							<tr class="grand-total">
								<td><strong>TOTAL</strong></td>
								<td class="text-end"><strong><?php echo number_format($grand_total_books); ?></strong></td>
								<td class="text-end"><strong><?php echo number_format($grand_total_pages); ?></strong></td>
								<td class="text-end"><strong><?php echo $grand_total_books > 0 ? number_format($grand_total_pages / $grand_total_books, 0) : 0; ?></strong></td>
								<td class="text-end"><strong>100%</strong></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		
		<!-- Statistics by Year -->
		<div class="card table-stats mb-4">
			<div class="card-body">
				<h4 class="mb-3"><i class="bi bi-calendar3"></i> Books by Year (Finish Date)</h4>
				<div class="table-responsive">
					<table class="table table-hover">
						<thead>
							<tr>
								<th>Year</th>
								<th class="text-end">Number of Books</th>
								<th class="text-end">Total Pages</th>
								<th class="text-end">Average Pages</th>
							</tr>
						</thead>
						<tbody>
							<?php 
							$total_books_with_date = 0;
							$total_pages_with_date = 0;
							foreach ($stats_by_year as $stat): 
								$total_books_with_date += $stat['book_count'];
								$total_pages_with_date += $stat['total_pages'];
							?>
							<tr>
								<td><strong><?php echo $stat['year']; ?></strong></td>
								<td class="text-end"><?php echo number_format($stat['book_count']); ?></td>
								<td class="text-end"><?php echo number_format($stat['total_pages']); ?></td>
								<td class="text-end"><?php echo number_format($stat['avg_pages'], 0); ?></td>
							</tr>
							<?php endforeach; ?>
							<?php if ($no_finish_date['book_count'] > 0): ?>
							<tr class="text-muted">
								<td><em>No finish date</em></td>
								<td class="text-end"><?php echo number_format($no_finish_date['book_count']); ?></td>
								<td class="text-end"><?php echo number_format($no_finish_date['total_pages']); ?></td>
								<td class="text-end">-</td>
							</tr>
							<?php endif; ?>
							<tr class="grand-total">
								<td><strong>TOTAL</strong></td>
								<td class="text-end"><strong><?php echo number_format($total_books_with_date + $no_finish_date['book_count']); ?></strong></td>
								<td class="text-end"><strong><?php echo number_format($total_pages_with_date + $no_finish_date['total_pages']); ?></strong></td>
								<td class="text-end"><strong>-</strong></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		
		<!-- Statistics by Format -->
		<div class="card table-stats mb-4">
			<div class="card-body">
				<h4 class="mb-3"><i class="bi bi-file-earmark"></i> Books by Format</h4>
				<div class="table-responsive">
					<table class="table table-hover">
						<thead>
							<tr>
								<th>Format</th>
								<th>Name</th>
								<th class="text-end">Number of Books</th>
								<th class="text-end">Total Pages</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($stats_by_format as $stat): ?>
							<?php if ($stat['book_count'] > 0): ?>
							<tr>
								<td><strong><?php echo htmlspecialchars($stat['shortname']); ?></strong></td>
								<td><?php echo htmlspecialchars($stat['name']); ?></td>
								<td class="text-end"><?php echo number_format($stat['book_count']); ?></td>
								<td class="text-end"><?php echo number_format($stat['total_pages']); ?></td>
							</tr>
							<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		
		<a href="index.php" class="btn btn-secondary mb-4">
			<i class="bi bi-arrow-left"></i> Back to Home
		</a>
	</div>
	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>