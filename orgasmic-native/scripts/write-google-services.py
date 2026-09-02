#!/usr/bin/env python3
"""Write android/app/google-services.json from GOOGLE_SERVICES_JSON.

Accepts raw JSON (one line or multiline) or standard base64. Never prints secrets.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import sys
from pathlib import Path

EXPECTED_PACKAGE = "live.lo.community"


def decode(raw: str) -> dict:
    text = raw.strip().lstrip("\ufeff")
    if len(text) >= 2 and text[0] == text[-1] and text[0] in {'"', "'"}:
        text = text[1:-1].strip()
    attempts = [text]
    try:
        attempts.append(base64.b64decode(text, validate=False).decode("utf-8"))
    except Exception:
        pass
    last_error = "kein JSON"
    for candidate in attempts:
        candidate = candidate.strip()
        if not candidate:
            continue
        try:
            data = json.loads(candidate)
        except json.JSONDecodeError as exc:
            last_error = str(exc)
            continue
        if isinstance(data, dict) and "client" in data:
            return data
        last_error = "JSON ohne Firebase-client"
    raise ValueError(last_error)


def package_names(data: dict) -> list[str]:
    names: list[str] = []
    for client in data.get("client") or []:
        if not isinstance(client, dict):
            continue
        info = client.get("android_client_info") or {}
        nested = (client.get("client_info") or {}).get("android_client_info") or {}
        for block in (info, nested):
            name = str((block or {}).get("package_name") or "")
            if name:
                names.append(name)
    return names


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("dest")
    parser.add_argument("--required", action="store_true")
    args = parser.parse_args()

    raw = os.environ.get("GOOGLE_SERVICES_JSON", "")
    if not raw.strip():
        if args.required:
            print("GOOGLE_SERVICES_JSON fehlt in der Codemagic-Gruppe firebase.", file=sys.stderr)
            return 1
        print("GOOGLE_SERVICES_JSON fehlt — Debug baut, Push geht noch nicht.")
        return 0

    try:
        data = decode(raw)
    except ValueError as exc:
        print(
            "GOOGLE_SERVICES_JSON ist kein gültiges google-services.json "
            f"({exc}). In Codemagic alles in EINER Zeile einfügen, oder die Datei base64-kodieren.",
            file=sys.stderr,
        )
        return 1

    names = package_names(data)
    if EXPECTED_PACKAGE not in names:
        print(
            f"google-services.json hat package_name {names or '[]'}, erwartet {EXPECTED_PACKAGE}.",
            file=sys.stderr,
        )
        return 1

    dest = Path(args.dest)
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    print(f"google-services.json geschrieben ({dest.stat().st_size} bytes), package {EXPECTED_PACKAGE}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
