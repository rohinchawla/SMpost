-- =============================================================================
-- Golden Opportunities - LinkedIn posting pipeline
-- MySQL 8.0 schema. Production DDL for the cPanel host (Part 2).
-- The agents (Part 1) are written against exactly this shape.
--
-- Import on cPanel: phpMyAdmin > select your database > Import > this file.
-- Do NOT run CREATE DATABASE on shared hosting; cPanel creates it for you and
-- usually prefixes the name (e.g. gojobs_linkedin).
-- =============================================================================

SET SESSION time_zone = '+00:00';
SET NAMES utf8mb4;

-- api_keys : one key per agent, so A5 (the only agent that can write to
-- LinkedIn) can be revoked without stopping the other five.
CREATE TABLE api_keys (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_code   ENUM('A1','A2','A3','A4','A5','A6','UI','OPS') NOT NULL,
  label        VARCHAR(80)  NOT NULL,
  key_prefix   CHAR(12)     NOT NULL,
  key_hash     CHAR(64)     NOT NULL,
  scopes       JSON         NOT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  last_used_at DATETIME(3)  NULL,
  created_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revoked_at   DATETIME(3)  NULL,
  UNIQUE KEY uq_api_keys_prefix (key_prefix),
  KEY ix_api_keys_agent (agent_code, is_active)
) ENGINE=InnoDB;

-- agent_runs : the job log. Every run writes a row, including no-ops, because
-- silence must be detectable. uq_runs_slot stops a double-fired cron.
CREATE TABLE agent_runs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_uid           CHAR(36)     NOT NULL,
  agent_code        ENUM('A1','A2','A3','A4','A5','A6') NOT NULL,
  agent_version     VARCHAR(20)  NOT NULL DEFAULT 'v1',
  trigger_type      ENUM('schedule','manual','chain','retry') NOT NULL DEFAULT 'schedule',
  parent_run_id     BIGINT UNSIGNED NULL,
  business_date_ist DATE         NOT NULL,
  attempt           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status            ENUM('running','succeeded','partial','failed','skipped','timed_out') NOT NULL DEFAULT 'running',
  skip_reason       VARCHAR(64)  NULL,
  dry_run           TINYINT(1)   NOT NULL DEFAULT 0,
  items_in          INT UNSIGNED NOT NULL DEFAULT 0,
  items_ok          INT UNSIGNED NOT NULL DEFAULT 0,
  items_failed      INT UNSIGNED NOT NULL DEFAULT 0,
  items_skipped     INT UNSIGNED NOT NULL DEFAULT 0,
  started_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  heartbeat_at      DATETIME(3)  NULL,
  finished_at       DATETIME(3)  NULL,
  duration_ms       INT UNSIGNED NULL,
  error_code        VARCHAR(64)  NULL,
  error_message     TEXT         NULL,
  metrics_json      JSON         NULL,
  UNIQUE KEY uq_runs_uid (run_uid),
  UNIQUE KEY uq_runs_slot (agent_code, business_date_ist, attempt),
  KEY ix_runs_status (status, started_at),
  KEY ix_runs_agent_date (agent_code, business_date_ist),
  CONSTRAINT fk_runs_parent FOREIGN KEY (parent_run_id) REFERENCES agent_runs(id)
) ENGINE=InnoDB;

CREATE TABLE run_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id      BIGINT UNSIGNED NOT NULL,
  seq         INT UNSIGNED    NOT NULL,
  level       ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  event_code  VARCHAR(64)     NOT NULL,
  entity_type ENUM('batch','topic','post','image','metric','none') NOT NULL DEFAULT 'none',
  entity_uid  CHAR(36)        NULL,
  message     VARCHAR(500)    NULL,
  data_json   JSON            NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_run_events_seq (run_id, seq),
  KEY ix_run_events_entity (entity_type, entity_uid),
  CONSTRAINT fk_run_events_run FOREIGN KEY (run_id) REFERENCES agent_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- topic_batches : what the owner sits down to review. state='open' means A1 is
-- still uploading, so the UI hides it until it is submitted.
CREATE TABLE topic_batches (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_uid         CHAR(36)     NOT NULL,
  run_id            BIGINT UNSIGNED NOT NULL,
  business_date_ist DATE         NOT NULL,
  title             VARCHAR(160) NOT NULL,
  sources_json      JSON         NULL,
  topic_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  state             ENUM('open','submitted','closed') NOT NULL DEFAULT 'open',
  submitted_at      DATETIME(3)  NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_batches_uid (batch_uid),
  UNIQUE KEY uq_batches_run (run_id),
  KEY ix_batches_state (state, business_date_ist),
  CONSTRAINT fk_batches_run FOREIGN KEY (run_id) REFERENCES agent_runs(id)
) ENGINE=InnoDB;

