# LO Community — Android (Codemagic)

Die App ist keine zweite Website. Capacitor öffnet FluentCommunity unter `https://community.orgasmic.live/portal`. Push läuft über Firebase. Gebaut wird online bei Codemagic.

## Öffentliche Namen (Store + Homescreen)

Im Store und unter dem App-Icon **keine** sexuellen oder expliziten Wörter (auch nicht die interne Marke).

| Wo | Text |
| --- | --- |
| App-Name auf dem Handy | **LO Community** |
| Play-Store-Titel (max. 30 Zeichen) | **LO Community** |
| Kurzbeschreibung | Mitglieder-Community mit Chat, Beiträgen und Kalender |
| Entwicklername | Firmenname oder **LO Community** — ohne Sex-Wörter |
| Android-Paketname (technisch) | `live.lo.community` — so in Firebase und Play anlegen |

Nicht in Titel, Kurztext, Screenshots, Icon-Schrift: orgasm, orgasmic, sex, xxx, nackt, escort, fetish usw.

Reihenfolge einhalten. Android zuerst. iOS kommt später mit demselben Firebase-Projekt.

---

**Start-URL:** `https://community.orgasmic.live/portal` (FluentCommunity, nicht die WordPress-Startseite). Nach einer URL-Änderung muss die APK neu gebaut und installiert werden.

## 0. Was schon im Repo liegt

| Datei | Zweck |
| --- | --- |
| `orgasmic-native/` | Capacitor-Hülle (Android + iOS vorbereitet) |
| `codemagic.yaml` | Workflows: Debug-APK, Keystore, Release, Play-Upload |
| WordPress → **ORGASMIC App** | Feld für Firebase Service-Account (Push vom Server) |

Workflows in Codemagic:

1. **Android Debug (testen)** — APK zum Installieren, ohne Play Store  
2. **Android Keystore einmal erzeugen** — Signing-Schlüssel, nur einmal  
3. **Android Release (AAB + APK)** — signierte Store-Dateien  
4. **Android zu Play (Internal)** — lädt die AAB in den internen Test-Track

---

## 1. Google-Konto und Play Console

