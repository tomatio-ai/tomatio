"""Facts noted while the AI works (B66): commits, pushes, pull requests, merges, tags.

Facts come from what a shell command ran and printed, never from what the AI
says it did. Each fact is a short line, with secrets masked.
"""
from __future__ import annotations

import re

from . import secrets
from .events import Event

TIODAY_TOOL = re.compile(r"tioday-(?:task|done|decided|inflight|reply|release)$", re.I)
TIODAY_STORE = re.compile(r"artifact-store-(?:set|delete)$", re.I)

_COMMIT_OUT = re.compile(r"^\[([^\]\s]+)(?: \(root-commit\))? ([0-9a-f]{7,40})\] (.+)$", re.M)
_PUSH_REF = re.compile(r"^\s*(?:\*\s*\[new branch\]|[0-9a-f]+\.\.[0-9a-f]+|\+?\s*[0-9a-f]+\.\.\.[0-9a-f]+)\s+(\S+)\s+->\s+(\S+)", re.M)
_TAG_CMD = re.compile(r"\bgit\s+tag\s+(?:-[asfm]\s+)*(?:-m\s+(?:'[^']*'|\"[^\"]*\"|\S+)\s+)*([\w./-]+)")
_PR_URL = re.compile(r"https?://[^\s\"']+/(?:pulls?|merge_requests)/(\d+)")
_MERGE_CMD = re.compile(r"/pulls/(\d+)/merge\b|\bgh\s+pr\s+merge\s+(\d+)|\bgit\s+merge\s+([\w./-]+)")
_PR_CREATE = re.compile(r"\bgh\s+pr\s+create\b|curl\b[^|;&]*-X\s*POST[^|;&]*/pulls\b(?!/)|/pulls[\"']?\s*$", re.M)
_DEPLOY = re.compile(r"\b(?:fly|flyctl)\s+deploy\b|\bwrangler\s+(?:pages\s+)?deploy\b|\bvercel\s+--prod\b")


def from_event(event: Event) -> list[str]:
    """The facts one finished tool call shows."""
    if event.name != "post_tool":
        return []
    cmd = event.command
    if not cmd:
        return []
    out = event.response_text()
    found: list[str] = []
    if re.search(r"\bgit\s+commit\b", cmd):
        for branch, sha, subject in _COMMIT_OUT.findall(out):
            found.append(f"commit {sha[:8]} on {branch}: {subject.strip()}")
    if re.search(r"\bgit\s+push\b", cmd):
        refs = _PUSH_REF.findall(out)
        for _src, dst in refs:
            found.append(f"pushed {dst}")
    m = _TAG_CMD.search(cmd)
    if m and not re.search(r"\bgit\s+tag\s+(?:-l|--list|-d)\b", cmd):
        found.append(f"tag {m.group(1)}")
    if _PR_CREATE.search(cmd):
        url = _PR_URL.search(out)
        if url:
            found.append(f"opened PR {url.group(0)}")
    mm = _MERGE_CMD.search(cmd)
    if mm and not re.search(r"\b(?:--abort|--continue)\b", cmd):
        if mm.group(1) or mm.group(2):
            if re.search(r"\"merged\"\s*:\s*true|Merged|^\s*$", out, re.M) or out.strip() == "":
                found.append(f"merged PR {mm.group(1) or mm.group(2)}")
        elif mm.group(3) and not re.search(r"(?i)conflict|fatal|error", out):
            found.append(f"merged {mm.group(3)}")
    if _DEPLOY.search(cmd) and not re.search(r"(?i)\berror\b", out[-400:]):
        found.append("deployed: " + _DEPLOY.search(cmd).group(0))
    return [secrets.mask(f)[0] for f in found]


def touches_tioday(event: Event) -> bool:
    """True when the AI wrote to TíoDay itself."""
    if event.name not in ("pre_tool", "post_tool"):
        return False
    name = event.tool_name.split("__")[-1]
    if TIODAY_TOOL.search(name):
        return True
    if TIODAY_STORE.search(name) and re.search(r"\b390\b|tioday", secrets.mask(str(event.tool_input))[0], re.I):
        return True
    return False


def did_work(event: Event) -> bool:
    """True for a tool call that changed something (an edit, a commit, a write call)."""
    if event.name != "post_tool":
        return False
    if event.tool_name in ("Edit", "Write", "MultiEdit", "NotebookEdit", "apply_patch"):
        return True
    cmd = event.command
    if cmd and re.search(
        r"\bgit\s+(?:commit|push|tag|merge)\b|curl\b[^|;&]*-X\s*(?:POST|PATCH|PUT|DELETE)|\b(?:fly|flyctl)\s+deploy\b|\bgh\s+(?:pr|release|issue)\s+(?:create|merge|edit|close)",
        cmd,
    ):
        return True
    return False
