<?php
// cron_alerts.php - Master Farm Assistant (Smart Push Notifications & Backups)
require 'db.php';
require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Secure the cron job (only accessible via CLI or secret key)
if (php_sapi_name() !== 'cli' && !isset($_GET['run_cron_secret_key'])) {
    die("Unauthorized access.");
}

$publicKey = 'BD1jqAjA_Z8keWfrWHPVBaa6YAYu-6QI7IJu5gQalCceNt_cZhVIgiQBFo_0mWQPeIM14O7qGKoYGL439FXLQ5g';
$privateKey = 'RJOuBFjKkYuce19joT-rFTayyAHdI_7e3QYrH2IAgL4';

$auth = [
    'VAPID' => [
        'subject' => 'mailto:admin@dontv.shop', 
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
    ],
];

$webPush = new WebPush($auth);
$alertsToSend = [];

// ==========================================
// 1. DAILY HABITS (Milk & Food Logs)
// ==========================================
$milk_today = $conn->query("SELECT COUNT(*) as cnt FROM milk_logs WHERE log_date = CURDATE()")->fetch_assoc()['cnt'];
if ($milk_today == 0) {
    $alertsToSend[] = ['title' => '🥛 Asistent Farme', 'body' => 'Niste unijeli podatke o današnjoj mužnji!', 'url' => 'https://dontv.shop/KozaFarm/barn.php'];
}

$food_today = $conn->query("SELECT COUNT(*) as cnt FROM feed_transactions WHERE transaction_date = CURDATE() AND type = 'out'")->fetch_assoc()['cnt'] ?? 0;
if ($food_today == 0) {
    $alertsToSend[] = ['title' => '🌾 Asistent Farme', 'body' => 'Niste zabilježili današnju potrošnju hrane u skladištu!', 'url' => 'https://dontv.shop/KozaFarm/food.php'];
}

// ==========================================
// 2. INVENTORY & FINANCE ALERTS
// ==========================================
// Low Food Stock Alert
$low_food = $conn->query("SELECT name, quantity_in_stock, unit FROM feed_inventory WHERE min_stock_limit > 0 AND quantity_in_stock <= min_stock_limit");
while($f = $low_food->fetch_assoc()) {
    $alertsToSend[] = ['title' => '⚠️ Niske Zalihe', 'body' => "{$f['name']} je pri kraju (Zaliha: " . (float)$f['quantity_in_stock'] . " {$f['unit']}).", 'url' => 'https://dontv.shop/KozaFarm/food.php'];
}

