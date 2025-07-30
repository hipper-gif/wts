<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>🔍 トリガー・ビュー詳細調査</h2>";
    
    // 1. トリガー確認
    echo "<h3>📋 ride_recordsテーブルのトリガー:</h3>";
    $stmt = $pdo->query("SHOW TRIGGERS LIKE 'ride_records'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>❌ トリガーが見つかりません</p>";
        
        // 全トリガー確認
        echo "<h3>📋 データベース内の全トリガー:</h3>";
        $stmt = $pdo->query("
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT
            FROM information_schema.TRIGGERS 
            WHERE TRIGGER_SCHEMA = 'twinklemark_wts'
        ");
        $all_triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($all_triggers)) {
            echo "<p>❌ データベース内にトリガーはありません</p>";
        } else {
            foreach ($all_triggers as $trigger) {
                echo "<div style='border:1px solid #ccc; padding:10px; margin:10px 0;'>";
                echo "<strong>トリガー名:</strong> " . htmlspecialchars($trigger['TRIGGER_NAME']) . "<br>";
                echo "<strong>イベント:</strong> " . htmlspecialchars($trigger['EVENT_MANIPULATION']) . "<br>";
                echo "<strong>テーブル:</strong> " . htmlspecialchars($trigger['EVENT_OBJECT_TABLE']) . "<br>";
                echo "<strong>処理内容:</strong><br>";
                echo "<pre>" . htmlspecialchars($trigger['ACTION_STATEMENT']) . "</pre>";
                echo "</div>";
            }
        }
    } else {
        foreach ($triggers as $trigger) {
            echo "<div style='border:1px solid red; padding:10px; margin:10px 0; background:#ffe6e6;'>";
            echo "<strong>🚨 問題のトリガー発見:</strong><br>";
            echo "<strong>トリガー名:</strong> " . htmlspecialchars($trigger['Trigger']) . "<br>";
            echo "<strong>イベント:</strong> " . htmlspecialchars($trigger['Event']) . "<br>";
            echo "<strong>タイミング:</strong> " . htmlspecialchars($trigger['Timing']) . "<br>";
            echo "<strong>処理内容:</strong><br>";
            echo "<pre>" . htmlspecialchars($trigger['Statement']) . "</pre>";
            echo "</div>";
        }
    }
    
    // 2. ビュー確認
    echo "<h3>📋 ビューの確認:</h3>";
    $stmt = $pdo->query("SHOW FULL TABLES IN twinklemark_wts WHERE Table_type = 'VIEW'");
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($views)) {
        echo "<p>❌ ビューは見つかりません</p>";
    } else {
        foreach ($views as $view) {
            echo "<p>🔍 ビュー発見: " . htmlspecialchars($view['Tables_in_twinklemark_wts']) . "</p>";
            
            // ビューの定義を取得
            $view_name = $view['Tables_in_twinklemark_wts'];
            $stmt2 = $pdo->query("SHOW CREATE VIEW `{$view_name}`");
            $view_def = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            echo "<div style='border:1px solid blue; padding:10px; margin:10px 0; background:#e6f3ff;'>";
            echo "<strong>ビュー定義:</strong><br>";
            echo "<pre>" . htmlspecialchars($view_def['Create View']) . "</pre>";
            echo "</div>";
        }
    }
    
    // 3. 外部キー制約確認
    echo "<h3>📋 外部キー制約:</h3>";
    $stmt = $pdo->query("
        SELECT 
            CONSTRAINT_NAME,
            TABLE_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'twinklemark_wts'
        AND TABLE_NAME = 'ride_records'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $foreign_keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($foreign_keys)) {
        echo "<p>❌ 外部キー制約はありません</p>";
    } else {
        foreach ($foreign_keys as $fk) {
            echo "<p>🔗 外部キー: {$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
