<?php
require 'db.php';

// Provjera ID-a
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Greška: Nije odabrana nijedna koza. <a href='herd.php'>Vrati se nazad</a>");
}

$id = (int)$_GET['id'];
$goat_id = $id; 
$success_msg = ""; $error_msg = "";

// --- SMART DATABASE UPDATER: Dodaj Arhivirano ---
$checkArchive = $conn->query("SHOW COLUMNS FROM goats LIKE 'archived'");
if ($checkArchive->num_rows == 0) {
    $conn->query("ALTER TABLE goats ADD COLUMN archived TINYINT(1) DEFAULT 0");
}

// --- 1. SPREMANJE UREĐENOG PROFILA ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_goat'])) {
    $name = $conn->real_escape_string($_POST['edit_name']);
    $gender = $conn->real_escape_string($_POST['edit_gender']);
    $breed = $conn->real_escape_string($_POST['edit_breed']);
    $birth_date = $conn->real_escape_string($_POST['edit_birth']);
    $status = $conn->real_escape_string($_POST['edit_status']);
    $notes = $conn->real_escape_string($_POST['edit_notes']);
    
    $mother_id = !empty($_POST['edit_mother_id']) ? (int)$_POST['edit_mother_id'] : "NULL";
    $father_id = !empty($_POST['edit_father_id']) ? (int)$_POST['edit_father_id'] : "NULL";

    $image_update_sql = "";
    if (!empty($_FILES['edit_goat_image']['name'])) {
        $target_dir = "images/goats/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        $file_extension = strtolower(pathinfo($_FILES["edit_goat_image"]["name"], PATHINFO_EXTENSION));
        $new_file_name = uniqid("goat_") . "." . $file_extension;
        $target_file = $target_dir . $new_file_name;
        
        if (move_uploaded_file($_FILES["edit_goat_image"]["tmp_name"], $target_file)) {
            $image_update_sql = ", image_path='" . $conn->real_escape_string($new_file_name) . "'";
            $old_img_res = $conn->query("SELECT image_path FROM goats WHERE id=$id");
            if ($old_img_res && $old_img_res->num_rows > 0) {
                $old_img = $old_img_res->fetch_assoc()['image_path'];
                if (!empty($old_img) && file_exists("images/goats/" . $old_img)) unlink("images/goats/" . $old_img);
            }
        }
    }

    $sql = "UPDATE goats SET name='$name', gender='$gender', breed='$breed', birth_date='$birth_date', status='$status', notes='$notes', mother_id=$mother_id, father_id=$father_id $image_update_sql WHERE id=$id";
    if ($conn->query($sql) === TRUE) { 
        logAction($conn, "Ažuriran profil koze (ID: $id)");
        $success_msg = "Profil je uspješno ažuriran!"; 
    } else { 
        $error_msg = "Greška: " . $conn->error; 
    }
}

// --- 1B. ARHIVIRANJE KOZE (Oslobađanje ID-a) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_goat'])) {
    // Postavljamo arhivirano na 1 i mijenjamo ID u privremeni da se stari oslobodi
    // Formatiramo novi ID kao: stari_id + 900000 (npr. 105 postaje 900105)
    $new_archived_id = $id + 900000;
    
    // Prvo moramo ažurirati sve tablice koje su vezane za ovaj stari ID, da pokažu na novi ID
    $conn->query("UPDATE goats SET mother_id=$new_archived_id WHERE mother_id=$id");
    $conn->query("UPDATE goats SET father_id=$new_archived_id WHERE father_id=$id");
    $conn->query("UPDATE events SET goat_id=$new_archived_id WHERE goat_id=$id");
    $conn->query("UPDATE milk_logs SET goat_id=$new_archived_id WHERE goat_id=$id");
    $conn->query("UPDATE weight_logs SET goat_id=$new_archived_id WHERE goat_id=$id");
    
    // Zatim mijenjamo glavni ID i označavamo kao arhivirano. Status mora biti točno 'Prodano'.
    $sql = "UPDATE goats SET id=$new_archived_id, archived=1, status='Prodano' WHERE id=$id";
    
    if ($conn->query($sql) === TRUE) {
        logAction($conn, "Koza ID $id je arhivirana. ID je oslobođen za novu kozu. (Novi sistemski ID: $new_archived_id)");
        header("Location: herd.php"); // Vrati na stado
        exit();
    } else {
        $error_msg = "Greška pri arhiviranju: " . $conn->error;
    }
}

// --- 2. SPREMANJE DODATNE NAPOMENE ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile_notes'])) {
    $p_notes = $conn->real_escape_string($_POST['profile_notes']);
    if ($conn->query("UPDATE goats SET profile_notes='$p_notes' WHERE id=$id")) {
        logAction($conn, "Ažurirana osobna napomena za kozu (ID: $id)");
        $success_msg = "Dodatna napomena je spremljena!";
    }
}

