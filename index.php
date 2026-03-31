<?php
require 'db.php';

// 1. SMART UPDATER: Tablica za Barn Scratchpad
$conn->query("CREATE TABLE IF NOT EXISTS scratchpad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$sp_check = $conn->query("SELECT id FROM scratchpad LIMIT 1");
if($sp_check->num_rows == 0) $conn->query("INSERT INTO scratchpad (note) VALUES ('')");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_scratchpad'])) {
    $note = $conn->real_escape_string($_POST['scratchpad_text']);
    $conn->query("UPDATE scratchpad SET note = '$note' WHERE id = 1");
    logAction($conn, "Ažurirana digitalna ploča (Brze bilješke)."); // <-- ADDED LOG
    header("Location: index.php");
    exit();
}

$scratchpad_text = $conn->query("SELECT note FROM scratchpad WHERE id = 1")->fetch_assoc()['note'] ?? '';

// ==========================================
// DATA FETCHING ZA DASHBOARD
// ==========================================

// --- 1. RED ZONE (Kritična Upozorenja) ---
$red_zone = [];

$karenca_q = $conn->query("
    SELECT g.id, g.name, e.event_date, DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY) as w_end 
    FROM events e JOIN goats g ON e.goat_id = g.id 
    WHERE e.withdrawal_days > 0 AND DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY) >= CURDATE()
");
while($k = $karenca_q->fetch_assoc()) {
    $dateFmt = date("d.m.", strtotime($k['w_end']));
    $red_zone[] = "<i class='fas fa-biohazard'></i> <strong>Koza #{$k['id']}</strong> je u karenci do {$dateFmt}!";
}

