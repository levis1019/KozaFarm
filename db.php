<?php
// Pokreni sesiju za Login sustav
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Load .env variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remove quotes if present
        if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match("/^'(.*)'$/", $value, $matches)) {
            $value = $matches[1];
        }
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'koza_farm';

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("<h3 style='color:red;'>Baza nije spojena! Greška: " . $e->getMessage() . "</h3>");
}

// ==========================================
// 1. SMART DATABASE UPDATER (Centralizirano)
// ==========================================

// A) Kreiraj tablicu za korisnike
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    username VARCHAR(50) UNIQUE, 
    password VARCHAR(255)
)");

// B) Dodaj kolonu za pamćenje prijave (Remember Me)
$checkToken = $conn->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
if ($checkToken->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL");
}

// C) Dodaj kolonu za uloge (Admin vs Worker)
$checkRole = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($checkRole->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN role ENUM('admin', 'worker') DEFAULT 'admin'");
}

// D) Dozvoli "Cijelo Stado" (NULL ID) u mužnji
$checkMilk = $conn->query("SHOW COLUMNS FROM milk_logs LIKE 'goat_id'");
if ($checkMilk->num_rows > 0) {
    $conn->query("ALTER TABLE milk_logs MODIFY goat_id INT NULL");
}

// E) Karenca (Withdrawal Days) u Događajima
$checkKarenca = $conn->query("SHOW COLUMNS FROM events LIKE 'withdrawal_days'");
if ($checkKarenca->num_rows == 0) {
    $conn->query("ALTER TABLE events ADD COLUMN withdrawal_days INT DEFAULT 0");
}

// F) Bilješke za Mlijeko (Za Multi-Goat mužnju)
$checkMilkNotes = $conn->query("SHOW COLUMNS FROM milk_logs LIKE 'notes'");
if ($checkMilkNotes->num_rows == 0) {
    $conn->query("ALTER TABLE milk_logs ADD COLUMN notes TEXT");
}

// G) Limit duga za Kupce
$checkDebtLimit = $conn->query("SHOW COLUMNS FROM customers LIKE 'debt_limit'");
if ($checkDebtLimit->num_rows == 0) {
    $conn->query("ALTER TABLE customers ADD COLUMN debt_limit DECIMAL(10,2) DEFAULT 0.00");
}

// H) Tablica za bilježenje aktivnosti (Audit Trail)
$conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)");

// I) Tablica za System To-Do listu (Website improvements)
$conn->query("CREATE TABLE IF NOT EXISTS system_todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// J) Ako nema korisnika, dodaj početne admine
$userCount = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
if ($userCount == 0) {
    $hash = password_hash('admin', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, role) VALUES ('levisabi', '$hash', 'admin'), ('Josip', '$hash', 'admin')");
}

// Globalna funkcija za logiranje aktivnosti
if (!function_exists('logAction')) {
    function logAction($conn, $action_text) {
        if(isset($_SESSION['user_id'])) {
            $uid = (int)$_SESSION['user_id'];
            $txt = $conn->real_escape_string($action_text);
            $conn->query("INSERT INTO activity_logs (user_id, action) VALUES ($uid, '$txt')");
        }
    }
}

// ==========================================
// 2. SECURITY & ACCESS CONTROL
// ==========================================

$currentFile = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['user_id']) && $currentFile != 'login.php') {
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'worker' && $currentFile != 'login.php' && $currentFile != 'logout.php') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete']) || isset($_GET['del'])) {
        die("
        <div style='background: #0f111a; color: white; padding: 40px; text-align: center; font-family: sans-serif; height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; box-sizing: border-box; margin: 0;'>
            <h1 style='color: #ef4444; font-size: 32px; margin-bottom: 15px;'>Pristup Odbijen</h1>
            <p style='font-size: 16px; color: #a1a1aa; margin-bottom: 30px; max-width: 400px; line-height: 1.5;'>Kao <strong>Radnik</strong>, imate pravo samo na pregled podataka. Ne možete dodavati, brisati ili mijenjati evidenciju na farmi.</p>
            <button onclick='window.history.back()' style='padding: 15px 30px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: bold; font-size: 16px; cursor: pointer;'>Nazad</button>
        </div>
        ");
    }
}
?>
