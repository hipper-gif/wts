<?php
/**
 * 福祉輸送管理システム - 共通関数ライブラリ
 * 運転者選択問題修正版
 */

/**
 * 運転者一覧を取得（権限フィルタリング付き）
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効ユーザーのみ取得するか
 * @return array 運転者一覧
 */
function getDriverList($pdo, $active_only = true) {
    try {
        $where_conditions = ["is_driver = 1"];
        
        if ($active_only) {
            $where_conditions[] = "is_active = 1";
        }
        
        // NOTE: $where_clause contains only hardcoded conditions (no user input) - safe from SQL injection
        $where_clause = implode(" AND ", $where_conditions);

        $stmt = $pdo->prepare("
            SELECT id, name, login_id, is_active
            FROM users 
            WHERE {$where_clause}
            ORDER BY name
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting driver list: " . $e->getMessage());
        return [];
    }
}

/**
 * 車両一覧を取得
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効車両のみ取得するか
 * @return array 車両一覧
 */
function getVehicleList($pdo, $active_only = true) {
    try {
        $where_clause = $active_only ? "WHERE is_active = 1" : "";
        
        $stmt = $pdo->prepare("
            SELECT id, vehicle_number, model, is_active
            FROM vehicles 
            {$where_clause}
            ORDER BY vehicle_number
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting vehicle list: " . $e->getMessage());
        return [];
    }
}

/**
 * 現在のユーザーが指定した権限を持っているかチェック
 * @param string $permission 権限名 ('driver', 'caller', 'admin')
 * @return bool 権限を持っている場合true
 */
function hasPermission($permission) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    switch ($permission) {
        case 'driver':
            return isset($_SESSION['is_driver']) && $_SESSION['is_driver'];
        case 'caller':
            return isset($_SESSION['is_caller']) && $_SESSION['is_caller'];
        case 'admin':
            return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
        default:
            return false;
    }
}

/**
 * カラムが存在するかチェック
 * @param PDO $pdo データベース接続
 * @param string $table_name テーブル名
 * @param string $column_name カラム名
 * @return bool カラムが存在する場合true
 */
function columnExists($pdo, $table_name, $column_name) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table_name}` LIKE ?");
        $stmt->execute([$column_name]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error checking column existence: " . $e->getMessage());
        return false;
    }
}

/**
 * アクティブなドライバー一覧を取得
 */
function getActiveDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * アクティブな車両一覧を取得
 */
function getActiveVehicles($pdo, $columns = 'basic') {
    switch ($columns) {
        case 'with_model':
            $sql = "SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'with_mileage':
            $sql = "SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'with_name':
            $sql = "SELECT id, vehicle_number, vehicle_name, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'full':
            $sql = "SELECT id, vehicle_number, vehicle_name, model, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        default:
            $sql = "SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
    }
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 安全なHTMLエスケープ
 * @param string $str エスケープする文字列
 * @return string エスケープされた文字列
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 統一監査ログ記録
 * @param PDO $pdo データベース接続
 * @param int $record_id 対象レコードID
 * @param string $action 操作種別 (create, edit, delete, admin_unlock)
 * @param int $user_id 操作ユーザーID
 * @param string $type レコード種別 (arrival, departure, ride_record, inspection, pre_duty_call, post_duty_call, location, vehicle)
 * @param array $changes 変更内容の配列 [['field'=>..., 'old'=>..., 'new'=>...], ...]
 * @param string|null $reason 編集理由
 */
function logAudit($pdo, $record_id, $action, $user_id, $type = 'record', $changes = [], $reason = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // record_type に種別を持つ（026: 点検専用の外部キーを外し全種別を記録できるようにした・Issue #78）
        $stmt = $pdo->prepare("INSERT INTO inspection_audit_logs
            (inspection_id, record_type, action, edited_by, field_changed, old_value, new_value, reason, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (empty($changes)) {
            $stmt->execute([$record_id, $type, $action, $user_id, $type, null, null, $reason, $ip, $ua]);
        } else {
            foreach ($changes as $change) {
                $stmt->execute([
                    $record_id, $type, $action, $user_id,
                    $change['field'] ?? $type,
                    $change['old'] ?? null,
                    $change['new'] ?? null,
                    $reason, $ip, $ua
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("監査ログ記録エラー: " . $e->getMessage());
    }
}

/**
 * 保存が通らなかった時に、入れた値をフォームへ戻す script を返す（各画面はフッターの手前で1行呼ぶだけ。決まりはここ1か所）。
 * 弾かれると画面が白紙で出し直され、入れた内容を全部打ち直すことになっていた（2026-11-18 全画面点検・類型①）。
 *
 * - 保存が通った時・開いただけの時は何も返さない。
 * - どのフォームに戻すかは、送られた action の値で選ぶ（$forms = ['add' => '#addForm', ...]。action を送らない画面は '' をキーにする）。
 * - パスワードとトークンは戻さない。
 * - フォームが窓（モーダル）の中にある時は、その窓を開き直す。
 *
 * @param string $error_message 画面に出すエラー文（空なら何もしない）
 * @param array $forms action の値 => フォームのセレクタ
 * @param string $after_js 戻した後に走らせる JS（引数 data, form。画面固有の見た目の同期用）
 */
function renderRestoreInputScript($error_message, array $forms, $after_js = '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (string)$error_message === '') {
        return '';
    }
    $action = (string)($_POST['action'] ?? '');
    if (!isset($forms[$action])) {
        return '';
    }
    $data = [];
    foreach ($_POST as $key => $value) {
        if (!is_scalar($value)) continue;
        if ($key === 'csrf_token' || stripos($key, 'password') !== false) continue;
        $data[$key] = (string)$value;
    }
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $flags);
    $selector = json_encode($forms[$action], $flags);
    $after = $after_js !== '' ? $after_js : 'null';
    return <<<HTML
<script>
// 保存が通らなかったので、入れた値をフォームへ戻す（functions.php の renderRestoreInputScript）
(function () {
    var data = {$json};
    var after = {$after};
    function run() {
        var form = document.querySelector({$selector});
        if (!form) return;
        var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
        Array.prototype.forEach.call(fields, function (el) {
            var name = el.name;
            if (name === 'csrf_token' || el.type === 'password' || el.type === 'file') return;
            var has = Object.prototype.hasOwnProperty.call(data, name);
            if (el.type === 'checkbox') {
                el.checked = has;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (el.type === 'radio') {
                el.checked = has && el.value === data[name];
                if (el.checked) el.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (has) {
                el.value = data[name];
                if (el.type === 'hidden') el.disabled = false;
            }
        });
        var modal = form.closest('.modal');
        if (modal && window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
        if (typeof after === 'function') after(data, form);
    }
    // 画面自身の初期化（欄の初期値の設定）が終わってから戻す
    if (document.readyState === 'complete') { setTimeout(run, 0); }
    else { window.addEventListener('load', function () { setTimeout(run, 0); }); }
})();
</script>
HTML;
}

/**
 * 点呼（乗務前・乗務後）で、付けないと登録できない確認項目。画面の「（必須）」表示と保存時の検査の両方がこれを使う。
 * @param string $kind 'pre' または 'post'
 * @return array [項目のキー => 画面での呼び名]
 */
function getRequiredDutyCallChecks($kind) {
    if ($kind === 'pre') {
        return [
            'health_check' => '健康状態',
            'pre_inspection_check' => '運行前点検',
            'license_check' => '免許証',
        ];
    }
    return [
        'health_condition_check' => '健康状態に異常はないか',
        'alcohol_drug_check' => '酒気・薬物の影響はないか',
        'accident_violation_check' => '事故・違反の発生はないか',
        'vehicle_condition_check' => '車両に異常・損傷はないか',
    ];
}

/**
 * 点呼日として使える日付にそろえる（乗務前・乗務後で共通・ADR-0012/0016）。形が違う・未来の日付は今日にする。
 */
function normalizeDutyCallDate($value, $today) {
    $value = trim((string)$value);
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value || $value > $today) {
        return $today;
    }
    return $value;
}

/**
 * その日の日々の法定記録（乗務前・乗務後点呼・日常点検）を新しく登録できるか（ADR-0012/0016/0017）。
 * 今日と前日は誰でも（日付をまたいだ・翌朝に気づいた）。それより前は管理者だけ。未来の日付は別に弾く。
 */
function canRegisterDailyRecordDate($call_date, $user_role, $yesterday) {
    return $call_date >= $yesterday || $user_role === 'Admin';
}

/**
 * 点呼の保存前の検査（ADR-0013・0014）。アルコール測定値は空では登録できない（画面の既定は 0.000＝ADR-0014）。
 * 必須の確認項目は、付いていなければ登録できない。だめな時は理由を InvalidArgumentException で返す。
 * @return string 保存する測定値
 */
function validateDutyCallInput($kind, array $in) {
    $alcohol = trim((string)($in['alcohol_check_value'] ?? ''));
    if ($alcohol === '' || !is_numeric($alcohol) || (float)$alcohol < 0) {
        throw new InvalidArgumentException('アルコール測定値を入力してください。');
    }
    $missing = [];
    foreach (getRequiredDutyCallChecks($kind) as $key => $label) {
        if (!isset($in[$key])) $missing[] = '「' . $label . '」';
    }
    if ($missing) {
        throw new InvalidArgumentException('必須の確認項目にチェックが付いていません: ' . implode('、', $missing) . '。確認してからチェックを付けてください。');
    }
    return $alcohol;
}

/**
 * アルコールが出たとみなす境目（ADR-0015）。これより大きい値は「出た」。
 * 0.15mg/L（道路交通法の酒気帯び運転の基準）ではなく 0 にしている＝事業用の点呼では少しでも出たら乗務させない、を安全側に取る。
 * 登録は止めない（測った値を記録に残すことが先）。画面で赤く知らせ、ダッシュボードで運行管理者に知らせる。
 */
const WTS_ALCOHOL_ALERT_ABOVE = 0.0;

/** 測定値からアルコールが出たかの判定。決まりはここ1か所（画面・ダッシュボードはこれを呼ぶ） */
function isAlcoholDetected($value) {
    return $value !== null && $value !== '' && is_numeric($value) && (float)$value > WTS_ALCOHOL_ALERT_ABOVE;
}

/** SQL で同じ判定をする断片（ダッシュボードの一覧用）。isAlcoholDetected と同じ境目を使う */
function wtsSqlAlcoholDetected($column) {
    return "({$column} IS NOT NULL AND {$column} > " . sprintf('%.3F', WTS_ALCOHOL_ALERT_ABOVE) . ")";
}

/**
 * 記録の測定値でアルコールが出ていたら、画面の上に出す赤い知らせ。出ていなければ空。
 * @param string $kind 'pre'（乗務前）/'post'（乗務後）
 */
function renderAlcoholDetectedAlert($value, $kind) {
    if (!isAlcoholDetected($value)) return '';
    $v = htmlspecialchars(sprintf('%.3f', (float)$value), ENT_QUOTES, 'UTF-8');
    $next = $kind === 'pre'
        ? 'この運転者を乗務させないでください。すぐに運行管理者へ連絡し、時間を置いて測り直してください。'
        : '運行管理者へすぐに連絡してください。乗務中に飲酒した可能性があります。';
    return '<div class="alert alert-danger border-danger border-3 fw-bold" role="alert">'
         . '<i class="fas fa-exclamation-triangle me-2 fa-lg"></i>'
         . 'アルコールが検出されています（' . $v . ' mg/L）。' . $next
         . '<div class="small fw-normal mt-1">記録はこの値で残っています。測り直した時は、もう1回この画面で記録してください（前の値は変更の記録に残ります）。</div>'
         . '</div>';
}

/**
 * 測定値の入力欄の下に、0 より大きい値を入れた時だけ赤い注意を出す（保存の前に気づけるように）。
 * 境目は WTS_ALCOHOL_ALERT_ABOVE を渡す＝JS に数を書き写さない。
 */
function renderAlcoholLiveWarningScript($input_id) {
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $id = json_encode((string)$input_id, $flags);
    $above = json_encode(WTS_ALCOHOL_ALERT_ABOVE);
    return <<<HTML
<script>
// アルコール測定値の注意（functions.php の renderAlcoholLiveWarningScript・ADR-0015）
(function () {
    function run() {
        var field = document.getElementById({$id});
        if (!field) return;
        var note = document.createElement('div');
        note.className = 'text-danger fw-bold small mt-1';
        note.style.display = 'none';
        note.textContent = 'アルコールが出ています。保存すると運行管理者に知らせが出ます。乗務させないでください。';
        var host = field.closest('.input-group') || field;
        host.parentNode.insertBefore(note, host.nextSibling);
        function update() {
            var v = parseFloat(field.value);
            var hit = !isNaN(v) && v > {$above};
            note.style.display = hit ? '' : 'none';
            field.classList.toggle('border-danger', hit);
        }
        field.addEventListener('input', update);
        field.addEventListener('change', update);
        update();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
</script>
HTML;
}

/**
 * 保存が通った後に、同じ画面を出し直す（再読込や「戻る→進む」で同じ保存がもう1回走らないように）。
 * 知らせは、出し直した画面で1回だけ出す（takeFlashMessage）。保存が通らなかった時は何もしない＝入力を残して出し直す側の処理へ進む。
 * 各画面の保存処理のすぐ後で呼ぶ。決まりはここ1か所。
 */
function redirectAfterSuccessfulPost($success_message, $error_message = '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (string)$success_message === '' || (string)$error_message !== '') {
        return;
    }
    if (headers_sent()) {
        return; // すでに画面を出し始めている時は、そのまま出す
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $_SESSION['flash_messages'][$script] = (string)$success_message;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? $script), true, 303);
    exit;
}

/** 出し直した画面で、保存の知らせを1回だけ取り出す（無ければ空文字） */
function takeFlashMessage() {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $message = $_SESSION['flash_messages'][$script] ?? '';
    unset($_SESSION['flash_messages'][$script]);
    return $message;
}

/**
 * 乗車記録の売上の式（SQL の断片）。売上・現金売上・カード売上の決まりはここ1か所。
 * 売上金確認・現金カウント・乗車記録の日次集計・ダッシュボードが同じ式を使う
 * （以前は4か所に別々に書かれていて、片方だけ直すと画面ごとに金額が違い得た）。
 * @param string $alias 表の別名（'r' など。無ければ空）
 */
function wtsSqlRideRevenue($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    return "(CASE WHEN {$a}total_fare IS NOT NULL AND {$a}total_fare > 0 THEN {$a}total_fare"
         . " ELSE COALESCE({$a}fare, 0) + COALESCE({$a}charge, 0) END)";
}

/** 現金で受け取った額。現金額の欄が空の古い記録は、支払方法が現金なら売上の全額とみなす */
function wtsSqlRideCash($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$a}cash_amount, CASE WHEN {$a}payment_method = '現金' THEN " . wtsSqlRideRevenue($alias) . " ELSE 0 END)";
}

/** カードで受け取った額（考え方は現金と同じ） */
function wtsSqlRideCard($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$a}card_amount, CASE WHEN {$a}payment_method = 'カード' THEN " . wtsSqlRideRevenue($alias) . " ELSE 0 END)";
}

/** サンプル扱いの記録を集計から外す条件 */
function wtsSqlRideNotSample($alias = '') {
    $a = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$a}is_sample_data, 0) = 0";
}

/**
 * 現金カウントの金種（額面）と、基準おつりの枚数。決まりはここ1か所。
 * 現金カウントの画面と保存API（合計の計算）の両方がこれを使う。
 */
function getCashBaseChange() {
    return [
        'bill_10000' => ['count' => 0, 'value' => 10000, 'name' => '1万円札'],
        'bill_5000' => ['count' => 1, 'value' => 5000, 'name' => '5千円札'],
        'bill_1000' => ['count' => 10, 'value' => 1000, 'name' => '千円札'],
        'coin_500'  => ['count' => 3, 'value' => 500, 'name' => '500円玉'],
        'coin_100'  => ['count' => 11, 'value' => 100, 'name' => '100円玉'],
        'coin_50'   => ['count' => 5, 'value' => 50, 'name' => '50円玉'],
        'coin_10'   => ['count' => 15, 'value' => 10, 'name' => '10円玉'],
        'coin_5'    => ['count' => 0, 'value' => 5, 'name' => '5円玉'],
        'coin_1'    => ['count' => 0, 'value' => 1, 'name' => '1円玉']
    ];
}

/** 基準おつりの合計（円）。金種の表から計算する */
function getCashBaseTotal() {
    $total = 0;
    foreach (getCashBaseChange() as $d) {
        $total += $d['count'] * $d['value'];
    }
    return $total;
}

/** 数えた枚数から合計金額（円）を出す。マイナスの枚数は0として数える */
function calcCashCountTotal(array $counts) {
    $total = 0;
    foreach (getCashBaseChange() as $key => $d) {
        $total += max(0, (int)($counts[$key] ?? 0)) * $d['value'];
    }
    return $total;
}

/**
 * 画面から記録を消す前に、消す行を控えの表（deleted_records_archive・sql/028）へ写す。
 * 物理削除する画面はすべて、DELETE の直前にこれを呼ぶ（決まりはここ1か所）。
 * 写せなかった時は例外を投げる＝控えの無い削除はしない。呼ぶ側のトランザクションの中で呼ぶと、削除が失敗した時に控えも残らない。
 * 戻し方: row_json を元の表へ INSERT し直す（画面は無い。システム担当が行う）。
 *
 * @param string $table 元の表（呼ぶ側が固定の文字列で渡す。利用者の入力を入れない）
 * @param string $where 対象行の条件（プレースホルダつき）
 * @param array $params 条件の値
 * @param string $reason 消した操作（例: 乗車記録の削除）
 * @param string|null $batch_id 1回の操作で複数の表を消す時に同じ番号を渡す
 * @return int 控えた行数
 */
function archiveRowsBeforeDelete($pdo, $table, $where, array $params, $reason, $user_id, $batch_id = null) {
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('控えの対象の表が正しくありません。');
    }
    $batch_id = $batch_id ?: (date('YmdHis') . '-' . bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE {$where}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare("INSERT INTO deleted_records_archive (batch_id, table_name, record_id, row_json, reason, deleted_by)
                          VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($rows as $row) {
        $ins->execute([$batch_id, $table, (int)($row['id'] ?? 0), json_encode($row, JSON_UNESCAPED_UNICODE), $reason, $user_id]);
    }
    return count($rows);
}

/**
 * 入庫記録を1件登録する（入庫画面と、出庫画面の「前回の入庫を一緒に登録」の両方が呼ぶ。決まりはここ1か所）
 * 呼ぶ側がトランザクションを張ること。入力の誤りは InvalidArgumentException（文言はそのまま画面に出す）。
 * @param PDO $pdo
 * @param array $in departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, arrival_mileage,
 *                  fuel_cost, highway_cost, other_cost, break_location, break_start_time, break_end_time, remarks
 * @param int $user_id 操作ユーザーID（監査ログ用）
 * @return array ['id', 'driver_id', 'vehicle_id', 'total_distance']
 */
function registerArrivalRecord($pdo, array $in, $user_id) {
    $departure_record_id = !empty($in['departure_record_id']) ? (int)$in['departure_record_id'] : null;
    $driver_id = (int)($in['driver_id'] ?? 0);
    $vehicle_id = (int)($in['vehicle_id'] ?? 0);
    $arrival_date = (string)($in['arrival_date'] ?? '');
    $arrival_time = (string)($in['arrival_time'] ?? '');
    $arrival_mileage = (int)($in['arrival_mileage'] ?? 0);

    $departure_mileage = 0;
    if ($departure_record_id) {
        // 行ロックして、同じ出庫への二重入庫（連打・別端末）を防ぐ
        $stmt = $pdo->prepare("SELECT driver_id, vehicle_id, departure_date, departure_time, departure_mileage FROM departure_records WHERE id = ? FOR UPDATE");
        $stmt->execute([$departure_record_id]);
        $dep = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dep) {
            // 古い画面からの送信（出庫が削除済み）＝外部キー違反を一般エラーにしない（Issue #79）
            throw new InvalidArgumentException('選んだ出庫記録が見つかりません（別の端末で入庫済みか、出庫記録が削除されています）。画面を開き直して、未入庫の出庫を選び直してください。');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE departure_record_id = ?");
        $stmt->execute([$departure_record_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('この出庫はすでに入庫が登録されています。画面を開き直して確認してください。');
        }
        $departure_mileage = (int)$dep['departure_mileage'];
        // 入庫の運転者・車両は出庫記録に従う（画面の選択違いで車両の記録が分かれるのを防ぐ）
        $driver_id = (int)$dep['driver_id'];
        $vehicle_id = (int)$dep['vehicle_id'];
        // 過去の出庫を後日入庫する際に、入庫日が「今日」のまま登録されて日付が逆転する事故を防ぐ
        $departure_at = strtotime($dep['departure_date'] . ' ' . $dep['departure_time']);
        $arrival_at = strtotime($arrival_date . ' ' . $arrival_time);
        if ($departure_at && $arrival_at && $arrival_at < $departure_at) {
            throw new InvalidArgumentException(
                '入庫日時（' . $arrival_date . ' ' . substr($arrival_time, 0, 5) . '）が出庫日時（'
                . $dep['departure_date'] . ' ' . substr($dep['departure_time'], 0, 5)
                . '）より前になっています。実際に入庫した日付と時刻を入力してください。'
            );
        }
    }

    if (!$driver_id || !$vehicle_id || !$arrival_date || !$arrival_time || !$arrival_mileage) {
        throw new InvalidArgumentException('必須項目が入力されていません。');
    }

    $total_distance = $arrival_mileage - $departure_mileage;
    if ($total_distance < 0) {
        throw new InvalidArgumentException(
            '入庫メーター（' . number_format($arrival_mileage) . 'km）が出庫メーター（' . number_format($departure_mileage) . 'km）より小さくなっています。確認してください。'
        );
    }

    $stmt = $pdo->prepare("
        INSERT INTO arrival_records
        (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, arrival_mileage,
         total_distance, fuel_cost, highway_cost, other_cost, break_location, break_start_time,
         break_end_time, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $departure_record_id,
        $driver_id,
        $vehicle_id,
        $arrival_date,
        $arrival_time,
        $arrival_mileage,
        $total_distance,
        (int)($in['fuel_cost'] ?? 0),
        (int)($in['highway_cost'] ?? 0),
        (int)($in['other_cost'] ?? 0),
        ($in['break_location'] ?? '') ?: null,
        ($in['break_start_time'] ?? '') ?: null,
        ($in['break_end_time'] ?? '') ?: null,
        ($in['remarks'] ?? '') ?: null,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    // 車両の走行距離を更新（楽観的ロック：メーターが逆行しないことを保証）
    $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ? AND current_mileage <= ?");
    $stmt->execute([$arrival_mileage, $vehicle_id, $arrival_mileage]);

    logAudit($pdo, $new_id, 'create', $user_id, 'arrival');

    return ['id' => $new_id, 'driver_id' => $driver_id, 'vehicle_id' => $vehicle_id, 'total_distance' => $total_distance];
}

/**
 * 日付ベースの編集可否判定（共通）
 * @param array $record レコードデータ
 * @param string $date_column 日付カラム名
 * @param string $user_role ユーザー権限 (Admin/User)
 * @return array ['can_edit'=>bool, 'needs_reason'=>bool, 'lock_reason'=>string]
 */
function canEditByDate($record, $date_column, $user_role) {
    $today = date('Y-m-d');
    $record_date = $record[$date_column] ?? '';

    if ($record_date >= $today) {
        return ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];
    }

    if ($user_role === 'Admin') {
        return ['can_edit' => true, 'needs_reason' => true, 'lock_reason' => '過去日の記録です。管理者権限で修正できます。'];
    }

    return ['can_edit' => false, 'needs_reason' => false, 'lock_reason' => '過去日の記録はロックされています。管理者にお問い合わせください。'];
}

?>
