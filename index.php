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
    if ($total_words == 0) return [];
    foreach ($tf as &$value) {
        $value /= $total_words;
    }
    return $tf;
}

function computeIDF($conn, $terms, $field = 'deskripsi') {
    $idf = [];
    $total_docs = $conn->query("SELECT COUNT(*) as count FROM campaigns")->fetch_assoc()['count'];
    if ($total_docs == 0) return [];
    foreach ($terms as $term) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM campaigns WHERE $field LIKE ?");
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
        $tfidf[$term] = $value * (isset($idf[$term]) ? $idf[$term] : 0);
    }
    return $tfidf;
}

function cosineSimilarity($vec1, $vec2) {
    $dotProduct = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;
    $all_terms = array_unique(array_merge(array_keys($vec1), array_keys($vec2)));
    foreach ($all_terms as $term) {
        $v1 = isset($vec1[$term]) ? $vec1[$term] : 0;
        $v2 = isset($vec2[$term]) ? $vec2[$term] : 0;
        $dotProduct += $v1 * $v2;
        $magnitude1 += $v1 * $v1;
        $magnitude2 += $v2 * $v2;
    }
    $magnitude = sqrt($magnitude1) * sqrt($magnitude2);
    return $magnitude ? $dotProduct / $magnitude : 0;
}

// Ambil daftar kategori
$categories = [];
$result = $conn->query("SELECT name FROM categories");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row['name'];
}

// Pencarian kampanye
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$search_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$search_deadline = isset($_GET['deadline']) ? (int)$_GET['deadline'] : 0;
$search_location = isset($_GET['location']) ? trim($_GET['location']) : '';
$search_budget = isset($_GET['budget']) ? trim($_GET['budget']) : '';

// Pagination
$items_per_page = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Ambil semua kampanye dan rating ahli dalam satu query
$all_campaigns = [];
$expert_ratings = [];
$rating_result = $conn->query("SELECT campaign_id, AVG(rating) as avg_rating FROM expert_ratings GROUP BY campaign_id");
while ($row = $rating_result->fetch_assoc()) {
    $expert_ratings[$row['campaign_id']] = $row['avg_rating'] / 5;
}
$result = $conn->query("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar, terkumpul FROM campaigns");
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
        'terkumpul' => $row['terkumpul'],
        'similarity' => 0,
        'expert_rating' => isset($expert_ratings[$row['id']]) ? $expert_ratings[$row['id']] : 0
    ];
}

