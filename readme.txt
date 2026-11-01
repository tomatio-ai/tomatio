=== TomaTío ===
Contributors: agentbrain
Tags: ai, agents, memory, mcp, knowledge-base
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.9.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private, scoped, curator-reviewed memory for external MCP agents, on a WordPress site of its own.

== Description ==

TomaTío gives autonomous and semi-autonomous AI agents a private, permission-scoped memory store inside your site: raw working memory, compressed episodes and facts, and a curator-reviewed knowledge base, all exposed to agents only through the Model Context Protocol (MCP) with per-scope read/write grants and a full audit trail.

People and agents also write documents and small HTML pages together and share them through links that need no sign-in. A shared page can remember: with the page store switched on, it keeps JSON data between visits and shares it with everyone who opens the link: a leaderboard, a poll, a sign-up sheet, a checklist, a feedback form. It works in every browser, Safari and iPhone included. Each collection follows rules the server enforces, picked from presets (counter, ballot, entries, personal, inbox, shared): who may write, what a write may do, who sees what, plus key patterns, a value schema and rate limits. Every write is kept for 30 days with its writer and share link, so members can restore a version or undo everything a leaked link wrote.

Every feature described here is fully available in the free plugin. There is no separate paid tier, no functionality held back behind a license key, and no nag screens asking you to upgrade — the optional cloud service described below is a convenience for offloading compression and backups, not a requirement for using the plugin. Summarising old memories can use your site's own AI provider, an OpenAI-compatible endpoint you configure (URL, API key, model), or the optional cloud service; each is optional, and without any of them memories still roll over with a built-in digest that loses nothing.

The plugin collects no analytics and does not track site usage or visitors. The only data that ever leaves your site is the data described in "External services" below, and only once you have explicitly opted in.

== External services ==

This plugin can optionally connect to the three services below. None of them is contacted unless you explicitly configure and opt in to it. Apart from those, the only request TomaTío makes on its own is the update check described in section 4.

**1. The site's own AI provider, via the WordPress AI Client**

TomaTío uses the community WordPress AI Client library to compress raw agent memory into summaries and facts. This library sends the content being compressed (the raw memory text you or your agents have written) to whichever AI provider *you* have configured and hold your own API key for (for example OpenAI, Anthropic, or Google). TomaTío never bundles or supplies its own key, and never sees or stores your provider credentials — the WordPress AI Client passes them directly from your own configuration to your chosen provider on your behalf.

This only happens when the automatic memory-compression job runs, and only for the memory content being compressed at that time.

- Service depends on which provider you configure (e.g. OpenAI, Anthropic, Google).
- Terms of service and privacy policy are those of the provider you choose; consult the WordPress AI Client's own documentation for the current list of supported providers and links to each one's terms.

**2. An OpenAI-compatible endpoint you configure**

In Settings you can enter the base URL, API key and model of any service that speaks the OpenAI chat completions API (for example OpenAI, Cloudflare Workers AI or AI Gateway, OpenRouter, or a local server such as Ollama). When the memory-compression job runs, the memory text being summarised is sent to that URL with your key. The API key is encrypted in your database (libsodium, with a key derived from your wp-config.php salts), is never shown again, and is excluded from logs, exports and backups. Without https and a public address the URL is refused, unless you tick that the server is on your own network.

- Service depends on the endpoint you enter; terms and privacy policy are those of that provider.

**3. The optional TomaTío cloud service**

The TomaTío cloud service is not available to the public yet. The plugin never contacts it unless an administrator connects the site to it from Settings, which it is not by default. Its terms of service and privacy policy will be linked here when the service opens. Once connected, the plugin may send the following to that service:

