# TomaTío hooks

One hooks layer for Claude Code, Codex and Cursor (backlog B129: B64, B66, B67, with the brain's hooks login, B65, and the click login, B211). It runs on your own computer, beside the AI tool, and is not part of the WordPress plugin zip. Python 3.10+ only, no other dependencies.

What it does, in every tool:

- **Keeps secrets out of transcripts (B67).** Before a shell command runs, it refuses the ones known to print a secret (`security … -w`, `git credential fill`, `gh auth token`, `env`, `cat .env`, `curl -v` with an Authorization header, …) and tells the AI the safe form: capture it in a variable. After a tool call, it hides known token shapes (GitHub, Anthropic, OpenAI, AWS, Slack, Fly, Stripe, Google keys, JWTs, bearer headers, `password=` lines, WordPress application passwords) and warns the AI not to repeat them. Codex cannot swap a shell result after the fact, so there the hook runs each shell command with its output piped through the same masker (`tomatio-hook mask`): the raw value never reaches Codex or its transcript.
- **Logs work to TíoDay (B66).** In the folders you list, it notes facts from what commands ran and printed: commits, pushes, pull requests, merges, tags, deploys. The first real fact opens an in-flight item on your TíoDay (tool, folder, branch, facts, PR links); session end clears it. When the AI stops after real work and wrote nothing to TíoDay, it is asked once to record it in plain words, with the facts attached.
- **Never blocks work.** Brain calls run in a detached process after the hook has answered; failures go to `~/.local/state/tomatio-hooks/hooks.log` (no secrets in it). Any error in the hook ends in an empty answer.

## Set up

### Claude Code: the plugin (B210)

The TomaTío repository is a Claude Code marketplace (`tomatio`, in `.claude-plugin/marketplace.json` at its root) holding one plugin, `tomatio-hooks`, which is this folder. In Claude Code:

```text
/plugin marketplace add tomatio-ai/tomatio
/plugin install tomatio-hooks@tomatio
```

or from a shell:

```bash
claude plugin marketplace add tomatio-ai/tomatio
claude plugin install tomatio-hooks@tomatio
```

From a checkout of the repository, use its path in place of `tomatio-ai/tomatio`.

The plugin's hooks keep secrets out at once. To log to TíoDay, ask Claude to run `/tomatio-hooks:setup`: it asks for the brain and folders, opens your profile, and you click **Make a hooks login**. Until then, each session starts with a short note that logging is off. Do not also run `tomatio-hooks install claude-code`, or the hooks run twice (`tomatio-hooks check` warns).

### Any tool: by hand

From a checkout of the repository:

```bash
hooks/bin/tomatio-hooks setup https://your-brain.example.com/ --root ~/code
hooks/bin/tomatio-hooks login
hooks/bin/tomatio-hooks install claude-code   # or use the plugin instead
hooks/bin/tomatio-hooks install codex
hooks/bin/tomatio-hooks check
```

`login` (B211) waits on `127.0.0.1` and opens your profile on the brain (TíoDay > Hooks logins). Click **Make a hooks login**: the brain's page posts the new key straight back to `login`, which takes it only when its one-time state, the brain and the page's origin match, and keeps it in the keychain (elsewhere, in `~/.config/tomatio-hooks/key`, readable only by you). Nothing to copy or paste; the key never goes through an AI chat, a command line or a log. Without a browser on that computer, `login --paste` shows the key on the page for you to paste at the keychain's own prompt. A hooks login can only read and write your own TíoDay (see the plugin's manual, tioday.md, "Hooks logins"). The hooks login and the click login need the TomaTío plugin 1.10.2 or later on the brain; older brains have no hooks login.

`install` adds the hooks to `~/.claude/settings.json` or `~/.codex/hooks.json` (`--project DIR` for one project, `--print` to see the block). Installing twice changes nothing. Codex asks you to approve new hooks once, in its `/hooks` screen. If you had a hand-made TíoDay Stop hook in Claude Code's settings, remove it when you install: these hooks replace it.

