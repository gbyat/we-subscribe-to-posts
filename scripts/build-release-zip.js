/**
 * Build the distributable plugin ZIP (same payload as the GitHub release workflow).
 *
 * Writes:
 *   releases/<slug>-<version>.zip   (versioned archive, kept locally)
 *   <slug>.zip                      (root copy for CI / softprops upload)
 *
 * Usage:
 *   node scripts/build-release-zip.js
 *   node scripts/build-release-zip.js --build
 *
 * npm scripts:
 *   npm run build        # block plugins: assets + ZIP; PHP plugins: ZIP only
 *   npm run build:assets # wp-scripts build only (block plugins)
 *   npm run zip          # package current tree without rebuilding assets
 *   npm run pack         # alias for npm run build
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const archiver = require('archiver');
const { loadConfig, rootDir } = require('./load-config');

const config = loadConfig();
const slug = String(config.slug);
const zipInclude = config.zipInclude || { files: [], directories: [] };
const stagingDir = path.join(rootDir, slug);
const releasesDir = path.join(rootDir, 'releases');
const shouldBuild = process.argv.includes('--build');

/**
 * @return {string}
 */
function readVersion() {
	try {
		const pkg = JSON.parse(fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8'));
		if (pkg.version) {
			return String(pkg.version);
		}
	} catch (error) {
		// fall through
	}
	return '0.0.0';
}

/**
 * @param {string} source
 * @param {string} target
 */
function copyIfExists(source, target) {
	const sourcePath = path.join(rootDir, source);
	if (!fs.existsSync(sourcePath)) {
		return;
	}

	fs.cpSync(sourcePath, target, { recursive: true });
}

/**
 * @return {void}
 */
function runAssetBuild() {
	const pkgPath = path.join(rootDir, 'package.json');
	let scripts = {};
	try {
		scripts = JSON.parse(fs.readFileSync(pkgPath, 'utf8')).scripts || {};
	} catch (error) {
		scripts = {};
	}

	if (!scripts['build:assets']) {
		if (config.hasBlocks) {
			console.log('hasBlocks=true but no build:assets script — skipping asset build.');
		}
		return;
	}

	console.log(
		config.hasBlocks
			? 'Building block assets (hasBlocks=true)…'
			: 'Building admin assets (build:assets)…'
	);

	const npmCmd = process.platform === 'win32' ? 'npm.cmd' : 'npm';

	const buildResult = spawnSync(npmCmd, ['run', 'build:assets'], {
		cwd: rootDir,
		stdio: 'inherit',
	});

	if (buildResult.error || buildResult.status !== 0) {
		console.error('Asset build failed. Fix build errors before creating the ZIP.');
		process.exit(buildResult.status || 1);
	}
}

/**
 * @param {string} zipPath
 * @return {Promise<void>}
 */
function createZipArchive(zipPath) {
	return new Promise((resolve, reject) => {
		const output = fs.createWriteStream(zipPath);
		const archive = archiver('zip', {
			zlib: { level: 9 },
		});

		output.on('close', resolve);
		output.on('error', reject);
		archive.on('error', reject);

		archive.pipe(output);
		archive.directory(stagingDir, slug);
		archive.finalize();
	});
}

async function main() {
	const preflight = spawnSync(process.execPath, [path.join(__dirname, 'preflight-deploy.js')], {
		cwd: rootDir,
		stdio: 'inherit',
	});

	if (preflight.status !== 0) {
		process.exit(preflight.status || 1);
	}

	if (shouldBuild) {
		runAssetBuild();
	}

	const version = readVersion();
	const versionedName = `${slug}-${version}.zip`;
	const versionedPath = path.join(releasesDir, versionedName);
	const rootZipName = `${slug}.zip`;
	const rootZipPath = path.join(rootDir, rootZipName);
	const latestAliasPath = path.join(releasesDir, rootZipName);

	if (fs.existsSync(stagingDir)) {
		fs.rmSync(stagingDir, { recursive: true, force: true });
	}
	fs.mkdirSync(stagingDir, { recursive: true });
	fs.mkdirSync(releasesDir, { recursive: true });

	try {
		(zipInclude.files || []).forEach((fileName) => {
			copyIfExists(fileName, path.join(stagingDir, fileName));
		});

		(zipInclude.directories || []).forEach((dirName) => {
			copyIfExists(dirName, path.join(stagingDir, dirName));
		});

		if (fs.existsSync(versionedPath)) {
			fs.unlinkSync(versionedPath);
		}

		await createZipArchive(versionedPath);

		fs.copyFileSync(versionedPath, rootZipPath);
		fs.copyFileSync(versionedPath, latestAliasPath);
	} finally {
		if (fs.existsSync(stagingDir)) {
			fs.rmSync(stagingDir, { recursive: true, force: true });
		}
	}

	console.log(`Release ZIP created: releases/${versionedName}`);
	console.log(`Also copied to: ${rootZipName} and releases/${rootZipName}`);
}

main().catch((error) => {
	console.error(error.message || error);
	process.exit(1);
});
