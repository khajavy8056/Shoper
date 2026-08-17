const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const version = process.env.SHOPER_VERSION || require('../package.json').version;
const root = path.resolve(__dirname, '..');
const out = path.join(root, 'dist');
fs.mkdirSync(out, { recursive: true });
const zip = path.join(out, `shoper-torob-importer-${version}.zip`);
try { fs.unlinkSync(zip); } catch (_) {}
execFileSync('zip', ['-qr', zip, 'shoper-torob-importer'], { cwd: root, stdio: 'inherit' });
console.log(`Built ${zip}`);
