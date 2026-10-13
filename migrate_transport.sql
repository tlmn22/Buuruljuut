-- =====================================================================
-- buuruljuut.sql дээр дутуу байгаа хүснэгтүүд — Тээврийн модуль (transport)
-- schema_new.sql-ийн 16-р хэсгээс авав. Байгаа өгөгдөлд хүрэхгүй,
-- IF NOT EXISTS тул дахин ажиллуулахад аюулгүй.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- SuperAdmin-аар чөлөөтэй нэмэгддэг/хасагддаг 2 global role
CREATE TABLE IF NOT EXISTS transport_roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  role          ENUM('director','transport_manager') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transport_role (employee_id, role),
  CONSTRAINT fk_transport_roles_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нэгтгэсэн бодит тээврийн захиалга (transport_requests.order_id энд заана)
CREATE TABLE IF NOT EXISTS transport_orders (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transport_manager_id  INT UNSIGNED NOT NULL,
  status                ENUM('draft','pending_director','director_rejected','completed') NOT NULL DEFAULT 'draft',
  note                  VARCHAR(500) NULL,
  depart_location       VARCHAR(255) NULL,   -- Хөдлөх газар (менежер гараар бичнэ, хүсэгчид харагдана)
  depart_at             DATETIME NULL,
  return_location       VARCHAR(255) NULL,   -- Буцаж хөдлөх газар
  return_at             DATETIME NULL,
  director_id           INT UNSIGNED NULL,
  director_reviewed_at  TIMESTAMP NULL DEFAULT NULL,
  director_comment      VARCHAR(500) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transport_order_tm FOREIGN KEY (transport_manager_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_order_director FOREIGN KEY (director_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_requests (
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_id                    INT UNSIGNED NOT NULL,
  travel_direction                VARCHAR(255) NOT NULL,
  travel_purpose                  VARCHAR(100) NOT NULL,   -- TRANSPORT_PURPOSE_GROUPS (functions.php) дахь зорилго
  start_date                      DATE NOT NULL,
  end_date                        DATE NOT NULL,
  total_days                      INT UNSIGNED NOT NULL DEFAULT 1,
  total_km                        DECIMAL(8,1) NULL,
  status                          ENUM('pending_manager','manager_rejected','pending_director','director_rejected','pending_transport_manager','merged','completed') NOT NULL DEFAULT 'pending_manager',
  manager_id                      INT UNSIGNED NULL,
  manager_reviewed_at             TIMESTAMP NULL DEFAULT NULL,
  manager_comment                 VARCHAR(500) NULL,
  director_id                     INT UNSIGNED NULL,
  director_reviewed_at            TIMESTAMP NULL DEFAULT NULL,
  director_comment                VARCHAR(500) NULL,
  transport_manager_id            INT UNSIGNED NULL,
  transport_manager_completed_at  TIMESTAMP NULL DEFAULT NULL,
  transport_manager_note          VARCHAR(500) NULL,
  order_id                        INT UNSIGNED NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transport_req_requester FOREIGN KEY (requester_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_req_manager FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_transport_req_director FOREIGN KEY (director_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_transport_req_tmanager FOREIGN KEY (transport_manager_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_transport_req_order FOREIGN KEY (order_id) REFERENCES transport_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ажилтны мэдээлэл хүснэгт (бүртгэлтэй ажилтан эсвэл гараар оруулсан зочин)
CREATE TABLE IF NOT EXISTS transport_request_passengers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id    INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NULL,
  type          VARCHAR(50) NULL,
  full_name     VARCHAR(200) NULL,
  organization  VARCHAR(200) NULL,
  position      VARCHAR(200) NULL,
  unit_name     VARCHAR(200) NULL,
  meal_location ENUM('station','camp','other') NULL,   -- TRANSPORT_MEAL_LOCATIONS: Станц / Кемп / Бусад
  food_morning  TINYINT(1) NOT NULL DEFAULT 0,
  food_lunch    TINYINT(1) NOT NULL DEFAULT 0,
  food_dinner   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_pax_request FOREIGN KEY (request_id) REFERENCES transport_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_pax_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Автомашины мэдээлэл (vehicle_type_id: TRANSPORT_VEHICLE_TYPES — lx/lc/b24/b45/t5/t8)
CREATE TABLE IF NOT EXISTS transport_request_vehicles (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id        INT UNSIGNED NOT NULL,
  vehicle_type_id   VARCHAR(10) NOT NULL,
  qty               INT UNSIGNED NOT NULL DEFAULT 1,
  selected_seats    TEXT NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_veh_request FOREIGN KEY (request_id) REFERENCES transport_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Физик машин тус бүрийн гэрээ/жолооч/шатахууны мэдээлэл
CREATE TABLE IF NOT EXISTS transport_vehicle_units (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicle_id        INT UNSIGNED NOT NULL,
  unit_index        INT UNSIGNED NOT NULL DEFAULT 1,
  is_rented         TINYINT(1) NULL,
  rental_company    VARCHAR(200) NULL,
  rental_days       INT UNSIGNED NULL,
  rental_rate       DECIMAL(12,2) NULL,
  rental_total      DECIMAL(12,2) NULL,
  plate_number      VARCHAR(20) NULL,
  driver_name       VARCHAR(100) NULL,
  driver_phone      VARCHAR(20) NULL,
  fuel_type         VARCHAR(20) NULL,
  distance_km       DECIMAL(8,1) NULL,
  fuel_norm         DECIMAL(6,2) NULL,
  fuel_price        DECIMAL(10,2) NULL,
  fuel_card_number  VARCHAR(50) NULL,
  fuel_total        DECIMAL(12,2) NULL,
  UNIQUE KEY uq_transport_unit (vehicle_id, unit_index),
  CONSTRAINT fk_transport_unit_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_request_vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нэгтгэсэн захиалга дээрх машины төрөл/тоо
CREATE TABLE IF NOT EXISTS transport_order_vehicles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  vehicle_type_id VARCHAR(10) NOT NULL,
  qty             INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_ordveh_order FOREIGN KEY (order_id) REFERENCES transport_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- transport_vehicle_units-тэй ижил бүтэц, нэгтгэсэн захиалгын машин руу заана
CREATE TABLE IF NOT EXISTS transport_order_vehicle_units (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_vehicle_id  INT UNSIGNED NOT NULL,
  unit_index        INT UNSIGNED NOT NULL DEFAULT 1,
  is_rented         TINYINT(1) NULL,
  rental_company    VARCHAR(200) NULL,
  rental_days       INT UNSIGNED NULL,
  rental_rate       DECIMAL(12,2) NULL,
  rental_total      DECIMAL(12,2) NULL,
  plate_number      VARCHAR(20) NULL,
  driver_name       VARCHAR(100) NULL,
  driver_phone      VARCHAR(20) NULL,
  fuel_type         VARCHAR(20) NULL,
  distance_km       DECIMAL(8,1) NULL,
  fuel_norm         DECIMAL(6,2) NULL,
  fuel_price        DECIMAL(10,2) NULL,
  fuel_card_number  VARCHAR(50) NULL,
  fuel_total        DECIMAL(12,2) NULL,
  UNIQUE KEY uq_transport_order_unit (order_vehicle_id, unit_index),
  CONSTRAINT fk_transport_ordunit_vehicle FOREIGN KEY (order_vehicle_id) REFERENCES transport_order_vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
