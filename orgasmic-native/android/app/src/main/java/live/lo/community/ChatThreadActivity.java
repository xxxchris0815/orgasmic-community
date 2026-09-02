package live.lo.community;

import android.os.Bundle;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ImageButton;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.core.view.WindowCompat;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import org.json.JSONArray;
import org.json.JSONObject;

public class ChatThreadActivity extends AppCompatActivity {
    private int spaceId;
    private int meId;
    private MessageAdapter adapter;
    private EditText input;
    private ApiClient api;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
        setContentView(R.layout.activity_chat_thread);
        spaceId = getIntent().getIntExtra("space_id", 0);
        String title = getIntent().getStringExtra("title");
        TextView heading = findViewById(R.id.thread_title);
        heading.setText(title != null ? title : "Chat");
        findViewById(R.id.thread_back).setOnClickListener(v -> finish());
        api = HybridRuntime.API;
        meId = HybridRuntime.SESSION.userId;
        RecyclerView list = findViewById(R.id.thread_list);
        LinearLayoutManager layout = new LinearLayoutManager(this);
        layout.setStackFromEnd(true);
        list.setLayoutManager(layout);
        adapter = new MessageAdapter();
        list.setAdapter(adapter);
        input = findViewById(R.id.thread_input);
        ImageButton send = findViewById(R.id.thread_send);
        send.setOnClickListener(v -> sendMessage());
        load();
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

    private void sendMessage() {
        String text = input.getText().toString().trim();
        if (text.isEmpty()) {
            return;
        }
        JSONObject body = new JSONObject();
        try {
            body.put("body", text);
        } catch (Exception ignored) {
        }
        input.setText("");
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
            String body = msg.optString("body");
            JSONObject att = msg.optJSONObject("attachment");
            if (body.isEmpty() && att != null) {
                String kind = att.optString("kind");
                body = "audio".equals(kind) ? "Sprachnachricht" : "Bild";
            }
            boolean mine = msg.optInt("user_id") == meId && meId > 0;
            holder.author.setText(mine ? "Du" : name);
            holder.body.setText(body);
            LinearLayout.LayoutParams params = (LinearLayout.LayoutParams) holder.bubble.getLayoutParams();
            params.gravity = mine ? Gravity.END : Gravity.START;
            holder.bubble.setLayoutParams(params);
            holder.bubble.setBackgroundColor(ContextCompat.getColor(
                    ChatThreadActivity.this, mine ? R.color.bubble_mine : R.color.bubble_theirs));
        }

        @Override
        public int getItemCount() {
            return items.length();
        }
    }

    static class MsgHolder extends RecyclerView.ViewHolder {
        final View bubble;
        final TextView author;
        final TextView body;

        MsgHolder(@NonNull View itemView) {
            super(itemView);
            bubble = itemView.findViewById(R.id.msg_bubble);
            author = itemView.findViewById(R.id.msg_author);
            body = itemView.findViewById(R.id.msg_body);
        }
    }
}
