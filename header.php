<?php
// Saznaj na kojoj smo stranici za navigaciju
$current_page = basename($_SERVER['PHP_SELF']);

// 1. Dinamični naslovi za preglednik
$page_titles = [
    'index.php' => 'Dashboard',
    'herd.php' => 'Stado',
    'profile.php' => 'Profil Koze',
    'barn.php' => 'Mlijeko',
    'food.php' => 'Hrana i Skladište',
    'finance.php' => 'Financije',
    'transactions.php' => 'Transakcije',
    'events.php' => 'Događaji',
    'meat.php' => 'Meso',
    'meat_history.php' => 'Povijest Mesa',
    'customers.php' => 'Kupci',
    'customer_ledger.php' => 'Knjiga Kupca',
    'calendar.php' => 'Kalendar',
    'settings.php' => 'Postavke'
];
$current_title = isset($page_titles[$current_page]) ? $page_titles[$current_page] . ' | AgroDon' : 'AgroDon';

?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $current_title; ?></title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f111a">
    <link rel="apple-touch-icon" href="icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <link rel="stylesheet" href="css/style.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        @media (min-width: 901px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 260px;
                z-index: 1000;
            }
            .content-area {
                /* 260px za sidebar + 20px za mali gap = 280px */
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }

        /* Ensure sidebar uses flexbox to push bottom items down */
        #main-sidebar {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .header-actions { display: flex; align-items: center; gap: 20px; }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <h2 style="margin: 0; font-size: 22px;">
            <span style="color: #10b981;">Agro</span><span style="color: white;">Don</span> 🐐
        </h2>
        <div class="header-actions">
            <button id="mobile-menu-toggle" onclick="toggleMobileMenu()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer;">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>

    <div id="sidebar-overlay" onclick="toggleMobileMenu()"></div>

    <nav class="sidebar" id="main-sidebar">
	    <h2 style="margin: 20px 0 30px 0; color: white; text-align: center; flex-shrink: 0;"><span style="color: #10b981;">Agro</span>Don 🐐</h2>
        
        <ul style="flex-grow: 1; overflow-y: auto; padding-bottom: 10px;">
            <li class="<?= ($current_page == 'index.php') ? 'active' : ''; ?>"><a href="index.php"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li class="<?= ($current_page == 'herd.php' || $current_page == 'profile.php' || $current_page == 'add_goat.php') ? 'active' : ''; ?>"><a href="herd.php"><i class="fas fa-list"></i> Stado</a></li>
            <li class="<?= ($current_page == 'barn.php') ? 'active' : ''; ?>"><a href="barn.php"><i class="fas fa-mobile-alt"></i> Mlijeko</a></li>
            <li class="<?= ($current_page == 'food.php') ? 'active' : ''; ?>"><a href="food.php"><i class="fas fa-boxes"></i> Hrana i Skladište</a></li>
            <li class="<?= ($current_page == 'finance.php' || $current_page == 'transactions.php') ? 'active' : ''; ?>"><a href="finance.php"><i class="fas fa-wallet"></i> Financije</a></li>
            <li class="<?= ($current_page == 'events.php') ? 'active' : ''; ?>"><a href="events.php"><i class="fas fa-calendar-check"></i> Događaji</a></li>
            <li class="<?= ($current_page == 'meat.php' || $current_page == 'meat_history.php' || $current_page == 'edit_meat.php') ? 'active' : ''; ?>"><a href="meat.php"><i class="fas fa-weight"></i> Meso</a></li>
            <li class="<?= ($current_page == 'customers.php' || $current_page == 'customer_ledger.php') ? 'active' : ''; ?>"><a href="customers.php"><i class="fas fa-users"></i> Kupci / Dugovi</a></li>
            <li class="<?= ($current_page == 'calendar.php' || $current_page == 'milk_calendar.php') ? 'active' : ''; ?>"><a href="calendar.php"><i class="fas fa-calendar-alt"></i> Kalendar</a></li>
        </ul>

        <ul style="flex-shrink: 0; padding-bottom: 20px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 15px;">
            <li class="<?= ($current_page == 'settings.php') ? 'active' : ''; ?>"><a href="settings.php"><i class="fas fa-cog"></i> Postavke</a></li>
            <li><a href="logout.php" style="color: var(--accent-danger);"><i class="fas fa-sign-out-alt"></i> Odjava</a></li>
        </ul>
    </nav>

    <script>
        function toggleMobileMenu() {
            var sidebar = document.getElementById('main-sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            
            if (sidebar && overlay) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            }
        }
    </script>
