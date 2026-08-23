=== ORGAMSIC Bunny Embeds ===
Contributors: orgasmic
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later

Turns Bunny Stream play links in FluentCommunity posts into an inline player, tracks playback, and forwards events via webhook.

== Description ==

* Replaces the published-feed preview with the official Bunny iframe
* Optional autoplay (WP-Admin setting)
* Tracks play / pause / progress / ended / seeked per logged-in member
* Local Wiedergaben log + outbound webhook (`video.play`, …)

Independent of the ORGAMSIC Tracker.

== Installation ==

1. Copy `orgasmic-fc-embeds` into `wp-content/plugins/`
2. Activate **ORGAMSIC Bunny Embeds**
3. Open WP-Admin → ORGAMSIC Bunny → Einstellungen
4. Set webhook URL and autoplay
5. Optional: add `community.orgasmic.live` to Bunny Stream → Allowed Domains

== Changelog ==

= 1.1.0 =
* Playback tracking (who watched how far) with webhook and admin log
* Autoplay on/off in settings

= 1.0.1 =
* Do not intercept FluentCommunity oEmbed while composing a post
* If a player is already in the post, do not insert a second one

= 1.0.0 =
* Initial release (split out of orgasmic-fc-tracker).
