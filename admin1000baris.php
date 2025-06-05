<?php
// ... (bagian awal kode sama seperti sebelumnya, mulai dari error_reporting hingga sebelum section 'apriori')
error_reporting(E_ALL);
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
$valid_sections = ['kelola', 'tambah-manual', 'tambah-csv', 'tambah-kategori', 'daftar', 'update', 'donasi', 'apriori'];
if (!in_array($section, $valid_sections)) {
    $section = 'kelola';
}

// Ambil daftar kategori dari database
$kategori_list = [];
$result = $conn->query("SELECT name FROM categories");
while ($row = $result->fetch_assoc()) {
    $kategori_list[] = $row['name'];
}

// Proses tambah kategori
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

// Proses input manual
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

// Proses update kampanye
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
        $section = 'daftar';
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
        $stmt = $conn->prepare("UPDATE campaigns SET judul = ?, deskripsi = ?, yayasan = ?, kategori = ?, sisa_hari = ?, lokasi = ? WHERE id = ?");
        $stmt->bind_param("ssssisi", $judul, $deskripsi, $yayasan, $kategori, $sisa_hari, $lokasi, $campaign_id);
        if ($stmt->execute()) {
            $update_message = 'Kampanye berhasil diperbarui.';
            $section = 'daftar';
        } else {
            $update_message = 'Gagal memperbarui kampanye: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Proses delete kampanye
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

        $stmt = $conn->prepare("DELETE FROM donations WHERE campaign_id = ?");
        $stmt->bind_param("i", $campaign_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->bind_param("i", $campaign_id);
        if ($stmt->execute()) {
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

// Proses pengaturan minimum support dan confidence
$min_support = isset($_SESSION['min_support']) ? $_SESSION['min_support'] : 0.2;
$min_confidence = isset($_SESSION['min_confidence']) ? $_SESSION['min_confidence'] : 0.5;
if ($section == 'kelola' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_apriori'])) {
    $min_support = floatval($_POST['min_support']);
    $min_confidence = floatval($_POST['min_confidence']);
    $_SESSION['min_support'] = $min_support;
    $_SESSION['min_confidence'] = $min_confidence;
    $manual_message = 'Pengaturan Apriori berhasil disimpan.';
}

// Proses Apriori
$apriori_message = '';
$apriori_results = [];
if ($section == 'apriori' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apriori_submit'])) {
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);
    $min_support_input = floatval($_POST['min_support']);
    $min_confidence_input = floatval($_POST['min_confidence']);

    if (empty($start_date) || empty($end_date) || $min_support_input <= 0 || $min_support_input > 1 || $min_confidence_input <= 0 || $min_confidence_input > 1) {
        $apriori_message = 'Semua field wajib diisi dengan nilai yang valid (0-1 untuk support dan confidence).';
    } else {
        $start_date = date('Y-m-d H:i:s', strtotime($start_date));
        $end_date = date('Y-m-d H:i:s', strtotime($end_date));

        // Ambil data donasi dalam rentang tanggal
        $stmt = $conn->prepare("SELECT c.kategori FROM donations d JOIN campaigns c ON d.campaign_id = c.id WHERE d.created_at BETWEEN ? AND ?");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['kategori'])) {
                $categories = array_filter(array_map('trim', explode(',', $row['kategori'])), function($item) {
                    return !empty($item);
                });
                if (!empty($categories)) {
                    $transactions[] = array_values($categories);
                }
            }
        }
        $stmt->close();

        $total_transactions = count($transactions);
        error_log("Total Transactions: $total_transactions");
        if ($total_transactions == 0) {
            $apriori_message = 'Tidak ada data donasi dengan kategori valid pada rentang tanggal tersebut.';
            $apriori_results = [
                'total_transactions' => 0,
                'min_support_absolut' => 0,
                'min_support_relatif' => 0,
                'min_confidence' => 0,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'itemset1' => [],
                'frequent_itemset1' => [],
                'itemset2' => [],
                'frequent_itemset2' => [],
                'rules' => [],
                'recommendations' => []
            ];
        } else {
            // Hitung min support absolut
            $min_support_absolut = $min_support_input * $total_transactions;
            $min_support_relatif = $min_support_input * 100;

            // Langkah 1: Hitung support untuk setiap item
            $item_counts = [];
            foreach ($transactions as $transaction) {
                foreach ($transaction as $item) {
                    if (!empty($item)) {
                        $item_counts[$item] = ($item_counts[$item] ?? 0) + 1;
                    }
                }
            }
            error_log("Itemset 1 Count: " . count($item_counts));

            $frequent_items = [];
            foreach ($item_counts as $item => $count) {
                $support = ($count / $total_transactions) * 100;
                if ($support >= ($min_support_input * 100)) {
                    $frequent_items[$item] = [
                        'count' => $count,
                        'support' => $support
                    ];
                }
            }
            error_log("Frequent Itemset 1 Count: " . count($frequent_items));

            // Langkah 2: Hitung support untuk pasangan item
            $pairs = [];
            $items = array_keys($frequent_items);
            for ($i = 0; $i < count($items); $i++) {
                for ($j = $i + 1; $j < count($items); $j++) {
                    $pair = [$items[$i], $items[$j]];
                    sort($pair);
                    $count = 0;
                    foreach ($transactions as $transaction) {
                        if (count(array_intersect($pair, $transaction)) == 2) {
                            $count++;
                        }
                    }
                    $support = ($count / $total_transactions) * 100;
                    if ($support >= ($min_support_input * 100)) {
                        $pairs[implode(',', $pair)] = [
                            'items' => $pair,
                            'count' => $count,
                            'support' => $support
                        ];
                    }
                }
            }
            error_log("Total Pairs: " . count($pairs));

            // Langkah 3: Hitung confidence untuk setiap aturan
            $rules = [];
            foreach ($pairs as $pair_key => $pair_data) {
                $items = $pair_data['items'];
                $item1 = $items[0];
                $item2 = $items[1];
                $count_pair = $pair_data['count'];
                $count_item1 = $item_counts[$item1] ?? 0;
                if ($count_item1 > 0) {
                    $confidence = ($count_pair / $count_item1) * 100;
                    if ($confidence >= ($min_confidence_input * 100)) {
                        $rules[] = [
                            'rule' => "$item1 -> $item2",
                            'support' => $pair_data['support'],
                            'confidence' => $confidence,
                            'consequent' => $item2
                        ];
                    }
                }

                $count_item2 = $item_counts[$item2] ?? 0;
                if ($count_item2 > 0) {
                    $confidence_reverse = ($count_pair / $count_item2) * 100;
                    if ($confidence_reverse >= ($min_confidence_input * 100)) {
                        $rules[] = [
                            'rule' => "$item2 -> $item1",
                            'support' => $pair_data['support'],
                            'confidence' => $confidence_reverse,
                            'consequent' => $item1
                        ];
                    }
                }
            }
            error_log("Total Rules: " . count($rules));

            // Langkah 4: Buat rekomendasi berdasarkan aturan
            $recommendations = [];
            foreach ($rules as $rule) {
                $consequent = $rule['consequent'];
                $query = "SELECT id, judul, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE kategori LIKE ?";
                $stmt = $conn->prepare($query);
                $like_param = "%$consequent%";
                $stmt->bind_param("s", $like_param);
                $stmt->execute();
                $result = $stmt->get_result();
                error_log("Consequent: $consequent, Query Result Rows: " . $result->num_rows);
                while ($row = $result->fetch_assoc()) {
                    $categories = array_map('trim', explode(',', strtolower($row['kategori'])));
                    error_log("Campaign Categories: " . implode(',', $categories));
                    if (in_array(strtolower($consequent), $categories)) {
                        error_log("Recommendation Added: {$row['judul']}");
                        $recommendations[] = [
                            'judul' => $row['judul'],
                            'yayasan' => $row['yayasan'],
                            'kategori' => $row['kategori'],
                            'lokasi' => $row['lokasi'],
                            'sisa_hari' => $row['sisa_hari'],
                            'gambar' => $row['gambar'],
                            'support' => $rule['support'],
                            'confidence' => $rule['confidence'],
                            'rule' => $rule['rule']
                        ];
                    }
                }
                $stmt->close();
            }
            error_log("Total Recommendations: " . count($recommendations));

            // Simpan hasil untuk ditampilkan
            $apriori_results = [
                'min_support_absolut' => $min_support_absolut,
                'min_support_relatif' => $min_support_relatif,
                'min_confidence' => $min_confidence_input * 100,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'total_transactions' => $total_transactions,
                'itemset1' => $item_counts,
                'frequent_itemset1' => $frequent_items,
                'itemset2' => array_map(function($key, $data) {
                    return [
                        'pair' => $key,
                        'count' => $data['count'],
                        'support' => $data['support']
                    ];
                }, array_keys($pairs), array_values($pairs)),
                'frequent_itemset2' => $pairs,
                'rules' => $rules,
                'recommendations' => $recommendations
            ];
            $apriori_message = 'Proses Apriori selesai.';
        }
    }
}

