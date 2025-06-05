<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=recommendations.php");
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Ambil dan perbarui kampanye yang dipilih sebagai acuan
$selected_campaign = null;
$recalculate = false;
if (isset($_GET['select'])) {
    $campaign_id = (int)$_GET['select'];
    if ($campaign_id > 0) {
        $stmt = $conn->prepare("SELECT id, judul, deskripsi, kategori, lokasi, sisa_hari FROM campaigns WHERE id = ?");
        $stmt->bind_param("i", $campaign_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $selected_campaign = $result->fetch_assoc();
            $_SESSION['selected_campaign'] = $selected_campaign;
            $recalculate = true;
        } else {
            $error_message = "Kampanye dengan ID $campaign_id tidak ditemukan.";
        }
        $stmt->close();
    } else {
        $error_message = "ID kampanye tidak valid.";
    }
} else {
    $selected_campaign = isset($_SESSION['selected_campaign']) ? $_SESSION['selected_campaign'] : null;
    if ($selected_campaign) {
        $recalculate = true;
    }
}

function computeTF($text) {
    $words = str_word_count(strtolower($text), 1);
    $tf = array_count_values($words);
    $total_words = count($words);
    foreach ($tf as &$value) {
        $value /= $total_words;
    }
    return $tf;
}

function computeIDF($conn, $terms) {
    $idf = [];
    $total_docs = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
    foreach ($terms as $term) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM campaigns WHERE deskripsi LIKE ?");
        $like_term = "%$term%";
        $stmt->bind_param("s", $like_term);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $doc_freq = $result['count'];
        $idf[$term] = log($total_docs / ($doc_freq > 0 ? $doc_freq : 1));
        $stmt->close();
    }
    return $idf;
}

function computeTFIDF($tf, $idf) {
    $tfidf = [];
    foreach ($tf as $term => $value) {
        $tfidf[$term] = $value * ($idf[$term] ?? 0);
    }
    return $tfidf;
}

function cosineSimilarity($vec1, $vec2) {
    $dotProduct = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;
    $all_terms = array_unique(array_merge(array_keys($vec1), array_keys($vec2)));

    foreach ($all_terms as $term) {
        $v1 = $vec1[$term] ?? 0;
        $v2 = $vec2[$term] ?? 0;
        $dotProduct += $v1 * $v2;
        $magnitude1 += $v1 * $v1;
        $magnitude2 += $v2 * $v2;
    }

    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);
    return $magnitude1 && $magnitude2 ? $dotProduct / ($magnitude1 * $magnitude2) : 0;
}

