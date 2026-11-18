<?php

// データベース接続
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    error_log("data_management DB error: " . $e->getMessage());
    header('Location: index.php?error=db');
    exit;
}

// 管理者権限チェック（$user_role は session_check.php で設定済み）
$is_admin = ($user_role === 'Admin');
if (!$is_admin) {
    header('Location: dashboard.php?error=permission_denied');
    exit();
}

// サンプル扱いの対象になる記録（表 => [日付の列, 画面での呼び名]）。この画面の全操作がここを見る
const SAMPLE_TABLES = [
    'ride_records'         => ['ride_date', '乗車記録'],
    'daily_inspections'    => ['inspection_date', '日常点検'],
    'pre_duty_calls'       => ['call_date', '乗務前点呼'],
    'post_duty_calls'      => ['call_date', '乗務後点呼'],
    'departure_records'    => ['departure_date', '出庫記録'],
    'arrival_records'      => ['arrival_date', '入庫記録'],
    'periodic_inspections' => ['inspection_date', '定期点検'],
];
// 消す順（参照している側を先に）と、親と一緒に消える子の表（控えに一緒に写す）
const SAMPLE_DELETE_ORDER = ['ride_records', 'arrival_records', 'departure_records', 'post_duty_calls',
                             'pre_duty_calls', 'daily_inspections', 'periodic_inspections'];
const SAMPLE_CHILD_TABLES = [
    'ride_records'         => ['ride_waypoints', 'ride_record_id'],
    'periodic_inspections' => ['periodic_inspection_items', 'inspection_id'],
];
const DELETE_CONFIRM_WORD = '削除';

$message = '';
$error = '';
$preview = null; // 実行前の確かめ（件数）を出す時だけ入る

// 実行後は画面を出し直してから知らせる（再読込で同じ操作がもう1回走らないように）
if (!empty($_SESSION['data_management_flash'])) {
    $message = $_SESSION['data_management_flash'];
    unset($_SESSION['data_management_flash']);
}

// ---- 関数定義 ----

function dmTableExists($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function dmHasSampleFlag($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'is_sample_data'");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

/** サンプルの印（is_sample_data 列）がまだ無い表 */
function dmTablesMissingFlag($pdo) {
    $missing = [];
    foreach (SAMPLE_TABLES as $table => $def) {
        if (dmTableExists($pdo, $table) && !dmHasSampleFlag($pdo, $table)) $missing[] = $table;
    }
    return $missing;
}

function dmAddSampleFlags($pdo) {
    foreach (dmTablesMissingFlag($pdo) as $table) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN is_sample_data BOOLEAN DEFAULT FALSE");
    }
}

function dmValidDate($value, $label) {
    $value = trim((string)$value);
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . 'を入力してください。');
    }
    return $value;
}

/**
 * 操作の対象になる行の条件を返す。件数の確かめと実行の両方がこれを使う（同じ決まりは1か所）。
 * @param string $op mark=サンプル扱いにする / unmark=サンプル扱いを外す / delete=サンプル扱いの記録を消す
 * @return array [where句, パラメータ]
 */
function dmTargetCondition($op, $date_column, $start, $end) {
    switch ($op) {
        case 'mark':
            return ["`{$date_column}` BETWEEN ? AND ? AND (is_sample_data = FALSE OR is_sample_data IS NULL)", [$start, $end]];
        case 'unmark':
            return ["`{$date_column}` BETWEEN ? AND ? AND is_sample_data = TRUE", [$start, $end]];
        case 'delete':
            return ["is_sample_data = TRUE", []];
    }
    throw new InvalidArgumentException('操作を受け付けられませんでした。');
}

