<?php
require __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "---------------------------------\n";
echo "YOUR REAL VAPID KEYS\n";
echo "Save these somewhere safe!\n";
echo "---------------------------------\n\n";
echo "Public Key:  " . $keys['publicKey'] . "\n\n";
echo "Private Key: " . $keys['privateKey'] . "\n\n";
?>
