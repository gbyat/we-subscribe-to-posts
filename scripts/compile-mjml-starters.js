const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const rootDir = path.join(__dirname, '..');
const startersDir = path.join(rootDir, 'templates', 'emails', 'starters');
const mjmlBinary = path.join(
	rootDir,
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'mjml.cmd' : 'mjml'
);

if (!fs.existsSync(mjmlBinary)) {
	console.error('MJML CLI not found. Run npm install first.');
	process.exit(1);
}

if (!fs.existsSync(startersDir)) {
	console.error(`Starters directory not found: ${startersDir}`);
	process.exit(1);
}

const files = fs.readdirSync(startersDir).filter((name) => name.endsWith('.mjml'));
if (files.length === 0) {
	console.error('No starter .mjml files found.');
	process.exit(1);
}

files.forEach((fileName) => {
	const inputPath = path.join(startersDir, fileName);
	const outputPath = path.join(startersDir, fileName.replace(/\.mjml$/i, '.html'));
	const command = `"${mjmlBinary}" "${inputPath}" -o "${outputPath}"`;

	execSync(command, {
		cwd: rootDir,
		stdio: 'inherit',
		shell: true,
	});

	console.log(`Compiled ${fileName} -> ${path.basename(outputPath)}`);
});
