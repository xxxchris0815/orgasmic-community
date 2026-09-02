package live.lo.community;

import android.os.Build;
import android.os.Bundle;
import android.text.Html;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.view.WindowCompat;
import org.json.JSONObject;

public class EventDetailActivity extends AppCompatActivity {
    private int eventId;
    private TextView title;
    private TextView when;
    private TextView body;
    private TextView rsvpState;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
        setContentView(R.layout.activity_event_detail);
        eventId = getIntent().getIntExtra("event_id", 0);
        findViewById(R.id.event_back).setOnClickListener(v -> finish());
        title = findViewById(R.id.event_title);
        when = findViewById(R.id.event_when);
        body = findViewById(R.id.event_body);
        rsvpState = findViewById(R.id.event_rsvp_state);
        findViewById(R.id.rsvp_going).setOnClickListener(v -> rsvp("going"));
        findViewById(R.id.rsvp_maybe).setOnClickListener(v -> rsvp("maybe"));
        findViewById(R.id.rsvp_declined).setOnClickListener(v -> rsvp("declined"));
        load();
    }

    private void load() {
        HybridRuntime.API.get("orgasmic-events/v1/events/" + eventId, new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                title.setText(json.optString("title"));
                when.setText(DateFmt.eventWhen(json.optString("starts_at")));
                String html = json.optString("description_html", json.optString("excerpt"));
                if (Build.VERSION.SDK_INT >= 24) {
                    body.setText(Html.fromHtml(html, Html.FROM_HTML_MODE_COMPACT));
                } else {
                    body.setText(Html.fromHtml(html));
                }
                JSONObject rsvp = json.optJSONObject("rsvp");
                String mine = rsvp != null ? rsvp.optString("mine") : "";
                rsvpState.setText(mine.isEmpty() ? "Noch keine Zusage" : "Deine Zusage: " + mine);
                boolean enabled = json.optBoolean("rsvp_enabled");
                int visibility = enabled ? android.view.View.VISIBLE : android.view.View.GONE;
                findViewById(R.id.rsvp_going).setVisibility(visibility);
                findViewById(R.id.rsvp_maybe).setVisibility(visibility);
                findViewById(R.id.rsvp_declined).setVisibility(visibility);
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(EventDetailActivity.this, message, Toast.LENGTH_LONG).show();
            }
        });
    }

    private void rsvp(String status) {
        JSONObject bodyJson = new JSONObject();
        try {
            bodyJson.put("status", status);
        } catch (Exception ignored) {
        }
        HybridRuntime.API.post("orgasmic-events/v1/events/" + eventId + "/rsvp", bodyJson, new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                load();
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(EventDetailActivity.this, message, Toast.LENGTH_LONG).show();
            }
        });
    }
}
