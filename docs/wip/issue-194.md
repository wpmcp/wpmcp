# WIP plan: bridge third-party abilities under the governed endpoint (issue #194)

Field-audit parity item: expose abilities registered by OTHER plugins through
our single governed endpoint, so a site running Yoast, FluentCart,
Ameliabooking or BetterLinks does not need a second MCP plugin
(enable-abilities-for-mcp, abilities-bridge, acrossai-mcp-manager, ...).
Unlike those pass-throughs, our bridge must never widen access.

## Implemented in this slice

- `src/Tools/Bridge/Bridge_Guard.php`: default-off site opt-in
  (`WPMCP_ENABLE_ABILITY_BRIDGE` constant or `wpmcp_enable_ability_bridge`
  filter, same pattern as `Wp_Cli_Guard`), foreign-name check (anything not
  in the `wpmcp/` namespace), and owner attribution derived from the ability
  namespace.
- `src/Tools/Bridge/List_Site_Abilities.php`: `wpmcp/list-site-abilities`,
  every non-wpmcp ability with name, clamped summary, owning plugin,
  input-schema availability and `reversible: false`.
- `src/Tools/Bridge/Get_Site_Ability.php`: `wpmcp/get-site-ability`, one
  foreign ability's full description, input/output schema and meta.
- `src/Tools/Bridge/Execute_Site_Ability.php`: `wpmcp/execute-site-ability`,
  invokes strictly through `WP_Ability::execute()` so the target's own
  permission callback and input validation always run (no bypass path,
  filter or setting exists). Refuses wpmcp's own abilities, which also keeps
  the meta-tools and the bridge tools themselves unreachable. Success
  payloads are wrapped with `reversible: false`.
- Registration in `src/Plugin.php` (`register_bridge_abilities`, group
  `bridge`): the three shells are ordinary registered abilities, so
  governance AND-of-narrowing, identity scoping and the rate limiter apply
  to every bridged call on top of the target's own gate.
  `execute-site-ability` carries explicit destructive annotations like
  `call-tool`.

## Security model (matches the issue's requirements)

- Target permission callback always runs; a denied ability stays denied.
- Whole surface is default OFF, opt-in per site.
- Bridged abilities are never injected into tools/list; discovery is
  `list-site-abilities`, execution is `execute-site-ability` (compact-mode
  pattern).
- No snapshot promise for foreign abilities: everything bridged is marked
  `reversible: false`.

## Remaining work

- Governance audit-log entries for every bridged call (including denials),
  attributed to the owning plugin; needs the audit-log write path factored
  for non-wpmcp ability names.
- Per-ability governance toggles for bridged names (enable/disable one
  foreign ability per identity/role/environment), plus admin grid surface.
- Confirm the `bridge` group is included in the intended FLAVOR_GROUPS
  allowlists and the ability-manifest drift guard fixture.
- Tests: denied foreign ability stays denied, governance can disable the
  bridge shells, own abilities and unknown names refused, gate-closed error,
  reversible:false marker present, owner attribution.
- Docs: readme + COMPLIANCE notes for the new opt-in.
