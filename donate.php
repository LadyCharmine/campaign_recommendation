<?php
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Buat tabel donations jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    campaign_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$campaign = null;
if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $campaign_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT id, judul FROM campaigns WHERE id = ?");
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

$success_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $amount = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);

    if ($name && $amount > 0 && $payment_method) {
        $user_id = $_SESSION['user_id'];
        
        // Mulai transaksi
        $conn->begin_transaction();
        
        try {
            // Simpan donasi ke tabel donations
            $stmt = $conn->prepare("INSERT INTO donations (user_id, campaign_id, name, amount, payment_method) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisds", $user_id, $campaign_id, $name, $amount, $payment_method);
            $stmt->execute();
            $donation_id = $conn->insert_id;
            $stmt->close();

            // Perbarui kolom terkumpul di tabel campaigns
            $stmt = $conn->prepare("UPDATE campaigns SET terkumpul = terkumpul + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $campaign_id);
            $stmt->execute();
            $stmt->close();

            // Commit transaksi
            $conn->commit();

            // Arahkan ke halaman sukses
            header("Location: transaction_success.php?donation_id=$donation_id");
            exit();
        } catch (Exception $e) {
            // Rollback jika terjadi error
            $conn->rollback();
            $success_message = "Gagal menyimpan donasi: " . $e->getMessage();
        }
    } else {
        $success_message = "Semua field wajib diisi dan jumlah harus lebih dari 0.";
    }
}
?>

<?php require 'templatess/header.php'; ?>

<div class="container mx-auto px-6 py-12">
    <div class="max-w-md mx-auto bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Formulir Donasi</h2>
        <h3 class="text-lg font-semibold mb-4 text-gray-700"><?php echo htmlspecialchars($campaign['judul']); ?></h3>
        <?php if ($success_message): ?>
            <p class="text-red-500 mb-4"><?php echo htmlspecialchars($success_message); ?></p>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700">Nama Donatur</label>
                <input type="text" name="name" class="w-full p-2 border rounded" required>
            </div>
            <div>
                <label class="block text-gray-700">Jumlah Donasi (Rp)</label>
                <input type="number" name="amount" step="0.01" min="0.01" class="w-full p-2 border rounded" required>
            </div>
            <div>
                <label class="block text-gray-700">Metode Pembayaran</label>
                <select name="payment_method" class="w-full p-2 border rounded" required>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="E-Wallet">E-Wallet</option>
                    <option value="Cash">Cash</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition">
                Kirim Donasi
            </button>
        </form>
    </div>
</div>

<?php $conn->close(); require 'templatess/footer.php'; ?>