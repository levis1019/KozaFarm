<?php
session_start();
require 'db.php';

// Ako korisnik ima token, obriši ga iz baze radi sigurnosti
if (isset($_SESSION['user_id'])) {
    $id = (int)$_SESSION['user_id'];
    $conn->query("UPDATE users SET remember_token=NULL WHERE id=$id");
}

// Uništi kolačić postavljanjem vremena u prošlost
if (isset($_COOKIE['agrodon_remember'])) {
    setcookie('agrodon_remember', '', time() - 3600, '/');
}

// Uništi sesiju
session_unset();
session_destroy();

// Preusmjeri na prijavu
header("Location: login.php");
exit();
?>
