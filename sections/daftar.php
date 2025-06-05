<?php
echo "File daftar.php berhasil dimuat<br>";

// Proses delete kampanye
$delete_message = '';
if (isset($_GET['delete'])) {
    $campaign_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("SELECT gambar FROM campaigns WHERE id = ?");
    if ($stmt === false) {
        echo "Error prepare delete: " . $conn->error . "<br>";
    } else {
        $stmt->bind_param("i", $campaign_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $campaign = $result->fetch_assoc();
                $gambar = $campaign['gambar'];
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ?");
                if ($stmt === false) {
                    echo "Error prepare delete: " . $conn->error . "<br>";
                } else {
                    $stmt->bind_param("i", $campaign_id);
                    if ($stmt->execute()) {
                        if ($gambar !== 'default.jpg' && file_exists("images/$gambar")) {
                            unlink("images/$gambar");
                        }
                        $delete_message = 'Kampanye berhasil dihapus.';
                    } else {
                        $delete_message = 'Gagal menghapus kampanye: ' . $conn->error;
                    }
                    $stmt->close();
                }
            } else {
                $delete_message = 'Kampanye tidak ditemukan.';
            }
        } else {
            echo "Error execute delete: " . $conn->error . "<br>";
        }
    }
}

// Implementasi Algoritma Apriori Sederhana
$campaigns_data = [];
$apriori_rules = [];
$category_counts = [];
$pair_counts = [];
$total_campaigns = 0;

echo "Mengambil data kampanye untuk Apriori...<br>";
$result = $conn->query("SELECT kategori FROM campaigns");
if ($result === false) {
    echo "Error query Apriori: " . $conn->error . "<br>";
} else {
    $total_campaigns = $result->num_rows;
    while ($row = $result->fetch_assoc()) {
        $categories = explode(',', $row['kategori']);
        $campaigns_data[] = $categories;

        foreach ($categories as $cat) {
            if (!isset($category_counts[$cat])) {
                $category_counts[$cat] = 0;
            }
            $category_counts[$cat]++;
        }

        for ($i = 0; $i < count($categories); $i++) {
            for ($j = $i + 1; $j < count($categories); $j++) {
                $pair = [$categories[$i], $categories[$j]];
                sort($pair);
                $pair_key = implode(' -> ', $pair);
                if (!isset($pair_counts[$pair_key])) {
                    $pair_counts[$pair_key] = 0;
                }
                $pair_counts[$pair_key]++;
            }
        }
    }

    foreach ($pair_counts as $pair_key => $count) {
        $pair = explode(' -> ', $pair_key);
        $antecedent = $pair[0];
        $consequent = $pair[1];

        $support = ($count / $total_campaigns) * 100;
        $antecedent_count = $category_counts[$antecedent];
        $confidence = ($count / $antecedent_count) * 100;

        if ($support >= $min_support * 100 && $confidence >= $min_confidence * 100) {
            $apriori_rules[] = [
                'rule' => "$antecedent -> $consequent",
                'support' => $support,
                'confidence' => $confidence,
                'antecedent' => $antecedent,
                'consequent' => $consequent
            ];
        }

        $reverse_support = $support;
        $consequent_count = $category_counts[$consequent];
        $reverse_confidence = ($count / $consequent_count) * 100;

        if ($reverse_support >= $min_support * 100 && $reverse_confidence >= $min_confidence * 100) {
            $apriori_rules[] = [
                'rule' => "$consequent -> $antecedent",
                'support' => $reverse_support,
                'confidence' => $reverse_confidence,
                'antecedent' => $consequent,
                'consequent' => $antecedent
            ];
        }
    }
}

// Pagination untuk Daftar Kampanye
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
echo "Menghitung total kampanye...<br>";
$total_campaigns_result = $conn->query("SELECT COUNT(*) as count FROM campaigns");
if ($total_campaigns_result === false) {
    echo "Error query total kampanye: " . $conn->error . "<br>";
} else {
    $total_campaigns = $total_campaigns_result->fetch_assoc()['count'];
    $total_pages = ceil($total_campaigns / $per_page);
    $offset = ($page - 1) * $per_page;
    echo "Mengambil daftar kampanye...<br>";
    $campaigns = $conn->query("SELECT * FROM campaigns LIMIT $offset, $per_page");
    if ($campaigns === false) {
        echo "Error query daftar kampanye: " . $conn->error . "<br>";
    }
}
?>

