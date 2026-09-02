# LO Community — Android Hybrid (Test)

Eigener Branch: `cursor/android-hybrid-shell-d4ba`.

Native Bottom-Bar, native Chat / Kalender / Profil. Nur der **Feed** bleibt ein WebView (FluentCommunity). Das ist der Store-Weg: echte native Screens, kein zweites Portal-WebView für Chat und Kalender.

## Tabs

| Tab | Umsetzung |
| --- | --- |
| **Feed** | WebView `https://community.orgasmic.live/portal` — Login, Beiträge, Kurse |
| **Chat** | Native Räume + Thread: Avatare, Text, Bild, Sprache, Antworten, Markieren, Löschen |
| **Kalender** | Native Monatskachel + Event-Karten, Detail mit RSVP |
| **Profil** | Native Schalter, Datenschutz, Konto löschen |

## Backend

Für den Hybrid-Test reicht **ORGASMIC App 1.1.29+** plus die bestehenden Chat-/Kalender-Plugins. Die REST-Routen sind unverändert (`/wp-json/orgasmic-chat/v1/`, `/wp-json/orgasmic-events/v1/`).

Session: Login im Feed, Cookies aus dem WebView, Nonce per `admin-ajax.php?action=orgasmic_fc_app_boot`.

## Testen

1. Codemagic **Android Debug** auf `cursor/android-hybrid-shell-d4ba`.
2. Feed → einloggen.
3. Chat → Raum, Avatar, Sprache, Bild, lange auf eine Nachricht drücken (Antworten / Markieren / Löschen).
4. Kalender → Monat blättern, Tag antippen, Event, RSVP.

## Absturz-Logs

```bash
adb logcat -d | grep -A 60 "FATAL EXCEPTION"
```
