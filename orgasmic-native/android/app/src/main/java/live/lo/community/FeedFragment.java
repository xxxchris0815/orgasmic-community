package live.lo.community;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import org.json.JSONObject;

public class FeedFragment extends Fragment implements HybridChrome.Host {
    private WebView webView;
    private ValueCallback<Uri[]> fileCallback;
    private final ActivityResultLauncher<Intent> filePicker =
            registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), result -> {
                Uri[] uris = HybridChrome.urisFromResult(result.getData());
                if (fileCallback != null) {
                    fileCallback.onReceiveValue(uris);
                    fileCallback = null;
                }
            });

    @Nullable
    @Override
    @SuppressLint("SetJavaScriptEnabled")
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_feed, container, false);
        webView = root.findViewById(R.id.feed_web);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setUserAgentString(HybridConfig.userAgent(settings.getUserAgentString(), "feed"));
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(@NonNull WebView view, @NonNull WebResourceRequest request) {
                Uri uri = request.getUrl();
                String url = uri.toString();
                String fragment = uri.getFragment() == null ? "" : uri.getFragment();
                if (url.contains("#orgasmic-chat") || fragment.startsWith("orgasmic-chat")) {
                    host().openChat(fragment.isEmpty() ? "#orgasmic-chat" : "#" + fragment);
                    return true;
                }
                if (url.contains("#orgasmic-calendar") || url.contains("#orgasmic-event")) {
                    host().openCalendar(fragment.isEmpty() ? "#orgasmic-calendar" : "#" + fragment);
                    return true;
                }
                String hostName = uri.getHost() == null ? "" : uri.getHost();
                if (!hostName.endsWith("orgasmic.live") && !hostName.isEmpty()) {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                    return true;
                }
                return false;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                view.evaluateJavascript(
                        "(function(){var h=document.documentElement;"
                                + "h.classList.add('orgasmic-hybrid-shell','orgasmic-hybrid-feed');"
                                + "var s=document.getElementById('oa-hybrid-css');"
                                + "if(!s){s=document.createElement('style');s.id='oa-hybrid-css';"
                                + "s.textContent=" + JSONObject.quote(HybridConfig.SHELL_CSS) + ";"
                                + "h.appendChild(s);}})()",
                        null);
                if (isAdded()) {
                    host().onPortalLoaded();
                }
            }
        });
        webView.setWebChromeClient(new HybridChrome(this));
        if (savedInstanceState == null) {
            webView.loadUrl(HybridConfig.PORTAL);
        } else {
            webView.restoreState(savedInstanceState);
        }
        return root;
    }

    @Override
    public void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        if (webView != null) {
            webView.saveState(outState);
        }
    }

    @Override
    public void onHiddenChanged(boolean hidden) {
        super.onHiddenChanged(hidden);
        if (webView == null) {
            return;
        }
        if (hidden) {
            webView.onPause();
        } else {
            webView.onResume();
        }
    }

    boolean goBack() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return true;
        }
        return false;
    }

    private MainActivity host() {
        return (MainActivity) requireActivity();
    }

    @Override
    public MainActivity activity() {
        return host();
    }

    @Override
    public ActivityResultLauncher<Intent> filePicker() {
        return filePicker;
    }

    @Override
    public ValueCallback<Uri[]> fileCallback() {
        return fileCallback;
    }

    @Override
    public void setFileCallback(ValueCallback<Uri[]> callback) {
        fileCallback = callback;
    }
}
