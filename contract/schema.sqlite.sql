-- =============================================================================
-- GENERATED FILE - DO NOT EDIT BY HAND.
-- Produced from schema.mysql.sql by contract/mysql-to-sqlite.mjs
-- Edit the MySQL file and re-run the generator.
--
-- This exists only so the local mock API can run without a MySQL install.
-- MySQL remains the contract; this is its faithful translation.
-- =============================================================================
PRAGMA foreign_keys = ON;

-- =============================================================================
-- Golden Opportunities - LinkedIn posting pipeline
-- MySQL 8.0 schema. Production DDL for the cPanel host (Part 2).
-- The agents (Part 1) are written against exactly this shape.
--
-- Import on cPanel: phpMyAdmin > select your database > Import > this file.
-- Do NOT run CREATE DATABASE on shared hosting; cPanel creates it for you and
-- usually prefixes the name (e.g. gojobs_linkedin).
-- =============================================================================


-- api_keys : one key per agent, so A5 (the only agent that can write to
-- LinkedIn) can be revoked without stopping the other five.
CREATE TABLE api_keys (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  agent_code TEXT NOT NULL CHECK (agent_code IN ('A1','A2','A3','A4','A5','A6','UI','OPS')),
  label        TEXT  NOT NULL,
  key_prefix   TEXT     NOT NULL,
  key_hash     TEXT     NOT NULL,
  scopes       TEXT         NOT NULL,
  is_active    INTEGER   NOT NULL DEFAULT 1,
  last_used_at TEXT  NULL,
  created_at   TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  revoked_at   TEXT  NULL,
  UNIQUE (key_prefix)
);

-- agent_runs : the job log. Every run writes a row, including no-ops, because
-- silence must be detectable. uq_runs_slot stops a double-fired cron.
CREATE TABLE agent_runs (
  id                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  run_uid           TEXT     NOT NULL,
  agent_code TEXT NOT NULL CHECK (agent_code IN ('A1','A2','A3','A4','A5','A6')),
  agent_version     TEXT  NOT NULL DEFAULT 'v1',
  trigger_type TEXT NOT NULL DEFAULT 'schedule' CHECK (trigger_type IN ('schedule','manual','chain','retry')),
  parent_run_id     INTEGER NULL,
  business_date_ist TEXT         NOT NULL,
  attempt           INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','succeeded','partial','failed','skipped','timed_out')),
  skip_reason       TEXT  NULL,
  dry_run           INTEGER   NOT NULL DEFAULT 0,
  items_in          INTEGER NOT NULL DEFAULT 0,
  items_ok          INTEGER NOT NULL DEFAULT 0,
  items_failed      INTEGER NOT NULL DEFAULT 0,
  items_skipped     INTEGER NOT NULL DEFAULT 0,
  started_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  heartbeat_at      TEXT  NULL,
  finished_at       TEXT  NULL,
  duration_ms       INTEGER NULL,
  error_code        TEXT  NULL,
  error_message     TEXT         NULL,
  metrics_json      TEXT         NULL,
  UNIQUE (run_uid),
  UNIQUE (agent_code, business_date_ist, attempt),
  CONSTRAINT fk_runs_parent FOREIGN KEY (parent_run_id) REFERENCES agent_runs(id)
);

CREATE TABLE run_events (
  id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  run_id      INTEGER NOT NULL,
  seq         INTEGER    NOT NULL,
  level TEXT NOT NULL DEFAULT 'info' CHECK (level IN ('debug','info','warn','error')),
  event_code  TEXT     NOT NULL,
  entity_type TEXT NOT NULL DEFAULT 'none' CHECK (entity_type IN ('batch','topic','post','image','metric','none')),
  entity_uid  TEXT        NULL,
  message     TEXT    NULL,
  data_json   TEXT            NULL,
  created_at  TEXT     NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  UNIQUE (run_id, seq),
  CONSTRAINT fk_run_events_run FOREIGN KEY (run_id) REFERENCES agent_runs(id) ON DELETE CASCADE
);