$births_q = $conn->query("
    SELECT g.id, g.name, e.next_appointment 
    FROM events e JOIN goats g ON e.goat_id = g.id 
    WHERE e.event_type = 'Parenje' AND e.next_appointment BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
");
while($b = $births_q->fetch_assoc()) {
    $dateFmt = date("d.m.", strtotime($b['next_appointment']));
    $red_zone[] = "<i class='fas fa-baby'></i> <strong>Koza #{$b['id']}</strong> očekuje porod oko {$dateFmt}!";
}

$overdue_q = $conn->query("
    SELECT e.id, e.event_type, e.next_appointment, e.notes, g.id as goat_id, g.name as goat_name
    FROM events e LEFT JOIN goats g ON e.goat_id = g.id
    WHERE e.next_appointment < CURDATE() AND e.next_appointment IS NOT NULL AND e.next_appointment != '0000-00-00'
");
while($o = $overdue_q->fetch_assoc()) {
    $dateFmt = date("d.m.", strtotime($o['next_appointment']));
    $target = $o['goat_id'] ? "Kozu #{$o['goat_id']}" : "Cijelo stado";
    $red_zone[] = "<i class='fas fa-exclamation-circle'></i> Zakašnjelo ({$dateFmt}): <strong>{$o['event_type']}</strong> za {$target}.";
}

// --- 2. MISSING DATA NUDGES (Asistent Farme) ---
$nudges = [];

$milk_today = $conn->query("SELECT COUNT(*) as cnt FROM milk_logs WHERE log_date = CURDATE()")->fetch_assoc()['cnt'];
if ($milk_today == 0) $nudges[] = "Zaboravili ste unijeti današnje mužnje.";

$food_today_check = $conn->query("SELECT COUNT(*) as cnt FROM feed_transactions WHERE transaction_date = CURDATE() AND type = 'out'");
$food_today = $food_today_check->fetch_assoc()['cnt'] ?? 0;
if ($food_today == 0) $nudges[] = "Niste zabilježili današnju potrošnju hrane u skladištu.";

$cur_month = (int)date('n');
$cur_day = (int)date('j');
if (($cur_month == 6 || $cur_month == 12) && $cur_day <= 15) {
    $nudges[] = "<strong><i class='fas fa-boxes' style='color:var(--accent-success);'></i> Podsjetnik za inventuru:</strong> Vrijeme je za polugodišnje prebrojavanje zaliha! Provjerite stanje u štali i uskladite ga sa Skladištem.";
}

$dryoff_q = $conn->query("
    SELECT COUNT(*) as cnt FROM events e JOIN goats g ON e.goat_id = g.id 
    WHERE g.status = 'Mlijecna' AND e.event_type = 'Parenje' 
    AND e.next_appointment BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
");
$dryoff_cnt = $dryoff_q->fetch_assoc()['cnt'];
if ($dryoff_cnt > 0) $nudges[] = "<strong>{$dryoff_cnt} mliječnih koza</strong> očekuje porod u idućih 60 dana. Vrijeme je za zasušivanje!";

$wean_q = $conn->query("SELECT COUNT(*) as cnt FROM goats WHERE status = 'Mlado' AND birth_date <= DATE_SUB(CURDATE(), INTERVAL 60 DAY)");
$wean_cnt = $wean_q->fetch_assoc()['cnt'];
if ($wean_cnt > 0) $nudges[] = "Imate <strong>{$wean_cnt} jarića (Mlado)</strong> starijih od 60 dana. Spremni su za odbijanje ili prodaju.";

$yest_q = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs WHERE log_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$yest_milk = (float)($yest_q->fetch_assoc()['t'] ?? 0);
$avg_q = $conn->query("SELECT SUM(morning_liters + evening_liters)/6 as avg_m FROM milk_logs WHERE log_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 2 DAY)");
$avg_milk = (float)($avg_q->fetch_assoc()['avg_m'] ?? 0);
if ($avg_milk > 0 && $yest_milk < ($avg_milk * 0.85)) {
    $drop = round((1 - ($yest_milk / $avg_milk)) * 100);
    $nudges[] = "<span style='color:var(--accent-danger);'><strong>Upozorenje:</strong> Jučerašnje mlijeko palo je za <strong>{$drop}%</strong> u odnosu na tjedni prosjek!</span>";
}

$debt_q = $conn->query("SELECT id FROM customers");
$old_debts = 0;
while($c = $debt_q->fetch_assoc()) {
    $cid = $c['id'];
    $st = $conn->query("SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'debt' THEN amount ELSE 0 END),0) as td,
        COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE 0 END),0) as tp,
        MAX(transaction_date) as last_act
        FROM customer_ledger WHERE customer_id = $cid")->fetch_assoc();
    if (($st['td'] - $st['tp']) > 0 && strtotime($st['last_act']) <= strtotime('-30 days')) $old_debts++;
}
if ($old_debts > 0) $nudges[] = "Imate <strong>{$old_debts} kupaca</strong> s dugom koji nisu izvršili uplatu više od 30 dana.";


// --- 3. GLOBALNE STATISTIKE ---
$prod_total = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs")->fetch_assoc()['t'] ?? 0;
$usage_total = $conn->query("SELECT SUM(liters) as t FROM milk_usage")->fetch_assoc()['t'] ?? 0;
$current_inventory = (float)$prod_total - (float)$usage_total;

$milk_month_q = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs WHERE MONTH(log_date) = MONTH(CURDATE()) AND YEAR(log_date) = YEAR(CURDATE())");
$milk_month = $milk_month_q->fetch_assoc()['t'] ?? 0;

$fin_total_q = $conn->query("SELECT SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END) as inc, SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END) as exp FROM finances");
$fin_total = $fin_total_q->fetch_assoc();
$bal_total = ($fin_total['inc'] ?? 0) - ($fin_total['exp'] ?? 0);

$fin_month_q = $conn->query("SELECT SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END) as inc, SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END) as exp FROM finances WHERE MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())");
$fin_month = $fin_month_q->fetch_assoc();
$bal_month = ($fin_month['inc'] ?? 0) - ($fin_month['exp'] ?? 0);


// --- 4. FARM PULSE CHARTS ---
$last_7_days = [];
$milk_data = []; $inc_data = []; $exp_data = [];

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $lbl = date('d.m.', strtotime($d));
    $last_7_days[$d] = $lbl;
    $milk_data[$d] = 0; $inc_data[$d] = 0; $exp_data[$d] = 0;
}

$m_q = $conn->query("SELECT log_date, SUM(morning_liters + evening_liters) as total FROM milk_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY log_date");
while($r = $m_q->fetch_assoc()) { if(isset($milk_data[$r['log_date']])) $milk_data[$r['log_date']] = (float)$r['total']; }

$f_q = $conn->query("SELECT transaction_date, transaction_type, SUM(amount) as total FROM finances WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY transaction_date, transaction_type");
while($r = $f_q->fetch_assoc()) {
    if(isset($inc_data[$r['transaction_date']]) && $r['transaction_type'] == 'income') $inc_data[$r['transaction_date']] = (float)$r['total'];
    if(isset($exp_data[$r['transaction_date']]) && $r['transaction_type'] == 'expense') $exp_data[$r['transaction_date']] = (float)$r['total'];
}


// --- 5. VISUAL HERD DEMOGRAPHICS ---
$demo_females = [0, 0, 0, 0, 0, 0];
$demo_males = [0, 0, 0, 0, 0, 0];
$total_goats_real = 0;

$d_q = $conn->query("SELECT status, gender, COUNT(*) as cnt FROM goats WHERE status NOT IN ('Prodano', 'Krepano') GROUP BY status, gender");
while($r = $d_q->fetch_assoc()) {
    $status = $r['status'];
    $idx = array_search($status, ['Mlijecna', 'Suha', 'Trudna', 'Mlado', 'Rasplodna']);
    if($idx === false) $idx = 5; 
    
    if($r['gender'] == 'Zensko') {
        $demo_females[$idx] += (int)$r['cnt'];
    } else {
        $demo_males[$idx] += (int)$r['cnt'];
    }
    $total_goats_real += (int)$r['cnt'];
}

$total_females = array_sum($demo_females);
$total_males = array_sum($demo_males);

$cnt_mlijecna = $demo_females[0] + $demo_males[0];
$cnt_suha = $demo_females[1] + $demo_males[1];
$cnt_trudna = $demo_females[2] + $demo_males[2];
$cnt_mlado = $demo_females[3] + $demo_males[3];
$cnt_rasplodna = $demo_females[4] + $demo_males[4];

include 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    body, .content-area { overflow-x: hidden; width: 100%; box-sizing: border-box; }
    * { box-sizing: border-box; }

    .weather-widget { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: var(--radius-lg); padding: 20px; color: white; margin-bottom: 24px; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3); width: 100%; }
    .w-main { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 15px; margin-bottom: 15px; }
    .w-temp { font-size: 36px; font-weight: bold; line-height: 1; margin-bottom: 5px; }
    .w-loc { font-size: 14px; opacity: 0.9; }
    .w-icon { font-size: 45px; opacity: 0.9; }
    .w-forecast { display: flex; justify-content: space-between; text-align: center; gap: 10px; }
    .wf-day { flex: 1; background: rgba(0,0,0,0.15); padding: 10px 5px; border-radius: var(--radius-md); }
    .wf-day-name { font-size: 12px; opacity: 0.8; margin-bottom: 5px; text-transform: uppercase; font-weight: bold; }
    .wf-day-icon { font-size: 20px; margin-bottom: 5px; }
    .wf-day-temp { font-size: 14px; font-weight: bold; }
    .wf-day-temp span { opacity: 0.7; font-size: 11px; margin-left: 3px; }

    .red-zone { background: rgba(239, 68, 68, 0.1); border: 2px solid var(--accent-danger); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 24px; width: 100%; }
    .red-zone h3 { color: var(--accent-danger); margin: 0 0 15px 0; display: flex; align-items: center; gap: 10px; }
    .red-zone ul { list-style: none; padding: 0; margin: 0; }
    .red-zone li { color: white; padding: 10px 0; border-bottom: 1px dashed rgba(239, 68, 68, 0.3); font-size: 15px; line-height: 1.4; }
    .red-zone li:last-child { border-bottom: none; padding-bottom: 0; }

    .nudge-box { background: rgba(245, 158, 11, 0.1); border-left: 4px solid var(--accent-warning); padding: 15px 20px; border-radius: var(--radius-md); margin-bottom: 24px; width: 100%; }
    .nudge-box h4 { color: var(--accent-warning); margin: 0 0 10px 0; }
    .nudge-box ul { list-style: none; padding: 0; margin: 0; }
    .nudge-box li { color: var(--text-secondary); font-size: 14px; margin-bottom: 5px; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4; }

    .quick-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 30px; width: 100%; }
    .qa-btn { background: var(--bg-surface-hover); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px 10px; text-align: center; color: var(--text-primary); text-decoration: none; transition: 0.2s; display: flex; flex-direction: column; align-items: center; gap: 12px; }
    .qa-btn i { font-size: 28px; }
    .qa-btn span { font-size: 14px; font-weight: bold; }
    .qa-btn:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
    .qa-milk { color: #10b981; border-color: rgba(16, 185, 129, 0.3); } 
    .qa-money { color: #f59e0b; border-color: rgba(245, 158, 11, 0.3); } 
    .qa-goat { color: #3b82f6; border-color: rgba(59, 130, 246, 0.3); } 
    .qa-event { color: #a78bfa; border-color: rgba(167, 139, 250, 0.3); } 

    .stats-overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; width: 100%; }
    .stat-overview-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px; display: flex; flex-direction: column; justify-content: center; }
    .stat-overview-card h4 { font-size: 13px; color: var(--text-muted); margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-overview-card .val { font-size: 26px; font-weight: 900; margin: 0; display: flex; align-items: baseline; gap: 5px; }
    .stat-overview-card .val span { font-size: 14px; font-weight: normal; opacity: 0.8; }

    .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; width: 100%; }
    .chart-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px; width: 100%; }
    .chart-card h3 { color: var(--text-secondary); font-size: 14px; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
    .canvas-container { position: relative; height: 220px; width: 100%; }

    .fancy-herd-container { background: transparent; border: none; margin-bottom: 30px; width: 100%; }
    .fancy-herd-container h3 { color: var(--text-primary); margin: 0 0 20px 0; font-size: 18px; display: flex; align-items: center; gap: 10px; }
    .fh-badge { background: var(--accent-info); color: white; padding: 4px 10px; border-radius: 20px; font-size: 13px; font-weight: bold; }
    
    .fh-genders { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
    .fh-gender-card { padding: 18px 20px; border-radius: var(--radius-md); display: flex; justify-content: space-between; align-items: center; font-size: 18px; font-weight: bold; }
    .fh-female { background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(219, 39, 119, 0.05)); border: 1px solid rgba(236, 72, 153, 0.3); color: #f472b6; }
    .fh-male { background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(37, 99, 235, 0.05)); border: 1px solid rgba(59, 130, 246, 0.3); color: #3b82f6; }
    .fh-gender-card .fh-val { font-size: 26px; }

    .fh-status-list { display: flex; flex-direction: column; gap: 10px; }
    .fh-status-item { display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface-hover); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: var(--radius-md); transition: 0.2s; }
    .fh-status-item:hover { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); }
    .fh-status-left { display: flex; align-items: center; gap: 15px; font-size: 16px; font-weight: 500; color: var(--text-primary); }
    .fh-status-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; justify-content: center; align-items: center; font-size: 16px; }
    .fh-status-val { font-size: 20px; font-weight: bold; color: white; }

    .st-mlijecna .fh-status-icon { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .st-suha .fh-status-icon { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
    .st-trudna .fh-status-icon { background: rgba(167, 139, 250, 0.15); color: #a78bfa; }
    .st-mlado .fh-status-icon { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
    .st-rasplodna .fh-status-icon { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

    .scratchpad { background: #1e293b; border: 2px dashed #475569; border-radius: var(--radius-lg); padding: 20px; margin-bottom: 30px; width: 100%; }
    .scratchpad textarea { width: 100%; background: transparent; border: none; color: #f8fafc; font-size: 16px; line-height: 1.5; resize: vertical; min-height: 120px; outline: none; margin-bottom: 15px; }
    
    @media (max-width: 768px) {
        .charts-grid { grid-template-columns: 1fr; }
        .quick-actions { grid-template-columns: 1fr 1fr; }
        .fh-genders { grid-template-columns: 1fr; }
    }
</style>

<main class="content-area">

    <div class="weather-widget">
        <div class="w-main">
            <div>
                <div class="w-temp" id="w-temp">--°C</div>
                <div class="w-loc"><i class="fas fa-map-marker-alt"></i> Posušje, BiH</div>
            </div>
            <div class="w-icon" id="w-icon"><i class="fas fa-cloud-sun"></i></div>
        </div>
        <div class="w-forecast" id="w-forecast">
            <div style="text-align:center; width:100%; font-size:12px;">Učitavam prognozu...</div>
        </div>
    </div>

    <?php if(!empty($red_zone)): ?>
        <div class="red-zone">
            <h3><i class="fas fa-radiation"></i> KRITIČNA UPOZORENJA</h3>
            <ul>
                <?php foreach($red_zone as $alert) echo "<li>$alert</li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if(!empty($nudges)): ?>
        <div class="nudge-box">
            <h4><i class="fas fa-info-circle"></i> Asistent Farme</h4>
            <ul>
                <?php foreach($nudges as $nudge) echo "<li><i class='fas fa-caret-right' style='margin-top:4px;'></i> <div>$nudge</div></li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="quick-actions">
        <a href="barn.php" class="qa-btn qa-milk">
            <i class="fas fa-prescription-bottle"></i>
            <span>Nova Mužnja</span>
        </a>
        <a href="finance.php" class="qa-btn qa-money">
            <i class="fas fa-wallet"></i>
            <span>Novi Trošak</span>
        </a>
        <a href="add_goat.php" class="qa-btn qa-goat">
            <i class="fas fa-plus-circle"></i>
            <span>Nova Koza</span>
        </a>
        <a href="events.php" class="qa-btn qa-event">
            <i class="fas fa-syringe"></i>
            <span>Liječenje / Zadatak</span>
        </a>
    </div>

    <div class="stats-overview-grid">
        <div class="stat-overview-card" style="border-bottom: 3px solid #10b981;">
            <h4><i class="fas fa-prescription-bottle"></i> Zalihe Mlijeka</h4>
            <div class="val" style="color: #10b981;"><?php echo number_format($current_inventory, 1, ',', '.'); ?> <span>Litara</span></div>
        </div>
        <div class="stat-overview-card" style="border-bottom: 3px solid rgba(16, 185, 129, 0.5);">
            <h4><i class="fas fa-calendar-alt"></i> Mlijeko (Ovaj Mjesec)</h4>
            <div class="val" style="color: #10b981;"><?php echo number_format($milk_month, 1, ',', '.'); ?> <span>Litara</span></div>
        </div>
        <div class="stat-overview-card" style="border-bottom: 3px solid <?php echo $bal_total >= 0 ? '#3b82f6' : '#ef4444'; ?>;">
            <h4><i class="fas fa-wallet"></i> Ukupno Stanje</h4>
            <div class="val" style="color: <?php echo $bal_total >= 0 ? '#3b82f6' : '#ef4444'; ?>;">
                <?php echo number_format($bal_total, 2, ',', '.'); ?> <span>BAM</span>
            </div>
        </div>
        <div class="stat-overview-card" style="border-bottom: 3px solid <?php echo $bal_month >= 0 ? 'rgba(59, 130, 246, 0.5)' : 'rgba(239, 68, 68, 0.5)'; ?>;">
            <h4><i class="fas fa-calendar-alt"></i> Stanje (Ovaj Mjesec)</h4>
            <div class="val" style="color: <?php echo $bal_month >= 0 ? '#3b82f6' : '#ef4444'; ?>;">
                <?php echo number_format($bal_month, 2, ',', '.'); ?> <span>BAM</span>
            </div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <h3><i class="fas fa-chart-line text-success"></i> Mlijeko (Zadnjih 7 dana)</h3>
            <div class="canvas-container">
                <canvas id="pulseMilkChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3><i class="fas fa-money-bill-wave text-warning"></i> Financije (Zadnjih 7 dana)</h3>
            <div class="canvas-container">
                <canvas id="pulseFinChart"></canvas>
            </div>
        </div>
    </div>

    <div class="fancy-herd-container">
        <h3>Detaljni Pregled Stada <span class="fh-badge"><?php echo $total_goats_real; ?> Aktivnih</span></h3>
        
        <div class="fh-genders">
            <div class="fh-gender-card fh-female">
                <span><i class="fas fa-venus" style="margin-right:8px;"></i> Ukupno Ženki</span>
                <span class="fh-val"><?php echo $total_females; ?></span>
            </div>
            <div class="fh-gender-card fh-male">
                <span><i class="fas fa-mars" style="margin-right:8px;"></i> Ukupno Mužjaka</span>
                <span class="fh-val"><?php echo $total_males; ?></span>
            </div>
        </div>

        <div class="fh-status-list">
            <div class="fh-status-item st-mlijecna">
                <div class="fh-status-left">
                    <div class="fh-status-icon"><i class="fas fa-prescription-bottle"></i></div>
                    <span>Mliječna</span>
                </div>
                <div class="fh-status-val"><?php echo $cnt_mlijecna; ?></div>
            </div>
            
            <div class="fh-status-item st-suha">
                <div class="fh-status-left">
                    <div class="fh-status-icon"><i class="fas fa-tint-slash"></i></div>
                    <span>Suha</span>
                </div>
                <div class="fh-status-val"><?php echo $cnt_suha; ?></div>
            </div>

            <div class="fh-status-item st-trudna">
                <div class="fh-status-left">
                    <div class="fh-status-icon"><i class="fas fa-baby-carriage"></i></div>
                    <span>Trudna</span>
                </div>
                <div class="fh-status-val"><?php echo $cnt_trudna; ?></div>
            </div>

            <div class="fh-status-item st-mlado">
                <div class="fh-status-left">
                    <div class="fh-status-icon"><i class="fas fa-baby"></i></div>
                    <span>Mlado (Jarići)</span>
                </div>
                <div class="fh-status-val"><?php echo $cnt_mlado; ?></div>
            </div>

            <div class="fh-status-item st-rasplodna">
                <div class="fh-status-left">
                    <div class="fh-status-icon"><i class="fas fa-mars-stroke"></i></div>
                    <span>Rasplodna (Jarci)</span>
                </div>
                <div class="fh-status-val"><?php echo $cnt_rasplodna; ?></div>
            </div>
        </div>
    </div>

    <div class="scratchpad">
        <h3 style="color: #cbd5e1; margin-bottom: 15px; font-size: 16px;"><i class="fas fa-edit"></i> Digitalna Ploča / Brze Bilješke</h3>
        <form method="POST">
            <textarea name="scratchpad_text" placeholder="Upiši brzu bilješku, zapažanje u štali, popis za kupovinu..."><?php echo htmlspecialchars($scratchpad_text); ?></textarea>
            <input type="hidden" name="update_scratchpad" value="1">
            <button type="submit" class="btn btn-secondary" style="width: 100%; background: #334155; border: none; color: white;"><i class="fas fa-save"></i> Spremi Bilješke</button>
        </form>
    </div>

</main>

<script>
    // --- 1. OPEN-METEO API ZA POSUŠJE ---
    const WMO_CODES = {
        0: 'fa-sun', 1: 'fa-cloud-sun', 2: 'fa-cloud-sun', 3: 'fa-cloud', 
        45: 'fa-smog', 48: 'fa-smog', 51: 'fa-cloud-rain', 53: 'fa-cloud-rain', 
        55: 'fa-cloud-rain', 61: 'fa-cloud-showers-heavy', 63: 'fa-cloud-showers-heavy', 
        65: 'fa-cloud-showers-heavy', 71: 'fa-snowflake', 73: 'fa-snowflake', 
        75: 'fa-snowflake', 95: 'fa-bolt', 96: 'fa-bolt', 99: 'fa-bolt'
    };

    const DAYS = ['Ned', 'Pon', 'Uto', 'Sri', 'Čet', 'Pet', 'Sub'];

    async function fetchWeather() {
        try {
            const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=43.4736&longitude=17.3283&current_weather=true&daily=temperature_2m_max,temperature_2m_min,weathercode&timezone=Europe%2FBerlin');
            const data = await res.json();
            
            const currentTemp = Math.round(data.current_weather.temperature);
            const currentCode = data.current_weather.weathercode;
            let currentIcon = WMO_CODES[currentCode] || 'fa-cloud-sun';
            
            document.getElementById('w-temp').innerText = currentTemp + '°C';
            document.getElementById('w-icon').innerHTML = `<i class="fas ${currentIcon}"></i>`;

            let forecastHTML = '';
            for(let i = 1; i <= 3; i++) {
                let dateObj = new Date(data.daily.time[i]);
                let dayName = DAYS[dateObj.getDay()];
                let maxT = Math.round(data.daily.temperature_2m_max[i]);
                let minT = Math.round(data.daily.temperature_2m_min[i]);
                let wCode = data.daily.weathercode[i];
                let fIcon = WMO_CODES[wCode] || 'fa-cloud';

                forecastHTML += `
                    <div class="wf-day">
                        <div class="wf-day-name">${dayName}</div>
                        <div class="wf-day-icon"><i class="fas ${fIcon}"></i></div>
                        <div class="wf-day-temp">${maxT}° <span>${minT}°</span></div>
                    </div>
                `;
            }
            document.getElementById('w-forecast').innerHTML = forecastHTML;

        } catch(e) {
            console.log("Weather error", e);
            document.getElementById('w-forecast').innerHTML = '<div style="font-size:12px;">Greška pri učitavanju vremenske prognoze.</div>';
        }
    }
    fetchWeather();

    // --- 2. GLOBAL CHART SETTINGS ---
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.font.family = "sans-serif";

    const chartLabels = <?php echo json_encode(array_values($last_7_days)); ?>;

    // --- 3. FARM PULSE: MLIJEKO ---
    const ctxMilk = document.getElementById('pulseMilkChart').getContext('2d');
    new Chart(ctxMilk, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Litara',
                data: <?php echo json_encode(array_values($milk_data)); ?>,
                borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true, tension: 0.3, borderWidth: 2, pointRadius: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { display: true, beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });

    // --- 4. FARM PULSE: FINANCIJE ---
    const ctxFin = document.getElementById('pulseFinChart').getContext('2d');
    new Chart(ctxFin, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Zarada (BAM)',
                    data: <?php echo json_encode(array_values($inc_data)); ?>,
                    backgroundColor: '#10b981', borderRadius: 4
                },
                {
                    label: 'Trošak (BAM)',
                    data: <?php echo json_encode(array_values($exp_data)); ?>,
                    backgroundColor: '#ef4444', borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { display: true, beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
</script>

<?php include 'footer.php'; ?>
