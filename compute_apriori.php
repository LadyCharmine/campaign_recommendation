<?php
session_start();

$log_file = 'apriori_log.txt';
function log_message($message) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

$conn = new mysqli('localhost', 'root', '', 'campaign_recommendation');
if ($conn->connect_error) {
    log_message("Error: Koneksi database gagal: " . $conn->connect_error);
    die("Koneksi database gagal: " . $conn->connect_error);
}

function apriori($conn, $min_support, $min_confidence, $user_id = null) {
    log_message("Memulai proses Apriori dengan min_support=$min_support, min_confidence=$min_confidence");
    $transactions = [];
    $result = $conn->query("SELECT id, kategori FROM campaigns");
    if (!$result) {
        log_message("Error: Gagal mengambil data kategori");
        return;
    }
    while ($row = $result->fetch_assoc()) {
        $categories = array_map('trim', explode(',', $row['kategori']));
        if (!empty($categories)) {
            $transactions[$row['id']] = $categories;
        }
    }
    $total_transactions = count($transactions);
    log_message("Total transaksi: $total_transactions");
    if ($total_transactions == 0) {
        log_message("Tidak ada transaksi kategori");
        return;
    }

    // Langkah 1: Hitung support untuk setiap item (Rumus 3.4)
    $item_counts = [];
    foreach ($transactions as $transaction) {
        foreach ($transaction as $item) {
            $item_counts[$item] = ($item_counts[$item] ?? 0) + 1;
        }
    }

    $frequent_items = [];
    foreach ($item_counts as $item => $count) {
        $support = $count / $total_transactions; // Rumus 3.4 (dalam desimal)
        if ($support >= $min_support) {
            $frequent_items[$item] = ['count' => $count, 'support' => $support];
        }
    }
    log_message("Itemset frequent: " . count($frequent_items));

    // Langkah 2: Hitung support untuk pasangan item (Rumus 3.5)
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
            $support = $count / $total_transactions; // Rumus 3.5 (dalam desimal)
            if ($support >= $min_support) {
                $pairs[implode(',', $pair)] = [
                    'items' => $pair,
                    'count' => $count,
                    'support' => $support
                ];
            }
        }
    }
    log_message("Pasangan frequent: " . count($pairs));

    // Langkah 3: Hitung confidence untuk setiap aturan (Rumus 3.6)
    $rules = [];
    foreach ($pairs as $pair_key => $pair_data) {
        $items = $pair_data['items'];
        $item1 = $items[0];
        $item2 = $items[1];
        $count_pair = $pair_data['count'];
        $count_item1 = $item_counts[$item1];
        $confidence = $count_pair / $count_item1; // Rumus 3.6 (dalam desimal)
        if ($confidence >= $min_confidence) {
            $rules[] = [
                'itemset' => implode(',', $items),
                'support' => $pair_data['support'],
                'confidence' => $confidence,
                'rule' => "$item1 -> $item2"
            ];
        }

        $count_item2 = $item_counts[$item2];
        $confidence_reverse = $count_pair / $count_item2;
        if ($confidence_reverse >= $min_confidence) {
            $rules[] = [
                'itemset' => implode(',', $items),
                'support' => $pair_data['support'],
                'confidence' => $confidence_reverse,
                'rule' => "$item2 -> $item1"
            ];
        }
    }
    log_message("Jumlah aturan: " . count($rules));

    // Langkah 4: Simpan aturan ke database
    $conn->query("DELETE FROM apriori_rules WHERE processed_by = " . ($user_id ?? 'NULL'));
    foreach ($rules as $rule) {
        $stmt = $conn->prepare("INSERT INTO apriori_rules (itemset, support, confidence, rule, processed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sddsi", $rule['itemset'], $rule['support'], $rule['confidence'], $rule['rule'], $user_id);
        if (!$stmt->execute()) {
            log_message("Error menyimpan aturan: $rule[rule]");
        }
        $stmt->close();
    }
    log_message("Proses Apriori selesai");
}

$min_support = isset($_SESSION['min_support']) ? $_SESSION['min_support'] : 0.2;
$min_confidence = isset($_SESSION['min_confidence']) ? $_SESSION['min_confidence'] : 0.5;
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
apriori($conn, $min_support, $min_confidence, $user_id);
$conn->close();
?>