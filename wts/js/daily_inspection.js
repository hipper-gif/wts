// 安全に関わる重要項目リスト
const safetyCriticalItems = [
    'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
    'lights_result', 'tire_pressure_result', 'tire_damage_result'
];

// 必須項目リスト（プログレスバー用）
const requiredItemsForProgress = [
    'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
    'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
];

// 元の値を保持（否→可の検出用）
const originalValues = {};

// プログレスバー更新
function updateProgressBar() {
    const total = requiredItemsForProgress.length;
    let completed = 0;
    requiredItemsForProgress.forEach(function(item) {
        const checked = document.querySelector(`input[name="${item}"]:checked`);
        if (checked) completed++;
    });
    const percent = Math.round((completed / total) * 100);
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    if (progressBar) {
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', percent);
    }
    if (progressText) {
        progressText.textContent = completed + ' / ' + total + ' 完了';
    }
}

// 安全項目の否→可変更を検出
function checkSafetyCriticalChanges() {
    let hasChange = false;
    safetyCriticalItems.forEach(function(item) {
        const checked = document.querySelector(`input[name="${item}"]:checked`);
        if (checked && originalValues[item] === '否' && checked.value === '可') {
            hasChange = true;
        }
    });
    const section = document.getElementById('safetyCriticalReasonSection');
    if (section) {
        section.style.display = hasChange ? 'block' : 'none';
        const textarea = document.getElementById('safetyCriticalReason');
        if (textarea) {
            textarea.required = hasChange;
        }
    }
}

// 車両選択時の走行距離更新
function updateMileage() {
    const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
    const mileageInput = document.getElementById('mileage');
    const mileageInfo = document.getElementById('mileageInfo');
    const mileageText = document.getElementById('mileageText');

    if (vehicleSelect.value) {
        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
        const currentMileage = selectedOption.getAttribute('data-mileage');

        if (currentMileage && currentMileage !== '0') {
            mileageText.textContent = `前回記録: ${currentMileage}km`;
            mileageInfo.style.display = 'block';

            if (!mileageInput.value) {
                mileageInput.value = currentMileage;
            }
        } else {
            mileageInfo.style.display = 'none';
        }
    } else {
        mileageInfo.style.display = 'none';
    }
}

// 点検結果の変更時にスタイル更新
function updateInspectionItemStyle(itemName, value) {
    const item = document.querySelector(`[data-item="${itemName}"]`);
    if (item) {
        item.classList.remove('border-success', 'border-danger', 'border-warning', 'bg-success', 'bg-danger', 'bg-warning');

        if (value === '可') {
            item.classList.add('border-success', 'bg-success', 'bg-opacity-10');
        } else if (value === '否') {
            item.classList.add('border-danger', 'bg-danger', 'bg-opacity-10');
        } else if (value === '省略') {
            item.classList.add('border-warning', 'bg-warning', 'bg-opacity-10');
        }
    }
}

// 一括選択機能
function setAllResults(value) {
    // 必須項目のみ対象
    const requiredItems = [
        'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
        'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
    ];

    requiredItems.forEach(function(itemName) {
        const radio = document.querySelector(`input[name="${itemName}"][value="${value}"]`);
        if (radio) {
            radio.checked = true;
            updateInspectionItemStyle(itemName, value);
        }
    });
}

// 必須項目のみ全て可
function setAllOk() {
    setAllResults('可');
    updateProgressBar();
}

// 全て否
function setAllNg() {
    setAllResults('否');
}

// 全項目（必須＋省略可）を可に設定
function setAllOkIncludingOptional() {
    const allItems = [
        'foot_brake_result', 'parking_brake_result', 'engine_start_result',
        'engine_performance_result', 'wiper_result', 'washer_spray_result',
        'brake_fluid_result', 'coolant_result', 'engine_oil_result',
        'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
        'lights_result', 'lens_result', 'tire_pressure_result',
        'tire_damage_result', 'tire_tread_result'
    ];

    allItems.forEach(function(itemName) {
        const radio = document.querySelector('input[name="' + itemName + '"][value="可"]');
        if (radio) {
            radio.checked = true;
            updateInspectionItemStyle(itemName, '可');
        }
    });
    updateProgressBar();
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 車両選択の初期設定
    updateMileage();

    // 既存の点検結果のスタイル適用 & 元の値を記録
    const radioButtons = document.querySelectorAll('input[type="radio"]:checked');
    radioButtons.forEach(function(radio) {
        const itemName = radio.name;
        const value = radio.value;
        updateInspectionItemStyle(itemName, value);
        originalValues[itemName] = value;
    });

    // ラジオボタンの変更イベント
    const allRadios = document.querySelectorAll('input[type="radio"]');
    allRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            updateInspectionItemStyle(this.name, this.value);
            updateProgressBar();
            checkSafetyCriticalChanges();
        });
    });

    // 初期プログレスバー更新
    updateProgressBar();

    // 下書き機能（編集モードでない場合のみ）
    const isEditMode = !!document.querySelector('input[name="inspector_id"][type="hidden"]');
    if (!isEditMode) {
        // 下書きが存在する場合、バナーを表示
        const draft = localStorage.getItem('inspection_draft');
        if (draft) {
            const draftBanner = document.getElementById('draftBanner');
            if (draftBanner) {
                draftBanner.classList.remove('d-none');
            }
        }

        // 30秒ごとに自動保存
        setInterval(function() {
            saveDraft();
        }, 30000);
    }
});

