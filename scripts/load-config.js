/**
 * Load plugin.config.json from the project root.
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.join(__dirname, '..');

/**
 * @return {Record<string, unknown>}
 */
function loadConfig() {
	const configPath = path.join(rootDir, 'plugin.config.json');

	if (!fs.existsSync(configPath)) {
		console.error(`Missing plugin.config.json at ${configPath}`);
		process.exit(1);
	}

	return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

module.exports = {
	rootDir,
	loadConfig,
};