// --- 3. DODAVANJE LIJEKA / TERAPIJE S KARENCOM ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_medication'])) {
    $event_date = $conn->real_escape_string($_POST['event_date']);
    $medication = $conn->real_escape_string($_POST['medication']);
    $event_notes = $conn->real_escape_string($_POST['event_notes']);
    $withdrawal_days = isset($_POST['withdrawal_days']) ? (int)$_POST['withdrawal_days'] : 0;
    $next_app = !empty($_POST['next_appointment']) ? "'" . $conn->real_escape_string($_POST['next_appointment']) . "'" : "NULL";

    $sql = "INSERT INTO events (goat_id, event_date, event_type, notes, medication, cost, next_appointment, withdrawal_days) 
            VALUES ($id, '$event_date', 'Liječenje', '$event_notes', '$medication', 0.00, $next_app, $withdrawal_days)";
    if ($conn->query($sql) === TRUE) { 
        logAction($conn, "Zabilježeno liječenje za kozu (ID: $id) - $medication");
        $success_msg = "Terapija zabilježena! Sinhronizirano s kalendarom."; 
    } else { 
        $error_msg = "Greška pri dodavanju lijeka: " . $conn->error; 
    }
}

// --- 4. DOHVAĆANJE PODATAKA O KOZI I RODITELJIMA ---
$sql = "SELECT g.*, m.name as mother_name, f.name as father_name 
        FROM goats g
        LEFT JOIN goats m ON g.mother_id = m.id
        LEFT JOIN goats f ON g.father_id = f.id
        WHERE g.id = $id";
$result = $conn->query($sql);
if ($result->num_rows == 0) { die("Greška: Koza ne postoji."); }
$goat = $result->fetch_assoc();

// Izračun starosti
$ageStr = '-';
if (!empty($goat['birth_date'])) {
    $dob = new DateTime($goat['birth_date']);
    $now = new DateTime();
    $diff = $now->diff($dob);
    if ($diff->y > 0) { $ageStr = "{$diff->y} god, {$diff->m} mj"; } 
    elseif ($diff->m > 0) { $ageStr = "{$diff->m} mj, {$diff->d} d"; } 
    else { $ageStr = "{$diff->d} dana"; }
}

