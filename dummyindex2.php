<?php
session_start();

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Fungsi TF-IDF dan Cosine Similarity
function computeTF($text) {
    $words = str_word_count(strtolower($text), 1);
    $tf = array_count_values($words);
    $total_words = count($words);
    foreach ($tf as &$value) {
        $value /= $total_words;
    }
    return $tf;
}

function computeIDF($conn, $terms, $field = 'deskripsi') {
    $idf = [];
    $total_docs = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
    foreach ($terms as $term) {
        if ($field == 'deskripsi') {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM campaigns WHERE deskripsi LIKE ?");
            $like_term = "%$term%";
            $stmt->bind_param("s", $like_term);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM campaigns WHERE kategori LIKE ?");
            $like_term = "%$term%";
            $stmt->bind_param("s", $like_term);
        }
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
    if ($magnitude1 == 0 && $magnitude2 == 0) return 1;
    return ($magnitude1 * $magnitude2) != 0 ? $dotProduct / ($magnitude1 * $magnitude2) : 0;
}

// Fungsi Apriori
$min_support = 0.1; // 10%
$min_confidence = 0.3; // 30%

function apriori($conn, $min_support, $min_confidence, $filtered_campaign_ids = []) {
    $transactions = [];
    
    if (!empty($filtered_campaign_ids)) {
        $ids = implode(',', array_map('intval', $filtered_campaign_ids));
        $query = "SELECT kategori FROM campaigns WHERE id IN ($ids)";
    } else {
        $query = "SELECT kategori FROM campaigns";
    }
    
    $result = $conn->query($query);
    if (!$result) {
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
            $frequent_items[$item] = ['count' => $count, 'support' => $support];
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
                $pairs[implode(',', $pair)] = ['items' => $pair, 'count' => $count, 'support' => $support];
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
            $rules[] = ['rule' => "$item1 -> $item2", 'support' => $pair_data['support'], 'confidence' => $confidence, 'consequent' => $item2];
        }
        $count_item2 = $item_counts[$item2];
        $confidence_reverse = ($count_pair / $count_item2) * 100;
        if ($confidence_reverse >= ($min_confidence * 100)) {
            $rules[] = ['rule' => "$item2 -> $item1", 'support' => $pair_data['support'], 'confidence' => $confidence_reverse, 'consequent' => $item1];
        }
    }

    $recommendations = [];
    foreach ($rules as $rule) {
        $consequent = $rule['consequent'];
        $query = "SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE kategori LIKE ? AND id IN (" . implode(',', array_map('intval', $filtered_campaign_ids)) . ")";
        $stmt = $conn->prepare($query);
        $like_param = "%$consequent%";
        $stmt->bind_param("s", $like_param);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $categories = array_map('trim', explode(',', $row['kategori']));
            if (in_array($consequent, $categories)) {
                $recommendations[$row['id']] = [
                    'id' => $row['id'],
                    'judul' => $row['judul'],
                    'deskripsi' => $row['deskripsi'],
                    'yayasan' => $row['yayasan'],
                    'kategori' => $row['kategori'],
                    'lokasi' => $row['lokasi'],
                    'sisa_hari' => $row['sisa_hari'],
                    'gambar' => $row['gambar'],
                    'support' => $rule['support'],
                    'confidence' => $rule['confidence'],
                    'similarity' => 0
                ];
            }
        }
        $stmt->close();
    }

    return $recommendations;
}

// Ambil daftar kategori dari tabel categories
$categories = [];
$result = $conn->query("SELECT name FROM categories");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row['name'];
}

// Pencarian kampanye dengan content-based filtering dan Apriori
$search_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$search_deadline = isset($_GET['deadline']) ? (int)$_GET['deadline'] : 0;
$search_location = isset($_GET['location']) ? trim($_GET['location']) : '';

// Pagination
$items_per_page = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

