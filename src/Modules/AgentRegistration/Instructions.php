<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

/**
 * The registration instructions block a person pastes to an agent
 * (docs/rfc/agent-id-fqdn.md section 6.4). Pure text, no WordPress calls.
 */
final class Instructions {

	public static function build( string $siteTitle, string $mcpUrl, string $key, string $expires ): string {
		return 'Register yourself on the "' . $siteTitle . '" TomaTío.' . "\n"
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
			. '   Choose a short lowercase agent_name that describes your job (for example code-reviewer).' . "\n"
			. '   It needs 3 to 32 characters, a-z, 0-9 and "-".' . "\n"
			. '3. The response holds a secret: the authorization_header field and the credential.' . "\n"
			. '   NEVER print, echo, quote or log them, including their base64 form, and keep them out of' . "\n"
			. '   chat, tool output and shell history. Store authorization_header.value straight into your' . "\n"
			. '   client secret store, or a file only you can read, then reconnect with the connect_from_file' . "\n"
			. '   command so the secret is read from that file.' . "\n"
			. '   This key stops working after you register.' . "\n"
			. '4. Call tomatio-agent-whoami to confirm who you are, then tomatio-context-briefing to start.' . "\n"
			. "\n"
			. 'This key expires ' . $expires . ' and can be used once.' . "\n";
	}
}
