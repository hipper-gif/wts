// =================================================================
// 予約作成・編集JavaScript
// 
// ファイル: /Smiley/taxi/wts/calendar/js/reservation.js
// 機能: 予約モーダル制御・フォーム処理・API通信・復路作成
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

document.addEventListener('DOMContentLoaded', function() {
    // =================================================================
    // グローバル変数
    // =================================================================
    
    let reservationModal;
    let currentMode = 'create'; // 'create' or 'edit'
    let currentReservation = null;
    let isSubmitting = false;
    
    // =================================================================
    // モーダル初期化
    // =================================================================
    
    function initializeReservationModal() {
        const modalElement = document.getElementById('reservationModal');
        if (!modalElement) return;
        
        reservationModal = new bootstrap.Modal(modalElement);
        
        // モーダルイベントリスナー
        modalElement.addEventListener('shown.bs.modal', handleModalShown);
        modalElement.addEventListener('hidden.bs.modal', handleModalHidden);
        
        // フォームイベントリスナー
        setupFormEventListeners();
    }
    
    function setupFormEventListeners() {
        // 保存ボタン
        const saveBtn = document.getElementById('saveReservationBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', handleSaveReservation);
        }
        
        // 保存して復路作成ボタン
        const saveAndReturnBtn = document.getElementById('saveAndCreateReturnBtn');
        if (saveAndReturnBtn) {
            saveAndReturnBtn.addEventListener('click', handleSaveAndCreateReturn);
        }
        
        // フォーム要素のリアルタイム検証
        const form = document.getElementById('reservationForm');
        if (form) {
            form.addEventListener('input', handleFormInput);
            form.addEventListener('change', handleFormChange);
        }
        
        // 場所入力の候補表示
        setupLocationSuggestions();
        
        // 車両・レンタルサービス制約チェック
        setupVehicleConstraints();
    }
    
    // =================================================================
    // モーダル開閉処理
    // =================================================================
    
    function openReservationModal(mode, data = {}) {
        currentMode = mode;
        currentReservation = data;
        
        // モーダルタイトル設定
        const title = mode === 'create' ? '新規予約作成' : '予約編集';
        document.getElementById('reservationModalTitle').textContent = title;
        
        // フォーム初期化
        resetForm();
        
        // データ設定
        if (mode === 'edit' && data) {
            populateForm(data);
        } else if (data.reservation_date || data.reservation_time) {
            setFormDefaults(data);
        }
        
        // 復路作成ボタンの表示制御
        updateReturnTripButton();
        
        // モーダル表示
        reservationModal.show();
    }
    
    function handleModalShown() {
        // フォーカス設定
        const firstInput = document.querySelector('#reservationForm input:not([type="hidden"])');
        if (firstInput) {
            firstInput.focus();
        }
        
        // 空き状況チェック
        if (currentMode === 'create') {
            checkAvailability();
        }
    }
    
    function handleModalHidden() {
        // フォームリセット
        resetForm();
        currentReservation = null;
        
        // 制約警告クリア
        clearConstraintWarnings();
    }
    
    // =================================================================
    // フォーム操作
    // =================================================================
    
    function resetForm() {
        const form = document.getElementById('reservationForm');
        if (form) {
            form.reset();
        }
        
        // 隠しフィールドクリア
        document.getElementById('reservationId').value = '';
        document.getElementById('parentReservationId').value = '';
        
        // エラー表示クリア
        clearFormErrors();
        clearConstraintWarnings();
        
        // 復路表示クリア
        const returnIndicator = document.querySelector('.return-trip-indicator');
        if (returnIndicator) {
            returnIndicator.remove();
        }
    }
    
    function populateForm(data) {
        // 基本情報
        setFormValue('reservationId', data.id || data.reservationId);
        setFormValue('reservationDate', data.reservation_date);
        setFormValue('reservationTime', data.reservation_time);
        setFormValue('clientName', data.client_name || data.clientName);
        setFormValue('pickupLocation', data.pickup_location || data.pickupLocation);
        setFormValue('dropoffLocation', data.dropoff_location || data.dropoffLocation);
        
        // 詳細情報
        setFormValue('passengerCount', data.passenger_count || data.passengerCount || 1);
        setFormValue('serviceType', data.service_type || data.serviceType);
        setFormValue('driverId', data.driver_id || data.driverId);
        setFormValue('vehicleId', data.vehicle_id || data.vehicleId);
        
        // レンタル・支援
        setFormValue('rentalService', data.rental_service || data.rentalService || 'なし');
        setFormCheckbox('entranceAssistance', data.entrance_assistance);
        setFormCheckbox('disabilityCard', data.disability_card);
        setFormCheckbox('careServiceUser', data.care_service_user);
        setFormValue('hospitalEscortStaff', data.hospital_escort_staff);
        setFormValue('dualAssistanceStaff', data.dual_assistance_staff);
        
        // 紹介者情報
        setFormValue('referrerType', data.referrer_type || data.referrerType);
        setFormValue('referrerName', data.referrer_name || data.referrerName);
        setFormValue('referrerContact', data.referrer_contact || data.referrerContact);
        setFormCheckbox('isTimeCritical', data.is_time_critical !== false);
        
        // 料金・備考
        setFormValue('estimatedFare', data.estimated_fare || data.estimatedFare);
        setFormValue('actualFare', data.actual_fare || data.actualFare);
        setFormValue('paymentMethod', data.payment_method || data.paymentMethod || '現金');
        setFormValue('specialNotes', data.special_notes || data.specialNotes);
        
        // 復路情報
        if (data.is_return_trip || data.isReturnTrip) {
            setFormValue('parentReservationId', data.parent_reservation_id || data.parentReservationId);
            showReturnTripIndicator(data);
        }
    }
    
    function setFormDefaults(data) {
        if (data.reservation_date) {
            setFormValue('reservationDate', data.reservation_date);
        }
        if (data.reservation_time) {
            setFormValue('reservationTime', data.reservation_time);
        }
        
        // デフォルト値設定
        setFormValue('passengerCount', 1);
        setFormValue('serviceType', 'お迎え');
        setFormValue('rentalService', 'なし');
        setFormValue('referrerType', 'CM');
        setFormValue('paymentMethod', '現金');
        setFormCheckbox('isTimeCritical', true);
    }
    
    function setFormValue(elementId, value) {
        const element = document.getElementById(elementId);
        if (element && value !== undefined && value !== null) {
            element.value = value;
        }
    }
    
    function setFormCheckbox(elementId, checked) {
        const element = document.getElementById(elementId);
        if (element) {
            element.checked = !!checked;
        }
    }
    
    // =================================================================
    // フォーム検証・入力処理
    // =================================================================
    
    function handleFormInput(event) {
        const target = event.target;
        
        // リアルタイム検証
        validateField(target);
        
        // 場所候補表示
        if (target.id === 'pickupLocation' || target.id === 'dropoffLocation') {
            showLocationSuggestions(target);
        }
    }
    
    function handleFormChange(event) {
        const target = event.target;
        
        // 車両制約チェック
        if (target.id === 'rentalService' || target.id === 'vehicleId') {
            checkVehicleConstraints();
        }
        
        // サービス種別による復路ボタン制御
        if (target.id === 'serviceType') {
            updateReturnTripButton();
        }
        
        // 空き状況チェック
        if (target.id === 'reservationDate' || target.id === 'reservationTime' || 
            target.id === 'driverId' || target.id === 'vehicleId') {
            debounce(checkAvailability, 500)();
        }
    }
    
    function validateField(field) {
        clearFieldError(field);
        
        if (field.hasAttribute('required') && !field.value.trim()) {
            showFieldError(field, 'この項目は必須です');
            return false;
        }
        
        // 個別検証
        switch (field.id) {
            case 'reservationDate':
                return validateDate(field);
            case 'reservationTime':
                return validateTime(field);
            case 'clientName':
                return validateClientName(field);
            case 'estimatedFare':
            case 'actualFare':
                return validateFare(field);
        }
        
        return true;
    }
    
    function validateDate(field) {
        const date = new Date(field.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (date < today) {
            showFieldError(field, '過去の日付は選択できません');
            return false;
        }
        
        return true;
    }
    
    function validateTime(field) {
        const time = field.value;
        const hour = parseInt(time.split(':')[0]);
        
        if (hour < 7 || hour > 19) {
            showFieldError(field, '営業時間外です（7:00-19:00）');
            return false;
        }
        
        return true;
    }
    
    function validateClientName(field) {
        const name = field.value.trim();
        
        if (name.length < 2) {
            showFieldError(field, '2文字以上で入力してください');
            return false;
        }
        
        return true;
    }
    
    function validateFare(field) {
        const fare = parseInt(field.value);
        
        if (field.value && (isNaN(fare) || fare < 0)) {
            showFieldError(field, '正しい金額を入力してください');
            return false;
        }
        
        return true;
    }
    
    // =================================================================
    // 場所候補表示
    // =================================================================
    
    function setupLocationSuggestions() {
        const pickupLocation = document.getElementById('pickupLocation');
        const dropoffLocation = document.getElementById('dropoffLocation');
        
        if (pickupLocation) {
            setupLocationSuggestionsForField(pickupLocation);
        }
        if (dropoffLocation) {
            setupLocationSuggestionsForField(dropoffLocation);
        }
    }
    
    function setupLocationSuggestionsForField(field) {
        // 候補表示用要素作成
        const suggestions = document.createElement('div');
        suggestions.className = 'location-suggestions';
        suggestions.id = field.id + 'Suggestions';
        
        field.parentElement.style.position = 'relative';
        field.parentElement.appendChild(suggestions);
        
        // イベントリスナー
        field.addEventListener('focus', () => showLocationSuggestions(field));
        field.addEventListener('blur', () => {
            setTimeout(() => hideLocationSuggestions(field), 200);
        });
    }
    
    function showLocationSuggestions(field) {
        const query = field.value.trim();
        const suggestions = document.getElementById(field.id + 'Suggestions');
        
        if (!suggestions) return;
        
        if (query.length < 1) {
            // よく使う場所を表示
            showFrequentLocations(suggestions, field);
        } else {
            // 検索結果を表示
            searchLocations(query, suggestions, field);
        }
    }
    
    function showFrequentLocations(suggestions, field) {
        // 固定データ（実際の実装では API から取得）
        const locations = [
            { name: '大阪市立総合医療センター', type: '病院', address: '大阪市都島区都島本通2-13-22' },
            { name: '大阪大学医学部附属病院', type: '病院', address: '大阪府吹田市山田丘2-15' },
            { name: '大阪駅', type: '駅', address: '大阪市北区梅田3丁目1-1' },
            { name: 'ケアハウス大阪', type: '施設', address: '大阪市中央区南船場1-3-5' }
        ];
        
        showLocationSuggestionsList(locations, suggestions, field);
    }
    
    function searchLocations(query, suggestions, field) {
        // 簡易検索（実際の実装では API 呼び出し）
        const allLocations = [
            { name: '大阪市立総合医療センター', type: '病院', address: '大阪市都島区都島本通2-13-22' },
            { name: '大阪大学医学部附属病院', type: '病院', address: '大阪府吹田市山田丘2-15' },
            { name: '関西医科大学附属病院', type: '病院', address: '大阪府枚方市新町2-5-1' },
            { name: '大阪駅', type: '駅', address: '大阪市北区梅田3丁目1-1' },
            { name: '新大阪駅', type: '駅', address: '大阪市淀川区西中島5-16-1' },
            { name: 'ケアハウス大阪', type: '施設', address: '大阪市中央区南船場1-3-5' },
            { name: 'グループホームさくら', type: '施設', address: '大阪市福島区福島2-8-15' }
        ];
        
        const filtered = allLocations.filter(loc => 
            loc.name.includes(query) || loc.address.includes(query)
        );
        
        showLocationSuggestionsList(filtered, suggestions, field);
    }
    
    function showLocationSuggestionsList(locations, suggestions, field) {
        let html = '';
        
        locations.forEach(location => {
            html += `
                <div class="location-suggestion-item" onclick="selectLocation('${field.id}', '${location.name}')">
                    <div class="suggestion-name">${location.name}</div>
                    <div>
                        <span class="suggestion-type">${location.type}</span>
                        <span class="suggestion-address">${location.address}</span>
                    </div>
                </div>
            `;
        });
        
        suggestions.innerHTML = html;
        suggestions.classList.add('show');
    }
    
    function hideLocationSuggestions(field) {
        const suggestions = document.getElementById(field.id + 'Suggestions');
        if (suggestions) {
            suggestions.classList.remove('show');
        }
    }
    
    // 場所選択処理
    window.selectLocation = function(fieldId, locationName) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = locationName;
            field.dispatchEvent(new Event('input'));
            hideLocationSuggestions(field);
        }
    };
    
    // =================================================================
    // 車両制約チェック
    // =================================================================
    
    function setupVehicleConstraints() {
        // 既に handleFormChange で処理済み
    }
    
    function checkVehicleConstraints() {
        const rentalService = document.getElementById('rentalService').value;
        const vehicleId = document.getElementById('vehicleId').value;
        
        clearConstraintWarnings();
        
        if (!vehicleId || !rentalService || rentalService === 'なし') {
            return;
        }
        
        // 車両情報取得
        const vehicles = window.calendarConfig.vehicles || [];
        const selectedVehicle = vehicles.find(v => v.id == vehicleId);
        
        if (!selectedVehicle) return;
        
        // ストレッチャーはハイエースのみ
        if (rentalService === 'ストレッチャー' && selectedVehicle.model !== 'ハイエース') {
            showConstraintError('ストレッチャーはハイエースのみ対応可能です。車両を変更してください。');
            return;
        }
        
        // 制約なし
        showConstraintSuccess('車両とレンタルサービスの組み合わせに問題ありません。');
    }
    
    function showConstraintError(message) {
        const container = document.getElementById('vehicleId').parentElement;
        const error = document.createElement('div');
        error.className = 'constraint-error show';
        error.innerHTML = `
            <i class="fas fa-exclamation-triangle error-icon"></i>
            <span class="error-text">${message}</span>
        `;
        container.appendChild(error);
    }
    
    function showConstraintWarning(message) {
        const container = document.getElementById('vehicleId').parentElement;
        const warning = document.createElement('div');
        warning.className = 'constraint-warning show';
        warning.innerHTML = `
            <i class="fas fa-exclamation-circle warning-icon"></i>
            <span class="warning-text">${message}</span>
        `;
        container.appendChild(warning);
    }
    
    function showConstraintSuccess(message) {
        // 成功メッセージは表示しない（ユーザビリティ向上）
    }
    
    function clearConstraintWarnings() {
        document.querySelectorAll('.constraint-warning, .constraint-error').forEach(el => {
            el.remove();
        });
    }
    
    // =================================================================
    // 空き状況チェック
    // =================================================================
    
    function checkAvailability() {
        const date = document.getElementById('reservationDate').value;
        const time = document.getElementById('reservationTime').value;
        const rentalService = document.getElementById('rentalService').value || 'なし';
        const excludeId = document.getElementById('reservationId').value;
        
        if (!date || !time) return;
        
        const params = new URLSearchParams({
            date: date,
            time: time,
            rental_service: rentalService,
            exclude_id: excludeId
        });
        
        fetch(`${window.calendarConfig.apiUrls.getAvailability}?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateAvailabilityDisplay(data.data);
                }
            })
            .catch(error => {
                console.error('空き状況確認エラー:', error);
            });
    }
    
    function updateAvailabilityDisplay(availability) {
        // 運転者選択肢の更新
        updateDriverOptions(availability.available_drivers);
        
        // 車両選択肢の更新
        updateVehicleOptions(availability.available_vehicles);
        
        // 推奨表示
        if (availability.recommendations) {
            showRecommendations(availability.recommendations);
        }
    }
    
    function updateDriverOptions(drivers) {
        const select = document.getElementById('driverId');
        const currentValue = select.value;
        
        // オプション更新
        Array.from(select.options).forEach((option, index) => {
            if (index === 0) return; // "選択してください"はスキップ
            
            const driverId = parseInt(option.value);
            const driver = drivers.find(d => d.id === driverId);
            
            if (driver) {
                option.disabled = !driver.is_available;
                option.textContent = `${driver.full_name} (${driver.workload})`;
                
                if (!driver.is_available) {
                    option.textContent += ' - 予約済み';
                }
            }
        });
        
        // 選択値が利用不可の場合はクリア
        const selectedDriver = drivers.find(d => d.id == currentValue);
        if (selectedDriver && !selectedDriver.is_available) {
            select.value = '';
        }
    }
    
    function updateVehicleOptions(vehicles) {
        const select = document.getElementById('vehicleId');
        const currentValue = select.value;
        
        // オプション更新
        Array.from(select.options).forEach((option, index) => {
            if (index === 0) return; // "選択してください"はスキップ
            
            const vehicleId = parseInt(option.value);
            const vehicle = vehicles.find(v => v.id === vehicleId);
            
            if (vehicle) {
                option.disabled = !vehicle.is_available || !vehicle.supports_rental_service;
                
                let text = `${vehicle.vehicle_number} (${vehicle.model})`;
                if (!vehicle.is_available) {
                    text += ' - 予約済み';
                } else if (!vehicle.supports_rental_service) {
                    text += ' - 非対応';
                }
                
                option.textContent = text;
            }
        });
        
        // 選択値が利用不可の場合はクリア
        const selectedVehicle = vehicles.find(v => v.id == currentValue);
        if (selectedVehicle && (!selectedVehicle.is_available || !selectedVehicle.supports_rental_service)) {
            select.value = '';
        }
    }
    
    function showRecommendations(recommendations) {
        // 推奨表示は簡易版のみ実装
        if (recommendations.time_suggestion) {
            console.log('時間推奨:', recommendations.time_suggestion.reason);
        }
    }
    
    // =================================================================
    // 保存処理
    // =================================================================
    
    function handleSaveReservation() {
        if (isSubmitting) return;
        
        if (!validateForm()) {
            showNotification('入力内容に不備があります', 'error');
            return;
        }
        
        const formData = collectFormData();
        saveReservation(formData);
    }
    
    function handleSaveAndCreateReturn() {
        if (isSubmitting) return;
        
        if (!validateForm()) {
            showNotification('入力内容に不備があります', 'error');
            return;
        }
        
        const formData = collectFormData();
        saveReservation(formData, true);
    }
    
    function validateForm() {
        const form = document.getElementById('reservationForm');
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!validateField(field)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    function collectFormData() {
        const form = document.getElementById('reservationForm');
        const formData = new FormData(form);
        const data = {};

        // フィールド名のマッピング（キャメルケース → スネークケース）
        const fieldMapping = {
            'reservationId': 'id',
            'reservationDate': 'reservation_date',
            'reservationTime': 'reservation_time',
            'clientName': 'client_name',
            'pickupLocation': 'pickup_location',
            'dropoffLocation': 'dropoff_location',
            'passengerCount': 'passenger_count',
            'serviceType': 'service_type',
            'driverId': 'driver_id',
            'vehicleId': 'vehicle_id',
            'rentalService': 'rental_service',
            'hospitalEscortStaff': 'hospital_escort_staff',
            'dualAssistanceStaff': 'dual_assistance_staff',
            'referrerType': 'referrer_type',
            'referrerName': 'referrer_name',
            'referrerContact': 'referrer_contact',
            'estimatedFare': 'estimated_fare',
            'actualFare': 'actual_fare',
            'paymentMethod': 'payment_method',
            'specialNotes': 'special_notes',
            'parentReservationId': 'parent_reservation_id'
        };

        // 基本データ収集（フィールド名を変換）
        for (let [key, value] of formData.entries()) {
            const mappedKey = fieldMapping[key] || key;
            data[mappedKey] = value;
        }

        // チェックボックスの処理
        data.entrance_assistance = document.getElementById('entranceAssistance').checked;
        data.disability_card = document.getElementById('disabilityCard').checked;
        data.care_service_user = document.getElementById('careServiceUser').checked;
        data.is_time_critical = document.getElementById('isTimeCritical').checked;

        // 空値の処理
        Object.keys(data).forEach(key => {
            if (data[key] === '') {
                delete data[key];
            }
        });
        
        return data;
    }
    
    function saveReservation(data, createReturn = false) {
        isSubmitting = true;
        setFormLoading(true);

        console.log('予約保存データ:', data);

        fetch(window.calendarConfig.apiUrls.saveReservation, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            console.log('APIレスポンスステータス:', response.status);

            // 常にテキストとして読み取り、JSONか確認
            return response.text().then(text => {
                console.log('API生レスポンス (最初の500文字):', text.substring(0, 500));

                // HTMLエラーやPHP警告が含まれているかチェック
                if (text.trim().startsWith('<') || text.includes('<br />') || text.includes('Warning:') || text.includes('Notice:') || text.includes('Fatal error:')) {
                    console.error('=== APIエラーレスポンス（HTML/PHP Error）===');
                    console.error(text);
                    console.error('=== エラー終了 ===');
                    throw new Error('サーバーエラーが発生しました。詳細はコンソールを確認してください。');
                }

                // JSONとしてパース
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON解析エラー:', e);
                    console.error('レスポンステキスト:', text);
                    throw new Error('無効なレスポンス形式です');
                }
            });
        })
        .then(result => {
            console.log('API結果:', result);

            if (result.success) {
                showNotification(result.message || '予約を保存しました', 'success');

                // カレンダー更新
                if (window.calendarMethods) {
                    window.calendarMethods.refreshEvents();
                }

                const resData = result.data || result;
                if (createReturn && resData.can_create_return) {
                    // 復路作成処理
                    createReturnTrip(resData.reservation_id);
                } else {
                    // モーダル閉じる
                    reservationModal.hide();
                }
            } else {
                throw new Error(result.message || '保存に失敗しました');
            }
        })
        .catch(error => {
            console.error('保存エラー:', error);
            showNotification(error.message, 'error');
        })
        .finally(() => {
            isSubmitting = false;
            setFormLoading(false);
        });
    }
    
    // =================================================================
    // 復路作成
    // =================================================================
    
    function createReturnTrip(parentReservationId) {
        const hoursLater = 3; // デフォルト3時間後
        
        fetch(window.calendarConfig.apiUrls.createReturnTrip, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                parent_reservation_id: parentReservationId,
                hours_later: hoursLater
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showNotification(result.message, 'success');
                
                // カレンダー更新
                if (window.calendarMethods) {
                    window.calendarMethods.refreshEvents();
                }
                
                // モーダル閉じる
                reservationModal.hide();
            } else {
                throw new Error(result.message || '復路作成に失敗しました');
            }
        })
        .catch(error => {
            console.error('復路作成エラー:', error);
            showNotification(error.message, 'error');
        });
    }
    
    function updateReturnTripButton() {
        const serviceType = document.getElementById('serviceType').value;
        const isReturnTrip = document.getElementById('parentReservationId').value;
        const saveAndReturnBtn = document.getElementById('saveAndCreateReturnBtn');
        
        if (saveAndReturnBtn) {
            // 復路でない新規予約の場合に表示
            const show = !isReturnTrip && currentMode === 'create';
            saveAndReturnBtn.style.display = show ? 'inline-block' : 'none';
        }
    }
    
    function showReturnTripIndicator(data) {
        const form = document.getElementById('reservationForm');
        const indicator = document.createElement('div');
        indicator.className = 'return-trip-indicator';
        indicator.innerHTML = `
            <i class="fas fa-undo icon"></i>
            この予約は復路です（往路ID: ${data.parent_reservation_id || data.parentReservationId}）
        `;
        
        form.insertBefore(indicator, form.firstChild);
    }
    
    // =================================================================
    // UI制御
    // =================================================================
    
    function setFormLoading(loading) {
        const form = document.getElementById('reservationForm');
        const buttons = document.querySelectorAll('#reservationModal .modal-footer .btn');
        
        if (loading) {
            form.classList.add('form-loading');
            buttons.forEach(btn => btn.disabled = true);
        } else {
            form.classList.remove('form-loading');
            buttons.forEach(btn => btn.disabled = false);
        }
    }
    
    function showFieldError(field, message) {
        clearFieldError(field);
        
        field.classList.add('is-invalid');
        
        const error = document.createElement('div');
        error.className = 'invalid-feedback';
        error.textContent = message;
        
        field.parentElement.appendChild(error);
    }
    
    function clearFieldError(field) {
        field.classList.remove('is-invalid');
        
        const error = field.parentElement.querySelector('.invalid-feedback');
        if (error) {
            error.remove();
        }
    }
    
    function clearFormErrors() {
        document.querySelectorAll('.is-invalid').forEach(field => {
            clearFieldError(field);
        });
    }
    
    function showNotification(message, type = 'info') {
        if (window.calendarMethods) {
            window.calendarMethods.showNotification(message, type);
        }
    }
    
    // =================================================================
    // 外部公開関数
    // =================================================================
    
    window.openReservationModal = openReservationModal;
    
    window.showReservationDetails = function(reservation) {
        // 詳細表示のみ（簡易版）
        const details = `
            利用者: ${reservation.clientName}様
            日時: ${reservation.reservation_date} ${reservation.reservation_time}
            区間: ${reservation.pickupLocation} → ${reservation.dropoffLocation}
            状態: ${reservation.status}
        `;
        alert(details);
    };
    
    // =================================================================
    // ユーティリティ関数
    // =================================================================
    
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // =================================================================
    // 初期化
    // =================================================================
    
    initializeReservationModal();
});
