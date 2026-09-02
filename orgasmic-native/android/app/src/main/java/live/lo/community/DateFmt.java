package live.lo.community;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

final class DateFmt {
    private DateFmt() {}

    static String eventWhen(String iso) {
        Date date = parseIso(iso);
        if (date == null) {
            return iso != null ? iso : "";
        }
        SimpleDateFormat out = new SimpleDateFormat("EEE, d. MMM · HH:mm", Locale.GERMAN);
        out.setTimeZone(TimeZone.getTimeZone("Europe/Berlin"));
        return out.format(date);
    }

    static String isoNowMinusDays(int days) {
        long ms = System.currentTimeMillis() - days * 24L * 60L * 60L * 1000L;
        SimpleDateFormat iso = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US);
        iso.setTimeZone(TimeZone.getTimeZone("UTC"));
        return iso.format(new Date(ms));
    }

    static Date parseIso(String raw) {
        if (raw == null || raw.isEmpty()) {
            return null;
        }
        String normalized = raw.trim().replace("Z", "+0000");
        normalized = normalized.replaceAll("([+-]\\d{2}):(\\d{2})$", "$1$2");
        String[] patterns = {
            "yyyy-MM-dd'T'HH:mm:ssZ",
            "yyyy-MM-dd'T'HH:mm:ss",
            "yyyy-MM-dd HH:mm:ss"
        };
        for (String pattern : patterns) {
            try {
                SimpleDateFormat fmt = new SimpleDateFormat(pattern, Locale.US);
                if (pattern.endsWith("'Z'") || pattern.endsWith("Z")) {
                    fmt.setTimeZone(TimeZone.getTimeZone("UTC"));
                }
                return fmt.parse(normalized);
            } catch (Exception ignored) {
            }
        }
        return null;
    }
}
