<?php
include '../../../config/database.php';
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

/* ========== Utils ========== */
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

/* ========== Handle POST ========== */
$name = '';
$email = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name  = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $cpass = $_POST['password_confirm'] ?? '';
    $role  = 'staff'; // dikunci

    // Validasi
    if ($name === '')  $errors[] = 'Nama wajib diisi.';
    if ($email === '') $errors[] = 'Email wajib diisi.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if (strlen($pass) < 8) $errors[] = 'Password minimal 8 karakter.';
    if ($pass !== $cpass)  $errors[] = 'Konfirmasi password tidak cocok.';

    // Email unik
    if (!$errors) {
        if ($st = $conn->prepare("SELECT COUNT(*) c FROM users WHERE email=?")) {
            $st->bind_param("s", $email);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            if ((int)$r['c'] > 0) $errors[] = 'Email sudah terdaftar.';
            $st->close();
        } else {
            $errors[] = 'Gagal menyiapkan query cek email.';
        }
    }

    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        if ($ins = $conn->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?,?,?,?,NOW())")) {
            $ins->bind_param("ssss", $name, $email, $hash, $role);
            $ok = $ins->execute();
            $newId = $ins->insert_id;
            $ins->close();

            if ($ok) {
                // Audit log (opsional, aman FK)
                $actor = valid_user_id($conn, $_SESSION['user_id'] ?? null);
                if ($actor > 0 && ($al = $conn->prepare("INSERT INTO audit_logs (action, affected_table, affected_id, user_id, created_at) VALUES ('create','users',?,?,NOW())"))) {
                    $al->bind_param("ii", $newId, $actor);
                    $al->execute();
                    $al->close();
                }

                set_flash('success', 'Staf baru berhasil ditambahkan.');
                header('Location: ../role-permissions.php');
                exit;
            } else {
                $errors[] = 'Gagal menambahkan pengguna.';
            }
        } else {
            $errors[] = 'Gagal menyiapkan query insert.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>SIMTIB</title>
    <link rel="icon" href="../../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
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
            /* hitam murni */
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

        /* Drawer (mobile sidebar) */
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

        /* Breadcrumb / title wrapper mengikuti referensi */
        .topbar-title {
            font-weight: 700
        }
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
                <li><a href="../role-permissions.php" class="slink active"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
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
                    <li><a href="../tasks.php" class="slink"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                    <li><a href="../user-activity.php" class="slink"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                    <li><a href="../notifications.php" class="slink"><i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                    <li><a href="../role-permissions.php" class="slink active"><i class="fas fa-user-cog mr-3"></i>Kontrol Akses</a></li>
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
                    <div class="topbar-title text-base lg:text-lg">Tambah Pengguna (Staf)</div>
                </div>
                <a class="btn btn-outline" href='../../../controllers/logout.php'><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1">
                <!-- Flash -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black"><?= $msg ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="mb-4 p-3 rounded border border-gray-300 bg-white text-black">
                        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <div class="card p-6">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label>Nama Pengguna</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($name) ?>" class="field" required>
                            </div>
                            <div>
                                <label>Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" class="field" required>
                            </div>
                            <div>
                                <label>Password</label>
                                <input type="password" name="password" minlength="8" placeholder="Minimal 8 karakter" class="field" required>
                            </div>
                            <div>
                                <label>Konfirmasi Password</label>
                                <input type="password" name="password_confirm" minlength="8" class="field" required>
                            </div>
                        </div>

                        <div class="mt-4">
                            <span class="text-sm text-gray-700">Peran</span>
                            <div class="mt-1 inline-flex items-center gap-2 px-3 py-1 rounded-full border border-gray-200 bg-gray-50 text-black text-sm">
                                <i class="fas fa-user-tag"></i> Staff (dikunci)
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col sm:flex-row gap-3 sm:justify-between">
                            <a href="../role-permissions.php" class="btn btn-outline">Kembali</a>
                            <button type="submit" class="btn btn-black">
                                <i class="fas fa-user-plus mr-2"></i> Tambah Pengguna
                            </button>
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