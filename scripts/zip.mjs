/**
 * A ZIP writer with forward slashes in the entry names.
 *
 * Windows PowerShell's Compress-Archive writes backslash separators, which the
 * spec says must be forward slashes. Most tools cope; cPanel's extractor is not
 * a tool I can test from here, and a deployment that unpacks into a folder
 * literally called "app\Core" is a bad first hour. Forty lines of zlib avoids
 * finding out the hard way. No dependencies, deliberately - same rule as the
 * rest of this project.
 */
import { deflateRawSync } from 'node:zlib';
import { readFileSync, statSync } from 'node:fs';

const TABLE = (() => {
  const t = new Int32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[i] = c;
  }
  return t;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

/** DOS time and date, which is what a zip records. */
function dosStamp(d) {
  const year = Math.max(1980, d.getFullYear());
  return {
    time: (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1),
    date: ((year - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(),
  };
}

/**
 * @param {{name: string, path: string}[]} entries  name is the path inside the
 *   archive, always with forward slashes.
 * @returns {Buffer}
 */
export function zip(entries) {
  const locals = [];
  const central = [];
  let offset = 0;

  for (const { name, path } of entries) {
    // A name ending in / is a directory entry. storage/logs and
    // storage/media/images are empty in the upload and have to arrive anyway:
    // the installer writes into them, and it cannot create a folder that the
    // extraction never made.
    const isDir = name.endsWith('/');
    const raw = isDir ? Buffer.alloc(0) : readFileSync(path);
    const stamp = dosStamp(isDir ? new Date() : statSync(path).mtime);
    const crc = crc32(raw);
    const deflated = isDir ? raw : deflateRawSync(raw, { level: 9 });
    // A file that grows when compressed is stored instead. Rare, but the
    // one-pixel PNGs in this tree are exactly the shape that does it.
    const stored = isDir || deflated.length >= raw.length;
    const body = stored ? raw : deflated;
    const method = stored ? 0 : 8;
    const nameBuf = Buffer.from(name, 'utf8');

    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0);
    lh.writeUInt16LE(20, 4);
    lh.writeUInt16LE(0x0800, 6);           // UTF-8 names
    lh.writeUInt16LE(method, 8);
    lh.writeUInt16LE(stamp.time, 10);
    lh.writeUInt16LE(stamp.date, 12);
    lh.writeUInt32LE(crc, 14);
    lh.writeUInt32LE(body.length, 18);
    lh.writeUInt32LE(raw.length, 22);
    lh.writeUInt16LE(nameBuf.length, 26);
    locals.push(lh, nameBuf, body);

    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0);
    ch.writeUInt16LE(0x031e, 4);           // made by unix, so the mode is read
    ch.writeUInt16LE(20, 6);
    ch.writeUInt16LE(0x0800, 8);
    ch.writeUInt16LE(method, 10);
    ch.writeUInt16LE(stamp.time, 12);
    ch.writeUInt16LE(stamp.date, 14);
    ch.writeUInt32LE(crc, 16);
    ch.writeUInt32LE(body.length, 20);
    ch.writeUInt32LE(raw.length, 24);
    ch.writeUInt16LE(nameBuf.length, 28);
    ch.writeUInt32LE(isDir ? ((0o755 << 16) | 0x10) : (0o644 << 16), 38);
    ch.writeUInt32LE(offset, 42);
    central.push(ch, nameBuf);

    offset += lh.length + nameBuf.length + body.length;
  }

  const cd = Buffer.concat(central);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(cd.length, 12);
  end.writeUInt32LE(offset, 16);

  return Buffer.concat([...locals, cd, end]);
}