-- topic_batches : what the owner sits down to review. state='open' means A1 is
-- still uploading, so the UI hides it until it is submitted.
CREATE TABLE topic_batches (
  id                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  batch_uid         TEXT     NOT NULL,
  run_id            INTEGER NOT NULL,
  business_date_ist TEXT         NOT NULL,
  title             TEXT NOT NULL,
  sources_json      TEXT         NULL,
  topic_count       INTEGER NOT NULL DEFAULT 0,
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','submitted','closed')),
  submitted_at      TEXT  NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  UNIQUE (batch_uid),
  UNIQUE (run_id),
  CONSTRAINT fk_batches_run FOREIGN KEY (run_id) REFERENCES agent_runs(id)
);

-- topics
--
-- original_* is written once by A1 and is immutable thereafter (see the trigger
-- below). final_* is written only by the owner through the web page. This is the
-- literal implementation of "must not change any information I have given it":
-- a re-run can never overwrite an owner edit.
CREATE TABLE topics (
  id                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  topic_uid         TEXT     NOT NULL,
  batch_id          INTEGER NOT NULL,
  created_by_run_id INTEGER NOT NULL,
  position          INTEGER NOT NULL,

  original_title      TEXT  NOT NULL,
  original_one_liner  TEXT  NOT NULL,
  original_cta_flag   INTEGER    NOT NULL DEFAULT 0,
  original_cta_type TEXT NOT NULL DEFAULT 'none' CHECK (original_cta_type IN ('none','website','email')),
  original_cta_text   TEXT  NULL,
  original_cta_target TEXT  NULL,
  source_url          TEXT NULL,
  source_domain       TEXT  NULL,
  source_title        TEXT  NULL,
  source_published_on TEXT          NULL,
  source_type TEXT NOT NULL DEFAULT 'blog' CHECK (source_type IN ('blog','news','whitepaper','google_search','report','other')),
  source_quote        TEXT NULL,
  post_type           TEXT   NOT NULL DEFAULT 'data_point',
  theme_tag           TEXT   NOT NULL DEFAULT 'workforce_planning',
  india_relevance     INTEGER NOT NULL DEFAULT 1,

  final_title      TEXT NOT NULL,
  final_one_liner  TEXT NOT NULL,
  final_cta_flag   INTEGER   NOT NULL DEFAULT 0,
  final_cta_type TEXT NOT NULL DEFAULT 'none' CHECK (final_cta_type IN ('none','website','email')),
  final_cta_text   TEXT NULL,
  final_cta_target TEXT NULL,

  was_edited INTEGER GENERATED ALWAYS AS ((final_title <> original_title) OR (final_one_liner <> original_one_liner)) STORED,

  review_status TEXT NOT NULL DEFAULT 'pending' CHECK (review_status IN ('pending','approved','rejected','hold')),
  reviewed_at   TEXT  NULL,
  reviewed_by   TEXT NULL,
  review_note   TEXT NULL,

  pipeline_state TEXT NOT NULL DEFAULT 'new' CHECK (pipeline_state IN ('new','awaiting_copy','copy_in_progress','copy_done','discarded')),
  claimed_by_run_id INTEGER NULL,
  claim_expires_at  TEXT NULL,

  dedupe_hash TEXT NOT NULL,
  priority    INTEGER NOT NULL DEFAULT 100,

  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

  UNIQUE (topic_uid),
  UNIQUE (batch_id, dedupe_hash),
  UNIQUE (batch_id, position),
  CONSTRAINT fk_topics_batch FOREIGN KEY (batch_id) REFERENCES topic_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_topics_run   FOREIGN KEY (created_by_run_id) REFERENCES agent_runs(id),
  CONSTRAINT chk_topics_relevance CHECK (india_relevance BETWEEN 0 AND 2)
);


