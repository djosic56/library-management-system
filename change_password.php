<?php
// change_password.php - Improved version with better validation
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	verify_csrf();
	
	$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
			   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
	$response = ['success' => false, 'error' => ''];

	$current_password = trim($_POST['current_password'] ?? '');
	$new_password = trim($_POST['new_password'] ?? '');
	$confirm_password = trim($_POST['confirm_password'] ?? '');
	$user_id = $_SESSION['user_id'];

	try {
		// Verify current password
		$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
		$stmt->execute([$user_id]);
		$user = $stmt->fetch();
		
		if (!$user || !password_verify($current_password, $user['password'])) {
			$response['error'] = 'Current password is incorrect.';
		} 
		elseif (strlen($new_password) < 8) {
			$response['error'] = 'New password must be at least 8 characters.';
		} 
		elseif ($new_password !== $confirm_password) {
			$response['error'] = 'New password and confirmation do not match.';
		}
		// Check password complexity
		elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
			$response['error'] = 'Password must contain at least one uppercase letter, one lowercase letter, and one number.';
		}
		// Check if new password is same as current
		elseif (password_verify($new_password, $user['password'])) {
			$response['error'] = 'New password must be different from current password.';
		}
		else {
			// Update password
			$hashed_password = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
			$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
			$stmt->execute([$hashed_password, $user_id]);
			
			$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
			log_action($user_id, 'change_password', $ip);
			
			$response['success'] = true;
			$response['message'] = 'Password changed successfully.';
		}
	} catch (PDOException $e) {
		$response['error'] = 'Database error occurred. Please try again.';
		error_log("Change password error: " . $e->getMessage());
	}

	if ($is_ajax) {
		header('Content-Type: application/json');
		echo json_encode($response);
		exit;
	} else {
		if ($response['success']) {
			$_SESSION['password_changed'] = true;
			header('Location: index.php');
			exit;
		} else {
			$error = $response['error'];
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Change Password - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<?php include 'header.php'; ?>
	
	<div class="container mt-5">
		<div class="row justify-content-center">
			<div class="col-md-6">
				<div class="card">
					<div class="card-header bg-primary text-white">
						<h4 class="mb-0">
							<i class="bi bi-key"></i> Change Password
						</h4>
					</div>
					<div class="card-body">
						<?php if (isset($error)) { ?>
							<div class="alert alert-danger">
								<i class="bi bi-exclamation-triangle"></i>
								<?php echo htmlspecialchars($error); ?>
							</div>
						<?php } ?>
						
						<div class="alert alert-info">
							<strong>Password Requirements:</strong>
							<ul class="mb-0 mt-2">
								<li>At least 8 characters long</li>
								<li>Contains at least one uppercase letter</li>
								<li>Contains at least one lowercase letter</li>
								<li>Contains at least one number</li>
							</ul>
						</div>
						
						<form method="POST">
							<?php echo csrf_field(); ?>
							<div class="mb-3">
								<label for="current_password" class="form-label">Current Password</label>
								<input type="password" class="form-control" id="current_password" 
									   name="current_password" required autocomplete="current-password">
							</div>
							<div class="mb-3">
								<label for="new_password" class="form-label">New Password</label>
								<input type="password" class="form-control" id="new_password" 
									   name="new_password" required minlength="8" autocomplete="new-password">
								<div class="form-text">Minimum 8 characters</div>
							</div>
							<div class="mb-3">
								<label for="confirm_password" class="form-label">Confirm New Password</label>
								<input type="password" class="form-control" id="confirm_password" 
									   name="confirm_password" required autocomplete="new-password">
							</div>
							<div class="d-grid gap-2">
								<button type="submit" class="btn btn-primary">
									<i class="bi bi-check-circle"></i> Change Password
								</button>
								<a href="index.php" class="btn btn-secondary">
									<i class="bi bi-x-circle"></i> Cancel
								</a>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
	
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	$(document).ready(function() {
		// Real-time password validation
		$('#new_password').on('input', function() {
			var password = $(this).val();
			var hasUpper = /[A-Z]/.test(password);
			var hasLower = /[a-z]/.test(password);
			var hasNumber = /[0-9]/.test(password);
			var isLongEnough = password.length >= 8;
			
			if (isLongEnough && hasUpper && hasLower && hasNumber) {
				$(this).removeClass('is-invalid').addClass('is-valid');
			} else {
				$(this).removeClass('is-valid').addClass('is-invalid');
			}
		});
		
		// Check if passwords match
		$('#confirm_password').on('input', function() {
			if ($(this).val() === $('#new_password').val()) {
				$(this).removeClass('is-invalid').addClass('is-valid');
			} else {
				$(this).removeClass('is-valid').addClass('is-invalid');
			}
		});
	});
	</script>
<?php include 'footer.php'; ?>
</body>
</html>