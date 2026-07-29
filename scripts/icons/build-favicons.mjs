#!/usr/bin/env node
/**
 * Regenerate the site's browser icons from the committed ILAS mark.
 *
 * Background: /favicon.ico, /apple-touch-icon.png and /apple-touch-icon-precomposed.png
 * were the top three 404 paths on the whole Cloudflare zone (~1,370 per two days).
 * See docs/pantheon-cloudflare-preimplementation-validation.md §8.1.
 *
 * Source of truth is the committed SVG under the theme, NOT the copy in the public
 * files directory (which is untracked, so builds from it are not reproducible), and
 * NOT web/themes/custom/b5subtheme/favicon.ico (that file is byte-identical to
 * Bootstrap's own docs favicon — shipping it would put a Bootstrap "B" on every tab).
 *
 * Usage:
 *   node scripts/icons/build-favicons.mjs
 *
 * Requires sharp. It is normally present as an optional transitive dev dependency of
 * promptfoo, but that is not guaranteed after every `npm ci`. If it is missing:
 *   npm i --no-save sharp@0.34.5
 *
 * This is a maintenance tool run by hand. It is deliberately not wired into CI, and
 * sharp is deliberately not a declared dependency: it ships native binaries and the
 * generated icons are committed, so nothing in the build or test path needs it.
 */

import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

let sharp;
try {
  sharp = require('sharp');
}
catch {
  console.error(
    'sharp is not installed.\n' +
    'It is an optional transitive dev dependency and may be absent after `npm ci`.\n' +
    'Install it just for this run:\n\n  npm i --no-save sharp@0.34.5\n'
  );
  process.exit(1);
}

/** Brand blue — the SVG\'s own background rect fill. Used to flatten away alpha. */
const BRAND_BLUE = '#1263a0';

/** Render the vector once at high resolution, then downscale everything from it. */
const MASTER = 1024;

const SOURCE = path.join(repoRoot, 'web/themes/custom/b5subtheme/images/ILAS-favicon-source.svg');
const WEB = path.join(repoRoot, 'web');

