<?php
require 'db.php';

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_weight'])) {
    $goat_id = (int)$_POST['goat_id'];
    $log_date = $conn->real_escape_string($_POST['log_date']);
    $live_weight = (float)$_POST['live_weight'];
    $net_weight = !empty($_POST['net_weight']) ? (float)$_POST['net_weight'] : "NULL";
    $notes = $conn->real_escape_string($_POST['notes']);

    $sql = "INSERT INTO weight_logs (goat_id, log_date, live_weight, net_weight, notes) 
            VALUES ($goat_id, '$log_date', $live_weight, $net_weight, '$notes')";
            
    if ($conn->query($sql) === TRUE) {
        $log_text = "Dodano vaganje za kozu #$goat_id ($live_weight kg)";
        
        // AUTO-SLAUGHTER & FINANCE LINK (Automatski Otpis i Prodaja)
        if ($net_weight !== "NULL" && isset($_POST['auto_sell']) && $_POST['auto_sell'] == '1') {
            $price_per_kg = (float)$_POST['price_per_kg'];
            $payment_method = $conn->real_escape_string($_POST['payment_method']);
            
            // Izračunaj ukupnu cijenu
            $total_price = (float)$net_weight * $price_per_kg;
            
            // 1. Promijeni status koze u 'Prodano'
            $conn->query("UPDATE goats SET status = 'Prodano' WHERE id = $goat_id");
            
            // 2. Upiši zaradu u financije
            if ($total_price > 0) {
                $desc = "Automatska prodaja mesa - Životinja #$goat_id (" . number_format($net_weight,1) . " kg neto po " . number_format($price_per_kg,2) . " BAM/kg)";
                $finance_sql = "INSERT INTO finances (transaction_date, transaction_type, category, amount, payment_method, description) 
                                VALUES ('$log_date', 'income', 'Prodaja Mesa', $total_price, '$payment_method', '$desc')";
                $conn->query($finance_sql);
            }
            
            $log_text .= " | Automatski otpisana i prodana za $total_price BAM.";
        }
        
        logAction($conn, $log_text);
        header("Location: meat.php?success=1");
        exit();
    } else {
        $error_msg = "Greška u bazi: " . $conn->error;
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_msg = "Vaganje / Meso je uspješno upisano!";
}

$all_goats = $conn->query("SELECT id, name, status FROM goats WHERE status != 'Prodano' AND status != 'Krepano' ORDER BY name ASC, id ASC");

