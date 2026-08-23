# ORGASMIC Community Plugins

WordPress-Begleitplugins für [community.orgasmic.live](https://community.orgasmic.live).

Moved from `evolution-api` (`Extras/orgasmic-community`).

## Plugins

| Plugin | Version | Aufgabe |
| --- | --- | --- |
| `orgasmic-fc-tracker/` | **1.2.1** | Engagement-Tracker, Dashboard, Evolution-Webhook |
| `orgasmic-fc-events/` | **1.0.4** | Kalender im Portal (RSVP, Zoom, Activity Stream) |
| `orgasmic-fc-embeds/` | **1.1.1** | Bunny-Player, Autoplay-Setting, Wiedergabe-Tracking + Webhook |
| `orgasmic-fc-chat/` | **1.0.2** | Space-Chat im Portal (Icon oben rechts, Ungelesen-Badge, REST) |

## ORGASMIC Community Kalender

Plugin-Ordner: `orgasmic-fc-events/`

Eventkalender **im FluentCommunity-Portal**:

- Sichtbarkeit über Spaces (Outer Circle, Live Community, Inner Circle, …)
- RSVP (dabei / vielleicht / kann nicht), Teilnehmerliste, optional Kapazität
- Zoom Server-to-Server: Sub-Account per E-Mail wählen, Meeting wird automatisch angelegt
- Optionaler Post in den Activity Stream (im passenden Space, nicht öffentlich bei geheimen Kreisen)
- Reminder (z. B. 1 Tag / 1 Stunde vorher) als Tracker-Webhook `event.reminder`
- REST API für Create / Update / Delete / RSVP

Im Portal: Menüpunkt **Kalender** bzw. `#orgasmic-calendar`.

WP-Admin: **ORGASMIC Kalender → Einstellungen** (Zoom, Untertitel, Erscheinungsbild, Akzentfarbe).

ZIP: [`orgasmic-fc-events-1.0.4.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-events-1.0.4.zip)

## ORGASMIC FluentCommunity Tracker

Plugin-Ordner: `orgasmic-fc-tracker/`

- FluentCommunity-Core-Hooks + Kalender-Interaktionen (`event.created`, `event.rsvp`, `event.viewed`, `event.reminder`, …)
- Dashboard und optionaler JSON-Webhook

ZIP: [`orgasmic-fc-tracker-1.2.1.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-tracker-1.2.1.zip)

## ORGASMIC Bunny Embeds

Plugin-Ordner: `orgasmic-fc-embeds/`

- `player.mediadelivery.net/play/...` wird im Feed als eingebetteter Player angezeigt
- Autoplay ein/aus unter **ORGASMIC Bunny → Einstellungen**
- Tracking: wer spielt welches Video, Position in Sekunden, max. gesehen
- Webhook: `video.play`, `video.pause`, `video.progress` (alle 15s), `video.ended`, `video.seeked`

ZIP: [`orgasmic-fc-embeds-1.1.1.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-embeds-1.1.1.zip)

Falls der iframe leer bleibt: in Bunny Stream → Allowed Domains `community.orgasmic.live` eintragen.

## ORGASMIC Chat

Plugin-Ordner: `orgasmic-fc-chat/`

Ersatz für den FluentCommunity-Pro-Chat:

- Ein Chatraum pro Space, nur für Mitglieder dieses Spaces
- Icon oben rechts im Portal, Ungelesen-Badge
- Text, Emoji, optionales Bild
- REST-API für Portal und spätere PWA/App (`/wp-json/orgasmic-chat/v1/`)

WP-Admin: **ORGASMIC Chat → Einstellungen** (Farben, Untertitel, welche Spaces Chat haben).

ZIP: [`orgasmic-fc-chat-1.0.2.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-chat-1.0.2.zip)

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

cd /opt/community
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-tracker
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-events
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-embeds
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-chat
```

TEC / FluentCommunity-Events-Beta nicht parallel zum ORGASMIC-Kalender betreiben.

Private Chats/DMs werden nicht mitgeschnitten.
