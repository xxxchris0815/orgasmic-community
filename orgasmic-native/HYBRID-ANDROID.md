# LO Community — Android Hybrid (Test)

Eigener Branch: `cursor/android-hybrid-shell-d4ba`. Die App ist **kein** Voll-WebView mehr.

## Was du siehst

Unten vier native Tabs:

| Tab | Was passiert |
| --- | --- |
| **Feed** | WebView auf `https://community.orgasmic.live/portal` (FluentCommunity). Login bleibt hier. |
| **Chat** | Native Liste + Thread gegen `/wp-json/orgasmic-chat/v1/` |
| **Kalender** | Native Liste + RSVP gegen `/wp-json/orgasmic-events/v1/` |
| **Profil** | Schalter, Datenschutz, Konto löschen gegen `/wp-json/orgasmic-app/v1/` |

Kamera/Mikro im Feed (Beiträge) laufen über den WebView-Dateidialog. Chat in dieser Testversion: **Text**. Bilder/Sprachnachrichten kommen später.

## Backend — was nötig ist

Kein neues WordPress-Plugin-Modell. Es reicht:

1. Plugin **ORGASMIC App 1.1.29** (Session-JSON + Blendet FC-Tab-Leiste/Chat/Kalender-Icons im Hybrid-WebView aus). Ohne das funktioniert die API trotzdem, du siehst aber doppelte Navigation im Feed.
2. Chat- und Kalender-Plugins wie bisher aktiv.
3. WordPress-**Cookies + REST-Nonce**. Die App holt den Nonce nach dem Login per `admin-ajax.php?action=orgasmic_fc_app_boot` (kein REST, deshalb kein Huhn-Ei). Danach: `Cookie` + `X-WP-Nonce`.
4. Push: dasselbe Firebase wie die alte App. Token geht an `orgasmic_fc_app_push_token`.

**Keine** Application Passwords, kein JWT, kein CORS (native HTTP).

## Testen

1. App **1.1.29** auf den Server.
2. Codemagic: Workflow **Android Debug** auf Branch `cursor/android-hybrid-shell-d4ba`.
3. APK installieren.
4. Tab **Feed** → einloggen.
5. Tab **Chat** → Raum öffnen, Text senden.
6. Tab **Kalender** → Event, RSVP.
7. Tab **Profil** → Schalter, optional Konto löschen nur an einem Wegwerf-User.

Wenn Chat „Bitte zuerst im Feed einloggen“ bleibt: Cookies kamen nicht an. Einmal Feed neu laden, eingeloggt bleiben, Tab wechseln.

## Bewusst nicht in v1

- Native Chat-Bilder / Voice
- Native Event-Anlage
- iOS-Hybrid (gleicher Plan, andere Hülle)
- Capacitor-Bridge (die alte Voll-WebView-App). Dieser Branch **ersetzt** MainActivity.