$weight_history = $conn->query("
    SELECT w.*, g.name as goat_name 
    FROM weight_logs w 
    JOIN goats g ON w.goat_id = g.id 
    ORDER BY w.log_date DESC, w.id DESC 
    LIMIT 10
");

include 'header.php';
?>

<style>
    .meat-input { font-size: 16px !important; padding: 14px !important; width: 100%; box-sizing: border-box; }
    
    /* Auto-Sell Box Styling */
    .auto-sell-box {
        display: none; /* Skriveno po defaultu, prikazuje se preko JS */
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.05) 100%);
        border: 1px dashed var(--accent-success);
        border-radius: var(--radius-md);
        padding: 15px;
        margin-top: 15px;
        animation: slideDown 0.3s ease-out;
    }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    
    .total-price-display {
        background: var(--bg-surface);
        border: 1px solid var(--accent-success);
        color: var(--accent-success);
        padding: 10px;
        border-radius: var(--radius-md);
        text-align: center;
        font-size: 18px;
        font-weight: bold;
        margin-top: 15px;
    }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-weight" style="color: var(--accent-info); margin-right: 10px;"></i> Vaganje i Meso</h1>
        <a href="meat_history.php" class="btn btn-secondary"><i class="fas fa-history"></i> Povijest Vaganja</a>
    </header>

    <?php if($success_msg): ?>
        <div class="card mb-20" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-success); margin-bottom: 20px;">
            <p style="color: var(--accent-success); font-weight: bold; text-align: center; font-size: 16px; margin: 0;"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></p>
        </div>
    <?php endif; ?>
    <?php if($error_msg): ?>
        <div class="card mb-20" style="background-color: rgba(239, 68, 68, 0.1); border-color: var(--accent-danger); margin-bottom: 20px;">
            <p style="color: var(--accent-danger); font-weight: bold; text-align: center; margin: 0;"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></p>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="card" style="border-top: 4px solid var(--accent-info);">
            <h3 style="color: var(--text-primary); margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Novo Vaganje</h3>
            
            <form method="POST" action="meat.php">
                <div class="form-group">
                    <label>Odaberi Životinju</label>
                    <select name="goat_id" class="meat-input" required>
                        <option value="" disabled selected>-- Pretraži i odaberi --</option>
                        <?php 
                        if ($all_goats->num_rows > 0) {
                            while($g = $all_goats->fetch_assoc()) {
                                $name = $g['name'] ? htmlspecialchars($g['name']) : 'Bez imena';
                                echo "<option value='{$g['id']}'>#{$g['id']} - {$name} ({$g['status']})</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Datum vaganja</label>
                    <input type="date" name="log_date" class="meat-input" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label style="color: var(--text-primary);"><i class="fas fa-balance-scale"></i> Živa Vaga (KG)</label>
                        <input type="number" step="0.1" min="0.1" name="live_weight" class="meat-input" required placeholder="0.0" style="text-align: center; font-weight: bold; font-size: 20px !important;">
                    </div>
                    <div class="form-group">
                        <label style="color: var(--accent-danger);"><i class="fas fa-drumstick-bite"></i> Neto Meso (KG) <span style="font-size:11px; color:var(--text-muted);">(Opcionalno)</span></label>
                        <input type="number" step="0.1" min="0.1" name="net_weight" id="net_weight" class="meat-input" placeholder="0.0" style="text-align: center; font-weight: bold; font-size: 20px !important; color: var(--accent-danger);" oninput="checkNetWeight()">
                    </div>
                </div>

                <div id="auto_sell_box" class="auto-sell-box">
                    <h4 style="color: var(--accent-success); margin: 0 0 10px 0;"><i class="fas fa-hand-holding-usd"></i> Automatska Prodaja i Otpis</h4>
                    <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 10px;">Želite li odmah otpisati životinju iz stada i zabilježiti zaradu u Računovodstvo?</p>
                    
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 15px; font-weight: bold; margin-bottom: 15px; color: white;">
                        <input type="checkbox" name="auto_sell" id="auto_sell" value="1" style="transform: scale(1.3);" onchange="toggleSellDetails()"> 
                        Da, prodaj i otpiši iz stada
                    </label>

                    <div id="sell_details" style="display: none; margin-top: 15px; border-top: 1px dashed rgba(16, 185, 129, 0.3); padding-top: 15px;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label style="font-size: 13px;">Cijena po KG (BAM)</label>
                                <input type="number" step="0.1" min="0" name="price_per_kg" id="price_per_kg" class="meat-input" placeholder="0.00" oninput="calculateTotal()">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 13px;">Plaćanje</label>
                                <select name="payment_method" class="meat-input">
                                    <option value="Gotovina">Gotovina</option>
                                    <option value="Banka">Banka</option>
                                    <option value="Dug">Na Dug</option>
                                </select>
                            </div>
                        </div>
                        <div class="total-price-display" id="total_price_display">
                            Ukupno za naplatu: 0.00 BAM
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label>Napomena (Opcionalno)</label>
                    <input type="text" name="notes" class="meat-input" placeholder="npr. Redovno vaganje jareta, kupac iz Sarajeva...">
                </div>

                <input type="hidden" name="add_weight" value="1">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px; font-size: 18px; margin-top: 10px;">
                    <i class="fas fa-save"></i> Spremi Podatke
                </button>
            </form>
        </div>

        <div class="card" style="display: flex; flex-direction: column;">
            <h3 style="color: var(--text-secondary); margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-list"></i> Nedavno Dodano</h3>
            
            <div style="flex: 1; overflow-y: auto;">
                <?php if ($weight_history->num_rows > 0): ?>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php while($w = $weight_history->fetch_assoc()): 
                            $dateFmt = date("d.m.Y", strtotime($w['log_date']));
                            $goatName = $w['goat_name'] ? htmlspecialchars($w['goat_name']) : 'Bez imena';
                        ?>
                            <li style="padding: 15px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong style="font-size: 16px;">
                                        <a href="profile.php?id=<?php echo $w['goat_id']; ?>" style="color: var(--accent-info); text-decoration: none;">#<?php echo $w['goat_id']; ?> <?php echo $goatName; ?></a>
                                    </strong>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                                        <i class="fas fa-calendar-alt"></i> <?php echo $dateFmt; ?>
                                    </div>
                                    <?php if($w['notes']): ?>
                                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;"><?php echo htmlspecialchars($w['notes']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 18px; font-weight: bold; color: var(--text-primary);">
                                        <?php echo number_format($w['live_weight'], 1); ?> <span style="font-size: 12px; color: var(--text-muted);">kg</span>
                                    </div>
                                    <?php if($w['net_weight']): ?>
                                        <div style="font-size: 14px; font-weight: bold; color: var(--accent-danger); margin-top: 2px;">
                                            <i class="fas fa-drumstick-bite"></i> <?php echo number_format($w['net_weight'], 1); ?> kg
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: var(--text-muted);">
                        <i class="fas fa-balance-scale" style="font-size: 40px; margin-bottom: 10px; opacity: 0.3;"></i>
                        <p>Još nema upisanih vaganja.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
    function checkNetWeight() {
        const netInput = document.getElementById('net_weight').value;
        const sellBox = document.getElementById('auto_sell_box');
        const autoSellCb = document.getElementById('auto_sell');
        
        if (parseFloat(netInput) > 0) {
            sellBox.style.display = 'block';
        } else {
            sellBox.style.display = 'none';
            autoSellCb.checked = false; // Reset
            toggleSellDetails();
        }
        calculateTotal();
    }

    function toggleSellDetails() {
        const autoSellCb = document.getElementById('auto_sell').checked;
        const details = document.getElementById('sell_details');
        const priceInput = document.getElementById('price_per_kg');
        
        if (autoSellCb) {
            details.style.display = 'block';
            priceInput.required = true;
        } else {
            details.style.display = 'none';
            priceInput.required = false;
        }
    }

    function calculateTotal() {
        const net = parseFloat(document.getElementById('net_weight').value) || 0;
        const price = parseFloat(document.getElementById('price_per_kg').value) || 0;
        const total = net * price;
        
        document.getElementById('total_price_display').innerText = `Ukupno za naplatu: ${total.toFixed(2)} BAM`;
    }
</script>

<?php include 'footer.php'; ?>
