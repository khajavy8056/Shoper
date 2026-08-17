#!/usr/bin/env node
/**
 * ساخت آرشیو ZIP قابل‌نصب افزونه‌ی Shoper.
 *
 * خروجی: build/shoper-torob-importer-1.2.0.zip
 * محتوا: پوشه‌ی shoper-torob-importer/ در ریشه (تا در wp-content/plugins ریخته شود)
 *
 * اجرا:  npm run build
 */
'use strict';

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const VERSION = process.env.SHOPER_VERSION || '1.2.0';
const ROOT = path.resolve(__dirname, '..');
const PLUGIN_DIR = path.join(ROOT, 'shoper-torob-importer');
const BUILD_DIR = path.join(ROOT, 'build');
const OUT = path.join(BUILD_DIR, `shoper-torob-importer-${VERSION}.zip`);

if (!fs.existsSync(PLUGIN_DIR)) {
	console.error(`پوشه‌ی افزونه یافت نشد: ${PLUGIN_DIR}`);
	process.exit(1);
}

fs.mkdirSync(BUILD_DIR, { recursive: true });

// حذف ZIP قبلی.
if (fs.existsSync(OUT)) fs.unlinkSync(OUT);

console.log('📦 ساخت آرشیو افزونه…');

// از داخل پوشه‌ی والدِ shoper-torob-importer اجرا می‌شود تا پوشه در ریشه‌ی ZIP باشد.
execSync(
	`cd "${ROOT}" && zip -r -q "${OUT}" "shoper-torob-importer" ` +
		`-x "shoper-torob-importer/assets/mock/index.html"`,
	{ stdio: 'inherit' }
);

const sizeKb = Math.round(fs.statSync(OUT).size / 1024);
console.log(`✅ ساخته شد: ${path.relative(ROOT, OUT)} (${sizeKb} KB)`);
