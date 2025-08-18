<?php
// ===== Guard & DB =====
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

// ===== Helper count aman =====
function fetch_count(mysqli $conn, string $sql, ?string $param = null): int
{
    $total = 0;
    if ($param === null) {
        if ($res = $conn->query($sql)) {
            if ($row = $res->fetch_row()) $total = (int)$row[0];
            $res->close();
        }
    } else {
        if ($st = $conn->prepare($sql)) {
            $st->bind_param("s", $param);
            $st->execute();
            $res = $st->get_result();
            if ($row = $res->fetch_row()) $total = (int)$row[0];
            $st->close();
        }
    }
    return $total;
}

// ===== Ringkasan tugas =====
$totalTasks       = fetch_count($conn, "SELECT COUNT(*) FROM tasks");
$completedTasks   = fetch_count($conn, "SELECT COUNT(*) FROM tasks WHERE status=?", "completed");
$inProgressTasks  = fetch_count($conn, "SELECT COUNT(*) FROM tasks WHERE status=?", "in progress");
$pendingTasks     = fetch_count($conn, "SELECT COUNT(*) FROM tasks WHERE status=?", "pending");
$notFinishedTasks = max(0, $totalTasks - $completedTasks);

// ===== Log aktivitas terbaru =====
$logs = [];
if ($st = $conn->prepare("
    SELECT al.action, al.affected_table, al.affected_id, al.user_id, al.created_at, u.name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 5
")) {
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $logs[] = $r;
    $st->close();
}

// ===== Icon + label helper =====
function action_icon(string $action): array
{
    switch (strtolower($action)) {
        case 'create':
            return ['fa-plus-circle', 'text-black'];
        case 'update':
            return ['fa-edit',        'text-black'];
        case 'delete':
            return ['fa-trash-alt',   'text-black'];
        case 'login':
            return ['fa-sign-in-alt', 'text-black'];
        case 'logout':
            return ['fa-sign-out-alt', 'text-black'];
        default:
            return ['fa-info-circle', 'text-black'];
    }
}
function table_label(string $tbl): string
{
    switch (strtolower($tbl)) {
        case 'tasks':
            return 'tugas';
        case 'users':
            return 'pengguna';
        case 'comments':
            return 'komentar';
        case 'notifications':
            return 'notifikasi';
        default:
            return $tbl;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>SIMTIB</title>
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">

    <!-- Tailwind (browser build) -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #111827;
            /* teks utama */
            --paper: #fff;
            /* latar */
            --muted: #6b7280;
            /* teks sekunder */
            --line: #e5e7eb;
            /* border */
            --ink-strong: #000;
            /* hitam murni utk tombol aktif */
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

        /* kartu & kontrol (SAMA seperti referensi notifications/tasks) */
        .card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05)
        }

        .field {
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: .5rem
        }

        .field:focus {
            outline: none;
            border-color: #111;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, .15)
        }

        .btn {
            border-radius: .5rem;
            padding: .5rem 1rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-black {
            background: var(--ink-strong);
            color: #fff;
            border: 1px solid var(--ink-strong)
        }

        .btn-black:hover {
            background: #0a0a0a;
            border-color: #0a0a0a
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

        .badge {
            font-size: .75rem;
            padding: .125rem .5rem;
            border-radius: .375rem;
            border: 1px solid var(--line);
            color: #111;
            background: #f9fafb
        }

        /* table */
        thead tr {
            background: #f3f4f6
        }

        .th,
        .td {
            padding: .625rem 1rem
        }

        /* pagination */
        .page {
            padding: .25rem .75rem;
            border-radius: .375rem;
            border: 1px solid #d1d5db
        }

        .page:hover {
            background: #f9fafb
        }

        .page.active {
            background: #111;
            color: #fff;
            border-color: #111
        }

        /* Sidebar + Drawer (persis dengan referensi) */
        .sidebar {
            width: 16rem;
            background: var(--paper);
            border-right: 1px solid var(--line)
        }

        .sidebar-title {
            font-weight: 700;
            letter-spacing: .2px
        }

        .slink {
            display: block;
            padding: .625rem 1rem;
            border-radius: .5rem;
            color: var(--ink)
        }

        .slink:hover {
            background: #f9fafb
        }

        .slink.active {
            background: var(--ink-strong);
            color: #fff
        }

        .drawer {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 40
        }

        .drawer-panel {
            width: 16rem;
            max-width: 85vw;
            height: 100%;
            background: #fff;
            border-right: 1px solid var(--line);
            transform: translateX(-100%);
            transition: transform .25s ease
        }

        .drawer.show {
            pointer-events: auto
        }

        .drawer.show .drawer-panel {
            transform: translateX(0)
        }

        .drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            opacity: 0;
            transition: opacity .25s ease
        }

        .drawer.show .drawer-backdrop {
            opacity: 1
        }

        @media (min-width:1024px) {
            .drawer {
                display: none
            }
        }

        /* hide drawer on lg+ */

        /* chart wrapper fixed agar konsisten */
        .chart-fixed {
            width: 360px;
            height: 240px
        }

        #taskStatusChart {
            width: 360px !important;
            height: 240px !important;
            display: block
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink active"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
        </aside>

        <!-- Drawer mobile -->
        <div id="drawer" class="drawer lg:hidden">
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
                    <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                    <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                    <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                    <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
                </ul>
                <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
        </div>

        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar: hamburger muncul hanya di kecil -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Dashboard Admin</div>
                </div>
                <a href="../../controllers/logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">

                <!-- Cards ringkasan -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="card rounded-xl p-6">
                        <div class="flex items-center text-3xl font-bold">
                            <i class="fas fa-tasks mr-3"></i><?= (int)$totalTasks ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Total Tugas</span>
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
                            <i class="fas fa-times-circle mr-3"></i><?= (int)$notFinishedTasks ?>
                        </div>
                        <span class="mt-2 block text-lg text-gray-600">Belum Selesai</span>
                    </div>
                </div>

                <!-- Logs + Chart -->
                <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Log Aktivitas -->
                    <div class="card rounded-xl p-6">
                        <h3 class="text-lg font-semibold mb-4">Log Aktivitas Terbaru</h3>
                        <?php if (!$logs): ?>
                            <div class="text-gray-500">Belum ada aktivitas.</div>
                        <?php else: ?>
                            <ul class="divide-y divide-gray-200">
                                <?php foreach ($logs as $r):
                                    [$ic, $cls] = action_icon($r['action']);
                                    $actor = $r['name'] ? htmlspecialchars($r['name']) : 'Sistem';
                                    $tbl   = table_label($r['affected_table']);
                                    $id    = (int)$r['affected_id'];
                                    $time  = date('Y-m-d H:i', strtotime($r['created_at']));
                                ?>
                                    <li class="py-3 flex justify-between items-start">
                                        <div class="flex items-start gap-3">
                                            <i class="fas <?= $ic ?> <?= $cls ?> mt-1"></i>
                                            <div>
                                                <div class="font-medium">
                                                    <?= $actor ?> melakukan <span class="capitalize"><?= htmlspecialchars($r['action']) ?></span>
                                                    pada <span class="capitalize"><?= htmlspecialchars($tbl) ?></span> #<?= $id ?>
                                                </div>
                                                <div class="text-sm text-gray-500"><?= $time ?></div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Chart -->
                    <div class="card rounded-xl p-6 flex flex-col items-center">
                        <h3 class="text-lg font-semibold mb-4">Statistik Status Tugas</h3>
                        <div class="chart-fixed">
                            <canvas id="taskStatusChart" width="360" height="240"></canvas>
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

        /* Chart.js config */
        if (typeof Chart !== 'undefined') {
            Chart.defaults.devicePixelRatio = 1;
        }
        const completed = <?= (int)$completedTasks ?>;
        const inProgress = <?= (int)$inProgressTasks ?>;
        const pending = <?= (int)$pendingTasks ?>;

        const ctx = document.getElementById('taskStatusChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Sedang Dikerjakan', 'Pending'],
                datasets: [{
                    data: [completed, inProgress, pending],
                    backgroundColor: ['#111827', '#6b7280', '#d1d5db'],
                    /* monokrom */
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                devicePixelRatio: 1,
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
    </script>
</body>

</html>