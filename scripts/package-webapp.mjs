/**
 * Builds the folder that actually gets uploaded.
 *
 * `webapp/` is a working directory: running the suite against real PHP leaves a
 * config file with a database password in it, an installed.lock that makes the
 * installer refuse to run, six test images and an error log. Uploading that
 * folder would hand the host a dead installer and a stale password, which is
 * the single most likely way this deployment goes wrong.
 *
 *   node scripts/package-webapp.mjs
 *
 * Writes dist/gojobs-webapp/, prints what it left behind and why, and refuses
 * to finish if anything on the deny list survived the copy.
 */
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { zip } from './zip.mjs';

const SRC  = 'webapp';
const DEST = join('dist', 'gojobs-webapp');

/** Path prefixes, relative to webapp/, that never ship - and the reason. */
const EXCLUDE = [
  ['config/config.php',      'written by the installer on the host; this copy holds a local database password'],
  ['config/installed.lock',  'its presence makes install.php refuse to run at all'],
  ['storage/logs',           'local PHP error log'],
  ['storage/media',          'test images; the host generates its own'],
  ['app/Harness',            'the /__mock/ test routes. Gated on env=test and a token, but a file that is not there cannot be misconfigured'],
];

const norm = (p) => p.split(sep).join('/');
const isExcluded = (rel) =>
  EXCLUDE.some(([p]) => rel === p || rel.startsWith(p + '/'));

if (!existsSync(SRC)) {
  console.error(`no ${SRC}/ here - run this from the project root`);
  process.exit(1);
}

rmSync(DEST, { recursive: true, force: true });
mkdirSync(DEST, { recursive: true });

let files = 0;
let bytes = 0;

cpSync(SRC, DEST, {
  recursive: true,
  filter(src) {
    const rel = norm(relative(SRC, src));
    if (rel === '') return true;
    if (isExcluded(rel)) return false;
    if (statSync(src).isFile()) { files += 1; bytes += statSync(src).size; }
    return true;
  },
});

// storage/ has to exist on the host, writable and empty. The installer creates
// the dated subfolders, but it cannot create a directory the upload never made.
for (const dir of ['storage/logs', 'storage/media/images']) {
  mkdirSync(join(DEST, dir), { recursive: true });
}

// Belt and braces: walk the result and fail loudly if anything on the deny list
// made it through. A packaging script that silently ships a password is worse
// than no packaging script.
const leaked = [];
(function walk(dir) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, e.name);
    const rel = norm(relative(DEST, full));
    if (e.isDirectory()) { walk(full); continue; }
    if (isExcluded(rel)) leaked.push(rel);
  }
})(DEST);

if (leaked.length > 0) {
  console.error('REFUSING: these should never have been copied:');
  for (const f of leaked) console.error('  ' + f);
  process.exit(1);
}

// The archive, with forward-slash entry names, so cPanel's extractor puts
// app/Core/ where it belongs rather than creating a folder called 'app\Core'.
const ZIP = join('dist', 'gojobs-webapp.zip');
const entries = [];
(function collect(dir) {
  const kids = readdirSync(dir, { withFileTypes: true }).sort((x, y) => x.name < y.name ? -1 : 1);
  if (kids.length === 0 && dir !== DEST) {
    entries.push({ name: norm(relative(DEST, dir)) + '/', path: dir });
    return;
  }
  for (const e of kids) {
    const full = join(dir, e.name);
    if (e.isDirectory()) { collect(full); continue; }
    entries.push({ name: norm(relative(DEST, full)), path: full });
  }
})(DEST);
writeFileSync(ZIP, zip(entries));

console.log(`${DEST}/  ${files} files, ${(bytes / 1024).toFixed(0)} KB`);
console.log(`${ZIP}  ${entries.length} entries, ${(statSync(ZIP).size / 1024).toFixed(0)} KB`);
console.log('');
console.log('Left behind on purpose:');
for (const [p, why] of EXCLUDE) console.log(`  ${p.padEnd(24)} ${why}`);
console.log('');
console.log('Upload the CONTENTS of that folder, then point the document root at');
console.log('its public/ subfolder and open /install.php. docs/DEPLOY.md has the');
console.log('cPanel steps in order.');