-- topics
--
-- original_* is written once by A1 and is immutable thereafter (see the trigger
-- below). final_* is written only by the owner through the web page. This is the
-- literal implementation of "must not change any information I have given it":
-- a re-run can never overwrite an owner edit.
CREATE TABLE topics (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  topic_uid         CHAR(36)     NOT NULL,
  batch_id          BIGINT UNSIGNED NOT NULL,
  created_by_run_id BIGINT UNSIGNED NOT NULL,
  position          SMALLINT UNSIGNED NOT NULL,

  original_title      VARCHAR(300)  NOT NULL,
  original_one_liner  VARCHAR(500)  NOT NULL,
  original_cta_flag   TINYINT(1)    NOT NULL DEFAULT 0,
  original_cta_type   ENUM('none','website','email') NOT NULL DEFAULT 'none',
  original_cta_text   VARCHAR(300)  NULL,
  original_cta_target VARCHAR(500)  NULL,
  source_url          VARCHAR(1000) NULL,
  source_domain       VARCHAR(190)  NULL,
  source_title        VARCHAR(400)  NULL,
  source_published_on DATE          NULL,
  source_type         ENUM('blog','news','whitepaper','google_search','report','other') NOT NULL DEFAULT 'blog',
  source_quote        VARCHAR(1000) NULL,
  post_type           VARCHAR(40)   NOT NULL DEFAULT 'data_point',
  theme_tag           VARCHAR(40)   NOT NULL DEFAULT 'workforce_planning',
  india_relevance     TINYINT UNSIGNED NOT NULL DEFAULT 1,

  final_title      VARCHAR(300) NOT NULL,
  final_one_liner  VARCHAR(500) NOT NULL,
  final_cta_flag   TINYINT(1)   NOT NULL DEFAULT 0,
  final_cta_type   ENUM('none','website','email') NOT NULL DEFAULT 'none',
  final_cta_text   VARCHAR(300) NULL,
  final_cta_target VARCHAR(500) NULL,

  was_edited TINYINT(1) GENERATED ALWAYS AS (
      (final_title <> original_title) OR (final_one_liner <> original_one_liner)
  ) STORED,

  review_status ENUM('pending','approved','rejected','hold') NOT NULL DEFAULT 'pending',
  reviewed_at   DATETIME(3)  NULL,
  reviewed_by   VARCHAR(120) NULL,
  review_note   VARCHAR(500) NULL,

  pipeline_state ENUM('new','awaiting_copy','copy_in_progress','copy_done','discarded') NOT NULL DEFAULT 'new',
  claimed_by_run_id BIGINT UNSIGNED NULL,
  claim_expires_at  DATETIME(3) NULL,

  dedupe_hash CHAR(64) NOT NULL,
  priority    TINYINT UNSIGNED NOT NULL DEFAULT 100,

  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

  UNIQUE KEY uq_topics_uid (topic_uid),
  UNIQUE KEY uq_topics_batch_dedupe (batch_id, dedupe_hash),
  UNIQUE KEY uq_topics_batch_pos (batch_id, position),
  KEY ix_topics_queue (review_status, pipeline_state, priority, id),
  KEY ix_topics_dedupe_recent (dedupe_hash, created_at),
  KEY ix_topics_batch_review (batch_id, review_status),
  CONSTRAINT fk_topics_batch FOREIGN KEY (batch_id) REFERENCES topic_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_topics_run   FOREIGN KEY (created_by_run_id) REFERENCES agent_runs(id),
  CONSTRAINT chk_topics_relevance CHECK (india_relevance BETWEEN 0 AND 2)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_topics_protect_original BEFORE UPDATE ON topics
FOR EACH ROW
BEGIN
  IF NEW.original_title <> OLD.original_title
     OR NEW.original_one_liner <> OLD.original_one_liner
     OR NOT (NEW.source_url <=> OLD.source_url)
     OR NOT (NEW.source_quote <=> OLD.source_quote) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'original_* columns on topics are immutable';
  END IF;
END$$
DELIMITER ;

-- posts
--
-- review_status and lifecycle_state are deliberately separate columns.
-- review_status is the owner's verdict and is re-settable at will.
-- lifecycle_state records irreversible machine facts. If they were one column,
-- a dropdown could "un-post" something already live on LinkedIn, and a publish
-- failure would have nowhere to live.
CREATE TABLE posts (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_uid CHAR(36)        NOT NULL,
  topic_id BIGINT UNSIGNED NOT NULL,
  revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  copy_run_id    BIGINT UNSIGNED NOT NULL,
  image_run_id   BIGINT UNSIGNED NULL,
  submit_run_id  BIGINT UNSIGNED NULL,
  publish_run_id BIGINT UNSIGNED NULL,

  original_hook       VARCHAR(300) NOT NULL,
  original_body       TEXT         NOT NULL,
  original_cta_text   VARCHAR(300) NULL,
  original_cta_target VARCHAR(500) NULL,
  original_hashtags   JSON         NOT NULL,
  original_keywords   JSON         NOT NULL,
  original_word_count SMALLINT UNSIGNED NOT NULL,
  first_comment_text  VARCHAR(500) NULL,
  numbers_used        JSON         NULL,
  image_brief         JSON         NULL,
  model_name          VARCHAR(60)  NULL,
  generation_notes    JSON         NULL,

  final_hook       VARCHAR(300) NOT NULL,
  final_body       TEXT         NOT NULL,
  final_cta_text   VARCHAR(300) NULL,
  final_cta_target VARCHAR(500) NULL,
  final_hashtags   JSON         NOT NULL,
  final_keywords   JSON         NOT NULL,
  final_word_count SMALLINT UNSIGNED NOT NULL,
  selected_image_id BIGINT UNSIGNED NULL,

  was_edited TINYINT(1) GENERATED ALWAYS AS (
      (final_body <> original_body) OR (final_hook <> original_hook)
  ) STORED,

  review_status ENUM('pending','approved','rejected','hold') NOT NULL DEFAULT 'pending',
  reviewed_at   DATETIME(3)  NULL,
  reviewed_by   VARCHAR(120) NULL,
  review_note   VARCHAR(500) NULL,
  approved_content_hash CHAR(64) NULL,

  lifecycle_state ENUM('draft','images_pending','images_ready','in_review','ready','scheduled','publishing','posted','failed','expired','archived') NOT NULL DEFAULT 'draft',
  degraded_flags  JSON NULL,

  scheduled_date_ist       DATE        NULL,
  scheduled_slot_time      TIME        NOT NULL DEFAULT '08:00:00',
  expires_at_ist           DATE        NULL,
  publish_lease_token      CHAR(36)    NULL,
  publish_lease_expires_at DATETIME(3) NULL,
  publish_attempts         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  posted_at_utc            DATETIME(3) NULL,
  posted_date_ist          DATE        NULL,
  linkedin_urn             VARCHAR(120) NULL,
  linkedin_permalink       VARCHAR(500) NULL,
  first_comment_urn        VARCHAR(120) NULL,
  last_error_code          VARCHAR(64)  NULL,
  last_error_message       TEXT         NULL,
  metrics_watch_until_ist  DATE         NULL,

  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

  UNIQUE KEY uq_posts_uid (post_uid),
  UNIQUE KEY uq_posts_topic_rev (topic_id, revision),
  UNIQUE KEY uq_posts_linkedin_urn (linkedin_urn),
  KEY ix_posts_publish_queue (review_status, lifecycle_state, scheduled_date_ist, id),
  KEY ix_posts_review_table (review_status, lifecycle_state, updated_at),
  KEY ix_posts_metrics_window (lifecycle_state, metrics_watch_until_ist),
  KEY ix_posted_date (posted_date_ist),
  CONSTRAINT fk_posts_topic FOREIGN KEY (topic_id) REFERENCES topics(id),
  CONSTRAINT chk_posts_wordcount CHECK (final_word_count <= 100),
  CONSTRAINT chk_posts_orig_wordcount CHECK (original_word_count <= 100)
) ENGINE=InnoDB;

-- post_images : filesystem + URL, never BLOB. Higgsfield URLs expire, so the app
-- takes its own copy the moment A3 uploads. sha256 catches a truncated download
-- at the boundary instead of as a grey box in the preview a week later.
CREATE TABLE post_images (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  image_uid    CHAR(36)        NOT NULL,
  post_id      BIGINT UNSIGNED NOT NULL,
  option_index TINYINT UNSIGNED NOT NULL,
  generated_by_run_id BIGINT UNSIGNED NOT NULL,

  provider        ENUM('higgsfield','manual_upload','template_fallback') NOT NULL DEFAULT 'higgsfield',
  provider_model  VARCHAR(60)  NULL,
  provider_job_id VARCHAR(120) NULL,
  prompt_text     TEXT         NOT NULL,
  negative_prompt TEXT         NULL,
  seed            BIGINT       NULL,
  aspect_ratio    VARCHAR(12)  NULL,
  source_url            VARCHAR(1000) NULL,
  source_url_fetched_at DATETIME(3)   NULL,
  storage_path VARCHAR(500) NOT NULL,
  public_url   VARCHAR(500) NOT NULL,
  thumb_path   VARCHAR(500) NULL,
  mime_type    VARCHAR(40)  NOT NULL,
  width_px     SMALLINT UNSIGNED NULL,
  height_px    SMALLINT UNSIGNED NULL,
  bytes        INT UNSIGNED NOT NULL,
  sha256       CHAR(64)     NOT NULL,
  alt_text     VARCHAR(300) NULL,
  concept_label VARCHAR(120) NULL,
  ocr_text     VARCHAR(500) NULL,
  status       ENUM('stored','missing','quarantined') NOT NULL DEFAULT 'stored',
  verified_at  DATETIME(3)  NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_images_uid (image_uid),
  UNIQUE KEY uq_images_post_option (post_id, option_index),
  KEY ix_images_post (post_id),
  CONSTRAINT fk_images_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT chk_images_option CHECK (option_index BETWEEN 1 AND 2)
) ENGINE=InnoDB;

ALTER TABLE posts
  ADD CONSTRAINT fk_posts_selected_image
  FOREIGN KEY (selected_image_id) REFERENCES post_images(id) ON DELETE SET NULL;

-- Audit trails. Append-only: never updated, never deleted.
CREATE TABLE review_actions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('topic','post') NOT NULL,
  entity_uid  CHAR(36) NOT NULL,
  from_status ENUM('pending','approved','rejected','hold') NULL,
  to_status   ENUM('pending','approved','rejected','hold') NOT NULL,
  actor_type  ENUM('human','agent','system') NOT NULL DEFAULT 'human',
  actor_name  VARCHAR(120) NOT NULL,
  note        VARCHAR(500) NULL,
  ip_address  VARBINARY(16) NULL,
  acted_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_review_actions_entity (entity_type, entity_uid, acted_at)
) ENGINE=InnoDB;