// --- 5. DEEP ANCESTRY S INBREEDING DETEKCIJOM ---
function getAncestryLine($id, $conn, &$seen_ids = [], $generation = 1) {
    if ($generation > 5) return null;

    $stmt = $conn->prepare("SELECT id, name, gender, breed, mother_id, father_id FROM goats WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows === 0) return null;
    $g = $res->fetch_assoc();

    $inbred = isset($seen_ids[$id]);
    $seen_ids[$id] = true;

    return [
        'id' => $g['id'],
        'name' => $g['name'],
        'gender' => $g['gender'],
        'breed' => $g['breed'],
        'inbred' => $inbred,
        'mother' => $g['mother_id'] ? getAncestryLine($g['mother_id'], $conn, $seen_ids, $generation + 1) : null,
        'father' => $g['father_id'] ? getAncestryLine($g['father_id'], $conn, $seen_ids, $generation + 1) : null,
    ];
}

$seen_ancestors = [];
$deep_ancestry = getAncestryLine($goat_id, $conn, $seen_ancestors);

function renderTree($branch) {
    if (!$branch) return '';
    $icon = $branch['gender'] == 'Zensko' ? '<i class="fas fa-venus" style="color: pink;"></i>' : '<i class="fas fa-mars" style="color: #3b82f6;"></i>';
    $name = $branch['name'] ?: 'Bez imena';
    $inbredClass = $branch['inbred'] ? 'inbred-warning' : '';
    $inbredIcon = $branch['inbred'] ? '<i class="fas fa-exclamation-triangle" style="color: var(--accent-danger);" title="Pojavljuje se više puta (Inbreeding)"></i> ' : '';
    
    // Normaliziraj prikaz ID-a da arhivirani ne izgledaju kao #900105
    $display_id = $branch['id'] > 900000 ? ($branch['id'] - 900000) : $branch['id'];

    $html = "<li>
        <div class='tree-node {$inbredClass}'>
            {$inbredIcon}{$icon} <strong><a href='profile.php?id={$branch['id']}'>#{$display_id} {$name}</a></strong>
        </div>";
        
    if ($branch['mother'] || $branch['father']) {
        $html .= "<ul>";
        if ($branch['father']) $html .= renderTree($branch['father']);
        if ($branch['mother']) $html .= renderTree($branch['mother']);
        $html .= "</ul>";
    }
    $html .= "</li>";
    return $html;
}

// --- 6. NAPREDNA STATISTIKA (Mlijeko, Meso, Zadaci, Potomstvo) ---

// Zadaci specifični za kozu
$goat_tasks = $conn->query("SELECT * FROM events WHERE goat_id = $id AND next_appointment >= CURDATE() ORDER BY next_appointment ASC");

// Potomstvo (Offspring)
$offspring_query = $conn->query("SELECT id, name, gender, status, birth_date, archived FROM goats WHERE mother_id = $id OR father_id = $id ORDER BY birth_date DESC");

// Mlijeko
$chart_milk = $conn->query("SELECT log_date, (IFNULL(morning_liters, 0) + IFNULL(evening_liters, 0)) as total_milk FROM milk_logs WHERE goat_id = $id ORDER BY log_date DESC LIMIT 30");
$chart_dates = []; $chart_data = []; $total_milk_30days = 0;
while($row = $chart_milk->fetch_assoc()) {
    $chart_dates[] = date("d.m.Y", strtotime($row['log_date']));
    $chart_data[] = $row['total_milk'];
    $total_milk_30days += $row['total_milk'];
}
$chart_dates = array_reverse($chart_dates); $chart_data = array_reverse($chart_data);

// Lifetime & Laktacija Mlijeko
$lifetime_milk = $conn->query("SELECT SUM(morning_liters + evening_liters) as total FROM milk_logs WHERE goat_id = $id")->fetch_assoc()['total'] ?? 0;

$last_kidding = $conn->query("SELECT event_date FROM events WHERE goat_id = $id AND event_type = 'Porod' ORDER BY event_date DESC LIMIT 1");
$lactation_milk = 0;
if ($last_kidding->num_rows > 0) {
    $lk_date = $last_kidding->fetch_assoc()['event_date'];
    $lactation_milk = $conn->query("SELECT SUM(morning_liters + evening_liters) as total FROM milk_logs WHERE goat_id = $id AND log_date >= '$lk_date'")->fetch_assoc()['total'] ?? 0;
} else {
    $lactation_milk = $lifetime_milk; 
}

// Težina (Izračun prirasta)
$weight_logs = $conn->query("SELECT * FROM weight_logs WHERE goat_id = $id ORDER BY log_date DESC");
$weight_data = [];
while($w = $weight_logs->fetch_assoc()) { $weight_data[] = $w; }
for ($i = 0; $i < count($weight_data); $i++) {
    $prev = isset($weight_data[$i+1]) ? $weight_data[$i+1]['live_weight'] : null;
    $weight_data[$i]['delta'] = $prev !== null ? ($weight_data[$i]['live_weight'] - $prev) : null;
}

// Opcije za uredi formu (Ne prikazuj arhivirane u padajućem meniju)
$motherOptions = "<option value=''>-- Nepoznato --</option>";
$mq = $conn->query("SELECT id, name FROM goats WHERE gender='Zensko' AND id != $id AND archived = 0 ORDER BY id ASC");
while($m = $mq->fetch_assoc()) { $sel = ($goat['mother_id'] == $m['id']) ? "selected" : ""; $motherOptions .= "<option value='{$m['id']}' $sel>#{$m['id']} - {$m['name']}</option>"; }

// Ako je majka arhivirana, ipak je prikaži kao odabranu, da je korisnik slučajno ne obriše
if ($goat['mother_id'] > 900000) {
    $real_m_id = $goat['mother_id'] - 900000;
    $m_name = $conn->query("SELECT name FROM goats WHERE id = {$goat['mother_id']}")->fetch_assoc()['name'] ?? 'Nepoznato';
    $motherOptions .= "<option value='{$goat['mother_id']}' selected>#{$real_m_id} - {$m_name} (Arhivirano)</option>";
}

$fatherOptions = "<option value=''>-- Nepoznato --</option>";
$fq = $conn->query("SELECT id, name FROM goats WHERE gender='Musko' AND id != $id AND archived = 0 ORDER BY id ASC");
while($f = $fq->fetch_assoc()) { $sel = ($goat['father_id'] == $f['id']) ? "selected" : ""; $fatherOptions .= "<option value='{$f['id']}' $sel>#{$f['id']} - {$f['name']}</option>"; }

// Ako je otac arhiviran, prikaži ga
if ($goat['father_id'] > 900000) {
    $real_f_id = $goat['father_id'] - 900000;
    $f_name = $conn->query("SELECT name FROM goats WHERE id = {$goat['father_id']}")->fetch_assoc()['name'] ?? 'Nepoznato';
    $fatherOptions .= "<option value='{$goat['father_id']}' selected>#{$real_f_id} - {$f_name} (Arhivirano)</option>";
}

include 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* REDESIGNED PROFILE BANNER */
    .profile-header-new {
        background: linear-gradient(145deg, var(--bg-surface) 0%, #151b2b 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        padding: 30px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        position: relative;
        overflow: hidden;
    }
    
    .profile-header-new::before {
        content: ''; position: absolute; top: -50px; right: -50px; width: 200px; height: 200px;
        background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%); border-radius: 50%;
    }

    .ph-left { display: flex; align-items: center; gap: 24px; z-index: 1; }
    .ph-avatar {
        width: 120px; height: 120px; border-radius: 20px; object-fit: cover;
        border: 2px solid rgba(255,255,255,0.1); box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        background-color: var(--bg-surface-hover); cursor: zoom-in; transition: transform 0.3s;
    }
    .ph-avatar:hover { transform: scale(1.05); }
    .ph-avatar-placeholder {
        width: 120px; height: 120px; border-radius: 20px; background-color: rgba(0,0,0,0.4);
        display: flex; align-items: center; justify-content: center; font-size: 40px; color: var(--text-muted);
        border: 2px solid rgba(255,255,255,0.05);
    }
    .ph-info h1 { font-size: 32px; margin: 0 0 5px 0; color: var(--text-primary); line-height: 1.2; }
    .ph-info .tags { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
    
    .ph-right { display: flex; gap: 10px; z-index: 1; }

    /* MINI STATS GRID */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 30px; }
    .mini-stat-card { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 16px; border-radius: var(--radius-md); text-align: center; }
    .mini-stat-label { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: bold; margin-bottom: 5px; display: block; letter-spacing: 0.5px; }
    .mini-stat-value { font-size: 15px; font-weight: 700; color: var(--text-primary); }
    .color-zensko { color: pink !important; } .color-musko { color: #3b82f6 !important; }
    
    /* STICKY SUBMENU */
    .submenu-nav { 
        display: flex; gap: 12px; margin-bottom: 24px; position: sticky; top: 0; z-index: 50; 
        background: rgba(15, 17, 26, 0.9); padding: 15px 0; backdrop-filter: blur(10px);
        flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
    }
    .submenu-nav::-webkit-scrollbar { display: none; }
    .submenu-nav { -ms-overflow-style: none; scrollbar-width: none; }

    .submenu-nav a { 
        text-decoration: none; color: var(--text-secondary); background: var(--bg-surface); 
        padding: 10px 18px; border-radius: 30px; border: 1px solid var(--border-color); 
        font-size: 14px; font-weight: 600; transition: 0.2s; white-space: nowrap;
    }
    .submenu-nav a:hover, .submenu-nav a.active-sub { 
        color: white; background: var(--accent-primary); border-color: var(--accent-primary); 
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); 
    }

    /* CSS PEDIGREE TREE */
    .tree-container { padding: 20px; overflow-x: auto; display: flex; justify-content: flex-start; }
    .tree { display: inline-flex; }
    .tree ul { padding-top: 20px; position: relative; transition: all 0.5s; display: flex; gap: 20px; padding-left: 0; margin: 0; }
    .tree li { float: left; text-align: center; list-style-type: none; position: relative; padding: 20px 5px 0 5px; transition: all 0.5s; }
    .tree li::before, .tree li::after { content: ''; position: absolute; top: 0; right: 50%; border-top: 2px solid var(--border-color); width: 50%; height: 20px; }
    .tree li::after { right: auto; left: 50%; border-left: 2px solid var(--border-color); }
    .tree li:only-child::after, .tree li:only-child::before { display: none; }
    .tree li:only-child { padding-top: 0; }
    .tree li:first-child::before, .tree li:last-child::after { border: 0 none; }
    .tree li:last-child::before { border-right: 2px solid var(--border-color); border-radius: 0 5px 0 0; }
    .tree li:first-child::after { border-radius: 5px 0 0 0; }
    .tree ul ul::before { content: ''; position: absolute; top: 0; left: 50%; border-left: 2px solid var(--border-color); width: 0; height: 20px; }
    .tree-node { border: 1px solid var(--border-color); padding: 10px 15px; text-decoration: none; color: var(--text-primary); font-size: 13px; display: inline-block; border-radius: 8px; background: var(--bg-surface); box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.2s; white-space: nowrap; }
    .tree-node a { color: var(--text-primary); text-decoration: none; }
    .tree-node a:hover { color: var(--accent-info); }
    .inbred-warning { border-color: var(--accent-danger); box-shadow: 0 0 10px rgba(239, 68, 68, 0.2); }

    #lightbox { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 17, 26, 0.95); align-items: center; justify-content: center; cursor: zoom-out; }
    #lightbox img { max-width: 95%; max-height: 95%; border-radius: var(--radius-md); box-shadow: 0 10px 30px rgba(0,0,0,0.8); }

    /* EDIT MODAL */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; position: relative; }
    .close-modal-btn { position: absolute; top: 15px; right: 15px; background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; transition: 0.2s; }
    .close-modal-btn:hover { color: white; }

    @media (max-width: 768px) {
        .profile-header-new { flex-direction: column; text-align: center; }
        .ph-left { flex-direction: column; }
        .ph-info .tags { justify-content: center; }
        .ph-right { width: 100%; flex-direction: column; margin-top: 15px; }
        .ph-right .btn { width: 100%; justify-content: center; }
    }
