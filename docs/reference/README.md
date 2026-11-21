# WordPress MCP plugin developer docs (WP MCP reference)

The full reference that used to live in the repository README. The user documentation, with client setup guides, is at [wpmcp-pro.com/docs](https://wpmcp-pro.com/docs).

| Page | What is in it |
| --- | --- |
| [Overview](overview.md) | What WP MCP is, test status, ability counts, why it is built around undo |
| [Safety model](safety-model.md) | Snapshot, apply, verify, rollback; the Audit Log; [what cannot be undone](safety-model.md#what-cannot-be-undone) |
| [Page builders](page-builders.md) | Gutenberg, Elementor and the other builders, free and Pro, plus theme add-ons |
| [Installation](installation.md) | Requirements, the release zip, WP-CLI, other release zips, installing from source |
| [Connecting clients](connecting-clients.md) | Application passwords, Claude Code, Claude Desktop, OAuth and the custom connector, ChatGPT, protocol revisions and the MCP Adapter |
| [Tools](tools.md) | The core tools table, permission and snapshot rules, core operations on the theme pair, forms, WooCommerce domains, personal data masking |
| [Governance](governance.md) | Refusal codes, compact tool surface, new tools after updates, IP pinning, code execution on multisite |
| [Memory and skills](memory-and-skills.md) | Agent project memory, agent skills, MCP prompts and resources |
| [Free vs Pro](free-vs-pro.md) | What each tier has, snapshot history depth, the licensing SDK and the wordpress.org build |
| [Roadmap and limitations](roadmap-and-limitations.md) | What is planned and the known limitations |
| [Development](development.md) | The local test gate and how to run it |

Other pages in [docs/](..): [mcp-clients.md](../mcp-clients.md), [connect-chatgpt.md](../connect-chatgpt.md), [release-checklist.md](../release-checklist.md).
