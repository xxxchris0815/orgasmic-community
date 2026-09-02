# LO Community Native (Capacitor)

Hülle um die bestehende Community-Website für Play Store und App Store.

- **Anzeigename (Handy + Store):** LO Community
- **App-ID (technisch):** `live.lo.community`
- **Icon / Splash:** goldenes LO-Logo — Homescreen auf Weiß, Ladebildschirm auf Navy `#121c30`. Neu erzeugen: `python3 scripts/generate-branding.py`
- Push: `@capacitor/push-notifications` → WordPress `POST /wp-json/orgasmic-app/v1/push/token`
- Bauen: Codemagic — **[ANLEITUNG-ANDROID.md](ANLEITUNG-ANDROID.md)** und **[ANLEITUNG-IOS.md](ANLEITUNG-IOS.md)**
- Hybrid-Test (native Tabs): **[HYBRID-ANDROID.md](HYBRID-ANDROID.md)**
