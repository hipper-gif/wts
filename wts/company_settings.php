<?php
/**
 * 会社情報設定
 * 事業者基本情報の閲覧・編集ページ
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// 権限チェック（Admin限定ページ）
if ($user_role !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

// POST処理: 会社情報更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    try {
        $pdo->beginTransaction();
        // 最初のレコードのIDを動的に取得してUPDATE
        $id_row = $pdo->query("SELECT id FROM company_info ORDER BY id LIMIT 1")->fetch();
        if (!$id_row) {
            $pdo->exec("INSERT INTO company_info (id) VALUES (1)");
            $target_id = 1;
        } else {
            $target_id = $id_row['id'];
        }
        $stmt = $pdo->prepare("
            UPDATE company_info SET
            company_name = ?, representative_name = ?,
            postal_code = ?, address = ?, phone = ?,
            fax = ?, manager_name = ?, manager_email = ?,
            license_number = ?, business_type = ?,
            business_number = ?, capital_thousand_yen = ?, concurrent_business = ?,
            form21_prev_total = ?, form21_prev_wheelchair = ?, form21_prev_udt = ?,
            form21_prev_stretcher = ?, form21_prev_combo = ?, form21_prev_rotation = ?,
            form21_plan_content = ?, form21_change_content = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['company_name'], $_POST['representative_name'],
            $_POST['postal_code'], $_POST['address'], $_POST['phone'],
            $_POST['fax'] ?? '', $_POST['manager_name'] ?? '', $_POST['manager_email'] ?? '',
            $_POST['license_number'], $_POST['business_type'],
            $_POST['business_number'] ?? '', intval($_POST['capital_thousand_yen'] ?? 0), $_POST['concurrent_business'] ?? '',
            intval($_POST['form21_prev_total'] ?? 0), intval($_POST['form21_prev_wheelchair'] ?? 0), intval($_POST['form21_prev_udt'] ?? 0),
            intval($_POST['form21_prev_stretcher'] ?? 0), intval($_POST['form21_prev_combo'] ?? 0), intval($_POST['form21_prev_rotation'] ?? 0),
            $_POST['form21_plan_content'] ?? '', $_POST['form21_change_content'] ?? '',
            $target_id
        ]);
        logAudit($pdo, 0, '[会社情報] 事業者情報更新', $user_id, 'company_settings', [], "company_name={$_POST['company_name']}");
        $pdo->commit();
        $message = "事業者情報を更新しました。";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Company settings error: " . $e->getMessage());
        $error = "エラーが発生しました。";
    }
}

// 会社情報取得
$stmt = $pdo->query("SELECT * FROM company_info ORDER BY id LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    $company = [
        'company_name' => '', 'representative_name' => '',
        'address' => '', 'phone' => '', 'fax' => '',
        'manager_name' => '', 'manager_email' => '',
        'postal_code' => '', 'license_number' => '',
        'business_type' => '一般乗用旅客自動車運送事業（福祉）',
        'business_number' => '', 'capital_thousand_yen' => 0, 'concurrent_business' => ''
    ];
}

// ページヘッダー
$page_data = renderCompletePage(
    '会社情報設定',
    $user_name, $user_role, 'company_settings',
    'fas fa-building', '会社情報設定', '事業者基本情報の管理', 'master',
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスタ管理', 'url' => 'master_menu.php'],
            ['text' => '会社情報設定']
        ]
    ]
);
?>
<?= $page_data['html_head'] ?>
<style>
.info-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid rgba(0,0,0,0.06); }
.info-label { font-weight: 600; color: #6c757d; font-size: 0.85rem; margin-bottom: 0.25rem; }
.info-value { font-size: 1rem; margin-bottom: 1rem; }
.info-value:empty::after { content: '未設定'; color: #adb5bd; font-style: italic; }
</style>
</head>
<body>
    <?= $page_data['system_header'] ?>
    <?= $page_data['page_header'] ?>

<main class="main-content">
<div class="container-fluid px-4">

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- 表示モード -->
        <div class="col-lg-6 mb-4">
            <div class="info-card h-100">
                <h5 class="mb-3"><i class="fas fa-id-card me-2 text-primary"></i>基本情報</h5>
                <div class="info-label">事業者名</div>
                <div class="info-value"><?= htmlspecialchars($company['company_name']) ?></div>
                <div class="info-label">代表者名</div>
                <div class="info-value"><?= htmlspecialchars($company['representative_name']) ?></div>
                <div class="info-label">郵便番号</div>
                <div class="info-value"><?= htmlspecialchars($company['postal_code']) ?></div>
                <div class="info-label">住所</div>
                <div class="info-value"><?= htmlspecialchars($company['address']) ?></div>
                <div class="info-label">事業種別</div>
                <div class="info-value"><?= htmlspecialchars($company['business_type']) ?></div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="info-card h-100">
                <h5 class="mb-3"><i class="fas fa-phone-alt me-2 text-success"></i>連絡先・管理者</h5>
                <div class="info-label">電話番号</div>
                <div class="info-value"><?= htmlspecialchars($company['phone']) ?></div>
                <div class="info-label">FAX番号</div>
                <div class="info-value"><?= htmlspecialchars($company['fax'] ?? '') ?></div>
                <div class="info-label">運行管理者</div>
                <div class="info-value"><?= htmlspecialchars($company['manager_name'] ?? '') ?></div>
                <div class="info-label">管理者メールアドレス</div>
                <div class="info-value"><?= htmlspecialchars($company['manager_email'] ?? '') ?></div>
                <div class="info-label">許可番号</div>
                <div class="info-value"><?= htmlspecialchars($company['license_number'] ?: '') ?></div>
            </div>
        </div>

        <!-- 第4号様式（陸運局提出）専用情報 -->
        <div class="col-12 mb-4">
            <div class="info-card">
                <h5 class="mb-3"><i class="fas fa-file-alt me-2 text-info"></i>第4号様式（陸運局提出）専用項目</h5>
                <div class="row">
                    <div class="col-md-4">
                        <div class="info-label">事業者番号</div>
                        <div class="info-value"><?= htmlspecialchars($company['business_number'] ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-label">資本金（基金）千円</div>
                        <div class="info-value"><?= number_format(intval($company['capital_thousand_yen'] ?? 0)) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-label">兼営事業</div>
                        <div class="info-value"><?= htmlspecialchars($company['concurrent_business'] ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 編集フォーム -->
    <div class="info-card mb-4">
        <h5 class="mb-3">
            <i class="fas fa-edit me-2 text-warning"></i>情報を編集
        </h5>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="row">
                <div class="col-lg-6">
                    <div class="mb-3">
                        <label class="form-label">事業者名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="company_name"
                               value="<?= htmlspecialchars($company['company_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">代表者名</label>
                        <input type="text" class="form-control" name="representative_name"
                               value="<?= htmlspecialchars($company['representative_name']) ?>"
                               placeholder="代表取締役　田中　太郎">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">郵便番号</label>
                        <input type="text" class="form-control" name="postal_code"
                               value="<?= htmlspecialchars($company['postal_code']) ?>"
                               placeholder="000-0000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">住所 <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="address" rows="2" required><?= htmlspecialchars($company['address']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="text" class="form-control" name="phone"
                               value="<?= htmlspecialchars($company['phone']) ?>"
                               placeholder="06-1234-5678">
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="mb-3">
                        <label class="form-label">FAX番号</label>
                        <input type="text" class="form-control" name="fax"
                               value="<?= htmlspecialchars($company['fax'] ?? '') ?>"
                               placeholder="06-1234-5679">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">運行管理者氏名</label>
                        <input type="text" class="form-control" name="manager_name"
                               value="<?= htmlspecialchars($company['manager_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">管理者メールアドレス</label>
                        <input type="email" class="form-control" name="manager_email"
                               value="<?= htmlspecialchars($company['manager_email'] ?? '') ?>"
                               placeholder="manager@example.co.jp">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">許可番号</label>
                        <input type="text" class="form-control" name="license_number"
                               value="<?= htmlspecialchars($company['license_number']) ?>"
                               placeholder="近運輸第○○号">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">事業種別</label>
                        <select class="form-select" name="business_type">
                            <?php
                            $types = [
                                '一般乗用旅客自動車運送事業（福祉）',
                                '一般乗用旅客自動車運送事業',
                                '特定旅客自動車運送事業',
                            ];
                            foreach ($types as $t):
                            ?>
                                <option value="<?= $t ?>" <?= ($company['business_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <hr class="my-3">
            <h6 class="mb-3"><i class="fas fa-file-alt me-2 text-info"></i>第4号様式（陸運局提出）専用項目</h6>
            <div class="row">
                <div class="col-lg-4">
                    <div class="mb-3">
                        <label class="form-label">事業者番号</label>
                        <input type="text" class="form-control" name="business_number"
                               value="<?= htmlspecialchars($company['business_number'] ?? '') ?>"
                               placeholder="261">
                        <small class="text-muted">第4号様式右上の「事業者番号」欄に出力</small>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="mb-3">
                        <label class="form-label">資本金（基金）の額（千円）</label>
                        <input type="number" class="form-control" name="capital_thousand_yen" min="0" step="1"
                               value="<?= htmlspecialchars($company['capital_thousand_yen'] ?? 0) ?>"
                               placeholder="3000">
                        <small class="text-muted">千円単位で入力（例: 300万円 → 3000）</small>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="mb-3">
                        <label class="form-label">兼営事業</label>
                        <input type="text" class="form-control" name="concurrent_business"
                               value="<?= htmlspecialchars($company['concurrent_business'] ?? '') ?>"
                               placeholder="有 / 無し / 配食事業 など">
                        <small class="text-muted">「有」「無し」または兼営事業名を記入</small>
                    </div>
                </div>
            </div>

            <hr class="my-3">
            <h6 class="mb-3"><i class="fas fa-wheelchair me-2 text-info"></i>第21号様式（移動等円滑化実績等報告書 福祉タクシー車両）専用項目</h6>
            <p class="text-muted small">前年度3月31日時点の車両数を入力。年度末車両数は車両管理画面のフラグから自動集計。</p>
            <div class="row">
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 計</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_total" min="0" value="<?= intval($company['form21_prev_total'] ?? 0) ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 車椅子対応</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_wheelchair" min="0" value="<?= intval($company['form21_prev_wheelchair'] ?? 0) ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 うちUDT</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_udt" min="0" value="<?= intval($company['form21_prev_udt'] ?? 0) ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 寝台対応</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_stretcher" min="0" value="<?= intval($company['form21_prev_stretcher'] ?? 0) ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 兼用車</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_combo" min="0" value="<?= intval($company['form21_prev_combo'] ?? 0) ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label small">前年度 回転シート</label>
                    <input type="number" class="form-control form-control-sm" name="form21_prev_rotation" min="0" value="<?= intval($company['form21_prev_rotation'] ?? 0) ?>">
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-lg-6">
                    <label class="form-label">計画内容（計画対象期間及び事業の主な内容）</label>
                    <textarea class="form-control" name="form21_plan_content" rows="4" placeholder="例: 令和8年度〜令和10年度の3年間で福祉タクシー車両を1台増車予定…"><?= htmlspecialchars($company['form21_plan_content'] ?? '') ?></textarea>
                </div>
                <div class="col-lg-6">
                    <label class="form-label">前年度の計画からの変更内容</label>
                    <textarea class="form-control" name="form21_change_content" rows="4" placeholder="例: 変更なし、または計画期間延長等"><?= htmlspecialchars($company['form21_change_content'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="text-end">
                <a href="master_menu.php" class="btn btn-secondary me-2"><i class="fas fa-arrow-left me-1"></i>戻る</a>
                <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>保存</button>
            </div>
        </form>
    </div>

</div>
</main>

<?php echo $page_data['html_footer']; ?>
