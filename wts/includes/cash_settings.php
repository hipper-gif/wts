<!-- 設定管理 -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-cog me-2"></i>現金管理設定</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_cash_settings">
                    
                    <div class="mb-3">
                        <label class="form-label">差額許容範囲</label>
                        <div class="input-group">
                            <span class="input-group-text">±</span>
                            <input type="number" class="form-control" name="tolerance_amount" 
                                   value="100" placeholder="100">
                            <span class="input-group-text">円</span>
                        </div>
                        <small class="text-muted">この範囲内の差額は警告を表示しません</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">自動確認期限</label>
                        <select class="form-select" name="auto_confirm_days">
                            <option value="1">翌日</option>
                            <option value="3" selected>3日後</option>
                            <option value="7">1週間後</option>
                            <option value="0">自動確認しない</option>
                        </select>
                        <small class="text-muted">この期間を過ぎると自動的に確認済みとします</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="email_notification" 
                                   name="email_notification" checked>
                            <label class="form-check-label" for="email_notification">
                                差額発生時にメール通知
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>設定保存
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-file-export me-2"></i>出力設定</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_export_settings">
                    
                    <div class="mb-3">
                        <label class="form-label">デフォルト出力形式</label>
                        <select class="form-select" name="default_export_format">
                            <option value="pdf">PDF</option>
                            <option value="excel" selected>Excel</option>
                            <option value="csv">CSV</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">レポートテンプレート</label>
                        <select class="form-select" name="report_template">
                            <option value="standard" selected>標準テンプレート</option>
                            <option value="detailed">詳細テンプレート</option>
                            <option value="summary">サマリーテンプレート</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_charts" 
                                   name="include_charts" checked>
                            <label class="form-check-label" for="include_charts">
                                グラフを含める
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>設定保存
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- データ管理 -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-database me-2"></i>データ管理</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6>データバックアップ</h6>
                                <p class="text-muted">現金確認データをバックアップします</p>
                                <button type="button" class="btn btn-outline-warning" onclick="backupData()">
                                    <i class="fas fa-download me-1"></i>バックアップ実行
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h6>データ復元</h6>
                                <p class="text-muted">バックアップファイルからデータを復元</p>
                                <button type="button" class="btn btn-outline-info" onclick="restoreData()">
                                    <i class="fas fa-upload me-1"></i>復元実行
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <h6>データクリーンアップ</h6>
                                <p class="text-muted">古いデータを削除してパフォーマンス向上</p>
                                <button type="button" class="btn btn-outline-danger" onclick="cleanupData()">
                                    <i class="fas fa-trash me-1"></i>クリーンアップ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- システム情報 -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-info-circle me-2"></i>システム情報</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th>現金確認テーブル</th>
                                <td>
                                    <?php
                                    try {
                                        $stmt = $pdo->query("SELECT COUNT(*) FROM cash_confirmations");
                                        $count = $stmt->fetchColumn();
                                        echo "<span class='badge bg-success'>{$count}件</span>";
                                    } catch (Exception $e) {
                                        echo "<span class='badge bg-danger'>エラー</span>";
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>最新確認日</th>
                                <td>
                                    <?php
                                    try {
                                        $stmt = $pdo->query("SELECT MAX(confirmation_date) FROM cash_confirmations");
                                        $latest = $stmt->fetchColumn();
                                        echo $latest ? date('Y/m/d', strtotime($latest)) : '未確認';
                                    } catch (Exception $e) {
                                        echo 'エラー';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>未確認日数</th>
                                <td>
                                    <?php
                                    try {
                                        $stmt = $pdo->query("
                                            SELECT COUNT(DISTINCT DATE(ride_date)) 
                                            FROM ride_records r
                                            LEFT JOIN cash_confirmations c ON DATE(r.ride_date) = c.confirmation_date
                                            WHERE c.id IS NULL AND DATE(r.ride_date) < CURDATE()
                                        ");
                                        $unconfirmed = $stmt->fetchColumn();
                                        $class = $unconfirmed > 0 ? 'bg-warning' : 'bg-success';
                                        echo "<span class='badge {$class}'>{$unconfirmed}日</span>";
                                    } catch (Exception $e) {
                                        echo "<span class='badge bg-danger'>エラー</span>";
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th>差額件数（今月）</th>
                                <td>
                                    <?php
                                    try {
                                        $stmt = $pdo->query("
                                            SELECT COUNT(*) 
                                            FROM cash_confirmations 
                                            WHERE difference != 0 
                                            AND DATE_FORMAT(confirmation_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                                        ");
                                        $diff_count = $stmt->fetchColumn();
                                        $class = $diff_count > 0 ? 'bg-warning' : 'bg-success';
                                        echo "<span class='badge {$class}'>{$diff_count}件</span>";
                                    } catch (Exception $e) {
                                        echo "<span class='badge bg-danger'>エラー</span>";
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>総差額（今月）</th>
                                <td>
                                    <?php
                                    try {
                                        $stmt = $pdo->query("
                                            SELECT SUM(difference) 
                                            FROM cash_confirmations 
                                            WHERE DATE_FORMAT(confirmation_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                                        ");
                                        $total_diff = $stmt->fetchColumn() ?? 0;
                                        $class = getDifferenceClass($total_diff);
                                        echo "<span class='badge bg-info {$class}'>" . formatAmount($total_diff) . "</span>";
                                    } catch (Exception $e) {
                                        echo "<span class='badge bg-danger'>エラー</span>";
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>バージョン</th>
                                <td><span class="badge bg-primary">v2.0</span></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- アクションログ -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-history me-2"></i>最近のアクション</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>日時</th>
                                <th>ユーザー</th>
                                <th>アクション</th>
                                <th>詳細</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $stmt = $pdo->query("
                                    SELECT 
                                        cc.created_at,
                                        u.name,
                                        '現金確認' as action,
                                        CONCAT('差額: ', cc.difference, '円') as details
                                    FROM cash_confirmations cc
                                    LEFT JOIN users u ON cc.confirmed_by = u.id
                                    ORDER BY cc.created_at DESC
                                    LIMIT 10
                                ");
                                $recent_actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($recent_actions as $action): ?>
                                    <tr>
                                        <td><?= date('m/d H:i', strtotime($action['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($action['name']) ?></td>
                                        <td><span class="badge bg-info"><?= $action['action'] ?></span></td>
                                        <td><?= htmlspecialchars($action['details']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            } catch (Exception $e) {
                                echo '<tr><td colspan="4" class="text-center text-muted">ログデータの取得に失敗しました</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 診断・修復ツール -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning">
                <h6><i class="fas fa-tools me-2"></i>診断・修復ツール</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-warning w-100" onclick="checkDataIntegrity()">
                            <i class="fas fa-search me-1"></i><br>データ整合性チェック
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-info w-100" onclick="rebuildIndexes()">
                            <i class="fas fa-sync me-1"></i><br>インデックス再構築
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-success w-100" onclick="optimizeTables()">
                            <i class="fas fa-rocket me-1"></i><br>テーブル最適化
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-primary w-100" onclick="generateTestData()">
                            <i class="fas fa-flask me-1"></i><br>テストデータ生成
                        </button>
                    </div>
                </div>
                <div class="mt-3">
                    <small class="text-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        これらのツールは慎重に使用してください。必要に応じてバックアップを取ってから実行してください。
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
