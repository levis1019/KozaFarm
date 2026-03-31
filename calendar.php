<?php
require 'db.php';

// --- BRZI UNOS DOGAĐAJA S KALENDARA ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_calendar_event'])) {
    $goat_id = !empty($_POST['goat_id']) ? (int)$_POST['goat_id'] : 'NULL';
    $event_date = $conn->real_escape_string($_POST['event_date']);
    $event_type = $conn->real_escape_string($_POST['event_type']);
    $notes = $conn->real_escape_string($_POST['notes']);
    
    // Provjera ako je parenje, odmah računamo porod
    $next_appointment = "NULL";
    if ($event_type === 'Parenje') {
        $next_appointment = "'" . date('Y-m-d', strtotime($event_date . ' + 150 days')) . "'";
    }
    
    $sql = "INSERT INTO events (goat_id, event_date, event_type, notes, cost, withdrawal_days, next_appointment) 
            VALUES ($goat_id, '$event_date', '$event_type', '$notes', 0, 0, $next_appointment)";
            
    if ($conn->query($sql) === TRUE) {
        logAction($conn, "Brzi kalendarski unos: $event_type");
        header("Location: calendar.php?success=1");
        exit();
    }
}

// --- 1. DANAŠNJI ZADACI (TODAY's AGENDA) ---
$today_events = $conn->query("
    SELECT e.*, g.name as goat_name, 
           DATE_ADD(e.event_date, INTERVAL 90 DAY) as drying_date
    FROM events e 
    LEFT JOIN goats g ON e.goat_id = g.id 
    WHERE e.event_date = CURDATE() 
       OR e.next_appointment = CURDATE()
       OR (e.event_type = 'Parenje' AND DATE_ADD(e.event_date, INTERVAL 90 DAY) = CURDATE())
");

// --- 2. PRIKUPI PODATKE ZA FULLCALENDAR ---
$events_json = [];
$evQuery = $conn->query("SELECT e.*, g.name as goat_name FROM events e LEFT JOIN goats g ON e.goat_id = g.id");

while($row = $evQuery->fetch_assoc()) {
    $goatName = $row['goat_name'] ? $row['goat_name'] : 'Stado';
    
    // 1a. Zabilježi stvarni datum događaja (Plava boja)
    $events_json[] = [
        'title' => $row['event_type'] . ' (' . $goatName . ')',
        'start' => $row['event_date'],
        'color' => '#3b82f6', 
        'className' => 'evt-history',
        'description' => '<i class="fas fa-info-circle"></i> ' . htmlspecialchars($row['notes'])
    ];
    
    // 1b. Ako postoji idući termin (Narančasta)
    if (!empty($row['next_appointment'])) {
        $events_json[] = [
            'title' => '[TERMIN] ' . $row['event_type'] . ' (' . $goatName . ')',
            'start' => $row['next_appointment'],
            'color' => '#f59e0b', 
            'className' => 'evt-appointment',
            'description' => '<i class="fas fa-bell"></i> Planirani termin: ' . htmlspecialchars($row['notes'])
        ];
    }

    // 1c. Zalušenje - 90 dana nakon parenja (Ljubičasta)
    if ($row['event_type'] === 'Parenje') {
        $zalusenje_date = date('Y-m-d', strtotime($row['event_date'] . ' + 90 days'));
        $events_json[] = [
            'title' => '[ZALUŠENJE] ' . $goatName,
            'start' => $zalusenje_date,
            'color' => '#a78bfa', 
            'className' => 'evt-drying',
            'description' => '<i class="fas fa-tint-slash"></i> Prestanak muže (60 dana pred porod)'
        ];
    }
}

// --- 3. PREGNANCY DASHBOARD (Nadzor Trudnoće) ---
$pregnancy_query = $conn->query("
    SELECT e.goat_id, e.event_date as mating_date, e.next_appointment as kidding_date, g.name as goat_name,
           DATE_ADD(e.event_date, INTERVAL 90 DAY) as drying_date,
           DATEDIFF(e.next_appointment, CURDATE()) as days_to_kidding,
           DATEDIFF(DATE_ADD(e.event_date, INTERVAL 90 DAY), CURDATE()) as days_to_drying
    FROM events e
    LEFT JOIN goats g ON e.goat_id = g.id
    WHERE e.event_type = 'Parenje' AND e.next_appointment >= CURDATE()
    ORDER BY e.next_appointment ASC
");

// Sve koze za brzi unos
$all_goats = $conn->query("SELECT id, name FROM goats ORDER BY name ASC");

include 'header.php';
?>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.10/locales-all.global.min.js'></script>
<script src="https://unpkg.com/@popperjs/core@2"></script>
<script src="https://unpkg.com/tippy.js@6"></script>

<style id="calendar-filter-style"></style>

<style>
    /* MODAL */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 450px; }

    /* TODAY'S AGENDA WIDGET */
    .agenda-widget { background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(153, 27, 27, 0.05) 100%); border-left: 5px solid var(--accent-danger); padding: 20px; border-radius: var(--radius-lg); margin-bottom: 24px; }
    .agenda-item { display: flex; align-items: center; gap: 15px; background: rgba(0,0,0,0.2); padding: 12px 15px; border-radius: 8px; margin-bottom: 10px; border: 1px dashed var(--accent-danger); }
    .agenda-item:last-child { margin-bottom: 0; }
    
    /* FILTER LEGEND */
    .calendar-legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; }
    .legend-btn { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; cursor: pointer; transition: 0.2s; border: none; color: white; opacity: 1; }
    .legend-btn.inactive { opacity: 0.4; filter: grayscale(100%); }
    .l-history { background-color: #3b82f6; }
    .l-appt { background-color: #f59e0b; }
    .l-drying { background-color: #a78bfa; }

    /* CALENDAR OVERRIDES */
    #calendar { width: 100%; color: var(--text-primary); background: var(--bg-surface); padding: 20px; border-radius: 12px; box-sizing: border-box; }
    .fc-theme-standard th { border-color: var(--border-color) !important; background-color: var(--bg-surface-hover) !important; padding: 10px 0 !important; }
    .fc-theme-standard td, .fc-theme-standard .fc-scrollgrid { border-color: var(--border-color) !important; }
    .fc .fc-col-header-cell-cushion { color: var(--text-secondary) !important; font-size: 14px; font-weight: 600; text-transform: capitalize; text-decoration: none !important; }
    .fc .fc-daygrid-day-number { color: var(--text-primary) !important; text-decoration: none !important; font-weight: 500; padding: 8px !important; }
    .fc-day-today { background-color: rgba(99, 102, 241, 0.1) !important; }
    .fc .fc-button-primary { background-color: var(--accent-primary) !important; border: none !important; text-transform: capitalize; padding: 8px 16px !important; }
    .fc .fc-button-primary:not(:disabled):active, .fc .fc-button-primary:not(:disabled).fc-button-active { background-color: var(--accent-info) !important; }
    .fc-toolbar-title { text-transform: capitalize; color: var(--text-primary) !important; }
    .fc-event { cursor: pointer; transition: transform 0.1s; }
    .fc-event:hover { transform: scale(1.02); }

    /* PREGNANCY TABLE */
    .progress-wrapper { background: rgba(0,0,0,0.3); border-radius: 10px; height: 12px; width: 100%; overflow: hidden; margin-top: 5px; border: 1px solid var(--border-color); }
    .progress-fill { height: 100%; transition: width 0.4s ease; }

    @media (max-width: 768px) {
        .fc .fc-toolbar { flex-direction: column; gap: 10px; }
        #calendar { padding: 10px; }
        .fc-toolbar-title { font-size: 1.2em !important; }
        .herd-table thead { display: none; }
        .herd-table tbody tr { display: flex; flex-direction: column; margin-bottom: 15px; background: var(--bg-surface-hover); border-radius: 12px; padding: 15px; border: 1px solid var(--border-color); }
        .herd-table td { display: flex; justify-content: space-between; padding: 8px 0 !important; border-bottom: 1px dashed rgba(255,255,255,0.05); text-align: right; }
        .herd-table td::before { content: attr(data-label); font-weight: 600; color: var(--text-muted); text-align: left; margin-right: 15px; }
        .herd-table td:last-child { border-bottom: none; flex-direction: column; align-items: flex-start; text-align: left; }
        .herd-table td:last-child::before { margin-bottom: 5px; }
    }
</style>

<script>
    // FILTER LOGIKA ZA KALENDAR
    let activeFilters = { history: true, appt: true, drying: true };
    
    function toggleFilter(type, btnElement) {
        activeFilters[type] = !activeFilters[type];
        btnElement.classList.toggle('inactive');
        
        let css = '';
        if(!activeFilters.history) css += '.evt-history { display: none !important; } ';
        if(!activeFilters.appt) css += '.evt-appointment { display: none !important; } ';
        if(!activeFilters.drying) css += '.evt-drying { display: none !important; } ';
        
        document.getElementById('calendar-filter-style').innerHTML = css;
    }

    // INICIJALIZACIJA KALENDARA
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'hr',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek' },
            events: <?php echo json_encode($events_json); ?>,
            
            // SMART HOVER DETAILS (Tippy.js)
            eventDidMount: function(info) {
                tippy(info.el, {
                    content: `<div style='text-align:left; font-size:13px;'><strong style='font-size:15px;'>${info.event.title}</strong><hr style='border-color:rgba(255,255,255,0.2); margin:5px 0;'>${info.event.extendedProps.description}</div>`,
                    allowHTML: true,
                    theme: 'translucent',
                    placement: 'top',
                });
            },
            height: 'auto',
            contentHeight: 'auto'
        });
        calendar.render();

        const hamBtn = document.getElementById('hamburger-btn');
        if(hamBtn) { hamBtn.addEventListener('click', function() { setTimeout(() => { window.dispatchEvent(new Event('resize')); }, 300); }); }
    });

    // Modal Control
    function openEventModal() { document.getElementById('eventModal').style.display = 'flex'; }
    function closeEventModal() { document.getElementById('eventModal').style.display = 'none'; }
