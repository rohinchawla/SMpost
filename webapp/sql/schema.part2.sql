-- =============================================================================
-- Part 2 additions: the people who log in.
--
-- Everything the agents use is in schema.mysql.sql, which is the frozen Part 1
-- contract and is shipped byte-identical. This file holds only what the web app
-- itself needs, so the contract and the application can be reviewed separately.
--
-- Portable across MySQL 8 and MariaDB 10.4+.
-- =============================================================================

CREATE TABLE users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  display_name    VARCHAR(120) NULL,
  role            ENUM('owner','editor','viewer') NOT NULL DEFAULT 'viewer',

  -- Unused today. The owner chose password-only login, and these exist so that
  -- turning on a second factor later is a settings change rather than a
  -- migration against a live database.
  totp_secret     VARBINARY(128) NULL,
  totp_enabled    TINYINT(1)   NOT NULL DEFAULT 0,

  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME(3)  NULL,
  password_changed_at DATETIME(3) NULL,
  last_login_at   DATETIME(3)  NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_active (is_active, role)
) ENGINE=InnoDB;

-- Sessions live in the database rather than in files. /tmp on shared hosting has
-- historically been readable across accounts, and a table gives "sign out
-- everywhere" and a session list for free.
CREATE TABLE ui_sessions (
  sid          CHAR(64) NOT NULL PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  payload      MEDIUMTEXT NOT NULL,
  ua_hash      CHAR(64) NULL,
  ip           VARBINARY(16) NULL,
  created_at   DATETIME(3) NOT NULL,
  last_seen_at DATETIME(3) NOT NULL,
  KEY ix_sessions_user (user_id),
  KEY ix_sessions_seen (last_seen_at)
) ENGINE=InnoDB;

-- Rate limiting, per IP and per account. Swept by Sweeper after 7 days.
CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip           VARBINARY(16) NOT NULL,
  email_hash   CHAR(64) NOT NULL,
  ok           TINYINT(1) NOT NULL,
  attempted_at DATETIME(3) NOT NULL,
  KEY ix_la_ip (ip, attempted_at),
  KEY ix_la_email (email_hash, attempted_at)
) ENGINE=InnoDB;
