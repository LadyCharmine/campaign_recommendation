<?php
error_reporting(E_ALL); // Aktifkan sementara untuk debugging
ini_set('display_errors', 1);
session_start();

// Cek apakah pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Koneksi ke database
$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Cek apakah pengguna adalah admin
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Tentukan bagian yang ditampilkan berdasarkan parameter 'section'
$section = isset($_GET['section']) ? $_GET['section'] : 'kelola';
$valid_sections = ['kelola', 'tambah-manual', 'tambah-csv', 'daftar', 'update', 'donasi', 'tambah-kategori'];
if (!in_array($section, $valid_sections)) {
    $section = 'kelola';
}

// Ambil daftar kategori dari database
$kategori_list = [];
$result = $conn->query("SELECT name FROM categories");
while ($row = $result->fetch_assoc()) {
    $kategori_list[] = $row['name'];
}

// Proses tambah kategori (hanya jika bagian 'tambah-kategori' aktif)
$kategori_message = '';
if ($section == 'tambah-kategori' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kategori_submit'])) {
    $nama_kategori = trim($_POST['nama_kategori']);
    
    if (empty($nama_kategori)) {
        $kategori_message = 'Nama kategori tidak boleh kosong.';
    } elseif (strlen($nama_kategori) > 100) {
        $kategori_message = 'Nama kategori maksimal 100 karakter.';
    } else {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $nama_kategori);
        if ($stmt->execute()) {
            $kategori_message = 'Kategori berhasil ditambahkan.';
            // Refresh daftar kategori
            $kategori_list = [];
            $result = $conn->query("SELECT name FROM categories");
            while ($row = $result->fetch_assoc()) {
                $kategori_list[] = $row['name'];
            }
        } else {
            $kategori_message = 'Gagal menambahkan kategori: ' . ($conn->errno == 1062 ? 'Kategori sudah ada.' : $conn->error);
        }
        $stmt->close();
    }
}

// Proses input manual (hanya jika bagian 'tambah-manual' aktif)
$manual_message = '';
if ($section == 'tambah-manual' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_submit'])) {
    $judul = trim($_POST['judul']);
    $deskripsi = trim($_POST['deskripsi']);
    $yayasan = trim($_POST['yayasan']);
    $kategori = isset($_POST['kategori']) ? implode(',', $_POST['kategori']) : '';
    $sisa_hari = (int)$_POST['sisa_hari'];
    $lokasi = trim($_POST['lokasi']);
    $gambar = 'default.jpg';

    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png'];
        $max_size = 2 * 1024 * 1024;
        if (in_array($_FILES['gambar']['type'], $allowed_types) && $_FILES['gambar']['size'] <= $max_size) {
            $ext = pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION);
            $gambar = 'kampanye_' . time() . '.' . $ext;
            $upload_success = move_uploaded_file($_FILES['gambar']['tmp_name'], "images/$gambar");
            if (!$upload_success) {
                $manual_message = 'Gagal mengunggah gambar ke direktori.';
            }
        } else {
            $manual_message = 'Gambar harus berupa JPEG/PNG dan maksimal 2MB.';
        }
    }

    if (empty($judul) || empty($deskripsi) || empty($yayasan) || empty($kategori) || $sisa_hari <= 0 || empty($lokasi)) {
        $manual_message = 'Semua field wajib diisi dan sisa hari harus lebih dari 0.';
    } else {
        $stmt = $conn->prepare("INSERT INTO campaigns (judul, deskripsi, yayasan, kategori, sisa_hari, uploaded_by, gambar, lokasi) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssiss", $judul, $deskripsi, $yayasan, $kategori, $sisa_hari, $_SESSION['user_id'], $gambar, $lokasi);
        if ($stmt->execute()) {
            $manual_message = 'Kampanye berhasil ditambahkan.';
        } else {
            $manual_message = 'Gagal menambahkan kampanye.';
        }
        $stmt->close();
    }
}

// Proses update kampanye (hanya jika bagian 'update' aktif)
$update_message = '';
$campaign_to_update = null;
if ($section == 'update' && isset($_GET['id'])) {
    $campaign_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->bind_param("i", $campaign_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $campaign_to_update = $result->fetch_assoc();
    } else {
        $update_message = 'Kampanye tidak ditemukan.';
        $section = 'daftar'; // Kembali ke daftar jika kampanye tidak ditemukan
    }
    $stmt->close();
}