/** 対象件数（表ごと）と、対象のうち一番新しい日付 */
function dmCountTargets($pdo, $op, $start = null, $end = null) {
    $counts = [];
    $newest = null;
    foreach (SAMPLE_TABLES as $table => [$date_column, $label]) {
        if (!dmTableExists($pdo, $table)) continue;
        [$where, $params] = dmTargetCondition($op, $date_column, $start, $end);
        $stmt = $pdo->prepare("SELECT COUNT(*), MAX(`{$date_column}`) FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        [$n, $max] = $stmt->fetch(PDO::FETCH_NUM);
        $counts[$table] = (int)$n;
        if ($max !== null && ($newest === null || $max > $newest)) $newest = $max;
    }
    return ['counts' => $counts, 'total' => array_sum($counts), 'newest' => $newest];
}

function dmCountsText(array $counts) {
    $parts = [];
    foreach ($counts as $table => $n) {
        if ($n > 0) $parts[] = SAMPLE_TABLES[$table][1] . ' ' . number_format($n) . '件';
    }
    return $parts ? implode('、', $parts) : '0件';
}

/** サンプル扱いにする／外す。変えた件数（表ごと）を返す。呼ぶ側がトランザクションを張る */
function dmSetSampleFlag($pdo, $op, $start, $end) {
    $changed = [];
    foreach (SAMPLE_TABLES as $table => [$date_column, $label]) {
        if (!dmTableExists($pdo, $table)) continue;
        [$where, $params] = dmTargetCondition($op, $date_column, $start, $end);
        $value = ($op === 'mark') ? 'TRUE' : 'FALSE';
        $stmt = $pdo->prepare("UPDATE `{$table}` SET is_sample_data = {$value} WHERE {$where}");
        $stmt->execute($params);
        $changed[$table] = $stmt->rowCount();
    }
    return $changed;
}

/** サンプル扱いの記録を、控えへ写してから消す。消した件数（表ごと）を返す。呼ぶ側がトランザクションを張る */
function dmDeleteSampleData($pdo, $batch_id, $user_id) {
    $deleted = [];
    foreach (SAMPLE_DELETE_ORDER as $table) {
        if (!dmTableExists($pdo, $table)) continue;
        // 親と一緒に消える子の行（経由地・点検項目）も控える
        if (isset(SAMPLE_CHILD_TABLES[$table])) {
            [$child, $fk] = SAMPLE_CHILD_TABLES[$table];
            if (dmTableExists($pdo, $child)) {
                archiveRowsBeforeDelete($pdo, $child, "`{$fk}` IN (SELECT id FROM `{$table}` WHERE is_sample_data = TRUE)", [], 'サンプルデータ削除', $user_id, $batch_id);
            }
        }
        archiveRowsBeforeDelete($pdo, $table, 'is_sample_data = TRUE', [], 'サンプルデータ削除', $user_id, $batch_id);
        $deleted[$table] = $pdo->exec("DELETE FROM `{$table}` WHERE is_sample_data = TRUE");
    }
    return $deleted;
}

function dmExportRealData($pdo) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="real_data_backup_' . date('Y-m-d_H-i-s') . '.json"');
    $export_data = [];
    foreach (['users', 'vehicles'] as $table) {
        $export_data[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (SAMPLE_TABLES as $table => $def) {
        if (!dmTableExists($pdo, $table)) continue;
        $export_data[$table] = $pdo->query("SELECT * FROM `{$table}` WHERE is_sample_data = FALSE OR is_sample_data IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function dmStatistics($pdo) {
    $stats = [];
    foreach (SAMPLE_TABLES as $table => [$date_column, $label]) {
        if (!dmTableExists($pdo, $table)) continue;
        if (dmHasSampleFlag($pdo, $table)) {
            $row = $pdo->query("SELECT COUNT(*) AS total, COUNT(CASE WHEN is_sample_data = TRUE THEN 1 END) AS sample FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $stats[$table] = ['label' => $label, 'total' => (int)$row['total'], 'sample' => (int)$row['sample'], 'has_sample_flag' => true];
        } else {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $stats[$table] = ['label' => $label, 'total' => $total, 'sample' => 0, 'has_sample_flag' => false];
        }
        $stats[$table]['real'] = $stats[$table]['total'] - $stats[$table]['sample'];
    }
    return $stats;
}

/**
 * 画面から来た操作を、対象の期間つきの形にそろえる。
 * production=「本番運用開始日」より前を全部サンプル扱いにする（mark の期間指定が違うだけ）
 */
function dmResolveOperation(array $in) {
    $op = $in['op'] ?? '';
    switch ($op) {
        case 'mark':
        case 'unmark':
            $start = dmValidDate($in['start_date'] ?? '', '開始日');
            $end = dmValidDate($in['end_date'] ?? '', '終了日');
            if ($start > $end) {
                throw new InvalidArgumentException('開始日が終了日より後になっています。');
            }
            return ['op' => $op, 'flag_op' => $op, 'start' => $start, 'end' => $end, 'production_start_date' => null];
        case 'production':
            $production = dmValidDate($in['production_start_date'] ?? '', '本番運用開始日');
            $end = date('Y-m-d', strtotime($production . ' -1 day'));
            return ['op' => $op, 'flag_op' => 'mark', 'start' => '1900-01-01', 'end' => $end, 'production_start_date' => $production];
        case 'delete':
            return ['op' => $op, 'flag_op' => 'delete', 'start' => null, 'end' => null, 'production_start_date' => null];
    }
    throw new InvalidArgumentException('操作を受け付けられませんでした。もう一度やり直してください。');
}

// ---- POST処理 ----
// どの操作も「件数を確かめる（preview）」→「実行（execute）」の2段。preview は何も変えない。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'export_real_data') {
            logAudit($pdo, 0, 'create', $user_id, 'data_management', [], '実務データのエクスポート');
            dmExportRealData($pdo);
        }

        if ($action === 'add_sample_flag') {
            dmAddSampleFlags($pdo);
            logAudit($pdo, 0, 'edit', $user_id, 'data_management', [], 'サンプルの印を付ける準備（列の追加）');
            $_SESSION['data_management_flash'] = '準備ができました。';
            header('Location: data_management.php');
            exit;
        }

        if ($action !== 'preview' && $action !== 'execute') {
            throw new InvalidArgumentException('操作を受け付けられませんでした。もう一度やり直してください。');
        }
        if (dmTablesMissingFlag($pdo)) {
            throw new InvalidArgumentException('先に「準備」を実行してください。');
        }

        $o = dmResolveOperation($_POST);
        $target = dmCountTargets($pdo, $o['flag_op'], $o['start'], $o['end']);

        if ($action === 'preview') {
            // 本番運用開始日の記録だけは、対象が0件でも実行できる（開始日を記録するため）
            if ($target['total'] === 0 && $o['op'] !== 'production') {
                $none = ['mark' => 'この期間に、サンプル扱いにできる記録はありません。',
                         'unmark' => 'この期間に、サンプル扱いの記録はありません。',
                         'delete' => 'サンプル扱いの記録はありません。消すものはありません。'];
                throw new InvalidArgumentException($none[$o['op']]);
            }
            $preview = $o + $target;
        } else {
            if ($o['op'] === 'delete' && trim($_POST['confirm_word'] ?? '') !== DELETE_CONFIRM_WORD) {
                $preview = $o + $target;
                throw new InvalidArgumentException('確認の欄に「' . DELETE_CONFIRM_WORD . '」と入力してください。まだ何も消していません。');
            }
            $pdo->beginTransaction();
            try {
                if ($o['op'] === 'delete') {
                    $batch_id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
                    $done = dmDeleteSampleData($pdo, $batch_id, $user_id);
                    $text = 'サンプル扱いの記録を消しました（' . dmCountsText($done) . '）。消した記録の控えは残してあります（控え番号 ' . $batch_id . '）。';
                    $reason = 'サンプルデータ削除: ' . dmCountsText($done) . ' / 控え番号 ' . $batch_id;
                    $audit_action = 'delete';
                } else {
                    $done = dmSetSampleFlag($pdo, $o['flag_op'], $o['start'], $o['end']);
                    $audit_action = 'edit';
                    if ($o['op'] === 'production') {
                        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('production_start_date', ?)
                                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                        $stmt->execute([$o['production_start_date']]);
                        $text = '本番運用開始日を ' . $o['production_start_date'] . ' にしました。それより前の記録をサンプル扱いにしました（' . dmCountsText($done) . '）。';
                        $reason = '本番運用開始日 ' . $o['production_start_date'] . ' / サンプル扱いにした: ' . dmCountsText($done);
                    } elseif ($o['op'] === 'mark') {
                        $text = $o['start'] . ' ～ ' . $o['end'] . ' の記録をサンプル扱いにしました（' . dmCountsText($done) . '）。';
                        $reason = 'サンプル扱いにした ' . $o['start'] . '～' . $o['end'] . ': ' . dmCountsText($done);
                    } else {
                        $text = $o['start'] . ' ～ ' . $o['end'] . ' の記録のサンプル扱いを外しました（' . dmCountsText($done) . '）。';
                        $reason = 'サンプル扱いを外した ' . $o['start'] . '～' . $o['end'] . ': ' . dmCountsText($done);
                    }
                }
                logAudit($pdo, 0, $audit_action, $user_id, 'data_management', [], mb_substr($reason, 0, 250));
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $_SESSION['data_management_flash'] = $text;
            header('Location: data_management.php');
            exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Exception $e) {
        error_log("data_management error: " . $e->getMessage());
        $error = '実行できませんでした。記録は変わっていません。もう一度試しても同じ場合は、システム担当に連絡してください。';
    }
}

$missing_flag_tables = dmTablesMissingFlag($pdo);
$stats = dmStatistics($pdo);
$sample_total = array_sum(array_column($stats, 'sample'));
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データ管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/wts-design-tokens.css">
    <style>
        .action-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .danger-zone { background: #fdf3f4; border: 1px solid #f1aeb5; }
        .confirm-card { background: #fff8e1; border: 2px solid var(--wts-amber); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .confirm-card.is-delete { background: #fdf3f4; border-color: var(--wts-red); }
        .stats-table th { background-color: #f8f9fa; }
        /* 1色=1意味: アンバー=サンプル扱い / 緑=実務の記録 */
        .sample-badge { background-color: var(--wts-amber); }
        .real-badge { background-color: var(--wts-green); }
        .btn:focus-visible { outline: 3px solid var(--wts-blue); outline-offset: 2px; }
        .form-control:focus { border-color: var(--wts-blue); }
        .action-card .btn, .confirm-card .btn { min-height: 44px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="master_menu.php">
                <i class="fas fa-database me-2"></i>データ管理
            </a>
            <a href="master_menu.php" class="btn btn-outline-light">
                <i class="fas fa-arrow-left me-1"></i>マスターメニュー
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <h1><i class="fas fa-broom me-2"></i>データ管理</h1>
        <p class="text-muted">
            練習で入れた記録を「サンプル扱い」にして、集計・日報・年次報告から外すための画面です。
            導入のときに使います。ふだんの運用では使いません。
        </p>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($preview): ?>
            <?php
            $p = $preview;
            $is_delete = ($p['op'] === 'delete');
            $titles = [
                'mark' => $p['start'] . ' ～ ' . $p['end'] . ' の記録をサンプル扱いにします',
                'unmark' => $p['start'] . ' ～ ' . $p['end'] . ' の記録のサンプル扱いを外します',
                'production' => '本番運用開始日を ' . $p['production_start_date'] . ' にして、' . $p['end'] . ' までの記録をサンプル扱いにします',
                'delete' => 'サンプル扱いの記録を消します',
            ];
            $effects = [
                'mark' => 'サンプル扱いにした記録は、集計・日報・年次報告・ダッシュボードに出なくなります。あとで「サンプル扱いを外す」で戻せます。',
                'unmark' => 'サンプル扱いを外した記録は、集計・日報・年次報告・ダッシュボードに出るようになります。',
                'production' => 'サンプル扱いにした記録は、集計・日報・年次報告・ダッシュボードに出なくなります。あとで「サンプル扱いを外す」で戻せます。',
                'delete' => '消した記録は画面から戻せません。消す前の控えは残しますが、戻すにはシステム担当の作業が要ります。',
            ];
            $buttons = ['mark' => 'サンプル扱いにする', 'unmark' => 'サンプル扱いを外す', 'production' => 'この日で本番運用を開始する', 'delete' => '消す'];
            ?>
            <div class="confirm-card <?= $is_delete ? 'is-delete' : '' ?>" id="confirmCard">
                <h5 class="mb-3"><i class="fas fa-clipboard-check me-2"></i>確かめてください：<?= htmlspecialchars($titles[$p['op']]) ?></h5>
                <p class="mb-2"><strong>まだ何も変えていません。</strong>対象は次の <?= number_format($p['total']) ?> 件です。</p>
                <table class="table table-sm w-auto mb-3">
                    <tbody>
                        <?php foreach ($p['counts'] as $table => $n): ?>
                            <tr>
                                <td><?= htmlspecialchars(SAMPLE_TABLES[$table][1]) ?></td>
                                <td class="text-end"><strong><?= number_format($n) ?></strong> 件</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($p['newest'] && !$is_delete && $p['op'] !== 'unmark' && $p['newest'] >= date('Y-m-d', strtotime('-30 days'))): ?>
                    <p class="text-danger mb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>この中に、直近30日の記録（いちばん新しいもので <?= htmlspecialchars($p['newest']) ?>）が入っています。</strong>
                        実際の運行の記録が混ざっていないか確かめてください。
                    </p>
                <?php endif; ?>
                <p class="mb-3"><?= htmlspecialchars($effects[$p['op']]) ?></p>

                <form method="POST" class="d-flex flex-wrap align-items-end gap-2">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="execute">
                    <input type="hidden" name="op" value="<?= htmlspecialchars($p['op']) ?>">
                    <?php if ($p['op'] === 'mark' || $p['op'] === 'unmark'): ?>
                        <input type="hidden" name="start_date" value="<?= htmlspecialchars($p['start']) ?>">
                        <input type="hidden" name="end_date" value="<?= htmlspecialchars($p['end']) ?>">
                    <?php elseif ($p['op'] === 'production'): ?>
                        <input type="hidden" name="production_start_date" value="<?= htmlspecialchars($p['production_start_date']) ?>">
                    <?php endif; ?>
                    <?php if ($is_delete): ?>
                        <div>
                            <label for="confirm_word" class="form-label mb-1">消してよければ、「<?= DELETE_CONFIRM_WORD ?>」と入力してください</label>
                            <input type="text" class="form-control" id="confirm_word" name="confirm_word" autocomplete="off" required style="max-width: 12rem;">
                        </div>
                    <?php endif; ?>
                    <a href="data_management.php" class="btn btn-outline-secondary">やめる</a>
                    <button type="submit" class="btn <?= $is_delete ? 'btn-danger' : 'wts-btn-blue' ?>" data-loading-text="実行中...">
                        <?= htmlspecialchars($buttons[$p['op']]) ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- データ統計 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>いまの記録の数</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table stats-table mb-0">
                                <thead>
                                    <tr>
                                        <th>記録</th>
                                        <th class="text-end">全部</th>
                                        <th class="text-end">サンプル扱い</th>
                                        <th class="text-end">実務の記録</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats as $table => $stat): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($stat['label']) ?></td>
                                            <td class="text-end"><?= number_format($stat['total']) ?></td>
                                            <td class="text-end"><span class="badge sample-badge"><?= number_format($stat['sample']) ?></span></td>
                                            <td class="text-end"><span class="badge real-badge"><?= number_format($stat['real']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($missing_flag_tables): ?>
            <!-- 準備（サンプルの印がまだ無い時だけ出る） -->
            <div class="action-card">
                <h5><i class="fas fa-cog me-2"></i>準備</h5>
                <p>サンプル扱いの印を付けられるようにします。記録の中身は変わりません。先にこれを実行してください。</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="add_sample_flag">
                    <button type="submit" class="btn wts-btn-blue" data-loading-text="準備中...">
                        <i class="fas fa-flag me-1"></i>準備する
                    </button>
                </form>
            </div>
        <?php else: ?>
        <p class="text-muted">どの操作も、まず対象の件数を確かめる画面が出ます。そこで実行を押すまで、記録は変わりません。</p>
        <div class="row">
            <!-- 期間でサンプル扱いにする -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-calendar me-2"></i>期間を指定してサンプル扱いにする</h5>
                    <p>練習で入れた期間の記録を、集計から外します。</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="op" value="mark">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label mb-1" for="mark_start">開始日</label>
                                <input type="date" id="mark_start" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1" for="mark_end">終了日</label>
                                <input type="date" id="mark_end" name="end_date" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" class="btn wts-btn-blue mt-3">
                            <i class="fas fa-search me-1"></i>対象の件数を確かめる
                        </button>
                    </form>
                </div>
            </div>

            <!-- 本番運用開始日 -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-rocket me-2"></i>本番運用開始日を決める</h5>
                    <p>決めた日より前の記録を、全部サンプル扱いにします。<strong>すでに運用している場合は使わないでください。</strong></p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="op" value="production">
                        <label class="form-label mb-1" for="production_start_date">本番運用開始日</label>
                        <input type="date" id="production_start_date" name="production_start_date" class="form-control" required>
                        <button type="submit" class="btn wts-btn-blue mt-3">
                            <i class="fas fa-search me-1"></i>対象の件数を確かめる
                        </button>
                    </form>
                </div>
            </div>

            <!-- サンプル扱いを外す -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-undo me-2"></i>サンプル扱いを外す</h5>
                    <p>間違えてサンプル扱いにした期間の記録を、元に戻します。</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="op" value="unmark">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label mb-1" for="unmark_start">開始日</label>
                                <input type="date" id="unmark_start" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label mb-1" for="unmark_end">終了日</label>
                                <input type="date" id="unmark_end" name="end_date" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary mt-3" <?= $sample_total === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-search me-1"></i>対象の件数を確かめる
                        </button>
                        <?php if ($sample_total === 0): ?>
                            <div class="form-text">サンプル扱いの記録はありません。</div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- エクスポート -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-download me-2"></i>実務の記録を書き出す</h5>
                    <p>サンプル扱いでない記録と、ユーザー・車両をファイルに書き出します。</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="export_real_data">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-file-export me-1"></i>書き出す
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 消す操作 -->
        <div class="row mt-2">
            <div class="col-12">
                <div class="action-card danger-zone">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>サンプル扱いの記録を消す</h5>
                    <p>サンプル扱いにした記録を消します。消した記録は画面から戻せません。</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="op" value="delete">
                        <button type="submit" class="btn btn-outline-danger" <?= $sample_total === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-search me-1"></i>消す対象の件数を確かめる
                        </button>
                        <?php if ($sample_total === 0): ?>
                            <div class="form-text">サンプル扱いの記録はありません。消すものはありません。</div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 使い方 -->
        <div class="row mt-2 mb-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>使う順番（導入のとき）</h5>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0">
                            <li>練習で記録を入れて、操作に慣れる。</li>
                            <li>本番の運用を始める日に「本番運用開始日を決める」を実行する（それより前の練習の記録が集計から外れる）。</li>
                            <li>間違えたら「サンプル扱いを外す」で戻す。</li>
                            <li>練習の記録が要らなくなったら「サンプル扱いの記録を消す」。消さなくても集計には出ません。</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ui-interactions.js"></script>
    <script>
        // 確かめる枠が出た時は、そこが見える位置へ（押した結果が画面の外に出ないように）
        var confirmCard = document.getElementById('confirmCard');
        if (confirmCard) confirmCard.scrollIntoView({ block: 'start' });
    </script>
</body>
</html>
