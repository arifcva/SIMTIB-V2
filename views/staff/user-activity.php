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
/*
  Catatan CSP:
  - Ada sedikit inline JS untuk drawer; maka sementara 'unsafe-inline' di script-src.
  - Jika nanti dipindah ke file .js terpisah, hapus 'unsafe-inline'.
*/
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
function table_label_staff(string $tbl): string
{
    switch (strtolower($tbl)) {
        case 'tasks':
            return 'Tugas';
        case 'users':
            return 'Pengguna';
        case 'comments':
            return 'Komentar';
        case 'notifications':
            return 'Notifikasi';
        default:
            return $tbl;
    }
}
/* Klasifikasi status badge dari action */
function action_badge(string $action): array
{
    $a = strtolower($action);
    if ($a === 'login' || $a === 'logout') return ['Login/Logout', 'activity-login'];
    if ($a === 'create') return ['Edit',   'activity-task'];
    if ($a === 'update') return ['Update', 'activity-task'];
    if ($a === 'delete') return ['Delete', 'activity-comment']; // merah
    return [ucfirst($a), 'activity-task'];
}

/******************************
 * Ambil aktivitas milik USER INI saja
 * - Join kondisional untuk menampilkan nama entitas
 ******************************/
$logs = [];
$st = $conn->prepare("
    SELECT 
        al.id AS log_id,
        al.action,
        al.affected_table,
        al.affected_id,
        al.created_at,
        CASE 
            WHEN al.affected_table='tasks' THEN t.title
            ELSE NULL
        END AS entity_name
    FROM audit_logs al
    LEFT JOIN tasks t ON t.id = al.affected_id AND al.affected_table='tasks'
    WHERE al.user_id = ?
    ORDER BY al.created_at DESC
    LIMIT 50
");
$st->bind_param('i', $userId);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
    $logs[] = [
        'action' => $row['action'],
        'table'  => $row['affected_table'],
        'id'     => (int)$row['affected_id'],
        'time'   => $row['created_at'],
        'name'   => $row['entity_name'] // bisa null jika bukan 'tasks'
    ];
}
$st->close();

/******************************
 * Hitung jumlah notifikasi unread (untuk badge di sidebar)
 ******************************/
$unreadCount = 0;
$st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($unreadCount);
$st->fetch();
$st->close();

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

        /* Badge bulat hitam (ukuran tetap, tidak mendorong sidebar) */
        .badge {
            min-width: 1.5rem;
            /* 24px */
            height: 1.5rem;
            /* 24px */
            line-height: 1.5rem;
            padding: 0 .5rem;
            /* muat 2+ digit */
            border-radius: 9999px;
            /* pil */
            font-size: .75rem;
            /* 12px */
            text-align: center;
            background: #111;
            /* hitam */
            color: #fff;
            border: 1px solid #111;
            display: inline-flex;
            /* pusatkan isi secara fleksibel */
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            /* jangan melar/menyusut */
        }

        /* Saat menu aktif (background hitam), badge di-invert agar tetap kontras */
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

        /* Card, buttons */
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

        /* Badge status (monokrom-ish dengan aksen) */
        .activity-status {
            color: #fff;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: .875rem;
        }

        .activity-login {
            background-color: #16A34A;
        }

        /* hijau */
        .activity-task {
            background-color: #9CA3AF;
        }

        /* abu */
        .activity-comment {
            background-color: #DC2626;
        }

        /* merah */

        /* Table */
        .table-wrap {
            overflow-x: auto;
        }

        table thead tr {
            background: #f3f4f6;
        }

        th,
        td {
            padding: .625rem 1rem;
            white-space: nowrap;
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop (≥ lg) -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="slink active"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li>
                    <!-- Notifikasi dengan badge jumlah unread -->
                    <a href="notifications.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-bell mr-3"></i>Notifikasi</span>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge shrink-0"><?= (int)$unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
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
                    <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="user-activity.php" class="slink active"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li>
                        <!-- Notifikasi dengan badge jumlah unread (drawer) -->
                        <a href="notifications.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center"><i class="fas fa-bell mr-3"></i>Notifikasi</span>
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge shrink-0"><?= (int)$unreadCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
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
                    <!-- Hamburger muncul hanya di layar kecil -->
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Aktivitas Pengguna - Staff</div>
                </div>
                <form action='../../controllers/logout.php' method='POST' class="hidden lg:block">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <div class="card p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6">Log Aktivitas Saya</h3>

                    <div class="table-wrap">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr class="border-b">
                                    <th class="py-2 px-4 text-left text-gray-700">No</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Deskripsi Aktivitas</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Waktu</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$logs): ?>
                                    <tr>
                                        <td colspan="4" class="py-6 px-4 text-center text-gray-500">Belum ada aktivitas.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = 1;
                                    foreach ($logs as $r):
                                        $tbl = table_label_staff($r['table']);
                                        $time = date('Y-m-d H:i', strtotime($r['time']));
                                        $name = $r['name']; // bisa null kalau bukan 'tasks'
                                        [$statusText, $statusClass] = action_badge($r['action']);
                                        // bangun deskripsi lalu escape saat cetak (hindari double-escape)
                                        $desc = "Melakukan " . ucfirst($r['action']) . " pada " . $tbl . ($name ? " " . $name : "");
                                    ?>
                                        <tr class="border-b">
                                            <td class="py-2 px-4"><?= $no++ ?></td>
                                            <td class="py-2 px-4"><?= e($desc) ?></td>
                                            <td class="py-2 px-4"><?= e($time) ?></td>
                                            <td class="py-2 px-4">
                                                <span class="activity-status <?= e($statusClass) ?>"><?= e($statusText) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

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