package live.lo.community;

import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

final class DateFmt {
    private static final String[] MONTH_SHORT = {
        "JAN", "FEB", "MÄR", "APR", "MAI", "JUN", "JUL", "AUG", "SEP", "OKT", "NOV", "DEZ"
    };
    private static final String[] MONTHS = {
        "Januar", "Februar", "März", "April", "Mai", "Juni", "Juli",
        "August", "September", "Oktober", "November", "Dezember"
    };

    private DateFmt() {}

    static TimeZone berlin() {
        return TimeZone.getTimeZone("Europe/Berlin");
    }

    static String eventWhen(String iso) {
        Date date = parseIso(iso);
        if (date == null) {
            return iso != null ? iso : "";
        }
        SimpleDateFormat out = new SimpleDateFormat("EEE, d. MMM · HH:mm", Locale.GERMAN);
        out.setTimeZone(berlin());
        return out.format(date);
    }

    static String clock(String iso) {
        Date date = parseIso(iso);
        if (date == null) {
            return "";
        }
        SimpleDateFormat out = new SimpleDateFormat("HH:mm", Locale.GERMAN);
        out.setTimeZone(berlin());
        return out.format(date);
    }

    static String relative(String iso) {
        Date date = parseIso(iso);
        if (date == null) {
            return "";
        }
        long sec = Math.max(0, (System.currentTimeMillis() - date.getTime()) / 1000L);
        if (sec < 45) {
            return "jetzt";
        }
        if (sec < 3600) {
            return (sec / 60) + " Min";
        }
        if (sec < 86400) {
            return (sec / 3600) + " Std";
        }
        SimpleDateFormat out = new SimpleDateFormat("d. MMM", Locale.GERMAN);
        out.setTimeZone(berlin());
        return out.format(date);
    }

    static String ymd(String iso) {
        Date date = parseIso(iso);
        if (date == null) {
            return iso == null ? "" : iso.length() >= 10 ? iso.substring(0, 10) : iso;
        }
        SimpleDateFormat out = new SimpleDateFormat("yyyy-MM-dd", Locale.US);
        out.setTimeZone(berlin());
        return out.format(date);
    }

    static String ymd(Calendar calendar) {
        return String.format(
                Locale.US,
                "%04d-%02d-%02d",
                calendar.get(Calendar.YEAR),
                calendar.get(Calendar.MONTH) + 1,
                calendar.get(Calendar.DAY_OF_MONTH));
    }

    static String dayNum(String iso) {
        String key = ymd(iso);
        if (key.length() < 10) {
            return "";
        }
        return String.valueOf(Integer.parseInt(key.substring(8, 10)));
    }

    static String monthShort(String iso) {
        String key = ymd(iso);
        if (key.length() < 7) {
            return "";
        }
        int month = Integer.parseInt(key.substring(5, 7));
        return month >= 1 && month <= 12 ? MONTH_SHORT[month - 1] : "";
    }

    static String monthTitle(Calendar calendar) {
        return MONTHS[calendar.get(Calendar.MONTH)] + " " + calendar.get(Calendar.YEAR);
    }

    static String isoUtc(Calendar calendar) {
        SimpleDateFormat iso = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US);
        iso.setTimeZone(TimeZone.getTimeZone("UTC"));
        return iso.format(calendar.getTime());
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
                if (pattern.endsWith("Z")) {
                    fmt.setTimeZone(TimeZone.getTimeZone("UTC"));
                }
                return fmt.parse(normalized);
            } catch (Exception ignored) {
            }
        }
        return null;
    }
}
