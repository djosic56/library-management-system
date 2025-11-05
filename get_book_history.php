<?php
// get_book_history.php - AJAX endpoint to get book history
require_once 'bootstrap.php';
require_once 'functions.php';
require_login();

header('Content-Type: application/json');

$book_id = filter_input(INPUT_GET, 'book_id', FILTER_VALIDATE_INT);

if (!$book_id) {
	echo json_encode(['success' => false, 'error' => 'Invalid book ID']);
	exit;
}

try {
	$stmt = $pdo->prepare("SELECT h.id, h.inserted, s.name as status_name, s.note
						   FROM history h
						   JOIN status s ON h.id_status = s.id
						   WHERE h.id_book = ?
						   ORDER BY h.inserted DESC");
	$stmt->execute([$book_id]);
	$history = $stmt->fetchAll();
	
	echo json_encode([
		'success' => true,
		'history' => $history
	]);
} catch (PDOException $e) {
	error_log("Get book history error: " . $e->getMessage());
	echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>