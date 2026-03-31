<?php
require 'db.php';

$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_weight'])) {
    $id = (int)$_POST['id'];
    $goat_id = (int)$_POST['goat_id'];
    $log_date = $conn->real_escape_string($_POST['log_date']);
    $live_weight = (float)$_POST['live_weight'];
    $net_weight = !empty($_POST['net_weight']) ? (float)$_POST['net_weight'] : "NULL";
    $notes = $conn->real_escape_string($_POST['notes']);

    $sql = "UPDATE weight_logs 
            SET goat_id = $goat_id, 
                log_date = '$log_date', 
                live_weight = $live_weight, 
                net_weight = $net_weight, 
                notes = '$notes' 
            WHERE id = $id";
            
    if ($conn->query($sql) === TRUE) {
        logAction($conn, "Ažurirano vaganje (ID: $id) za kozu #$goat_id");
        header("Location: meat_history.php");
        exit();
    } else {
        $error_msg = "Greška u bazi: " . $conn->error;
    }
}

if (!isset($_GET['id']) && !isset($_POST['id'])) {
    header("Location: meat_history.php");
    exit();
}

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)$_POST['id'];
$log_query = $conn->query("SELECT * FROM weight_logs WHERE id = $edit_id");

if ($log_query->num_rows == 0) {
    die("Zapis nije pronađen. <a href='meat_history.php'>Vrati se nazad</a>");
}

$log_data = $log_query->fetch_assoc();
$all_goats = $conn->query("SELECT id, name, status FROM goats ORDER BY name ASC, id ASC");
?>

<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KozaFarm - Uredi Vaganje</title>
    <link rel="stylesheet" href="css/style.css?v=4">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

    <div class="mobile-topbar">
        <h2>🐐 KozaFarm</h2>
        <button id="sidebar-toggle"><i class="fas fa-bars"></i></button>
    </div>
    <div id="sidebar-overlay"></div>

    <nav class="sidebar" id="sidebar">
        <h2 class="desktop-logo">🐐 KozaFarm</h2>
        <ul>
            <li><a href="index.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li><a href="herd.php"><i class="fas fa-list"></i> Stado</a></li>
            <li><a href="barn.php"><i class="fas fa-mobile-alt"></i> Mlijeko</a></li>
            <li><a href="finance.php"><i class="fas fa-wallet"></i> Financije</a></li>
            <li><a href="events.php"><i class="fas fa-calendar-check"></i> Događaji</a></li>
            <li class="active"><a href="meat.php"><i class="fas fa-weight"></i> Meso</a></li>
            <li><a href="calendar.php"><i class="fas fa-calendar-alt"></i> Kalendar</a></li>
            <li style="margin-top: 50px;"><a href="settings.php"><i class="fas fa-cog"></i> Postavke</a></li>
            <li><a href="logout.php" style="color: var(--accent-danger);"><i class="fas fa-sign-out-alt"></i> Odjava</a></li>
        </ul>
    </nav>

    <main class="content-area">
        <header>
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="meat_history.php" class="btn btn-secondary" style="padding: 8px 12px;"><i class="fas fa-arrow-left"></i> Odustani</a>
                <h1 style="margin: 0;"><i class="fas fa-edit" style="color: var(--accent-info); margin-right: 10px;"></i> Uredi Zapis Vaganja</h1>
            </div>
        </header>

        <?php if($error_msg): ?>
            <div class="card mb-20" style="background-color: rgba(239, 68, 68, 0.1); border-color: var(--accent-danger); margin-bottom: 20px;">
                <p style="color: var(--accent-danger); font-weight: bold; text-align: center;"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="border-top: 4px solid var(--accent-info); max-width: 800px;">
            <form method="POST" action="edit_meat.php">
                <input type="hidden" name="id" value="<?php echo $log_data['id']; ?>">
                
                <div class="form-group">
                    <label>Odaberi Kozu / Jare</label>
                    <select name="goat_id" required>
                        <?php 
                        if ($all_goats->num_rows > 0) {
                            while($g = $all_goats->fetch_assoc()) {
                                $name = $g['name'] ? htmlspecialchars($g['name']) : 'Bez imena';
                                $selected = ($g['id'] == $log_data['goat_id']) ? 'selected' : '';
                                echo "<option value='{$g['id']}' $selected>#{$g['id']} - {$name} ({$g['status']})</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Datum vaganja</label>
                    <input type="date" name="log_date" required value="<?php echo $log_data['log_date']; ?>">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label style="color: var(--text-primary);"><i class="fas fa-balance-scale"></i> Živa Vaga (KG)</label>
                        <input type="number" step="0.1" min="0.1" name="live_weight" required value="<?php echo $log_data['live_weight']; ?>" style="font-size: 18px; text-align: center;">
                    </div>
                    <div class="form-group">
                        <label style="color: var(--accent-danger);"><i class="fas fa-drumstick-bite"></i> Neto Meso (KG)</label>
                        <input type="number" step="0.1" min="0.1" name="net_weight" value="<?php echo $log_data['net_weight']; ?>" placeholder="Opcionalno" style="font-size: 18px; text-align: center;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Napomena</label>
                    <input type="text" name="notes" value="<?php echo htmlspecialchars($log_data['notes']); ?>" placeholder="npr. Redovno vaganje jareta, prodano za meso...">
                </div>

                <input type="hidden" name="update_weight" value="1">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px; font-size: 18px; background-color: var(--accent-info);">
                    <i class="fas fa-save"></i> Ažuriraj Zapis
                </button>
            </form>
        </div>

    </main>

    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const toggleBtn = document.getElementById('sidebar-toggle');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        toggleBtn.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);
    </script>
</body>
</html>
