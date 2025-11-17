// =================================================================
// カレンダー制御JavaScript
// 
// ファイル: /Smiley/taxi/wts/calendar/js/calendar.js
// 機能: FullCalendar初期化・イベント処理・表示制御
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

document.addEventListener('DOMContentLoaded', function() {
    // =================================================================
    // グローバル変数・設定
    // =================================================================
    
    let calendar;
    let currentConfig = window.calendarConfig || {};
    let isLoading = false;
    
    // =================================================================
    // カレンダー初期化
    // =================================================================
    
    function initializeCalendar() {
        const calendarEl = document.getElementById('calendar');
        
        if (!calendarEl) {
            console.error('カレンダー要素が見つかりません');
            return;
        }
        
        calendar = new FullCalendar.Calendar(calendarEl, {
            // 基本設定
            locale: 'ja',
            timeZone: 'Asia/Tokyo',
            height: 'auto',
            
            // 初期表示
            initialView: currentConfig.viewMode || 'dayGridMonth',
            initialDate: currentConfig.currentDate || new Date(),
            
            // ヘッダー設定
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            
            // ボタンテキスト
            buttonText: {
                today: '今日',
                month: '月',
                week: '週',
                day: '日'
            },
            
            // 表示設定
            weekends: true,
            navLinks: true,
            selectable: true,
            selectMirror: true,
            dayMaxEvents: 3,
            moreLinkText: function(num) {
                return '他 ' + num + ' 件';
            },
            
            // 営業時間設定
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5, 6], // 月-土
                startTime: '08:00',
                endTime: '18:00'
            },
            
            // 時間軸設定
            slotMinTime: '07:00:00',
            slotMaxTime: '19:00:00',
            slotDuration: '00:30:00',
            
            // イベントソース
            events: function(info, successCallback, failureCallback) {
                loadReservations(info.start, info.end, successCallback, failureCallback);
            },
            
            // イベントハンドラー
            eventClick: handleEventClick,
            select: handleDateSelect,
            eventDrop: handleEventDrop,
            eventResize: handleEventResize,
            datesSet: handleDatesChange,
            
            // カスタムレンダリング
            eventDidMount: customizeEventDisplay,
            
            // ローディング表示
            loading: function(bool) {
                toggleLoading(bool);
            }
        });
        
        calendar.render();
    }
    
    // =================================================================
    // データ読み込み
    // =================================================================
    
    function loadReservations(start, end, successCallback, failureCallback) {
        if (isLoading) return;

        isLoading = true;

        const params = new URLSearchParams({
            start: start.toISOString().split('T')[0],
            end: end.toISOString().split('T')[0],
            driver_id: currentConfig.driverFilter || 'all',
            view_type: currentConfig.viewMode || 'dayGridMonth'
        });
        
        fetch(`${currentConfig.apiUrls.getReservations}?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    successCallback(data.data);
                    updateSidebarStats(data.data);
                } else {
                    throw new Error(data.message || 'データ取得に失敗しました');
                }
            })
            .catch(error => {
                console.error('予約データ取得エラー:', error);
                failureCallback(error);
                showNotification('予約データの取得に失敗しました', 'error');
            })
            .finally(() => {
                isLoading = false;
            });
    }
    
    // =================================================================
    // イベントハンドラー
    // =================================================================
    
    function handleEventClick(info) {
        const event = info.event;
        const reservation = event.extendedProps;
        
        // 予約詳細を表示（編集モーダルを開く）
        if (currentConfig.accessLevel !== '閲覧のみ') {
            openReservationModal('edit', reservation);
        } else {
            showReservationDetails(reservation);
        }
    }
    
    function handleDateSelect(info) {
        // 新規予約作成
        if (currentConfig.accessLevel === '閲覧のみ') {
            showNotification('予約作成権限がありません', 'warning');
            calendar.unselect();
            return;
        }
        
        const selectedDate = info.start.toISOString().split('T')[0];
        const selectedTime = currentConfig.viewMode.includes('timeGrid') ?
            info.start.toTimeString().split(' ')[0].substring(0, 5) : '09:00';
        
        openReservationModal('create', {
            reservation_date: selectedDate,
            reservation_time: selectedTime
        });
        
        calendar.unselect();
    }
    
    function handleEventDrop(info) {
        // ドラッグ&ドロップによる予約移動
        const event = info.event;
        const newDate = event.start.toISOString().split('T')[0];
        const newTime = event.start.toTimeString().split(' ')[0].substring(0, 5);
        
        updateReservationDateTime(event.id, newDate, newTime, info);
    }
    
    function handleEventResize(info) {
        // リサイズによる時間変更
        const event = info.event;
        const endTime = event.end.toTimeString().split(' ')[0].substring(0, 5);
        
        // 終了時刻の更新（実装は簡易版）
        console.log('予約時間変更:', event.title, '終了時刻:', endTime);
    }
    
    function handleDatesChange(info) {
        // 表示期間変更時の処理
        // ビューが変更された場合、currentConfigを更新
        if (info.view && info.view.type) {
            currentConfig.viewMode = info.view.type;
        }
        updateSidebarContent(info.start, info.end);
    }
    
    // =================================================================
    // カスタム表示
    // =================================================================
    
    function customizeEventDisplay(info) {
        const event = info.event;
        const props = event.extendedProps;
        const element = info.el;
        
        // データ属性設定（CSS用）
        element.setAttribute('data-status', props.status);
        element.setAttribute('data-return-trip', props.isReturnTrip);
        element.setAttribute('data-rental-service', props.rentalService);
        element.setAttribute('data-time-critical', props.isTimeCritical);
        
        // ツールチップ設定
        const tooltip = createTooltipContent(props);
        element.setAttribute('title', tooltip);
        element.setAttribute('data-bs-toggle', 'tooltip');
        element.setAttribute('data-bs-placement', 'top');
        
        // Bootstrap tooltip初期化
        new bootstrap.Tooltip(element);
    }
    
    function createTooltipContent(reservation) {
        const lines = [
            `利用者: ${reservation.clientName}様`,
            `区間: ${reservation.pickupLocation} → ${reservation.dropoffLocation}`,
            `人数: ${reservation.passengerCount}名`,
            `種別: ${reservation.serviceType}`
        ];
        
        if (reservation.rentalService !== 'なし') {
            lines.push(`レンタル: ${reservation.rentalService}`);
        }
        
        if (reservation.driverName) {
            lines.push(`運転者: ${reservation.driverName}`);
        }
        
        if (reservation.vehicleInfo) {
            lines.push(`車両: ${reservation.vehicleInfo}`);
        }
        
        if (reservation.fare) {
            lines.push(`料金: ¥${reservation.fare.toLocaleString()}`);
        }
        
        lines.push(`状態: ${reservation.status}`);
        
        return lines.join('\n');
    }
    
    // =================================================================
    // 予約操作
    // =================================================================
    
    function updateReservationDateTime(reservationId, newDate, newTime, dropInfo) {
        const updateData = {
            id: reservationId,
            reservation_date: newDate,
            reservation_time: newTime
        };
        
        fetch(currentConfig.apiUrls.saveReservation, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(updateData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('予約時刻を更新しました', 'success');
                calendar.refetchEvents();
            } else {
                throw new Error(data.message || '更新に失敗しました');
            }
        })
        .catch(error => {
            console.error('予約更新エラー:', error);
            dropInfo.revert(); // 元の位置に戻す
            showNotification('予約時刻の更新に失敗しました', 'error');
        });
    }
    
    // =================================================================
    // サイドバー更新
    // =================================================================
    
    function updateSidebarStats(events) {
        const today = new Date().toISOString().split('T')[0];
        const todayEvents = events.filter(event => 
            event.start.split('T')[0] === today
        );
        
        // 本日の概要更新
        document.getElementById('todayReservationCount').textContent = `${todayEvents.length}件`;
        
        const completedCount = todayEvents.filter(event => 
            event.extendedProps.status === '完了'
        ).length;
        document.getElementById('todayCompletedCount').textContent = `${completedCount}件`;
        
        const inProgressCount = todayEvents.filter(event => 
            event.extendedProps.status === '進行中'
        ).length;
        document.getElementById('todayInProgressCount').textContent = `${inProgressCount}件`;
        
        // 車両・運転者状況更新
        updateVehicleStatus(events);
        updateDriverStatus(events);
    }
    
    function updateSidebarContent(start, end) {
        // よく使う場所の更新
        loadFrequentLocations();
    }
    
    function updateVehicleStatus(events) {
        const vehicleStatusArea = document.getElementById('vehicleStatusArea');
        if (!vehicleStatusArea) return;
        
        const vehicles = currentConfig.vehicles || [];
        let html = '';
        
        vehicles.forEach(vehicle => {
            const vehicleEvents = events.filter(event => 
                event.extendedProps.vehicleInfo && 
                event.extendedProps.vehicleInfo.includes(vehicle.vehicle_number)
            );
            
            const status = vehicleEvents.length > 0 ? 'busy' : 'available';
            const statusText = status === 'busy' ? '稼働中' : '待機中';
            const statusClass = status === 'busy' ? 'bg-warning' : 'bg-success';
            
            html += `
                <div class="vehicle-item">
                    <span>🚐 ${vehicle.vehicle_number} (${vehicle.model})</span>
                    <span class="badge ${statusClass}">${statusText}</span>
                </div>
            `;
        });
        
        vehicleStatusArea.innerHTML = html;
    }
    
    function updateDriverStatus(events) {
        const driverStatusArea = document.getElementById('driverStatusArea');
        if (!driverStatusArea) return;
        
        const drivers = currentConfig.drivers || [];
        let html = '';
        
        drivers.forEach(driver => {
            const driverEvents = events.filter(event => 
                event.extendedProps.driverName === driver.full_name
            );
            
            const status = driverEvents.length > 0 ? 'busy' : 'available';
            const statusText = status === 'busy' ? `${driverEvents.length}件` : '待機中';
            const statusClass = status === 'busy' ? 'bg-warning' : 'bg-success';
            
            html += `
                <div class="driver-item">
                    <span>👤 ${driver.full_name}</span>
                    <span class="badge ${statusClass}">${statusText}</span>
                </div>
            `;
        });
        
        driverStatusArea.innerHTML = html;
    }
    
    function loadFrequentLocations() {
        const frequentLocationsArea = document.getElementById('frequentLocationsArea');
        if (!frequentLocationsArea) return;
        
        // 簡易版：固定データを表示
        // 実際の実装ではAPIから取得
        const locations = [
            { name: '大阪市立総合医療センター', type: '病院', usage_count: 15 },
            { name: '大阪大学医学部附属病院', type: '病院', usage_count: 12 },
            { name: '大阪駅', type: '駅', usage_count: 8 },
            { name: 'ケアハウス大阪', type: '施設', usage_count: 6 }
        ];
        
        let html = '';
        locations.forEach(location => {
            html += `
                <div class="frequent-location-item" onclick="useFrequentLocation('${location.name}')">
                    <div class="location-name">${location.name}</div>
                    <div>
                        <span class="location-type">${location.type}</span>
                        <span class="usage-count">${location.usage_count}回</span>
                    </div>
                </div>
            `;
        });
        
        frequentLocationsArea.innerHTML = html;
    }
    
    // =================================================================
    // UI制御
    // =================================================================
    
    function toggleLoading(isLoading) {
        const calendar = document.getElementById('calendar');
        if (isLoading) {
            calendar.style.opacity = '0.6';
            calendar.style.pointerEvents = 'none';
        } else {
            calendar.style.opacity = '1';
            calendar.style.pointerEvents = 'auto';
        }
    }
    
    function showNotification(message, type = 'info') {
        // 簡易通知システム
        const alertClass = type === 'error' ? 'alert-danger' : 
                          type === 'warning' ? 'alert-warning' :
                          type === 'success' ? 'alert-success' : 'alert-info';
        
        const notification = document.createElement('div');
        notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // 5秒後に自動削除
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }
    
    // =================================================================
    // イベントリスナー設定
    // =================================================================
    
    function setupEventListeners() {
        // 表示モード切り替え
        document.querySelectorAll('input[name="viewMode"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    calendar.changeView(this.value);
                    currentConfig.viewMode = this.value;
                }
            });
        });
        
        // 運転者フィルター
        const driverFilter = document.getElementById('driverFilter');
        if (driverFilter) {
            driverFilter.addEventListener('change', function() {
                currentConfig.driverFilter = this.value;
                calendar.refetchEvents();
            });
        }
        
        // ナビゲーションボタン
        const todayBtn = document.getElementById('todayBtn');
        if (todayBtn) {
            todayBtn.addEventListener('click', () => calendar.today());
        }
        
        const prevBtn = document.getElementById('prevBtn');
        if (prevBtn) {
            prevBtn.addEventListener('click', () => calendar.prev());
        }
        
        const nextBtn = document.getElementById('nextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', () => calendar.next());
        }
        
        // 新規予約作成ボタン
        const createBtn = document.getElementById('createReservationBtn');
        if (createBtn) {
            createBtn.addEventListener('click', function() {
                const today = new Date().toISOString().split('T')[0];
                openReservationModal('create', {
                    reservation_date: today,
                    reservation_time: '09:00'
                });
            });
        }
    }
    
    // =================================================================
    // 外部関数（他のJSファイルから呼び出し可能）
    // =================================================================
    
    window.calendarMethods = {
        refreshEvents: () => calendar.refetchEvents(),
        gotoDate: (date) => calendar.gotoDate(date),
        getCurrentView: () => calendar ? calendar.view.type : currentConfig.viewMode,
        addEvent: (eventData) => calendar.addEvent(eventData),
        removeEvent: (eventId) => {
            const event = calendar.getEventById(eventId);
            if (event) event.remove();
        },
        showNotification: showNotification
    };
    
    // よく使う場所クリック処理
    window.useFrequentLocation = function(locationName) {
        // 予約フォームが開いている場合に場所を設定
        const activeInput = document.querySelector('#reservationModal input:focus');
        if (activeInput && (activeInput.id === 'pickupLocation' || activeInput.id === 'dropoffLocation')) {
            activeInput.value = locationName;
            activeInput.dispatchEvent(new Event('input'));
        }
    };
    
    // =================================================================
    // 初期化実行
    // =================================================================
    
    initializeCalendar();
    setupEventListeners();
    
    // 初期データ読み込み
    setTimeout(() => {
        loadFrequentLocations();
    }, 1000);
});

// =================================================================
// ユーティリティ関数
// =================================================================

function formatDateTime(dateString, timeString) {
    const date = new Date(`${dateString}T${timeString}`);
    return date.toLocaleString('ja-JP', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('ja-JP', {
        style: 'currency',
        currency: 'JPY'
    }).format(amount);
}
