<?php
// login.php - Improved version with rate limiting and CSRF protection
require_once 'bootstrap.php';
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	verify_csrf();

	$username = trim($_POST['username'] ?? '');
	$password = trim($_POST['password'] ?? '');
	$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
			   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

	$response = ['success' => false, 'error' => ''];

	// Input validation
	if (empty($username) || empty($password)) {
		$response['error'] = 'Username and password are required.';
	}
	else {
		try {
			// Check rate limiting (with try-catch to avoid errors if table doesn't exist)
			try {
				if (check_login_attempts($username, $ip)) {
					$response['error'] = 'Too many failed login attempts. Please try again in 15 minutes.';
					error_log("Login rate limit exceeded for username: $username, IP: $ip");
					// Don't continue to login check
					goto skip_login;
				}
			} catch (Exception $e) {
				// Rate limiting table might not exist, continue anyway
				error_log("Rate limiting check failed: " . $e->getMessage());
			}

			$stmt = $pdo->prepare("SELECT id, password, level FROM users WHERE username = ?");
			$stmt->execute([$username]);
			$user = $stmt->fetch();

			if ($user && password_verify($password, $user['password'])) {
				// Successful login
				// Store session data BEFORE regenerating ID
				$_SESSION['user_id'] = $user['id'];
				$_SESSION['username'] = $username;
				$_SESSION['level'] = $user['level'];
				$_SESSION['created'] = time();

				// Regenerate session ID for security (use false to keep session data)
				session_regenerate_id(false);

				// Try to log the action (non-critical)
				try {
					log_action($user['id'], 'login', $ip);
				} catch (Exception $e) {
					error_log("Failed to log action: " . $e->getMessage());
				}

				// Try to log login attempt (non-critical)
				try {
					log_login_attempt($username, $user['id'], $ip, true);
				} catch (Exception $e) {
					error_log("Failed to log login attempt: " . $e->getMessage());
				}

				$response['success'] = true;
				$response['redirect'] = filter_input(INPUT_GET, 'redirect', FILTER_SANITIZE_URL) ?? 'index.php';

				// Prevent open redirect
				if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?&=]+$/', $response['redirect'])) {
					$response['redirect'] = 'index.php';
				}
			} else {
				// Failed login
				$response['error'] = 'Invalid username or password.';

				// Try to log failed attempt (non-critical)
				try {
					log_login_attempt($username, $user['id'] ?? null, $ip, false);
				} catch (Exception $e) {
					error_log("Failed to log failed attempt: " . $e->getMessage());
				}

				error_log("Failed login attempt for username: $username from IP: $ip");
			}

			skip_login:

		} catch (PDOException $e) {
			$response['error'] = 'An error occurred. Please try again later.';
			error_log("Login database error: " . $e->getMessage());
		} catch (Exception $e) {
			$response['error'] = 'An error occurred. Please try again later.';
			error_log("Login error: " . $e->getMessage());
		}
	}

	if ($is_ajax) {
		header('Content-Type: application/json');
		echo json_encode($response);
		exit;
	} else {
		if ($response['success']) {
			header('Location: ' . $response['redirect']);
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
	<title>Login</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<!-- Login Form -->
	<div class="container mt-5">
		<div class="row justify-content-center">
			<div class="col-md-6">
				<div class="card">
					<div class="card-header bg-primary text-white">
						<h3 class="mb-0"><i class="bi bi-lock"></i> Login</h3>
					</div>
					<div class="card-body">
						<?php if (isset($error)) { ?>
							<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
						<?php } ?>
						<form method="POST">
							<?php echo csrf_field(); ?>
							<div class="mb-3">
								<label for="username" class="form-label">Username</label>
								<input type="text" class="form-control" id="username" name="username" required autofocus>
							</div>
							<div class="mb-3">
								<label for="password" class="form-label">Password</label>
								<input type="password" class="form-control" id="password" name="password" required>
							</div>
							<button type="submit" class="btn btn-primary w-100">
								<i class="bi bi-box-arrow-in-right"></i> Login
							</button>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>