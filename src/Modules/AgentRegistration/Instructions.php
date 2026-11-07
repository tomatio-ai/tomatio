<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

/**
 * The registration instructions block a person pastes to an agent
 * (docs/rfc/agent-id-fqdn.md section 6.4). Pure text, no WordPress calls.
 */
final class Instructions {

	/**
	 * $maxUses above 1 is a key for several bots (#807): the same text goes
	 * into every bot, and each registers under a name of its own.
	 */
	public static function build( string $siteTitle, string $mcpUrl, string $key, string $expires, int $maxUses = 1 ): string {
		$shared = $maxUses > 1;

		return 'Register yourself on the "' . $siteTitle . '" TomaTío.' . "\n"
			. ( $shared ? 'This key is shared: the same instructions go to several bots, and each one becomes its own agent.' . "\n" : '' )
			. "\n"
			. '1. Add this MCP server to your client. It is a streamable HTTP server:' . "\n"
			. '   URL:    ' . $mcpUrl . "\n"
			. '   Header: Authorization: Bearer ' . $key . "\n"
			. '   Claude Code:' . "\n"
			. '   claude mcp add --transport http brain ' . $mcpUrl . ' --header "Authorization: Bearer ' . $key . '"' . "\n"
			. '   Other clients (Kimi, Cursor and similar) take the same URL and header in their MCP config:' . "\n"
			. '   {"mcpServers": {"brain": {"url": "' . $mcpUrl . '", "headers": {"Authorization": "Bearer ' . $key . '"}}}}' . "\n"
			. '   A newly added server may not load until the client restarts. If it is missing, restart' . "\n"
			. '   the client; until then you can call the same URL over plain HTTP with that header.' . "\n"
			. '2. Call the tomatio-agent-register tool (some clients show a server prefix before it).' . "\n"
			. '   The older agent-brain- spelling of every tool (agent-brain-agent-register and so on)' . "\n"
			. '   still works, but is no longer listed.' . "\n"
			. ( $shared
				? '   Every bot needs its own agent_name: use your bot\'s own name (for example grok-sales or' . "\n"
					. '   grok-support), never one another bot took. If the name is taken, choose another one;' . "\n"
					. '   that does not use up the key.' . "\n"
				: '   Choose a short lowercase agent_name that describes your job (for example code-reviewer).' . "\n" )
			. '   It needs 3 to 32 characters, a-z, 0-9 and "-".' . "\n"
			. '3. The response holds a secret: the authorization_header field and the credential.' . "\n"
			. '   NEVER print, echo, quote or log them, including their base64 form, and keep them out of' . "\n"
			. '   chat, tool output and shell history. Store authorization_header.value straight into your' . "\n"
			. '   client secret store, or a file only you can read, then reconnect with the connect_from_file' . "\n"
			. '   command so the secret is read from that file.' . "\n"
			. ( $shared
				? '   After you register, connect only with your own credential: the key is for the other bots.' . "\n"
				: '   This key stops working after you register.' . "\n" )
			. '4. Call tomatio-agent-whoami to confirm who you are, then tomatio-context-briefing to start.' . "\n"
			. "\n"
			. ( $shared
				? 'This key expires ' . $expires . ' and can register up to ' . $maxUses . ' agents, one per bot.' . "\n"
				: 'This key expires ' . $expires . ' and can be used once.' . "\n" );
	}
}
