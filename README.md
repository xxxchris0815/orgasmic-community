# ORGAMSIC Community Plugins

WordPress-Begleitplugins für [community.orgasmic.live](https://community.orgasmic.live).

Moved from `evolution-api` (`Extras/orgasmic-community`).

## ORGAMSIC Community Kalender

Plugin-Ordner: `orgasmic-fc-events/`

Eventkalender **im FluentCommunity-Portal**:

- Sichtbarkeit über Spaces (Outer Circle, Live Community, Inner Circle, …)
- RSVP (dabei / vielleicht / kann nicht), Teilnehmerliste, optional Kapazität
- Zoom Server-to-Server: Sub-Account per E-Mail wählen, Meeting wird automatisch angelegt
- Optionaler Post in den Activity Stream (im passenden Space, nicht öffentlich bei geheimen Kreisen)
- Reminder (z. B. 1 Tag / 1 Stunde vorher) als Tracker-Webhook `event.reminder`
- REST API für Create / Update / Delete / RSVP

Im Portal: Menüpunkt **Kalender** bzw. `#orgasmic-calendar`.

WP-Admin: **ORGAMSIC Kalender → Einstellungen** (Zoom, Untertitel, Erscheinungsbild, Akzentfarbe).

## ORGAMSIC FluentCommunity Tracker

Plugin-Ordner: `orgasmic-fc-tracker/`

- FluentCommunity-Core-Hooks + Kalender-Interaktionen (`event.created`, `event.rsvp`, `event.viewed`, `event.reminder`, …)
- Dashboard und optionaler JSON-Webhook
- Bunny Stream (`player.mediadelivery.net/play/...`) wird im Feed als eingebetteter Player angezeigt

Aktuelles ZIP: [`orgasmic-fc-tracker-1.1.1.zip`](https://github.com/xxxchris0815/orgasmic-community/raw/cursor/migrate-community-plugins-d4ba/orgasmic-fc-tracker-1.1.1.zip)

### Installation

```bash
cp -a orgasmic-fc-tracker \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a orgasmic-fc-events \
  /opt/community/data/wordpress/wp-content/plugins/

cd /opt/community
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-tracker
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-events
```

TEC / FluentCommunity-Events-Beta nicht parallel zum ORGAMSIC-Kalender betreiben.

Private Chats/DMs werden nicht mitgeschnitten.
