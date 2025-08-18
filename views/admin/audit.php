<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

/* ========== Utils ========== */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

function set_flash($k, $v)
{
    $_SESSION['flash'][$k] = $v;
}
function get_flash($k)
{
    if (isset($_SESSION['flash'][$k])) {
        $v = $_SESSION['flash'][$k];
        unset($_SESSION['flash'][$k]);
        return $v;
    }
    return null;
}
function qs_keep($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

/* ========== Filters & Pagination ========== */
$q       = trim($_GET['q'] ?? '');          // cari action / table / user / affected_id
$actionF = trim($_GET['action'] ?? '');     // '', 'create','update','delete'
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = [];
$types  = '';
$params = [];

if ($q !== '') {
    $where[] = "(al.action LIKE ? OR al.affected_table LIKE ? OR u.name LIKE ? OR al.affected_id LIKE ?)";
    $kw = "%$q%";
    $types .= 'ssss';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}
if ($actionF !== '') {
    $where[] = "al.action = ?";
    $types  .= 's';
    $params[] = $actionF;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ========== Count total ========== */
$total = 0;
$sqlCount = "SELECT COUNT(*) c
             FROM audit_logs al
             LEFT JOIN users u ON al.user_id = u.id
             $whereSql";
if ($st = $conn->prepare($sqlCount)) {
    if ($types !== '') {
        $refs = [];
        foreach ($params as $k => &$v) $refs[$k] = &$v;
        $st->bind_param($types, ...$refs);
    }
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) $total = (int)$row['c'];
    $st->close();
}
$totalPages = max(1, (int)ceil($total / $perPage));

/* ========== Fetch list ========== */
$sqlList = "SELECT
                al.id, al.action, al.affected_table, al.affected_id,
                al.user_id, al.created_at,
                u.name AS user_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $whereSql
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";
$types2 = $types . 'ii';
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;

$rows = [];
if ($st = $conn->prepare($sqlList)) {
    $refs = [];
    foreach ($params2 as $k => &$v) $refs[$k] = &$v;
    $st->bind_param($types2, ...$refs);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();
}

/* ========== UI helpers ========== */
function action_badge($a)
{
    $a = strtolower((string)$a);

    if (in_array($a, ['create', 'insert', 'add'])) {
        return ['Buat', 'bg-green-50 text-green-700 border border-green-200', 'fa-plus-circle'];
    }
    if (in_array($a, ['update', 'edit', 'change'])) {
        return ['Ubah', 'bg-yellow-50 text-yellow-700 border border-yellow-200', 'fa-edit'];
    }
    if (in_array($a, ['delete', 'remove'])) {
        return ['Hapus', 'bg-red-50 text-red-700 border border-red-200', 'fa-trash-alt'];
    }

    // aksi selain Buat/Ubah/Hapus -> tidak tampilkan badge
    return [null, null, null];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>SIMTIB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #111827;
            --paper: #fff;
            --line: #e5e7eb;
            --ink-strong: #000;
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

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

        /* Sidebar */
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

        /* Drawer (mobile) */
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
                <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="audit.php" class="slink active"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
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
                    <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                    <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                    <li><a href="audit.php" class="slink active"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                    <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
                </ul>
                <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
        </div>

        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Audit</div>
                </div>
                <a class="btn btn-outline" href='../../controllers/logout.php'><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">

                <!-- Flash -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-green-200 bg-white text-green-700"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if ($msg = get_flash('error')): ?>
                    <div class="mb-4 p-3 rounded border border-red-200 bg-white text-red-700"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <!-- Toolbar -->
                <div class="card p-4 mb-4">
                    <form class="grid grid-cols-1 md:grid-cols-3 gap-3 items-stretch md:items-end" method="get" action="audit.php">
                        <div class="md:col-span-2">
                            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                                placeholder="Cari aksi/tabel/pengguna/ID terdampak…"
                                class="field">
                        </div>
                        <div class="flex gap-2">
                            <select name="action" class="field">
                                <option value="">Semua aksi</option>
                                <option value="create" <?= $actionF === 'create' ? 'selected' : ''; ?>>Create</option>
                                <option value="update" <?= $actionF === 'update' ? 'selected' : ''; ?>>Update</option>
                                <option value="delete" <?= $actionF === 'delete' ? 'selected' : ''; ?>>Delete</option>
                            </select>
                            <button class="btn btn-black w-full md:w-auto"><i class="fas fa-search mr-2"></i> Cari</button>
                            <?php if ($q !== '' || $actionF !== ''): ?>
                                <a href="audit.php" class="btn btn-outline w-full md:w-auto">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- List -->
                <div class="card p-0 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left bg-gray-100">
                                <th class="py-2 px-4">No</th>
                                <th class="py-2 px-4">Aksi</th>
                                <th class="py-2 px-4">Tabel & ID</th>
                                <th class="py-2 px-4">Pengguna</th>
                                <th class="py-2 px-4">Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-600">Tidak ada data audit.</td>
                                </tr>
                                <?php else: $no = $offset + 1;
                                foreach ($rows as $r):
                                    [$label, $badgeCls, $icon] = action_badge($r['action']);
                                    $uname = $r['user_name'] ? $r['user_name'] : ('Pengguna #' . (int)$r['user_id']);
                                    $when  = $r['created_at'] ? date('Y-m-d H:i', strtotime($r['created_at'])) : '-';
                                ?>
                                    <tr class="border-t border-gray-200">
                                        <td class="py-2 px-4"><?= $no++ ?></td>
                                        <td class="py-2 px-4">
                                            <?php if ($label): ?>
                                                <span class="inline-flex items-center gap-2 px-2 py-1 rounded text-xs <?= $badgeCls ?>">
                                                    <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-500">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-4">
                                            <div class="font-medium"><?= htmlspecialchars($r['affected_table']) ?></div>
                                            <div class="text-gray-700">ID: <?= (int)$r['affected_id'] ?></div>
                                        </td>
                                        <td class="py-2 px-4"><?= htmlspecialchars($uname) ?></td>
                                        <td class="py-2 px-4"><?= htmlspecialchars($when) ?></td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-sm">
                            <div>Menampilkan <strong><?= count($rows) ?></strong> dari <strong><?= $total ?></strong> baris audit</div>
                            <div class="flex flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="audit.php?<?= qs_keep(['page' => $p]) ?>"
                                        class="px-3 py-1 rounded border <?= $p == $page ? 'bg-black text-white border-black' : 'bg-white text-gray-800 hover:bg-gray-50 border-gray-300' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Drawer (mobile)
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