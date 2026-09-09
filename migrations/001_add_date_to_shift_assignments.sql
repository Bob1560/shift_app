-- 講師割り当てを日付ベースに変更するマイグレーション

-- 既存データを保持しつつ日付カラムを追加
ALTER TABLE shift_assignments 
ADD COLUMN date DATE DEFAULT NULL AFTER id,
ADD INDEX idx_date_user (date, user_id),
ADD UNIQUE KEY uq_assignment_by_date (user_id, date);

-- 既存の shift_assignments に日付を設定（scheduleから取得）
UPDATE shift_assignments sa
SET sa.date = (SELECT s.date FROM schedules s WHERE s.id = sa.schedule_id)
WHERE sa.date IS NULL;

-- date が NOT NULL に設定
ALTER TABLE shift_assignments MODIFY date DATE NOT NULL;

-- 古い schedule_id ベースのユニーク制約を削除
ALTER TABLE shift_assignments DROP INDEX uq_assignment;

-- schedule_id は参照用に保持（NULL許可に変更して柔軟性を持たせる）
ALTER TABLE shift_assignments MODIFY schedule_id INT DEFAULT NULL;
