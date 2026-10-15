-- 体験コマに会場（既存の教室）を設定できるようにするマイグレーション
-- 本番(Lolipop)・ローカルとも、phpMyAdminで1回だけ実行してください

ALTER TABLE schedules
ADD COLUMN venue_location_id INT DEFAULT NULL AFTER location_id,
ADD CONSTRAINT fk_schedules_venue FOREIGN KEY (venue_location_id) REFERENCES locations(id) ON DELETE SET NULL;
