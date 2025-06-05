<?php
error_reporting(0);
session_start();
$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
$is_admin = false;
$is_expert = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $is_admin = ($user['role'] === 'admin');
    $is_expert = ($user['role'] === 'expert');
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
            <div class="flex items-center space-x-4">
                <!-- Search Bar -->
                <form method="GET" action="search_results.php" class="flex items-center">
                    <input type="text" name="keyword" placeholder="Cari judul kampanye..." class="p-2 rounded-l-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" value="<?php echo isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : ''; ?>">
                    <button type="submit" class="bg-white text-blue-600 p-2 rounded-r-md hover:bg-gray-100 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </form>
                <!-- Menu Navigasi -->
                <div class="flex space-x-4">
                    <a href="index.php" class="text-white hover:text-gray-200">Beranda</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="recommendations.php" class="text-white hover:text-gray-200">Rekomendasi</a>
                        <a href="profile.php" class="text-white hover:text-gray-200">Profile</a>
                        <?php if ($is_admin): ?>
                            <a href="admin.php?section=tambah-manual" class="text-white hover:text-gray-200">Admin</a>
                        <?php endif; ?>
                        <?php if ($is_expert): ?>
                            <a href="expert.php" class="text-white hover:text-gray-200">Expert</a>
                        <?php endif; ?>
                        <a href="logout.php" class="text-white hover:text-gray-200">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="text-white hover:text-gray-200">Login</a>
                        <a href="register.php" class="text-white hover:text-gray-200">Daftar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>