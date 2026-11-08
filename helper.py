#!/usr/bin/env python3
"""Root share helper for PRM Auto Deploy (panel PHP runs as nginx)."""

from __future__ import annotations

import json
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:  # quieter
        pass

    def _json(self, code: int, payload: dict) -> None:
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self) -> None:
        if self.path == "/health":
            self._json(200, {"ok": True})
            return
        self._json(404, {"error": "not found"})

    def do_POST(self) -> None:
        if self.path != "/share":
            self._json(404, {"error": "not found"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
            donor = str(body.get("donor", "")).strip()
            target = str(body.get("target", "")).strip()
            if not donor or not target:
                self._json(422, {"error": "donor and target required"})
                return
            if "/" in donor or "/" in target or ".." in donor or ".." in target:
                self._json(422, {"error": "invalid uuid"})
                return
            proc = subprocess.run(
                ["sh", "/share.sh", donor, target],
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
