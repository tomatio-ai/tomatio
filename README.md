# TomaTío

**A private brain for your team's AI agents, on your own WordPress.** TomaTío is a free, GPL WordPress plugin. Your AI clients (Claude, ChatGPT, Cursor, VS Code and any other MCP client) connect to it and share one memory: working notes, decisions, a reviewed knowledge base and documents they write together. People stay in charge: every agent reads and writes only the scopes you grant it, knowledge becomes official only when a person approves it, and everything is in an audit log.

Every feature is in the free plugin. There is no paid tier and no licence key, and nothing leaves your site unless you switch on an optional service.

TomaTío (from the Spanish «¡toma, tío!», roughly “take that, dude”) is written `tomatio` in addresses and code.

## The promise to self-hosters

1. **Same software as ours.** The brains we host run the same release zip you download. No private fork.
2. **Updates arrive by themselves.** New versions appear in WordPress's own Updates screen, with one-click or automatic updates.
3. **Updates are safe.** Database changes migrate automatically, connections keep working, and every release has a changelog.
4. **Nothing is locked.** Every feature is in the free plugin. Optional services stay optional.
5. **You can leave.** Export everything in the open [Portable Context Protocol](https://github.com/pcp-protocol/pcp) format and take it to any host or tool.
6. **Verifiable.** Every release zip comes with a SHA-256 checksum, and the code is public under the GPL.

## What you need

- **A WordPress install of its own**, for example `brain.yourcompany.com`, with nothing else on it. Never add TomaTío to an existing website: a brain holds your team's private context and your agents' keys, and every other plugin, theme and administrator on a site can reach its database. Most hosts create a new WordPress site in one click.
- **WordPress 7.1 or newer**, installed at the root of its domain or subdomain (not in a subfolder such as `example.com/brain/`), because AI clients look for the brain's sign-in details at the root.
- **PHP 8.3 or newer.** WordPress will not activate the plugin on older PHP. Many hosts still default to 8.1 or 8.2 but let you switch: look for "PHP version", "PHP Selector" or "MultiPHP Manager" in your hosting control panel (cPanel, Plesk, hPanel and the like) and pick 8.3 for that site.
- **HTTPS**, so AI clients can sign in. Any host with free Let's Encrypt certificates will do.

## Install in 10 minutes

1. **Create the site.** In your hosting panel, create a new WordPress site on its own subdomain, with PHP 8.3 and HTTPS. Sign in to its wp-admin.
2. **Download the release.** From the project's [Releases page](https://github.com/tomatio-ai/tomatio/releases), download `tomatio-<version>.zip` and `tomatio-<version>.zip.sha256`. Use the release zip, not GitHub's "Download ZIP" button: only the release has the libraries and built files the plugin needs.
3. **Check the download** (optional, recommended). In a terminal, in the folder with both files:

   ```bash
   shasum -a 256 -c tomatio-*.zip.sha256
   ```

   It should print `OK`. On Linux, `sha256sum -c` does the same.
4. **Upload and activate.** In wp-admin: Plugins > Add New Plugin > Upload Plugin, choose the zip, Install Now, then Activate.
5. **Make the site a brain.** On a fresh install, TomaTío asks once: "Is this WordPress just for TomaTío?" Say yes: it hides the website screens (posts, pages, themes), shows a simple home page for your team and opens TomaTío when you sign in. Nothing is deleted, and you can turn it off later in Settings ("This site is dedicated to TomaTío").
6. **Follow Get started.** The TomaTío Overview has a short checklist. A first team scope (a shared space for memory) already exists; add the agents you want (one per AI client or role) and invite your teammates as WordPress users.

## Connect an AI

Open **TomaTío > Connect an AI**. It shows your brain's connector address (`https://<your-site>/wp-json/agent-brain/v1/mcp`) with a copy button, and step-by-step instructions for each client.

- **claude.ai and Claude Desktop:** in claude.ai, Customize > Connectors > Add > Custom connector (in Claude Desktop, Settings > Connectors); paste the address, click Connect, pick the agent this AI should be, check the scopes and allow access.
- **ChatGPT (developer mode), Cursor, VS Code (GitHub Copilot), Gemini CLI and other MCP clients:** add an MCP server at the same address. The client finds the sign-in by itself (OAuth 2.1 with dynamic client registration) and you approve it on the consent screen.
- **Claude Code and other command-line clients:** sign in as one agent with a WordPress application password sent as a header. The Connect an AI page builds the exact command for you.

Each AI signs in as one agent and sees only that agent's scopes. Everything it does appears in the audit log.

## Updates

TomaTío updates itself like any plugin from WordPress.org:

- The plugin checks the project's Releases page about twice a day and offers each new version in **Dashboard > Updates** and on the Plugins screen.
- Before installing, it downloads the release's `.sha256` file and refuses any zip whose checksum does not match.
- For hands-off updates, click **Enable auto-updates** next to TomaTío on the Plugins screen.
- Database changes run on their own after an update, and every release lists its changes in the changelog on the Releases page.
- If a release adds new tools, an AI client may need to reconnect before it sees them. The Connect an AI page says how for each client.

Hosts that manage updates themselves can turn the built-in updater off with `define( 'AGENT_BRAIN_DISABLE_UPDATER', true );` in `wp-config.php` (or the `agent_brain/updater_enabled` filter). When TomaTío is listed on WordPress.org, updates will come from there instead and the built-in updater steps aside.

## Leaving, or moving

Your brain is yours to take with you.

- **Export:** TomaTío > Export and import > Make a full export. You get one zip in the open PCP v0.3 format: every memory and knowledge page with its history, documents and handoffs, people, agents and scopes, the audit log and erasure receipts, all checked by a manifest of SHA-256 hashes. It holds no passwords or keys. From the command line: `wp tomatio export`.
- **Migrate** a whole brain from its full export into a new, empty brain on any host, or **import** an archive from another brain or PCP tool, where it arrives as proposals for a person to review. Both check the whole archive before writing anything and offer a dry run. (Restoring one of the brain's own encrypted backups is a different thing, under Backups.)
- **Erase:** Forget removes a subject, named pages or everything a former member or agent wrote, and gives you a receipt.
- **Uninstall:** deleting the plugin keeps your data. To remove every trace of it on delete, set the option first: `wp option update ab_delete_data_on_uninstall 1`.

Export, migration and import are built and in every release, and every brain advertises export to other PCP tools (`"export": true` in `/.well-known/pcp.json`): the cross-brain round trip is an automated test.

## Good to know

- **No tracking.** TomaTío collects no analytics. It makes no outbound requests of its own except the update check above, and the services you switch on yourself: summarising old memories with your site's AI provider or an OpenAI-compatible endpoint you configure, and the optional TomaTío cloud service. Without any of them, memories still roll over with a built-in digest.
- **Sharing documents.** Documents and small HTML pages can be shared through links that need no sign-in. Share links work on the brain's own address, with nothing else to set up.
- **Multisite** works, with a separate brain per site. Details are in the plugin's `readme.txt`.

## Who makes it

[Roger Gentry](https://github.com/jamesrascal) designed Agent Brain, the plugin TomaTío grew out of, and built its first version. [Dennis Kittrell](https://github.com/thefactremains) had the idea of building it on WordPress, leads the project, and has done most of the work since mid-October 2026.

## Help and contributing

- The manual: [tomatio.ai/docs](https://tomatio.ai/docs) explains every part of a brain, for the people who run it, the people who use it and the AIs connected to it.
- Questions and bugs: open an issue in the project's tracker.
- Contributing: start with [CONTRIBUTING.md](CONTRIBUTING.md).
- Licence: GPL-2.0-or-later.
