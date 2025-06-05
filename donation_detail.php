<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=donation_detail.php");
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$campaign_id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisahari, gambar, terkumpul FROM campaigns WHERE id = ?");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}
$campaign = $result->fetch_assoc();
$stmt->close();

// Ambil rata-rata rating dari expert
$stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as rating_count FROM expert_ratings WHERE campaign_id = ?");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$rating_result = $stmt->get_result()->fetch_assoc();
$avg_rating = $rating_result['avg_rating'] ? number_format($rating_result['avg_rating'], 2) : 'Belum dinilai';
$rating_count = $rating_result['rating_count'];
$stmt->close();

// Ambil komentar expert
$comments = [];
$stmt = $conn->prepare("SELECT r.comment, u.username FROM expert_ratings r JOIN users u ON r.expert_id = u.id WHERE r.campaign_id = ?");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Donasi - <?php echo htmlspecialchars($campaign['judul']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <?php require 'templatess/header.php'; ?>
    <div class="container mx-auto p-6">
        <h2 class="text-2xl font-bold mb-4">Detail Donasi</h2>
        <div class="bg-white p-6 rounded-lg shadow-lg mb-6">
            <h3 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($campaign['judul']); ?></h3>
            <img src="images/<?php echo htmlspecialchars($campaign['gambar']); ?>" alt="<?php echo htmlspecialchars($campaign['judul']); ?>" class="w-full h-64 object-cover mb-4 rounded">
            <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($campaign['deskripsi']); ?></p>
            <div class="space-y-2">
                <p><strong>Yayasan:</strong> <?php echo htmlspecialchars($campaign['yayasan']); ?></p>
                <p><strong>Kategori:</strong> <?php echo htmlspecialchars($campaign['kategori']); ?></p>
                <p><strong>Lokasi:</strong> <?php echo htmlspecialchars($campaign['lokasi']); ?></p>
                <p><strong>Sisa Hari:</strong> <?php echo htmlspecialchars($campaign['sisahari']); ?></p>
                <p><strong>Terkumpul:</strong> Rp <?php echo number_format($campaign['terkumpul'], 0, ',', '.'); ?></p>
                <p><strong>Rating Expert:</strong> <?php echo $avg_rating; ?> (dari <?php echo $rating_count; ?> penilaian)</p>
            </div>
            <h4 class="text-lg font-semibold mt-4 mb-2">Komentar Expert</h4>
            <?php if ($comments): ?>
                <ul class="list-disc pl-5">
                    <?php foreach ($comments as $comment): ?>
                        <li><strong><?php echo htmlspecialchars($comment['username']); ?>:</strong> <?php echo htmlspecialchars($comment['comment'] ?: 'Tidak ada komentar'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>Belum ada komentar dari expert.</p>
            <?php endif; ?>
            <a href="campaign_detail.php?id=<?php echo $campaign_id; ?>" class="inline-block mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Donasi Sekarang</a>
            <a href="index.php" class="inline-block mt-4 ml-2 bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Kembali</a>
        </div>
    </div>
    <?php
    $conn->close();
    require 'templatess/footer.php';
    ?>
</body>
</html>