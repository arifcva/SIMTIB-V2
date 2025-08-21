<?php
// ====== Guard & Runtime ======
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../auth/login.php');
    exit;
}
date_default_timezone_set('Asia/Makassar');

// ====== Security Headers & Cache ======
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
// Catatan: ada sedikit inline JS → izinkan 'unsafe-inline'. Idealnya pindah ke file .js.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'");

// ====== DB ======
require_once '../../config/database.php'; // $conn = new mysqli(...)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
$conn->set_charset('utf8mb4');

// ====== CSRF for logout ======
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// ====== User Context ======
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// ====== Unread notifications count ======
$unreadCount = 0;
$st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
$userIdForQuery = $userId ?? 0;
$st->bind_param('i', $userIdForQuery);
$st->execute();
$st->bind_result($unreadCount);
$st->fetch();
$st->close();

// ====== Stats (dinamis, difilter untuk staff) ======
$stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'pending' => 0];
if ($userId) {
    $st = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='in progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending
        FROM tasks
        WHERE assigned_to = ?
    ");
    $st->bind_param('i', $userId);
} else {
    $st = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='in progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending
        FROM tasks
    ");
}
$st->execute();
$st->bind_result($stats['total'], $stats['completed'], $stats['in_progress'], $stats['pending']);
$st->fetch();
$st->close();

$totalTasks      = (int)$stats['total'];
$completedTasks  = (int)$stats['completed'];
$inProgressTasks = (int)$stats['in_progress'];
$pendingTasks    = (int)$stats['pending'];
$notFinished     = max(0, $totalTasks - $completedTasks);

