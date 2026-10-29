-- =====================================================================
-- Хурлын өрөө захиалгын модуль (intranet төслийн модулийг шилжүүлсэн)
-- Өрөө, хариуцагч, захиалга (давтамжтай), оролцогч, боломжгүй цаг (давтамжтай).
-- Давхар ажиллуулахад аюулгүй. UTF-8 файлаас ажиллуулна:
--   mysql -u root --default-character-set=utf8mb4 buuruljuut -e "source migrate_meetingroom.sql"
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS meetingroom_rooms (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  floor       VARCHAR(50)  NULL,
  capacity    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  photo       VARCHAR(255) NULL,            -- uploads/meetingroom/ доторх файлын нэр
  notes       TEXT NULL,
  amenities   JSON NULL,                    -- MR_AMENITIES түлхүүрүүд
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Хариуцагч — захиалга батлах, өрөө удирдах (HR / superadmin томилно)
CREATE TABLE IF NOT EXISTS meetingroom_managers (
  employee_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  granted_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mrm_emp     FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_mrm_granted FOREIGN KEY (granted_by)  REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetingroom_bookings (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id              INT UNSIGNED NOT NULL,
  requested_by         INT UNSIGNED NOT NULL,
  start_dt             DATETIME NOT NULL,
  end_dt               DATETIME NOT NULL,
  title                VARCHAR(200) NULL,
  description          TEXT NULL,
  has_external_guests  TINYINT(1) NOT NULL DEFAULT 0,
  needs_equipment      TINYINT(1) NOT NULL DEFAULT 0,
  recurrence           ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  recurrence_end       DATE NULL,
  recurrence_group     VARCHAR(40) NULL,    -- нэг давтамжийн бүх захиалга ижил утгатай
  status               ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  approved_by          INT UNSIGNED NULL,
  approved_at          DATETIME NULL,
  reject_reason        VARCHAR(255) NULL,
  cancelled_by         INT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mrb_room_time (room_id, start_dt, end_dt),
  KEY idx_mrb_status (status, start_dt),
  KEY idx_mrb_group (recurrence_group),
  CONSTRAINT fk_mrb_room      FOREIGN KEY (room_id)      REFERENCES meetingroom_rooms(id),
  CONSTRAINT fk_mrb_requested FOREIGN KEY (requested_by) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_mrb_approved  FOREIGN KEY (approved_by)  REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_mrb_cancelled FOREIGN KEY (cancelled_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetingroom_booking_attendees (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id     INT UNSIGNED NOT NULL,
  employee_id    INT UNSIGNED NULL,
  attendee_name  VARCHAR(100) NULL,         -- ажилтан биш (гадны) оролцогч
  CONSTRAINT fk_mrba_booking FOREIGN KEY (booking_id)  REFERENCES meetingroom_bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_mrba_emp     FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Өрөөг тодорхой хугацаанд захиалга авахгүй болгох (засвар, цэвэрлэгээ г.м.)
CREATE TABLE IF NOT EXISTS meetingroom_blackouts (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id           INT UNSIGNED NOT NULL,
  start_dt          DATETIME NOT NULL,
  end_dt            DATETIME NOT NULL,
  reason            VARCHAR(255) NULL,
  recurrence        ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  recurrence_end    DATE NULL,
  recurrence_group  VARCHAR(40) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mrbo_room_time (room_id, start_dt, end_dt),
  CONSTRAINT fk_mrbo_room    FOREIGN KEY (room_id)    REFERENCES meetingroom_rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_mrbo_created FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
