package live.lo.community;

import android.os.Handler;
import android.os.Looper;
import android.webkit.CookieManager;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONArray;
import org.json.JSONObject;

final class ApiClient {
    interface JsonCallback {
        void onOk(JSONObject json);

        void onErr(String message);
    }

    interface ArrayCallback {
        void onOk(JSONArray items, JSONObject raw);

        void onErr(String message);
    }

    private final Session session;
    private final ExecutorService io = Executors.newCachedThreadPool();
    private final Handler main = new Handler(Looper.getMainLooper());

    ApiClient(Session session) {
        this.session = session;
    }

    void refreshSession(JsonCallback cb) {
        io.execute(() -> {
            try {
                JSONObject json = request("POST", HybridConfig.AJAX, "action=orgasmic_fc_app_boot", false);
                session.apply(json);
                ok(cb, json);
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    void get(String restPath, JsonCallback cb) {
        io.execute(() -> {
            try {
                ok(cb, request("GET", HybridConfig.REST + restPath.replaceAll("^/+", ""), null, true));
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    void getArray(String restPath, String arrayKey, ArrayCallback cb) {
        io.execute(() -> {
            try {
                JSONObject json = request("GET", HybridConfig.REST + restPath.replaceAll("^/+", ""), null, true);
                JSONArray items = json.optJSONArray(arrayKey);
                if (items == null) {
                    items = new JSONArray();
                }
                JSONArray copy = items;
                main.post(() -> cb.onOk(copy, json));
            } catch (Exception e) {
                String message = e.getMessage() != null ? e.getMessage() : "Netzwerkfehler";
                main.post(() -> cb.onErr(message));
            }
        });
    }

    void post(String restPath, JSONObject body, JsonCallback cb) {
        io.execute(() -> {
            try {
                ok(cb, request("POST", HybridConfig.REST + restPath.replaceAll("^/+", ""), body, true));
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    void delete(String restPath, JsonCallback cb) {
        io.execute(() -> {
            try {
                ok(cb, request("DELETE", HybridConfig.REST + restPath.replaceAll("^/+", ""), null, true));
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    void upload(java.io.File file, String mime, String filename, int duration, JsonCallback cb) {
        io.execute(() -> {
            try {
                ok(cb, uploadFile(file, mime, filename, duration));
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    void postAjax(String action, String extraBody, JsonCallback cb) {
        io.execute(() -> {
            try {
                String payload = "action=" + action + (extraBody != null ? extraBody : "");
                ok(cb, request("POST", HybridConfig.AJAX, payload, false));
            } catch (Exception e) {
                err(cb, e);
            }
        });
    }

    private void ok(JsonCallback cb, JSONObject json) {
        main.post(() -> cb.onOk(json));
    }

    private void err(JsonCallback cb, Exception e) {
        String message = e.getMessage() != null ? e.getMessage() : "Netzwerkfehler";
        main.post(() -> cb.onErr(message));
    }

    private JSONObject request(String method, String url, Object body, boolean rest) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setConnectTimeout(20000);
        conn.setReadTimeout(25000);
        conn.setRequestMethod(method);
        conn.setInstanceFollowRedirects(true);
        conn.setRequestProperty("Accept", "application/json");
        String cookie = CookieManager.getInstance().getCookie(HybridConfig.ORIGIN);
        if (cookie != null && !cookie.isEmpty()) {
            conn.setRequestProperty("Cookie", cookie);
        }
        if (rest && session.nonce != null && !session.nonce.isEmpty()) {
            conn.setRequestProperty("X-WP-Nonce", session.nonce);
        }
        byte[] payload = null;
        if (body instanceof JSONObject) {
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            payload = body.toString().getBytes(StandardCharsets.UTF_8);
        } else if (body instanceof String && !((String) body).isEmpty()) {
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
            payload = ((String) body).getBytes(StandardCharsets.UTF_8);
        }
        if (payload != null) {
            conn.setDoOutput(true);
            OutputStream os = conn.getOutputStream();
            os.write(payload);
            os.close();
        }
        int code = conn.getResponseCode();
        storeCookies(conn);
        InputStream stream = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
        String raw = readAll(stream);
        conn.disconnect();
        JSONObject json;
        try {
            json = raw == null || raw.isEmpty() ? new JSONObject() : new JSONObject(raw);
        } catch (Exception e) {
            throw new Exception(code + ": " + raw);
        }
        if (code >= 400) {
            String message = json.optString("message", json.optString("code", "Fehler " + code));
            throw new Exception(message);
        }
        return json;
    }

    private JSONObject uploadFile(java.io.File file, String mime, String filename, int duration) throws Exception {
        String boundary = "----oa" + System.currentTimeMillis();
        HttpURLConnection conn = (HttpURLConnection) new URL(HybridConfig.REST + "orgasmic-chat/v1/upload").openConnection();
        conn.setConnectTimeout(20000);
        conn.setReadTimeout(60000);
        conn.setRequestMethod("POST");
        conn.setDoOutput(true);
        conn.setRequestProperty("Accept", "application/json");
        conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
        String cookie = CookieManager.getInstance().getCookie(HybridConfig.ORIGIN);
        if (cookie != null && !cookie.isEmpty()) {
            conn.setRequestProperty("Cookie", cookie);
        }
        if (session.nonce != null && !session.nonce.isEmpty()) {
            conn.setRequestProperty("X-WP-Nonce", session.nonce);
        }
        OutputStream os = conn.getOutputStream();
        String header = "--" + boundary + "\r\n"
                + "Content-Disposition: form-data; name=\"file\"; filename=\"" + filename + "\"\r\n"
                + "Content-Type: " + mime + "\r\n\r\n";
        os.write(header.getBytes(StandardCharsets.UTF_8));
        java.io.FileInputStream in = new java.io.FileInputStream(file);
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) >= 0) {
            os.write(buf, 0, n);
        }
        in.close();
        os.write("\r\n".getBytes(StandardCharsets.UTF_8));
        if (duration > 0) {
            String dur = "--" + boundary + "\r\n"
                    + "Content-Disposition: form-data; name=\"duration\"\r\n\r\n"
                    + duration + "\r\n";
            os.write(dur.getBytes(StandardCharsets.UTF_8));
        }
        os.write(("--" + boundary + "--\r\n").getBytes(StandardCharsets.UTF_8));
        os.close();
        int code = conn.getResponseCode();
        storeCookies(conn);
        InputStream stream = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
        String raw = readAll(stream);
        conn.disconnect();
        JSONObject json;
        try {
            json = raw == null || raw.isEmpty() ? new JSONObject() : new JSONObject(raw);
        } catch (Exception e) {
            throw new Exception(code + ": " + raw);
        }
        if (code >= 400) {
            throw new Exception(json.optString("message", "Upload fehlgeschlagen"));
        }
        return json;
    }

    private void storeCookies(HttpURLConnection conn) {
        Map<String, List<String>> headers = conn.getHeaderFields();
        if (headers == null) {
            return;
        }
        List<String> values = headers.get("Set-Cookie");
        if (values == null) {
            values = headers.get("set-cookie");
        }
        if (values == null) {
            return;
        }
        CookieManager cookies = CookieManager.getInstance();
        for (String value : values) {
            cookies.setCookie(HybridConfig.ORIGIN, value);
        }
        cookies.flush();
    }

    private static String readAll(InputStream stream) throws Exception {
        if (stream == null) {
            return "";
        }
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];
        int n;
        while ((n = stream.read(buf)) >= 0) {
            out.write(buf, 0, n);
        }
        stream.close();
        return out.toString(StandardCharsets.UTF_8.name());
    }
}
