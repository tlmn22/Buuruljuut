-- =====================================================================
-- Мэдээ мэдээлэл (blog) + ерөнхий мэдэгдэл (notifications)
-- Байгаа өгөгдөлд хүрэхгүй, давхар ажиллуулахад аюулгүй. UTF-8 файлаас ажиллуулна:
--   mysql -u root --default-character-set=utf8mb4 buuruljuut -e "source migrate_news.sql"
-- =====================================================================

SET NAMES utf8mb4;

-- Нийтлэл. status='published' бөгөөд published_at <= NOW() болмогц харагдана (хуваарьт нийтлэл).
-- notified_at — мэдэгдэл/имэйлийг нэг л удаа илгээсний тэмдэглэгээ.
CREATE TABLE IF NOT EXISTS news_posts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  author_id       INT UNSIGNED NOT NULL,
  title           VARCHAR(255) NOT NULL,
  category        VARCHAR(50)  NOT NULL,          -- NEWS_CATEGORIES (modules/news/functions.php)
  cover_image     VARCHAR(255) NULL,
  body            MEDIUMTEXT   NOT NULL,          -- цэвэрлэгдсэн HTML (Quill)
  audience        ENUM('all','units') NOT NULL DEFAULT 'all',
  is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
  allow_comments  TINYINT(1) NOT NULL DEFAULT 1,
  notify_email    TINYINT(1) NOT NULL DEFAULT 0,  -- бүх ажилтны нэгдсэн хаяг руу имэйл
  status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at    DATETIME NULL,
  notified_at     DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_news_posts_feed (status, published_at),
  CONSTRAINT fk_news_posts_author FOREIGN KEY (author_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- audience='units' үед хамаарах нэгжүүд (дэд нэгжүүд нь мөн хамрагдана)
CREATE TABLE IF NOT EXISTS news_post_units (
  post_id      INT UNSIGNED NOT NULL,
  org_unit_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, org_unit_id),
  CONSTRAINT fk_news_units_post FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_units_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сэтгэгдэл, нэг түвшний хариулттай (parent_id). Устгасныг deleted_at-аар тэмдэглэнэ (хариултууд үлдэнэ).
CREATE TABLE IF NOT EXISTS news_comments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id      INT UNSIGNED NOT NULL,
  employee_id  INT UNSIGNED NOT NULL,
  parent_id    INT UNSIGNED NULL,
  body         TEXT NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  deleted_at   TIMESTAMP NULL DEFAULT NULL,
  KEY idx_news_comments_post (post_id, created_at),
  CONSTRAINT fk_news_comments_post FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_comments_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_comments_parent FOREIGN KEY (parent_id) REFERENCES news_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_likes (
  post_id      INT UNSIGNED NOT NULL,
  employee_id  INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, employee_id),
  CONSTRAINT fk_news_likes_post FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_likes_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_reads (
  post_id      INT UNSIGNED NOT NULL,
  employee_id  INT UNSIGNED NOT NULL,
  read_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, employee_id),
  CONSTRAINT fk_news_reads_post FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_reads_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ерөнхий мэдэгдэл (🔔) — мэдээ, цаашид KPI, тээвэр гэх мэт бүх модуль ашиглана
CREATE TABLE IF NOT EXISTS notifications (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT UNSIGNED NOT NULL,            -- хүлээн авагч
  type         VARCHAR(50)  NOT NULL,            -- news.post, news.comment, news.reply, ...
  title        VARCHAR(255) NOT NULL,
  body         VARCHAR(500) NULL,
  link         VARCHAR(500) NULL,                -- BASE_URL-ээс хойших зам (/modules/news/post.php?id=5)
  actor_id     INT UNSIGNED NULL,                -- үйлдэл хийсэн ажилтан
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at      TIMESTAMP NULL DEFAULT NULL,
  KEY idx_notifications_inbox (employee_id, read_at, created_at),
  CONSTRAINT fk_notifications_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нийтлэх эрх — superadmin "Нийтлэгчид" хуудаснаас user_permissions-аар олгоно
INSERT IGNORE INTO permissions (code, module, action, description)
VALUES ('news.publish', 'news', 'publish', 'Мэдээ мэдээлэл нийтлэх');
