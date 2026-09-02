package live.lo.community;

import android.content.Intent;
import android.net.Uri;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebView;
import androidx.activity.result.ActivityResultLauncher;

final class HybridChrome extends WebChromeClient {
    interface Host {
        MainActivity activity();

        ActivityResultLauncher<Intent> filePicker();

        ValueCallback<Uri[]> fileCallback();

        void setFileCallback(ValueCallback<Uri[]> callback);
    }

    private final Host host;

    HybridChrome(Host host) {
        this.host = host;
    }

    @Override
    public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> callback, FileChooserParams params) {
        ValueCallback<Uri[]> previous = host.fileCallback();
        if (previous != null) {
            previous.onReceiveValue(null);
        }
        host.setFileCallback(callback);
        Intent intent = params.createIntent();
        try {
            host.filePicker().launch(intent);
        } catch (Exception e) {
            host.setFileCallback(null);
            return false;
        }
        return true;
    }

    @Override
    public void onPermissionRequest(PermissionRequest request) {
        MainActivity activity = host.activity();
        activity.runOnUiThread(() -> activity.grantWebMedia(request));
    }

    static Uri[] urisFromResult(Intent data) {
        if (data == null) {
            return null;
        }
        if (data.getClipData() != null) {
            int n = data.getClipData().getItemCount();
            Uri[] uris = new Uri[n];
            for (int i = 0; i < n; i += 1) {
                uris[i] = data.getClipData().getItemAt(i).getUri();
            }
            return uris;
        }
        if (data.getData() != null) {
            return new Uri[] {data.getData()};
        }
        return null;
    }
}
