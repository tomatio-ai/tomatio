"""Per-session state: the facts seen, whether the AI logged, whether it was asked."""
from __future__ import annotations

import fcntl
import json
import re
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

from .config import state_dir

EMPTY = {
    "facts": [],        # facts since the last time the AI could stop cleanly
    "all_facts": [],    # every fact of the session (for the in-flight item)
    "links": [],        # pull request links seen
    "did_work": False,
    "logged": False,
    "asked": False,
    "inflight": "",     # slug of this session's in-flight item, once made
    "tool": "",
    "cwd": "",
    "started": 0,
}


def _path(session_id: str) -> Path:
    safe = re.sub(r"[^A-Za-z0-9_.-]", "_", session_id or "none")[:80]
    return state_dir() / "sessions" / f"{safe}.json"


@contextmanager
def session(session_id: str) -> Iterator[dict]:
    """Read, lock and write back one session's state."""
    path = _path(session_id)
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "a+", encoding="utf-8") as fh:
        fcntl.flock(fh, fcntl.LOCK_EX)
        fh.seek(0)
        try:
            data = json.loads(fh.read() or "{}")
        except ValueError:
            data = {}
        st = {**json.loads(json.dumps(EMPTY)), **data}
        if not st["started"]:
            st["started"] = int(time.time())
        yield st
        fh.seek(0)
        fh.truncate()
        fh.write(json.dumps(st, ensure_ascii=False))


def forget(session_id: str) -> None:
    try:
        _path(session_id).unlink()
    except OSError:
        pass


def prune(days: int = 14) -> None:
    folder = state_dir() / "sessions"
    cutoff = time.time() - days * 86400
    for p in folder.glob("*.json") if folder.exists() else []:
        try:
            if p.stat().st_mtime < cutoff:
                p.unlink()
        except OSError:
            pass