CREATE TABLE field_edits (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('topic','post','image') NOT NULL,
  entity_uid  CHAR(36)     NOT NULL,
  field_name  VARCHAR(64)  NOT NULL,
  old_value   MEDIUMTEXT   NULL,
  new_value   MEDIUMTEXT   NULL,
  actor_type  ENUM('human','agent','system') NOT NULL,
  actor_name  VARCHAR(120) NOT NULL,
  edited_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_field_edits_entity (entity_type, entity_uid, edited_at)
) ENGINE=InnoDB;

CREATE TABLE publish_attempts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_id          BIGINT UNSIGNED NOT NULL,
  run_id           BIGINT UNSIGNED NOT NULL,
  attempt_no       SMALLINT UNSIGNED NOT NULL,
  idempotency_key  CHAR(64) NOT NULL,
  outcome          ENUM('success','retryable_error','permanent_error','skipped','dry_run') NOT NULL,
  http_status      SMALLINT NULL,
  linkedin_urn     VARCHAR(120) NULL,
  error_code       VARCHAR(64)  NULL,
  error_message    TEXT         NULL,
  request_snapshot JSON         NULL,
  attempted_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_publish_idem (idempotency_key),
  UNIQUE KEY uq_publish_post_attempt (post_id, attempt_no),
  KEY ix_publish_post (post_id, attempted_at),
  CONSTRAINT fk_publish_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_publish_run  FOREIGN KEY (run_id)  REFERENCES agent_runs(id)
) ENGINE=InnoDB;

