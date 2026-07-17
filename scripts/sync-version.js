const fs = require('fs');
const path = require('path');

const rootDir = path.join(__dirname, '..');
const packagePath = path.join(rootDir, 'package.json');
const pluginFilePath = path.join(rootDir, 'we-subscribe-to-posts.php');
const readmeMdPath = path.join(rootDir, 'README.md');
const readmeTxtPath = path.join(rootDir, 'README.txt');
const fallbackReadmeTxtPath = path.join(rootDir, 'readme.txt');

const packageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
const version = packageData.version;

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

updatePluginMainFile();
updateReadmeStableTag(readmeMdPath, true);
updateReadmeStableTag(readmeTxtPath, false);
updateReadmeStableTag(fallbackReadmeTxtPath, false);

console.log(`Version synchronized to ${version}.`);
