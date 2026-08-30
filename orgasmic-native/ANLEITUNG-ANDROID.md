# ORGASMIC Android-App — Schritt für Schritt (Codemagic)

Die App ist keine zweite Website. Capacitor öffnet **https://community.orgasmic.live**. Push läuft über Firebase. Gebaut wird online bei Codemagic.

Paketname (niemals ändern): `live.orgasmic.community`

Reihenfolge einhalten. Android zuerst. iOS kommt später mit demselben Firebase-Projekt.

---

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
   - App-Name: `ORGASMIC`
   - Standardsprache: Deutsch
   - App oder Spiel: **App**
   - Kostenlos oder kostenpflichtig: **Kostenlos**
   - Richtlinien und US-Exportregeln akzeptieren
5. **App erstellen**.
6. Notiere dir: die App existiert, ist aber noch **Entwurf**. Es fehlt Listing, Inhalt, Datenschutz — das kommt in Schritt 7. Zuerst Firebase und ein Test-Build.

---

## 2. Firebase-Projekt (Push)

Zwei verschiedene Namen — nicht vermischen:

| Was | Beispiel | Punkte? |
| --- | --- | --- |
| **Projekt-ID** (Firebase-Konto) | `live-orgasmic-community` | Bindestriche, **keine Punkte** — so ist das richtig |
| **Android-Paketname** (die App) | `live.orgasmic.community` | **Punkte**, keine Bindestriche |

Die Projekt-ID darfst du nicht mit Punkten anlegen. Den Android-Paketnamen **musst** du mit Punkten eintragen. Das Feld heißt beim Hinzufügen der Android-App „Android-Paketname“ / „Android package name“, nicht Projekt-ID.

1. Öffne [https://console.firebase.google.com](https://console.firebase.google.com) mit **demselben Google-Konto**.
2. **Projekt hinzufügen** → Anzeigename z. B. `ORGASMIC`. Die erzeugte Projekt-ID darf `live-orgasmic-community` heißen.
3. Google Analytics kannst du anlassen oder überspringen.
4. **Projekt erstellen**.

### 2a. Android-App in Firebase

1. Auf der Projektübersicht: **Android** (Icon) — nicht das Projekt umbenennen, eine **App** zum Projekt hinzufügen.
2. Feld **Android-Paketname** **genau**: `live.orgasmic.community`  
   (Punkte sind hier erlaubt und nötig. `live-orgasmic-community` ist **falsch** und passt nicht zur App.)
3. App-Spitzname: `ORGASMIC Android`
4. Debug-Signatur-SHA kannst du leer lassen.
5. **App registrieren**.
6. **`google-services.json` herunterladen**. Datei aufheben. Nicht ins öffentliche Git legen.
7. Die nächsten Firebase-Schritte „ins SDK einfügen“ überspringen — Capacitor macht das.

Falls schon eine Android-App mit `live-orgasmic-community` existiert: stehen lassen. **Zusätzlich** eine zweite Android-App mit Paket `live.orgasmic.community` anlegen und **deren** `google-services.json` verwenden.

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

1. Codemagic → Team / App → **Environment variables** (oder Teams → Global variables).
2. **Gruppe anlegen:** Name genau `firebase` (wie in der yaml).
3. Variable:
   - Name: `GOOGLE_SERVICES_JSON`
   - Value: **kompletter Inhalt** der `google-services.json` (alles von `{` bis `}`)
   - **Secret** anhaken
   - Gruppe: `firebase`
4. Speichern.

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

---

## 6. Keystore einmal erzeugen (ohne eigenen PC)

Für den Store brauchst du einen Signing-Schlüssel. **Einmal** erzeugen, **für immer** behalten. Verloren = keine Updates mehr unter derselben Play-App.

1. Codemagic → Environment variables → Gruppe **`android_keystore`**.
2. Variable `KEYSTORE_PASSWORD`: ein langes Passwort, **Secret**, Gruppe `android_keystore`.
3. Passwort in einem Passwort-Manager speichern.
4. Workflow **Android Keystore einmal erzeugen** starten.
5. Artifact **`orgasmic-release.keystore`** herunterladen.
6. Datei an zwei sichere Orte kopieren (nicht ins Git).
7. Diesen Workflow **nie wieder** starten.

### Keystore in Codemagic hochladen

1. Codemagic → **Teams** → dein Team → **codemagic.yaml settings** → **Code signing identities**.
2. Tab **Android keystores**.
3. Datei `orgasmic-release.keystore` hochladen.
4. Keystore password: dasselbe wie `KEYSTORE_PASSWORD`.
5. Key alias: `orgasmic`
6. Key password: dasselbe Passwort.
7. Reference name **genau:** `orgasmic_android`
8. **Add keystore**.

---

## 7. Play Console fertigmachen (bevor der Store die AAB annimmt)

In der Play Console die App öffnen. Links die Pflichtpunkte abarbeiten. Ohne die bleibt der Upload im Entwurf.

### 7a. Store-Eintrag (Hauptstore-eintrag)

1. **Wachstum → Store-Eintrag**.
2. Kurzbeschreibung und ausführliche Beschreibung (Deutsch).
3. App-Icon 512×512 PNG.
4. Feature-Grafik 1024×500.
5. Mindestens **2 Handy-Screenshots** (z. B. Feed und Chat).
6. Speichern.

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

1. Release- oder Debug-App (mit echter `google-services.json`) installieren.
2. In der Community einloggen.
3. Systemdialog „Benachrichtigungen zulassen“ bestätigen.
4. WordPress → **ORGASMIC App** → **Test-Push an mich senden**.
5. Handy nicht im Flugmodus, App darf im Hintergrund sein.

Kommt nichts:

- Firebase JSON in WordPress hinterlegt?  
- `GOOGLE_SERVICES_JSON` in Codemagic die **Android**-Datei, Paketname `live.orgasmic.community`?  
- In Firebase dieselbe Android-App?  
- Nutzer in der App wirklich eingeloggt (Token geht nur an eingeloggte Mitglieder)?

---

## 11. iPhone (später, nicht jetzt)

Im Ordner `orgasmic-native/ios` ist die Hülle schon da. Dafür extra:

- Apple Developer 99 $/Jahr  
- Dieselbe Firebase-Projekt → **iOS-App** mit Bundle-ID `live.orgasmic.community`  
- `GoogleService-Info.plist`  
- Codemagic-macOS-Workflow (legen wir an, wenn Android steht)

---

## Kurz: Reihenfolge zum Abhaken

- [ ] Play-Konto (25 $) aktiv  
- [ ] Play-App `ORGASMIC` angelegt  
- [ ] Firebase-Projekt + Android-App `live.orgasmic.community`  
- [ ] `google-services.json` heruntergeladen  
- [ ] Firebase-Dienstkonto-JSON in WordPress **ORGASMIC App**  
- [ ] Codemagic ↔ GitHub  
- [ ] Variable `GOOGLE_SERVICES_JSON` in Gruppe `firebase`  
- [ ] Workflow **Android Debug** → APK aufs Handy  
- [ ] Test-Push  
- [ ] Keystore-Workflow → Datei sichern → als `orgasmic_android` hochladen  
- [ ] Play: Listing, Datenschutz, Inhalt, interner Test  
- [ ] Workflow **Android Release** oder **Android zu Play**
