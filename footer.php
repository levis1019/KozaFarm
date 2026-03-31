<?php
// --- SMART QUICK ADD HANDLER ---
// Hvata "Brzi Trošak" (Expense) putem AJAX-a direktno u bazu 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_expense'])) {
    if (isset($conn)) {
        $amt = (float)$_POST['amount'];
        $desc = $conn->real_escape_string($_POST['description']);
        $date = $conn->real_escape_string($_POST['transaction_date']);
        $vendor = $conn->real_escape_string($_POST['vendor'] ?? '');
        $conn->query("INSERT INTO finances (transaction_date, category, description, transaction_type, amount, payment_method, vendor) 
                      VALUES ('$date', 'Ostalo / Brzi Unos', '$desc', 'expense', $amt, 'Gotovina', '$vendor')");
        exit("OK");
    }
}

// Dohvati liste i trenutno stanje zaliha za Brzi Unos
$qa_customers = [];
$qa_inv_milk = 0; 
$qa_inv_cheese = 0; 
$qa_inv_whey = 0;
$qa_mix_id = 0;
$qa_mix_stock = 0;

if (isset($conn)) {
    // Lista kupaca
    $c_res = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    if ($c_res) {
        while ($c = $c_res->fetch_assoc()) {
            $qa_customers[] = $c;
        }
    }
    
    // Stanje Zaliha mliječnih proizvoda u realnom vremenu
    $prod_total = $conn->query("SELECT SUM(morning_liters + evening_liters) as t FROM milk_logs")->fetch_assoc()['t'] ?? 0;
    $usage_total = $conn->query("SELECT SUM(liters) as t FROM milk_usage")->fetch_assoc()['t'] ?? 0;
    $qa_inv_milk = max(0, (float)$prod_total - (float)$usage_total);
    
    $qa_inv_cheese = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sir'")->fetch_assoc()['quantity'] ?? 0;
    $qa_inv_whey = $conn->query("SELECT quantity FROM dairy_inventory WHERE item_name='Sirutka'")->fetch_assoc()['quantity'] ?? 0;

    // Stanje Gotove Smjese
    $mix_res = $conn->query("SELECT id, quantity_in_stock FROM feed_inventory WHERE category='mix' LIMIT 1");
    if ($mix_res && $mix_res->num_rows > 0) {
        $mix_data = $mix_res->fetch_assoc();
        $qa_mix_id = $mix_data['id'];
        $qa_mix_stock = $mix_data['quantity_in_stock'];
    }
}
?>

<style>
    /* QUICK ADD RESPONSIVE SIDEBAR & BOTTOM SHEET */
    .qa-overlay {
        display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6); z-index: 9998; backdrop-filter: blur(3px);
    }
    .qa-overlay.open { display: block; }
    
    /* Desktop (Default): Slides from Right */
    .quick-add-panel {
        position: fixed; top: 0; right: 0; width: 360px; height: 100vh;
        background: var(--bg-surface); border-left: 1px solid var(--border-color);
        z-index: 9999; display: flex; flex-direction: column;
        box-shadow: -5px 0 25px rgba(0,0,0,0.5);
        transform: translateX(100%); 
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .quick-add-panel.open { transform: translateX(0); }
    
    .qa-header {
        padding: 20px; border-bottom: 1px solid var(--border-color);
        display: flex; justify-content: space-between; align-items: center;
        background: var(--bg-surface-hover);
    }
    
    .qa-body { padding: 20px; overflow-y: auto; flex-grow: 1; }
    
    .qa-close-btn { 
        display: block; background: none; border: none; color: var(--text-muted); 
        font-size: 20px; cursor: pointer; transition: 0.2s; 
    }
    .qa-close-btn:hover { color: white; }
    
    /* Universal Floating Button */
    #fab-quick-add {
        display: flex; position: fixed; bottom: 25px; right: 25px;
        width: 60px; height: 60px; border-radius: 50%;
        background: var(--accent-info); color: white; border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.4); font-size: 24px;
        cursor: pointer; z-index: 9997; align-items: center; justify-content: center;
        transition: transform 0.2s ease;
    }

    /* MOBILE: Bottom Sheet mode */
    @media (max-width: 1200px) {
        .quick-add-panel {
            top: auto; bottom: 0; width: 100%; height: auto; max-height: 90vh;
            border-left: none; border-top: 1px solid var(--border-color);
            border-top-left-radius: 20px; border-top-right-radius: 20px;
            transform: translateY(100%); 
        }
        .quick-add-panel.open { transform: translateY(0); }
    }
</style>

<div class="qa-overlay" id="qa-overlay" onclick="closeQuickAddModal()"></div>

<button id="fab-quick-add" onclick="openQuickAddModal()">
    <i class="fas fa-plus"></i>
</button>

