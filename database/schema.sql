-- Libris relational schema for MySQL 8.0.16+ / MariaDB 10.4+ (enforced CHECK constraints).
CREATE DATABASE IF NOT EXISTS libris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE libris;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  role ENUM('customer','librarian','admin') NOT NULL DEFAULT 'customer',
  preferred_lang ENUM('en','ckb') NOT NULL DEFAULT 'en',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Home',
  recipient_name VARCHAR(200) NOT NULL,
  line1 VARCHAR(200) NOT NULL,
  line2 VARCHAR(200) NULL,
  city VARCHAR(100) NOT NULL,
  region VARCHAR(100) NULL,
  postal_code VARCHAR(32) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'IQ',
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_addresses_user (user_id, is_default)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS authors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  sort_name VARCHAR(200) NULL,
  open_library_id VARCHAR(80) NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name_en VARCHAR(120) NOT NULL,
  name_ckb VARCHAR(120) NOT NULL,
  description_en VARCHAR(500) NULL,
  description_ckb VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS books (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(300) NOT NULL,
  subtitle VARCHAR(300) NULL,
  description TEXT NULL,
  language_code VARCHAR(16) NOT NULL DEFAULT 'en',
  isbn13 CHAR(13) NULL UNIQUE,
  open_library_id VARCHAR(80) NULL UNIQUE,
  cover_url VARCHAR(500) NULL,
  published_year SMALLINT UNSIGNED NULL,
  publisher VARCHAR(200) NULL,
  page_count SMALLINT UNSIGNED NULL,
  sale_price_iqd INT UNSIGNED NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_books_title (title),
  INDEX idx_books_active_created (is_active, created_at),
  CHECK (sale_price_iqd IS NULL OR sale_price_iqd > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_authors (
  book_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  author_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (book_id, author_id),
  CONSTRAINT fk_book_authors_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_authors_author FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_categories (
  book_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (book_id, category_id),
  CONSTRAINT fk_book_categories_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS catalog_guard (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  book_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CHECK (id = 1),
  CHECK (book_count <= 200)
) ENGINE=InnoDB;
INSERT INTO catalog_guard (id, book_count) VALUES (1, 0)
  ON DUPLICATE KEY UPDATE id = VALUES(id);

DELIMITER //
CREATE TRIGGER books_before_insert BEFORE INSERT ON books FOR EACH ROW
BEGIN
  UPDATE catalog_guard SET book_count = book_count + 1 WHERE id = 1 AND book_count < 200;
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Libris catalogue is limited to 200 titles';
  END IF;
END//
CREATE TRIGGER books_after_delete AFTER DELETE ON books FOR EACH ROW
BEGIN
  UPDATE catalog_guard SET book_count = GREATEST(book_count - 1, 0) WHERE id = 1;
END//
DELIMITER ;

CREATE TABLE IF NOT EXISTS book_copies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NOT NULL,
  inventory_code VARCHAR(48) NOT NULL UNIQUE,
  purpose ENUM('sale','loan') NOT NULL,
  status ENUM('available','reserved','sold','loaned','lost','retired') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_copies_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  INDEX idx_copies_availability (book_id, purpose, status)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW v_book_availability AS
SELECT b.id AS book_id,
       SUM(c.purpose = 'sale' AND c.status = 'available') AS sale_copies_available,
       SUM(c.purpose = 'loan' AND c.status = 'available') AS loan_copies_available,
       SUM(c.purpose = 'sale') AS sale_copies_total,
       SUM(c.purpose = 'loan') AS loan_copies_total
FROM books b LEFT JOIN book_copies c ON c.book_id = b.id
GROUP BY b.id;

CREATE TABLE IF NOT EXISTS cart_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
  added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_user_book (user_id, book_id),
  CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CHECK (quantity BETWEEN 1 AND 20)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  address_id BIGINT UNSIGNED NULL,
  shipping_address_snapshot JSON NULL,
  order_number VARCHAR(24) NOT NULL UNIQUE,
  status ENUM('pending','paid','processing','ready','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  subtotal_iqd INT UNSIGNED NOT NULL,
  total_iqd INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'IQD',
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
  INDEX idx_orders_user_date (user_id, created_at),
  INDEX idx_orders_status_date (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  copy_id BIGINT UNSIGNED NULL,
  title_snapshot VARCHAR(300) NOT NULL,
  unit_price_iqd INT UNSIGNED NOT NULL,
  quantity TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_copy FOREIGN KEY (copy_id) REFERENCES book_copies(id) ON DELETE SET NULL,
  CHECK (quantity BETWEEN 1 AND 20)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'simulation',
  status ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  amount_iqd INT UNSIGNED NOT NULL,
  reference_code VARCHAR(64) NOT NULL UNIQUE,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  copy_id BIGINT UNSIGNED NOT NULL,
  borrowed_at DATE NOT NULL,
  due_at DATE NOT NULL,
  returned_at DATE NULL,
  status ENUM('active','returned','lost') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_loans_copy FOREIGN KEY (copy_id) REFERENCES book_copies(id) ON DELETE RESTRICT,
  INDEX idx_loans_user_status (user_id, status, due_at),
  INDEX idx_loans_due (status, due_at),
  CHECK (due_at >= borrowed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  status ENUM('waiting','ready','fulfilled','cancelled','expired') NOT NULL DEFAULT 'waiting',
  queue_position INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ready_until DATE NULL,
  CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  INDEX idx_reservations_queue (book_id, status, created_at),
  INDEX idx_reservations_user (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL UNIQUE,
  amount_iqd INT UNSIGNED NOT NULL,
  status ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at TIMESTAMP NULL,
  CONSTRAINT fk_fines_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  title VARCHAR(160) NULL,
  body TEXT NULL,
  is_approved BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_user_book (user_id, book_id),
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlist (
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, book_id),
  CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARBINARY(16) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_date (created_at),
  INDEX idx_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('loan_days', '14'), ('max_active_loans', '3'), ('late_fine_per_day_iqd', '1000'), ('catalog_limit', '200')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO categories (slug, name_en, name_ckb) VALUES
 ('fiction','Fiction','ئەدەبیات'),
 ('mystery','Mystery','نهێنی'),
 ('fantasy','Fantasy','خەیاڵی'),
 ('science-fiction','Science Fiction','زانستی خەیاڵی'),
 ('romance','Romance','ڕۆمانسی'),
 ('history','History','مێژوو'),
 ('biography','Biography','ژیاننامە'),
 ('science','Science','زانست'),
 ('philosophy','Philosophy','فەلسەفە'),
 ('classics','Classics','کلاسیک')
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_ckb = VALUES(name_ckb);
