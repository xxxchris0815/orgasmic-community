=== ORGASMIC App ===
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.1.2
License: GPLv2 or later

PWA, offline cache, and Web Push for chat, posts, comments, and calendar events.

== Changelog ==

= 1.1.2 =
* Keep the bell out of the center header menu (icon only, right side)

= 1.1.1 =
* Mobile nav shows the bell glyph instead of an empty blue circle

= 1.1.0 =
* Capacitor token REST (`POST /push/token`) and optional Firebase HTTP v1 send
* Native PushNotifications / Camera / VoiceRecorder hooks in portal JS

= 1.0.1 =
* Per-member notification prefs (chat, feed, comment, event)
* Pad VAPID/ECDH P-256 coordinates to 32 bytes
* Register /orgasmic-sw.js and /orgasmic-manifest.json rewrite rules on activate

= 1.0.0 =
* Manifest + service worker
* Push subscriptions and queue
* Chat, feed, comment, and event notifications scoped to space members
