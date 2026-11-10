#!/usr/bin/env python3
"""Root share helper for PRM Auto Deploy (panel PHP runs as nginx)."""

from __future__ import annotations

import json
import os
import re
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SHARE_SH = os.environ.get("PRM_SHARE_SH", "/share.sh")
UUID_RE = re.compile(r"^[0-9a-fA-F-]{32,36}$")
DEFAULT_ROOTS = (
    "/data",
    "/var/lib/pterodactyl/volumes",
    "/peto-volumes",
    "/srv/daemon-data",
    "/srv/pterodactyl/volumes",
)


def valid_uuid(value: str) -> bool:
    return bool(UUID_RE.match(value)) and "/" not in value and ".." not in value


def _split_paths(raw: str) -> list[str]:
    parts: list[str] = []
    for chunk in raw.replace(";", ",").split(","):
        item = chunk.strip()
        if item:
            parts.append(item.rstrip("/"))
    return parts


def volume_roots(extra: list[str] | None = None) -> list[str]:
    ordered: list[str] = []
    ordered.extend(_split_paths(os.environ.get("PRM_VOLUMES", "")))
    if extra:
        ordered.extend(p.rstrip("/") for p in extra if p)
    ordered.extend(DEFAULT_ROOTS)
    seen: set[str] = set()
    out: list[str] = []
    for path in ordered:
        if not path or path in seen:
            continue
        seen.add(path)
        if os.path.isdir(path):
            out.append(path)
    return out


def looks_like_donor(base: str) -> bool:
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


def find_server_dir(uuid: str, extra: list[str] | None = None) -> str | None:
    for root in volume_roots(extra):
        base = os.path.join(root, uuid)
        if os.path.isdir(base):
            return base
    return None


def find_donor_dir(uuid: str, extra: list[str] | None = None) -> str | None:
    hits: list[str] = []
    for root in volume_roots(extra):
        base = os.path.join(root, uuid)
        if looks_like_donor(base):
            hits.append(base)
    return hits[0] if hits else None


def is_donor(uuid: str, extra: list[str] | None = None) -> bool:
    return find_donor_dir(uuid, extra) is not None


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
            self._json(200, {"ok": True, "service": "prmautodeploy", "roots": volume_roots()})
            return
        self._json(404, {"error": "not found"})

    def do_POST(self) -> None:
        try:
            if self.path == "/lookup":
                body = self._body()
                uuids = body.get("uuids") or []
                extra = body.get("roots") if isinstance(body.get("roots"), list) else []
                extra_paths = [str(item).strip() for item in extra if str(item).strip()]
                if not isinstance(uuids, list) or len(uuids) > 200:
                    self._json(422, {"error": "uuids required"})
                    return
                donors = []
                for item in uuids:
                    uuid = str(item).strip()
                    if valid_uuid(uuid) and is_donor(uuid, extra_paths):
                        donors.append(uuid)
                self._json(200, {"ok": True, "donors": donors, "roots": volume_roots(extra_paths)})
                return
            if self.path != "/share":
                self._json(404, {"error": "not found"})
                return
            body = self._body()
            donor = str(body.get("donor", "")).strip()
            target = str(body.get("target", "")).strip()
            extra = body.get("roots") if isinstance(body.get("roots"), list) else []
            extra_paths = [str(item).strip() for item in extra if str(item).strip()]
            if not valid_uuid(donor) or not valid_uuid(target):
                self._json(422, {"error": "invalid uuid"})
                return
            donor_dir = find_donor_dir(donor, extra_paths)
            if not donor_dir:
                self._json(404, {"ok": False, "error": f"donor volume not found: {donor}"})
                return
            data_root = os.path.dirname(donor_dir)
            env = os.environ.copy()
            env["PRM_VOLUMES"] = data_root
            proc = subprocess.run(
                ["sh", SHARE_SH, donor, target],
                capture_output=True,
                text=True,
                timeout=600,
                env=env,
            )
            lines = (proc.stdout or "").splitlines() + (proc.stderr or "").splitlines()
            self._json(
                200 if proc.returncode == 0 else 500,
                {"ok": proc.returncode == 0, "code": proc.returncode, "log": lines, "root": data_root},
            )
        except Exception as exc:  # noqa: BLE001
            self._json(500, {"error": str(exc)})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8099), Handler).serve_forever()
