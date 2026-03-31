<?php
require 'db.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Greška: Kupac nije odabran. <a href='customers.php'>Nazad</a>");
}

$customer_id = (int)$_GET['id'];
$success_msg = ""; $error_msg = "";

// Dohvati trenutne zalihe za formu
$prod_total = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs")->fetch_assoc()['t'] ?? 0;
$usage_total = $conn->query("SELECT SUM(liters) as t FROM milk_usage")->fetch_assoc()['t'] ?? 0;
$inv_milk = max(0, (float)$prod_total - (float)$usage_total);
$inv_cheese = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sir'")->fetch_assoc()['quantity'] ?? 0;
$inv_whey = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sirutka'")->fetch_assoc()['quantity'] ?? 0;


// --- 1. OBRADA FORMI ---

// A) Uređivanje Kupca
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_customer'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $address = $conn->real_escape_string($_POST['address']);
    $notes = $conn->real_escape_string($_POST['notes']);
    
    $conn->query("UPDATE customers SET name='$name', phone='$phone', address='$address', notes='$notes' WHERE id=$customer_id");
    logAction($conn, "Ažurirani podaci kupca: $name");
    $success_msg = "Podaci kupca uspješno ažurirani!";
}

// B) Brisanje Transakcije (SMART RESTORE INVENTORY)
if (isset($_GET['delete_tx'])) {
    $del_id = (int)$_GET['delete_tx'];
    
    // Dohvati podatke prije brisanja kako bi znali što vratiti u zalihe
    $tx_query = $conn->query("SELECT * FROM customer_ledger WHERE id=$del_id AND customer_id=$customer_id");
    if ($tx_query->num_rows > 0) {
        $tx = $tx_query->fetch_assoc();
        $desc = $tx['description'];
        $type = $tx['transaction_type'];
        $date = $tx['transaction_date'];
        $amount = $tx['amount'];
        
        $restored_msg = "";

        // Samo zaduženja skidaju robu, pa samo nju i vraćamo
        if ($type == 'debt') {
            // Parsiraj iz opisa (npr. "Uzeo Sir: 5 kg") i vrati zalihe
            if (preg_match('/Uzeo Mlijeko:\s*([0-9.]+)\s*L/i', $desc, $matches)) {
                $qty = (float)$matches[1];
                // Obriši i iz milk_usage kako bi se mlijeko "vratilo" u zalihe
                $conn->query("DELETE FROM milk_usage WHERE customer_id=$customer_id AND usage_date='$date' AND liters=$qty AND price_total=$amount LIMIT 1");
                $restored_msg = " (Vraćeno $qty L mlijeka na zalihe)";
            } 
            elseif (preg_match('/Uzeo Sir:\s*([0-9.]+)\s*kg/i', $desc, $matches)) {
                $qty = (float)$matches[1];
                $conn->query("UPDATE dairy_inventory SET quantity = quantity + $qty WHERE item_name = 'Sir'");
                $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sir', CURDATE(), 'in', $qty, 'Storno duga kupca: Povrat')");
                $restored_msg = " (Vraćeno $qty kg sira na zalihe)";
            }
            elseif (preg_match('/Uzeo Sirutku:\s*([0-9.]+)\s*L/i', $desc, $matches)) {
                $qty = (float)$matches[1];
                $conn->query("UPDATE dairy_inventory SET quantity = quantity + $qty WHERE item_name = 'Sirutka'");
                $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) VALUES ('Sirutka', CURDATE(), 'in', $qty, 'Storno duga kupca: Povrat')");
                $restored_msg = " (Vraćeno $qty L sirutke na zalihe)";
            }
        }

        // Konačno, obriši zapis iz knjige
        $conn->query("DELETE FROM customer_ledger WHERE id=$del_id AND customer_id=$customer_id");
        logAction($conn, "Obrisana transakcija iz knjige kupca (ID: $del_id)." . $restored_msg);
    }
    
    header("Location: customer_ledger.php?id=$customer_id&success=deleted");
    exit();
}

// C) Uređivanje Transakcije (Inline Edit)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_tx'])) {
    $tx_id = (int)$_POST['tx_id'];
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $type = $conn->real_escape_string($_POST['transaction_type']);
    $amount = (float)$_POST['amount'];
    $description = $conn->real_escape_string($_POST['description']);

    $conn->query("UPDATE customer_ledger SET transaction_date='$date', transaction_type='$type', amount=$amount, description='$description' WHERE id=$tx_id");
    logAction($conn, "Uređena transakcija u knjizi kupca (ID: $tx_id).");
    $success_msg = "Transakcija uspješno izmijenjena!";
}