// Content-Based Filtering
$cb_recommendations = [];
if ($selected_campaign && $recalculate) {
    // Try using cached TF-IDF
    $stmt = $conn->prepare("SELECT term, tfidf_value FROM campaign_tfidf WHERE campaign_id = ?");
    $stmt->bind_param("i", $selected_campaign['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $query_tfidf = [];
    while ($row = $result->fetch_assoc()) {
        $query_tfidf[$row['term']] = $row['tfidf_value'];
    }
    $stmt->close();

    if (!empty($query_tfidf)) {
        // Cached TF-IDF available
        $result = $conn->query("SELECT c.id, c.judul, c.deskripsi, c.yayasan, c.kategori, c.lokasi, c.sisa_hari, c.gambar, t.term, t.tfidf_value 
                                FROM campaigns c 
                                LEFT JOIN campaign_tfidf t ON c.id = t.campaign_id 
                                WHERE c.id != " . $conn->real_escape_string($selected_campaign['id']));
        if ($result) {
            $campaign_tfidf = [];
            while ($row = $result->fetch_assoc()) {
                $campaign_id = $row['id'];
                if (!isset($campaign_tfidf[$campaign_id])) {
                    $campaign_tfidf[$campaign_id] = [
                        'id' => $row['id'],
                        'judul' => $row['judul'],
                        'deskripsi' => $row['deskripsi'],
                        'yayasan' => $row['yayasan'],
                        'kategori' => $row['kategori'],
                        'lokasi' => $row['lokasi'],
                        'sisa_hari' => $row['sisa_hari'],
                        'gambar' => $row['gambar'],
                        'tfidf' => []
                    ];
                }
                if ($row['term']) {
                    $campaign_tfidf[$campaign_id]['tfidf'][$row['term']] = $row['tfidf_value'];
                }
            }

            foreach ($campaign_tfidf as $campaign) {
                $similarity = cosineSimilarity($query_tfidf, $campaign['tfidf']);
                if ($similarity > 0) {
                    $cb_recommendations[] = [
                        'id' => $campaign['id'],
                        'judul' => $campaign['judul'],
                        'deskripsi' => $campaign['deskripsi'],
                        'yayasan' => $campaign['yayasan'],
                        'kategori' => $campaign['kategori'],
                        'lokasi' => $campaign['lokasi'],
                        'sisa_hari' => $campaign['sisa_hari'],
                        'gambar' => $campaign['gambar'],
                        'similarity' => $similarity
                    ];
                }
            }
            usort($cb_recommendations, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });
            $cb_recommendations = array_slice($cb_recommendations, 0, 3);
        } else {
            $error_message = "Gagal mengambil data kampanye untuk content-based filtering.";
        }
    } else {
        // Fallback to original method
        $query_tfidf = computeTFIDF(computeTF($selected_campaign['deskripsi']), computeIDF($conn, array_keys(computeTF($selected_campaign['deskripsi']))));
        $result = $conn->query("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE id != " . $conn->real_escape_string($selected_campaign['id']));
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $campaign_tfidf = computeTFIDF(computeTF($row['deskripsi']), computeIDF($conn, array_keys(computeTF($row['deskripsi']))));
                $similarity = cosineSimilarity($query_tfidf, $campaign_tfidf);
                if ($similarity > 0) {
                    $cb_recommendations[] = [
                        'id' => $row['id'],
                        'judul' => $row['judul'],
                        'deskripsi' => $row['deskripsi'],
                        'yayasan' => $row['yayasan'],
                        'kategori' => $row['kategori'],
                        'lokasi' => $row['lokasi'],
                        'sisa_hari' => $row['sisa_hari'],
                        'gambar' => $row['gambar'],
                        'similarity' => $similarity
                    ];
                }
            }
            usort($cb_recommendations, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });
            $cb_recommendations = array_slice($cb_recommendations, 0, 3);
        } else {
            $error_message = "Gagal mengambil data kampanye untuk content-based filtering.";
        }
    }
}

