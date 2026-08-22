=== ORGAMSIC Bunny Embeds ===
Contributors: orgasmic
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later

Turns Bunny Stream play links in FluentCommunity posts into an inline player.

== Description ==

FluentCommunity only knows YouTube/Vimeo as video embeds. A Bunny Stream
`player.mediadelivery.net/play/...` link otherwise becomes an Open Graph card
that leaves the community.

This plugin:

* Replaces the published-feed preview with the official Bunny iframe
* Starts playback with autoplay
* Shows only one player per post
* Leaves the URL alone while composing a post

Does not depend on the ORGAMSIC Tracker and does not count video plays.

== Installation ==

1. Copy `orgasmic-fc-embeds` into `wp-content/plugins/`
2. Activate **ORGAMSIC Bunny Embeds**
3. If you still have Tracker 1.1.x, update the Tracker to 1.2.0 so embeds are not loaded twice
4. Optional: add `community.orgasmic.live` to Bunny Stream → Allowed Domains

Does not track video plays. Use the Tracker plugin for feed/comment/like events.

== Changelog ==

= 1.0.1 =
* Do not intercept FluentCommunity oEmbed while composing a post (pasted links stay visible).
* If a player is already in the post, do not insert a second one.

= 1.0.0 =
* Initial release (split out of orgasmic-fc-tracker).
