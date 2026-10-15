-- =====================================================================
-- Нууц үг солих / сэргээх
--   users.must_change_password — admin-ы өгсөн түр нууц үгээр нэвтэрмэгц солиулна
--   password_resets            — имэйлээр илгээсэн сэргээх холбоосын токенууд
--                                (токеныг өөрийг нь биш SHA-256 hash-ийг хадгална)
-- Байгаа өгөгдөлд хүрэхгүй, давхар ажиллуулахад аюулгүй.
--   mysql -u root --default-character-set=utf8mb4 buuruljuut -e "source migrate_password_reset.sql"
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
  ADD COLUMN IF NOT EXISTS password_changed_at  TIMESTAMP  NULL DEFAULT NULL AFTER must_change_password;

CREATE TABLE IF NOT EXISTS password_resets (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_at       DATETIME     NULL DEFAULT NULL,
  requested_ip  VARCHAR(45)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id, created_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
