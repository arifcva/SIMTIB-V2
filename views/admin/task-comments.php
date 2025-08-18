<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

/* ================= Utils ================= */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

function check_csrf()
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}
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
function refValues(array &$arr)
{
    $r = [];
    foreach ($arr as $k => &$v) {
        $r[$k] = &$v;
    }
    return $r;
}
function valid_user_id(mysqli $conn, ?int $uid): int
{
    if (!$uid || $uid <= 0) return 0;
    if ($st = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param("i", $uid);
        $st->execute();
        $st->store_result();
        $ok = ($st->num_rows === 1);
        $st->close();
        return $ok ? $uid : 0;
    }
    return 0;
}
function qs_keep($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

/* =============== AJAX: get assignee for a task =============== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'assignee') {
    header('Content-Type: application/json');
    $tid = (int)($_GET['task_id'] ?? 0);
    $resp = ['ok' => false, 'user_id' => null, 'name' => null, 'message' => null];
    if ($tid > 0 && ($st = $conn->prepare("
        SELECT t.assigned_to AS user_id, u.name
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.id=? LIMIT 1
    "))) {
        $st->bind_param("i", $tid);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
            if (!empty($row['user_id'])) {
                $resp['ok'] = true;
                $resp['user_id'] = (int)$row['user_id'];
                $resp['name'] = $row['name'] ?? '—';
            } else {
                $resp['message'] = 'Tugas ini belum memiliki assignee (assigned_to).';
            }
        } else {
            $resp['message'] = 'Tugas tidak ditemukan.';
        }
        $st->close();
    } else {
        $resp['message'] = 'Parameter tidak valid.';
    }
    echo json_encode($resp);
    exit;
}

/* ================= Handle POST ================= */
/* Add comment — hanya untuk assignee dari task tsb */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    check_csrf();
    $task_id = (int)($_POST['task_id'] ?? 0);
    $user_id = (int)($_POST['user_id'] ?? 0); // harus = tasks.assigned_to
    $comment = trim($_POST['comment'] ?? '');

    $errors = [];
    if ($task_id <= 0) $errors[] = 'Tugas wajib dipilih.';
    if ($comment === '') $errors[] = 'Komentar tidak boleh kosong.';

    $assigned_to = 0;
    if (!$errors && ($st = $conn->prepare("SELECT assigned_to FROM tasks WHERE id=? LIMIT 1"))) {
        $st->bind_param("i", $task_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        if (!$row) {
            $errors[] = 'Tugas tidak ditemukan.';
        } else {
            $assigned_to = (int)($row['assigned_to'] ?? 0);
            if ($assigned_to <= 0) {
                $errors[] = 'Tugas ini belum memiliki assignee. Tambahkan assignee pada halaman tugas.';
            }
        }
    }
    if (!$errors && ($user_id <= 0 || $user_id !== $assigned_to)) {
        $errors[] = 'Komentar hanya dapat ditujukan pada staf yang ditugaskan pada tugas ini.';
    }

    if (!$errors) {
        if ($ins = $conn->prepare("INSERT INTO comments (task_id, user_id, comment_text, created_at) VALUES (?,?,?,NOW())")) {
            $ins->bind_param("iis", $task_id, $user_id, $comment);
            $ok = $ins->execute();
            $newId = $ins->insert_id;
            $ins->close();
            if ($ok) {
                $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
                if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('create','comments',?,?,NOW())"))) {
                    $al->bind_param("ii", $newId, $actor);
                    $al->execute();
                    $al->close();
                }
                set_flash('success', 'Komentar berhasil ditambahkan.');
            } else set_flash('error', 'Gagal menambahkan komentar.');
        } else set_flash('error', 'Gagal menyiapkan query insert komentar.');
    } else set_flash('error', implode(' ', $errors));

    header('Location: task-comments.php');
    exit;
}

