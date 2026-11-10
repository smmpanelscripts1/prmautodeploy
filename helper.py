#!/usr/bin/env python3
"""Root share helper for PRM Auto Deploy (panel PHP runs as nginx)."""

from __future__ import annotations

import json
import os
import re
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VOLUMES = os.environ.get("PRM_VOLUMES", "/data")
SHARE_SH = os.environ.get("PRM_SHARE_SH", "/share.sh")
UUID_RE = re.compile(r"^[0-9a-fA-F-]{32,36}$")


def valid_uuid(value: str) -> bool:
    return bool(UUID_RE.match(value)) and "/" not in value and ".." not in value


def is_donor(uuid: str) -> bool:
    base = os.path.join(VOLUMES, uuid)
    if (
        os.path.isdir(os.path.join(base, "game"))
        or os.path.isfile(os.path.join(base, "game", "cs2.sh"))
        or os.path.isdir(os.path.join(base, "steamapps"))
    ):
        return True
    if not os.path.isdir(base):
        return False
    try:
        entries = [name for name in os.listdir(base) if name not in (".", "..", ".sftp")]
    except OSError:
        return False
    return len(entries) >= 2


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        pass

    def _json(self, code: int, payload: dict) -> None:
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _body(self) -> dict:
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length).decode("utf-8") if length else "{}"
        data = json.loads(raw or "{}")
        if not isinstance(data, dict):
            raise ValueError("json object required")
        return data

    def do_GET(self) -> None:
        if self.path == "/health":
            self._json(200, {"ok": True, "service": "prmautodeploy"})
            return
        self._json(404, {"error": "not found"})

    def do_POST(self) -> None:
        try:
            if self.path == "/lookup":
                body = self._body()
                uuids = body.get("uuids") or []
                if not isinstance(uuids, list) or len(uuids) > 200:
                    self._json(422, {"error": "uuids required"})
                    return
                donors = []
                for item in uuids:
                    uuid = str(item).strip()
                    if valid_uuid(uuid) and is_donor(uuid):
                        donors.append(uuid)
                self._json(200, {"ok": True, "donors": donors})
                return
            if self.path != "/share":
                self._json(404, {"error": "not found"})
                return
            body = self._body()
            donor = str(body.get("donor", "")).strip()
            target = str(body.get("target", "")).strip()
            if not valid_uuid(donor) or not valid_uuid(target):
                self._json(422, {"error": "invalid uuid"})
                return
            proc = subprocess.run(
                ["sh", SHARE_SH, donor, target],
                capture_output=True,
                text=True,
                timeout=600,
            )
            lines = (proc.stdout or "").splitlines() + (proc.stderr or "").splitlines()
            self._json(
                200 if proc.returncode == 0 else 500,
                {"ok": proc.returncode == 0, "code": proc.returncode, "log": lines},
            )
        except Exception as exc:  # noqa: BLE001
            self._json(500, {"error": str(exc)})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8099), Handler).serve_forever()