// Pagination untuk Daftar Kampanye
$campaigns = [];
$total_pages = 1;
$page = 1;
if ($section == 'daftar') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $total_campaigns = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
    $total_pages = ceil($total_campaigns / $per_page);
    $offset = ($page - 1) * $per_page;

    $result = $conn->query("SELECT * FROM campaigns LIMIT $offset, $per_page");
    while ($row = $result->fetch_assoc()) {
        $campaigns[] = $row;
    }
}

// Ambil data donasi
$donations = [];
$delete_donation_message = '';
if ($section == 'donasi') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $total_donations = $conn->query("SELECT COUNT(*) as count FROM donations")->fetch_assoc()['count'];
    $total_pages = ceil($total_donations / $per_page);
    $offset = ($page - 1) * $per_page;

    $stmt = $conn->prepare("SELECT id, user_id, campaign_id, name, amount, payment_method, created_at FROM donations LIMIT ?, ?");
    $stmt->bind_param("ii", $offset, $per_page);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $donations[] = $row;
    }
    $stmt->close();

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
        .sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 640px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
        }
        .notification {
            position: fixed; top: 20px; right: 20px; z-index: 50;
            padding: 1rem; border-radius: 0.375rem; display: none;
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100">
    <div id="notification" class="notification bg-green-500 text-white"></div>

    <div class="sidebar w-64 bg-gray-800 text-white p-4 fixed h-full shadow-lg z-20" id="sidebar">
        <h2 class="text-2xl font-bold mb-6">Admin Panel</h2>
        <nav>
            <!-- <a href="?section=kelola" class="block py-2 px-4 rounded <?php echo $section == 'kelola' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Admin Kelola Kampanye</a> -->
            <a href="?section=tambah-manual" class="block py-2 px-4 rounded <?php echo $section == 'tambah-manual' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Tambah Kampanye</a>
            <a href="?section=tambah-kategori" class="block py-2 px-4 rounded <?php echo $section == 'tambah-kategori' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Tambah Kategori</a>
            <a href="?section=daftar" class="block py-2 px-4 rounded <?php echo $section == 'daftar' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Daftar Kampanye</a>
            <a href="?section=donasi" class="block py-2 px-4 rounded <?php echo $section == 'donasi' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Riwayat Donasi</a>
            <a href="?section=apriori" class="block py-2 px-4 rounded <?php echo $section == 'apriori' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">Proses Apriori</a>
        </nav>
    </div>

    <button id="menu-toggle" class="fixed top-4 left-4 z-30 bg-gray-800 text-white p-2 rounded md:hidden">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
        </svg>
    </button>

    <div class="flex-1 ml-0 md:ml-64 p-6 overflow-auto" id="main-content">
        <?php require 'templatess/header.php'; ?>

        <div class="container mx-auto">
            <?php if ($section == 'kelola'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Pengaturan Apriori</h3>
                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Support (0-1)</label>
                            <input type="number" name="min_support" step="0.01" min="0" max="1" value="<?php echo htmlspecialchars($min_support); ?>" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Confidence (0-1)</label>
                            <input type="number" name="min_confidence" step="0.01" min="0" max="1" value="<?php echo htmlspecialchars($min_confidence); ?>" class="w-full p-2 border rounded" required>
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
                            <img src="<?php echo $image_path; ?>" alt="Gambar" class="max-w-20 h-20 object-cover mb-2">
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
                    <?php if (!empty($campaigns)): ?>
                        <table class="w-full border">
                            <thead>
                                <tr class="bg-gray-200">
                                    <th class="border p-2">Gambar</th>
                                    <th class="border p-2">Judul</th>
                                    <th class="border p-2">Yayasan</th>
                                    <th class="border p-2">Kategori</th>
                                    <th class="border p-2">Lokasi</th>
                                    <th class="border p-2">Sisa Hari</th>
                                    <th class="border p-2">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaigns as $row): ?>
                                    <tr>
                                        <td class="border p-2">
                                            <?php
                                            $image_path = file_exists("images/" . $row['gambar']) ? "/campaign_recommendation/images/" . htmlspecialchars($row['gambar']) . "?t=" . time() : "/campaign_recommendation/images/default.jpg?t=" . time();
                                            ?>
                                            <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Gambar" class="w-20 h-20 object-cover">
                                        </td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['judul']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['yayasan']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['kategori']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['lokasi']); ?></td>
                                        <td class="border p-2"><?php echo htmlspecialchars($row['sisa_hari']); ?></td>
                                        <td class="border p-2">
                                            <a href="?section=update&id=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-600">Update</a>
                                            <a href="?section=daftar&delete=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600" onclick="return confirm('Apakah Anda yakin ingin menghapus kampanye ini?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
