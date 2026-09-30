-- =====================================================================
-- Buuruljuut — Шинэ DB схем (ЦЭЦЭНС МАЙНИНГ ЭНД ЭНЕРЖИ ХХК)
-- Санал болгож буй хувилбар — ХЯНАЖ ҮЗЭЭД ЗӨВШӨӨРСНИЙ ДАРАА АЖИЛЛУУЛНА
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Хуучин хүснэгтүүдийг устгах (бүрмөсөн орлуулна)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS regulation_reads;
DROP TABLE IF EXISTS regulation_org_units;
DROP TABLE IF EXISTS regulations;
DROP TABLE IF EXISTS regulation_categories;
DROP TABLE IF EXISTS kpi_items;
DROP TABLE IF EXISTS kpi_evaluations;
DROP TABLE IF EXISTS kpi_periods;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS org_units;
DROP TABLE IF EXISTS onefitoid;
DROP TABLE IF EXISTS onefit;
DROP TABLE IF EXISTS divisions;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS companies;

-- =====================================================================
-- 1. companies — байгууллага (ирээдүйд өргөтгөх боломжтой, одоогоор 1 мөр)
-- =====================================================================
CREATE TABLE companies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(200) NOT NULL,
  description   TEXT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO companies (id, name) VALUES
  (1, 'ЦЭЦЭНС МАЙНИНГ ЭНД ЭНЕРЖИ ХХК');

