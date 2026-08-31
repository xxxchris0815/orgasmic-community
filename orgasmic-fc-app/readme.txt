=== ORGASMIC App ===
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.1.9
License: GPLv2 or later

PWA, offline cache, and Web Push for chat, posts, comments, and calendar events.

== Changelog ==

= 1.1.9 =
* Register the FCM token after login (not on the login screen), retry via admin-ajax if the REST nonce is stale
* Push copy names the circle and type: Chat, Beitrag, Kommentar, Termin
* Space membership lookup includes FC statuses beyond active/accepted
* Admin lists recent FCM devices so you can see whether a member has a token

= 1.1.8 =
* Native shell: keep status/nav bars off the FluentCommunity chrome; align notification item in the profile menu

= 1.1.7 =
* Test-push reports missing FCM token vs missing Firebase service account

= 1.1.6 =
* Do not call Capacitor PushNotifications.register until Firebase is initialized (prevents Android crash after login)

= 1.1.5 =
* Service worker caches calendar and chat REST responses (network-first)

= 1.1.4 =
* Notification settings live in the profile dropdown (no extra bell icons)

= 1.1.3 =
* Service worker also caches chat uploads (images and audio)

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
