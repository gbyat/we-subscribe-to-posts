#!/usr/bin/env node

/**
 * Pre-deploy dependency hygiene checks for WordPress plugin projects.
 *
 * Usage:
 *   node scripts/preflight-deploy.js           # strict — fails on high audit findings
 *   node scripts/preflight-deploy.js --warn-only # log only (CI PRs)
 *
 * npm script:
 *   npm run preflight
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const rootDir = process.cwd();
const warnOnly = process.argv.includes('--warn-only');
const errors = [];
const warnings = [];

/** @param {'error'|'warn'} level */
function note(level, message) {
	const effective = warnOnly && 'error' === level ? 'warn' : level;

	if ('error' === effective) {
		errors.push(message);
		console.error(`ERROR: ${message}`);
		return;
	}
	warnings.push(message);
	console.warn(`WARN: ${message}`);
}

/**
 * @param {string} relative
 * @return {boolean}
 */
function exists(relative) {
	return fs.existsSync(path.join(rootDir, relative));
}

/**
 * @param {string} relative
 * @return {object|null}
 */
function readJson(relative) {
	const full = path.join(rootDir, relative);
	if (!fs.existsSync(full)) {
		return null;
	}
	try {
		return JSON.parse(fs.readFileSync(full, 'utf8'));
	} catch (error) {
		note('error', `Invalid JSON: ${relative}`);
		return null;
	}
}

function hasComposerPackages(composer) {
	if (!composer || typeof composer !== 'object') {
		return false;
	}
	const require = { ...(composer.require || {}), ...(composer['require-dev'] || {}) };
	delete require.php;
	delete require['ext-json'];
	delete require['ext-mbstring'];
	return Object.keys(require).length > 0;
}

function hasNpmPackages(pkg) {
	if (!pkg || typeof pkg !== 'object') {
		return false;
	}
	const deps = { ...(pkg.dependencies || {}), ...(pkg.devDependencies || {}) };
	return Object.keys(deps).length > 0;
}

function checkLockfiles() {
	const composer = readJson('composer.json');
	if (composer && hasComposerPackages(composer) && !exists('composer.lock')) {
		note('error', 'composer.lock is missing — run composer install and commit the lockfile.');
	}

	const pkg = readJson('package.json');
	if (pkg && hasNpmPackages(pkg) && !exists('package-lock.json')) {
		note('error', 'package-lock.json is missing — run npm install and commit the lockfile.');
	}
}

/**
 * @return {string|null}
 */
function findPhpExecutable() {
	if (process.env.PHP_BIN) {
		return process.env.PHP_BIN;
	}

	const probe = spawnSync('php', ['-v'], {
		encoding: 'utf8',
		shell: process.platform === 'win32',
	});

	if (probe.status === 0) {
		return 'php';
	}

	return null;
}

function composerAuditCommand() {
	const localPhar = path.join(rootDir, 'composer.phar');
	if (exists('composer.phar')) {
		const php = findPhpExecutable();
		if (!php) {
			return null;
		}

		return {
			command: php,
			args: [localPhar, 'audit', '--format=plain', '--locked'],
			shell: process.platform === 'win32',
		};
	}

	return {
		command: 'composer',
		args: ['audit', '--format=plain', '--locked'],
		shell: process.platform === 'win32',
	};
}

function runComposerAudit() {
	if (!exists('composer.lock')) {
		return;
	}

	const auditCommand = composerAuditCommand();
	if (!auditCommand) {
		note('warn', 'composer.phar found but PHP is not available — skipping composer audit.');
		return;
	}

	const audit = spawnSync(auditCommand.command, auditCommand.args, {
		cwd: rootDir,
		encoding: 'utf8',
		shell: Boolean(auditCommand.shell),
	});

	if (audit.status === 0) {
		console.log('composer audit: OK');
		return;
	}

	const combined = `${audit.stdout || ''}\n${audit.stderr || ''}`;
	const missingComposer =
		Boolean(audit.error) ||
		/nicht gefunden|not found|not recognized|ENOENT|command not found/i.test(combined);

	if (missingComposer && auditCommand.command === 'composer') {
		note('warn', 'composer is not available on PATH — skipping composer audit.');
		return;
	}

	if (audit.stdout) {
		process.stdout.write(audit.stdout);
	}
	if (audit.stderr) {
		process.stderr.write(audit.stderr);
	}

	note(warnOnly ? 'warn' : 'error', 'composer audit reported vulnerabilities.');
}

function runNpmAudit() {
	if (!exists('package.json') || !exists('package-lock.json')) {
		return;
	}

	const audit = spawnSync(
		'npm',
		['audit', '--audit-level=high', '--omit=dev'],
		{ cwd: rootDir, encoding: 'utf8', shell: process.platform === 'win32' }
	);

	if (audit.status === 0) {
		console.log('npm audit (high+, prod): OK');
		return;
	}

	if (audit.stdout) {
		process.stdout.write(audit.stdout);
	}

	note(warnOnly ? 'warn' : 'error', 'npm audit reported high or critical vulnerabilities in production dependencies.');
}

function checkZipInclude() {
	const configPath = path.join(rootDir, 'plugin.config.json');
	if (!fs.existsSync(configPath)) {
		return;
	}

	let config;
	try {
		config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
	} catch (error) {
		note('warn', 'plugin.config.json could not be parsed.');
		return;
	}

	const forbidden = new Set(['vendor', 'node_modules', '.git', '.github', '.cursor', '.env']);
	const dirs = config?.zipInclude?.directories;
	if (!Array.isArray(dirs)) {
		return;
	}

	dirs.forEach((dir) => {
		const name = String(dir).replace(/\\/g, '/').split('/').pop();
		if (forbidden.has(name) || forbidden.has(String(dir))) {
			note('error', `zipInclude must not ship "${dir}" — remove it from plugin.config.json directories.`);
		}
	});
}

function checkTrackedForbidden() {
	const git = spawnSync('git', ['rev-parse', '--is-inside-work-tree'], { cwd: rootDir, encoding: 'utf8' });
	if (git.status !== 0 || git.stdout.trim() !== 'true') {
		return;
	}

	['vendor', 'node_modules'].forEach((dir) => {
		const tracked = spawnSync('git', ['ls-files', `${dir}/`], { cwd: rootDir, encoding: 'utf8' });
		if (tracked.status === 0 && tracked.stdout.trim() !== '') {
			note('error', `${dir}/ is tracked in git — remove from the repository.`);
		}
	});
}

function checkEnvFiles() {
	['.env', '.env.local', 'credentials.json'].forEach((file) => {
		if (exists(file)) {
			note('warn', `${file} exists in project root — must not be deployed or committed.`);
		}
	});
}

function main() {
	console.log('Preflight deploy checks…');
	console.log(`Root: ${rootDir}`);
	console.log(warnOnly ? 'Mode: warn-only' : 'Mode: strict');

	checkLockfiles();
	checkZipInclude();
	checkTrackedForbidden();
	checkEnvFiles();
	runComposerAudit();
	runNpmAudit();

	console.log('');
	if (warnings.length) {
		console.log(`Warnings: ${warnings.length}`);
	}
	if (errors.length) {
		console.error(`Failed with ${errors.length} error(s).`);
		process.exit(1);
	}

	console.log('Preflight passed.');
}

main();
