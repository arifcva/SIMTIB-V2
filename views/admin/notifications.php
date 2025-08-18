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

/* ========== Deteksi skema notifications ========== */
$dbname = '';
$resDB = $conn->query("SELECT DATABASE() db");
if ($resDB && ($r = $resDB->fetch_assoc())) $dbname = $r['db'];
if ($resDB) $resDB->close();

$cols = [];
if ($dbname) {
    if ($st = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='notifications'")) {
        $st->bind_param("s", $dbname);
        $st->execute();
        $rs = $st->get_result();
        while ($row = $rs->fetch_assoc()) $cols[] = strtolower($row['COLUMN_NAME']);
        $st->close();
    }
}
$has  = fn($name) => in_array(strtolower($name), $cols, true);
$pick = function (array $cands) use ($has) {
    foreach ($cands as $c) if ($has($c)) return $c;
    return null;
};

$idCol        = $pick(['id', 'notification_id']) ?? 'id';
$titleCol     = $pick(['title', 'subject', 'judul']);
$messageCol   = $pick(['message', 'content', 'body', 'text', 'description', 'notification']);
$typeCol      = $pick(['type', 'level', 'severity']);
$isReadCol    = $pick(['is_read', 'read', 'status_read']);
$createdAtCol = $pick(['created_at', 'createdon', 'created_on', 'timestamp', 'created_time', 'date']);
if (!$messageCol) $messageCol = 'message';

