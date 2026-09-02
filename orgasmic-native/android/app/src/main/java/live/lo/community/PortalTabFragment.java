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

public class PortalTabFragment extends Fragment implements HybridChrome.Host {
    static final String ARG_SHELL = "shell";
    static final String ARG_CLASS = "htmlClass";
    static final String ARG_HASH = "hash";

    private WebView webView;
    private ValueCallback<Uri[]> fileCallback;
    private String pendingHash;
    private final ActivityResultLauncher<Intent> filePicker =
            registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), result -> {
                Uri[] uris = HybridChrome.urisFromResult(result.getData());
                if (fileCallback != null) {
                    fileCallback.onReceiveValue(uris);
                    fileCallback = null;
                }
            });

    static PortalTabFragment chat() {
        return create("chat", "orgasmic-hybrid-chat", "#orgasmic-chat");
    }

    static PortalTabFragment calendar() {
        return create("cal", "orgasmic-hybrid-cal", "#orgasmic-calendar");
    }

    static PortalTabFragment create(String shell, String htmlClass, String hash) {
        PortalTabFragment fragment = new PortalTabFragment();
        Bundle args = new Bundle();
        args.putString(ARG_SHELL, shell);
        args.putString(ARG_CLASS, htmlClass);
        args.putString(ARG_HASH, hash);
        fragment.setArguments(args);
        return fragment;
    }

    private String shell() {
        Bundle args = getArguments();
        return args == null ? "feed" : args.getString(ARG_SHELL, "feed");
    }

    private String htmlClass() {
        Bundle args = getArguments();
        return args == null ? "orgasmic-hybrid-feed" : args.getString(ARG_CLASS, "orgasmic-hybrid-feed");
    }

    private String startHash() {
        Bundle args = getArguments();
        return args == null ? "" : args.getString(ARG_HASH, "");
    }

    @Nullable
    @Override
    @SuppressLint("SetJavaScriptEnabled")
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        webView = new WebView(requireContext());
        webView.setLayoutParams(new ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setUserAgentString(HybridConfig.userAgent(settings.getUserAgentString(), shell()));
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(@NonNull WebView view, @NonNull WebResourceRequest request) {
                return handleUri(request.getUrl());
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                String hash = pendingHash != null ? pendingHash : startHash();
                pendingHash = null;
                injectShell(hash);
            }
        });
        webView.setWebChromeClient(new HybridChrome(this));
        if (savedInstanceState == null) {
            String hash = startHash();
            webView.loadUrl(hash.isEmpty() ? HybridConfig.PORTAL : HybridConfig.PORTAL + hash);
        } else {
            webView.restoreState(savedInstanceState);
        }
        return webView;
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
            injectShell(startHash());
        }
    }

    void reload() {
        if (webView != null) {
            injectShell(startHash());
        }
    }

    void openHash(String hash) {
        if (hash == null || hash.isEmpty()) {
            return;
        }
        pendingHash = hash;
        if (webView != null) {
            webView.evaluateJavascript(
                    "(function(){location.hash=" + JSONObject.quote(hash) + ";})()", null);
        }
    }

    boolean goBack() {
        if (webView == null) {
            return false;
        }
        String url = webView.getUrl();
        if (url == null) {
            return false;
        }
        if (url.contains("#orgasmic-chat-")) {
            openHash("#orgasmic-chat");
            return true;
        }
        if (url.contains("#orgasmic-event")) {
            openHash("#orgasmic-calendar");
            return true;
        }
        return false;
    }

    private boolean handleUri(Uri uri) {
        String url = uri.toString();
        String fragment = uri.getFragment() == null ? "" : uri.getFragment();
        boolean chatLink = url.contains("#orgasmic-chat") || "orgasmic-chat".equals(fragment);
        boolean calLink = url.contains("#orgasmic-calendar") || url.contains("#orgasmic-event");
        if (chatLink && !"chat".equals(shell())) {
            host().openChat(fragment.isEmpty() ? "#orgasmic-chat" : "#" + fragment);
            return true;
        }
        if (calLink && !"cal".equals(shell())) {
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

    private void injectShell(String hash) {
        if (webView == null) {
            return;
        }
        String js = "(function(){var h=document.documentElement;"
                + "h.classList.add('orgasmic-hybrid-shell',"
                + JSONObject.quote(htmlClass()) + ");"
                + "var s=document.getElementById('oa-hybrid-css');"
                + "if(!s){s=document.createElement('style');s.id='oa-hybrid-css';"
                + "s.textContent=" + JSONObject.quote(HybridConfig.SHELL_CSS) + ";"
                + "h.appendChild(s);}"
                + "var want=" + JSONObject.quote(hash) + ";"
                + "var cur=location.hash||'';"
                + "if(!want)return;"
                + "if(want.indexOf('#orgasmic-chat')===0){"
                + "if(cur.indexOf('orgasmic-chat')!==1)location.hash=want;return;}"
                + "if(want.indexOf('#orgasmic-cal')===0||want.indexOf('#orgasmic-event')===0){"
                + "if(cur.indexOf('orgasmic-calendar')!==1&&cur.indexOf('orgasmic-event')!==1)location.hash=want;}"
                + "})()";
        webView.evaluateJavascript(js, null);
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
