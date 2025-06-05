<?php
// Proses input manual
$manual_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_submit'])) {
    $judul = trim($_POST['judul']);
    $deskripsi = trim($_POST['deskripsi']);
    $yayasan = trim($_POST['yayasan']);
    $kategori = isset($_POST['kategori']) ? implode(',', $_POST['kategori']) : '';
    $sisa_hari = (int)$_POST['sisa_hari'];
    $lokasi = trim($_POST['lokasi']);
    $gambar = 'default.jpg';

    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png'];
        $max_size = 2 * 1024 * 1024;
        if (in_array($_FILES['gambar']['type'], $allowed_types) && $_FILES['gambar']['size'] <= $max_size) {
            $ext = pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION);
            $gambar = 'kampanye_' . time() . '.' . $ext;
            $upload_success = move_uploaded_file($_FILES['gambar']['tmp_name'], "images/$gambar");
            if (!$upload_success) {
                $manual_message = 'Gagal mengunggah gambar ke direktori.';
            }
        } else {
            $manual_message = 'Gambar harus berupa JPEG/PNG dan maksimal 2MB.';
        }
    }

    if (empty($judul) || empty($deskripsi) || empty($yayasan) || empty($kategori) || $sisa_hari <= 0 || empty($lokasi)) {
        $manual_message = 'Semua field wajib diisi dan sisa hari harus lebih dari 0.';
    } else {
        $stmt = $conn->prepare("INSERT INTO campaigns (judul, deskripsi, yayasan, kategori, sisa_hari, uploaded_by, gambar, lokasi) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssiss", $judul, $deskripsi, $yayasan, $kategori, $sisa_hari, $_SESSION['user_id'], $gambar, $lokasi);
        if ($stmt->execute()) {
            $manual_message = 'Kampanye berhasil ditambahkan.';
        } else {
            $manual_message = 'Gagal menambahkan kampanye.';
        }
        $stmt->close();
    }
}
?>

<div class="bg-white p-6 rounded-lg shadow-lg mb-6">
    <h3 class="text-xl font-semibold mb-4">Tambah Kampanye</h3>
    <?php if ($manual_message): ?>
        <p class="text-<?php echo strpos($manual_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($manual_message); ?></p>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <div class="mb-4">
            <label class="block text-gray-700">Judul</label>
            <input type="text" name="judul" class="w-full p-2 border rounded" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Deskripsi</label>
            <textarea name="deskripsi" class="w-full p-2 border rounded" required></textarea>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Yayasan</label>
            <input type="text" name="yayasan" class="w-full p-2 border rounded" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Kategori</label>
            <div class="space-y-2">
                <?php foreach ($kategori_list as $kat): ?>
                    <label class="flex items-center">
                        <input type="checkbox" name="kategori[]" value="<?php echo htmlspecialchars($kat); ?>" class="mr-2">
                        <?php echo htmlspecialchars($kat); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Sisa Hari</label>
            <input type="number" name="sisa_hari" class="w-full p-2 border rounded" min="1" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Lokasi</label>
            <input type="text" name="lokasi" class="w-full p-2 border rounded" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Gambar (JPEG/PNG, maks 2MB)</label>
            <input type="file" name="gambar" accept="image/jpeg,image/png" class="w-full p-2 border rounded">
        </div>
        <button type="submit" name="manual_submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Tambah Kampanye</button>
    </form>
</div>

<script>
    <?php if ($manual_message): ?>
        document.getElementById('notification').textContent = '<?php echo htmlspecialchars($manual_message); ?>';
        document.getElementById('notification').style.display = 'block';
        setTimeout(() => {
            document.getElementById('notification').style.display = 'none';
        }, 3000);
    <?php endif; ?>
</script>