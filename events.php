
<?php
require 'db.php';

$success_msg = "";
$error_msg = "";

// --- BRISANJE DOGAĐAJA ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($conn->query("DELETE FROM events WHERE id=$del_id") === TRUE) {
        logAction($conn, "Obrisan događaj iz povijesti (ID: $del_id)");
        header("Location: events.php?success=2");
        exit();
    } else {
        $error_msg = "Greška pri brisanju: " . $conn->error;
    }
}

// --- OBRADA FORME: SPREMANJE DOGAĐAJA (S GRUPNIM UNOSOM I JARIĆIMA) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_event'])) {
    $event_date = $conn->real_escape_string($_POST['event_date']);
    $event_type = $conn->real_escape_string($_POST['event_type']);
    $notes = $conn->real_escape_string($_POST['notes']);
    $medication = $conn->real_escape_string($_POST['medication']);
    $cost = (float)$_POST['cost'];
    $withdrawal_days = isset($_POST['withdrawal_days']) ? (int)$_POST['withdrawal_days'] : 0;
    
    // Računanje idućeg termina
    if (empty($_POST['next_appointment'])) {
        if ($event_type === 'Parenje') {
            $expected_birth_date = date('Y-m-d', strtotime($event_date . ' + 150 days'));
            $next_appointment = "'" . $expected_birth_date . "'";
            $notes .= " [Prognoza poroda: " . date('d.m.Y', strtotime($expected_birth_date)) . "]";
        } else {
            $next_appointment = "NULL";
        }
    } else {
        $next_appointment = "'" . $conn->real_escape_string($_POST['next_appointment']) . "'";
    }

    // Odabrane koze (Grupni unos)
    $goat_ids = isset($_POST['goat_ids']) ? $_POST['goat_ids'] : [];
    
    // Ako nije odabrana nijedna koza, znači da je za cijelo stado
    if (empty($goat_ids)) {
        $goat_ids = ['NULL'];
    }

    $inserted_count = 0;
    
    foreach ($goat_ids as $gid) {
        $safe_gid = ($gid === 'NULL') ? 'NULL' : (int)$gid;
        
        $sql = "INSERT INTO events (goat_id, event_date, event_type, notes, medication, cost, next_appointment, withdrawal_days) 
                VALUES ($safe_gid, '$event_date', '$event_type', '$notes', '$medication', " . ($inserted_count == 0 ? $cost : 0) . ", $next_appointment, $withdrawal_days)";
                // Napomena: Trošak bilježimo samo na prvu kozu u petlji da se ne bi duplicirao ukupan iznos
        
        if ($conn->query($sql) === TRUE) {
            $inserted_count++;
            
            // PAMETNI STATUS AUTO-UPDATE & JARIĆI
            if ($safe_gid !== 'NULL') {
                if ($event_type === 'Porod') {
                    // Update u Mliječna
                    $conn->query("UPDATE goats SET status = 'Mlijecna' WHERE id = $safe_gid");
                    
                    // BRZI UNOS JARIĆA
                    $kids_f = isset($_POST['kids_f']) ? (int)$_POST['kids_f'] : 0;
                    $kids_m = isset($_POST['kids_m']) ? (int)$_POST['kids_m'] : 0;
                    
                    // Dodaj ženske jariće
                    for ($i=0; $i<$kids_f; $i++) {
                        $conn->query("INSERT INTO goats (gender, birth_date, status, mother_id, notes) VALUES ('Zensko', '$event_date', 'Mlado', $safe_gid, 'Automatski dodano iz događaja poroda')");
                    }
                    // Dodaj muške jariće
                    for ($i=0; $i<$kids_m; $i++) {
                        $conn->query("INSERT INTO goats (gender, birth_date, status, mother_id, notes) VALUES ('Musko', '$event_date', 'Mlado', $safe_gid, 'Automatski dodano iz događaja poroda')");
                    }
                }
                elseif ($event_type === 'Parenje') {
                    // Update u Trudna
                    $conn->query("UPDATE goats SET status = 'Trudna' WHERE id = $safe_gid");
                }
            }
        }
    }
    
    // AUTO-LINK U FINANCIJE
    if ($cost > 0 && isset($_POST['log_to_finance']) && $_POST['log_to_finance'] == '1') {
        $desc = "Trošak iz Događaja: $event_type. Napomena: $notes";
        $cat = ($event_type == 'Cijepljenje' || $event_type == 'Dehelmintizacija' || $event_type == 'Bolest') ? 'Veterinar / Lijekovi' : 'Ostalo -';
        $conn->query("INSERT INTO finances (transaction_date, transaction_type, category, amount, payment_method, description) 
                      VALUES ('$event_date', 'expense', '$cat', $cost, 'Gotovina', '$desc')");
    }

    logAction($conn, "Zabilježen događaj: $event_type za $inserted_count koza.");
    header("Location: events.php?success=1");
    exit();
}

