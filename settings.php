<?php
require 'db.php';

$msg = "";
$error = "";

$current_user_id = $_SESSION['user_id'] ?? 0;

// OBRADA: Promjena vlastite lozinke
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_pass'])) {
    $new_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    if ($conn->query("UPDATE users SET password='$new_pass' WHERE id=$current_user_id") === TRUE) {
        logAction($conn, "Korisnik je promijenio vlastitu lozinku.");
        $msg = "Vaša lozinka je uspješno promijenjena!";
    }
}

// OBRADA: Kreiranje novog korisnika
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $new_username = $conn->real_escape_string(trim($_POST['new_username']));
    $new_user_pass = password_hash($_POST['new_user_password'], PASSWORD_DEFAULT);
    $new_role = $conn->real_escape_string($_POST['new_user_role']);

    $check = $conn->query("SELECT id FROM users WHERE username='$new_username'");
    if ($check->num_rows > 0) {
        $error = "Korisnik pod imenom '$new_username' već postoji!";
    } else {
        $sql = "INSERT INTO users (username, password, role) VALUES ('$new_username', '$new_user_pass', '$new_role')";
        if ($conn->query($sql) === TRUE) {
            logAction($conn, "Kreiran novi korisnik: $new_username ($new_role)");
            $msg = "Novi korisnik ($new_username) je uspješno kreiran!";
        } else {
            $error = "Greška u bazi: " . $conn->error;
        }
    }
}

// OBRADA: Brisanje korisnika
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user_id'])) {
    $del_id = (int)$_POST['delete_user_id'];
    if ($del_id === $current_user_id) {
        $error = "Ne možete obrisati vlastiti račun!";
    } else {
        if ($conn->query("DELETE FROM users WHERE id=$del_id") === TRUE) {
            logAction($conn, "Obrisan korisnički račun (ID: $del_id)");
            $msg = "Korisnik je uspješno obrisan.";
        }
    }
}

// OBRADA: Promjena uloge (Admin / Radnik)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_role_id'])) {
    $target_id = (int)$_POST['toggle_role_id'];
    if ($target_id === $current_user_id) {
        $error = "Ne možete mijenjati vlastitu ulogu!";
    } else {
        $conn->query("UPDATE users SET role = IF(role='admin', 'worker', 'admin') WHERE id=$target_id");
        logAction($conn, "Promijenjena uloga korisniku (ID: $target_id)");
        $msg = "Uloga korisnika je uspješno promijenjena.";
    }
}

// OBRADA: Dodavanje Todo
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_todo'])) {
    $task = $conn->real_escape_string(trim($_POST['todo_text']));
    $priority = $conn->real_escape_string($_POST['priority']);
    if (!empty($task)) {
        $conn->query("INSERT INTO system_todos (task, priority) VALUES ('$task', '$priority')");
        logAction($conn, "Dodan novi To-Do zadatak (Prioritet: $priority)."); 
        $msg = "Zadatak dodan na To-Do listu!";
    }
}

// OBRADA: Brisanje Todo
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_todo_id'])) {
    $td_id = (int)$_POST['delete_todo_id'];
    $conn->query("DELETE FROM system_todos WHERE id=$td_id");
    logAction($conn, "Obrisan To-Do zadatak (ID: $td_id)."); 
    $msg = "Zadatak obrisan!";
}

// PRIKUPLJANJE PODATAKA
$users_list = $conn->query("SELECT id, username, role FROM users ORDER BY id ASC");
$logs_query = $conn->query("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 150");
$todos_query = $conn->query("SELECT * FROM system_todos ORDER BY FIELD(priority, 'high', 'medium', 'low'), created_at DESC");

// Priprema jedinstvenih korisnika za filter dnevnika
$log_users = [];
$logs_data = [];
if ($logs_query->num_rows > 0) {
    while($log = $logs_query->fetch_assoc()) {
        $logs_data[] = $log;
        $uname = $log['username'] ?? 'Sustav';
        if (!in_array($uname, $log_users)) {
            $log_users[] = $uname;
        }
    }
}

