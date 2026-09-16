#!/usr/bin/env node
/**
 * Derives contract/schema.sqlite.sql from contract/schema.mysql.sql.
 *
 * The MySQL file is the single source of truth: it is what Part 2 deploys to
 * cPanel. The SQLite file exists only so the local mock API can run without a
 * MySQL install. Generating one from the other means the mock can never quietly
 * drift away from the contract the real app implements.
 *
 * Usage:  node contract/mysql-to-sqlite.mjs [--check]
 *         --check regenerates in memory and fails if the file on disk differs.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const SRC = join(here, 'schema.mysql.sql');
const OUT = join(here, 'schema.sqlite.sql');

const NOW = "(strftime('%Y-%m-%dT%H:%M:%fZ','now'))";

function mapType(line) {
  let out = line;
  // ENUM(...) becomes TEXT plus an inline CHECK, so the same values are rejected.
  out = out.replace(/\bENUM\(([^)]*)\)/gi, (_m, vals) => `TEXT__ENUM__${vals}__`);
  out = out.replace(/\b(?:BIGINT|INT|SMALLINT|TINYINT|MEDIUMINT)\s+UNSIGNED\b/gi, 'INTEGER');
  out = out.replace(/\bTINYINT\(1\)/gi, 'INTEGER');
  out = out.replace(/\b(?:BIGINT|SMALLINT|MEDIUMINT|TINYINT)\b/gi, 'INTEGER');
  out = out.replace(/\bINT\b(?!EGER)/gi, 'INTEGER');
  out = out.replace(/\bDATETIME\(\d\)/gi, 'TEXT');
  out = out.replace(/\bDECIMAL\(\d+,\s*\d+\)/gi, 'REAL');
  out = out.replace(/\bVARBINARY\(\d+\)/gi, 'BLOB');
  out = out.replace(/\b(?:VARCHAR|CHAR)\(\d+\)/gi, 'TEXT');
  out = out.replace(/\b(?:MEDIUMTEXT|LONGTEXT)\b/gi, 'TEXT');
  out = out.replace(/\bJSON\b/gi, 'TEXT');
  out = out.replace(/\bDATE\b(?!TIME|DIFF)/gi, 'TEXT');
  out = out.replace(/\bTIME\b(?!STAMP)/gi, 'TEXT');
  out = out.replace(/\bAUTO_INCREMENT\s+PRIMARY\s+KEY\b/gi, 'PRIMARY KEY AUTOINCREMENT');
  out = out.replace(/\s*ON\s+UPDATE\s+CURRENT_TIMESTAMP\(\d\)/gi, '');
  out = out.replace(/\bCURRENT_TIMESTAMP\(\d\)/gi, NOW);
  return out;
}

function expandEnum(line) {
  const m = line.match(/^(\s*)(\w+)\s+TEXT__ENUM__(.*?)__(.*)$/);
  if (!m) return line;
  const [, indent, col, vals, rest] = m;
  // rest still carries the trailing comma; the CHECK has to sit before it.
  const comma = /,s*$/.test(rest) ? ',' : '';
  const tail = rest.replace(/,s*$/, '');
  return `${indent}${col} TEXT${tail} CHECK (${col} IN (${vals}))${comma}`;
}

function convert(sql) {
  // Collapse multi-line generated-column expressions onto one line first.
  sql = sql.replace(/GENERATED ALWAYS AS \(([\s\S]*?)\) STORED/g,
    (_m, expr) => `GENERATED ALWAYS AS (${expr.replace(/\s+/g, ' ').trim()}) STORED`);

  const lines = sql.split(/\r?\n/);
  const out = [];
  const indexes = [];
  let inMysqlTrigger = false;
  let table = null;
  let body = [];

  const flush = () => {
    if (!table) return;
    // Strip the trailing comma from the final constraint line.
    for (let i = body.length - 1; i >= 0; i--) {
      if (body[i].trim()) { body[i] = body[i].replace(/,\s*$/, ''); break; }
    }
    out.push(`CREATE TABLE ${table} (`);
    out.push(...body);
    out.push(');');
    table = null; body = [];
  };

  for (let raw of lines) {
    const t = raw.trim();

    if (/^SET\s+(SESSION|NAMES)/i.test(t)) continue;
    if (/^DELIMITER/i.test(t)) continue;

    // The MySQL trigger body is procedural SQL that SQLite cannot parse.
    // Drop the whole block; an equivalent SQLite trigger is appended at the end.
    if (inMysqlTrigger) {
      if (t.toUpperCase().startsWith('END$')) inMysqlTrigger = false;
      continue;
    }
    if (t.toUpperCase().startsWith('CREATE TRIGGER')) { inMysqlTrigger = true; continue; }

    const create = t.match(/^CREATE TABLE (\w+)\s*\($/i);
    if (create) { flush(); table = create[1]; body = []; continue; }

    if (table) {
      if (/^\)\s*ENGINE=InnoDB;/i.test(t)) { flush(); continue; }

      // KEY name (cols) -> a standalone CREATE INDEX after the table.
      const key = t.match(/^KEY\s+(\w+)\s*\(([^)]*)\)\s*,?$/i);
      if (key) { indexes.push(`CREATE INDEX ${key[1]} ON ${table} (${key[2]});`); continue; }

      // UNIQUE KEY name (cols) -> an inline UNIQUE table constraint.
      const uk = t.match(/^UNIQUE KEY\s+\w+\s*\(([^)]*)\)\s*(,?)$/i);
      if (uk) { body.push(`  UNIQUE (${uk[1]})${uk[2]}`); continue; }

      body.push(expandEnum(mapType(raw)));
      continue;
    }

    // Outside a CREATE TABLE block.
    if (/^ALTER TABLE/i.test(t)) {
      out.push('-- SQLite cannot ALTER TABLE ADD CONSTRAINT. posts.selected_image_id -> post_images(id)');
      out.push('-- is enforced by the mock API in application code instead.');
      // Skip until the statement terminator.
      continue;
    }
    if (/^FOREIGN KEY \(selected_image_id\)/i.test(t)) continue;
    if (/^ADD CONSTRAINT fk_posts_selected_image/i.test(t)) continue;

    if (/^CREATE OR REPLACE VIEW/i.test(t)) {
      out.push(raw.replace(/CREATE OR REPLACE VIEW/i, 'CREATE VIEW'));
      continue;
    }
    if (/DATEDIFF\(/i.test(t)) {
      out.push(raw.replace(/DATEDIFF\(([^,]+),\s*([^)]+)\)/i,
        'CAST(julianday($1) - julianday($2) AS INTEGER)'));
      continue;
    }
    if (/JSON_QUOTE\(|CAST\(.*AS JSON\)/i.test(t)) {
      out.push(raw
        .replace(/JSON_QUOTE\('([^']*)'\)/gi, "'\"$1\"'")
        .replace(/CAST\((\d+)\s+AS JSON\)/gi, "'$1'"));
      continue;
    }
    out.push(raw);
  }
  flush();

  // The MySQL trigger that makes original_* immutable, restated for SQLite.
  const trigger = `
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
`;

  const header = `-- =============================================================================
-- GENERATED FILE - DO NOT EDIT BY HAND.
-- Produced from schema.mysql.sql by contract/mysql-to-sqlite.mjs
-- Edit the MySQL file and re-run the generator.
--
-- This exists only so the local mock API can run without a MySQL install.
-- MySQL remains the contract; this is its faithful translation.
-- =============================================================================
PRAGMA foreign_keys = ON;
`;

  return [header, out.join('\n'), '', '-- Indexes', ...indexes, trigger].join('\n') + '\n';
}

const generated = convert(readFileSync(SRC, 'utf8'));

if (process.argv.includes('--check')) {
  let onDisk = '';
  try { onDisk = readFileSync(OUT, 'utf8'); } catch { /* not generated yet */ }
  if (onDisk !== generated) {
    console.error('schema.sqlite.sql is stale. Run: node contract/mysql-to-sqlite.mjs');
    process.exit(1);
  }
  console.log('schema.sqlite.sql is up to date with schema.mysql.sql');
} else {
  writeFileSync(OUT, generated);
  console.log(`Wrote ${OUT} (${generated.split('\n').length} lines)`);
}