// D) Novi Zapis (Zaduženje uz ZALIHE ili Uplata)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_ledger_entry'])) {
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $type = $conn->real_escape_string($_POST['transaction_type']); 
    $amount = (float)$_POST['amount'];
    $user_desc = trim($conn->real_escape_string($_POST['description']));

    $c_name_query = $conn->query("SELECT name FROM customers WHERE id = $customer_id");
    $c_name = $c_name_query->fetch_assoc()['name'];

    if ($type == 'debt') {
        // Logika za ZADUŽENJE (Oduzimanje sa skladišta)
        $product = $_POST['product_type']; // 'mlijeko', 'sir', 'sirutka', 'ostalo'
        $qty = isset($_POST['quantity']) ? (float)$_POST['quantity'] : 0;
        
        $final_desc = "";
        
        if ($product === 'mlijeko' && $qty > 0) {
            $conn->query("INSERT INTO milk_usage (usage_date, usage_type, liters, price_total, payment_type, customer_id, notes) 
                          VALUES ('$date', 'sale', $qty, $amount, 'debt', $customer_id, 'Kupac: $c_name | $user_desc')");
            $final_desc = "Uzeo Mlijeko: $qty L";
        } 
        elseif ($product === 'sir' && $qty > 0) {
            $conn->query("UPDATE dairy_inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name = 'Sir'");
            $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) 
                          VALUES ('Sir', '$date', 'out', $qty, 'Dug kupca: $c_name')");
            $final_desc = "Uzeo Sir: $qty kg";
        } 
        elseif ($product === 'sirutka' && $qty > 0) {
            $conn->query("UPDATE dairy_inventory SET quantity = GREATEST(0, quantity - $qty) WHERE item_name = 'Sirutka'");
            $conn->query("INSERT INTO dairy_transactions (item_name, transaction_date, type, quantity, notes) 
                          VALUES ('Sirutka', '$date', 'out', $qty, 'Dug kupca: $c_name')");
            $final_desc = "Uzeo Sirutku: $qty L";
        } 
        else {
            $final_desc = "Zaduženje (Ostalo)";
        }
        
        if ($user_desc != "") { $final_desc .= " - " . $user_desc; }

        $sql = "INSERT INTO customer_ledger (customer_id, transaction_date, transaction_type, amount, description) 
                VALUES ($customer_id, '$date', 'debt', $amount, '$final_desc')";
                
        if ($conn->query($sql)) {
            logAction($conn, "Kupac $c_name zadužen za $amount BAM ($final_desc). Roba skinuta sa zaliha.");
            $success_msg = "Zaduženje uspješno zabilježeno i roba skinuta sa zaliha!";
        } else { $error_msg = "Greška: " . $conn->error; }

    } 
    elseif ($type == 'payment') {
        // Logika za UPLATU (Nema diranja zaliha, samo financije)
        $final_desc = "Uplata duga";
        if ($user_desc != "") { $final_desc .= " - " . $user_desc; }

        $sql = "INSERT INTO customer_ledger (customer_id, transaction_date, transaction_type, amount, description) 
                VALUES ($customer_id, '$date', 'payment', $amount, '$final_desc')";
                
        if ($conn->query($sql)) {
            $success_msg = "Uplata uspješno zabilježeno!";
            logAction($conn, "Kupac $c_name izvršio uplatu od $amount BAM.");
            
            if (isset($_POST['sync_finance']) && $_POST['sync_finance'] == '1') {
                $fin_desc = "Uplata duga (Kupac: " . $conn->real_escape_string($c_name) . ")" . ($user_desc ? " - " . $user_desc : "");
                $fin_sql = "INSERT INTO finances (transaction_date, category, description, transaction_type, amount, payment_method, vendor) 
                            VALUES ('$date', 'Prodaja Mlijeka / Sira', '$fin_desc', 'income', $amount, 'Gotovina', '" . $conn->real_escape_string($c_name) . "')";
                $conn->query($fin_sql);
                $success_msg .= " (Uplata je zabilježena i u Financije!)";
            }
        } else { $error_msg = "Greška: " . $conn->error; }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 'deleted') {
    $success_msg = "Transakcija je obrisana, a zalihe (ako ih je bilo) su automatski vraćene!";
}

// --- 2. DOHVAĆANJE PODATAKA ---
$c_query = $conn->query("SELECT * FROM customers WHERE id = $customer_id");
if ($c_query->num_rows == 0) { die("Kupac ne postoji."); }
$customer = $c_query->fetch_assoc();

$stats = $conn->query("
    SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'debt' THEN amount ELSE 0 END), 0) as total_debt,
        COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE 0 END), 0) as total_paid
    FROM customer_ledger WHERE customer_id = $customer_id