/* Delete comment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    check_csrf();
    $cid = (int)$_POST['delete_id'];
    if ($cid > 0 && ($st = $conn->prepare("DELETE FROM comments WHERE id=?"))) {
        $st->bind_param("i", $cid);
        $ok = $st->execute();
        $deleted = $st->affected_rows;
        $st->close();
        if ($ok && $deleted > 0) {
            $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
            if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('delete','comments',?,?,NOW())"))) {
                $al->bind_param("ii", $cid, $actor);
                $al->execute();
                $al->close();
            }
            set_flash('success', 'Komentar berhasil dihapus.');
        } else set_flash('error', 'Gagal menghapus komentar.');
    } else set_flash('error', 'Gagal menyiapkan query hapus.');
    header('Location: task-comments.php');
    exit;
}

/* ================= Dropdown data ================= */
$tasks_dd = [];
if ($rs = $conn->query("SELECT id,title FROM tasks ORDER BY created_at DESC LIMIT 100")) {
    while ($r = $rs->fetch_assoc()) $tasks_dd[] = $r;
    $rs->close();
}

/* ================= List + Search + Pagination ================= */
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = [];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = "(t.title LIKE ? OR u.name LIKE ? OR c.comment_text LIKE ?)";
    $kw = "%$search%";
    $types .= 'sss';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
$sqlCount = "SELECT COUNT(*) c
           FROM comments c
           JOIN tasks t ON c.task_id=t.id
           JOIN users u ON c.user_id=u.id
           $whereSql";
