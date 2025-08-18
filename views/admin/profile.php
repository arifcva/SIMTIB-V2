<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../../config/database.php'; // $conn = new mysqli(...)

/* ===== CSRF & Flash ===== */
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

/* ===== Helpers kolom dinamis ===== */
function users_has_column(mysqli $conn, string $col): bool
{
    // Works with prepared statements
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'users'
              AND COLUMN_NAME  = ?";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param("s", $col);
        $st->execute();
        $res = $st->get_result();
        $exists = (bool)$res->fetch_row();
        $st->close();
        return $exists;
    }
    return false;
}

function update_profile_photo_any(mysqli $conn, int $uid, string $path): bool
{
    // coba 'profile_photo' dulu, fallback 'avatar'
    if ($st = @$conn->prepare("UPDATE users SET profile_photo=? WHERE id=?")) {
        $st->bind_param("si", $path, $uid);
        $ok = $st->execute();
        $st->close();
        if ($ok) return true;
    }
    if ($st = @$conn->prepare("UPDATE users SET avatar=? WHERE id=?")) {
        $st->bind_param("si", $path, $uid);
        $ok = $st->execute();
        $st->close();
        if ($ok) return true;
    }
    return false;
}

/* ===== Prefill dari session + sinkron DB ===== */
$userId = (int)($_SESSION['user_id'] ?? 0);
$name   = $_SESSION['name']  ?? 'Admin';
$email  = $_SESSION['email'] ?? 'admin@example.com';
$role   = $_SESSION['role']  ?? 'admin';
$photo  = $_SESSION['profile_photo'] ?? null;

$wantProfileCol = users_has_column($conn, 'profile_photo');
$wantAvatarCol  = users_has_column($conn, 'avatar');

// Ambil data teranyar dari DB
if ($userId > 0) {
    if ($st = $conn->prepare("SELECT name,email,role FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param("i", $userId);
        if ($st->execute()) {
            $res = $st->get_result();
            if ($row = $res->fetch_assoc()) {
                $name  = $row['name']  ?? $name;
                $email = $row['email'] ?? $email;
                $role  = $row['role']  ?? $role;
            }
        }
        $st->close();
    }
    // ambil foto bila kolom ada
    if ($wantProfileCol && ($st = $conn->prepare("SELECT profile_photo FROM users WHERE id=? LIMIT 1"))) {
        $st->bind_param("i", $userId);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) $photo = $row['profile_photo'] ?? $photo;
        $st->close();
    } elseif ($wantAvatarCol && ($st = $conn->prepare("SELECT avatar FROM users WHERE id=? LIMIT 1"))) {
        $st->bind_param("i", $userId);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) $photo = $row['avatar'] ?? $photo;
        $st->close();
    }
}

