-- ============================================================
-- WTS テナント基盤スキーマ（新テナント用・構造のみ / データなし）
--
-- 生成元: 稼働中の twinklemark_wts（Smiley本番）を 2026-09-18 に mysqldump
--         ＋ 2026-09-24 sql/021（users の配車4列）・2026-09-25 sql/022（customers.residence_location_id）・2026-09-26 sql/023（vehicles.shaken_expiry_date）を手で反映
-- 収録:   37テーブル + トリガー4件（arrival_records の走行距離・車両積算距離の自動計算）。
--         dispatch_* 8テーブルは除外済み。これは HaiGO（配車PWA）のテーブルで、
--         WTSのDBに同居している。HaiGO がまだ Smiley 専用なので外してある。
--         HaiGO も同梱するテナントなら、この除外を見直すこと
--
-- なぜ必要か:
--   sql/ の連番マイグレーションは 003 から始まり、土台となる基本テーブル
--   （users・vehicles・ride_records 等）を作るSQLがrepoに無い。さらに
--   migration_history と実スキーマがずれており（vehicles の form21 列は
--   履歴に無いまま本番に存在）、マイグレーションの積み上げでは現行スキーマ
--   を再現できない。空のDBから新テナントを立てる土台はこのファイルが正本。
--
-- ⚠️ DEFINER句は必ず削る:
--   mysqldump が出すトリガーには DEFINER=`twinklemark_app`@`localhost` 等が付く。
--   これを残したまま流すと ERROR 1227 (SUPER/SET USER 権限が要る) で
--   **途中で止まり、テーブルが3つだけ出来た半端なDBが残る**。
--   共有サーバーのDBユーザーにその権限は無い。下の再生成コマンドの sed が該当処理。
--
-- 使い方（空のDBに対して・先頭で流す）:
--   mysql -u <user> -p <DB名> < 000_schema.sql
--   mysql -u <user> -p <DB名> < 001_seed.sql
--   php sql/run_migration.php --baseline    # 連番SQLを適用済みとして登録
--
-- 更新のしかた: 本番スキーマを変えたら、このファイルも取り直す。
--   (1) mysqldump --no-data --skip-add-drop-table --skip-comments --triggers
--       --default-character-set=utf8mb4 --ignore-table=<DB>.dispatch_<各表> <DB>
--   (2) その出力を sed -E 's/ AUTO_INCREMENT=[0-9]+//' に通す
--   (3) さらに sed -E 's|/\*!50017 DEFINER=[^*]*\*/ ||g' に通してDEFINERを削る
--
-- 検証記録: 2026-09-18 に空DB(twinklemark_wtsverify)へ実投入し、雛形元と
--   テーブル37・列構成・トリガー4・外部キー54がすべて一致することを確認済み。
-- ============================================================

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accident_date` date NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `ride_record_id` int(11) DEFAULT NULL,
  `accident_type` enum('交通事故','重大事故','ヒヤリハット','その他') NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `deaths` int(11) DEFAULT 0,
  `injuries` int(11) DEFAULT 0,
  `property_damage` tinyint(1) DEFAULT 0,
  `police_report` tinyint(1) DEFAULT 0,
  `insurance_claim` tinyint(1) DEFAULT 0,
  `prevention_measures` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_accident_date` (`accident_date`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `driver_id` (`driver_id`),
  KEY `idx_accident_ride_record` (`ride_record_id`),
  CONSTRAINT `accidents_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `accidents_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `annual_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_year` int(11) NOT NULL,
  `report_type` varchar(50) NOT NULL,
  `submission_date` date DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `status` enum('未作成','作成中','確認中','提出済み') DEFAULT '未作成',
  `memo` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_year_type` (`fiscal_year`,`report_type`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `annual_reports_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `arrival_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `departure_record_id` int(11) DEFAULT NULL COMMENT '対応する出庫記録ID',
  `driver_id` int(11) NOT NULL COMMENT '運転者ID',
  `vehicle_id` int(11) NOT NULL COMMENT '車両ID',
  `arrival_date` date NOT NULL COMMENT '入庫日',
  `arrival_time` time NOT NULL COMMENT '入庫時刻',
  `arrival_mileage` int(11) NOT NULL COMMENT '入庫時メーター(km)',
  `total_distance` int(11) DEFAULT NULL COMMENT '走行距離(km)',
  `fuel_cost` int(11) DEFAULT 0 COMMENT '燃料代(円)',
  `highway_cost` int(11) DEFAULT 0 COMMENT '高速代(円)',
  `other_costs` int(11) DEFAULT 0 COMMENT 'その他料金',
  `break_location` varchar(200) DEFAULT NULL COMMENT '休憩地点',
  `break_start_time` time DEFAULT NULL COMMENT '休憩開始時刻',
  `break_end_time` time DEFAULT NULL COMMENT '休憩終了時刻',
  `remarks` text DEFAULT NULL COMMENT '備考',
  `post_duty_completed` tinyint(1) DEFAULT 0 COMMENT '乗務後点呼完了フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `toll_cost` int(11) DEFAULT 0 COMMENT '通行料(円)',
  `other_cost` int(11) DEFAULT 0 COMMENT 'その他費用(円)',
  `notes` text DEFAULT NULL COMMENT '備考',
  `total_trips` int(11) DEFAULT 0,
  `total_passengers` int(11) DEFAULT 0,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `is_sample_data` tinyint(1) DEFAULT 0,
  `is_edited` tinyint(1) DEFAULT 0 COMMENT '編集済みフラグ',
  `edit_reason` varchar(100) DEFAULT NULL COMMENT '修正理由',
  `last_edited_by` int(11) DEFAULT NULL COMMENT '最終編集者ID',
  `last_edited_at` timestamp NULL DEFAULT NULL COMMENT '最終編集日時',
  PRIMARY KEY (`id`),
  KEY `idx_arrival_date` (`arrival_date`),
  KEY `idx_driver_vehicle_date` (`driver_id`,`vehicle_id`,`arrival_date`),
  KEY `idx_departure_record` (`departure_record_id`),
  KEY `idx_arrival_vehicle_date` (`vehicle_id`,`arrival_date`),
  KEY `idx_arrival_driver_date` (`driver_id`,`arrival_date`),
  KEY `idx_arrival_edited` (`is_edited`),
  KEY `idx_arrival_edit_date` (`last_edited_at`),
  KEY `idx_arrival_editor` (`last_edited_by`),
  KEY `idx_arrival_date_driver` (`arrival_date`,`driver_id`),
  KEY `idx_arrival_departure_record` (`departure_record_id`),
  CONSTRAINT `arrival_records_ibfk_1` FOREIGN KEY (`departure_record_id`) REFERENCES `departure_records` (`id`),
  CONSTRAINT `arrival_records_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `arrival_records_ibfk_3` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_arrival_departure` FOREIGN KEY (`departure_record_id`) REFERENCES `departure_records` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_arrival_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_arrival_last_edited_by` FOREIGN KEY (`last_edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_arrival_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='入庫記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER calculate_distance_on_arrival
BEFORE INSERT ON arrival_records 
FOR EACH ROW 
BEGIN
    DECLARE departure_mileage INT DEFAULT 0;
    
    -- 対応する出庫記録から出庫メーターを取得
    IF NEW.departure_record_id IS NOT NULL THEN
        SELECT departure_mileage INTO departure_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 走行距離を自動計算
        IF departure_mileage > 0 AND NEW.arrival_mileage > departure_mileage THEN
            SET NEW.total_distance = NEW.arrival_mileage - departure_mileage;
        END IF;
    END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER update_vehicle_mileage_on_arrival
    AFTER INSERT ON arrival_records
    FOR EACH ROW
    BEGIN
        DECLARE dep_mileage INT DEFAULT 0;
        
        -- 出庫メーターを取得
        SELECT departure_mileage INTO dep_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 車両情報を更新
        UPDATE vehicles 
        SET current_mileage = NEW.arrival_mileage,
            total_distance = total_distance + (NEW.arrival_mileage - IFNULL(dep_mileage, 0)),
            updated_at = NOW()
        WHERE id = NEW.vehicle_id;
    END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER calculate_distance_on_arrival_update
BEFORE UPDATE ON arrival_records 
FOR EACH ROW 
BEGIN
    DECLARE departure_mileage INT DEFAULT 0;
    
    -- 対応する出庫記録から出庫メーターを取得
    IF NEW.departure_record_id IS NOT NULL THEN
        SELECT departure_mileage INTO departure_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 走行距離を自動計算
        IF departure_mileage > 0 AND NEW.arrival_mileage > departure_mileage THEN
            SET NEW.total_distance = NEW.arrival_mileage - departure_mileage;
        END IF;
    END IF;
END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER update_vehicle_mileage_on_arrival_update
    AFTER UPDATE ON arrival_records
    FOR EACH ROW
    BEGIN
        DECLARE old_dep_mileage INT DEFAULT 0;
        DECLARE new_dep_mileage INT DEFAULT 0;
        
        -- 旧出庫メーターを取得
        SELECT departure_mileage INTO old_dep_mileage 
        FROM departure_records 
        WHERE id = OLD.departure_record_id;
        
        -- 新出庫メーターを取得
        SELECT departure_mileage INTO new_dep_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 車両情報を更新
        UPDATE vehicles 
        SET current_mileage = NEW.arrival_mileage,
            total_distance = total_distance - (OLD.arrival_mileage - IFNULL(old_dep_mileage, 0)) + (NEW.arrival_mileage - IFNULL(new_dep_mileage, 0)),
            updated_at = NOW()
        WHERE id = NEW.vehicle_id;
    END 
*/;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'ユーザーID',
  `user_type` enum('admin','driver','partner_company') NOT NULL COMMENT 'ユーザー種別',
  `action` enum('create','edit','delete','view','export') NOT NULL COMMENT '操作種別',
  `target_type` enum('reservation','partner_company','location') NOT NULL COMMENT '対象種別',
  `target_id` int(11) DEFAULT NULL COMMENT '対象ID',
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '変更前データ' CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '変更後データ' CHECK (json_valid(`new_data`)),
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IPアドレス',
  `user_agent` text DEFAULT NULL COMMENT 'ユーザーエージェント',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  PRIMARY KEY (`id`),
  KEY `idx_user_action` (`user_id`,`action`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `calendar_audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='カレンダー操作ログテーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_collections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `collection_date` date NOT NULL,
  `is_collected` tinyint(1) NOT NULL DEFAULT 1,
  `collected_by` int(11) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_date` (`driver_id`,`collection_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `confirmation_date` date NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `confirmed_amount` int(11) NOT NULL DEFAULT 0,
  `calculated_amount` int(11) NOT NULL DEFAULT 0,
  `difference` int(11) NOT NULL DEFAULT 0,
  `change_stock` int(11) DEFAULT 0,
  `memo` text DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `confirmation_date` (`confirmation_date`),
  KEY `idx_confirmation_date` (`confirmation_date`),
  KEY `FK_cash_confirmations_user` (`confirmed_by`),
  CONSTRAINT `FK_cash_confirmations_user` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_count_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `confirmation_date` date NOT NULL,
  `driver_id` int(11) NOT NULL,
  `bill_10000` int(11) DEFAULT 0,
  `bill_5000` int(11) DEFAULT 0,
  `bill_2000` int(11) DEFAULT 0,
  `bill_1000` int(11) DEFAULT 0,
  `coin_500` int(11) DEFAULT 0,
  `coin_100` int(11) DEFAULT 0,
  `coin_50` int(11) DEFAULT 0,
  `coin_10` int(11) DEFAULT 0,
  `coin_5` int(11) DEFAULT 0,
  `coin_1` int(11) DEFAULT 0,
  `total_amount` int(11) NOT NULL DEFAULT 0,
  `memo` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_driver` (`confirmation_date`,`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_management` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `confirmation_date` date NOT NULL COMMENT '確認日',
  `driver_id` int(11) NOT NULL COMMENT '運転者ID',
  `confirmed_amount` int(11) NOT NULL DEFAULT 0 COMMENT '確認金額（実際の現金）',
  `calculated_amount` int(11) NOT NULL DEFAULT 0 COMMENT '計算金額（システム計算）',
  `difference` int(11) NOT NULL DEFAULT 0 COMMENT '差額',
  `change_stock` int(11) NOT NULL DEFAULT 0 COMMENT '釣銭在庫',
  `memo` text DEFAULT NULL COMMENT 'メモ・備考',
  `cash_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '現金内訳（紙幣・硬貨別）' CHECK (json_valid(`cash_breakdown`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  PRIMARY KEY (`id`),
  KEY `idx_confirmation_date` (`confirmation_date`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `cash_management_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='売上金確認管理';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(100) NOT NULL,
  `company_kana` varchar(100) DEFAULT NULL,
  `representative_name` varchar(100) DEFAULT '',
  `postal_code` varchar(10) DEFAULT NULL,
  `address` varchar(300) DEFAULT '大阪市中央区天満橋1-7-10',
  `phone` varchar(20) DEFAULT '06-6949-6446',
  `fax` varchar(20) DEFAULT '',
  `manager_name` varchar(100) DEFAULT '',
  `manager_email` varchar(200) DEFAULT '',
  `license_number` varchar(50) DEFAULT '',
  `business_type` varchar(100) DEFAULT '一般乗用旅客自動車運送事業（福祉）',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `business_number` varchar(20) DEFAULT '' COMMENT '事業者番号（例: 261）',
  `capital_thousand_yen` int(11) DEFAULT 0 COMMENT '資本金（資金）千円単位',
  `concurrent_business` varchar(100) DEFAULT '' COMMENT '兼営事業（"有"/"無し"または事業名）',
  `form21_prev_total` int(11) DEFAULT 0 COMMENT '第21号様式 前年度車両数 計',
  `form21_prev_wheelchair` int(11) DEFAULT 0 COMMENT '第21号様式 前年度 車椅子対応車数',
  `form21_prev_udt` int(11) DEFAULT 0 COMMENT '第21号様式 前年度 UDT認定車数',
  `form21_prev_stretcher` int(11) DEFAULT 0 COMMENT '第21号様式 前年度 寝台対応車数',
  `form21_prev_combo` int(11) DEFAULT 0 COMMENT '第21号様式 前年度 兼用車数',
  `form21_prev_rotation` int(11) DEFAULT 0 COMMENT '第21号様式 前年度 回転シート車数',
  `form21_plan_content` text DEFAULT NULL COMMENT '第21号様式 計画内容（計画対象期間及び事業の主な内容）',
  `form21_change_content` text DEFAULT NULL COMMENT '第21号様式 前年度の計画からの変更内容',
  `form21_target_vehicles` varchar(200) DEFAULT '' COMMENT '第21号様式Ⅱ 対象となる福祉タクシー車両',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_date` date NOT NULL,
  `complaint_time` time DEFAULT NULL,
  `complainant_name` varchar(100) NOT NULL COMMENT '苦情申立者名',
  `complainant_phone` varchar(20) DEFAULT NULL COMMENT '連絡先',
  `complaint_type` enum('運転マナー','接客態度','遅刻・時間','車両状態','料金','その他') NOT NULL,
  `severity` enum('軽度','中程度','重度') DEFAULT '中程度',
  `related_date` date DEFAULT NULL COMMENT '事象発生日',
  `driver_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `description` text NOT NULL COMMENT '苦情内容',
  `response` text DEFAULT NULL COMMENT '対応内容',
  `response_status` enum('未対応','対応中','対応完了','保留') DEFAULT '未対応',
  `response_date` date DEFAULT NULL COMMENT '対応完了日',
  `handled_by` int(11) DEFAULT NULL COMMENT '対応者',
  `prevention_measures` text DEFAULT NULL COMMENT '再発防止策',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`complaint_date`),
  KEY `idx_status` (`response_status`),
  KEY `idx_driver` (`driver_id`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `handled_by` (`handled_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `complaint_records_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaint_records_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaint_records_ibfk_3` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaint_records_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `relationship_type` enum('home','frequent','default_pickup','default_dropoff') NOT NULL DEFAULT 'frequent',
  `visit_count` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_location` (`customer_id`,`location_id`,`relationship_type`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_location` (`location_id`),
  CONSTRAINT `customer_locations_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_locations_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `location_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='顧客-場所 紐付け';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '顧客名',
  `name_kana` varchar(100) DEFAULT NULL COMMENT 'フリガナ（検索用）',
  `phone` varchar(20) DEFAULT NULL COMMENT '電話番号',
  `phone_secondary` varchar(20) DEFAULT NULL COMMENT '副電話番号',
  `email` varchar(255) DEFAULT NULL COMMENT 'メールアドレス',
  `postal_code` varchar(10) DEFAULT NULL COMMENT '郵便番号',
  `address` varchar(255) DEFAULT NULL COMMENT '住所',
  `address_detail` varchar(255) DEFAULT NULL COMMENT '建物名・部屋番号',
  `care_level` varchar(50) DEFAULT NULL COMMENT '介護度（要支援1-2, 要介護1-5）',
  `disability_type` varchar(100) DEFAULT NULL COMMENT '障害区分',
  `mobility_type` enum('independent','wheelchair','stretcher','walker') NOT NULL DEFAULT 'independent' COMMENT '移動形態',
  `wheelchair_type` varchar(50) DEFAULT NULL COMMENT '車椅子タイプ',
  `default_pickup_location` varchar(255) DEFAULT NULL COMMENT 'デフォルト乗車地',
  `default_dropoff_location` varchar(255) DEFAULT NULL COMMENT 'デフォルト降車地',
  `default_pickup_location_id` int(11) DEFAULT NULL COMMENT 'デフォルト乗車地（場所マスタID）',
  `default_dropoff_location_id` int(11) DEFAULT NULL COMMENT 'デフォルト降車地（場所マスタID）',
  `assigned_driver_id` int(11) DEFAULT NULL COMMENT '担当ドライバー（ユーザーID）',
  `emergency_contact_name` varchar(100) DEFAULT NULL COMMENT '緊急連絡先名',
  `emergency_contact_phone` varchar(20) DEFAULT NULL COMMENT '緊急連絡先電話番号',
  `notes` text DEFAULT NULL COMMENT '特記事項（アレルギー・注意点等）',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ（0=無効/削除済み）',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  `created_by` int(11) DEFAULT NULL COMMENT '作成者ユーザーID',
  `residence_location_id` int(11) DEFAULT NULL COMMENT '入居先 location_master.id（施設・病院。NULL=自宅）（HaiGO）',
  PRIMARY KEY (`id`),
  KEY `idx_customers_name_kana` (`name_kana`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_is_active` (`is_active`),
  KEY `idx_customers_pickup_location` (`default_pickup_location_id`),
  KEY `idx_customers_dropoff_location` (`default_dropoff_location_id`),
  KEY `idx_customers_assigned_driver` (`assigned_driver_id`),
  CONSTRAINT `fk_customers_assigned_driver` FOREIGN KEY (`assigned_driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_customers_dropoff_location` FOREIGN KEY (`default_dropoff_location_id`) REFERENCES `location_master` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_customers_pickup_location` FOREIGN KEY (`default_pickup_location_id`) REFERENCES `location_master` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='顧客（利用者）マスタ';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `inspection_date` date NOT NULL COMMENT '点検日',
  `inspector_name` varchar(100) DEFAULT NULL COMMENT '点検者名（運転者と異なる場合）',
  `foot_brake_result` enum('可','否') NOT NULL COMMENT 'フットブレーキの踏み代・効き',
  `parking_brake_result` enum('可','否') NOT NULL COMMENT 'パーキングブレーキの引き代',
  `engine_start_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'エンジンのかかり具合・異音',
  `engine_performance_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'エンジンの低速・加速',
  `wiper_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'ワイパーのふき取り能力',
  `washer_spray_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'ウインドウォッシャー液の噴射状態',
  `brake_fluid_result` enum('可','否') NOT NULL COMMENT 'ブレーキ液量',
  `coolant_result` enum('可','否','省略') DEFAULT '省略' COMMENT '冷却水量',
  `engine_oil_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'エンジンオイル量',
  `battery_fluid_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'バッテリー液量',
  `washer_fluid_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'ウインドウォッシャー液量',
  `fan_belt_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'ファンベルトの張り・損傷',
  `lights_result` enum('可','否') NOT NULL COMMENT '灯火類の点灯・点滅',
  `lens_result` enum('可','否') NOT NULL COMMENT 'レンズの損傷・汚れ',
  `tire_pressure_result` enum('可','否') NOT NULL COMMENT 'タイヤの空気圧',
  `tire_damage_result` enum('可','否') NOT NULL COMMENT 'タイヤの亀裂・損傷',
  `tire_tread_result` enum('可','否','省略') DEFAULT '省略' COMMENT 'タイヤ溝の深さ',
  `defect_details` text DEFAULT NULL COMMENT '不良個所及び処置',
  `remarks` text DEFAULT NULL COMMENT '備考',
  `mileage` int(11) DEFAULT NULL COMMENT '走行距離',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_trips` int(11) DEFAULT 0,
  `cabin_brake_pedal` tinyint(4) DEFAULT 1,
  `cabin_parking_brake` tinyint(4) DEFAULT 1,
  `lighting_headlights` tinyint(4) DEFAULT 1,
  `lighting_taillights` tinyint(4) DEFAULT 1,
  `engine_oil` tinyint(4) DEFAULT 1,
  `brake_fluid` tinyint(4) DEFAULT 1,
  `tire_condition` tinyint(4) DEFAULT 1,
  `is_sample_data` tinyint(1) DEFAULT 0,
  `inspection_time` time DEFAULT NULL COMMENT '点検実施時間',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ロック状態（0=編集可, 1=ロック済）',
  `locked_at` datetime DEFAULT NULL COMMENT 'ロック日時',
  `last_edited_by` int(11) DEFAULT NULL COMMENT '最終編集者ユーザーID',
  `last_edited_at` datetime DEFAULT NULL COMMENT '最終編集日時',
  `edit_count` int(11) NOT NULL DEFAULT 0 COMMENT '編集回数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vehicle_driver_date` (`vehicle_id`,`driver_id`,`inspection_date`),
  KEY `driver_id` (`driver_id`),
  KEY `idx_inspection_date` (`inspection_date`),
  KEY `idx_vehicle_date` (`vehicle_id`,`inspection_date`),
  CONSTRAINT `daily_inspections_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `daily_inspections_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='日常点検記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `departure_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL COMMENT '運転者ID',
  `vehicle_id` int(11) NOT NULL COMMENT '車両ID',
  `departure_date` date NOT NULL COMMENT '出庫日',
  `departure_time` time NOT NULL COMMENT '出庫時刻',
  `weather` varchar(20) DEFAULT NULL COMMENT '天候（晴・曇・雨・雪・霧）',
  `departure_mileage` int(11) DEFAULT NULL COMMENT '出庫メーター',
  `pre_duty_completed` tinyint(1) DEFAULT 0 COMMENT '乗務前点呼完了フラグ',
  `daily_inspection_completed` tinyint(1) DEFAULT 0 COMMENT '日常点検完了フラグ',
  `remarks` text DEFAULT NULL COMMENT '備考',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_trips` int(11) DEFAULT 0,
  `total_passengers` int(11) DEFAULT 0,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `total_distance` int(11) DEFAULT 0,
  `is_sample_data` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_departure_date` (`departure_date`),
  KEY `idx_driver_vehicle_date` (`driver_id`,`vehicle_id`,`departure_date`),
  KEY `idx_departure_date_driver` (`departure_date`,`driver_id`),
  KEY `idx_departure_vehicle_date` (`vehicle_id`,`departure_date`),
  CONSTRAINT `departure_records_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `departure_records_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_departure_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_departure_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='出庫記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `category` enum('license','insurance','vehicle','driver','contract','report','other') NOT NULL DEFAULT 'other',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `related_driver_id` int(11) DEFAULT NULL,
  `related_vehicle_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_expiry` (`is_active`,`expiry_date`),
  KEY `idx_driver` (`related_driver_id`),
  KEY `idx_vehicle` (`related_vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_years` (
  `fiscal_year` int(11) NOT NULL COMMENT '年度（2024等）',
  `start_date` date NOT NULL COMMENT '開始日（4/1）',
  `end_date` date NOT NULL COMMENT '終了日（3/31）',
  `is_active` tinyint(1) DEFAULT 0,
  `vehicle_count` int(11) DEFAULT 0 COMMENT '事業用自動車数',
  `employee_count` int(11) DEFAULT 0 COMMENT '従業員数',
  `driver_count` int(11) DEFAULT 0 COMMENT '運転者数',
  `total_mileage` int(11) DEFAULT 0 COMMENT '年間走行距離',
  `total_trips` int(11) DEFAULT 0 COMMENT '年間運送回数',
  `total_passengers` int(11) DEFAULT 0 COMMENT '年間輸送人員',
  `total_revenue` int(11) DEFAULT 0 COMMENT '年間営業収入',
  `traffic_accidents` int(11) DEFAULT 0 COMMENT '交通事故件数',
  `serious_accidents` int(11) DEFAULT 0 COMMENT '重大事故件数',
  `total_deaths` int(11) DEFAULT 0 COMMENT '年間死者数',
  `total_injuries` int(11) DEFAULT 0 COMMENT '年間負傷者数',
  `report_submitted` tinyint(1) DEFAULT 0 COMMENT '陸運局提出済み',
  `submission_date` date DEFAULT NULL COMMENT '提出日',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fiscal_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='年度マスタ';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `frequent_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL COMMENT '顧客ID',
  `location_name` varchar(255) NOT NULL COMMENT '場所名（例: ○○病院、自宅）',
  `address` varchar(255) DEFAULT NULL COMMENT '住所',
  `location_type` enum('hospital','dialysis','facility','home','other') NOT NULL DEFAULT 'other' COMMENT '場所種別',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '表示順（小さい順）',
  `notes` text DEFAULT NULL COMMENT '備考（受付窓口・駐車場情報等）',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  PRIMARY KEY (`id`),
  KEY `idx_fd_customer_id` (`customer_id`),
  KEY `idx_fd_customer_sort` (`customer_id`,`sort_order`),
  CONSTRAINT `fk_fd_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='顧客別よく使う行き先';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `frequent_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(200) NOT NULL COMMENT '場所名',
  `location_type` enum('自宅','病院','施設','駅','その他') NOT NULL COMMENT '場所種別',
  `address` varchar(300) DEFAULT NULL COMMENT '住所',
  `phone` varchar(20) DEFAULT NULL COMMENT '電話番号',
  `usage_count` int(11) DEFAULT 0 COMMENT '使用回数',
  `last_used_date` date DEFAULT NULL COMMENT '最終使用日',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '有効フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  PRIMARY KEY (`id`),
  KEY `idx_location_type` (`location_type`),
  KEY `idx_usage_count` (`usage_count`),
  KEY `idx_last_used_date` (`last_used_date`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='よく使う場所管理テーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hospital_assistance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assistance_date` date NOT NULL,
  `assistance_time` time DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `facility_name` varchar(200) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`assistance_date`),
  KEY `idx_staff` (`staff_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `hospital_assistance_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`),
  CONSTRAINT `hospital_assistance_logs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL COMMENT '対象の点検レコードID',
  `action` enum('create','edit','delete','admin_unlock') NOT NULL COMMENT '操作種別（作成/編集/削除/管理者ロック解除）',
  `edited_by` int(11) NOT NULL COMMENT '操作実行ユーザーID',
  `edited_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '操作日時',
  `field_changed` varchar(100) DEFAULT NULL COMMENT '変更フィールド名（create/deleteの場合はNULL）',
  `old_value` text DEFAULT NULL COMMENT '変更前の値',
  `new_value` text DEFAULT NULL COMMENT '変更後の値',
  `reason` text DEFAULT NULL COMMENT '変更理由（admin_unlock時・安全項目変更時は必須）',
  `ip_address` varchar(45) DEFAULT NULL COMMENT '操作元IPアドレス（IPv6対応）',
  `user_agent` varchar(255) DEFAULT NULL COMMENT '操作元ブラウザ情報',
  PRIMARY KEY (`id`),
  KEY `idx_audit_inspection_id` (`inspection_id`),
  KEY `idx_audit_edited_by` (`edited_by`),
  KEY `idx_audit_edited_at` (`edited_at`),
  CONSTRAINT `fk_audit_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `daily_inspections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='日常点検 監査証跡ログ';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `name_kana` varchar(255) DEFAULT NULL,
  `location_type` varchar(50) DEFAULT 'other',
  `postal_code` varchar(10) DEFAULT NULL,
  `location_name` varchar(100) NOT NULL,
  `location_kana` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `usage_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migration_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `checksum` varchar(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `applied_by` varchar(100) DEFAULT NULL,
  `execution_time_ms` int(11) DEFAULT NULL,
  `status` enum('success','failed','rolled_back') NOT NULL DEFAULT 'success',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(100) NOT NULL COMMENT '会社名',
  `contact_person` varchar(100) DEFAULT NULL COMMENT '担当者名',
  `phone` varchar(20) DEFAULT NULL COMMENT '電話番号',
  `email` varchar(100) DEFAULT NULL COMMENT 'メールアドレス',
  `access_level` enum('閲覧のみ','部分作成') DEFAULT '閲覧のみ' COMMENT 'アクセスレベル',
  `can_view_all_reservations` tinyint(1) DEFAULT 1 COMMENT '全予約閲覧可能',
  `can_create_own_reservations` tinyint(1) DEFAULT 0 COMMENT '自社予約作成可能',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '有効フラグ',
  `display_color` varchar(7) DEFAULT '#808080' COMMENT '表示色（HEXコード）',
  `sort_order` int(11) DEFAULT 99 COMMENT '表示順序',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  PRIMARY KEY (`id`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='協力会社管理テーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `periodic_inspection_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `category` enum('steering','braking','running','suspension','power_transmission','electrical','engine') NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `result` enum('良好','要注意','不良') NOT NULL,
  `note` varchar(500) DEFAULT '' COMMENT '備考・詳細',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inspection_category` (`inspection_id`,`category`),
  KEY `idx_result` (`result`),
  CONSTRAINT `periodic_inspection_items_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `periodic_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定期点検項目詳細テーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `periodic_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `inspector_id` int(11) NOT NULL,
  `inspection_date` date NOT NULL,
  `inspection_type` enum('3months','6months','12months') NOT NULL DEFAULT '3months',
  `mileage` int(11) NOT NULL,
  `service_provider_name` varchar(200) DEFAULT '',
  `service_provider_address` varchar(300) DEFAULT '',
  `co_concentration` decimal(4,2) DEFAULT NULL COMMENT 'CO濃度（%）',
  `hc_concentration` int(11) DEFAULT NULL COMMENT 'HC濃度（ppm）',
  `overall_result` enum('合格','不合格','条件付合格') DEFAULT '合格',
  `remarks` text DEFAULT NULL,
  `defect_details` text DEFAULT NULL COMMENT '不具合詳細',
  `next_inspection_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_sample_data` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_date` (`vehicle_id`,`inspection_date`),
  KEY `idx_inspector` (`inspector_id`),
  KEY `idx_inspection_type` (`inspection_type`),
  KEY `idx_next_inspection` (`next_inspection_date`),
  CONSTRAINT `periodic_inspections_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `periodic_inspections_ibfk_2` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定期点検記録メインテーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `post_duty_calls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL COMMENT '車両ID（出庫記録がない場合は NULL）',
  `call_date` date NOT NULL COMMENT '点呼日',
  `call_time` time NOT NULL COMMENT '点呼時刻',
  `caller_name` varchar(100) NOT NULL COMMENT '点呼者',
  `duty_record_check` tinyint(1) DEFAULT 0 COMMENT '乗務内容の記載内容',
  `vehicle_condition_check` tinyint(1) DEFAULT 0 COMMENT '車両状態',
  `health_condition_check` tinyint(1) DEFAULT 0 COMMENT '健康状態',
  `fatigue_check` tinyint(1) DEFAULT 0,
  `alcohol_drug_check` tinyint(1) DEFAULT 0,
  `accident_violation_check` tinyint(1) DEFAULT 0,
  `equipment_return_check` tinyint(1) DEFAULT 0,
  `report_completion_check` tinyint(1) DEFAULT 0,
  `lost_items_check` tinyint(1) DEFAULT 0 COMMENT '遺失物状況',
  `violation_accident_check` tinyint(1) DEFAULT 0 COMMENT '違反事故苦情の状況',
  `route_operation_check` tinyint(1) DEFAULT 0 COMMENT '進路及び運行状況',
  `passenger_condition_check` tinyint(1) DEFAULT 0 COMMENT '旅客の状況',
  `alcohol_check_value` decimal(4,3) DEFAULT NULL COMMENT 'アルコール数値',
  `alcohol_check_time` time DEFAULT NULL COMMENT 'アルコールチェック時刻',
  `pre_duty_call_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL COMMENT '備考',
  `signature_data` text DEFAULT NULL COMMENT '点呼者署名データ',
  `is_completed` tinyint(1) DEFAULT 0 COMMENT '完了フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_trips` int(11) DEFAULT 0,
  `is_sample_data` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_vehicle_date` (`driver_id`,`vehicle_id`,`call_date`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `idx_call_date` (`call_date`),
  KEY `idx_driver_date` (`driver_id`,`call_date`),
  KEY `idx_pre_duty_call_id` (`pre_duty_call_id`),
  CONSTRAINT `fk_post_duty_pre_duty` FOREIGN KEY (`pre_duty_call_id`) REFERENCES `pre_duty_calls` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `post_duty_calls_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `post_duty_calls_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='乗務後点呼記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pre_duty_calls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `call_date` date NOT NULL COMMENT '点呼日',
  `call_time` time NOT NULL COMMENT '点呼時刻',
  `caller_name` varchar(100) NOT NULL COMMENT '点呼者',
  `health_check` tinyint(1) DEFAULT 0 COMMENT '健康状態',
  `clothing_check` tinyint(1) DEFAULT 0 COMMENT '服装',
  `footwear_check` tinyint(1) DEFAULT 0 COMMENT '履物',
  `pre_inspection_check` tinyint(1) DEFAULT 0 COMMENT '運行前点検',
  `license_check` tinyint(1) DEFAULT 0 COMMENT '免許証',
  `vehicle_registration_check` tinyint(1) DEFAULT 0 COMMENT '車検証',
  `insurance_check` tinyint(1) DEFAULT 0 COMMENT '保険証',
  `emergency_tools_check` tinyint(1) DEFAULT 0 COMMENT '応急工具',
  `map_check` tinyint(1) DEFAULT 0 COMMENT '地図',
  `taxi_card_check` tinyint(1) DEFAULT 0 COMMENT 'タクシーカード',
  `emergency_signal_check` tinyint(1) DEFAULT 0 COMMENT '非常信号用具',
  `change_money_check` tinyint(1) DEFAULT 0 COMMENT '釣銭',
  `crew_id_check` tinyint(1) DEFAULT 0 COMMENT '乗務員証',
  `operation_record_check` tinyint(1) DEFAULT 0 COMMENT '運行記録用用紙',
  `receipt_check` tinyint(1) DEFAULT 0 COMMENT '領収書',
  `stop_sign_check` tinyint(1) DEFAULT 0 COMMENT '停止表示機',
  `alcohol_check_value` decimal(4,3) DEFAULT NULL COMMENT 'アルコール数値',
  `alcohol_check_time` time DEFAULT NULL COMMENT 'アルコールチェック時刻',
  `remarks` text DEFAULT NULL COMMENT '備考',
  `signature_data` text DEFAULT NULL COMMENT '点呼者署名データ',
  `is_completed` tinyint(1) DEFAULT 0 COMMENT '完了フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_trips` int(11) DEFAULT 0,
  `is_sample_data` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_driver_vehicle_date` (`driver_id`,`vehicle_id`,`call_date`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `idx_call_date` (`call_date`),
  KEY `idx_driver_date` (`driver_id`,`call_date`),
  CONSTRAINT `pre_duty_calls_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `pre_duty_calls_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='乗務前点呼記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(24) NOT NULL COMMENT 'Cookie照合用キー（平文）',
  `validator_hash` char(64) NOT NULL COMMENT 'validatorのSHA-256ハッシュ',
  `prev_validator_hash` char(64) DEFAULT NULL COMMENT 'ローテーション直前のハッシュ（並行リクエスト猶予用）',
  `rotated_at` datetime DEFAULT NULL COMMENT '最終ローテーション日時',
  `expires_at` datetime NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Remember Me 自動ログイントークン';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_field_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_name` varchar(50) NOT NULL COMMENT '対象フィールド名',
  `option_value` varchar(100) NOT NULL COMMENT '選択肢の値',
  `option_label` varchar(100) NOT NULL COMMENT '表示ラベル',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '表示順',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_field_option` (`field_name`,`option_value`),
  KEY `idx_field_active` (`field_name`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_date` date NOT NULL COMMENT '予約日',
  `reservation_time` time NOT NULL COMMENT '予約時刻',
  `client_name` varchar(100) NOT NULL COMMENT '利用者名',
  `customer_id` int(11) DEFAULT NULL COMMENT '顧客マスタID',
  `pickup_location` varchar(200) NOT NULL COMMENT 'お迎え場所',
  `dropoff_location` varchar(200) NOT NULL COMMENT '目的地',
  `passenger_count` int(11) NOT NULL DEFAULT 1 COMMENT '乗車人数',
  `driver_id` int(11) DEFAULT NULL COMMENT '運転者ID',
  `vehicle_id` int(11) DEFAULT NULL COMMENT '車両ID',
  `service_type` enum('お迎え','お送り','入院','退院','転院','その他') NOT NULL COMMENT 'サービス種別',
  `is_time_critical` tinyint(1) DEFAULT 1 COMMENT '時間厳守フラグ（1:厳守 0:目安）',
  `rental_service` enum('なし','車いす','リクライニング','ストレッチャー') DEFAULT 'なし' COMMENT 'レンタルサービス',
  `entrance_assistance` tinyint(1) DEFAULT 0 COMMENT '玄関まで送迎（1:要 0:不要）',
  `disability_card` tinyint(1) DEFAULT 0 COMMENT '障がい者手帳（1:有 0:無）',
  `care_service_user` tinyint(1) DEFAULT 0 COMMENT '介護サービス利用（1:有 0:無）',
  `hospital_escort_staff` varchar(50) DEFAULT NULL COMMENT '院内付添者',
  `dual_assistance_staff` varchar(50) DEFAULT NULL COMMENT '2名介助担当者',
  `referrer_type` enum('CM','SW','家族','本人') NOT NULL COMMENT '紹介者区分',
  `referrer_name` varchar(100) NOT NULL COMMENT '紹介者名',
  `referrer_contact` varchar(100) DEFAULT NULL COMMENT '紹介者連絡先',
  `is_return_trip` tinyint(1) DEFAULT 0 COMMENT '復路フラグ（1:復路 0:往路）',
  `parent_reservation_id` int(11) DEFAULT NULL COMMENT '親予約ID（復路の場合の往路ID）',
  `return_hours_later` int(11) DEFAULT NULL COMMENT '復路作成時の時間後',
  `estimated_fare` int(11) DEFAULT 0 COMMENT '見積料金',
  `actual_fare` int(11) DEFAULT NULL COMMENT '実際料金',
  `payment_method` enum('現金','カード','その他') DEFAULT '現金' COMMENT '支払方法',
  `status` enum('予約','進行中','完了','キャンセル') DEFAULT '予約' COMMENT 'ステータス',
  `ride_record_id` int(11) DEFAULT NULL COMMENT '乗車記録ID（変換後）',
  `special_notes` text DEFAULT NULL COMMENT '特記事項・備考',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '作成日時',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新日時',
  `created_by` int(11) DEFAULT NULL COMMENT '作成者ID',
  PRIMARY KEY (`id`),
  KEY `idx_reservation_date` (`reservation_date`),
  KEY `idx_driver_date` (`driver_id`,`reservation_date`),
  KEY `idx_vehicle_date` (`vehicle_id`,`reservation_date`),
  KEY `idx_service_type` (`service_type`),
  KEY `idx_status` (`status`),
  KEY `idx_return_trip` (`is_return_trip`,`parent_reservation_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `parent_reservation_id` (`parent_reservation_id`),
  KEY `ride_record_id` (`ride_record_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_reservations_customer_id` (`customer_id`),
  CONSTRAINT `fk_reservations_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `reservations_ibfk_3` FOREIGN KEY (`parent_reservation_id`) REFERENCES `reservations` (`id`),
  CONSTRAINT `reservations_ibfk_4` FOREIGN KEY (`ride_record_id`) REFERENCES `ride_records` (`id`),
  CONSTRAINT `reservations_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='予約管理メインテーブル';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ride_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ride_number` int(11) NOT NULL COMMENT '乗車番号（日次連番）',
  `ride_time` time NOT NULL COMMENT '乗車時間',
  `dropoff_time` time DEFAULT NULL,
  `passenger_count` int(11) NOT NULL DEFAULT 1 COMMENT '人員',
  `pickup_location` varchar(200) NOT NULL COMMENT '乗車地',
  `dropoff_location` varchar(200) NOT NULL COMMENT '降車地',
  `ride_distance` decimal(6,1) DEFAULT NULL,
  `fare` int(11) NOT NULL DEFAULT 0 COMMENT '運賃・料金',
  `transport_type` enum('通院','外出等','退院','転院','施設入所','その他') NOT NULL COMMENT '輸送分類',
  `payment_method` enum('現金','カード','その他') DEFAULT '現金' COMMENT '支払方法',
  `disability_discount` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_amount` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL COMMENT '備考',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `driver_id` int(11) DEFAULT NULL COMMENT '運転者ID（独立化用）',
  `vehicle_id` int(11) DEFAULT NULL COMMENT '車両ID（独立化用）',
  `ride_date` date DEFAULT NULL COMMENT '乗車日（独立化用）',
  `charge` int(11) DEFAULT 0 COMMENT '料金',
  `total_fare` int(11) DEFAULT NULL,
  `cash_amount` int(11) DEFAULT NULL,
  `card_amount` int(11) DEFAULT NULL,
  `is_return_trip` tinyint(1) DEFAULT 0 COMMENT '復路フラグ',
  `original_ride_id` int(11) DEFAULT NULL COMMENT '元乗車記録ID',
  `transportation_type` varchar(50) DEFAULT '未設定',
  `is_sample_data` tinyint(1) DEFAULT 0,
  `departure_record_id` int(11) DEFAULT NULL COMMENT '出庫記録ID（運行記録との関連付け）',
  PRIMARY KEY (`id`),
  KEY `idx_operation_ride` (`ride_number`),
  KEY `idx_transport_type` (`transport_type`),
  KEY `idx_ride_time` (`ride_time`),
  KEY `idx_ride_records_type_date` (`transport_type`,`created_at`),
  KEY `idx_driver_vehicle_date` (`driver_id`,`vehicle_id`,`ride_date`),
  KEY `idx_ride_date` (`ride_date`),
  KEY `idx_ride_driver` (`driver_id`,`ride_date`),
  KEY `idx_ride_vehicle` (`vehicle_id`,`ride_date`),
  KEY `idx_ride_driver_date` (`driver_id`,`ride_date`),
  KEY `idx_ride_vehicle_date` (`vehicle_id`,`ride_date`),
  KEY `fk_original_ride` (`original_ride_id`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_vehicle_id` (`vehicle_id`),
  KEY `idx_departure_record` (`departure_record_id`),
  KEY `idx_ride_number` (`departure_record_id`,`ride_number`),
  KEY `idx_ride_date_driver` (`ride_date`,`driver_id`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_return_trip` (`is_return_trip`,`original_ride_id`),
  KEY `idx_sample_data` (`is_sample_data`),
  KEY `idx_transportation_type` (`transportation_type`),
  CONSTRAINT `fk_original_ride` FOREIGN KEY (`original_ride_id`) REFERENCES `ride_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ride_departure` FOREIGN KEY (`departure_record_id`) REFERENCES `departure_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ride_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_ride_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='乗車記録';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ride_waypoints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ride_record_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 1,
  `location` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ride_record` (`ride_record_id`),
  CONSTRAINT `ride_waypoints_ibfk_1` FOREIGN KEY (`ride_record_id`) REFERENCES `ride_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supervision_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervision_date` date NOT NULL,
  `supervision_type` enum('初任教育','適齢教育','安全運転指導','接遇マナー','車椅子操作','応急救護','その他') NOT NULL,
  `driver_id` int(11) NOT NULL,
  `supervisor_id` int(11) NOT NULL,
  `duration_minutes` int(11) DEFAULT 60 COMMENT '指導時間（分）',
  `subject` varchar(255) NOT NULL COMMENT '指導テーマ',
  `content` text NOT NULL COMMENT '指導内容',
  `result` enum('良好','概ね良好','要改善','要再指導') DEFAULT '良好',
  `follow_up_date` date DEFAULT NULL COMMENT 'フォローアップ予定日',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`supervision_date`),
  KEY `idx_driver` (`driver_id`),
  KEY `idx_type` (`supervision_type`),
  KEY `supervisor_id` (`supervisor_id`),
  CONSTRAINT `supervision_records_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `supervision_records_ibfk_2` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL COMMENT '設定キー',
  `setting_value` text DEFAULT NULL COMMENT '設定値',
  `setting_type` enum('string','integer','boolean','json') DEFAULT 'string' COMMENT '設定値タイプ',
  `description` text DEFAULT NULL COMMENT '設定説明',
  `is_editable` tinyint(1) DEFAULT 1 COMMENT '編集可能フラグ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='システム設定';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transport_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  `category_code` varchar(10) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login_id` varchar(50) NOT NULL COMMENT 'ログインID',
  `NAME` varchar(100) NOT NULL COMMENT '氏名',
  `PASSWORD` varchar(255) NOT NULL COMMENT 'パスワード（ハッシュ化）',
  `permission_level` enum('User','Admin') NOT NULL DEFAULT 'User',
  `is_inspector` tinyint(1) DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL COMMENT '電話番号',
  `email` varchar(100) DEFAULT NULL COMMENT 'メールアドレス',
  `date_of_birth` date DEFAULT NULL COMMENT '生年月日',
  `hire_date` date DEFAULT NULL COMMENT '入社日',
  `address` text DEFAULT NULL COMMENT '住所',
  `emergency_contact` varchar(255) DEFAULT NULL COMMENT '緊急連絡先（氏名・電話番号）',
  `driver_license_number` varchar(20) DEFAULT NULL COMMENT '運転免許証番号',
  `driver_license_type` varchar(100) DEFAULT NULL COMMENT '免許種別（普通二種等）',
  `driver_license_expiry` date DEFAULT NULL COMMENT '運転免許有効期限',
  `operator_card_number` varchar(20) DEFAULT NULL,
  `care_qualification` varchar(100) DEFAULT NULL COMMENT '介護資格名',
  `care_qualification_date` date DEFAULT NULL COMMENT '介護資格取得日',
  `health_check_date` date DEFAULT NULL COMMENT '直近健康診断日',
  `health_check_next` date DEFAULT NULL COMMENT '次回健康診断予定日',
  `aptitude_test_date` date DEFAULT NULL COMMENT '直近適性診断日',
  `aptitude_test_next` date DEFAULT NULL COMMENT '次回適性診断予定日',
  `photo_path` varchar(500) DEFAULT NULL COMMENT '顔写真パス（uploads/からの相対）',
  `notes` text DEFAULT NULL COMMENT '備考・メモ',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL COMMENT '最終ログイン日時',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_driver` tinyint(1) NOT NULL DEFAULT 0,
  `is_caller` tinyint(1) NOT NULL DEFAULT 0,
  `is_admin` tinyint(1) DEFAULT 0 COMMENT 'システム管理者権限',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `is_manager` tinyint(1) NOT NULL DEFAULT 0,
  `is_mechanic` tinyint(1) DEFAULT 0,
  `dispatch_color` varchar(7) DEFAULT NULL COMMENT '配車ボードの担当色 #RRGGBB（HaiGO。NULL=自動）',
  `dispatch_priority` int(11) DEFAULT NULL COMMENT '自動振り分けの優先順位（小さいほど先。NULL=順位なし・ID順）（HaiGO）',
  `default_vehicle_id` int(11) DEFAULT NULL COMMENT 'いつもの担当車 vehicles.id（HaiGO。NULL=車両担当なし＝自動振り分けの対象外）',
  `dispatch_shift` text DEFAULT NULL COMMENT '曜日シフト JSON {"0".."6":"main|sub|off","holiday":"off"}（HaiGO。0=日..6=土）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_id` (`login_id`),
  KEY `idx_login_id` (`login_id`),
  KEY `idx_is_driver` (`is_driver`),
  KEY `idx_is_caller` (`is_caller`),
  KEY `idx_is_admin` (`is_admin`),
  KEY `idx_permission_level` (`permission_level`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_driver_active` (`is_driver`,`is_active`),
  KEY `idx_last_login` (`last_login_at`),
  KEY `idx_license_expiry` (`driver_license_expiry`),
  KEY `idx_health_check_next` (`health_check_next`),
  KEY `idx_aptitude_test_next` (`aptitude_test_next`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='ユーザーマスタ';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_number` varchar(20) NOT NULL COMMENT '車両番号',
  `model` varchar(100) DEFAULT NULL COMMENT '車種',
  `registration_date` date DEFAULT NULL COMMENT '登録年月日',
  `vehicle_type` enum('welfare','taxi') DEFAULT 'welfare' COMMENT '車両種別',
  `capacity` int(11) DEFAULT 4 COMMENT '定員',
  `next_inspection_date` date DEFAULT NULL COMMENT '次回定期点検日',
  `shaken_expiry_date` date DEFAULT NULL COMMENT '車検の満了日（車検証「有効期間の満了する日」）',
  `inspection_mileage` int(11) DEFAULT NULL COMMENT '点検時走行距離',
  `current_mileage` int(11) DEFAULT 0 COMMENT '現在の走行距離',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '使用中フラグ',
  `notes` text DEFAULT NULL COMMENT '備考',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vehicle_name` varchar(100) DEFAULT '',
  `total_distance` int(11) DEFAULT 0,
  `status` varchar(20) DEFAULT 'active',
  `total_trips` int(11) DEFAULT 0,
  `total_passengers` int(11) DEFAULT 0,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `is_universal_design_taxi` tinyint(1) DEFAULT 0 COMMENT 'ユニバーサルデザインタクシー認定',
  `accessibility_category` enum('none','wheelchair','stretcher','combo','rotation') DEFAULT 'none' COMMENT '福祉車両区分（第21号様式 基準省令第45条 / wheelchair=車椅子のみ・stretcher=寝台のみ・combo=兼用・rotation=回転シート）',
  `is_wheelchair_compatible` tinyint(1) DEFAULT 0 COMMENT '車椅子対応車（基準省令第45条第1項）',
  `is_stretcher_compatible` tinyint(1) DEFAULT 0 COMMENT '寝台対応車（基準省令第45条第1項）',
  `is_combo_vehicle` tinyint(1) DEFAULT 0 COMMENT '兼用車（車椅子・寝台どちらも輸送可）',
  `is_rotation_seat` tinyint(1) DEFAULT 0 COMMENT '回転シート車（基準省令第45条第2項）',
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_number` (`vehicle_number`),
  KEY `idx_next_inspection` (`next_inspection_date`),
  KEY `idx_inspections_alert` (`next_inspection_date`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='車両マスタ';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