$all_campaigns = [];
$result = $conn->query("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns");
while ($row = $result->fetch_assoc()) {
    $all_campaigns[$row['id']] = [
        'id' => $row['id'],
        'judul' => $row['judul'],
        'deskripsi' => $row['deskripsi'],
        'yayasan' => $row['yayasan'],
        'kategori' => $row['kategori'],
        'lokasi' => $row['lokasi'],
        'sisa_hari' => $row['sisa_hari'],
        'gambar' => $row['gambar'],
        'similarity' => 0,
        'confidence' => 0,
        'support' => 0
    ];
}

// Pencarian dan filtering untuk content-based
$cb_recommendations = [];
$is_search_active = ($search_category || $search_location || $search_deadline > 0);
if ($is_search_active) {
    $query_text = ($search_category ? $search_category . " " : "") . ($search_location ? $search_location : "");
    if (!empty(trim($query_text)) || $search_deadline > 0) {
        $query_desc = $query_text;
        $query_cat = $search_category;

        $query_tf_desc = computeTF($query_desc);
        $query_tf_cat = computeTF($query_cat);
        $query_idf_desc = computeIDF($conn, array_keys($query_tf_desc), 'deskripsi');
        $query_idf_cat = computeIDF($conn, array_keys($query_tf_cat), 'kategori');
        $query_tfidf_desc = computeTFIDF($query_tf_desc, $query_idf_desc);
        $query_tfidf_cat = computeTFIDF($query_tf_cat, $query_idf_cat);

        foreach ($all_campaigns as $campaign_id => $campaign) {
            if ($search_deadline > 0 && $campaign['sisa_hari'] > $search_deadline) {
                continue;
            }
            $campaign_desc = $campaign['deskripsi'];
            $campaign_cat = $campaign['kategori'];
            $campaign_tf_desc = computeTF($campaign_desc);
            $campaign_tf_cat = computeTF($campaign_cat);
            $campaign_idf_desc = computeIDF($conn, array_keys($campaign_tf_desc), 'deskripsi');
            $campaign_idf_cat = computeIDF($conn, array_keys($campaign_tf_cat), 'kategori');
            $campaign_tfidf_desc = computeTFIDF($campaign_tf_desc, $campaign_idf_desc);
            $campaign_tfidf_cat = computeTFIDF($campaign_tf_cat, $campaign_idf_cat);

            $similarity_desc = cosineSimilarity($query_tfidf_desc, $campaign_tfidf_desc);
            $similarity_cat = cosineSimilarity($query_tfidf_cat, $campaign_tfidf_cat);
            $combined_similarity = (0.7 * $similarity_desc) + (0.3 * $similarity_cat);

            if ($search_location && strtolower($campaign['lokasi']) == strtolower($search_location)) {
                $combined_similarity += 0.3;
            }

            if ($combined_similarity > 0 || ($search_category && in_array($search_category, explode(',', $campaign['kategori']))) || ($search_location && strtolower($campaign['lokasi']) == strtolower($search_location))) {
                $cb_recommendations[$campaign_id] = $campaign;
                $cb_recommendations[$campaign_id]['similarity'] = min($combined_similarity, 1);
            }
        }
        usort($cb_recommendations, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
    }
}

// Apriori: Rekomendasi berdasarkan aturan asosiasi, hanya untuk kampanye yang cocok dengan pencarian
$filtered_campaign_ids = array_keys($cb_recommendations);
$apriori_recommendations = [];
if (!empty($filtered_campaign_ids)) {
    $apriori_recommendations = apriori($conn, $min_support, $min_confidence, $filtered_campaign_ids);
}

// Gabungkan semua kampanye berdasarkan status login
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    $donation_based_recommendations = [];
    $donation_stmt = $conn->prepare("SELECT campaign_id FROM donations WHERE user_id = ?");
    $donation_stmt->bind_param("i", $user_id);
    $donation_stmt->execute();
    $donation_result = $donation_stmt->get_result();
    $donated_campaign_ids = [];
    while ($don = $donation_result->fetch_assoc()) {
        $donated_campaign_ids[] = $don['campaign_id'];
    }
    $donation_stmt->close();

    if (!empty($donated_campaign_ids)) {
        $in_clause = implode(',', array_map('intval', $donated_campaign_ids));
        $query = "SELECT c.* FROM campaigns c WHERE c.id NOT IN ($in_clause) AND (c.kategori IN (SELECT kategori FROM campaigns WHERE id IN ($in_clause)) OR c.lokasi IN (SELECT lokasi FROM campaigns WHERE id IN ($in_clause)))";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            if ($search_deadline > 0 && $row['sisa_hari'] > $search_deadline) {
                continue;
            }
            $donation_based_recommendations[$row['id']] = [
                'id' => $row['id'],
                'judul' => $row['judul'],
                'deskripsi' => $row['deskripsi'],
                'yayasan' => $row['yayasan'],
                'kategori' => $row['kategori'],
                'lokasi' => $row['lokasi'],
                'sisa_hari' => $row['sisa_hari'],
                'gambar' => $row['gambar'],
                'similarity' => 0,
                'confidence' => 0,
                'support' => 0
            ];
        }
    }

    $preference_based_recommendations = [];
    $pref_stmt = $conn->prepare("SELECT kategori FROM user_preferences WHERE user_id = ?");
    $pref_stmt->bind_param("i", $user_id);
    $pref_stmt->execute();
    $pref_result = $pref_stmt->get_result();
    if ($pref_result->num_rows > 0) {
        $pref_row = $pref_result->fetch_assoc();
        $user_categories = array_map('trim', explode(',', $pref_row['kategori']));
        foreach ($user_categories as $user_cat) {
            $query = "SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns WHERE kategori LIKE ?";
            $stmt = $conn->prepare($query);
            $like_param = "%$user_cat%";
            $stmt->bind_param("s", $like_param);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ($search_deadline > 0 && $row['sisa_hari'] > $search_deadline) {
                    continue;
                }
                $campaign_categories = array_map('trim', explode(',', $row['kategori']));
                if (in_array($user_cat, $campaign_categories)) {
                    $preference_based_recommendations[$row['id']] = [
                        'id' => $row['id'],
                        'judul' => $row['judul'],
                        'deskripsi' => $row['deskripsi'],
                        'yayasan' => $row['yayasan'],
                        'kategori' => $row['kategori'],
                        'lokasi' => $row['lokasi'],
                        'sisa_hari' => $row['sisa_hari'],
                        'gambar' => $row['gambar'],
                        'similarity' => 0,
                        'confidence' => 0,
                        'support' => 0,
                        'preference_boost' => 0.2
                    ];
                }
            }
            $stmt->close();
        }
    }
    $pref_stmt->close();

    $campaigns = array_merge($cb_recommendations, $apriori_recommendations, $donation_based_recommendations, $preference_based_recommendations, $all_campaigns);
} else {
    $campaigns = $all_campaigns;
    if (!empty($cb_recommendations)) {
        $campaigns = $cb_recommendations;
    }
}

