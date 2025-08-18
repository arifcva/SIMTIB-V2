<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi - Staff</title>
    <!-- icon -->
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- tailwind -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .notification-status {
            color: white;
            padding: 5px 10px;
            border-radius: 12px;
        }

        .notification-info {
            background-color: #4CAF50;
        }

        .notification-warning {
            background-color: #FFEB3B;
        }

        .notification-error {
            background-color: #F44336;
        }
    </style>
</head>

<body class="bg-gray-50 font-sans antialiased">

    <!-- Main Layout: Sidebar + Content -->
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="bg-indigo-900 text-white w-64 p-4 h-full overflow-y-auto">
            <h2 class="flex justify-center text-2xl font-semibold mb-6">
                <i class="mr-2"></i>SIMTIB
            </h2>
            <ul id="sidebarMenu">
                <li><a href="dashboard.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li><a href="tasks.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="text-lg block py-2 bg-indigo-600 text-white px-4 rounded-lg transition">
                        <i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="profile.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6 overflow-y-auto">
            <!-- Navbar -->
            <div class="flex justify-between items-center bg-white p-4 shadow-sm rounded-lg mb-6">
                <div class="text-lg font-bold text-gray-800">Notifikasi - Staff</div>
                <div>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition cursor-pointer" onclick="window.location.href='../../controllers/logout.php'">
                        <a href="../../controllers/logout.php">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </button>
                </div>
            </div>

            <!-- Notifikasi Section -->
            <div class="bg-white p-6 shadow-lg rounded-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Daftar Notifikasi</h3>

                <!-- Notification List -->
                <div class="space-y-4">
                    <!-- Notifikasi 1: Informasi -->
                    <div class="flex items-center justify-between p-4 border-b rounded-lg notification-info">
                        <div>
                            <p class="text-white font-medium">Tugas 25 telah selesai</p>
                            <p class="text-sm text-white">Tugas 25 yang Anda tangani telah selesai dan diperiksa.</p>
                        </div>
                        <span class="text-white text-sm">2025-08-09 10:00</span>
                    </div>

                    <!-- Notifikasi 2: Peringatan -->
                    <div class="flex items-center justify-between p-4 border-b rounded-lg notification-warning">
                        <div>
                            <p class="text-white font-medium">Tugas 30 mendekati batas waktu</p>
                            <p class="text-sm text-white">Pastikan tugas 30 diselesaikan sebelum tanggal 2025-08-10.</p>
                        </div>
                        <span class="text-white text-sm">2025-08-09 12:30</span>
                    </div>

                    <!-- Notifikasi 3: Error -->
                    <div class="flex items-center justify-between p-4 border-b rounded-lg notification-error">
                        <div>
                            <p class="text-white font-medium">Tugas 20 gagal diupload</p>
                            <p class="text-sm text-white">Gagal mengupload tugas 20. Harap coba lagi.</p>
                        </div>
                        <span class="text-white text-sm">2025-08-09 14:00</span>
                    </div>

                    <!-- Notifikasi 4: Informasi -->
                    <div class="flex items-center justify-between p-4 border-b rounded-lg notification-info">
                        <div>
                            <p class="text-white font-medium">Pemberitahuan pembaruan sistem</p>
                            <p class="text-sm text-white">Sistem akan melakukan pembaruan pada tanggal 2025-08-11 mulai pukul 02:00 WIB.</p>
                        </div>
                        <span class="text-white text-sm">2025-08-09 15:30</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>