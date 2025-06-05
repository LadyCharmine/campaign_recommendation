<?php
session_start();

// Jika sudah login, redirect ke index
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

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

$register_error = '';
$show_popup = false;
$category_param = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $kategori = isset($_POST['kategori']) ? implode(',', $_POST['kategori']) : '';

    // Validasi input
    if (empty($username) || empty($password) || empty($kategori)) {
        $register_error = 'Semua field wajib diisi.';
    } else {
        // Cek apakah username sudah ada
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $register_error = 'Username sudah digunakan.';
        } else {
            // Insert ke tabel users
            $role = 'user';
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $password, $role);
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                // Insert ke tabel user_preferences
                $stmt = $conn->prepare("INSERT INTO user_preferences (user_id, kategori) VALUES (?, ?)");
                $stmt->bind_param("is", $user_id, $kategori);
                if ($stmt->execute()) {
                    // Set session untuk user yang baru terdaftar
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;

                    // Hitung rekomendasi berdasarkan preferensi
                    $query_cat = $kategori;
                    $query_tf_cat = computeTF($query_cat);
                    $query_idf_cat = computeIDF($conn, array_keys($query_tf_cat), 'kategori');
                    $query_tfidf_cat = computeTFIDF($query_tf_cat, $query_idf_cat);

                    $campaigns = [];
                    $result = $conn->query("SELECT id, judul, deskripsi, yayasan, kategori, lokasi, sisa_hari, gambar FROM campaigns");
                    while ($row = $result->fetch_assoc()) {
                        $campaign_cat = $row['kategori'];
                        $campaign_tf_cat = computeTF($campaign_cat);
                        $campaign_idf_cat = computeIDF($conn, array_keys($campaign_tf_cat), 'kategori');
                        $campaign_tfidf_cat = computeTFIDF($campaign_tf_cat, $campaign_idf_cat);

                        $similarity_cat = cosineSimilarity($query_tfidf_cat, $campaign_tfidf_cat);
                        if ($similarity_cat > 0) {
                            $campaigns[$row['id']] = [
                                'id' => $row['id'],
                                'judul' => $row['judul'],
                                'deskripsi' => $row['deskripsi'],
                                'yayasan' => $row['yayasan'],
                                'kategori' => $row['kategori'],
                                'lokasi' => $row['lokasi'],
                                'sisa_hari' => $row['sisa_hari'],
                                'gambar' => $row['gambar'],
                                'similarity' => $similarity_cat
                            ];
                        }
                    }
                    usort($campaigns, function($a, $b) {
                        return $b['similarity'] <=> $a['similarity'];
                    });

                    // Simpan rekomendasi di session
                    $_SESSION['initial_recommendations'] = $campaigns;
                    $category_param = urlencode($kategori);
                    $show_popup = true; // Tampilkan pop-up
                } else {
                    $register_error = 'Gagal menyimpan preferensi.';
                }
            } else {
                $register_error = 'Gagal mendaftar. Coba lagi.';
            }
        }
        $stmt->close();
    }
}

