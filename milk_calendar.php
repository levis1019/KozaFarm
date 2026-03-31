<?php
require 'db.php';

// Postavljanje trenutnog mjeseca i godine
$m = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('m');
$y = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');

// Navigacija mjesecima
$prev_m = $m - 1; $prev_y = $y;
if ($prev_m == 0) { $prev_m = 12; $prev_y--; }

$next_m = $m + 1; $next_y = $y;
if ($next_m == 13) { $next_m = 1; $next_y++; }

// Mjeseci na HR
$mjeseci_hr = ['', 'Siječanj', 'Veljača', 'Ožujak', 'Travanj', 'Svibanj', 'Lipanj', 'Srpanj', 'Kolovoz', 'Rujan', 'Listopad', 'Studeni', 'Prosinac'];
$mjesec_naziv = $mjeseci_hr[$m];

// --- 1. DOHVAĆANJE STATISTIKE TRENUTNOG MJESECA ---
$curr_month_sql = "
    SELECT log_date, SUM(morning_liters) as m_liters, SUM(evening_liters) as e_liters, GROUP_CONCAT(notes SEPARATOR ' | ') as day_notes
    FROM milk_logs
    WHERE MONTH(log_date) = $m AND YEAR(log_date) = $y
    GROUP BY log_date ORDER BY log_date ASC
";
$curr_res = $conn->query($curr_month_sql);

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $m, $y);
$chart_labels = [];
$chart_data = [];
$daily_breakdown = [];
$total_current = 0;
$max_daily = 0;

// Inicijalizacija niza za svaki dan u mjesecu
for ($i = 1; $i <= $days_in_month; $i++) {
    $date_str = sprintf("%04d-%02d-%02d", $y, $m, $i);
    $chart_labels[] = $i . '.';
    $chart_data[$date_str] = 0;
    $daily_breakdown[$date_str] = ['m' => 0, 'e' => 0, 'notes' => '', 'missing_shift' => false];
}

while ($row = $curr_res->fetch_assoc()) {
    $date = $row['log_date'];
    $ml = (float)$row['m_liters'];
    $el = (float)$row['e_liters'];
    $total_day = $ml + $el;
    
    $total_current += $total_day;
    $chart_data[$date] = $total_day;
    if ($total_day > $max_daily) $max_daily = $total_day;

    // Logika za detekciju propuštene smjene
    $missing_shift = false;
    if (($ml > 0 && $el == 0) || ($ml == 0 && $el > 0)) {
        // Ignoriraj današnji dan ako je tek jutro
        if ($date !== date('Y-m-d')) {
            $missing_shift = true;
        }
    }

    $daily_breakdown[$date] = [
        'm' => $ml,
        'e' => $el,
        'notes' => $row['day_notes'],
        'missing_shift' => $missing_shift
    ];
}

// --- 2. USPOREDBA (MoM GROWTH) ---
$prev_total = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs WHERE MONTH(log_date) = $prev_m AND YEAR(log_date) = $prev_y")->fetch_assoc()['t'] ?? 0;

$growth_str = "";
$growth_class = "";
if ($prev_total > 0) {
    $perc = (($total_current - $prev_total) / $prev_total) * 100;
    if ($perc > 0) {
        $growth_str = "+" . round($perc, 1) . "% u odnosu na prošli mjesec";
        $growth_class = "text-success";
    } elseif ($perc < 0) {
        $growth_str = round($perc, 1) . "% u odnosu na prošli mjesec";
        $growth_class = "text-danger";
    } else {
        $growth_str = "Ista proizvodnja kao prošli mjesec";
        $growth_class = "text-muted";
    }
} else {
    $growth_str = "Nema podataka za prošli mjesec.";
    $growth_class = "text-muted";
}

