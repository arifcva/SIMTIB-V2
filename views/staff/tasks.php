<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Tugas - Staff</title>
    <!-- icon -->
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- tailwind -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .task-status {
            color: white;
            padding: 5px 10px;
            border-radius: 12px;
        }

        .in-progress {
            background-color: #FFEB3B;
        }

        .completed {
            background-color: #4CAF50;
        }

        .not-finished {
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
                <li><a href="tasks.php" class="text-lg block py-2 bg-indigo-600 text-white px-4 rounded-lg transition">
                        <i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li><a href="user-activity.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li><a href="notifications.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="profile.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6 overflow-y-auto">
            <!-- Navbar -->
            <div class="flex justify-between items-center bg-white p-4 shadow-sm rounded-lg mb-6">
                <div class="text-lg font-bold text-gray-800">Manajemen Tugas - Staff</div>
                <div>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition cursor-pointer" onclick="window.location.href='../../controllers/logout.php'">
                        <a href="../../controllers/logout.php">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </button>
                </div>
            </div>

            <!-- Manajemen Tugas Section -->
            <div class="bg-white p-6 shadow-lg rounded-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Daftar Tugas</h3>
                <table class="min-w-full bg-white">
                    <thead>
                        <tr class="border-b">
                            <th class="py-2 px-4 text-left text-gray-700">No</th>
                            <th class="py-2 px-4 text-left text-gray-700">Nama Tugas</th>
                            <th class="py-2 px-4 text-left text-gray-700">Tanggal Mulai</th>
                            <th class="py-2 px-4 text-left text-gray-700">Tanggal Selesai</th>
                            <th class="py-2 px-4 text-left text-gray-700">Status</th>
                            <th class="py-2 px-4 text-left text-gray-700">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="py-2 px-4">1</td>
                            <td class="py-2 px-4">Tugas 1 - Penyusunan Laporan</td>
                            <td class="py-2 px-4">2025-08-09</td>
                            <td class="py-2 px-4">2025-08-15</td>
                            <td class="py-2 px-4">
                                <span class="task-status in-progress">Sedang Dikerjakan</span>
                            </td>
                            <td class="py-2 px-4">
                                <button class="px-4 py-2 bg-yellow-600 text-white rounded-lg">Update Status</button>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4">2</td>
                            <td class="py-2 px-4">Tugas 2 - Penyusunan Anggaran</td>
                            <td class="py-2 px-4">2025-08-05</td>
                            <td class="py-2 px-4">2025-08-10</td>
                            <td class="py-2 px-4">
                                <span class="task-status completed">Selesai</span>
                            </td>
                            <td class="py-2 px-4">
                                <button class="px-4 py-2 bg-green-600 text-white rounded-lg">Update Status</button>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-2 px-4">3</td>
                            <td class="py-2 px-4">Tugas 3 - Pemeriksaan Data</td>
                            <td class="py-2 px-4">2025-08-08</td>
                            <td class="py-2 px-4">2025-08-12</td>
                            <td class="py-2 px-4">
                                <span class="task-status not-finished">Belum Selesai</span>
                            </td>
                            <td class="py-2 px-4">
                                <button class="px-4 py-2 bg-red-600 text-white rounded-lg">Update Status</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>

</html>