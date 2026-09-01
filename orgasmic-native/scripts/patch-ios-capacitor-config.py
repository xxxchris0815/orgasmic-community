#!/usr/bin/env python3
"""Keep OrgasmicNativePlugin in ios/App/App/capacitor.config.json after `npx cap sync`."""

from __future__ import annotations

import json
from pathlib import Path

PLUGIN = "OrgasmicNativePlugin"
PATH = Path(__file__).resolve().parent.parent / "ios" / "App" / "App" / "capacitor.config.json"


def main() -> int:
    if not PATH.exists():
        print(f"{PATH} fehlt — cap sync zuerst ausführen.", flush=True)
        return 1
    data = json.loads(PATH.read_text(encoding="utf-8"))
    classes = [str(item) for item in (data.get("packageClassList") or [])]
    if PLUGIN not in classes:
        classes.append(PLUGIN)
    data["packageClassList"] = classes
    PATH.write_text(json.dumps(data, indent="\t", ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"packageClassList enthält {PLUGIN}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
