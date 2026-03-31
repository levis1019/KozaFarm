<?php
require 'db.php';

// --- 1. SMART DATABASE UPDATER: Dodavanje Sirutke i Zaliha ---
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

// Dodajemo kolonu za sirutku u milk_usage ako ne postoji
$checkWhey = $conn->query("SHOW COLUMNS FROM milk_usage LIKE 'whey_liters'");
if ($checkWhey->num_rows == 0) {
    $conn->query("ALTER TABLE milk_usage ADD COLUMN whey_liters DECIMAL(8,2) DEFAULT 0");
}

// Tablica zaliha gotovih proizvoda (Sir, Sirutka)
$conn->query("CREATE TABLE IF NOT EXISTS dairy_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(50) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 0,
    unit VARCHAR(20) NOT NULL
)");

// Inicijalizacija Sira i Sirutke ako je tablica prazna
if($conn->query("SELECT id FROM dairy_inventory WHERE item_name='Sir'")->num_rows == 0) {
    $conn->query("INSERT INTO dairy_inventory (item_name, quantity, unit) VALUES ('Sir', 0, 'kg')");
}
if($conn->query("SELECT id FROM dairy_inventory WHERE item_name='Sirutka'")->num_rows == 0) {
    $conn->query("INSERT INTO dairy_inventory (item_name, quantity, unit) VALUES ('Sirutka', 0, 'L')");
}

// Tablica transakcija gotovih proizvoda
$conn->query("CREATE TABLE IF NOT EXISTS dairy_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(50) NOT NULL,
    transaction_date DATE NOT NULL,
    type ENUM('in', 'out', 'adjustment') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$success_msg = ""; $error_msg = "";

$goats_list_query = $conn->query("SELECT id, name FROM goats WHERE status = 'Mlijecna' ORDER BY id ASC");
$mlijecne_koze = [];
while($g = $goats_list_query->fetch_assoc()) {
    $mlijecne_koze[] = $g;
}
$ukupno_mlijecnih = count($mlijecne_koze);

// --- OBRADA FORMI ---

// 1. UNOS MUŽNJE (ULAZ)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_milk'])) {
    $date = $conn->real_escape_string($_POST['log_date']);
    $morning = !empty($_POST['morning_liters']) ? (float)$_POST['morning_liters'] : 0;
    $evening = !empty($_POST['evening_liters']) ? (float)$_POST['evening_liters'] : 0;
    $total = $morning + $evening;
    $milking_type = $_POST['milking_type'];

    if ($milking_type === 'single') {
        $goat_id = !empty($_POST['goat_id']) ? (int)$_POST['goat_id'] : "NULL";
        $notes = $conn->real_escape_string($_POST['notes']);
    } else {
        $goat_id = "NULL";
        $excluded = isset($_POST['excluded_goats']) ? $_POST['excluded_goats'] : [];
        $milked_count = $ukupno_mlijecnih - count($excluded);
        
        $bulk_notes = "Grupno ($milked_count/$ukupno_mlijecnih koza).";
        if (count($excluded) > 0) {
            $ex_ids = array_map(function($val) { return "#" . explode('-', $val)[0]; }, $excluded);
            $bulk_notes .= " Preskočeno: " . implode(", ", $ex_ids) . ".";
        }
        $user_notes = trim($_POST['notes']);
        if ($user_notes) $bulk_notes .= " | " . $user_notes;
        $notes = $conn->real_escape_string($bulk_notes);
    }

    $sql = "INSERT INTO milk_logs (goat_id, log_date, morning_liters, evening_liters, notes) 
            VALUES ($goat_id, '$date', $morning, $evening, '$notes')";
    if ($conn->query($sql)) {
        logAction($conn, "Upisana mužnja: +$total L ($date)");
        $success_msg = "Mužnja uspješno zabilježena!";
    } else { $error_msg = "Greška: " . $conn->error; }
}