-- posts
--
-- review_status and lifecycle_state are deliberately separate columns.
-- review_status is the owner's verdict and is re-settable at will.
-- lifecycle_state records irreversible machine facts. If they were one column,
-- a dropdown could "un-post" something already live on LinkedIn, and a publish
-- failure would have nowhere to live.
CREATE TABLE posts (
  id       INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  post_uid TEXT        NOT NULL,
  topic_id INTEGER NOT NULL,
  revision INTEGER NOT NULL DEFAULT 1,

  copy_run_id    INTEGER NOT NULL,
  image_run_id   INTEGER NULL,
  submit_run_id  INTEGER NULL,
  publish_run_id INTEGER NULL,

  original_hook       TEXT NOT NULL,
  original_body       TEXT         NOT NULL,
  original_cta_text   TEXT NULL,
  original_cta_target TEXT NULL,
  original_hashtags   TEXT         NOT NULL,
  original_keywords   TEXT         NOT NULL,
  original_word_count INTEGER NOT NULL,
  first_comment_text  TEXT NULL,
  numbers_used        TEXT         NULL,
  image_brief         TEXT         NULL,
  model_name          TEXT  NULL,
  generation_notes    TEXT         NULL,

  final_hook       TEXT NOT NULL,
  final_body       TEXT         NOT NULL,
  final_cta_text   TEXT NULL,
  final_cta_target TEXT NULL,
  final_hashtags   TEXT         NOT NULL,
  final_keywords   TEXT         NOT NULL,
  final_word_count INTEGER NOT NULL,
  selected_image_id INTEGER NULL,

  was_edited INTEGER GENERATED ALWAYS AS ((final_body <> original_body) OR (final_hook <> original_hook)) STORED,

  review_status TEXT NOT NULL DEFAULT 'pending' CHECK (review_status IN ('pending','approved','rejected','hold')),
  reviewed_at   TEXT  NULL,
  reviewed_by   TEXT NULL,
  review_note   TEXT NULL,
  approved_content_hash TEXT NULL,

  lifecycle_state TEXT NOT NULL DEFAULT 'draft' CHECK (lifecycle_state IN ('draft','images_pending','images_ready','in_review','ready','scheduled','publishing','posted','failed','expired','archived')),
  degraded_flags  TEXT NULL,

  scheduled_date_ist       TEXT        NULL,
  scheduled_slot_time      TEXT        NOT NULL DEFAULT '08:00:00',
  expires_at_ist           TEXT        NULL,
  publish_lease_token      TEXT    NULL,
  publish_lease_expires_at TEXT NULL,
  publish_attempts         INTEGER NOT NULL DEFAULT 0,
  posted_at_utc            TEXT NULL,
  posted_date_ist          TEXT        NULL,
  linkedin_urn             TEXT NULL,
  linkedin_permalink       TEXT NULL,
  first_comment_urn        TEXT NULL,
  last_error_code          TEXT  NULL,
  last_error_message       TEXT         NULL,
  metrics_watch_until_ist  TEXT         NULL,

  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

  UNIQUE (post_uid),
  UNIQUE (topic_id, revision),
  UNIQUE (linkedin_urn),
  CONSTRAINT fk_posts_topic FOREIGN KEY (topic_id) REFERENCES topics(id),
  CONSTRAINT chk_posts_wordcount CHECK (final_word_count <= 100),
  CONSTRAINT chk_posts_orig_wordcount CHECK (original_word_count <= 100)
);

-- post_images : filesystem + URL, never BLOB. Higgsfield URLs expire, so the app
-- takes its own copy the moment A3 uploads. sha256 catches a truncated download
-- at the boundary instead of as a grey box in the preview a week later.
CREATE TABLE post_images (
  id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  image_uid    TEXT        NOT NULL,
  post_id      INTEGER NOT NULL,
  option_index INTEGER NOT NULL,
  generated_by_run_id INTEGER NOT NULL,

  provider TEXT NOT NULL DEFAULT 'higgsfield' CHECK (provider IN ('higgsfield','manual_upload','template_fallback')),
  provider_model  TEXT  NULL,
  provider_job_id TEXT NULL,
  prompt_text     TEXT         NOT NULL,
  negative_prompt TEXT         NULL,
  seed            INTEGER       NULL,
  aspect_ratio    TEXT  NULL,
  source_url            TEXT NULL,
  source_url_fetched_at TEXT   NULL,
  storage_path TEXT NOT NULL,
  public_url   TEXT NOT NULL,
  thumb_path   TEXT NULL,
  mime_type    TEXT  NOT NULL,
  width_px     INTEGER NULL,
  height_px    INTEGER NULL,
  bytes        INTEGER NOT NULL,
  sha256       TEXT     NOT NULL,
  alt_text     TEXT NULL,
  concept_label TEXT NULL,
  ocr_text     TEXT NULL,
  status TEXT NOT NULL DEFAULT 'stored' CHECK (status IN ('stored','missing','quarantined')),
  verified_at  TEXT  NULL,
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  UNIQUE (image_uid),
  UNIQUE (post_id, option_index),
  CONSTRAINT fk_images_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT chk_images_option CHECK (option_index BETWEEN 1 AND 2)
);

