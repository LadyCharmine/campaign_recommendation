<?php
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user = null;
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
}
$stmt->close();

// Proses ubah password
$success_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password === $confirm_password) {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        if (password_verify($current_password, $user_data['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $success_message = "Password berhasil diubah.";
            } else {
                $success_message = "Gagal mengubah password.";
            }
        } else {
            $success_message = "Password lama salah.";
        }
        $stmt->close();
    } else {
        $success_message = "Password baru dan konfirmasi tidak cocok.";
    }
}

// Ambil riwayat donasi
$donations = [];
$stmt = $conn->prepare("SELECT d.*, c.judul FROM donations d JOIN campaigns c ON d.campaign_id = c.id WHERE d.user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $donations[] = $row;
}
$stmt->close();

// Tentukan section yang aktif
$section = isset($_GET['section']) ? $_GET['section'] : 'change_password';
?>

<?php require 'templatess/header.php'; ?>

<div class="container mx-auto px-6 py-12 flex">
    <!-- Sidebar -->
    <div class="w-64 bg-white rounded-lg shadow-lg p-4 mr-6">
        <h3 class="text-lg font-semibold mb-4">Menu</h3>
        <ul class="space-y-2">
            <li>
                <a href="profile.php?section=change_password" class="block px-4 py-2 rounded-lg <?php echo $section === 'change_password' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-200'; ?>">
                    Change Password
                </a>
            </li>
            <li>
                <a href="profile.php?section=invoice" class="block px-4 py-2 rounded-lg <?php echo $section === 'invoice' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-200'; ?>">
                    Invoice
                </a>
            </li>
        </ul>
    </div>

    <!-- Konten Utama -->
    <div class="flex-1 bg-white rounded-lg shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Profil Pengguna</h2>
        <!-- <p class="text-gray-700 mb-6"><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p> -->

        <?php if ($section === 'change_password'): ?>
            <!-- Form Ubah Password -->
            <h3 class="text-xl font-semibold mb-4">Ubah Password</h3>
            <?php if ($success_message): ?>
                <p class="text-<?php echo strpos($success_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($success_message); ?></p>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-gray-700">Password Lama</label>
                    <input type="password" name="current_password" class="w-full p-2 border rounded" required>
                </div>
                <div>
                    <label class="block text-gray-700">Password Baru</label>
                    <input type="password" name="new_password" class="w-full p-2 border rounded" required>
                </div>
                <div>
                    <label class="block text-gray-700">Konfirmasi Password Baru</label>
                    <input type="password" name="confirm_password" class="w-full p-2 border rounded" required>
                </div>
                <button type="submit" name="change_password" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                    Ubah Password
                </button>
            </form>
        <?php elseif ($section === 'invoice'): ?>
            <!-- Riwayat Donasi -->
            <h3 class="text-xl font-semibold mb-4">Riwayat Donasi</h3>
            <?php if (empty($donations)): ?>
                <p class="text-gray-600">Belum ada riwayat donasi.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($donations as $donation): ?>
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p><strong>Kampanye:</strong> <?php echo htmlspecialchars($donation['judul']); ?></p>
                            <p><strong>Jumlah:</strong> Rp <?php echo number_format($donation['amount'], 2); ?></p>
                            <p><strong>Metode:</strong> <?php echo htmlspecialchars($donation['payment_method']); ?></p>
                            <p><strong>Tanggal:</strong> <?php echo htmlspecialchars($donation['created_at']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php $conn->close(); require 'templatess/footer.php'; ?>