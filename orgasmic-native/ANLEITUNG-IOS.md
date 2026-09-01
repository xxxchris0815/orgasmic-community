# LO Community — iOS (App Store / TestFlight)

Dieselbe Capacitor-Hülle wie Android. Die App ist **kein Safari-Lesezeichen**: sie hat natives Push (APNs + Firebase), Kamera für Chat/Beiträge und Mikrofon für Sprachnachrichten. Gebaut wird bei Codemagic auf einem Mac.

Öffentlicher Name überall **LO Community**. Bundle-ID **`live.lo.community`**. Keine sexuellen Wörter in Titel, Screenshots, Icon-Schrift, Review-Notizen-Überschrift.

Android-Anleitung: [`ANLEITUNG-ANDROID.md`](ANLEITUNG-ANDROID.md). Firebase-Projekt von Android **wiederverwenden**, nur eine **iOS-App** dazuhängen.

---

## Was im Repo schon liegt (Review-Blocker)

| Datei | Zweck |
| --- | --- |
| `ios/App/App/Info.plist` | Kamera / Mikro / Fotos auf Deutsch, `ITSAppUsesNonExemptEncryption=false`, Remote-Notifications |
| `ios/App/App/App.entitlements` | Push `aps-environment = production` |
| `ios/App/App/PrivacyInfo.xcprivacy` | Kein Tracking, Nutrition-Labels, Required-Reason-APIs |
| `ios/App/App/AppDelegate.swift` | Firebase + APNs-Token an FCM, Notification-Delegate |
| `ios/App/App/OrgasmicNativePlugin.swift` | `pushReady` / FCM-Token für das WordPress-Plugin |
| `ios/App/App/GoogleService-Info.plist` | **Platzhalter** — CI überschreibt mit der echten Firebase-Datei |
| `codemagic.yaml` | Workflows **iOS IPA** und **iOS TestFlight** |

Im Portal (Plugin **ORGASMIC App ≥ 1.1.27**): **Profil → Benachrichtigungen → Konto dauerhaft löschen**. Apple 5.1.1(v) verlangt die Löschung **in der App**, nicht nur auf der Website.

---

## 1. Apple Developer

