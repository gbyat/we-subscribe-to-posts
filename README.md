# We Subscribe To Posts

**Contributors:** webentwicklerin  
**Stable tag:** 1.0.0  
**Requires at least:** 6.6  
**Tested up to:** 7.0  
**Requires PHP:** 8.1  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Post subscription notifications for WordPress with double opt-in, one-click unsubscribe, digest delivery (daily/weekly/monthly), and an email template editor.

## Features

- Frontend subscription form with block and shortcode support
- Double opt-in confirmation flow
- One-click unsubscribe links in outgoing emails
- Subscriber management (filters, bulk actions, CSV export)
- Digest scheduling for daily, weekly, and monthly frequencies
- Preview mail sending and configurable digest subjects
- Optional SMTP transport settings
- GitHub-based plugin release/update flow

## Usage

- Add the form via block: `wstp/subscription-form`
- Or shortcode: `[wstp_subscription_form]`
- Configure settings under `Post Subscriptions` in wp-admin

## Development

- Build POT catalog: `npm run pot`
- Build JS translation JSON: `npm run json`
- Create release: `npm run release:patch` (or `minor` / `major`)

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).
