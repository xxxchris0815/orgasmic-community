# ORGAMSIC Community Plugins

WordPress-Begleitplugins für [community.orgasmic.live](https://community.orgasmic.live). Kein Evolution-API-Code.

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

WP-Admin: **ORGAMSIC Tracker → Kalender / Zoom** (Account ID, Client ID, Secret).

## ORGAMSIC FluentCommunity Tracker

Plugin-Ordner: `orgasmic-fc-tracker/`

- FluentCommunity-Core-Hooks + Kalender-Interaktionen (`event.created`, `event.rsvp`, `event.viewed`, `event.reminder`, …)
- Dashboard und optionaler JSON-Webhook

### Installation

```bash
cp -a Extras/orgasmic-community/orgasmic-fc-tracker \
  /opt/community/data/wordpress/wp-content/plugins/
cp -a Extras/orgasmic-community/orgasmic-fc-events \
  /opt/community/data/wordpress/wp-content/plugins/

cd /opt/community
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-tracker
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-events
```

TEC / FluentCommunity-Events-Beta nicht parallel zum ORGAMSIC-Kalender betreiben.

Private Chats/DMs werden nicht mitgeschnitten.
