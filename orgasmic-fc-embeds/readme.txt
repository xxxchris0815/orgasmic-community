=== ORGASMIC Bunny Embeds ===
Contributors: orgasmic
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.10
License: GPLv2 or later

Turns Bunny Stream play links in FluentCommunity posts into an inline player, tracks playback, and forwards events via webhook.

== Description ==

* Video files from the FluentCommunity composer upload to Bunny Stream; the player link is inserted into the post
* Replaces the published-feed preview with the official Bunny iframe
* Optional autoplay (WP-Admin setting)
* Tracks play / pause / progress / ended / seeked per logged-in member
* Local Wiedergaben log + outbound webhook (`video.play`, …)

Independent of the ORGASMIC Tracker.

== Installation ==

1. Copy `orgasmic-fc-embeds` into `wp-content/plugins/`
2. Activate **ORGASMIC Bunny Embeds**
3. Open WP-Admin → ORGASMIC Bunny → Einstellungen
4. Set Bunny Library-ID and Stream API key (required for composer uploads)
5. Set webhook URL and autoplay
6. Optional: add `community.orgasmic.live` to Bunny Stream → Allowed Domains

== Changelog ==

= 1.2.10 =
* Capacitor/WebView: read the picked video via arrayBuffer before chunking (FileReader often fails)

= 1.2.9 =
* Phone app uploads use FileReader + admin-ajax instead of XHR FormData (WebView network error)

= 1.2.8 =
* Hide the Bunny provider name from composer upload status and errors

= 1.2.7 =
* Phone / PWA / Capacitor uploads skip TUS and send 1 MB chunks through WordPress
* Desktop TUS that stays at 0% falls back to the same origin upload

= 1.2.6 =
* TUS fingerprint returns a Promise (tus-js-client 4.x)

= 1.2.5 =
* Finish Bunny TUS uploads (no stale resume); verify the file arrived; server PUT fallback

= 1.2.4 =
* Replace the play URL in the composer with an inline Bunny player object

= 1.2.3 =
* Put the play URL in the post body, not the title line
* Show the Bunny player in the composer instead of the raw link

= 1.2.2 =
* Insert the Bunny play URL back into the post composer after upload
* Do not hide the create-post dialog when closing the oEmbed popup

= 1.2.1 =
* Video in the composer opens a native file picker (phone and desktop) instead of the oEmbed dialog
* Plain Bunny play URLs in the feed render as the official iframe player

= 1.2.0 =
* Composer video uploads go to Bunny Stream (TUS) and insert the player link

= 1.1.1 =
* Brand name corrected to ORGASMIC.

= 1.1.0 =
* Playback tracking (who watched how far) with webhook and admin log
* Autoplay on/off in settings

= 1.0.1 =
* Do not intercept FluentCommunity oEmbed while composing a post
* If a player is already in the post, do not insert a second one

= 1.0.0 =
* Initial release (split out of orgasmic-fc-tracker).
