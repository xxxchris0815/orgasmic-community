package live.lo.community;

import android.content.Intent;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.GridLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.Fragment;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;
import java.net.URLEncoder;
import java.util.Calendar;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import org.json.JSONArray;
import org.json.JSONObject;

public class CalendarFragment extends Fragment {
    private static final String[] DOW = {"Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"};
    private SwipeRefreshLayout refresh;
    private TextView empty;
    private TextView monthLabel;
    private GridLayout grid;
    private LinearLayout list;
    private Calendar month;
    private String selectedDay;
    private JSONArray events = new JSONArray();

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_calendar, container, false);
        refresh = root.findViewById(R.id.refresh);
        empty = root.findViewById(R.id.empty);
        monthLabel = root.findViewById(R.id.cal_month);
        grid = root.findViewById(R.id.cal_grid);
        list = root.findViewById(R.id.cal_list);
        month = Calendar.getInstance(DateFmt.berlin(), Locale.GERMAN);
        month.set(Calendar.DAY_OF_MONTH, 1);
        root.findViewById(R.id.cal_prev).setOnClickListener(v -> {
            month.add(Calendar.MONTH, -1);
            selectedDay = null;
            reload();
        });
        root.findViewById(R.id.cal_next).setOnClickListener(v -> {
            month.add(Calendar.MONTH, 1);
            selectedDay = null;
            reload();
        });
        refresh.setOnRefreshListener(this::reload);
        return root;
    }

    @Override
    public void onResume() {
        super.onResume();
        if (!isHidden()) {
            reload();
        }
    }

    void reload() {
        if (!isAdded() || refresh == null) {
            return;
        }
        if (!HybridRuntime.SESSION.loggedIn) {
            events = new JSONArray();
            render();
            empty.setVisibility(View.VISIBLE);
            empty.setText("Bitte zuerst im Feed einloggen.");
            refresh.setRefreshing(false);
            return;
        }
        refresh.setRefreshing(true);
        Calendar fromCal = (Calendar) month.clone();
        fromCal.set(Calendar.DAY_OF_MONTH, 1);
        fromCal.set(Calendar.HOUR_OF_DAY, 0);
        Calendar toCal = (Calendar) fromCal.clone();
        toCal.add(Calendar.MONTH, 1);
        toCal.add(Calendar.SECOND, -1);
        String from;
        String to;
        try {
            from = URLEncoder.encode(DateFmt.isoUtc(fromCal), "UTF-8");
            to = URLEncoder.encode(DateFmt.isoUtc(toCal), "UTF-8");
        } catch (Exception e) {
            from = "";
            to = "";
        }
        HybridRuntime.API.getArray(
                "orgasmic-events/v1/events?from=" + from + "&to=" + to + "&limit=80",
                "items",
                new ApiClient.ArrayCallback() {
                    @Override
                    public void onOk(JSONArray items, JSONObject raw) {
                        if (!isAdded()) {
                            return;
                        }
                        refresh.setRefreshing(false);
                        events = items;
                        render();
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

    private void render() {
        monthLabel.setText(DateFmt.monthTitle(month));
        buildGrid();
        list.removeAllViews();
        int shown = 0;
        LayoutInflater inflater = LayoutInflater.from(requireContext());
        for (int i = 0; i < events.length(); i += 1) {
            JSONObject event = events.optJSONObject(i);
            if (event == null) {
                continue;
            }
            String day = DateFmt.ymd(event.optString("starts_at"));
            if (selectedDay != null && !selectedDay.equals(day)) {
                continue;
            }
            shown += 1;
            View card = inflater.inflate(R.layout.item_event, list, false);
            ((TextView) card.findViewById(R.id.event_day)).setText(DateFmt.dayNum(event.optString("starts_at")));
            ((TextView) card.findViewById(R.id.event_month)).setText(DateFmt.monthShort(event.optString("starts_at")));
            ((TextView) card.findViewById(R.id.event_title)).setText(event.optString("title"));
            ((TextView) card.findViewById(R.id.event_when)).setText(DateFmt.eventWhen(event.optString("starts_at")));
            JSONObject rsvp = event.optJSONObject("rsvp");
            int going = 0;
            if (rsvp != null) {
                JSONObject counts = rsvp.optJSONObject("counts");
                if (counts != null) {
                    going = counts.optInt("going");
                }
            }
            ((TextView) card.findViewById(R.id.event_meta)).setText(
                    going + (going == 1 ? " Person sagt zu" : " sagen zu"));
            card.setOnClickListener(v -> {
                Intent intent = new Intent(requireContext(), EventDetailActivity.class);
                intent.putExtra("event_id", event.optInt("id"));
                startActivity(intent);
            });
            list.addView(card);
        }
        if (HybridRuntime.SESSION.loggedIn) {
            empty.setVisibility(shown == 0 ? View.VISIBLE : View.GONE);
            empty.setText(selectedDay != null ? "Keine Events an diesem Tag." : "Keine Events in diesem Monat.");
        }
    }

    private void buildGrid() {
        grid.removeAllViews();
        int cell = (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, 40, getResources().getDisplayMetrics());
        for (String label : DOW) {
            TextView dow = dayView(label, cell, false, false, false, null);
            dow.setTextColor(col(R.color.ink_muted));
            grid.addView(dow);
        }
        Calendar cursor = (Calendar) month.clone();
        cursor.set(Calendar.DAY_OF_MONTH, 1);
        int startOffset = (cursor.get(Calendar.DAY_OF_WEEK) + 5) % 7;
        cursor.add(Calendar.DAY_OF_MONTH, -startOffset);
        Calendar today = Calendar.getInstance(DateFmt.berlin(), Locale.GERMAN);
        String todayKey = DateFmt.ymd(today);
        Set<String> daysWithEvents = new HashSet<>();
        for (int i = 0; i < events.length(); i += 1) {
            JSONObject event = events.optJSONObject(i);
            if (event != null) {
                daysWithEvents.add(DateFmt.ymd(event.optString("starts_at")));
            }
        }
        for (int i = 0; i < 42; i += 1) {
            String key = DateFmt.ymd(cursor);
            boolean mute = cursor.get(Calendar.MONTH) != month.get(Calendar.MONTH);
            boolean isToday = todayKey.equals(key);
            boolean selected = key.equals(selectedDay);
            boolean has = daysWithEvents.contains(key);
            TextView cellView = dayView(String.valueOf(cursor.get(Calendar.DAY_OF_MONTH)), cell, mute, isToday, selected, key);
            if (has) {
                cellView.setPaintFlags(cellView.getPaintFlags() | android.graphics.Paint.UNDERLINE_TEXT_FLAG);
            }
            grid.addView(cellView);
            cursor.add(Calendar.DAY_OF_MONTH, 1);
        }
    }

    private TextView dayView(String text, int size, boolean mute, boolean today, boolean selected, String key) {
        TextView view = new TextView(requireContext());
        GridLayout.LayoutParams params = new GridLayout.LayoutParams();
        params.width = 0;
        params.height = size;
        params.columnSpec = GridLayout.spec(GridLayout.UNDEFINED, 1f);
        view.setLayoutParams(params);
        view.setGravity(Gravity.CENTER);
        view.setText(text);
        view.setTextSize(13);
        view.setTextColor(col(mute ? R.color.ink_faint : R.color.ink));
        if (today) {
            view.setBackgroundResource(R.drawable.bg_avatar);
        }
        if (selected) {
            view.setTextColor(col(R.color.on_dateblock));
            view.setBackgroundResource(R.drawable.bg_dateblock);
        }
        if (key != null) {
            view.setOnClickListener(v -> {
                selectedDay = key.equals(selectedDay) ? null : key;
                render();
            });
        }
        return view;
    }

    private int col(int id) {
        return ContextCompat.getColor(requireContext(), id);
    }
}
