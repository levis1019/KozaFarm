<?php
require 'db.php';


$success_msg = ""; $error_msg = "";

// --- 2. OBRADA FORMI ---

// A) Novi Dobavljač
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_supplier'])) {
    $name = $conn->real_escape_string(trim($_POST['name']));
    
    $check = $conn->query("SELECT id FROM suppliers WHERE name = '$name'");
    if ($check && $check->num_rows > 0) {
        $error_msg = "Dobavljač '$name' već postoji!";
    } else {
        if ($conn->query("INSERT INTO suppliers (name) VALUES ('$name')")) {
            $success_msg = "Dobavljač '$name' uspješno dodan!";
        } else { 
            $error_msg = "Greška: " . $conn->error; 
        }
    }
}

// B) Brisanje Dobavljača
if (isset($_GET['delete_supplier'])) {
    $del_id = (int)$_GET['delete_supplier'];
    if ($conn->query("DELETE FROM suppliers WHERE id = $del_id")) {
        // Brisanje svih cijena vezanih za ovog dobavljača
        $conn->query("DELETE FROM item_catalog_prices WHERE supplier_id = $del_id");
        $success_msg = "Dobavljač i sve njegove cijene su obrisani.";
    }
}

// C) Nova Cijena u Katalog
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_price'])) {
    $supplier_id = (int)$_POST['supplier_id'];
    
    // Čišćenje i formatiranje imena artikla (npr. kukuruz -> Kukuruz)
    $item_name = trim($conn->real_escape_string($_POST['item_name']));
    $item_name = ucwords(strtolower($item_name)); 
    
    $category = $conn->real_escape_string($_POST['category']);
    $package_price = (float)$_POST['package_price'];
    $package_size = (float)$_POST['package_size'];
    $unit = $conn->real_escape_string($_POST['unit']);
    $date = $conn->real_escape_string($_POST['last_updated']);
    $notes = $conn->real_escape_string($_POST['notes']);

    // Automatska računica cijene po jedinici
    $price_per_unit = ($package_size > 0) ? ($package_price / $package_size) : $package_price;

    $check = $conn->query("SELECT id FROM item_catalog_prices WHERE supplier_id = $supplier_id AND item_name = '$item_name'");
    
    if ($check && $check->num_rows > 0) {
        $price_id = $check->fetch_assoc()['id'];
        $conn->query("UPDATE item_catalog_prices SET category='$category', package_price=$package_price, package_size=$package_size, price_per_unit=$price_per_unit, unit='$unit', last_updated='$date', notes='$notes' WHERE id=$price_id");
        $success_msg = "Cijena za '$item_name' uspješno ažurirana!";
    } else {
        $conn->query("INSERT INTO item_catalog_prices (supplier_id, item_name, category, package_price, package_size, price_per_unit, unit, last_updated, notes) VALUES ($supplier_id, '$item_name', '$category', $package_price, $package_size, $price_per_unit, '$unit', '$date', '$notes')");
        $success_msg = "Nova cijena uspješno dodana u katalog!";
    }
}

// D) Brisanje Cijene iz Kataloga
if (isset($_GET['delete_price'])) {
    $del_id = (int)$_GET['delete_price'];
    if ($conn->query("DELETE FROM item_catalog_prices WHERE id = $del_id")) {
        $success_msg = "Cijena uspješno obrisana iz kataloga.";
    }
}

// --- 3. DOHVAĆANJE PODATAKA ---
$suppliers = [];
$suppliers_res = $conn->query("SELECT * FROM suppliers ORDER BY name ASC");
if ($suppliers_res) {
    while ($s = $suppliers_res->fetch_assoc()) { $suppliers[] = $s; }
}

$existing_items = [];
$items_res = $conn->query("SELECT DISTINCT item_name FROM item_catalog_prices ORDER BY item_name ASC");
if ($items_res) {
    while ($i = $items_res->fetch_assoc()) { $existing_items[] = $i['item_name']; }
}

