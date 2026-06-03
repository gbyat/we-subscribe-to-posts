# WordPress Plugin Release Template

Reusable template for release/version/update infrastructure across plugins.

## What this template standardizes

- Semantic version bump via `npm run release:patch|minor|major`
- Version sync between `package.json` and plugin bootstrap file
- Changelog discipline (`CHANGELOG.md`)
- Tag-driven GitHub Releases (`vX.Y.Z`)
- GitHub-based WordPress auto-updates
- Optional local `TASK-LOG.md` workflow

## Required project-specific placeholders

Replace these values when applying to another project:

- Plugin slug and folder name (example: `we-subscribe-to-posts`)
- Main plugin file (example: `we-subscribe-to-posts.php`)
- PHP namespace prefix (example: `WSTP`)
- Version constant name (example: `WSTP_VERSION`)
- GitHub repo slug (example: `gbyat/we-subscribe-to-posts`)
- Release ZIP file name (example: `we-subscribe-to-posts.zip`)

## Copy into target project

- `scripts/release.js`
- `scripts/sync-version.js`
- `scripts/extract-release-notes.js`
- `.github/workflows/pr.yml`
- `.github/workflows/release.yml`
- `includes/core/class-updater.php`
- `CHANGELOG.md` (starter)
- `.gitignore` entries for local artifacts

## Integration checklist

1. Add package scripts (`release:*`, `sync:version`) in `package.json`.
2. Add Plugin URI and Update URI to the main plugin header.
3. Define a repo constant in bootstrap (example: `WSTP_GITHUB_REPO`).
4. Instantiate updater in plugin bootstrap (`is_admin() || wp_doing_cron()`).
5. Add admin setting toggle for GitHub updates.
6. Ensure release workflow ZIP contains only deployable plugin files.
7. Ensure tag policy is strict: `vX.Y.Z`.
8. Run a dry release on a test repository first.

## Suggested release flow

1. Finish feature/fix work.
2. Ensure `CHANGELOG.md` is ready.
3. Run `npm run release:patch` (or `minor`/`major`).
4. Confirm GitHub Action finished and attached ZIP.
5. Verify update appears on a test WordPress site with updates enabled.
