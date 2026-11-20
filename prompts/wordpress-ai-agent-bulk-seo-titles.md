# WordPress AI agent prompt: bulk-fix SEO titles, then review in History

Use this to clean up missing, duplicate or too-long SEO titles across your posts. It needs Yoast SEO or Rank Math active (the two SEO plugins the SEO tools read and write); the agent writes through that plugin's own fields, so nothing changes in how your theme prints titles.

```text
You are working on my WordPress site through the WP MCP server. Use one session_id for every write, for example "seo-titles-<today's date>".

1. List my published posts (list-posts, up to 50) and read each one's SEO title and meta description with get-seo-meta.
2. Make a table of the posts whose SEO title is missing, duplicated, or longer than 60 characters. Show me the current title and your proposed one side by side. Do not change anything yet.
3. When I approve the table (or the rows I pick), update each SEO title with update-seo-meta, using the session_id. Leave descriptions, focus keywords, canonical URLs and robots settings alone.
4. When you are done, call list-operations (raise limit to cover the run), keep the rows from this session, and give me a short list: post, old title, new title, operation_id.
5. Tell me how to undo: one post with rollback-operation, the whole run with rollback-session and the session_id, or per row from wpmcp > History in wp-admin.
```

What to expect: one snapshot per updated post, all under the same session, so you can review the run in History and roll back any single post or the whole batch.
