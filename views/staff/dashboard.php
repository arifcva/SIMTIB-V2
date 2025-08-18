<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'staff') {
    header('Location: ../auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Staff</title>
    <!-- icon -->
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- tailwind -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        /* Force Font Awesome Icon Display */
        .fas {
            color: white;
            /* Make sure icons are visible */
            z-index: 10;
            /* Ensure the icons are on top */
        }

        .dashboard-card {
            z-index: 5;
            /* Ensure the cards are not hidden behind the chart */
        }

        /* Special styles for icons to ensure visibility */
        .task-icon {
            color: white;
            z-index: 20;
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
                <li onclick="window.location.href='dashboard.php'"><a class="text-lg block py-2 bg-indigo-600 text-white px-4 rounded-lg transition">
                        <i class="fas fa-tachometer-alt mr-3"></i>Dashboard</a></li>
                <li onclick="window.location.href='tasks.php'"><a class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-tasks mr-3"></i>Manajemen Tugas</a></li>
                <li onclick="window.location.href='user-activity.php'"><a class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-users mr-3"></i>Aktivitas Pengguna</a></li>
                <li onclick="window.location.href='notifications.php'"><a class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <!-- Menu yang hanya bisa diakses oleh Admin dihapus -->
                <li onclick="window.location.href='profile.php'"><a class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6 overflow-y-auto">
            <!-- Navbar -->
            <div class="flex justify-between items-center bg-white p-4 shadow-sm rounded-lg mb-6">
                <div class="text-lg font-bold text-gray-800">Dashboard Staff</div>
                <div>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition cursor-pointer" onclick="window.location.href='../../controllers/logout.php'">
                        <a>
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </button>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Card 1: Total Tasks with Icon -->
                <div class="bg-blue-600 p-6 shadow-lg rounded-lg flex items-center justify-between dashboard-card">
                    <div class="text-3xl font-bold text-white" id="totalTasks">
                        <i class="fas fa-tasks mr-2"></i>120
                    </div>
                    <div class="text-white">Total Tugas</div>
                </div>

                <!-- Card 2: In Progress Tasks with Icon -->
                <div class="bg-yellow-600 p-6 shadow-lg rounded-lg flex items-center justify-between dashboard-card">
                    <div class="text-3xl font-bold text-white" id="inProgressTasks">
                        <i class="fas fa-cogs mr-2"></i>45
                    </div>
                    <div class="text-white">Tugas Sedang Dikerjakan</div>
                </div>

                <!-- Card 3: Completed Tasks with Icon -->
                <div class="bg-green-600 p-6 shadow-lg rounded-lg flex items-center justify-between dashboard-card">
                    <div class="text-3xl font-bold text-white" id="completedTasks">
                        <i class="fas fa-check-circle mr-2"></i>75
                    </div>
                    <div class="text-white">Tugas Selesai</div>
                </div>

                <!-- Card 4: Not Finished Tasks with Icon -->
                <div class="bg-red-600 p-6 shadow-lg rounded-lg flex items-center justify-between dashboard-card">
                    <div class="text-3xl font-bold text-white" id="notFinishedTasks">
                        <i class="fas fa-times-circle mr-2 task-icon text-black"></i>0
                    </div>
                    <div class="text-white">Tugas Belum Selesai</div>
                </div>
            </div>

            <!-- Activity Logs Section -->
            <div class="mt-8 bg-white p-6 shadow-lg rounded-lg flex">
                <!-- Log Aktivitas Pengguna -->
                <div class="w-1/2 pr-4">
                    <h3 class="text-xl font-semibold text-gray-800">Log Aktivitas Pengguna</h3>
                    <ul class="mt-4">
                        <li class="py-2 border-b border-gray-300 flex justify-between">
                            <span class="text-gray-700">Admin login</span>
                            <span class="text-sm text-gray-500">1 jam yang lalu</span>
                        </li>
                        <li class="py-2 border-b border-gray-300 flex justify-between">
                            <span class="text-gray-700">Staff A menyelesaikan tugas 25</span>
                            <span class="text-sm text-gray-500">2 jam yang lalu</span>
                        </li>
                        <li class="py-2 border-b border-gray-300 flex justify-between">
                            <span class="text-gray-700">Staff B menambahkan komentar pada tugas 10</span>
                            <span class="text-sm text-gray-500">3 jam yang lalu</span>
                        </li>
                    </ul>
                </div>

                <!-- Statistik Status Tugas Chart -->
                <div class="w-1/2 pl-4">
                    <div class="mt-4 w-90 mx-auto">
                        <h3 class="text-xl font-semibold text-gray-800 mb-4 text-center">Statistik Status Tugas</h3>
                        <canvas id="taskStatusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Data for the pie chart
        var ctx = document.getElementById('taskStatusChart').getContext('2d');
        var taskStatusChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Tugas Selesai', 'Tugas Sedang Dikerjakan', 'Tugas Belum Selesai'],
                datasets: [{
                    label: 'Status Tugas',
                    data: [75, 45, 120 - 75 - 45],
                    backgroundColor: ['#4CAF50', '#FFEB3B', '#F44336'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },

        });
    </script>

</body>

</html>