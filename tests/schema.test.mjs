/**
 * Guards the contract itself.
 *
 * The MySQL file is what Part 2 deploys to cPanel; the SQLite file is what the
 * mock runs on. If those two ever disagree, every green contract test becomes a
 * lie, because the agents would be passing against a shape the real app does not
 * have. These tests make that drift impossible to miss.
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { DatabaseSync } from 'node:sqlite';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '..');
const mysqlSql = readFileSync(resolve(ROOT, 'contract/schema.mysql.sql'), 'utf8');
const sqliteSql = readFileSync(resolve(ROOT, 'contract/schema.sqlite.sql'), 'utf8');

/** Pulls {table: [column, ...]} out of raw CREATE TABLE text. */
function columnsOf(sql) {
  const out = {};
  const re = /CREATE TABLE (\w+)\s*\(([\s\S]*?)\n\)/g;
  let m;
  while ((m = re.exec(sql))) {
    const [, table, bodyRaw] = m;
    const body = bodyRaw.replace(/GENERATED ALWAYS AS \(([\s\S]*?)\) STORED/g, 'GENERATED');
    const cols = [];
    for (const line of body.split('\n')) {
      const t = line.trim();
      if (!t || t.startsWith('--')) continue;
      if (/^(PRIMARY KEY|UNIQUE|KEY|CONSTRAINT|FOREIGN KEY|CHECK)\b/i.test(t)) continue;
      const c = t.match(/^(\w+)\s+/);
      if (c) cols.push(c[1]);
    }
    out[table] = cols;
  }
  return out;
}

describe('the two schemas describe the same database', () => {
  const my = columnsOf(mysqlSql);
  const lite = columnsOf(sqliteSql);

  test('both define the same 14 tables', () => {
    assert.equal(Object.keys(my).length, 14);
    assert.deepEqual(Object.keys(my).sort(), Object.keys(lite).sort());
  });

  for (const table of Object.keys(columnsOf(mysqlSql))) {
    test(`${table} has identical columns in both`, () => {
      assert.deepEqual(lite[table], my[table],
        `column drift in ${table}: the mock and the cPanel database disagree`);
    });
  }

  test('the generated file is not stale', () => {
    // Fails loudly if someone edited the MySQL file and forgot to regenerate.
    execFileSync(process.execPath, ['contract/mysql-to-sqlite.mjs', '--check'], { cwd: ROOT });
  });
});

describe('what the owner typed cannot be overwritten', () => {
  test('original_* on topics is immutable at the database level', () => {
    const db = new DatabaseSync(':memory:');
    db.exec(sqliteSql);
    db.exec(`INSERT INTO agent_runs (run_uid, agent_code, business_date_ist) VALUES ('r1','A1','2026-09-16')`);
    db.exec(`INSERT INTO topic_batches (batch_uid, run_id, business_date_ist, title) VALUES ('b1',1,'2026-09-16','t')`);
    db.exec(`INSERT INTO topics (topic_uid, batch_id, created_by_run_id, position,
               original_title, original_one_liner, final_title, final_one_liner, dedupe_hash)
             VALUES ('t1',1,1,1,'Agent wrote this','one liner','Agent wrote this','one liner','h1')`);

    // The owner editing their own copy is allowed.
    db.exec(`UPDATE topics SET final_title='Owner edited this' WHERE topic_uid='t1'`);
    assert.equal(db.prepare(`SELECT final_title f FROM topics WHERE topic_uid='t1'`).get().f, 'Owner edited this');
    assert.equal(db.prepare(`SELECT original_title o FROM topics WHERE topic_uid='t1'`).get().o, 'Agent wrote this');

    // Rewriting the agent's original is refused outright.
    assert.throws(
      () => db.exec(`UPDATE topics SET original_title='Tampered' WHERE topic_uid='t1'`),
      /immutable/,
      'an agent must never be able to rewrite what it originally produced');

    // Same for the source, which is what every statistic traces back to.
    assert.throws(
      () => db.exec(`UPDATE topics SET source_quote='Fabricated quote' WHERE topic_uid='t1'`),
      /immutable/);
  });

  test('the database itself refuses a post over 100 words', () => {
    // MySQL enforces this with a CHECK constraint, so a drifting agent gets a
    // database error rather than a bad post.
    assert.match(mysqlSql, /CHECK \(final_word_count <= 100\)/);
    assert.match(mysqlSql, /CHECK \(original_word_count <= 100\)/);
  });

  test('a LinkedIn URN can only ever be recorded once', () => {
    assert.match(mysqlSql, /UNIQUE KEY uq_posts_linkedin_urn \(linkedin_urn\)/);
  });

  test('approval status and machine lifecycle are separate columns', () => {
    assert.match(mysqlSql, /review_status ENUM\('pending','approved','rejected','hold'\)/);
    assert.match(mysqlSql, /lifecycle_state ENUM\('draft','images_pending'/);
  });
});

describe('the OpenAPI spec matches the implementation', () => {
  const spec = readFileSync(resolve(ROOT, 'contract/openapi.yaml'), 'utf8');
  const server = readFileSync(resolve(ROOT, 'mock-api/server.mjs'), 'utf8');

  /** Route table entries in the mock, as OpenAPI-style paths. */
  const implemented = [...server.matchAll(/\['(GET|POST|PATCH)',\s*`\$\{AGENT\}([^`]*)`/g)]
    // Only a colon following a slash is a path parameter. The colon in
    // "metrics:bulk-upsert" is part of the route name itself.
    .map((m) => '/api/v1/agent' + m[2].replace(/\/:(\w+)/g, '/{$1}'));

  test('every implemented agent route is documented', () => {
    const undocumented = [...new Set(implemented)].filter((p) => !spec.includes(`\n  ${p}:`));
    assert.deepEqual(undocumented, [],
      'these routes exist in the mock but are missing from openapi.yaml, so Part 2 would not know to build them');
  });

  test('the spec has no duplicate path keys', () => {
    // A duplicate mapping key is invalid YAML and silently drops one definition.
    const paths = [...spec.matchAll(/^ {2}(\/\S*):$/gm)].map((m) => m[1]);
    const dupes = paths.filter((p, i) => paths.indexOf(p) !== i);
    assert.deepEqual(dupes, []);
  });

  test('the error envelope every agent branches on is specified', () => {
    assert.match(spec, /retryable:/);
    assert.match(spec, /Idempotency-Key/);
  });

  test('the spec names both the production host and the local mock', () => {
    assert.match(spec, /app\.gojobs\.biz/);
    assert.match(spec, /127\.0\.0\.1:8787/);
  });
});
