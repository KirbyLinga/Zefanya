# Media upload diagnosis — S2.1

Platform x pick-or-capture x result x root cause for the Zefanya media flow
(avatar, review photos, proof-of-delivery, rider documents, government ID).
Every pick flows through a single service, `lib/services/media_picker.dart`,
wired by `lib/utils/image_picker_helper.dart`; no screen calls `ImagePicker`
directly.

## Matrix

| Platform | Action | Result | Root cause |
|----------|--------|--------|------------|
| Android 13+ | Gallery pick | Photo returned, re-encoded to JPEG ≤1280 px @ q80, EXIF/GPS stripped | `ImagePicker().pickImage(maxWidth:1280, imageQuality:80)` re-encodes; result copied to app documents dir |
| Android 13+ | Camera capture | Photo returned after runtime camera grant | `CAMERA` permission declared in `AndroidManifest.xml`; `image_picker` requests it at launch |
| Android 11+ | Gallery / camera intent resolution | Intent resolves | `<queries>` entries for `IMAGE_CAPTURE`, `GET_CONTENT`, `OPEN_DOCUMENT` (`image/*`) |
| Android | Camera denied | Friendly dialog + "Open Settings" button; no crash | `MediaPicker` catches camera launch failure, shows `_showPermissionDenied`, `AppSettings.openAppSettings()` |
| Android | Gallery denied | Photo picker (system UI) handles its own grant; no app-level permission needed | Android 13+ photo picker requires no storage permission |
| Android | Non-image / >5 MB / empty | Refused with `MediaProblem.notAnImage` / `tooLarge` / `empty`, shown via `mediaProblemMessage` | `_validateAndSave` checks mime + extension + size (5 MB) |
| iOS | Camera / photo library | Usage descriptions present | `NSCameraUsageDescription`, `NSPhotoLibraryUsageDescription` in `Info.plist` |
| iOS | Google sign-in (related) | `GIDClientID` / `GIDServerClientID` from `$(GOOGLE_…_CLIENT_ID)` | `Info.plist`; values supplied at build time, never committed |
| Web | Gallery | File chooser | `image_picker` web implementation |
| Web | Camera | Falls back to file chooser | `image_picker` has no camera on web |

## Known root causes (failure → fix)

- **"Camera or photo library access is required"** — camera launch threw (usually permission not granted). Fix: grant permission via the Open Settings button, then re-pick.
- **"That file is not an image"** — picked file's mime/extension not in `kMediaImageMimeTypes`/`kMediaImageExtensions` (or, with `allowPdf`, not in the document sets). Fix: pick a JPG/PNG/WEBP photo, or a PDF when the caller passes `allowPdf: true` (rider documents, government ID).
- **"Image is too large"** — source file > 5 MB before re-encoding. Fix: image_picker's `imageQuality: 80` + `maxWidth: 1280` usually brings photos under the limit; choose a smaller photo otherwise.
- **"Could not open the camera or gallery"** — platform error (device has no camera, intent unresolved). Fix: `platformError` path; on web the camera falls back to the gallery file chooser.

## Notes

- Re-encoding: `image_picker` re-encodes to JPEG at `imageQuality: 80` bounded by `maxWidth: 1280`, which drops EXIF/GPS on Android. The saved copy keeps the re-encoded bytes (no second, lossy pass).
- PDF documents are copied as-is (`.pdf`) and are not image-re-encoded; size ≤ 5 MB still enforced.
- No real-device camera test is possible in CI/widget tests; permission states are covered by fakes in `media_picker_test.dart`.
