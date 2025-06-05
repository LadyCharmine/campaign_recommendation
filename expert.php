<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Cek apakah pengguna adalah expert
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user['role'] !== 'expert') {
    header("Location: index.php");
    exit();
}

// Proses penilaian kampanye
$rating_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_rating'])) {
    $campaign_id = (int)$_POST['campaign_id'];
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    $expert_id = $_SESSION['user_id'];

    if ($rating < 1 || $rating > 5) {
        $rating_message = 'Rating harus antara 1 hingga 5.';
    } else {
        $stmt = $conn->prepare("INSERT INTO expert_ratings (campaign_id, expert_id, rating, comment) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $campaign_id, $expert_id, $rating, $comment);
        if ($stmt->execute()) {
            $rating_message = 'Penilaian berhasil disimpan.';
        } else {
            $rating_message = 'Gagal menyimpan penilaian: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Ambil daftar kampanye dengan penilaian expert
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$total_campaigns = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
$total_pages = ceil($total_campaigns / $per_page);

$campaigns = $conn->query("SELECT c.*, r.rating, r.comment 
                           FROM campaigns c 
                           LEFT JOIN expert_ratings r ON c.id = r.campaign_id AND r.expert_id = {$_SESSION['user_id']}
                           LIMIT $offset, $per_page");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert - Penilaian Kampanye</title>
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
        <h2 class="text-2xl font-bold mb-6">Expert Panel</h2>
        <nav>
            <a href="expert.php" class="block py-2 px-4 rounded bg-gray-700">Penilaian Kampanye</a>
        </nav>
    </div>

    <!-- Toggle Button for Mobile -->
    <button id="menu-toggle" class="fixed top-4 left-4 z-30 bg-gray-800 text-white p-2 rounded md:hidden">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
        </svg>
    </button>

    <!-- Main Content -->
    <div class="flex-1 ml-0 md:ml-64 p-6 overflow-auto">
        <?php require 'templatess/header.php'; ?>
        <div class="container mx-auto">
            <!-- <h2 class="text-2xl font-bold mb-4">Penilaian Kampanye</h2> -->
            <?php if ($rating_message): ?>
                <p class="text-<?php echo strpos($rating_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($rating_message); ?></p>
            <?php endif; ?>
            <?php if ($campaigns->num_rows > 0): ?>
                <table class="w-full border">
                    <thead>
                        <tr class="bg-gray-200">
                            <th class="border p-2">Judul</th>
                            <th class="border p-2">Yayasan</th>
                            <th class="border p-2">Kategori</th>
                            <th class="border p-2">Lokasi</th>
                            <th class="border p-2">Sisa Hari</th>
                            <th class="border p-2">Rating</th>
                            <th class="border p-2">Komentar</th>
                            <th class="border p-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $campaigns->fetch_assoc()): ?>
                            <tr>
                                <td class="border p-2"><?php echo htmlspecialchars($row['judul']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($row['yayasan']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($row['kategori']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($row['lokasi']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($row['sisa_hari']); ?></td>
                                <td class="border p-2"><?php echo $row['rating'] ? htmlspecialchars($row['rating']) : '-'; ?></td>
                                <td class="border p-2"><?php echo $row['comment'] ? htmlspecialchars($row['comment']) : '-'; ?></td>
                                <td class="border p-2">
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="campaign_id" value="<?php echo $row['id']; ?>">
                                        <select name="rating" class="p-1 border rounded" required>
                                            <option value="">Pilih Rating</option>
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo $row['rating'] == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <textarea name="comment" class="w-full p-1 border rounded" placeholder="Komentar"><?php echo $row['comment'] ? htmlspecialchars($row['comment']) : ''; ?></textarea>
                                        <button type="submit" name="submit_rating" class="bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700">Simpan</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <!-- Pagination -->
                <div class="mt-4 flex justify-center space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Previous</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-300'; ?> rounded hover:bg-gray-400"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p>Tidak ada kampanye untuk dinilai.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        <?php if ($rating_message): ?>
            document.getElementById('notification').textContent = '<?php echo htmlspecialchars($rating_message); ?>';
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