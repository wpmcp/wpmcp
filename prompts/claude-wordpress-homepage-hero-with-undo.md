# Claude WordPress prompt: rebuild a homepage hero with undo

Use this when you want a fresh first screen on your homepage but want to see it live before you commit. Works in Claude, Claude Code, ChatGPT or Cursor connected to WP MCP. The homepage must be built with the block editor (Gutenberg) on the free plugin; on a page builder site, Pro adds the builder tools and the same undo.

```text
You are editing my WordPress site through the WP MCP server. Use one session_id for every write in this task, for example "hero-refresh", and tell me what it is.

1. Find the page set as the homepage and read it with get-page-snapshot. Tell me in two lines what the first section (the hero) says now: heading, subheading, button text and link.
2. Propose a new hero for this audience: <describe your audience and offer>. Keep the existing structure (heading, short paragraph, one button). Show me the new text before writing anything.
3. When I say "go", change only the hero blocks. Do not touch any other section. Keep the button link unless I gave you a new one.
4. Read the page back and confirm the block markup still parses. Give me the page URL so I can look at it.
5. Tell me the operation_id of the change and how to undo it: rollback-operation with that id, or the Restore button on wpmcp > History in wp-admin.

If I reply "undo", call rollback-operation (or rollback-session with the session_id) and read the page back to prove the old hero is back.
```

What to expect: one or two snapshotted writes, listed on the History screen with a Restore button each. Undoing puts back the exact block markup the page had before.
