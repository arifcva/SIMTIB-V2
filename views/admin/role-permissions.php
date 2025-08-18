<?php
include '../../config/database.php';
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

/* ========== Utils ========== */
// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
function check_csrf()
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}
// Flash
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
// bind_param variadik (by-ref)
function refValues(array &$arr)
{
    $refs = [];
    foreach ($arr as $k => &$v) $refs[$k] = &$v;
    return $refs;
}
// validasi user id untuk audit_logs
function valid_user_id(mysqli $conn, ?int $uid): int
{
    if (!$uid || $uid <= 0) return 0;
    if ($rs = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1")) {
        $rs->bind_param("i", $uid);
        $rs->execute();
        $rs->store_result();
        $ok = ($rs->num_rows === 1);
        $rs->close();
        return $ok ? $uid : 0;
    }
    return 0;
}

/* ========== Handle Delete (STAFF only) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    check_csrf();
    $uid = (int)$_POST['delete_id'];

    // Cegah hapus diri sendiri (opsional)
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $uid) {
        set_flash('error', 'Tidak dapat menghapus akun Anda sendiri.');
        header('Location: role-permissions.php');
        exit;
    }

    // Pastikan target adalah STAFF
    $targetRole = null;
    if ($stmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1")) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $targetRole = $row['role'];
        $stmt->close();
    }
    if ($targetRole === null) {
        set_flash('error', 'Pengguna tidak ditemukan.');
        header('Location: role-permissions.php');
        exit;
    }
    if ($targetRole !== 'staff') {
        set_flash('error', 'Hanya pengguna dengan peran STAFF yang dapat dihapus.');
        header('Location: role-permissions.php');
        exit;
    }

    // Hapus user STAFF
    if ($stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role='staff'")) {
        $stmt->bind_param("i", $uid);
        $ok = $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($ok && $deleted > 0) {
            // Audit log (aman FK)
            $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
            if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('delete','users',?,?,NOW())"))) {
                $al->bind_param("ii", $uid, $actor);
                $al->execute();
                $al->close();
            }
            set_flash('success', 'Staf berhasil dihapus.');
        } else {
            set_flash('error', 'Gagal menghapus staf.');
        }
    } else {
        set_flash('error', 'Gagal menyiapkan query hapus.');
    }
    header('Location: role-permissions.php');
    exit;
}

/* ========== Query params ========== */
$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

/* ========== Build WHERE (STAFF only) ========== */
$where = ["u.role = 'staff'"];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $kw = "%$q%";
    $types .= 'ss';
    $params[] = $kw;
    $params[] = $kw;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* ========== Count total ========== */
$total = 0;
$sqlCount = "SELECT COUNT(*) c FROM users u $whereSql";
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

