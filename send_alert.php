<?php
// Učitaj bazu i Composer biblioteku
require 'db.php';
require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// --- 1. UNESI SVOJE VAPID KLJUČEVE OVDJE ---
$publicKey = 'BD1jqAjA_Z8keWfrWHPVBaa6YAYu-6QI7IJu5gQalCceNt_cZhVIgiQBFo_0mWQPeIM14O7qGKoYGL439FXLQ5g';
$privateKey = 'RJOuBFjKkYuce19joT-rFTayyAHdI_7e3QYrH2IAgL4';

$auth = [
    'VAPID' => [
        'subject' => 'mailto:admin@dontv.shop', // Tvoj kontakt email
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
    ],
];

$webPush = new WebPush($auth);

// --- 2. PORUKA KOJU ŠALJEMO ---
$payload = "Ovo je testna poruka s farme! 🐐";

// --- 3. DOHVATI SVE PRETPLAĆENE UREĐAJE ---
$result = $conn->query("SELECT * FROM push_subscriptions");

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
        ]);

        // Dodaj u red čekanja
        $webPush->queueNotification($subscription, $payload);
    }

    // --- 4. POŠALJI SVE PORUKE ---
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();

        if ($report->isSuccess()) {
            echo "<span style='color:green;'>[Uspješno]</span> Obavijest poslana na uređaj!<br>";
        } else {
            echo "<span style='color:red;'>[Greška]</span> Slanje nije uspjelo. Razlog: {$report->getReason()}<br>";
            
            // Ako je korisnik ugasio obavijesti ili izbrisao app, brišemo ga iz baze
            if ($report->isSubscriptionExpired()) {
                $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                $stmt->bind_param("s", $endpoint);
                $stmt->execute();
                echo "Uređaj je uklonjen iz baze jer je pretplata istekla.<br>";
            }
        }
    }
} else {
    echo "Nema pretplaćenih uređaja u bazi.";
}
?>