-- SQLite cannot ALTER TABLE ADD CONSTRAINT. posts.selected_image_id -> post_images(id)
-- is enforced by the mock API in application code instead.

-- Audit trails. Append-only: never updated, never deleted.
CREATE TABLE review_actions (
  id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL CHECK (entity_type IN ('topic','post')),
  entity_uid  TEXT NOT NULL,
  from_status TEXT NULL CHECK (from_status IN ('pending','approved','rejected','hold')),
  to_status TEXT NOT NULL CHECK (to_status IN ('pending','approved','rejected','hold')),
  actor_type TEXT NOT NULL DEFAULT 'human' CHECK (actor_type IN ('human','agent','system')),
  actor_name  TEXT NOT NULL,
  note        TEXT NULL,
  ip_address  BLOB NULL,
  acted_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE field_edits (
  id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL CHECK (entity_type IN ('topic','post','image')),
  entity_uid  TEXT     NOT NULL,
  field_name  TEXT  NOT NULL,
  old_value   TEXT   NULL,
  new_value   TEXT   NULL,
  actor_type TEXT NOT NULL CHECK (actor_type IN ('human','agent','system')),
  actor_name  TEXT NOT NULL,
  edited_at   TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE TABLE publish_attempts (
  id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  post_id          INTEGER NOT NULL,
  run_id           INTEGER NOT NULL,
  attempt_no       INTEGER NOT NULL,
  idempotency_key  TEXT NOT NULL,
  outcome TEXT NOT NULL CHECK (outcome IN ('success','retryable_error','permanent_error','skipped','dry_run')),
  http_status      INTEGER NULL,
  linkedin_urn     TEXT NULL,
  error_code       TEXT  NULL,
  error_message    TEXT         NULL,
  request_snapshot TEXT         NULL,
  attempted_at     TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  UNIQUE (idempotency_key),
  UNIQUE (post_id, attempt_no),
  CONSTRAINT fk_publish_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_publish_run  FOREIGN KEY (run_id)  REFERENCES agent_runs(id)
);

-- post_metrics_daily : one row per post per day. The composite PK IS the
-- identity, so re-running A6 on the same day updates rather than duplicates, and
-- all rows for one post are physically contiguous for the trend chart.
-- A metric the API did not return is stored NULL, never 0: a zero would read as
-- "the post died" rather than "we could not look", and would corrupt every
-- day-over-day delta after it.
CREATE TABLE post_metrics_daily (
  post_id             INTEGER NOT NULL,
  metric_date_ist     TEXT            NOT NULL,
  impressions         INTEGER NULL,
  unique_impressions  INTEGER NULL,
  shares              INTEGER NULL,
  reactions           INTEGER NULL,
  comments            INTEGER NULL,
  clicks              INTEGER NULL,
  engagement_rate     REAL NULL,
  delta_impressions   INTEGER NULL,
  delta_shares        INTEGER NULL,
  age_days            INTEGER NULL,
  source TEXT NOT NULL DEFAULT 'linkedin_api' CHECK (source IN ('linkedin_api','manual_csv','dry_run')),
  raw_field_map       TEXT NULL,
  api_version         TEXT NULL,
  gap_reason          TEXT NULL,
  collected_by_run_id INTEGER NOT NULL,
  collected_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  revision            INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (post_id, metric_date_ist),
  CONSTRAINT fk_metrics_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE dead_letters (
  id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  dl_uid        TEXT NOT NULL,
  agent_code TEXT NOT NULL CHECK (agent_code IN ('A1','A2','A3','A4','A5','A6')),
  run_id        INTEGER NULL,
  entity_type TEXT NOT NULL DEFAULT 'none' CHECK (entity_type IN ('batch','topic','post','image','metric','none')),
  entity_uid    TEXT NULL,
  stage         TEXT  NOT NULL,
  error_code    TEXT  NOT NULL,
  error_message TEXT         NOT NULL,
  payload_json  TEXT         NOT NULL,
  attempts      INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','retrying','resolved','ignored')),
  first_seen_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  last_seen_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  resolved_at   TEXT  NULL,
  resolved_by   TEXT NULL,
  resolution_note TEXT NULL,
  UNIQUE (dl_uid),
  UNIQUE (agent_code, stage, error_code, entity_uid)
);

CREATE TABLE idempotency_keys (
  id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  key_hash        TEXT  NOT NULL,
  agent_code      TEXT NOT NULL,
  method          TEXT NOT NULL,
  path            TEXT NOT NULL,
  request_hash    TEXT  NOT NULL,
  response_status INTEGER  NOT NULL,
  response_body   TEXT      NOT NULL,
  created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  expires_at      TEXT NOT NULL,
  UNIQUE (key_hash)
);

CREATE TABLE app_settings (
  setting_key   TEXT NOT NULL PRIMARY KEY,
  setting_value TEXT        NOT NULL,
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_by    TEXT NULL
);

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

CREATE VIEW v_post_performance AS
SELECT p.id AS post_id, p.post_uid, t.final_title AS topic_title,
       p.posted_date_ist, p.linkedin_permalink,
       m.metric_date_ist, m.impressions, m.shares, m.reactions,
       m.delta_impressions, m.delta_shares,
       CAST(julianday(m.metric_date_ist) - julianday(p.posted_date_ist) AS INTEGER) AS age_days
FROM posts p
JOIN topics t ON t.id = p.topic_id
JOIN post_metrics_daily m ON m.post_id = p.id
WHERE p.lifecycle_state = 'posted';


-- Indexes
CREATE INDEX ix_api_keys_agent ON api_keys (agent_code, is_active);
CREATE INDEX ix_runs_status ON agent_runs (status, started_at);
CREATE INDEX ix_runs_agent_date ON agent_runs (agent_code, business_date_ist);
CREATE INDEX ix_run_events_entity ON run_events (entity_type, entity_uid);
CREATE INDEX ix_batches_state ON topic_batches (state, business_date_ist);
CREATE INDEX ix_topics_queue ON topics (review_status, pipeline_state, priority, id);
CREATE INDEX ix_topics_dedupe_recent ON topics (dedupe_hash, created_at);
CREATE INDEX ix_topics_batch_review ON topics (batch_id, review_status);
CREATE INDEX ix_posts_publish_queue ON posts (review_status, lifecycle_state, scheduled_date_ist, id);
CREATE INDEX ix_posts_review_table ON posts (review_status, lifecycle_state, updated_at);
CREATE INDEX ix_posts_metrics_window ON posts (lifecycle_state, metrics_watch_until_ist);
CREATE INDEX ix_posted_date ON posts (posted_date_ist);
CREATE INDEX ix_images_post ON post_images (post_id);
CREATE INDEX ix_review_actions_entity ON review_actions (entity_type, entity_uid, acted_at);
CREATE INDEX ix_field_edits_entity ON field_edits (entity_type, entity_uid, edited_at);
CREATE INDEX ix_publish_post ON publish_attempts (post_id, attempted_at);
CREATE INDEX ix_metrics_date ON post_metrics_daily (metric_date_ist);
CREATE INDEX ix_metrics_run ON post_metrics_daily (collected_by_run_id);
CREATE INDEX ix_dl_status ON dead_letters (status, agent_code, last_seen_at);
CREATE INDEX ix_idem_expiry ON idempotency_keys (expires_at);

-- The MySQL build enforces this with a BEFORE UPDATE trigger; same rule here, so
-- the mock rejects exactly what production rejects.
CREATE TRIGGER trg_topics_protect_original BEFORE UPDATE ON topics
FOR EACH ROW WHEN
     NEW.original_title     IS NOT OLD.original_title
  OR NEW.original_one_liner IS NOT OLD.original_one_liner
  OR NEW.source_url         IS NOT OLD.source_url
  OR NEW.source_quote       IS NOT OLD.source_quote
BEGIN
  SELECT RAISE(ABORT, 'original_* columns on topics are immutable');
END;

