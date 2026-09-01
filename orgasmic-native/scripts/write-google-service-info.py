#!/usr/bin/env python3
"""Write ios/App/App/GoogleService-Info.plist from GOOGLE_SERVICE_INFO_PLIST.

Accepts raw plist XML or standard base64. Never prints secrets.
"""

from __future__ import annotations

import argparse
import base64
import os
import re
import sys
from pathlib import Path

EXPECTED_BUNDLE = "live.lo.community"
STUB_PROJECT = "replace-with-firebase-ios-app"


def decode(raw: str) -> str:
    text = raw.strip().lstrip("\ufeff")
    if len(text) >= 2 and text[0] == text[-1] and text[0] in {'"', "'"}:
        text = text[1:-1].strip()
    attempts = [text]
    try:
        attempts.append(base64.b64decode(text, validate=False).decode("utf-8"))
    except Exception:
        pass
    last_error = "kein Plist"
    for candidate in attempts:
        candidate = candidate.strip()
        if not candidate:
            continue
        if "<plist" in candidate and "BUNDLE_ID" in candidate:
            return candidate
        last_error = "XML ohne BUNDLE_ID"
    raise ValueError(last_error)


def plist_string(xml: str, key: str) -> str:
    pattern = rf"<key>{re.escape(key)}</key>\s*<string>([^<]*)</string>"
    match = re.search(pattern, xml)
    return match.group(1).strip() if match else ""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("dest")
    parser.add_argument("--required", action="store_true")
    args = parser.parse_args()

    raw = os.environ.get("GOOGLE_SERVICE_INFO_PLIST", "")
    if not raw.strip():
        if args.required:
            print("GOOGLE_SERVICE_INFO_PLIST fehlt in der Codemagic-Gruppe firebase.", file=sys.stderr)
            return 1
        print("GOOGLE_SERVICE_INFO_PLIST fehlt — iOS-Build geht, Push erst nach der echten Firebase-Plist.")
        return 0

    try:
        xml = decode(raw)
    except ValueError as exc:
        print(
            "GOOGLE_SERVICE_INFO_PLIST ist keine gültige GoogleService-Info.plist "
            f"({exc}). In Codemagic die Datei als XML oder base64 in EINER Variable einfügen.",
            file=sys.stderr,
        )
        return 1

    bundle = plist_string(xml, "BUNDLE_ID")
    project = plist_string(xml, "PROJECT_ID")
    if bundle != EXPECTED_BUNDLE:
        print(
            f"GoogleService-Info.plist hat BUNDLE_ID {bundle or '[]'}, erwartet {EXPECTED_BUNDLE}.",
            file=sys.stderr,
        )
        return 1
    if not project or project == STUB_PROJECT:
        print("GoogleService-Info.plist hat noch die Platzhalter-PROJECT_ID.", file=sys.stderr)
        return 1

    dest = Path(args.dest)
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(xml if xml.endswith("\n") else xml + "\n", encoding="utf-8")
    print(f"GoogleService-Info.plist geschrieben ({dest.stat().st_size} bytes), bundle {EXPECTED_BUNDLE}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
