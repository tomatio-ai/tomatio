"""Login with a click (B211).

`tomatio-hooks login` waits on http://127.0.0.1:<port>/tomatio-hooks with a
one-time state, and opens the brain's profile with both in the address.
When the person clicks "Make a hooks login", the brain's page posts the new
key back here in a form. We take it only when the state matches, the brain
is the one we asked and the post came from that brain's page; then we keep
it in the keychain. The key is never printed, logged or put on a command
line.
"""
from __future__ import annotations

import hmac
import secrets
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, quote, urlparse

from . import config as cfgmod

PATH = "/tomatio-hooks"
MAX_BODY = 8192


def new_state() -> str:
    return secrets.token_urlsafe(32)


def login_page(cfg: cfgmod.Config, port: int, state: str) -> str:
    return (
        f"{cfg.brain}wp-admin/profile.php?tomatio_hooks={quote(cfg.machine)}"
        f"&tomatio_hooks_port={port}&tomatio_hooks_state={quote(state)}#tomatio-hooks"
    )


def same_host(a: str, b: str) -> bool:
    return (urlparse(a).hostname or "").lower() == (urlparse(b).hostname or "").lower() != ""


def accept(form: dict[str, str], origin: str, cfg: cfgmod.Config, state: str) -> str:
    """'' when the post is the one we wait for, else why not (no secrets in it)."""
    if not hmac.compare_digest(form.get("state", ""), state):
        return "the one-time state does not match"
    if not same_host(form.get("brain", ""), cfg.brain):
        return "it came for another brain"
    if origin and origin != "null" and not same_host(origin, cfg.brain):
        return "it was not posted from the brain's page"
    key = form.get("key", "")
    if ":" not in key or len(key) > 512 or any(c in key for c in "\r\n\0"):
        return "the key has the wrong shape"
    return ""


def page(title: str, text: str) -> bytes:
    return (
        "<!doctype html><meta charset=utf-8><title>TomaTío hooks</title>"
        "<body style='font-family:system-ui;max-width:32em;margin:4em auto'>"
        f"<h1>{title}</h1><p>{text}</p></body>"
    ).encode()


class Waiter:
    """One login: a local server that takes one matching post."""

    def __init__(self, cfg: cfgmod.Config, state: str | None = None, port: int = 0):
        self.cfg = cfg
        self.state = state or new_state()
        self.key = ""
        self.refused = ""
        self.done = threading.Event()
        waiter = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):  # nothing in the terminal, nothing logged
                pass

            def _send(self, code: int, body: bytes) -> None:
                self.send_response(code)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Cache-Control", "no-store")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_GET(self):
                self._send(404, page("Nothing here", "tomatio-hooks login waits for the brain's page to post here."))

            def do_POST(self):
                if urlparse(self.path).path != PATH or waiter.done.is_set():
                    self._send(404, page("Nothing here", "This login is not waiting any more."))
                    return
                try:
                    length = int(self.headers.get("Content-Length") or 0)
                except ValueError:
                    length = 0
                if not 0 < length <= MAX_BODY:
                    self._send(400, page("Not taken", "The post had the wrong size."))
                    return
                raw = self.rfile.read(length).decode("utf-8", "replace")
                form = {k: v[0] for k, v in parse_qs(raw, keep_blank_values=True).items()}
                why = accept(form, self.headers.get("Origin", ""), waiter.cfg, waiter.state)
                if why:
                    waiter.refused = why
                    self._send(403, page("Not taken", f"tomatio-hooks did not take this key: {why}. Run tomatio-hooks login again."))
                    return
                waiter.key = form["key"]
                waiter.done.set()
                self._send(200, page("Done", f"This computer has its hooks login for {waiter.cfg.host}. You can close this tab."))

        self.server = HTTPServer(("127.0.0.1", port), Handler)
        self.port = self.server.server_address[1]

    def wait(self, timeout: float) -> str:
        """The key, or '' when none came in time."""
        thread = threading.Thread(target=self.server.serve_forever, kwargs={"poll_interval": 0.2}, daemon=True)
        thread.start()
        try:
            self.done.wait(timeout)
        finally:
            self.server.shutdown()
            self.server.server_close()
        return self.key


def keep(cfg: cfgmod.Config, key: str) -> bool:
    """Keep the key: the macOS keychain, else a file only this user reads."""
    if sys.platform == "darwin":
        def q(v: str) -> str:
            return '"' + v.replace("\\", "\\\\").replace('"', '\\"') + '"'

        line = f"add-generic-password -U -s {q(cfgmod.SERVICE)} -a {q(cfg.host)} -l {q('TomaTío hooks')} -w {q(key)}\n"
        # Through stdin (security -i), so the key is never on a command line.
        done = subprocess.run(["security", "-i"], input=line, capture_output=True, text=True, timeout=10)
        return done.returncode == 0 and not done.stderr.strip()
    path = cfgmod.key_file()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.touch(mode=0o600, exist_ok=True)
    path.chmod(0o600)
    path.write_text(key + "\n", encoding="utf-8")
    return True

