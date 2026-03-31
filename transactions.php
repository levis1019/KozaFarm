<?php
require 'db.php';

// 1. SMART UPDATER: Dodavanje kolone za Dobavljača/Partnera
$checkVendor = $conn->query("SHOW COLUMNS FROM finances LIKE 'vendor'");
if ($checkVendor->num_rows == 0) {
    $conn->query("ALTER TABLE finances ADD COLUMN vendor VARCHAR(100) DEFAULT NULL");
}

// 2. AJAX & BATCH AKCIJE
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // A) INLINE EDIT (Brza izmjena)
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'inline_edit') {
        $id = (int)$_POST['tx_id'];
        $amount = (float)$_POST['amount'];
        $category = $conn->real_escape_string($_POST['category']);
        $vendor = $conn->real_escape_string($_POST['vendor']);
        $description = $conn->real_escape_string($_POST['description']);
        
        $conn->query("UPDATE finances SET amount=$amount, category='$category', vendor='$vendor', description='$description' WHERE id=$id");
        header("Location: transactions.php?msg=Uspješno ažurirano");
        exit();
    }
    
    // B) BATCH ACTIONS (Grupne akcije)
    if (isset($_POST['batch_action']) && !empty($_POST['selected_tx'])) {
        $ids = array_map('intval', $_POST['selected_tx']);
        $id_list = implode(',', $ids);
        $action_type = $_POST['batch_action_type'];
        
        if ($action_type == 'delete') {
            $conn->query("DELETE FROM finances WHERE id IN ($id_list)");
            logAction($conn, "Grupno obrisano " . count($ids) . " transakcija.");
        } elseif ($action_type == 'category') {
            $new_cat = $conn->real_escape_string($_POST['batch_category']);
            if($new_cat != "") {
                $conn->query("UPDATE finances SET category='$new_cat' WHERE id IN ($id_list)");
                logAction($conn, "Grupno promijenjena kategorija u '$new_cat' za " . count($ids) . " transakcija.");
            }
        }
        header("Location: transactions.php?msg=Grupna akcija izvršena");
        exit();
    }
}

// Pojedinačno brisanje (Fallback)
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $conn->query("DELETE FROM finances WHERE id=$del_id");
    header("Location: transactions.php");
    exit();
}

// 3. FILTERI I DEFAULT DATUMI
// Default na "Ovaj mjesec" ako nema unosa
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