// フォーム送信前の確認
document.getElementById('inspectionForm').addEventListener('submit', function(e) {
    var inspectorField = document.querySelector('select[name="inspector_id"]') || document.querySelector('input[name="inspector_id"]');
    var vehicleField = document.querySelector('select[name="vehicle_id"]') || document.querySelector('input[name="vehicle_id"]');
    const inspectorId = inspectorField ? inspectorField.value : '';
    const vehicleId = vehicleField ? vehicleField.value : '';

    if (!inspectorId || !vehicleId) {
        e.preventDefault();
        showToast('点検者と車両を選択してください。', 'warning');
        return;
    }

    // 必須項目のチェック
    const requiredItems = [
        'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
        'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
    ];

    let missingItems = [];
    requiredItems.forEach(function(item) {
        const checked = document.querySelector(`input[name="${item}"]:checked`);
        if (!checked) {
            missingItems.push(item);
        }
    });

    if (missingItems.length > 0) {
        e.preventDefault();
        showToast('必須点検項目に未選択があります。すべての必須項目を選択してください。', 'warning');
        return;
    }

    // 修正理由が必須の場合のチェック
    const editReasonSection = document.getElementById('editReasonSection');
    if (editReasonSection && editReasonSection.style.display !== 'none') {
        const editReason = document.getElementById('editReason');
        if (editReason && !editReason.value.trim()) {
            e.preventDefault();
            showToast('ロック済みレコードの修正には理由の記入が必要です。', 'warning');
            editReason.focus();
            return;
        }
    }

    // 安全項目変更理由のチェック
    const safetyCriticalSection = document.getElementById('safetyCriticalReasonSection');
    if (safetyCriticalSection && safetyCriticalSection.style.display !== 'none') {
        const safetyReason = document.getElementById('safetyCriticalReason');
        if (safetyReason && !safetyReason.value.trim()) {
            e.preventDefault();
            showToast('安全に関わる項目の変更理由を入力してください。', 'warning');
            safetyReason.focus();
            return;
        }
    }

    // 「否」の項目がある場合の確認
    const ngItems = document.querySelectorAll('input[value="否"]:checked');
    if (ngItems.length > 0) {
        const defectDetails = document.querySelector('textarea[name="defect_details"]').value.trim();
        if (!defectDetails) {
            if (!confirm('点検結果に「否」がありますが、不良個所の詳細が未記入です。このまま保存しますか？')) {
                e.preventDefault();
                return;
            }
        }
    }

    // 送信成功時に下書きをクリア
    localStorage.removeItem('inspection_draft');
});

// 編集モード有効化
function enableEditMode() {
    const lockStatus = window.inspectionLockStatus || {};

    // ロック中かつ編集不可の場合は何もしない
    if (lockStatus.isLocked && !lockStatus.canEdit) {
        showToast(lockStatus.lockReason || 'この記録は編集できません。', 'warning');
        return;
    }

    // readonly/disabled属性を削除
    document.querySelectorAll('input[readonly], textarea[readonly]').forEach(element => {
        element.removeAttribute('readonly');
    });

    document.querySelectorAll('input[disabled]').forEach(element => {
        element.removeAttribute('disabled');
    });

    // 基本情報の選択フィールドを復元
    const inspectorId = document.querySelector('input[name="inspector_id"]').value;
    const vehicleId = document.querySelector('input[name="vehicle_id"]').value;
    const inspectionDate = document.querySelector('input[name="inspection_date"]').value;

    // 点検日フィールドを復元
    const dateInput = document.querySelector('input[name="inspection_date_display"]');
    if (dateInput) {
        dateInput.name = 'inspection_date';
        dateInput.type = 'date';
        dateInput.removeAttribute('readonly');
    }

    // ロック済みレコードの場合：修正理由セクションを表示
    if (lockStatus.needsReason) {
        const reasonSection = document.getElementById('editReasonSection');
        if (reasonSection) {
            reasonSection.style.display = 'block';
            const reasonTextarea = document.getElementById('editReason');
            if (reasonTextarea) {
                reasonTextarea.required = true;
            }
        }
    }

    // プログレスバー更新
    updateProgressBar();

    // ボタンを変更
    const actionButtons = document.getElementById('actionButtons');
    actionButtons.innerHTML = `
        <button type="submit" class="btn btn-success btn-lg">
            <i class="fas fa-save me-2"></i>変更を保存
        </button>
        <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="location.reload()">
            <i class="fas fa-times me-2"></i>キャンセル
        </button>
    `;
}

