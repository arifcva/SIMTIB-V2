<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Staff</title>
    <!-- icon -->
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- tailwind -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom style for profile picture */
        .profile-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
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
                <li><a href="notifications.php" class="text-lg block py-2 hover:bg-indigo-600 px-4 rounded-lg transition">
                        <i class="fas fa-bell mr-3"></i>Notifikasi</a></li>
                <li><a href="profile.php" class="text-lg block py-2 bg-indigo-600 text-white px-4 rounded-lg transition">
                        <i class="fas fa-user mr-3"></i>Profil</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="flex-1 p-6 overflow-y-auto">
            <!-- Navbar -->
            <div class="flex justify-between items-center bg-white p-4 shadow-sm rounded-lg mb-6">
                <div class="text-lg font-bold text-gray-800">Profil - Staff</div>
                <div>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition cursor-pointer" onclick="window.location.href='../../controllers/logout.php'">
                        <a href="../../controllers/logout.php">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </button>
                </div>
            </div>

            <!-- Profil Section -->
            <div class="bg-white p-6 shadow-lg rounded-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Informasi Profil</h3>

                <div class="flex items-center mb-6">
                    <!-- Foto Profil -->
                    <img src="https://via.placeholder.com/150" alt="Foto Profil" class="profile-img mr-6">

                    <div>
                        <h4 class="text-2xl font-bold text-gray-800">Nama Staff</h4>
                        <p class="text-gray-600">Jabatan: Staff Tugas</p>
                        <p class="text-gray-600">Email: staff@example.com</p>
                    </div>
                </div>

                <h3 class="text-xl font-semibold text-gray-800 mb-6">Edit Profil</h3>

                <!-- Form Edit Profil -->
                <form action="#" method="POST">
                    <div class="mb-4">
                        <label for="name" class="block text-gray-700 font-semibold">Nama Lengkap</label>
                        <input type="text" id="name" name="name" value="Nama Staff" class="w-full px-4 py-2 mt-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-600" required>
                    </div>

                    <div class="mb-4">
                        <label for="email" class="block text-gray-700 font-semibold">Email</label>
                        <input type="email" id="email" name="email" value="staff@example.com" class="w-full px-4 py-2 mt-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-600" required>
                    </div>

                    <div class="mb-4">
                        <label for="position" class="block text-gray-700 font-semibold">Jabatan</label>
                        <input type="text" id="position" name="position" value="Staff Tugas" class="w-full px-4 py-2 mt-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-600" required>
                    </div>

                    <div class="mb-6">
                        <label for="profileImage" class="block text-gray-700 font-semibold">Foto Profil</label>
                        <input type="file" id="profileImage" name="profileImage" class="w-full px-4 py-2 mt-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-600">
                    </div>

                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">Simpan Perubahan</button>
                </form>
            </div>
        </div>
    </div>

</body>

</html>