/* ========== Fetch data (STAFF only) ========== */
$sql = "SELECT u.id, u.name, u.email, u.role, u.created_at
        FROM users u
        $whereSql
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";
$users = [];
if ($stmt = $conn->prepare($sql)) {
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    $bp = array_merge([$types2], refValues($params2));
    $stmt->bind_param(...$bp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $users[] = $row;
    $stmt->close();
}

/* ========== Helpers ========== */
function role_color($r)
{
    return 'text-gray-900 bg-gray-100 border border-gray-200';
} // monokrom badge
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <!-- Monokrom + Responsif Utilities -->
    <style>
        :root {
            --ink: #111827;
            /* teks */
            --paper: #ffffff;
            /* latar */
            --muted: #6b7280;
            /* teks sekunder */
            --line: #e5e7eb;
            /* border */
            --ink-strong: #000;
            /* tombol utama */
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
            white-space: nowrap;
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

        .btn-warn {
            background: #fff;
            color: #111;
            border: 1px solid #111;
        }

        .btn-warn:hover {
            background: #111;
            color: #fff;
        }

        .badge {
            font-size: .75rem;
            padding: .125rem .5rem;
            border-radius: .375rem;
            display: inline-block;
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

        /* Drawer (sidebar) mobile */
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

        @media (min-width: 1024px) {

            /* lg+ : sidebar statis */
            .drawer {
                display: none;
            }
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar (desktop) -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="role-permissions.php" class="slink active"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href="../../controllers/logout.php" class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
        </aside>

        <!-- Drawer (sidebar) mobile -->
        <div id="drawer" class="drawer lg:hidden">
            <div class="drawer-backdrop" id="drawerBackdrop"></div>
            <div class="drawer-panel p-4 overflow-y-auto">
                <h2 class="flex justify-between items-center text-xl sidebar-title mb-6">
                    <span>SIMTIB</span>
                    <button id="drawerClose" class="btn btn-outline px-2 py-1"><i class="fas fa-times"></i></button>
                </h2>
                <ul class="space-y-1">
                    <li><a href="dashboard.php" class="slink">Dashboard</a></li>
                    <li><a href="tasks.php" class="slink">Manajemen Tugas</a></li>
                    <li><a href="user-activity.php" class="slink">Aktivitas Pengguna</a></li>
                    <li><a href="notifications.php" class="slink">Notifikasi</a></li>
                    <li><a href="role-permissions.php" class="slink active">Kontrol Akses</a></li>
                    <li><a href="task-comments.php" class="slink">Komentar Tugas</a></li>
                    <li><a href="audit.php" class="slink">Audit</a></li>
                    <li><a href="profile.php" class="slink">Profil</a></li>
                </ul>
                <a href="../../controllers/logout.php" class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
            </div>
        </div>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <!-- Hamburger (mobile) -->
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="text-base lg:text-lg font-bold">Kontrol Akses Staf</div>
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

                <!-- Konfirmasi Hapus -->
                <div id="confirmBar" class="hidden mb-4 p-4 card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div class="flex items-start md:items-center gap-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Yakin ingin menghapus staf ini? Tindakan ini tidak bisa dibatalkan.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="confirmNo" class="btn btn-outline">Tidak</button>
                            <button id="confirmYes" class="btn btn-warn">Ya, Hapus</button>
                        </div>
                    </div>
                </div>

                <!-- Actions + Search -->
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-6">
                    <div>
                        <button class="btn btn-black" onclick="location.href='./rolemanage/add-user.php'">
                            <i class="fas fa-user-plus mr-2"></i> Tambah Pengguna (Staf)
                        </button>
                    </div>

                    <form class="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:flex-row items-stretch lg:items-center gap-3"
                        method="get" action="role-permissions.php">
                        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari staf (nama/email)" class="field">
                        <div class="flex gap-2">
                            <button class="btn btn-black"><i class="fas fa-search mr-2"></i> Cari</button>
                            <?php if ($q !== ''): ?>
                                <a href="role-permissions.php" class="btn btn-outline">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="card p-0 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="th">No</th>
                                <th class="th">Nama</th>
                                <th class="th">Email</th>
                                <th class="th">Peran</th>
                                <th class="th">Dibuat</th>
                                <th class="th">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="6" class="td py-6 text-center text-gray-600">Tidak ada staf.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = $offset + 1;
                                foreach ($users as $u): ?>
                                    <tr class="border-t border-gray-200">
                                        <td class="td"><?= $no++ ?></td>
                                        <td class="td font-medium"><?= htmlspecialchars($u['name']) ?></td>
                                        <td class="td"><?= htmlspecialchars($u['email']) ?></td>
                                        <td class="td">
                                            <span class="badge <?= role_color($u['role']) ?>">
                                                <?= htmlspecialchars(ucfirst($u['role'])) ?>
                                            </span>
                                        </td>
                                        <td class="td"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($u['created_at']))) ?></td>
                                        <td class="td">
                                            <div class="flex flex-wrap gap-2">
                                                <a href="./rolemanage/edit-user.php?id=<?= (int)$u['id'] ?>" class="btn btn-outline">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="post" action="role-permissions.php?<?= qs_keep() ?>" class="inline delete-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
                                                    <button type="button" class="btn btn-warn delete-btn">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-sm">
                            <div>Menampilkan <strong><?= count($users) ?></strong> dari <strong><?= $total ?></strong> staf</div>
                            <div class="flex flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="role-permissions.php?<?= qs_keep(['page' => $p]) ?>"
                                        class="page <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Drawer logic
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
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        })();

        // Confirm delete bar
        document.addEventListener("DOMContentLoaded", function() {
            const bar = document.getElementById("confirmBar");
            const yes = document.getElementById("confirmYes");
            const no = document.getElementById("confirmNo");
            let currentForm = null;

            document.querySelectorAll(".delete-btn").forEach(btn => {
                btn.addEventListener("click", function() {
                    currentForm = this.closest("form");
                    bar.classList.remove("hidden");
                    bar.scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });
                });
            });
            if (no) {
                no.addEventListener("click", function() {
                    bar.classList.add("hidden");
                    currentForm = null;
                });
            }
            if (yes) {
                yes.addEventListener("click", function() {
                    if (currentForm) currentForm.submit();
                });
            }
        });
    </script>
</body>

</html>