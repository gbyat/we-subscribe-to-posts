const esbuild = require('esbuild');
const fs = require('fs');
const path = require('path');

const rootDir = path.join(__dirname, '..');
const outFile = path.join(rootDir, 'assets', 'js', 'mjml-browser.min.js');
const entryFile = path.join(__dirname, 'mjml-browser-entry.js');

fs.mkdirSync(path.dirname(outFile), { recursive: true });

esbuild
	.build({
		entryPoints: [entryFile],
		bundle: true,
		minify: true,
		format: 'iife',
		globalName: 'wstpMjml',
		platform: 'browser',
		target: ['es2018'],
		outfile: outFile,
		logLevel: 'info',
	})
	.then(() => {
		console.log(`MJML browser bundle written to ${outFile}`);
	})
	.catch((error) => {
		console.error(error);
		process.exit(1);
	});