</style>

    <div id="lightbox" onclick="closeLightbox()"><img id="lightbox-img" src=""></div>

    <main class="content-area">
        <div style="margin-bottom: 20px;">
            <a href="herd.php" class="btn btn-secondary" style="padding: 8px 16px;"><i class="fas fa-arrow-left"></i> Nazad na stado</a>
        </div>

        <?php 
        // Provjera je li trenutno aktivna karenca
        $karenca_query = $conn->query("SELECT MAX(DATE_ADD(event_date, INTERVAL withdrawal_days DAY)) as w_end FROM events WHERE goat_id = $id AND withdrawal_days > 0");
        $karenca_end = $karenca_query->fetch_assoc()['w_end'] ?? null;
        if ($karenca_end && $karenca_end >= date('Y-m-d')): 
        ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 2px solid var(--accent-danger); padding: 20px; border-radius: var(--radius-md); margin-bottom: 24px; text-align: center; color: var(--accent-danger); box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);">
                <i class="fas fa-biohazard" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>
                <h3 style="margin: 0 0 5px 0;">STROGA KARENCA</h3>
                <p style="margin: 0; font-size: 15px; color: white;">Životinja je pod zabranom do <strong><?php echo date("d.m.Y", strtotime($karenca_end)); ?></strong>!<br>Ne koristiti mlijeko ni meso u ovom periodu.</p>
            </div>
        <?php endif; ?>

        <?php if($goat['archived'] == 1): ?>
            <div style="background: rgba(148, 163, 184, 0.1); border: 1px solid var(--text-muted); padding: 15px; border-radius: var(--radius-md); margin-bottom: 24px; text-align: center; color: var(--text-muted);">
                <i class="fas fa-archive" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                <strong>Ova životinja je arhivirana.</strong><br>
                Njen stvarni ID je oslobođen za novu kozu. Zadržana je u bazi samo radi očuvanja obiteljskog stabla.
            </div>
        <?php endif; ?>

        <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); margin-bottom: 20px;'><p style='color:var(--accent-success); font-weight: bold; text-align: center; margin:0;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
        <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); margin-bottom: 20px;'><p style='color:var(--accent-danger); font-weight: bold; text-align: center; margin:0;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

        <?php if($goat_tasks->num_rows > 0): ?>
            <div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--accent-warning); background: rgba(245, 158, 11, 0.05);">
                <h4 style="color: var(--accent-warning); margin-bottom: 10px;"><i class="fas fa-bell"></i> Nadolazeći zadaci za ovu životinju</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php while($t = $goat_tasks->fetch_assoc()): ?>
                        <li style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.1);">
                            <span><strong><?php echo $t['event_type']; ?></strong> <span style="color:var(--text-muted); font-size:13px;">(<?php echo htmlspecialchars($t['notes']); ?>)</span></span>
                            <span style="color: var(--accent-warning); font-weight: bold;"><?php echo date("d.m.Y", strtotime($t['next_appointment'])); ?></span>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="profile-header-new">
            <div class="ph-left">
                <?php $img_src = (!empty($goat['image_path']) && file_exists("images/goats/" . $goat['image_path'])) ? "images/goats/" . $goat['image_path'] : ""; ?>
                <?php if($img_src): ?>
                    <img src="<?php echo htmlspecialchars($img_src); ?>" alt="Slika" class="ph-avatar" onclick="openLightbox('<?php echo htmlspecialchars($img_src); ?>')">
                <?php else: ?>
                    <div class="ph-avatar-placeholder"><i class="fas fa-camera"></i></div>
                <?php endif; ?>
                
                <div class="ph-info">
                    <h1>
                        <?php 
                            // Prikaz pravog ID-a čak i ako je arhivirana
                            $display_id = $goat['archived'] == 1 ? ($goat['id'] - 900000) : $goat['id']; 
                        ?>
                        #<?php echo $display_id . ' ' . ($goat['name'] ? htmlspecialchars($goat['name']) : 'Bez imena'); ?>
                    </h1>
                    <div class="tags">
                        <span class="badge bg-default"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($goat['breed'] ? $goat['breed'] : 'Nepoznata pasmina'); ?></span>
                        <span class="badge bg-<?php echo strtolower($goat['status']); ?>"><?php echo $goat['status']; ?></span>
                    </div>
                </div>
            </div>
            
            <?php if($goat['archived'] == 0): ?>
            <div class="ph-right">
                <a href="meat.php?goat_id=<?php echo $goat['id']; ?>" class="btn btn-secondary" style="background: rgba(255,255,255,0.05);"><i class="fas fa-weight"></i> Vagaj</a>
                <button class="btn btn-primary" onclick="openEditModal()"><i class="fas fa-edit"></i> Uredi / Arhiviraj</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="stats-grid">
            <div class="mini-stat-card">
                <span class="mini-stat-label">Starost</span>
                <span class="mini-stat-value"><i class="fas fa-birthday-cake text-muted"></i> <?php echo $ageStr; ?></span>
            </div>
            <div class="mini-stat-card">
                <span class="mini-stat-label">Spol</span>
                <span class="mini-stat-value color-<?php echo strtolower($goat['gender']); ?>">
                    <?php echo ($goat['gender'] == 'Zensko' ? '<i class="fas fa-venus"></i> Žensko' : '<i class="fas fa-mars"></i> Muško'); ?>
                </span>
            </div>
            <div class="mini-stat-card">
                <span class="mini-stat-label">Majka</span>
                <span class="mini-stat-value">
                    <?php if($goat['mother_id']): ?>
                        <a href="profile.php?id=<?php echo $goat['mother_id']; ?>" style="color: var(--accent-info); text-decoration: none;">#<?php echo ($goat['mother_id'] > 900000 ? ($goat['mother_id'] - 900000) : $goat['mother_id']); ?></a>
                    <?php else: ?> <span style="color: var(--text-muted);">Nepoznato</span> <?php endif; ?>
                </span>
            </div>
            <div class="mini-stat-card">
                <span class="mini-stat-label">Otac</span>
                <span class="mini-stat-value">
                    <?php if($goat['father_id']): ?>
                        <a href="profile.php?id=<?php echo $goat['father_id']; ?>" style="color: var(--accent-info); text-decoration: none;">#<?php echo ($goat['father_id'] > 900000 ? ($goat['father_id'] - 900000) : $goat['father_id']); ?></a>
                    <?php else: ?> <span style="color: var(--text-muted);">Nepoznato</span> <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="submenu-nav">
            <?php if($goat['gender'] == 'Zensko'): ?>
                <a href="#milk" class="active-sub"><i class="fas fa-prescription-bottle"></i> Mlijeko</a>
            <?php endif; ?>
            <a href="#meat"><i class="fas fa-weight"></i> Težina</a>
            <a href="#pedigree"><i class="fas fa-sitemap"></i> Rodovnik</a>
            <a href="#health"><i class="fas fa-briefcase-medical"></i> Zdravlje</a>
        </div>

        <?php if($goat['gender'] == 'Zensko'): ?>
        <section id="milk" class="mb-30 dashboard-grid">
            <div class="card" style="border-top: 4px solid var(--accent-success); grid-column: 1 / -1;">
                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <h3 style="color: var(--text-secondary); font-size: 14px; text-transform: uppercase;">Ukupno (Životni vijek)</h3>
                        <div style="font-size: 24px; font-weight: bold; color: var(--accent-success);"><?php echo number_format($lifetime_milk, 1); ?> L</div>
                    </div>
                    <div>
                        <h3 style="color: var(--text-secondary); font-size: 14px; text-transform: uppercase;">Trenutna Laktacija</h3>
                        <div style="font-size: 24px; font-weight: bold; color: var(--accent-info);"><?php echo number_format($lactation_milk, 1); ?> L</div>
                    </div>
                    <div>
                        <h3 style="color: var(--text-secondary); font-size: 14px; text-transform: uppercase;">Zadnjih 30 dana</h3>
                        <div style="font-size: 24px; font-weight: bold; color: var(--text-primary);"><?php echo number_format($total_milk_30days, 1); ?> L</div>
                    </div>
                </div>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="milkChart"></canvas>
                </div>
            </div>
        </section>
        <?php endif; ?>
        
        <section id="meat" class="mb-30">
            <div class="card" style="border-top: 4px solid var(--accent-warning);">
                <h3><i class="fas fa-balance-scale text-muted me-2"></i> Povijest Vaganja i Prirast</h3>
                <div class="table-responsive mt-10">
                    <table class="herd-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Živa Vaga</th>
                                <th>Prirast</th>
                                <th>Neto Meso</th>
                                <th>Napomena</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($weight_data) > 0): ?>
                                <?php foreach($weight_data as $w): 
                                    $deltaBadge = "-";
                                    if ($w['delta'] !== null) {
                                        if ($w['delta'] > 0) $deltaBadge = "<span style='color: var(--accent-success); font-weight: bold;'>+" . number_format($w['delta'], 1) . " kg <i class='fas fa-arrow-up'></i></span>";
                                        elseif ($w['delta'] < 0) $deltaBadge = "<span style='color: var(--accent-danger); font-weight: bold;'>" . number_format($w['delta'], 1) . " kg <i class='fas fa-arrow-down'></i></span>";
                                        else $deltaBadge = "<span style='color: var(--text-muted);'>0.0 kg</span>";
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo date("d.m.Y", strtotime($w['log_date'])); ?></td>
                                        <td style="font-weight: bold; font-size: 16px;"><?php echo number_format($w['live_weight'], 1); ?> kg</td>
                                        <td><?php echo $deltaBadge; ?></td>
                                        <td style="color: var(--accent-danger);"><?php echo $w['net_weight'] ? number_format($w['net_weight'], 1) . ' kg' : '-'; ?></td>
                                        <td style="font-size: 13px; color: var(--text-muted);"><?php echo htmlspecialchars($w['notes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 20px;">Još nema unesenih vaganja.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="pedigree" class="mb-30 dashboard-grid">
            <div class="card" style="border-top: 4px solid var(--accent-primary); grid-column: 1 / -1; overflow-x: auto;">
                <h3 style="margin-bottom: 20px;"><i class="fas fa-sitemap text-muted"></i> Vizualni Rodovnik</h3>
                <div class="tree-container">
                    <div class="tree">
                        <ul>
                            <li>
                                <div class="tree-node" style="border-color: var(--accent-primary); border-width: 2px;">
                                    <?php echo ($goat['gender']=='Zensko'?'<i class="fas fa-venus" style="color:pink;"></i>':'<i class="fas fa-mars" style="color:#3b82f6;"></i>'); ?> 
                                    <strong>#<?php echo $display_id . ' ' . htmlspecialchars($goat['name']); ?></strong>
                                </div>
                                
                                <?php if ($deep_ancestry['father'] || $deep_ancestry['mother'] || $offspring_query->num_rows > 0): ?>
                                    <ul>
                                        <?php if ($deep_ancestry['father'] || $deep_ancestry['mother']): ?>
                                            <li>
                                                <div class="tree-node" style="background: rgba(255,255,255,0.05); color: var(--text-muted); border-style: dashed;">Preci (Roditelji)</div>
                                                <ul>
                                                    <?php if ($deep_ancestry['father']) echo renderTree($deep_ancestry['father']); ?>
                                                    <?php if ($deep_ancestry['mother']) echo renderTree($deep_ancestry['mother']); ?>
                                                </ul>
                                            </li>
                                        <?php endif; ?>

                                        <?php if ($offspring_query->num_rows > 0): ?>
                                            <li>
                                                <div class="tree-node" style="background: rgba(255,255,255,0.05); color: var(--text-muted); border-style: dashed;">Potomci (Djeca)</div>
                                                <ul>
                                                    <?php 
                                                    $offspring_query->data_seek(0);
                                                    while($kid = $offspring_query->fetch_assoc()): 
                                                        $kid_id = $kid['archived'] == 1 ? ($kid['id'] - 900000) : $kid['id'];
                                                    ?>
                                                        <li>
                                                            <div class="tree-node">
                                                                <?php echo ($kid['gender']=='Zensko'?'<i class="fas fa-venus" style="color:pink;"></i>':'<i class="fas fa-mars" style="color:#3b82f6;"></i>'); ?>
                                                                <a href="profile.php?id=<?php echo $kid['id']; ?>"><strong>#<?php echo $kid_id . ' ' . htmlspecialchars($kid['name']); ?></strong></a>
                                                            </div>
                                                        </li>
                                                    <?php endwhile; ?>
                                                </ul>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card" style="border-top: 4px solid #a78bfa; grid-column: 1 / -1;">
                <h3 style="margin-bottom: 15px;"><i class="fas fa-baby"></i> Tablica Potomstva (<?php echo $offspring_query->num_rows; ?>)</h3>
                <div class="table-responsive">
                    <table class="herd-table">
                        <thead>
                            <tr>
                                <th>ID i Ime</th>
                                <th>Spol</th>
                                <th>Datum Rođenja</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $offspring_query->data_seek(0);
                            if($offspring_query->num_rows > 0): ?>
                                <?php while($kid = $offspring_query->fetch_assoc()): 
                                    $kid_id = $kid['archived'] == 1 ? ($kid['id'] - 900000) : $kid['id'];
                                ?>
                                    <tr>
                                        <td><strong><a href="profile.php?id=<?php echo $kid['id']; ?>" style="color:var(--accent-info); text-decoration:none;">#<?php echo $kid_id . ' ' . htmlspecialchars($kid['name']); ?></a></strong></td>
                                        <td><?php echo ($kid['gender']=='Zensko'?'<i class="fas fa-venus" style="color:pink;"></i>':'<i class="fas fa-mars" style="color:#3b82f6;"></i>'); ?></td>
                                        <td><?php echo $kid['birth_date'] ? date("d.m.Y", strtotime($kid['birth_date'])) : '-'; ?></td>
                                        <td><span class="badge bg-<?php echo strtolower($kid['status']); ?>"><?php echo $kid['status']; ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 20px; color: var(--text-muted);">Ova životinja nema zabilježeno potomstvo.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="health" class="mb-30 dashboard-grid">
            <div class="card" style="border-top: 4px solid var(--accent-info);">
                <h3 style="color: var(--accent-info); margin-bottom: 15px;"><i class="fas fa-sticky-note"></i> Osobna Napomena</h3>
                <form method="POST">
                    <textarea name="profile_notes" rows="6" style="width: 100%; padding: 15px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); color: white; border-radius: var(--radius-md); font-size: 15px; margin-bottom: 15px;" placeholder="Ovdje upišite detaljne bilješke..."><?php echo htmlspecialchars($goat['profile_notes'] ?? ''); ?></textarea>
                    <input type="hidden" name="update_profile_notes" value="1">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; color: var(--accent-info); border-color: var(--accent-info);"><i class="fas fa-save"></i> Spremi Napomenu</button>
                </form>
            </div>

            <div class="card" style="border-top: 4px solid var(--accent-danger);">
                <h3 style="color: var(--accent-danger); margin-bottom: 20px;"><i class="fas fa-briefcase-medical"></i> Dodaj Terapiju</h3>
                <form method="POST" class="form-grid">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Datum davanja</label>
                        <input type="date" name="event_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Lijek / Terapija <span style="color:var(--accent-danger);">*</span></label>
                        <input type="text" name="medication" placeholder="npr. Penicilin 5ml" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Karenca <i class="fas fa-biohazard" style="color:var(--accent-danger);"></i></label>
                        <input type="number" min="0" name="withdrawal_days" value="0" placeholder="Dani zabrane">
                        <small style="color:var(--text-muted);">Unesite broj dana</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Dijagnoza</label>
                        <input type="text" name="event_notes" placeholder="npr. Upala vimena...">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>Iduća doza (Podsjetnik u Kalendaru)</label>
                        <input type="date" name="next_appointment">
                    </div>
                    <input type="hidden" name="add_medication" value="1">
                    <div style="grid-column: 1 / -1;">
                        <button type="submit" class="btn btn-primary" style="width: 100%; background-color: var(--accent-danger);"><i class="fas fa-syringe"></i> Zabilježi Liječenje</button>
                    </div>
                </form>
            </div>
        </section>

    </main>

    <div class="modal-overlay" id="editGoatModal">
        <div class="modal-content">
            <button type="button" class="close-modal-btn" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
            <h3 style="margin-top:0; color:var(--accent-warning); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-edit"></i> Uredi Profil Koze #<?php echo $display_id; ?></h3>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group" style="margin-bottom: 15px;"><label>Ime</label><input type="text" name="edit_name" value="<?php echo htmlspecialchars($goat['name']); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"></div>
                <div class="form-group" style="margin-bottom: 15px;"><label>Nova Slika</label><input type="file" name="edit_goat_image" accept="image/*" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"></div>
                
                <div style="display:flex; gap:15px; margin-bottom: 15px;">
                    <div class="form-group" style="flex:1;">
                        <label>Spol</label>
                        <select name="edit_gender" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                            <option value="Zensko" <?php if($goat['gender']=='Zensko') echo 'selected';?>>Žensko</option>
                            <option value="Musko" <?php if($goat['gender']=='Musko') echo 'selected';?>>Muško</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;"><label>Pasmina</label><input type="text" name="edit_breed" value="<?php echo htmlspecialchars($goat['breed']); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"></div>
                </div>

                <div style="display:flex; gap:15px; margin-bottom: 15px;">
                    <div class="form-group" style="flex:1;"><label>Datum rođenja</label><input type="date" name="edit_birth" required value="<?php echo $goat['birth_date']; ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"></div>
                    <div class="form-group" style="flex:1;">
                        <label>Status</label>
                        <select name="edit_status" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;">
                            <?php 
                            $statuses = ['Mlado', 'Mlijecna', 'Trudna', 'Rasplodna', 'Suha', 'Prodano', 'Krepano'];
                            foreach($statuses as $st) {
                                $sel = ($goat['status'] == $st) ? "selected" : "";
                                echo "<option value='$st' $sel>$st</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:15px; margin-bottom: 15px;">
                    <div class="form-group" style="flex:1;"><label>Majka <i class="fas fa-venus"></i></label><select name="edit_mother_id" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"><?php echo $motherOptions; ?></select></div>
                    <div class="form-group" style="flex:1;"><label>Otac <i class="fas fa-mars"></i></label><select name="edit_father_id" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"><?php echo $fatherOptions; ?></select></div>
                </div>

                <div class="form-group" style="margin-bottom: 25px;"><label>Kratka napomena (za tablicu)</label><input type="text" name="edit_notes" value="<?php echo htmlspecialchars($goat['notes']); ?>" style="width:100%; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-surface-hover); color:white;"></div>
                
                <input type="hidden" name="edit_goat" value="1"> 
                <button type="submit" class="btn btn-primary" style="background-color: var(--accent-warning); color: #000; width: 100%; margin-bottom: 15px;"><i class="fas fa-save"></i> Spremi Promjene</button>
            </form>

            <div style="border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 15px;">
                <form method="POST" onsubmit="return confirm('Ovim ćete osloboditi ID broj <?php echo $display_id; ?> za novu kozu. Životinja će biti označena kao arhivirana/uklonjena iz stada. Želite li nastaviti?');">
                    <input type="hidden" name="archive_goat" value="1">
                    <button type="submit" class="btn btn-secondary" style="width: 100%; color: var(--text-muted); border-color: transparent;"><i class="fas fa-archive"></i> Ukloni životinju sa farme (Arhiviraj ID)</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.submenu-nav a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.submenu-nav a').forEach(a => a.classList.remove('active-sub'));
                this.classList.add('active-sub');
                document.querySelector(this.getAttribute('href')).scrollIntoView({ behavior: 'smooth' });
            });
        });

        // Modal funkcije
        function openEditModal() { document.getElementById('editGoatModal').style.display = 'flex'; }
        function closeEditModal() { document.getElementById('editGoatModal').style.display = 'none'; }

        function openLightbox(imageSrc) {
            if(!imageSrc) return;
            document.getElementById('lightbox-img').src = imageSrc;
            document.getElementById('lightbox').style.display = "flex";
        }
        function closeLightbox() { document.getElementById('lightbox').style.display = "none"; }
        
        <?php if($goat['gender'] == 'Zensko'): ?>
        const ctx = document.getElementById('milkChart').getContext('2d');
        const milkChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_dates); ?>,
                datasets: [{
                    label: 'Litara Mlijeka',
                    data: <?php echo json_encode($chart_data); ?>,
                    borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.15)',
                    fill: true, tension: 0.4, borderWidth: 3, pointBackgroundColor: '#10b981', pointRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } },
                    y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } }
                },
                plugins: { legend: { display: false } }
            }
        });
        <?php endif; ?>
    </script>
<?php include 'footer.php'; ?>