// フォーム未保存データの離脱警告
var formDirty = false;
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('inspectionForm');
    if (form) {
        form.addEventListener('input', function() { formDirty = true; });
        form.addEventListener('change', function() { formDirty = true; });
        form.addEventListener('submit', function() { formDirty = false; });
    }
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) { e.preventDefault(); }
});

// 削除確認（管理者のみ、理由入力付き・監査ログ記録）
function confirmDelete() {
    const reason = prompt('削除理由を入力してください（監査ログに記録されます）:');
    if (reason === null) return;
    if (reason.trim() === '') {
        showToast('削除理由を入力してください。', 'warning');
        return;
    }
    if (confirm('本当に削除しますか？この操作は監査ログに記録されます。')) {
        const deleteReasonInput = document.getElementById('deleteReason');
        if (deleteReasonInput) {
            deleteReasonInput.value = reason;
        }
        document.getElementById('deleteForm').submit();
    }
}
// 印刷機能
function printInspection() {
    // 印刷ヘッダーに値をセット
    var dateEl = document.querySelector('input[name="inspection_date"]') || document.querySelector('input[name="inspection_date_display"]');
    var timeEl = document.querySelector('input[name="inspection_time"]');
    var inspectorEl = document.querySelector('select[name="inspector_id"]') || document.querySelector('input[name="inspector_id"]');
    var vehicleEl = document.querySelector('select[name="vehicle_id"]') || document.querySelector('input[name="vehicle_id"]');
    var mileageEl = document.getElementById('mileage');

    document.getElementById('printDate').textContent = dateEl ? dateEl.value : '';
    document.getElementById('printTime').textContent = timeEl ? timeEl.value : '';

    // 点検者名
    if (inspectorEl && inspectorEl.tagName === 'SELECT') {
        document.getElementById('printInspector').textContent = inspectorEl.options[inspectorEl.selectedIndex]?.text || '';
    } else {
        var inspectorText = inspectorEl ? inspectorEl.closest('.col-md-6').querySelector('input[type="text"][readonly]') : null;
        document.getElementById('printInspector').textContent = inspectorText ? inspectorText.value : (inspectorEl ? inspectorEl.value : '');
    }

    // 車両名
    if (vehicleEl && vehicleEl.tagName === 'SELECT') {
        document.getElementById('printVehicle').textContent = vehicleEl.options[vehicleEl.selectedIndex]?.text || '';
    } else {
        var vehicleText = vehicleEl ? vehicleEl.closest('.col-md-6').querySelector('input[type="text"][readonly]') : null;
        document.getElementById('printVehicle').textContent = vehicleText ? vehicleText.value : (vehicleEl ? vehicleEl.value : '');
    }

    document.getElementById('printMileage').textContent = mileageEl && mileageEl.value ? mileageEl.value + ' km' : '未記入';

    // 各点検項目の結果テキストをセット
    document.querySelectorAll('.print-result-text').forEach(function(span) {
        var itemName = span.getAttribute('data-print-for');
        var checked = document.querySelector('input[name="' + itemName + '"]:checked');
        span.textContent = '';
        span.className = 'print-result-text';
        if (checked) {
            span.textContent = checked.value;
            if (checked.value === '可') span.classList.add('print-result-ok');
            else if (checked.value === '否') span.classList.add('print-result-ng');
            else span.classList.add('print-result-skip');
        } else {
            span.textContent = '未選択';
            span.classList.add('print-result-none');
        }
    });

    // 不良個所・備考
    var defectsEl = document.querySelector('textarea[name="defect_details"]');
    var remarksEl = document.querySelector('textarea[name="remarks"]');
    document.getElementById('printDefects').textContent = defectsEl ? (defectsEl.value || 'なし') : 'なし';
    document.getElementById('printRemarks').textContent = remarksEl ? (remarksEl.value || 'なし') : 'なし';

    // 印刷日
    document.getElementById('printDateStamp').textContent = new Date().toLocaleDateString('ja-JP');

    // 印刷実行
    window.print();
}

