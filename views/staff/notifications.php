<?php

/******************************
 * Guard & Runtime
 ******************************/
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../auth/login.php');
    exit;
}
date_default_timezone_set('Asia/Makassar');

/******************************
 * Security Headers & Cache
 ******************************/
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
/* Catatan: ada sedikit inline JS (drawer) → sementara izinkan 'unsafe-inline' */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'");

/******************************
 * DB Connection
 ******************************/
require_once '../../config/database.php'; // harus set $conn = new mysqli(...)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
$conn->set_charset('utf8mb4');

/******************************
 * CSRF
 ******************************/
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

/******************************
 * Context
 ******************************/
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/******************************
 * Helpers
 ******************************/
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function notif_card_class(string $status): string
{
    return strtolower($status) === 'unread' ? 'notification-unread' : 'notification-read';
}

/******************************
 * Actions (POST)
 ******************************/
$flash = null;

/* Tandai satu notifikasi dibaca */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $flash = ['type' => 'error', 'msg' => 'CSRF tidak valid.'];
    } else {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            $st = $conn->prepare("UPDATE notifications SET status='read' WHERE id=? AND user_id=?");
            $st->bind_param('ii', $nid, $userId);
            $st->execute();
            $st->close();
            $flash = ['type' => 'success', 'msg' => 'Notifikasi ditandai dibaca.'];
        }
    }
}

/* Tandai semua notifikasi dibaca */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $flash = ['type' => 'error', 'msg' => 'CSRF tidak valid.'];
    } else {
        $st = $conn->prepare("UPDATE notifications SET status='read' WHERE user_id=? AND status='unread'");
        $st->bind_param('i', $userId);
        $st->execute();
        $st->close();
        $flash = ['type' => 'success', 'msg' => 'Semua notifikasi ditandai dibaca.'];
    }
}

/******************************
 * Fetch Notifikasi user ini
 ******************************/
