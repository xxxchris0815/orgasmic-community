package live.lo.community;

final class HybridConfig {
    static final String ORIGIN = "https://community.orgasmic.live";
    static final String PORTAL = ORIGIN + "/portal";
    static final String AJAX = ORIGIN + "/wp-admin/admin-ajax.php";
    static final String REST = ORIGIN + "/wp-json/";
    static final String UA_MARK = "LOCommunityHybrid/1";
    static final String SHELL_CSS =
            "html.orgasmic-hybrid-shell .fcom_mobile_menu,"
                    + "html.orgasmic-hybrid-shell .fcom-mobile-menu,"
                    + "html.orgasmic-hybrid-shell .fcom_mobile_nav,"
                    + "html.orgasmic-hybrid-shell [class*=\"mobile_menu\"],"
                    + "html.orgasmic-hybrid-shell [class*=\"mobile-menu\"],"
                    + "html.orgasmic-hybrid-shell [class*=\"bottom-nav\"],"
                    + "html.orgasmic-hybrid-shell [class*=\"bottom_nav\"],"
                    + "html.orgasmic-hybrid-shell .orgasmic-chat-nav,"
                    + "html.orgasmic-hybrid-shell a[data-orgasmic-chat],"
                    + "html.orgasmic-hybrid-shell .orgasmic-cal-nav,"
                    + "html.orgasmic-hybrid-shell a[data-orgasmic-calendar]"
                    + "{display:none!important}";

    static String userAgent(String current, String shell) {
        String base = current == null ? "" : current;
        if (!base.contains(UA_MARK)) {
            base = base + " " + UA_MARK;
        }
        return base + " OAShell/" + shell;
    }

    private HybridConfig() {}
}
