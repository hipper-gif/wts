<?php
/**
 * 福祉輸送管理システム - rawリンク集自動生成
 * URL: https://tw1nkle.com/Smiley/taxi/wts/raw_links_generator.php
 * 目的: Claude用の最新rawリンク集を自動生成
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - rawリンク集</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3><i class="fas fa-link"></i> 福祉輸送管理システム - rawリンク集</h3>
                        <small>最終更新: <?= date('Y-m-d H:i:s') ?></small>
                    </div>
                    <div class="card-body">
                        
                        <!-- システム状態チェック -->
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle"></i> システム状態確認</h5>
                            <p><strong>JSON形式:</strong> <a href="system_status_check.php" target="_blank" class="btn btn-sm btn-outline-primary">system_status_check.php</a></p>
                            <p><strong>使用方法:</strong> Claudeがこのリンクにアクセスして最新状況を確認</p>
                        </div>

                        <?php
                        // ファイル構成の定義
                        $file_structure = [
                            '🔐 基盤システム' => [
                                'index.php' => 'ログイン画面',
                                'dashboard.php' => 'ダッシュボード',
                                'logout.php' => 'ログアウト処理'
                            ],
                            '🎯 点呼・点検システム' => [
                                'pre_duty_call.php' => '乗務前点呼',
                                'post_duty_call.php' => '乗務後点呼', 
                                'daily_inspection.php' => '日常点検',
                                'periodic_inspection.php' => '定期点検（3ヶ月）'
                            ],
                            '🚀 運行管理システム' => [
                                'departure.php' => '出庫処理',
                                'arrival.php' => '入庫処理',
                                'ride_records.php' => '乗車記録管理'
                            ],
                            '💰 集金・報告機能' => [
                                'cash_management.php' => '集金管理',
                                'annual_report.php' => '陸運局提出',
                                'accident_management.php' => '事故管理'
                            ],
                            '🚨 緊急監査対応' => [
                                'emergency_audit_kit.php' => '緊急監査対応キット',
                                'adaptive_export_document.php' => '適応型出力システム',
                                'audit_data_manager.php' => '監査データ一括管理'
                            ],
                            '👥 マスタ管理' => [
                                'user_management.php' => 'ユーザー管理',
                                'vehicle_management.php' => '車両管理',
                                'master_menu.php' => 'マスターメニュー'
                            ],
                            '🔧 システム管理・診断' => [
                                'check_table_structure.php' => 'テーブル構造確認',
                                'fix_table_structure.php' => 'テーブル構造修正',
                                'system_status_check.php' => 'システム状態確認',
                                'safe_check.php' => '安全性確認'
                            ]
                        ];

                        $github_base = 'https://raw.githubusercontent.com/hipper-gif/wts/main/wts/';
                        $server_base = 'https://tw1nkle.com/Smiley/taxi/wts/';
                        ?>

                        <!-- ファイル一覧 -->
                        <?php foreach ($file_structure as $category => $files): ?>
                        <div class="mb-4">
                            <h4><?= $category ?></h4>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>ファイル</th>
                                            <th>機能</th>
                                            <th>GitHub Raw</th>
                                            <th>サーバー</th>
                                            <th>状態</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($files as $filename => $description): ?>
                                        <tr>
                                            <td><code><?= $filename ?></code></td>
                                            <td><?= $description ?></td>
                                            <td>
                                                <a href="<?= $github_base . $filename ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fab fa-github"></i> Raw
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?= $server_base . $filename ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-external-link-alt"></i> Live
                                                </a>
                                            </td>
                                            <td>
                                                <?php
                                                $exists = file_exists($filename);
                                                if ($exists) {
                                                    $size = round(filesize($filename) / 1024, 1);
                                                    $modified = date('m/d H:i', filemtime($filename));
                                                    echo "<span class='badge bg-success'>✓ {$size}KB</span><br><small>{$modified}</small>";
                                                } else {
                                                    echo "<span class='badge bg-warning'>未確認</span>";
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Claude用コピペ形式 -->
                        <div class="card mt-4">
                            <div class="card-header bg-success text-white">
                                <h5><i class="fas fa-robot"></i> Claude用 - コピペ形式</h5>
                            </div>
                            <div class="card-body">
                                <textarea class="form-control" rows="10" readonly onclick="this.select();">
# 福祉輸送管理システム - 最新rawリンク集

## システム状態確認
- **JSON形式チェック**: <?= $server_base ?>system_status_check.php

## 主要機能ファイル
<?php foreach ($file_structure as $category => $files): ?>

### <?= $category ?>

<?php foreach ($files as $filename => $description): ?>
- **<?= $description ?>**: <?= $github_base . $filename ?>

<?php endforeach; ?>
<?php endforeach; ?>

## 使用方法
1. system_status_check.php で現在の状況確認
2. 必要なファイルのrawリンクにアクセス
3. 実際のコードを確認して分析
                                </textarea>
                                <button class="btn btn-sm btn-primary mt-2" onclick="navigator.clipboard.writeText(document.querySelector('textarea').value)">
                                    <i class="fas fa-copy"></i> クリップボードにコピー
                                </button>
                            </div>
                        </div>

                        <!-- 更新履歴 -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5><i class="fas fa-history"></i> 自動更新機能</h5>
                            </div>
                            <div class="card-body">
                                <p>このページは以下の情報を自動で更新します：</p>
                                <ul>
                                    <li>ファイルの存在確認</li>
                                    <li>ファイルサイズ</li>
                                    <li>最終更新日時</li>
                                    <li>rawリンクの生成</li>
                                </ul>
                                <button class="btn btn-primary" onclick="location.reload()">
                                    <i class="fas fa-sync"></i> 最新情報に更新
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
