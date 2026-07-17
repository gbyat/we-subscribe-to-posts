=== We Subscribe To Posts ===
Contributors: webentwicklerin
Tags: subscription, email, digest, notifications, double-opt-in
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Post subscription digests with double opt-in, unsubscribe links, and a visual Gutenberg → MJML email editor.

== Description ==

We Subscribe To Posts adds a focused post subscription workflow to WordPress:

- frontend signup form (block and shortcode)
- double opt-in confirmation flow
- one-click unsubscribe links
- digest delivery by frequency (daily, weekly, monthly)
- subscriber management in wp-admin (filters, bulk actions, CSV export)
- visual email template editor (Gutenberg blocks compiled to MJML)
- starter layouts, branding colors, header/footer, HTML preview and send-preview
- posts-loop fields including title, date/author meta, excerpt word count, images, and read more
- optional SMTP transport settings
- PHP code checked against WordPress Coding Standards 3.4 (WordPress-Extra)

Use this plugin when you only need post update notifications without a full newsletter suite.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP via wp-admin.
2. Activate **We Subscribe To Posts** in `Plugins`.
3. Open `Post Subscriptions` in wp-admin and configure your settings.
4. Add the subscription form block, or use shortcode `[wstp_subscription_form]`.
5. Customize the digest under the Email Template screen (Visual or MJML).

== Frequently Asked Questions ==

= How do users subscribe? =

Users subscribe through the form and confirm by double opt-in email.

= How can users unsubscribe? =

Every email contains a one-click unsubscribe link.

= Can I customize email content? =

Yes. Use the Visual tab to compose with blocks (header, greeting, posts loop, footer). The editor compiles to MJML/HTML for reliable email clients. Starter layouts and branding colors are available.

= Can I control the excerpt length? =

Yes. In the posts loop, the Post excerpt block has a word-count setting.

== Changelog ==

= 1.3.1 =

* Post meta block (date and/or author) in the posts loop
* Excerpt word-count control in the visual editor
* Intro section: personalized greeting only; optional text via blocks
* Removed legacy `{{wstp:posts_intro}}` token

= 1.3.0 =

* Visual Gutenberg email editor for digest templates (blocks → MJML)
* Column width, Gap after, starter layouts (minimal, stacked, image-left)
* Outlook/column width and mobile image fixes; branding and preview improvements

See `CHANGELOG.md` for full release notes.

= Earlier =

See `CHANGELOG.md`.
