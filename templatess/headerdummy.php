<?php
error_reporting(0);
session_start();
$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
$is_admin = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $is_admin = ($user['role'] === 'admin');
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekomendasi Kampanye</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="static/style.css">
</head>
<body class="bg-gray-100">
    <nav class="bg-blue-600 p-4 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-white text-2xl font-bold">Kampanye Bersama</h1>
            <div class="flex space-x-4">
                <a href="index.php" class="text-white hover:text-gray-200">Beranda</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="recommendations.php" class="text-white hover:text-gray-200">Rekomendasi</a>
                    <a href="profile.php" class="text-white hover:text-gray-200">Profile</a>
                    <?php if ($is_admin): ?>
                        <a href="admin.php" class="text-white hover:text-gray-200">Admin</a>
                    <?php endif; ?>
                    <a href="logout.php" class="text-white hover:text-gray-200">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="text-white hover:text-gray-200">Login</a>
                    <a href="register.php" class="text-white hover:text-gray-200">Daftar</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>