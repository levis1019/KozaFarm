<?php
require 'db.php';

// AUTO-CREATE THE ONE GLOBAL "SMJESA" IF IT DOESN'T EXIST
$mix_query = $conn->query("SELECT id, name FROM feed_inventory WHERE category = 'mix' LIMIT 1");
if ($mix_query->num_rows > 0) {
    $global_mix = $mix_query->fetch_assoc();
    $global_mix_id = $global_mix['id'];
    $global_mix_name = $global_mix['name'];
} else {
    $conn->query("INSERT INTO feed_inventory (name, category, unit, quantity_in_stock, avg_price_per_unit) VALUES ('Gotova Smjesa', 'mix', 'kg', 0, 0)");
    $global_mix_id = $conn->insert_id;
    $global_mix_name = 'Gotova Smjesa';
}

$success_msg = ""; $error_msg = "";

// --- 2. FORM PROCESSING ---

// A) Novi Artikal (New Feed Item)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_feed_type'])) {
    $name = $conn->real_escape_string($_POST['feed_name']);
    $category = $conn->real_escape_string($_POST['category']);
    $unit = $conn->real_escape_string($_POST['unit']);
    $initial_qty = (float)$_POST['initial_qty'];
    $initial_price = (float)$_POST['initial_price']; 
    $min_stock = (float)$_POST['min_stock'];

    $avg_price = ($initial_qty > 0) ? ($initial_price / $initial_qty) : 0;

    $sql = "INSERT INTO feed_inventory (name, category, unit, quantity_in_stock, avg_price_per_unit, min_stock_limit) 
            VALUES ('$name', '$category', '$unit', $initial_qty, $avg_price, $min_stock)";
    
    if ($conn->query($sql)) {
        $feed_id = $conn->insert_id;
        if ($initial_qty > 0) {
            $conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, total_cost, notes) 
                          VALUES ($feed_id, CURDATE(), 'in', $initial_qty, $initial_price, 'Početno stanje')");
        }
        logAction($conn, "Dodan novi artikl u skladište: $name");
        $success_msg = "Novi artikal uspješno dodan!";
    } else { $error_msg = "Greška: " . $conn->error; }
}

// B) Nova Nabavka (Delivery In)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_delivery'])) {
    $feed_id = (int)$_POST['feed_id'];
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $qty = (float)$_POST['quantity'];
    $total_cost = (float)$_POST['total_cost'];
    $sync_finance = isset($_POST['sync_finance']) ? 1 : 0;
    $notes = $conn->real_escape_string($_POST['notes']);

    $curr = $conn->query("SELECT name, quantity_in_stock, avg_price_per_unit FROM feed_inventory WHERE id = $feed_id")->fetch_assoc();
    $old_qty = (float)$curr['quantity_in_stock'];
    $old_avg = (float)$curr['avg_price_per_unit'];
    $old_value = $old_qty * $old_avg;

    $new_qty = $old_qty + $qty;
    $new_avg = ($new_qty > 0) ? (($old_value + $total_cost) / $new_qty) : 0;

    if ($conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, total_cost, notes) VALUES ($feed_id, '$date', 'in', $qty, $total_cost, '$notes')")) {
        $conn->query("UPDATE feed_inventory SET quantity_in_stock = $new_qty, avg_price_per_unit = $new_avg WHERE id = $feed_id");
        
        if ($sync_finance && $total_cost > 0) {
            $desc = "Nabavka hrane: " . $curr['name'] . " ($qty) - " . $notes;
            $conn->query("INSERT INTO finances (transaction_date, category, description, transaction_type, amount, payment_method) 
                          VALUES ('$date', 'Hrana / Lijekovi', '$desc', 'expense', $total_cost, 'Gotovina')");
        }
        logAction($conn, "Zabilježena nabavka hrane: " . $curr['name'] . " ($qty)");
        $success_msg = "Nabavka zabilježena!";
    }
}

