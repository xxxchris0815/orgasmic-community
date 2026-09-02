package live.lo.community;

import android.Manifest;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.WebView;
import androidx.activity.OnBackPressedCallback;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.view.WindowCompat;
import androidx.fragment.app.Fragment;
import com.google.android.material.bottomnavigation.BottomNavigationView;
import java.net.URLEncoder;
import org.json.JSONObject;

public class MainActivity extends AppCompatActivity {
    final Session session = HybridRuntime.SESSION;
    final ApiClient api = HybridRuntime.API;

    private BottomNavigationView nav;
    private FeedFragment feed;
    private ChatListFragment chat;
    private CalendarFragment calendar;
    private ProfileFragment profile;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
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
            chat = new ChatListFragment();
            calendar = new CalendarFragment();
            profile = new ProfileFragment();
            getSupportFragmentManager()
                    .beginTransaction()
                    .add(R.id.tab_host, feed, "feed")
                    .add(R.id.tab_host, chat, "chat")
                    .add(R.id.tab_host, calendar, "cal")
                    .add(R.id.tab_host, profile, "profile")
                    .hide(chat)
                    .hide(calendar)
                    .hide(profile)
                    .commit();
        } else {
            feed = (FeedFragment) getSupportFragmentManager().findFragmentByTag("feed");
            chat = (ChatListFragment) getSupportFragmentManager().findFragmentByTag("chat");
            calendar = (CalendarFragment) getSupportFragmentManager().findFragmentByTag("cal");
            profile = (ProfileFragment) getSupportFragmentManager().findFragmentByTag("profile");
        }
        nav.setOnItemSelectedListener(item -> {
            int id = item.getItemId();
            if (id == R.id.nav_feed) {
                show(feed);
            } else if (id == R.id.nav_chat) {
                show(chat);
                chat.reload();
            } else if (id == R.id.nav_cal) {
                show(calendar);
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
                if (nav.getSelectedItemId() == R.id.nav_feed && feed.goBack()) {
                    return;
                }
                if (nav.getSelectedItemId() != R.id.nav_feed) {
                    openTab(R.id.nav_feed);
                    return;
                }
                setEnabled(false);
                getOnBackPressedDispatcher().onBackPressed();
            }
        });
        maybeAskNotifications();
    }

    void openTab(int id) {
        nav.setSelectedItemId(id);
    }

    void onPortalLoaded() {
        refreshSessionAndPush();
    }

    void refreshSessionAndPush() {
        api.refreshSession(new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                if (session.loggedIn) {
                    registerPush();
                    chat.reload();
                }
            }

            @Override
            public void onErr(String message) {}
        });
    }

    private void show(Fragment target) {
        androidx.fragment.app.FragmentTransaction tx = getSupportFragmentManager().beginTransaction();
        for (Fragment fragment : new Fragment[] {feed, chat, calendar, profile}) {
            if (fragment.isAdded()) {
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
    }
}
