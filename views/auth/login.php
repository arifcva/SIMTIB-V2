<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMTIB Login</title>
    <!-- icon -->
    <link rel="icon" href="../../public/assets/img/bawaslu.png" type="image/svg+xml">
    <!-- tailwind -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #fff;
            color: #000;
        }

        .form-wrapper {
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .1);
            border-radius: .75rem;
            padding: 2rem;
            animation: fadeIn 1s forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .field {
            width: 100%;
            padding: .625rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: .5rem;
            font-size: .875rem;
        }

        .field:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, .2);
        }

        .btn-black {
            background: #000;
            color: #fff;
            border-radius: .5rem;
            padding: .5rem 1rem;
            font-weight: 600;
            transition: all .2s;
        }

        .btn-black:hover {
            background: #111;
        }

        .btn-text {
            font-size: .875rem;
            font-weight: 500;
            color: #000;
            transition: color .2s;
        }

        .btn-text:hover {
            color: #555;
        }

        footer {
            background: #fff;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            padding: 1rem 0;
            font-size: .875rem;
            color: #555;
        }
    </style>
</head>

<body>
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="form-wrapper w-full max-w-sm">
            <!-- Logo and Heading -->
            <div class="text-center mb-6">
                <img class="mx-auto h-12 w-auto" src="../../public/assets/img/bawaslu.png" alt="Login Bawaslu">
                <h2 class="mt-4 text-xl font-bold text-black">Login SIMTIB</h2>
                <p class="text-sm text-gray-600">Anggota Bawaslu</p>
            </div>

            <!-- Login Form -->
            <form class="space-y-5" action="../../controllers/UserController.php" method="POST">
                <input type="hidden" name="action" value="login">

                <div>
                    <label for="email" class="block text-sm mb-1">Alamat Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required placeholder="Masukkan email..."
                        class="field">
                </div>

                <div>
                    <label for="password" class="block text-sm mb-1">Kata Sandi</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                        placeholder="Masukkan kata sandi..." class="field">
                </div>

                <div class="flex items-center">
                    <input id="remember_me" name="remember" type="checkbox"
                        class="h-4 w-4 border-gray-300 rounded text-black focus:ring-black">
                    <label for="remember_me" class="ml-2 text-sm">Ingat saya</label>
                </div>

                <div class="flex justify-between items-center">
                    <button type="button" onclick="window.location.href='../../index.php'" class="btn-text">Kembali</button>
                    <button type="submit" class="btn-black">Masuk</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        &copy; 2025 SIMTIB | Dikembangkan oleh Tim Magang STIKOM
    </footer>

    <!-- Overlay animasi -->
    <div id="overlay" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 hidden z-50"></div>

    <script>
        document.querySelector("form").addEventListener("submit", function(event) {
            event.preventDefault();
            const loginButton = document.querySelector("button[type='submit']");
            const overlay = document.getElementById('overlay');

            loginButton.classList.add("opacity-70", "scale-95");
            overlay.style.display = 'block';

            setTimeout(() => {
                event.target.submit();
            }, 800);
        });
    </script>
</body>

</html>