<?php
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$donation = null;
if (isset($_GET['donation_id']) && isset($_SESSION['user_id'])) {
    $donation_id = (int)$_GET['donation_id'];
    $stmt = $conn->prepare("SELECT d.*, c.judul FROM donations d JOIN campaigns c ON d.campaign_id = c.id WHERE d.id = ? AND d.user_id = ?");
    $stmt->bind_param("ii", $donation_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $donation = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$donation) {
    header("Location: index.php");
    exit();
}
?>

<?php require 'templatess/header.php'; ?>

<div class="container mx-auto px-6 py-12 text-center">
    <div class="max-w-md mx-auto">
        <!-- Gambar Centang -->
        <svg class="w-32 h-32 mx-auto mb-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <h2 class="text-2xl font-bold mb-4 text-gray-800">Transaksi Berhasil!</h2>
        <p class="text-gray-600 mb-8">Terima kasih atas donasi Anda untuk kampanye "<?php echo htmlspecialchars($donation['judul']); ?>".</p>
        <div class="flex justify-center space-x-4">
            <a href="profile.php" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition">
                Cek Invoice
            </a>
            <a href="index.php" class="bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700 transition">
                Beranda
            </a>
        </div>
    </div>
</div>

<?php $conn->close(); require 'templatess/footer.php'; ?>