1. Öffne [https://play.google.com/console](https://play.google.com/console) mit dem Google-Konto, das die App besitzen soll.
2. Falls noch kein Entwicklerkonto: **Registrieren**, einmalig **25 USD** zahlen, Identität verifizieren. Das kann 1–2 Tage dauern. Warte, bis das Konto **aktiv** ist.
3. **Alle Apps erstellen** → **App erstellen**.
4. Ausfüllen:
   - App-Name: `LO Community`
   - Standardsprache: Deutsch
   - App oder Spiel: **App**
   - Kostenlos oder kostenpflichtig: **Kostenlos**
   - Richtlinien und US-Exportregeln akzeptieren
5. **App erstellen**.
6. Falls du schon eine Play-App mit Paket `live.orgasmic.community` angelegt oder hochgeladen hast: **neue App** `LO Community` erstellen (Paketnamen in Play kann man nicht umbenennen). Die alte als Entwurf liegen lassen oder löschen, wenn Play das anbietet.
7. Notiere dir: die App existiert, ist aber noch **Entwurf**. Listing (Name, Texte, Icon) änderst du später in Abschnitt 7 — in der Play Console, nicht in Codemagic.

---

## 2. Firebase-Projekt (Push)

Zwei verschiedene Namen — nicht vermischen:

| Was | Beispiel | Punkte? |
| --- | --- | --- |
| **Projekt-ID** (Firebase-Konto) | `live-orgasmic-community` | Bindestriche, **keine Punkte** — so ist das richtig |
| **Android-Paketname** (die App) | `live.lo.community` | **Punkte**, keine Bindestriche |

Die Projekt-ID darfst du nicht mit Punkten anlegen. Den Android-Paketnamen **musst** du mit Punkten eintragen. Das Feld heißt beim Hinzufügen der Android-App „Android-Paketname“ / „Android package name“, nicht Projekt-ID.

1. Öffne [https://console.firebase.google.com](https://console.firebase.google.com) mit **demselben Google-Konto**.
2. **Projekt hinzufügen** → Anzeigename z. B. `LO Community`. Die erzeugte Projekt-ID darf Bindestriche haben (z. B. `live-orgasmic-community`).
3. Google Analytics kannst du anlassen oder überspringen.
4. **Projekt erstellen**.

### 2a. Android-App in Firebase

1. Auf der Projektübersicht: **Android** (Icon) — nicht das Projekt umbenennen, eine **App** zum Projekt hinzufügen.
2. Feld **Android-Paketname** **genau**: `live.lo.community`  
   (Punkte sind hier erlaubt und nötig. Nicht die Firebase-Projekt-ID eintragen.)
3. App-Spitzname: `LO Community Android`
4. Debug-Signatur-SHA kannst du leer lassen.
5. **App registrieren**.
6. **`google-services.json` herunterladen**. Datei aufheben. Nicht ins öffentliche Git legen.
7. Die nächsten Firebase-Schritte „ins SDK einfügen“ überspringen — Capacitor macht das.

Falls schon eine Android-App mit `live.orgasmic.community` existiert: in Firebase **Einstellungen → Deine Apps** diese App entfernen (oder ignorieren) und **neu** mit Paket `live.lo.community` anlegen. Deren neue `google-services.json` in Codemagic als `GOOGLE_SERVICES_JSON` ersetzen.

### 2b. Cloud Messaging prüfen

1. Firebase → **⚙️ Projekteinstellungen** → Tab **Cloud Messaging**.
2. Es braucht kein extra API-Key mehr. FCM v1 läuft über das Dienstkonto.

### 2c. Dienstkonto für WordPress (Push vom Server)

1. Weiter in **⚙️ Projekteinstellungen** → Tab **Dienstkonten**.
2. **Neuen privaten Schlüssel generieren** → **JSON** → erzeugen.
3. Datei aufheben (z. B. `firebase-adminsdk.json`). Das ist der Schlüssel, mit dem WordPress an Android (und später iPhone) sendet.
4. WordPress-Admin → **ORGASMIC App** → **Capacitor / Firebase** → den **gesamten JSON-Inhalt** ins Textfeld einfügen → Speichern.
5. Die Seite muss danach **„hinterlegt“** anzeigen.

Ohne diesen Schritt bekommt die App ein Token, WordPress kann aber nichts zustellen.

---

## 3. Codemagic mit GitHub verbinden

1. Öffne [https://codemagic.io](https://codemagic.io) → mit **GitHub** einloggen.
2. GitHub darf auf das Repo `xxxchris0815/orgasmic-community` zugreifen (Authorize).
3. **Add application** → GitHub → Repo **orgasmic-community** wählen.
4. Project type: **Ionic Capacitor** (oder Other).
5. Oben **Check for configuration file** / Branch `cursor/migrate-community-plugins-d4ba` oder später `main` scannen. `codemagic.yaml` muss gefunden werden.

Wenn die yaml erst auf dem Feature-Branch liegt: in Codemagic diesen Branch scannen oder nach dem Merge `main` nutzen.

---

## 4. `google-services.json` bei Codemagic hinterlegen

Das ist die Android-Datei aus Firebase (**Deine Apps**), **nicht** der private Schlüssel unter Dienstkonten.

Mehrzeiliges JSON zerbricht in Codemagic oft den Build. Am sichersten: **eine Zeile**.

1. `google-services.json` in einem Editor öffnen.
2. Alle Zeilenumbrüche entfernen, sodass der Text mit `{` beginnt und mit `}` endet (eine lange Zeile).
3. Codemagic → App → **Environment variables** (oder Teams → Global variables).
4. Variable:
   - Name: `GOOGLE_SERVICES_JSON`
   - Value: diese **eine** Zeile
   - **Secret** anhaken
   - Gruppe: **`firebase`** (Name exakt so)
5. Speichern. Alte Variable gleichen Namens vorher löschen, falls sie mehrzeilig war.
6. **Android Debug (testen)** neu starten.

Im Log bei **Write google-services.json** muss stehen: `google-services.json geschrieben … package live.lo.community`.

---

## 5. Erster Test: Debug-APK (noch ohne Play Store)

1. In Codemagic die App öffnen.
2. Workflow **Android Debug (testen)** wählen.
3. Branch wählen (der mit `orgasmic-native/` und `codemagic.yaml`).
4. **Start new build**.
5. Warten (oft 5–12 Minuten).
6. Nach Erfolg: unter **Artifacts** die `.apk` herunterladen.
7. Datei aufs Android-Handy (USB, Drive, Telegram an dich selbst).
8. Auf dem Handy: **unbekannte Quellen** / „aus dieser Quelle installieren“ erlauben.
9. App öffnen → einloggen → Push erlauben, wenn gefragt.

Wenn die Community lädt, ist die Hülle in Ordnung.  
Wenn eine Test-Push aus WP-Admin (**ORGASMIC App** → Test-Push) auf dem Handy ankommt, sind Firebase + WordPress in Ordnung.

Debug-APK nicht in den Play Store laden.

**Build-Fehler `invalid source release: 21`:** Capacitor 7 braucht JDK 21, Codemagic startet standardmäßig mit JDK 17. In `codemagic.yaml` steht dafür `ubuntu: 24.04` und `java: 21`. Den Workflow **Android Debug (testen)** auf dem aktuellen Feature-Branch nochmal starten — mehr Log-Zeilen brauchst du für diesen Fehler nicht.

**Build bricht bei google-services.json ab:** Die Variable war fast immer **mehrzeilig**. Inhalt als **eine Zeile** erneut speichern (siehe Abschnitt 4), Branch mit dem aktuellen `codemagic.yaml` bauen.

---

## 6. Keystore einmal erzeugen (ohne eigenen PC)

Der Keystore ist der **Unterschriften-Stift** der App. Play Store akzeptiert Updates nur mit **demselben** Stift. Einmal erzeugen, **für immer** behalten. Verloren = neue Play-App, alle Installationen tot.

Du brauchst **kein** Android Studio. Codemagic erzeugt die Datei. Danach lädst du sie in Codemagic als Signing-Identity hoch, damit der Workflow **Android Release** sie findet.

Namen müssen **exakt** so heißen (Copy-Paste):

| Was | Wert | Wo |
| --- | --- | --- |
| Environment-Gruppe | `android_keystore` | Variablen (Schritt A) |
| Variable | `KEYSTORE_PASSWORD` | Variablen (Schritt A) |
| Key alias | `orgasmic` | Signing Identity (Schritt D) |
| Reference name | `orgasmic_android` | Signing Identity (Schritt D) |
| Dateiname | `orgasmic-release.keystore` | Artifact nach dem Build |

Das Passwort für Keystore und Key ist **dasselbe** (`KEYSTORE_PASSWORD`).

Reihenfolge: **6A → 6B → 6C → 6D**. Nicht überspringen. 6D ist der Schritt, den die meisten verpassen — ohne ihn schlägt **Android Release** fehl, obwohl die Datei schon existiert.

### Wo klicke ich? (Codemagic-Karte)

Codemagic hat **zwei verschiedene Orte**. Environment-Variablen sitzen an der **App**, der Keystore-Upload sitzt am **Team**. Das ist Absicht.

Nach dem Login (`https://codemagic.io`):

1. **Apps** (linke Leiste oder Startseite) → die App mit dem GitHub-Repo **orgasmic-community** öffnen.  
   Dort oben siehst du Tabs wie **Builds**, **codemagic.yaml**, **Environment variables**, manchmal ein Zahnrad.  
   **Hier machst du 6A, 6B und 6E.**
2. **Teams** (linke Leiste, neben Apps — nicht in der App selbst). Team anklicken (oft nur **Personal** / dein Name). Dann **Team settings** / Einstellungen.  
   Darin: **codemagic.yaml settings** → **Code signing identities**.  
   **Hier machst du 6D.**  
   Siehst du kein „Teams“: oben rechts auf dein Profilbild → **Team settings**.

| Schritt | Ort | Was du tust |
| --- | --- | --- |
| 6A | **App** → Tab **Environment variables** | Passwort als Secret, Gruppe `android_keystore` |
| 6B | **App** → **Start new build** | Workflow **Android Keystore einmal erzeugen** |
| 6C | Build → **Artifacts** + dein Rechner | `orgasmic-release.keystore` zweimal sichern, nie ins Git |
| 6D | **Teams** → Team settings → **Code signing identities** → Android | Datei hochladen, Reference `orgasmic_android` |
| 6E | **App** → **Start new build** | Workflow **Android Release (AAB + APK)** |

---

### 6A. Passwort in Codemagic anlegen

Das Passwort ist **nicht** der Firebase-JSON und **nicht** `GOOGLE_SERVICES_JSON`. Nur ein geheimes Passwort für den Keystore.

1. [codemagic.io](https://codemagic.io) öffnen, einloggen.
2. Die App **orgasmic-community** anklicken (nicht Teams zuerst — direkt die App).
3. Oben die Tabs: **Builds** | **codemagic.yaml** | **Environment variables** | …  
   Tab **Environment variables** öffnen.  
   (Falls du nur Teams siehst: **Teams** → dein Team / Personal Account → **Global variables and secrets**. Funktioniert genauso, Gruppe muss trotzdem `android_keystore` heißen.)
4. Formular **Add variable** / Variable hinzufügen:

   | Feld in der UI | Eintrag |
   | --- | --- |
   | **Variable name** | `KEYSTORE_PASSWORD` |
   | **Variable value** | ein langes Passwort, z. B. 20+ Zeichen, Mix aus Buchstaben/Zahlen. **Kein** Leerzeichen am Anfang/Ende. |
   | **Select group** / Group | `android_keystore` eintippen. Wenn die Gruppe noch nicht existiert: Enter bzw. **Create group** `android_keystore`. |
   | **Secret** | Haken **an** (Schloss / „Secret“) |

5. **Add** / **Add variable** klicken.
6. Falls Codemagic fragt, **für welche Apps** die Gruppe gilt: **orgasmic-community** anhaken. Eine nur globale Variable ohne App-Zuordnung sieht der Workflow nicht.
7. In der Liste muss stehen: Name `KEYSTORE_PASSWORD`, Gruppe `android_keystore`, Secret ja. Der Wert ist danach nicht mehr lesbar — deshalb **sofort** ins Passwort-Safe (1Password, Bitwarden, Papier im Tresor). Ohne dieses Passwort kannst du den Keystore später nicht mehr verwenden.

Wenn der Workflow später sagt `KEYSTORE_PASSWORD fehlt`: Gruppe heißt nicht genau `android_keystore` (Tippfehler, Großbuchstaben), die Variable liegt in einer anderen Gruppe (`firebase` z. B.), oder die Gruppe ist der App nicht zugeordnet.

---

### 6B. Workflow starten (Keystore erzeugen)

1. In derselben App (nicht Teams) oben **Start new build** — großer Button, oft rechts.
2. Es öffnet sich ein Dialog mit zwei wichtigen Feldern:
   - **Workflow:** Dropdown aufklappen. Es müssen vier Einträge aus der `codemagic.yaml` stehen. Wähle **Android Keystore einmal erzeugen** (nicht Debug, nicht Release).
   - **Branch:** `cursor/migrate-community-plugins-d4ba` — solange die yaml nur auf diesem Branch liegt. `main` hat sie ggf. noch nicht.
3. **Start new build** / Start bestätigen.
4. Warte, bis der Build grün ist (meist unter einer Minute). Rot → Log öffnen, nach `KEYSTORE_PASSWORD` suchen → zurück zu 6A.
5. In der Build-Seite nach unten zu **Artifacts**. Datei **`orgasmic-release.keystore`** herunterladen.  
   Sieht unscheinbar aus, oft ein paar KB. Das **ist** der Schlüssel. Ohne Download ist der Schlüssel weg, sobald Codemagic das Artifact löscht.

Schlägt der Build fehl mit der Meldung zu `KEYSTORE_PASSWORD`: zurück zu 6A.

---

### 6C. Datei sichern (jetzt, bevor du irgendwas löschst)

Codemagic lässt den hochgeladenen Keystore **nicht** wieder herunterladen. Nur diese eine Artifact-Datei zählt.

1. `orgasmic-release.keystore` auf den Rechner speichern.
2. Kopie 1: Passwort-Manager als Anhang, oder verschlüsselter USB.
3. Kopie 2: zweiter Ort (z. B. anderer Cloud-Ordner, nicht das öffentliche GitHub-Repo).
4. **Nicht** ins Git, **nicht** in Slack, **nicht** in die Play-Console als Text.

Diesen Workflow **nie wieder** starten. Ein zweiter Lauf erzeugt einen **anderen** Schlüssel — Play nimmt den nicht für dieselbe App.

---

### 6D. Keystore als Code-Signing-Identity hochladen

Erst danach kann **Android Release** signieren. Environment-Variable allein reicht nicht — Release liest `android_signing: orgasmic_android`.

1. Die App **verlassen**. Links in der Leiste (nicht in den App-Tabs) auf **Teams** klicken.  
   Alternative: oben rechts Profilbild → **Team settings**.
2. Dein Team anklicken. Als Einzelperson oft **Personal Account** / dein Name.
3. **Team settings** / Team-Einstellungen (Zahnrad oder der Team-Name).
4. Abschnitt **codemagic.yaml settings** (manchmal unter **Settings** → **codemagic.yaml**).  
   Alternative UI: direkt **Code signing identities** in den Team settings, ohne den yaml-Unterpunkt.
5. **Code signing identities** öffnen.
6. Tab **Android keystores** (nicht iOS Certificates).
7. **Add keystore** / **Choose a file** / Datei in den Rahmen ziehen: die heruntergeladene `orgasmic-release.keystore` vom Rechner (nicht `google-services.json`).
8. Felder ausfüllen — Copy-Paste, nichts „ähnlich“:

   | Feld in der UI | Wert |
   | --- | --- |
   | **Keystore password** | dasselbe wie `KEYSTORE_PASSWORD` aus 6A |
   | **Key alias** | `orgasmic` |
   | **Key password** | **dasselbe** Passwort nochmal (nicht leer lassen) |
   | **Reference name** | `orgasmic_android` |

9. **Add keystore**.
10. In der Liste erscheint ein Eintrag `orgasmic_android` mit Ablaufdatum (sehr weit in der Zukunft). Fertig.

Hochladen braucht **Team-Admin**. Wenn der Button fehlt: mit dem Konto einloggen, das das Team angelegt hat.

Häufige Fehler in diesem Schritt:

- Reference name `orgasmic` oder `android` statt `orgasmic_android` → Release-Build findet den Keystore nicht.
- Alias `ORGASMIC` oder `key0` statt `orgasmic` → Signatur schlägt fehl.
- Anderes Passwort als in 6A → Codemagic nimmt die Datei nicht an oder der Release-Build bricht ab.
- Die `.keystore` mit der `google-services.json` verwechselt.

---

### 6E. Kontrolle: Release bauen

1. Zurück zur App **orgasmic-community**.
2. **Start new build** → Workflow **Android Release (AAB + APK)** → Branch `cursor/migrate-community-plugins-d4ba`.
3. Gruppe `firebase` muss weiterhin `GOOGLE_SERVICES_JSON` haben (wie beim Debug).
4. Bei Erfolg: Artifacts **`.aab`** (für Play) und **`.apk`** (zum Selbstinstallieren).
5. Debug-App auf dem Handy **deinstallieren**, dann die Release-APK installieren (andere Signatur, sonst „App nicht installiert“).

Wenn der Release-Build sagt, Signing / keystore / `orgasmic_android` fehlt: 6D, Reference name nochmal prüfen.

Danach weiter mit Abschnitt 7 (Play-Listing) und AAB in den internen Test hochladen.

---

## 7. Play Console fertigmachen (bevor der Store die AAB annimmt)

Das Play-Listing liegt **nicht** in Codemagic und **nicht** in GitHub. Es ist die öffentliche Store-Seite in der **Google Play Console**. Codemagic baut nur die AAB. Texte, Icon, Screenshots änderst du immer in Play.

Ohne Listing + Datenschutz + App-Inhalt bleibt jeder Upload im **Entwurf**.

### Wo klicke ich? (Play-Console-Karte)

1. Browser: [https://play.google.com/console](https://play.google.com/console) — mit dem Google-Konto, das die App besitzt (dasselbe wie in Abschnitt 1).
2. Nicht in Firebase, nicht in Codemagic.
3. Auf der Startseite die App **LO Community** anklicken.  
   Siehst du die App nicht: links **Alle Apps** / **All apps**.
4. Du bist jetzt **in der App**. Links eine Leiste. Die Labels wechseln je nach Sprache der Console (Zahnrad unten links → Sprache). Deutsch und Englisch:

| Was du willst | Links in der Leiste (Deutsch) | Englisch |
| --- | --- | --- |
| **Listing** (Name, Texte, Icon, Screenshots) | **Mehr Nutzer gewinnen** → **App-Präsenz im Play Store** → **Store-Haupteintrag** | Grow users → Store presence → Main store listing |
| Alternative, oft kürzer | **Dashboard** → Karte / Aufgabe **Store-Eintrag festlegen** | Dashboard → Set up your store listing |
| Kategorie, E-Mail, Website | **Mehr Nutzer gewinnen** → **App-Präsenz im Play Store** → **Play Store-Einstellungen** | Grow users → Store presence → Store settings |
| Datenschutz-URL | **Richtlinie** → **Datenschutzrichtlinie** | Policy → Privacy policy |
| Datensicherheit-Fragebogen | **Richtlinie** → **Datensicherheit** | Policy → Data safety |
| UGC / Zielgruppe | **Richtlinie** → **App-Inhalt** | Policy → App content |
| AAB hochladen (später) | **Testen und veröffentlichen** → **Testen** → **Interner Test** | Test and release → Testing → Internal testing |

Ältere Console sagt statt „Mehr Nutzer gewinnen“ oft **Wachstum**, statt „Store-Haupteintrag“ oft **Hauptstore-eintrag** / **Store-Eintrag**. Inhalt ist derselbe Bildschirm.

Paketname `live.lo.community` steht **nicht** auf der Listing-Seite. Den setzt Play beim **ersten AAB-Upload**. Listing = nur das, was Menschen im Store lesen.

---

### 7a. Store-Haupteintrag (das eigentliche Listing)

1. Links: **Mehr Nutzer gewinnen** (oder **Wachstum**) aufklappen.
2. **App-Präsenz im Play Store** / **Store-Präsenz** aufklappen.
3. **Store-Haupteintrag** / **Hauptstore-eintrag** anklicken.  
   Nicht „Benutzerdefinierte Store-Einträge“ — das ist extra, brauchen wir nicht.
4. Oben auf der Seite die **Sprache** prüfen: **Deutsch** (bzw. die Standardsprache, die du beim App-Erstellen gewählt hast). Falsche Sprache = du editierst eine Übersetzung, die kaum jemand sieht.
5. Abschnitt **App-Details** / **App details** — Copy-Paste:

   | Feld in der UI | Wert | Limit |
   | --- | --- | --- |
   | **App-Name** | `LO Community` | 30 Zeichen |
   | **Kurze Beschreibung** / Short description | `Mitglieder-Community mit Chat, Beiträgen und Kalender.` | 80 Zeichen |
   | **Vollständige Beschreibung** / Full description | Text unten | 4000 Zeichen |

   Ausführliche Beschreibung zum Einfügen:

   ```
   LO Community ist der Treffpunkt für Mitglieder: Beiträge im Feed, Chat in den Räumen und ein gemeinsamer Kalender. Push-Benachrichtigungen für neue Nachrichten und Termine. Die Nutzung setzt ein bestehendes Konto voraus.
   ```

   In **keinem** dieser Felder und auf **keinem** Bild: orgasm, orgasmic, sex, xxx, nackt, escort, fetish, interne Marke. Play lehnt das ab oder hängt 18+ an.

6. Weiter runter zu **Grafiken** / **Graphics**. Dateien vom Rechner, Drag-and-drop oder **Hochladen**:

   | Feld | Pflicht | Format |
   | --- | --- | --- |
   | **App-Symbol** / App icon | ja | PNG, **genau 512×512**, kein Alpha nötig, ohne Sex-Wörter in der Schrift |
   | **Feature-Grafik** / Feature graphic | ja | **1024×500**, JPEG oder PNG |
   | **Handy-Screenshots** / Phone screenshots | ja, **mindestens 2** (bis 8) | JPEG oder PNG, kürzeste Seite mindestens 320 px, längste höchstens 3840 px. z. B. Feed + Chat, Texte im Bild ebenfalls ohne kritische Wörter |
   | Tablet-Screenshots, TV, Wear | nein | weglassen |

7. YouTube-Video: leer lassen, solange keins existiert.
8. Unten rechts **Speichern** / **Save**. Ohne Speichern ist nichts übernommen. Es gibt hier meist **keine** extra „Veröffentlichen“-Schaltfläche — das Listing hängt am Release. Speichern reicht, bis der interne Test live geht.
9. Oben oft **Vorschau bei Google Play** — nur du siehst den Entwurf; Testhandys sehen ihn nach Annahme der Tester-Einladung.

Fehlt der Menüpunkt: App noch nicht angelegt → Abschnitt 1. Oder du stehst auf Konto-Ebene (**Alle Apps**) statt in der App **LO Community**.

---

### 7a-2. Kategorie und Kontakt (andere Seite, gehört zum Listing)

Name/Texte/Bilder allein reichen nicht. Play will Kategorie und Support-Mail.

1. Links bleiben: **Mehr Nutzer gewinnen** → **App-Präsenz im Play Store** → **Play Store-Einstellungen** (nicht der Haupteintrag).
2. **App-Kategorie:** **Soziales** (engl. **Social**). Das ist die richtige Schublade für eine Mitglieder-Community mit Feed, Chat und Kalender.
   - Nicht **Kommunikation** — das ist WhatsApp/Telegram, nicht euer Portal.
   - Nicht **Dating** — extra Richtlinien, 18+-Schublade, falsches Publikum.
   - Nicht **Veranstaltungen**, nicht **Lifestyle** als Ausweichkategorie.
3. **Tags:** weglassen, oder nur Harmloses wie Community. Keine Sex-/Dating-Tags.
4. Runter zu **Kontaktdaten**:
   - **E-Mail** (Pflicht): eine Adresse, die du liest
   - **Website:** `https://community.orgasmic.live/portal` (oder die öffentliche Info-Seite)
   - Telefon: optional
5. **Speichern**.

Die Datenschutz-URL gehört **nicht** hierhin, sondern unter **Richtlinie → Datenschutzrichtlinie** (7b).

### 7b. Datenschutz

1. **Richtlinie → Datenschutzrichtlinie**: URL zu eurer Datenschutzerklärung (muss öffentlich erreichbar sein, z. B. auf community.orgasmic.live).
2. **Richtlinie → Datensicherheit**: Fragebogen.
   - Login / Konto: ja  
   - Push-Token / Geräte-ID: ja (für Benachrichtigungen)  
   - Fotos/Mikro nur wenn der Nutzer in der App selbst aufnimmt  
   - Nicht verkauft, für App-Funktionen

### 7c. App-Inhalt

1. **Richtlinie → App-Inhalt**: Zielgruppe, Nachrichten-App, Nutzerinhalte (UGC) — Community mit Chat/Posts: UGC ja, Moderation erklären.
2. **Jugendschutzeinstufung**: Fragebogen ausfüllen, IARC-Rating erzeugen.

### 7d. Test-Track anlegen

1. **Testen → Interner Test**.
2. Testhandys / Google-Konten als Tester einladen (deine eigene Gmail reicht für den ersten Check).
3. Den Link „Tester werden“ auf dem Handy öffnen und annehmen, sonst ist die App unsichtbar.

Neue persönliche Play-Konten müssen oft erst **geschlossenen Test mit 20 Testern** und Wartezeit hinter sich bringen, bevor **Produktion** geht. Internal Test zum Selberprüfen geht trotzdem.

---

## 8. Signiertes Release bauen

1. Codemagic → Workflow **Android Release (AAB + APK)**.
2. Build starten.
3. Artifacts: `.aab` (Store) und `.apk` (manuell installieren).
4. Die Release-APK überschreibt die Debug-App nur, wenn dieselbe Signatur verwendet wird — Debug und Release sind verschieden. Debug vorher deinstallieren, dann Release-APK installieren.

---

## 9. Optional: direkt zu Play hochladen

Erst wenn Schritt 7 steht und ein **Service Account** für Play existiert.

### 9a. Play-API-Zugang

1. Play Console → **Einstellungen → API-Zugriff** (oder Setup → API access).
2. Google-Cloud-Projekt verknüpfen (dasselbe wie Firebase geht, oder ein neues).
3. **Service Account erstellen** in Google Cloud, Rolle in Play: **Release-Verwaltung** (und Metadaten, wenn gefragt).
4. JSON-Schlüssel herunterladen.

### 9b. In Codemagic

1. Gruppe **`google_play`**.
2. Variable `GOOGLE_PLAY_SERVICE_ACCOUNT_CREDENTIALS`: **kompletter JSON**, Secret.
3. Workflow **Android zu Play (Internal)** starten.
4. In Play Console → Interner Test sollte ein neuer Release als Entwurf liegen → **Prüfen und veröffentlichen**.

Wenn die App in Play noch `draft` ist, bleibt `submit_as_draft: true` in der yaml — das ist richtig.

---

## 10. Push auf dem Handy prüfen

Ohne **beide** Firebase-Dateien kommt nichts an. 1.1.6 verhindert den Absturz, indem die App **kein Token** speichert — WordPress hat dann kein Ziel.

| Wo | Datei | Zweck |
| --- | --- | --- |
| Codemagic, Gruppe `firebase`, Variable `GOOGLE_SERVICES_JSON` | Android-`google-services.json`, Paket `live.lo.community` | Gerät bekommt FCM-Token |
| WordPress → ORGASMIC App → FCM Service Account | Dienstkonto-JSON (Firebase → Dienstkonten) | Server **sendet** an das Token |

Das sind **zwei verschiedene** JSON-Dateien.

1. `GOOGLE_SERVICES_JSON` in Codemagic setzen (kompletter Dateiinhalt, Secret).
2. Workflow **Android Debug (testen)** neu bauen, APK installieren.
3. App öffnen, einloggen, **Benachrichtigungen erlauben** (jetzt darf der Dialog kommen).
4. WP-Admin → **ORGASMIC App**: bei deinem Konto muss **1 FCM** stehen. Dienstkonto **hinterlegt**.
5. **Test-Push an mich senden**.

Kommt der Test mit „Kein App-Token“, fehlt Schritt 1–3. Kommt „Dienstkonto fehlt“, fehlt Schritt 4.

---

## 11. App stürzt nach dem Login — Logs holen

Nach dem Login lädt das Portal `app.js` und ruft `PushNotifications.register()` auf. **Ohne** `google-services.json` in der APK beendet Android den Prozess (`Default FirebaseApp is not initialized`). Ein JavaScript-`try/catch` fängt das nicht.

**Sofort:** WordPress-Plugin **ORGASMIC App 1.1.6** einspielen. Dann wird Push erst registriert, wenn Firebase in der App wirklich da ist. Die aktuelle APK kann danach wieder genutzt werden.

**Danach Push:** `GOOGLE_SERVICES_JSON` in Codemagic (Gruppe `firebase`) mit Paket `live.lo.community` → Debug-APK neu bauen.

### Logs ohne Computer (Handy)

1. Play Store: App **Logcat Reader** (oder MatLog) installieren.  
2. Android: **Einstellungen → Über das Telefon** → siebenmal auf **Build-Nummer**.  
3. **Entwickleroptionen** einschalten → bei Logcat Reader USB-Debugging / Lesen der Logs erlauben, wenn gefragt.  
4. Filter: `AndroidRuntime` oder `live.lo.community` oder `FirebaseApp` oder `FATAL`.  
5. App öffnen, einloggen, crash abwarten.  
6. Die roten Zeilen ab `FATAL EXCEPTION` bis zum Ende des Java-Stacks kopieren und schicken.

### Logs mit Computer (USB)

1. Auf dem Handy: Entwickleroptionen → **USB-Debugging**.  
2. USB verbinden, `adb devices` muss das Gerät zeigen.  
3. Direkt nach dem Crash:

```bash
adb logcat -d | grep -A 80 "FATAL EXCEPTION"
```

Oder laufend mitlesen:

```bash
adb logcat Capacitor:V Capacitor/Console:V AndroidRuntime:E FirebaseMessaging:V *:S
```

### JavaScript (nur wenn die App offen bleibt)

1. USB-Debugging an.  
2. Am PC Chrome öffnen: `chrome://inspect/#devices`  
3. Die WebView der App antippen → **inspect**.  
4. Console / Network. Ein echter Prozess-Absturz erscheint hier **nicht**.

Codemagic hat **keine** Laufzeit-Logs vom Handy — nur den APK-Build.

---

## 12. iPhone (später, nicht jetzt)

Im Ordner `orgasmic-native/ios` ist die Hülle schon da. Dafür extra:

- Apple Developer 99 $/Jahr  
- Dieselbe Firebase-Projekt → **iOS-App** mit Bundle-ID `live.lo.community`  
- `GoogleService-Info.plist`  
- Codemagic-macOS-Workflow (legen wir an, wenn Android steht)

---

## Kurz: Reihenfolge zum Abhaken

- [ ] Play-Konto (25 $) aktiv  
- [ ] Play-App `LO Community` angelegt  
- [ ] Firebase-Projekt + Android-App `live.lo.community`  
- [ ] `google-services.json` heruntergeladen  
- [ ] Firebase-Dienstkonto-JSON in WordPress **ORGASMIC App**  
- [ ] Codemagic ↔ GitHub  
- [ ] Variable `GOOGLE_SERVICES_JSON` in Gruppe `firebase`  
- [ ] Workflow **Android Debug** → APK aufs Handy  
- [ ] Test-Push  
- [ ] Keystore-Workflow → Datei sichern → als `orgasmic_android` hochladen  
- [ ] Play: Listing, Datenschutz, Inhalt, interner Test  
- [ ] Workflow **Android Release** oder **Android zu Play**
