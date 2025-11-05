<?php
// backup.php - Complete fixed version
require_once 'bootstrap.php';
require_once 'functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	verify_csrf();
	
	try {
		// Get all table names
		$stmt = $pdo->query("SHOW TABLES");
		$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$output = '';
		$output .= "-- jsistem_ap Database Backup\n";
		$output .= "-- Generated on " . date('Y-m-d H:i:s') . "\n";
		$output .= "-- Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
		$output .= "-- Database: " . DB_NAME . "\n\n";

		$output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
		$output .= "START TRANSACTION;\n";
		$output .= "SET time_zone = \"+00:00\";\n\n";
		$output .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
		$output .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
		$output .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
		$output .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

		foreach ($tables as $table) {
			$output .= "--\n";
			$output .= "-- Table structure for table `$table`\n";
			$output .= "--\n\n";

			$output .= "DROP TABLE IF EXISTS `$table`;\n";
			$stmt = $pdo->prepare("SHOW CREATE TABLE `$table`");
			$stmt->execute();
			$create_table = $stmt->fetch();
			$output .= $create_table['Create Table'] . ";\n\n";

			$output .= "--\n";
			$output .= "-- Dumping data for table `$table`\n";
			$output .= "--\n\n";

			$stmt = $pdo->prepare("SELECT * FROM `$table`");
			$stmt->execute();
			$rows = $stmt->fetchAll();

			if (!empty($rows)) {
				foreach ($rows as $row) {
					$output .= "INSERT INTO `$table` (";
					$columns = array_keys($row);
					$output .= implode(', ', array_map(function($col) { 
						return "`$col`"; 
					}, $columns)) . ") VALUES (";
					
					$values = array_values($row);
					$escaped_values = array_map(function($val) use ($pdo) {
						if ($val === null) return 'NULL';
						if (is_numeric($val)) return $val;
						return $pdo->quote($val);
					}, $values);
					
					$output .= implode(', ', $escaped_values) . ");\n";
				}
				$output .= "\n";
			}
		}

		$output .= "COMMIT;\n\n";
		$output .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
		$output .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
		$output .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";

		$filename = 'jsistem_ap_backup_' . date('Y-m-d_H-i-s') . '.sql';
		
		// Log backup action
		log_action($_SESSION['user_id'], 'database_backup', $_SERVER['REMOTE_ADDR'], "Backup file: $filename");
		
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($output));
		header('Pragma: no-cache');
		header('Expires: 0');
		echo $output;
		exit;
	} catch (PDOException $e) {
		$error = "Backup failed. Please try again.";
		error_log("Backup error: " . $e->getMessage());
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Database Backup - Library System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="css/style.css">
	<style>
		.backup-card {
			max-width: 600px;
			margin: 50px auto;
		}
		.backup-icon {
			font-size: 5rem;
			color: #667eea;
		}
	</style>
</head>
<body>
	<?php include 'header.php'; ?>
	<div class="container">
		<div class="backup-card">
			<div class="card shadow">
				<div class="card-body text-center p-5">
					<i class="bi bi-database-down backup-icon mb-4"></i>
					<h1 class="mb-4">Database Backup</h1>
					
					<?php if (isset($error)) { ?>
						<div class="alert alert-danger">
							<i class="bi bi-exclamation-triangle"></i>
							<?php echo htmlspecialchars($error); ?>
						</div>
					<?php } ?>
					
					<p class="text-muted mb-4">
						Click the button below to download a complete SQL backup of the 
						<strong><?php echo DB_NAME; ?></strong> database.
					</p>
					
					<div class="alert alert-info text-start">
						<h6><i class="bi bi-info-circle"></i> Backup Information:</h6>
						<ul class="mb-0">
							<li>Includes all tables and data</li>
							<li>Compatible with MySQL/MariaDB</li>
							<li>File format: SQL dump</li>
							<li>Timestamp included in filename</li>
						</ul>
					</div>
					
					<form method="POST" style="display: inline;">
						<?php echo csrf_field(); ?>
						<button type="submit" class="btn btn-primary btn-lg">
							<i class="bi bi-download"></i> Download Backup
						</button>
					</form>
					
					<div class="mt-4">
						<a href="index.php" class="btn btn-secondary">
							<i class="bi bi-arrow-left"></i> Back to Home
						</a>
					</div>
					
					<p class="text-muted mt-4 small">
						<i class="bi bi-shield-check"></i>
						The backup will be downloaded as an SQL file with the current timestamp.
					</p>
				</div>
			</div>
		</div>
	</div>
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>	
</body>
</html>