<div class="bg-white p-6 rounded-lg shadow-lg mb-6">
    <h3 class="text-xl font-semibold mb-4">Daftar Kampanye</h3>
    <?php if ($delete_message): ?>
        <p class="text-<?php echo strpos($delete_message, 'berhasil') !== false ? 'green' : 'red'; ?>-500 mb-4"><?php echo htmlspecialchars($delete_message); ?></p>
    <?php endif; ?>
    <?php if (isset($campaigns) && $campaigns->num_rows > 0): ?>
        <table class="w-full border">
            <thead>
                <tr class="bg-gray-200">
                    <th class="border p-2">Gambar</th>
                    <th class="border p-2">Judul</th>
                    <th class="border p-2">Yayasan</th>
                    <th class="border p-2">Kategori</th>
                    <th class="border p-2">Lokasi</th>
                    <th class="border p-2">Sisa Hari</th>
                    <th class="border p-2">Aturan Apriori</th>
                    <th class="border p-2">Support (%)</th>
                    <th class="border p-2">Confidence (%)</th>
                    <th class="border p-2">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $campaigns->fetch_assoc()): ?>
                    <tr>
                        <td class="border p-2">
                            <?php
                            $image_path = file_exists("images/" . $row['gambar']) ? "/campaign_recommendation/images/" . htmlspecialchars($row['gambar']) . "?t=" . time() : "/campaign_recommendation/images/default.jpg?t=" . time();
                            ?>
                            <img src="<?php echo $image_path; ?>" alt="Gambar" class="w-20 h-20 object-cover">
                        </td>
                        <td class="border p-2"><?php echo htmlspecialchars($row['judul']); ?></td>
                        <td class="border p-2"><?php echo htmlspecialchars($row['yayasan']); ?></td>
                        <td class="border p-2"><?php echo htmlspecialchars($row['kategori']); ?></td>
                        <td class="border p-2"><?php echo htmlspecialchars($row['lokasi']); ?></td>
                        <td class="border p-2"><?php echo htmlspecialchars($row['sisa_hari']); ?></td>
                        <td class="border p-2">
                            <?php
                            $campaign_categories = explode(',', $row['kategori']);
                            $relevant_rules = [];
                            foreach ($apriori_rules as $rule) {
                                if (in_array($rule['antecedent'], $campaign_categories)) {
                                    $relevant_rules[] = $rule;
                                }
                            }
                            if (!empty($relevant_rules)) {
                                foreach ($relevant_rules as $rule) {
                                    echo htmlspecialchars($rule['rule']) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="border p-2">
                            <?php
                            if (!empty($relevant_rules)) {
                                foreach ($relevant_rules as $rule) {
                                    echo number_format($rule['support'], 2) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="border p-2">
                            <?php
                            if (!empty($relevant_rules)) {
                                foreach ($relevant_rules as $rule) {
                                    echo number_format($rule['confidence'], 2) . '<br>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="border p-2">
                            <a href="?section=update&id=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-600">Update</a>
                            <a href="?section=daftar&delete=<?php echo htmlspecialchars($row['id']); ?>" class="inline-block my-1 mx-1 bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600" onclick="return confirm('Apakah Anda yakin ingin menghapus kampanye ini?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="mt-4 flex justify-center space-x-2">
            <?php if ($page > 1): ?>
                <a href="?section=daftar&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Previous</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?section=daftar&page=<?php echo $i; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-300'; ?> rounded hover:bg-gray-400"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?section=daftar&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Next</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>Tidak ada kampanye ditemukan.</p>
    <?php endif; ?>
</div>

<script>
    <?php if ($delete_message): ?>
        document.getElementById('notification').textContent = '<?php echo htmlspecialchars($delete_message); ?>';
        document.getElementById('notification').style.display = 'block';
        setTimeout(() => {
            document.getElementById('notification').style.display = 'none';
        }, 3000);
    <?php endif; ?>
</script>