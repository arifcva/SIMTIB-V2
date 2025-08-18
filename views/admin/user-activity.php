<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

/* ---------- helpers ---------- */
function time_ago($datetime)
{
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    if (!$ts) return '-';
    $diff = time() - $ts;
    if ($diff < 60) return $diff . ' detik lalu';
    $mins = floor($diff / 60);
    if ($mins < 60) return $mins . ' menit lalu';
    $hours = floor($mins / 60);
    if ($hours < 24) return $hours . ' jam lalu';
    $days = floor($hours / 24);
    if ($days < 30) return $days . ' hari lalu';
    $months = floor($days / 30);
    if ($months < 12) return $months . ' bulan lalu';
    $years = floor($months / 12);
    return $years . ' tahun lalu';
}

function icon_for($action, $table)
{
    $map = [
        'login'   => 'fa-sign-in-alt',
        'logout'  => 'fa-sign-out-alt',
        'create'  => 'fa-plus-circle',
        'update'  => 'fa-edit',
        'delete'  => 'fa-trash',
        'comment' => 'fa-comment-alt',
        'assign'  => 'fa-user-check',
        'status'  => 'fa-check-circle',
    ];
    if (isset($map[$action])) return $map[$action];

    $tmap = [
        'tasks'     => 'fa-tasks',
        'comments'  => 'fa-comment',
        'users'     => 'fa-users',
        'roles'     => 'fa-user-cog',
        'audit'     => 'fa-clipboard-list',
        'profiles'  => 'fa-user',
        'notifs'    => 'fa-bell',
        'notifications' => 'fa-bell',
    ];
    return $tmap[$table] ?? 'fa-clipboard-list';
}

// untuk bind_param variadik (by-ref)
function refValues(array &$arr)
{
    $refs = [];
    foreach ($arr as $k => &$v) $refs[$k] = &$v;
    return $refs;
}

/* ---------- query params ---------- */
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$action   = isset($_GET['action']) ? trim($_GET['action']) : '';
$table    = isset($_GET['table']) ? trim($_GET['table']) : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;
$offset   = ($page - 1) * $perPage;

$allowedActions = ['create', 'update', 'delete', 'login', 'logout', 'comment', 'assign', 'status'];
$allowedTables  = ['tasks', 'comments', 'users', 'notifications', 'audit_logs', 'profiles', 'roles'];

if ($action !== '' && !in_array($action, $allowedActions, true)) $action = '';
if ($table  !== '' && !in_array($table,  $allowedTables,  true)) $table  = '';

/* ---------- build WHERE ---------- */
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(a.action LIKE ? OR a.affected_table LIKE ? OR u.name LIKE ?)";
    $kw = "%$q%";
    $types .= 'sss';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}
