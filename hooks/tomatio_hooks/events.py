"""One event shape for every coding tool.

Claude Code and Codex send the same JSON core on stdin (see portakey
docs/hooks-test.md): hook_event_name, session_id, transcript_path, cwd,
tool_name, tool_input, tool_response, last_assistant_message. Cursor uses
camelCase event names. A wrapper names the tool; this module maps the rest.
"""
from __future__ import annotations


from dataclasses import dataclass, field
from typing import Any

# Common names the handlers use.
SESSION_START = "session_start"
PROMPT = "prompt"
PRE_TOOL = "pre_tool"
POST_TOOL = "post_tool"
TOOL_FAILED = "tool_failed"
SUBAGENT_START = "subagent_start"
SUBAGENT_STOP = "subagent_stop"
STOP = "stop"
SESSION_END = "session_end"
OTHER = "other"

_NAMES = {
    # Claude Code and Codex
    "SessionStart": SESSION_START,
    "UserPromptSubmit": PROMPT,
    "PreToolUse": PRE_TOOL,
    "PostToolUse": POST_TOOL,
    "PostToolUseFailure": TOOL_FAILED,
    "SubagentStart": SUBAGENT_START,
    "SubagentStop": SUBAGENT_STOP,
    "Stop": STOP,
    "SessionEnd": SESSION_END,
    # Cursor (from its docs; not tested)
    "sessionStart": SESSION_START,
    "beforeSubmitPrompt": PROMPT,
    "preToolUse": PRE_TOOL,
    "beforeShellExecution": PRE_TOOL,
    "beforeMCPExecution": PRE_TOOL,
    "postToolUse": POST_TOOL,
    "afterShellExecution": POST_TOOL,
    "afterMCPExecution": POST_TOOL,
    "postToolUseFailure": TOOL_FAILED,
    "subagentStart": SUBAGENT_START,
    "subagentStop": SUBAGENT_STOP,
    "stop": STOP,
    "sessionEnd": SESSION_END,
}

SHELL_TOOLS = {"Bash", "shell", "exec_command", "local_shell", "Shell", "run_terminal_cmd"}


@dataclass
class Event:
    tool: str
    raw_name: str
    name: str
    session_id: str = ""
    cwd: str = ""
    transcript_path: str = ""
    tool_name: str = ""
    tool_input: Any = None
    tool_response: Any = None
    last_message: str = ""
    prompt: str = ""
    stop_hook_active: bool = False
    raw: dict = field(default_factory=dict)

    @property
    def is_shell(self) -> bool:
        return self.tool_name in SHELL_TOOLS or self.raw_name in ("beforeShellExecution", "afterShellExecution")

    @property
    def command(self) -> str:
        """The shell command of a shell tool call, or '' (without the hooks' masking wrapper)."""
        from .secrets import unwrap

        return unwrap(self._command())

    def _command(self) -> str:
        if not self.is_shell:
            return ""
        ti = self.tool_input
        if isinstance(ti, dict):
            cmd = ti.get("command") if "command" in ti else ti.get("cmd")
            if isinstance(cmd, list):
                return " ".join(str(c) for c in cmd)
            return str(cmd or "")
        if isinstance(ti, str):
            return ti
        return str(self.raw.get("command") or "")

    def response_text(self) -> str:
        """Everything the tool printed, as one string."""
        return as_text(self.tool_response)


def as_text(value: Any) -> str:
    """Every string in value (stdout, stderr, content blocks...), one per line."""
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    parts: list[str] = []

    def walk(v: Any) -> None:
        if isinstance(v, str):
            parts.append(v)
        elif isinstance(v, dict):
            for x in v.values():
                walk(x)
        elif isinstance(v, list):
            for x in v:
                walk(x)
        elif isinstance(v, (int, float)) and not isinstance(v, bool):
            parts.append(str(v))

    walk(value)
    return "\n".join(parts)


def parse(tool: str, data: dict) -> Event:
    raw_name = str(data.get("hook_event_name") or data.get("hookEventName") or "")
    name = _NAMES.get(raw_name, OTHER)
    tool_name = str(data.get("tool_name") or "")
    tool_input = data.get("tool_input")
    if raw_name in ("beforeShellExecution", "afterShellExecution"):
        tool_name = tool_name or "Shell"
        tool_input = tool_input or {"command": data.get("command", "")}
    response = data.get("tool_response")
    if response is None:
        response = data.get("tool_output", data.get("output"))
    if name == TOOL_FAILED and response is None:
        response = data.get("error")
    return Event(
        tool=tool,
        raw_name=raw_name,
        name=name,
        session_id=str(data.get("session_id") or data.get("conversation_id") or ""),
        cwd=str(data.get("cwd") or (data.get("workspace_roots") or [""])[0] or ""),
        transcript_path=str(data.get("transcript_path") or ""),
        tool_name=tool_name,
        tool_input=tool_input,
        tool_response=response,
        last_message=str(data.get("last_assistant_message") or ""),
        prompt=str(data.get("prompt") or ""),
        stop_hook_active=bool(data.get("stop_hook_active")),
        raw=data,
    )
