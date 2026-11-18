# MCP client behaviour

How the MCP clients people connect to WP MCP react when the set of tools a site offers changes, for example after an administrator activates WooCommerce or adds a WP MCP Pro license (issue #632).

## What WP MCP sends

- **stdio** (`wp mcp-stdio serve --user=<login>`): the `initialize` result advertises `tools.listChanged: true`. When the available tool set changes during the session (a plugin activated or deactivated, a WP MCP Pro license added or lapsed, a governance toggle, the compact-mode switch), the server sends one `notifications/tools/list_changed`. It checks before and after each message, so a change made in wp-admin is reported when the client next talks to the server.
- **HTTP**: every request is stateless, so `listChanged` stays `false` and no notification is sent. A client picks up a change when it lists tools again, which for most clients means reconnecting.

A stdio session registers its tools once, when it starts. A plugin activated somewhere else is loaded by the next process, not by the running one, so after activating a plugin or a license the reliable step is to reconnect the MCP client, which starts a fresh `wp mcp-stdio serve`. The refusal for a tool whose plugin is not active says so: "Install and activate ... then reconnect the MCP client so it reloads its tool list."

## Client matrix

Checked 2026-11-18. Rows marked unconfirmed have no statement in the client's own documentation; do not rely on them without testing.

| Client | Acts on `tools/list_changed` | After a change | Tool count limit |
| --- | --- | --- | --- |
| Claude Code | Yes: re-lists the server's tools when the notification arrives (stdio). | Nothing to do on stdio for changes the running process can see; reconnect (`/mcp`) after activating a plugin or license. | None documented. |
| Claude Desktop | Reported not to: the tool list is read at connection. Unconfirmed in Anthropic's documentation. | Fully quit and reopen Claude Desktop, or reconnect the connector. | None documented. |
| Cursor | Unconfirmed. Cursor's MCP documentation (https://cursor.com/docs/context/mcp) lists tools as supported and says nothing about `list_changed`; forum reports differ by version. | Toggle the server off and on in Cursor's MCP settings, or restart Cursor. | Unconfirmed. Cursor's documentation states no limit; forum reports describe only the first 40 tools being sent to the agent. Compact mode (five tools) stays well under any such cap. |

When a client in this table changes its behaviour, update the row and the date above.
