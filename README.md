# TomaTío

[![Claude](https://img.shields.io/badge/Claude-connector-d97757)](https://tomatio.ai/docs/connect-an-ai)
[![ChatGPT](https://img.shields.io/badge/ChatGPT-connector-10a37f)](https://tomatio.ai/docs/connect-an-ai)
[![Cursor](https://img.shields.io/badge/Cursor-MCP-555)](https://tomatio.ai/docs/connect-an-ai)
[![VS Code](https://img.shields.io/badge/VS%20Code-MCP-007acc)](https://tomatio.ai/docs/connect-an-ai)
[![WordPress 7.1+](https://img.shields.io/badge/WordPress-7.1%2B-21759b)](https://tomatio.ai/docs/install)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](https://tomatio.ai/docs/install)
[![GPL](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

**A team brain for your people and their AI agents, on your own WordPress.**

Claude, ChatGPT, Cursor, VS Code and any other Model Context Protocol (MCP) client connect to one shared memory: working notes, decisions, a reviewed knowledge base and documents they write together. People stay in charge.

Free and GPL. Every feature is in the plugin: no paid tier, no licence key.

## Why TomaTío

1. **Runs on the hosting you already have.** A WordPress plugin under 2 MB: no Docker, no extra services.
2. **People approve what counts.** Agents read and write only the scopes you grant them, knowledge becomes official only when a curator approves it, and every action is in the audit log with who wrote each part.
3. **You can leave with everything.** Export a whole team brain in the open [Portable Context Protocol (PCP)](https://github.com/pcp-protocol/pcp) format, move it to any host, and erase what you must with a receipt.

## Quick start

1. **Create a new WordPress site** on its own subdomain (for example `brain.yourcompany.com`) with PHP 8.3 and HTTPS.
2. **Upload the plugin.** Download `tomatio-<version>.zip` from [Releases](https://github.com/tomatio-ai/tomatio/releases), then in wp-admin: Plugins > Add New Plugin > Upload Plugin > Activate. Say yes when it asks whether the site is just for TomaTío.
3. **Connect an AI.** Open **TomaTío > Connect an AI**, copy the connector address and add it to your AI client.

The full walkthrough is in [Install](https://tomatio.ai/docs/install).

## Documentation

Everything else is in the manual at **[tomatio.ai/docs](https://tomatio.ai/docs)**: [install](https://tomatio.ai/docs/install) (requirements, checksums, updates, multisite, uninstall), [connect an AI](https://tomatio.ai/docs/connect-an-ai), [export and migration](https://tomatio.ai/docs/export-and-migration), and [what self-hosters can count on](https://tomatio.ai/self-host).

## Who makes it

[Roger Gentry](https://github.com/jamesrascal) designed Agent Brain, the plugin TomaTío grew out of, and built its first version. [Dennis Kittrell](https://github.com/thefactremains) had the idea of building it on WordPress, leads the project, and has done most of the work since mid-October 2026.

## Help and contributing

- Questions and bugs: open an issue.
- Contributing: start with [CONTRIBUTING.md](CONTRIBUTING.md).
- Licence: GPL-2.0-or-later.