-- =====================================================================
-- 2. org_units — газар/хэлтэс/алба/хэсэг НЭГ хүснэгтэд (recursive tree)
--    parent_id = NULL  → хамгийн дээд түвшин (жишээ нь 6 газар)
--    unit_type          → зөвхөн харагдац/өнгө ялгах чөлөөт текст,
--                          ямар ч давхарга/дараалал тулгахгүй
-- =====================================================================
CREATE TABLE org_units (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            INT UNSIGNED NOT NULL,
  parent_id             INT UNSIGNED NULL,
  unit_type             VARCHAR(50) NULL,
  name                  VARCHAR(200) NOT NULL,
  description           TEXT NULL,
  manager_employee_id   INT UNSIGNED NULL,   -- FK employees руу доор ALTER-аар нэмнэ
  sort_order            INT NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_units_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_org_units_parent  FOREIGN KEY (parent_id)  REFERENCES org_units(id) ON DELETE SET NULL,
  INDEX idx_org_units_parent (parent_id),
  INDEX idx_org_units_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Анхны 6 газар (parent_id = NULL, зурган дээрх байдлаар)
INSERT INTO org_units (id, company_id, parent_id, unit_type, name, sort_order) VALUES
  (1, 1, NULL, 'газар', 'Төслийн удирдлагын газар',                1),
  (2, 1, NULL, 'газар', 'Цахилгаан станцын удирдлагын газар',      2),
  (3, 1, NULL, 'газар', 'Уурхайн удирдлагын газар',                3),
  (4, 1, NULL, 'газар', 'Санхүүгийн удирдлагын газар',             4),
  (5, 1, NULL, 'газар', 'Худалдан авалт, бизнес хөгжлийн газар',   5),
  (6, 1, NULL, 'газар', 'Үйл ажиллагааны газар',                   6);

-- Үйл ажиллагааны газрын дэд нэгжүүд (employeeUAUG.xlsx-ээс)
INSERT INTO org_units (id, company_id, parent_id, unit_type, name, sort_order) VALUES
  (7, 1, 6, 'хэлтэс', 'Аж ахуй, тээвэр зохицуулалтын хэлтэс-ҮАГ', 1),
  (8, 1, 6, 'хэлтэс', 'Захиргаа, олон нийттэй харилцах хэлтэс-ҮАГ', 2),
  (9, 1, 6, 'хэлтэс', 'Хуулийн хэлтэс-ҮАГ', 3),
  (10, 1, 6, 'алба',  'Мэдээллийн технологийн алба-ҮАГ', 4);

-- =====================================================================
-- 3. employees — цэвэр HR мэдээлэл (auth-гүй)
-- =====================================================================
CREATE TABLE employees (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code     VARCHAR(50) NOT NULL,
  last_name         VARCHAR(100) NOT NULL,
  first_name        VARCHAR(100) NOT NULL,
  register_number   VARCHAR(20) NOT NULL,
  email             VARCHAR(150) NULL,
  phone             VARCHAR(20) NULL,
  position          VARCHAR(200) NULL,
  contract_level    VARCHAR(50) NULL,
  hire_date         DATE NULL,
  termination_date  DATE NULL,
  org_unit_id       INT UNSIGNED NULL,
  photo             VARCHAR(255) NULL,
  -- Bodi/Cetsens HR системээс ирсэн нэмэлт талбарууд
  global_dimension_1  VARCHAR(50) NULL,
  global_dimension_2  VARCHAR(50) NULL,
  working_condition   VARCHAR(50) NULL,
  calendar_work_type  VARCHAR(50) NULL,
  work_schedule_code  VARCHAR(50) NULL,
  contract_type       VARCHAR(50) NULL,
  -- KPI-г хэн үнэлэхийг org бүтцийн менежерээс үл хамааран тусад нь тохируулна
  default_evaluator_id INT UNSIGNED NULL,
  -- 'auto' = org_units.manager_employee_id-ээс автоматаар тооцсон, 'manual' = HR гараар сонгосон (автомат синк дахин дарахгүй)
  evaluator_source      ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employees_code (employee_code),
  UNIQUE KEY uq_employees_register (register_number),
  CONSTRAINT fk_employees_org_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_evaluator FOREIGN KEY (default_evaluator_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Superadmin bootstrap ажилтан
INSERT INTO employees (id, employee_code, last_name, first_name, register_number, position, org_unit_id, is_active) VALUES
  (1, 'EMP001', 'Админ', 'Супер', 'AA00000001', 'Систем администратор', NULL, 1);

-- org_units.manager_employee_id -> employees FK-г одоо холбоно (тойрог хамаарал тул дараа нь)
ALTER TABLE org_units
  ADD CONSTRAINT fk_org_units_manager FOREIGN KEY (manager_employee_id)
  REFERENCES employees(id) ON DELETE SET NULL;

-- =====================================================================
-- 4. users — нэвтрэлт (employees-ээс тусгаарлагдсан, 1:1)
-- =====================================================================
CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  username        VARCHAR(100) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at   TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_employee (employee_id),
  UNIQUE KEY uq_users_username (username),
  CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Superadmin bootstrap хэрэглэгч (username: EMP001 / password: 123456 — эхний нэвтрэлтийн дараа солино)
INSERT INTO users (id, employee_id, username, password_hash) VALUES
  (1, 1, 'EMP001', '$2y$10$TCUPENQoQlko6UYGI87Zne0SaZSpulmVb01.zHrrNcURv99zJLeJ2');

-- =====================================================================
-- 5. roles — үндсэн эрхийн багц (анхны утга, чөлөөтэй нэмж болно)
-- =====================================================================
CREATE TABLE roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(50) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  description   TEXT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, code, name, description) VALUES
  (1, 'superadmin', 'Супер Админ',      'Бүх эрхтэй'),
  (2, 'hr',         'HR Менежер',       'Бүтэц болон ажилтны мэдээллийг удирдана'),
  (3, 'director',   'Газрын захирал',   'Газрын түвшний батламж, хяналт'),
  (4, 'manager',    'Хэлтсийн захирал', 'Хэлтэс/алба түвшний батламж'),
  (5, 'employee',   'Ажилтан',          'Зөвхөн өөрийн мэдээлэл');

-- =====================================================================
-- 6. permissions — module.action хэлбэрийн жижиг нэгж эрхүүд
-- =====================================================================
CREATE TABLE permissions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(100) NOT NULL,
  module        VARCHAR(50) NOT NULL,
  action        VARCHAR(50) NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, module, action, description) VALUES
  ('companies.view',    'companies',   'view',    'Компанийн мэдээлэл харах'),
  ('companies.edit',    'companies',   'edit',    'Компанийн мэдээлэл засах'),

  ('org_units.view',    'org_units',   'view',    'Байгууллагын бүтэц харах'),
  ('org_units.create',  'org_units',   'create',  'Газар/хэлтэс/алба үүсгэх'),
  ('org_units.edit',    'org_units',   'edit',    'Газар/хэлтэс/алба засах'),
  ('org_units.delete',  'org_units',   'delete',  'Газар/хэлтэс/алба устгах'),

  ('employees.view',    'employees',   'view',    'Ажилтны мэдээлэл харах'),
  ('employees.create',  'employees',   'create',  'Ажилтан бүртгэх'),
  ('employees.edit',    'employees',   'edit',    'Ажилтны мэдээлэл засах'),
  ('employees.delete',  'employees',   'delete',  'Ажилтныг идэвхгүй болгох/устгах'),

  ('users.view',            'users', 'view',            'Хэрэглэгчийн бүртгэл харах'),
  ('users.create',          'users', 'create',          'Хэрэглэгчийн бүртгэл үүсгэх'),
  ('users.edit',            'users', 'edit',            'Хэрэглэгчийн бүртгэл засах'),
  ('users.reset_password',  'users', 'reset_password',  'Нууц үг шинэчлэх'),

  ('roles.view',               'roles', 'view',               'Role-ийн жагсаалт харах'),
  ('roles.manage',             'roles', 'manage',             'Role үүсгэх/засах/устгах'),
  ('roles.assign',             'roles', 'assign',             'Хэрэглэгчид role оноох'),

  ('user_permissions.manage',  'user_permissions', 'manage',  'Ажилтан тус бүрт эрх шууд нэмэх/хасах (dynamic)'),

  ('kpi.view_own',       'kpi', 'view_own',       'Өөрийн KPI хуудас харах/оруулах'),
  ('kpi.approve',        'kpi', 'approve',        'Багийн гишүүдийн KPI төлөвлөгөө батлах'),
  ('kpi.evaluate',       'kpi', 'evaluate',       'Багийн гишүүдийн KPI-г үнэлэх'),
  ('kpi.view_all',       'kpi', 'view_all',       'Бүх ажилтны KPI харах'),
  ('kpi.manage_periods', 'kpi', 'manage_periods', 'KPI үнэлгээний хугацаа үүсгэх/тохируулах'),
  ('kpi.manage_evaluators', 'kpi', 'manage_evaluators', 'Ажилтан тус бүрийн үнэлэгчийг тохируулах');

-- =====================================================================
-- 7. role_permissions — role-ийн анхдагч эрхийн багц
-- =====================================================================
CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- superadmin: бүх эрх
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;

-- hr: бүтэц + ажилтан + users бүрэн, role зөвхөн харах, KPI-г бүхэлд нь хянана
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE code IN (
    'companies.view',
    'org_units.view','org_units.create','org_units.edit',
    'employees.view','employees.create','employees.edit',
    'users.view','users.create','users.edit','users.reset_password',
    'roles.view',
    'kpi.view_own','kpi.view_all','kpi.manage_periods','kpi.manage_evaluators'
  );

-- director: харах голлосон + KPI бүгдийг харах
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE code IN (
    'org_units.view','employees.view','users.view',
    'kpi.view_own','kpi.view_all'
  );

-- manager: өөрийн хэлтэс/алба-даа ажиллах (scope-ийг апп түвшинд хязгаарлана), багийнхаа KPI батлах/үнэлэх
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE code IN (
    'org_units.view','employees.view','employees.edit',
    'kpi.view_own','kpi.approve','kpi.evaluate'
  );

-- employee: зөвхөн харах эрх, апп түвшинд өөрийн мэдээллээр хязгаарлана, өөрийн KPI-гаа удирдана
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 5, id FROM permissions WHERE code IN (
    'employees.view',
    'kpi.view_own'
  );

-- =====================================================================
-- 8. user_roles — хэрэглэгч ↔ role (олон:олон, 1-ээс олон role авч болно)
-- =====================================================================
CREATE TABLE user_roles (
  user_id      INT UNSIGNED NOT NULL,
  role_id      INT UNSIGNED NOT NULL,
  granted_by   INT UNSIGNED NULL,
  granted_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Superadmin хэрэглэгчид superadmin role оноох
INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);

-- =====================================================================
-- 9. user_permissions — хэрэглэгч тус бүрт шууд эрх (role-оос үл хамаарна)
--    effect = 'allow' → role-д байхгүй ч нэмж өгнө
--    effect = 'deny'  → role-оос ирсэн ч байсан хориглоно (deny нь давамгайлна)
-- =====================================================================
CREATE TABLE user_permissions (
  user_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  effect         ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  granted_by     INT UNSIGNED NULL,
  granted_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     TIMESTAMP NULL,
  PRIMARY KEY (user_id, permission_id),
  CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. kpi_periods — үнэлгээний хугацаанууд (жингийн тохиргоо энд байна)
-- =====================================================================
CREATE TABLE kpi_periods (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(150) NOT NULL,
  start_date             DATE NOT NULL,
  end_date               DATE NOT NULL,
  personal_kpi_weight    DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  core_duty_weight       DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  special_task_weight    DECIMAL(4,3) NOT NULL DEFAULT 0.100,
  self_weight            DECIMAL(4,3) NOT NULL DEFAULT 0.200,
  manager_weight         DECIMAL(4,3) NOT NULL DEFAULT 0.800,
  is_active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 11. kpi_evaluations — ажилтан + хугацаа тус бүрийн KPI хуудас
--     status: planning -> planning_approved -> self_evaluated -> completed
-- =====================================================================
CREATE TABLE kpi_evaluations (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id              INT UNSIGNED NOT NULL,
  employee_id            INT UNSIGNED NOT NULL,
  evaluator_id           INT UNSIGNED NULL,   -- employees.default_evaluator_id-аас тухайн үед хуулбарлагдана

  -- Түүхэн бичлэгийн үнэн зөв байдлыг хадгалах snapshot талбарууд
  position_snapshot      VARCHAR(200) NULL,
  org_unit_snapshot      VARCHAR(200) NULL,

  status                 ENUM('planning','planning_approved','self_evaluated','completed') NOT NULL DEFAULT 'planning',

  planning_submitted_at  TIMESTAMP NULL,
  approved_by            INT UNSIGNED NULL,   -- employees.id (баталсан менежер)
  approved_at            TIMESTAMP NULL,

  self_submitted_at      TIMESTAMP NULL,
  evaluation_interview_date DATE NULL,
  manager_submitted_at   TIMESTAMP NULL,

  final_main_score       DECIMAL(6,3) NULL,
  final_bonus_score      DECIMAL(6,3) NULL,
  final_total_score      DECIMAL(6,3) NULL,

  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_kpi_eval_employee_period (employee_id, period_id),
  CONSTRAINT fk_kpi_eval_period    FOREIGN KEY (period_id)   REFERENCES kpi_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_eval_employee  FOREIGN KEY (employee_id) REFERENCES employees(id)   ON DELETE CASCADE,
  CONSTRAINT fk_kpi_eval_evaluator FOREIGN KEY (evaluator_id)REFERENCES employees(id)   ON DELETE SET NULL,
  CONSTRAINT fk_kpi_eval_approver  FOREIGN KEY (approved_by) REFERENCES employees(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 12. kpi_items — динамик мөрүүд (3 төрөл: хувийн KPI / чиг үүрэг / нэмэлт ажил)
-- =====================================================================
CREATE TABLE kpi_items (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_id      INT UNSIGNED NOT NULL,
  section            ENUM('personal_kpi','core_duty','special_task') NOT NULL,
  sort_order         INT NOT NULL DEFAULT 0,

  title              VARCHAR(500) NOT NULL,   -- Зорилтот ажил / Чиг үүргийн ажил / Нэмэлт ажил
  kpi_target         VARCHAR(500) NULL,       -- зөвхөн personal_kpi: "KPI-ийн зорилт"
  frequency          ENUM('day','week','month','quarter') NULL,  -- зөвхөн core_duty
  metric             VARCHAR(500) NULL,       -- core_duty: "Зорилт/Хэмжигдэхүүн"

  importance_weight  TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1=Дунд, 2=Чухал, 3=Маш чухал
  difficulty_weight  TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1=Дунд, 2=Хүнд, 3=Маш хүнд

  performance_note   TEXT NULL,               -- Гүйцэтгэлийн тайлбар
  self_score         DECIMAL(3,1) NULL,        -- 0 - 3, 0.5 нарийвчлалтай
  manager_score      DECIMAL(3,1) NULL,

  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_kpi_items_evaluation FOREIGN KEY (evaluation_id) REFERENCES kpi_evaluations(id) ON DELETE CASCADE,
  INDEX idx_kpi_items_evaluation (evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. Дүрэм журам модуль — ангилал, баримт (PDF), газар/хэлтэс хамаарал, унших бүртгэл
-- =====================================================================
CREATE TABLE regulation_categories (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE regulations (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id         INT UNSIGNED NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT NULL,
  file_path           VARCHAR(255) NOT NULL,
  file_original_name  VARCHAR(255) NULL,
  approved_date       DATE NULL,
  require_ack         TINYINT(1) NOT NULL DEFAULT 0,   -- заавал уншиж танилцах
  created_by          INT UNSIGNED NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_regulations_category FOREIGN KEY (category_id) REFERENCES regulation_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_regulations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_regulations_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тухайн журам аль газар/хэлтэс/алба нэгжид хамаарахыг заана (олон:олон); хоосон бол бүх компанид хамаарна
CREATE TABLE regulation_org_units (
  regulation_id  INT UNSIGNED NOT NULL,
  org_unit_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (regulation_id, org_unit_id),
  CONSTRAINT fk_reg_org_regulation FOREIGN KEY (regulation_id) REFERENCES regulations(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_org_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ажилтан тухайн журмыг нээж үзсэн бүртгэл (танилцсан гэж тооцно)
CREATE TABLE regulation_reads (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  regulation_id  INT UNSIGNED NOT NULL,
  employee_id    INT UNSIGNED NOT NULL,
  read_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reg_read (regulation_id, employee_id),
  CONSTRAINT fk_reg_reads_regulation FOREIGN KEY (regulation_id) REFERENCES regulations(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_reads_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, module, action, description) VALUES
  ('regulations.manage', 'regulations', 'manage', 'Дүрэм журам ангилал/баримт нэмэх, засах, устгах, унших тайлан харах, экспорт хийх');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r, permissions p
  WHERE r.code IN ('superadmin','hr') AND p.code = 'regulations.manage';

-- =====================================================================
-- 14. OneFit модуль — сарын бүртгэл (onefitoid) ба ажилтны захиалга (onefit)
-- =====================================================================
CREATE TABLE `onefitoid` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) NOT NULL,
  `StartDate` varchar(20) NOT NULL,
  `EndDate` varchar(20) NOT NULL,
  `Status` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `onefit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ofId` int(11) NOT NULL,
  `EmployeeNumber` varchar(10) NOT NULL,
  `PhoneNumber` int(11) NOT NULL,
  `Day` int(11) NOT NULL,
  `Salary` int(11) NOT NULL,
  `Date` varchar(50) NOT NULL,
  `Status` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1970 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 15. KPI2 — туршилтын дээрээс доош урсгалтай KPI модуль
--     (одоогийн modules/kpi-с бүрэн тусгаарлагдсан: өөрийн гэсэн
--      manager/director оноолт (kpi2_unit_roles), org_units.manager_employee_id
--      болон employees.default_evaluator_id-г огт ашиглахгүй)
-- =====================================================================

CREATE TABLE kpi2_periods (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(150) NOT NULL,
  start_date            DATE NOT NULL,
  end_date              DATE NOT NULL,
  personal_kpi_weight   DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  core_duty_weight      DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  special_task_weight   DECIMAL(4,3) NOT NULL DEFAULT 0.100,
  self_weight           DECIMAL(4,3) NOT NULL DEFAULT 0.200,
  manager_weight        DECIMAL(4,3) NOT NULL DEFAULT 0.800,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тухайн org_unit дээр хэн KPI2 "manager" / "director" болохыг тохируулна
-- (жинхэнэ org_units.manager_employee_id-с бүрэн тусдаа — зөвхөн KPI2 туршилтад)
CREATE TABLE kpi2_unit_roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_unit_id   INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,
  role          ENUM('manager','director') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi2_unit_role (org_unit_id, role),
  CONSTRAINT fk_kpi2_unit_roles_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_unit_roles_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нэг Хэлтэс/Алба + нэг улирлын "ажлын багц" — захиралд батлуулах нэгж
CREATE TABLE kpi2_task_batches (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id      INT UNSIGNED NOT NULL,
  org_unit_id    INT UNSIGNED NOT NULL,
  created_by     INT UNSIGNED NOT NULL,
  status         ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  submitted_at   TIMESTAMP NULL DEFAULT NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    TIMESTAMP NULL DEFAULT NULL,
  review_note    VARCHAR(500) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi2_batch_period_unit (period_id, org_unit_id),
  CONSTRAINT fk_kpi2_batch_period FOREIGN KEY (period_id) REFERENCES kpi2_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_batch_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_batch_creator FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_batch_reviewer FOREIGN KEY (reviewed_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Багц доторх ажлууд (менежерийн оруулсан)
CREATE TABLE kpi2_org_tasks (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id            INT UNSIGNED NOT NULL,
  title               VARCHAR(500) NOT NULL,
  kpi_target          VARCHAR(500) NULL,
  frequency           ENUM('day','week','month','quarter') NULL,
  metric              VARCHAR(500) NULL,
  importance_weight   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  difficulty_weight   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order          INT NOT NULL DEFAULT 0,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_kpi2_org_task_batch FOREIGN KEY (batch_id) REFERENCES kpi2_task_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тухайн ажлыг хэн хэдэн хувь хариуцахыг (зөвхөн мэдээллийн зорилготой)
CREATE TABLE kpi2_org_task_assignments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_task_id   INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,
  percent       DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi2_assignment (org_task_id, employee_id),
  CONSTRAINT fk_kpi2_assignment_task FOREIGN KEY (org_task_id) REFERENCES kpi2_org_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_assignment_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нэг ажилтны нэг улирлын KPI2 хуудас
CREATE TABLE kpi2_evaluations (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id                   INT UNSIGNED NOT NULL,
  employee_id                 INT UNSIGNED NOT NULL,
  evaluator_id                INT UNSIGNED NULL,
  position_snapshot           VARCHAR(200) NULL,
  org_unit_snapshot           VARCHAR(200) NULL,
  status                      ENUM('planning','planning_approved','self_evaluated','completed') NOT NULL DEFAULT 'planning',
  planning_submitted_at       TIMESTAMP NULL DEFAULT NULL,
  approved_by                 INT UNSIGNED NULL,
  approved_at                 TIMESTAMP NULL DEFAULT NULL,
  self_submitted_at           TIMESTAMP NULL DEFAULT NULL,
  evaluation_interview_date   DATE NULL,
  manager_submitted_at        TIMESTAMP NULL DEFAULT NULL,
  final_main_score            DECIMAL(6,3) NULL,
  final_bonus_score           DECIMAL(6,3) NULL,
  final_total_score           DECIMAL(6,3) NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi2_eval_employee_period (employee_id, period_id),
  CONSTRAINT fk_kpi2_eval_period FOREIGN KEY (period_id) REFERENCES kpi2_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_eval_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_eval_evaluator FOREIGN KEY (evaluator_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_kpi2_eval_approver FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KPI2 мөрүүд (org_task_id гаралтай бол ажилтанд түгжигдсэн байна)
CREATE TABLE kpi2_items (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_id       INT UNSIGNED NOT NULL,
  section             ENUM('personal_kpi','core_duty','special_task') NOT NULL,
  org_task_id         INT UNSIGNED NULL,
  share_percent       DECIMAL(5,2) NULL,
  sort_order          INT NOT NULL DEFAULT 0,
  title               VARCHAR(500) NOT NULL,
  kpi_target          VARCHAR(500) NULL,
  frequency           ENUM('day','week','month','quarter') NULL,
  metric              VARCHAR(500) NULL,
  importance_weight   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  difficulty_weight   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  performance_note    TEXT NULL,
  self_score          DECIMAL(3,1) NULL,
  manager_score       DECIMAL(3,1) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi2_item_eval_task (evaluation_id, org_task_id),
  KEY idx_kpi2_items_evaluation (evaluation_id),
  CONSTRAINT fk_kpi2_items_evaluation FOREIGN KEY (evaluation_id) REFERENCES kpi2_evaluations(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi2_items_org_task FOREIGN KEY (org_task_id) REFERENCES kpi2_org_tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 16. Тээврийн хэрэгслийн захиалга (transport)
--     3 шаттай батлах урсгал: шууд удирдлага (employees.default_evaluator_id)
--     → Тээвэр хариуцсан захирал → Тээвэр хариуцсан менежер (сүүлийн 2 нь
--     SuperAdmin-аар dynamic нэмэгддэг/хасагддаг global role, нэгжид
--     хамааралгүй)
-- =====================================================================

-- SuperAdmin-аар chөлөөтэй нэмэгддэг/хасагддаг 2 global role
CREATE TABLE transport_roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  role          ENUM('director','transport_manager') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transport_role (employee_id, role),
  CONSTRAINT fk_transport_roles_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_requests (
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_id                    INT UNSIGNED NOT NULL,
  travel_direction                VARCHAR(255) NOT NULL,
  travel_purpose                  ENUM('14/14 ростер','7/7 ростер','5/2 ээлж','3/2 ээлж','Ашиглалтын ээлж','Уулын ээлж') NOT NULL,
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
  CONSTRAINT fk_transport_req_tmanager FOREIGN KEY (transport_manager_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ажилтны мэдээлэл хүснэгт (бүртгэлтэй ажилтан эсвэл гараар оруулсан зочин)
CREATE TABLE transport_request_passengers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id    INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NULL,
  type          VARCHAR(50) NULL,
  full_name     VARCHAR(200) NULL,
  organization  VARCHAR(200) NULL,
  position      VARCHAR(200) NULL,
  unit_name     VARCHAR(200) NULL,
  food_morning  TINYINT(1) NOT NULL DEFAULT 0,
  food_lunch    TINYINT(1) NOT NULL DEFAULT 0,
  food_dinner   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_pax_request FOREIGN KEY (request_id) REFERENCES transport_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_pax_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Автомашины мэдээлэл хүснэгт (vehicle_type_id нь modules/transport/functions.php-ийн
-- TRANSPORT_VEHICLE_TYPES hardcode каталогийн key: lx/lc/b24/b45/t5/t8)
CREATE TABLE transport_request_vehicles (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id        INT UNSIGNED NOT NULL,
  vehicle_type_id   VARCHAR(10) NOT NULL,
  qty               INT UNSIGNED NOT NULL DEFAULT 1,
  selected_seats    TEXT NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_veh_request FOREIGN KEY (request_id) REFERENCES transport_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тээвэр хариуцсан менежерийн бөглөдөг, физик машин ТУС БҮРИЙН (qty>1 бол олон мөр)
-- гэрээ/жолооч/шатахуулын мэдээлэл. rental_total = is_rented ? rental_days*rental_rate : 0.
-- fuel_total = (distance_km * fuel_norm / 100) * fuel_price (норм = литр/100км).
CREATE TABLE transport_vehicle_units (
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

-- Тээвэр хариуцсан менежерийн олон ажилтны хүсэлтийг нэгтгэж үүсгэдэг НЭГ бодит
-- тээврийн захиалга. transport_requests.order_id нь энд заана (нэгтгэгдсэн хүсэлт
-- бүрийн status='merged' болно, бодит явцыг энэ хүснэгтээс дагана).
CREATE TABLE transport_orders (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transport_manager_id  INT UNSIGNED NOT NULL,
  status                ENUM('draft','pending_director','director_rejected','completed') NOT NULL DEFAULT 'draft',
  note                  VARCHAR(500) NULL,
  director_id           INT UNSIGNED NULL,
  director_reviewed_at  TIMESTAMP NULL DEFAULT NULL,
  director_comment      VARCHAR(500) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transport_order_tm FOREIGN KEY (transport_manager_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_transport_order_director FOREIGN KEY (director_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transport_requests ADD CONSTRAINT fk_transport_req_order FOREIGN KEY (order_id) REFERENCES transport_orders(id) ON DELETE SET NULL;

-- Нэгтгэсэн захиалга дээр менежерийн шинээр сонгосон машины төрөл/тоо (vehicle_type_id: TRANSPORT_VEHICLE_TYPES)
CREATE TABLE transport_order_vehicles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  vehicle_type_id VARCHAR(10) NOT NULL,
  qty             INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_transport_ordveh_order FOREIGN KEY (order_id) REFERENCES transport_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- transport_vehicle_units-тэй яг адилхан бүтэц, зөвхөн нэгтгэсэн захиалгын машины мөр рүү заана
CREATE TABLE transport_order_vehicle_units (
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