// 2. UNIVERZALNA PRODAJA (Mlijeko, Sir, Sirutka)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_sale'])) {
    $product = $_POST['product_type']; // 'mlijeko', 'sir', 'sirutka'
    $date = $conn->real_escape_string($_POST['usage_date']);
    $qty = (float)$_POST['quantity'];
    $price = (float)$_POST['price_total'];
    $payment = $conn->real_escape_string($_POST['payment_type']);
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : "NULL";
    $notes = $conn->real_escape_string($_POST['notes']);

    $sale_success = false;

    if ($product === 'mlijeko') {
        $sql = "INSERT INTO milk_usage (usage_date, usage_type, liters, price_total, payment_type, customer_id, notes) 
                VALUES ('$date', 'sale', $qty, $price, '$payment', $customer_id, '$notes')";
        if ($conn->query($sql)) $sale_success = true;
        $product_name = "Mlijeko";
        $unit = "L";
    } else {
        $item_name = ($product === 'sir') ? 'Sir' : 'Sirutka';
        $product_name = $item_name;
        $unit = ($product === 'sir') ? 'kg' : 'L';
        
        $conn->query("UPDATE dairy_inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name = '$item_name'");
        $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('$item_name', '$date', 'out', $qty, 'Prodaja: $notes')");
        $sale_success = true;
    }

    if ($sale_success) {
        if ($payment == 'cash') {
            $desc = "Prodaja: $qty $unit $product_name" . ($notes ? " ($notes)" : "");
            $conn->query("INSERT INTO finances (transaction_date, category, description, transaction_type, amount, payment_method) 
                          VALUES ('$date', 'Prodaja Mlijeka / Sira', '$desc', 'income', $price, 'Gotovina')");
        } elseif ($payment == 'debt' && $customer_id !== "NULL") {
            $desc = "Dug: Prodano $qty $unit $product_name" . ($notes ? " ($notes)" : "");
            $conn->query("INSERT INTO customer_ledger (customer_id, transaction_date, transaction_type, amount, description) 
                          VALUES ($customer_id, '$date', 'debt', $price, '$desc')");
        }
        $success_msg = "Prodaja ($product_name) uspješno zabilježena i knjižena!";
        logAction($conn, "Prodano $qty $unit $product_name za $price BAM.");
    }
}

// 3. PROIZVODNJA SIRA I SIRUTKE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_cheese'])) {
    $date = $conn->real_escape_string($_POST['usage_date']);
    $liters = (float)$_POST['liters'];
    $cheese_kg = (float)$_POST['cheese_kg'];
    $whey_liters = (float)$_POST['whey_liters'];
    $notes = $conn->real_escape_string($_POST['notes']);

    $sql = "INSERT INTO milk_usage (usage_date, usage_type, liters, cheese_kg, whey_liters, notes) 
            VALUES ('$date', 'cheese', $liters, $cheese_kg, $whey_liters, '$notes')";
    if ($conn->query($sql)) {
        // Dodaj na zalihe Sira
        $conn->query("UPDATE dairy_inventory SET quantity = quantity + $cheese_kg WHERE item_name = 'Sir'");
        $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sir', '$date', 'in', $cheese_kg, 'Proizvodnja')");
        
        // Dodaj na zalihe Sirutke
        if ($whey_liters > 0) {
            $conn->query("UPDATE dairy_inventory SET quantity = quantity + $whey_liters WHERE item_name = 'Sirutka'");
            $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sirutka', '$date', 'in', $whey_liters, 'Proizvodnja sira')");
        }
        
        $success_msg = "Proizvodnja Sira i Sirutke uspješno zabilježena!";
        logAction($conn, "Proizvodnja: Od $liters L mlijeka napravljeno $cheese_kg kg sira i $whey_liters L sirutke.");
    } else { $error_msg = "Greška: " . $conn->error; }
}

// 4. OTPIS (IZLAZ) ZA MLIJEKO, SIR I SIRUTKU
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_waste'])) {
    $date = $conn->real_escape_string($_POST['usage_date']);
    $product = $_POST['waste_product']; // 'mlijeko', 'sir', 'sirutka'
    $qty = (float)$_POST['quantity'];
    $reason = $conn->real_escape_string($_POST['waste_reason']);
    $notes = $conn->real_escape_string($_POST['notes']);

    if ($product === 'mlijeko') {
        $sql = "INSERT INTO milk_usage (usage_date, usage_type, liters, waste_reason, notes) 
                VALUES ('$date', 'waste', $qty, '$reason', '$notes')";
        if ($conn->query($sql)) {
            $success_msg = "Otpis mlijeka zabilježen!";
            logAction($conn, "Otpisano $qty L mlijeka (Razlog: $reason).");
        }
    } else {
        $item_name = ($product === 'sir') ? 'Sir' : 'Sirutka';
        $unit = ($product === 'sir') ? 'kg' : 'L';
        
        // Smanji zalihe
        $conn->query("UPDATE dairy_inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name = '$item_name'");
        
        // Zabilježi u transakcije
        $full_note = "Otpis: $reason" . ($notes ? " | " . $notes : "");
        $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) 
                      VALUES ('$item_name', '$date', 'out', $qty, '$full_note')");
        
        $success_msg = "Otpis proizvoda ($item_name) zabilježen!";
        logAction($conn, "Otpisano $qty $unit $item_name (Razlog: $reason).");
    }
}