const MANIFEST = {
  name: 'Idaho Legal Aid Services',
  short_name: 'Idaho Legal Aid',
  start_url: '/',
  scope: '/',
  // "browser" keeps home-screen launches in the normal browser, with the address
  // bar and back button intact. The site links out heavily (courts, forms), so an
  // app-like chrome-less window would strand people.
  display: 'browser',
  background_color: BRAND_BLUE,
  theme_color: BRAND_BLUE,
  lang: 'en',
  icons: [
    { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
    { src: '/icon-512.png', sizes: '512x512', type: 'image/png' },
  ],
};

/**
 * Pack raw BGRA pixels into a 32-bit BMP/DIB icon entry.
 *
 * ICO entries may hold either a PNG or a headerless bottom-up DIB. DIB is used here
 * because every consumer understands it, whereas PNG-in-ICO needs Vista or later.
 * The DIB header lies about its height (doubled) to account for the AND mask that
 * follows the colour data; the mask is all zeros since these icons are fully opaque.
 */
function dibEntry(rgba, size) {
  const header = Buffer.alloc(40);
  header.writeUInt32LE(40, 0);         // biSize
  header.writeInt32LE(size, 4);        // biWidth
  header.writeInt32LE(size * 2, 8);    // biHeight — colour rows + mask rows
  header.writeUInt16LE(1, 12);         // biPlanes
  header.writeUInt16LE(32, 14);        // biBitCount
  header.writeUInt32LE(0, 16);         // biCompression = BI_RGB

  // Colour data: BGRA, bottom-up.
  const colour = Buffer.alloc(size * size * 4);
  for (let y = 0; y < size; y++) {
    const src = (size - 1 - y) * size * 4;
    const dst = y * size * 4;
    for (let x = 0; x < size; x++) {
      colour[dst + x * 4] = rgba[src + x * 4 + 2];      // B
      colour[dst + x * 4 + 1] = rgba[src + x * 4 + 1];  // G
      colour[dst + x * 4 + 2] = rgba[src + x * 4];      // R
      colour[dst + x * 4 + 3] = rgba[src + x * 4 + 3];  // A
    }
  }

  // AND mask: 1 bit per pixel, rows padded to a 4-byte boundary. All zero = opaque.
  const maskStride = Math.ceil(size / 32) * 4;
  const mask = Buffer.alloc(maskStride * size);

  return Buffer.concat([header, colour, mask]);
}

/** Assemble a multi-resolution ICO from [{size, rgba}] entries. */
function buildIco(images) {
  const bodies = images.map((img) => dibEntry(img.rgba, img.size));

  const dir = Buffer.alloc(6);
  dir.writeUInt16LE(0, 0);                // reserved
  dir.writeUInt16LE(1, 2);                // type 1 = icon
  dir.writeUInt16LE(images.length, 4);    // image count

  const entries = [];
  let offset = 6 + images.length * 16;
  images.forEach((img, i) => {
    const e = Buffer.alloc(16);
    e.writeUInt8(img.size === 256 ? 0 : img.size, 0);   // width  (0 means 256)
    e.writeUInt8(img.size === 256 ? 0 : img.size, 1);   // height
    e.writeUInt8(0, 2);                                 // palette entries
    e.writeUInt8(0, 3);                                 // reserved
    e.writeUInt16LE(1, 4);                              // colour planes
    e.writeUInt16LE(32, 6);                             // bits per pixel
    e.writeUInt32LE(bodies[i].length, 8);               // byte size
    e.writeUInt32LE(offset, 12);                        // byte offset
    entries.push(e);
    offset += bodies[i].length;
  });

  return Buffer.concat([dir, ...entries, ...bodies]);
}

const written = [];

function write(relative, buffer) {
  const target = path.join(WEB, relative);
  fs.writeFileSync(target, buffer);
  written.push([relative, buffer.length, createHash('sha256').update(buffer).digest('hex')]);
}

async function main() {
  if (!fs.existsSync(SOURCE)) {
    console.error(`Source SVG not found: ${SOURCE}`);
    process.exit(1);
  }

  const svg = fs.readFileSync(SOURCE);

  // density is what tells librsvg how finely to rasterise the vector; without it the
  // SVG renders at its nominal 500px and upscaling to MASTER would soften every edge.
  const master = await sharp(svg, { density: 1400 })
    .resize(MASTER, MASTER, { fit: 'cover' })
    .flatten({ background: BRAND_BLUE })
    .png()
    .toBuffer();

  const png = async (size) => sharp(master)
    .resize(size, size, { kernel: 'lanczos3' })
    .flatten({ background: BRAND_BLUE })
    .removeAlpha()
    .png({ compressionLevel: 9 })
    .toBuffer();

  const raw = async (size) => sharp(master)
    .resize(size, size, { kernel: 'lanczos3' })
    .flatten({ background: BRAND_BLUE })
    .ensureAlpha()
    .raw()
    .toBuffer();

  // favicon.ico — 16, 32 and 48 in one file.
  const ico = buildIco(await Promise.all(
    [16, 32, 48].map(async (size) => ({ size, rgba: await raw(size) }))
  ));
  write('favicon.ico', ico);

  // Apple touch icons. iOS ignores alpha and composites onto black, so these are
  // already flattened; the -precomposed name is the legacy spelling and older iOS
  // requests it directly, so ship both rather than relying on a redirect.
  const touch = await png(180);
  write('apple-touch-icon.png', touch);
  write('apple-touch-icon-precomposed.png', touch);

  write('icon-192.png', await png(192));
  write('icon-512.png', await png(512));

  // manifest.json is a byte-identical twin of site.webmanifest: requests for that
  // exact path were recorded at the edge, and .json is guaranteed a correct MIME
  // type on Pantheon's nginx whereas .webmanifest may not be.
  const manifest = Buffer.from(`${JSON.stringify(MANIFEST, null, 2)}\n`, 'utf8');
  write('site.webmanifest', manifest);
  write('manifest.json', manifest);

  const pad = Math.max(...written.map(([name]) => name.length));
  console.log(`Generated from ${path.relative(repoRoot, SOURCE)}:\n`);
  for (const [name, size, hash] of written) {
    console.log(`  ${name.padEnd(pad)}  ${String(size).padStart(7)} B  sha256:${hash}`);
  }
}

await main();
