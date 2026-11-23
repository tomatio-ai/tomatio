"""Calls to the brain, with the hooks login (plugin B65).

A hook never waits on the brain: `later()` starts a detached process that
makes the call and exits, so a slow or failing brain never blocks work.
Failures go to the local log only (no secrets in it).
"""
from __future__ import annotations

import base64
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from typing import Any

from . import config as cfgmod
from .config import Config, state_dir

TIMEOUT = 8


class BrainError(Exception):
    pass


def log(line: str) -> None:
    try:
        path = state_dir() / "hooks.log"
        path.parent.mkdir(parents=True, exist_ok=True)
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(time.strftime("%Y-%m-%dT%H:%M:%S ") + line[:500] + "\n")
    except OSError:
        pass


def _post(url: str, key: str, body: dict, session: str = "") -> tuple[dict, str]:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "Authorization": "Basic " + base64.b64encode(key.encode()).decode(),
        "User-Agent": "tomatio-hooks/0.1",
    }
    if session:
        headers["Mcp-Session-Id"] = session
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            raw = resp.read().decode("utf-8", "replace")
            sid = resp.headers.get("Mcp-Session-Id", "") or session
    except urllib.error.HTTPError as e:
        raise BrainError(f"HTTP {e.code}") from None
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        raise BrainError(type(e).__name__) from None
    if not raw.strip():
        return {}, sid
    if raw.lstrip().startswith("event:") or raw.lstrip().startswith("data:"):
        datas = [ln[5:].strip() for ln in raw.splitlines() if ln.startswith("data:")]
        raw = datas[-1] if datas else "{}"
    try:
        return json.loads(raw), sid
    except ValueError:
        raise BrainError("not JSON") from None


def call(cfg: Config, tool: str, arguments: dict, key: str | None = None) -> Any:
    """Call one brain tool; returns its structured answer. Raises BrainError."""
    key = key if key is not None else cfgmod.key(cfg)
    if not cfg.brain or not key:
        raise BrainError("no brain or no hooks login")
    init, sid = _post(
        cfg.mcp_url,
        key,
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-06-18",
                "capabilities": {},
                "clientInfo": {"name": "tomatio-hooks", "version": "0.1"},
            },
        },
    )
    if "error" in init:
        raise BrainError("initialize refused: " + str(init["error"].get("message", ""))[:200])
    _post(cfg.mcp_url, key, {"jsonrpc": "2.0", "method": "notifications/initialized"}, sid)
    res, _ = _post(
        cfg.mcp_url,
        key,
        {"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {"name": tool, "arguments": arguments}},
        sid,
    )
    if "error" in res:
        raise BrainError(str(res["error"].get("message", ""))[:300])
    result = res.get("result") or {}
    if result.get("isError"):
        text = " ".join(c.get("text", "") for c in result.get("content") or [] if isinstance(c, dict))
        raise BrainError(text[:300] or "tool error")
    if "structuredContent" in result:
        return result["structuredContent"]
    for c in result.get("content") or []:
        if isinstance(c, dict) and c.get("type") == "text":
            try:
                return json.loads(c["text"])
            except (ValueError, KeyError):
                return c.get("text")
    return result


def later(tool: str, arguments: dict) -> None:
    """Make the call in a detached process; return at once."""
    folder = state_dir() / "outbox"
    try:
        folder.mkdir(parents=True, exist_ok=True)
        job = folder / f"{int(time.time() * 1000)}-{uuid.uuid4().hex[:8]}.json"
        job.write_text(json.dumps({"tool": tool, "arguments": arguments}), encoding="utf-8")
        root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        subprocess.Popen(
            [sys.executable, "-I", "-c", "import sys; sys.path.insert(0, sys.argv[1]); from tomatio_hooks import brain; brain.run_job(sys.argv[2])", root, str(job)],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
            close_fds=True,
        )
    except OSError as e:
        log(f"could not start a brain call: {type(e).__name__}")


def run_job(path: str) -> None:
    try:
        with open(path, encoding="utf-8") as fh:
            job = json.load(fh)
        os.unlink(path)
    except (OSError, ValueError):
        return
    cfg = cfgmod.load()
    try:
        call(cfg, job["tool"], job["arguments"])
        log(f"ok {job['tool']}")
    except BrainError as e:
        log(f"failed {job['tool']}: {e}")
