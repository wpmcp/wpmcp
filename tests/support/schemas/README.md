# Vendored JSON Schemas

Test fixtures used to validate the discovery documents the plugin serves
(see `tests/free/MCP/DiscoveryDocumentsTest.php`).

- `mcp-server-card.v1.schema.json`: the MCP Server Card extension's published
  `schema.json`, from `modelcontextprotocol/experimental-ext-server-card` at
  `526201bb`. This copy is lightly normalized: em-dashes in four `description`
  strings were replaced with plain hyphens to follow the repository's text
  rules. Nothing else changed; descriptions are annotations only, so
  validation behavior is identical to upstream.
- `agent-skills-discovery-0.2.0.schema.json`: the Agent Skills discovery
  index schema, version 0.2.0.
