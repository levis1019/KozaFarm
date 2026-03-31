<?php
require 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    $log_query = $conn->query("SELECT goat_id, live_weight FROM weight_logs WHERE id=$id");
    if($log_query && $log_query->num_rows > 0) {
        $log_data = $log_query->fetch_assoc();
        logAction($conn, "Obrisano vaganje (ID: $id) za kozu #{$log_data['goat_id']} ({$log_data['live_weight']} kg)");
    }

    $stmt = $conn->prepare("DELETE FROM weight_logs WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    
    $stmt->close();
    exit();
} else {
    echo json_encode(['success' => false, 'error' => 'Nevažeći zahtjev.']);
    exit();
}
?>