// Apriori Recommendations
$apriori_recommendations = [];
$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT itemset, support, confidence, rule FROM apriori_rules WHERE (processed_by = $user_id OR processed_by IS NULL) AND confidence IS NOT NULL ORDER BY confidence DESC, support DESC LIMIT 10");
if ($result && $result->num_rows > 0) {
    while ($rule = $result->fetch_assoc()) {
        // Extract consequent from rule (e.g., "Kesehatan -> Sosial" => "Sosial")
        $rule_parts = explode(' -> ', $rule['rule'] ?? '');
        $consequent = !empty($rule_parts[1]) ? trim($rule_parts[1]) : end(explode(',', $rule['itemset']));
        $query = "SELECT id, judul, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE kategori LIKE ?";
        $stmt = $conn->prepare($query);
        $like_param = "%$consequent%";
        $stmt->bind_param("s", $like_param);
        $stmt->execute();
        $campaign_result = $stmt->get_result();
        while ($row = $campaign_result->fetch_assoc()) {
            $campaign_categories = array_map('trim', explode(',', $row['kategori']));
            if (in_array($consequent, $campaign_categories)) {
                $apriori_recommendations[] = [
                    'judul' => $row['judul'],
                    'yayasan' => $row['yayasan'],
                    'kategori' => $row['kategori'],
                    'lokasi' => $row['lokasi'],
                    'sisa_hari' => $row['sisa_hari'],
                    'gambar' => $row['gambar'],
                    'support' => $rule['support'] * 100, // Convert to percentage
                    'confidence' => $rule['confidence'] * 100, // Convert to percentage
                    'rule' => $rule['rule'] ?? $rule['itemset']
                ];
            }
        }
        $stmt->close();
    }
} else {
    // Fallback to original Apriori method
    $min_support = 0.1;
    $min_confidence = 0.3;

    function apriori($conn, $min_support, $min_confidence) {
        $transactions = [];
        $result = $conn->query("SELECT kategori FROM campaigns");
        if (!$result) {
            echo "Error: Gagal mengambil data kategori dari campaigns.";
            return [];
        }

        while ($row = $result->fetch_assoc()) {
            $categories = array_map('trim', explode(',', $row['kategori']));
            if (!empty($categories) && count($categories) > 1) {
                $transactions[] = $categories;
            }
        }
        $total_transactions = count($transactions);
        if ($total_transactions == 0) {
            echo "Error: Tidak ada transaksi kategori yang ditemukan.";
            return [];
        }

        $item_counts = [];
        foreach ($transactions as $transaction) {
            foreach ($transaction as $item) {
                $item_counts[$item] = ($item_counts[$item] ?? 0) + 1;
            }
        }

        $frequent_items = [];
        foreach ($item_counts as $item => $count) {
            $support = ($count / $total_transactions) * 100;
            if ($support >= ($min_support * 100)) {
                $frequent_items[$item] = [
                    'count' => $count,
                    'support' => $support
                ];
            }
        }

        $pairs = [];
        $items = array_keys($frequent_items);
        for ($i = 0; $i < count($items); $i++) {
            for ($j = $i + 1; $j < count($items); $j++) {
                $pair = [$items[$i], $items[$j]];
                sort($pair);
                $count = 0;
                foreach ($transactions as $transaction) {
                    $transaction_set = array_flip($transaction);
                    if (isset($transaction_set[$pair[0]]) && isset($transaction_set[$pair[1]])) {
                        $count++;
                    }
                }
                $support = ($count / $total_transactions) * 100;
                if ($support >= ($min_support * 100)) {
                    $pairs[implode(',', $pair)] = [
                        'items' => $pair,
                        'count' => $count,
                        'support' => $support
                    ];
                }
            }
        }

        $rules = [];
        foreach ($pairs as $pair_key => $pair_data) {
            $items = $pair_data['items'];
            $item1 = $items[0];
            $item2 = $items[1];
            $count_pair = $pair_data['count'];
            $count_item1 = $item_counts[$item1];
            $confidence = ($count_pair / $count_item1) * 100;
            if ($confidence >= ($min_confidence * 100)) {
                $rules[] = [
                    'rule' => "$item1 -> $item2",
                    'support' => $pair_data['support'],
                    'confidence' => $confidence,
                    'consequent' => $item2
                ];
            }

            $count_item2 = $item_counts[$item2];
            $confidence_reverse = ($count_pair / $count_item2) * 100;
            if ($confidence_reverse >= ($min_confidence * 100)) {
                $rules[] = [
                    'rule' => "$item2 -> $item1",
                    'support' => $pair_data['support'],
                    'confidence' => $confidence_reverse,
                    'consequent' => $item1
                ];
            }
        }

        $recommendations = [];
        foreach ($rules as $rule) {
            $consequent = $rule['consequent'];
            $query = "SELECT id, judul, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE kategori LIKE ?";
            $stmt = $conn->prepare($query);
            $like_param = "%$consequent%";
            $stmt->bind_param("s", $like_param);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $categories = array_map('trim', explode(',', $row['kategori']));
                if (in_array($consequent, $categories)) {
                    $recommendations[] = [
                        'judul' => $row['judul'],
                        'yayasan' => $row['yayasan'],
                        'kategori' => $row['kategori'],
                        'lokasi' => $row['lokasi'],
                        'sisa_hari' => $row['sisa_hari'],
                        'gambar' => $row['gambar'],
                        'support' => $rule['support'],
                        'confidence' => $rule['confidence'],
                        'rule' => $rule['rule']
                    ];
                }
            }
            $stmt->close();
        }

        return $recommendations;
    }

    $apriori_recommendations = apriori($conn, $min_support, $min_confidence);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekomendasi Kampanye</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <?php require 'templatess/header.php'; ?>

    <div class="container mx-auto p-6">
        <h2 class="text-2xl font-bold mb-4">Rekomendasi Kampanye</h2>

        <?php if (isset($error_message)): ?>
            <p class="text-red-500 mb-4"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>

        <h3 class="text-xl font-semibold mb-2">Content-Based Filtering (Cosine Similarity)</h3>
        <?php if ($selected_campaign): ?>
            <p class="mb-4">Acuan: <?php echo htmlspecialchars($selected_campaign['judul']); ?></p>
            <?php if ($cb_recommendations): ?>
                <table class="w-full border mb-6">
                    <thead>
                        <tr class="bg-gray-200">
                            <th class="border p-2">Gambar</th>
                            <th class="border p-2">Judul</th>
                            <th class="border p-2">Deskripsi</th>
                            <th class="border p-2">Yayasan</th>
                            <th class="border p-2">Kategori</th>
                            <th class="border p-2">Lokasi</th>
                            <th class="border p-2">Sisa Hari</th>
                            <th class="border p-2">Similarity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cb_recommendations as $rec): ?>
                            <tr>
                                <td class="border p-2"><img src="images/<?php echo htmlspecialchars($rec['gambar']); ?>" alt="Gambar" class="w-20 h-20 object-cover"></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['judul']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['deskripsi']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['yayasan']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['kategori']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['lokasi']); ?></td>
                                <td class="border p-2"><?php echo htmlspecialchars($rec['sisa_hari']); ?></td>
                                <td class="border p-2"><?php echo number_format($rec['similarity'], 4); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Tidak ada rekomendasi Content-Based ditemukan.</p>
            <?php endif;
        else: ?>
            <p>Pilih kampanye sebagai acuan di halaman utama terlebih dahulu.</p>
        <?php endif; ?>

        <h3 class="text-xl font-semibold mb-2">Rekomendasi Apriori</h3>
        <?php if ($apriori_recommendations): ?>
            <p class="mb-4">Minimum Support: <?php echo $min_support * 100; ?>%, Minimum Confidence: <?php echo $min_confidence * 100; ?>%</p>
            <table class="w-full border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border p-2">Gambar</th>
                        <th class="border p-2">Judul</th>
                        <th class="border p-2">Yayasan</th>
                        <th class="border p-2">Kategori</th>
                        <th class="border p-2">Lokasi</th>
                        <th class="border p-2">Sisa Hari</th>
                        <th class="border p-2">Aturan</th>
                        <th class="border p-2">Support (%)</th>
                        <th class="border p-2">Confidence (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apriori_recommendations as $rec): ?>
                        <tr>
                            <td class="border p-2"><img src="images/<?php echo htmlspecialchars($rec['gambar']); ?>" alt="Gambar" class="w-20 h-20 object-cover"></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['judul']); ?></td>
                            <td class="border p-2"><?php echo htmlspecialchars($rec['yayasan']); ?></td>
<td class="border p-2"><?php echo htmlspecialchars($rec['kategori']); ?></td>
<td class="border p-2"><?php echo htmlspecialchars($rec['lokasi']); ?></td>
<td class="border p-2"><?php echo htmlspecialchars($rec['sisa_hari']); ?></td>
<td class="border p-2"><?php echo htmlspecialchars($rec['rule']); ?></td>
<td class="text-right border p-2"><?php echo number_format($rec['support'], 2); ?></td>
<td class="text-right border p-2"><?php echo number_format($rec['confidence'], 2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p>Tidak ada rekomendasi Apriori ditemukan.</p>
<?php endif; ?>

        <div class="mt-4">
            <a href="index.php" class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Kembali</a>
        </div>
    </div>

    <?php
    $conn->close();
    require 'templatess/footer.php';
    ?>
</body>
</html>