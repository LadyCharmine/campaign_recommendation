<?php
error_reporting(0);
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$donation = null;
if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $donation_id = (int)$_GET['id'];
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

<div class="container mx-auto px-6 py-12">
    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Invoice Donasi</h2>
        <div class="space-y-4">
            <p><strong>No. Invoice:</strong> <?php echo htmlspecialchars($donation['id']); ?></p>
            <p><strong>Kampanye:</strong> <?php echo htmlspecialchars($donation['judul']); ?></p>
            <p><strong>Nama Donatur:</strong> <?php echo htmlspecialchars($donation['name']); ?></p>
            <p><strong>Jumlah Donasi:</strong> Rp <?php echo number_format($donation['amount'], 2); ?></p>
            <p><strong>Metode Pembayaran:</strong> <?php echo htmlspecialchars($donation['payment_method']); ?></p>
            <p><strong>Tanggal:</strong> <?php echo htmlspecialchars($donation['created_at']); ?></p>
        </div>
        <p class="mt-6 text-gray-600">Terima kasih atas donasi Anda! Ini hanya simulasi dan tidak melibatkan pembayaran nyata.</p>
        <a href="index.php" class="mt-6 inline-block bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition">
            Kembali ke Beranda
        </a>
    </div>
</div>

<?php $conn->close(); require 'templatess/footer.php'; ?>