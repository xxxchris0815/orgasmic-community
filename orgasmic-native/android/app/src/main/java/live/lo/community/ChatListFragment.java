package live.lo.community;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;
import org.json.JSONArray;
import org.json.JSONObject;

public class ChatListFragment extends Fragment {
    private SwipeRefreshLayout refresh;
    private TextView empty;
    private RoomAdapter adapter;

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_list, container, false);
        ((TextView) root.findViewById(R.id.screen_title)).setText("Chat");
        refresh = root.findViewById(R.id.refresh);
        empty = root.findViewById(R.id.empty);
        RecyclerView list = root.findViewById(R.id.list);
        adapter = new RoomAdapter();
        list.setLayoutManager(new LinearLayoutManager(requireContext()));
        list.setAdapter(adapter);
        refresh.setOnRefreshListener(this::reload);
        return root;
    }

    void reload() {
        if (!isAdded() || refresh == null) {
            return;
        }
        MainActivity host = (MainActivity) requireActivity();
        if (!host.session.loggedIn) {
            adapter.clear();
            empty.setVisibility(View.VISIBLE);
            empty.setText("Bitte zuerst im Feed einloggen.");
            refresh.setRefreshing(false);
            return;
        }
        refresh.setRefreshing(true);
        host.api.getArray("orgasmic-chat/v1/rooms", "rooms", new ApiClient.ArrayCallback() {
            @Override
            public void onOk(JSONArray items, JSONObject raw) {
                if (!isAdded()) {
                    return;
                }
                refresh.setRefreshing(false);
                adapter.setItems(items);
                empty.setVisibility(items.length() == 0 ? View.VISIBLE : View.GONE);
                empty.setText("Noch keine Chats.");
            }

            @Override
            public void onErr(String message) {
                if (!isAdded()) {
                    return;
                }
                refresh.setRefreshing(false);
                empty.setVisibility(View.VISIBLE);
                empty.setText(message);
            }
        });
    }

    private class RoomAdapter extends RecyclerView.Adapter<RoomHolder> {
        private JSONArray items = new JSONArray();

        void setItems(JSONArray next) {
            items = next;
            notifyDataSetChanged();
        }

        void clear() {
            items = new JSONArray();
            notifyDataSetChanged();
        }

        @NonNull
        @Override
        public RoomHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_row, parent, false);
            return new RoomHolder(view);
        }

        @Override
        public void onBindViewHolder(@NonNull RoomHolder holder, int position) {
            JSONObject room = items.optJSONObject(position);
            if (room == null) {
                return;
            }
            holder.title.setText(room.optString("title"));
            JSONObject last = room.optJSONObject("last_message");
            String preview = "";
            if (last != null) {
                preview = last.optString("preview", last.optString("body"));
            }
            int unread = room.optInt("unread");
            holder.sub.setText(unread > 0 ? unread + " ungelesen · " + preview : preview);
            holder.itemView.setOnClickListener(v -> {
                Intent intent = new Intent(requireContext(), ChatThreadActivity.class);
                intent.putExtra("space_id", room.optInt("space_id"));
                intent.putExtra("title", room.optString("title"));
                startActivity(intent);
            });
        }

        @Override
        public int getItemCount() {
            return items.length();
        }
    }

    static class RoomHolder extends RecyclerView.ViewHolder {
        final TextView title;
        final TextView sub;

        RoomHolder(@NonNull View itemView) {
            super(itemView);
            title = itemView.findViewById(R.id.row_title);
            sub = itemView.findViewById(R.id.row_sub);
        }
    }
}
