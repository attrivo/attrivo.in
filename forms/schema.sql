-- Attrivo website forms — MySQL schema
-- cPanel → phpMyAdmin → select your database → SQL tab → paste → Go
-- (or: mysql -u USER -p DBNAME < schema.sql)

CREATE TABLE IF NOT EXISTS submissions (
  id            VARCHAR(40)  NOT NULL PRIMARY KEY,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  form_kind     VARCHAR(20)  NOT NULL,
  page          VARCHAR(160) NULL,
  section       VARCHAR(120) NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'new',
  first_name    VARCHAR(120) NULL,
  last_name     VARCHAR(120) NULL,
  mobile        VARCHAR(40)  NULL,
  email         VARCHAR(200) NULL,
  company       VARCHAR(200) NULL,
  message       TEXT         NULL,
  plan          VARCHAR(120) NULL,
  industry      VARCHAR(120) NULL,
  role          VARCHAR(160) NULL,
  linkedin      VARCHAR(300) NULL,
  resume_name   VARCHAR(255) NULL,
  resume_stored VARCHAR(255) NULL,
  resume_url    VARCHAR(500) NULL,
  history       TEXT         NULL,
  ip            VARCHAR(64)  NULL,
  INDEX idx_kind (form_kind),
  INDEX idx_status (status),
  INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id            BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  submission_id VARCHAR(40)  NOT NULL,
  created_at    DATETIME     NOT NULL,
  direction     ENUM('in','out') NOT NULL,
  subject       VARCHAR(300) NULL,
  body          MEDIUMTEXT   NULL,
  sender        VARCHAR(120) NULL,
  INDEX idx_submission (submission_id),
  CONSTRAINT fk_messages_submission
    FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
