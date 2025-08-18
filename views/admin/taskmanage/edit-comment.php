<?php
// views/admin/taskmanage/edit-comment.php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../../config/database.php'; // $conn = new mysqli(...)

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

/* ========== Ambil ID komentar ========== */
$cid = (int)($_GET['id'] ?? 0);
if ($cid <= 0) {
    set_flash('error', 'Parameter tidak valid.');
    header('Location: ../task-comments.php');
    exit;
}

/* ========== Dropdown data: tasks, staff ========== */
$tasks_dd = [];
if ($rs = $conn->query("SELECT id, title FROM tasks ORDER BY created_at DESC LIMIT 200")) {
    while ($r = $rs->fetch_assoc()) $tasks_dd[] = $r;
    $rs->close();
}
$staff_dd = [];
if ($st = $conn->prepare("SELECT id, name FROM users WHERE role='staff' ORDER BY name ASC")) {
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $staff_dd[] = $r;
    $st->close();
}

/* ========== Ambil komentar yang akan diedit ========== */
$comment = null;
if ($st = $conn->prepare(
    "SELECT c.id, c.task_id, c.user_id, c.comment_text, c.created_at,
            t.title AS task_title, u.name AS user_name
     FROM comments c
     JOIN tasks t ON c.task_id = t.id
     JOIN users u ON c.user_id = u.id
     WHERE c.id=? LIMIT 1"
)) {
    $st->bind_param("i", $cid);
    $st->execute();
    $comment = $st->get_result()->fetch_assoc();
    $st->close();
}
if (!$comment) {
    set_flash('error', 'Komentar tidak ditemukan.');
    header('Location: ../task-comments.php');
    exit;
}

/* ========== Handle POST update ========== */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    check_csrf();

    $task_id = (int)($_POST['task_id'] ?? 0);
    $user_id = (int)($_POST['user_id'] ?? 0);
    $text    = trim($_POST['comment'] ?? '');

    if ($task_id <= 0) $errors[] = 'Tugas wajib dipilih.';
    if ($user_id <= 0) $errors[] = 'Pengguna (staf) wajib dipilih.';
    if ($text === '')  $errors[] = 'Komentar tidak boleh kosong.';

    // validasi task
    if (!$errors && ($st = $conn->prepare("SELECT id FROM tasks WHERE id=? LIMIT 1"))) {
        $st->bind_param("i", $task_id);
        $st->execute();
        $st->store_result();
        if ($st->num_rows !== 1) $errors[] = 'Tugas tidak ditemukan.';
        $st->close();
    }
    // validasi staff
    if (!$errors && ($st = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1"))) {
        $st->bind_param("i", $user_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        if (!$row || $row['role'] !== 'staff') $errors[] = 'Hanya pengguna dengan peran STAFF yang valid.';
    }

    if (!$errors) {
        if ($up = $conn->prepare("UPDATE comments SET task_id=?, user_id=?, comment_text=? WHERE id=?")) {
            $up->bind_param("iisi", $task_id, $user_id, $text, $cid);
            $ok = $up->execute();
            $up->close();

            if ($ok) {
                // audit log (opsional)
                $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
                if ($actor > 0 && ($al = $conn->prepare(
                    "INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at)
                     VALUES ('update','comments',?,?,NOW())"
                ))) {
                    $al->bind_param("ii", $cid, $actor);
                    $al->execute();
                    $al->close();
                }

                set_flash('success', 'Komentar berhasil diperbarui.');
                header('Location: ../task-comments.php');
                exit;
            } else {
                $errors[] = 'Gagal memperbarui komentar.';
            }
        } else {
            $errors[] = 'Gagal menyiapkan query update.';
        }
    }
}

/* ========== Prefill nilai form ========== */
$val_task_id = (int)($_POST['task_id'] ?? $comment['task_id']);
$val_user_id = (int)($_POST['user_id'] ?? $comment['user_id']);
$val_text    = ($_POST['comment'] ?? $comment['comment_text']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>SIMTIB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="../../../public/assets/img/bawaslu.png" type="image/svg+xml">
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

        /* Kartu & form */
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

        /* sembunyikan drawer di lg+ */
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar desktop -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl sidebar-title mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="../dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="../tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="../user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="../notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="../role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="../task-comments.php" class="slink active"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                <li><a href="../audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="../profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href='../../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
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
                    <li><a href="../dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                    <li><a href="../tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="../user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li><a href="../notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="../role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                    <li><a href="../task-comments.php" class="slink active"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
                    <li><a href="../audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                    <li><a href="../profile.php" class="slink"><i class="fas fa-user mr-3"></i>Profil</a></li>
                </ul>
                <a href='../../../controllers/logout.php' class="mt-6 w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>
        </div>

        <!-- Main column -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <div class="card p-3 flex items-center justify-between lg:rounded-none lg:border-0">
                <div class="flex items-center gap-3">
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Edit Komentar</div>
                </div>
                <a class="btn btn-outline" href='../../../controllers/logout.php'><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <!-- Flash / Errors -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="mb-4 p-3 rounded border border-red-200 bg-white text-red-700">
                        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <div class="card p-6">
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="update">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm mb-1">Tugas</label>
                                <select name="task_id" class="field" required>
                                    <option value="">Pilih tugas…</option>
                                    <?php foreach ($tasks_dd as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= $val_task_id == (int)$t['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm mb-1">Pengguna (Staf)</label>
                                <select name="user_id" class="field" required>
                                    <option value="">Pilih staf…</option>
                                    <?php foreach ($staff_dd as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= $val_user_id == (int)$s['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm mb-1">Komentar</label>
                                <textarea name="comment" rows="5" class="field" required><?= htmlspecialchars($val_text) ?></textarea>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-between">
                            <a href="../task-comments.php" class="btn btn-outline">Kembali</a>
                            <button type="submit" class="btn btn-black inline-flex items-center">
                                <i class="fas fa-save mr-2"></i> Simpan Perubahan
                            </button>
                        </div>
                    </form>
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