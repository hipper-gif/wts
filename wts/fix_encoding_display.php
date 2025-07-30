<?php
// 文字エンコーディング修正スクリプト
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    echo "<h2>🔧 輸送分類別表示修正</h2>";
    
    // 現在のデータの文字エンコーディング確認
    echo "<h3>📋 現在の輸送分類データ確認</h3>";
    
    $check_sql = "SELECT 
        transport_category,
        transport_type,
        COUNT(*) as count,
        HEX(transport_category) as category_hex,
        HEX(transport_type) as type_hex
        FROM ride_records 
        WHERE ride_date >= CURDATE() - INTERVAL 7 DAY
        GROUP BY transport_category, transport_type
        ORDER BY count DESC";
    
    $stmt = $pdo->prepare($check_sql);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>transport_category</th><th>transport_type</th><th>件数</th><th>category_hex</th><th>type_hex</th></tr>";
    
    foreach ($data as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['transport_category'] ?? 'NULL', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['transport_type'] ?? 'NULL', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . $row['count'] . "</td>";
        echo "<td>" . ($row['category_hex'] ?? 'NULL') . "</td>";
        echo "<td>" . ($row['type_hex'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 修正提案
    echo "<h3>🔧 修正提案</h3>";
    
    $has_category_data = false;
    $has_type_data = false;
    
    foreach ($data as $row) {
        if (!empty($row['transport_category'])) $has_category_data = true;
        if (!empty($row['transport_type'])) $has_type_data = true;
    }
    
    if ($has_category_data && $has_type_data) {
        echo "<p>⚠️ transport_category と transport_type の両方にデータがあります</p>";
        echo "<p>📝 transport_category を優先して使用することを推奨します</p>";
        
        // データ統一処理
        echo "<h4>データ統一処理</h4>";
        $update_sql = "UPDATE ride_records 
                      SET transport_category = COALESCE(transport_category, transport_type)
                      WHERE transport_category IS NULL OR transport_category = ''";
        $pdo->exec($update_sql);
        echo "<p>✅ transport_category にデータを統一しました</p>";
        
    } elseif ($has_type_data && !$has_category_data) {
        echo "<p>📝 transport_type のデータを transport_category に移行します</p>";
        
        $migrate_sql = "UPDATE ride_records 
                       SET transport_category = transport_type
                       WHERE transport_category IS NULL OR transport_category = ''";
        $pdo->exec($migrate_sql);
        echo "<p>✅ transport_type から transport_category にデータを移行しました</p>";
    }
    
    // 修正後の確認
    echo "<h3>📊 修正後の輸送分類データ</h3>";
    $final_check_sql = "SELECT 
        transport_category,
        COUNT(*) as count,
        SUM(passenger_count) as passengers,
        SUM(fare + COALESCE(charge, 0)) as revenue
        FROM ride_records 
        WHERE ride_date >= CURDATE() - INTERVAL 7 DAY
        AND transport_category IS NOT NULL 
        AND transport_category != ''
        GROUP BY transport_category
        ORDER BY count DESC";
    
    $stmt = $pdo->prepare($final_check_sql);
    $stmt->execute();
    $final_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>輸送分類</th><th>回数</th><th>人数</th><th>売上</th></tr>";
    
    foreach ($final_data as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['transport_category'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . $row['count'] . "</td>";
        echo "<td>" . $row['passengers'] . "</td>";
        echo "<td>¥" . number_format($row['revenue']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>🎉 修正完了</h3>";
    echo "<p>✅ 文字エンコーディングとデータの問題を修正しました</p>";
    echo "<p>🔗 <a href='ride_records.php'>ride_records.php で確認してください</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ エラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}
?>
