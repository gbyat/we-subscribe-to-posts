/**
 * Build POT file from PHP + Gutenberg JS sources.
 *
 * Uses WP-CLI i18n make-pot command.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const rootDir = path.join(__dirname, '..');
const languagesDir = path.join(rootDir, 'languages');
const textDomain = 'we-subscribe-to-posts';
const potFile = path.join(languagesDir, `${textDomain}.pot`);

if (!fs.existsSync(languagesDir)) {
	fs.mkdirSync(languagesDir, { recursive: true });
}

/**
 * Run a command and return its status.
 *
 * @param {string} command Command to run.
 * @param {string[]} args Command arguments.
 * @returns {{ status: number|null, error: Error|undefined }}
 */
function run(command, args) {
	const result = spawnSync(command, args, {
		cwd: rootDir,
		stdio: 'inherit',
		shell: false,
	});

	return { status: result.status, error: result.error };
}

/**
 * Resolve an available WP-CLI invocation.
 *
 * @returns {{ command: string, argsPrefix: string[] }|null}
 */
function resolveWpCli() {
	const wpCommands = process.platform === 'win32' ? ['wp.cmd', 'wp'] : ['wp'];
	for (const wpCommand of wpCommands) {
		const direct = run(wpCommand, ['--info']);
		if (!direct.error && direct.status === 0) {
			return { command: wpCommand, argsPrefix: [] };
		}
	}

	const phpCandidates = [
		'php',
		path.join(process.env.ProgramFiles || 'C:\\Program Files', 'PHP', 'php-8-3', 'php.exe'),
		path.join(process.env.ProgramFiles || 'C:\\Program Files', 'PHP', 'php.exe'),
		path.join(process.env.USERPROFILE || '', 'scoop', 'apps', 'php', 'current', 'php.exe'),
	].filter(Boolean);

	const pharCandidates = [
		path.join(rootDir, 'wp-cli.phar'),
		path.join(rootDir, 'tools', 'wp-cli.phar'),
		path.join(process.env.USERPROFILE || '', 'bin', 'wp-cli.phar'),
	];

	for (const pharPath of pharCandidates) {
		if (!fs.existsSync(pharPath)) {
			continue;
		}
		for (const phpBinary of phpCandidates) {
			if ('php' !== phpBinary && !fs.existsSync(phpBinary)) {
				continue;
			}
			const phpCheck = run(phpBinary, [pharPath, '--info']);
			if (!phpCheck.error && phpCheck.status === 0) {
				return { command: phpBinary, argsPrefix: [pharPath] };
			}
		}
	}

	return null;
}

/**
 * Run WP-CLI i18n command and fail loudly on errors.
 *
 * @param {string[]} args WP-CLI i18n arguments.
 * @returns {void}
 */
function runWp(args) {
	const cli = resolveWpCli();
	if (!cli) {
		console.error('WP-CLI not found. Install "wp" globally or place "wp-cli.phar" in project root.');
		console.error('Expected one of:');
		console.error('- wp (available in PATH)');
		console.error('- php ./wp-cli.phar');
		console.error('- php ./tools/wp-cli.phar');
		console.error('- C:\\Users\\<you>\\bin\\wp-cli.phar with a local php.exe path');
		process.exit(1);
	}

	const result = run(cli.command, [...cli.argsPrefix, ...args]);
	if (result.error) {
		throw result.error;
	}
	if (result.status !== 0) {
		process.exit(result.status || 1);
	}
}

try {
	runWp([
		'i18n',
		'make-pot',
		'.',
		potFile,
		`--domain=${textDomain}`,
		'--exclude=node_modules,vendor,scripts',
	]);

	console.log(`POT file updated: ${potFile}`);
} catch (error) {
	console.error('WP-CLI POT build failed.');
	console.error('Ensure WP-CLI is installed and available as "wp".');
	console.error(error instanceof Error ? error.message : String(error));
	process.exit(1);
}
