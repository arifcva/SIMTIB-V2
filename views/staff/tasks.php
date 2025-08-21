<?php

/******************************
 *  Guard & Runtime
 ******************************/
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../auth/login.php');
    exit;
}

date_default_timezone_set('Asia/Makassar');

/******************************
 *  Security Headers & Cache
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
 *  DB Connection
 ******************************/
require_once '../../config/database.php'; // harus set $conn = new mysqli(...)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
$conn->set_charset('utf8mb4');

/******************************
 *  CSRF
 ******************************/
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

/******************************
 *  Context
 ******************************/
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/******************************
 *  Unread Notifications Count (untuk badge)
 ******************************/
$unreadCount = 0;
$st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($unreadCount);
$st->fetch();
$st->close();

/******************************
 *  Helpers
 ******************************/
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function status_badge_info(string $s): array
{
    $s = strtolower($s);
    if ($s === 'in progress') return ['Sedang Dikerjakan', 'in-progress'];
    if ($s === 'completed')   return ['Selesai', 'completed'];
    return ['Pending', 'pending'];
}

/******************************
 *  Handle POST: Update Status
 ******************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    // CSRF check
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal: CSRF token tidak valid.'];
        header('Location: tasks.php');
        exit;
    }

    $taskId    = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $newStatus = isset($_POST['status']) ? trim(strtolower($_POST['status'])) : '';

    $allowed = ['pending', 'in progress', 'completed'];
    if ($taskId <= 0 || !in_array($newStatus, $allowed, true)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Input tidak valid.'];
        header('Location: tasks.php');
        exit;
    }

    // Pastikan tugas milik staf ini
    $st = $conn->prepare("SELECT id FROM tasks WHERE id=? AND assigned_to=? LIMIT 1");
    $st->bind_param('ii', $taskId, $userId);
    $st->execute();
    $st->store_result();
    if ($st->num_rows === 0) {
        $st->close();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Tugas tidak ditemukan atau bukan milik Anda.'];
        header('Location: tasks.php');
        exit;
    }
    $st->close();

    // Update status + updated_at
    $st = $conn->prepare("UPDATE tasks SET status=?, updated_at=NOW() WHERE id=? AND assigned_to=?");
    $st->bind_param('sii', $newStatus, $taskId, $userId);
    $st->execute();
    $st->close();

    // Catat audit log agar admin melihat perubahan ini
    // Asumsi struktur audit_logs: (id, action, affected_table, affected_id, user_id, created_at)
    $st = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('update','tasks',?,?,NOW())");
    $st->bind_param('ii', $taskId, $userId);
    $st->execute();
    $st->close();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status tugas berhasil diperbarui.'];
    header('Location: tasks.php');
    exit;
}

/******************************
 *  Fetch Tugas Milik Staf
 ******************************/
$tasks = [];
$st = $conn->prepare("
    SELECT id, title, status, created_at, updated_at
    FROM tasks
    WHERE assigned_to = ?
    ORDER BY created_at ASC, id ASC
");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($tid, $ttitle, $tstatus, $tcreated, $tupdated);
while ($st->fetch()) {
    $tasks[] = [
        'id'      => (int)$tid,
        'title'   => $ttitle,
        'status'  => $tstatus,
        'created' => $tcreated,
        'updated' => $tupdated,
    ];
}
$st->close();

// Ambil & hapus flash dari session
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SIMTIB</title>
    <!-- icon -->
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

        /* Card, fields, buttons */
        .card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        .field {
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: .5rem;
        }

        .field:focus {
            outline: none;
            border-color: #111;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, .15);
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

        /* Badge status tugas */
        .task-status {
            color: #fff;
            padding: .375rem .625rem;
            border-radius: 12px;
            font-size: .85rem;
        }

        .pending {
            background-color: #9CA3AF;
        }

        .in-progress {
            background-color: #FFB020;
        }

        .completed {
            background-color: #16A34A;
        }

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

        /* ===== Badge jumlah notifikasi – sesuai referensi (ukuran tetap & tidak dorong sidebar) ===== */
        .badge {
            min-width: 1.5rem;
            /* 24px */
            height: 1.5rem;
            /* 24px */
            line-height: 1.5rem;
            padding: 0 .5rem;
            /* tampung 2+ digit */
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

        /* Saat link aktif (bg hitam), badge otomatis di-invert agar kontras */
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
                <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
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
                    <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
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
            <!-- Topbar -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <!-- Hamburger hanya di kecil -->
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Manajemen Tugas - Staff</div>
                </div>
                <form action='../../controllers/logout.php' method='POST' class="hidden lg:block">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">

                <?php if ($flash): ?>
                    <div class="mb-4 border rounded-lg p-3 <?= $flash['type'] === 'success' ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800' ?>">
                        <?= e($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <div class="card p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6">Daftar Tugas Saya</h3>

                    <div class="table-wrap">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr class="border-b">
                                    <th class="py-2 px-4 text-left text-gray-700">No</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Nama Tugas</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Tanggal Dibuat</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Tanggal Update</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Status</th>
                                    <th class="py-2 px-4 text-left text-gray-700">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tasks): ?>
                                    <tr>
                                        <td colspan="6" class="py-6 px-4 text-center text-gray-500">Belum ada tugas.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = 1;
                                    foreach ($tasks as $t):
                                        [$label, $cls] = status_badge_info($t['status']);
                                    ?>
                                        <tr class="border-b">
                                            <td class="py-2 px-4"><?= $no++ ?></td>
                                            <td class="py-2 px-4"><?= e($t['title']) ?></td>
                                            <td class="py-2 px-4"><?= e($t['created']) ?></td>
                                            <td class="py-2 px-4"><?= e($t['updated']) ?></td>
                                            <td class="py-2 px-4">
                                                <span class="task-status <?= e($cls) ?>"><?= e($label) ?></span>
                                            </td>
                                            <td class="py-2 px-4">
                                                <form method="POST" class="flex items-center gap-2">
                                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                                    <select name="status" class="field">
                                                        <option value="pending" <?= strtolower($t['status']) === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="in progress" <?= strtolower($t['status']) === 'in progress' ? 'selected' : ''; ?>>Sedang Dikerjakan</option>
                                                        <option value="completed" <?= strtolower($t['status']) === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-black">Update Status</button>
                                                </form>
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