// 5. INVENTURA MLIJEKA I SIRA (KOREKCIJA)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['do_inventory'])) {
    // Mlijeko logika
    $actual_milk = (float)$_POST['inv_milk'];
    $prod_t = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs")->fetch_assoc()['t'] ?? 0;
    $usage_t = $conn->query("SELECT SUM(liters) as t FROM milk_usage")->fetch_assoc()['t'] ?? 0;
    $current_milk = (float)$prod_t - (float)$usage_t;
    $diff_milk = $actual_milk - $current_milk;

    if (abs($diff_milk) > 0.01) {
        if ($diff_milk < 0) {
            // Fali mlijeka -> Otpis
            $waste = abs($diff_milk);
            $conn->query("INSERT INTO milk_usage (usage_date, usage_type, liters, waste_reason, notes) VALUES (CURDATE(), 'waste', $waste, 'Korekcija Inventure', 'Inventura')");
        } else {
            // Višak mlijeka -> Upis kao extra log
            $conn->query("INSERT INTO milk_logs (log_date, morning_liters, evening_liters, notes) VALUES (CURDATE(), $diff_milk, 0, 'Korekcija Inventure')");
        }
    }

    // Sir logika
    $actual_cheese = (float)$_POST['inv_cheese'];
    $cur_cheese = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sir'")->fetch_assoc()['quantity'] ?? 0;
    $diff_cheese = $actual_cheese - $cur_cheese;
    if (abs($diff_cheese) > 0.01) {
        $conn->query("UPDATE dairy_inventory SET quantity = $actual_cheese WHERE item_name = 'Sir'");
        $type = $diff_cheese > 0 ? 'in' : 'out';
        $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sir', CURDATE(), '$type', ABS($diff_cheese), 'Korekcija Inventure')");
    }

    // Sirutka logika
    $actual_whey = (float)$_POST['inv_whey'];
    $cur_whey = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sirutka'")->fetch_assoc()['quantity'] ?? 0;
    $diff_whey = $actual_whey - $cur_whey;
    if (abs($diff_whey) > 0.01) {
        $conn->query("UPDATE dairy_inventory SET quantity = $actual_whey WHERE item_name = 'Sirutka'");
        $type = $diff_whey > 0 ? 'in' : 'out';
        $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sirutka', CURDATE(), '$type', ABS($diff_whey), 'Korekcija Inventure')");
    }

    $success_msg = "Inventura uspješno provedena, sve zalihe su automatski korigirane!";
    logAction($conn, "Ažurirana inventura mliječnih proizvoda (Mlijeko: $actual_milk L, Sir: $actual_cheese kg, Sirutka: $actual_whey L).");
}


// --- DOHVAĆANJE STATISTIKE ZALIHA ---
$prod_total = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs")->fetch_assoc()['t'] ?? 0;
$usage_total = $conn->query("SELECT SUM(liters) as t FROM milk_usage")->fetch_assoc()['t'] ?? 0;
$current_inventory = (float)$prod_total - (float)$usage_total;

$inv_sir = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sir'")->fetch_assoc()['quantity'] ?? 0;
$inv_sirutka = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sirutka'")->fetch_assoc()['quantity'] ?? 0;

$cheese_stats = $conn->query("SELECT SUM(liters) as tot_l, SUM(cheese_kg) as tot_kg FROM milk_usage WHERE usage_type = 'cheese'")->fetch_assoc();
$randman = ($cheese_stats['tot_kg'] > 0) ? round($cheese_stats['tot_l'] / $cheese_stats['tot_kg'], 2) : 0;

$waste_q = $conn->query("SELECT waste_reason, SUM(liters) as t FROM milk_usage WHERE usage_type = 'waste' GROUP BY waste_reason ORDER BY t DESC");
$waste_data = []; $total_waste = 0;
while($w = $waste_q->fetch_assoc()) { 
    $waste_data[$w['waste_reason']] = $w['t']; 
    $total_waste += $w['t']; 
}

$customers_list = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");