if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) $success_msg = "Događaj je uspješno zabilježen!";
    if ($_GET['success'] == 2) $success_msg = "Događaj je uspješno obrisan!";
}

// --- UPITI ZA PRIKAZ ---
$all_goats = $conn->query("SELECT id, name FROM goats ORDER BY id ASC");

// 1. KARENCA RED ZONE
$quarantine_query = $conn->query("
    SELECT e.goat_id, e.event_date, e.withdrawal_days, e.medication, g.name as goat_name,
           DATEDIFF(DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY), CURDATE()) as days_left
    FROM events e
    JOIN goats g ON e.goat_id = g.id
    WHERE e.withdrawal_days > 0 
    AND DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY) >= CURDATE()
    ORDER BY days_left ASC
");

// 2. TO-DO ZADACI
$upcoming_tasks = $conn->query("
    SELECT e.*, g.name as goat_name 
    FROM events e 
    LEFT JOIN goats g ON e.goat_id = g.id 
    WHERE e.next_appointment >= CURDATE() 
    ORDER BY e.next_appointment ASC
");

// 3. POVIJEST
$event_history = $conn->query("
    SELECT e.*, g.name as goat_name 
    FROM events e 
    LEFT JOIN goats g ON e.goat_id = g.id 
    ORDER BY e.event_date DESC, e.id DESC
");

include 'header.php';
?>

<style>
    /* ACTIVE QUARANTINE ZONE */
    .quarantine-zone { background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(153, 27, 27, 0.1) 100%); border: 1px solid var(--accent-danger); border-left: 6px solid var(--accent-danger); padding: 20px; border-radius: var(--radius-lg); margin-bottom: 24px; }
    .q-item { background: rgba(0,0,0,0.3); padding: 10px 15px; border-radius: var(--radius-md); display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border: 1px dashed var(--accent-danger); }
    .q-item:last-child { margin-bottom: 0; }
    
    /* BATCH SELECTOR */
    .multi-select-box { max-height: 200px; overflow-y: auto; background: var(--bg-surface-hover); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 10px; }
    .ms-item { display: flex; align-items: center; padding: 8px; border-bottom: 1px dashed rgba(255,255,255,0.05); cursor: pointer; transition: 0.2s; border-radius: 6px; }
    .ms-item:hover { background: rgba(59, 130, 246, 0.1); }
    .ms-item input { transform: scale(1.3); margin-right: 15px; accent-color: var(--accent-info); cursor: pointer; }
    
    /* QUICK FILTERS */
    .quick-filters { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 15px; scrollbar-width: none; }
    .quick-filters::-webkit-scrollbar { display: none; }
    .qf-btn { background: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-secondary); padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s; }
    .qf-btn.active { background: var(--accent-info); color: white; border-color: var(--accent-info); }

    /* RESPONSIVE TABLE */
    @media (max-width: 768px) {
        .herd-table thead { display: none; }
        .herd-table tbody tr { display: flex; flex-direction: column; margin-bottom: 20px; background: linear-gradient(145deg, var(--bg-surface-hover), var(--bg-surface)); border-radius: 16px; padding: 15px; border: 1px solid var(--border-color); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .herd-table td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed rgba(255,255,255,0.05); text-align: right; font-size: 15px !important; }
        .herd-table td::before { content: attr(data-label); font-weight: 600; color: var(--text-muted); text-align: left; margin-right: 15px; }
        .herd-table td:last-child { border-bottom: none; justify-content: stretch; margin-top: 10px; padding-bottom: 0 !important; }
        .herd-table td:last-child::before { display: none; }
        .herd-table td:last-child .btn { flex: 1; text-align: center; padding: 12px; font-size: 16px; justify-content: center; }
    }
