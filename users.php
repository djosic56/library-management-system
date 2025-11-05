<?php
// users.php - Improved version with better UI
require_once 'bootstrap.php';
require_once 'functions.php';
require_admin();

try {
	// Pagination and sorting
	$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
	$sort_by = filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_STRING) ?? 'timestamp';
	$sort_order = filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_STRING) ?? 'DESC';
	$filter_action = filter_input(INPUT_GET, 'filter_action', FILTER_SANITIZE_STRING) ?? '';
	$filter_user = filter_input(INPUT_GET, 'filter_user', FILTER_SANITIZE_STRING) ?? '';
	
	$offset = ($page - 1) * ITEMS_PER_PAGE;

	// Validate sort_by
	$valid_sort_columns = ['id', 'user_id', 'username', 'action', 'timestamp'];
	$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'timestamp';
	$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

	// Build WHERE clause
	$where = [];
	$params = [];
	
	if ($filter_action) {
		$where[] = "ul.action = ?";
		$params[] = $filter_action;
	}
	
	if ($filter_user) {
		$where[] = "u.username LIKE ?";
		$params[] = "%$filter_user%";
	}
	
	$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

	// Fetch logs
	$sql = "SELECT ul.id, ul.user_id, u.username, ul.action, ul.details, ul.ip, ul.timestamp
			FROM user_log ul
			JOIN users u ON ul.user_id = u.id
			$where_clause
			ORDER BY ul.$sort_by $sort_order
			LIMIT ? OFFSET ?";
	
	$stmt = $pdo->prepare($sql);
	foreach ($params as $i => $param) {
		$stmt->bindValue($i + 1, $param, PDO::PARAM_STR);
	}
	$stmt->bindValue(count($params) + 1, ITEMS_PER_PAGE, PDO::PARAM_INT);
	$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
	$stmt->execute();
	$logs = $stmt->fetchAll();

	// Count total logs
	$count_sql = "SELECT COUNT(*) FROM user_log ul JOIN users u ON ul.user_id = u.id $where_clause";
	$stmt = $pdo->prepare($count_sql);
	foreach ($params as $i => $param) {
		$stmt->bindValue($i + 1, $param, PDO::PARAM_STR);
	}
	$stmt->execute();
	$total_logs = $stmt->fetchColumn();
	$total_pages = ceil($total_logs / ITEMS_PER_PAGE);

	// Get distinct actions for filter
	$stmt = $pdo->query("SELECT DISTINCT action FROM user_log ORDER BY action");
	$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
	
	// Get distinct users for filter
	$stmt = $pdo->query("SELECT DISTINCT u.username FROM user_log ul JOIN users u ON ul.user_id = u.id ORDER BY u.username");
	$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

	// Pagination range
	$max_pages_to_show = 5;
	$half_range = floor($max_pages_to_show / 2);
	$start_page = max(1, $page - $half_range);
	$end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
	if ($end_page - $start_page + 1 < $max_pages_to_show) {
		$start_page = max(1, $end_page - $max_pages_to_show + 1);
	}

	// Next sort orders
	$next_sort_order_id = ($sort_by === 'id' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
	$next_sort_order_user_id = ($sort_by === 'user_id' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
	$next_sort_order_username = ($sort_by === 'username' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
	$next_sort_order_action = ($sort_by === 'action' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
	$next_sort_order_timestamp = ($sort_by === 'timestamp' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
	
} catch (PDOException $e) {
	$error = "Database error occurred.";
	error_log("Users error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>User Logs - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
	<style>
		.action-badge {
			font-size: 0.85rem;
			padding: 5px 10px;
		}
		.log-details {
			max-width: 200px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1><i class="bi bi-clock-history"></i> User Activity Logs</h1>
		
		<?php if (isset($error)) { ?>
			<div class="alert alert-danger alert-dismissible fade show">
				<i class="bi bi-exclamation-triangle"></i>
				<?php echo htmlspecialchars($error); ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
		<?php } ?>
		
		<!-- Statistics -->
		<div class="row mb-4">
			<div class="col-md-3">
				<div class="card text-center">
					<div class="card-body">
						<h3 class="text-primary"><?php echo $total_logs; ?></h3>
						<p class="text-muted mb-0">Total Logs</p>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card text-center">
					<div class="card-body">
						<h3 class="text-success"><?php echo count($users); ?></h3>
						<p class="text-muted mb-0">Active Users</p>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card text-center">
					<div class="card-body">
						<h3 class="text-info"><?php echo count($actions); ?></h3>
						<p class="text-muted mb-0">Action Types</p>
					</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card text-center">
					<div class="card-body">
						<h3 class="text-warning"><?php echo $total_pages; ?></h3>
						<p class="text-muted mb-0">Pages</p>
					</div>
				</div>
			</div>
		</div>
		
		<!-- Filters -->
		<form method="GET" class="mb-3">
			<div class="row g-3">
				<div class="col-md-3">
					<select name="filter_action" class="form-control">
						<option value="">All Actions</option>
						<?php foreach ($actions as $action) { ?>
							<option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filter_action === $action ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($action); ?>
							</option>
						<?php } ?>
					</select>
				</div>
				<div class="col-md-3">
					<select name="filter_user" class="form-control">
						<option value="">All Users</option>
						<?php foreach ($users as $user) { ?>
							<option value="<?php echo htmlspecialchars($user); ?>" <?php echo $filter_user === $user ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($user); ?>
							</option>
						<?php } ?>
					</select>
				</div>
				<div class="col-md-3">
					<button type="submit" class="btn btn-primary w-100">
						<i class="bi bi-filter"></i> Filter
					</button>
				</div>
				<div class="col-md-3">
					<a href="users.php" class="btn btn-secondary w-100">
						<i class="bi bi-x-circle"></i> Clear
					</a>
				</div>
			</div>
			<input type="hidden" name="page" value="1">
			<input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
			<input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
		</form>
		
		<!-- Pagination -->
		<nav aria-label="Page navigation" class="mb-3">
			<ul class="pagination pagination-sm">
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=1">First</a>
				</li>
				<li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
					<a class="page-link" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page - 1; ?>">Previous</a>
				</li>
				<?php for ($i = $start_page; $i <= $end_page; $i++) { ?>
					<li class="page-item <?php if ($i == $page) echo 'active'; ?>">
						<a class="page-link" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
					</li>
				<?php } ?>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $page + 1; ?>">Next</a>
				</li>
				<li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
					<a class="page-link" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=<?php echo urlencode($sort_by); ?>&sort_order=<?php echo urlencode($sort_order); ?>&page=<?php echo $total_pages; ?>">Last</a>
				</li>
			</ul>
		</nav>
		
		<!-- Logs Table -->
		<div class="table-responsive">
			<table class="table table-striped table-hover">
				<thead class="table-dark">
					<tr>
						<th style="width: 60px">
							<a class="text-white text-decoration-none" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=id&sort_order=<?php echo $next_sort_order_id; ?>&page=1">
								ID <?php echo $sort_by === 'id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th style="width: 80px">
							<a class="text-white text-decoration-none" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=user_id&sort_order=<?php echo $next_sort_order_user_id; ?>&page=1">
								User ID <?php echo $sort_by === 'user_id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>
							<a class="text-white text-decoration-none" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=username&sort_order=<?php echo $next_sort_order_username; ?>&page=1">
								Username <?php echo $sort_by === 'username' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>
							<a class="text-white text-decoration-none" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=action&sort_order=<?php echo $next_sort_order_action; ?>&page=1">
								Action <?php echo $sort_by === 'action' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>Details</th>
						<th style="width: 120px">IP Address</th>
						<th style="width: 160px">
							<a class="text-white text-decoration-none" href="?filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&sort_by=timestamp&sort_order=<?php echo $next_sort_order_timestamp; ?>&page=1">
								Timestamp <?php echo $sort_by === 'timestamp' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($logs)) { ?>
						<tr>
							<td colspan="7" class="text-center text-muted">
								<i class="bi bi-inbox"></i> No logs found
							</td>
						</tr>
					<?php } else { ?>
						<?php foreach ($logs as $log) { 
							// Determine badge color based on action
							$badge_class = 'bg-secondary';
							if (strpos($log['action'], 'login') !== false) $badge_class = 'bg-success';
							elseif (strpos($log['action'], 'logout') !== false) $badge_class = 'bg-info';
							elseif (strpos($log['action'], 'add') !== false) $badge_class = 'bg-primary';
							elseif (strpos($log['action'], 'edit') !== false) $badge_class = 'bg-warning text-dark';
							elseif (strpos($log['action'], 'delete') !== false) $badge_class = 'bg-danger';
							elseif (strpos($log['action'], 'password') !== false) $badge_class = 'bg-dark';
						?>
						<tr>
							<td><?php echo htmlspecialchars($log['id']); ?></td>
							<td><?php echo htmlspecialchars($log['user_id']); ?></td>
							<td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
							<td>
								<span class="badge action-badge <?php echo $badge_class; ?>">
									<?php echo htmlspecialchars($log['action']); ?>
								</span>
							</td>
							<td>
								<?php if ($log['details']) { ?>
									<span class="log-details" title="<?php echo htmlspecialchars($log['details']); ?>">
										<?php echo htmlspecialchars($log['details']); ?>
									</span>
								<?php } else { ?>
									<span class="text-muted">-</span>
								<?php } ?>
							</td>
							<td><code><?php echo htmlspecialchars($log['ip']); ?></code></td>
							<td>
								<small><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></small>
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
	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Auto-refresh every 30 seconds (optional)
		// setInterval(function() {
		// 	location.reload();
		// }, 30000);
		
		// Tooltip for truncated details
		$('.log-details').hover(function() {
			$(this).css('cursor', 'help');
		});
	});
	</script>
<?php include 'footer.php'; ?>	
</body>
</html>