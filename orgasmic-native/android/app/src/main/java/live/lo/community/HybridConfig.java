package live.lo.community;

final class HybridConfig {
    static final String ORIGIN = "https://community.orgasmic.live";
    static final String PORTAL = ORIGIN + "/portal";
    static final String AJAX = ORIGIN + "/wp-admin/admin-ajax.php";
    static final String REST = ORIGIN + "/wp-json/";
    static final String UA_MARK = "LOCommunityHybrid/1";
    static final String HYBRID_CSS =
            "html.orgasmic-hybrid-feed .fcom_mobile_menu,"
                    + "html.orgasmic-hybrid-feed .fcom-mobile-menu,"
                    + "html.orgasmic-hybrid-feed .fcom_mobile_nav,"
                    + "html.orgasmic-hybrid-feed [class*=\"mobile_menu\"],"
                    + "html.orgasmic-hybrid-feed [class*=\"mobile-menu\"],"
                    + "html.orgasmic-hybrid-feed [class*=\"bottom-nav\"],"
                    + "html.orgasmic-hybrid-feed [class*=\"bottom_nav\"],"
                    + "html.orgasmic-hybrid-feed .orgasmic-chat-nav,"
                    + "html.orgasmic-hybrid-feed a[data-orgasmic-chat],"
                    + "html.orgasmic-hybrid-feed .orgasmic-cal-nav,"
                    + "html.orgasmic-hybrid-feed a[data-orgasmic-calendar]"
                    + "{display:none!important}";

    private HybridConfig() {}
}
