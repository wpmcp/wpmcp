# WordPress MCP prompt: build a Gutenberg landing page, then undo it

Use this to get a first draft of a landing page built from core blocks and your theme's patterns, without touching any live page. Everything here is in the free plugin.

```text
You are building a page on my WordPress site through the WP MCP server. Use one session_id for every write, for example "landing-<topic>".

1. Ask me for: the offer, the audience, the main call to action and its link. Wait for my answers.
2. Create a new page as a DRAFT (create-post, post_type page, status draft) titled after the offer.
3. Build it from Gutenberg blocks: a hero (heading, short paragraph, button), three short benefit columns, one testimonial or proof line, a FAQ with three questions, and a closing call to action. If my theme offers block patterns that fit (list them first), use insert-pattern for those sections; otherwise add the blocks yourself.
4. Read the page back with get-page-snapshot, confirm the block markup parses, and give me the preview link.
5. List what this session did (list-operations, keeping the rows with this session_id) and tell me how to remove it all: rollback-session moves the created draft to the trash and undoes every edit in the session.

Do not publish the page and do not change any other page or menu.
```

What to expect: a draft page you can open in the block editor and keep editing by hand. If you do not want it, one rollback-session call undoes the session, and the draft goes to the trash, where you can still restore it.
