const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const releaseType = args.find((arg) => !arg.startsWith('--')) || 'patch';
const localOnly = args.includes('--local') || args.includes('--no-push');
const allowedTypes = ['patch', 'minor', 'major'];
const rootDir = path.join(__dirname, '..');
const npmCommand = 'npm';

function shouldUseShell(command) {
	return process.platform === 'win32' && command === npmCommand;
}

function escapeShellArg(value) {
	const safe = /^[a-zA-Z0-9_./:=+-]+$/;
	if (safe.test(value)) {
		return value;
	}
	return `"${value.replace(/"/g, '\\"')}"`;
}

function runCommand(command, argsList, stdio, encoding) {
	if (shouldUseShell(command)) {
		const fullCommand = [command, ...argsList].map(escapeShellArg).join(' ');
		return spawnSync(fullCommand, [], {
			cwd: rootDir,
			stdio,
			encoding,
			shell: true,
		});
	}

	return spawnSync(command, argsList, {
		cwd: rootDir,
		stdio,
		encoding,
		shell: false,
	});
}

if (!allowedTypes.includes(releaseType)) {
	console.error('Invalid release type. Use patch, minor, or major.');
	console.error('Optional flags: --local (or --no-push) to tag without pushing to origin.');
	process.exit(1);
}

function run(command, argsList, options = {}) {
	const result = runCommand(command, argsList, options.stdio || 'inherit', options.encoding);

	if (result.error) {
		throw result.error;
	}
	if (result.status !== 0) {
		throw new Error(`${command} ${argsList.join(' ')} failed with exit code ${result.status}`);
	}
}

function runQuiet(command, argsList) {
	const result = runCommand(command, argsList, ['ignore', 'pipe', 'ignore'], 'utf8');
	if (result.error || result.status !== 0) {
		return '';
	}
	return (result.stdout || '').trim();
}

function runOptional(command, argsList, label) {
	try {
		run(command, argsList);
		console.log(`${label} completed.`);
	} catch (error) {
		console.warn(`${label} failed (non-blocking): ${error instanceof Error ? error.message : String(error)}`);
	}
}

function hasNpmScript(name) {
	try {
		const pkg = JSON.parse(fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8'));
		return Boolean(pkg.scripts && pkg.scripts[name]);
	} catch (error) {
		return false;
	}
}

try {
	const config = JSON.parse(fs.readFileSync(path.join(rootDir, 'plugin.config.json'), 'utf8'));
	const { draftUnreleasedFromCommits } = require('./promote-changelog');

	const gitRepoCheck = runQuiet('git', ['rev-parse', '--is-inside-work-tree']);
	if ('true' !== gitRepoCheck) {
		console.error('Current directory is not a git repository. Initialize git first.');
		process.exit(1);
	}

	const remoteUrl = runQuiet('git', ['remote', 'get-url', 'origin']);
	if (!localOnly && !remoteUrl) {
		console.error('No git remote named "origin" found. Add remote before releasing, or use --local.');
		process.exit(1);
	}

	// Draft / validate changelog BEFORE bumping version (avoids orphan bumps on failure).
	const draft = draftUnreleasedFromCommits(false);
	if (draft.drafted) {
		console.log(
			`CHANGELOG.md: auto-drafted ${draft.count} note(s) from commit messages. Review before the release commit.`
		);
	}

	const previousVersion = JSON.parse(
		fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8')
	).version;

	run(npmCommand, ['version', releaseType, '--no-git-tag-version']);
	run('node', ['scripts/sync-version.js']);

	const version = JSON.parse(fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8')).version;
	run('node', ['scripts/promote-changelog.js', version, previousVersion]);

	if (config.hasBlocks && hasNpmScript('build:assets')) {
		runOptional(npmCommand, ['run', 'build:assets'], 'Asset build');
	}

	runOptional(npmCommand, ['run', 'pot'], 'POT generation');
	runOptional(npmCommand, ['run', 'json'], 'JSON translation generation');

	const tag = `v${version}`;

	const existingTag = runQuiet('git', ['tag', '-l', tag]);
	if (existingTag === tag) {
		console.error(`Tag ${tag} already exists. Bump again or delete the tag manually.`);
		process.exit(1);
	}

	run('git', ['add', '-A']);
	run('git', ['commit', '-m', `Release ${tag}`]);
	run('git', ['tag', '-a', tag, '-m', `Release ${tag}`]);

	run(npmCommand, ['run', 'zip']);

	if (localOnly) {
		console.log(`Release ${tag} created locally (no push).`);
		console.log(`ZIP: releases/${config.slug}-${version}.zip`);
		console.log('Push later with: git push origin HEAD && git push origin ' + tag);
	} else {
		const branch = runQuiet('git', ['rev-parse', '--abbrev-ref', 'HEAD']) || 'main';
		run('git', ['push', 'origin', branch]);
		run('git', ['push', 'origin', tag]);

		console.log(`Release ${tag} created and pushed.`);
		console.log('GitHub Actions will build the ZIP and publish the release.');
		console.log(`Local ZIP also kept at: releases/${config.slug}-${version}.zip`);
	}
} catch (error) {
	console.error(`Release failed: ${error instanceof Error ? error.message : String(error)}`);
	process.exit(1);
}