To undo: `claude plugin uninstall tomatio-hooks@tomatio`; remove the TomaTío entries from `~/.codex/hooks.json`; on macOS, `security delete-generic-password -s tomatio-hooks -a <your brain's host>`; revoke the hooks login on your profile (it is an application password).

## How it is built

- `tomatio_hooks/events.py`: each tool's event read into one shape (Claude Code and Codex share the core fields; Cursor's names are mapped from its docs).
- `tomatio_hooks/secrets.py`: one list of command rules and token shapes for all tools, and the wrapper that masks a Codex command's output as it streams.
- `tomatio_hooks/facts.py`: facts from commands and their output.
- `tomatio_hooks/hook.py`: the decisions, and each tool's answer form.
- `tomatio_hooks/brain.py`: MCP calls with the hooks login, detached.
- `tomatio_hooks/loopback.py`: the click login's local waiter.
- `bin/tomatio-hook <tool>`: what each tool runs. `bin/tomatio-hooks`: setup.
- `.claude-plugin/plugin.json`, `hooks/hooks.json`, `skills/setup/`: the Claude Code plugin. Its marketplace file is `.claude-plugin/marketplace.json` at the repository root.

What each tool can do with an answer:

| | Claude Code | Codex 0.169 | Cursor |
| --- | --- | --- | --- |
| Refuse a command | yes (`permissionDecision: deny`) | yes, same field | `permission: deny` (from docs) |
| Hide a secret in place | every tool (`updatedToolOutput`); the local transcript keeps the hidden form | shell: the command runs piped through the masker (`updatedInput` with `allow`); MCP tools: `updatedMCPToolOutput` | not wired |
| Ask once before stopping | `decision: block` | same field | `followup_message` (from docs) |

## Tested

`make test-hooks` at the repository root, or `python3 -m unittest discover -s tests` in this folder (stdlib only): 32 tests. CI runs them on every pull request.

End to end, 21 Nov 2026, Claude Code headless in a scratch project with a made-up token: `gh auth token` was refused with the safe form; `cat notes.txt` showed `[hidden github-token]` to the AI and in the saved transcript (the raw value appears 0 times); the stop check asked once with the commit listed. Plugin, Claude Code 2.1.330: `claude plugin validate` passes; a headless run with `--plugin-dir` saw the not-connected note, had `tomatio-hooks` on its PATH, listed `/tomatio-hooks:setup`, and had `gh auth token` refused; marketplace add and plugin install worked in a throwaway config folder. Click login: a form posted from a public https page to the local waiter reached it in a Chromium browser, and the waiter took the key.

Codex, 22 Nov 2026, codex-cli 0.169.1 headless (`codex exec --dangerously-bypass-hook-trust -s workspace-write`) with a throwaway `CODEX_HOME` and a made-up token: `gh auth token` was refused with the safe form; `cat notes.txt` ran piped through the masker and Codex saw `[hidden github-token]`; the raw value appears 0 times in the exec JSON, the session files and stderr; the stop check asked once. Before the masking wrapper, the same run left the raw value in Codex's transcript 3 times, which is why it exists. With the hook's `allow`, Codex's sandbox still applied: a write outside it failed exactly as without the hook (read-only and workspace-write). Commands that ask for more than the sandbox, use a TTY, or leave a process running (`&`, `nohup`) are left as written and get the warning only. Not checked: whether `allow` skips an interactive approval prompt in the Codex TUI (headless runs have none), which is why escalated commands are never rewritten.

Live brain, 22 Nov 2026, on a brain running plugin 1.10.2: `check` and a hook's detached TíoDay call reached the MCP endpoint and were refused (HTTP 401) with a made-up key, logged in `hooks.log`, and the hook still answered in under a second. A call with a real hooks login is not yet tested. Cursor: not tested.