// C) Potrošnja (Manual Usage / Quick Feed)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['log_usage'])) {
    $feed_id = (int)$_POST['feed_id'];
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $qty = (float)$_POST['quantity'];
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');

    $curr = $conn->query("SELECT name, quantity_in_stock FROM feed_inventory WHERE id = $feed_id")->fetch_assoc();
    $new_qty = max(0, (float)$curr['quantity_in_stock'] - $qty);

    if ($conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, notes) VALUES ($feed_id, '$date', 'out', $qty, '$notes')")) {
        $conn->query("UPDATE feed_inventory SET quantity_in_stock = $new_qty WHERE id = $feed_id");
        logAction($conn, "Zabilježena potrošnja hrane: " . $curr['name'] . " ($qty)");
        $success_msg = "Uspješno nahranjeno: Skinuto $qty sa zaliha!";
    }
}

// D) NAPRAVI SMJESU (DYNAMIC UNLIMITED ARRAYS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_mix'])) {
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    
    $total_kg_produced = 0;
    $total_cost_of_mix = 0;

    // Process the dynamic array
    if (isset($_POST['ing_id']) && is_array($_POST['ing_id'])) {
        foreach ($_POST['ing_id'] as $index => $ing_id) {
            $ing_qty = $_POST['ing_qty'][$index] ?? 0;
            
            if (!empty($ing_id) && !empty($ing_qty) && $ing_qty > 0) {
                $ing_id = (int)$ing_id;
                $ing_qty = (float)$ing_qty;
                
                $ing = $conn->query("SELECT name, avg_price_per_unit, quantity_in_stock FROM feed_inventory WHERE id = $ing_id")->fetch_assoc();
                $cost_for_this_ing = $ing_qty * (float)$ing['avg_price_per_unit'];
                
                $total_kg_produced += $ing_qty;
                $total_cost_of_mix += $cost_for_this_ing;

                $new_ing_qty = max(0, (float)$ing['quantity_in_stock'] - $ing_qty);
                $conn->query("UPDATE feed_inventory SET quantity_in_stock = $new_ing_qty WHERE id = $ing_id");
                $conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, notes) VALUES ($ing_id, '$date', 'out', $ing_qty, 'Utrošeno u smjesu')");
            }
        }
    }

    if ($total_kg_produced > 0) {
        $mix = $conn->query("SELECT quantity_in_stock, avg_price_per_unit FROM feed_inventory WHERE id = $global_mix_id")->fetch_assoc();
        $old_qty = (float)$mix['quantity_in_stock'];
        $old_val = $old_qty * (float)$mix['avg_price_per_unit'];
        
        $new_qty = $old_qty + $total_kg_produced;
        $new_avg = ($old_val + $total_cost_of_mix) / $new_qty;

        $conn->query("UPDATE feed_inventory SET quantity_in_stock = $new_qty, avg_price_per_unit = $new_avg WHERE id = $global_mix_id");
        $conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, total_cost, notes) VALUES ($global_mix_id, '$date', 'in', $total_kg_produced, $total_cost_of_mix, 'Proizvedena smjesa: $notes')");
        
        logAction($conn, "Napravljena gotova smjesa: $total_kg_produced KG."); 
        $success_msg = "Uspješno napravljeno $total_kg_produced KG smjese!";
    } else {
        $error_msg = "Niste unijeli ispravne sirovine za smjesu.";
    }
}