1. [developer.apple.com](https://developer.apple.com) → **Account** mit der Firma / dem Konto, das die App besitzen soll.
2. **Apple Developer Program** (99 $/Jahr). Warten, bis der Vertrag **Active** ist.
3. **Certificates, Identifiers & Profiles → Identifiers → + → App IDs → App**.
4. Description: `LO Community`. Bundle ID **explicit**: `live.lo.community`.
5. Capabilities: **Push Notifications** an. (Sign in with Apple nicht nötig.)
6. **App ID Register**.

### 1b. APNs-Schlüssel (einmal, für Firebase)

1. Developer → **Keys → +**.
2. Name `LO Community APNs`. Haken **Apple Push Notifications service (APNs)**.
3. Key erzeugen, `.p8` **sofort herunterladen** (geht nur einmal), Key-ID und Team-ID notieren.
4. Datei nicht ins Git legen.

---

## 2. App Store Connect

1. [appstoreconnect.apple.com](https://appstoreconnect.apple.com) → **Apps → + → Neue App**.
2. Plattform iOS. Name **LO Community** (kann regional abweichen, wenn der Name belegt ist — dann `LO Community DE` o. ä., ohne Sex-Wörter).
3. Primäre Sprache: Deutsch. Bundle-ID: `live.lo.community`. SKU z. B. `lo-community-ios`.
4. User Access: Vollzugriff für das Codemagic-Konto.

### 2b. Pflichtfragen, die Review killen wenn sie fehlen

| Frage | Antwort |
| --- | --- |
| Export Compliance / Encryption | **No** — nur HTTPS. Entspricht `ITSAppUsesNonExemptEncryption=false` |
| Advertising Identifier (IDFA) | **No**. Kein ATT-Dialog, keine `NSUserTrackingUsageDescription` |
| Content rights | Eigene Inhalte + UGC der Mitglieder |
| Age | **17+** (UGC, Community). Nicht 4+ |
| Made for Kids | **No** |
| Sign in with Apple | Nicht nötig (kein Drittanbieter-Login wie Google/Facebook in der App) |

Datenschutz-Labels (App Privacy) in Connect, analog zum Privacy Manifest:

- E-Mail, User-ID, Fotos/Videos, Audiodaten, sonstige User-Inhalte, Geräte-ID (Push-Token)
- Verknüpft mit der Identität, **nicht** zum Tracking, Zweck **App-Funktionalität**
- Nicht an Dritte verkauft
- Kamera / Mikro / Mediathek nur nach Nutzeraktion (Chat, Beitrag)

Kinderschutz / CSAE: dieselbe öffentliche Seite wie bei Play (`/kinderschutz`). In Connect unter **App-Informationen** verlinken, wenn Apple das Feld anbietet.

---

## 3. Firebase iOS-App (selbes Projekt wie Android)

1. [Firebase Console](https://console.firebase.google.com) → Projekt **LO Community**.
2. **App hinzufügen → iOS**. Bundle-ID **genau** `live.lo.community`.
3. App-Spitzname: `LO Community iOS`. App Store ID später eintragen, sobald Connect eine hat.
4. `GoogleService-Info.plist` herunterladen. **Nicht committen** (im Repo liegt nur ein Stub).
5. **Project settings → Cloud Messaging → Apple app configuration**: APNs Authentication Key (`.p8`) hochladen, Key-ID + Team-ID.

Ohne diesen Key kommen keine Pushes aufs iPhone — FCM kann APNs sonst nicht erreichen.

WordPress **ORGASMIC App** behält dasselbe Firebase-**Dienstkonto** wie Android. iOS-Geräte speichern denselben FCM-Token-Typ.

---

## 4. Codemagic

Voraussetzungen aus der Android-Anleitung: GitHub-Repo verbunden, Gruppe `firebase` existiert.

### 4a. iOS Signing

1. Codemagic → **Teams → Code signing identities → iOS**.
2. Entweder **Codemagic erzeugt** Distribution-Zertifikat + App-Store-Profil für `live.lo.community` (Apple-ID mit Developer-Zugang hinterlegen), oder eigenes Distribution-Zertifikat + Profil hochladen.
3. Das Profil muss **Push Notifications** enthalten (App ID mit Push).

### 4b. App Store Connect API (für TestFlight-Upload)

1. App Store Connect → **Benutzer und Zugriffsrechte → Integrationen → App Store Connect API**.
2. Key mit Rolle **Admin** oder **App Manager** erzeugen, `.p8` laden.
3. Codemagic → **Integrations → App Store Connect**: Issuer ID, Key ID, `.p8`. Name der Integration **genau** `orgasmic_asc` (steht so in `codemagic.yaml`). Sonst den Namen in der YAML anpassen.

### 4c. Secret `GOOGLE_SERVICE_INFO_PLIST`

Gruppe **firebase**, Variable `GOOGLE_SERVICE_INFO_PLIST`:

- Inhalt der echten `GoogleService-Info.plist` (ganzes XML) **oder** Datei als base64 (`base64 -i GoogleService-Info.plist | tr -d '\n'`).
- `BUNDLE_ID` muss `live.lo.community` sein.

### 4d. Workflows

| Workflow | Zweck |
| --- | --- |
| **iOS IPA (TestFlight vorbereiten)** | Signierte IPA als Artifact, kein Upload |
| **iOS TestFlight** | IPA bauen und zu TestFlight legen |

Erst IPA-Workflow einmal grün, dann TestFlight. Build-Nummer kommt von TestFlight (`latest + 1`), Marketing-Version `1.0.<build>`.

---

## 5. TestFlight-Prüfung vor Review

1. Interne Tester (App Store Connect → TestFlight → Internal Testing).
2. iPhone: TestFlight-App, **LO Community** installieren.
3. Einloggen mit einem **Wegwerf-Review-Konto**, nicht mit `post@orgasmic.live` (das Konto könnte jemand in der Review löschen).
4. Checkliste:

- [ ] Portal lädt (nicht die WordPress-Startseite)
- [ ] Chat: Foto aufnehmen / aus Mediathek, Sprachnachricht (Permission-Dialoge erscheinen mit den deutschen Texten aus `Info.plist`)
- [ ] Push: WP-Admin → ORGASMIC App → Test-Push; auf dem iPhone muss **1 FCM** stehen
- [ ] Profil → Benachrichtigungen → Speichern
- [ ] Konto löschen nur an einem Testuser prüfen, nicht am Review-Account

Plugin **ORGASMIC App 1.1.27** (oder neuer) auf dem Server, sonst fehlt die Löschung in der App.

---

## 6. Review-Notizen (in Connect einfügen)

Englisch ist üblich, Apple liest beides. Vorlage:

```
LO Community is a members-only community app (courses, spaces, feed, chat, calendar).

Native features (not a Safari bookmark):
- APNs / Firebase push for chat, posts, comments, events
- Camera and photo library for chat and posts (only after the member taps)
- Microphone for voice notes in chat (only after the member taps)

Test account (do not delete):
Email: <REVIEW_EMAIL>
Password: <REVIEW_PASSWORD>
Open the app → log in on /portal.

Account deletion (Guideline 5.1.1(v)):
Profile menu → Benachrichtigungen (Notifications) → Konto dauerhaft löschen.
Type DELETE to confirm. Site admins cannot delete themselves in-app.

No ads. No tracking / IDFA. Camera, mic, and photos are not accessed in the background.
Encryption: HTTPS only; ITSAppUsesNonExemptEncryption is false.
```

Demo-Account: Mitglied in den öffentlichen Test-Spaces, **nicht** Admin, **nicht** der Shared-Tester, mit dem intern gelöscht wird.

---

## 7. Guideline 4.2 (Minimum Functionality)

Apple lehnt reine WebView-Hüllen ab. Die Argumentation in den Review-Notizen plus echte Nutzung:

1. Push kommt als System-Notification, auch wenn die App zu ist.
2. Kamera- und Mikro-Permissions sind native iOS-Dialoge.
3. Die App bleibt in der Hülle (`community.orgasmic.live`), kein Absprung in Safari für den Kernflow.

Nicht in den Store-Texten behaupten, die App sei „nativ wie Instagram“. Sie ist eine native Hülle um FluentCommunity — das ist erlaubt, wenn die nativen Fähigkeiten oben wirklich greifen.

---

## 8. Häufige Ablehnungen

| Grund | Was tun |
| --- | --- |
| 5.1.1(v) keine Kontolöschung in der App | Plugin 1.1.27+, Pfad in den Review-Notizen, selbst einmal durchklicken |
| 2.1 Information Needed / Login hängt | Review-Account, der wirklich Mitglied ist; 2FA aus; nicht nur Website-Login |
| 4.2 Mini-App / Website | Push + Kamera + Voice auf dem Review-Gerät vorführen |
| Missing usage description | Steht in `Info.plist`; nicht die Keys löschen |
| Missing Push entitlement | `App.entitlements` + App-ID Capability + Profil neu erzeugen |
| Encryption export | Connect **No**, Plist `false` |
| Tracking / IDFA | Nutrition Labels ohne Tracking, kein ATT |
| Binary rejected: placeholder Firebase | `GOOGLE_SERVICE_INFO_PLIST` muss die echte Datei sein, nicht den Stub |

---

## Kurz: Reihenfolge zum Abhaken

- [ ] Apple Developer aktiv, App ID `live.lo.community` mit Push
- [ ] APNs `.p8` in Firebase iOS-App
- [ ] App Store Connect App **LO Community**
- [ ] Privacy, 17+, Encryption No, kein IDFA
- [ ] Codemagic iOS Signing + Integration `orgasmic_asc`
- [ ] `GOOGLE_SERVICE_INFO_PLIST` in Gruppe `firebase`
- [ ] WordPress: ORGASMIC App **1.1.27**
- [ ] Workflow **iOS IPA** grün
- [ ] Workflow **iOS TestFlight** → internes TestFlight
- [ ] Review-Account + Notizen
- [ ] Submit for Review
