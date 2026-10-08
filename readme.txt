=== Image SEO Pro ===
Contributors: dyzlek
Tags: webp, avif, image optimization, alt text, seo
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lighter images (WebP / AVIF, originals never modified) and complete alt texts, with AI suggestions.

== Description ==

* Creates a WebP and/or AVIF copy next to every image and thumbnail. Originals are never modified.
* Serves them with a `<picture>` tag: every browser takes the best format it supports.
* Optimizes new uploads automatically, or the whole library in one click.
* Lists images without alt text; fill them in place, from the file name, or with a vision AI
  (Ollama locally, or any OpenAI-compatible API).
* Fills empty alt attributes in published content from the media library, without editing posts.
* WP-CLI commands: `wp isp optimize`, `wp isp restore`, `wp isp stats`, `wp isp alt`.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Media → Image SEO Pro and click "Optimize all".
3. Optional: set up the AI in the Settings tab and click "Test the connection".

== Frequently Asked Questions ==

= Are my original images modified? =

No. Copies are created next to them (`photo.jpg.webp`). Restoring an image or uninstalling the plugin
deletes the copies only.

= My server does not support AVIF =

The dashboard shows what your server supports. WebP alone already saves a lot.

== Changelog ==

= 2.0.0 =
* Full rewrite. See CHANGELOG.md.