// Customer Debt Limit Alert
$debtors = $conn->query("
    SELECT c.name, c.debt_limit, 
    (SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id = c.id AND transaction_type='debt') - 
    (SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id = c.id AND transaction_type='payment') as balance 
    FROM customers c WHERE c.debt_limit > 0 HAVING balance > c.debt_limit
");
while($d = $debtors->fetch_assoc()) {
    $alertsToSend[] = ['title' => '🛑 Prekoračenje Duga', 'body' => "Kupac {$d['name']} je prešao dozvoljeni limit duga (Trenutno: -" . number_format($d['balance'], 2) . " BAM).", 'url' => 'https://dontv.shop/KozaFarm/customers.php'];
}

// ==========================================
// 3. HERD HEALTH & MANAGEMENT
// ==========================================
// End of Withdrawal Period (Karenca)
$karenca_end = $conn->query("SELECT e.goat_id, g.name FROM events e JOIN goats g ON e.goat_id = g.id WHERE e.withdrawal_days > 0 AND DATE_ADD(e.event_date, INTERVAL e.withdrawal_days DAY) = CURDATE() AND g.archived = 0");
while($k = $karenca_end->fetch_assoc()) {
    $alertsToSend[] = ['title' => '✅ Karenca Istekla', 'body' => "Mlijeko koze #{$k['goat_id']} ({$k['name']}) je od danas sigurno za upotrebu!", 'url' => 'https://dontv.shop/KozaFarm/profile.php?id=' . $k['goat_id']];
}

// Upcoming Treatments
$treatments = $conn->query("SELECT e.goat_id, g.name, e.medication FROM events e JOIN goats g ON e.goat_id = g.id WHERE e.next_appointment = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND g.archived = 0");
while($t = $treatments->fetch_assoc()) {
    $alertsToSend[] = ['title' => '💉 Podsjetnik za Liječenje', 'body' => "Sutra je na rasporedu doza ({$t['medication']}) za kozu #{$t['goat_id']} ({$t['name']}).", 'url' => 'https://dontv.shop/KozaFarm/profile.php?id=' . $t['goat_id']];
}

// Weaning Reminder (60 Days old)
$weaning = $conn->query("SELECT id, name FROM goats WHERE birth_date = DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND archived = 0");
while($w = $weaning->fetch_assoc()) {
    $alertsToSend[] = ['title' => '🐐 Vrijeme za Odbijanje', 'body' => "Jare #{$w['id']} danas puni 2 mjeseca. Spremno je za odbijanje/krutu hranu.", 'url' => 'https://dontv.shop/KozaFarm/profile.php?id=' . $w['id']];
}

// Time to Dry Off (Zasušivanje - 90 days after mating)
$dry_off = $conn->query("SELECT e.goat_id, g.name FROM events e JOIN goats g ON e.goat_id = g.id WHERE e.event_type = 'Parenje' AND e.event_date = DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND g.archived = 0");
while($dry = $dry_off->fetch_assoc()) {
    $alertsToSend[] = ['title' => '🛑 Vrijeme za Zasušivanje', 'body' => "Koza #{$dry['goat_id']} ulazi u zadnja dva mjeseca trudnoće. Prestanite s mužnjom.", 'url' => 'https://dontv.shop/KozaFarm/profile.php?id=' . $dry['goat_id']];
}

// Flagged Goats Reminder
$flagged_count = $conn->query("SELECT COUNT(*) as cnt FROM goats WHERE flagged = 1 AND archived = 0")->fetch_assoc()['cnt'];
if ($flagged_count > 0) {
    $alertsToSend[] = ['title' => '⭐ Provjera Stada', 'body' => "Imate $flagged_count označenih (flagged) koza. Jeste li provjerili njihovo zdravlje?", 'url' => 'https://dontv.shop/KozaFarm/herd.php'];
}

// ==========================================
// 4. SMART ANALYTICS
// ==========================================
// Milk Drop Warning (>15% drop compared to 3-day avg)
$today_milk = $conn->query("SELECT SUM(morning_liters + evening_liters) as today_total FROM milk_logs WHERE log_date = CURDATE()")->fetch_assoc()['today_total'] ?? 0;
$avg_3d_milk = $conn->query("SELECT AVG(daily_total) as avg_3d FROM (SELECT SUM(morning_liters + evening_liters) as daily_total FROM milk_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND log_date < CURDATE() GROUP BY log_date) as sub")->fetch_assoc()['avg_3d'] ?? 0;

if ($avg_3d_milk > 0 && $today_milk < ($avg_3d_milk * 0.85)) {
    $alertsToSend[] = ['title' => '📉 Pad Proizvodnje Mlijeka', 'body' => "Oprez: Ukupna količina mlijeka danas je značajno pala u odnosu na prosjek zadnja 3 dana.", 'url' => 'https://dontv.shop/KozaFarm/barn.php'];
}

// ==========================================
// 5. WEEKLY DIGEST & AUTOMATED BACKUP
// ==========================================
if (date('w') == 0) { // 0 = Sunday
    
    // A) Send Financial Digest
    $w_milk = $conn->query("SELECT SUM(morning_liters + evening_liters) as total FROM milk_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0;
    $w_paid = $conn->query("SELECT SUM(amount) as total FROM customer_ledger WHERE transaction_type='payment' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0;
    $w_exp = $conn->query("SELECT SUM(amount) as total FROM finances WHERE transaction_type='expense' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0;
    
    $alertsToSend[] = [
        'title' => '📊 Tjedni Izvještaj', 
        'body' => "Proizvedeno: " . number_format($w_milk, 1) . " L | Naplaćeno: " . number_format($w_paid, 2) . " BAM | Troškovi: " . number_format($w_exp, 2) . " BAM.", 
        'url' => 'https://dontv.shop/KozaFarm/finances.php'
    ];

    // B) Generate Full Database Backup
    $backup_dir = __DIR__ . '/backups';
    
    // Create folder and lock it from public web access if it doesn't exist
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
        file_put_contents($backup_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
    }

    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()) { $tables[] = $row[0]; }

    $sql_dump = "-- AgroDon Baza Podataka (Automatski Backup)\n-- Datum: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach($tables as $table) {
        $result = $conn->query("SELECT * FROM `$table`");
        $num_fields = $result->field_count;
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_dump .= $row2[1] . ";\n\n";

        while($row = $result->fetch_row()) {
            $sql_dump .= "INSERT INTO `$table` VALUES(";
            for($j=0; $j<$num_fields; $j++) {
                if (isset($row[$j])) {
                    $safe_val = $conn->real_escape_string($row[$j]);
                    $sql_dump .= "'" . $safe_val . "'";
                } else {
                    $sql_dump .= "NULL";
                }
                if ($j < ($num_fields-1)) $sql_dump .= ',';
            }
            $sql_dump .= ");\n";
        }
        $sql_dump .= "\n\n";
    }

    $backup_file = $backup_dir . '/backup_' . date('Y-m-d') . '.sql';
    file_put_contents($backup_file, $sql_dump);

    // C) Delete backups older than 30 days to save disk space
    $files = glob($backup_dir . '/*.sql');
    foreach($files as $file) {
        if(is_file($file) && (time() - filemtime($file) >= 30 * 24 * 60 * 60)) {
            unlink($file);
        }
    }

    // D) Add Backup Success Notification
    $alertsToSend[] = [
        'title' => '💾 Sigurnosna Kopija', 
        'body' => "Tjedni backup baze podataka je uspješno kreiran i spremljen na server.", 
        'url' => 'https://dontv.shop/KozaFarm/settings.php'
    ];
}

// ==========================================
// 6. MANUAL FORCE TEST TRIGGER
// ==========================================
if (isset($_GET['force_test'])) {
    $alertsToSend[] = [
        'title' => '🐐 TEST TEST',
        'body' => 'Ovo je probna poruka da vidimo radi li sustav na mobitelu!',
        'url' => 'https://dontv.shop/KozaFarm/'
    ];
}

// ==========================================
// 7. DISPATCH ENGINE
// ==========================================
if (empty($alertsToSend)) {
    echo "Sve je u redu, nema upozorenja za slanje.\n";
    exit();
}

$result = $conn->query("SELECT * FROM push_subscriptions");

if ($result->num_rows > 0) {
    $subscriptions = [];
    while ($row = $result->fetch_assoc()) {
        $subscriptions[] = Subscription::create([
            'endpoint' => $row['endpoint'],
            'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
        ]);
    }

    foreach ($alertsToSend as $alert) {
        $payloadJson = json_encode($alert);
        
        foreach ($subscriptions as $sub) {
            $webPush->queueNotification($sub, $payloadJson);
        }
        
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            if ($report->isSuccess()) {
                echo "[SUCCESS] Obavijest uspješno poslana uređaju: {$alert['title']}\n<br>";
            } else {
                echo "[ERROR] Greška pri slanju: {$report->getReason()}\n<br>";
                if ($report->isSubscriptionExpired()) {
                    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                    $stmt->bind_param("s", $endpoint);
                    $stmt->execute();
                    echo "[INFO] Uklonjen istekli uređaj iz baze podataka.\n<br>";
                }
            }
        }
    }
} else {
    echo "Nema pretplaćenih uređaja u bazi podataka.\n";
}
?>
