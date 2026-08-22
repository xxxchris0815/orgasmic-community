=== ORGAMSIC FluentCommunity Tracker ===
Contributors: orgasmic
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
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
3. Open WP Admin → ORGAMSIC Tracker
4. Optional: set webhook URL under Einstellungen

== Changelog ==

= 1.0.0 =
* Initial release
