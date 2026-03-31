<?php
require 'db.php';

$success_msg = "";
$error_msg = "";

// 1. DOHVATI SVE TABLICE (Dynamic Table Discovery)
$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}

$selected_table = isset($_GET['table']) ? $_GET['table'] : '';

// 2. OBRADA BRISANJA (Delete Record)
if (isset($_GET['delete_id']) && $selected_table) {
    $del_id = (int)$_GET['delete_id'];
    $sql = "DELETE FROM `$selected_table` WHERE id = $del_id";
    if ($conn->query($sql)) {
        $success_msg = "Zapis uspješno obrisan iz tablice $selected_table!";
        logAction($conn, "GOD MODE: Obrisao zapis (ID: $del_id) iz tablice $selected_table.");
    } else {
        $error_msg = "Greška pri brisanju: " . $conn->error;
    }
}

// 3. OBRADA UREĐIVANJA (Update Record)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record'])) {
    $update_id = (int)$_POST['record_id'];
    $table_to_update = $_POST['table_name'];
    
    $update_fields = [];
    foreach ($_POST as $key => $value) {
        // Preskoči sistemska polja forme
        if (in_array($key, ['update_record', 'record_id', 'table_name'])) continue;
        
        // Pripremi vrijednosti za bazu
        $safe_val = $conn->real_escape_string($value);
        $update_fields[] = "`$key` = '$safe_val'";
    }
    
    if (!empty($update_fields)) {
        $sql = "UPDATE `$table_to_update` SET " . implode(', ', $update_fields) . " WHERE id = $update_id";
        if ($conn->query($sql)) {
            $success_msg = "Zapis uspješno ažuriran!";
            logAction($conn, "GOD MODE: Ažuriran zapis (ID: $update_id) u tablici $table_to_update.");
        } else {
            $error_msg = "Greška pri ažuriranju: " . $conn->error;
        }
    }
}

include 'header.php';
?>

<style>
    .god-mode-warning { background: rgba(239, 68, 68, 0.15); border: 2px dashed #ef4444; color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center; }
    .table-selector { background: var(--bg-surface); padding: 20px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); margin-bottom: 20px; }
    
    .db-table-wrapper { overflow-x: auto; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); }
    .db-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .db-table th { background: rgba(0,0,0,0.3); color: var(--text-secondary); text-align: left; padding: 12px 15px; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
    .db-table td { padding: 10px 15px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-primary); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .db-table tr:hover { background: rgba(255,255,255,0.02); }
    
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; padding: 15px; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
</style>

<main class="content-area">
    <div class="god-mode-warning">
        <i class="fas fa-radiation-alt" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
        UPOZORENJE: NALAZITE SE U "GOD MODE" UREĐIVAČU BAZE!<br>
        <span style="font-size: 12px; font-weight: normal; color: var(--text-secondary);">Brisanje i uređivanje ovdje NEĆE pokrenuti automatske zalihe. Koristite samo za hitne popravke kvara.</span>
    </div>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success);'><p style='color:var(--accent-success); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger);'><p style='color:var(--accent-danger); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="table-selector">
        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
            <div style="flex: 1;">
                <label style="display:block; margin-bottom: 8px; color: var(--text-secondary);">Odaberite tablicu za uređivanje:</label>
                <select name="table" required style="width: 100%; padding: 12px; border-radius: 6px; background: var(--bg-surface-hover); color: white; border: 1px solid var(--border-color);">
                    <option value="">-- Odaberi bazu podataka --</option>
                    <?php foreach ($tables as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo ($selected_table == $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Učitaj</button>
        </form>
    </div>

    <?php 
    if ($selected_table && in_array($selected_table, $tables)): 
        $columns = [];
        $col_result = $conn->query("SHOW COLUMNS FROM `$selected_table`");
        $has_id = false;
        while ($col = $col_result->fetch_assoc()) {
            $columns[] = $col['Field'];
            if ($col['Field'] == 'id') $has_id = true; 
        }

        $order_by = $has_id ? "ORDER BY id DESC" : "";
        $data_result = $conn->query("SELECT * FROM `$selected_table` $order_by LIMIT 100");
    ?>
        <h3 style="margin-bottom: 15px;"><i class="fas fa-database"></i> Sadržaj tablice: <span style="color: var(--accent-info);"><?php echo $selected_table; ?></span></h3>
        
        <?php if (!$has_id): ?>
            <p style="color: var(--accent-warning);">Napomena: Ova tablica nema 'id' kolonu. Uređivanje i brisanje nije podržano.</p>
        <?php endif; ?>

        <div class="db-table-wrapper">
            <table class="db-table">
                <thead>
                    <tr>
                        <?php if ($has_id): ?><th style="width: 100px;">Akcije</th><?php endif; ?>
                        <?php foreach ($columns as $col): ?>
                            <th><?php echo htmlspecialchars($col ?? ''); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($data_result->num_rows > 0): while ($row = $data_result->fetch_assoc()): ?>
                        <tr>
                            <?php if ($has_id): ?>
                                <td>
                                    <button class="btn btn-primary btn-sm" onclick='openEditModal(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-edit"></i></button>
                                    <a href="edit.php?table=<?php echo $selected_table; ?>&delete_id=<?php echo $row['id']; ?>" class="btn btn-secondary btn-sm" style="color: var(--accent-danger); border-color: var(--accent-danger);" onclick="return confirm('Jeste li SIGURNI da želite trajno obrisati ovaj zapis?');"><i class="fas fa-trash"></i></a>
                                </td>
                            <?php endif; ?>
                            
                            <?php foreach ($columns as $col): ?>
                                <td title="<?php echo htmlspecialchars($row[$col] ?? ''); ?>">
                                    <?php echo htmlspecialchars($row[$col] ?? ''); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="<?php echo count($columns) + 1; ?>" style="text-align: center;">Tablica je prazna.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--accent-info); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-edit"></i> Uredi Zapis</h3>
        
        <form method="POST" id="dynamicEditForm">
            <input type="hidden" name="update_record" value="1">
            <input type="hidden" name="table_name" value="<?php echo htmlspecialchars($selected_table ?? ''); ?>">
            <input type="hidden" name="record_id" id="modal_record_id">
            
            <div id="dynamic_inputs"></div>
            
            <div style="display:flex; gap:10px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeEditModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1;">Spremi Promjene</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(rowData) {
        const container = document.getElementById('dynamic_inputs');
        container.innerHTML = ''; 
        
        document.getElementById('modal_record_id').value = rowData.id;

        for (const [key, value] of Object.entries(rowData)) {
            if (key === 'id') continue; 
            
            const group = document.createElement('div');
            group.className = 'form-group';
            group.style.marginBottom = '15px';
            
            const label = document.createElement('label');
            label.innerText = key;
            label.style.display = 'block';
            label.style.marginBottom = '5px';
            label.style.color = 'var(--text-secondary)';
            
            let input;
            
            if (value !== null && value.length > 50) {
                input = document.createElement('textarea');
                input.rows = 3;
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }
            
            input.name = key;
            input.value = value !== null ? value : '';
            input.style.width = '100%';
            input.style.padding = '10px';
            input.style.borderRadius = '6px';
            input.style.border = '1px solid var(--border-color)';
            input.style.background = 'var(--bg-surface-hover)';
            input.style.color = 'white';
            
            group.appendChild(label);
            group.appendChild(input);
            container.appendChild(group);
        }
        
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>
