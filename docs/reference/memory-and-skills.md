# WordPress AI agent project memory, skills, prompts and resources

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Agent project memory (enforced, not advisory)

A site accumulates rules an agent should know: "the header is a reusable block", "never touch the homepage". wpmcp stores those as `wpmcp_memory` entries and puts a **trust gate** in front of them: everything an agent proposes lands `pending` and is completely inert. It is not injected into any future session, it is not enforced, and `memory-recall` will not read it back. An administrator publishing the entry in wp-admin is the single act that makes it live, and nothing on the agent-facing path can publish (`memory-propose` hard-codes the status and takes no parameter to override it).

Published entries reach the agent through the existing MCP `initialize` instructions channel, so there is no separate discovery seam.

The part that matters: an entry with `severity: block` is **enforced by the server, not suggested to the model**. Enforcement lives in `Registrar::is_permitted()`, the single gate every wpmcp ability passes through before it runs, so a rule denies uniformly across the whole surface, including tools written after the rule was created, with no per-tool opt-in and nothing for a future write path to remember to call. A rule names one or more targets:

| Target | Matches |
| --- | --- |
| `tool:<ability>` | that ability by exact name (the `wpmcp/` prefix is optional), or by prefix with a single trailing `*` |
| `post_id:<id>` | a call whose arguments name that post |
| `post_type:<slug>` | a call that declares that post type, or that names the id of a post of that type |

Every memory denial is written to the governance audit log with the blocking entry's id (`memory-block:<id>`), so "why was this refused" is answerable after the fact. Read-only abilities are never blocked: a guardrail restricts what an agent may change, and denying reads would break recall and diagnostics while protecting nothing. An untargeted `block` entry is rejected at validation time, because it would deny every write on the site.

Honest limitation, stated rather than hidden: `post_id` and `post_type` targets can only match arguments the server can see, so a tool that addresses content some other way (a slug-only API, a bulk operation with no id list) is not caught by those two forms. `tool:` targets have no such gap and are the airtight form.

Session summaries are a deterministic rollup of the session's snapshot rows, not an LLM summarization pass. wpmcp writes a before-image ahead of every mutation, so the rows are a complete server-side record of what actually changed; the digest is computed from them, which makes it reproducible, offline, free, and impossible for a summary to over- or under-claim.

The three tools are Pro tier and off by default (`wpmcp_enable_memory`, default `false`). Enforcement is deliberately **not** gated on either: it is on by default (`wpmcp_memory_enforce`) and keeps working after the tools are switched off or a license lapses, because a guardrail that quietly stops guarding is worse than no guardrail. Pending proposals surface as a count bubble on the wpmcp admin menu.

## Agent skills

A safety-first tool surface only pays off if the agent uses it correctly, so the plugin ships the guidance itself. `list-skills` and `get-skill` serve versioned markdown playbooks (a `SKILL.md` per skill, with a validated frontmatter header) covering snapshots and rollback, governance and scoped identities, surgical Elementor editing, WooCommerce catalog and order work, and safe database work.

Discovery is deliberately cheap: `list-skills` returns slug, name, a one-line description, version and tags for the whole library in well under 4 KB, and only `get-skill` pays for a body.

- **Validated, not best-effort.** A `SKILL.md` missing a name, description, or `version`, or over the 64 KB cap, is not served at all: it is refused and named, with its error codes, on the **wpmcp -> Skills** admin screen. Because oversized documents are refused at discovery time, `get-skill` can promise the body exactly as written, never a truncation.
- **Site-aware.** A skill may declare `requires` (ability names). Skills whose tools are not registered on this install (a vertical build, a governance-disabled domain, an absent plugin) are hidden from the default listing, so an agent is never handed a playbook it cannot execute. `include_unavailable: true` shows them with the missing ability names attached.
- **Extensible.** Drop skills in `wp-content/wpmcp-skills/<slug>/SKILL.md`, or register any directory through the `wpmcp_skill_sources` filter. A later source overrides a bundled slug, so a site can replace a shipped playbook with its own house style.
- **No path ever comes from input.** `get-skill` resolves a slug against the index built by scanning disk; a caller-supplied string is never concatenated into a filesystem path, and every discovered file must `realpath()` inside its declared source root.
- **Tiering is per skill, not per surface.** Both tools and the starter library are free. A skill whose frontmatter says `tier: pro` stays listed but its body is withheld behind `Pro\Gate` until the site is licensed.
- **Removable.** The checkbox on the Skills screen (option `wpmcp_skills_enabled`, or the filter of the same name) unregisters both abilities entirely, so a site that does not want them pays zero tokens for them in `tools/list`.

### Prompts and resources

The same content is also served through the MCP prompts and resources primitives, so clients that surface those natively (slash-command prompt pickers, attachable context) can use it without a tool call. The server advertises both capabilities on `initialize`, on the HTTP and the stdio transport alike.

- **Prompts.** `prompts/list` offers every skill whose body this site can serve (available and not withheld), named by slug. `prompts/get` returns the skill body exactly as written, as a single user message.
- **Resources.** `resources/list` offers `wpmcp://site/context` (the `get-site-context` payload) and `wpmcp://skills` (the `list-skills` catalog), both `application/json`. `resources/read` returns the same payload the tool would.
- **Governed like the tools behind them.** Each primitive is backed by the live ability that serves the same data (`get-skill`, `get-site-context`, `list-skills`): the capability check, governance toggles, scoped identities, project-memory rules, audit log, rate limit and request log are that ability's own. When the backing ability is not registered (governance-disabled, skills switched off, exposure switch off) the primitive is absent. Every backing ability is a read, so nothing here writes.
