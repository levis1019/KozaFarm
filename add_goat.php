<?php
require 'db.php';

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $conn->real_escape_string($_POST['name']);
    $gender = $conn->real_escape_string($_POST['gender']);
    $breed = $conn->real_escape_string($_POST['breed']);
    $birth_date = $conn->real_escape_string($_POST['birth_date']);
    $status = $conn->real_escape_string($_POST['status']);
    $notes = $conn->real_escape_string($_POST['notes']);
    
    $mother_id = !empty($_POST['mother_id']) ? (int)$_POST['mother_id'] : "NULL";
    $father_id = !empty($_POST['father_id']) ? (int)$_POST['father_id'] : "NULL";

    // Handle Image Upload
    $image_path = "NULL";
    if (!empty($_FILES['goat_image']['name'])) {
        $target_dir = "images/goats/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["goat_image"]["name"], PATHINFO_EXTENSION));
        $new_file_name = uniqid("goat_") . "." . $file_extension;
        $target_file = $target_dir . $new_file_name;

        $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($file_extension, $allowed_types)) {
            if (move_uploaded_file($_FILES["goat_image"]["tmp_name"], $target_file)) {
                $image_path = "'" . $conn->real_escape_string($new_file_name) . "'";
            }
        }
    }

    $sql = "INSERT INTO goats (name, gender, breed, birth_date, status, mother_id, father_id, notes, image_path) 
            VALUES ('$name', '$gender', '$breed', '$birth_date', '$status', $mother_id, $father_id, '$notes', $image_path)";

    if ($conn->query($sql) === TRUE) {
        $new_id = $conn->insert_id;
        $display_name = $name ? $name : 'Bez imena';
        logAction($conn, "Dodana nova životinja u stado: #$new_id ($display_name)");
        
        $success_msg = "Životinja #$new_id uspješno dodana u stado!";
    } else {
        $error_msg = "Greška u bazi: " . $conn->error;
    }
}

// Dohvati roditelje za dropdown
$mothers = $conn->query("SELECT id, name FROM goats WHERE gender = 'Zensko' ORDER BY id DESC");
$fathers = $conn->query("SELECT id, name FROM goats WHERE gender = 'Musko' ORDER BY id DESC");

include 'header.php';
?>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-plus-circle" style="color: var(--accent-info); margin-right: 10px;"></i> Dodaj Novu Životinju</h1>
        <a href="herd.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Nazad na Stado</a>
    </header>

    <?php if($success_msg): ?>
        <div class="card mb-20" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-success); margin-bottom: 24px;">
            <p style="color: var(--accent-success); font-weight: bold; text-align: center; margin: 0; font-size: 16px;"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></p>
        </div>
    <?php endif; ?>

    <?php if($error_msg): ?>
        <div class="card mb-20" style="background-color: rgba(239, 68, 68, 0.1); border-color: var(--accent-danger); margin-bottom: 24px;">
            <p style="color: var(--accent-danger); font-weight: bold; text-align: center; margin: 0;"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></p>
        </div>
    <?php endif; ?>

    <div class="card" style="border-top: 4px solid var(--accent-info); max-width: 800px;">
        <form method="POST" action="add_goat.php" enctype="multipart/form-data">
            
            <div class="form-grid" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label>Ime (Opcionalno)</label>
                    <input type="text" name="name" placeholder="npr. Bjelka" style="padding: 14px;">
                </div>
                
                <div class="form-group">
                    <label>Fotografija (Opcionalno)</label>
                    <input type="file" name="goat_image" accept="image/*" style="padding: 10px; background: var(--bg-surface-hover); border-radius: var(--radius-md); width: 100%; box-sizing: border-box; color: var(--text-secondary);">
                </div>
            </div>

            <div class="form-grid" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label>Spol <span style="color:var(--accent-danger);">*</span></label>
                    <select name="gender" required style="padding: 14px;">
                        <option value="Zensko">Žensko</option>
                        <option value="Musko">Muško</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Datum Rođenja <span style="color:var(--accent-danger);">*</span></label>
                    <input type="date" name="birth_date" required value="<?php echo date('Y-m-d'); ?>" style="padding: 14px;">
                </div>
            </div>

            <div class="form-grid" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label>Pasmina / Rasa</label>
                    <input type="text" name="breed" placeholder="npr. Alpina, Sanska..." style="padding: 14px;">
                </div>
                
                <div class="form-group">
                    <label>Status <span style="color:var(--accent-danger);">*</span></label>
                    <select name="status" required style="padding: 14px;">
                        <option value="Mlado">Mlado</option>
                        <option value="Mlijecna">Mliječna</option>
                        <option value="Trudna">Trudna</option>
                        <option value="Suha">Suha</option>
                    </select>
                </div>
            </div>

            <h3 style="color: var(--text-secondary); border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin: 25px 0 15px 0; font-size: 16px;">Rodovnik (Opcionalno)</h3>
            
            <div class="form-grid" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label><i class="fas fa-venus" style="color: pink;"></i> Majka</label>
                    <select name="mother_id" style="padding: 14px;">
                        <option value="">-- Nepoznato --</option>
                        <?php while($m = $mothers->fetch_assoc()): ?>
                            <option value="<?php echo $m['id']; ?>">#<?php echo $m['id'] . ' - ' . htmlspecialchars($m['name'] ? $m['name'] : 'Bez imena'); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-mars" style="color: #3b82f6;"></i> Otac</label>
                    <select name="father_id" style="padding: 14px;">
                        <option value="">-- Nepoznato --</option>
                        <?php while($f = $fathers->fetch_assoc()): ?>
                            <option value="<?php echo $f['id']; ?>">#<?php echo $f['id'] . ' - ' . htmlspecialchars($f['name'] ? $f['name'] : 'Bez imena'); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Napomene (Vidljivo u tablici)</label>
                <input type="text" name="notes" placeholder="npr. Kupljena od susjeda, rođena s manjom težinom..." style="padding: 14px;">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px; font-size: 18px; margin-top: 20px;">
                <i class="fas fa-save"></i> Spremi i Dodaj u Stado
            </button>
        </form>
    </div>

</main>

<?php include 'footer.php'; ?>
