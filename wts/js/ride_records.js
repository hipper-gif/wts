/**
 * ride_records.js - 乗車記録ページのJavaScript
 *
 * 依存グローバル変数（PHPブリッジから）:
 *   commonLocations, currentUserId, userIsDriver,
 *   defaultDriverId, defaultVehicleId, userRole, todayDate
 */

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// 新規登録モーダル表示
function showAddModal() {
    document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>乗車記録登録';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalRecordId').value = '';
    document.getElementById('modalIsReturnTrip').value = '0';
    document.getElementById('modalOriginalRideId').value = '';
    document.getElementById('returnTripInfo').style.display = 'none';

    // 修正理由セクションを非表示
    document.getElementById('editReasonSection').style.display = 'none';
    document.getElementById('editReason').required = false;
    document.getElementById('editReason').value = '';

    // フォームをリセット
    document.getElementById('rideForm').reset();

    // デフォルト値設定
    document.getElementById('modalRideDate').value = todayDate;
    document.getElementById('modalRideTime').value = getCurrentTime();
    document.getElementById('modalDropoffTime').value = '';
    document.getElementById('modalPassengerCount').value = '1';
    document.getElementById('modalCharge').value = '0';
    document.getElementById('modalPaymentMethod').value = '現金';
    document.getElementById('modalRideDistance').value = '';
    document.getElementById('modalDisabilityDiscount').checked = false;
    document.getElementById('modalTicketAmount').value = '0';

    // デフォルト運転者・車両選択
    if (defaultDriverId) {
        document.getElementById('modalDriverId').value = defaultDriverId;
    }
    if (defaultVehicleId) {
        document.getElementById('modalVehicleId').value = defaultVehicleId;
    }

    // 経由地クリア
    clearWaypoints();

    // 人数ボタン初期化
    document.querySelectorAll('.passenger-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.passenger-btn[data-count="1"]').classList.add('active');

    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
}

// 編集モーダル表示
function editRecord(record) {
    document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>乗車記録編集';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalRecordId').value = record.id;
    document.getElementById('returnTripInfo').style.display = 'none';

    // フォームに値を設定
    document.getElementById('modalDriverId').value = record.driver_id;
    document.getElementById('modalVehicleId').value = record.vehicle_id;
    document.getElementById('modalRideDate').value = record.ride_date;
    document.getElementById('modalRideTime').value = record.ride_time;
    document.getElementById('modalPassengerCount').value = record.passenger_count;
    document.getElementById('modalPickupLocation').value = record.pickup_location;
    document.getElementById('modalDropoffLocation').value = record.dropoff_location;
    document.getElementById('modalFare').value = record.fare;
    document.getElementById('modalCharge').value = record.charge;
    document.getElementById('modalTransportationType').value = record.transportation_type;
    document.getElementById('modalPaymentMethod').value = record.payment_method;
    document.getElementById('modalNotes').value = record.notes || '';

    // 新規フィールド
    document.getElementById('modalDropoffTime').value = record.dropoff_time ? record.dropoff_time.substring(0, 5) : '';
    document.getElementById('modalRideDistance').value = record.ride_distance || '';
    document.getElementById('modalDisabilityDiscount').checked = (record.disability_discount == 1);
    document.getElementById('modalTicketAmount').value = record.ticket_amount || 0;

    // 経由地を復元
    clearWaypoints();
    if (record.waypoints && record.waypoints.length > 0) {
        record.waypoints.forEach(function(wp) { addWaypoint(wp); });
    }

    // 修正理由セクションの表示制御（過去日 + Admin の場合のみ表示）
    var editReasonSection = document.getElementById('editReasonSection');
    var editReasonInput = document.getElementById('editReason');
    if (record.ride_date < todayDate && userRole === 'Admin') {
        editReasonSection.style.display = 'block';
        editReasonInput.required = true;
        editReasonInput.value = '';
    } else {
        editReasonSection.style.display = 'none';
        editReasonInput.required = false;
        editReasonInput.value = '';
    }

    // 人数ボタン設定
    updatePassengerButtons(record.passenger_count);

    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
}

