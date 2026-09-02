package live.lo.community;

import android.Manifest;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.util.Log;
import android.webkit.CookieManager;
import android.webkit.PermissionRequest;
import android.webkit.WebView;
import androidx.activity.OnBackPressedCallback;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.splashscreen.SplashScreen;
import androidx.core.view.WindowCompat;
import androidx.fragment.app.Fragment;
import com.google.android.material.bottomnavigation.BottomNavigationView;
import java.net.URLEncoder;
import java.util.ArrayList;
import org.json.JSONObject;

public class MainActivity extends AppCompatActivity {
    static final String TAG = "LOCommunity";

    final Session session = HybridRuntime.SESSION;
    final ApiClient api = HybridRuntime.API;

    private BottomNavigationView nav;
    private FeedFragment feed;
    private PortalTabFragment chat;
    private PortalTabFragment calendar;
    private ProfileFragment profile;
    private PermissionRequest pendingWebPermission;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        SplashScreen.installSplashScreen(this);
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
        CookieManager.getInstance().setAcceptCookie(true);
        if ((getApplicationInfo().flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0) {
            WebView.setWebContentsDebuggingEnabled(true);
        }
        setContentView(R.layout.activity_main);
        nav = findViewById(R.id.bottom_nav);
        if (savedInstanceState == null) {
            feed = new FeedFragment();
            profile = new ProfileFragment();
            getSupportFragmentManager()
                    .beginTransaction()
                    .add(R.id.tab_host, feed, "feed")
                    .add(R.id.tab_host, profile, "profile")
                    .hide(profile)
                    .commit();
        } else {
            feed = (FeedFragment) getSupportFragmentManager().findFragmentByTag("feed");
            chat = (PortalTabFragment) getSupportFragmentManager().findFragmentByTag("chat");
            calendar = (PortalTabFragment) getSupportFragmentManager().findFragmentByTag("cal");
            profile = (ProfileFragment) getSupportFragmentManager().findFragmentByTag("profile");
        }
        nav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_feed) {
                show(feed);
            } else if (id == R.id.nav_chat) {
                show(ensureChat());
                chat.reload();
            } else if (id == R.id.nav_cal) {
                show(ensureCalendar());
                calendar.reload();
            } else if (id == R.id.nav_profile) {
                show(profile);
                profile.reload();
            }
            return true;
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                int selected = nav.getSelectedItemId();
                if (selected == R.id.nav_feed && feed.goBack()) {
                    return;
                }
                if (selected == R.id.nav_chat && chat != null && chat.goBack()) {
                    return;
                }
                if (selected == R.id.nav_cal && calendar != null && calendar.goBack()) {
                    return;
                }
                if (selected != R.id.nav_feed) {
                    openTab(R.id.nav_feed);
                    return;
                }
                setEnabled(false);
                getOnBackPressedDispatcher().onBackPressed();
            }
        });
        maybeAskNotifications();
        Log.i(TAG, "hybrid shell started");
    }

    void openTab(int id) {
        nav.setSelectedItemId(id);
    }

    void openChat(String hash) {
        openTab(R.id.nav_chat);
        ensureChat().openHash(hash == null || hash.isEmpty() ? "#orgasmic-chat" : hash);
    }

    void openCalendar(String hash) {
        openTab(R.id.nav_cal);
        ensureCalendar().openHash(hash == null || hash.isEmpty() ? "#orgasmic-calendar" : hash);
    }

    void onPortalLoaded() {
        refreshSessionAndPush();
    }

    void grantWebMedia(PermissionRequest request) {
        pendingWebPermission = request;
        ArrayList<String> needed = new ArrayList<>();
        for (String resource : request.getResources()) {
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resource)
                    && ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
                    != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.RECORD_AUDIO);
            }
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)
                    && ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                    != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.CAMERA);
            }
        }
        if (needed.isEmpty()) {
            request.grant(request.getResources());
            pendingWebPermission = null;
            return;
        }
        ActivityCompat.requestPermissions(this, needed.toArray(new String[0]), 72);
    }

    void refreshSessionAndPush() {
        api.refreshSession(new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                if (session.loggedIn) {
                    registerPush();
                }
            }

            @Override
            public void onErr(String message) {}
        });
    }

    private PortalTabFragment ensureChat() {
        if (chat == null) {
            chat = (PortalTabFragment) getSupportFragmentManager().findFragmentByTag("chat");
        }
        if (chat == null) {
            chat = PortalTabFragment.chat();
            getSupportFragmentManager()
                    .beginTransaction()
                    .add(R.id.tab_host, chat, "chat")
                    .hide(chat)
                    .commitNow();
        }
        return chat;
    }

    private PortalTabFragment ensureCalendar() {
        if (calendar == null) {
            calendar = (PortalTabFragment) getSupportFragmentManager().findFragmentByTag("cal");
        }
        if (calendar == null) {
            calendar = PortalTabFragment.calendar();
            getSupportFragmentManager()
                    .beginTransaction()
                    .add(R.id.tab_host, calendar, "cal")
                    .hide(calendar)
                    .commitNow();
        }
        return calendar;
    }

    private void show(Fragment target) {
        androidx.fragment.app.FragmentTransaction tx = getSupportFragmentManager().beginTransaction();
        for (Fragment fragment : new Fragment[] {feed, chat, calendar, profile}) {
            if (fragment != null && fragment.isAdded()) {
                if (fragment == target) {
                    tx.show(fragment);
                } else {
                    tx.hide(fragment);
                }
            }
        }
        tx.commit();
    }

    private void registerPush() {
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) {
            return;
        }
        try {
            com.google.firebase.messaging.FirebaseMessaging.getInstance()
                    .getToken()
                    .addOnSuccessListener(token -> {
                        if (token == null || token.isEmpty() || !session.loggedIn) {
                            return;
                        }
                        try {
                            String extra = "&token=" + URLEncoder.encode(token, "UTF-8") + "&platform=android";
                            api.postAjax("orgasmic_fc_app_push_token", extra, new ApiClient.JsonCallback() {
                                @Override
                                public void onOk(JSONObject json) {}

                                @Override
                                public void onErr(String message) {}
                            });
                        } catch (Exception ignored) {
                        }
                    });
        } catch (Throwable ignored) {
        }
    }

    private void maybeAskNotifications() {
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, new String[] {Manifest.permission.POST_NOTIFICATIONS}, 71);
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 71 && session.loggedIn) {
            registerPush();
        }
        if (requestCode == 72 && pendingWebPermission != null) {
            boolean ok = true;
            for (int result : grantResults) {
                if (result != PackageManager.PERMISSION_GRANTED) {
                    ok = false;
                    break;
                }
            }
            if (ok) {
                pendingWebPermission.grant(pendingWebPermission.getResources());
            } else {
                pendingWebPermission.deny();
            }
            pendingWebPermission = null;
        }
    }
}
