<?php
require 'db.php';

$success_msg = ""; $error_msg = "";

// 1. Dodavanje novog kupca
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_customer'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $address = $conn->real_escape_string($_POST['address']);
    $debt_limit = (float)$_POST['debt_limit'];

    $sql = "INSERT INTO customers (name, phone, address, debt_limit) VALUES ('$name', '$phone', '$address', $debt_limit)";
    if ($conn->query($sql) === TRUE) {
        logAction($conn, "Dodan novi kupac: $name (Limit: $debt_limit BAM)");
        $success_msg = "Novi kupac je uspješno dodan!";
    } else {
        $error_msg = "Greška: " . $conn->error;
    }
}

// 2. Brisanje kupca
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_customer_id'])) {
    $del_id = (int)$_POST['delete_customer_id'];
    $del_sql = "DELETE FROM customers WHERE id = $del_id";
    if ($conn->query($del_sql) === TRUE) {
        logAction($conn, "Obrisan kupac (ID: $del_id)");
        $success_msg = "Kupac i njegova povijest su uspješno obrisani!";
    } else {
        $error_msg = "Greška pri brisanju: " . $conn->error;
    }
}

// 3. Dohvati sve kupce i izračunaj njihov trenutni dug
$customers_sql = "
    SELECT c.*, 
    (SELECT COALESCE(SUM(amount), 0) FROM customer_ledger WHERE customer_id = c.id AND transaction_type = 'debt') as total_debt,
    (SELECT COALESCE(SUM(amount), 0) FROM customer_ledger WHERE customer_id = c.id AND transaction_type = 'payment') as total_paid
    FROM customers c
    ORDER BY name ASC
";
$customers = $conn->query($customers_sql);

// Računanje "Novca na ulici"
$total_owed_to_farm = 0;
$customers_data = [];
if ($customers->num_rows > 0) {
    while($c = $customers->fetch_assoc()) {
        $balance = $c['total_debt'] - $c['total_paid'];
        if ($balance > 0) {
            $total_owed_to_farm += $balance;
        }
        $customers_data[] = $c;
    }
}

include 'header.php';
?>