include 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* MJESEČNA NAVIGACIJA */
    .month-nav { display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface); padding: 15px 20px; border-radius: var(--radius-lg); margin-bottom: 24px; border: 1px solid var(--border-color); }
    .month-nav a { color: var(--text-primary); text-decoration: none; padding: 10px 15px; border-radius: var(--radius-md); background: rgba(255,255,255,0.05); transition: 0.2s; font-weight: bold; }
    .month-nav a:hover { background: var(--accent-info); color: white; }
    .month-nav h2 { margin: 0; color: var(--accent-info); font-size: 20px; text-transform: uppercase; letter-spacing: 1px; }

    /* HERO WIDGET */
    .hero-stat { text-align: center; margin-bottom: 30px; }
    .hero-stat h1 { font-size: 50px; margin: 0 0 10px 0; color: white; }
    .hero-stat h1 span { font-size: 20px; color: var(--text-muted); }
    .growth-badge { font-size: 14px; font-weight: bold; padding: 6px 15px; border-radius: 20px; display: inline-block; background: rgba(255,255,255,0.05); }

    /* CHART CONTAINER */
    .chart-container { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 30px; position: relative; height: 300px; width: 100%; }

    /* DETALJNI PREGLED (HEATMAP LISTA) */
    .day-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
    .day-item { display: flex; align-items: center; justify-content: space-between; background: var(--bg-surface); border: 1px solid var(--border-color); padding: 15px; border-radius: var(--radius-md); transition: 0.2s; border-left-width: 6px; border-left-style: solid; }
    .day-date { font-size: 18px; font-weight: bold; color: var(--text-primary); width: 60px; }
    .day-stats { flex-grow: 1; display: flex; flex-wrap: wrap; gap: 20px; padding-left: 15px; }
    .day-total { font-size: 20px; font-weight: bold; color: white; }
    .day-warn { color: var(--accent-warning); display: flex; align-items: center; gap: 5px; font-size: 13px; font-weight: bold; background: rgba(245, 158, 11, 0.1); padding: 5px 10px; border-radius: 6px; }

    /* Heatmap boje bazirane na max proizvodnji */
    .color-high { border-left-color: #10b981; } /* Zelena > 80% */
    .color-med { border-left-color: #f59e0b; }  /* Žuta > 40% */
    .color-low { border-left-color: #ef4444; }  /* Crvena < 40% */
    .color-zero { border-left-color: #475569; opacity: 0.5; } /* Siva 0L */

</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-chart-bar" style="color: var(--accent-info); margin-right: 10px;"></i> Analitika Mlijeka</h1>
        <a href="barn.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Nazad u Zalihe</a>
    </header>

    <div class="month-nav">
        <a href="?m=<?php echo $prev_m; ?>&y=<?php echo $prev_y; ?>"><i class="fas fa-chevron-left"></i></a>
        <h2><?php echo $mjesec_naziv . ' ' . $y; ?></h2>
        <a href="?m=<?php echo $next_m; ?>&y=<?php echo $next_y; ?>"><i class="fas fa-chevron-right"></i></a>
    </div>

    <div class="hero-stat">
        <h1><?php echo number_format($total_current, 1); ?> <span>Litara</span></h1>
        <div class="growth-badge <?php echo $growth_class; ?>"><?php echo $growth_str; ?></div>
    </div>

    <div class="chart-container">
        <canvas id="monthChart"></canvas>
    </div>

    <h3 style="color: var(--text-secondary); margin-bottom: 15px;"><i class="fas fa-list-ol"></i> Dnevni Pregled</h3>
    
    <ul class="day-list">
        <?php foreach(array_reverse($daily_breakdown, true) as $date => $d): 
            $tot = $d['m'] + $d['e'];
            $day_num = date('d.', strtotime($date));
            $color_class = 'color-zero';
            
            if ($tot > 0 && $max_daily > 0) {
                $pct = $tot / $max_daily;
                if ($pct >= 0.8) $color_class = 'color-high';
                elseif ($pct >= 0.4) $color_class = 'color-med';
                else $color_class = 'color-low';
            }
        ?>
            <li class="day-item <?php echo $color_class; ?>">
                <div class="day-date"><?php echo $day_num; ?></div>
                
                <div class="day-stats">
                    <?php if($tot > 0): ?>
                        <div style="color: var(--text-muted); font-size: 14px;">
                            <div><i class="fas fa-sun" style="color:#f59e0b; width:15px;"></i> <?php echo number_format($d['m'], 1); ?> L</div>
                            <div><i class="fas fa-moon" style="color:#64748b; width:15px;"></i> <?php echo number_format($d['e'], 1); ?> L</div>
                        </div>
                        <div class="day-total"><?php echo number_format($tot, 1); ?> L</div>
                        
                        <?php if($d['missing_shift']): ?>
                            <div class="day-warn" title="Čini se da nedostaje unos za jednu smjenu (Jutro ili Večer)!">
                                <i class="fas fa-exclamation-triangle"></i> Propuštena smjena?
                            </div>
                        <?php endif; ?>

                        <?php if($d['notes']): ?>
                            <div style="width: 100%; font-size: 12px; color: var(--text-secondary); margin-top: 5px;">
                                <i class="fas fa-comment-dots"></i> <?php echo htmlspecialchars(str_replace(' | ', ', ', $d['notes'])); ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div style="color: var(--text-muted); font-size: 14px; font-style: italic;">Nema upisa za ovaj dan.</div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

</main>

<script>
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.font.family = "sans-serif";

    const ctx = document.getElementById('monthChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Litara',
                data: <?php echo json_encode(array_values($chart_data)); ?>,
                backgroundColor: '#3b82f6',
                borderRadius: 4,
                hoverBackgroundColor: '#60a5fa'
            }]
        },
        options: {
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 15 } },
                y: { display: true, beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
</script>

<?php include 'footer.php'; ?>