/* ========== Handle POST actions ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!$isReadCol) {
        set_flash('error', 'Kolom status baca tidak tersedia pada tabel notifikasi.');
        header('Location: notifications.php?' . qs_keep());
        exit;
    }
    check_csrf();
    $nid = (int)($_POST['id'] ?? 0);
    $cur = (int)($_POST['is_read'] ?? 0);
    if ($nid > 0) {
        $sql = "UPDATE notifications SET `$isReadCol`=? WHERE `$idCol`=?";
        if ($st = $conn->prepare($sql)) {
            $new = $cur ? 0 : 1;
            $st->bind_param("ii", $new, $nid);
            $ok = $st->execute();
            $st->close();
            set_flash($ok ? 'success' : 'error', $ok ? 'Status notifikasi diperbarui.' : 'Gagal memperbarui status.');
        } else set_flash('error', 'Gagal menyiapkan query.');
    }
    header('Location: notifications.php?' . qs_keep());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    check_csrf();
    $nid = (int)$_POST['delete_id'];
    if ($nid > 0) {
        $sql = "DELETE FROM notifications WHERE `$idCol`=?";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param("i", $nid);
            $ok = $st->execute();
            $deleted = $st->affected_rows;
            $st->close();

            if ($ok && $deleted > 0) {
                $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
                if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('delete','notifications',?,?,NOW())"))) {
                    $al->bind_param("ii", $nid, $actor);
                    $al->execute();
                    $al->close();
                }
                set_flash('success', 'Notifikasi berhasil dihapus.');
            } else set_flash('error', 'Gagal menghapus notifikasi.');
        } else set_flash('error', 'Gagal menyiapkan query hapus.');
    }
    header('Location: notifications.php?' . qs_keep());
    exit;
}

/* ========== Filters, search, pagination ========== */
$search = trim($_GET['q'] ?? '');
$only   = trim($_GET['only'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

$where  = [];
$types  = '';
$params = [];
if ($search !== '') {
    if ($titleCol) {
        $where[] = "(`$titleCol` LIKE ? OR `$messageCol` LIKE ?)";
        $kw = "%$search%";
        $types .= 'ss';
        $params[] = $kw;
        $params[] = $kw;
    } else {
        $where[] = "(`$messageCol` LIKE ?)";
        $kw = "%$search%";
        $types .= 's';
        $params[] = $kw;
    }
}
if ($isReadCol && $only === 'read')   $where[] = "`$isReadCol`=1";
if ($isReadCol && $only === 'unread') $where[] = "`$isReadCol`=0";
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ========== Count ========== */
$total = 0;
$sqlCount = "SELECT COUNT(*) c FROM notifications $whereSql";
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

/* ========== List ========== */
$idSel      = "`$idCol` AS id";
$titleSel   = $titleCol     ? "`$titleCol` AS title"      : "'' AS title";
$messageSel = $messageCol   ? "`$messageCol` AS message"  : "'' AS message";
$typeSel    = $typeCol      ? "`$typeCol` AS type"        : "'info' AS type";
$isReadSel  = $isReadCol    ? "`$isReadCol` AS is_read"   : "0 AS is_read";
$createdSel = $createdAtCol ? "`$createdAtCol` AS created_at" : "NOW() AS created_at";

$sqlList = "SELECT $idSel, $titleSel, $messageSel, $typeSel, $isReadSel, $createdSel
            FROM notifications
            $whereSql
            ORDER BY created_at DESC
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

/* ========== Helpers UI (monokrom) ========== */
function type_icon($t)
{
    return ['fa-info-circle', 'text-gray-700'];
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
            --paper: #ffffff;
            --muted: #6b7280;
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
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        /* Sidebar base */
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

        /* Inputs / buttons */
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

        .btn-black {
            background: var(--ink-strong);
            color: #fff;
            border: 1px solid var(--ink-strong);
        }

        .btn-black:hover {
            background: #0a0a0a;
            border-color: #0a0a0a;
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

        .badge {
            font-size: .75rem;
            padding: .125rem .5rem;
            border-radius: .375rem;
            border: 1px solid var(--line);
            color: #111;
            background: #f9fafb;
        }

        thead tr {
            background: #f3f4f6;
        }

        .th,
        .td {
            padding: .625rem 1rem;
        }

        .page {
            padding: .25rem .75rem;
            border-radius: .375rem;
            border: 1px solid #d1d5db;
        }

        .page:hover {
            background: #f9fafb;
        }

        .page.active {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        /* Drawer (mobile sidebar) */
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
                display: none;
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
                <li><a href="notifications.php" class="slink active"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
        </aside>

        <!-- Drawer mobile -->
        <!-- Drawer mobile -->
        <div id="drawer" class="drawer lg:hidden">
            <div class="drawer-backdrop" id="drawerBackdrop"></div>
            <div class="drawer-panel p-4 overflow-y-auto">
                <h2 class="flex justify-between items-center text-xl sidebar-title mb-6">
                    <span>SIMTIB</span>
                    <button id="drawerClose" class="btn btn-outline px-2 py-1">
                        <i class="fas fa-times"></i>
                    </button>
                </h2>

                <ul class="space-y-1">
                    <li>
                        <a href="dashboard.php" class="slink">
                            <i class="fas fa-tachometer-alt mr-3"></i>Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="tasks.php" class="slink">
                            <i class="fas fa-tasks mr-3"></i>Manajemen Tugas
                        </a>
                    </li>
                    <li>
                        <a href="user-activity.php" class="slink">
                            <i class="fas fa-users mr-3"></i>Aktivitas Pengguna
                        </a>
                    </li>
                    <li>
                        <a href="notifications.php" class="slink active">
                            <i class="fas fa-bell mr-3"></i>Notifikasi
                        </a>
                    </li>
                    <li>
                        <a href="role-permissions.php" class="slink">
                            <i class="fas fa-user-cog mr-3"></i>Kontrol Akses
                        </a>
                    </li>
                    <li>
                        <a href="task-comments.php" class="slink">
                            <i class="fas fa-comment-alt mr-3"></i>Komentar Tugas
                        </a>
                    </li>
                    <li>
                        <a href="audit.php" class="slink">
                            <i class="fas fa-clipboard-list mr-3"></i>Audit
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="slink">
                            <i class="fas fa-user mr-3"></i>Profil
                        </a>
                    </li>
                </ul>

                <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </div>
        </div>


        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="text-base lg:text-lg font-bold">Notifikasi</div>
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

                <!-- Confirm bar -->
                <div id="confirmBar" class="hidden mb-4 p-4 card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex items-start md:items-center gap-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Yakin ingin menghapus notifikasi ini? Tindakan ini tidak bisa dibatalkan.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="confirmNo" class="btn btn-outline">Tidak</button>
                            <button id="confirmYes" class="btn btn-black">Ya, Hapus</button>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="card p-4 mb-4">
                    <form class="grid grid-cols-1 md:grid-cols-3 gap-3 items-stretch md:items-end" method="get" action="notifications.php">
                        <div class="md:col-span-2">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari judul / isi notifikasi…" class="field">
                        </div>
                        <div class="flex gap-2">
                            <select name="only" class="field" <?= $isReadCol ? '' : 'disabled' ?>>
                                <option value="">Semua</option>
                                <option value="unread" <?= $only === 'unread' ? 'selected' : ''; ?>>Belum dibaca</option>
                                <option value="read" <?= $only === 'read'   ? 'selected' : ''; ?>>Sudah dibaca</option>
                            </select>
                            <button class="btn btn-black w-full md:w-auto"><i class="fas fa-search mr-2"></i> Cari</button>
                            <?php if ($search !== '' || $only !== ''): ?>
                                <a href="notifications.php" class="btn btn-outline w-full md:w-auto">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- List -->
                <div class="card p-0 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="th">No</th>
                                <th class="th">Notifikasi</th>
                                <th class="th">Status</th>
                                <th class="th">Waktu</th>
                                <th class="th">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="5" class="td py-6 text-center text-gray-600">Tidak ada notifikasi.</td>
                                </tr>
                                <?php else: $no = $offset + 1;
                                foreach ($rows as $n):
                                    [$icon, $cls] = type_icon($n['type'] ?? 'info');
                                    $isRead = (int)($n['is_read'] ?? 0) === 1;
                                    $title  = trim($n['title'] ?? '');
                                    $msg    = $n['message'] ?? '';
                                ?>
                                    <tr class="border-t border-gray-200">
                                        <td class="td"><?= $no++ ?></td>
                                        <td class="td">
                                            <div class="flex items-start gap-2">
                                                <i class="fas <?= $icon ?> text-gray-700 mt-1"></i>
                                                <div>
                                                    <?php if ($title !== ''): ?>
                                                        <div class="font-medium text-gray-900"><?= htmlspecialchars($title) ?></div>
                                                    <?php endif; ?>
                                                    <div class="text-gray-700"><?= nl2br(htmlspecialchars($msg)) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="td">
                                            <?php if ($isReadCol): ?>
                                                <span class="badge"><?= $isRead ? 'Sudah dibaca' : 'Belum dibaca' ?></span>
                                            <?php else: ?>
                                                <span class="badge">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td">
                                            <?php $t = $n['created_at'] ?? date('Y-m-d H:i:s');
                                            echo htmlspecialchars(date('Y-m-d H:i', strtotime($t))); ?>
                                        </td>
                                        <td class="td">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if ($isReadCol): ?>
                                                    <form method="post" action="notifications.php?<?= qs_keep() ?>" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                                        <input type="hidden" name="is_read" value="<?= (int)$n['is_read'] ?>">
                                                        <button class="btn btn-black">
                                                            <i class="fas <?= $isRead ? 'fa-envelope-open' : 'fa-envelope' ?> mr-2"></i>
                                                            <?= $isRead ? 'Tandai belum dibaca' : 'Tandai sudah dibaca' ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="notifications.php?<?= qs_keep() ?>" class="inline delete-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$n['id'] ?>">
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
                            <div>Menampilkan <strong><?= count($rows) ?></strong> dari <strong><?= $total ?></strong> notifikasi</div>
                            <div class="flex flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="notifications.php?<?= qs_keep(['page' => $p]) ?>" class="page <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Drawer (mobile) controls
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

        // Delete confirm bar
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