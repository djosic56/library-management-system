<?php
require_once 'functions.php';
require_login();

$search_title = $_GET['search_title'] ?? '';
$search_author = $_GET['search_author'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_invoice = $_GET['filter_invoice'] ?? '';

$books = get_books($search_title, $search_author, $filter_status, $filter_invoice);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="books_export.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Title', 'Authors', 'Pages', 'Start Date', 'Finish Date', 'Status', 'Format', 'Invoice', 'Note', 'Inserted', 'Changed']);

foreach ($books as $book) {
	fputcsv($output, [
		$book['id'],
		$book['title'],
		$book['authors'] ?? 'None',
		$book['pages'],
		$book['date_start'],
		$book['date_finish'],
		$book['status_name'],
		$book['format_name'],
		$book['invoice'] ? 'Yes' : 'No',
		$book['note'],
		$book['inserted'],
		$book['changed']
	]);
}
fclose($output);
log_action($_SESSION['user_id'], 'export_books');
exit;
?>