// Gabungkan dan hilangkan duplikat
$unique_campaigns = [];
foreach ($campaigns as $campaign) {
    if (!isset($unique_campaigns[$campaign['id']])) {
        $unique_campaigns[$campaign['id']] = $campaign;
    } else {
        if (isset($campaign['preference_boost'])) {
            $unique_campaigns[$campaign['id']]['preference_boost'] = $campaign['preference_boost'];
        }
        if ($campaign['similarity'] > $unique_campaigns[$campaign['id']]['similarity']) {
            $unique_campaigns[$campaign['id']]['similarity'] = $campaign['similarity'];
        }
        if ($campaign['confidence'] > $unique_campaigns[$campaign['id']]['confidence']) {
            $unique_campaigns[$campaign['id']]['confidence'] = $campaign['confidence'];
            $unique_campaigns[$campaign['id']]['support'] = $campaign['support'];
        }
    }
}
$campaigns = array_values($unique_campaigns);

// Hitung skor gabungan
foreach ($campaigns as &$campaign) {
    $similarity = $campaign['similarity'] ?? 0;
    $confidence = ($campaign['confidence'] ?? 0) / 100;
    $preference_boost = $campaign['preference_boost'] ?? 0;
    $campaign['combined_score'] = ($similarity * 0.5) + ($confidence * 0.3) + $preference_boost;
}
unset($campaign);