// E) INVENTURA HRANE (Korekcija)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['do_food_inventory'])) {
    $inv_data = $_POST['inv_qty']; // Array [feed_id => actual_qty]
    $corrections_made = 0;

    foreach ($inv_data as $feed_id => $actual_qty) {
        $feed_id = (int)$feed_id;
        $actual_qty = (float)$actual_qty;
        
        $curr = $conn->query("SELECT quantity_in_stock FROM feed_inventory WHERE id = $feed_id")->fetch_assoc();
        $old_qty = (float)$curr['quantity_in_stock'];
        $diff = $actual_qty - $old_qty;

        if (abs($diff) > 0.01) {
            $conn->query("UPDATE feed_inventory SET quantity_in_stock = $actual_qty WHERE id = $feed_id");
            $type = ($diff > 0) ? 'in' : 'out'; 
            $abs_diff = abs($diff);
            $conn->query("INSERT INTO feed_transactions (feed_id, transaction_date, type, quantity, notes) VALUES ($feed_id, CURDATE(), 'adjustment', $abs_diff, 'Korekcija Inventure')");
            
            $corrections_made++;
        }
    }

    if ($corrections_made > 0) {
        logAction($conn, "Provedena inventura skladišta hrane: $corrections_made usklađivanja zaliha."); 
        $success_msg = "Inventura uspješna! Sustav je napravio $corrections_made usklađivanja.";
    } else {
        $success_msg = "Sve zalihe se već savršeno slažu. Nema promjena.";
    }
}


// --- 3. FETCH DATA ---
$inventory_query = $conn->query("SELECT * FROM feed_inventory ORDER BY category ASC, name ASC");
$inventory = [];
$all_items_dropdown = []; 

while($row = $inventory_query->fetch_assoc()) {
    $inventory[$row['category']][] = $row;
    $all_items_dropdown[] = $row;
}

