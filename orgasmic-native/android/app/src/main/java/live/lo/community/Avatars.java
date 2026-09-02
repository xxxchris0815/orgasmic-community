package live.lo.community;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.webkit.CookieManager;
import android.widget.ImageView;
import android.widget.TextView;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

final class Avatars {
    private static final Map<String, Bitmap> CACHE = new ConcurrentHashMap<>();
    private static final ExecutorService IO = Executors.newCachedThreadPool();
    private static final Handler MAIN = new Handler(Looper.getMainLooper());

    private Avatars() {}

    static String initial(String name) {
        String text = name == null ? "" : name.trim();
        return text.isEmpty() ? "?" : text.substring(0, 1).toUpperCase();
    }

    static void bindImage(ImageView image, String url) {
        image.setTag(url == null ? "" : url);
        if (url == null || url.isEmpty()) {
            image.setImageDrawable(null);
            return;
        }
        Bitmap cached = CACHE.get(url);
        if (cached != null && !cached.isRecycled()) {
            image.setImageBitmap(cached);
            return;
        }
        IO.execute(() -> {
            Bitmap bitmap = download(url);
            if (bitmap != null) {
                remember(url, bitmap);
            }
            MAIN.post(() -> {
                if (!url.equals(image.getTag()) || bitmap == null) {
                    return;
                }
                image.setImageBitmap(bitmap);
            });
        });
    }

    static void bind(ImageView image, TextView letter, String url, String name) {
        letter.setText(initial(name));
        letter.setVisibility(View.VISIBLE);
        image.setVisibility(View.GONE);
        image.setTag(url == null ? "" : url);
        if (url == null || url.isEmpty()) {
            return;
        }
        Bitmap cached = CACHE.get(url);
        if (cached != null && !cached.isRecycled()) {
            image.setImageBitmap(cached);
            image.setVisibility(View.VISIBLE);
            letter.setVisibility(View.GONE);
            return;
        }
        IO.execute(() -> {
            Bitmap bitmap = download(url);
            if (bitmap != null) {
                remember(url, bitmap);
            }
            MAIN.post(() -> {
                if (!url.equals(image.getTag())) {
                    return;
                }
                if (bitmap == null) {
                    return;
                }
                image.setImageBitmap(bitmap);
                image.setVisibility(View.VISIBLE);
                letter.setVisibility(View.GONE);
            });
        });
    }

    private static void remember(String url, Bitmap bitmap) {
        if (CACHE.size() > 80) {
            CACHE.clear();
        }
        CACHE.put(url, bitmap);
    }

    private static Bitmap download(String url) {
        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(url).openConnection();
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(12000);
            conn.setInstanceFollowRedirects(true);
            String cookie = CookieManager.getInstance().getCookie(HybridConfig.ORIGIN);
            if (cookie != null && !cookie.isEmpty()) {
                conn.setRequestProperty("Cookie", cookie);
            }
            InputStream stream = conn.getInputStream();
            Bitmap bitmap = BitmapFactory.decodeStream(stream);
            stream.close();
            return bitmap;
        } catch (Exception ignored) {
            return null;
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }
}
