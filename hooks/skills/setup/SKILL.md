---
name: setup
description: Connect the TomaTío hooks on this computer to the person's brain, so their TíoDay stays current from Claude Code. Use when the person asks to set up, connect, log in or check the TomaTío hooks, or when a session note says the hooks are not connected.
---

# Set up the TomaTío hooks

The plugin's hooks already keep secrets out of this session. This connects them to the person's brain so the work they do here is logged to their TíoDay. `tomatio-hooks` is on the Bash tool's PATH while the plugin is on.

1. Check first: run `tomatio-hooks check`. If it ends with "Connected", say so and stop.
2. Ask the person, in one message, for anything missing:
   - their brain's address, such as `https://nautilus.tomatio.ai/`;
   - which folders to log to TíoDay (their code folder, such as `~/code`). Secrets are kept out everywhere either way.
3. Run `tomatio-hooks setup <brain> --root <folder>` (one `--root` per folder).
4. Tell the person their profile will open in the browser and they should click **Make a hooks login**. Then run `tomatio-hooks login` with a Bash timeout of 600000 ms. It waits on 127.0.0.1, the brain's page sends the key straight to it, and it keeps the key in the keychain, then runs the check.

Rules:

- The key goes browser → this computer → keychain. Never read it, print it or ask the person to paste it into the chat. Never run `tomatio-hooks login --paste` yourself; if the person wants it (no browser on this computer), tell them to run it in a terminal of their own.
- If `login` says no key came, the person may not have clicked in time: offer to run it again.
- If the person also installed the hooks with `tomatio-hooks install claude-code`, they run twice: `check` says so. Offer to remove that copy from `~/.claude/settings.json` only when they say yes.