// Pencarian content-based dengan caching TF-IDF
$cb_recommendations = [];
$is_search_active = ($search_keyword || $search_category || $search_location || $search_deadline > 0 || $search_budget);
if ($is_search_active) {
    $query_text = trim($search_keyword . ' ' . $search_category . ' ' . $search_location);
    if (!empty($query_text) || $search_deadline > 0) {
        // Hitung TF-IDF untuk query
        $query_tf_desc = computeTF($query_text);
        $query_tf_cat = computeTF($search_category);
        $all_terms_desc = array_keys($query_tf_desc);
        $all_terms_cat = array_keys($query_tf_cat);
        $query_idf_desc = computeIDF($conn, $all_terms_desc, 'deskripsi');
        $query_idf_cat = computeIDF($conn, $all_terms_cat, 'kategori');
        $query_tfidf_desc = computeTFIDF($query_tf_desc, $query_idf_desc);
        $query_tfidf_cat = computeTFIDF($query_tf_cat, $query_idf_cat);

        // Ambil TF-IDF kampanye dari tabel caching
        $campaign_tfidf = [];
        $result = $conn->query("SELECT campaign_id, term, tfidf_value FROM campaign_tfidf");
        $tfidf_exists = $result && $result->num_rows > 0;
        if ($tfidf_exists) {
            while ($row = $result->fetch_assoc()) {
                $campaign_id = $row['campaign_id'];
                if (!isset($campaign_tfidf[$campaign_id])) {
                    $campaign_tfidf[$campaign_id] = [];
                }
                $campaign_tfidf[$campaign_id][$row['term']] = $row['tfidf_value'];
            }

            foreach ($all_campaigns as $campaign_id => $campaign) {
                if ($search_deadline > 0 && $campaign['sisa_hari'] !== '∞' && (int)$campaign['sisa_hari'] > $search_deadline) {
                    continue;
                }
                $campaign_tfidf_desc = isset($campaign_tfidf[$campaign_id]) ? $campaign_tfidf[$campaign_id] : [];
                $campaign_tfidf_cat = computeTFIDF(computeTF($campaign['kategori']), computeIDF($conn, array_keys(computeTF($campaign['kategori'])), 'kategori'));

                $similarity_desc = cosineSimilarity($query_tfidf_desc, $campaign_tfidf_desc);
                $similarity_cat = cosineSimilarity($query_tfidf_cat, $campaign_tfidf_cat);
                $combined_similarity = (0.7 * $similarity_desc) + (0.3 * $similarity_cat);

                if ($search_location && strtolower($campaign['lokasi']) === strtolower($search_location)) {
                    $combined_similarity += 0.3;
                }

                if ($combined_similarity >= 0.0100) {
                    $cb_recommendations[$campaign_id] = $campaign;
                    $cb_recommendations[$campaign_id]['similarity'] = min($combined_similarity, 1);
                }
            }
        } else {
            // Fallback ke perhitungan manual jika campaign_tfidf kosong
            foreach ($all_campaigns as $campaign_id => $campaign) {
                if ($search_deadline > 0 && $campaign['sisa_hari'] !== '∞' && (int)$campaign['sisa_hari'] > $search_deadline) {
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

                if ($search_location && strtolower($campaign['lokasi']) === strtolower($search_location)) {
                    $combined_similarity += 0.3;
                }

                if ($combined_similarity >= 0.0100) {
                    $cb_recommendations[$campaign_id] = $campaign;
                    $cb_recommendations[$campaign_id]['similarity'] = min($combined_similarity, 1);
                }
            }
        }
        usort($cb_recommendations, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
    }
}

// Rekomendasi berdasarkan preferensi pengguna
$campaigns = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Ambil preferensi budget pengguna
    $pref_stmt = $conn->prepare("SELECT kategori, budget_daily, budget_weekly, budget_monthly FROM user_preferences WHERE user_id = ?");
    $pref_stmt->bind_param("i", $user_id);
    $pref_stmt->execute();
    $pref_result = $pref_stmt->get_result();
    $user_budget = ['daily' => 0, 'weekly' => 0, 'monthly' => 0];
    $user_categories = [];
    if ($pref_result->num_rows > 0) {
        $pref_row = $pref_result->fetch_assoc();
        $user_categories = array_map('trim', explode(',', $pref_row['kategori']));
        $user_budget['daily'] = $pref_row['budget_daily'];
        $user_budget['weekly'] = $pref_row['budget_weekly'];
        $user_budget['monthly'] = $pref_row['budget_monthly'];
    }
    $pref_stmt->close();

    if ($is_search_active) {
        $campaigns = $cb_recommendations;
    } else {
        // Rekomendasi berdasarkan donasi sebelumnya
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
            $query = "SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar, terkumpul FROM campaigns 
                      WHERE id NOT IN ($in_clause) 
                      AND (kategori IN (SELECT kategori FROM campaigns WHERE id IN ($in_clause)) 
                           OR lokasi IN (SELECT lokasi FROM campaigns WHERE id IN ($in_clause)))";
            $result = $conn->query($query);
            while ($row = $result->fetch_assoc()) {
                $donation_based_recommendations[$row['id']] = [
                    'id' => $row['id'],
                    'judul' => $row['judul'],
                    'deskripsi' => $row['deskripsi'],
                    'yayasan' => $row['yayasan'],
                    'kategori' => $row['kategori'],
                    'lokasi' => $row['lokasi'],
                    'sisa_hari' => $row['sisa_hari'],
                    'gambar' => $row['gambar'],
                    'terkumpul' => $row['terkumpul'],
                    'similarity' => 0,
                    'expert_rating' => isset($expert_ratings[$row['id']]) ? $expert_ratings[$row['id']] : 0
                ];
            }
        }

        // Rekomendasi berdasarkan kategori preferensi
        $preference_based_recommendations = [];
        foreach ($user_categories as $user_cat) {
            $query = "SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar, terkumpul FROM campaigns WHERE kategori LIKE ?";
            $stmt = $conn->prepare($query);
            $like_param = "%$user_cat%";
            $stmt->bind_param("s", $like_param);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $preference_based_recommendations[$row['id']] = [
                    'id' => $row['id'],
                    'judul' => $row['judul'],
                    'deskripsi' => $row['deskripsi'],
                    'yayasan' => $row['yayasan'],
                    'kategori' => $row['kategori'],
                    'lokasi' => $row['lokasi'],
                    'sisa_hari' => $row['sisa_hari'],
                    'gambar' => $row['gambar'],
                    'terkumpul' => $row['terkumpul'],
                    'similarity' => 0,
                    'preference_boost' => 0.2,
                    'expert_rating' => isset($expert_ratings[$row['id']]) ? $expert_ratings[$row['id']] : 0
                ];
            }
            $stmt->close();
        }

        // Filter berdasarkan budget
        $budget_filtered_recommendations = [];
        if ($search_budget) {
            $budget_field = "budget_$search_budget";
            if ($user_budget[$search_budget] > 0) {
                foreach ($all_campaigns as $campaign_id => $campaign) {
                    $assumed_donation = 100000;
                    if ($assumed_donation <= $user_budget[$search_budget]) {
                        $budget_filtered_recommendations[$campaign_id] = $campaign;
                    }
                }
            }
        }

        $campaigns = array_merge($cb_recommendations, $donation_based_recommendations, $preference_based_recommendations, $budget_filtered_recommendations, $all_campaigns);
    }
} else {
    $campaigns = $is_search_active ? $cb_recommendations : $all_campaigns;
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
        if (isset($campaign['expert_rating']) && (!isset($unique_campaigns[$campaign['id']]['expert_rating']) || $campaign['expert_rating'] > $unique_campaigns[$campaign['id']]['expert_rating'])) {
            $unique_campaigns[$campaign['id']]['expert_rating'] = $campaign['expert_rating'];
        }
        if (isset($campaign['terkumpul'])) {
            $unique_campaigns[$campaign['id']]['terkumpul'] = $campaign['terkumpul'];
        }
    }
}
$campaigns = array_values($unique_campaigns);

