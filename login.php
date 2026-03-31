<?php
session_start();
require 'db.php'; 

// --- SMART DATABASE UPDATER ---
$checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL");
}
// ------------------------------

// 1. AUTO-LOGIN PREKO KOLAČIĆA (30 DANA)
if (!isset($_SESSION['user_id']) && isset($_COOKIE['agrodon_remember'])) {
    $token = $conn->real_escape_string($_COOKIE['agrodon_remember']);
    $result = $conn->query("SELECT * FROM users WHERE remember_token='$token'");
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $row['role']; // SPREMI ULOGU U SESIJU
        header("Location: index.php");
        exit();
    }
}

// 2. Ako je već prijavljen, idi na dashboard
if(isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit(); 
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $conn->real_escape_string($_POST['username']);
    $pass = $_POST['password'];

    $result = $conn->query("SELECT * FROM users WHERE username='$user'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        if (password_verify($pass, $row['password'])) {
            // Uspješna prijava - postavi sesiju
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role']; // SPREMI ULOGU U SESIJU
            
            // GENERIRAJ SIGURNOSNI TOKEN ZA 30 DANA
            $token = bin2hex(random_bytes(32)); 
            $conn->query("UPDATE users SET remember_token='$token' WHERE id=" . $row['id']);
            
            setcookie('agrodon_remember', $token, time() + (86400 * 30), "/", "", true, true);
            
            header("Location: index.php");
            exit();
        } else {
            $error = "Pogrešna lozinka!";
        }
    } else {
        $error = "Korisnik ne postoji!";
    }
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KozaFarm - Prijava</title>
    <link rel="stylesheet" href="css/style.css?v=3">
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: var(--bg-body); margin: 0; }
        .login-card { background: var(--bg-surface); padding: 40px; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); width: 100%; max-width: 400px; text-align: center; box-sizing: border-box; }
        .login-card input { width: 100%; padding: 15px; margin-bottom: 20px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); color: white; border-radius: var(--radius-md); font-size: 16px; box-sizing: border-box; }
        .login-card button { width: 100%; padding: 15px; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); font-weight: bold; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
    <div class="login-card">
	<h2 style="margin-bottom: 30px; color: white;"> <span style="color: #10b981;">Agro</span>Don Prijava</h2>
        <?php if($error) echo "<p style='color: var(--accent-danger); margin-bottom:15px; font-weight: bold;'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Korisničko ime" required>
            <input type="password" name="password" placeholder="Lozinka" required>
            <button type="submit">Prijavi se</button>
        </form>
    </div>

    <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js');
      });
    }
    </script>
</body>
</html>
