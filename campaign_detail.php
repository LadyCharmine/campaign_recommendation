<?php
error_reporting(0);
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$campaign = null;
if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $campaign_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->bind_param("i", $campaign_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $campaign = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$campaign || !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
?>

<?php require 'templatess/header.php'; ?>

<div class="container mx-auto px-6 py-12">
    <div class="max-w-3xl mx-auto bg-white rounded-xl shadow-lg overflow-hidden">
        <img src="images/<?php echo htmlspecialchars($campaign['gambar']); ?>" alt="<?php echo htmlspecialchars($campaign['judul']); ?>" class="w-full h-64 object-cover">
        <div class="p-6">
            <h2 class="text-3xl font-bold mb-4 text-gray-800"><?php echo htmlspecialchars($campaign['judul']); ?></h2>
            <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($campaign['deskripsi']); ?></p>
            <div class="space-y-3">
                <p class="text-sm text-gray-500"><strong>Yayasan:</strong> <?php echo htmlspecialchars($campaign['yayasan']); ?></p>
                <p class="text-sm text-gray-500"><strong>Kategori:</strong> <?php echo htmlspecialchars($campaign['kategori']); ?></p>
                <p class="text-sm text-gray-500"><strong>Lokasi:</strong> <?php echo htmlspecialchars($campaign['lokasi']); ?></p>
                <p class="text-sm text-gray-500"><strong>Sisa Hari:</strong> <?php echo htmlspecialchars($campaign['sisa_hari']); ?></p>
            </div>
            <a href="donate.php?id=<?php echo $campaign['id']; ?>" class="mt-6 inline-block bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition">
                Donasi Sekarang
            </a>
        </div>
    </div>
</div>

<?php $conn->close(); require 'templatess/footer.php'; ?>