if ($action !== '') {
    $where[] = "a.action = ?";
    $types .= 's';
    $params[] = $action;
}
if ($table  !== '') {
    $where[] = "a.affected_table = ?";
    $types .= 's';
    $params[] = $table;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- count total ---------- */
$total = 0;
$sqlCount = "SELECT COUNT(*) c
             FROM audit_logs a
             LEFT JOIN users u ON a.user_id = u.id
             $whereSql";
if ($stmt = $conn->prepare($sqlCount)) {
    if ($types !== '') {
        $bp = array_merge([$types], refValues($params));
        $stmt->bind_param(...$bp);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $total = (int)$row['c'];
    $stmt->close();
}
$totalPages = max(1, (int)ceil($total / $perPage));

/* ---------- fetch data ---------- */
$sql = "SELECT a.id, a.action, a.affected_table, a.affected_id, a.user_id, a.created_at,
               u.name AS user_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $whereSql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";

$logs = [];
if ($stmt = $conn->prepare($sql)) {
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    $bp = array_merge([$types2], refValues($params2));
    $stmt->bind_param(...$bp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $logs[] = $row;
    $stmt->close();
}

/* ---------- builder pesan ---------- */
function build_message($row)
{
    $user = $row['user_name'] ? $row['user_name'] : 'Pengguna #' . $row['user_id'];
    $act  = $row['action'];
    $tbl  = $row['affected_table'];
    $id   = $row['affected_id'];

    switch ($act) {
        case 'login':
            return "$user login ke sistem";
        case 'logout':
            return "$user logout dari sistem";
        case 'create':
            return "$user menambahkan $tbl #$id";
        case 'update':
            return "$user mengedit $tbl #$id";
        case 'delete':
            return "$user menghapus $tbl #$id";
        case 'comment':
            return "$user mengomentari $tbl #$id";
        case 'assign':
            return "$user mengubah penugasan $tbl #$id";
        case 'status':
            return "$user mengubah status $tbl #$id";
        default:
            return "$user melakukan $act pada $tbl #$id";
    }
}

function qs_keep($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <title>SIMTIB</title>
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #111827;
            --paper: #fff;
            --muted: #6b7280;
            --line: #e5e7eb;
            --ink-strong: #000;
        }

        body {
            background: var(--paper);
            color: var(--ink)
        }

        /* card, table, buttons – sama dengan notifications.php */
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

        thead tr {
            background: #f3f4f6
        }

        .th,
        .td {
            padding: .625rem 1rem
        }

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

        /* Sidebar + drawer – sama */
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
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="slink active"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
        </aside>

        <!-- Drawer mobile (sidebar kecil) -->
        <div id="drawer" class="drawer lg:hidden">
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
            <!-- Topbar – sama gaya dgn notifications -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="text-base lg:text-lg font-bold">Aktivitas Pengguna</div>
                </div>
                <a href='../../controllers/logout.php' class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <!-- Filter & Search -->
                <div class="card p-4 mb-6">
                    <form class="grid grid-cols1 md:grid-cols-4 gap-3 items-end" method="get" action="user-activity.php">
                        <div class="md:col-span-2">
                            <label class="block text-sm mb-1">Pencarian</label>
                            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari aksi / tabel / nama user" class="field">
                        </div>
                        <div>
                            <label class="block text-sm mb-1">Aksi</label>
                            <select name="action" class="field">
                                <option value="">Semua</option>
                                <?php foreach ($allowedActions as $a): ?>
                                    <option value="<?= $a ?>" <?= $action === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm mb-1">Tabel</label>
                            <select name="table" class="field">
                                <option value="">Semua</option>
                                <?php foreach ($allowedTables as $t): ?>
                                    <option value="<?= $t ?>" <?= $table === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-4 flex gap-2">
                            <button class="btn btn-black inline-flex items-center"><i class="fas fa-search mr-2"></i> Terapkan</button>
                            <a href="user-activity.php" class="btn btn-outline">Reset</a>
                        </div>
                    </form>
                </div>

                <!-- User Activity Logs -->
                <div class="card p-0">
                    <?php if (!$logs): ?>
                        <div class="p-6 text-center text-gray-600">Belum ada log yang cocok.</div>
                    <?php else: ?>
                        <ul class="divide-y divide-gray-200">
                            <?php foreach ($logs as $row):
                                $icon = icon_for($row['action'], $row['affected_table']);
                                $message = build_message($row);
                                $ago = time_ago($row['created_at']);
                            ?>
                                <li class="py-3 px-4 flex justify-between items-center">
                                    <div class="flex items-center">
                                        <i class="fas <?= $icon ?> text-black mr-3"></i>
                                        <div>
                                            <div class="text-gray-900"><?= htmlspecialchars($message) ?></div>
                                            <div class="text-xs text-gray-600">
                                                aksi: <span class="font-mono"><?= htmlspecialchars($row['action']) ?></span> •
                                                tabel: <span class="font-mono"><?= htmlspecialchars($row['affected_table']) ?></span> •
                                                id: <span class="font-mono"><?= (int)$row['affected_id'] ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-600"><?= htmlspecialchars($ago) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-sm">
                                <div>Menampilkan <strong><?= count($logs) ?></strong> dari <strong><?= $total ?></strong> log</div>
                                <div class="flex flex-wrap gap-1">
                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <a href="user-activity.php?<?= qs_keep(['page' => $p]) ?>"
                                            class="page <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        /* Drawer (mobile) controls – sama persis dengan notifications */
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