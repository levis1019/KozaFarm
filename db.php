<?php
// Pokreni sesiju za Login sustav
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = '127.0.0.1';
$user = 'koza';         
$pass = 'Farma123!';    
$db   = 'koza_farm';

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("<h3 style='color:red;'>Baza nije spojena! Greška: " . $e->getMessage() . "</h3>");
}

// ==========================================
// 1. SMART DATABASE UPDATER (Centralizirano)
// ==========================================

if (!isset($_SESSION['schema_updated'])) {
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

    $checkFlag = $conn->query("SHOW COLUMNS FROM goats LIKE 'flagged'");
    if ($checkFlag->num_rows == 0) {
        $conn->query("ALTER TABLE goats ADD COLUMN flagged TINYINT(1) DEFAULT 0");
    }

    $checkArchive = $conn->query("SHOW COLUMNS FROM goats LIKE 'archived'");
    if ($checkArchive->num_rows == 0) {
        $conn->query("ALTER TABLE goats ADD COLUMN archived TINYINT(1) DEFAULT 0");
    }

    $createWeightLogsTableSql = "CREATE TABLE IF NOT EXISTS weight_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        goat_id INT NOT NULL,
        log_date DATE NOT NULL,
        live_weight DECIMAL(6,2) NOT NULL,
        net_weight DECIMAL(6,2) DEFAULT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (goat_id) REFERENCES goats(id) ON DELETE CASCADE
    )";
    $conn->query($createWeightLogsTableSql);

    $conn->query("CREATE TABLE IF NOT EXISTS scratchpad (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $sp_check = $conn->query("SELECT id FROM scratchpad LIMIT 1");
    if($sp_check->num_rows == 0) $conn->query("INSERT INTO scratchpad (note) VALUES ('')");

    $conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $createTableSql = "CREATE TABLE IF NOT EXISTS finances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        category VARCHAR(100) NOT NULL,
        description TEXT,
        transaction_type ENUM('income', 'expense') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createTableSql);

    $checkVendor = $conn->query("SHOW COLUMNS FROM finances LIKE 'vendor'");
    if ($checkVendor->num_rows == 0) {
        $conn->query("ALTER TABLE finances ADD COLUMN vendor VARCHAR(100) DEFAULT NULL");
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

    $conn->query("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS item_catalog_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        package_price DECIMAL(10,2) NOT NULL,
        package_size DECIMAL(10,2) NOT NULL,
        unit VARCHAR(20) NOT NULL,
        price_per_unit DECIMAL(10,3) NOT NULL,
        last_updated DATE NOT NULL,
        notes VARCHAR(255)
    )");

    $checkCat = $conn->query("SHOW COLUMNS FROM item_catalog_prices LIKE 'category'");
    if ($checkCat && $checkCat->num_rows == 0) {
        $conn->query("ALTER TABLE item_catalog_prices ADD COLUMN category VARCHAR(50) DEFAULT 'Ostalo'");
    }

    $checkPhone = $conn->query("SHOW COLUMNS FROM customers LIKE 'phone'");
    if ($checkPhone->num_rows == 0) { $conn->query("ALTER TABLE customers ADD COLUMN phone VARCHAR(50) DEFAULT NULL"); }
    $checkAddress = $conn->query("SHOW COLUMNS FROM customers LIKE 'address'");
    if ($checkAddress->num_rows == 0) { $conn->query("ALTER TABLE customers ADD COLUMN address TEXT DEFAULT NULL"); }
    $checkNotes = $conn->query("SHOW COLUMNS FROM customers LIKE 'notes'");
    if ($checkNotes->num_rows == 0) { $conn->query("ALTER TABLE customers ADD COLUMN notes TEXT DEFAULT NULL"); }

    $checkPriority = $conn->query("SHOW COLUMNS FROM system_todos LIKE 'priority'");
    if ($checkPriority->num_rows == 0) {
        $conn->query("ALTER TABLE system_todos ADD COLUMN priority ENUM('low', 'medium', 'high') DEFAULT 'medium'");
    }

    $createUsageTable = "CREATE TABLE IF NOT EXISTS milk_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usage_date DATE NOT NULL,
        usage_type ENUM('sale', 'cheese', 'waste') NOT NULL,
        liters DECIMAL(8,2) NOT NULL,
        cheese_kg DECIMAL(8,2) DEFAULT NULL,
        waste_reason VARCHAR(100) DEFAULT NULL,
        customer_id INT DEFAULT NULL,
        payment_type ENUM('cash', 'debt') DEFAULT NULL,
        price_total DECIMAL(10,2) DEFAULT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createUsageTable);

    $checkWhey = $conn->query("SHOW COLUMNS FROM milk_usage LIKE 'whey_liters'");
    if ($checkWhey->num_rows == 0) {
        $conn->query("ALTER TABLE milk_usage ADD COLUMN whey_liters DECIMAL(8,2) DEFAULT 0");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS dairy_inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(50) NOT NULL,
        quantity DECIMAL(10,2) DEFAULT 0,
        unit VARCHAR(20) NOT NULL
    )");

    if($conn->query("SELECT id FROM dairy_inventory WHERE item_name='Sir'")->num_rows == 0) {
        $conn->query("INSERT INTO dairy_inventory (item_name, quantity, unit) VALUES ('Sir', 0, 'kg')");
    }
    if($conn->query("SELECT id FROM dairy_inventory WHERE item_name='Sirutka'")->num_rows == 0) {
        $conn->query("INSERT INTO dairy_inventory (item_name, quantity, unit) VALUES ('Sirutka', 0, 'L')");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS dairy_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(50) NOT NULL,
        transaction_date DATE NOT NULL,
        type ENUM('in', 'out', 'adjustment') NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS feed_inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category ENUM('forage', 'grain', 'supplement', 'mix') NOT NULL,
        unit ENUM('bale', 'kg', 'piece') NOT NULL,
        quantity_in_stock DECIMAL(10,2) DEFAULT 0,
        avg_price_per_unit DECIMAL(10,2) DEFAULT 0,
        min_stock_limit DECIMAL(10,2) DEFAULT 0,
        quick_feed_amount DECIMAL(10,2) DEFAULT 0
    )");

    $checkMinStock = $conn->query("SHOW COLUMNS FROM feed_inventory LIKE 'min_stock_limit'");
    if ($checkMinStock->num_rows == 0) {
        $conn->query("ALTER TABLE feed_inventory ADD COLUMN min_stock_limit DECIMAL(10,2) DEFAULT 0");
        $conn->query("ALTER TABLE feed_inventory ADD COLUMN quick_feed_amount DECIMAL(10,2) DEFAULT 0");
        $conn->query("ALTER TABLE feed_inventory MODIFY COLUMN category ENUM('forage', 'grain', 'supplement', 'mix') NOT NULL");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS feed_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        feed_id INT NOT NULL,
        transaction_date DATE NOT NULL,
        type ENUM('in', 'out', 'adjustment') NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        total_cost DECIMAL(10,2) DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (feed_id) REFERENCES feed_inventory(id) ON DELETE CASCADE
    )");

    $_SESSION['schema_updated'] = true;
}

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
