# WordPress MCP prompts for Claude, ChatGPT and Cursor

Paste-once blueprints for the WP MCP server. Each one does a real job on your site, keeps every change in one session, and ends with a step that shows you how to undo it. Copy the text inside the fenced block into your AI client after it is connected (see [Connect your AI client](../README.md#connect-your-ai-client-claude-code-claude-desktop-chatgpt-cursor)).

| Prompt | What it does |
| --- | --- |
| [Rebuild a homepage hero with undo](claude-wordpress-homepage-hero-with-undo.md) | Rewrites the first section of your homepage, shows you the result, rolls it back if you say so |
| [Bulk-fix SEO titles, then review in History](wordpress-ai-agent-bulk-seo-titles.md) | Finds weak SEO titles, fixes them in one session, lists what changed for review |
| [WooCommerce price update with rollback](woocommerce-mcp-price-update-with-rollback.md) | Changes prices on a set of products as one batch that a single call can undo |
| [Gutenberg landing page, then undo it](gutenberg-landing-page-wordpress-mcp.md) | Builds a draft landing page from core blocks and patterns, then shows the undo path |

Try them on a staging copy first if you can. Undo covers the writes WP MCP snapshots; the [list of what cannot be undone](../docs/reference/safety-model.md#what-cannot-be-undone) is short, and none of these prompts touch it.
