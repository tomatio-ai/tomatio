"""tomatio-hooks: set up the hooks on this computer.

  tomatio-hooks setup <brain> [--root DIR ...]   where the brain is, which folders to log
  tomatio-hooks login [<brain>] [--paste]         click "Make a hooks login"; the key comes straight here
  tomatio-hooks install <claude-code|codex> [--project DIR] [--print]
  tomatio-hooks check                             config, key present, one test call

login waits on 127.0.0.1 and the brain's page posts the new key back to
it, so there is nothing to copy. With --paste (no browser on this
computer), the page shows the key and you paste it at the keychain's own
prompt. Either way it is never printed, logged or passed on a command line.
"""
from __future__ import annotations

import argparse
import json
import os
import shlex
import subprocess
import sys
import webbrowser
from pathlib import Path
from urllib.parse import quote

from . import brain, loopback
from . import config as cfgmod

EVENTS = {
    "claude-code": ["PreToolUse", "PostToolUse", "PostToolUseFailure", "Stop", "SessionEnd"],
    "codex": ["PreToolUse", "PostToolUse", "Stop", "SessionEnd"],
}
MATCHED = {"PreToolUse", "PostToolUse", "PostToolUseFailure"}
TARGETS = {
    "claude-code": (Path.home() / ".claude" / "settings.json", ".claude/settings.json"),
    "codex": (Path.home() / ".codex" / "hooks.json", ".codex/hooks.json"),
}


def hook_command(tool: str) -> str:
    script = Path(__file__).resolve().parent.parent / "bin" / "tomatio-hook"
    return f"python3 {shlex.quote(str(script))} {tool}"


def hooks_block(tool: str) -> dict:
    block: dict = {}
    for name in EVENTS[tool]:
        entry: dict = {"hooks": [{"type": "command", "command": hook_command(tool), "timeout": 10}]}
        if name in MATCHED:
            entry = {"matcher": "*", **entry}
        block[name] = [entry]
    return block


def merge(existing: dict, tool: str) -> dict:
    """existing settings with our hooks added once (a second install changes nothing)."""
    ours = hooks_block(tool)
    hooks = existing.setdefault("hooks", {})
    for name, entries in ours.items():
        current = hooks.setdefault(name, [])
        current[:] = [e for e in current if not any("tomatio-hook" in str(h.get("command", "")) for h in e.get("hooks", []))]
        current.extend(entries)
    return existing