// Pastikan semua kampanye memiliki expert_rating
foreach ($campaigns as &$campaign) {
    if (!isset($campaign['expert_rating'])) {
        $campaign['expert_rating'] = 0;
    }
}

// Hitung skor gabungan
foreach ($campaigns as &$cargo) {
    $similarity = isset($cargo['similarity']) ? $cargo['similarity'] : 0;
    $preference_boost = isset($cargo['preference_boost']) ? $cargo['preference_boost'] : 0;
    $expert_rating = isset($cargo['expert_rating']) ? $cargo['expert_rating'] : 0;
    $cargo['combined_score'] = ($similarity * 0.5) + ($preference_boost * 0.25) + ($expert_rating * 0.25);
}
unset($cargo);

// Urutkan berdasarkan skor
usort($campaigns, function($a, $b) {
    return $b['combined_score'] <=> $a['combined_score'];
});

// Pagination
$total_items = count($campaigns);
$total_pages = ceil($total_items / $items_per_page);
$campaigns = array_slice($campaigns, $offset, $items_per_page);

// Simpan kampanye acuan
$selected_campaign = '';
if (isset($_GET['select']) && isset($_SESSION['user_id'])) {
    $campaign_id = (int)$_GET['select'];
    $stmt = $conn->prepare("SELECT judul, deskripsi, kategori, lokasi, sisa_hari, terkumpul FROM campaigns WHERE id = ?");
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

<!-- Hero Section -->
<div class="relative min-h-screen bg-cover bg-center" style="background-image: url('images/mainbg.jpg');">
    <div class="absolute inset-0 bg-black opacity-50"></div>
    <div class="relative z-10">
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
            <!-- Form Pencarian -->
            <div id="searchForm" class="mt-8 max-w-3xl mx-auto bg-white bg-opacity-90 rounded-lg p-6 shadow-lg hidden">
                <h3 class="text-xl font-semibold mb-4 text-gray-700">Cari Kampanye</h3>
                <form id="searchFormElement" method="GET" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium">Kata Kunci</label>
                        <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="Masukkan kata kunci">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium">Kategori</label>
                        <select name="category" class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600">
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($search_category == $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium">Tenggat Waktu (Hari)</label>
                        <input type="number" name="deadline" value="<?php echo htmlspecialchars($search_deadline); ?>" class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="Masukkan sisa hari">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium">Lokasi</label>
                        <input type="text" name="location" value="<?php echo htmlspecialchars($search_location); ?>" class="w-full p-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="Masal: Jakarta">
                    </div>
                    <div class="md:col-span-2 flex justify-center space-x-4">
                        <button type="submit" id="submitSearchBtn" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">Cari</button>
                        <button type="button" id="cancelSearchBtn" class="w-full bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition">Batal</button>
                    </div>
                </form>
            </div>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <p class="text-gray-200 mt-6">Silakan <a href="login.php" class="text-blue-400 hover:underline">login</a> atau <a href="register.php" class="text-blue-400 hover:underline">daftar</a> untuk mendonasikan dana.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bagian Kampanye -->
<div id="campaigns" class="container mx-auto px-6 py-12 bg-gray-100">
    <h2 class="text-3xl font-bold text-center mb-12">Kampanye Penggalangan Dana</h2>
    <?php if (!empty($campaigns)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($campaigns as $row): ?>
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <img src="images/<?php echo htmlspecialchars($row['gambar']); ?>" alt="<?php echo htmlspecialchars($row['judul']); ?>" class="w-full h-48 object-cover">
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($row['judul']); ?></h3>
                        <p class="text-gray-600 mb-4"><?php echo htmlspecialchars(substr($row['deskripsi'], 0, 100)) . (strlen($row['deskripsi']) > 100 ? '...' : ''); ?></p>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-500"><strong>Yayasan:</strong> <?php echo htmlspecialchars($row['yayasan']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Kategori:</strong> <?php echo htmlspecialchars($row['kategori']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Lokasi:</strong> <?php echo htmlspecialchars($row['lokasi']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Sisa Hari:</strong> <?php echo htmlspecialchars($row['sisa_hari']); ?></p>
                            <p class="text-sm text-gray-500"><strong>Terkumpul:</strong> Rp <?php echo number_format($row['terkumpul'] ? $row['terkumpul'] : 0, 0, ',', '.'); ?></p>
                            <?php if ($is_search_active): ?>
                                <p class="text-sm text-gray-500"><strong>Similarity:</strong> <?php echo number_format($row['similarity'], 4); ?></p>
                                <p class="text-sm text-gray-500"><strong>Expert Rating:</strong> <?php echo number_format($row['expert_rating'] * 5, 2); ?>/5</p>
                            <?php endif; ?>
                        </div>
                        <div class="mt-4 space-y-2">
                            <a href="<?php echo isset($_SESSION['user_id']) ? 'recommendations.php?select=' . $row['id'] : 'login.php?redirect=index.php'; ?>" class="block w-full bg-blue-600 text-white font-medium rounded-lg px-4 py-2 text-center hover:bg-blue-700 transition">
                                <?php echo isset($_SESSION['user_id']) ? 'Pilih sebagai Acuan' : 'Donasi Sekarang'; ?>
                            </a>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="donation_detail.php?id=<?php echo $row['id']; ?>" class="block w-full bg-green-600 text-white font-medium rounded-lg px-4 py-2 text-center hover:bg-green-700 transition">
                                    Lihat Detail Donasi
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <div class="mt-8 flex justify-between items-center space-x-2">
            <div class="flex items-center space-x-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $search_keyword ? '&keyword=' . urlencode($search_keyword) : ''; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline > 0 ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?><?php echo $search_budget ? '&budget=' . urlencode($search_budget) : ''; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Previous</a>
                <?php endif; ?>
            </div>
            <div class="flex space-x-2">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search_keyword ? '&keyword=' . urlencode($search_keyword) : ''; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?><?php echo $search_budget ? '&budget=' . urlencode($search_budget) : ''; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg transition"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <div class="flex items-center space-x-2">
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $search_keyword ? '&keyword=' . urlencode($search_keyword) : ''; ?><?php echo $search_category ? '&category=' . urlencode($search_category) : ''; ?><?php echo $search_deadline > 0 ? '&deadline=' . $search_deadline : ''; ?><?php echo $search_location ? '&location=' . urlencode($search_location) : ''; ?><?php echo $search_budget ? '&budget=' . urlencode($search_budget) : ''; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <p class="text-center text-gray-600">Tidak ada kampanye yang tersedia berdasarkan kriteria pencarian.</p>
    <?php endif; ?>
</div>

<!-- JavaScript -->
<script>
    const searchBtn = document.getElementById('searchBtn');
    const searchForm = document.getElementById('searchForm');
    const cancelSearchBtn = document.getElementById('cancelSearchBtn');
    const viewCampaignsBtn = document.getElementById('viewCampaignsBtn');
    const submitSearchBtn = document.getElementById('submitSearchBtn');

    // Toggle search form visibility
    searchBtn.addEventListener('click', () => {
        searchForm.classList.toggle('hidden');
    });

    // Hide search form when cancel is clicked
    cancelSearchBtn.addEventListener('click', () => {
        searchForm.classList.add('hidden');
    });

    // Scroll to campaigns section
    viewCampaignsBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const campaignsSection = document.getElementById('campaigns');
        campaignsSection.scrollIntoView({ behavior: 'smooth' });
    });

    // Handle form submission
    submitSearchBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const form = document.getElementById('searchFormElement');
        if (form.checkValidity()) {
            form.submit();
        } else {
            form.reportValidity();
        }
    });

    // Show form if search parameters are present
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('keyword') || urlParams.has('category') || urlParams.has('deadline') || urlParams.has('location') || urlParams.has('budget')) {
            searchForm.classList.remove('hidden');
            const campaignsSection = document.getElementById('campaigns');
            campaignsSection.scrollIntoView({ behavior: 'smooth' });
        }
    });
</script>

<?php
$conn->close();
require 'templatess/footer.php';
?>