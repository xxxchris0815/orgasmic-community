package live.lo.community;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.text.InputType;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.CompoundButton;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.Fragment;
import com.google.android.material.switchmaterial.SwitchMaterial;
import org.json.JSONObject;

public class ProfileFragment extends Fragment {
    private TextView name;
    private TextView status;
    private SwitchMaterial chat;
    private SwitchMaterial feed;
    private SwitchMaterial comment;
    private SwitchMaterial event;
    private boolean applying;

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_profile, container, false);
        name = root.findViewById(R.id.profile_name);
        status = root.findViewById(R.id.profile_status);
        chat = root.findViewById(R.id.pref_chat);
        feed = root.findViewById(R.id.pref_feed);
        comment = root.findViewById(R.id.pref_comment);
        event = root.findViewById(R.id.pref_event);
        CompoundButton.OnCheckedChangeListener listener = (button, checked) -> {
            if (!applying) {
                savePrefs();
            }
        };
        chat.setOnCheckedChangeListener(listener);
        feed.setOnCheckedChangeListener(listener);
        comment.setOnCheckedChangeListener(listener);
        event.setOnCheckedChangeListener(listener);
        root.findViewById(R.id.link_privacy).setOnClickListener(v -> openUrl(HybridRuntime.SESSION.privacyUrl));
        root.findViewById(R.id.link_safety).setOnClickListener(v -> {
            String url = HybridRuntime.SESSION.safetyUrl;
            openUrl(url == null || url.isEmpty() ? HybridConfig.ORIGIN + "/kinderschutz" : url);
        });
        root.findViewById(R.id.btn_delete).setOnClickListener(v -> confirmDelete());
        return root;
    }

    void reload() {
        if (!isAdded() || name == null) {
            return;
        }
        HybridRuntime.API.refreshSession(new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                bind();
            }

            @Override
            public void onErr(String message) {
                status.setText(message);
            }
        });
    }

    private void bind() {
        Session session = HybridRuntime.SESSION;
        if (!session.loggedIn) {
            name.setText("Nicht angemeldet");
            status.setText("Bitte zuerst im Feed einloggen.");
            return;
        }
        name.setText(session.displayName.isEmpty() ? "Mitglied" : session.displayName);
        status.setText("Angemeldet");
        JSONObject prefs = session.snapshotPrefs();
        applying = true;
        if (prefs != null) {
            chat.setChecked(prefs.optBoolean("chat", true));
            feed.setChecked(prefs.optBoolean("feed", true));
            comment.setChecked(prefs.optBoolean("comment", true));
            event.setChecked(prefs.optBoolean("event", true));
        }
        applying = false;
        View privacy = requireView().findViewById(R.id.link_privacy);
        privacy.setVisibility(session.privacyUrl == null || session.privacyUrl.isEmpty() ? View.GONE : View.VISIBLE);
    }

    private void savePrefs() {
        JSONObject prefs = new JSONObject();
        JSONObject body = new JSONObject();
        try {
            prefs.put("chat", chat.isChecked());
            prefs.put("feed", feed.isChecked());
            prefs.put("comment", comment.isChecked());
            prefs.put("event", event.isChecked());
            body.put("prefs", prefs);
        } catch (Exception ignored) {
        }
        HybridRuntime.API.post("orgasmic-app/v1/prefs", body, new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                JSONObject saved = json.optJSONObject("prefs");
                if (saved != null) {
                    HybridRuntime.SESSION.prefs = saved;
                }
                status.setText("Gespeichert");
            }

            @Override
            public void onErr(String message) {
                status.setText(message);
            }
        });
    }

    private void confirmDelete() {
        if (!HybridRuntime.SESSION.loggedIn) {
            Toast.makeText(requireContext(), "Bitte zuerst einloggen.", Toast.LENGTH_SHORT).show();
            return;
        }
        EditText field = new EditText(requireContext());
        field.setHint("DELETE");
        field.setInputType(InputType.TYPE_CLASS_TEXT);
        field.setTextColor(ContextCompat.getColor(requireContext(), R.color.ink));
        field.setHintTextColor(ContextCompat.getColor(requireContext(), R.color.ink_muted));
        new AlertDialog.Builder(requireContext())
                .setTitle("Konto löschen")
                .setMessage("Tippe DELETE, um dein Konto unwiderruflich zu löschen.")
                .setView(field)
                .setNegativeButton("Abbrechen", null)
                .setPositiveButton("Löschen", (d, w) -> {
                    if (!"DELETE".equals(field.getText().toString().trim())) {
                        Toast.makeText(requireContext(), "Löschung abgebrochen.", Toast.LENGTH_SHORT).show();
                        return;
                    }
                    JSONObject body = new JSONObject();
                    try {
                        body.put("confirm", "DELETE");
                    } catch (Exception ignored) {
                    }
                    HybridRuntime.API.post("orgasmic-app/v1/account/delete", body, new ApiClient.JsonCallback() {
                        @Override
                        public void onOk(JSONObject json) {
                            android.webkit.CookieManager.getInstance().removeAllCookies(null);
                            HybridRuntime.SESSION.loggedIn = false;
                            MainActivity host = (MainActivity) requireActivity();
                            host.openTab(R.id.nav_feed);
                        }

                        @Override
                        public void onErr(String message) {
                            Toast.makeText(requireContext(), message, Toast.LENGTH_LONG).show();
                        }
                    });
                })
                .show();
    }

    private void openUrl(String url) {
        if (url == null || url.isEmpty()) {
            return;
        }
        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
    }
}