def cmd_setup(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if args.brain:
        cfg.brain = args.brain if args.brain.endswith("/") else args.brain + "/"
    for root in args.root or []:
        root = os.path.realpath(os.path.expanduser(root))
        if root not in cfg.roots:
            cfg.roots.append(root)
    if args.machine:
        cfg.machine = args.machine
    path = cfgmod.save(cfg)
    print(f"Saved {path}: brain {cfg.brain or '(none)'}, {len(cfg.roots)} folder(s) logged, computer \"{cfg.machine}\".")
    return 0


def cmd_login(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if args.brain:
        cfg.brain = args.brain if args.brain.endswith("/") else args.brain + "/"
        cfgmod.save(cfg)
    if not cfg.host:
        print("Say which brain first: tomatio-hooks login https://your-brain.example.com/", file=sys.stderr)
        return 2
    if args.paste:
        return login_paste(cfg, args)

    waiter = loopback.Waiter(cfg)
    page = loopback.login_page(cfg, waiter.port, waiter.state)
    print(f"Opening your profile on {cfg.host}. Click \"Make a hooks login for {cfg.machine}\".")
    print(f"If no browser opens, go to: {page}")
    if not args.no_browser:
        webbrowser.open(page)
    print(f"Waiting up to {args.timeout // 60} minutes for the brain to send the key here (Ctrl-C stops)…")
    try:
        key = waiter.wait(args.timeout)
    except KeyboardInterrupt:
        print("\nStopped.")
        return 1
    if not key:
        why = f" The last try was refused: {waiter.refused}." if waiter.refused else ""
        print(f"No key came.{why} Run tomatio-hooks login again, or use --paste.", file=sys.stderr)
        return 1
    if not loopback.keep(cfg, key):
        print("The keychain did not save the key.", file=sys.stderr)
        return 1
    print("Got it: the key is in " + ("the keychain." if sys.platform == "darwin" else f"{cfgmod.key_file()} (only you can read it)."))
    return cmd_check(args)


def login_paste(cfg: cfgmod.Config, args: argparse.Namespace) -> int:
    page = f"{cfg.brain}wp-admin/profile.php?tomatio_hooks={quote(cfg.machine)}#tomatio-hooks"
    print(f"1. Open {page}")
    print(f"   and click \"Make a hooks login for {cfg.machine}\". Copy the key it shows.")
    if not args.no_browser:
        webbrowser.open(page)
    if sys.platform != "darwin":
        print("2. Paste the key below. It is not shown.")
        import getpass

        key = getpass.getpass("Key: ").strip()
        if ":" not in key or not loopback.keep(cfg, key):
            print("That is not a hooks login key; nothing saved.", file=sys.stderr)
            return 1
        return cmd_check(args)
    print("2. Paste the key at the keychain's prompt below (twice). It is not shown and not saved anywhere else.")
    done = subprocess.run(["security", "add-generic-password", "-U", "-s", cfgmod.SERVICE, "-a", cfg.host, "-l", "TomaTío hooks", "-w"])
    if done.returncode != 0:
        print("The keychain did not save the key.", file=sys.stderr)
        return 1
    return cmd_check(args)


def cmd_install(args: argparse.Namespace) -> int:
    tool = args.tool
    if args.print:
        print(json.dumps({"hooks": hooks_block(tool)}, indent=2))
        return 0
    home, rel = TARGETS[tool]
    path = Path(args.project).expanduser() / rel if args.project else home
    try:
        existing = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
    except ValueError:
        print(f"{path} is not valid JSON; not changed.", file=sys.stderr)
        return 1
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(merge(existing, tool), indent=2) + "\n", encoding="utf-8")
    print(f"Added the TomaTío hooks to {path}.")
    if tool == "codex":
        print("Codex asks you to approve new hooks: open /hooks in Codex once and approve them.")
    if tool == "claude-code":
        print("If an older TíoDay Stop hook is in that file, remove it: these hooks replace it.")
        print("With the tomatio-hooks Claude Code plugin installed you do not need this: use one or the other.")
    return 0


def twice() -> bool:
    """Whether Claude Code runs the hooks twice: the plugin and a copy in settings.json."""
    path = TARGETS["claude-code"][0]
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return False
    plugin_on = any(k.startswith("tomatio-hooks@") and v for k, v in (data.get("enabledPlugins") or {}).items())
    copy = "tomatio-hook" in json.dumps(data.get("hooks") or {})
    return plugin_on and copy


def cmd_check(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if twice():
        print(f"Claude Code runs these hooks twice: the tomatio-hooks plugin and a copy in {TARGETS['claude-code'][0]}. Remove the copy's tomatio-hook entries.")
    print(f"Brain: {cfg.brain or '(none)'}")
    print(f"Folders logged: {', '.join(cfg.roots) or '(none: secrets are still kept out everywhere)'}")
    print(f"Computer: {cfg.machine}")
    key, where = cfgmod.key_and_source(cfg)
    print(f"Hooks login key: {'in ' + where if key else 'missing'}")
    if not (cfg.brain and key):
        return 1
    try:
        answer = brain.call(cfg, "tomatio-tioday-get", {}, key=key)
    except brain.BrainError as e:
        print(f"The brain refused or did not answer: {e}")
        return 1
    tasks = answer.get("tasks") if isinstance(answer, dict) else None
    print("Connected: your TíoDay answers" + (f" ({len(tasks)} open tasks)." if isinstance(tasks, list) else "."))
    return 0


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="tomatio-hooks", description="TomaTío hooks for Claude Code, Codex and Cursor.")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("setup")
    s.add_argument("brain", nargs="?")
    s.add_argument("--root", action="append")
    s.add_argument("--machine")
    s.set_defaults(func=cmd_setup)
    s = sub.add_parser("login")
    s.add_argument("brain", nargs="?")
    s.add_argument("--paste", action="store_true", help="show the key on the page and paste it here instead")
    s.add_argument("--no-browser", action="store_true")
    s.add_argument("--timeout", type=int, default=600, help="seconds to wait for the key (default 600)")
    s.set_defaults(func=cmd_login)
    s = sub.add_parser("install")
    s.add_argument("tool", choices=sorted(EVENTS))
    s.add_argument("--project")
    s.add_argument("--print", action="store_true")
    s.set_defaults(func=cmd_install)
    s = sub.add_parser("check")
    s.set_defaults(func=cmd_check)
    args = p.parse_args(argv)
    return args.func(args)
