<?php
// export_books_csv.php - Export filtered books to CSV
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

// Get filter parameters
$search_title = filter_input(INPUT_GET, 'search_title', FILTER_SANITIZE_STRING) ?? '';
$search_author = filter_input(INPUT_GET, 'search_author', FILTER_SANITIZE_STRING) ?? '';
$filter_status = filter_input(INPUT_GET, 'filter_status', FILTER_SANITIZE_STRING) ?? '';
$filter_invoice = filter_input(INPUT_GET, 'filter_invoice', FILTER_SANITIZE_STRING) ?? '';

try {
	// Build query with filters (same as books.php but without pagination)
	$params = [];
	$sql = "SELECT 
				b.id,
				b.title,
				b.pages,
				b.date_start,
				b.date_finish,
				s.name as status_name,
				f.shortname as format_shortname,
				b.invoice,
				b.note,
				b.inserted,
				b.changed,
				GROUP_CONCAT(CONCAT(a.fname, ' ', a.name) SEPARATOR ', ') as authors
			FROM book b
			LEFT JOIN status s ON b.id_status = s.id
			LEFT JOIN formating f ON b.id_formating = f.id
			LEFT JOIN book_author ba ON b.id = ba.id_book
			LEFT JOIN author a ON ba.id_author = a.id AND a.deleted_at IS NULL
			WHERE b.deleted_at IS NULL";
	
	if ($search_title) {
		$sql .= " AND b.title LIKE ?";
		$params[] = "%$search_title%";
	}
	
	if ($search_author) {
		$sql .= " AND (a.fname LIKE ? OR a.name LIKE ?)";
		$params[] = "%$search_author%";
		$params[] = "%$search_author%";
	}
	
	if ($filter_status !== '') {
		$sql .= " AND b.id_status = ?";
		$params[] = (int)$filter_status;
	}
	
	if ($filter_invoice !== '') {
		$sql .= " AND b.invoice = ?";
		$params[] = (int)$filter_invoice;
	}
	
	$sql .= " GROUP BY b.id ORDER BY b.id DESC";
	
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$books = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	// Generate filename with timestamp
	$filename = 'books_export_' . date('Y-m-d_H-i-s') . '.csv';
	
	// Set headers for CSV download
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Pragma: no-cache');
	header('Expires: 0');
	
	// Create file pointer connected to output stream
	$output = fopen('php://output', 'w');
	
	// Add BOM for proper UTF-8 handling in Excel
	fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
	
	// Output column headers
	fputcsv($output, [
		'ID',
		'Title',
		'Authors',
		'Pages',
		'Start Date',
		'Finish Date',
		'Status',
		'Format',
		'Invoice',
		'Note',
		'Date Added',
		'Last Modified'
	]);
	
	// Output data rows
	foreach ($books as $book) {
		fputcsv($output, [
			$book['id'],
			$book['title'],
			$book['authors'] ?? '',
			$book['pages'] ?? '',
			$book['date_start'] ?? '',
			$book['date_finish'] ?? '',
			$book['status_name'] ?? '',
			$book['format_shortname'] ?? '',
			$book['invoice'] ? 'Yes' : 'No',
			$book['note'] ?? '',
			$book['inserted'] ?? '',
			$book['changed'] ?? ''
		]);
	}
	
	fclose($output);
	
	// Log export action
	$filter_desc = [];
	if ($search_title) $filter_desc[] = "title:$search_title";
	if ($search_author) $filter_desc[] = "author:$search_author";
	if ($filter_status) $filter_desc[] = "status:$filter_status";
	if ($filter_invoice !== '') $filter_desc[] = "invoice:$filter_invoice";
	
	$details = count($books) . ' books exported';
	if (!empty($filter_desc)) {
		$details .= ' (filters: ' . implode(', ', $filter_desc) . ')';
	}
	
	log_action($_SESSION['user_id'], 'export_csv', $_SERVER['REMOTE_ADDR'], $details);
	
	exit;
	
} catch (PDOException $e) {
	error_log("CSV Export error: " . $e->getMessage());
	die("Error exporting data. Please try again.");
}
?>