// 復路作成モーダル表示
function createReturnTrip(record) {
    document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-route me-2"></i>復路作成';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalRecordId').value = '';
    document.getElementById('modalIsReturnTrip').value = '1';
    document.getElementById('modalOriginalRideId').value = record.id;

    // 復路情報表示
    const returnTripInfo = document.getElementById('returnTripInfo');
    returnTripInfo.style.display = 'block';
    returnTripInfo.innerHTML = `
        <h6 class="mb-2"><i class="fas fa-route me-2"></i>復路作成</h6>
        <p class="mb-0">「${record.pickup_location} → ${record.dropoff_location}」の復路を作成します。</p>
        <p class="mb-0">乗車地と降車地が自動で入れ替わります。</p>
    `;

    // 基本情報をコピー（乗降地は入れ替え）
    document.getElementById('modalDriverId').value = record.driver_id;
    document.getElementById('modalVehicleId').value = record.vehicle_id;
    document.getElementById('modalRideDate').value = record.ride_date;
    document.getElementById('modalRideTime').value = getCurrentTime();
    document.getElementById('modalPassengerCount').value = record.passenger_count;

    // 乗降地を入れ替え
    document.getElementById('modalPickupLocation').value = record.dropoff_location;
    document.getElementById('modalDropoffLocation').value = record.pickup_location;

    // 復路では経由地をクリア
    clearWaypoints();

    document.getElementById('modalFare').value = record.fare;
    document.getElementById('modalCharge').value = record.charge;
    document.getElementById('modalTransportationType').value = record.transportation_type;
    document.getElementById('modalPaymentMethod').value = record.payment_method;
    document.getElementById('modalNotes').value = '';

    // 新規フィールド（復路は距離・時刻クリア、割引は往路を引き継ぐ）
    document.getElementById('modalDropoffTime').value = '';
    document.getElementById('modalRideDistance').value = '';
    document.getElementById('modalDisabilityDiscount').checked = (record.disability_discount == 1);
    document.getElementById('modalTicketAmount').value = record.ticket_amount || 0;

    // 人数ボタン設定
    updatePassengerButtons(record.passenger_count);

    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
}

// 削除確認
function deleteRecord(recordId, rideDate) {
    var isPastDate = (rideDate < todayDate);
    var deleteReason = '';

    // 一般ユーザーは当日データのみ削除可
    if (userRole !== 'Admin' && isPastDate) {
        showToast('過去日の記録は管理者のみ削除できます。管理者にお問い合わせください。', 'warning');
        return;
    }

    // Admin + 過去日: 理由入力を求める
    if (userRole === 'Admin' && isPastDate) {
        deleteReason = prompt('削除理由を入力してください（監査ログに記録されます）：');
        if (deleteReason === null) return; // キャンセル
        if (deleteReason.trim() === '') {
            showToast('削除理由を入力してください。', 'warning');
            return;
        }
    } else {
        if (!confirm('この乗車記録を削除しますか？')) return;
    }

    // CSRFトークンをフォームの既存hidden inputから取得
    var csrfToken = document.querySelector('input[name="csrf_token"]').value;

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="record_id" value="${recordId}">
        <input type="hidden" name="delete_reason" value="${escapeHtml(deleteReason)}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// 現在時刻を取得
function getCurrentTime() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

// 人数ボタンの更新
function updatePassengerButtons(count) {
    count = parseInt(count) || 1;
    document.getElementById('modalPassengerCount').value = count;
    document.querySelectorAll('.passenger-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.count == count) {
            btn.classList.add('active');
        }
    });
}

// === 経由地管理 ===
var waypointCounter = 0;

function addWaypoint(value) {
    waypointCounter++;
    var n = waypointCounter;
    var div = document.createElement('div');
    div.className = 'd-flex align-items-center gap-2 mb-2';
    div.id = 'waypoint-' + n;
    div.innerHTML =
        '<i class="fas fa-map-pin text-info"></i>' +
        '<input type="text" class="form-control form-control-sm unified-input" name="waypoints[]" ' +
        'placeholder="経由地を入力" value="' + (value || '') + '">' +
        '<button type="button" class="btn btn-outline-danger btn-sm" onclick="removeWaypoint(' + n + ')" title="削除">' +
        '<i class="fas fa-times"></i></button>';
    document.getElementById('waypointList').appendChild(div);
    if (!value) {
        div.querySelector('input').focus();
    }
}

function removeWaypoint(n) {
    var el = document.getElementById('waypoint-' + n);
    if (el) el.remove();
}

