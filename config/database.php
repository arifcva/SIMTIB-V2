<?php
// koneksi ke database
$host = 'localhost';
$dbname = 'task_manager_bawaslu';
$username = 'root';
$password = '';
// Membuat koneksi
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