</style>

    <main class="content-area">
        <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <h1 style="margin: 0;"><i class="fas fa-notes-medical" style="color: var(--accent-info); margin-right: 10px;"></i> Događaji i Zdravlje</h1>
            <button class="btn btn-primary" onclick="toggleAddForm()"><i class="fas fa-plus"></i> Novi Događaj</button>
        </header>

        <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); margin-bottom: 24px;'><p style='color:var(--accent-success); font-weight:bold; text-align:center; margin:0;'><i class='fas fa-check-circle'></i> $success_msg</p></div>"; ?>
        <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); margin-bottom: 24px;'><p style='color:var(--accent-danger); font-weight:bold; text-align:center; margin:0;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

        <?php if ($quarantine_query->num_rows > 0): ?>
            <div class="quarantine-zone">
                <h3 style="color: var(--accent-danger); margin: 0 0 15px 0;"><i class="fas fa-biohazard"></i> PAŽNJA: Aktivne karence!</h3>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 15px;">Mlijeko ili meso ovih koza trenutno nije za upotrebu.</p>
                <div>
                    <?php while($q = $quarantine_query->fetch_assoc()): ?>
                        <div class="q-item">
                            <div>
                                <a href="profile.php?id=<?php echo $q['goat_id']; ?>" style="color: white; font-weight: bold; text-decoration: none; font-size: 16px;">#<?php echo $q['goat_id']; ?> <?php echo htmlspecialchars($q['goat_name']); ?></a>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Terapija: <?php echo htmlspecialchars($q['medication']); ?></div>
                            </div>
                            <div style="background: var(--accent-danger); color: white; padding: 6px 12px; border-radius: 20px; font-weight: bold; font-size: 14px;">
                                <i class="fas fa-clock"></i> Još <?php echo $q['days_left']; ?> dana
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>

        <div id="add-form-container" class="card" style="display: none; margin-bottom: 24px; border-top: 4px solid var(--accent-info);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="color: var(--text-primary); margin: 0;">Zabilježi Događaj / Terapiju</h3>
                <button type="button" class="btn btn-secondary" onclick="toggleAddForm()" style="padding: 8px 12px;"><i class="fas fa-times"></i></button>
            </div>
            
            <form method="POST" action="events.php">
                <div class="form-grid">
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Odaberi koze (Ostavite prazno za cijelo stado)</label>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <button type="button" class="btn btn-secondary" onclick="selectAllGoats(true)" style="font-size:12px; padding:5px 10px;">Označi sve</button>
                            <button type="button" class="btn btn-secondary" onclick="selectAllGoats(false)" style="font-size:12px; padding:5px 10px;">Odznači sve</button>
                        </div>
                        <div class="multi-select-box" id="goatCheckboxes">
                            <?php 
                            if ($all_goats->num_rows > 0) {
                                while($g = $all_goats->fetch_assoc()) {
                                    $name = $g['name'] ? htmlspecialchars($g['name']) : 'Bez imena';
                                    echo "<label class='ms-item'><input type='checkbox' name='goat_ids[]' value='{$g['id']}'> #{$g['id']} - {$name}</label>";
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Datum Događaja</label>
                        <input type="date" name="event_date" required value="<?php echo date('Y-m-d'); ?>" style="padding: 14px;">
                    </div>
                    
                    <div class="form-group">
                        <label>Vrsta Događaja</label>
                        <select name="event_type" id="event_type" required style="padding: 14px;" onchange="handleEventTypeChange()">
                            <option value="">-- Odaberi --</option>
                            <option value="Porod">Porod (Automatski stvara jariće!)</option>
                            <option value="Parenje">Parenje</option>
                            <option value="Cijepljenje">Cijepljenje</option>
                            <option value="Dehelmintizacija">Čišćenje parazita</option>
                            <option value="Papci">Rezanje papaka</option>
                            <option value="Bolest">Bolest / Liječenje</option>
                            <option value="Vaganje">Vaganje</option>
                            <option value="Ostalo">Ostalo</option>
                        </select>
                    </div>

                    <div id="newborn_section" class="form-group" style="display:none; grid-column: 1 / -1; background: rgba(16, 185, 129, 0.1); border: 1px dashed #10b981; padding: 15px; border-radius: var(--radius-md);">
                        <label style="color: #10b981; font-weight:bold;"><i class="fas fa-baby"></i> Dodavanje Jarića u Stado</label>
                        <p style="font-size:12px; color:var(--text-muted); margin-bottom:10px;">Aplikacija će automatski napraviti nove profile za ove jariće i povezati ih s majkom.</p>
                        <div style="display:flex; gap:15px;">
                            <div style="flex:1;">
                                <label style="font-size:12px;">Broj <span style="color:#3b82f6;">Muških</span> Jarića</label>
                                <input type="number" min="0" value="0" name="kids_m" style="padding:10px;">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:12px;">Broj <span style="color:pink;">Ženskih</span> Jarića</label>
                                <input type="number" min="0" value="0" name="kids_f" style="padding:10px;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Lijek / Terapija (Opcionalno)</label>
                        <input type="text" name="medication" placeholder="npr. Penicilin 5ml" style="padding: 14px;">
                    </div>
                    <div class="form-group">
                        <label>Karenca <i class="fas fa-biohazard" style="color:var(--accent-danger);"></i></label>
                        <input type="number" min="0" name="withdrawal_days" value="0" placeholder="Broj dana" style="padding: 14px;">
                    </div>
                    
                    <div class="form-group">
                        <label>Ukupan Trošak (BAM)</label>
                        <input type="number" step="0.01" min="0" name="cost" value="0" style="padding: 14px;">
                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 10px; cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                            <input type="checkbox" name="log_to_finance" value="1" style="transform: scale(1.2);"> Spremi ovaj iznos u Financije
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Idući Termin / Podsjetnik</label>
                        <input type="date" name="next_appointment" style="padding: 14px;">
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Napomena / Opis</label>
                        <input type="text" name="notes" placeholder="Detalji događaja..." required style="padding: 16px;">
                    </div>
                </div>
                <input type="hidden" name="add_event" value="1"> 
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 16px; font-weight: bold; margin-top: 10px;">
                    <i class="fas fa-save"></i> Spremi Događaj
                </button>
            </form>
        </div>

        <div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--accent-warning);">
            <h3 style="color: var(--accent-warning); margin-bottom: 15px;"><i class="fas fa-bell"></i> To-Do / Idući Termini</h3>
            <?php if ($upcoming_tasks->num_rows > 0): ?>
                <ul style="list-style: none; padding: 0;">
                    <?php while($task = $upcoming_tasks->fetch_assoc()): 
                        $goatDisp = $task['goat_id'] ? "#{$task['goat_id']} " . htmlspecialchars($task['goat_name']) : "Cijelo stado";
                        $taskDate = date("d.m.Y", strtotime($task['next_appointment']));
                    ?>
                        <li style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: var(--radius-md); margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: var(--text-primary); font-size: 15px;"><?php echo htmlspecialchars($task['event_type']); ?></strong> 
                                <span style="color: var(--accent-info); font-weight: bold; margin-left: 5px;"><?php echo $goatDisp; ?></span>
                                <div style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($task['notes']); ?></div>
                            </div>
                            <div style="display:flex; align-items:center; gap:15px;">
                                <div style="background-color: rgba(245, 158, 11, 0.15); border: 1px solid var(--accent-warning); color: var(--accent-warning); font-weight: bold; padding: 6px 12px; border-radius: 12px; font-size: 13px;"><i class="fas fa-calendar"></i> <?php echo $taskDate; ?></div>
                                <a href="events.php?delete=<?php echo $task['id']; ?>" onclick="return confirm('Obrisati ovaj zadatak?');" style="color: var(--accent-danger); font-size: 18px;" title="Obriši"><i class="fas fa-times-circle"></i></a>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p style="color: var(--text-muted);"><i class="fas fa-check"></i> Nemate nadolazećih zadataka ni zakazanih termina.</p>
            <?php endif; ?>
        </div>

        <h3 style="color: var(--text-secondary); margin-bottom: 15px;"><i class="fas fa-history"></i> Povijest Zdravlja i Događaja</h3>
        
        <div class="quick-filters" id="quickFilters">
            <button class="qf-btn active" onclick="filterTable('Sve', this)">Sve</button>
            <button class="qf-btn" onclick="filterTable('Porod', this)">Porod</button>
            <button class="qf-btn" onclick="filterTable('Bolest', this)">Bolest/Terapija</button>
            <button class="qf-btn" onclick="filterTable('Cijepljenje', this)">Cijepljenje</button>
            <button class="qf-btn" onclick="filterTable('Parenje', this)">Parenje</button>
        </div>

        <div class="card table-container" style="padding: 0; background: transparent; border: none; box-shadow: none;">
            <table class="herd-table" style="width: 100%; border-collapse: separate; border-spacing: 0;">
                <thead style="background: var(--bg-surface-hover);">
                    <tr>
                        <th style="padding: 15px;">Datum</th>
                        <th>Koza</th>
                        <th>Događaj</th>
                        <th>Terapija & Karenca</th>
                        <th>Napomena</th>
                        <th style="text-align: right; padding: 15px;">Akcija</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <?php 
                    if ($event_history->num_rows > 0) {
                        while($row = $event_history->fetch_assoc()) {
                            $dateStr = date("d.m.Y", strtotime($row['event_date']));
                            $goatDisplay = $row['goat_id'] ? "<a href='profile.php?id={$row['goat_id']}' style='color: var(--accent-info); text-decoration: none; font-weight:bold;'>#{$row['goat_id']} " . htmlspecialchars($row['goat_name']) . "</a>" : "<span style='color: var(--text-muted); font-weight:bold;'>Cijelo stado</span>";
                            
                            $medDisplay = $row['medication'] ? htmlspecialchars($row['medication']) : '-';
                            if ($row['withdrawal_days'] > 0) {
                                $wEnd = date('d.m.Y', strtotime($row['event_date'] . " + {$row['withdrawal_days']} days"));
                                $medDisplay .= "<br><span style='color:var(--accent-danger); font-size:12px; font-weight:bold;'><i class='fas fa-biohazard'></i> Karenca do $wEnd</span>";
                            }
                            
                            $eventType = $row['event_type'];
                            $typeColor = "var(--text-primary)";
                            if($eventType == 'Porod') $typeColor = "var(--accent-success)";
                            if($eventType == 'Bolest') $typeColor = "var(--accent-danger)";
                            if($eventType == 'Parenje') $typeColor = "#a78bfa";
                            if($eventType == 'Cijepljenje' || $eventType == 'Dehelmintizacija') $typeColor = "var(--accent-info)";

                            // Filter klase
                            $filterClass = "tr-".strtolower(str_replace([' ', '/'], '', $eventType));

                            echo "<tr class='h-row $filterClass' data-type='{$eventType}' style='background: var(--bg-surface);'>
                                <td data-label='Datum' style='padding: 15px;'><strong>{$dateStr}</strong></td>
                                <td data-label='Koza'>{$goatDisplay}</td>
                                <td data-label='Događaj' style='color: {$typeColor}; font-weight: bold;'>{$eventType}</td>
                                <td data-label='Terapija'>{$medDisplay}</td>
                                <td data-label='Napomena' style='color: var(--text-secondary); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='" . htmlspecialchars($row['notes']) . "'>" . htmlspecialchars($row['notes']) . "</td>
                                <td style='text-align: right; padding: 15px;'>
                                    <a href='events.php?delete={$row['id']}' onclick=\"return confirm('Obrisati ovaj događaj iz povijesti?');\" class='btn btn-secondary' style='color:var(--accent-danger); border-color:rgba(239, 68, 68, 0.3); padding: 8px 12px; width: 100%; box-sizing: border-box;'><i class='fas fa-trash'></i></a>
                                </td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' style='text-align:center; padding: 30px; color: var(--text-muted); background: var(--bg-surface); border-radius: 12px;'>Nema zabilježenih događaja.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        function toggleAddForm() {
            var form = document.getElementById("add-form-container");
            form.style.display = form.style.display === "none" ? "block" : "none";
        }

        function selectAllGoats(check) {
            const boxes = document.querySelectorAll('#goatCheckboxes input[type="checkbox"]');
            boxes.forEach(box => box.checked = check);
        }

        function handleEventTypeChange() {
            const type = document.getElementById('event_type').value;
            const newbornSection = document.getElementById('newborn_section');
            if(type === 'Porod') {
                newbornSection.style.display = 'block';
            } else {
                newbornSection.style.display = 'none';
            }
        }

        function filterTable(type, btn) {
            // Update buttons
            document.querySelectorAll('.qf-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Filter rows
            const rows = document.querySelectorAll('.h-row');
            rows.forEach(row => {
                if(type === 'Sve' || row.getAttribute('data-type') === type) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
<?php include 'footer.php'; ?>
