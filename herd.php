<?php
require 'db.php';

// --- SMART DATABASE UPDATER ---
$checkFlag = $conn->query("SHOW COLUMNS FROM goats LIKE 'flagged'");
if ($checkFlag->num_rows == 0) {
    $conn->query("ALTER TABLE goats ADD COLUMN flagged TINYINT(1) DEFAULT 0");
}
$checkArchive = $conn->query("SHOW COLUMNS FROM goats LIKE 'archived'");
if ($checkArchive->num_rows == 0) {
    $conn->query("ALTER TABLE goats ADD COLUMN archived TINYINT(1) DEFAULT 0");
}

// --- AJAX HANDLERI (Za brze akcije bez učitavanja stranice) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $goat_id = (int)$_POST['goat_id'];
    
    if ($_POST['ajax_action'] === 'toggle_flag') {
        $flag_val = (int)$_POST['flag_val'];
        $conn->query("UPDATE goats SET flagged = $flag_val WHERE id = $goat_id");
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($_POST['ajax_action'] === 'quick_edit_status') {
        $new_status = $conn->real_escape_string($_POST['new_status']);
        $conn->query("UPDATE goats SET status = '$new_status' WHERE id = $goat_id");
        logAction($conn, "Brza promjena statusa: Koza #$goat_id je sada '$new_status'");
        echo json_encode(['success' => true]);
        exit();
    }
}

// --- BRISANJE KOZE ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    logAction($conn, "Obrisana koza (ID: $del_id) iz stada");
    $conn->query("DELETE FROM goats WHERE id=$del_id");
    header("Location: herd.php");
    exit();
}

// --- DOHVAĆANJE PODATAKA I STATISTIKA ZA TABS ---
$all_goats = $conn->query("
    SELECT g.*, 
    (SELECT COUNT(*) FROM events e WHERE e.goat_id = g.id AND e.withdrawal_days > 0 AND DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY) >= CURDATE()) as in_karenca
    FROM goats g ORDER BY g.id DESC
");

// Brojači za Quick Tabs
$counts = [
    'Sve' => 0, 'Mlijecna' => 0, 'Suha' => 0, 'Trudna' => 0, 
    'Mlado' => 0, 'Flagged' => 0, 'Karenca' => 0, 'Arhiva' => 0
];
$goats_data = [];

while($g = $all_goats->fetch_assoc()) {
    if (!isset($g['archived'])) $g['archived'] = 0; // Osiguranje za stare zapise

    if ($g['archived'] == 1) {
        $counts['Arhiva']++;
    } else {
        $counts['Sve']++;
        
        if ($g['status'] == 'Rasplodna' || $g['status'] == 'Trudna') {
            $counts['Trudna']++;
        } elseif (isset($counts[$g['status']])) {
            $counts[$g['status']]++;
        }

        if($g['flagged'] == 1) $counts['Flagged']++;
        if($g['in_karenca'] > 0) $counts['Karenca']++;
    }
    
    $ageStr = '-';
    if (!empty($g['birth_date'])) {
        $dob = new DateTime($g['birth_date']);
        $now = new DateTime();
        $diff = $now->diff($dob);
        
        if ($diff->y > 0) { 
            $ageStr = "{$diff->y} g"; 
            if ($diff->m > 0) {
                $ageStr .= " {$diff->m} mj"; 
            }
        } 
        elseif ($diff->m > 0) { 
            $ageStr = "{$diff->m} mj"; 
        } 
        else { 
            $ageStr = "{$diff->d} d"; 
        }
    }
    $g['age_str'] = $ageStr;
    $goats_data[] = $g;
}

$status_colors = [
    'Mlijecna' => '#10b981', 
    'Suha' => '#f59e0b',     
    'Trudna' => '#f472b6',   
    'Rasplodna' => '#f472b6', 
    'Mlado' => '#3b82f6',    
    'Prodano' => '#64748b',
    'Krepano' => '#ef4444'
];

include 'header.php';
?>