-- post_metrics_daily : one row per post per day. The composite PK IS the
-- identity, so re-running A6 on the same day updates rather than duplicates, and
-- all rows for one post are physically contiguous for the trend chart.
-- A metric the API did not return is stored NULL, never 0: a zero would read as
-- "the post died" rather than "we could not look", and would corrupt every
-- day-over-day delta after it.
CREATE TABLE post_metrics_daily (
  post_id             BIGINT UNSIGNED NOT NULL,
  metric_date_ist     DATE            NOT NULL,
  impressions         INT UNSIGNED NULL,
  unique_impressions  INT UNSIGNED NULL,
  shares              INT UNSIGNED NULL,
  reactions           INT UNSIGNED NULL,
  comments            INT UNSIGNED NULL,
  clicks              INT UNSIGNED NULL,
  engagement_rate     DECIMAL(6,4) NULL,
  delta_impressions   INT NULL,
  delta_shares        INT NULL,
  age_days            SMALLINT UNSIGNED NULL,
  source              ENUM('linkedin_api','manual_csv','dry_run') NOT NULL DEFAULT 'linkedin_api',
  raw_field_map       JSON NULL,
  api_version         VARCHAR(20) NULL,
  gap_reason          VARCHAR(120) NULL,
  collected_by_run_id BIGINT UNSIGNED NOT NULL,
  collected_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revision            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (post_id, metric_date_ist),
  KEY ix_metrics_date (metric_date_ist),
  KEY ix_metrics_run (collected_by_run_id),
  CONSTRAINT fk_metrics_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dead_letters (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dl_uid        CHAR(36) NOT NULL,
  agent_code    ENUM('A1','A2','A3','A4','A5','A6') NOT NULL,
  run_id        BIGINT UNSIGNED NULL,
  entity_type   ENUM('batch','topic','post','image','metric','none') NOT NULL DEFAULT 'none',
  entity_uid    CHAR(36) NULL,
  stage         VARCHAR(64)  NOT NULL,
  error_code    VARCHAR(64)  NOT NULL,
  error_message TEXT         NOT NULL,
  payload_json  JSON         NOT NULL,
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status        ENUM('open','retrying','resolved','ignored') NOT NULL DEFAULT 'open',
  first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  resolved_at   DATETIME(3)  NULL,
  resolved_by   VARCHAR(120) NULL,
  resolution_note VARCHAR(500) NULL,
  UNIQUE KEY uq_dl_uid (dl_uid),
  UNIQUE KEY uq_dl_dedupe (agent_code, stage, error_code, entity_uid),
  KEY ix_dl_status (status, agent_code, last_seen_at)
) ENGINE=InnoDB;

CREATE TABLE idempotency_keys (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash        CHAR(64)  NOT NULL,
  agent_code      VARCHAR(8) NOT NULL,
  method          VARCHAR(8) NOT NULL,
  path            VARCHAR(255) NOT NULL,
  request_hash    CHAR(64)  NOT NULL,
  response_status SMALLINT  NOT NULL,
  response_body   JSON      NOT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at      DATETIME(3) NOT NULL,
  UNIQUE KEY uq_idem_key (key_hash),
  KEY ix_idem_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE app_settings (
  setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value JSON        NOT NULL,
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by    VARCHAR(120) NULL
) ENGINE=InnoDB;

-- Literal JSON text, not JSON_QUOTE()/CAST(... AS JSON): MariaDB has no
-- CAST(... AS JSON) and most cPanel hosts run MariaDB rather than MySQL.
INSERT INTO app_settings (setting_key, setting_value) VALUES
 ('timezone',               '"Asia/Kolkata"'),
 ('max_body_words',         '100'),
 ('topics_per_batch_min',   '30'),
 ('topics_per_batch_max',   '60'),
 ('approved_queue_ceiling', '21'),
 ('images_per_post',        '2'),
 ('default_post_time_ist',  '"08:00:00"'),
 ('metrics_window_days',    '183'),
 ('package_ttl_days',       '45'),
 ('company_website',        '"https://www.GOjobs.biz"'),
 ('company_email',          '"rc@gojobs.biz"'),
 ('linkedin_org_urn',       '"urn:li:organization:REPLACE_WITH_NUMERIC_ORG_ID"');

CREATE OR REPLACE VIEW v_post_performance AS
SELECT p.id AS post_id, p.post_uid, t.final_title AS topic_title,
       p.posted_date_ist, p.linkedin_permalink,
       m.metric_date_ist, m.impressions, m.shares, m.reactions,
       m.delta_impressions, m.delta_shares,
       DATEDIFF(m.metric_date_ist, p.posted_date_ist) AS age_days
FROM posts p
JOIN topics t ON t.id = p.topic_id
JOIN post_metrics_daily m ON m.post_id = p.id
WHERE p.lifecycle_state = 'posted';