// Dohvaćanje cijena i grupiranje za JS Modal
$prices_res = $conn->query("
    SELECT p.*, s.name as supplier_name 
    FROM item_catalog_prices p
    JOIN suppliers s ON p.supplier_id = s.id
    ORDER BY p.item_name ASC, p.price_per_unit ASC
");

$catalog = [];
$item_categories = [];
if ($prices_res && $prices_res->num_rows > 0) {
    while($row = $prices_res->fetch_assoc()) {
        $catalog[$row['item_name']][] = $row;
        $item_categories[$row['item_name']] = $row['category'];
    }
}

// Prebacivanje u JSON kako bi JavaScript mogao dinamički puniti Modal
$catalog_json = json_encode($catalog, JSON_HEX_APOS | JSON_HEX_QUOT);

include 'header.php';
?>

<style>
    /* KATEGORIJE - FILTERI */
    .filter-chips { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none; }
    .filter-chips::-webkit-scrollbar { display: none; }
    .f-chip { background: var(--bg-surface-hover); border: 1px solid var(--border-color); color: var(--text-primary); padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s; }
    .f-chip.active { background: rgba(59, 130, 246, 0.2); border-color: #3b82f6; color: #3b82f6; }

    /* MREŽA ARTIKALA */
    .item-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
    .item-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 20px; text-align: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-top: 4px solid var(--accent-info); }
    .item-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.3); background: var(--bg-surface-hover); }
    .item-icon { font-size: 30px; color: var(--text-muted); margin-bottom: 10px; }
    .item-title { font-size: 18px; color: white; font-weight: bold; margin: 0 0 5px 0; }
    .item-count { font-size: 12px; color: var(--text-secondary); background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 12px; display: inline-block; }

    /* TABLICA CIJENA U MODALU */
    .price-list { list-style: none; padding: 0; margin: 0; }
    .price-item { padding: 15px; border-bottom: 1px dashed rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; }
    .price-item:last-child { border-bottom: none; }
    .pi-best-buy { background: linear-gradient(90deg, rgba(16, 185, 129, 0.1) 0%, transparent 100%); border-left: 3px solid #10b981; }
    
    .supplier-name { font-size: 15px; color: var(--text-primary); font-weight: bold; margin-bottom: 3px; display:flex; align-items:center; gap:8px;}
    .package-info { font-size: 12px; color: var(--text-muted); background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 4px;}
    
    .unit-price { text-align: right; min-width: 90px;}
    .unit-price-amount { font-size: 20px; font-weight: 900; }
    .unit-price-amount.best { color: #10b981; }
    .unit-price-amount.normal { color: white; }
    .unit-price-label { font-size: 12px; color: var(--text-secondary); }
    .badge-best { background: #10b981; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: bold; text-transform: uppercase; }

    /* POPIS DOBAVLJAČA LISTA */
    .supplier-list { list-style: none; padding: 0; margin: 0; }
    .supplier-list-item { padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface-hover); margin-bottom: 8px; border-radius: 8px; }

    /* MODALS */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
    .modal-content { background: var(--bg-surface); border: 1px solid var(--border-color); padding: 25px; border-radius: var(--radius-lg); width: 100%; max-width: 550px; max-height: 90vh; overflow-y: auto; }
</style>

<main class="content-area">
    <header style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <h1 style="margin: 0;"><i class="fas fa-tags" style="color: var(--accent-info); margin-right: 10px;"></i> Katalog Cijena</h1>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-secondary" onclick="openModal('modal-manage-suppliers')"><i class="fas fa-building"></i> Popis Dobavljača</button>
            <button class="btn btn-primary" style="background: var(--accent-info); border: none;" onclick="openModal('modal-price')"><i class="fas fa-plus-circle"></i> Unesi Cijenu</button>
        </div>
    </header>

    <?php if($success_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-success); padding:12px;'><p style='color:var(--accent-success); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-check'></i> $success_msg</p></div>"; ?>
    <?php if($error_msg) echo "<div class='card mb-20' style='border:1px solid var(--accent-danger); padding:12px;'><p style='color:var(--accent-danger); margin:0; text-align:center; font-weight:bold;'><i class='fas fa-exclamation-triangle'></i> $error_msg</p></div>"; ?>

    <?php if (!empty($catalog)): ?>
        
        <div class="filter-chips">
            <div class="f-chip active" onclick="filterItems('Sve', this)">Sve</div>
            <div class="f-chip" onclick="filterItems('Hrana', this)">Hrana</div>
            <div class="f-chip" onclick="filterItems('Lijekovi', this)">Lijekovi</div>
            <div class="f-chip" onclick="filterItems('Oprema', this)">Oprema / Materijal</div>
            <div class="f-chip" onclick="filterItems('Ostalo', this)">Ostalo</div>
        </div>

        <div class="item-grid" id="item-grid">
            <?php foreach($catalog as $item_name => $prices): 
                $cat = $item_categories[$item_name];
                $icon = "fa-box";
                if ($cat == 'Hrana') $icon = "fa-wheat";
                if ($cat == 'Lijekovi') $icon = "fa-pills";
                if ($cat == 'Oprema') $icon = "fa-tools";
            ?>
                <div class="item-card" data-category="<?php echo htmlspecialchars($cat); ?>" onclick='openItemDetails("<?php echo addslashes($item_name); ?>")'>
                    <div class="item-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                    <h3 class="item-title"><?php echo htmlspecialchars($item_name); ?></h3>
                    <div class="item-count"><?php echo count($prices); ?> dostupnih ponuda</div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div style="background: var(--bg-surface); padding: 40px 20px; text-align: center; border-radius: var(--radius-lg); border: 1px solid var(--border-color); color: var(--text-muted);">
            <i class="fas fa-box-open" style="font-size: 40px; margin-bottom: 15px; opacity: 0.5;"></i>
            <p style="font-size: 16px;">Katalog artikala je prazan.</p>
            <p style="font-size: 14px;">1. Dodajte dobavljača (Popis Dobavljača)<br>2. Unesite novu cijenu za artikl.<br>Sustav će automatski stvoriti preglednu karticu za svaki artikl!</p>
        </div>
    <?php endif; ?>

</main>


<div class="modal-overlay" id="modal-item-details">
    <div class="modal-content">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px;">
            <div>
                <h3 id="detail-item-title" style="margin:0; color:var(--accent-info); font-size: 22px;">Ime Artikla</h3>
                <div id="detail-item-category" style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Kategorija</div>
            </div>
            <button class="btn btn-secondary" onclick="closeModal('modal-item-details')" style="padding: 5px 10px;"><i class="fas fa-times"></i></button>
        </div>
        
        <ul class="price-list" id="detail-price-list">
            </ul>
    </div>
</div>

<div class="modal-overlay" id="modal-manage-suppliers">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;">
            <i class="fas fa-building"></i> Upravljanje Dobavljačima
        </h3>
        
        <form method="POST" style="margin-bottom: 20px; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
            <label style="font-size: 12px; color: var(--text-secondary); margin-bottom: 5px; display:block;">Dodaj novu trgovinu/apoteku</label>
            <div style="display:flex; gap:10px;">
                <input type="text" name="name" required placeholder="Ime trgovine..." style="flex:1; padding:10px; border-radius:6px; background:var(--bg-surface); color:white; border:1px solid var(--border-color);">
                <input type="hidden" name="add_supplier" value="1">
                <button type="submit" class="btn btn-primary" style="background:var(--accent-success); border:none;"><i class="fas fa-plus"></i> Dodaj</button>
            </div>
        </form>

        <div style="max-height: 300px; overflow-y: auto;">
            <?php if(empty($suppliers)): ?>
                <p style="text-align: center; color: var(--text-muted); font-size: 13px;">Nema dobavljača.</p>
            <?php else: ?>
                <ul class="supplier-list">
                    <?php foreach($suppliers as $s): ?>
                        <li class="supplier-list-item">
                            <strong><i class="fas fa-store-alt" style="color:var(--text-muted); margin-right:8px;"></i> <?php echo htmlspecialchars($s['name']); ?></strong>
                            <a href="dobavljac.php?delete_supplier=<?php echo $s['id']; ?>" class="btn btn-secondary" style="padding: 5px 10px; color: var(--accent-danger); border-color: transparent;" onclick="return confirm('Brisanjem trgovine brišete i sve njezine cijene iz kataloga! Želite li nastaviti?');" title="Obriši dobavljača">
                                <i class="fas fa-trash"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <button type="button" class="btn btn-secondary" style="width:100%; margin-top: 15px;" onclick="closeModal('modal-manage-suppliers')">Zatvori Popis</button>
    </div>
</div>

<div class="modal-overlay" id="modal-price">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--accent-info); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;"><i class="fas fa-tags"></i> Unos Cijene u Katalog</h3>
        
        <?php if(empty($suppliers)): ?>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; padding: 15px; border-radius: 8px; color: #ef4444; text-align: center;">
                Prvo morate dodati barem jednog dobavljača kako biste mogli unositi cijene.
            </div>
            <button type="button" class="btn btn-secondary" style="width:100%; margin-top: 15px;" onclick="closeModal('modal-price')">Zatvori</button>
        <?php else: ?>
            <form method="POST">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Artikl <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="item_name" list="item-suggestions" required placeholder="npr. Kukuruz" autocomplete="off" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <datalist id="item-suggestions">
                        <?php foreach($existing_items as $item): ?>
                            <option value="<?php echo htmlspecialchars($item); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div style="display:flex; gap:15px; margin-bottom: 15px;">
                    <div class="form-group" style="flex:1;">
                        <label>Dobavljač <span style="color:#ef4444;">*</span></label>
                        <select name="supplier_id" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Kategorija</label>
                        <select name="category" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                            <option value="Hrana">Hrana</option>
                            <option value="Lijekovi">Lijekovi</option>
                            <option value="Oprema">Oprema / Materijal</option>
                            <option value="Ostalo">Ostalo</option>
                        </select>
                    </div>
                </div>

                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px dashed var(--border-color); margin-bottom: 15px;">
                    <label style="color: var(--text-secondary); display:block; margin-bottom: 10px; font-size: 13px;">Podaci o pakiranju (Sustav će izračunati cijenu po jedinici)</label>
                    
                    <div style="display:flex; gap:10px; margin-bottom: 10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Ukupna Cijena (BAM) <span style="color:#ef4444;">*</span></label>
                            <input type="number" step="0.01" min="0" name="package_price" required placeholder="npr. 20.00" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Količina u paketu <span style="color:#ef4444;">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="package_size" required placeholder="npr. 25" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Mjerna jedinica <span style="color:#ef4444;">*</span></label>
                        <select name="unit" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                            <option value="KG">KG</option>
                            <option value="Litar">Litar</option>
                            <option value="Komad">Komad</option>
                            <option value="Bala">Bala</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-bottom: 25px;">
                    <div class="form-group" style="flex:1;">
                        <label>Datum provjere</label>
                        <input type="date" name="last_updated" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Napomena (Opcionalno)</label>
                        <input type="text" name="notes" placeholder="npr. Cijena na vreću" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    </div>
                </div>
                
                <input type="hidden" name="add_price" value="1">
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('modal-price')">Odustani</button>
                    <button type="submit" class="btn btn-primary" style="flex:1; background: var(--accent-info); border: none;"><i class="fas fa-tags"></i> Spremi Cijenu</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    // Dohvaćamo JSON podatke generirane u PHP-u
    const catalogData = <?php echo $catalog_json; ?>;

    // Funkcije za otvaranje i zatvaranje Modala
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    // Funkcija za Filtriranje Mreže Artikala
    function filterItems(category, btnElement) {
        // Ažuriraj aktivni gumb
        document.querySelectorAll('.f-chip').forEach(btn => btn.classList.remove('active'));
        btnElement.classList.add('active');

        // Filtriraj kartice
        const cards = document.querySelectorAll('.item-card');
        cards.forEach(card => {
            if (category === 'Sve' || card.getAttribute('data-category') === category) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // Funkcija za otvaranje detalja pojedinog artikla
    function openItemDetails(itemName) {
        document.getElementById('detail-item-title').innerText = itemName;
        
        const listContainer = document.getElementById('detail-price-list');
        listContainer.innerHTML = ''; // Očisti stare rezultate

        const prices = catalogData[itemName];
        
        if (prices && prices.length > 0) {
            document.getElementById('detail-item-category').innerText = "Kategorija: " + prices[0].category;

            prices.forEach((p, index) => {
                const isBestBuy = index === 0; // Prvi je uvijek najeftiniji
                const itemClass = isBestBuy ? 'pi-best-buy' : '';
                const priceClass = isBestBuy ? 'best' : 'normal';
                const badgeHtml = isBestBuy ? '<span class="badge-best">Najjeftinije</span>' : '';
                
                // Formatiranje brojeva na 2 i 3 decimale
                const packageSize = parseFloat(p.package_size);
                const packagePrice = parseFloat(p.package_price).toFixed(2);
                const pricePerUnit = parseFloat(p.price_per_unit).toFixed(3);
                
                let notesHtml = '';
                if (p.notes && p.notes.trim() !== '') {
                    notesHtml = `<div style="font-size: 11px; color: var(--text-secondary); margin-top: 5px;">
                                    <i class="fas fa-info-circle"></i> ${p.notes}
                                 </div>`;
                }

                // Generiranje HTML-a za svaki redak dobavljača
                const li = document.createElement('li');
                li.className = `price-item ${itemClass}`;
                li.innerHTML = `
                    <div>
                        <div class="supplier-name">
                            ${p.supplier_name} ${badgeHtml}
                        </div>
                        <div class="package-info">
                            Paket: ${packageSize} ${p.unit} = ${packagePrice} BAM
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">
                            <i class="fas fa-history"></i> Ažurirano: ${p.last_updated}
                        </div>
                        ${notesHtml}
                    </div>
                    <div class="unit-price">
                        <div class="unit-price-amount ${priceClass}">${pricePerUnit}</div>
                        <div class="unit-price-label">BAM / ${p.unit}</div>
                        <div style="margin-top: 8px;">
                            <a href="dobavljac.php?delete_price=${p.id}" style="color: var(--accent-danger); font-size: 12px; text-decoration: none;" onclick="return confirm('Obrisati ovu cijenu?');"><i class="fas fa-times"></i> Obriši</a>
                        </div>
                    </div>
                `;
                listContainer.appendChild(li);
            });
        }
        
        openModal('modal-item-details');
    }
</script>

<?php include 'footer.php'; ?>
