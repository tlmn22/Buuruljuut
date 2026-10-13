-- =====================================================================
-- Тээврийн модулийн нэмэлт баганууд — migrate_transport.sql-ийн эхний
-- хувилбараар үүссэн (эсвэл хуучин dump-аас сэргээсэн) DB-д ажиллуулна.
-- Байгаа өгөгдөлд хүрэхгүй. UTF-8 файлаас ажиллуулах (кирилл ENUM):
--   mysql -u root --default-character-set=utf8mb4 buuruljuut -e "source migrate_transport_v2.sql"
-- =====================================================================

SET NAMES utf8mb4;

-- Аяллын зорилго: ENUM → VARCHAR. Зөвшөөрөгдөх утгуудыг кодонд (TRANSPORT_PURPOSE_GROUPS) шалгана,
-- тиймээс шинэ зорилго нэмэхэд DB өөрчлөх шаардлагагүй.
ALTER TABLE transport_requests
  MODIFY travel_purpose VARCHAR(100) NOT NULL;
-- Хуучин жижиг үсгийн утгыг шинэ жагсаалттай тааруулна (BINARY — яг тэр бичиглэлтэй мөрийг л)
UPDATE transport_requests SET travel_purpose = '14/14 Ростер' WHERE BINARY travel_purpose = '14/14 ростер';
UPDATE transport_requests SET travel_purpose = '7/7 Ростер'   WHERE BINARY travel_purpose = '7/7 ростер';

-- Зорчигчийн хоолны байршил (TRANSPORT_MEAL_LOCATIONS: Станц / Кемп / Бусад)
ALTER TABLE transport_request_passengers
  ADD COLUMN IF NOT EXISTS meal_location ENUM('station','camp','other') NULL AFTER unit_name;

-- Нэгтгэсэн захиалгын хөдлөх / буцах газар, цаг (хүсэгчид харагдана)
ALTER TABLE transport_orders
  ADD COLUMN IF NOT EXISTS depart_location VARCHAR(255) NULL AFTER note,
  ADD COLUMN IF NOT EXISTS depart_at       DATETIME     NULL AFTER depart_location,
  ADD COLUMN IF NOT EXISTS return_location VARCHAR(255) NULL AFTER depart_at,
  ADD COLUMN IF NOT EXISTS return_at       DATETIME     NULL AFTER return_location;