</script>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-calendar-alt" style="color: var(--accent-info); margin-right: 10px;"></i> Kalendar i Zadaci</h1>
        <button class="btn btn-primary" onclick="openEventModal()"><i class="fas fa-plus"></i> Zakaži Događaj</button>
    </header>

    <?php if(isset($_GET['success'])) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:12px;'><p style='color:var(--accent-success); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-check'></i> Događaj uspješno zabilježen!</p></div>"; ?>

    <?php 
    $today_count = 0;
    $today_html = "";
    if($today_events->num_rows > 0){
        $today = date('Y-m-d');
        while($te = $today_events->fetch_assoc()) {
            $gName = $te['goat_id'] ? "#{$te['goat_id']} " . htmlspecialchars($te['goat_name']) : "Cijelo stado";
            
            if($te['event_date'] == $today) {
                $today_count++;
                $today_html .= "<div class='agenda-item'><i class='fas fa-check-circle' style='color:#3b82f6; font-size:20px;'></i> <div><strong>{$te['event_type']}</strong> ($gName)<br><span style='font-size:12px; color:var(--text-secondary);'>Zabilježeno za danas</span></div></div>";
            }
            if($te['next_appointment'] == $today) {
                $today_count++;
                $today_html .= "<div class='agenda-item'><i class='fas fa-exclamation-triangle' style='color:#f59e0b; font-size:20px;'></i> <div><strong>Rok: {$te['event_type']}</strong> ($gName)<br><span style='font-size:12px; color:var(--text-secondary);'>Planirano: ".htmlspecialchars($te['notes'])."</span></div></div>";
            }
            if($te['event_type'] == 'Parenje' && $te['drying_date'] == $today) {
                $today_count++;
                $today_html .= "<div class='agenda-item'><i class='fas fa-tint-slash' style='color:#a78bfa; font-size:20px;'></i> <div><strong>Zalušenje - Prestanak muže</strong> ($gName)<br><span style='font-size:12px; color:var(--text-secondary);'>60 dana pred porod</span></div></div>";
            }
        }
    }
    
    if($today_count > 0): ?>
        <div class="agenda-widget">
            <h3 style="color: var(--accent-danger); margin: 0 0 15px 0;"><i class="fas fa-clipboard-list"></i> Vaši zadaci za danas!</h3>
            <?php echo $today_html; ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 30px; border-top: 4px solid #a78bfa;">
        <h3 style="color: #a78bfa; margin-bottom: 15px;"><i class="fas fa-baby-carriage"></i> Nadzor Trudnoće (Zalušenje i Porod)</h3>
        
        <?php if ($pregnancy_query->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="herd-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>Životinja</th>
                            <th>Parenje</th>
                            <th>Zalušenje (Suha)</th>
                            <th>Očekivani Porod</th>
                            <th style="width: 30%;">Napredak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($p = $pregnancy_query->fetch_assoc()): 
                            $goatDisplay = $p['goat_id'] ? "<a href='profile.php?id={$p['goat_id']}' style='color: var(--accent-info); text-decoration: none;'>#{$p['goat_id']} " . htmlspecialchars($p['goat_name']) . "</a>" : "Nepoznata";
                            
                            $daysPassed = 150 - $p['days_to_kidding'];
                            $percent = min(100, max(0, ($daysPassed / 150) * 100));
                            
                            $progColor = "#10b981"; 
                            if ($percent > 60) $progColor = "#f59e0b"; 
                            if ($percent > 90) $progColor = "#ef4444"; 
                            
                            $dryingDisplay = date("d.m.Y", strtotime($p['drying_date']));
                            if ($p['days_to_drying'] <= 7 && $p['days_to_drying'] >= 0) {
                                $dryingDisplay .= " <br><span style='color: var(--accent-warning); font-size: 12px; font-weight:bold;'><i class='fas fa-exclamation-circle'></i> Uskoro</span>";
                            } elseif ($p['days_to_drying'] < 0) {
                                $dryingDisplay .= " <br><span style='color: var(--accent-danger); font-size: 12px;'>(Zalušena)</span>";
                            }
                        ?>
                            <tr>
                                <td data-label="Životinja"><strong><?php echo $goatDisplay; ?></strong></td>
                                <td data-label="Parenje"><?php echo date("d.m.Y", strtotime($p['mating_date'])); ?></td>
                                <td data-label="Zalušenje"><?php echo $dryingDisplay; ?></td>
                                <td data-label="Porod" style="font-weight: bold; color: #a78bfa;">
                                    <?php echo date("d.m.Y", strtotime($p['kidding_date'])); ?> 
                                    <div style="font-size: 12px; color: <?php echo $progColor; ?>; margin-top:3px;">
                                        <?php echo $p['days_to_kidding'] <= 14 ? '<i class="fas fa-exclamation-triangle"></i> ' : ''; ?>
                                        (za <?php echo $p['days_to_kidding']; ?> dana)
                                    </div>
                                </td>
                                <td data-label="Napredak">
                                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 2px;">
                                        <span>Dan <?php echo $daysPassed; ?></span>
                                        <span style="color: <?php echo $progColor; ?>; font-weight:bold;"><?php echo round($percent); ?>%</span>
                                    </div>
                                    <div class="progress-wrapper">
                                        <div class="progress-fill" style="width: <?php echo $percent; ?>%; background-color: <?php echo $progColor; ?>;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 30px 0; color: var(--text-muted);">
                <i class="fas fa-venus-mars" style="font-size: 30px; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>Trenutno nema prijavljenih parenja.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding: 20px;">
        <div class="calendar-legend">
            <button class="legend-btn l-history" onclick="toggleFilter('history', this)"><i class="fas fa-check"></i> Povijest</button>
            <button class="legend-btn l-appt" onclick="toggleFilter('appt', this)"><i class="fas fa-bell"></i> Termini</button>
            <button class="legend-btn l-drying" onclick="toggleFilter('drying', this)"><i class="fas fa-tint-slash"></i> Zalušenje</button>
        </div>
        <div id='calendar'></div>
    </div>
</main>

<div class="modal-overlay" id="eventModal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-plus-circle"></i> Zakaži Događaj</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Datum</label>
                <input type="date" name="event_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Vrsta</label>
                <select name="event_type" required style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                    <option value="Cijepljenje">Cijepljenje</option>
                    <option value="Dehelmintizacija">Čišćenje parazita</option>
                    <option value="Papci">Rezanje papaka</option>
                    <option value="Parenje">Parenje</option>
                    <option value="Porod">Porod</option>
                    <option value="Bolest">Bolest / Liječenje</option>
                    <option value="Ostalo">Ostalo</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Životinja</label>
                <select name="goat_id" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                    <option value="">-- Cijelo stado --</option>
                    <?php 
                    if ($all_goats->num_rows > 0) {
                        while($g = $all_goats->fetch_assoc()) {
                            echo "<option value='{$g['id']}'>#{$g['id']} - " . htmlspecialchars($g['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label>Napomena / Plan</label>
                <input type="text" name="notes" placeholder="Detalji..." required style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
            </div>
            
            <input type="hidden" name="add_calendar_event" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeEventModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Zakaži</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
