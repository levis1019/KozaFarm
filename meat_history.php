<?php
require 'db.php';

// 1. FILTERING LOGIC
$where_clauses = ["1=1"];
$goat_filter = isset($_GET['goat_id']) && $_GET['goat_id'] !== '' ? (int)$_GET['goat_id'] : '';
$date_from = !empty($_GET['date_from']) ? $conn->real_escape_string($_GET['date_from']) : '';
$date_to = !empty($_GET['date_to']) ? $conn->real_escape_string($_GET['date_to']) : '';

if ($goat_filter) {
    $where_clauses[] = "w.goat_id = $goat_filter";
}
if ($date_from) {
    $where_clauses[] = "w.log_date >= '$date_from'";
}
if ($date_to) {
    $where_clauses[] = "w.log_date <= '$date_to'";
}

$where_sql = implode(' AND ', $where_clauses);

// 2. CALCULATIONS (Total for filtered view)
$calc_query = $conn->query("SELECT SUM(live_weight) as total_live, SUM(net_weight) as total_net FROM weight_logs w WHERE $where_sql");
$calc_data = $calc_query->fetch_assoc();
$total_live = $calc_data['total_live'] ? $calc_data['total_live'] : 0;
$total_net = $calc_data['total_net'] ? $calc_data['total_net'] : 0;

// Randman mesa (Prosjek)
$avg_yield = 0;
if ($total_live > 0 && $total_net > 0) {
    // Note: Točan randman se računa samo nad onima koji imaju net_weight, ali ovo je okvirni brzi info
    $avg_yield = ($total_net / $total_live) * 100;
}