// ====== Activity Logs (terbaru oleh staff ini) — tampilkan NAMA, bukan #ID ======
$logs = [];
if ($userId) {
    // Join kondisional: jika affected_table='tasks', ambil tasks.title sebagai entity_name
    $st = $conn->prepare("
        SELECT 
            al.action,
            al.affected_table,
            al.affected_id,
            al.created_at,
            CASE 
                WHEN al.affected_table = 'tasks' THEN t.title
                ELSE NULL
            END AS entity_name
        FROM audit_logs al
        LEFT JOIN tasks t 
            ON t.id = al.affected_id AND al.affected_table = 'tasks'
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 5
    ");
    $st->bind_param('i', $userId);
} else {
    $st = $conn->prepare("
        SELECT 
            al.action,
            al.affected_table,
            al.affected_id,
            al.created_at,
            CASE 
                WHEN al.affected_table = 'tasks' THEN t.title
                ELSE NULL
            END AS entity_name
        FROM audit_logs al
        LEFT JOIN tasks t 
            ON t.id = al.affected_id AND al.affected_table = 'tasks'
        ORDER BY al.created_at DESC
        LIMIT 5
    ");
}
$st->execute();
$st->bind_result($a, $t, $i, $c, $ename);
while ($st->fetch()) {
    $logs[] = [
        'action' => $a,
        'table'  => $t,
        'id'     => (int)$i,       // fallback/debug
        'time'   => $c,
        'name'   => $ename         // null jika bukan tabel 'tasks'
    ];
}
$st->close();

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
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Staff</title>
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">

    <!-- Tailwind (browser build) -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4" defer></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #111827;
            /* teks utama */
            --paper: #ffffff;
            /* latar */
            --muted: #6b7280;
            /* teks sekunder */
            --line: #e5e7eb;
            /* border */
            --ink-strong: #000;
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

        /* Sidebar desktop (Putih) */
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

        /* ikon ikut warna link */
        .slink.active .fas {
            color: #fff !important;
        }

        /* ikon putih saat active */

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

        /* Kartu & tombol */
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
            justify-content: center
        }

        .btn-outline {
            background: #fff;
            color: #111;
            border: 1px solid #111
        }

        .btn-outline:hover {
            background: #111;
            color: #fff
        }

        /* Chart sizing (responsive) */
        .chart-wrap {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        canvas#taskStatusChart {
            width: 100% !important;
            height: 280px !important;
            display: block;
        }

        /* ===== Badge jumlah notifikasi – ukuran tetap (sesuai referensi) ===== */
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
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            /* jangan melar/menyusut */
        }

        /* Invert saat link aktif (bg hitam) agar tetap kontras */
        .slink.active .badge {
            background: #fff;
            color: #000;
            border-color: #fff;
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop (≥ lg) -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink active"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>

                <!-- Notifikasi dengan badge jumlah unread (sidebar) -->
                <li>
                    <a href="notifications.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center">
                            <i class="fas fa-bell mr-3"></i>Notifikasi
                        </span>
                        <?php if ((int)$unreadCount > 0): ?>
                            <span class="badge shrink-0" aria-label="Notifikasi belum dibaca">
                                <?= (int)$unreadCount ?>
                            </span>
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
                    <li><a href="dashboard.php" class="slink active"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>

                    <!-- Notifikasi dengan badge jumlah unread (drawer) -->
                    <li>
                        <a href="notifications.php" class="slink flex items-center justify-between">
                            <span class="inline-flex items-center">
                                <i class="fas fa-bell mr-3"></i>Notifikasi
                            </span>
                            <?php if ((int)$unreadCount > 0): ?>
                                <span class="badge shrink-0" aria-label="Notifikasi belum dibaca">
                                    <?= (int)$unreadCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li><a href="profile.php" class="slink"><i class="fas a-user mr-3"></i>Profil</a></li>
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
                    <div class="text-base lg:text-lg font-bold">Dashboard Staff</div>
                </div>
                <form action='../../controllers/logout.php' method='POST' class="hidden lg:block">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">

                <!-- Cards ringkasan -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="card rounded-xl p-6">
                        <div class="flex items-center text-3xl font-bold">
                            <i class="fas fa-tasks mr-3"></i><?= (int)$totalTasks ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Total Tugas Saya</span>
                    </div>
                    <div class="card rounded-xl p-6">
                        <div class="flex items-center text-3xl font-bold">
                            <i class="fas fa-spinner mr-3"></i><?= (int)$inProgressTasks ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Sedang Dikerjakan</span>
                    </div>
                    <div class="card rounded-xl p-6">
                        <div class="flex items-center text-3xl font-bold">
                            <i class="fas fa-check-circle mr-3"></i><?= (int)$completedTasks ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Selesai</span>
                    </div>
                    <div class="card rounded-xl p-6">
                        <div class="flex items-center text-3xl font-bold">
                            <i class="fas fa-times-circle mr-3"></i><?= (int)$notFinished ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Belum Selesai</span>
                    </div>
                </div>

                <!-- Logs + Chart -->
                <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Log Aktivitas -->
                    <div class="card rounded-xl p-6">
                        <h3 class="text-lg font-semibold mb-4">Log Aktivitas Saya</h3>
                        <?php if (!$logs): ?>
                            <div class="text-gray-500">Belum ada aktivitas.</div>
                        <?php else: ?>
                            <ul class="divide-y divide-gray-200">
                                <?php foreach ($logs as $r):
                                    $tbl   = table_label_staff($r['table']);
                                    $time  = date('Y-m-d H:i', strtotime($r['time']));
                                    $entityName = $r['name'] ?: null;
                                ?>
                                    <li class="py-3 flex justify-between items-start">
                                        <div class="flex items-start gap-3">
                                            <i class="fas fa-info-circle mt-1"></i>
                                            <div>
                                                <div class="font-medium">
                                                    Melakukan <span class="capitalize"><?= e($r['action']) ?></span>
                                                    pada <span class="capitalize"><?= e($tbl) ?></span>
                                                    <?php if ($entityName): ?>
                                                        <span class="font-semibold"> <?= e($entityName) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500"><?= e($time) ?></div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Chart -->
                    <div class="card rounded-xl p-6 flex flex-col">
                        <h3 class="text-lg font-semibold mb-4 text-center">Statistik Status Tugas</h3>
                        <div class="chart-wrap">
                            <canvas id="taskStatusChart"></canvas>
                        </div>
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

        /* Chart.js: inisialisasi setelah DOM & script eksternal siap */
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined') return; // guard jika CDN gagal
            const done = <?= (int)$completedTasks ?>;
            const prog = <?= (int)$inProgressTasks ?>;
            const total = <?= (int)$totalTasks ?>;
            const notFinished = Math.max(0, total - done);

            const ctx = document.getElementById('taskStatusChart').getContext('2d');
            Chart.defaults.devicePixelRatio = 1;

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Sedang Dikerjakan', 'Belum Selesai'],
                    datasets: [{
                        data: [done, prog, notFinished],
                        backgroundColor: ['#111827', '#9ca3af', '#e5e7eb'], // monokrom
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#000'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: (c) => `${c.label}: ${c.parsed} tugas`
                            }
                        }
                    },
                    animation: {
                        animateScale: true
                    }
                }
            });
        });
    </script>
</body>

</html>