$notifs = [];
$st = $conn->prepare("
    SELECT id, message, status, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($nid, $nmsg, $nstatus, $ncreated);
while ($st->fetch()) {
    $notifs[] = [
        'id'      => (int)$nid,
        'message' => $nmsg,
        'status'  => $nstatus, // 'unread' | 'read'
        'time'    => $ncreated
    ];
}
$st->close();

/* Hitung unread untuk badge & tombol */
$unreadCount = 0;
foreach ($notifs as $n) if ($n['status'] === 'unread') $unreadCount++;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMTIB</title>
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- Tailwind -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4" defer></script>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #111827;
            --paper: #ffffff;
            --muted: #6b7280;
            --line: #e5e7eb;
            --ink-strong: #000;
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

        /* Sidebar Putih (desktop) */
        .sidebar {
            width: 16rem;
            background: var(--paper);
            border-right: 1px solid var(--line);
        }

        .sidebar-title {
            font-weight: 700;
            letter-spacing: .2px;
        }

        .slink {
            display: block;
            padding: .625rem 1rem;
            border-radius: .5rem;
            color: var(--ink);
        }

        .slink:hover {
            background: #f9fafb;
        }

        .slink.active {
            background: var(--ink-strong);
            color: #fff;
        }

        .slink .fas {
            color: inherit;
        }

        .slink.active .fas {
            color: #fff !important;
        }

        /* ===== Badge jumlah notif – ukuran tetap & tidak mendorong sidebar (sesuai referensi) ===== */
        .badge {
            min-width: 1.5rem;
            /* 24px */
            height: 1.5rem;
            /* 24px */
            line-height: 1.5rem;
            padding: 0 .5rem;
            /* muat 2+ digit */
            border-radius: 9999px;
            font-size: .75rem;
            /* 12px */
            text-align: center;
            background: #111;
            /* hitam */
            color: #fff;
            border: 1px solid #111;
            display: inline-flex;
            /* pusatkan angka dengan fleksibel */
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            /* jangan melar/menyusut */
        }

        /* Saat menu aktif, invert agar kontras di background hitam */
        .slink.active .badge {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        /* Drawer mobile */
        .drawer {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 40;
        }

        .drawer-panel {
            width: 16rem;
            max-width: 85vw;
            height: 100%;
            background: #fff;
            border-right: 1px solid var(--line);
            transform: translateX(-100%);
            transition: transform .25s ease;
        }

        .drawer.show {
            pointer-events: auto;
        }

        .drawer.show .drawer-panel {
            transform: translateX(0);
        }

        .drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            opacity: 0;
            transition: opacity .25s ease;
        }

        .drawer.show .drawer-backdrop {
            opacity: 1;
        }

        @media (min-width:1024px) {
            .drawer {
                display: none
            }
        }

        /* hide drawer on lg+ */

        /* Card & Buttons */
        .card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        .btn {
            border-radius: .5rem;
            padding: .5rem 1rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-outline {
            background: #fff;
            color: #111;
            border: 1px solid #111;
        }

        .btn-outline:hover {
            background: #111;
            color: #fff;
        }

        .btn-black {
            background: #000;
            color: #fff;
            border: 1px solid #000;
        }

        .btn-black:hover {
            background: #0a0a0a;
            border-color: #0a0a0a;
        }

        /* Kartu notifikasi */
        .notification-unread {
            background-color: #111827;
            color: #fff;
        }

        .notification-read {
            background-color: #e5e7eb;
            color: #111;
        }

        .notification-unread .meta,
        .notification-read .meta {
            opacity: .9;
            font-size: .85rem;
        }

        .notification-read {
            opacity: .9;
        }

        .chip {
            font-size: .75rem;
            padding: .125rem .5rem;
            border-radius: .375rem;
            border: 1px solid rgba(255, 255, 255, .6);
        }

        .notification-read .chip {
            border-color: #9ca3af;
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop (≥ lg) -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li>
                    <a href="dashboard.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="tasks.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</span>
                    </a>
                </li>
                <li>
                    <a href="user-activity.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</span>
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="slink active flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-bell mr-3"></i>Notifikasi</span>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge shrink-0" aria-label="Notifikasi belum dibaca">
                                <?= (int)$unreadCount ?>
                                <!-- atau gunakan: <?= (int)$unreadCount > 99 ? '99+' : (int)$unreadCount ?> -->
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-user mr-3"></i>Profil</span>
                    </a>
                </li>
            </ul>
            <form action='../../controllers/logout.php' method='POST' class="mt-6">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
            </form>
        </aside>

        <!-- Drawer mobile (< lg) -->
        <div id="drawer" class="drawer lg:hidden" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="drawer-backdrop" id="drawerBackdrop"></div>
            <div class="drawer-panel p-4 overflow-y-auto">
                <h2 class="flex justify-between items-center text-xl sidebar-title mb-6">
                    <span>SIMTIB</span>
                    <button id="drawerClose" class="btn btn-outline px-2 py-1"><i class="fas fa-times"></i></button>
                </h2>
                <ul class="space-y-1">
                    <li>
                        <a href="dashboard.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="tasks.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</span>
                        </a>
                    </li>
                    <li>
                        <a href="user-activity.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</span>
                        </a>
                    </li>
                    <li>
                        <a href="notifications.php" class="slink active flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-bell mr-3"></i>Notifikasi</span>
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge shrink-0" aria-label="Notifikasi belum dibaca">
                                    <?= (int)$unreadCount ?>
                                    <!-- atau gunakan: <?= (int)$unreadCount > 99 ? '99+' : (int)$unreadCount ?> -->
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-user mr-3"></i>Profil</span>
                        </a>
                    </li>
                </ul>
                <form action='../../controllers/logout.php' method='POST' class="mt-6">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>
        </div>

        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar: hamburger hanya di kecil -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Notifikasi - Staff</div>
                </div>
                <form action='../../controllers/logout.php' method='POST' class="hidden lg:block">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-semibold text-gray-800">Daftar Notifikasi</h3>
                        <?php if ($unreadCount > 0): ?>
                            <form method="POST" class="inline-flex">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="btn btn-black">
                                    Tandai Semua Dibaca (<?= (int)$unreadCount ?>)
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($flash): ?>
                        <div class="mb-4 border rounded-lg p-3 <?= $flash['type'] === 'success' ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800' ?>">
                            <?= e($flash['msg']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$notifs): ?>
                        <div class="text-gray-500">Belum ada notifikasi.</div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($notifs as $n): ?>
                                <div class="flex items-center justify-between p-4 rounded-lg border <?= e(notif_card_class($n['status'])) ?>">
                                    <div class="flex-1 pr-4">
                                        <p class="font-medium <?= $n['status'] === 'unread' ? 'text-white' : 'text-gray-800' ?>">
                                            <?= e($n['message']) ?>
                                        </p>
                                        <p class="meta <?= $n['status'] === 'unread' ? 'text-gray-200' : 'text-gray-600' ?>">
                                            <span class="chip <?= $n['status'] === 'unread' ? 'text-white' : 'text-gray-700' ?>">
                                                <?= $n['status'] === 'unread' ? 'Belum dibaca' : 'Sudah dibaca' ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="<?= $n['status'] === 'unread' ? 'text-white' : 'text-gray-700' ?> text-sm">
                                            <?= e(date('Y-m-d H:i', strtotime($n['time']))) ?>
                                        </span>
                                        <?php if ($n['status'] === 'unread'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                                <button type="submit" class="btn btn-outline <?= $n['status'] === 'unread' ? 'bg-white/20 text-white border-white' : '' ?>">
                                                    Tandai Dibaca
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script>
        /* Drawer controls (muncul hanya di kecil) */
        (function() {
            const drawer = document.getElementById('drawer');
            const openBtn = document.getElementById('drawerOpen');
            const closeBtn = document.getElementById('drawerClose');
            const backdrop = document.getElementById('drawerBackdrop');

            function open() {
                drawer.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            function close() {
                drawer.classList.remove('show');
                document.body.style.overflow = '';
            }

            if (openBtn) openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (backdrop) backdrop.addEventListener('click', close);
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') close();
            });
        })();
    </script>
</body>

</html>