function clearWaypoints() {
    document.getElementById('waypointList').innerHTML = '';
    waypointCounter = 0;
}

// よく使う場所の候補表示
function showLocationSuggestions(input, type) {
    const query = input.value.toLowerCase().trim();
    const suggestionId = type === 'pickup' ? 'pickupSuggestions' : 'dropoffSuggestions';
    const suggestionsDiv = document.getElementById(suggestionId);

    // 空文字またはフォーカス時はよく使う場所を表示
    if (query.length === 0) {
        const topLocations = commonLocations.slice(0, 8);
        suggestionsDiv.innerHTML = '';

        if (topLocations.length > 0) {
            topLocations.forEach(location => {
                const div = document.createElement('div');
                div.className = 'unified-suggestion';
                div.innerHTML = `<i class="fas fa-map-marker-alt me-2 text-muted"></i>${escapeHtml(location)}`;
                div.onclick = () => selectLocation(input, location, suggestionsDiv);
                suggestionsDiv.appendChild(div);
            });
            suggestionsDiv.style.display = 'block';
        }
        return;
    }

    // 検索結果
    const filteredLocations = commonLocations.filter(location =>
        location.toLowerCase().includes(query)
    );

    if (filteredLocations.length === 0) {
        suggestionsDiv.style.display = 'none';
        return;
    }

    suggestionsDiv.innerHTML = '';
    filteredLocations.slice(0, 10).forEach(location => {
        const div = document.createElement('div');
        div.className = 'unified-suggestion';

        // 検索語をハイライト
        const escapedLocation = escapeHtml(location);
        const highlightedText = escapedLocation.replace(
            new RegExp(escapeHtml(query), 'gi'),
            `<mark>$&</mark>`
        );
        div.innerHTML = `<i class="fas fa-search me-2 text-muted"></i>${highlightedText}`;
        div.onclick = () => selectLocation(input, location, suggestionsDiv);
        suggestionsDiv.appendChild(div);
    });

    suggestionsDiv.style.display = 'block';
}

// 場所選択処理
function selectLocation(input, location, suggestionsDiv) {
    input.value = location;
    suggestionsDiv.style.display = 'none';
}

// イベントリスナー設定
document.addEventListener('DOMContentLoaded', function() {
    // 人数選択ボタンのイベント
    document.querySelectorAll('.passenger-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const count = this.dataset.count;
            document.getElementById('modalPassengerCount').value = count;

            // アクティブ状態の切り替え
            document.querySelectorAll('.passenger-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // 人数入力フィールドの変更時にボタンを更新
    document.getElementById('modalPassengerCount').addEventListener('input', function() {
        updatePassengerButtons(this.value);
    });

    // 場所入力フィールドのイベント設定
    ['modalPickupLocation', 'modalDropoffLocation'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            const type = id.includes('Pickup') ? 'pickup' : 'dropoff';

            input.addEventListener('keyup', function() {
                showLocationSuggestions(this, type);
            });

            input.addEventListener('focus', function() {
                showLocationSuggestions(this, type);
            });
        }
    });

    // 外部クリックで候補を閉じる
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.unified-dropdown')) {
            document.getElementById('pickupSuggestions').style.display = 'none';
            document.getElementById('dropoffSuggestions').style.display = 'none';
        }
    });
});

// フォーム送信前の確認
document.getElementById('rideForm').addEventListener('submit', function(e) {
    const action = document.getElementById('modalAction').value;
    const isReturnTrip = document.getElementById('modalIsReturnTrip').value === '1';

    let message = '';
    if (action === 'add' && isReturnTrip) {
        message = '復路の乗車記録を登録しますか？';
    } else if (action === 'add') {
        message = '乗車記録を登録しますか？';
    } else {
        message = '乗車記録を更新しますか？';
    }

    if (!confirm(message)) {
        e.preventDefault();
        return;
    }

    // 送信ボタンをローディング状態に
    var btn = this.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>保存中...';
    }
});

// beforeunload警告（未保存変更の検知）
var formDirty = false;
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('rideForm');
    if (form) {
        form.addEventListener('input', function() { formDirty = true; });
        form.addEventListener('change', function() { formDirty = true; });
        form.addEventListener('submit', function() { formDirty = false; });
    }
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) { e.preventDefault(); }
});
