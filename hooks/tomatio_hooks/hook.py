"""The shared hook: one event in, one answer out, for every tool.

A wrapper runs `tomatio-hook <tool>` with the tool's event JSON on stdin.
This module reads it into the common Event, decides (secrets, facts, the
stop check), and writes the answer in that tool's own form. It never
fails the tool: any error ends in an empty answer and exit 0.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from typing import Any

from . import brain, events, facts, secrets, state
from .config import Config
from .config import key as stored_key
from .config import load as load_config

TOOLS = {"claude-code": "Claude Code", "codex": "Codex", "cursor": "Cursor"}
SIGNIFICANT = re.compile(r"^(?:commit|pushed|opened PR|merged|tag|deployed)\b")


@dataclass
class Answer:
    deny: str = ""          # refuse a tool call, with this reason for the AI
    mask: Any = None        # replace what a tool printed (same shape)
    context: str = ""       # a note for the AI after a tool call
    block_stop: str = ""    # do not stop yet: this is what to do first
    rewrite: Any = None     # run this tool input instead (Codex: the command with its output masked)

    def empty(self) -> bool:
        return not (self.deny or self.mask is not None or self.context or self.block_stop or self.rewrite is not None)


# Codex asks for these when a command wants more than its sandbox; such a
# command is left as the AI wrote it, so the hook never stands in for the
# person's approval.
_ESCALATION = ("sandbox_permissions", "with_escalated_permissions", "justification", "prefix_rule", "tty")


def masked_input(ev: events.Event) -> dict | None:
    """For Codex, which cannot replace what a shell command printed: the
    same tool input with the command's output piped through the masker, or
    None when it should run as it is."""
    ti = ev.tool_input
    if ev.tool != "codex" or not ev.is_shell or not isinstance(ti, dict) or not isinstance(ti.get("command"), str):
        return None
    if any(ti.get(k) not in (None, False, "", "use_default") for k in _ESCALATION):
        return None
    script = os.path.join(os.path.dirname(os.path.dirname(os.path.realpath(__file__))), "bin", "tomatio-hook")
    wrapped = secrets.wrap(ti["command"], secrets.mask_command(script, sys.executable or "python3"))
    if wrapped is None:
        return None
    return {**ti, "command": wrapped}


def replaces_output(ev: events.Event) -> bool:
    """Whether this tool can swap what a tool call printed for the masked form."""
    return ev.tool == "claude-code" or ev.tool_name.startswith("mcp__")


def mask_value(value: Any) -> tuple[Any, list[str]]:
    """value with every known secret hidden, in the same shape."""
    kinds: list[str] = []

    def walk(v: Any) -> Any:
        if isinstance(v, str):
            masked, found = secrets.mask(v)
            kinds.extend(k for k in found if k not in kinds)
            return masked
        if isinstance(v, list):
            return [walk(x) for x in v]
        if isinstance(v, dict):
            return {k: walk(x) for k, x in v.items()}
        return v

    return walk(value), kinds


def stop_reason(facts_seen: list[str]) -> str:
    lines = "\n".join(f"- {f}" for f in facts_seen[-10:])
    seen = f"\nWhat the hooks saw this turn:\n{lines}" if lines else ""
    return (
        "TomaTío hooks: this turn did work and nothing went on the TíoDay. Before you end, record it in plain words: "
        "tomatio-tioday-done for finished work (link the pull request), tomatio-tioday-inflight for work that goes on, "
        "tomatio-tioday-task for anything that now needs the person. If nothing belongs there, say "
        "\"TíoDay: nothing to record\" and end." + seen
    )


def branch(cwd: str) -> str:
    try:
        out = subprocess.run(["git", "-C", cwd, "rev-parse", "--abbrev-ref", "HEAD"], capture_output=True, text=True, timeout=1)
    except (OSError, subprocess.TimeoutExpired):
        return ""
    return out.stdout.strip() if out.returncode == 0 else ""


def inflight_slug(tool: str, session_id: str) -> str:
    sid = re.sub(r"[^a-z0-9]", "", session_id.lower())[:8] or "session"
    return f"hooks-{tool}-{sid}"[:64]


def pr_links(found: list[str]) -> list[dict]:
    links = []
    for f in found:
        m = re.search(r"(https://\S+/(?:pulls?|merge_requests)/(\d+))", f)
        if m:
            links.append({"label": f"PR #{m.group(2)}", "url": m.group(1)})
    return links


def not_connected(cfg: Config) -> str:
    """At session start: a note for the AI when this computer is not set up yet ('' when it is)."""
    if cfg.brain and stored_key(cfg):
        return ""
    how = (
        "run /tomatio-hooks:setup"
        if os.environ.get("CLAUDE_PLUGIN_ROOT")
        else "run tomatio-hooks setup <brain> and tomatio-hooks login in a terminal"
    )
    return (
        "TomaTío hooks: secrets are kept out of this session, but TíoDay logging is off because this "
        f"computer is not connected to a brain yet. If the person asks about it, {how}."
    )


def handle(ev: events.Event, cfg: Config) -> Answer:
    answer = Answer()

    if ev.name == events.SESSION_START:
        answer.context = not_connected(cfg)
        return answer

    if cfg.secrets:
        if ev.name == events.PRE_TOOL and ev.command:
            refusal = secrets.check_command(ev.command)
            if refusal:
                answer.deny = refusal.reason
                return answer
            answer.rewrite = masked_input(ev)
        if ev.name in (events.POST_TOOL, events.TOOL_FAILED) and ev.tool_response is not None:
            masked, kinds = mask_value(ev.tool_response)
            if kinds:
                answer.mask = masked
                where = "now hidden" if replaces_output(ev) else f"which {TOOLS.get(ev.tool, ev.tool)} keeps in its transcript"
                answer.context = (
                    f"TomaTío hooks: that output held a secret ({', '.join(kinds)}), {where}. "
                    "Do not repeat, print, store or send it; if you need it, read it into a variable."
                )

    if not cfg.logging or not ev.session_id or not cfg.in_roots(ev.cwd):
        return answer

    with state.session(ev.session_id) as st:
        st["tool"] = ev.tool
        st["cwd"] = ev.cwd or st["cwd"]

        if ev.name == events.POST_TOOL:
            if facts.touches_tioday(ev):
                st["logged"] = True
            if facts.did_work(ev):
                st["did_work"] = True
            new = [f for f in facts.from_event(ev) if f not in st["all_facts"]]
            if new:
                st["facts"].extend(new)
                st["all_facts"].extend(new)
                for link in pr_links(new):
                    if link not in st["links"]:
                        st["links"].append(link)
                if any(SIGNIFICANT.match(f) for f in new):
                    _upsert_inflight(ev, cfg, st)

        elif ev.name == events.STOP:
            if st["did_work"] and not st["logged"] and not ev.stop_hook_active and not st["asked"]:
                st["asked"] = True
                answer.block_stop = stop_reason(st["facts"])
                return answer
            # The turn may end: start the next one clean.
            st["facts"], st["did_work"], st["logged"], st["asked"] = [], False, False, False

        elif ev.name == events.SESSION_END:
            if st["inflight"]:
                brain.later("tomatio-tioday-inflight", {"slug": st["inflight"], "clear": True})
            st["inflight"] = ""

    if ev.name == events.SESSION_END:
        state.forget(ev.session_id)
    return answer


def _upsert_inflight(ev: events.Event, cfg: Config, st: dict) -> None:
    label = TOOLS.get(ev.tool, ev.tool)
    folder = os.path.basename((ev.cwd or "").rstrip("/")) or ev.cwd
    br = branch(ev.cwd)
    args: dict[str, Any] = {
        "slug": st["inflight"] or inflight_slug(ev.tool, ev.session_id),
        "title": f"{label} in {folder}" + (f" ({br})" if br else ""),
        "detail": "; ".join(st["all_facts"][-8:]),
        "who": f"{label} · hooks on {cfg.machine}",
    }
    if st["links"]:
        args["links"] = st["links"][-10:]
    st["inflight"] = args["slug"]
    brain.later("tomatio-tioday-inflight", args)


def render(tool: str, ev: events.Event, answer: Answer) -> tuple[str, int]:
    """The answer in the tool's own form: (stdout, exit code)."""
    if answer.empty():
        return "", 0
    if tool == "cursor":
        if answer.deny:
            return json.dumps({"permission": "deny", "user_message": answer.deny, "agent_message": answer.deny}), 0
        if answer.block_stop:
            return json.dumps({"followup_message": answer.block_stop}), 0
        return "", 0

    # Claude Code and Codex share the same answer shapes.
    if answer.deny:
        return json.dumps({
            "hookSpecificOutput": {
                "hookEventName": ev.raw_name or "PreToolUse",
                "permissionDecision": "deny",
                "permissionDecisionReason": answer.deny,
            }
        }), 0
    if answer.block_stop:
        return json.dumps({"decision": "block", "reason": answer.block_stop}), 0
    if answer.rewrite is not None:
        # Codex takes updatedInput only with "allow". Tested on 0.169.1: the
        # sandbox still applies (a write outside it fails the same way), and
        # commands that ask for more than the sandbox are never rewritten.
        return json.dumps({
            "hookSpecificOutput": {
                "hookEventName": ev.raw_name or "PreToolUse",
                "permissionDecision": "allow",
                "updatedInput": answer.rewrite,
            }
        }), 0
    out: dict[str, Any] = {"hookEventName": ev.raw_name or "PostToolUse"}
    # Claude Code replaces any tool's output (and the transcript keeps the
    # masked one); Codex 0.169 only knows updatedMCPToolOutput, so a shell
    # result there gets the warning alone.
    if answer.mask is not None:
        if tool == "claude-code":
            out["updatedToolOutput"] = answer.mask
        if ev.tool_name.startswith("mcp__"):
            out["updatedMCPToolOutput"] = answer.mask
    if answer.context:
        out["additionalContext"] = answer.context
    return json.dumps({"hookSpecificOutput": out}), 0


def main(argv: list[str] | None = None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    tool = argv[0] if argv else "claude-code"
    if tool == "mask":
        # The other end of a wrapped command: what it prints, secrets hidden.
        try:
            secrets.mask_stream(sys.stdin.buffer, sys.stdout.buffer)
        except BrokenPipeError:
            pass
        return 0
    try:
        raw = sys.stdin.read()
        data = json.loads(raw) if raw.strip() else {}
        if not isinstance(data, dict):
            return 0
        ev = events.parse(tool, data)
        answer = handle(ev, load_config())
        out, code = render(tool, ev, answer)
        if out:
            sys.stdout.write(out)
        return code
    except Exception as e:  # never break the tool
        brain.log(f"hook error ({tool}): {type(e).__name__}: {str(e)[:200]}")
        return 0


if __name__ == "__main__":
    sys.exit(main())
