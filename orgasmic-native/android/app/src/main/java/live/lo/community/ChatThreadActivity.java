package live.lo.community;

import android.Manifest;
import android.content.pm.PackageManager;
import android.media.MediaPlayer;
import android.media.MediaRecorder;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.webkit.CookieManager;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ImageButton;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.view.WindowCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;
import org.json.JSONArray;
import org.json.JSONObject;

public class ChatThreadActivity extends AppCompatActivity {
    private int spaceId;
    private int meId;
    private boolean canManage;
    private MessageAdapter adapter;
    private EditText input;
    private TextView replyBar;
    private ImageButton deleteBtn;
    private ImageButton micBtn;
    private ApiClient api;
    private int replyToId;
    private String replyPreview = "";
    private final Set<Integer> selected = new HashSet<>();
    private MediaRecorder recorder;
    private File recordFile;
    private long recordStarted;
    private boolean recording;
    private MediaPlayer player;
    private int playingId;
    private final ActivityResultLauncher<String> imagePicker =
            registerForActivityResult(new ActivityResultContracts.GetContent(), uri -> {
                if (uri != null) {
                    uploadAndSend(uri, "image/jpeg", "image.jpg", 0);
                }
            });

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
        setContentView(R.layout.activity_chat_thread);
        spaceId = getIntent().getIntExtra("space_id", 0);
        canManage = getIntent().getBooleanExtra("can_manage", false);
        String title = getIntent().getStringExtra("title");
        String logo = getIntent().getStringExtra("logo");
        TextView heading = findViewById(R.id.thread_title);
        heading.setText(title != null ? title : "Chat");
        Avatars.bind(findViewById(R.id.thread_avatar), findViewById(R.id.thread_letter), logo, title);
        findViewById(R.id.thread_avatar).setClipToOutline(true);
        findViewById(R.id.thread_back).setOnClickListener(v -> onBack());
        deleteBtn = findViewById(R.id.thread_delete);
        deleteBtn.setOnClickListener(v -> deleteSelected());
        api = HybridRuntime.API;
        meId = HybridRuntime.SESSION.userId;
        RecyclerView list = findViewById(R.id.thread_list);
        LinearLayoutManager layout = new LinearLayoutManager(this);
        layout.setStackFromEnd(true);
        list.setLayoutManager(layout);
        adapter = new MessageAdapter();
        list.setAdapter(adapter);
        input = findViewById(R.id.thread_input);
        replyBar = findViewById(R.id.thread_reply);
        replyBar.setOnClickListener(v -> clearReply());
        micBtn = findViewById(R.id.thread_mic);
        findViewById(R.id.thread_send).setOnClickListener(v -> sendText());
        findViewById(R.id.thread_image).setOnClickListener(v -> imagePicker.launch("image/*"));
        micBtn.setOnClickListener(v -> toggleVoice());
        load();
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 81 && grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            startRecord();
        }
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        stopPlayback();
        cancelRecord(true);
    }

    private void onBack() {
        if (!selected.isEmpty()) {
            selected.clear();
            adapter.notifyDataSetChanged();
            updateSelectUi();
            return;
        }
        finish();
    }

    private void load() {
        api.getArray("orgasmic-chat/v1/rooms/" + spaceId + "/messages?limit=40", "items", new ApiClient.ArrayCallback() {
            @Override
            public void onOk(JSONArray items, JSONObject raw) {
                adapter.setItems(items);
                RecyclerView list = findViewById(R.id.thread_list);
                if (adapter.getItemCount() > 0) {
                    list.scrollToPosition(adapter.getItemCount() - 1);
                }
                JSONObject last = items.optJSONObject(items.length() - 1);
                if (last != null) {
                    JSONObject body = new JSONObject();
                    try {
                        body.put("last_id", last.optInt("id"));
                    } catch (Exception ignored) {
                    }
                    api.post("orgasmic-chat/v1/rooms/" + spaceId + "/read", body, new ApiClient.JsonCallback() {
                        @Override
                        public void onOk(JSONObject json) {}

                        @Override
                        public void onErr(String message) {}
                    });
                }
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(ChatThreadActivity.this, message, Toast.LENGTH_LONG).show();
            }
        });
    }

    private void sendText() {
        String text = input.getText().toString().trim();
        if (text.isEmpty()) {
            return;
        }
        sendMessage(text, 0);
        input.setText("");
    }

    private void sendMessage(String text, int attachmentId) {
        JSONObject body = new JSONObject();
        try {
            body.put("body", text == null ? "" : text);
            if (attachmentId > 0) {
                body.put("attachment_id", attachmentId);
            }
            if (replyToId > 0) {
                body.put("reply_to", replyToId);
            }
        } catch (Exception ignored) {
        }
        clearReply();
        api.post("orgasmic-chat/v1/rooms/" + spaceId + "/messages", body, new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                load();
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(ChatThreadActivity.this, message, Toast.LENGTH_LONG).show();
            }
        });
    }

    private void toggleVoice() {
        if (recording) {
            stopRecordAndSend();
            return;
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
                != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, new String[] {Manifest.permission.RECORD_AUDIO}, 81);
            return;
        }
        startRecord();
    }

    private void startRecord() {
        try {
            recordFile = new File(getCacheDir(), "voice-" + System.currentTimeMillis() + ".m4a");
            recorder = Build.VERSION.SDK_INT >= 31 ? new MediaRecorder(this) : new MediaRecorder();
            recorder.setAudioSource(MediaRecorder.AudioSource.MIC);
            recorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
            recorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
            recorder.setOutputFile(recordFile.getAbsolutePath());
            recorder.prepare();
            recorder.start();
            recording = true;
            recordStarted = System.currentTimeMillis();
            micBtn.setImageResource(R.drawable.ic_nav_stop);
            Toast.makeText(this, "Aufnahme läuft — erneut tippen zum Senden", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            cancelRecord(true);
            Toast.makeText(this, "Mikrofon nicht verfügbar", Toast.LENGTH_LONG).show();
        }
    }

    private void stopRecordAndSend() {
        int seconds = (int) Math.max(1, (System.currentTimeMillis() - recordStarted) / 1000L);
        File file = recordFile;
        cancelRecord(false);
        if (file == null || !file.exists() || file.length() < 200) {
            Toast.makeText(this, "Aufnahme zu kurz", Toast.LENGTH_SHORT).show();
            return;
        }
        api.upload(file, "audio/mp4", file.getName(), Math.min(90, seconds), new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                sendMessage("", json.optInt("id"));
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(ChatThreadActivity.this, message, Toast.LENGTH_LONG).show();
            }
        });
    }

    private void cancelRecord(boolean deleteFile) {
        recording = false;
        micBtn.setImageResource(R.drawable.ic_nav_mic);
        if (recorder != null) {
            try {
                recorder.stop();
            } catch (Exception ignored) {
            }
            recorder.release();
            recorder = null;
        }
        if (deleteFile && recordFile != null) {
            recordFile.delete();
        }
        recordFile = deleteFile ? null : recordFile;
    }

    private void uploadAndSend(Uri uri, String mime, String filename, int duration) {
        try {
            File file = new File(getCacheDir(), filename);
            InputStream in = getContentResolver().openInputStream(uri);
            FileOutputStream out = new FileOutputStream(file);
            byte[] buf = new byte[8192];
            int n;
            while (in != null && (n = in.read(buf)) >= 0) {
                out.write(buf, 0, n);
            }
            if (in != null) {
                in.close();
            }
            out.close();
            api.upload(file, mime, filename, duration, new ApiClient.JsonCallback() {
                @Override
                public void onOk(JSONObject json) {
                    sendMessage("", json.optInt("id"));
                }

                @Override
                public void onErr(String message) {
                    Toast.makeText(ChatThreadActivity.this, message, Toast.LENGTH_LONG).show();
                }
            });
        } catch (Exception e) {
            Toast.makeText(this, "Datei konnte nicht gelesen werden", Toast.LENGTH_LONG).show();
        }
    }

    private void setReply(JSONObject msg) {
        replyToId = msg.optInt("id");
        JSONObject author = msg.optJSONObject("author");
        String name = author != null ? author.optString("display_name") : "Nachricht";
        replyPreview = name + ": " + previewOf(msg);
        replyBar.setText("Antwort an " + replyPreview + "  ×");
        replyBar.setVisibility(View.VISIBLE);
    }

    private void clearReply() {
        replyToId = 0;
        replyPreview = "";
        replyBar.setVisibility(View.GONE);
    }

    private void toggleSelect(int id) {
        if (selected.contains(id)) {
            selected.remove(id);
        } else {
            selected.add(id);
        }
        adapter.notifyDataSetChanged();
        updateSelectUi();
    }

    private void updateSelectUi() {
        deleteBtn.setVisibility(selected.isEmpty() ? View.GONE : View.VISIBLE);
    }

    private void deleteSelected() {
        if (selected.isEmpty()) {
            return;
        }
        Integer[] ids = selected.toArray(new Integer[0]);
        deleteNext(ids, 0);
    }

    private void deleteNext(Integer[] ids, int index) {
        if (index >= ids.length) {
            selected.clear();
            updateSelectUi();
            load();
            return;
        }
        api.delete("orgasmic-chat/v1/messages/" + ids[index], new ApiClient.JsonCallback() {
            @Override
            public void onOk(JSONObject json) {
                deleteNext(ids, index + 1);
            }

            @Override
            public void onErr(String message) {
                Toast.makeText(ChatThreadActivity.this, message, Toast.LENGTH_LONG).show();
                deleteNext(ids, index + 1);
            }
        });
    }

    private boolean canDelete(JSONObject msg) {
        return (msg.optInt("user_id") == meId && meId > 0) || canManage;
    }

    private String previewOf(JSONObject msg) {
        String body = msg.optString("body");
        if (!body.isEmpty()) {
            return body;
        }
        JSONObject att = msg.optJSONObject("attachment");
        if (att != null && "audio".equals(att.optString("kind"))) {
            return "Sprachnachricht";
        }
        return "Bild";
    }

    private void playVoice(int id, String url) {
        if (playingId == id) {
            stopPlayback();
            adapter.notifyDataSetChanged();
            return;
        }
        stopPlayback();
        try {
            player = new MediaPlayer();
            Map<String, String> headers = new HashMap<>();
            String cookie = CookieManager.getInstance().getCookie(HybridConfig.ORIGIN);
            if (cookie != null && !cookie.isEmpty()) {
                headers.put("Cookie", cookie);
            }
            player.setDataSource(this, Uri.parse(url), headers);
            player.setOnPreparedListener(mp -> {
                mp.start();
                playingId = id;
                adapter.notifyDataSetChanged();
            });
            player.setOnCompletionListener(mp -> {
                stopPlayback();
                adapter.notifyDataSetChanged();
            });
            player.prepareAsync();
            playingId = id;
            adapter.notifyDataSetChanged();
        } catch (Exception e) {
            Toast.makeText(this, "Wiedergabe fehlgeschlagen", Toast.LENGTH_SHORT).show();
        }
    }

    private void stopPlayback() {
        playingId = 0;
        if (player != null) {
            try {
                player.stop();
            } catch (Exception ignored) {
            }
            player.release();
            player = null;
        }
    }

    private void showActions(JSONObject msg) {
        boolean deletable = canDelete(msg);
        String[] items = deletable
                ? new String[] {"Antworten", "Markieren", "Löschen"}
                : new String[] {"Antworten", "Markieren"};
        new AlertDialog.Builder(this)
                .setItems(items, (dialog, which) -> {
                    if (which == 0) {
                        setReply(msg);
                    } else if (which == 1) {
                        toggleSelect(msg.optInt("id"));
                    } else if (deletable) {
                        selected.clear();
                        selected.add(msg.optInt("id"));
                        deleteSelected();
                    }
                })
                .show();
    }

    private class MessageAdapter extends RecyclerView.Adapter<MsgHolder> {
        private JSONArray items = new JSONArray();

        void setItems(JSONArray next) {
            items = next;
            notifyDataSetChanged();
        }

        @NonNull
        @Override
        public MsgHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_message, parent, false);
            return new MsgHolder(view);
        }

        @Override
        public void onBindViewHolder(@NonNull MsgHolder holder, int position) {
            JSONObject msg = items.optJSONObject(position);
            if (msg == null) {
                return;
            }
            JSONObject author = msg.optJSONObject("author");
            String name = author != null ? author.optString("display_name") : "";
            String avatar = author != null ? author.optString("avatar") : "";
            boolean mine = msg.optInt("user_id") == meId && meId > 0;
            int id = msg.optInt("id");
            holder.author.setText(mine ? "Du" : name);
            Avatars.bind(holder.avatar, holder.letter, avatar, mine ? "Du" : name);
            holder.avatar.setClipToOutline(true);
            holder.avatarWrap.setVisibility(mine ? View.GONE : View.VISIBLE);
            ((LinearLayout) holder.itemView).setGravity(mine ? Gravity.END : Gravity.START);
            holder.bubble.setBackgroundResource(mine ? R.drawable.bg_bubble_mine : R.drawable.bg_bubble_theirs);
            JSONObject reply = msg.optJSONObject("reply");
            if (reply != null && reply.optInt("id") > 0) {
                holder.quote.setVisibility(View.VISIBLE);
                JSONObject rAuthor = reply.optJSONObject("author");
                String rName = rAuthor != null ? rAuthor.optString("display_name") : "";
                holder.quote.setText(rName + ": " + reply.optString("body", previewOf(reply)));
            } else {
                holder.quote.setVisibility(View.GONE);
            }
            JSONObject att = msg.optJSONObject("attachment");
            String body = msg.optString("body");
            holder.photo.setVisibility(View.GONE);
            holder.voice.setVisibility(View.GONE);
            holder.body.setVisibility(View.GONE);
            if (att != null && "image".equals(att.optString("kind"))) {
                holder.photo.setVisibility(View.VISIBLE);
                Avatars.bindImage(holder.photo, att.optString("thumb", att.optString("url")));
            } else if (att != null && "audio".equals(att.optString("kind"))) {
                holder.voice.setVisibility(View.VISIBLE);
                int dur = att.optInt("duration");
                holder.voiceDur.setText(dur > 0 ? dur + " s" : "Sprachnachricht");
                holder.voicePlay.setImageResource(playingId == id ? R.drawable.ic_nav_stop : R.drawable.ic_nav_play);
                holder.voicePlay.setOnClickListener(v -> playVoice(id, att.optString("url")));
            } else {
                holder.body.setVisibility(View.VISIBLE);
                holder.body.setText(body);
            }
            if (!body.isEmpty() && att != null && "image".equals(att.optString("kind"))) {
                holder.body.setVisibility(View.VISIBLE);
                holder.body.setText(body);
            }
            holder.itemView.setBackgroundColor(selected.contains(id) ? 0x33121C30 : 0x00000000);
            holder.itemView.setOnLongClickListener(v -> {
                showActions(msg);
                return true;
            });
            holder.itemView.setOnClickListener(v -> {
                if (!selected.isEmpty()) {
                    toggleSelect(id);
                }
            });
        }

        @Override
        public int getItemCount() {
            return items.length();
        }
    }

    static class MsgHolder extends RecyclerView.ViewHolder {
        final View avatarWrap;
        final ImageView avatar;
        final TextView letter;
        final View bubble;
        final TextView author;
        final TextView quote;
        final ImageView photo;
        final View voice;
        final ImageButton voicePlay;
        final TextView voiceDur;
        final TextView body;

        MsgHolder(@NonNull View itemView) {
            super(itemView);
            avatarWrap = itemView.findViewById(R.id.msg_avatar_wrap);
            avatar = itemView.findViewById(R.id.msg_avatar);
            letter = itemView.findViewById(R.id.msg_letter);
            bubble = itemView.findViewById(R.id.msg_bubble);
            author = itemView.findViewById(R.id.msg_author);
            quote = itemView.findViewById(R.id.msg_quote);
            photo = itemView.findViewById(R.id.msg_photo);
            voice = itemView.findViewById(R.id.msg_voice);
            voicePlay = itemView.findViewById(R.id.msg_voice_play);
            voiceDur = itemView.findViewById(R.id.msg_voice_dur);
            body = itemView.findViewById(R.id.msg_body);
        }
    }
}