")->fetch_assoc();

$balance = $stats['total_debt'] - $stats['total_paid'];
if ($balance < 0) $balance = 0; 

$ledger = $conn->query("SELECT * FROM customer_ledger WHERE customer_id = $customer_id ORDER BY transaction_date DESC, id DESC");

include 'header.php';
?>

<style>
    /* MODALS */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 450px; position: relative; }
    
    /* TRANSACTIONS LIST */
    .transaction-list { list-style: none; padding: 0; margin: 0; }
    .transaction-list li { padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 15px; transition: background 0.2s; }
    .transaction-list li:hover { background-color: rgba(255,255,255,0.02); }
    .transaction-list li:last-child { border-bottom: none; }
    
    .t-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin-right: 12px; font-size: 16px; flex-shrink: 0; }
    .bg-income { background-color: rgba(16, 185, 129, 0.15); color: #10b981; }
    .bg-expense { background-color: rgba(239, 68, 68, 0.15); color: #ef4444; }

    .action-btn { background: none; border: none; cursor: pointer; padding: 8px; border-radius: 6px; transition: 0.2s; font-size: 14px; }
    .action-btn:hover { background-color: rgba(255,255,255,0.1); }
    .btn-edit { color: var(--accent-info); }
    .btn-delete { color: var(--accent-danger); }

    /* AUTO FILLER BADGE */
    .auto-fill-badge { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #10b981; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; }
    .auto-fill-badge:hover { background: #10b981; color: white; }

    /* FORM PRODUCT ROW (Responsive Fix) */
    .product-input-row { display: flex; gap: 10px; margin-bottom: 10px; }

    @media (max-width: 600px) {
        .transaction-list li { flex-direction: column; align-items: flex-start; }
        .list-actions { width: 100%; display: flex; justify-content: space-between; align-items: center; margin-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 10px; }
        .product-input-row { flex-direction: column; }
    }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-book-open" style="color: var(--accent-info); margin-right: 10px;"></i> Knjiga: <?php echo htmlspecialchars($customer['name']); ?></h1>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-secondary" onclick="openCustomerModal()"><i class="fas fa-user-edit"></i> Uredi Kupca</button>
            <a href="customers.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Nazad</a>
        </div>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:12px;'><p style='color:var(--accent-success); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); padding:12px;'><p style='color:var(--accent-danger); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="dashboard-grid">
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <div class="card stat-card <?php echo $balance > 0 ? 'danger' : 'success'; ?>" style="text-align: center; border-bottom: 4px solid <?php echo $balance > 0 ? 'var(--accent-danger)' : 'var(--accent-success)'; ?>;">
                <h3>Trenutni Dug Kupca</h3>
                <div class="stat-number" style="font-size: 40px; margin: 10px 0; color: <?php echo $balance > 0 ? 'var(--accent-danger)' : 'var(--accent-success)'; ?>;">
                    <?php echo number_format($balance, 2); ?> BAM
                </div>
                <p style="font-size: 13px; color: var(--text-muted);">
                    Ukupno uzeto: <?php echo number_format($stats['total_debt'], 2); ?> | Plaćeno: <?php echo number_format($stats['total_paid'], 2); ?>
                </p>
                <?php if(!empty($customer['phone']) || !empty($customer['address']) || !empty($customer['notes'])): ?>
                    <div style="margin-top: 15px; font-size: 13px; color: var(--text-secondary); text-align: left; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px;">
                        <?php if(!empty($customer['phone'])) echo "<div><i class='fas fa-phone' style='width:20px;'></i> {$customer['phone']}</div>"; ?>
                        <?php if(!empty($customer['address'])) echo "<div><i class='fas fa-map-marker-alt' style='width:20px;'></i> {$customer['address']}</div>"; ?>
                        <?php if(!empty($customer['notes'])) echo "<div style='margin-top:5px; border-top:1px dashed rgba(255,255,255,0.1); padding-top:5px;'><i>{$customer['notes']}</i></div>"; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card" style="border-top: 4px solid var(--accent-info);">
                <h3 style="margin-bottom: 15px; color: var(--text-primary);">Novi Zapis u Knjigu</h3>
                <form method="POST">
                    <div class="form-grid">
                        
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Vrsta Zapisa</label>
                            <select name="transaction_type" id="t_type" required style="padding: 14px; font-size: 16px; width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-surface-hover); color: white;" onchange="toggleFormLogic()">
                                <option value="debt">Zaduženje (Uzeo robu na dug)</option>
                                <option value="payment">Uplata (Donio novac)</option>
                            </select>
                        </div>

                        <div id="product_selection_wrapper" style="grid-column: 1 / -1; display: block; background: rgba(0,0,0,0.2); padding: 15px; border-radius: var(--radius-md); border: 1px dashed var(--border-color); margin-bottom: 15px;">
                            <label style="color: var(--text-secondary); margin-bottom: 10px; display: block;">Što kupac uzima? <span style="font-size:12px; color:var(--accent-warning);">(Automatski skida sa zaliha)</span></label>
                            
                            <div class="product-input-row">
                                <select name="product_type" id="p_type" style="flex:2; width:100%; padding:12px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);" onchange="updateDebtUnit()">
                                    <option value="mlijeko">Mlijeko (Zaliha: <?php echo number_format($inv_milk,1); ?> L)</option>
                                    <option value="sir">Sir (Zaliha: <?php echo number_format($inv_cheese,2); ?> KG)</option>
                                    <option value="sirutka">Sirutka (Zaliha: <?php echo number_format($inv_whey,1); ?> L)</option>
                                    <option value="ostalo">Ostalo (Meso, Koza...)</option>
                                </select>
                                <input type="number" step="0.01" min="0" name="quantity" id="p_qty" placeholder="Količina" style="flex:1; width:100%; padding:12px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Datum</label>
                            <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>" style="padding: 14px; width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-surface-hover); color: white;">
                        </div>
                        <div class="form-group">
                            <label style="display: flex; justify-content: space-between; align-items: center;">
                                <span>Iznos (BAM)</span>
                                <?php if($balance > 0): ?>
                                    <span class="auto-fill-badge" id="btn_pay_all" style="display:none;" onclick="payInFull(<?php echo $balance; ?>)">
                                        <i class="fas fa-bolt"></i> Naplati sve: <?php echo number_format($balance, 2); ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                            <input type="number" step="0.01" min="0.01" id="t_amount" name="amount" required placeholder="0.00" style="padding: 14px; font-size: 18px; width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-surface-hover); color: white;">
                        </div>
                        
                        <div class="form-group" id="finance_sync_wrapper" style="display: none; grid-column: 1 / -1; background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: var(--radius-md); border: 1px dashed #10b981;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: white; margin:0;">
                                <input type="checkbox" name="sync_finance" value="1" checked style="transform: scale(1.3);">
                                Također zabilježi ovu uplatu kao prihod u Financijama
                            </label>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Dodatna Napomena (Opcionalno)</label>
                            <input type="text" id="t_desc" name="description" placeholder="npr. Ostavio pare kod kapije..." style="padding: 14px; width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-surface-hover); color: white;">
                        </div>
                    </div>
                    <input type="hidden" name="add_ledger_entry" value="1">
                    <button type="submit" id="btn_submit_entry" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 16px; margin-top: 10px; font-weight:bold; background: var(--accent-danger); border: none;">
                        <i class="fas fa-save"></i> Upiši Zaduženje
                    </button>
                </form>
            </div>
        </div>

        <div class="card" style="max-height: 800px; overflow-y: auto;">
            <h3 style="margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-history"></i> Knjiga Transakcija</h3>
            <?php if ($ledger->num_rows > 0): ?>
                <ul class="transaction-list">
                    <?php while($l = $ledger->fetch_assoc()): 
                        $isDebt = $l['transaction_type'] == 'debt';
                        $color = $isDebt ? '#ef4444' : '#10b981';
                        $bgClass = $isDebt ? 'bg-expense' : 'bg-income';
                        $icon = $isDebt ? 'fa-minus' : 'fa-plus';
                        $label = $isDebt ? 'Zaduženje' : 'Uplata';
                    ?>
                        <li>
                            <div style="display: flex; align-items: center; flex-grow: 1;">
                                <div class="t-icon <?php echo $bgClass; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                                <div>
                                    <strong style="color: var(--text-primary);"><?php echo $label; ?></strong><br>
                                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fas fa-calendar-alt"></i> <?php echo date("d.m.Y", strtotime($l['transaction_date'])); ?></span>
                                    <?php if($l['description']): ?>
                                        <div style="font-size: 13px; margin-top: 2px; color: var(--text-secondary);"><?php echo htmlspecialchars($l['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="list-actions">
                                <div style="font-size: 18px; font-weight: bold; color: <?php echo $color; ?>; white-space: nowrap;">
                                    <?php echo number_format($l['amount'], 2); ?> BAM
                                </div>
                                <div style="display: flex; gap: 5px;">
                                    <button type="button" class="action-btn btn-edit" onclick="openTxModal(<?php echo $l['id']; ?>, '<?php echo $l['transaction_type']; ?>', '<?php echo $l['amount']; ?>', '<?php echo htmlspecialchars(addslashes($l['description'])); ?>', '<?php echo $l['transaction_date']; ?>')" title="Uredi"><i class="fas fa-edit"></i></button>
                                    <a href="customer_ledger.php?id=<?php echo $customer_id; ?>&delete_tx=<?php echo $l['id']; ?>" class="action-btn btn-delete" onclick="return confirm('Obriši zapis i VRATI zalihu nazad u štalu (ako je roba unesena)?');" title="Obriši"><i class="fas fa-trash"></i></a>
                                </div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p style="color: var(--text-muted); text-align: center; padding: 40px 0;">Knjiga je trenutno prazna.</p>
            <?php endif; ?>
        </div>
    </div>
</main>

<div class="modal-overlay" id="customerModal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-user-edit"></i> Uredi Kupca</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Ime Kupca <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" required value="<?php echo htmlspecialchars($customer['name']); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Telefon</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Adresa</label>
                <input type="text" name="address" value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Napomene</label>
                <input type="text" name="notes" value="<?php echo htmlspecialchars($customer['notes'] ?? ''); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            
            <input type="hidden" name="edit_customer" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeCustomerModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Spremi Promjene</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="txModal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-edit"></i> Uredi Transakciju</h3>
        <form method="POST">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Vrsta</label>
                <select name="transaction_type" id="edit_t_type" required style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                    <option value="debt">Zaduženje</option>
                    <option value="payment">Uplata</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Datum</label>
                <input type="date" name="transaction_date" id="edit_date" required style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Iznos (BAM)</label>
                <input type="number" step="0.01" name="amount" id="edit_amount" required style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Opis</label>
                <input type="text" name="description" id="edit_desc" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            
            <input type="hidden" name="edit_tx" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeTxModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Spremi</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Dinamičko prebacivanje forme ovisno je li Dug ili Uplata
    function toggleFormLogic() {
        const type = document.getElementById('t_type').value;
        const syncWrapper = document.getElementById('finance_sync_wrapper');
        const prodWrapper = document.getElementById('product_selection_wrapper');
        const btnSubmit = document.getElementById('btn_submit_entry');
        const btnPayAll = document.getElementById('btn_pay_all');
        const qtyInput = document.getElementById('p_qty');

        if (type === 'payment') {
            syncWrapper.style.display = 'block';
            prodWrapper.style.display = 'none';
            qtyInput.required = false;
            btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> Upiši Uplatu';
            btnSubmit.style.background = '#10b981'; // Green
            if(btnPayAll) btnPayAll.style.display = 'inline-flex';
        } else {
            syncWrapper.style.display = 'none';
            prodWrapper.style.display = 'block';
            qtyInput.required = true;
            updateDebtUnit();
            btnSubmit.innerHTML = '<i class="fas fa-save"></i> Upiši Zaduženje';
            btnSubmit.style.background = 'var(--accent-danger)'; // Red
            if(btnPayAll) btnPayAll.style.display = 'none';
        }
    }

    function updateDebtUnit() {
        const pType = document.getElementById('p_type').value;
        const qtyInput = document.getElementById('p_qty');
        if(pType === 'ostalo') {
            qtyInput.required = false;
            qtyInput.disabled = true;
            qtyInput.value = '';
        } else {
            qtyInput.required = true;
            qtyInput.disabled = false;
            qtyInput.placeholder = (pType === 'sir') ? 'Koliko KG?' : 'Koliko L?';
        }
    }

    function payInFull(amount) {
        document.getElementById('t_amount').value = amount.toFixed(2);
    }

    function openCustomerModal() { document.getElementById('customerModal').style.display = 'flex'; }
    function closeCustomerModal() { document.getElementById('customerModal').style.display = 'none'; }

    function openTxModal(id, type, amount, desc, date) {
        document.getElementById('edit_tx_id').value = id;
        document.getElementById('edit_t_type').value = type;
        document.getElementById('edit_amount').value = amount;
        document.getElementById('edit_desc').value = desc;
        document.getElementById('edit_date').value = date;
        document.getElementById('txModal').style.display = 'flex';
    }
    function closeTxModal() { document.getElementById('txModal').style.display = 'none'; }

    // Pokreni jednom pri učitavanju da postavi početno stanje UI-a
    window.onload = function() { toggleFormLogic(); };
</script>

<?php include 'footer.php'; ?>
