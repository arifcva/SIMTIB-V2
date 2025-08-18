<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../../../config/database.php'; // harus mendefinisikan $conn = new mysqli(...)

/* ========= Utils ========= */
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
// Validasi user id aman (untuk audit FK)
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

/* ========= Ambil daftar staf ========= */
$users = [];
if ($st = $conn->prepare("SELECT id, name FROM users WHERE role='staff' ORDER BY name")) {
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $users[] = $r;
    $st->close();
}

/* ========= Handle POST ========= */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $title       = trim($_POST['task_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status      = strtolower(trim($_POST['status'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;

    if ($title === '')        $errors[] = 'Nama tugas wajib diisi.';
    if ($description === '')  $errors[] = 'Deskripsi wajib diisi.';
    $allowedStatuses = ['pending', 'in progress', 'completed'];
    if (!in_array($status, $allowedStatuses, true)) $errors[] = 'Status tidak valid.';

    // Pastikan assignee adalah staf bila diisi
    if ($assigned_to !== null) {
        if ($st = $conn->prepare("SELECT COUNT(*) c FROM users WHERE id=? AND role='staff'")) {
            $st->bind_param("i", $assigned_to);
            $st->execute();
            $c = $st->get_result()->fetch_assoc();
            if ((int)$c['c'] === 0) $errors[] = 'Assignee harus staf yang valid.';
            $st->close();
        }
    }

    if (!$errors) {
        // Insert task
        if ($st = $conn->prepare("INSERT INTO tasks (title, description, status, assigned_to, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")) {
            // untuk NULL assigned_to, kita tetap bind dengan tipe i; mysqli akan mengirim NULL jika variabelnya = null
            $st->bind_param("sssi", $title, $description, $status, $assigned_to);
            $ok = $st->execute();
            $taskId = $st->insert_id;
            $st->close();

            if ($ok) {
                // task_history
                $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
                if ($actor > 0 && ($th = $conn->prepare("INSERT INTO task_history (task_id, `change`, changed_by, changed_at) VALUES (?,?,?,NOW())"))) {
                    $chg = 'created';
                    $th->bind_param("isi", $taskId, $chg, $actor);
                    $th->execute();
                    $th->close();
                } else if ($actor === 0 && ($th = $conn->prepare("INSERT INTO task_history (task_id, `change`, changed_by, changed_at) VALUES (?,? ,NULL,NOW())"))) {
                    $chg = 'created';
                    $th->bind_param("is", $taskId, $chg);
                    $th->execute();
                    $th->close();
                }

                // audit_logs
                if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('create','tasks',?,?,NOW())"))) {
                    $al->bind_param("ii", $taskId, $actor);
                    $al->execute();
                    $al->close();
                } else if ($actor === 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('create','tasks',?,NULL,NOW())"))) {
                    $al->bind_param("i", $taskId);
                    $al->execute();
                    $al->close();
                }

                // notifikasi ke staf yang ditugaskan
                if ($assigned_to !== null) {
                    if ($nn = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?,?,NOW())")) {
                        $msg = "Anda mendapat tugas baru: " . $title;
                        $nn->bind_param("is", $assigned_to, $msg);
                        $nn->execute();
                        $nn->close();
                    }
                }

                set_flash('success', 'Tugas berhasil ditambahkan.');
                header("Location: ../tasks.php");
                exit;
            } else {
                $errors[] = "Gagal menambahkan tugas.";
            }
        } else {
            $errors[] = "Gagal menyiapkan query.";
        }
    }
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
            /* teks utama */
            --paper: #fff;
            /* latar */
            --muted: #6b7280;
            /* teks sekunder */
            --line: #e5e7eb;
            /* border */
            --ink-strong: #000;
            /* hitam murni utk tombol/aktif */
        }

        body {
            background: var(--paper);
            color: var(--ink);
        }

        /* KOMPONEN (konsisten dengan referensi) */
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

        label {
            font-size: .875rem;
            color: #111827;
            margin-bottom: .25rem;
            display: block
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
                <li><a href="../dashboard.php" class="slink"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="../tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="../user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="../notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="../role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                <li><a href="../task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
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
                    <li><a href="../tasks.php" class="slink active"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="../user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li><a href="../notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="../role-permissions.php" class="slink"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
                    <li><a href="../task-comments.php" class="slink"><i class="fas fa-comment-alt mr-3"></i>Komentar Tugas</a></li>
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
                    <div class="text-base lg:text-lg font-bold">Tambah Tugas</div>
                </div>
                <a href="../../../controllers/logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <!-- Alerts -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black">
                        <?php foreach ($errors as $e): ?>
                            <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <div class="card p-6 rounded">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="task-name">Nama Tugas</label>
                                <input type="text" id="task-name" name="task_name" class="field" required>
                            </div>

                            <div class="md:col-span-2">
                                <label for="description">Deskripsi</label>
                                <textarea id="description" name="description" rows="4" class="field" required></textarea>
                            </div>

                            <div>
                                <label for="status">Status</label>
                                <select id="status" name="status" class="field" required>
                                    <option value="pending">Belum Dikerjakan</option>
                                    <option value="in progress">Sedang Dikerjakan</option>
                                    <option value="completed">Selesai</option>
                                </select>
                            </div>

                            <div>
                                <label for="assigned_to">Ditugaskan ke (Staf)</label>
                                <select id="assigned_to" name="assigned_to" class="field">
                                    <option value="">— Tidak ditugaskan —</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col sm:flex-row gap-3 sm:justify-between">
                            <button type="submit" class="btn btn-black">
                                <i class="fas fa-save mr-2"></i> Simpan
                            </button>
                            <a href="../tasks.php" class="btn btn-outline">Kembali</a>
                        </div>
                    </form>
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
    </script>
</body>

</html>