$history_q = $conn->query("
    (SELECT id, log_date as t_date, 'in' as direction, 'Mužnja' as action, (morning_liters + evening_liters) as qty, 'L' as unit, notes 
     FROM milk_logs)
    UNION ALL
    (SELECT id, usage_date as t_date, 'out' as direction, 
            CASE usage_type WHEN 'sale' THEN 'Prodaja Mlijeka' WHEN 'cheese' THEN 'Proizvodnja Sira' ELSE 'Otpis Mlijeka' END as action, 
            liters as qty, 'L' as unit, notes 
     FROM milk_usage)
    UNION ALL
    (SELECT id, transaction_date as t_date, type as direction, 
            CASE WHEN item_name='Sir' AND type='in' THEN 'Unos Sira' WHEN item_name='Sir' AND type='out' THEN 'Izlaz Sira' WHEN item_name='Sirutka' AND type='in' THEN 'Unos Sirutke' ELSE 'Izlaz Sirutke' END as action, 
            quantity as qty, CASE WHEN item_name='Sir' THEN 'kg' ELSE 'L' END as unit, notes
     FROM dairy_transactions)
    ORDER BY t_date DESC, id DESC LIMIT 40
");

include 'header.php';
?>

<style>
    .inventory-hero { background: linear-gradient(135deg, #1e293b 0%, #0f111a 100%); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 30px; text-align: center; margin-bottom: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative; overflow: hidden; }
    .inv-glow { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 200px; height: 200px; background: rgba(59, 130, 246, 0.15); border-radius: 50%; filter: blur(50px); z-index: 0; }
    .inv-content { position: relative; z-index: 1; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px;}
    .inv-block { text-align: center; }
    .inv-label { font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; font-weight: bold; }
    .inv-amount { font-size: 46px; font-weight: 900; color: #f8fafc; line-height: 1; }
    .inv-amount span { font-size: 20px; color: var(--accent-info); }
    
    .action-tabs { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none; }
    .action-tabs::-webkit-scrollbar { display: none; }
    .at-btn { flex: 1; min-width: 130px; padding: 15px 10px; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-secondary); font-weight: bold; font-size: 13px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 8px; transition: 0.2s; }
    .at-btn i { font-size: 20px; }
    .at-btn.active-in { background: rgba(16, 185, 129, 0.1); border-color: #10b981; color: #10b981; }
    .at-btn.active-out-sale { background: rgba(59, 130, 246, 0.1); border-color: #3b82f6; color: #3b82f6; }
    .at-btn.active-out-cheese { background: rgba(245, 158, 11, 0.1); border-color: #f59e0b; color: #f59e0b; }
    .at-btn.active-out-waste { background: rgba(239, 68, 68, 0.1); border-color: #ef4444; color: #ef4444; }
    .at-btn.active-inv { background: rgba(167, 139, 250, 0.1); border-color: #a78bfa; color: #a78bfa; }
    
    .form-panel { display: none; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); margin-bottom: 30px; animation: fadeIn 0.3s; }
    .form-panel.active { display: block; }
    .input-lg { font-size: 18px !important; padding: 15px !important; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
    
    .exclude-list-container { display: none; margin-top: 15px; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: rgba(0,0,0,0.2); }
    .exclude-scroll { max-height: 250px; overflow-y: auto; padding: 10px; }
    .exclude-item { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px dashed rgba(255,255,255,0.05); cursor: pointer; color: var(--text-primary); font-size: 16px; border-radius: 8px; transition: 0.2s; }
    .exclude-item:hover { background: rgba(255,255,255,0.05); }
    .exclude-item input[type="checkbox"] { transform: scale(1.5); margin-right: 15px; cursor: pointer; accent-color: #ef4444; }
    .exclude-item.checked { background: rgba(239, 68, 68, 0.1); border-color: transparent; }
    
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .a-card { background: var(--bg-surface-hover); border-radius: var(--radius-lg); padding: 20px; border: 1px solid var(--border-color); }
    .a-card h4 { color: var(--text-muted); margin: 0 0 15px 0; font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .waste-item { margin-bottom: 12px; }
    .w-label { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-secondary); margin-bottom: 4px; }
    .w-bar-bg { width: 100%; height: 8px; background: rgba(255,255,255,0.05); border-radius: 4px; overflow: hidden; }
    .w-bar-fill { height: 100%; background: #ef4444; border-radius: 4px; }
    
    .hist-list { list-style: none; padding: 0; margin: 0; }
    .hist-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); }
    .h-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; margin-right: 12px; flex-shrink: 0;}
    .h-in { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .h-out { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-prescription-bottle" style="color: var(--accent-info); margin-right: 10px;"></i> Proizvodi i Zalihe</h1>
        <a href="milk_calendar.php" class="btn btn-secondary"><i class="fas fa-chart-bar"></i> Analitika i Kalendar</a>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success);'><p style='color:var(--accent-success); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger);'><p style='color:var(--accent-danger); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="inventory-hero">
        <div class="inv-glow"></div>
        <div class="inv-content">
            <div class="inv-block">
                <div class="inv-label"><i class="fas fa-snowflake"></i> Mlijeko</div>
                <div class="inv-amount"><?php echo number_format($current_inventory, 1); ?> <span>L</span></div>
            </div>
            <div class="inv-block">
                <div class="inv-label"><i class="fas fa-cheese"></i> Sir</div>
                <div class="inv-amount" style="color: #f59e0b;"><?php echo number_format($inv_sir, 2); ?> <span>KG</span></div>
            </div>
            <div class="inv-block">
                <div class="inv-label"><i class="fas fa-glass-whiskey"></i> Sirutka</div>
                <div class="inv-amount" style="color: #a78bfa;"><?php echo number_format($inv_sirutka, 1); ?> <span>L</span></div>
            </div>
        </div>
    </div>

    <div class="action-tabs">
        <button class="at-btn active-in" onclick="openForm('milk', this)"><i class="fas fa-plus-circle"></i> Dodaj Mužnju</button>
        <button class="at-btn" onclick="openForm('sale', this)"><i class="fas fa-shopping-cart"></i> Prodaja M/S</button>
        <button class="at-btn" onclick="openForm('cheese', this)"><i class="fas fa-cheese"></i> Pravljenje Sira</button>
        <button class="at-btn" onclick="openForm('waste', this)"><i class="fas fa-trash-alt"></i> Otpis / Bačeno</button>
        <button class="at-btn" onclick="openForm('inventory', this)"><i class="fas fa-clipboard-check"></i> Inventura</button>
    </div>

    <div id="form-milk" class="form-panel active" style="border-top: 4px solid #10b981;">
        <h3 style="color: #10b981; margin-bottom: 20px;"><i class="fas fa-plus"></i> Evidencija Mužnje (Ulaz)</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Način Unosa</label>
                    <select name="milking_type" id="milking_type" class="input-lg" onchange="toggleMilkingType()">
                        <option value="bulk">Grupna mužnja (Cijelo stado)</option>
                        <option value="single">Pojedinačna koza</option>
                    </select>
                </div>

                <div id="bulk_options" class="form-group" style="grid-column: 1 / -1; background: rgba(59, 130, 246, 0.05); padding: 15px; border-radius: var(--radius-md); border: 1px dashed var(--accent-info);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <strong style="color: white; font-size: 18px;">Muze se: <span id="milking_count" style="color: var(--accent-info);"><?php echo $ukupno_mlijecnih; ?></span> / <?php echo $ukupno_mlijecnih; ?> koza</strong>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="toggleExcludeList()" style="border-color: var(--accent-danger); color: var(--accent-danger);">
                            <i class="fas fa-minus-circle"></i> Oduzmi preskočene koze
                        </button>
                    </div>
                    <div id="exclude_list_container" class="exclude-list-container">
                        <div style="padding: 10px 15px; border-bottom: 1px solid rgba(255,255,255,0.1); font-size: 13px; color: var(--text-muted);">
                            Označite koze koje DANAS NISU muzene:
                        </div>
                        <div class="exclude-scroll">
                            <?php foreach($mlijecne_koze as $g): ?>
                                <label class="exclude-item" id="lbl-<?php echo $g['id']; ?>">
                                    <input type="checkbox" name="excluded_goats[]" value="<?php echo $g['id'].'-'.$g['name']; ?>" onclick="updateMilkedCount(this, 'lbl-<?php echo $g['id']; ?>')">
                                    #<?php echo $g['id'] . ' ' . htmlspecialchars($g['name'] ? $g['name'] : 'Bez imena'); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div id="single_options" class="form-group" style="display: none; grid-column: 1 / -1;">
                    <label>Odaberi Kozu</label>
                    <select name="goat_id" class="input-lg">
                        <option value="">-- Odaberi --</option>
                        <?php foreach($mlijecne_koze as $g) echo "<option value='{$g['id']}'>#{$g['id']} - {$g['name']}</option>"; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Datum</label>
                    <input type="date" name="log_date" required value="<?php echo date('Y-m-d'); ?>" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Napomena</label>
                    <input type="text" name="notes" placeholder="Opcionalno..." style="padding: 15px; font-size: 16px;">
                </div>

                <div class="form-group">
                    <label>Jutarnja Mužnja (Litara)</label>
                    <input type="number" step="0.1" min="0" name="morning_liters" placeholder="0.0" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Večernja Mužnja (Litara)</label>
                    <input type="number" step="0.1" min="0" name="evening_liters" placeholder="0.0" class="input-lg">
                </div>
            </div>
            <input type="hidden" name="add_milk" value="1">
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #10b981; padding: 15px; font-size: 18px; margin-top: 15px; font-weight: bold;"><i class="fas fa-save"></i> Spremi Mlijeko u Zalihe</button>
        </form>
    </div>

    <div id="form-sale" class="form-panel" style="border-top: 4px solid #3b82f6;">
        <h3 style="color: #3b82f6; margin-bottom: 20px;"><i class="fas fa-shopping-cart"></i> Prodaja Proizvoda (Izlaz)</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Što prodajete? <span style="color:var(--accent-danger);">*</span></label>
                    <select name="product_type" required class="input-lg" onchange="updateSaleUnit(this.value)">
                        <option value="mlijeko">Mlijeko (Zaliha: <?php echo number_format($current_inventory,1); ?> L)</option>
                        <option value="sir">Sir (Zaliha: <?php echo number_format($inv_sir,2); ?> KG)</option>
                        <option value="sirutka">Sirutka (Zaliha: <?php echo number_format($inv_sirutka,1); ?> L)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Količina za prodaju (<span id="sale_unit">L</span>) <span style="color:var(--accent-danger);">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="0.0" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Ukupna Cijena (BAM) <span style="color:var(--accent-danger);">*</span></label>
                    <input type="number" step="0.1" min="0.1" name="price_total" required placeholder="0.00" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Datum Prodaje</label>
                    <input type="date" name="usage_date" required value="<?php echo date('Y-m-d'); ?>" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Način Plaćanja</label>
                    <select name="payment_type" id="payment_type" class="input-lg" onchange="toggleCustomerSelect()">
                        <option value="cash">Gotovina (Ide u Financije)</option>
                        <option value="debt">Na Dug (Ide u Knjigu Kupaca)</option>
                    </select>
                </div>
                <div class="form-group" id="customer_select_group" style="display: none; grid-column: 1 / -1;">
                    <label>Odaberi Kupca (Za dug)</label>
                    <select name="customer_id" class="input-lg">
                        <option value="">-- Odaberi kupca --</option>
                        <?php while($c = $customers_list->fetch_assoc()) echo "<option value='{$c['id']}'>{$c['name']}</option>"; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Napomena</label>
                    <input type="text" name="notes" placeholder="npr. Ime kupca, na tržnici..." style="padding: 12px;">
                </div>
            </div>
            <input type="hidden" name="add_sale" value="1">
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #3b82f6; padding: 15px; font-size: 16px; margin-top: 15px;"><i class="fas fa-hand-holding-usd"></i> Zabilježi Prodaju</button>
        </form>
    </div>

    <div id="form-cheese" class="form-panel" style="border-top: 4px solid #f59e0b;">
        <h3 style="color: #f59e0b; margin-bottom: 20px;"><i class="fas fa-cheese"></i> Proizvodnja Sira i Sirutke</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>Utrošeno Mlijeko (L) <span style="color:var(--accent-danger);">*</span></label>
                    <input type="number" step="0.1" min="0.1" name="liters" required placeholder="0.0" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Dobiveno Sira (KG) <span style="color:var(--accent-danger);">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="cheese_kg" required placeholder="0.00" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Dobiveno Sirutke (L) <span style="color:var(--text-muted);">(Opcionalno)</span></label>
                    <input type="number" step="0.1" min="0" name="whey_liters" value="0" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Datum Proizvodnje</label>
                    <input type="date" name="usage_date" required value="<?php echo date('Y-m-d'); ?>" class="input-lg">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Napomena / Vrsta sira</label>
                    <input type="text" name="notes" placeholder="npr. Tvrdi sir, dimljeni..." style="padding: 12px;">
                </div>
            </div>
            <input type="hidden" name="add_cheese" value="1">
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #f59e0b; padding: 15px; font-size: 16px; margin-top: 15px;"><i class="fas fa-cube"></i> Spremi Proizvode na Zalihe</button>
        </form>
    </div>

    <div id="form-waste" class="form-panel" style="border-top: 4px solid #ef4444;">
        <h3 style="color: #ef4444; margin-bottom: 20px;"><i class="fas fa-trash-alt"></i> Otpis (Bačeno/Gubitak/Kvar)</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Što otpisujete? <span style="color:var(--accent-danger);">*</span></label>
                    <select name="waste_product" required class="input-lg" onchange="updateWasteUnit(this.value)">
                        <option value="mlijeko">Mlijeko</option>
                        <option value="sir">Sir</option>
                        <option value="sirutka">Sirutka</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Količina za otpis (<span id="waste_unit">L</span>) <span style="color:var(--accent-danger);">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="0.0" class="input-lg">
                </div>
                <div class="form-group">
                    <label>Razlog Otpisa <span style="color:var(--accent-danger);">*</span></label>
                    <select name="waste_reason" required class="input-lg">
                        <option value="Hranjenje Jarića/Životinja">Hranjenje Jarića/Životinja</option>
                        <option value="Karenca / Antibiotik">Karenca / Antibiotik</option>
                        <option value="Kolostrum">Kolostrum</option>
                        <option value="Pokvareno / Kvar">Pokvareno / Kvar</option>
                        <option value="Prosuto / Fizičko oštećenje">Prosuto / Fizičko oštećenje</option>
                        <option value="Ostalo">Ostalo</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Datum Otpisa</label>
                    <input type="date" name="usage_date" required value="<?php echo date('Y-m-d'); ?>" class="input-lg">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Dodatna Napomena</label>
                    <input type="text" name="notes" placeholder="Detalji..." style="padding: 12px;">
                </div>
            </div>
            <input type="hidden" name="add_waste" value="1">
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #ef4444; padding: 15px; font-size: 16px; margin-top: 15px;"><i class="fas fa-ban"></i> Otpiši Proizvod</button>
        </form>
    </div>

    <div id="form-inventory" class="form-panel" style="border-top: 4px solid #a78bfa;">
        <h3 style="color: #a78bfa; margin-bottom: 20px;"><i class="fas fa-clipboard-check"></i> Inventura (Korekcija Zaliha)</h3>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">Upišite STVARNO stanje koje trenutno imate. Aplikacija će sama izračunati razliku i stvoriti zapis 'Korekcija Inventure' u knjigama.</p>
        
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>Stvarno stanje Mlijeka (L)</label>
                    <input type="number" step="0.1" min="0" name="inv_milk" value="<?php echo round($current_inventory, 1); ?>" required class="input-lg" style="border-color:#3b82f6;">
                </div>
                <div class="form-group">
                    <label>Stvarno stanje Sira (KG)</label>
                    <input type="number" step="0.01" min="0" name="inv_cheese" value="<?php echo round($inv_sir, 2); ?>" required class="input-lg" style="border-color:#f59e0b;">
                </div>
                <div class="form-group">
                    <label>Stvarno stanje Sirutke (L)</label>
                    <input type="number" step="0.1" min="0" name="inv_whey" value="<?php echo round($inv_sirutka, 1); ?>" required class="input-lg" style="border-color:#a78bfa;">
                </div>
            </div>
            <input type="hidden" name="do_inventory" value="1">
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #a78bfa; padding: 15px; font-size: 16px; margin-top: 15px; font-weight: bold;"><i class="fas fa-sync-alt"></i> Izvrši Usklađivanje</button>
        </form>
    </div>

    <div class="analytics-grid">
        <div class="a-card">
            <h4><i class="fas fa-balance-scale"></i> Prosječni Randman Sira</h4>
            <div style="font-size: 32px; font-weight: bold; color: #f59e0b; margin-top: 10px;">
                <?php echo $randman; ?> <span style="font-size: 16px; color: var(--text-muted);">L / 1 kg</span>
            </div>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">Koliko Vam je litara mlijeka u prosjeku potrebno za dobivanje 1 kg sira (Manje = Bolje).</p>
        </div>

        <div class="a-card">
            <h4><i class="fas fa-chart-pie"></i> Analiza Otpisa Mlijeka</h4>
            <div style="margin-top: 15px;">
                <?php if($total_waste > 0): foreach($waste_data as $reason => $amt): $pct = round(($amt / $total_waste) * 100); ?>
                    <div class="waste-item">
                        <div class="w-label"><span><?php echo $reason; ?></span> <strong><?php echo number_format($amt, 1); ?> L (<?php echo $pct; ?>%)</strong></div>
                        <div class="w-bar-bg"><div class="w-bar-fill" style="width: <?php echo $pct; ?>%;"></div></div>
                    </div>
                <?php endforeach; else: ?>
                    <p style="color: var(--text-muted); font-size: 13px;">Još nema otpisanog mlijeka.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="max-height: 500px; overflow-y: auto;">
        <h3 style="margin-bottom: 15px; color: var(--text-secondary);"><i class="fas fa-history"></i> Knjiga Transakcija (Zadnjih 40)</h3>
        <?php if($history_q->num_rows > 0): ?>
            <ul class="hist-list">
                <?php while($h = $history_q->fetch_assoc()): 
                    $isIn = $h['direction'] == 'in';
                    $iconClass = $isIn ? 'h-in' : 'h-out';
                    $faIcon = $isIn ? 'fa-arrow-down' : 'fa-arrow-up';
                    $sign = $isIn ? '+' : '-';
                ?>
                    <li class="hist-item">
                        <div style="display: flex; align-items: center;">
                            <div class="h-icon <?php echo $iconClass; ?>"><i class="fas <?php echo $faIcon; ?>"></i></div>
                            <div>
                                <strong style="color: var(--text-primary); font-size: 15px;"><?php echo $h['action']; ?></strong>
                                <div style="font-size: 12px; color: var(--text-muted);"><i class="fas fa-calendar-alt"></i> <?php echo date("d.m.Y", strtotime($h['t_date'])); ?></div>
                                <?php if($h['notes']): ?><div style="font-size: 12px; color: var(--text-secondary); margin-top: 3px;"><?php echo htmlspecialchars($h['notes']); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 18px; font-weight: bold; padding-left: 15px;" class="<?php echo $isIn ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $sign . number_format($h['qty'], 2) . " " . $h['unit']; ?>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: 20px;">Knjiga je prazna.</p>
        <?php endif; ?>
    </div>
</main>

<script>
    const ukupnoMlijecnih = <?php echo $ukupno_mlijecnih; ?>;

    function updateMilkedCount(checkbox, labelId) {
        const checkedCount = document.querySelectorAll('input[name="excluded_goats[]"]:checked').length;
        document.getElementById('milking_count').innerText = ukupnoMlijecnih - checkedCount;
        
        const label = document.getElementById(labelId);
        if(checkbox.checked) {
            label.classList.add('checked');
        } else {
            label.classList.remove('checked');
        }
    }

    function toggleMilkingType() {
        const type = document.getElementById('milking_type').value;
        if(type === 'bulk') {
            document.getElementById('bulk_options').style.display = 'block';
            document.getElementById('single_options').style.display = 'none';
        } else {
            document.getElementById('bulk_options').style.display = 'none';
            document.getElementById('single_options').style.display = 'block';
        }
    }

    function toggleExcludeList() {
        const el = document.getElementById('exclude_list_container');
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }

    function openForm(formId, btn) {
        document.querySelectorAll('.form-panel').forEach(f => f.classList.remove('active'));
        document.querySelectorAll('.at-btn').forEach(b => {
            b.classList.remove('active-in', 'active-out-sale', 'active-out-cheese', 'active-out-waste', 'active-inv');
        });
        document.getElementById('form-' + formId).classList.add('active');
        
        if(formId === 'milk') btn.classList.add('active-in');
        else if(formId === 'sale') btn.classList.add('active-out-sale');
        else if(formId === 'cheese') btn.classList.add('active-out-cheese');
        else if(formId === 'waste') btn.classList.add('active-out-waste');
        else if(formId === 'inventory') btn.classList.add('active-inv');
    }

    function toggleCustomerSelect() {
        const type = document.getElementById('payment_type').value;
        const group = document.getElementById('customer_select_group');
        if (type === 'debt') {
            group.style.display = 'block';
            group.querySelector('select').required = true;
        } else {
            group.style.display = 'none';
            group.querySelector('select').required = false;
        }
    }

    function updateSaleUnit(val) {
        document.getElementById('sale_unit').innerText = (val === 'sir') ? 'KG' : 'L';
    }

    function updateWasteUnit(val) {
        document.getElementById('waste_unit').innerText = (val === 'sir') ? 'KG' : 'L';
    }
</script>

<?php include 'footer.php'; ?>
