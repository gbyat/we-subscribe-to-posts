const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const releaseType = process.argv[2] || 'patch';
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

function runCommand(command, args, stdio, encoding) {
	if (shouldUseShell(command)) {
		const fullCommand = [command, ...args].map(escapeShellArg).join(' ');
		return spawnSync(fullCommand, [], {
			cwd: rootDir,
			stdio,
			encoding,
			shell: true,
		});
	}

	return spawnSync(command, args, {
		cwd: rootDir,
		stdio,
		encoding,
		shell: false,
	});
}

if (!allowedTypes.includes(releaseType)) {
	console.error('Invalid release type. Use patch, minor, or major.');
	process.exit(1);
}

function run(command, args, options = {}) {
	const result = runCommand(command, args, options.stdio || 'inherit', options.encoding);

	if (result.error) {
		throw result.error;
	}
	if (result.status !== 0) {
		throw new Error(`${command} ${args.join(' ')} failed with exit code ${result.status}`);
	}
}

function runQuiet(command, args) {
	const result = runCommand(command, args, ['ignore', 'pipe', 'ignore'], 'utf8');
	if (result.error || result.status !== 0) {
		return '';
	}
	return (result.stdout || '').trim();
}

function runOptional(command, args, label) {
	try {
		run(command, args);
		console.log(`${label} completed.`);
	} catch (error) {
		console.warn(`${label} failed (non-blocking): ${error instanceof Error ? error.message : String(error)}`);
	}
}

try {
	const gitRepoCheck = runQuiet('git', ['rev-parse', '--is-inside-work-tree']);
	if ('true' !== gitRepoCheck) {
		console.error('Current directory is not a git repository. Initialize git first.');
		process.exit(1);
	}

	const remoteUrl = runQuiet('git', ['remote', 'get-url', 'origin']);
	if (!remoteUrl) {
		console.error('No git remote named "origin" found. Add remote before releasing.');
		process.exit(1);
	}

	const previousVersion = JSON.parse(
		fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8')
	).version;

	run(npmCommand, ['version', releaseType, '--no-git-tag-version']);
	run('node', ['scripts/sync-version.js']);

	const version = JSON.parse(fs.readFileSync(path.join(rootDir, 'package.json'), 'utf8')).version;
	run('node', ['scripts/promote-changelog.js', version, previousVersion]);

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

	const branch = runQuiet('git', ['rev-parse', '--abbrev-ref', 'HEAD']) || 'main';
	run('git', ['push', 'origin', branch]);
	run('git', ['push', 'origin', tag]);

	console.log(`Release ${tag} created and pushed.`);
	console.log('GitHub Actions will build the ZIP and publish the release.');
} catch (error) {
	console.error(`Release failed: ${error instanceof Error ? error.message : String(error)}`);
	process.exit(1);
}
