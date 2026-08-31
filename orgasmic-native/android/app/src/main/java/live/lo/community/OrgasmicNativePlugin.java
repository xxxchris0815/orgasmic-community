package live.lo.community;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.util.List;

@CapacitorPlugin(name = "OrgasmicNative")
public class OrgasmicNativePlugin extends Plugin {
    @PluginMethod
    public void pushReady(PluginCall call) {
        boolean ready = false;
        try {
            Class<?> firebaseApp = Class.forName("com.google.firebase.FirebaseApp");
            Object apps = firebaseApp.getMethod("getApps", android.content.Context.class).invoke(null, getContext());
            ready = apps instanceof List && !((List<?>) apps).isEmpty();
        } catch (Throwable ignored) {
            ready = false;
        }
        JSObject ret = new JSObject();
        ret.put("ready", ready);
        call.resolve(ret);
    }
}
