<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
function check_csrf()
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

/* ---------- Flash ---------- */
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

/* ---------- bind_param variadik ---------- */
function refValues(array &$arr)
{
    $r = [];
    foreach ($arr as $k => &$v) {
        $r[$k] = &$v;
    }
    return $r;
}

/* ---------- DELETE task ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    check_csrf();
    $taskId = (int)$_POST['delete_id'];

    if ($st = $conn->prepare("DELETE FROM tasks WHERE id=?")) {
        $st->bind_param("i", $taskId);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            // tulis audit jika user valid
            $actor = 0;
            if (isset($_SESSION['user_id'])) {
                $uid = (int)$_SESSION['user_id'];
                if ($uid > 0 && ($chk = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1"))) {
                    $chk->bind_param("i", $uid);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 1) $actor = $uid;
                    $chk->close();
                }
            }
            if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('delete','tasks',?,?,NOW())"))) {
                $al->bind_param("ii", $taskId, $actor);
                $al->execute();
                $al->close();
            }
            set_flash('success', 'Tugas berhasil dihapus.');
        } else {
            set_flash('error', 'Gagal menghapus tugas.');
        }
    } else {
        set_flash('error', 'Gagal menyiapkan query.');
    }
    header('Location: tasks.php');
    exit;
}

/* ---------- Query params ---------- */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$validStatuses = ['pending', 'in progress', 'completed'];
if ($status !== '' && !in_array($status, $validStatuses, true)) $status = '';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

/* ---------- WHERE ---------- */
$whereSql = '';
$conds = [];
$types = '';
$params = [];
if ($search !== '') {
    $conds[] = "(t.title LIKE ? OR t.description LIKE ?)";
    $kw = "%$search%";
    $types .= 'ss';
    $params[] = $kw;
    $params[] = $kw;
}
if ($status !== '') {
    $conds[] = "t.status = ?";
    $types .= 's';
    $params[] = $status;
}
if ($conds) $whereSql = 'WHERE ' . implode(' AND ', $conds);

/* ---------- Count ---------- */
$total = 0;
$sqlCount = "SELECT COUNT(*) c FROM tasks t $whereSql";
if ($st = $conn->prepare($sqlCount)) {
    if ($types !== '') {
        $bp = array_merge([$types], refValues($params));
        $st->bind_param(...$bp);
    }
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) $total = (int)$row['c'];
    $st->close();
}
$totalPages = max(1, (int)ceil($total / $perPage));

/* ---------- List ---------- */
$sql = "SELECT t.id,t.title,t.description,t.status,t.created_at,t.updated_at,
             u.name AS assigned_user
      FROM tasks t
      LEFT JOIN users u ON t.assigned_to=u.id
      $whereSql
      ORDER BY t.created_at DESC
      LIMIT ? OFFSET ?";
$tasks = [];
if ($st = $conn->prepare($sql)) {
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    $bp = array_merge([$types2], refValues($params2));
    $st->bind_param(...$bp);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) $tasks[] = $row;
    $st->close();
}

/* ---------- Helpers UI ---------- */
function status_badge($s)
{
    switch ($s) {
        case 'pending':
            return ['Belum Dikerjakan', 'badge'];
        case 'in progress':
            return ['Sedang Dikerjakan', 'badge'];
        case 'completed':
            return ['Selesai', 'badge'];
        default:
            return [$s, 'badge'];
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
    <meta charset="UTF-8">
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

        /* Sidebar & drawer (sama dengan notifications) */
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
                <li><a href="tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
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
                    <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
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
            <!-- Topbar (seragam) -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Manajemen Tugas</div>
                </div>
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
                    <form class="grid grid-cols-1 md:grid-cols-3 gap-3 items-stretch md:items-end" method="get" action="tasks.php">
                        <div class="md:col-span-2">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari judul / deskripsi…" class="field">
                        </div>
                        <div class="flex gap-2">
                            <select name="status" class="field">
                                <option value="">Semua Status</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : ''; ?>>Belum Dikerjakan</option>
                                <option value="in progress" <?= $status === 'in progress' ? 'selected' : ''; ?>>Sedang Dikerjakan</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                            </select>
                            <button class="btn btn-black w-full md:w-auto"><i class="fas fa-search mr-2"></i> Cari</button>
                            <?php if ($search !== '' || $status !== ''): ?>
                                <a href="tasks.php" class="btn btn-outline w-full md:w-auto">Reset</a>
                            <?php endif; ?>
                            <a href="./taskmanage/add-task.php" class="btn btn-outline w-full md:w-auto"><i class="fas fa-plus-circle mr-2"></i> Tambah</a>
                        </div>
                    </form>
                </div>

                <!-- Confirm delete bar -->
                <div id="confirmBar" class="hidden mb-4 p-4 card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex items-start md:items-center gap-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Yakin ingin menghapus tugas ini? Tindakan ini tidak bisa dibatalkan.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="confirmNo" class="btn btn-outline">Tidak</button>
                            <button id="confirmYes" class="btn btn-black">Ya, Hapus</button>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="card p-0 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="th">No</th>
                                <th class="th">Nama Tugas</th>
                                <th class="th">Deskripsi</th>
                                <th class="th">Ditugaskan ke</th>
                                <th class="th">Status</th>
                                <th class="th">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tasks): ?>
                                <tr>
                                    <td colspan="6" class="td py-6 text-center text-gray-600">Tidak ada data.</td>
                                </tr>
                                <?php else: $no = $offset + 1;
                                foreach ($tasks as $t):
                                    [$label, $cls] = status_badge($t['status']); ?>
                                    <tr class="border-t border-gray-200">
                                        <td class="td"><?= $no++ ?></td>
                                        <td class="td font-medium"><?= htmlspecialchars($t['title']) ?></td>
                                        <td class="td text-gray-700"><?= nl2br(htmlspecialchars($t['description'])) ?></td>
                                        <td class="td"><?= htmlspecialchars($t['assigned_user'] ?? '-') ?></td>
                                        <td class="td"><span class="<?= $cls ?>"><?= $label ?></span></td>
                                        <td class="td">
                                            <div class="flex flex-wrap gap-2">
                                                <a href="./taskmanage/edit-task.php?id=<?= (int)$t['id'] ?>" class="btn btn-outline"><i class="fas fa-edit"></i></a>
                                                <form method="post" action="tasks.php?<?= qs_keep() ?>" class="inline delete-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                                                    <button type="button" class="btn btn-outline delete-btn"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-sm">
                            <div>Menampilkan <strong><?= count($tasks) ?></strong> dari <strong><?= $total ?></strong> tugas</div>
                            <div class="flex flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="tasks.php?<?= qs_keep(['page' => $p]) ?>" class="page <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        /* Drawer controls (kecil saja) */
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

        /* Delete confirm bar */
        document.addEventListener('DOMContentLoaded', function() {
            const bar = document.getElementById('confirmBar');
            const yes = document.getElementById('confirmYes');
            const no = document.getElementById('confirmNo');
            let currentForm = null;
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentForm = this.closest('form');
                    bar.classList.remove('hidden');
                    bar.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                });
            });
            if (no) no.addEventListener('click', () => {
                bar.classList.add('hidden');
                currentForm = null;
            });
            if (yes) yes.addEventListener('click', () => {
                if (currentForm) currentForm.submit();
            });
        });
    </script>
</body>

</html>