package live.lo.community;

import android.Manifest;
import android.os.Build;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;
import java.util.List;

@CapacitorPlugin(
    name = "OrgasmicNative",
    permissions = {
        @Permission(alias = "videos", strings = { Manifest.permission.READ_MEDIA_VIDEO }),
        @Permission(alias = "storage", strings = { Manifest.permission.READ_EXTERNAL_STORAGE })
    }
)
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

    @PluginMethod
    public void requestVideoRead(PluginCall call) {
        String alias = videoPermissionAlias();
        if (getPermissionState(alias) == PermissionState.GRANTED) {
            resolveVideoRead(call, true);
            return;
        }
        requestPermissionForAlias(alias, call, "videoReadResult");
    }

    @PermissionCallback
    private void videoReadResult(PluginCall call) {
        resolveVideoRead(call, getPermissionState(videoPermissionAlias()) == PermissionState.GRANTED);
    }

    private String videoPermissionAlias() {
        return Build.VERSION.SDK_INT >= 33 ? "videos" : "storage";
    }

    private void resolveVideoRead(PluginCall call, boolean granted) {
        JSObject ret = new JSObject();
        ret.put("granted", granted);
        call.resolve(ret);
    }
}