include 'header.php';
?>

<style>
    /* MODAL STYLES */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; position: relative; max-height: 90vh; overflow-y: auto; }
    
    /* TODO LIST STYLES */
    .todo-item { padding: 12px 15px; background: var(--bg-surface-hover); border: 1px solid var(--border-color); margin-bottom: 10px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; gap: 15px; border-left: 4px solid var(--border-color); transition: 0.2s; }
    .todo-item:hover { background: rgba(255,255,255,0.05); }
    .todo-high { border-left-color: var(--accent-danger); }
    .todo-medium { border-left-color: var(--accent-warning); }
    .todo-low { border-left-color: var(--accent-info); }
    .badge-priority { font-size: 10px; font-weight: bold; padding: 3px 8px; border-radius: 12px; text-transform: uppercase; color: white; margin-bottom: 5px; display: inline-block; }
    .bg-high { background-color: var(--accent-danger); }
    .bg-medium { background-color: var(--accent-warning); color: #000; }
    .bg-low { background-color: var(--accent-info); }

    /* LOG FILTERS */
    .log-filters { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; background: rgba(0,0,0,0.2); padding: 10px; border-radius: var(--radius-md); border: 1px solid var(--border-color); }
    .log-input { padding: 10px 15px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-primary); font-size: 14px; flex-grow: 1; }

    /* SETTINGS CARDS */
    .settings-card { border-top-width: 4px; border-top-style: solid; margin-bottom: 20px; }
    .sc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
</style>