<div class="mt-4 flex justify-between items-center">
    <div class="flex space-x-2">
        <?php
        $max_pages_to_show = 5; // Jumlah halaman yang ditampilkan di sekitar halaman aktif
        $start_page = max(1, $page - floor($max_pages_to_show / 2));
        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
        $start_page = max(1, $end_page - $max_pages_to_show + 1);
        ?>
        <?php if ($total_pages > 1): ?>
            <?php if ($page > 1): ?>
                <a href="?section=donasi&page=1" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">First</a>
                <a href="?section=donasi&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Previous</a>
            <?php endif; ?>
            <?php if ($start_page > 1): ?>
                <span class="px-4 py-2">...</span>
            <?php endif; ?>
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?section=donasi&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-300'; ?> rounded hover:bg-gray-400"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($end_page < $total_pages): ?>
                <span class="px-4 py-2">...</span>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?section=donasi&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Next</a>
                <a href="?section=donasi&page=<?php echo $total_pages; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Last</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="flex items-center space-x-2">
        <label for="go-to-page" class="text-gray-700">Go to Page:</label>
        <input type="number" id="go-to-page" min="1" max="<?php echo $total_pages; ?>" value="<?php echo $page; ?>" class="w-20 p-2 border rounded">
        <button id="go-to-page-btn" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Go</button>
    </div>