// Urutkan berdasarkan skor gabungan
usort($campaigns, function($a, $b) {
    return $b['combined_score'] <=> $a['combined_score'];
});

// Batasi hanya 3 kampanye teratas jika pencarian aktif
if ($is_search_active) {
    $campaigns = array_slice($campaigns, 0, 3);
    $total_items = count($campaigns);
    $total_pages = 1; // Tidak perlu pagination jika hanya 3 item
    $campaigns = array_slice($campaigns, 0, $total_items); // Tidak perlu offset karena hanya 3 item
} else {
    // Pagination untuk tampilan biasa (tanpa pencarian)
    $total_items = count($campaigns);
    $total_pages = ceil($total_items / $items_per_page);
    $campaigns = array_slice($campaigns, $offset, $items_per_page);
}

// Simpan kampanye sebagai acuan jika dipilih
$selected_campaign = '';
if (isset($_GET['select']) && isset($_SESSION['user_id'])) {
    $campaign_id = (int)$_GET['select'];
    $stmt = $conn->prepare("SELECT judul, deskripsi, kategori, lokasi, sisa_hari FROM campaigns WHERE id = ?");
    $stmt->bind_param("i", $campaign_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $selected_campaign = $result->fetch_assoc();
        $_SESSION['selected_campaign'] = $selected_campaign;
    }
    $stmt->close();
}
?>

<?php require 'templatess/header.php'; ?>