- *Compression jobs*: a signed job payload containing the raw memory content to be summarized/compressed, when you have chosen the cloud provider instead of (or in addition to) your own AI provider under option 1 or 2 above.
- *Encrypted backups*: full-site memory backups (every memory page in the PCP format plus a SQL dump of the plugin's own tables) are encrypted on your site before upload, in chunks, so the cloud service only ever receives ciphertext it cannot read.
- *Scheduler pings*: small signed "tick" requests with no memory content, used to trigger scheduled jobs (compression, backups) on sites that don't have reliable server-side cron.

All requests to the cloud service are signed (Ed25519) and none of your WordPress credentials are ever sent or stored by the cloud service. This only happens after you, as an administrator, explicitly connect your site to the cloud service from Settings; it never happens by default and can be disconnected at any time, which stops all further transmission.

- Service: TomaTío Cloud (not yet open to the public).

**4. Update check (GitHub)**

Copies installed from a release zip check the project's GitHub Releases for a new version about twice a day, and download the new zip and its checksum when you update. The requests go to api.github.com and github.com and carry no content from your site; the user agent names only the plugin and its version, not your site's address. GitHub sees the request's IP address, as with any download.

- Service: GitHub Releases for tomatio-ai/tomatio.
- Terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
- Privacy policy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
- To turn it off, add `define( 'AGENT_BRAIN_DISABLE_UPDATER', true );` to wp-config.php, or use the `agent_brain/updater_enabled` filter. Copies installed from WordPress.org use WordPress.org's updates instead.

== Installation ==

Give the brain a WordPress install of its own (for example `brain.example.com`), never an existing website: the brain holds your team's private context and your agents' keys, and every other plugin, theme and administrator on a site can reach its database. WordPress must serve the root of its domain or subdomain, over HTTPS, on PHP 8.3 or newer.

1. Download `agent-brain-<version>.zip` and its `.sha256` file from the project's Releases page (not the source "Download ZIP", which lacks the libraries and built files), and check it: `shasum -a 256 -c agent-brain-<version>.zip.sha256`.
2. Plugins > Add New Plugin > Upload Plugin, choose the zip, install and activate.
3. On a fresh install, answer yes to "Is this WordPress just for TomaTío?" to hide the website screens.
4. Follow Get started on the TomaTío Overview, then TomaTío > Connect an AI to connect Claude, ChatGPT, Cursor, VS Code or another MCP client.

New versions appear in Dashboard > Updates; each zip is checked against its `.sha256` before it is installed.

== Multisite ==

TomaTío works on WordPress multisite networks with one **per-site brain**: every site keeps its own `ab_*` database tables, options, cron jobs, custom post types and transients, and nothing is shared between sites at the data layer.

- **Network activation**: when the plugin is network-activated, every existing site is provisioned during activation (tables, `ab_agent`/`ab_curator` roles, options), and every site created later is provisioned automatically when WordPress initializes it.
- **Per-site activation**: activating the plugin on a single site of a network provisions only that site.
- **Users and principals**: WordPress shares user accounts and user meta across a network. TomaTío keeps one principal id per person network-wide: the first site a person uses issues their id, and other sites of the network adopt that same id (see the Principals module in the Portability section of the codebase).
- **Endpoints**: REST, MCP and OAuth endpoints are per-site, under each site's own URL. An agent connecting to `https://example.com/subsite/` talks to that site's brain only; tokens issued for one site are not valid on another.
- **WP-CLI**: run commands per site with the global `--url` flag, e.g. `wp tomatio export --url=https://example.com/subsite/`. There is no `--network` flag yet.
- **Not supported**: a network-wide shared brain (one set of tables for the whole network). There is no Network Admin dashboard; manage each site under its own wp-admin.

== Frequently Asked Questions ==

= Does this plugin track my site or my visitors? =

No. TomaTío does not collect analytics and does not track site usage or visitors. See "External services" above for the only data that ever leaves your site, and only with your explicit opt-in.

= Are any features locked behind a paid upgrade? =

No. Every feature of this plugin is available for free; there is no paid tier and nothing is disabled pending a purchase.

= What happens to my data if I delete the plugin? =

By default, nothing is removed when you delete the plugin — all data stays in your database. If you explicitly enable "Delete all data on uninstall" in Settings first, deleting the plugin will permanently remove every table, option, user meta value, post, and taxonomy term the plugin created, along with any scheduled cron jobs and its export directory.

= The plugin used to be called "Agent Brain" — what changed? =

Only the display name. TomaTío is the same plugin; its plugin slug, text domain, option prefixes and database tables are unchanged. During the rename transition, every REST route answers under both the canonical `agent-brain/v1` namespace and the alias `tomatio/v1`, every MCP ability answers under both the `agent-brain-` and `tomatio-` spellings, and WP-CLI answers as both `wp agent-brain` and `wp tomatio`. The old `agent-brain` spellings are deprecated and keep working for at least one minor release.

== Changelog ==

= Unreleased =

= 1.9.10 =
* The release download is now named tomatio-<version>.zip. The plugin folder inside stays agent-brain, so it updates in place.

= 1.9.9 =
* The admin screens are redesigned: Overview, Library (Memories, Knowledge, Procedures, Documents, Comments), Agents, Activity, Handoffs, Provenance and Settings now share one list-and-detail layout, each saying what needs you first.
* The Review inbox now says that approving a handoff shares the whole scopes its memories are in, and names them.
* The members address of a document with nothing approved yet now says so instead of showing a blank page.
* People who can review a document now see its sections waiting for review on its members address, marked, with Approve all.
* Deleting the plugin with data removal turned on now also removes artifact comments.

= 1.9.8 =
* The Review Queue is now one inbox: facts, procedures, handoffs and proposed agents in a list with a detail pane, a word diff against official knowledge, edit in place, Undo and keyboard shortcuts.

= 1.9.7 =
* The signed-in home page gives every person the brain's MCP address with a copy button, and the role-switching address when that is on.
* artifact-html-put takes edits (find and replace, or insert before or after) to change part of a page without resending it, and refuses a whole-file upload that would shrink a file by over 90% unless force=true.
* The Review Queue mostly runs itself: facts from curators' own AIs can be made official automatically (Settings > Review, off by default), anything nobody decides closes after 14 days with its memory kept, curators can review in chat with their AI, and the page says plainly what it is.
* Every AI you connect keeps your TíoDay current: the brain tells it you have one, and six new tools let it add tasks for you, mark work in flight, record what got done and your decisions, and answer comments, without knowing how the page stores its data.
* What lands on a TíoDay (done items, decisions, closed tasks, comments) becomes a brain memory, so search and briefings know.

= 1.9.6 =
* Delegated authority, an explicit choice per connection: tick "Let this AI act with my rights when I ask" when connecting an AI, and it can create, publish and approve without review when you ask. Off by default; administrators can forbid it; a "Review required" lock on an artifact still wins.
* The signed-in home page marks pages that have a public share link with a globe and "Public", and a button copies that link. `wp tomatio share remember <url>` adds links made before this release.
* A page that keeps its data in the page store now shows that data on its members address (/a/{slug}) too, as the signed-in member.
* The role-switching connector address is now `.../wp-json/agent-brain/v1/mcp-roles`, with no query. claude.ai dropped the old `?roles=1`, so it showed the agent picker; the old address still works.
* Someone who may edit a page and opens its share link while signed in now gets the Edit button there too, so one link is enough.

= 1.9.5 =
* Connected AIs can find a shared document or page by its title with the new artifact-list tool, instead of needing its id.
* One brain design: documents and pages share TomaTío's look (light and dark, headings, cards, callouts, status pills, tables), with the brain's own name, logo and accent on top.
* A brain shows its own logo: the WordPress Site Icon, or a monogram of its name. TomaTío's mark is no longer the default on the brain's own pages.
* In claude.ai and other clients each tool now appears once, with a plain title, and says whether it only reads, changes or deletes, so you can set the read-only tools to Always allow and use the brain by voice.
* Edit a page in place: pages that work as documents get an Edit button on their members address for the people who may edit them; each save is a new version, live at once.
* An AI connected through claude.ai can add records to a page's data (such as a TíoDay task) again: a record it sends as JSON text is stored as the record.

= 1.9.4 =
* The signed-in home page shows pages and documents with their own icons, and how many memories, knowledge pages, procedures and agents the brain has.

= 1.9.3 =
* Signed in, a dedicated brain's home page is now the team's space: quick links and every page and document you can open, grouped by scope.
* A self-hosted brain shows TomaTío's mark out of the box: on the OAuth consent page, as the favicon (SVG, 32 px PNG, apple-touch-icon) and as the MCP server icon. A Site Icon still wins.
* When an agent uploads an HTML page, it is reminded to embed the logo and favicon so they show on every link.
* Shared pages that keep data (checklists, boards, a TíoDay) now work on private links too.
* The release zip is smaller (about 1.8 MB) and stays under PHP's default 2 MB upload limit, so it installs through Plugins > Add New on a default PHP setup.
* A brain restored onto a new address gives its agents that address, so the sign-in screen no longer shows the old host.

= 1.9.2 =
* The consent screen says which account the AI client will connect as, with your default agent preselected (#591).
* readme.txt: the optional cloud service is marked as not yet open to the public, the placeholder terms and privacy links are gone, and the update check is listed under External services.
* Imports can land as reference: approved once by the controller, searchable and dated, with no review per page (#594).

= 1.9.1 =
* Migrating a brain can let accounts that already exist on the new brain take over their archive identity ("Let accounts here take over their archive identity", `--adopt-accounts`): they keep their login, password and role and gain the person's access and authorship.
* AI apps are told what the brain is and when to use it when they connect, so an app with many connectors asks the brain about its team; set the line under Settings > Onboarding.
* AI apps that register themselves (ChatGPT, Cursor, VS Code, Gemini CLI) can finish signing in again.

= 1.9.0 =
* Site administrators see every scope in wp-admin again; read scopes limit everyone else's lists.
* HTML artifacts move whole: exports and migrations carry their versions, the approved version and mode, version history, page store data with its history, seats, uploads, settings and friendly address. Share links are listed without their tokens and created again on the new brain.
* An export signed by another brain can be migrated, moved or imported here: its signature cannot be checked on this brain, so it goes through with a note, like an unsigned archive. Tampering with an archive signed by this brain is still refused.
* Migrating a whole brain can leave scopes and people behind (the demo scopes and /try logins of a public demo, for example): `--leave-scopes`, `--keep-scopes`, `--leave-people` with `human:try-*` patterns, and the same fields on Export and import. The dry run lists every scope and person of the archive.
* Move scopes here: chosen scopes of another brain's export, with their pages, history, documents, HTML artifacts, page store data, handoffs and people, into a brain in use (Export and import, or `wp agent-brain move-scopes`). Everything waits for review unless the controller keeps approvals; moving the same archive again adds nothing.
* Migrating a brain is no longer called a restore: "Migrate a brain here" on Export and import, `wp agent-brain migrate`, and an entry under WordPress's Tools > Import; restoring a backup stays on Backups.
* After a migration or a move, a Reconnect section gets everyone back in: invite all people by email in one go, give every agent a new key in one downloaded file, see the connector address, and make share links again at the new address. Also `wp agent-brain invite-people`, `agent-keys` and `reconnect`.
* wp-admin shows each person only the knowledge, procedures, artifacts, comments, handoffs and memories within their read scopes, in lists, search, counts and item screens.
* After a migration, the Reconnect section lists the seats that came along: a viewer signs back in with the "#seat=" part of their old personal link, or a curator releases the name so it can be claimed again.

= 1.8.10 =
* E2E job that installs the release zip through the Plugins screen on a clean stack and proves the one-click update (and its checksum refusal).
* PCP discovery now advertises "export": true, and the archive's extension ids are ai.tomatio.doc and ai.tomatio.handoff.
* GitHub mirror: releases now publish weekly (Saturday 09:00 Europe/Madrid or manual dispatch) instead of on every tag, with combined changelog notes.
* The Copy buttons on Connect an AI work again, and Forget asks for confirmation again.

= 1.8.9 =
* Every page of a brain carries its name and mark: admin screens, the sign-in page, favicons, the not-found page and the browser tab of a permanent address.

= 1.8.8 =
* Members addresses (/a/...) run the page's scripts and show its inlined images again.
* The Plugin URI points at tomatio.ai/self-host, and the Installation steps describe a dedicated install, the release zip and its checksum.
* A personal link's Home Screen icon now takes the seat's own colour, so each person's icon stands out on a shared device.
* Shared pages can list the names of their seats (tomatio.seat.list()), so a leaderboard can show players before their first win.

= 1.8.7 =
* Connect an AI is branded with the brain's name and mark and puts the connector address first.
* Adding a teammate as administrator no longer shows the "existing website" warning on a dedicated brain.
* A fresh WordPress asks once whether it is just for TomaTío, and trims itself to a dedicated brain on yes.
* The public roadmap lists agent chat in WordPress, Mattermost, and personal agents such as Claude Duet and OpenAI Dots.
* The public roadmap lists shared pages as phone apps.
* The TomaTío menu is six groups (Overview, Review, Library, Agents, Activity, Settings), with tabs inside each.
* Shared HTML pages work as phone apps: a manifest and icons per link and per seat, so they install on the Home Screen and the Dock with their own name and icon.
* Seats: one public share link, a personal link per name, and store rules with owner "seat" that only that seat may write.
* Open shared pages reload by themselves at their next visibility change when a new version is approved (on by default, per artifact).

= 1.8.6 =
* New brains can start with a small team of agents and some beginner context, from a few onboarding answers.

= 1.8.5 =
* tomatio CLI: export, restore, and provider config commands (#407)
* The public roadmap lists extensions built as shared pages.
* Permanent members addresses use friendly names, like /a/product-planning.
* A private share link is refused, with how to fix it, when the artifact cannot serve it.
* The sign-in page says when a protected link sent you there.
* A link from the brain back to your team's own app or site, set in Settings or from the connector.

= 1.8.4 =
* Self-hosted copies get new releases in WordPress's Updates screen, with the release checksum verified.
* tomatio.ai/hosting pitches team brains as new life for shared hosting.
* The hosting page says large brains will add semantic recall inside MySQL itself.
* The public demo is TomaTío's own: "by Example Hosting" is gone.
* Private share links on the demo now ask you to sign in instead of landing on the demo page.
* tomatio.ai/wordpress explains to the WordPress community how TomaTío turns WordPress into a team context server.
* A guide to the page store for people and agents building shared pages.
* Page store collections now have server-enforced rules picked from presets (counter, ballot, entries, personal, inbox, shared, read); a "write" collection now means members only, with version checks.
* Page store: a shared HTML page keeps state in its artifact's data store, limited to the collections a curator lists, with document versions and a small client script, _tomatio.js.
* The tomatio-* tools now honour act-as and a person's default agent, so claude.ai can save after switching roles.
* act-as takes a login, a role word such as "designer" or a unique prefix, and lists the choices when a word fits several agents.
* Shared HTML pages work on iPhone and in Safari again: the page policy names the share host explicitly.

= 1.8.3 =
* Release zips are also published to GitHub Releases (with the .sha256) once the public mirror exists, not only on Forgejo.
* Warn when the plugin is activated on an existing (non-dedicated) WordPress site, and recommend a dedicated install.
* The demo's public pages can count visits with Plausible, cookieless and EU-hosted, when AB_PLAUSIBLE_SCRIPT is set.
* The public pages and the README say a brain gets a WordPress install of its own, not a place on an existing website.
* tomatio.ai opens on a project home page that says what TomaTío is, with link previews.
* The GitHub source mirror's generated README is now TomaTío-branded, with the tagline, version and brand mark.

= 1.8.2 =
* An agent's human — the person who issued it — now shows next to the FQDN in the Agents page, the Overview activity feed and the audit log.
* A per-artifact shared data store gives sandboxed artifact pages a small JSON API on the preview host (get, list, query, set, update, delete) with per-document, per-collection and rate limits, every write attributed to the viewer or the anonymous share link, off by default behind a curator switch.
* Signed short-lived viewer tokens let a share page know which brain member is viewing it: members links plus a per-artifact curator switch, single-use code exchange, 20-minute HMAC tokens, anonymous by default.
* Shared artifact pages can read live brain data in the artifact's scope: a per-artifact curator switch (off by default) exposes read-only search/get endpoints below the share link, with the named viewer's scope grants applied and anonymous viewers falling back to the artifact's share policy.
* Shared artifact pages can ask the brain a question and receive an AI-generated answer with citations to the memories and approved knowledge it used: a per-artifact curator switch (off by default) exposes a POST endpoint below the share link, with the viewer's scope grants applied, rate limits, per-artifact and site daily cost caps, and every call audited with viewer attribution and citations.
* Per-artifact file uploads: members upload from the shared page with their viewer token, the artifact lead and curators over MCP, everyone holding a share link can list and download, with type and size limits, PCP export and Forget support.
* Comment threads on an artifact version: agents and curators can comment, reply and resolve over MCP and wp-admin, convert a comment to a handoff or review note, and comments travel in PCP exports and answer to Forget.
* A new brain starts with a team scope, granted to its administrators and curators, so agents can save memories without any setup.
* A memory the database did not store is now reported as not saved, instead of as saved under another memory's id.
* Rename to TomaTío: the display name is now TomaTío (tomatillo-green accent, new mark), and the folded `tomatio` spelling is available on machine-facing surfaces — REST routes answer under both `agent-brain/v1` (canonical) and the alias `tomatio/v1`, every MCP ability answers under both `agent-brain-` and `tomatio-` spellings, and WP-CLI answers as both `wp agent-brain` and `wp tomatio`. The old `agent-brain` spellings are deprecated and keep working for at least one minor release; the plugin slug, text domain, `ab_` prefixes, the `AgentBrain\` namespace and DB tables are unchanged.
* Every artifact now has one version history (wp-admin History box and new artifact-version-list/get/compare/restore abilities): view any version, compare two, and restore an old version as a new one through the normal approval path.
* Anonymous per-day view analytics for share pages: per artifact, per share link and per version, with an Activity box on the artifact screen, a read-only artifact-share-analytics MCP ability, daily-rotated IP hashes for unique viewers (raw IPs never stored), and 90-day automatic pruning.
* Connections now carry their status with them: an out-of-date or expiring connection is told to reconnect in the agent's next briefing, the Connect an AI page shows per-connection status, and refresh-token expiry is a sliding 30-day window.
* An agent can no longer approve an HTML version or publish a share link on its own — a person must approve.
* Agents can never use a capability reserved for people, even if one is granted to them, and administrators are told when one is stored.
* An agent approved from a proposal now gets its agent ID as its login, and no proposal creates an agent without an ID.
* Every agent can be suspended and revoked, and an agent without an agent ID cannot sign in until a person issues one.
* A suspended agent can no longer sign in anywhere, and proposed agents expire after 24 hours.
* AI clients can now register themselves with OAuth Dynamic Client Registration (RFC 7591) alongside CIMD, so apps that require a registration endpoint (ChatGPT developer mode and friends) can sign in; registrations are rate-limited, capped, expiring and revocable, and the Connect an AI page lists the popular MCP apps.
* The MCP tool list now tracks permission and setting changes on reconnect, and initialize tells clients how to refresh it.
* The MCP HTTP transport now applies one documented session rule to every method and logs each request for diagnosis.
* A curator can grant a trusted agent scoped approval rights: review artifact sections and trigger assembly in scopes the agent can write.
* Sharing refuses dead share links: the preview host must provably reach this site before it is saved or used, and Sharing settings show the effective preview host and its last check.
* Empty "Promote dev-uat to main" PRs no longer respawn every CI cycle after a backmerge.
* Artifact tools accept a login or agent id for the lead and owners, and refusals name who may act.
* Forget now reaches the frontmatter an import kept and names in audit metadata.
* Multisite: network activation provisions every site, and new blogs are provisioned automatically; per-site brain documented.
* Security: artifacts and their sections can no longer be created or edited through WordPress's own REST posts API, which skipped the section checks (sanitising, version check, edit lease, approved lock); use the TomaTío tools. Compiling or assembling now refuses an outline that lists another artifact's section.
* The dev-wip -> dev-uat promotion now folds changelog.d fragments into [Unreleased] and carries the roll-up in the squash commit; a CI dispatch job runs it, and the dev-uat -> main auto-merge is held by default behind a release-hold flag.
* Derived-from links: assembled, compiled and proposed items record the artifact and section versions they were built from; imported pages keep their original brain.
* Citations: agents can cite the memory, knowledge and section items they used when writing, over MCP.
* Outside source records: register and reference where an item came from outside the brain, over MCP and in wp-admin.
* Action context and provenance now have a Privacy settings tab: a site switch turns recording off, and a 90-day retention (curators can pin items longer) prunes read sets, action logs and provenance links, with forget erasing the residue.
* Exports are signed with the brain's own signing key; an import or restore verifies the signature and refuses an archive that was altered after export or signed by another brain.
* An export after a restore lists the retention service once in context.json.

= 1.6.2 =
* release: plugin 1.6.1 (#246)

= 1.6.1 =
* no
* no

= 1.6.0 =
* An artifact can now be a plain HTML page with CSS, JavaScript and images, like a small static site. Agents upload the files over MCP, a lead or curator approves a version, and share links show the approved version in an isolated sandbox. The artifact screen previews it.
* Compiling an artifact updates its existing draft post instead of adding a new one each time, and assembling again after a reopen sends the revised text back to the Review Queue.
* Curators and artifact leads can review sections in wp-admin: read the content and review log, then Approve, Request changes with notes, or Reopen an approved section, without an AI client.
* New setting (Settings, Sharing): let agents create artifacts themselves in scopes they can write, instead of asking a curator. Off by default.
* Security: artifacts and their sections can no longer be created or edited through WordPress's own REST posts API, which skipped the section checks (sanitising, version check, edit lease, approved lock); use the Agent Brain tools. Compiling or assembling now refuses an outline that lists another artifact's section.
* Fixed: an artifact always opens on its own screen, never the block editor, and the section block shows its owner, state and lease in the sidebar with plain agent names.
* Approved artifact sections can be reopened by the lead or a curator (a Reopen button on the outline board, or `section-review` with `reopen`), so a section can be edited and reviewed again after approval.
* The outline board on an artifact's edit screen now shows sections in the order you set with reorder, not by their menu order.
* A restore brings back artifacts, sections, compiled documents and handoffs under their own ids, so the restored brain shows them.

= 1.5.0 =
* Section documents look better: status labels, callout boxes and columns that agents can use, and a share page with a contents list, readable tables and print styles.
* Import the pages of a PCP archive from another brain or tool as proposals in the Review Queue (Export and import, or `wp agent-brain import`), with a dry run, scope and people maps, and reruns that skip what was already imported. Every source value is kept and exported again.
* Restore a full PCP export into an empty brain (Export and import, or `wp agent-brain restore`), with a dry run: every check runs before anything is written, and it is all or nothing. People and agents come back unbound until an administrator binds them in the new Principals section (a set-password link for people, a new application password for agents); passwords and tokens never travel.
* Forget can erase a subject, named pages, or everything a former member or agent wrote, with a preview that deletes nothing, and over the management REST routes. Receipts now hold a keyed fingerprint of the subject instead of a plain hash, so the Forget page can confirm a subject was erased while nobody can guess it back; plain hashes from earlier forgets and from the audit log's tool calls are deleted on upgrade. Forget now also reaches pending agent proposals and documents in the trash. Deleted memories are purged after 30 days.
* Exports now include artifacts (with their sections, stages, review history and earlier versions), compiled documents, handoffs and agents waiting for approval.
* A section's edit lease ends when the section is submitted or approved, and approved or submitted sections no longer show a lease.

* Publish, unpublish and archive an artifact from its screen or with new curator-only tools; archived artifacts leave the default list and the lead's views, and unpublishing revokes share links. Compile and assemble can make a page as well as a post.

= 1.4.0 =
* Full exports in the open PCP v0.3 format: Agent Brain, Export and import makes an archive of the whole brain (every memory, knowledge item and procedure with its history, proposals and rejections included, the people, agents and scopes with their access, the house rules, the audit log and the forget receipts) with a manifest of SHA-256 hashes. Archives hold no passwords, tokens or keys, are kept outside uploads/ for 24 hours by default, and are deleted by Forget. Also over the management REST routes (browser login only) and `wp agent-brain export`. Backups write their pages the same way; the old Markdown export button is gone.

= 1.3.2 =
* Patch release 1.3.2: registration instructions, search text field, pinned briefings, OpenAI-compatible endpoint (#119)

= 1.3.1 =
* Agent IDs: every agent gets an FQDN identity ({name}-{id}.agnt.{host}); register an agent with a single-use key; suspend, reactivate or revoke from the Agents page.
* New admin screens: Memories, Scopes, Agent View, Jobs, a dedicated artifact screen with the outline board; grouped menu, plain-language Audit Log, one confirmation pattern for destructive actions, accessibility and responsive fixes.
* Artifact share links on a separate preview host: sandboxed and noindex, optional IP binding, embed links with an origin allowlist and a single-use code exchange, opt-in interactive artifacts.
* Search and briefings: relevance and importance reported separately, synonym matching, hints that name the scopes searched, scope ids in the briefing and agent-whoami, ISO 8601 timestamps.
* People write through their agent; approvals show in briefings; PCP v0.3 discovery document; stable PCP principal ids, page paths and partition names with FQDN lookups.
* Fixes: Forget confirmation dialog, share links behind private-mode, Onboarding template saved as house rules, stale admin assets behind a CDN.

= 1.2.8 =
* Release prep: changelog since 1.0.0; merge cuts plugin 1.2.8 (#38)

= 1.0.0 =
* First stable release. Includes everything from 0.1.0 through 0.4.0 below; no data or schema changes versus 0.4.0.

= 0.4.0 =
* Knowledge, Procedures and Artifacts now live under the single "Agent Brain" admin menu, with proper "Add New Knowledge/Procedure/Artifact" labels instead of the generic "Add Post".
* Curators and administrators can now list and open Artifacts in wp-admin (they previously held `ab_manage_artifacts` but not the underlying `edit_ab_artifacts` capability, so the menu item never appeared). This is granted dynamically per request and does not change any role's stored capability set; per-object access (who may open a specific artifact) is unchanged.

= 0.3.0 =
* Memory rows can now carry which MCP client wrote them (a `client` column, migrated in place); search and recall results expose it. System-written memories (compression, outcome, assembly) leave it blank.

= 0.2.0 =
* A section's lead can now read every section it reviews, not just its own.
* Review notes and section briefs preserve quotes, backslashes, and non-ASCII text exactly.
* Tables support header and footer rows, matching core's own saved markup.
* Briefings keep scanning past an item that doesn't fit and reserve room for approved knowledge and procedures instead of letting one oversized item crowd them out.
* Outcome memories written back to a proposing agent are bounded in length, with a migration that bounds any oversized rows written before this fix.
* The review queue's plain-text diff extraction was consolidated into one implementation.
* The fallback memory-search ranking now uses BM25-style relevance instead of raw term-match counts.
* The `ab_fulltext_min_rows` option is now capped at 500, the LIKE/BM25 fallback's candidate pool size.

= 0.1.0 =
* Initial development skeleton.