// Ambil daftar kategori dari tabel categories
$kategori_list = [];
$result = $conn->query("SELECT name FROM categories");
while ($row = $result->fetch_assoc()) {
    $kategori_list[] = $row['name'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Rekomendasi Kampanye</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #3b82f6 0%, #a855f7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 1rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            padding: 2rem;
            max-width: 28rem;
            width: 100%;
            transition: transform 0.3s ease;
        }
        .form-container:hover {
            transform: translateY(-4px);
        }
        .form-title {
            background: linear-gradient(to right, #2563eb, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .error-message {
            background: #fef2f2;
            border: 1px solid #f87171;
            color: #b91c1c;
            padding: 0.75rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }
        .error-message::before {
            content: '⚠️';
            margin-right: 0.5rem;
        }
        .input-field {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.75rem;
            width: 100%;
            transition: all 0.2s ease;
        }
        .input-field:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .category-grid {
            max-height: 12rem;
            overflow-y: auto;
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            background: #f9fafb;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        .category-grid::-webkit-scrollbar {
            width: 6px;
        }
        .category-grid::-webkit-scrollbar-thumb {
            background: #9ca3af;
            border-radius: 3px;
        }
        .category-label {
            display: flex;
            align-items: center;
            padding: 0.25rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .category-label:hover {
            background: #e5e7eb;
            border-radius: 0.25rem;
        }
        .category-checkbox {
            accent-color: #2563eb;
            margin-right: 0.5rem;
        }
        .submit-button {
            background: linear-gradient(to right, #2563eb, #4f46e5);
            color: white;
            padding: 0.75rem;
            border-radius: 0.5rem;
            width: 100%;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .submit-button:hover {
            background: linear-gradient(to right, #1d4ed8, #4338ca);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .modal {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }
        .modal-content {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            transform: scale(0.8);
            animation: popIn 0.3s ease forwards;
        }
        .modal-button {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .modal-button:hover {
            transform: translateY(-1px);
        }
        .countdown {
            color: #2563eb;
            font-weight: 500;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        @media (max-width: 640px) {
            .form-container {
                padding: 1.5rem;
            }
            .category-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2 class="form-title text-3xl font-bold mb-6 text-center">Daftar Akun</h2>
        <?php if ($register_error): ?>
            <div class="error-message"><?php echo htmlspecialchars($register_error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-5">
                <label class="block text-gray-700 font-medium mb-2">Username</label>
                <input type="text" name="username" class="input-field" required>
            </div>
            <div class="mb-5">
                <label class="block text-gray-700 font-medium mb-2">Password</label>
                <input type="password" name="password" class="input-field" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 font-medium mb-2">Preferensi Kategori Donasi</label>
                <div class="category-grid">
                    <?php foreach ($kategori_list as $kat): ?>
                        <label class="category-label">
                            <input type="checkbox" name="kategori[]" value="<?php echo htmlspecialchars($kat); ?>" class="category-checkbox">
                            <span class="text-gray-700 text-sm"><?php echo htmlspecialchars($kat); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="submit-button">Daftar</button>
        </form>
        <p class="text-center mt-4 text-gray-600 text-sm">Sudah punya akun? <a href="login.php" class="text-blue-600 hover:underline font-medium">Login</a></p>
    </div>

    <?php if ($show_popup): ?>
        <div id="recommendationModal" class="modal fixed inset-0 flex items-center justify-center z-50">
            <div class="modal-content w-full max-w-md text-center">
                <h3 class="text-xl font-bold mb-4 text-blue-700">Selamat Datang!</h3>
                <p class="mb-6 text-gray-600 text-sm">Kami telah menyiapkan rekomendasi kampanye berdasarkan preferensi Anda. Ingin melihatnya sekarang?</p>
                <div class="flex justify-center space-x-4 mb-4">
                    <button id="noThanksBtn" class="modal-button bg-gray-200 text-gray-800 hover:bg-gray-300">Tidak, Terima Kasih</button>
                    <button id="recommendBtn" class="modal-button bg-blue-600 text-white hover:bg-blue-700">Lihat Rekomendasi</button>
                </div>
                <p id="countdown" class="countdown text-sm">10 detik tersisa</p>
            </div>
        </div>

        <script>
            var countdown = 10;
            var countdownInterval = setInterval(function() {
                document.getElementById('countdown').textContent = countdown + ' detik tersisa';
                countdown--;
                if (countdown < 0) {
                    clearInterval(countdownInterval);
                    window.location.href = 'index.php?category=<?php echo $category_param; ?>';
                }
            }, 1000);

            document.getElementById('noThanksBtn').addEventListener('click', function() {
                clearInterval(countdownInterval);
                window.location.href = 'index.php';
            });

            document.getElementById('recommendBtn').addEventListener('click', function() {
                clearInterval(countdownInterval);
                window.location.href = 'index.php?category=<?php echo $category_param; ?>';
            });
        </script>
    <?php endif; ?>
</body>
</html>