<style>
    .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
    
    .quick-tabs-container {
        display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 20px;
        scrollbar-width: none; 
    }
    .quick-tabs-container::-webkit-scrollbar { display: none; }
    
    .q-tab {
        background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-secondary);
        padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s;
    }
    .q-tab.active { background: var(--accent-info); color: white; border-color: var(--accent-info); }
    .q-tab .badge { background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 10px; margin-left: 5px; font-size: 12px; }

    .view-toggles { display: flex; gap: 5px; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
    .v-btn { background: transparent; border: none; color: var(--text-muted); padding: 8px 12px; cursor: pointer; transition: 0.2s; }
    .v-btn.active { background: var(--accent-primary); color: white; }

    .herd-container { display: flex; flex-direction: column; gap: 10px; }

    /* DEFAULT/COMPACT LIST VIEW STYLES */
    .goat-card {
        background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md);
        display: flex; align-items: center; padding: 12px 15px; gap: 12px; position: relative; z-index: 1; transition: transform 0.2s ease-out;
        border-left-width: 5px; border-left-style: solid; 
    }
    .goat-card:hover { background: var(--bg-surface-hover); }
    
    .flag-btn { font-size: 18px; color: #475569; cursor: pointer; transition: 0.2s; background: transparent; border: none; padding: 5px; }
    .flag-btn.flagged { color: #eab308; text-shadow: 0 0 10px rgba(234, 179, 8, 0.5); }

    .herd-container.list-view .g-img-container { display: none; } 

    /* The clickable area wraps everything now */
    .g-info { flex-grow: 1; min-width: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; text-decoration: none; cursor: pointer; }
    .g-name { font-size: 16px; font-weight: bold; color: var(--text-primary); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-decoration: none; }
    
    .g-meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 0; }
    .g-meta-highlight { font-size: 13px; font-weight: 700; color: #f8fafc; display: flex; align-items: center; gap: 4px; background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px; text-decoration: none; }

    .card-actions { display: flex; flex-direction: row; align-items: center; gap: 10px; flex-shrink: 0; }

    .quick-status-wrapper { position: relative; display: inline-block; }
    .quick-status-badge {
        padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; color: white; cursor: pointer; display: flex; align-items: center; gap: 5px; border: 1px solid rgba(255,255,255,0.2); text-transform: capitalize;
    }
    .status-select {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;
    }

    /* GRID VIEW MODE (Photo Grid) OVERRIDES */
    .herd-container.grid-mode { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 15px; }
    .herd-container.grid-mode .goat-card { flex-direction: column; text-align: center; padding: 15px; border-left-width: 1px; border-top-width: 6px; border-top-style: solid; align-items: center; }
    
    .herd-container.grid-mode .g-img-container { display: block; width: 100%; margin-bottom: 10px; } 
    .g-img { width: 100%; height: 100px; border-radius: 8px; object-fit: cover; background: #0f111a; display: flex; align-items: center; justify-content: center; color: #475569; text-decoration: none; overflow: hidden; }
    
    .herd-container.grid-mode .g-info { flex-direction: column; justify-content: center; gap: 6px; }
    .herd-container.grid-mode .g-name { font-size: 16px; white-space: normal; }
    .herd-container.grid-mode .g-meta { justify-content: center; }
    .herd-container.grid-mode .batch-checkbox { position: absolute; top: 10px; left: 10px; }
    .herd-container.grid-mode .flag-btn { position: absolute; top: 5px; right: 5px; }
    .herd-container.grid-mode .card-actions { flex-direction: column; align-items: center; margin-top: 5px; }

    /* Traženje i Batch */
    .search-input { width: 100%; padding: 12px 15px; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-surface); color: white; font-size: 16px; margin-bottom: 15px; }
    
    #batch-actions { display: none; background: rgba(59, 130, 246, 0.1); border: 1px solid var(--accent-info); padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
</style>

<main class="content-area">
    <div class="action-bar">
        <h1 style="margin: 0;"><i class="fas fa-list" style="color: var(--accent-info); margin-right: 10px;"></i> Vaše Stado</h1>
        <a href="add_goat.php" class="btn btn-primary"><i class="fas fa-plus"></i> Dodaj Kozu</a>
    </div>

    <div class="quick-tabs-container" id="quick-tabs">
        <div class="q-tab active" data-filter="all">Sve <span class="badge"><?php echo $counts['Sve']; ?></span></div>
        <div class="q-tab" data-filter="flagged"><i class="fas fa-star text-warning"></i> Označene <span class="badge"><?php echo $counts['Flagged']; ?></span></div>
        <?php if($counts['Karenca'] > 0): ?>
            <div class="q-tab" data-filter="karenca" style="border-color: var(--accent-danger); color: var(--accent-danger);"><i class="fas fa-biohazard"></i> U Karenci <span class="badge"><?php echo $counts['Karenca']; ?></span></div>
        <?php endif; ?>
        <div class="q-tab" data-filter="Mlijecna">Mliječna <span class="badge"><?php echo $counts['Mlijecna']; ?></span></div>
        <div class="q-tab" data-filter="Trudna">Trudna <span class="badge"><?php echo $counts['Trudna']; ?></span></div>
        <div class="q-tab" data-filter="Mlado">Mlado <span class="badge"><?php echo $counts['Mlado']; ?></span></div>
        <div class="q-tab" data-filter="Suha">Suha <span class="badge"><?php echo $counts['Suha']; ?></span></div>
        
        <div class="q-tab" data-filter="arhiva" style="border-color: #64748b; color: #94a3b8;"><i class="fas fa-archive"></i> Arhiva <span class="badge" style="background: rgba(255,255,255,0.1);"><?php echo $counts['Arhiva']; ?></span></div>
    </div>

    <div style="display: flex; gap: 10px; margin-bottom: 15px; align-items: center;">
        <input type="text" id="search-input" class="search-input" placeholder="Pretraži po ID-u, Imenu ili Pasmini..." style="margin-bottom: 0;">
        <div class="view-toggles">
            <button class="v-btn active" id="btn-list" onclick="setView('list')" title="Prikaz Liste"><i class="fas fa-th-list"></i></button>
            <button class="v-btn" id="btn-grid" onclick="setView('grid')" title="Prikaz Slike"><i class="fas fa-border-all"></i></button>
        </div>
    </div>

    <form method="POST" action="batch_action.php" id="herd-form">
        <div id="batch-actions">
            <div><strong id="batch-count" style="color: var(--text-primary); font-size: 16px;">0</strong> <span style="color: var(--text-muted);">odabrano</span></div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="selectAllVisible()"><i class="fas fa-check-double"></i> Označi Sve Vidljive</button>
                <select name="batch_action_type" class="btn btn-secondary" style="background: var(--bg-surface); padding: 8px 12px; border-color: var(--accent-info); color: white;">
                    <option value="">-- Odaberi Akciju --</option>
                    <option value="vaccine">Cijepljenje / Liječenje</option>
                    <option value="weigh">Grupno Vaganje</option>
                    <option value="status">Promijeni Status</option>
                </select>
                <button type="submit" class="btn btn-primary" style="background: var(--accent-info);"><i class="fas fa-play"></i> Izvrši</button>
            </div>
        </div>

        <div class="herd-container list-view" id="herd-container">
            <?php foreach($goats_data as $g): 
                $statusColor = $g['archived'] == 1 ? '#475569' : ($status_colors[$g['status']] ?? '#64748b');
                $isFlagged = $g['flagged'] == 1;
                $inKarenca = $g['in_karenca'] > 0;
                $img_src = (!empty($g['image_path']) && file_exists("images/goats/" . $g['image_path'])) ? "images/goats/" . $g['image_path'] : null;
                $displayStatus = ($g['status'] == 'Rasplodna') ? 'Trudna' : $g['status']; 
                
                // Real ID representation for archived goats
                $display_id = $g['archived'] == 1 ? ($g['id'] - 900000) : $g['id'];
                $searchStr = strtolower($display_id . ' ' . $g['name'] . ' ' . $g['breed']);
            ?>
                <div class="herd-item" 
                     data-search="<?php echo htmlspecialchars($searchStr); ?>"
                     data-status="<?php echo $g['status']; ?>"
                     data-flagged="<?php echo $g['flagged']; ?>"
                     data-karenca="<?php echo $inKarenca ? '1' : '0'; ?>"
                     data-archived="<?php echo $g['archived']; ?>">

                    <div class="goat-card" style="border-color: <?php echo $statusColor; ?>; <?php if($g['archived'] == 1) echo 'opacity: 0.7;'; ?>" data-id="<?php echo $g['id']; ?>">
                        
                        <?php if($g['archived'] == 0): ?>
                            <input type="checkbox" name="selected_goats[]" value="<?php echo $g['id']; ?>" class="batch-checkbox" style="transform: scale(1.3); cursor: pointer;" onclick="updateBatchCount(event)">
                        <?php else: ?>
                            <div style="width: 20px;"></div> <?php endif; ?>

                        <div class="g-img-container">
                            <a href="profile.php?id=<?php echo $g['id']; ?>" class="g-img">
                                <?php if($img_src): ?>
                                    <img src="<?php echo $img_src; ?>" style="width:100%; height:100%; object-fit:cover; <?php if($g['archived'] == 1) echo 'filter: grayscale(100%);'; ?>">
                                <?php else: ?>
                                    <i class="fas fa-camera" style="font-size: 20px; opacity: 0.3;"></i>
                                <?php endif; ?>
                            </a>
                        </div>

                        <a href="profile.php?id=<?php echo $g['id']; ?>" class="g-info">
                            <h3 class="g-name">
                                #<?php echo $display_id; ?><?php echo !empty($g['name']) ? ' ' . htmlspecialchars($g['name']) : ''; ?>
                                <?php if($g['gender'] == 'Zensko') echo '<i class="fas fa-venus" style="color: pink; font-size:12px; margin-left:2px;"></i>'; else echo '<i class="fas fa-mars" style="color: #3b82f6; font-size:12px; margin-left:2px;"></i>'; ?>
                                <?php if($g['archived'] == 1) echo '<span style="font-size:10px; background:#475569; color:white; padding:2px 6px; border-radius:4px; vertical-align:middle; margin-left:6px;"><i class="fas fa-archive"></i> ARHIVA</span>'; ?>
                            </h3>
                            <div class="g-meta">
                                <span class="g-meta-highlight"><i class="fas fa-birthday-cake" style="color: var(--text-muted);"></i> <?php echo $g['age_str']; ?></span>
                                <span class="g-meta-highlight"><i class="fas fa-tag" style="color: var(--text-muted);"></i> <?php echo htmlspecialchars($g['breed'] ? $g['breed'] : 'Mix'); ?></span>
                                <?php if($inKarenca && $g['archived'] == 0): ?>
                                    <span style="color: var(--accent-danger); font-weight: bold; font-size: 12px; display:flex; align-items:center;"><i class="fas fa-biohazard" style="margin-right:2px;"></i> Karenca!</span>
                                <?php endif; ?>
                            </div>
                        </a>

                        <div class="card-actions">
                            <?php if($g['archived'] == 0): ?>
                                <button type="button" class="flag-btn <?php echo $isFlagged ? 'flagged' : ''; ?>" onclick="toggleFlag(<?php echo $g['id']; ?>, this)">
                                    <i class="fas fa-star"></i>
                                </button>

                                <div class="quick-status-wrapper">
                                    <div class="quick-status-badge" style="background-color: <?php echo $statusColor; ?>;" id="badge-<?php echo $g['id']; ?>">
                                        <?php echo $displayStatus; ?> <i class="fas fa-caret-down"></i>
                                    </div>
                                    <select class="status-select" onchange="quickEditStatus(<?php echo $g['id']; ?>, this)">
                                        <option value="" disabled selected></option>
                                        <option value="Mlijecna">Mliječna</option>
                                        <option value="Suha">Suha</option>
                                        <option value="Trudna">Trudna</option>
                                        <option value="Mlado">Mlado</option>
                                        <option value="Prodano">Prodano</option>
                                        <option value="Krepano">Krepano</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="quick-status-badge" style="background-color: #475569; cursor: default;">
                                    <?php echo $displayStatus; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
</main>

<script>
    // --- 1. SMART FILTERS & SEARCH ---
    const searchInput = document.getElementById('search-input');
    const tabs = document.querySelectorAll('.q-tab');
    const items = document.querySelectorAll('.herd-item');
    let currentFilter = 'all';

    function filterHerd() {
        const query = searchInput.value.toLowerCase();
        
        items.forEach(item => {
            const text = item.getAttribute('data-search');
            const status = item.getAttribute('data-status');
            const flagged = item.getAttribute('data-flagged');
            const karenca = item.getAttribute('data-karenca');
            const archived = item.getAttribute('data-archived');
            
            let showByTab = false;

            if (currentFilter === 'arhiva') {
                if (archived === '1') showByTab = true;
            } else {
                if (archived === '0') {
                    if (currentFilter === 'all') showByTab = true;
                    else if (currentFilter === 'flagged' && flagged === '1') showByTab = true;
                    else if (currentFilter === 'karenca' && karenca === '1') showByTab = true;
                    else if (currentFilter === 'Trudna' && (status === 'Trudna' || status === 'Rasplodna')) showByTab = true;
                    else if (status === currentFilter) showByTab = true;
                }
            }

            const showBySearch = text.includes(query);

            if (showByTab && showBySearch) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        updateBatchCount();
    }

    searchInput.addEventListener('input', filterHerd);

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter = tab.getAttribute('data-filter');
            filterHerd();
        });
    });

    // Pokreni filter na početku da sakrije arhivirane iz "Sve"
    filterHerd();

    // --- 2. BATCH CHECKBOX LOGIC ---
    function updateBatchCount(e) {
        if(e) e.stopPropagation();
        const checked = document.querySelectorAll('.batch-checkbox:checked').length;
        document.getElementById('batch-count').innerText = checked;
        document.getElementById('batch-actions').style.display = checked > 0 ? 'flex' : 'none';
    }

    function selectAllVisible() {
        let anyChanged = false;
        items.forEach(item => {
            if (item.style.display !== 'none') {
                let cb = item.querySelector('.batch-checkbox');
                if(cb && !cb.checked) { cb.checked = true; anyChanged = true; }
            }
        });
        if(!anyChanged) {
            items.forEach(item => {
                if (item.style.display !== 'none') {
                    let cb = item.querySelector('.batch-checkbox');
                    if(cb) cb.checked = false;
                }
            });
        }
        updateBatchCount();
    }

    // --- 3. PHOTO GRID TOGGLE ---
    function setView(mode) {
        document.getElementById('btn-list').classList.remove('active');
        document.getElementById('btn-grid').classList.remove('active');
        document.getElementById('btn-' + mode).classList.add('active');
        
        const container = document.getElementById('herd-container');
        if(mode === 'grid') {
            container.classList.remove('list-view');
            container.classList.add('grid-mode');
        } else {
            container.classList.remove('grid-mode');
            container.classList.add('list-view');
        }
    }

    // --- 4. AJAX: FLAG FOR REVIEW ---
    function toggleFlag(id, btn) {
        const isFlagged = btn.classList.contains('flagged');
        const newVal = isFlagged ? 0 : 1;
        
        fetch('herd.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=toggle_flag&goat_id=${id}&flag_val=${newVal}`
        }).then(res => res.json()).then(data => {
            if(data.success) {
                btn.classList.toggle('flagged');
                btn.closest('.herd-item').setAttribute('data-flagged', newVal);
            }
        });
    }

    // --- 5. AJAX: QUICK EDIT STATUS ---
    const colors = {'Mlijecna':'#10b981', 'Suha':'#f59e0b', 'Trudna':'#f472b6', 'Mlado':'#3b82f6', 'Prodano':'#64748b', 'Krepano':'#ef4444'};
    
    function quickEditStatus(id, selectEl) {
        const newStatus = selectEl.value;
        if(!newStatus) return;

        fetch('herd.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=quick_edit_status&goat_id=${id}&new_status=${newStatus}`
        }).then(res => res.json()).then(data => {
            if(data.success) {
                const badge = document.getElementById(`badge-${id}`);
                const card = selectEl.closest('.goat-card');
                const wrapper = selectEl.closest('.herd-item');
                
                badge.innerHTML = `${newStatus} <i class="fas fa-caret-down"></i>`;
                badge.style.backgroundColor = colors[newStatus] || '#64748b';
                card.style.borderColor = colors[newStatus] || '#64748b';
                
                wrapper.setAttribute('data-status', newStatus);
                selectEl.value = ""; 
            }
        });
    }
</script>

<?php include 'footer.php'; ?>