if ($section == 'update' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_submit'])) {
    $campaign_id = (int)$_POST['campaign_id'];
    $judul = trim($_POST['judul']);
    $deskripsi = trim($_POST['deskripsi']);
    $yayasan = trim($_POST['yayasan']);
    $kategori = isset($_POST['kategori']) ? implode(',', $_POST['kategori']) : '';
    $sisa_hari = (int)$_POST['sisa_hari'];
    $lokasi = trim($_POST['lokasi']);

    if (empty($judul) || empty($deskripsi) || empty($yayasan) || empty($kategori) || $sisa_hari <= 0 || empty($lokasi)) {
        $update_message = 'Semua field wajib diisi dan sisa hari harus lebih dari 0.';
    } else {
        // Update tanpa mengubah kolom gambar
        $stmt = $conn->prepare("UPDATE campaigns SET judul = ?, deskripsi = ?, yayasan = ?, kategori = ?, sisa_hari = ?, lokasi = ? WHERE id = ?");
        $stmt->bind_param("ssssisi", $judul, $deskripsi, $yayasan, $kategori, $sisa_hari, $lokasi, $campaign_id);
        if ($stmt->execute()) {
            $update_message = 'Kampanye berhasil diperbarui.';
            $section = 'daftar'; // Kembali ke daftar setelah update
        } else {
            $update_message = 'Gagal memperbarui kampanye: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Proses delete kampanye (hanya jika bagian 'daftar' aktif dan ada parameter 'delete')
$delete_message = '';
if ($section == 'daftar' && isset($_GET['delete'])) {
    $campaign_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("SELECT gambar FROM campaigns WHERE id = ?");
    $stmt->bind_param("i", $campaign_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $campaign = $result->fetch_assoc();
        $gambar = $campaign['gambar'];
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->bind_param("i", $campaign_id);
        if ($stmt->execute()) {
            // Hapus gambar jika bukan default.jpg
            if ($gambar !== 'default.jpg' && file_exists("images/$gambar")) {
                unlink("images/$gambar");
            }
            $delete_message = 'Kampanye berhasil dihapus.';
        } else {
            $delete_message = 'Gagal menghapus kampanye.';
        }
        $stmt->close();
    } else {
        $delete_message = 'Kampanye tidak ditemukan.';
    }
}

// Proses pengaturan minimum support dan confidence (hanya jika bagian 'kelola' aktif)
$min_support = 0.2;
$min_confidence = 0.5;
if ($section == 'kelola' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_apriori'])) {
    $min_support = floatval($_POST['min_support']);
    $min_confidence = floatval($_POST['min_confidence']);
    $_SESSION['min_support'] = $min_support;
    $_SESSION['min_confidence'] = $min_confidence;
    $manual_message = 'Pengaturan Apriori berhasil disimpan.';
}

// Gunakan nilai dari sesi jika ada
$min_support = isset($_SESSION['min_support']) ? $_SESSION['min_support'] : 0.2;
$min_confidence = isset($_SESSION['min_confidence']) ? $_SESSION['min_confidence'] : 0.5;

// Proses upload file CSV (hanya jika bagian 'tambah-csv' aktif)
$upload_message = '';
if ($section == 'tambah-csv' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file']['tmp_name'];
    $file_type = $_FILES['file']['type'];
    
    if ($file_type === 'text/csv' || $file_type === 'application/vnd.ms-excel') {
        if (($handle = fopen($file, 'r')) !== false) {
            fgetcsv($handle);
            $stmt = $conn->prepare("INSERT INTO campaigns (judul, deskripsi, yayasan, kategori, sisa_hari, uploaded_by, gambar, lokasi) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $success_count = 0;
            $error_count = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($data) >= 7 && is_numeric($data[4])) {
                    $judul = $data[0];
                    $deskripsi = $data[1];
                    $yayasan = $data[2];
                    $kategori = $data[3];
                    $sisa_hari = (int)$data[4];
                    $uploaded_by = $_SESSION['user_id'];
                    $gambar = isset($data[5]) && !empty($data[5]) ? $data[5] : 'default.jpg';
                    $lokasi = isset($data[6]) && !empty($data[6]) ? $data[6] : 'Tidak diketahui';
                    $stmt->bind_param("sssssiss", $judul, $deskripsi, $yayasan, $kategori, $sisa_hari, $uploaded_by, $gambar, $lokasi);
                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                } else {
                    $error_count++;
                }
            }
            fclose($handle);
            $stmt->close();
            $upload_message = "Upload selesai: $success_count data berhasil, $error_count data gagal.";
        } else {
            $upload_message = "Gagal membaca file CSV.";
        }
    } else {
        $upload_message = "Harap unggah file CSV.";
    }
}

// Implementasi Algoritma Apriori Sederhana
$campaigns_data = [];
$apriori_rules = [];
$category_counts = [];
$pair_counts = [];
$total_campaigns = 0;

if ($section == 'daftar') {
    // Ambil semua data kampanye untuk analisis Apriori
    $result = $conn->query("SELECT kategori FROM campaigns");
    $total_campaigns = $result->num_rows;

    while ($row = $result->fetch_assoc()) {
        $categories = explode(',', $row['kategori']);
        $campaigns_data[] = $categories;

        // Hitung frekuensi masing-masing kategori
        foreach ($categories as $cat) {
            if (!isset($category_counts[$cat])) {
                $category_counts[$cat] = 0;
            }
            $category_counts[$cat]++;
        }

        // Hitung frekuensi pasangan kategori
        for ($i = 0; $i < count($categories); $i++) {
            for ($j = $i + 1; $j < count($categories); $j++) {
                $pair = [$categories[$i], $categories[$j]];
                sort($pair); // Urutkan untuk konsistensi
                $pair_key = implode(' -> ', $pair);
                if (!isset($pair_counts[$pair_key])) {
                    $pair_counts[$pair_key] = 0;
                }
                $pair_counts[$pair_key]++;
            }
        }
    }

    // Hitung aturan asosiasi
    foreach ($pair_counts as $pair_key => $count) {
        $pair = explode(' -> ', $pair_key);
        $antecedent = $pair[0]; // Kategori awal
        $consequent = $pair[1]; // Kategori hasil

        // Hitung support
        $support = ($count / $total_campaigns) * 100;

        // Hitung confidence
        $antecedent_count = $category_counts[$antecedent];
        $confidence = ($count / $antecedent_count) * 100;

        // Simpan aturan jika memenuhi minimum support dan confidence
        if ($support >= $min_support * 100 && $confidence >= $min_confidence * 100) {
            $apriori_rules[] = [
                'rule' => "$antecedent -> $consequent",
                'support' => $support,
                'confidence' => $confidence,
                'antecedent' => $antecedent,
                'consequent' => $consequent
            ];
        }

        // Aturan terbalik (consequent -> antecedent)
        $reverse_support = $support; // Support sama
        $consequent_count = $category_counts[$consequent];
        $reverse_confidence = ($count / $consequent_count) * 100;

        if ($reverse_support >= $min_support * 100 && $reverse_confidence >= $min_confidence * 100) {
            $apriori_rules[] = [
                'rule' => "$consequent -> $antecedent",
                'support' => $reverse_support,
                'confidence' => $reverse_confidence,
                'antecedent' => $consequent,
                'consequent' => $antecedent
            ];
        }
    }
}

// Pagination untuk Daftar Kampanye (hanya jika bagian 'daftar' aktif)
$campaigns = null;
$total_pages = 1;
$page = 1;
if ($section == 'daftar') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $total_campaigns = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
    $total_pages = ceil($total_campaigns / $per_page);
    $offset = ($page - 1) * $per_page;
    $campaigns = $conn->query("SELECT * FROM campaigns LIMIT $offset, $per_page");
}

// Ambil data donasi untuk bagian 'donasi'
$donations = [];
$delete_donation_message = '';
if ($section == 'donasi') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $total_donations = $conn->query("SELECT COUNT(*) as count FROM donations")->fetch_assoc()['count'];
    $total_pages = ceil($total_donations / $per_page);
    $offset = ($page - 1) * $per_page;

    // Ambil data donasi dengan struktur tabel yang benar
    $stmt = $conn->prepare("SELECT id, user_id, campaign_id, name, amount, payment_method, created_at FROM donations LIMIT ?, ?");
    $stmt->bind_param("ii", $offset, $per_page);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $donations[] = $row;
    }
    $stmt->close();

    // Proses delete donasi
    if (isset($_GET['delete_donation'])) {
        $donation_id = (int)$_GET['delete_donation'];
        $stmt = $conn->prepare("DELETE FROM donations WHERE id = ?");
        $stmt->bind_param("i", $donation_id);
        if ($stmt->execute()) {
            $delete_donation_message = 'Donasi berhasil dihapus.';
        } else {
            $delete_donation_message = 'Gagal menghapus donasi: ' . $conn->error;
        }
        $stmt->close();
        header("Location: ?section=donasi&page=$page");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Kelola Kampanye</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        @media (max-width: 640px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
        }
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 50;
            padding: 1rem;
            border-radius: 0.375rem;
            display: none;
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100">
    <!-- Notifikasi -->
    <div id="notification" class="notification bg-green-500 text-white"></div>

    <!-- Sidebar -->
    <div class="sidebar w-64 bg-gray-800 text-white p-4 fixed h-full shadow-lg z-20" id="sidebar">
        <h2 class="text-2xl font-bold mb-6">Admin Panel</h2>
        <nav>
            <a href="?section=kelola" class="block py-2 px-4 rounded <?php echo $section == 'kelola' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Admin Kelola Kampanye</a>
            <a href="?section=tambah-manual" class="block py-2 px-4 rounded <?php echo $section == 'tambah-manual' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Tambah Kampanye</a>
            <a href="?section=tambah-kategori" class="block py-2 px-4 rounded <?php echo $section == 'tambah-kategori' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Tambah Kategori</a>
            <a href="?section=tambah-csv" class="block py-2 px-4 rounded <?php echo $section == 'tambah-csv' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Tambah Kampanye (CSV)</a>
            <a href="?section=daftar" class="block py-2 px-4 rounded <?php echo $section == 'daftar' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Daftar Kampanye</a>
            <a href="?section=donasi" class="block py-2 px-4 rounded <?php echo $section == 'donasi' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Riwayat Donasi</a>
        </nav>
    </div>

    <!-- Toggle Button for Mobile -->
    <button id="menu-toggle" class="fixed top-4 left-4 z-30 bg-gray-800 text-white p-2 rounded md:hidden">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
        </svg>
    </button>

    <!-- Main Content -->
    <div class="flex-1 ml-0 md:ml-64 p-6 overflow-auto" id="main-content">
        <?php require 'templatess/header.php'; ?>

        <div class="container mx-auto">
            <!-- Hanya tampilkan bagian yang sesuai dengan section -->
            <?php if ($section == 'kelola'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Pengaturan Apriori</h3>
                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Support (0-1)</label>
                            <input type="number" name="min_support" step="0.1" min="0" max="1" value="<?php echo isset($_SESSION['min_support']) ? htmlspecialchars($_SESSION['min_support']) : 0.2; ?>" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Confidence (0-1)</label>
                            <input type="number" name="min_confidence" step="0.1" min="0" max="1" value="<?php echo isset($_SESSION['min_confidence']) ? htmlspecialchars($_SESSION['min_confidence']) : 0.5; ?>" class="w-full p-2 border rounded" required>
                        </div>
                        <button type="submit" name="set_apriori" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Simpan Pengaturan</button>
                    </form>
                </div>
            <?php elseif ($section == 'tambah-manual'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Tambah Kampanye</h3>
                    <?php if ($manual_message): ?>
                        <p class="text-<?php echo strpos($manual_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($manual_message); ?></p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="block text-gray-700">Judul</label>
                            <input type="text" name="judul" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Deskripsi</label>
                            <textarea name="deskripsi" class="w-full p-2 border rounded" required></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Yayasan</label>
                            <input type="text" name="yayasan" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Kategori</label>
                            <div class="space-y-2">
                                <?php foreach ($kategori_list as $kat): ?>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="kategori[]" value="<?php echo htmlspecialchars($kat); ?>" class="mr-2">
                                        <?php echo htmlspecialchars($kat); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Sisa Hari</label>
                            <input type="number" name="sisa_hari" class="w-full p-2 border rounded" min="1" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Lokasi</label>
                            <input type="text" name="lokasi" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Gambar (JPEG/PNG, maks 2MB)</label>
                            <input type="file" name="gambar" accept="image/jpeg,image/png" class="w-full p-2 border rounded">
                        </div>
                        <button type="submit" name="manual_submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Tambah Kampanye</button>
                    </form>
                </div>
            <?php elseif ($section == 'tambah-kategori'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Tambah Kategori</h3>
                    <?php if ($kategori_message): ?>
                        <p class="text-<?php echo strpos($kategori_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($kategori_message); ?></p>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-gray-700">Nama Kategori</label>
                            <input type="text" name="nama_kategori" class="w-full p-2 border rounded" required>
                        </div>
                        <button type="submit" name="kategori_submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Tambah Kategori</button>
                    </form>
                </div>
            <?php elseif ($section == 'tambah-csv'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Unggah Data Kampanye (CSV)</h3>
                    <?php if ($upload_message): ?>
                        <p class="text-<?php echo strpos($upload_message, 'gagal') !== false ? 'red' : 'green'; ?>-500 mb-4"><?php echo htmlspecialchars($upload_message); ?></p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="block text-gray-700">Pilih File CSV</label>
                            <input type="file" name="file" accept=".csv" class="w-full p-2 border rounded" required>
                            <p class="text-sm text-gray-500 mt-1">Format: judul,deskripsi,yayasan,kategori,sisa_hari,gambar,lokasi</p>
                        </div>
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Unggah</button>
                    </form>
                </div>
            <?php elseif ($section == 'update' && $campaign_to_update): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Update Kampanye</h3>
                    <?php if ($update_message): ?>
                        <p class="text-<?php echo strpos($update_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($update_message); ?></p>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaign_to_update['id']); ?>">
                        <div class="mb-4">
                            <label class="block text-gray-700">Judul</label>
                            <input type="text" name="judul" class="w-full p-2 border rounded" value="<?php echo htmlspecialchars($campaign_to_update['judul']); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Deskripsi</label>
                            <textarea name="deskripsi" class="w-full p-2 border rounded" required><?php echo htmlspecialchars($campaign_to_update['deskripsi']); ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Yayasan</label>
                            <input type="text" name="yayasan" class="w-full p-2 border rounded" value="<?php echo htmlspecialchars($campaign_to_update['yayasan']); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Kategori</label>
                            <div class="space-y-2">
                                <?php
                                $existing_kategori = explode(',', $campaign_to_update['kategori']);
                                foreach ($kategori_list as $kat): ?>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="kategori[]" value="<?php echo htmlspecialchars($kat); ?>" class="mr-2" <?php echo in_array($kat, $existing_kategori) ? 'checked' : ''; ?>>
                                        <?php echo htmlspecialchars($kat); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Sisa Hari</label>
                            <input type="number" name="sisa_hari" class="w-full p-2 border rounded" min="1" value="<?php echo htmlspecialchars($campaign_to_update['sisa_hari']); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Lokasi</label>
                            <input type="text" name="lokasi" class="w-full p-2 border rounded" value="<?php echo htmlspecialchars($campaign_to_update['lokasi']); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Gambar Saat Ini</label>
                            <?php
                            $image_path = file_exists("images/" . $campaign_to_update['gambar']) ? "/campaign_recommendation/images/" . htmlspecialchars($campaign_to_update['gambar']) . "?t=" . time() : "/campaign_recommendation/images/default.jpg?t=" . time();
                            ?>
                            <img src="<?php echo $image_path; ?>" alt="Gambar" class="w-20 h-20 object-cover mb-2">
                        </div>
                        <button type="submit" name="update_submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Update Kampanye</button>
                        <a href="?section=daftar" class="ml-2 bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Batal</a>
                    </form>
                </div>
            <?php elseif ($section == 'daftar'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Daftar Kampanye</h3>
                    <?php if ($delete_message): ?>
                        <p class="text-<?php echo strpos($delete_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($delete_message); ?></p>
                    <?php endif; ?>
                    <?php if ($campaigns->num_rows > 0): ?>
                        <table class="w-full border">
                            <thead>
                                <tr class="bg-gray-200">
                                    <th class="border p-2">Gambar</th>
                                    <th class="border p-2">Judul</th>
                                    <th class="border p-2">Yayasan</th>
                                    <th class="border p-2">Kategori</th>
                                    <th class="border p-2">Lokasi</th>
                                    <th class="border p-2">Sisa Hari</th>
                                    <th class="border p-2">Aturan Apriori</th>
                                    <th class="border p-2">Support (%)</th>
                                    <th class="border p-2">Confidence (%)</th>
                                    <th class="border p-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $campaigns->fetch_assoc()): ?>
                                    <tr>
                                        <td class="border p-2">
                                            <?php
                                            $image_path = file_exists("images/" . $row['gambar']) ? "/campaign_recommendation/images/" . htmlspecialchars($row['gambar']) . "?t=" . time() : "/campaign_recommendation/images/default.jpg?t=" . time();
                                            ?>
                                            <img src="<?php echo $image_path; ?>" alt="Gambar" class="w-20 h-20 object-cover">
                                        </td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['judul']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['yayasan']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['kategori']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['lokasi']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['sisa_hari']); ?></td>
                                        <td class="border p-2">
                                            <?php
                                            $campaign_categories = explode(',', $row['kategori']);
                                            $relevant_rules = [];
                                            foreach ($apriori_rules as $rule) {
                                                // Cek apakah antecedent ada di kategori kampanye ini
                                                if (in_array($rule['antecedent'], $campaign_categories)) {
                                                    $relevant_rules[] = $rule;
                                                }
                                            }
                                            if (!empty($relevant_rules)) {
                                                foreach ($relevant_rules as $rule) {
                                                    echo htmlspecialchars($rule['rule']) . '<br>';
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="border p-2">
                                            <?php
                                            if (!empty($relevant_rules)) {
                                                foreach ($relevant_rules as $rule) {
                                                    echo number_format($rule['support'], 2) . '<br>';
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="border p-2">
                                            <?php
                                            if (!empty($relevant_rules)) {
                                                foreach ($relevant_rules as $rule) {
                                                    echo number_format($rule['confidence'], 2) . '<br>';
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="border p-2">
                                            <a href="?section=update&id=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-600">Update</a>
                                            <a href="?section=daftar&delete=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600" onclick="return confirm('Apakah Anda yakin ingin menghapus kampanye ini?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <div class="mt-4 flex justify-center space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?section=daftar&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?section=daftar&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-300'; ?> rounded hover:bg-gray-400"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?section=daftar&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p>Tidak ada kampanye ditemukan.</p>
                    <?php endif; ?>
                </div>
            <?php elseif ($section == 'donasi'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Riwayat Donasi</h3>
                    <?php if ($delete_donation_message): ?>
                        <p class="text-<?php echo strpos($delete_donation_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($delete_donation_message); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($donations)): ?>
                        <table class="w-full border">
                            <thead>
                                <tr class="bg-gray-200">
                                    <th class="border p-2">ID Donasi</th>
                                    <th class="border p-2">ID User</th>
                                    <th class="border p-2">ID Kampanye</th>
                                    <th class="border p-2">Nama Donatur</th>
                                    <th class="border p-2">Jumlah Donasi</th>
                                    <th class="border p-2">Metode Pembayaran</th>
                                    <th class="border p-2">Tanggal</th>
                                    <th class="border p-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donations as $donation): ?>
                                    <tr>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['id']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['user_id']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['campaign_id']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['name']); ?></td>
                                        <td class="border p-2"><?php echo "Rp " . number_format($donation['amount'], 0, ',', '.'); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['payment_method']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($donation['created_at']); ?></td>
                                        <td class="border p-2">
                                            <a href="?section=donasi&delete_donation=<?php echo htmlspecialchars($donation['id']); ?>&page=<?php echo $page; ?>" class="inline-block my-1 mx-1 bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600" onclick="return confirm('Apakah Anda yakin ingin menghapus donasi ini?')">Hapus</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <div class="mt-4 flex justify-center space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?section=donasi&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?section=donasi&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-300'; ?> rounded hover:bg-gray-400"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?section=donasi&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p>Tidak ada riwayat donasi ditemukan.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Tampilkan notifikasi jika ada pesan update atau delete
        <?php if ($section == 'daftar' && strpos($update_message, 'berhasil') !== false): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($update_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => {
                document.getElementById('notification').style.display = 'none';
            }, 3000);
        <?php elseif ($section == 'donasi' && $delete_donation_message): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($delete_donation_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => {
                document.getElementById('notification').style.display = 'none';
            }, 3000);
        <?php elseif ($section == 'tambah-kategori' && $kategori_message): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($kategori_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => {
                document.getElementById('notification').style.display = 'none';
            }, 3000);
        <?php endif; ?>
    </script>

    <?php
    $conn->close();
    require 'templatess/footer.php';
    ?>
</body>
</html>