<main class="content-area">
    <header style="margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-cog" style="color: var(--text-muted); margin-right: 10px;"></i> Postavke Sustava</h1>
    </header>

    <?php if($msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:12px;'><p style='color:var(--accent-success); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-check'></i> $msg</p></div>"; ?>
    <?php if($error) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); padding:12px;'><p style='color:var(--accent-danger); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-exclamation-triangle'></i> $error</p></div>"; ?>

    <div class="dashboard-grid">
        
        <div style="display: flex; flex-direction: column; gap: 20px;">
            
            <div class="card settings-card" style="border-color: var(--accent-info);">
                <div class="sc-header">
                    <h3 style="color: var(--text-primary); margin:0;"><i class="fas fa-user-circle"></i> Vaš Profil</h3>
                </div>
                <form method="POST">
                    <label style="font-size:13px; color:var(--text-muted); margin-bottom:8px; display:block;">Prijavljeni ste kao: <strong style="color:var(--accent-info)"><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User'; ?></strong></label>
                    <div style="display:flex; gap:10px;">
                        <input type="password" name="new_password" required placeholder="Nova lozinka..." style="flex-grow:1; padding:12px; border-radius:6px; border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                        <input type="hidden" name="change_pass" value="1">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-key"></i> Promijeni</button>
                    </div>
                </form>
            </div>

            <div class="card settings-card" style="border-color: var(--accent-danger);">
                <div class="sc-header">
                    <h3 style="color: var(--text-primary); margin:0;"><i class="fas fa-tools"></i> Napredni Alati</h3>
                </div>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">Uređivač baze služi isključivo za hitne ispravke kvara (npr. pogrešno knjiženje). Ne koristi automatsku logiku štale.</p>
                <a href="edit.php" class="btn btn-primary" style="width: 100%; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; padding: 15px; font-size: 16px; justify-content: center;">
                    <i class="fas fa-database"></i> Otvori Database-Editor
                </a>
            </div>

            <div class="card settings-card" style="border-color: var(--accent-warning);">
                <div class="sc-header">
                    <h3 style="color: var(--text-primary); margin:0;"><i class="fas fa-users-cog"></i> Korisnici</h3>
                    <button class="btn btn-primary btn-sm" onclick="openModal('modal-new-user')"><i class="fas fa-plus"></i> Novi Korisnik</button>
                </div>
                
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php while($u = $users_list->fetch_assoc()): 
                        $isMe = ($u['id'] == $current_user_id);
                        $isAdmin = ($u['role'] == 'admin');
                    ?>
                        <li style="padding: 12px; background: var(--bg-surface-hover); border: 1px solid var(--border-color); margin-bottom: 8px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-user" style="color: <?php echo $isAdmin ? 'var(--accent-primary)' : 'var(--text-muted)'; ?>; font-size:16px;"></i>
                                <strong style="font-size: 15px; color: var(--text-primary);"><?php echo htmlspecialchars($u['username']); ?></strong>
                                <?php if($isAdmin): ?>
                                    <span style="background-color: rgba(99, 102, 241, 0.2); color: var(--accent-primary); padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold;">ADMIN</span>
                                <?php else: ?>
                                    <span style="background-color: rgba(245, 158, 11, 0.2); color: var(--accent-warning); padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold;">RADNIK</span>
                                <?php endif; ?>
                            </div>

                            <?php if(!$isMe): ?>
                                <div style="display: flex; gap: 8px;">
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="toggle_role_id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" title="Promijeni ulogu"><i class="fas fa-exchange-alt"></i></button>
                                    </form>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Sigurno želite obrisati korisnika <?php echo htmlspecialchars($u['username']); ?>?');">
                                        <input type="hidden" name="delete_user_id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn" style="background-color: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #ef4444; padding: 6px 10px; font-size: 12px; border-radius:6px;" title="Obriši korisnika"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 20px;">
            
            <div class="card settings-card" style="border-color: var(--accent-primary);">
                <div class="sc-header">
                    <h3 style="color: var(--text-primary); margin:0;"><i class="fas fa-list-check"></i> To-Do Zadaci</h3>
                    <button class="btn btn-primary btn-sm" style="background: var(--accent-primary); border:none;" onclick="openModal('modal-new-todo')"><i class="fas fa-plus"></i> Novi Zadatak</button>
                </div>
                
                <ul style="list-style: none; padding: 0; margin: 0; max-height: 350px; overflow-y: auto; padding-right: 5px;">
                    <?php if($todos_query->num_rows > 0): while($td = $todos_query->fetch_assoc()): 
                        $priClass = "todo-medium"; $badgeClass = "bg-medium"; $lbl = "Srednje";
                        if($td['priority'] == 'high') { $priClass = "todo-high"; $badgeClass = "bg-high"; $lbl = "Hitno"; }
                        if($td['priority'] == 'low') { $priClass = "todo-low"; $badgeClass = "bg-low"; $lbl = "Nisko"; }
                    ?>
                        <li class="todo-item <?php echo $priClass; ?>">
                            <div>
                                <span class="badge-priority <?php echo $badgeClass; ?>"><?php echo $lbl; ?></span>
                                <div style="color: var(--text-primary); font-size: 15px; font-weight: 500; line-height:1.4;"><?php echo htmlspecialchars($td['task']); ?></div>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 5px;"><i class="fas fa-clock"></i> <?php echo date("d.m.Y.", strtotime($td['created_at'])); ?></div>
                            </div>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="delete_todo_id" value="<?php echo $td['id']; ?>">
                                <button type="submit" class="btn" style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; color: #10b981; padding: 8px 12px; border-radius: 8px;" title="Označi kao riješeno"><i class="fas fa-check"></i></button>
                            </form>
                        </li>
                    <?php endwhile; else: ?>
                        <div style="text-align:center; padding: 20px; color: var(--text-muted);"><i class="fas fa-check-double" style="font-size:30px; margin-bottom:10px; opacity:0.5;"></i><p>Nemate zadataka na listi.</p></div>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="card settings-card" style="border-color: var(--text-muted); display: flex; flex-direction: column; max-height: 600px;">
                <div class="sc-header" style="margin-bottom: 10px;">
                    <h3 style="color: var(--text-primary); margin:0;"><i class="fas fa-history"></i> Dnevnik Aktivnosti</h3>
                    <button class="btn btn-secondary btn-sm" onclick="toggleLogFilters()"><i class="fas fa-filter"></i> Filteri</button>
                </div>
                
                <div id="log-filters-container" class="log-filters" style="display: none;">
                    <input type="text" id="logSearch" class="log-input" placeholder="Pretraži (npr. 'brisanje')..." onkeyup="filterLogs()">
                    <select id="userFilter" class="log-input" style="flex-grow: 0; min-width: 150px;" onchange="filterLogs()">
                        <option value="">Svi Korisnici</option>
                        <?php foreach($log_users as $lu): ?>
                            <option value="<?php echo htmlspecialchars($lu); ?>"><?php echo htmlspecialchars($lu); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="overflow-y: auto; flex-grow: 1; padding-right: 5px;">
                    <ul id="logList" style="list-style: none; padding: 0; margin: 0;">
                        <?php if(!empty($logs_data)): foreach($logs_data as $log): 
                            $uname = $log['username'] ?? 'Sustav';
                        ?>
                            <li class="log-item" style="padding: 12px 10px; border-bottom: 1px dashed rgba(255,255,255,0.05);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 4px;">
                                    <strong class="log-user" style="color: var(--accent-info); font-size: 13px;"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($uname); ?></strong>
                                    <span style="font-size: 11px; color: var(--text-muted);"><i class="fas fa-clock"></i> <?php echo date("d.m.Y H:i", strtotime($log['created_at'])); ?></span>
                                </div>
                                <div class="log-action" style="color: var(--text-primary); font-size: 14px; line-height: 1.4;">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </div>
                            </li>
                        <?php endforeach; else: ?>
                            <li style="color: var(--text-muted); text-align: center; padding: 20px;">Nema zabilježenih aktivnosti.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</main>


