const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const rootDir = path.join(__dirname, '..');
const packagePath = path.join(rootDir, 'package.json');
const pluginFilePath = path.join(rootDir, 'we-subscribe-to-posts.php');
const changelogPath = path.join(rootDir, 'CHANGELOG.md');
const readmeMdPath = path.join(rootDir, 'README.md');
const readmeTxtPath = path.join(rootDir, 'README.txt');
const fallbackReadmeTxtPath = path.join(rootDir, 'readme.txt');
const repoSlug = 'gbyat/we-subscribe-to-posts';

const packageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
const version = packageData.version;
const today = new Date().toISOString().slice(0, 10);

function runGit(command) {
	return execSync(command, {
		cwd: rootDir,
		encoding: 'utf8',
		stdio: ['pipe', 'pipe', 'ignore'],
	}).trim();
}

function updatePluginMainFile() {
	let content = fs.readFileSync(pluginFilePath, 'utf8');
	content = content.replace(/Version:\s*[0-9]+\.[0-9]+\.[0-9]+/, `Version: ${version}`);
	content = content.replace(
		/define\(\s*'WSTP_VERSION',\s*'[^']*'\s*\);/,
		`define( 'WSTP_VERSION', '${version}' );`
	);
	fs.writeFileSync(pluginFilePath, content, 'utf8');
}

function updateReadmeStableTag(readmePath, markdown) {
	if (!fs.existsSync(readmePath)) {
		return;
	}

	let content = fs.readFileSync(readmePath, 'utf8');
	if (markdown) {
		content = content.replace(/\*\*Stable tag:\*\*\s*[0-9]+\.[0-9]+\.[0-9]+/, `**Stable tag:** ${version}`);
	} else {
		content = content.replace(/Stable tag:\s*[0-9]+\.[0-9]+\.[0-9]+/, `Stable tag: ${version}`);
	}
	fs.writeFileSync(readmePath, content, 'utf8');
}

function ensureChangelogExists() {
	if (fs.existsSync(changelogPath)) {
		return;
	}

	const initial = `# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Initial development.

`;
	fs.writeFileSync(changelogPath, initial, 'utf8');
}

function buildEntryBody(existingContent) {
	const unreleasedMatch = existingContent.match(/## \[Unreleased\]([\s\S]*?)(?=## \[|$)/);
	if (unreleasedMatch && unreleasedMatch[1].trim()) {
		return unreleasedMatch[1].trim();
	}

	try {
		let lastTag = '';
		try {
			lastTag = runGit('git describe --tags --abbrev=0');
		} catch (error) {
			lastTag = '';
		}

		const range = lastTag ? `${lastTag}..HEAD` : '-20';
		const log = runGit(`git log ${range} --pretty=format:%s --no-merges`);
		if (!log) {
			return '- Version update';
		}

		const lines = log
			.split('\n')
			.map((line) => line.trim())
			.filter((line) => line.length > 0)
			.filter((line) => !/^Release v[0-9]+\.[0-9]+\.[0-9]+$/i.test(line))
			.filter((line) => !/^Version update$/i.test(line))
			.slice(0, 12)
			.map((line) => `- ${line}`);

		return lines.length > 0 ? lines.join('\n') : '- Version update';
	} catch (error) {
		return '- Version update';
	}
}

function upsertChangelog() {
	ensureChangelogExists();
	let content = fs.readFileSync(changelogPath, 'utf8');
	const escapedVersion = version.replace(/\./g, '\\.');
	const hasVersion = new RegExp(`## \\[${escapedVersion}\\]`).test(content);

	if (!hasVersion) {
		const entryBody = buildEntryBody(content);
		const newEntry = `## [${version}] - ${today}

${entryBody}

`;
		const firstVersionHeaderIndex = content.search(/^## \[/m);
		if (firstVersionHeaderIndex >= 0) {
			content = content.slice(0, firstVersionHeaderIndex) + newEntry + content.slice(firstVersionHeaderIndex);
		} else {
			content = `${content.trim()}\n\n${newEntry}`;
		}
	}

	content = content.replace(/## \[Unreleased\]([\s\S]*?)(?=## \[|$)/, '## [Unreleased]\n\n');

	const linkLine = `[${version}]: https://github.com/${repoSlug}/releases/tag/v${version}`;
	if (!content.includes(linkLine)) {
		content = `${content.trim()}\n\n${linkLine}\n`;
	}

	fs.writeFileSync(changelogPath, content, 'utf8');
}

updatePluginMainFile();
updateReadmeStableTag(readmeMdPath, true);
updateReadmeStableTag(readmeTxtPath, false);
updateReadmeStableTag(fallbackReadmeTxtPath, false);
upsertChangelog();

console.log(`Version synchronized to ${version}.`);