// === 下書き保存・復元機能 ===
const draftRadioNames = [
    'foot_brake_result', 'parking_brake_result', 'engine_start_result',
    'engine_performance_result', 'wiper_result', 'washer_spray_result',
    'brake_fluid_result', 'coolant_result', 'engine_oil_result',
    'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
    'lights_result', 'lens_result', 'tire_pressure_result',
    'tire_damage_result', 'tire_tread_result'
];

function saveDraft() {
    const form = document.getElementById('inspectionForm');
    if (!form) return;

    // ラジオボタンが1つも選択されていなければ保存しない（空フォーム対策）
    const anyChecked = form.querySelector('input[type="radio"]:checked');
    if (!anyChecked) return;

    const draft = {
        timestamp: new Date().toISOString()
    };

    // セレクト要素
    const inspectorSelect = form.querySelector('select[name="inspector_id"]');
    if (inspectorSelect) draft.inspector_id = inspectorSelect.value;

    const vehicleSelect = form.querySelector('select[name="vehicle_id"]');
    if (vehicleSelect) draft.vehicle_id = vehicleSelect.value;

    // 入力要素
    const inspectionDate = form.querySelector('input[name="inspection_date"]');
    if (inspectionDate) draft.inspection_date = inspectionDate.value;

    const inspectionTime = form.querySelector('input[name="inspection_time"]');
    if (inspectionTime) draft.inspection_time = inspectionTime.value;

    const mileage = form.querySelector('input[name="mileage"]');
    if (mileage) draft.mileage = mileage.value;

    // ラジオボタン（17項目）
    draft.radios = {};
    draftRadioNames.forEach(function(name) {
        const checked = form.querySelector('input[name="' + name + '"]:checked');
        if (checked) {
            draft.radios[name] = checked.value;
        }
    });

    // テキストエリア
    const defectDetails = form.querySelector('textarea[name="defect_details"]');
    if (defectDetails) draft.defect_details = defectDetails.value;

    const remarks = form.querySelector('textarea[name="remarks"]');
    if (remarks) draft.remarks = remarks.value;

    localStorage.setItem('inspection_draft', JSON.stringify(draft));
}

function restoreDraft() {
    const draftJson = localStorage.getItem('inspection_draft');
    if (!draftJson) return;

    const draft = JSON.parse(draftJson);
    const form = document.getElementById('inspectionForm');
    if (!form) return;

    // セレクト要素
    if (draft.inspector_id) {
        const inspectorSelect = form.querySelector('select[name="inspector_id"]');
        if (inspectorSelect) inspectorSelect.value = draft.inspector_id;
    }

    if (draft.vehicle_id) {
        const vehicleSelect = form.querySelector('select[name="vehicle_id"]');
        if (vehicleSelect) {
            vehicleSelect.value = draft.vehicle_id;
            if (typeof updateMileage === 'function') updateMileage();
        }
    }

    // 入力要素
    if (draft.inspection_date) {
        const inspectionDate = form.querySelector('input[name="inspection_date"]');
        if (inspectionDate) inspectionDate.value = draft.inspection_date;
    }

    if (draft.inspection_time) {
        const inspectionTime = form.querySelector('input[name="inspection_time"]');
        if (inspectionTime) inspectionTime.value = draft.inspection_time;
    }

    if (draft.mileage) {
        const mileage = form.querySelector('input[name="mileage"]');
        if (mileage) mileage.value = draft.mileage;
    }

    // ラジオボタン復元
    if (draft.radios) {
        Object.keys(draft.radios).forEach(function(name) {
            const radio = form.querySelector('input[name="' + name + '"][value="' + draft.radios[name] + '"]');
            if (radio) {
                radio.checked = true;
                updateInspectionItemStyle(name, draft.radios[name]);
            }
        });
        updateProgressBar();
    }

    // テキストエリア
    if (draft.defect_details) {
        const defectDetails = form.querySelector('textarea[name="defect_details"]');
        if (defectDetails) defectDetails.value = draft.defect_details;
    }

    if (draft.remarks) {
        const remarks = form.querySelector('textarea[name="remarks"]');
        if (remarks) remarks.value = draft.remarks;
    }

    // バナーを非表示
    const draftBanner = document.getElementById('draftBanner');
    if (draftBanner) draftBanner.classList.add('d-none');
}

function discardDraft() {
    localStorage.removeItem('inspection_draft');
    const draftBanner = document.getElementById('draftBanner');
    if (draftBanner) draftBanner.classList.add('d-none');
}