</div>

<script>
    // JavaScript untuk fitur "Go to Page"
    document.getElementById('go-to-page-btn')?.addEventListener('click', function() {
        const pageInput = document.getElementById('go-to-page').value;
        const maxPage = <?php echo $total_pages; ?>;
        const page = Math.max(1, Math.min(parseInt(pageInput) || 1, maxPage));
        window.location.href = `?section=donasi&page=${page}`;
    });

    // Aktifkan tombol Go saat menekan Enter
    document.getElementById('go-to-page')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('go-to-page-btn').click();
        }
    });
</script>
                    <?php else: ?>
                        <p>Tidak ada riwayat donasi ditemukan.</p>
                    <?php endif; ?>
                </div>
            <?php elseif ($section == 'apriori'): ?>
                <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
                    <h3 class="text-xl font-semibold mb-4">Proses Apriori Berdasarkan Donasi</h3>
                    <?php if ($apriori_message): ?>
                        <p class="text-<?php echo strpos($apriori_message, 'selesai') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($apriori_message); ?></p>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-4">
                            <label class="block text-gray-700">Tanggal Awal</label>
                            <input type="date" name="start_date" value="2025-05-01" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Tanggal Akhir</label>
                            <input type="date" name="end_date" value="2025-05-31" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Support (0-1)</label>
                            <input type="number" name="min_support" step="0.01" min="0" max="1" value="0.2" class="w-full p-2 border rounded" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700">Minimum Confidence (0-1)</label>
                            <input type="number" name="min_confidence" step="0.01" min="0" max="1" value="0.5" class="w-full p-2 border rounded" required>
                        </div>
                        <button type="submit" name="apriori_submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Proses Apriori</button>
                    </form>

                    <?php if (!empty($apriori_results)): ?>
    <div class="mt-6">
        <h4 class="text-lg font-semibold mb-2">Informasi Proses</h4>
        <p><strong>Jumlah Transaksi:</strong> <?php echo htmlspecialchars($apriori_results['total_transactions']); ?></p>
        <p><strong>Min Support Absolut:</strong> <?php echo htmlspecialchars(number_format($apriori_results['min_support_absolut'], 2)); ?></p>
        <p><strong>Min Support Relatif:</strong> <?php echo htmlspecialchars(number_format($apriori_results['min_support_relatif'], 2)); ?>%</p>
        <p><strong>Min Confidence:</strong> <?php echo htmlspecialchars(number_format($apriori_results['min_confidence'], 2)); ?>%</p>
        <p><strong>Start Date:</strong> <?php echo htmlspecialchars($apriori_results['start_date']); ?></p>
        <p><strong>End Date:</strong> <?php echo htmlspecialchars($apriori_results['end_date']); ?></p>

        <h4 class="text-lg font-semibold mt-4 mb-2">Itemset 1</h4>
        <?php if (!empty($apriori_results['itemset1'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Item</th>
                        <th class="border p-2">Support Absolut</th>
                        <th class="border p-2">Support Relatif (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_results['itemset1'] as $item => $count): ?>
                        <tr>
                            <td class="border p-2"><?php echo htmlspecialchars($item); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($count); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format(($count / $apriori_results['total_transactions']) * 100, 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada itemset 1 yang ditemukan.</p>
        <?php endif; ?>

        <h4 class="text-lg font-semibold mt-4 mb-2">Itemset 1 yang Lolos Minimum Support</h4>
        <?php if (!empty($apriori_results['frequent_itemset1'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Item</th>
                        <th class="border p-2">Support Absolut</th>
                        <th class="border p-2">Support Relatif (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_results['frequent_itemset1'] as $item => $data): ?>
                        <tr>
                            <td class="border p-2"><?php echo htmlspecialchars($item); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($data['count']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($data['support'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada itemset 1 yang lolos minimum support.</p>
        <?php endif; ?>

        <h4 class="text-lg font-semibold mt-4 mb-2">Itemset 2</h4>
        <?php if (!empty($apriori_results['itemset2'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Pasangan Item</th>
                        <th class="border p-2">Support Absolut</th>
                        <th class="border p-2">Support Relatif (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_results['itemset2'] as $pair): ?>
                        <tr>
                            <td class="border p-2"><?php echo htmlspecialchars($pair['pair'] ?? implode(',', $pair['items'])); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($pair['count']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($pair['support'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada itemset 2 yang ditemukan.</p>
        <?php endif; ?>

        <h4 class="text-lg font-semibold mt-4 mb-2">Itemset 2 yang Lolos Minimum Support</h4>
        <?php if (!empty($apriori_results['frequent_itemset2'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Pasangan Item</th>
                        <th class="border p-2">Support Absolut</th>
                        <th class="border p-2">Support Relatif (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_results['frequent_itemset2'] as $pair_key => $data): ?>
                        <tr>
                            <td class="border p-2"><?php echo htmlspecialchars($pair_key); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($data['count']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($data['support'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada itemset 2 yang lolos minimum support.</p>
        <?php endif; ?>

        <h4 class="text-lg font-semibold mt-4 mb-2">Aturan Asosiasi</h4>
        <?php if (!empty($apriori_results['rules'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Aturan</th>
                        <th class="border p-2">Support (%)</th>
                        <th class="border p-2">Confidence (%)</th>
                        <th class="border p-2">Lift</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $item_counts = $apriori_results['itemset1'];
                    $total_transactions = $apriori_results['total_transactions'];
                    foreach ($apriori_results['rules'] as $rule):
                        $rule_parts = array_map('trim', explode('->', $rule['rule']));
                        $consequent = $rule_parts[1];
                        $support_consequent = isset($item_counts[$consequent]) ? ($item_counts[$consequent] / $total_transactions) : 0;
                        $lift = $support_consequent > 0 ? ($rule['confidence'] / 100) / $support_consequent : 0;
                    ?>
                        <tr>
                            <td class="border p-2"><?php echo htmlspecialchars($rule['rule']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($rule['support'], 2)); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($rule['confidence'], 2)); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($lift, 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada aturan asosiasi yang memenuhi minimum confidence.</p>
        <?php endif; ?>

        <h4 class="text-lg font-semibold mt-4 mb-2">Rekomendasi Kampanye</h4>
        <?php if (!empty($apriori_results['recommendations'])): ?>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Gambar</th>
                        <th class="border p-2">Judul</th>
                        <th class="border p-2">Yayasan</th>
                        <th class="border p-2">Kategori</th>
                        <th class="border p-2">Lokasi</th>
                        <th class="border p-2">Sisa Hari</th>
                        <th class="border p-2">Aturan</th>
                        <th class="border p-2">Support (%)</th>
                        <th class="border p-2">Confidence (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_results['recommendations'] as $rec): ?>
                        <tr>
                            <td class="border p-2">
                                <?php
                                $image_path = file_exists("images/" . $rec['gambar']) ? "/campaign_recommendation/images/" . htmlspecialchars($rec['gambar']) . "?t=" . time() : "/campaign_recommendation/images/default.jpg?t=" . time();
                                ?>
                                <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Gambar" class="w-20 h-20 object-cover">
                            </td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['judul']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['yayasan']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['kategori']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['lokasi']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['sisa_hari']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['rule']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($rec['support'], 2)); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars(number_format($rec['confidence'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Tidak ada rekomendasi kampanye yang ditemukan.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        <?php if ($section == 'daftar' && strpos($update_message, 'berhasil') !== false): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($update_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => document.getElementById('notification').style.display = 'none', 3000);
        <?php elseif ($section == 'donasi' && $delete_donation_message): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($delete_donation_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => document.getElementById('notification').style.display = 'none', 3000);
        <?php elseif ($section == 'tambah-kategori' && $kategori_message): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($kategori_message); ?>';
            document.getElementById('notification').style.display = 'block';
            setTimeout(() => document.getElementById('notification').style.display = 'none', 3000);
        <?php endif; ?>
    </script>

    <?php
    $conn->close();
    require 'templatess/footer.php';
    ?>
</body>
</html>