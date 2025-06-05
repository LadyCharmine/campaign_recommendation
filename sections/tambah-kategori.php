<?php
// Proses tambah kategori
$kategori_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kategori_submit'])) {
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
            // Refresh daftar kategori
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
?>

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

<script>
    <?php if ($kategori_message): ?>
        document.getElementById('notification').textContent = '<?php echo htmlspecialchars($kategori_message); ?>';
        document.getElementById('notification').style.display = 'block';
        setTimeout(() => {
            document.getElementById('notification').style.display = 'none';
        }, 3000);
    <?php endif; ?>
</script>