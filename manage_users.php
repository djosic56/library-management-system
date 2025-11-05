<?php
// manage_users.php
require_once 'bootstrap.php';
require_once 'functions.php';
require_admin();

$error = '';
$success = '';

// Pagination and sorting
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$sort_by = filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_STRING) ?? 'id';
$sort_order = filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_STRING) ?? 'ASC';
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';

$items_per_page = 20;
$offset = ($page - 1) * $items_per_page;

// Validate sort_by
$valid_sort_columns = ['id', 'username', 'level', 'inserted'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'id';
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

try {
	// Handle POST requests
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		verify_csrf();
		
		// ADD USER
		if (isset($_POST['add_user'])) {
			$username = trim($_POST['username'] ?? '');
			$password = trim($_POST['password'] ?? '');
			$confirm_password = trim($_POST['confirm_password'] ?? '');
			$level = filter_input(INPUT_POST, 'level', FILTER_VALIDATE_INT);
			
			if (empty($username)) {
				$error = "Username is required.";
			} elseif (strlen($username) < 3) {
				$error = "Username must be at least 3 characters.";
			} elseif (empty($password)) {
				$error = "Password is required.";
			} elseif (strlen($password) < 6) {
				$error = "Password must be at least 6 characters.";
			} elseif ($password !== $confirm_password) {
				$error = "Passwords do not match.";
			} elseif (!in_array($level, [1, 2])) {
				$error = "Invalid user level.";
			} else {
				// Check if username already exists
				$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
				$stmt->execute([$username]);
				
				if ($stmt->fetch()) {
					$error = "Username already exists.";
				} else {
					$hashed_password = password_hash($password, PASSWORD_BCRYPT);
					$stmt = $pdo->prepare("INSERT INTO users (username, password, level) VALUES (?, ?, ?)");
					$stmt->execute([$username, $hashed_password, $level]);
					
					$new_user_id = $pdo->lastInsertId();
					log_action($_SESSION['user_id'], 'add_user', $_SERVER['REMOTE_ADDR'], "Added user ID: $new_user_id ($username)");
					
					$success = "User added successfully.";
				}
			}
		}
		
		// EDIT USER
		elseif (isset($_POST['edit_user'])) {
			$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
			$username = trim($_POST['username'] ?? '');
			$level = filter_input(INPUT_POST, 'level', FILTER_VALIDATE_INT);
			$change_password = !empty($_POST['new_password']);
			$new_password = trim($_POST['new_password'] ?? '');
			$confirm_password = trim($_POST['confirm_new_password'] ?? '');
			
			if (!$id) {
				$error = "Invalid user ID.";
			} elseif (empty($username)) {
				$error = "Username is required.";
			} elseif (strlen($username) < 3) {
				$error = "Username must be at least 3 characters.";
			} elseif (!in_array($level, [1, 2])) {
				$error = "Invalid user level.";
			} elseif ($change_password && strlen($new_password) < 6) {
				$error = "New password must be at least 6 characters.";
			} elseif ($change_password && $new_password !== $confirm_password) {
				$error = "Passwords do not match.";
			} else {
				// Check if username is taken by another user
				$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
				$stmt->execute([$username, $id]);
				
				if ($stmt->fetch()) {
					$error = "Username already exists.";
				} else {
					if ($change_password) {
						$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
						$stmt = $pdo->prepare("UPDATE users SET username = ?, level = ?, password = ? WHERE id = ?");
						$stmt->execute([$username, $level, $hashed_password, $id]);
						log_action($_SESSION['user_id'], 'edit_user', $_SERVER['REMOTE_ADDR'], "Edited user ID: $id ($username) - password changed");
					} else {
						$stmt = $pdo->prepare("UPDATE users SET username = ?, level = ? WHERE id = ?");
						$stmt->execute([$username, $level, $id]);
						log_action($_SESSION['user_id'], 'edit_user', $_SERVER['REMOTE_ADDR'], "Edited user ID: $id ($username)");
					}
					
					$success = "User updated successfully.";
				}
			}
		}
		
		// DELETE USER
		elseif (isset($_POST['delete_user'])) {
			$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
			
			if (!$id) {
				$error = "Invalid user ID.";
			} elseif ($id == $_SESSION['user_id']) {
				$error = "You cannot delete your own account.";
			} else {
				// Get username before deleting
				$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
				$stmt->execute([$id]);
				$user = $stmt->fetch();
				
				if ($user) {
					$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
					$stmt->execute([$id]);
					
					log_action($_SESSION['user_id'], 'delete_user', $_SERVER['REMOTE_ADDR'], "Deleted user ID: $id ({$user['username']})");
					$success = "User deleted successfully.";
				} else {
					$error = "User not found.";
				}
			}
		}
	}
	
	// Fetch users
	$where = "WHERE 1=1";
	$params = [];
	
	if ($search) {
		$where .= " AND (username LIKE ? OR name LIKE ? OR fname LIKE ? OR email LIKE ?)";
		$params[] = "%$search%";
		$params[] = "%$search%";
		$params[] = "%$search%";
		$params[] = "%$search%";
	}
	
	$sql = "SELECT id, username, level, name, fname, email, inserted FROM users $where ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
	$stmt = $pdo->prepare($sql);
	
	// Bind search parameter
	$param_index = 1;
	foreach ($params as $param) {
		$stmt->bindValue($param_index++, $param, PDO::PARAM_STR);
	}
	
	// Bind pagination parameters
	$stmt->bindValue($param_index++, $items_per_page, PDO::PARAM_INT);
	$stmt->bindValue($param_index++, $offset, PDO::PARAM_INT);
	$stmt->execute();
	$users = $stmt->fetchAll();
	
	// Count total users
	$count_sql = "SELECT COUNT(*) FROM users $where";
	$stmt = $pdo->prepare($count_sql);
	$stmt->execute($params);
	$total_users = $stmt->fetchColumn();
	$total_pages = ceil($total_users / $items_per_page);
	
} catch (PDOException $e) {
	$error = "Database error occurred.";
	error_log("Manage users error: " . $e->getMessage());
}

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
$next_sort_order_username = ($sort_by === 'username' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
$next_sort_order_level = ($sort_by === 'level' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
$next_sort_order_inserted = ($sort_by === 'inserted' && $sort_order === 'ASC') ? 'DESC' : 'ASC';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Manage Users - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container mt-4">
		<h1><i class="bi bi-people"></i> Manage Users</h1>
		
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
		
		<!-- Search and Add User Form -->
		<form method="GET" class="mb-3">
			<div class="row g-3">
				<div class="col-12 col-md-auto">
					<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addUserModal">
						<i class="bi bi-plus-circle"></i> Add User
					</button>
				</div>
				<div class="col-12 col-md">
					<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
						   class="form-control" placeholder="Search by username">
				</div>
				<div class="col-12 col-md-auto">
					<button type="submit" class="btn btn-primary w-100">
						<i class="bi bi-search"></i> Search
					</button>
				</div>
				<div class="col-12 col-md-auto">
					<a href="manage_users.php" class="btn btn-secondary w-100">
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
			<ul class="pagination pagination-sm flex-wrap">
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
		
		<!-- Users Table -->
		<div class="table-responsive">
			<table class="table table-striped table-hover">
				<thead class="table-dark">
					<tr>
						<th style="width: 80px">
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=id&sort_order=<?php echo $next_sort_order_id; ?>&page=1">
								ID <?php echo $sort_by === 'id' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th>
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=username&sort_order=<?php echo $next_sort_order_username; ?>&page=1">
								Username <?php echo $sort_by === 'username' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th style="width: 150px">
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=level&sort_order=<?php echo $next_sort_order_level; ?>&page=1">
								Level <?php echo $sort_by === 'level' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th style="width: 180px">
							<a class="text-white text-decoration-none" href="?search=<?php echo urlencode($search); ?>&sort_by=inserted&sort_order=<?php echo $next_sort_order_inserted; ?>&page=1">
								Created <?php echo $sort_by === 'inserted' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?>
							</a>
						</th>
						<th style="width: 150px">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($users)) { ?>
						<tr>
							<td colspan="5" class="text-center text-muted">
								<i class="bi bi-inbox"></i> No users found
							</td>
						</tr>
					<?php } else { ?>
						<?php foreach ($users as $user) { ?>
						<tr>
							<td><?php echo $user['id']; ?></td>
							<td>
								<strong><?php echo htmlspecialchars($user['username']); ?></strong>
								<?php if ($user['id'] == $_SESSION['user_id']) { ?>
									<span class="badge bg-info">You</span>
								<?php } ?>
							</td>
							<td>
								<?php if ($user['level'] == 1) { ?>
									<span class="badge bg-danger">
										<i class="bi bi-shield-check"></i> Admin
									</span>
								<?php } else { ?>
									<span class="badge bg-primary">
										<i class="bi bi-person"></i> User
									</span>
								<?php } ?>
							</td>
							<td>
								<small class="text-muted">
									<?php echo date('d.m.Y H:i', strtotime($user['inserted'])); ?>
								</small>
							</td>
							<td>
								<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal"
										data-id="<?php echo $user['id']; ?>"
										data-username="<?php echo htmlspecialchars($user['username']); ?>"
										data-level="<?php echo $user['level']; ?>"
										title="Edit User">
									<i class="bi bi-pencil"></i>
								</button>
								<?php if ($user['id'] != $_SESSION['user_id']) { ?>
								<form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
									<?php echo csrf_field(); ?>
									<input type="hidden" name="delete_user" value="1">
									<input type="hidden" name="id" value="<?php echo $user['id']; ?>">
									<button type="submit" class="btn btn-sm btn-danger" title="Delete User">
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

	<!-- Add User Modal -->
	<div class="modal fade" id="addUserModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header bg-success text-white">
					<h5 class="modal-title">
						<i class="bi bi-plus-circle"></i> Add New User
					</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="add_user" value="1">
						
						<div class="mb-3">
							<label class="form-label">Username <span class="text-danger">*</span></label>
							<input type="text" name="username" class="form-control" required minlength="3" maxlength="50">
							<small class="form-text text-muted">Minimum 3 characters</small>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Password <span class="text-danger">*</span></label>
							<input type="password" name="password" class="form-control" required minlength="6">
							<small class="form-text text-muted">Minimum 6 characters</small>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Confirm Password <span class="text-danger">*</span></label>
							<input type="password" name="confirm_password" class="form-control" required minlength="6">
						</div>
						
						<div class="mb-3">
							<label class="form-label">User Level <span class="text-danger">*</span></label>
							<select name="level" class="form-control" required>
								<option value="">Select Level</option>
								<option value="2">User (Standard Access)</option>
								<option value="1">Admin (Full Access)</option>
							</select>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<i class="bi bi-x-circle"></i> Cancel
						</button>
						<button type="submit" class="btn btn-success">
							<i class="bi bi-save"></i> Add User
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Edit User Modal -->
	<div class="modal fade" id="editUserModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header bg-primary text-white">
					<h5 class="modal-title">
						<i class="bi bi-pencil"></i> Edit User
					</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<form method="POST">
					<?php echo csrf_field(); ?>
					<div class="modal-body">
						<input type="hidden" name="edit_user" value="1">
						<input type="hidden" name="id" id="edit-user-id">
						
						<div class="mb-3">
							<label class="form-label">Username <span class="text-danger">*</span></label>
							<input type="text" name="username" id="edit-user-username" class="form-control" required minlength="3" maxlength="50">
							<small class="form-text text-muted">Minimum 3 characters</small>
						</div>
						
						<div class="mb-3">
							<label class="form-label">User Level <span class="text-danger">*</span></label>
							<select name="level" id="edit-user-level" class="form-control" required>
								<option value="2">User (Standard Access)</option>
								<option value="1">Admin (Full Access)</option>
							</select>
						</div>
						
						<hr>
						
						<div class="mb-3">
							<div class="form-check form-switch">
								<input type="checkbox" class="form-check-input" id="change-password-toggle">
								<label class="form-check-label" for="change-password-toggle">
									<strong>Change Password</strong>
								</label>
							</div>
						</div>
						
						<div id="password-fields" style="display: none;">
							<div class="mb-3">
								<label class="form-label">New Password</label>
								<input type="password" name="new_password" id="edit-user-password" class="form-control" minlength="6">
								<small class="form-text text-muted">Minimum 6 characters</small>
							</div>
							
							<div class="mb-3">
								<label class="form-label">Confirm New Password</label>
								<input type="password" name="confirm_new_password" id="edit-user-confirm-password" class="form-control" minlength="6">
							</div>
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

	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Auto-dismiss alerts
		setTimeout(function() {
			$('.alert').fadeOut('slow');
		}, 5000);
		
		// Handle Edit User Modal
		$('#editUserModal').on('show.bs.modal', function(event) {
			const button = $(event.relatedTarget);
			const modal = $(this);
			
			modal.find('#edit-user-id').val(button.data('id'));
			modal.find('#edit-user-username').val(button.data('username'));
			modal.find('#edit-user-level').val(button.data('level'));
			
			// Reset password fields
			$('#change-password-toggle').prop('checked', false);
			$('#password-fields').hide();
			$('#edit-user-password').val('').prop('required', false);
			$('#edit-user-confirm-password').val('').prop('required', false);
		});
		
		// Toggle password fields
		$('#change-password-toggle').on('change', function() {
			if ($(this).is(':checked')) {
				$('#password-fields').slideDown();
				$('#edit-user-password').prop('required', true);
				$('#edit-user-confirm-password').prop('required', true);
			} else {
				$('#password-fields').slideUp();
				$('#edit-user-password').val('').prop('required', false);
				$('#edit-user-confirm-password').val('').prop('required', false);
			}
		});
		
		// Clear add user form when modal is closed
		$('#addUserModal').on('hidden.bs.modal', function() {
			$(this).find('form')[0].reset();
		});
	});
	</script>
<?php include 'footer.php'; ?>	
</body>
</html>