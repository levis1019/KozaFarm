<?php
require 'db.php';

$success_msg = "";
$error_msg = "";

// 1. OBRADA FORME: SPREMANJE TRANSAKCIJE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_transaction'])) {
    $date = $conn->real_escape_string($_POST['transaction_date']);
    $type = $conn->real_escape_string($_POST['transaction_type']);
    $category = $conn->real_escape_string($_POST['category']);
    $amount = (float)$_POST['amount'];
    $method = $conn->real_escape_string($_POST['payment_method']);
    $vendor = $conn->real_escape_string($_POST['vendor']);
    $description = $conn->real_escape_string($_POST['description']);

    $sql = "INSERT INTO finances (transaction_date, transaction_type, category, amount, payment_method, vendor, description) 
            VALUES ('$date', '$type', '$category', $amount, '$method', '$vendor', '$description')";
            
    if ($conn->query($sql) === TRUE) {
        $formatted_amount = number_format($amount, 2, '.', '');
        $partner_text = !empty($vendor) ? " [$vendor]" : "";
        $vrsta_tekst = ($type == 'income') ? "Zarada" : "Trošak";
        logAction($conn, "Dodana financijska transakcija: $vrsta_tekst - $category ($formatted_amount BAM)$partner_text");
        header("Location: finance.php?success=1");
        exit();
    } else {
        $error_msg = "Greška u bazi: " . $conn->error;
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_msg = "Transakcija je uspješno spremljena!";
}

// 2. IZRAČUN STATISTIKE (Prihodi, Troškovi, Stanje)
$income_query = $conn->query("SELECT SUM(amount) as total FROM finances WHERE transaction_type = 'income'");
$total_income = $income_query->fetch_assoc()['total'] ?? 0;

$expense_query = $conn->query("SELECT SUM(amount) as total FROM finances WHERE transaction_type = 'expense'");
$total_expense = $expense_query->fetch_assoc()['total'] ?? 0;

$balance = $total_income - $total_expense;

// Dohvaćanje transakcija za listu
$transactions = $conn->query("SELECT * FROM finances ORDER BY transaction_date DESC, id DESC LIMIT 50");

include 'header.php';
?>

