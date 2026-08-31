=== ORGASMIC Chat ===
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.1.16
License: GPLv2 or later

Space chat for FluentCommunity. One room per space, members only, header icon with unread badge, REST API.

== Changelog ==

= 1.1.16 =
* Load the latest 40 messages, then older ones when scrolling up
* Initial load no longer returns the oldest 50 messages

= 1.1.15 =
* Keep scroll position when selecting messages
* Desktop pick control and WhatsApp-style left/right bubbles

= 1.1.14 =
* Select messages without rebuilding the thread

= 1.1.13 =
* Long-press messages to select and delete (trash in the header), like WhatsApp
* Discard a pending voice note with a trash icon instead of the Sprachnachricht label

= 1.1.12 =
* Fill the visual viewport when the keyboard is open so the feed cannot show between composer and keys
* Android adjustResize was still subtracting the FluentCommunity bar height (that check never saw a keyboard)

= 1.1.11 =
* Keep the chat overlay covering the feed when the keyboard opens
* Use FluentCommunity profile photos in chat and refresh them after a change

= 1.1.10 =
* Close chat when tapping Home on the FluentCommunity bar; keep overlay opaque above the keyboard

= 1.1.9 =
* Mobile chat overlay leaves the FluentCommunity bottom bar visible

= 1.1.8 =
* Faster voice playback actually speeds up and keeps pitch
* Room icons use the Space logo or cover photo when set

= 1.1.7 =
* Faster voice playback keeps the original pitch

= 1.1.6 =
* Instant channel switch (cached thread or “Chat wird geladen…”)
* Mighty Networks-style layout, avatars, waveform player, and composer

= 1.1.5 =
* Record voice as WebM/Opus again (browser default mic, no AGC/bitrate overrides)

= 1.1.4 =
* Cache images and voice notes locally (Cache API)
* Play voice through Web Audio so Opus/WebM has sound
* Light white/gold chat wallpaper

= 1.1.3 =
* Keep Chat out of the center header menu (icon only, right side)
* Custom voice player that actually plays recordings
* Live ORGASMIC wallpaper (navy + gold) and readable bubble text

= 1.1.2 =
* Mobile nav shows the chat glyph instead of an empty blue circle

= 1.1.1 =
* WhatsApp-like bubbles, chat wallpaper, compact list, and round send button

= 1.1.0 =
* Voice messages (browser MediaRecorder, Capacitor VoiceRecorder if present)
* Capacitor Camera for image pick when the native app is wrapped

= 1.0.4 =
* Cache rooms and last messages in localStorage for offline viewing

= 1.0.3 =
* Fires orgasmic_fc/chat/message for the App/push plugin

= 1.0.2 =
* Room list no longer inherits FluentCommunity primary button styles

= 1.0.1 =
* Admin color settings and editable subtitle
* Choose which spaces have chat
* Keep focus while typing; disable send until the message is stored; clear the composer after send
* Remove the unused overlay scrollbar

= 1.0.0 =
* Initial release: space rooms, membership checks, header unread badge, polling, optional image, REST for future PWA
