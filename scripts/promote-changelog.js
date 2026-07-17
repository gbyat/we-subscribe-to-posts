/**
 * Promote CHANGELOG.md ## [Unreleased] to ## [version] - YYYY-MM-DD
 * and insert a Keep a Changelog release link for the previous version.
 *
 * Usage: node scripts/promote-changelog.js <version> [previousVersion]
 */
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const rootDir = path.join(__dirname, '..');
const changelogPath = path.join(rootDir, 'CHANGELOG.md');

function runQuiet(command, args) {
	const result = spawnSync(command, args, {
		cwd: rootDir,
		encoding: 'utf8',
		shell: false,
	});
	if (result.error || result.status !== 0) {
		return '';
	}
	return (result.stdout || '').trim();
}

function todayIsoDate() {
	const d = new Date();
	const y = d.getFullYear();
	const m = String(d.getMonth() + 1).padStart(2, '0');
	const day = String(d.getDate()).padStart(2, '0');
	return `${y}-${m}-${day}`;
}

function githubRepoWebUrl(remoteUrl) {
	if (!remoteUrl) {
		return '';
	}

	let url = remoteUrl.trim().replace(/\.git$/i, '');

	const sshMatch = url.match(/^git@([^:]+):(.+)$/);
	if (sshMatch) {
		return `https://${sshMatch[1]}/${sshMatch[2]}`.replace(/\/$/, '');
	}

	const sshUrlMatch = url.match(/^ssh:\/\/git@([^/]+)\/(.+)$/);
	if (sshUrlMatch) {
		return `https://${sshUrlMatch[1]}/${sshUrlMatch[2]}`.replace(/\/$/, '');
	}

	if (/^https?:\/\//i.test(url)) {
		return url.replace(/\/$/, '');
	}

	return '';
}

function findUnreleasedSection(content) {
	const headerRe = /^## \[Unreleased\][ \t]*\r?\n/m;
	const headerMatch = content.match(headerRe);
	if (!headerMatch || typeof headerMatch.index !== 'number') {
		return null;
	}

	const start = headerMatch.index;
	const bodyStart = start + headerMatch[0].length;
	const rest = content.slice(bodyStart);
	const nextHeader = rest.search(/^## \[/m);
	const bodyEnd = -1 === nextHeader ? rest.length : nextHeader;

	return {
		start,
		end: bodyStart + bodyEnd,
		body: rest.slice(0, bodyEnd),
	};
}

function hasReleaseNotes(body) {
	return /^\s*[-*]\s+\S/m.test(body);
}

/**
 * @param {string} content
 * @param {string} version
 * @param {string} [previousVersion]
 * @param {string} [remoteUrl]
 * @returns {string}
 */
function promoteUnreleased(content, version, previousVersion, remoteUrl) {
	const section = findUnreleasedSection(content);
	if (!section) {
		throw new Error(
			'CHANGELOG.md has no ## [Unreleased] section. Add release notes there before releasing.'
		);
	}

	if (!hasReleaseNotes(section.body)) {
		throw new Error(
			'CHANGELOG.md [Unreleased] has no bullet notes. Add entries under ## [Unreleased] before releasing.'
		);
	}

	const date = todayIsoDate();
	const body = section.body.replace(/^\r?\n+/, '').replace(/\s+$/, '\n');
	const repoUrl = githubRepoWebUrl(remoteUrl || '');
	let linkBlock = '';

	if (previousVersion && repoUrl) {
		linkBlock = `\n[${previousVersion}]: ${repoUrl}/releases/tag/v${previousVersion}\n\n`;
	}

	const promoted = `## [Unreleased]\n\n## [${version}] - ${date}\n\n${body}${linkBlock}`;
	return content.slice(0, section.start) + promoted + content.slice(section.end);
}

function main() {
	const version = process.argv[2];
	const previousVersion = process.argv[3] || '';

	if (!version || !/^\d+\.\d+\.\d+/.test(version)) {
		console.error('Usage: node scripts/promote-changelog.js <version> [previousVersion]');
		process.exit(1);
	}

	if (!fs.existsSync(changelogPath)) {
		console.error('CHANGELOG.md not found.');
		process.exit(1);
	}

	const remoteUrl = runQuiet('git', ['remote', 'get-url', 'origin']);
	const before = fs.readFileSync(changelogPath, 'utf8');
	const after = promoteUnreleased(before, version, previousVersion, remoteUrl);
	fs.writeFileSync(changelogPath, after, 'utf8');
	console.log(`CHANGELOG.md: promoted [Unreleased] to [${version}].`);
}

if (require.main === module) {
	try {
		main();
	} catch (error) {
		console.error(error instanceof Error ? error.message : String(error));
		process.exit(1);
	}
}

module.exports = {
	promoteUnreleased,
	findUnreleasedSection,
	hasReleaseNotes,
	githubRepoWebUrl,
	todayIsoDate,
};