<div class="quick-add-panel" id="quick-add-panel">
    <div class="qa-header">
        <h3 style="margin:0; color:var(--text-primary); font-size: 16px;">
            <i class="fas fa-bolt" style="color:var(--accent-info); margin-right: 5px;"></i> Brzi Unos
        </h3>
        <button class="qa-close-btn" onclick="closeQuickAddModal()"><i class="fas fa-times"></i></button>
    </div>
    
    <div class="qa-body">
        <div style="display: flex; gap: 8px; margin-bottom: 20px; background: rgba(0,0,0,0.2); padding: 5px; border-radius: 8px; flex-wrap: wrap;">
            <button type="button" class="btn" id="qa-tab-milk" onclick="switchQaTab('milk')" style="flex: 1; padding: 10px 5px; font-size: 12px; background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981;">🥛 Mužnja</button>
            <button type="button" class="btn" id="qa-tab-feed" onclick="switchQaTab('feed')" style="flex: 1; padding: 10px 5px; font-size: 12px; background: transparent; color: var(--text-muted); border: 1px solid transparent;">🌾 Hranjenje</button>
            <button type="button" class="btn" id="qa-tab-sale" onclick="switchQaTab('sale')" style="flex: 1; padding: 10px 5px; font-size: 12px; background: transparent; color: var(--text-muted); border: 1px solid transparent;">🛒 Prodaja</button>
            <button type="button" class="btn" id="qa-tab-expense" onclick="switchQaTab('expense')" style="flex: 1; padding: 10px 5px; font-size: 12px; background: transparent; color: var(--text-muted); border: 1px solid transparent;">💸 Trošak</button>
        </div>

        <form id="qa-form-milk" action="barn.php" method="POST" onsubmit="submitQuickAdd(event, this)">
            <input type="hidden" name="add_milk" value="1">
            <input type="hidden" name="milking_type" value="bulk">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Datum</label>
                <input type="date" name="log_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Jutro (L)</label>
                    <input type="number" step="0.1" min="0" name="morning_liters" placeholder="0.0" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Večer (L)</label>
                    <input type="number" step="0.1" min="0" name="evening_liters" placeholder="0.0" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Napomena</label>
                <input type="text" name="notes" placeholder="Brzi unos..." style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; background:#10b981; border:none; font-weight: bold;"><i class="fas fa-save"></i> Spremi Mlijeko</button>
        </form>

        <form id="qa-form-feed" action="food.php" method="POST" style="display: none;" onsubmit="submitQuickAdd(event, this)">
            <input type="hidden" name="log_usage" value="1">
            <input type="hidden" name="feed_id" value="<?php echo $qa_mix_id; ?>">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Hrana (Proizvod)</label>
                <input type="text" readonly value="Gotova Smjesa (Zaliha: <?php echo number_format($qa_mix_stock, 1); ?> KG)" style="width:100%; padding:12px; border-radius:6px; background:rgba(0,0,0,0.2); color:var(--text-muted); border:1px solid var(--border-color);">
            </div>
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Potrošeno (KG)</label>
                    <input type="number" step="0.1" min="0.1" name="quantity" required placeholder="0.0" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Datum</label>
                    <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Napomena</label>
                <input type="text" name="notes" value="Redovno hranjenje" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; background:#eab308; color:#000; border:none; font-weight: bold;"><i class="fas fa-utensils"></i> Nahrani Stado</button>
        </form>

        <form id="qa-form-sale" action="barn.php" method="POST" style="display: none;" onsubmit="submitQuickAdd(event, this)">
            <input type="hidden" name="add_sale" value="1">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Proizvod na prodaju</label>
                <select name="product_type" required style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="mlijeko">Mlijeko (Zaliha: <?php echo number_format($qa_inv_milk,1); ?> L)</option>
                    <option value="sir">Sir (Zaliha: <?php echo number_format($qa_inv_cheese,2); ?> KG)</option>
                    <option value="sirutka">Sirutka (Zaliha: <?php echo number_format($qa_inv_whey,1); ?> L)</option>
                </select>
            </div>
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Količina</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="0.0" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Iznos (BAM)</label>
                    <input type="number" step="0.1" min="0.1" name="price_total" required placeholder="0.00" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Datum</label>
                    <input type="date" name="usage_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Plaćanje</label>
                    <select name="payment_type" id="qa_payment_type" onchange="toggleQaCustomer()" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                        <option value="cash">Gotovina</option>
                        <option value="debt">Na Dug</option>
                    </select>
                </div>
            </div>
            <div class="form-group" id="qa_customer_group" style="display: none; margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Odaberi Kupca</label>
                <select name="customer_id" id="qa_customer_id" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="">-- Odaberi --</option>
                    <?php foreach($qa_customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; background:#3b82f6; border:none; font-weight: bold;"><i class="fas fa-check"></i> Prodano (Skini sa zaliha)</button>
        </form>

        <form id="qa-form-expense" method="POST" style="display: none;" onsubmit="submitQuickAdd(event, this, true)">
            <input type="hidden" name="quick_add_expense" value="1">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Kategorija</label>
                <select name="category" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                    <option value="Veterinar">Veterinar</option>
                    <option value="Lijekovi">Lijekovi</option>
                    <option value="Oprema / Materijal">Oprema / Materijal</option>
                    <option value="Ostalo">Ostalo</option>
                </select>
            </div>
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Iznos (BAM)</label>
                    <input type="number" step="0.1" min="0.1" name="amount" required placeholder="0.00" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size: 12px; color: var(--text-secondary);">Datum</label>
                    <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Dobavljač (Opcionalno)</label>
                <input type="text" name="vendor" placeholder="npr. Agro centar..." style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-size: 12px; color: var(--text-secondary);">Opis Troška</label>
                <input type="text" name="description" required placeholder="Detalji troška..." style="width:100%; padding:12px; border-radius:6px; background:var(--bg-surface-hover); color:white; border:1px solid var(--border-color);">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; background:#ef4444; border:none; font-weight: bold;"><i class="fas fa-minus-circle"></i> Upiši Trošak</button>
        </form>
    </div>
</div>

<script>
    // --- MAIN HAMBURGER LOGIC ---
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');

    if(hamburgerBtn) {
        hamburgerBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });
    }

    if(overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }

    // --- QUICK ADD MODAL LOGIC ---
    function openQuickAddModal() {
        document.getElementById('quick-add-panel').classList.add('open');
        document.getElementById('qa-overlay').classList.add('open');
        document.getElementById('fab-quick-add').style.transform = 'scale(0)'; 
        // Lock body scroll
        document.body.style.overflow = 'hidden';
    }

    function closeQuickAddModal() {
        document.getElementById('quick-add-panel').classList.remove('open');
        document.getElementById('qa-overlay').classList.remove('open');
        document.getElementById('fab-quick-add').style.transform = 'scale(1)'; 
        // Unlock body scroll
        document.body.style.overflow = '';
    }

    function switchQaTab(tabName) {
        const tabs = ['milk', 'sale', 'expense', 'feed'];
        const colors = { 'milk': '#10b981', 'sale': '#3b82f6', 'expense': '#ef4444', 'feed': '#eab308' };
        
        tabs.forEach(t => {
            const btn = document.getElementById(`qa-tab-${t}`);
            const form = document.getElementById(`qa-form-${t}`);
            
            if (t === tabName) {
                btn.style.background = `rgba(${hexToRgb(colors[t])}, 0.1)`;
                btn.style.color = colors[t];
                btn.style.border = `1px solid ${colors[t]}`;
                form.style.display = 'block';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = 'var(--text-muted)';
                btn.style.border = '1px solid transparent';
                form.style.display = 'none';
            }
        });
    }

    function toggleQaCustomer() {
        const pType = document.getElementById('qa_payment_type').value;
        const group = document.getElementById('qa_customer_group');
        if (pType === 'debt') {
            group.style.display = 'block';
            document.getElementById('qa_customer_id').required = true;
        } else {
            group.style.display = 'none';
            document.getElementById('qa_customer_id').required = false;
        }
    }

    function hexToRgb(hex) {
        let r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return `${r}, ${g}, ${b}`;
    }

    // AJAX Form submission
    function submitQuickAdd(e, formElement, isSelf = false) {
        e.preventDefault();
        const formData = new FormData(formElement);
        const actionUrl = isSelf ? window.location.href : formElement.getAttribute('action');
        
        const submitBtn = formElement.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Spremanje...';
        submitBtn.disabled = true;

        fetch(actionUrl, {
            method: 'POST',
            body: formData
        }).then(response => {
            if(response.ok) {
                window.location.reload(); 
            } else {
                alert("Došlo je do greške pri spremanju.");
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }).catch(err => {
            alert("Greška u komunikaciji sa serverom.");
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }

    // --- PUSH NOTIFICATIONS LOGIC ---
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
        return outputArray;
    }

    if ('serviceWorker' in navigator && 'PushManager' in window) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('sw.js?v=5').then(function(swReg) {
                if (Notification.permission === 'default') {
                    Notification.requestPermission().then(function(permission) {
                        if (permission === 'granted') {
                            subscribeUser(swReg);
                        }
                    });
                } else if (Notification.permission === 'granted') {
                     subscribeUser(swReg);
                }
            });
        });
    }

    function subscribeUser(swReg) {
        const publicVapidKey = 'BD1jqAjA_Z8keWfrWHPVBaa6YAYu-6QI7IJu5gQalCceNt_cZhVIgiQBFo_0mWQPeIM14O7qGKoYGL439FXLQ5g';
        swReg.pushManager.getSubscription().then(function(existingSubscription) {
            if (existingSubscription === null) {
                swReg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicVapidKey)
                }).then(function(newSubscription) {
                    sendSubscriptionToServer(newSubscription);
                });
            } else {
                sendSubscriptionToServer(existingSubscription);
            }
        });
    }

    function sendSubscriptionToServer(subscription) {
        const subJson = subscription.toJSON();
        fetch('save_subscription.php', {
            method: 'POST',
            body: JSON.stringify({
                endpoint: subJson.endpoint,
                p256dh: subJson.keys.p256dh,
                auth: subJson.keys.auth
            }),
            headers: { 'Content-Type': 'application/json' }
        });
    }
</script>
</body>
</html>
