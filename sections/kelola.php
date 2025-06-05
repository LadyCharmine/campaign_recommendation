<?php
// Proses pengaturan minimum support dan confidence
$manual_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_apriori'])) {
    $min_support = floatval($_POST['min_support']);
    $min_confidence = floatval($_POST['min_confidence']);
    $_SESSION['min_support'] = $min_support;
    $_SESSION['min_confidence'] = $min_confidence;
    $manual_message = 'Pengaturan Apriori berhasil disimpan.';
}
?>

<div class="bg-white p-6 rounded-lg shadow-lg mb-6">
    <h3 class="text-xl font-semibold mb-4">Pengaturan Apriori</h3>
    <?php if ($manual_message): ?>
        <p class="text-<?php echo strpos($manual_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($manual_message); ?></p>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-4">
            <label class="block text-gray-700">Minimum Support (0-1)</label>
            <input type="number" name="min_support" step="0.1" min="0" max="1" value="<?php echo isset($_SESSION['min_support']) ? htmlspecialchars($_SESSION['min_support']) : 0.2; ?>" class="w-full p-2 border rounded" required>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700">Minimum Confidence (0-1)</label>
            <input type="number" name="min_confidence" step="0.1" min="0" max="1" value="<?php echo isset($_SESSION['min_confidence']) ? htmlspecialchars($_SESSION['min_confidence']) : 0.5; ?>" class="w-full p-2 border rounded" required>
        </div>
        <button type="submit" name="set_apriori" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Simpan Pengaturan</button>
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