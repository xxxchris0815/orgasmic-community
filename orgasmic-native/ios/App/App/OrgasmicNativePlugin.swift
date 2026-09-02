import Capacitor
import Foundation
import Photos

#if canImport(FirebaseCore)
import FirebaseCore
#endif
#if canImport(FirebaseMessaging)
import FirebaseMessaging
#endif

@objc(OrgasmicNativePlugin)
public class OrgasmicNativePlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "OrgasmicNativePlugin"
    public let jsName = "OrgasmicNative"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "pushReady", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "fcmToken", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "requestVideoRead", returnType: CAPPluginReturnPromise)
    ]

    @objc func pushReady(_ call: CAPPluginCall) {
        var ready = false
        #if canImport(FirebaseCore)
        ready = AppDelegate.firebasePlistIsReal() && FirebaseApp.app() != nil
        #endif
        call.resolve(["ready": ready])
    }

    @objc func fcmToken(_ call: CAPPluginCall) {
        #if canImport(FirebaseMessaging)
        Messaging.messaging().token { token, error in
            if let token = token, !token.isEmpty {
                call.resolve(["token": token])
                return
            }
            call.reject(error?.localizedDescription ?? "FCM-Token fehlt")
        }
        #else
        call.reject("Firebase Messaging fehlt")
        #endif
    }

    @objc func requestVideoRead(_ call: CAPPluginCall) {
        let status = PHPhotoLibrary.authorizationStatus(for: .readWrite)
        if status == .authorized || status == .limited {
            call.resolve(["granted": true])
            return
        }
        PHPhotoLibrary.requestAuthorization(for: .readWrite) { newStatus in
            let granted = newStatus == .authorized || newStatus == .limited
            call.resolve(["granted": granted])
        }
    }
}
