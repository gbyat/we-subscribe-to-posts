# We Subscribe To Posts

**Contributors:** webentwicklerin  
**Stable tag:** 1.3.2  
**Requires at least:** 6.6  
**Tested up to:** 7.0  
**Requires PHP:** 8.1  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Post subscription notifications for WordPress with double opt-in, one-click unsubscribe, digest delivery (daily/weekly/monthly), and a visual Gutenberg → MJML email editor.

## Features

- Frontend subscription form with block and shortcode support
- Double opt-in confirmation flow
- One-click unsubscribe links in outgoing emails
- Subscriber management (filters, bulk actions, CSV export)
- Digest scheduling for daily, weekly, and monthly frequencies
- Visual email template editor (Gutenberg blocks → MJML), with starter layouts
- Email branding (colors, header/footer), live HTML preview, and send-preview
- Posts-loop fields: title, meta (date/author), excerpt with word count, images, read more
- Optional SMTP transport settings
- GitHub-based plugin release/update flow (changelog in plugin details)

## Usage

- Add the form via block: `wstp/subscription-form`
- Or shortcode: `[wstp_subscription_form]`
- Configure under **Post Subscriptions** in wp-admin (Settings, Email Template, Subscribers)

## Development

- Install PHP tools: `composer install`
- Lint against **WordPress Coding Standards 3.4** (`WordPress-Extra`): `composer run lint` / `composer run lint:fix`
- Build POT catalog: `npm run pot`
- Build JS translation JSON: `npm run json`
- Create release: `npm run release:patch` (or `minor` / `major`)

The PHP codebase is checked clean against WPCS 3.4.0 (0 errors / 0 warnings).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).
