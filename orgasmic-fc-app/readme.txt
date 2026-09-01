=== ORGASMIC App ===
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.1.20
License: GPLv2 or later

PWA, offline cache, and Web Push for chat, posts, comments, and calendar events.

== Changelog ==

= 1.1.20 =
* Native app: do not pull-to-reload (WebView froze on the FluentCommunity skeleton)
* Bind window.fetch so portal API calls work in Android WebView

= 1.1.19 =
* Announce controls are a megaphone icon in the composer toolbar; the checkboxes open on click

= 1.1.18 =
* Announce checkboxes sit under the composer toolbar so they no longer cover the text field

= 1.1.17 =
* Push/E-Mail checkboxes sit in a body overlay above the publish button so Vue cannot remove them
* Composer is detected by the German title “Beitrag erstellen”, not by CSS class names
* Service worker fetches plugin JS/CSS from the network so updates apply immediately

= 1.1.16 =
* Push and email checkboxes attach to the FluentCommunity post box (dialog, expanded editor, German publish labels)

= 1.1.15 =
* PWA and apple-touch icons use the gold LO mark instead of the placeholder circle

= 1.1.12 =
* Admin “Push prüfen”: search a member, see token/prefs/queue errors, send a test push to their phone

= 1.1.11 =
* Admins can send a post as push and/or email to everyone who can see it (composer checkboxes)

= 1.1.10 =
* Push uses Event (not Termin) and omits a generic Kreis when the space name is missing
* Pull down at the top of the activity stream to reload

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
