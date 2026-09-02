#!/usr/bin/env python3
"""Build launcher icons and splash screens from store/logo-source.png."""

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
STORE = ROOT / "store"
SOURCE = STORE / "logo-source.png"
NAVY = (18, 28, 48, 255)
WHITE = (255, 255, 255, 255)
ICON_SIZES = {
    "mdpi": 48,
    "hdpi": 72,
    "xhdpi": 96,
    "xxhdpi": 144,
    "xxxhdpi": 192,
}
FG_SIZES = {
    "mdpi": 108,
    "hdpi": 162,
    "xhdpi": 216,
    "xxhdpi": 324,
    "xxxhdpi": 432,
}
SPLASH = {
    "drawable/splash.png": (480, 320),
    "drawable-land-mdpi/splash.png": (480, 320),
    "drawable-land-hdpi/splash.png": (800, 480),
    "drawable-land-xhdpi/splash.png": (1280, 720),
    "drawable-land-xxhdpi/splash.png": (1600, 960),
    "drawable-land-xxxhdpi/splash.png": (1920, 1280),
    "drawable-port-mdpi/splash.png": (320, 480),
    "drawable-port-hdpi/splash.png": (480, 800),
    "drawable-port-xhdpi/splash.png": (720, 1280),
    "drawable-port-xxhdpi/splash.png": (960, 1600),
    "drawable-port-xxxhdpi/splash.png": (1280, 1920),
}


def cropped_logo() -> Image.Image:
    im = Image.open(SOURCE).convert("RGBA")
    alpha = im.split()[-1]
    bbox = alpha.getbbox()
    if not bbox:
        raise SystemExit("logo-source.png has no visible pixels")
    pad = 8
    left = max(0, bbox[0] - pad)
    top = max(0, bbox[1] - pad)
    right = min(im.width, bbox[2] + pad)
    bottom = min(im.height, bbox[3] + pad)
    return im.crop((left, top, right, bottom))


def fit(logo: Image.Image, box: int, fill: float) -> Image.Image:
    lw, lh = logo.size
    target_h = int(box * fill)
    target_w = int(lw * target_h / lh)
    if target_w > int(box * fill):
        target_w = int(box * fill)
        target_h = int(lh * target_w / lw)
    return logo.resize((max(1, target_w), max(1, target_h)), Image.Resampling.LANCZOS)


def compose(size: int, bg, logo: Image.Image, fill: float) -> Image.Image:
    canvas = Image.new("RGBA", (size, size), bg)
    mark = fit(logo, size, fill)
    x = (size - mark.width) // 2
    y = (size - mark.height) // 2
    canvas.alpha_composite(mark, (x, y))
    return canvas


def splash(size: tuple[int, int], logo: Image.Image) -> Image.Image:
    w, h = size
    canvas = Image.new("RGBA", (w, h), NAVY)
    mark = fit(logo, min(w, h), 0.52)
    canvas.alpha_composite(mark, ((w - mark.width) // 2, (h - mark.height) // 2))
    return canvas.convert("RGB")


def save_png(im: Image.Image, path: Path, mode: str | None = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    out = im.convert(mode) if mode else im
    out.save(path, "PNG", optimize=True)


def main() -> None:
    logo = cropped_logo()
    android = ROOT / "android/app/src/main/res"
    icon = compose(1024, WHITE, logo, 0.78)
    foreground = compose(1024, (0, 0, 0, 0), logo, 0.58)
    round_icon = icon.copy()

    save_png(icon.convert("RGB"), STORE / "app-icon-1024.png", "RGB")
    save_png(icon.resize((512, 512), Image.Resampling.LANCZOS).convert("RGB"), STORE / "play-icon-512.png", "RGB")
    save_png(foreground, STORE / "ic-launcher-foreground-1024.png")
    save_png(splash((1080, 1920), logo), STORE / "splash-preview.png", "RGB")
    preview = icon.resize((640, 640), Image.Resampling.LANCZOS)
    save_png(preview.convert("RGB"), STORE / "app-icon-homescreen-preview.png", "RGB")

    for dens, size in ICON_SIZES.items():
        folder = android / f"mipmap-{dens}"
        resized = icon.resize((size, size), Image.Resampling.LANCZOS)
        save_png(resized.convert("RGB"), folder / "ic_launcher.png", "RGB")
        save_png(resized.convert("RGB"), folder / "ic_launcher_round.png", "RGB")
        fg = foreground.resize((FG_SIZES[dens], FG_SIZES[dens]), Image.Resampling.LANCZOS)
        save_png(fg, folder / "ic_launcher_foreground.png")

    for rel, size in SPLASH.items():
        save_png(splash(size, logo), android / rel, "RGB")

    splash_icon = compose(576, (0, 0, 0, 0), logo, 0.72)
    save_png(splash_icon, android / "drawable-nodpi/splash_icon.png")

    ios_icon = ROOT / "ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png"
    save_png(icon.convert("RGB"), ios_icon, "RGB")
    ios_splash = splash((2732, 2732), logo)
    splash_dir = ROOT / "ios/App/App/Assets.xcassets/Splash.imageset"
    for name in ("splash-2732x2732.png", "splash-2732x2732-1.png", "splash-2732x2732-2.png"):
        save_png(ios_splash, splash_dir / name, "RGB")

    pwa = ROOT.parent / "orgasmic-fc-app/assets"
    save_png(icon.resize((512, 512), Image.Resampling.LANCZOS).convert("RGB"), pwa / "icon-512.png", "RGB")
    save_png(icon.resize((192, 192), Image.Resampling.LANCZOS).convert("RGB"), pwa / "icon-192.png", "RGB")
    badge = compose(72, NAVY, logo, 0.78)
    save_png(badge.convert("RGB"), pwa / "badge-72.png", "RGB")

    www_logo = compose(512, NAVY, logo, 0.72)
    save_png(www_logo.convert("RGB"), ROOT / "www/logo.png", "RGB")
    print("branding assets written")


if __name__ == "__main__":
    main()
