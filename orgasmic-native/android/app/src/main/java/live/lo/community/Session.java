package live.lo.community;

import org.json.JSONObject;

final class Session {
    volatile boolean loggedIn;
    volatile int userId;
    volatile String displayName = "";
    volatile String nonce = "";
    volatile String privacyUrl = "";
    volatile String safetyUrl = "";
    volatile JSONObject prefs;

    synchronized void apply(JSONObject json) {
        loggedIn = json.optBoolean("loggedIn");
        userId = json.optInt("userId");
        displayName = json.optString("displayName", "");
        nonce = json.optString("nonce", nonce);
        privacyUrl = json.optString("privacyUrl", privacyUrl);
        safetyUrl = json.optString("safetyUrl", safetyUrl);
        JSONObject nextPrefs = json.optJSONObject("prefs");
        if (nextPrefs != null) {
            prefs = nextPrefs;
        }
    }

    synchronized JSONObject snapshotPrefs() {
        return prefs;
    }
}