<style>
    /* PREMIUM WIDGET DESIGN */
    .street-money-widget {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        padding: 24px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }
    .street-money-widget::before {
        content: '';
        position: absolute;
        top: 0; left: 0; width: 4px; height: 100%;
        background: #f59e0b;
    }
    .sm-icon-wrapper {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        width: 65px; height: 65px;
        border-radius: 50%;
        display: flex; justify-content: center; align-items: center;
        font-size: 28px;
        flex-shrink: 0;
    }
    .sm-content { flex-grow: 1; }
    .sm-content h3 { margin: 0; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
    .sm-content .amount { margin: 5px 0 0 0; font-size: 38px; font-weight: 900; color: white; display: flex; align-items: baseline; gap: 8px;}
    .sm-content .amount span { font-size: 16px; color: #f59e0b; font-weight: bold; }
    .sm-bg-icon {
        position: absolute;
        right: -20px;
        bottom: -30px;
        font-size: 140px;
        color: rgba(245, 158, 11, 0.03);
        z-index: 0;
        pointer-events: none;
    }

    /* SEARCH */
    .search-input { width: 100%; padding: 15px; font-size: 16px; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-surface-hover); color: var(--text-primary); margin-bottom: 20px; box-sizing: border-box; }
    
    /* MODAL STYLES */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; position: relative; }
    .close-modal-btn { position: absolute; top: 15px; right: 15px; background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer; transition: 0.2s; }
    .close-modal-btn:hover { color: white; }

    /* MOBILE FRIENDLY CARDS */
    @media (max-width: 768px) {
        .street-money-widget { padding: 20px 15px; }
        .sm-content .amount { font-size: 30px; }
        
        .herd-table thead { display: none; }
        .herd-table tbody tr { display: flex; flex-direction: column; margin-bottom: 20px; background: linear-gradient(145deg, var(--bg-surface-hover), var(--bg-surface)); border-radius: 16px; padding: 15px; border: 1px solid var(--border-color); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .herd-table td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed rgba(255,255,255,0.05); text-align: right; font-size: 15px !important; }
        .herd-table td::before { content: attr(data-label); font-weight: 600; color: var(--text-muted); text-align: left; margin-right: 15px; }
        .herd-table td:last-child { border-bottom: none; justify-content: stretch; margin-top: 10px; padding-bottom: 0 !important; }
        .herd-table td:last-child::before { display: none; }
        .action-buttons-wrapper { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; width: 100%; }
        .action-buttons-wrapper .btn { flex: 1; text-align: center; justify-content: center; }
    }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-users" style="color: var(--accent-info); margin-right: 10px;"></i> Imenik i Naplata</h1>
        <button class="btn btn-primary" onclick="openCustomerModal()" style="background: var(--accent-info); border: none;"><i class="fas fa-user-plus"></i> Dodaj Kupca</button>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:12px;'><p style='color:var(--accent-success); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); padding:12px;'><p style='color:var(--accent-danger); margin:0; font-weight:bold; text-align:center;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="street-money-widget">
        <i class="fas fa-hand-holding-usd sm-bg-icon"></i>
        <div class="sm-icon-wrapper" style="z-index: 1;">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="sm-content" style="z-index: 1;">
            <h3>Novac na ulici (Nenaplaćena roba)</h3>
            <div class="amount">
                <?php echo number_format($total_owed_to_farm, 2); ?> <span>BAM</span>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 0; background: transparent; border: none;">
        
        <input type="text" id="searchInput" class="search-input" placeholder="Pretraži kupce po imenu..." onkeyup="filterCustomers()">
        
        <?php if (!empty($customers_data)): ?>
            <div class="table-responsive" style="max-height: 800px; overflow-y: auto; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
                <table class="herd-table" style="width: 100%; border-collapse: separate; border-spacing: 0;" id="customersTable">
                    <thead style="background: var(--bg-surface-hover); position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th style="padding: 15px;">Kupac</th>
                            <th>Stanje</th>
                            <th style="text-align: right; padding: 15px;">Akcije</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($customers_data as $c): 
                            $balance = $c['total_debt'] - $c['total_paid'];
                            $limit = (float)$c['debt_limit'];
                            $isOverLimit = ($limit > 0 && $balance > $limit);
                            $rowStyle = $isOverLimit ? "background: linear-gradient(90deg, rgba(239, 68, 68, 0.1) 0%, var(--bg-surface) 100%); border-left: 4px solid var(--accent-danger);" : "background: var(--bg-surface);";
                        ?>
                            <tr class="customer-row" style="<?php echo $rowStyle; ?>">
                                <td data-label="Kupac" style="padding: 15px;">
                                    <strong class="c-name" style="font-size: 16px;"><?php echo htmlspecialchars($c['name']); ?></strong><br>
                                    <span style="font-size: 13px; color: var(--text-muted);"><i class="fas fa-phone"></i> <?php echo $c['phone'] ? htmlspecialchars($c['phone']) : '-'; ?></span>
                                    <?php if($limit > 0): ?>
                                        <div style="font-size: 12px; color: <?php echo $isOverLimit ? 'var(--accent-danger)' : 'var(--text-secondary)'; ?>; margin-top: 4px; font-weight:bold;">
                                            <i class="fas fa-shield-alt"></i> Limit: <?php echo number_format($limit, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <td data-label="Trenutni Dug" style="font-weight: bold; font-size: 18px; color: <?php echo $balance > 0 ? 'var(--accent-danger)' : 'var(--accent-success)'; ?>;">
                                    <?php if($balance > 0): ?>
                                        - <?php echo number_format($balance, 2); ?> BAM
                                    <?php elseif($balance < 0): ?>
                                        + <?php echo number_format(abs($balance), 2); ?> BAM <span style="font-size:12px; font-weight:normal;">(Preplata)</span>
                                    <?php else: ?>
                                        0.00 BAM
                                    <?php endif; ?>
                                </td>
                                
                                <td style="text-align: right; padding: 15px;">
                                    <div class="action-buttons-wrapper">
                                        <a href="customer_ledger.php?id=<?php echo $c['id']; ?>" class="btn btn-secondary" style="font-size: 13px; padding: 8px 12px;"><i class="fas fa-book-open"></i> Knjiga</a>

                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Brisanjem kupca brišete i cijelu njegovu povijest. Jeste li sigurni?');">
                                            <input type="hidden" name="delete_customer_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-primary" style="background-color: var(--accent-danger); border-color: var(--accent-danger); font-size: 13px; padding: 8px 12px;" title="Obriši kupca">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; padding: 30px; background: var(--bg-surface); border-radius: 12px; border: 1px solid var(--border-color);">Nema dodanih kupaca u imeniku.</p>
        <?php endif; ?>
    </div>
</main>

<div class="modal-overlay" id="addCustomerModal">
    <div class="modal-content">
        <button type="button" class="close-modal-btn" onclick="closeCustomerModal()"><i class="fas fa-times"></i></button>
        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--accent-info); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">
            <i class="fas fa-user-plus"></i> Novi Kupac
        </h3>
        
        <form method="POST">
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="color: var(--text-primary); margin-bottom: 5px; display:block;">Ime i Prezime <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" required placeholder="npr. Petar Horvat" style="padding:14px; width:100%; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-surface-hover); color: white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="color: var(--text-primary); margin-bottom: 5px; display:block;">Broj Mobitela</label>
                <input type="text" name="phone" placeholder="063 123 456" style="padding:14px; width:100%; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-surface-hover); color: white;">
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="color: var(--text-primary); margin-bottom: 5px; display:block;">Adresa / Napomena</label>
                <input type="text" name="address" placeholder="Ulica, Grad..." style="padding:14px; width:100%; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-surface-hover); color: white;">
            </div>
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="color: var(--text-primary); margin-bottom: 5px; display:block;">Limit Duga (BAM)</label>
                <input type="number" step="0.01" min="0" name="debt_limit" value="0.00" placeholder="npr. 150" style="padding:14px; width:100%; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-surface-hover); color: white;">
                <small style="color: var(--text-muted); margin-top: 5px; display:block;">Upišite 0 ako kupac nema ograničenje.</small>
            </div>
            <input type="hidden" name="add_customer" value="1">
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeCustomerModal()">Odustani</button>
                <button type="submit" class="btn btn-primary" style="flex:1; background: var(--accent-info); border: none;"><i class="fas fa-save"></i> Dodaj Kupca</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Search Functionality
    function filterCustomers() {
        let input = document.getElementById("searchInput").value.toLowerCase();
        let rows = document.querySelectorAll(".customer-row");

        rows.forEach(row => {
            let name = row.querySelector(".c-name").innerText.toLowerCase();
            if (name.includes(input)) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
    }

    // Modal Control
    function openCustomerModal() {
        document.getElementById('addCustomerModal').style.display = 'flex';
    }
    
    function closeCustomerModal() {
        document.getElementById('addCustomerModal').style.display = 'none';
    }
</script>

<?php include 'footer.php'; ?>