$history_query = $conn->query("
    SELECT t.*, f.name, f.unit 
    FROM feed_transactions t 
    JOIN feed_inventory f ON t.feed_id = f.id 
    ORDER BY t.transaction_date DESC, t.id DESC LIMIT 40
");

// Formatting helpers
$cat_names = ['mix' => 'Vaša Gotova Smjesa', 'grain' => 'Sirovine (Koncentrati)', 'forage' => 'Kaba Hrana (Sijeno)', 'supplement' => 'Dodaci (Vitamini)'];
$unit_names = ['bale' => 'Bala', 'kg' => 'KG', 'piece' => 'Komada'];
$cat_icons = ['mix' => 'fa-blender', 'grain' => 'fa-seedling', 'forage' => 'fa-tractor', 'supplement' => 'fa-pills'];
$cat_colors = ['mix' => '#10b981', 'grain' => '#3b82f6', 'forage' => '#f59e0b', 'supplement' => '#a78bfa'];

include 'header.php';
?>

<style>
    .action-row { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
    .action-row .btn { flex: 1; min-width: 140px; text-align: center; justify-content: center; padding: 12px; font-weight:bold; }
    
    .feed-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .feed-card { background: var(--bg-surface-hover); border: 1px solid var(--border-color); border-radius: var(--radius-lg); overflow: hidden; }
    .fc-header { padding: 15px 20px; display: flex; align-items: center; gap: 10px; font-weight: bold; font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .fc-list { list-style: none; padding: 0; margin: 0; }
    .fc-item { padding: 15px 20px; border-bottom: 1px dashed rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; transition: 0.2s; position: relative;}
    .fc-item:last-child { border-bottom: none; }
    
    .fc-item.low-stock { background: linear-gradient(90deg, rgba(239, 68, 68, 0.15) 0%, transparent 100%); border-left: 4px solid #ef4444; }
    
    .fc-name { font-size: 16px; color: var(--text-primary); font-weight: bold; margin-bottom: 3px;}
    .fc-price { font-size: 12px; color: var(--text-muted); margin-bottom: 8px;}
    .fc-qty { font-size: 24px; font-weight: bold; color: white; text-align: right; }
    .fc-qty span { font-size: 12px; color: var(--text-secondary); font-weight: normal; margin-left: 3px; }
    .low-stock-badge { display: inline-block; background: #ef4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: bold; margin-left: 10px; vertical-align: middle;}

    .btn-quick-feed { background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid #3b82f6; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 5px;}
    .btn-quick-feed:hover { background: #3b82f6; color: white; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
    
    .hist-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px dashed rgba(255,255,255,0.05); }
    .hi-in { color: #10b981; }
    .hi-out { color: #ef4444; }
    .hi-adj { color: #a78bfa; }
</style>

<main class="content-area">
    <header style="margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-wheat" style="color: var(--accent-warning); margin-right: 10px;"></i> Skladište i Ishrana</h1>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success);'><p style='color:var(--accent-success); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger);'><p style='color:var(--accent-danger); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="action-row">
        <button class="btn btn-primary" style="background: #10b981; border: none;" onclick="openModal('modal-in')"><i class="fas fa-truck-loading"></i> Nabavka</button>
        <button class="btn btn-primary" style="background: #3b82f6; border: none;" onclick="openModal('modal-mix')"><i class="fas fa-blender"></i> Napravi Smjesu</button>
        <button class="btn btn-primary" style="background: #a78bfa; border: none;" onclick="openModal('modal-inventory')"><i class="fas fa-clipboard-check"></i> Inventura</button>
        <button class="btn btn-secondary" onclick="openModal('modal-new')"><i class="fas fa-plus-circle"></i> Novi Artikal</button>
    </div>

    <div class="feed-grid">
        <?php foreach (['mix', 'grain', 'forage', 'supplement'] as $cat): ?>
            <div class="feed-card" style="border-top: 4px solid <?php echo $cat_colors[$cat]; ?>;">
                <div class="fc-header" style="color: <?php echo $cat_colors[$cat]; ?>;">
                    <i class="fas <?php echo $cat_icons[$cat]; ?>"></i> <?php echo $cat_names[$cat]; ?>
                </div>
                <ul class="fc-list">
                    <?php if(!empty($inventory[$cat])): foreach($inventory[$cat] as $item): 
                        $isLow = ($item['min_stock_limit'] > 0 && $item['quantity_in_stock'] <= $item['min_stock_limit']);
                        $cardClass = $isLow ? 'low-stock' : '';
                    ?>
                        <li class="fc-item <?php echo $cardClass; ?>">
                            <div>
                                <div class="fc-name">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                    <?php if($isLow) echo '<span class="low-stock-badge">NISKO</span>'; ?>
                                </div>
                                <div class="fc-price">Cijena: <?php echo number_format($item['avg_price_per_unit'], 2); ?> BAM / <?php echo $unit_names[$item['unit']]; ?></div>
                                
                                <button type="button" class="btn-quick-feed" onclick="openQuickFeed(<?php echo $item['id']; ?>, '<?php echo addslashes(htmlspecialchars($item['name'])); ?>', '<?php echo $unit_names[$item['unit']]; ?>')">
                                    <i class="fas fa-utensils"></i> Nahrani
                                </button>
                            </div>
                            <div class="fc-qty" style="color: <?php echo $item['quantity_in_stock'] <= 0 ? '#ef4444' : 'white'; ?>;">
                                <?php echo number_format($item['quantity_in_stock'], ($item['unit'] == 'piece' ? 0 : 1)); ?> 
                                <span><?php echo $unit_names[$item['unit']]; ?></span>
                            </div>
                        </li>
                    <?php endforeach; else: ?>
                        <li class="fc-item" style="color: var(--text-muted); justify-content: center;">Nema artikala u ovoj kategoriji.</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="max-height: 500px; overflow-y: auto;">
        <h3 style="margin-bottom: 15px; color: var(--text-secondary);"><i class="fas fa-history"></i> Knjiga Skladišta (Nedavne promjene)</h3>
        <?php if($history_query->num_rows > 0): ?>
            <div style="list-style: none; padding: 0; margin: 0;">
                <?php while($h = $history_query->fetch_assoc()): 
                    if ($h['type'] == 'in') { $colorClass = 'hi-in'; $icon = 'fa-arrow-down'; $sign = '+'; }
                    elseif ($h['type'] == 'out') { $colorClass = 'hi-out'; $icon = 'fa-arrow-up'; $sign = '-'; }
                    else { $colorClass = 'hi-adj'; $icon = 'fa-sync-alt'; $sign = '±'; }
                ?>
                    <div class="hist-item">
                        <div>
                            <strong style="color: var(--text-primary); font-size: 15px;"><i class="fas <?php echo $icon; ?> <?php echo $colorClass; ?>" style="margin-right:8px;"></i> <?php echo htmlspecialchars($h['name']); ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top:3px;"><i class="fas fa-calendar-alt"></i> <?php echo date("d.m.Y", strtotime($h['transaction_date'])); ?></div>
                            <?php if($h['notes']): ?><div style="font-size: 12px; color: var(--text-secondary); margin-top: 3px;"><?php echo htmlspecialchars($h['notes']); ?></div><?php endif; ?>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 18px; font-weight: bold;" class="<?php echo $colorClass; ?>">
                                <?php echo $sign . number_format($h['quantity'], ($h['unit'] == 'piece' ? 0 : 1)) . ' ' . $unit_names[$h['unit']]; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: 20px;">Knjiga je prazna.</p>
        <?php endif; ?>
    </div>
</main>


<div class="modal-overlay" id="modal-inventory">
    <div class="modal-content" style="max-width: 700px;">
        <h3 style="margin-top:0; color:#a78bfa; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-clipboard-check"></i> Inventura Skladišta</h3>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom:20px;">Prođite kroz štalu i upišite <strong>stvarno stanje</strong> koje vidite.</p>
        
        <form method="POST">
            <div style="max-height: 400px; overflow-y: auto; padding-right: 10px; margin-bottom: 20px;">
                <?php foreach($all_items_dropdown as $item): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); padding: 10px 15px; margin-bottom: 10px; border-radius: 8px;">
                        <div>
                            <strong style="color: white; font-size: 15px;"><?php echo htmlspecialchars($item['name']); ?></strong>
                            <div style="font-size: 12px; color: var(--text-muted);">U aplikaciji: <?php echo (float)$item['quantity_in_stock'] . ' ' . $unit_names[$item['unit']]; ?></div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" step="0.1" min="0" name="inv_qty[<?php echo $item['id']; ?>]" value="<?php echo (float)$item['quantity_in_stock']; ?>" class="form-control" style="width: 100px; padding: 10px; border-radius: 6px; background: var(--bg-surface-hover); color: white; border: 1px solid #a78bfa; text-align: center; font-weight: bold;">
                            <span style="color: var(--text-secondary); width: 30px;"><?php echo $unit_names[$item['unit']]; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <input type="hidden" name="do_food_inventory" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-inventory')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:#a78bfa;"><i class="fas fa-sync-alt"></i> Izvrši Usklađivanje</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-quick-feed">
    <div class="modal-content" style="max-width: 400px;">
        <h3 style="margin-top:0; color:#3b82f6; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-utensils"></i> Potrošnja Hrane</h3>
        <p style="color:var(--text-primary); margin-bottom:15px; font-size:16px;">Artikal: <strong id="qf_name" style="color:white;"></strong></p>
        
        <form method="POST">
            <input type="hidden" name="feed_id" id="qf_id">
            <input type="hidden" name="transaction_date" value="<?php echo date('Y-m-d'); ?>">
            <input type="hidden" name="notes" value="Redovno hranjenje">
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Koliko ste potrošili? (<span id="qf_unit"></span>)</label>
                <input type="number" step="0.1" min="0.1" name="quantity" required placeholder="npr. 5" style="width:100%; padding:15px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color); font-size:18px; font-weight:bold; text-align:center;">
            </div>
            
            <input type="hidden" name="log_usage" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-quick-feed')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:#3b82f6;">Završi</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-in">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#10b981; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-truck-loading"></i> Nova Nabavka Hrane</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Što ste kupili?</label>
                <select name="feed_id" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="">-- Odaberite iz skladišta --</option>
                    <?php 
                    foreach($all_items_dropdown as $row) {
                        if($row['category'] != 'mix') echo "<option value='{$row['id']}'>{$row['name']} ({$unit_names[$row['unit']]})</option>";
                    }
                    ?>
                </select>
            </div>
            <div style="display:flex; gap:15px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label>Količina</label>
                    <input type="number" step="0.1" min="0.1" name="quantity" required placeholder="npr. 50" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Ukupno Plaćeno (BAM)</label>
                    <input type="number" step="0.1" min="0" name="total_cost" required placeholder="0.00" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 15px; background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: 6px; border: 1px dashed #10b981;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: white; margin:0;">
                    <input type="checkbox" name="sync_finance" value="1" checked style="transform: scale(1.3);">
                    Zabilježi kao Trošak u Financijama
                </label>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Datum nabavke</label>
                <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Napomena</label>
                <input type="text" name="notes" placeholder="npr. Dobavljač Ivan..." style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <input type="hidden" name="add_delivery" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-in')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:#10b981;">Spremi Nabavku</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-new">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-plus-circle"></i> Novi Artikal u Skladištu</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Naziv Hrane (npr. Djetelina, Kukuruz...)</label>
                <input type="text" name="feed_name" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div style="display:flex; gap:15px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label>Kategorija</label>
                    <select name="category" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                        <option value="forage">Kaba hrana (Sijeno/Djetelina)</option>
                        <option value="grain">Sirovina (Kukuruz/Ječam)</option>
                        <option value="supplement">Dodaci (Vitamini/Sol)</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Mjerna Jedinica</label>
                    <select name="unit" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                        <option value="kg">Kilogram (KG)</option>
                        <option value="bale">Bala</option>
                        <option value="piece">Komad (Blok)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Min. Zaliha (Upozorenje)</label>
                <input type="number" step="0.1" min="0" name="min_stock" value="0" title="Ako zalihe padnu ispod ovog broja, pojavit će se crveno upozorenje" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>

            <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 13px; color: var(--text-muted);">Početno Stanje (Opcionalno)</h4>
                <div style="display:flex; gap:15px;">
                    <div class="form-group" style="flex:1;">
                        <label>Količina</label>
                        <input type="number" step="0.1" min="0" name="initial_qty" value="0" style="width:100%; padding:10px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Vrijednost (BAM)</label>
                        <input type="number" step="0.1" min="0" name="initial_price" value="0" style="width:100%; padding:10px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);">
                    </div>
                </div>
            </div>
            
            <input type="hidden" name="add_feed_type" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-new')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Kreiraj Artikal</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-mix">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#10b981; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-blender"></i> Napravi Smjesu</h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">Odaberite sirovine koje ste umiješali. Čim ispunite red, automatski će se pojaviti novi (možete dodati neograničeno sirovina).</p>
        
        <form method="POST" onsubmit="saveRecipe()">
            <div id="mix-ingredients-container" style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid var(--border-color); transition: background-color 0.3s ease;">
                
                <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <label style="color: var(--text-primary); margin: 0;">Sirovine (Utrošeno)</label>
                    <button type="button" onclick="loadRecipe()" style="background: rgba(59,130,246,0.15); color: #3b82f6; border: 1px solid #3b82f6; border-radius: 4px; padding: 4px 10px; font-size: 12px; cursor: pointer; transition: 0.2s;">
                        <i class="fas fa-history"></i> Učitaj zadnji omjer
                    </button>
                </div>
                
                <div id="ingredients-list">
                    </div>
            </div>

            <div style="display:flex; gap:15px; margin-bottom: 20px;">
                <div class="form-group" style="flex:1;">
                    <label>Datum Miješanja</label>
                    <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Napomena</label>
                    <input type="text" name="notes" placeholder="Opcionalno..." style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            
            <input type="hidden" name="create_mix" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-mix')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:#10b981;">Završeno: Izmiješaj</button>
            </div>
        </form>
    </div>
</div>


<script>
    // Make PHP feed data safely available to Javascript
    const feedItemsData = <?php
        $js_items = [];
        foreach($all_items_dropdown as $row) {
            if($row['category'] != 'mix') {
                $js_items[] = [
                    'id' => $row['id'],
                    'name' => htmlspecialchars($row['name'], ENT_QUOTES),
                    'stock' => (float)$row['quantity_in_stock']
                ];
            }
        }
        echo json_encode($js_items);
    ?>;

    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function openQuickFeed(id, name, unit) {
        document.getElementById('qf_id').value = id;
        document.getElementById('qf_name').innerText = name;
        document.getElementById('qf_unit').innerText = unit;
        openModal('modal-quick-feed');
    }

    // --- DYNAMIC INGREDIENTS LOGIC ---
    function createIngredientRow(idValue = '', qtyValue = '') {
        const row = document.createElement('div');
        row.className = 'ing-row';
        row.style.display = 'flex';
        row.style.gap = '10px';
        row.style.marginBottom = '10px';

        let optionsHtml = '<option value="">Odaberi sirovinu (Opcionalno)</option>';
        feedItemsData.forEach(item => {
            const selected = item.id == idValue ? 'selected' : '';
            optionsHtml += `<option value="${item.id}" ${selected}>${item.name} (Stanje: ${item.stock})</option>`;
        });

        row.innerHTML = `
            <select name="ing_id[]" style="flex:2; padding:10px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);" onchange="checkToAddNewRow(this)">
                ${optionsHtml}
            </select>
            <input type="number" step="0.1" min="0.1" name="ing_qty[]" value="${qtyValue}" placeholder="KG/Kom" style="flex:1; padding:10px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);" oninput="checkToAddNewRow(this)">
        `;
        return row;
    }

    // Listens to inputs. If the user fills out the LAST visible row, spawn a new one.
    function checkToAddNewRow(element) {
        const currentRow = element.closest('.ing-row');
        const container = document.getElementById('ingredients-list');
        const allRows = container.querySelectorAll('.ing-row');

        if (currentRow === allRows[allRows.length - 1]) {
            const selectVal = currentRow.querySelector('select').value;
            const qtyVal = currentRow.querySelector('input').value;

            if (selectVal !== '' && qtyVal !== '') {
                container.appendChild(createIngredientRow());
            }
        }
    }

    // Init the very first row
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('ingredients-list');
        if(container && container.children.length === 0) {
            container.appendChild(createIngredientRow());
        }
    });

    // --- SAVE AND LOAD LAST RECIPE ---
    function saveRecipe() {
        const recipe = [];
        const rows = document.querySelectorAll('#ingredients-list .ing-row');
        
        rows.forEach(row => {
            const id = row.querySelector('select').value;
            const qty = row.querySelector('input').value;
            if(id && qty) {
                recipe.push({ id: id, qty: qty });
            }
        });
        localStorage.setItem('agrodon_last_mix', JSON.stringify(recipe));
    }

    function loadRecipe() {
        const saved = localStorage.getItem('agrodon_last_mix');
        if(saved) {
            const recipe = JSON.parse(saved);
            if(recipe.length > 0) {
                const container = document.getElementById('ingredients-list');
                container.innerHTML = ''; // Wipe current blanks

                // Re-build saved rows
                recipe.forEach(ing => {
                    container.appendChild(createIngredientRow(ing.id, ing.qty));
                });
                
                // Add one blank one at the bottom just in case they want to add more
                container.appendChild(createIngredientRow());

                // Visual flash effect so user knows it loaded
                const mixContainer = document.getElementById('mix-ingredients-container');
                mixContainer.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                setTimeout(() => mixContainer.style.backgroundColor = 'rgba(0,0,0,0.2)', 300);
            }
        } else {
            alert("Nemate još spremljenih receptura! Napravite svoju prvu smjesu i ona će se automatski spremiti za idući put.");
        }
    }
</script>

<?php include 'footer.php'; ?>