// 3. PAGINATION LOGIC
$limit = 50;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$count_query = $conn->query("SELECT COUNT(*) as total FROM weight_logs w WHERE $where_sql");
$total_rows = $count_query->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// 4. MAIN QUERY
$history_query = $conn->query("
    SELECT w.*, g.name as goat_name, g.gender, g.breed 
    FROM weight_logs w 
    JOIN goats g ON w.goat_id = g.id 
    WHERE $where_sql 
    ORDER BY w.log_date DESC, w.id DESC 
    LIMIT $limit OFFSET $offset
");

// Preuzimanje svih koza za filter dropdown
$all_goats = $conn->query("SELECT id, name FROM goats ORDER BY name ASC, id ASC");

include 'header.php';
?>

<style>
    /* Desktop Styling */
    .filter-card { background-color: var(--bg-surface-hover); padding: 20px; border-radius: var(--radius-lg); margin-bottom: 24px; border: 1px solid var(--border-color); }
    .action-btn { background: none; border: none; cursor: pointer; padding: 8px 12px; border-radius: 6px; transition: 0.2s; font-size: 15px; }
    .action-btn:hover { background-color: rgba(255,255,255,0.1); }
    .btn-edit { color: var(--accent-info); background-color: rgba(59, 130, 246, 0.1); }
    .btn-delete { color: var(--accent-danger); background-color: rgba(239, 68, 68, 0.1); }
    
    .pagination { display: flex; gap: 10px; justify-content: center; margin-top: 30px; flex-wrap: wrap; }
    .pagination a { padding: 10px 20px; background-color: var(--bg-surface-hover); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-primary); text-decoration: none; font-weight: bold; transition: 0.3s; }
    .pagination a:hover { background-color: var(--accent-primary); color: white; border-color: var(--accent-primary); }
    .pagination span { padding: 10px 20px; color: var(--text-muted); font-weight: bold; }

    .mobile-filter-toggle { display: none; width: 100%; margin-bottom: 15px; padding: 12px; background: var(--bg-surface-hover); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--radius-md); font-size: 16px; font-weight: bold; cursor: pointer; }

    .yield-badge { font-size: 12px; background: rgba(245, 158, 11, 0.2); color: #f59e0b; padding: 3px 6px; border-radius: 6px; font-weight: bold; margin-left: 5px; }

    /* --- MOBILE RESPONSIVE TABLE (CARD LAYOUT) --- */
    @media (max-width: 768px) {
        .mobile-filter-toggle { display: block; }
        .filter-card { display: none; }
        .filter-card.active { display: block; animation: fadeIn 0.3s ease; }
        
        .herd-table thead { display: none; }
        
        .herd-table tbody tr { 
            display: flex; flex-direction: column; margin-bottom: 20px; 
            background: linear-gradient(145deg, var(--bg-surface-hover), var(--bg-surface)); 
            border-radius: 16px; padding: 15px; border: 1px solid var(--border-color); box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .herd-table td { 
            display: flex; justify-content: space-between; align-items: center; 
            padding: 10px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); text-align: right; font-size: 15px;
        }
        .herd-table td::before { content: attr(data-label); font-weight: 600; color: var(--text-muted); text-align: left; margin-right: 15px; }
        
        .herd-table td:last-child { border-bottom: none; justify-content: stretch; margin-top: 10px; padding-bottom: 0; }
        .herd-table td:last-child::before { display: none; }
        .herd-table td:last-child .action-btn { flex: 1; text-align: center; padding: 12px; font-size: 16px; }
        .action-buttons-wrapper { display: flex; gap: 10px; width: 100%; }
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-history" style="color: var(--accent-info); margin-right: 10px;"></i> Povijest Vaganja</h1>
        <a href="meat.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Vaganje</a>
    </header>

    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="card stat-card" style="border-bottom: 4px solid var(--accent-info);">
            <h3><i class="fas fa-balance-scale"></i> Živa Vaga</h3>
            <div class="stat-number"><?php echo number_format($total_live, 1); ?> <span style="font-size: 20px;">kg</span></div>
            <p style="margin-top: 5px; color: var(--text-muted); font-size: 12px;">Ukupno (Prikazani filter)</p>
        </div>
        <div class="card stat-card danger" style="border-bottom: 4px solid var(--accent-danger);">
            <h3><i class="fas fa-drumstick-bite"></i> Neto Meso</h3>
            <div class="stat-number"><?php echo number_format($total_net, 1); ?> <span style="font-size: 20px;">kg</span></div>
            <p style="margin-top: 5px; color: var(--text-muted); font-size: 12px;">Ukupno (Prikazani filter)</p>
        </div>
        <?php if($total_net > 0): ?>
            <div class="card stat-card highlight" style="border-bottom: 4px solid #f59e0b;">
                <h3 style="color: #f59e0b;"><i class="fas fa-chart-pie"></i> Prosječni Randman</h3>
                <div class="stat-number" style="color: #f59e0b;"><?php echo number_format($avg_yield, 1); ?> <span style="font-size: 20px;">%</span></div>
                <p style="margin-top: 5px; color: var(--text-muted); font-size: 12px;">Udio čistog mesa</p>
            </div>
        <?php endif; ?>
    </div>

    <button class="mobile-filter-toggle" onclick="toggleFilters()">
        <i class="fas fa-filter"></i> Prikaži/Sakrij Filtere
    </button>

    <div class="filter-card" id="filterCard">
        <form method="GET" action="meat_history.php" class="form-grid" style="align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Životinja</label>
                <select name="goat_id" style="padding: 14px;">
                    <option value="">-- Sve životinje --</option>
                    <?php while($g = $all_goats->fetch_assoc()): ?>
                        <option value="<?php echo $g['id']; ?>" <?php if($goat_filter == $g['id']) echo 'selected'; ?>>
                            #<?php echo $g['id'] . ' - ' . htmlspecialchars($g['name'] ? $g['name'] : 'Bez imena'); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Od datuma</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" style="padding: 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Do datuma</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" style="padding: 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;"><i class="fas fa-search"></i> Filtriraj</button>
            </div>
        </form>
    </div>

    <div class="card" style="padding: 0; background: transparent; border: none; box-shadow: none;">
        <div class="table-responsive">
            <table class="herd-table" style="width: 100%; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="background: var(--bg-surface-hover);">
                        <th style="padding: 15px;">Datum</th>
                        <th>Životinja</th>
                        <th>Spol/Pasmina</th>
                        <th>Živo (kg)</th>
                        <th>Neto (kg)</th>
                        <th>Napomena</th>
                        <th style="text-align: right; padding: 15px;">Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($history_query->num_rows > 0): ?>
                        <?php while($row = $history_query->fetch_assoc()): ?>
                            <tr style="background: var(--bg-surface);">
                                <td data-label="Datum" style="padding: 15px;"><strong><?php echo date("d.m.Y", strtotime($row['log_date'])); ?></strong></td>
                                <td data-label="Životinja">
                                    <a href="profile.php?id=<?php echo $row['goat_id']; ?>" style="color: var(--accent-info); text-decoration: none; font-weight: bold; font-size: 16px;">
                                        #<?php echo $row['goat_id'] . ' ' . htmlspecialchars($row['goat_name'] ? $row['goat_name'] : 'Bez imena'); ?>
                                    </a>
                                </td>
                                <td data-label="Info">
                                    <span style="color: var(--text-secondary); font-size: 14px;">
                                        <?php 
                                        echo ($row['gender'] == 'Zensko' ? '<i class="fas fa-venus" style="color: pink;"></i> Ž' : '<i class="fas fa-mars" style="color: #3b82f6;"></i> M'); 
                                        if($row['breed']) echo ' | ' . htmlspecialchars($row['breed']); 
                                        ?>
                                    </span>
                                </td>
                                <td data-label="Živa Vaga" style="font-weight: bold; font-size: 16px; color: var(--text-primary);">
                                    <?php echo number_format($row['live_weight'], 1); ?> kg
                                </td>
                                <td data-label="Neto Meso" style="font-weight: bold; font-size: 16px; color: var(--accent-danger);">
                                    <?php 
                                    if($row['net_weight']) {
                                        echo number_format($row['net_weight'], 1) . ' kg';
                                        $yield = ($row['net_weight'] / $row['live_weight']) * 100;
                                        echo " <span class='yield-badge'>" . number_format($yield, 1) . "%</span>";
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td data-label="Napomena" style="color: var(--text-muted); font-size: 14px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($row['notes']); ?>
                                </td>
                                <td style="text-align: right; padding: 15px;">
                                    <div class="action-buttons-wrapper" style="display: flex; gap: 10px; justify-content: flex-end;">
                                        <button type="button" class="action-btn btn-delete" title="Obriši" onclick="deleteLog(<?php echo $row['id']; ?>, this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted); background: var(--bg-surface); border-radius: 12px;">
                            <i class="fas fa-search" style="font-size: 30px; margin-bottom: 15px; opacity: 0.5;"></i><br>
                            Nema pronađenih zapisa za odabrani filter.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&goat_id=<?php echo $goat_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><i class="fas fa-chevron-left"></i> Nazad</a>
                <?php endif; ?>
                
                <span>Stranica <?php echo $page; ?> / <?php echo $total_pages; ?></span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&goat_id=<?php echo $goat_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>">Naprijed <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    function toggleFilters() {
        const filterCard = document.getElementById('filterCard');
        filterCard.classList.toggle('active');
    }

    // Modern AJAX Delete Logic
    function deleteLog(id, btnElement) {
        if (confirm('Jeste li sigurni da želite obrisati ovaj zapis?')) {
            btnElement.disabled = true;
            const originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const formData = new FormData();
            formData.append('id', id);

            fetch('ajax_delete_meat.php', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return response.json();
                } else {
                    throw new Error('Pristup odbijen. Samo admin može brisati podatke.');
                }
            })
            .then(data => {
                if (data.success) {
                    const row = btnElement.closest('tr');
                    row.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
                    row.style.opacity = '0';
                    row.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        row.remove();
                        // Opcijsko osvježavanje stranice kako bi se ažurirale Total metrike na vrhu
                        // window.location.reload(); 
                    }, 400);
                } else {
                    alert('Greška pri brisanju: ' + data.error);
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalContent;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(error.message);
                btnElement.disabled = false;
                btnElement.innerHTML = originalContent;
            });
        }
    }
</script>

<?php include 'footer.php'; ?>
