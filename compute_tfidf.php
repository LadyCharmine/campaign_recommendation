<?php
$log_file = 'tfidf_log.txt';
function log_message($message) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    log_message("Error: Koneksi database gagal: " . $conn->connect_error);
    die("Koneksi database gagal: " . $conn->connect_error);
}

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

function computeIDF($conn, $terms) {
    $idf = [];
    $total_docs = $conn->query("SELECT COUNT(*) AS count FROM campaigns")->fetch_assoc()['count'];
    if ($total_docs == 0) return [];
    foreach ($terms as $term) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM campaigns WHERE deskripsi LIKE ?");
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

log_message("Memulai komputasi TF-IDF");
$conn->query("DELETE FROM campaign_tfidf");
$campaigns = [];
$result = $conn->query("SELECT id, deskripsi FROM campaigns");
if (!$result) {
    log_message("Error: Gagal mengambil data campaigns");
    die("Gagal mengambil data campaigns");
}
while ($row = $result->fetch_assoc()) {
    $campaigns[$row['id']] = $row['deskripsi'];
}
log_message("Jumlah kampanye: " . count($campaigns));

$all_terms = [];
foreach ($campaigns as $desc) {
    $terms = array_unique(str_word_count(strtolower($desc), 1));
    $all_terms = array_merge($all_terms, $terms);
}
$all_terms = array_unique($all_terms);
log_message("Jumlah term unik: " . count($all_terms));

if (empty($all_terms)) {
    log_message("Tidak ada term yang ditemukan");
    die("Tidak ada term yang ditemukan");
}

$idf = computeIDF($conn, $all_terms);

foreach ($campaigns as $id => $desc) {
    $tf = computeTF($desc);
    if (empty($tf)) {
        log_message("TF kosong untuk campaign_id: $id");
        continue;
    }
    $tfidf = computeTFIDF($tf, $idf);
    foreach ($tfidf as $term => $value) {
        $stmt = $conn->prepare("INSERT INTO campaign_tfidf (campaign_id, term, tfidf_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tfidf_value = ?");
        $stmt->bind_param("isdd", $id, $term, $value, $value);
        if (!$stmt->execute()) {
            log_message("Error menyimpan TF-IDF untuk campaign_id: $id, term: $term");
        }
        $stmt->close();
    }
}
log_message("Komputasi TF-IDF selesai");

$conn->close();
?>