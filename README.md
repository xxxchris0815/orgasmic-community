# ORGASMIC Community Plugins

WordPress-Begleitplugins für [community.orgasmic.live](https://community.orgasmic.live).

Moved from `evolution-api` (`Extras/orgasmic-community`).

## Plugins

| Plugin | Version | Aufgabe |
| --- | --- | --- |
| `orgasmic-fc-tracker/` | **1.2.1** | Engagement-Tracker, Dashboard, Evolution-Webhook |
| `orgasmic-fc-events/` | **1.0.12** | Kalender im Portal (RSVP, Zoom, Markieren/Duplizieren/Löschen mobil+Desktop) |
| `orgasmic-fc-embeds/` | **1.2.14** | Video-Player im Feed, kompakter Chip nur in Angesagte Beiträge |
| `orgasmic-fc-chat/` | **1.1.16** | Space-Chat (letzte 40 Nachrichten, ältere per Scroll) |
| `orgasmic-fc-app/` | **1.1.17** | PWA, Web Push, Mitglieder-Zuordnung, Homescreen-Logo, Push/E-Mail am Beitrag |

## ORGASMIC Community Kalender

Plugin-Ordner: `orgasmic-fc-events/`

Eventkalender **im FluentCommunity-Portal**:

- Sichtbarkeit über Räume (Kinder von Community/Training, keine Kurse/Gruppen-Links)
- Overlay öffnet sofort aus dem Cache, Events werden im Hintergrund nachgeladen
- RSVP (dabei / vielleicht / kann nicht), Teilnehmerliste, optional Kapazität
- Zoom Server-to-Server: Sub-Account per E-Mail wählen, Meeting wird automatisch angelegt
- Optionaler Post in den Activity Stream (im passenden Space, nicht öffentlich bei geheimen Kreisen)
- Event-Unterhaltung im Kalender (FluentCommunity-Kommentare, wie im Feed/Kurs)
- Reminder (z. B. 1 Tag / 1 Stunde vorher) als Tracker-Webhook `event.reminder`
- REST API für Create / Update / Delete / Duplicate / RSVP
- Admins: Events markieren (Handy: langes Drücken, PC: Kästchen oder Strg/Cmd+Klick), dann duplizieren (+7 Tage) oder löschen

Im Portal: Menüpunkt **Kalender** bzw. `#orgasmic-calendar`.

WP-Admin: **ORGASMIC Kalender → Einstellungen** (Zoom, Untertitel, Erscheinungsbild, Akzentfarbe).

ZIP: [`orgasmic-fc-events-1.0.12.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-events-1.0.12.zip)

## ORGASMIC FluentCommunity Tracker

Plugin-Ordner: `orgasmic-fc-tracker/`

- FluentCommunity-Core-Hooks + Kalender-Interaktionen (`event.created`, `event.rsvp`, `event.viewed`, `event.reminder`, …)
- Dashboard und optionaler JSON-Webhook

ZIP: [`orgasmic-fc-tracker-1.2.1.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-tracker-1.2.1.zip)

## ORGASMIC Bunny Embeds

Plugin-Ordner: `orgasmic-fc-embeds/`

- Klick auf **Video** im Composer öffnet den nativen Dateidialog (Handy + Desktop) und lädt zu Bunny Stream; der Player-Link landet automatisch im Beitrag
- Handy / PWA / Capacitor laden in 1-MB-Stücken über WordPress (nicht direkt per TUS zu Bunny)
- Der FluentCommunity-oEmbed-Dialog wird übersprungen
- `player.mediadelivery.net/play/...` wird im Feed und im Raum als offizieller Bunny-iframe angezeigt
- In „Angesagte Beiträge“ nur der **Video**-Chip, ohne nackte Player-URL
- Autoplay ein/aus unter **ORGASMIC Bunny → Einstellungen**
- Tracking: wer spielt welches Video, Position in Sekunden, max. gesehen
- Webhook: `video.play`, `video.pause`, `video.progress` (alle 15s), `video.ended`, `video.seeked`

ZIP: [`orgasmic-fc-embeds-1.2.14.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-embeds-1.2.14.zip)

Falls der iframe leer bleibt: in Bunny Stream → Allowed Domains `community.orgasmic.live` eintragen.

## ORGASMIC Chat

Plugin-Ordner: `orgasmic-fc-chat/`

Ersatz für den FluentCommunity-Pro-Chat:

- Ein Chatraum pro Space, nur für Mitglieder dieses Spaces
- Icon oben rechts im Portal, Ungelesen-Badge
- Layout wie WhatsApp: eigene Nachrichten rechts, andere links
- Text, Emoji, Bild, Sprachnachricht (max. 90 Sekunden, WebM/Opus)
- Nachrichten markieren: Kreis oben rechts im Thread, Handy langes Drücken, PC Rechtsklick — Scroll bleibt
- REST-API für Portal und Capacitor (`/wp-json/orgasmic-chat/v1/`)
- Thread lädt die **letzten 40** Nachrichten; ältere kommen beim Hochscrollen
- Offline: letzte Räume und Nachrichten im localStorage (kein REST-Cache im Service Worker)
- In einer Capacitor-App: natives Mikro (`capacitor-voice-recorder`) und Kamera (`@capacitor/camera`)

WP-Admin: **ORGASMIC Chat → Einstellungen** (Farben, Untertitel, welche Spaces Chat haben).

ZIP: [`orgasmic-fc-chat-1.1.16.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-chat-1.1.16.zip)

## ORGASMIC App (PWA + Push)

Plugin-Ordner: `orgasmic-fc-app/`

Kein zweites Native-Frontend. Das FluentCommunity-Portal wird zur App:

1. **Jetzt:** PWA (Homescreen, Service Worker Cache, Web Push)
2. **Stores:** Capacitor um dieselbe URL — Token an `POST /wp-json/orgasmic-app/v1/push/token`, Versand über Firebase wenn der Service Account im Admin liegt

Push geht an Mitglieder des jeweiligen Spaces **und an WordPress-Admins** (die sonst trotz Admin-Rolle nicht in `fcom_space_user` stehen). Format: **Raumname · Art** (Chat / Beitrag / Kommentar / Event / Ankündigung) plus Autor und Text — ohne generisches „Kreis“ oder „Termin“. Jedes Mitglied kann Chat / Beiträge / Kommentare / Events über **Profil → Benachrichtigungen** abschalten.

Räume, Kurse und Gruppen einem Konto zuordnen: **ORGASMIC App → Mitglieder**, oder per API:

```
GET  /wp-json/orgasmic-app/v1/spaces
GET  /wp-json/orgasmic-app/v1/members/{id}/spaces
POST /wp-json/orgasmic-app/v1/members/{id}/spaces
Header: X-Orgasmic-Key: <Kalender-API-Key>
Body: { "space_ids": [1, 2, 3], "mode": "set" }
```

`mode: "add"` ergänzt, ohne andere Spaces zu entfernen.

Admins sehen im Beitrags-Composer zwei Häkchen: **Per Push an alle Mitglieder senden** und **Per E-Mail an alle Mitglieder senden**. Empfänger sind nur Leute, die den Beitrag sehen dürfen (Raummitglieder bzw. Community-Feed). Geheime Kreise werden nicht nach außen geleakt. E-Mails laufen über `wp_mail` (Minute-Queue).

WP-Admin: **ORGASMIC App**. Unter **Push prüfen** ein Mitglied suchen (z. B. Alexandra): Token, erlaubte Arten, letzte Queue-Zeilen inkl. Firebase-Fehler, Test-Push auf ihr Gerät. PHP 8.2+ für Web-Push (`openssl_pkey_derive`). Firebase-JSON nur für Store-Apps. Unter **Geräte mit App-Push** steht, wessen Handy ein FCM-Token gespeichert hat.

ZIP: [`orgasmic-fc-app-1.1.17.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-app-1.1.17.zip)

### Capacitor / Play Store (Android zuerst)

Ordner `orgasmic-native/`. Die App lädt FluentCommunity unter `https://community.orgasmic.live/portal`. Öffentlicher Name: **LO Community** (kein explizites Store-Wording). Push über Firebase.

Schritt für Schritt (Firebase, Play Console, Codemagic):  
[`orgasmic-native/ANLEITUNG-ANDROID.md`](orgasmic-native/ANLEITUNG-ANDROID.md)

```bash
cd orgasmic-native
npm ci
python3 scripts/generate-branding.py
npx cap sync
```

Die Website erkennt `window.Capacitor` selbst: Push-Token, Kamera, Sprachnachricht. Firebase-Service-Account unter **ORGASMIC App** einfügen, sonst bleiben Store-Tokens liegen und nur Browser-Push geht.

### Installation

```bash
cp -a orgasmic-fc-tracker \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a orgasmic-fc-events \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a orgasmic-fc-embeds \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a orgasmic-fc-chat \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a orgasmic-fc-app \
  /opt/community/data/wordpress/wp-content/plugins/

cd /opt/community
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-tracker
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-events
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-embeds
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-chat
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-app
```

TEC / FluentCommunity-Events-Beta nicht parallel zum ORGASMIC-Kalender betreiben.

Private Chats/DMs werden nicht mitgeschnitten.
