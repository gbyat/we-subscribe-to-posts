/**
 * Promote CHANGELOG.md ## [Unreleased] to ## [version] - YYYY-MM-DD
 * and insert a Keep a Changelog release link for the previous version.
 *
 * Usage:
 *   node scripts/promote-changelog.js <version> [previousVersion]
 *   node scripts/promote-changelog.js --draft   # fill empty Unreleased from git commits
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
 * Latest semver tag like v1.2.3, or empty.
 *
 * @return {string}
 */
function latestVersionTag() {
	const tags = runQuiet('git', ['tag', '-l', 'v*', '--sort=-v:refname']);
	if (!tags) {
		return '';
	}
	const first = tags.split(/\r?\n/).map((line) => line.trim()).find(Boolean);
	return first || '';
}

/**
 * Commit subjects since last version tag (or all commits if none).
 *
 * @return {string[]}
 */
function commitSubjectsSinceLastTag() {
	const tag = latestVersionTag();
	const range = tag ? `${tag}..HEAD` : 'HEAD';
	const log = runQuiet('git', ['log', range, '--pretty=format:%s']);
	if (!log) {
		return [];
	}

	return log
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter(Boolean)
		.filter((subject) => !/^Merge\b/i.test(subject))
		.filter((subject) => !/^Release\s+v?\d/i.test(subject))
		.filter((subject) => !/^chore:\s*release\b/i.test(subject));
}

/**
 * If [Unreleased] has no bullets, draft them from commit subjects.
 *
 * @param {boolean} [force=false] Replace existing Unreleased bullets too.
 * @return {{ drafted: boolean, count: number }}
 */
function draftUnreleasedFromCommits(force = false) {
	if (!fs.existsSync(changelogPath)) {
		throw new Error('CHANGELOG.md not found.');
	}

	const content = fs.readFileSync(changelogPath, 'utf8');
	const section = findUnreleasedSection(content);
	if (!section) {
		throw new Error('CHANGELOG.md has no ## [Unreleased] section.');
	}

	if (!force && hasReleaseNotes(section.body)) {
		return { drafted: false, count: 0 };
	}

	const subjects = commitSubjectsSinceLastTag();
	if (subjects.length === 0) {
		throw new Error(
			'CHANGELOG.md [Unreleased] is empty and no commits since the last tag were found to draft from. Add bullets manually.'
		);
	}

	const bullets = subjects.map((subject) => `- ${subject}`).join('\n');
	const next = `${content.slice(0, section.start)}## [Unreleased]\n\n${bullets}\n\n${content.slice(section.end)}`;
	fs.writeFileSync(changelogPath, next, 'utf8');

	return { drafted: true, count: subjects.length };
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
	if (process.argv.includes('--draft')) {
		const force = process.argv.includes('--force');
		const result = draftUnreleasedFromCommits(force);
		if (result.drafted) {
			console.log(
				`CHANGELOG.md: drafted ${result.count} Unreleased note(s) from commits since ${latestVersionTag() || 'the start'}.`
			);
			console.log('Review and edit ## [Unreleased] before releasing.');
		} else {
			console.log('CHANGELOG.md [Unreleased] already has notes — left unchanged (use --force to replace).');
		}
		return;
	}

	const version = process.argv[2];
	const previousVersion = process.argv[3] || '';

	if (!version || !/^\d+\.\d+\.\d+/.test(version)) {
		console.error('Usage: node scripts/promote-changelog.js <version> [previousVersion]');
		console.error('       node scripts/promote-changelog.js --draft [--force]');
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
	draftUnreleasedFromCommits,
	latestVersionTag,
	commitSubjectsSinceLastTag,
};