<div class="modal-overlay" id="modal-new-user">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--accent-warning); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-user-plus"></i> Dodaj Novog Korisnika</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Korisničko Ime</label>
                <input type="text" name="new_username" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Lozinka</label>
                <input type="text" name="new_user_password" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div class="form-group" style="margin-bottom: 25px;">
                <label>Uloga u sustavu</label>
                <select name="new_user_role" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="worker">Radnik (Ograničene ovlasti)</option>
                    <option value="admin">Admin (Puni pristup)</option>
                </select>
            </div>
            <input type="hidden" name="add_user" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-new-user')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:var(--accent-warning); border:none;">Spremi Korisnika</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-new-todo">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--accent-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-tasks"></i> Novi Zadatak</h3>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Opis zadatka / Plana / Kvara</label>
                <textarea name="todo_text" required rows="3" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);"></textarea>
            </div>
            <div class="form-group" style="margin-bottom: 25px;">
                <label>Prioritet</label>
                <select name="priority" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="high">Hitno (Crveno)</option>
                    <option value="medium" selected>Srednje (Žuto)</option>
                    <option value="low">Nisko (Plavo)</option>
                </select>
            </div>
            <input type="hidden" name="add_todo" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-new-todo')">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background:var(--accent-primary); border:none;">Dodaj na listu</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal Logic
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    // Toggle Filters
    function toggleLogFilters() {
        const el = document.getElementById('log-filters-container');
        el.style.display = el.style.display === 'none' ? 'flex' : 'none';
    }

    // Live Search
    function filterLogs() {
        const searchInput = document.getElementById('logSearch').value.toLowerCase();
        const userFilter = document.getElementById('userFilter').value.toLowerCase();
        const rows = document.querySelectorAll('.log-item');

        rows.forEach(row => {
            const actionText = row.querySelector('.log-action').innerText.toLowerCase();
            const userText = row.querySelector('.log-user').innerText.toLowerCase();
            
            const matchesSearch = actionText.includes(searchInput);
            const matchesUser = userFilter === "" || userText === userFilter;

            if (matchesSearch && matchesUser) {
                row.style.display = 'block';
            } else {
                row.style.display = 'none';
            }
        });
    }
</script>

<?php include 'footer.php'; ?>