<!-- Hero Section dengan Background Full Layar -->
<div class="relative min-h-screen bg-cover bg-center" style="background-image: url('images/mainbg.jpg');">
    <div class="absolute inset-0 bg-black opacity-50"></div>
    <div class="relative z-10">
        <nav class="container mx-auto px-6 py-4"></nav>
        <div class="container mx-auto px-6 py-32 text-center">
            <h1 class="text-5xl font-bold text-white mb-4">Bersama Kita Wujudkan Perubahan</h1>
            <p class="text-xl text-gray-200 mb-8">Bergabunglah dengan ribuan donatur untuk mendukung kampanye yang membawa dampak nyata.</p>
            <div class="flex justify-center space-x-6">
                <a href="#campaigns" id="viewCampaignsBtn" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition">
                    Lihat Kampanye
                </a>
                <button id="searchBtn" class="bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-gray-100 transition">
                    Cari Kampanye
                </button>
            </div>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <p class="text-gray-200 mt-6">Silakan <a href="login.php" class="text-blue-400 hover:underline">login</a> atau <a href="register.php" class="text-blue-400 hover:underline">daftar</a> untuk mendonasikan dana.</p>
            <?php endif; ?>
        </div>
        <div id="searchModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold">Cari Kampanye</h3>
                    <button id="closeSearchBtn" class="text-gray-600 hover:text-gray-800">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form method="GET" class="space-y-4">
                    <div>
                        <label class="block text-gray-700">Kategori</label>
                        <select name="category" class="w-full p-2 border rounded">
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" 
                                        <?php echo ($search_category == $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700">Tenggat Waktu (Hari)</label>
                        <input type="number" name="deadline" value="<?php echo htmlspecialchars($search_deadline); ?>" class="w-full p-2 border rounded" placeholder="Masukkan sisa hari">
                    </div>
                    <div>
                        <label class="block text-gray-700">Lokasi</label>
                        <input type="text" name="location" value="<?php echo htmlspecialchars($search_location); ?>" class="w-full p-2 border rounded" placeholder="Misal: Jakarta">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Cari</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bagian Kampanye di Bawah -->
<div id="campaigns" class="container mx-auto px-6 py-12 bg-gray-100">
    <h2 class="text-3xl font-bold text-center mb-12 text-gray-800">Kampanye Penggalangan Dana</h2>
    <?php if (count($campaigns) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($campaigns as $row): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden transform transition hover:scale-105">
                    <img src="images/<?php echo htmlspecialchars($row['gambar']); ?>" alt="<?php echo htmlspecialchars($row['judul']); ?>" class="w-full h-56 object-cover">
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-3 text-gray-800"><?php echo htmlspecialchars($row['judul']); ?></h3>
                        <p class="text-gray-600 mb-4"><?php echo htmlspecialchars(substr($row['deskripsi'], 0, 100)) . (strlen($row['deskripsi']) > 100 ? '...' : ''); ?></p>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-500"><strong>Yayasan:</strong> <?php echo htmlspecialchars($row['yayasan']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Kategori:</strong> <?php echo htmlspecialchars($row['kategori']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Lokasi:</strong> <?php echo htmlspecialchars($row['lokasi']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Sisa Hari:</strong> <?php echo htmlspecialchars($row['sisa_hari']); ?></p>
                            <?php if ($is_search_active): ?>
                                <p class="text-sm text-gray-500"><strong>Similarity:</strong> <?php echo number_format($row['similarity'], 4); ?></p>
                                <p class="text-sm text-gray-500"><strong>Support:</strong> <?php echo number_format($row['support'], 2); ?>%</p>
                                <p class="text-sm text-gray-500"><strong>Confidence:</strong> <?php echo number_format($row['confidence'], 2); ?>%</p>
                            <?php endif; ?>
                        </div>
                        <div class="mt-4 space-y-2">
                            <a href="<?php echo isset($_SESSION['user_id']) ? 'recommendations.php?select=' . $row['id'] : 'login.php?redirect=index.php'; ?>" class="block bg-blue-600 text-white text-center px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                                <?php echo isset($_SESSION['user_id']) ? 'Pilih sebagai Acuan' : 'Donasi Sekarang'; ?>
                            </a>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="campaign_detail.php?id=<?php echo $row['id']; ?>" class="block bg-green-600 text-white text-center px-4 py-2 rounded-lg hover:bg-green-700 transition">
                                    Donasi Sekarang
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$is_search_active): ?>
            <!-- Pagination hanya ditampilkan jika tidak ada pencarian -->
            <div class="mt-8 flex justify-center items-center space-x-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline > 0 ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline > 0 ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?>" class="px-3 py-1 rounded-lg <?php echo $i == $page ? 'bg-blue-700 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> transition"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline > 0 ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-center text-gray-600">Tidak ada kampanye yang tersedia berdasarkan kriteria pencarian atau rekomendasi.</p>
    <?php endif; ?>
</div>

<!-- JavaScript untuk Menangani Modal Pencarian dan Smooth Scrolling -->
<script>
    const searchBtn = document.getElementById('searchBtn');
    const searchModal = document.getElementById('searchModal');
    const closeSearchBtn = document.getElementById('closeSearchBtn');

    searchBtn.addEventListener('click', () => {
        searchModal.classList.remove('hidden');
    });

    closeSearchBtn.addEventListener('click', () => {
        searchModal.classList.add('hidden');
    });

    searchModal.addEventListener('click', (e) => {
        if (e.target === searchModal) {
            searchModal.classList.add('hidden');
        }
    });

    const viewCampaignsBtn = document.getElementById('viewCampaignsBtn');
    viewCampaignsBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const campaignsSection = document.getElementById('campaigns');
        campaignsSection.scrollIntoView({ behavior: 'smooth' });
    });

    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('category') || urlParams.has('deadline') || urlParams.has('location')) {
            const campaignsSection = document.getElementById('campaigns');
            campaignsSection.scrollIntoView({ behavior: 'smooth' });
        }
    });
</script>

<?php
$conn->close();
require 'templatess/footer.php';
?>