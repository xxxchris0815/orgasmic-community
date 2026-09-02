package live.lo.community;

final class HybridRuntime {
    static final Session SESSION = new Session();
    static final ApiClient API = new ApiClient(SESSION);

    private HybridRuntime() {}
}
