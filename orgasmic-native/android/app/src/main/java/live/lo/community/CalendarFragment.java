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
import java.net.URLEncoder;
import org.json.JSONArray;
import org.json.JSONObject;

public class CalendarFragment extends Fragment {
    private SwipeRefreshLayout refresh;
    private TextView empty;
    private EventAdapter adapter;

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_list, container, false);
        ((TextView) root.findViewById(R.id.screen_title)).setText("Kalender");
        refresh = root.findViewById(R.id.refresh);
        empty = root.findViewById(R.id.empty);
        RecyclerView list = root.findViewById(R.id.list);
        adapter = new EventAdapter();
        list.setLayoutManager(new LinearLayoutManager(requireContext()));
        list.setAdapter(adapter);
        refresh.setOnRefreshListener(this::reload);
        return root;
    }

    void reload() {
        if (!isAdded() || refresh == null) {
            return;
        }
        if (!HybridRuntime.SESSION.loggedIn) {
            adapter.clear();
            empty.setVisibility(View.VISIBLE);
            empty.setText("Bitte zuerst im Feed einloggen.");
            refresh.setRefreshing(false);
            return;
        }
        refresh.setRefreshing(true);
        String from;
        try {
            from = URLEncoder.encode(DateFmt.isoNowMinusDays(1), "UTF-8");
        } catch (Exception e) {
            from = "";
        }
        HybridRuntime.API.getArray(
                "orgasmic-events/v1/events?from=" + from + "&limit=40",
                "items",
                new ApiClient.ArrayCallback() {
                    @Override
                    public void onOk(JSONArray items, JSONObject raw) {
                        if (!isAdded()) {
                            return;
                        }
                        refresh.setRefreshing(false);
                        adapter.setItems(items);
                        empty.setVisibility(items.length() == 0 ? View.VISIBLE : View.GONE);
                        empty.setText("Keine anstehenden Events.");
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

    private class EventAdapter extends RecyclerView.Adapter<RowHolder> {
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
        public RowHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_row, parent, false);
            return new RowHolder(view);
        }

        @Override
        public void onBindViewHolder(@NonNull RowHolder holder, int position) {
            JSONObject event = items.optJSONObject(position);
            if (event == null) {
                return;
            }
            holder.title.setText(event.optString("title"));
            holder.sub.setText(DateFmt.eventWhen(event.optString("starts_at")));
            holder.itemView.setOnClickListener(v -> {
                Intent intent = new Intent(requireContext(), EventDetailActivity.class);
                intent.putExtra("event_id", event.optInt("id"));
                startActivity(intent);
            });
        }

        @Override
        public int getItemCount() {
            return items.length();
        }
    }

    static class RowHolder extends RecyclerView.ViewHolder {
        final TextView title;
        final TextView sub;

        RowHolder(@NonNull View itemView) {
            super(itemView);
            title = itemView.findViewById(R.id.row_title);
            sub = itemView.findViewById(R.id.row_sub);
        }
    }
}