$t_type = !empty($_GET['t_type']) ? $conn->real_escape_string($_GET['t_type']) : '';
$t_category = !empty($_GET['t_category']) ? $conn->real_escape_string($_GET['t_category']) : '';
$vendor_filter = !empty($_GET['vendor']) ? $conn->real_escape_string($_GET['vendor']) : '';
$search = !empty($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$where_clauses = ["transaction_date >= '$date_from'", "transaction_date <= '$date_to'"];
if ($t_type) $where_clauses[] = "transaction_type = '$t_type'";
if ($t_category) $where_clauses[] = "category = '$t_category'";
if ($vendor_filter) $where_clauses[] = "vendor = '$vendor_filter'";
if ($search) $where_clauses[] = "(description LIKE '%$search%' OR category LIKE '%$search%' OR vendor LIKE '%$search%')";

$where_sql = implode(' AND ', $where_clauses);

// 4. STATISTIKA TRENUTNOG PERIODA
$transactions = $conn->query("SELECT * FROM finances WHERE $where_sql ORDER BY transaction_date DESC, id DESC");

$stats_query = $conn->query("
    SELECT 
        SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as total_inc,
        SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as total_exp,
        MAX(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as max_exp
    FROM finances WHERE $where_sql
");
$stats = $stats_query->fetch_assoc();
$total_inc = $stats['total_inc'] ?? 0;
$total_exp = $stats['total_exp'] ?? 0;
$balance = $total_inc - $total_exp;
$max_expense_val = $stats['max_exp'] ?? 0;

// Pronađi najveći trošak detalji
$largest_expense = null;
if ($max_expense_val > 0) {
    $largest_expense = $conn->query("SELECT category, vendor, transaction_date FROM finances WHERE transaction_type='expense' AND amount=$max_expense_val AND $where_sql LIMIT 1")->fetch_assoc();
}

// 5. MONTH-OVER-MONTH (MoM) TREND
// Izračunaj prethodni period iste duljine
$d1 = new DateTime($date_from);
$d2 = new DateTime($date_to);
$interval_days = $d1->diff($d2)->days + 1;

$prev_d2 = clone $d1;
$prev_d2->modify('-1 day');
$prev_d1 = clone $prev_d2;
$prev_d1->modify("-".($interval_days - 1)." days");

$p_from = $prev_d1->format('Y-m-d');
$p_to = $prev_d2->format('Y-m-d');

// Povuci kategorije iz prošlog perioda
$prev_cat_query = $conn->query("SELECT category, SUM(amount) as cat_total FROM finances WHERE transaction_type='expense' AND transaction_date >= '$p_from' AND transaction_date <= '$p_to' GROUP BY category");
$prev_cats = [];
while($p = $prev_cat_query->fetch_assoc()) {
    $prev_cats[$p['category']] = (float)$p['cat_total'];
}

// Povuci kategorije za trenutni period
$cat_summary = $conn->query("SELECT category, transaction_type, SUM(amount) as cat_total FROM finances WHERE $where_sql GROUP BY category, transaction_type ORDER BY cat_total DESC");

$exp_breakdown = ""; $inc_breakdown = "";
while($c = $cat_summary->fetch_assoc()) {
    $cat_name = $c['category'];
    $cat_total = (float)$c['cat_total'];
    
    if($c['transaction_type'] == 'expense') {
        $trend_html = "";
        if (isset($prev_cats[$cat_name]) && $prev_cats[$cat_name] > 0) {
            $diff_pct = (($cat_total - $prev_cats[$cat_name]) / $prev_cats[$cat_name]) * 100;
            if ($diff_pct > 0) {
                $trend_html = "<span style='font-size:11px; color:#ef4444; margin-left:10px;'><i class='fas fa-arrow-trend-up'></i> +" . round($diff_pct) . "%</span>";
            } elseif ($diff_pct < 0) {
                $trend_html = "<span style='font-size:11px; color:#10b981; margin-left:10px;'><i class='fas fa-arrow-trend-down'></i> " . round($diff_pct) . "%</span>";
            }
        }
        $exp_breakdown .= "<div style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed rgba(255,255,255,0.1); padding: 10px 0;'><span>{$cat_name} {$trend_html}</span><strong style='color:var(--accent-danger);'>-" . number_format($cat_total, 2) . " BAM</strong></div>";
    } else {
        $inc_breakdown .= "<div style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed rgba(255,255,255,0.1); padding: 10px 0;'><span>{$cat_name}</span><strong style='color:var(--accent-success);'>+" . number_format($cat_total, 2) . " BAM</strong></div>";
    }
}

// 6. SPARKLINE DATA PREP (Dnevni Cash Flow)
$spark_query = $conn->query("SELECT transaction_date, SUM(CASE WHEN transaction_type='income' THEN amount ELSE -amount END) as daily_net FROM finances WHERE $where_sql GROUP BY transaction_date ORDER BY transaction_date ASC");
$spark_labels = [];
$spark_data = [];
$running_balance = 0;
while($sp = $spark_query->fetch_assoc()) {
    $spark_labels[] = date("d.m.", strtotime($sp['transaction_date']));
    $running_balance += (float)$sp['daily_net'];
    $spark_data[] = $running_balance;
}

// Pomoćne liste
$all_cats = $conn->query("SELECT DISTINCT category FROM finances ORDER BY category ASC");
$all_vendors = $conn->query("SELECT DISTINCT vendor FROM finances WHERE vendor IS NOT NULL AND vendor != '' ORDER BY vendor ASC");

include 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* PRESET GUMBI */
    .date-presets { display: flex; gap: 10px; margin-bottom: 15px; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none; }
    .date-presets::-webkit-scrollbar { display: none; }
    .preset-btn { background: rgba(59, 130, 246, 0.1); border: 1px solid var(--accent-info); color: var(--accent-info); padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s; }
    .preset-btn:hover { background: var(--accent-info); color: white; }

    /* TRANSACTION LIST & BATCH ACTIONS */
    .transaction-list { list-style: none; padding: 0; margin: 0; }
    .transaction-list li { padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 15px; transition: background 0.2s; }
    .transaction-list li:hover { background-color: rgba(255,255,255,0.02); }
    .transaction-list li:last-child { border-bottom: none; }
    .t-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin-right: 15px; font-size: 18px; flex-shrink: 0; }
    .bg-income { background-color: rgba(16, 185, 129, 0.15); color: #10b981; }
    .bg-expense { background-color: rgba(239, 68, 68, 0.15); color: #ef4444; }
    .batch-checkbox { transform: scale(1.3); margin-right: 10px; cursor: pointer; accent-color: var(--accent-info); }
    
    #batch-bar { display: none; background: rgba(59, 130, 246, 0.15); border: 1px solid var(--accent-info); padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }

    /* WIDGETI */
    .largest-expense-widget { background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(153, 27, 27, 0.1) 100%); border-left: 4px solid var(--accent-danger); padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px; }
    .sparkline-container { height: 60px; width: 100%; margin-top: 15px; }

    /* MODAL ZA UREĐIVANJE */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 90%; max-width: 400px; }

    .action-wrapper { display: flex; align-items: center; gap: 10px; }

    .filter-card { background-color: var(--bg-surface-hover); padding: 20px; border-radius: var(--radius-lg); margin-bottom: 24px; border: 1px solid var(--border-color); }
    .mobile-filter-toggle { display: none; width: 100%; margin-bottom: 15px; padding: 12px; background: var(--bg-surface-hover); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--radius-md); font-size: 16px; font-weight: bold; cursor: pointer; }

    @media (max-width: 768px) {
        .transaction-list li { flex-direction: column; align-items: flex-start; }
        .action-wrapper { width: 100%; justify-content: flex-end; margin-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 10px; }
        .mobile-filter-toggle { display: block; }
        .filter-card { display: none; }
        .filter-card.active { display: block; animation: fadeIn 0.3s ease; }
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-list" style="color: var(--accent-info); margin-right: 10px;"></i> Povijest i Analiza</h1>
        <div style="display: flex; gap: 10px;">
            <a href="finance.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Nazad</a>
        </div>
    </header>

    <?php if(isset($_GET['msg'])) echo "<div class='card mb-20' style='border:1px solid var(--accent-info); padding:10px;'><p style='color:var(--accent-info); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-info-circle'></i> " . htmlspecialchars($_GET['msg']) . "</p></div>"; ?>

    <div class="card" style="margin-bottom: 24px; padding-bottom: 10px;">
        <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; border: none; padding: 0;">
            <div style="text-align: center;">
                <h4 style="color: var(--text-muted); margin: 0 0 5px 0; font-size: 13px;">Prihodi</h4>
                <div style="color: #10b981; font-size: 24px; font-weight: bold;">+ <?php echo number_format($total_inc, 2, ',', '.'); ?></div>
            </div>
            <div style="text-align: center;">
                <h4 style="color: var(--text-muted); margin: 0 0 5px 0; font-size: 13px;">Troškovi</h4>
                <div style="color: #ef4444; font-size: 24px; font-weight: bold;">- <?php echo number_format($total_exp, 2, ',', '.'); ?></div>
            </div>
            <div style="text-align: center;">
                <h4 style="color: var(--text-muted); margin: 0 0 5px 0; font-size: 13px;">Stanje Perioda</h4>
                <div style="color: <?php echo $balance >= 0 ? '#3b82f6' : '#ef4444'; ?>; font-size: 24px; font-weight: bold;">
                    <?php echo number_format($balance, 2, ',', '.'); ?>
                </div>
            </div>
        </div>
        
        <?php if(!empty($spark_data)): ?>
        <div class="sparkline-container">
            <canvas id="cashflowSparkline"></canvas>
        </div>
        <?php endif; ?>
    </div>

    <button class="mobile-filter-toggle" onclick="toggleFilters()">
        <i class="fas fa-filter"></i> Prikaži/Sakrij Filtere
    </button>

    <div class="filter-card" id="filterCard">
        <div class="date-presets">
            <button type="button" class="preset-btn" onclick="setDatePreset('<?php echo date('Y-m-d', strtotime('monday this week')); ?>', '<?php echo date('Y-m-d', strtotime('sunday this week')); ?>')">Ovaj Tjedan</button>
            <button type="button" class="preset-btn" onclick="setDatePreset('<?php echo date('Y-m-01'); ?>', '<?php echo date('Y-m-t'); ?>')">Ovaj Mjesec</button>
            <button type="button" class="preset-btn" onclick="setDatePreset('<?php echo date('Y-m-01', strtotime('last month')); ?>', '<?php echo date('Y-m-t', strtotime('last month')); ?>')">Prošli Mjesec</button>
            <button type="button" class="preset-btn" onclick="setDatePreset('<?php echo date('Y-01-01'); ?>', '<?php echo date('Y-12-31'); ?>')">Ova Godina</button>
        </div>

        <form method="GET" action="transactions.php" class="form-grid" style="align-items: flex-end;" id="filterForm">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Od datuma</label>
                <input type="date" id="d_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" style="padding: 12px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Do datuma</label>
                <input type="date" id="d_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" style="padding: 12px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Vrsta</label>
                <select name="t_type" style="padding: 12px;">
                    <option value="">Sve transakcije</option>
                    <option value="income" <?php if($t_type=='income') echo 'selected'; ?>>Samo Zarada (+)</option>
                    <option value="expense" <?php if($t_type=='expense') echo 'selected'; ?>>Samo Troškovi (-)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Kategorija</label>
                <select name="t_category" style="padding: 12px;">
                    <option value="">Sve kategorije</option>
                    <?php while($c = $all_cats->fetch_assoc()): ?>
                        <option value="<?php echo $c['category']; ?>" <?php if($t_category == $c['category']) echo 'selected'; ?>><?php echo htmlspecialchars($c['category']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Dobavljač / Partner</label>
                <select name="vendor" style="padding: 12px;">
                    <option value="">Svi partneri</option>
                    <?php while($v = $all_vendors->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($v['vendor']); ?>" <?php if($vendor_filter == $v['vendor']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vendor']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Pretraži tekst</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Traži..." style="padding: 12px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: var(--accent-info);"><i class="fas fa-search"></i> Filtriraj</button>
            </div>
        </form>
    </div>

    <div class="dashboard-grid">
        
        <div class="card" style="padding: 0; grid-column: span 2;">
            <form method="POST" id="batchForm">
                
                <div id="batch-bar">
                    <div><strong id="batch-count" style="color: white;">0</strong> odabrano</div>
                    <div style="display: flex; gap: 10px;">
                        <select name="batch_action_type" id="batchActionType" style="padding: 8px; background: var(--bg-surface); color: white; border: 1px solid var(--border-color); border-radius: var(--radius-md);" onchange="document.getElementById('batchCatGroup').style.display = this.value == 'category' ? 'block' : 'none';">
                            <option value="">-- Odaberi akciju --</option>
                            <option value="category">Promijeni kategoriju</option>
                            <option value="delete">Obriši odabrano</option>
                        </select>
                        <div id="batchCatGroup" style="display:none;">
                            <input type="text" name="batch_category" placeholder="Nova kategorija..." style="padding: 8px; background: var(--bg-surface); color: white; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                        </div>
                        <input type="hidden" name="batch_action" value="1">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Jeste li sigurni?');"><i class="fas fa-play"></i> Izvrši</button>
                    </div>
                </div>

                <?php if ($transactions->num_rows > 0): ?>
                    <ul class="transaction-list" style="max-height: 700px; overflow-y: auto;">
                        <?php while($t = $transactions->fetch_assoc()): 
                            $isIncome = $t['transaction_type'] == 'income';
                            $iconClass = $isIncome ? 'bg-income' : 'bg-expense';
                            $iconFa = $isIncome ? 'fa-arrow-down' : 'fa-arrow-up';
                            $sign = $isIncome ? '+' : '-';
                            $colorClass = $isIncome ? '#10b981' : '#ef4444';
                            $dateFmt = date("d.m.Y", strtotime($t['transaction_date']));
                        ?>
                            <li>
                                <div style="display: flex; align-items: center; width: 100%;">
                                    <input type="checkbox" name="selected_tx[]" value="<?php echo $t['id']; ?>" class="batch-checkbox" onclick="updateBatchCount()">
                                    <div class="t-icon <?php echo $iconClass; ?>"><i class="fas <?php echo $iconFa; ?>"></i></div>
                                    <div style="flex-grow: 1;">
                                        <strong style="color: var(--text-primary); display: block; font-size: 15px;">
                                            <?php echo htmlspecialchars($t['category']); ?>
                                        </strong>
                                        <span style="font-size: 12px; color: var(--text-muted);">
                                            <i class="fas fa-calendar-alt"></i> <?php echo $dateFmt; ?> 
                                            <?php if($t['vendor']): ?> &nbsp;|&nbsp; <i class="fas fa-building"></i> <span style="color: var(--accent-info);"><?php echo htmlspecialchars($t['vendor']); ?></span><?php endif; ?>
                                        </span>
                                        <?php if($t['description']): ?>
                                            <div style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                                                <?php echo htmlspecialchars($t['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="action-wrapper">
                                    <div style="font-size: 16px; font-weight: bold; white-space: nowrap; color: <?php echo $colorClass; ?>;">
                                        <?php echo $sign . number_format($t['amount'], 2, ',', '.'); ?> BAM
                                    </div>
                                    <div style="display:flex; gap:5px;">
                                        <button type="button" class="btn btn-secondary" style="padding: 6px 10px;" onclick="openEditModal(<?php echo $t['id']; ?>, '<?php echo $t['amount']; ?>', '<?php echo htmlspecialchars(addslashes($t['category'])); ?>', '<?php echo htmlspecialchars(addslashes($t['vendor'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($t['description'] ?? '')); ?>')">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <a href='transactions.php?delete=<?php echo $t['id'];?>' onclick="return confirm('Brisati?');" class='btn btn-secondary' style='color:var(--accent-danger); border-color:rgba(239, 68, 68, 0.3); padding: 6px 10px;'>
                                            <i class='fas fa-trash'></i>
                                        </a>
                                    </div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px 0; color: var(--text-muted);">
                        <i class="fas fa-search" style="font-size: 40px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p style="font-size: 16px;">Nema rezultata za odabrani filter.</p>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <div style="display: flex; flex-direction: column; gap: 20px;">
            
            <?php if($largest_expense): ?>
                <div class="largest-expense-widget">
                    <div style="font-size: 12px; color: var(--accent-danger); font-weight: bold; text-transform: uppercase; margin-bottom: 5px;"><i class="fas fa-exclamation-circle"></i> Najveći Pojedinačni Trošak</div>
                    <div style="font-size: 20px; color: white; font-weight: bold; margin-bottom: 2px;">- <?php echo number_format($max_expense_val, 2, ',', '.'); ?> BAM</div>
                    <div style="font-size: 14px; color: var(--text-secondary);">
                        <?php echo htmlspecialchars($largest_expense['category']); ?> 
                        <?php if($largest_expense['vendor']) echo " (" . htmlspecialchars($largest_expense['vendor']) . ")"; ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">Datum: <?php echo date("d.m.Y.", strtotime($largest_expense['transaction_date'])); ?></div>
                </div>
            <?php endif; ?>

            <?php if($exp_breakdown): ?>
                <div class="card" style="border-top: 4px solid var(--accent-danger);">
                    <h3 style="color: var(--accent-danger); margin-bottom: 5px; font-size:16px;"><i class="fas fa-chart-pie"></i> Analiza Troškova</h3>
                    <p style="font-size:11px; color:var(--text-muted); margin-bottom:15px; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;">Trend prikazuje usporedbu sa prethodnim periodom.</p>
                    <?php echo $exp_breakdown; ?>
                </div>
            <?php endif; ?>
            
            <?php if($inc_breakdown): ?>
                <div class="card" style="border-top: 4px solid var(--accent-success);">
                    <h3 style="color: var(--accent-success); margin-bottom: 15px; font-size:16px;"><i class="fas fa-chart-pie"></i> Prihodi po kategorijama</h3>
                    <?php echo $inc_breakdown; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-pen"></i> Uredi Transakciju</h3>
        <form method="POST" action="transactions.php">
            <input type="hidden" name="ajax_action" value="inline_edit">
            <input type="hidden" id="edit_tx_id" name="tx_id">
            
            <div class="form-group" style="margin-bottom: 10px;">
                <label>Iznos (BAM)</label>
                <input type="number" step="0.01" id="edit_amount" name="amount" required style="width:100%; padding:10px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label>Kategorija</label>
                <input type="text" id="edit_cat" name="category" required style="width:100%; padding:10px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label>Dobavljač / Partner</label>
                <input type="text" id="edit_vendor" name="vendor" placeholder="npr. Ime trgovine..." style="width:100%; padding:10px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Napomena</label>
                <input type="text" id="edit_desc" name="description" style="width:100%; padding:10px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeEditModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Spremi</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleFilters() {
        document.getElementById('filterCard').classList.toggle('active');
    }

    // Brzi filteri za datume
    function setDatePreset(from, to) {
        document.getElementById('d_from').value = from;
        document.getElementById('d_to').value = to;
        document.getElementById('filterForm').submit();
    }

    // Batch Select Logika
    function updateBatchCount() {
        const count = document.querySelectorAll('.batch-checkbox:checked').length;
        document.getElementById('batch-count').innerText = count;
        document.getElementById('batch-bar').style.display = count > 0 ? 'flex' : 'none';
    }

    // Inline Edit Modal
    function openEditModal(id, amount, cat, vendor, desc) {
        document.getElementById('edit_tx_id').value = id;
        document.getElementById('edit_amount').value = amount;
        document.getElementById('edit_cat').value = cat;
        document.getElementById('edit_vendor').value = vendor;
        document.getElementById('edit_desc').value = desc;
        document.getElementById('editModal').style.display = 'flex';
    }
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // Cash Flow Sparkline
    <?php if(!empty($spark_data)): ?>
    const ctx = document.getElementById('cashflowSparkline').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($spark_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($spark_data); ?>,
                borderColor: '#3b82f6',
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.3,
                fill: true,
                backgroundColor: 'rgba(59, 130, 246, 0.1)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: {enabled: false} },
            scales: {
                x: { display: false },
                y: { display: false }
            },
            layout: { padding: 0 }
        }
    });
    <?php endif; ?>
</script>

<?php include 'footer.php'; ?>