if ($stmt = $conn->prepare($sqlCount)) {
    if ($types !== '') {
        $bind = array_merge([$types], refValues($params));
        $stmt->bind_param(...$bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) $total = (int)$row['c'];
    $stmt->close();
}
$totalPages = max(1, (int)ceil($total / $perPage));

$sqlList = "SELECT c.id, c.comment_text, c.created_at,
                 t.title AS task_title,
                 u.name  AS user_name
          FROM comments c
          JOIN tasks t ON c.task_id=t.id
          JOIN users u ON c.user_id=u.id
          $whereSql
          ORDER BY c.created_at DESC
          LIMIT ? OFFSET ?";
$types2 = $types . 'ii';
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;

$comments = [];
if ($stmt = $conn->prepare($sqlList)) {
    $bind = array_merge([$types2], refValues($params2));
    $stmt->bind_param(...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $comments[] = $r;
    $stmt->close();
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
            --muted: #6b7280;
            --line: #e5e7eb;
            --ink-strong: #000;
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

        /* Komponen umum */
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

        thead tr {
            background: #f3f4f6
        }

        /* Sidebar desktop */
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
                <li><a href="task-comments.php" class="slink active"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
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
                    <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                    <li><a href="task-comments.php" class="slink active"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                    <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
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
                    <div class="text-base lg:text-lg font-bold">Komentar Tugas</div>
                </div>
                <a class="btn btn-outline" href='../../controllers/logout.php'><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <!-- Flash -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if ($msg = get_flash('error')): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <!-- Confirm bar -->
                <div id="confirmBar" class="hidden mb-4 p-4 card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex items-start md:items-center gap-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Yakin ingin menghapus komentar ini? Tindakan ini tidak bisa dibatalkan.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="confirmNo" class="btn btn-outline">Tidak</button>
                            <button id="confirmYes" class="btn btn-black">Ya, Hapus</button>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="card p-4 mb-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <button id="toggleAdd" class="btn btn-black inline-flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i> Tambah Komentar
                        </button>

                        <form class="flex w-full md:w-auto items-center gap-2" method="get" action="task-comments.php">
                            <div class="relative flex-1 md:w-55">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                    class="field pl-10" placeholder="        Cari Komentar...">
                            </div>
                            <button class="btn btn-black"><i class="fas fa-search mr-2"></i>Cari</button>
                            <?php if ($search !== ''): ?>
                                <a href="task-comments.php" class="btn btn-outline">Reset</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Panel Tambah Komentar -->
                <div id="addPanel" class="hidden mb-4 card p-4">
                    <form method="post" action="task-comments.php" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="add">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label>Tugas</label>
                                <select name="task_id" id="taskSelect" class="field" required>
                                    <option value="">Pilih tugas…</option>
                                    <?php foreach ($tasks_dd as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Staf (Assignee)</label>
                                <select id="assigneeView" class="field bg-gray-50 text-gray-700" disabled>
                                    <option value="">— pilih tugas dulu —</option>
                                </select>
                                <input type="hidden" name="user_id" id="assigneeId" value="">
                                <p id="assigneeHelp" class="text-xs text-red-600 mt-1 hidden">
                                    Tugas ini belum memiliki assignee. Tambahkan assignee pada halaman tugas.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label>Komentar</label>
                            <textarea name="comment" rows="4" class="field" placeholder="Tulis komentar…" required></textarea>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" id="cancelAdd" class="btn btn-outline">Batal</button>
                            <button type="submit" id="saveBtn" class="btn btn-black">Simpan</button>
                        </div>
                    </form>
                </div>

                <!-- List -->
                <div class="card p-0 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="th">No</th>
                                <th class="th">Tugas</th>
                                <th class="th">Komentar</th>
                                <th class="th">Pengguna</th>
                                <th class="th">Waktu</th>
                                <th class="th">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$comments): ?>
                                <tr>
                                    <td colspan="6" class="td py-6 text-center text-gray-600">Tidak ada komentar.</td>
                                </tr>
                                <?php else: $no = $offset + 1;
                                foreach ($comments as $c): ?>
                                    <tr class="border-t border-gray-200">
                                        <td class="td"><?= $no++ ?></td>
                                        <td class="td font-medium"><?= htmlspecialchars($c['task_title']) ?></td>
                                        <td class="td text-gray-700 max-w-xl"><?= nl2br(htmlspecialchars($c['comment_text'])) ?></td>
                                        <td class="td"><?= htmlspecialchars($c['user_name']) ?></td>
                                        <td class="td"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($c['created_at']))) ?></td>
                                        <td class="td">
                                            <div class="flex flex-wrap gap-2">
                                                <a href="./taskmanage/edit-comment.php?id=<?= (int)$c['id'] ?>" class="btn btn-outline">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="post" action="task-comments.php?<?= qs_keep() ?>" class="inline delete-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
                                                    <button type="button" class="btn btn-outline delete-btn">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
                            <div>Menampilkan <strong><?= count($comments) ?></strong> dari <strong><?= $total ?></strong> komentar</div>
                            <div class="flex flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="task-comments.php?<?= qs_keep(['page' => $p]) ?>" class="page <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
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

        // Toggle add panel
        (function() {
            const toggleBtn = document.getElementById('toggleAdd');
            const addPanel = document.getElementById('addPanel');
            const cancelAdd = document.getElementById('cancelAdd');
            if (toggleBtn && addPanel) toggleBtn.addEventListener('click', () => addPanel.classList.toggle('hidden'));
            if (cancelAdd && addPanel) cancelAdd.addEventListener('click', () => addPanel.classList.add('hidden'));
        })();

        // Delete confirm bar
        (function() {
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
        })();

        // Assignee autoload on task change
        (function() {
            const taskSelect = document.getElementById('taskSelect');
            const assigneeView = document.getElementById('assigneeView');
            const assigneeId = document.getElementById('assigneeId');
            const assigneeHelp = document.getElementById('assigneeHelp');
            const saveBtn = document.getElementById('saveBtn');

            function setAssigneeUI(ok, userId, name, message) {
                assigneeView.innerHTML = '';
                if (ok) {
                    const opt = document.createElement('option');
                    opt.value = userId;
                    opt.textContent = name || '—';
                    assigneeView.appendChild(opt);
                    assigneeId.value = userId;
                    assigneeHelp.classList.add('hidden');
                    saveBtn.disabled = false;
                    saveBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                } else {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = message || '— tidak ada assignee —';
                    assigneeView.appendChild(opt);
                    assigneeId.value = '';
                    assigneeHelp.textContent = message || 'Tugas ini belum memiliki assignee. Tambahkan assignee pada halaman tugas.';
                    assigneeHelp.classList.remove('hidden');
                    saveBtn.disabled = true;
                    saveBtn.classList.add('opacity-60', 'cursor-not-allowed');
                }
            }
            async function fetchAssignee(taskId) {
                if (!taskId) {
                    setAssigneeUI(false, null, null, '— pilih tugas dulu —');
                    return;
                }
                try {
                    const res = await fetch(`task-comments.php?ajax=assignee&task_id=${encodeURIComponent(taskId)}`, {
                        cache: 'no-store'
                    });
                    const data = await res.json();
                    if (data.ok) setAssigneeUI(true, data.user_id, data.name, null);
                    else setAssigneeUI(false, null, null, data.message);
                } catch (e) {
                    setAssigneeUI(false, null, null, 'Gagal memuat assignee.');
                }
            }
            if (taskSelect) taskSelect.addEventListener('change', () => fetchAssignee(taskSelect.value));
        })();
    </script>
</body>

</html>