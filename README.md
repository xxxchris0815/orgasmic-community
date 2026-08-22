# ORGAMSIC Community Plugins

WordPress-Begleitplugins für [community.orgasmic.live](https://community.orgasmic.live). Kein Evolution-API-Code.

## ORGAMSIC FluentCommunity Tracker

Plugin-Ordner: `orgasmic-fc-tracker/`

### Was es macht

- Hört FluentCommunity-**Core-Hooks** (kein Fork, kein Core-Patch)
- Speichert Lektionsfortschritt und Community-Engagement lokal
- Zeigt im WP-Admin ein Dashboard (Mitglieder, Kurse, Ereignisprotokoll)
- Optional: JSON-Webhook (gleicher Stil wie der Bunny Watch Tracker)

### Installation auf dem Community-Server

```bash
# Plugin nach WordPress kopieren
cp -a Extras/orgasmic-community/orgasmic-fc-tracker \
  /opt/community/data/wordpress/wp-content/plugins/

# Im WP-Container aktivieren
cd /opt/community
docker compose --profile tools run --rm wpcli plugin activate orgasmic-fc-tracker
```

Oder ZIP im WP-Admin unter **Plugins → Installieren → Plugin hochladen**.

Danach: **ORGAMSIC Tracker → Einstellungen** (Webhook-URL, Secret, Event-Gruppen).

### Getrackte Events

| Gruppe | Events |
| --- | --- |
| Kurse | enrolled, lesson_completed, lesson_incomplete, topic_completed, completed, progress_reset, student_left |
| Feed | created, updated, deleted, mentioned, survey_vote |
| Kommentare | added, updated, deleted |
| Reaktionen | feed/comment add + remove |
| Spaces | joined, join_requested, left, created |
| Mitglieder | deactivated, reactivated, points, level-up (Pro), follow (Pro) |

Private Chats/DMs werden **nicht** mitgeschnitten — FluentCommunity liefert dafür keine stabilen öffentlichen Hooks.

Der Bunny-Watch-Tracker bleibt separat (Videoposition). Dieses Plugin erfasst den **offiziellen Lektionsabschluss** in FluentCommunity.
