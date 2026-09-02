# LO Community — Android Hybrid (Test)

Eigener Branch: `cursor/android-hybrid-shell-d4ba`. Unten eine native Tab-Leiste. Feed / Chat / Kalender sind die **gleichen Web-UIs** wie im Portal, damit Features nicht auseinanderlaufen.

## Was du siehst

| Tab | Was passiert |
| --- | --- |
| **Feed** | WebView `https://community.orgasmic.live/portal` (FluentCommunity). Login bleibt hier. |
| **Chat** | Dieselbe Chat-Oberfläche wie im Web: Avatare, Bilder, Sprache, Markieren, Löschen, Antworten |
| **Kalender** | Dieselbe Kalender-Oberfläche wie im Web: Monat, Event-Karten, RSVP, Details |
| **Profil** | Native Schalter, Datenschutz, Konto löschen |

Die untere Icon-Leiste ist nativ (weiße Leiste, dieselben Chat-/Kalender-Icons wie im Web). Die FluentCommunity-Tab-Leiste im WebView bleibt ausgeblendet.

## Backend — was nötig ist

1. **ORGASMIC App 1.1.30**
2. **ORGASMIC Chat 1.1.19**
3. **ORGASMIC Community Kalender 1.0.13**

Ohne die drei Updates fehlen Vollbild-Overlay, Sprache/Kalender-Grid oder der Close-Button fällt auf den Feed zurück.

ZIPs auf diesem Branch:

- [`orgasmic-fc-app-1.1.30.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/android-hybrid-shell-d4ba/orgasmic-fc-app-1.1.30.zip)
- [`orgasmic-fc-chat-1.1.19.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/android-hybrid-shell-d4ba/orgasmic-fc-chat-1.1.19.zip)
- [`orgasmic-fc-events-1.0.13.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/android-hybrid-shell-d4ba/orgasmic-fc-events-1.0.13.zip)

Session: Login im Feed, Cookies aus dem WebView, Nonce per `admin-ajax.php?action=orgasmic_fc_app_boot`. Push unverändert.

## Testen

1. Die drei Plugins auf den Server.
2. Codemagic: Workflow **Android Debug** auf Branch `cursor/android-hybrid-shell-d4ba`.
3. APK installieren, alte App-Daten ggf. löschen.
4. **Feed** → einloggen.
5. **Chat** → Raum, Avatar, Sprache, Bild, Markieren/Löschen.
6. **Kalender** → Monatsraster, Event öffnen, RSVP.
7. **Profil** → Schalter.

Sprachnachricht: Android fragt nach Mikrofon. Wenn die Aufnahme nicht startet, Berechtigung in den Systemeinstellungen prüfen.

## Absturz-Logs (Logcat)

```bash
adb logcat -d -s AndroidRuntime:E LOCommunity:E chromium:E
```

Oder:

```bash
adb logcat -d | grep -A 60 "FATAL EXCEPTION"
```

## Bewusst nativ (nicht Web)

- Untere Tab-Leiste
- Profil (Mitteilungen, Konto löschen)