/* ===== Handle POST (Update Profil + Foto) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $newName  = trim($_POST['name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPass  = $_POST['password'] ?? '';

    $errors = [];
    if ($newName === '')  $errors[] = 'Nama wajib diisi.';
    if ($newEmail === '') $errors[] = 'Email wajib diisi.';
    elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';

    if (!$errors && $userId > 0 && ($st = $conn->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1"))) {
        $st->bind_param("si", $newEmail, $userId);
        $st->execute();
        $st->store_result();
        if ($st->num_rows > 0) $errors[] = 'Email sudah digunakan pengguna lain.';
        $st->close();
    }

    /* === Validasi & proses upload foto (opsional) === */
    $savedPhotoPath = null;
    if (isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['photo'];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Gagal mengunggah foto (kode: ' . $f['error'] . ').';
        } else {
            // Batasan 2MB
            if ($f['size'] > 2 * 1024 * 1024) $errors[] = 'Ukuran foto melebihi 2 MB.';
            // Validasi MIME
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) $errors[] = 'Tipe gambar harus JPG, PNG, atau WEBP.';

            if (!$errors) {
                $ext  = $allowed[$mime];
                // folder simpan
                $baseDirFs = realpath(__DIR__ . '/../../public'); // absolute /public
                if ($baseDirFs === false) $baseDirFs = __DIR__ . '/../../public';
                $uploadDirFs = $baseDirFs . '/uploads/avatars';
                if (!is_dir($uploadDirFs)) {
                    @mkdir($uploadDirFs, 0775, true);
                }
                $newFileName = 'user_' . $userId . '_' . date('Ymd_His') . '.' . $ext;
                $destFs = $uploadDirFs . '/' . $newFileName;

                if (@move_uploaded_file($f['tmp_name'], $destFs)) {
                    // path publik relatif ke /public
                    $savedPhotoPath = 'uploads/avatars/' . $newFileName;
                } else {
                    $errors[] = 'Gagal menyimpan file ke server.';
                }
            }
        }
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        header('Location: profile.php');
        exit;
    }

    // Update nama & email
    $ok = true;
    if ($userId > 0 && ($up = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?"))) {
        $up->bind_param("ssi", $newName, $newEmail, $userId);
        $ok = $up->execute();
        $up->close();
    } else {
        $ok = false;
    }

    // Update password (opsional, dengan fallback kolom)
    if ($ok && $newPass !== '') {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $okPass = false;
        if ($st = @$conn->prepare("UPDATE users SET password=? WHERE id=?")) {
            $st->bind_param("si", $hash, $userId);
            $okPass = $st->execute();
            $st->close();
        }
        if (!$okPass && ($st = @$conn->prepare("UPDATE users SET password_hash=? WHERE id=?"))) {
            $st->bind_param("si", $hash, $userId);
            $okPass = $st->execute();
            $st->close();
        }
        if (!$okPass) {
            set_flash('error', 'Nama & email diperbarui, namun gagal memperbarui kata sandi (skema kolom password tidak cocok).');
            $_SESSION['name']  = $newName;
            $_SESSION['email'] = $newEmail;
            header('Location: profile.php');
            exit;
        }
    }

    // Update foto (opsional)
    if ($ok && $savedPhotoPath) {
        if (!update_profile_photo_any($conn, $userId, $savedPhotoPath)) {
            // Tidak fatal—beri tahu
            set_flash('error', 'Profil diperbarui, namun gagal menyimpan path foto (kolom profile_photo/avatar tidak ada).');
            $_SESSION['name']  = $newName;
            $_SESSION['email'] = $newEmail;
            header('Location: profile.php');
            exit;
        } else {
            $_SESSION['profile_photo'] = $savedPhotoPath;
        }
    }

    if ($ok) {
        $_SESSION['name']  = $newName;
        $_SESSION['email'] = $newEmail;
        set_flash('success', 'Profil berhasil diperbarui.');
    } else {
        set_flash('error', 'Gagal memperbarui profil.');
    }

    header('Location: profile.php');
    exit;
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
            --line: #e5e7eb;
            --ink-strong: #000
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

        @media(min-width:1024px) {
            .drawer {
                display: none
            }
        }
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
                <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                <li><a href="profile.php" class="slink active"><i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
            <a href='../../controllers/logout.php' class="mt-6 w-full btn btn-outline">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </a>
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
                    <li><a href="audit.php" class="slink"><i class="fas fa-clipboard-list mr-3"></i>Audit</a></li>
                    <li><a href="profile.php" class="slink active"><i class="fas fa-user mr-3"></i>Profil</a></li>
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
                    <button id="drawerOpen" class="btn btn-outline inline-flex lg:!hidden"><i class="fas fa-bars"></i></button>
                    <div class="text-base lg:text-lg font-bold">Profil</div>
                </div>
                <form action="../../controllers/usercontroller.php" method="post">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn btn-outline cursor-pointer"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <!-- Content -->
            <div class="p-4 lg:p-6 overflow-y-auto flex-1 max-w-5xl mx-auto w-full">

                <!-- Flash -->
                <?php if ($msg = get_flash('success')): ?>
                    <div class="mb-4 p-3 rounded border border-green-200 bg-white text-green-700"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <?php if ($msg = get_flash('error')): ?>
                    <div class="mb-4 p-3 rounded border border-red-200 bg-white text-red-700"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <!-- Profile Card -->
                <div class="card overflow-hidden">
                    <div class="h-20 bg-gradient-to-r from-gray-900 to-gray-400"></div>
                    <div class="p-6 md:p-8">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                            <!-- Left -->
                            <div class="md:col-span-1">
                                <div class="-mt-16 mb-4">
                                    <?php if (!empty($photo)): ?>
                                        <img src="../../public/<?= htmlspecialchars($photo) ?>" alt="Foto Profil"
                                            class="w-28 h-28 rounded-full ring-4 ring-white shadow-md object-cover mx-auto">
                                    <?php else: ?>
                                        <div class="w-28 h-28 rounded-full ring-4 ring-white shadow-md bg-indigo-100 mx-auto flex items-center justify-center">
                                            <i class="fas fa-user text-4xl text-indigo-700"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="text-center md:text-left">
                                    <div class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($name) ?></div>
                                    <div class="text-gray-600"><?= htmlspecialchars($email) ?></div>
                                    <div class="mt-2 inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-50 text-purple-700 text-xs">
                                        <i class="fas fa-user-shield"></i><?= htmlspecialchars(ucfirst($role)) ?>
                                    </div>
                                </div>

                                <div class="mt-6 space-y-2 text-sm text-gray-700">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-envelope text-gray-500"></i>
                                        <span>Email tersinkron dengan akun Anda.</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-lock text-gray-500"></i>
                                        <span>Anda dapat mengganti kata sandi kapan pun.</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Right -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Perbarui Informasi</h3>
                                <form method="post" action="profile.php" class="space-y-4" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-700 mb-1">Nama Lengkap</label>
                                            <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" class="field" required>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-700 mb-1">Email</label>
                                            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" class="field" required>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-700 mb-1">Peran</label>
                                            <input type="text" value="<?= htmlspecialchars(ucfirst($role)) ?>" class="field bg-gray-50 text-gray-600" disabled>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-700 mb-1">Kata Sandi Baru (opsional)</label>
                                            <div class="relative">
                                                <input type="password" id="password" name="password" placeholder="Biarkan kosong jika tidak diganti" class="field pr-10">
                                                <button type="button" id="togglePwd" class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-700">
                                                    <i class="far fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Foto Profil (opsional)</label>
                                        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="field bg-white">
                                        <p class="text-xs text-gray-500 mt-1">Maks 2 MB. Format: JPG/PNG/WEBP.</p>
                                    </div>

                                    <div class="pt-2 flex items-center justify-end gap-3">
                                        <a href="dashboard.php" class="btn btn-outline">Kembali</a>
                                        <button type="submit" class="btn btn-black inline-flex items-center">
                                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div><!-- /card -->
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
                document.body.style.overflow = 'hidden'
            }

            function close() {
                drawer.classList.remove('show');
                document.body.style.overflow = ''
            }
            if (openBtn) openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (backdrop) backdrop.addEventListener('click', close);
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') close()
            })
        })();

        // Toggle password
        document.addEventListener('DOMContentLoaded', () => {
            const pwd = document.getElementById('password');
            const btn = document.getElementById('togglePwd');
            if (pwd && btn) {
                btn.addEventListener('click', () => {
                    const is = pwd.getAttribute('type') === 'password';
                    pwd.setAttribute('type', is ? 'text' : 'password');
                    btn.innerHTML = is ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
                });
            }
        });
    </script>
</body>

</html>