# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- MJML tab: init/refresh CodeMirror when the panel is visible; add “Show code editor” button (with DE/POT strings).

### Changed

- Release flow drafts/validates `[Unreleased]` changelog notes before bumping the version (avoids orphan version bumps).

## [1.3.4] - 2026-07-18

### Fixed

- Restored text alignment for heading/paragraph via the block toolbar (no duplicate sidebar control).
- Fixed invalid header heading/paragraph blocks on load (attrs like `textColor`/`fontSize` no longer mismatch core `save()` HTML).
- Recreate core heading/paragraph blocks after parse so stored header seeds always open as valid.
- Stop stripping header text colors on load (repair no longer removes `textColor` / `style`).
- Larger click target for separators in the visual editor (28px hit area).
- MJML tab: init/refresh CodeMirror when the panel is visible; add “Show code editor” button.

[1.3.3]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.3.3

## [1.3.3] - 2026-07-17

### Fixed

- Rebuilt German `.mo` from the complete `.po` catalog.
- Unified translator comment for the personalized greeting string (`Hi %s,`).

[1.3.2]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.3.2

## [1.3.2] - 2026-07-17

### Changed

- Adopted WordPress Coding Standards 3.4 (WordPress-Extra); PHPCS reports 0 errors / 0 warnings.
- Pinned PHPCS / WPCS and committed `composer.lock` for reproducible lint installs.

### Fixed

- Prepared SQL placeholders and related database query lint issues.
- Nonce / capability documentation and security-oriented PHPCS findings across admin and mailer code.

### Documentation

- README and `readme.txt` updated for the visual email editor and WPCS 3.4 baseline.

[1.3.1]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.3.1

## [1.3.1] - 2026-07-17

### Added

- Post meta block in the posts loop (date and/or author).
- Excerpt word-count control in the visual editor.

### Changed

- Intro section shows only the personalized greeting; optional text via paragraph/heading blocks.
- Removed the legacy `{{wstp:posts_intro}}` token and default intro line.

[1.3.0]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.3.0

## [1.3.0] - 2026-07-17

### Added

- Visual Gutenberg email editor for digest templates (blocks → MJML).
- Column width (%) control, Gap after spacing, and starter layouts (minimal, stacked, image-left).
- Editable intro body with personalized greeting placeholder; German translations for the new UI.

### Fixed

- Outlook/column width handling (percent vs px) and mobile stacked images.
- Footer phone `tel:` links; separator styles; duplicate color/typography inspector controls.
- Send preview placed next to Preview HTML (Visual and MJML tabs).

[1.2.1]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.2.1

## [1.2.1] - 2026-07-16

[1.2.0]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.2.0

## [1.2.0] - 2026-07-16

[1.1.3]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.1.3

## [1.1.3] - 2026-07-15

[1.1.2]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.1.2

## [1.1.2] - 2026-06-19

[1.1.1]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.1.1

## [1.1.1] - 2026-06-07

[1.1.0]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.1.0

## [1.1.0] - 2026-06-07

[1.0.0]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v1.0.0

## [1.0.0] - 2026-06-05

[0.1.3]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v0.1.3

## [0.1.3] - 2026-06-03

[0.1.2]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v0.1.2

## [0.1.2] - 2026-06-03

[0.1.1]: https://github.com/gbyat/we-subscribe-to-posts/releases/tag/v0.1.1

## [0.1.1] - 2026-06-03

- Initial development.
