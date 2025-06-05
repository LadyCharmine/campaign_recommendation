<?php
echo "File donasi.php berhasil dimuat<br>";

// Ambil data donasi
$donations = [];
$delete_donation_message = '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
echo "Menghitung total donasi...<br>";
$total_donations_result = $conn->query("SELECT COUNT(*) as count FROM donations");
if ($total_donations_result === false) {
    echo "Error query total donasi: " . $conn->error . "<br>";
} else {
    $total_donations = $total_donations_result->fetch_assoc()['count'];
    $total_pages = ceil($total_donations / $per_page);
    $offset = ($page - 1) * $per_page;
    echo "Mengambil daftar donasi...<br>";
    $stmt = $conn->prepare("SELECT id, user_id, campaign_id, name, amount, payment_method, created_at FROM donations LIMIT ?, ?");
    if ($stmt === false) {
        echo "Error prepare donasi: " . $conn->error . "<br>";
    } else {
        $stmt->bind_param("ii", $offset, $per_page);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $donations[] = $row;
            }
        } else {
            echo "Error execute donasi: " . $conn->error . "<br>";
        }
        $stmt->close();
    }
}

// Proses delete donasi
if (isset($_GET['delete_donation'])) {
    $donation_id = (int)$_GET['delete_donation'];
    $stmt = $conn->prepare("DELETE FROM donations WHERE id = ?");
    if ($stmt === false) {
        echo "Error prepare delete donasi: " . $conn->error . "<br>";
    } else {
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

<script>
    <?php if ($delete_donation_message): ?>
        document.getElementById('notification').textContent = '<?php echo htmlspecialchars($delete_donation_message); ?>';
        document.getElementById('notification').style.display = 'block';
        setTimeout(() => {
            document.getElementById('notification').style.display = 'none';
        }, 3000);
    <?php endif; ?>
</script>