<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Ambil kata kunci pencarian
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$campaigns = [];
$total_items = 0;

// Pagination
$items_per_page = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

if ($keyword) {
    // Query untuk mencari kampanye berdasarkan judul
    $stmt = $conn->prepare("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar, terkumpul 
                            FROM campaigns 
                            WHERE judul LIKE ?");
    $like_keyword = "%$keyword%";
    $stmt->bind_param("s", $like_keyword);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $campaigns[] = [
            'id' => $row['id'],
            'judul' => $row['judul'],
            'deskripsi' => $row['deskripsi'],
            'yayasan' => $row['yayasan'],
            'kategori' => $row['kategori'],
            'lokasi' => $row['lokasi'],
            'sisa_hari' => $row['sisa_hari'],
            'gambar' => $row['gambar'],
            'terkumpul' => $row['terkumpul']
        ];
    }
    $stmt->close();
    
    // Hitung total item untuk pagination
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM campaigns WHERE judul LIKE ?");
    $count_stmt->bind_param("s", $like_keyword);
    $count_stmt->execute();
    $total_items = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
}

$total_pages = ceil($total_items / $items_per_page);
$campaigns = array_slice($campaigns, $offset, $items_per_page);

?>

<?php require 'templatess/header.php'; ?>

<div class="container mx-auto px-6 py-12 bg-gray-100">
    <h2 class="text-3xl font-bold text-center mb-12">Hasil Pencarian: <?php echo htmlspecialchars($keyword); ?></h2>
    <?php if (!empty($campaigns)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($campaigns as $row): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <img src="images/<?php echo htmlspecialchars($row['gambar']); ?>" alt="<?php echo htmlspecialchars($row['judul']); ?>" class="w-full h-48 object-cover">
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($row['judul']); ?></h3>
                        <p class="text-gray-600 mb-4"><?php echo htmlspecialchars(substr($row['deskripsi'], 0, 100)) . (strlen($row['deskripsi']) > 100 ? '...' : ''); ?></p>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-500"><strong>Yayasan:</strong> <?php echo htmlspecialchars($row['yayasan']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Kategori:</strong> <?php echo htmlspecialchars($row['kategori']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Lokasi:</strong> <?php echo htmlspecialchars($row['lokasi']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Sisa Hari:</strong> <?php echo htmlspecialchars($row['sisa_hari']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Terkumpul:</strong> Rp <?php echo number_format($row['terkumpul'] ?? 0, 0, ',', '.'); ?></p>
                        </div>
                        <div class="mt-4 space-y-2">
                            <a href="<?php echo isset($_SESSION['user_id']) ? 'recommendations.php?select=' . $row['id'] : 'login.php?redirect=index.php'; ?>" class="block w-full bg-blue-600 text-white font-medium rounded-lg px-4 py-2 text-center hover:bg-blue-700 transition">
                                <?php echo isset($_SESSION['user_id']) ? 'Pilih sebagai Acuan' : 'Donasi Sekarang'; ?>
                            </a>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="donation_detail.php?id=<?php echo $row['id']; ?>" class="block w-full bg-green-600 text-white font-medium rounded-lg px-4 py-2 text-center hover:bg-green-700 transition">
                                    Lihat Detail Donasi
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <div class="mt-8 flex justify-between items-center space-x-2">
            <div class="flex items-center space-x-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&keyword=<?php echo urlencode($keyword); ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Previous</a>
                <?php endif; ?>
            </div>
            <div class="flex space-x-2">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&keyword=<?php echo urlencode($keyword); ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg transition"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <div class="flex items-center space-x-2">
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&keyword=<?php echo urlencode($keyword); ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <p class="text-center text-gray-600">Tidak ada kampanye yang ditemukan untuk kata kunci "<?php echo htmlspecialchars($keyword); ?>".</p>
    <?php endif; ?>
</div>

<?php
$conn->close();
require 'templatess/footer.php';
?>