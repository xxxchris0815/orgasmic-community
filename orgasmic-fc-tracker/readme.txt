=== ORGASMIC FluentCommunity Tracker ===
Contributors: orgasmic
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later

Tracks FluentCommunity lesson progress and community engagement. Local admin dashboard plus optional outbound webhook.

== Description ==

Listens to FluentCommunity Core hooks (no fork of FluentCommunity):

* Courses: enrolled, lesson completed/incomplete, topic completed, course completed, progress reset, student left
* Feed: created, updated, deleted, mentions, survey votes
* Comments: added, updated, deleted
* Reactions: feed and comment add/remove
* Spaces: joined, join requested, left, created
* Members: deactivate/reactivate, points, level-up (Pro), follow (Pro)

Does not track private chat/DM contents (FluentCommunity does not expose stable public chat hooks for that).

== Installation ==

1. Copy `orgasmic-fc-tracker` into `wp-content/plugins/`
2. Activate the plugin
3. Open WP Admin → ORGASMIC Tracker
4. Optional: set webhook URL under Einstellungen

== Changelog ==

= 1.2.1 =
* Brand name corrected to ORGASMIC.

= 1.2.0 =
* Bunny / oEmbed moved to the separate plugin `orgasmic-fc-embeds`.

= 1.1.3 =
* Show only one Bunny player per post (the previous update could render the embed twice).

= 1.1.2 =
* Bunny player mounts immediately in the feed and starts playback (autoplay) instead of requiring a preview click and a second play click.

= 1.1.1 =
* Bunny Stream (player.mediadelivery.net) play links render as an inline player in the feed instead of an Open Graph preview card.

= 1.1.0 =
* Portal notices stay scoped to the FluentCommunity portal.

= 1.0.0 =
* Initial release
