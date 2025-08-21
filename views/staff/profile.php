<?php

/******************************
 * Guard & Runtime
 ******************************/
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../auth/login.php');
    exit;
}
date_default_timezone_set('Asia/Makassar');

/******************************
 * Security Headers & Cache
 ******************************/
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
/* Catatan: ada sedikit inline JS (toggle password & preview foto) → sementara izinkan 'unsafe-inline' */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'");

/******************************
 * DB Connection
 ******************************/
require_once '../../config/database.php'; // harus set $conn = new mysqli(...)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
$conn->set_charset('utf8mb4');

/******************************
 * CSRF
 ******************************/
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

/******************************
 * Context
 ******************************/
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/******************************
 * Helpers
 ******************************/
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function ensure_dir($path)
{
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

/******************************
 * Fetch: data user + hitung unread notif
 ******************************/
$u = ['name' => '', 'email' => '', 'position' => '', 'profile_photo' => null];
$st = $conn->prepare("SELECT name, email, position, profile_photo FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($u['name'], $u['email'], $u['position'], $u['profile_photo']);
$st->fetch();
$st->close();

$unreadCount = 0;
$st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
$st->bind_param('i', $userId);
$st->execute();
$st->bind_result($unreadCount);
$st->fetch();
$st->close();

/******************************
 * Actions (POST)
 ******************************/
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $flash = ['type' => 'error', 'msg' => 'CSRF tidak valid. Silakan muat ulang halaman.'];
    } else {
        $action = $_POST['action'] ?? '';

        /* A) Update profil (nama, email, jabatan, dan/atau password) */
        if ($action === 'update_profile') {
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $pass     = $_POST['new_password'] ?? '';
            $pass2    = $_POST['confirm_password'] ?? '';

            if ($name === '' || $email === '' || $position === '') {
                $flash = ['type' => 'error', 'msg' => 'Nama, email, dan jabatan wajib diisi.'];
            } else {
                // update basic fields
                if ($pass !== '') {
                    if ($pass !== $pass2) {
                        $flash = ['type' => 'error', 'msg' => 'Konfirmasi password tidak cocok.'];
                    } elseif (strlen($pass) < 8) {
                        $flash = ['type' => 'error', 'msg' => 'Password minimal 8 karakter.'];
                    } else {
                        $hash = password_hash($pass, PASSWORD_DEFAULT);
                        $st = $conn->prepare("UPDATE users SET name=?, email=?, position=?, password_hash=?, updated_at=NOW() WHERE id=?");
                        $st->bind_param('ssssi', $name, $email, $position, $hash, $userId);
                        $st->execute();
                        $st->close();
                        $flash = ['type' => 'success', 'msg' => 'Profil & password berhasil diperbarui.'];
                        // refresh $u
                        $u['name'] = $name;
                        $u['email'] = $email;
                        $u['position'] = $position;
                    }
                } else {
                    $st = $conn->prepare("UPDATE users SET name=?, email=?, position=?, updated_at=NOW() WHERE id=?");
                    $st->bind_param('sssi', $name, $email, $position, $userId);
                    $st->execute();
                    $st->close();
                    $flash = ['type' => 'success', 'msg' => 'Profil berhasil diperbarui.'];
                    $u['name'] = $name;
                    $u['email'] = $email;
                    $u['position'] = $position;
                }
            }
        }

        /* B) Upload/Ubah foto profil */
        if ($action === 'upload_photo' && isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['profileImage'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $flash = ['type' => 'error', 'msg' => 'Gagal upload foto. Coba lagi.'];
            } else {
                // Validasi dasar: ukuran & MIME
                $maxBytes = 2 * 1024 * 1024; // 2MB
                if ($file['size'] > $maxBytes) {
                    $flash = ['type' => 'error', 'msg' => 'Ukuran foto maksimal 2MB.'];
                } else {
                    $fi = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $fi->file($file['tmp_name']);
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime])) {
                        $flash = ['type' => 'error', 'msg' => 'Format foto harus JPG, PNG, atau WEBP.'];
                    } else {
                        // Siapkan folder & nama unik
                        $dir = realpath(__DIR__ . '/../../public/uploads/avatars');
                        if (!$dir) {
                            $dir = __DIR__ . '/../../public/uploads/avatars';
                        }
                        ensure_dir($dir);
                        $ext = $allowed[$mime];
                        $fname = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = rtrim($dir, '/') . '/' . $fname;

                        if (!move_uploaded_file($file['tmp_name'], $dest)) {
                            $flash = ['type' => 'error', 'msg' => 'Tidak dapat menyimpan file upload.'];
                        } else {
                            // Simpan path relatif untuk dipakai di <img>
                            $relPath = '../../public/uploads/avatars/' . $fname;

                            // Hapus foto lama (jika ada & file eksis)
                            if (!empty($u['profile_photo'])) {
                                $old = realpath(__DIR__ . '/' . $u['profile_photo']);
                                if ($old && is_file($old)) {
                                    @unlink($old);
                                }
                            }

                            $st = $conn->prepare("UPDATE users SET profile_photo=?, updated_at=NOW() WHERE id=?");
                            $st->bind_param('si', $relPath, $userId);
                            $st->execute();
                            $st->close();

                            $u['profile_photo'] = $relPath;
                            $flash = ['type' => 'success', 'msg' => 'Foto profil berhasil diperbarui.'];
                        }
                    }
                }
            }
        }

        /* C) Hapus foto profil */
        if ($action === 'delete_photo') {
            if (!empty($u['profile_photo'])) {
                $old = realpath(__DIR__ . '/' . $u['profile_photo']);
                if ($old && is_file($old)) {
                    @unlink($old);
                }
            }
            $st = $conn->prepare("UPDATE users SET profile_photo=NULL, updated_at=NOW() WHERE id=?");
            $st->bind_param('i', $userId);
            $st->execute();
            $st->close();
            $u['profile_photo'] = null;
            $flash = ['type' => 'success', 'msg' => 'Foto profil dihapus.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Staff</title>
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- Tailwind (browser build) -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4" defer></script>
    <!-- Font Awesome -->
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
            background: #f9fafb;
            color: var(--ink);
        }

        .sidebar {
            width: 16rem;
            background: var(--paper);
            border-right: 1px solid var(--line);
        }

        .slink {
            display: block;
            padding: .625rem 1rem;
            border-radius: .5rem;
            color: var(--ink);
        }

        .slink:hover {
            background: #f3f4f6;
        }

        .slink.active {
            background: #000;
            color: #fff;
        }

        .slink .fas {
            color: inherit;
        }

        .slink.active .fas {
            color: #fff !important;
        }

        /* ===== Badge jumlah notifikasi – sesuai referensi (ukuran tetap & tidak dorong sidebar) ===== */
        .badge {
            min-width: 1.5rem;
            height: 1.5rem;
            line-height: 1.5rem;
            padding: 0 .5rem;
            border-radius: 9999px;
            font-size: .75rem;
            text-align: center;
            background: #111;
            color: #fff;
            border: 1px solid #111;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .slink.active .badge {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        /* Avatar */
        .profile-img {
            width: 144px;
            height: 144px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .08);
        }

        .avatar-fallback {
            width: 144px;
            height: 144px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            background: #111827;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .08);
        }

        .card {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        .btn {
            border-radius: .5rem;
            padding: .5rem 1rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .btn-black {
            background: #000;
            color: #fff;
            border: 1px solid #000;
        }

        .btn-black:hover {
            background: #0a0a0a;
            border-color: #0a0a0a;
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
            box-shadow: 0 0 0 2px rgba(0, 0, 0, .12);
        }

        .note {
            font-size: .85rem;
            color: #6b7280;
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="h-screen flex">

        <!-- Sidebar -->
        <aside class="sidebar p-4 h-full overflow-y-auto hidden lg:block">
            <h2 class="flex justify-center text-xl font-bold mb-6">SIMTIB</h2>
            <ul class="space-y-1">
                <li><a href="dashboard.php" class="slink flex items-center justify-between"><span class="inline-flex items-center"><i class="fas fa-tachometer-alt mr-3"></i>Dashboard</span></a></li>
                <li><a href="tasks.php" class="slink flex items-center justify-between"><span class="inline-flex items-center"><i class="fas fa-tasks mr-3"></i>Manajemen Tugas</span></a></li>
                <li><a href="user-activity.php" class="slink flex items-center justify-between"><span class="inline-flex items-center"><i class="fas fa-users mr-3"></i>Aktivitas Pengguna</span></a></li>
                <li>
                    <a href="notifications.php" class="slink flex items-center justify-between">
                        <span class="inline-flex items-center"><i class="fas fa-bell mr-3"></i>Notifikasi</span>
                        <?php if ((int)$unreadCount > 0): ?>
                            <span class="badge shrink-0" aria-label="Notifikasi belum dibaca"><?= (int)$unreadCount /* atau: (int)$unreadCount>99?'99+':(int)$unreadCount */ ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="profile.php" class="slink active flex items-center justify-between"><span class="inline-flex items-center"><i class="fas fa-user mr-3"></i>Profil</span></a></li>
            </ul>
            <form action='../../controllers/logout.php' method='POST' class="mt-6">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="w-full btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
            </form>
        </aside>

        <!-- Main -->
        <div class="flex-1 p-6 overflow-y-auto">
            <!-- Topbar -->
            <div class="card p-4 mb-6 flex items-center justify-between">
                <div class="text-lg font-bold">Profil - Staff</div>
                <form action='../../controllers/logout.php' method='POST'>
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-sign-out-alt mr-2"></i> Logout</button>
                </form>
            </div>

            <?php if ($flash): ?>
                <div class="mb-4 border rounded-lg p-3 <?= $flash['type'] === 'success' ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800' ?>">
                    <?= e($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <!-- Profile Card -->
            <div class="card p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Informasi Profil</h3>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Avatar + Actions -->
                    <div>
                        <div class="flex items-center gap-4">
                            <?php if (!empty($u['profile_photo'])): ?>
                                <img src="<?= e($u['profile_photo']) ?>" alt="Foto Profil" class="profile-img" id="avatarPreview">
                            <?php else: ?>
                                <div class="avatar-fallback" id="avatarFallback"><?= e(mb_strtoupper(mb_substr($u['name'] ?: 'S', 0, 1))) ?></div>
                                <img src="" id="avatarPreview" class="profile-img hidden" alt="Foto Profil">
                            <?php endif; ?>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-3">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="file" id="profileImage" name="profileImage" accept="image/png,image/jpeg,image/webp" class="field" />
                            <p class="note">PNG/JPG/WEBP, maks 2MB.</p>
                            <div class="flex gap-2">
                                <button type="submit" class="btn btn-black"><i class="fas fa-upload mr-2"></i>Unggah</button>
                                <?php if (!empty($u['profile_photo'])): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_photo">
                                        <button type="submit" class="btn btn-outline"><i class="fas fa-trash mr-2"></i>Hapus Foto</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if (!empty($u['profile_photo'])): ?>
                            <form method="POST" class="mt-2">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="delete_photo">
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Profile Form -->
                    <div class="lg:col-span-2">
                        <h3 class="text-lg font-semibold mb-4">Edit Profil</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <div>
                                <label class="block text-gray-700 font-semibold">Nama Lengkap</label>
                                <input type="text" name="name" value="<?= e($u['name']) ?>" class="field mt-1" required>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold">Email</label>
                                <input type="email" name="email" value="<?= e($u['email']) ?>" class="field mt-1" required>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold">Divisi</label>
                                <input type="text" name="position" value="<?= e($u['position']) ?>" class="field mt-1" required>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold">Password Baru (opsional)</label>
                                    <div class="relative mt-1">
                                        <input type="password" id="new_password" name="new_password" class="field pr-10" placeholder="Biarkan kosong jika tidak ganti">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 text-gray-500" data-toggle="pw" data-target="#new_password"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-gray-700 font-semibold">Konfirmasi Password</label>
                                    <div class="relative mt-1">
                                        <input type="password" id="confirm_password" name="confirm_password" class="field pr-10" placeholder="Ulangi password baru">
                                        <button type="button" class="absolute inset-y-0 right-0 px-3 text-gray-500" data-toggle="pw" data-target="#confirm_password"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <span class="note">Biarkan kolom password kosong bila tidak ingin mengubah.</span>
                                <button type="submit" class="btn btn-black"><i class="fas fa-save mr-2"></i>Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        /* Toggle drawer? (Jika kamu menambahkan drawer versi mobile, bisa reuse handler di halaman lain) */

        /* Toggle show/hide password */
        document.querySelectorAll('[data-toggle="pw"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetSel = btn.getAttribute('data-target');
                const input = document.querySelector(targetSel);
                if (!input) return;
                const isPwd = input.type === 'password';
                input.type = isPwd ? 'text' : 'password';
                btn.innerHTML = isPwd ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            });
        });

        /* Preview foto sebelum upload + fallback avatar */
        const fileInput = document.getElementById('profileImage');
        const img = document.getElementById('avatarPreview');
        const fallback = document.getElementById('avatarFallback');
        if (fileInput && img) {
            fileInput.addEventListener('change', () => {
                const f = fileInput.files && fileInput.files[0];
                if (!f) return;
                const ok = ['image/png', 'image/jpeg', 'image/webp'].includes(f.type);
                if (!ok) {
                    alert('Format harus PNG/JPG/WEBP');
                    fileInput.value = '';
                    return;
                }
                const url = URL.createObjectURL(f);
                img.src = url;
                img.classList.remove('hidden');
                if (fallback) fallback.classList.add('hidden');
            });
        }
    </script>
</body>

</html>