# Elementor atomic (v4) elements

Reference for the Elementor 4.0+ atomic element tools: what they accept, when
they exist, and what they refuse. This describes shipped behaviour, not a plan.

## Which tools exist, and when

Four write tools are conditional:

- `add-flexbox`
- `add-div-block`
- `add-atomic-widget`
- `update-atomic-widget`

They register only when the active builder can render atomic elements. That is
true for Elementor 4.0+, and also for a 3.3x install that has the Editor-V4
experiment on, because such a site actually loads the atomic-widgets module.
The check is `Atomic_Element::is_supported()`: the atomic-widgets class must
exist, and either the version is 4.0+ or Elementor reports the module loaded
this request.

Registration and the per-call guard read the same predicate, so a registered
tool is never one that refuses every call. Every handler re-checks it anyway,
because a site can activate or downgrade Elementor between the init hook that
registered the tool and the call that runs it.

`detect-elementor-version` is always registered and reports:

- `supports_atomic` - can this builder render atomic elements
- `atomic_tools_registered` - are the four write tools on this site's tool list

`atomic_tools_registered` is read back off the Registrar during the registration
pass, so it accounts for everything that can drop an ability (the pro gate,
governance), not only the builder predicate. Call it before assuming the atomic
tools exist.

There is no filter override. The builder is the only authority on whether these
tools may run; a forced-open gate could only produce tools that write elements
Elementor cannot render. Tests use `Atomic_Element::set_supported_for_tests()`,
a seam in the same spirit as `Gate::set_pro_for_tests()`.

## The `style` param

`add-flexbox`, `add-div-block`, `add-atomic-widget` and `update-atomic-widget`
all take an optional flat `style` object. It becomes one local v4 style class on
the element: a `styles` entry plus a `classes` settings ref pointing at it.

The dialect is the same one `create-global-class` speaks
(`Global_Class_Schema`), so a class authored through either path is built and
validated identically:

- colors (`color`, `border_color`, `background_color`) go through
  `sanitize_hex_color()`
- lengths (`width`, `gap`, `font_size`, `padding`, ...) accept a number (`18`),
  a CSS length string (`"1.5rem"`) or `{"size":18,"unit":"rem"}`
- `padding` / `margin` also accept a per-side object keyed by `top`, `right`,
  `bottom`, `left` or the logical `block-start`, `block-end`, `inline-start`,
  `inline-end`
- `props` is a raw escape hatch for anything the friendly keys do not cover:
  `{"props":{"width":{"$$type":"size","value":{"size":100,"unit":"%"}}}}`

Failure semantics are the same too, and they fail closed:

- an unknown key is an error, never a silent drop
- a value that is not a length is refused by name
- a unit outside the allowlist is refused, so caller text cannot reach the CSS
  Elementor generates
- a `padding` object mixing the `{size}` shorthand with side names is refused
  rather than having a side discarded
- the finished class is run through Elementor's own `Style_Parser`, and a prop
  the schema would silently drop fails the call; if that parser is unavailable
  the build fails with `schema_unavailable` rather than writing unvalidated

A `<key>_unit` companion (`{"font_size":18,"font_size_unit":"rem"}`) is
collected before any length is built, so it wins regardless of where it sits in
the object. JSON key order never changes the result.

## Ownership of the generated class

The class this builder generates carries the label `wpmcp-local`
(`Atomic_Styles::OWNED_LABEL`). A repeat style write replaces the class with
that label and nothing else.

Ownership has to be recorded rather than inferred, because the v4 editor names a
human-authored local class `e-<element-id>-<hash>`, exactly the shape this
builder mints. Deleting by id prefix would wipe styling a person wrote in the
editor, along with all of its breakpoint and state variants. Every other entry
in `styles`, and every other id in the `classes` ref, survives a tool write.

## What the tools refuse

`update-atomic-widget` refuses an element that is not atomic (an atomic
container, or a widget whose `widgetType` starts with `e-`) with
`not_an_atomic_element`, naming the element type. Typed atomic props and a
`styles` blob mean nothing to the classic v3 renderer, so writing them would
report success for styling that never appears. Use `update-widget` or
`update-container` for classic elements.

`build-page` is a composer, not an atomic write tool: it still writes an atomic
node on a builder that cannot render one (its classic siblings in the same tree
must land), but it warns, naming the widget type.

## Verification and rollback

Atomic writes go through `Safe_Mutation`: snapshot first, write, then verify the
stored tree is the intended tree, and roll back with `mutation_failed` if not.

The atomic path writes `_elementor_data` raw, so its verification compares the
`styles` blob too, and compares the intended tree after the same JSON round trip
the meta performs (`wp_json_encode` writes a whole float `32.0` as `32`, which
decodes as an int).

The classic tools persist through `Document::save()`, which drops atomic data
when the Editor-V4 experiment is off, so `Element_Tree::normalize()` leaves
`styles` out of the comparison by default. Only `Atomic_Element::write()` opts
in. Otherwise a classic edit to a page containing an atomic element would roll
the whole page back.

That opt-in is pinned by
`tests/pro/Elementor/AtomicElementsTest::test_a_classic_widget_update_survives_an_atomic_sibling_with_styles`,
which runs update-widget on a page holding an atomic element with a non-empty
`styles` blob: with the comparison made unconditional the classic write fails
verification and the page is rolled back, so the guard has teeth.