<style>
    /* MOBILE FRIENDLY GRID */
    .finance-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 24px; }
    @media (min-width: 992px) {
        .finance-grid { grid-template-columns: 1fr 1fr; }
    }

    /* TOP HEADER CARDS */
    .value-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
    .v-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px; display: flex; flex-direction: column; justify-content: center; position: relative; overflow: hidden; }
    .v-card h3 { font-size: 13px; color: var(--text-muted); text-transform: uppercase; margin: 0 0 10px 0; letter-spacing: 0.5px; z-index: 1; }
    .v-card .amount { font-size: 28px; font-weight: 900; color: white; margin: 0; z-index: 1; display: flex; align-items: baseline; gap: 5px; }
    .v-card .amount span { font-size: 14px; color: var(--text-secondary); font-weight: normal; }
    .v-card .icon-bg { position: absolute; right: -15px; bottom: -20px; font-size: 80px; opacity: 0.05; z-index: 0; }
    
    .card-income { border-bottom: 4px solid #10b981; }
    .card-expense { border-bottom: 4px solid #ef4444; }
    .card-balance { border-bottom: 4px solid #3b82f6; background: linear-gradient(135deg, rgba(59,130,246,0.1) 0%, rgba(37,99,235,0.05) 100%); }

    /* QUICK CHIPS */
    .quick-chips { display: flex; gap: 10px; overflow-x: auto; margin-bottom: 20px; padding-bottom: 5px; scrollbar-width: none; }
    .quick-chips::-webkit-scrollbar { display: none; }
    .q-chip { background: var(--bg-surface-hover); border: 1px solid var(--border-color); color: var(--text-primary); padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
    .q-chip:hover { background: rgba(239, 68, 68, 0.1); border-color: #ef4444; color: #ef4444; }

    /* FORMS */
    .finance-input { font-size: 16px !important; padding: 14px !important; width: 100%; box-sizing: border-box; }

    /* TRANSACTION LIST */
    .transaction-list { list-style: none; padding: 0; margin: 0; }
    .transaction-list li { padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 10px; transition: background 0.2s; }
    .transaction-list li:hover { background-color: rgba(255,255,255,0.02); }
    .transaction-list li:last-child { border-bottom: none; }
    .t-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin-right: 12px; font-size: 16px; flex-shrink: 0; }
    .bg-income { background-color: rgba(16, 185, 129, 0.15); color: #10b981; }
    .bg-expense { background-color: rgba(239, 68, 68, 0.15); color: #ef4444; }
    
    @media (max-width: 600px) {
        .transaction-list li { flex-direction: column; align-items: flex-start; }
        .amount-display { align-self: flex-end; margin-top: 5px; font-size: 18px !important; }
    }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-wallet" style="color: var(--accent-info); margin-right: 10px;"></i> Računovodstvo</h1>
        <a href="transactions.php" class="btn btn-secondary"><i class="fas fa-list"></i> Pregled svih transakcija</a>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:15px;'><p style='color:var(--accent-success); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); padding:15px;'><p style='color:var(--accent-danger); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <div class="value-cards">
        <div class="v-card card-income">
            <i class="fas fa-arrow-down icon-bg"></i>
            <h3>Ukupne Zarade</h3>
            <div class="amount" style="color: #10b981;">
                + <?php echo number_format($total_income, 2, ',', '.'); ?> <span>BAM</span>
            </div>
        </div>
        <div class="v-card card-expense">
            <i class="fas fa-arrow-up icon-bg"></i>
            <h3>Ukupni Troškovi</h3>
            <div class="amount" style="color: #ef4444;">
                - <?php echo number_format($total_expense, 2, ',', '.'); ?> <span>BAM</span>
            </div>
        </div>
        <div class="v-card card-balance">
            <i class="fas fa-chart-line icon-bg"></i>
            <h3>Trenutno Stanje</h3>
            <div class="amount" style="color: <?php echo $balance >= 0 ? '#3b82f6' : '#ef4444'; ?>;">
                <?php echo number_format($balance, 2, ',', '.'); ?> <span>BAM</span>
            </div>
        </div>
    </div>

    <div class="finance-grid">
        
        <div>
            <div class="card" style="margin-bottom: 20px;">
                <h3 style="color: var(--text-primary); margin-bottom: 15px;"><i class="fas fa-plus-circle"></i> Nova Transakcija</h3>
                
                <div class="quick-chips">
                    <div class="q-chip" onclick="quickExpense('Veterinar')"><i class="fas fa-stethoscope"></i> Veterinar</div>
                    <div class="q-chip" onclick="quickExpense('Gorivo / Prijevoz')"><i class="fas fa-gas-pump"></i> Gorivo</div>
                    <div class="q-chip" onclick="quickExpense('Alat / Oprema')"><i class="fas fa-tools"></i> Oprema</div>
                </div>

                <form method="POST" action="finance.php">
                    <div class="form-grid" style="margin-bottom: 15px;">
                        <div class="form-group">
                            <label>Vrsta</label>
                            <select name="transaction_type" id="t_type" class="finance-input" required onchange="updateCategories()">
                                <option value="expense">Trošak (-)</option>
                                <option value="income">Zarada (+)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Datum</label>
                            <input type="date" name="transaction_date" class="finance-input" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>Kategorija</label>
                        <select name="category" id="t_category" class="finance-input" required></select>
                    </div>

                    <div class="form-grid" style="margin-bottom: 15px;">
                        <div class="form-group">
                            <label>Iznos (BAM) <span style="color:#ef4444;">*</span></label>
                            <input type="number" step="0.01" min="0.01" id="t_amount" name="amount" class="finance-input" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Način Plaćanja</label>
                            <select name="payment_method" class="finance-input">
                                <option value="Gotovina">Gotovina</option>
                                <option value="Banka">Banka</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>Dobavljač / Partner (Opcionalno)</label>
                        <input type="text" id="t_vendor" name="vendor" placeholder="npr. Poljo-apoteka, Ime kupca..." class="finance-input">
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Opis (Opcionalno)</label>
                        <input type="text" id="t_desc" name="description" placeholder="npr. Veterinar za Vilmu..." class="finance-input">
                    </div>

                    <input type="hidden" name="add_transaction" value="1">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px; font-size: 16px; font-weight: bold;">
                        <i class="fas fa-save"></i> Spremi Transakciju
                    </button>
                </form>
            </div>
        </div>

        <div>
            <div class="card" style="max-height: 600px; overflow-y: auto;">
                <h3 style="color: var(--text-secondary); margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-history"></i> Nedavne Transakcije (Zadnjih 50)</h3>
                
                <?php if ($transactions->num_rows > 0): ?>
                    <ul class="transaction-list">
                        <?php while($t = $transactions->fetch_assoc()): 
                            $isIncome = $t['transaction_type'] == 'income';
                            $iconClass = $isIncome ? 'bg-income' : 'bg-expense';
                            $iconFa = $isIncome ? 'fa-arrow-down' : 'fa-arrow-up';
                            $sign = $isIncome ? '+' : '-';
                            $colorClass = $isIncome ? '#10b981' : '#ef4444';
                            $dateFmt = date("d.m.Y", strtotime($t['transaction_date']));
                        ?>
                            <li>
                                <div style="display: flex; align-items: flex-start; width: 100%;">
                                    <div class="t-icon <?php echo $iconClass; ?>"><i class="fas <?php echo $iconFa; ?>"></i></div>
                                    <div style="flex-grow: 1;">
                                        <strong style="color: var(--text-primary); display: block; word-break: break-word; font-size: 15px;">
                                            <?php echo htmlspecialchars($t['category']); ?>
                                        </strong>
                                        <span style="font-size: 12px; color: var(--text-muted);">
                                            <?php echo $dateFmt; ?> | <?php echo htmlspecialchars($t['payment_method']); ?>
                                            <?php if(!empty($t['vendor'])): ?>
                                                &nbsp;|&nbsp; <i class="fas fa-building"></i> <span style="color: var(--accent-info);"><?php echo htmlspecialchars($t['vendor']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if(!empty($t['description'])): ?>
                                            <div style="font-size: 13px; color: var(--text-secondary); margin-top: 4px; word-break: break-word;"><?php echo htmlspecialchars($t['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="font-size: 16px; font-weight: bold; white-space: nowrap; color: <?php echo $colorClass; ?>;" class="amount-display">
                                    <?php echo $sign . number_format($t['amount'], 2, ',', '.'); ?> BAM
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: var(--text-muted);">
                        <i class="fas fa-receipt" style="font-size: 30px; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p>Još nema upisanih transakcija.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<script>
    // Dinamičke kategorije
    const categories = {
        expense: [
            "Veterinar", 
            "Lijekovi", 
            "Cijepljenje", 
            "Štala / Materijal", 
            "Alat / Oprema", 
            "Gorivo / Prijevoz",
            "Struja / Voda",
            "Kupnja Životinje",
            "Ostalo -"
        ],
        income: [
            "Prodaja Mesa",
            "Prodaja Životinja",
            "Poticaji / Subvencije",
            "Ostalo +"
        ]
    };

    function updateCategories(preselect = null) {
        const type = document.getElementById("t_type").value;
        const catSelect = document.getElementById("t_category");
        
        catSelect.innerHTML = "";
        
        categories[type].forEach(cat => {
            const opt = document.createElement("option");
            opt.value = cat;
            opt.textContent = cat;
            if(cat === preselect) opt.selected = true;
            catSelect.appendChild(opt);
        });
    }

    // Funkcija za Brze Tipke (Quick Chips)
    function quickExpense(categoryName) {
        document.getElementById("t_type").value = "expense";
        updateCategories(categoryName);
        document.getElementById("t_amount").focus();
    }

    window.onload = function() {
        updateCategories();
    };
</script>

<?php include 'footer.php'; ?>
