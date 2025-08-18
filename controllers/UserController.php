<?php
// controllers/usercontroller.php
session_start();
include '../config/database.php'; // $conn = new mysqli(...)

// helper: redirect dengan optional flash error
function goto_login($msg = null)
{
    if ($msg) $_SESSION['login_error'] = $msg;
    header('Location: ../views/auth/login.php');
    exit;
}

// Helper: cek user_id valid (untuk audit log jika kamu pakai)
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

// ROUTER sederhana
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ================== LOGIN ================== */
if ($method === 'POST' && $action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        goto_login('Email dan password wajib diisi.');
    }

    // Ambil user by email (prepared)
    if (!($st = $conn->prepare("SELECT id, name, email, role, password FROM users WHERE email=? LIMIT 1"))) {
        goto_login('Terjadi kesalahan. Coba lagi.');
    }
    $st->bind_param("s", $email);
    $st->execute();
    $res  = $st->get_result();
    $user = $res->fetch_assoc();
    $st->close();

    if (!$user) {
        goto_login('Email atau password salah.');
    }

    $stored = $user['password'] ?? '';
    $ok = false;

    // 1) password_hash (bcrypt/argon)
    $info = password_get_info($stored);
    if ($stored && $info['algo'] !== 0) {
        $ok = password_verify($pass, $stored);
    }

    // 2) fallback MD5 legacy
    if (!$ok && $stored === md5($pass)) {
        $ok = true;
    }

    // 3) fallback plain legacy
    if (!$ok && hash_equals($stored, $pass)) {
        $ok = true;
    }

    if (!$ok) {
        goto_login('Email atau password salah.');
    }

    // Migrasi otomatis jika masih legacy (md5/plain) -> simpan password_hash()
    if ($info['algo'] === 0) {
        $newHash = password_hash($pass, PASSWORD_DEFAULT);
        if ($up = $conn->prepare("UPDATE users SET password=? WHERE id=?")) {
            $uid = (int)$user['id'];
            $up->bind_param("si", $newHash, $uid);
            $up->execute();
            $up->close();
        }
    }

    // Set session lengkap
    $_SESSION['login']   = true;
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];

    // Redirect sesuai role
    if ($user['role'] === 'admin') {
        header('Location: ../views/admin/dashboard.php');
        exit;
    } elseif ($user['role'] === 'staff') {
        header('Location: ../views/staff/dashboard.php');
        exit;
    } else {
        // role tidak dikenal → logout paksa
        session_unset();
        session_destroy();
        goto_login('Role pengguna tidak dikenali.');
    }
}

/* ================== LOGOUT ================== */
if ($action === 'logout') {
    session_unset();
    session_destroy();
    header('Location: ../views/auth/login.php');
    exit;
}

/* Default: arahkan ke login */
goto_login();
