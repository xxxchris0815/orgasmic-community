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
                    + "{display:none!important}"
                    + "html.orgasmic-hybrid-chat,html.orgasmic-hybrid-cal{--orgasmic-mobile-bar:0px}"
                    + "html.orgasmic-hybrid-chat #orgasmic-chat-root,"
                    + "html.orgasmic-hybrid-chat #orgasmic-chat-root[hidden],"
                    + "html.orgasmic-hybrid-cal #orgasmic-cal-root,"
                    + "html.orgasmic-hybrid-cal #orgasmic-cal-root[hidden]"
                    + "{display:block!important;position:fixed!important;inset:0!important;"
                    + "width:100%!important;height:100%!important;z-index:2147483000!important;transform:none!important}"
                    + "html.orgasmic-hybrid-chat .orgasmic-chat-overlay,"
                    + "html.orgasmic-hybrid-cal .orgasmic-cal-overlay"
                    + "{position:absolute!important;inset:0!important;height:100%!important}"
                    + "html.orgasmic-hybrid-chat .orgasmic-chat-thread-close,"
                    + "html.orgasmic-hybrid-cal .oc-icon-close{display:none!important}";

    static String userAgent(String current, String shell) {
        String base = current == null ? "" : current;
        if (!base.contains(UA_MARK)) {
            base = base + " " + UA_MARK;
        }
        return base + " OAShell/" + shell;
    }

    private HybridConfig() {}
}
