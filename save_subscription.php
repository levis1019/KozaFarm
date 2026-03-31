<?php
// save_subscription.php
require 'db.php';

// Get the JSON data sent from the mobile browser
$data = json_decode(file_get_contents('php://input'), true);

// Make sure we have exactly what we need
if (!$data || !isset($data['endpoint']) || !isset($data['p256dh']) || !isset($data['auth'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid data format']));
}

$endpoint = $conn->real_escape_string($data['endpoint']);
$p256dh = $conn->real_escape_string($data['p256dh']);
$auth = $conn->real_escape_string($data['auth']);

// Ensure the table exists (Smart Updater)
$conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Check if this endpoint is already subscribed
$stmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
$stmt->bind_param("s", $endpoint);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    // Save new subscription
    $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (?, ?, ?)");
    $insertStmt->bind_param("sss", $endpoint, $p256dh, $auth);
    
    if ($insertStmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subscription saved!']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
} else {
    // If the endpoint exists, update the keys just in case the browser refreshed them
    $updateStmt = $conn->prepare("UPDATE push_subscriptions SET p256dh = ?, auth = ? WHERE endpoint = ?");
    $updateStmt->bind_param("sss", $p256dh, $auth, $endpoint);
    $updateStmt->execute();
    
    echo json_encode(['status' => 'success', 'message' => 'Subscription updated/verified']);
}
?>
