# Custom blocks

`create-custom-block` turns a data spec into a real block. The spec is data, never code: a dynamic `render_callback` fills the template from the attributes, escaping every value by its declared type. `list-block-control-types` returns the same vocabulary at runtime.

## Spec

```json
{
  "title": "Features",
  "name": "features",
  "category": "widgets",
  "supports": { "color": true, "spacing": { "padding": true }, "align": ["wide", "full"] },
  "panels": [{ "title": "Layout", "help": "How the list is arranged." }, { "title": "Items" }],
  "attributes": [
    { "name": "variant", "type": "select", "label": "Layout", "panel": "Layout",
      "options": [{ "value": "grid", "label": "Grid" }, "list"], "default": "grid" },
    { "name": "items", "type": "repeater", "label": "Features", "panel": "Items", "min": 1, "max": 6,
      "fields": [
        { "name": "title", "type": "string", "label": "Title" },
        { "name": "icon", "type": "media", "label": "Icon" },
        { "name": "featured", "type": "boolean", "label": "Featured" }
      ] },
    { "name": "cta", "type": "link", "label": "Button", "help": "Shown under the list." }
  ],
  "template": "<div class=\"features is-{{variant}}\"><ul>{{#items}}<li>{{icon}}<h3>{{title}}</h3>{{#featured}}<span>Top pick</span>{{/featured}}</li>{{/items}}</ul>{{cta}}</div>"
}
```

## Attribute types

- `string`, `color`, `select`: escaped text. A `select` value outside its options renders the default.
- `richtext`: filtered with `wp_kses_post`.
- `url`, `image`: `esc_url`.
- `number`, `boolean`.
- `media` `{id, url, alt}`: `{{name}}` is an `<img>`; `{{name.url}}`, `{{name.alt}}`, `{{name.id}}` read one key.
- `link` `{text, url, target}`: `{{name}}` is an `<a>` (`target` is `_blank` or empty, with `rel="noopener noreferrer"`); `{{name.url}}`, `{{name.text}}`, `{{name.target}}` read one key.
- `repeater`: a list of items with typed `fields` (any type but `repeater`), `min` and `max`. `{{#name}}...{{/name}}` renders once per item, at most `max` items.

`{{#name}}...{{/name}}` on any other type renders its body once when the value is set. The template is scanned once, so a value that looks like `{{x}}` is printed, not expanded.

Names WordPress owns on a block are refused: `className`, `style`, `align`, `anchor`, `lock`, `metadata`, `backgroundColor`, `textColor`, `gradient`, `fontSize`, `fontFamily`, `borderColor`. Blocks stored before this rule keep working.

## Supports and panels

`supports` turns on the editor's own controls: `color`, `spacing`, `typography`, `border` (each `true` or an object of features), `shadow` (`true`), `align` (`true` or a list of `left`, `center`, `right`, `wide`, `full`). A block with supports renders inside a wrapper `<div>` that carries the classes and inline styles WordPress builds from them; a block without supports renders exactly as before.

In the editor every attribute gets a sidebar control. `panel` puts it in a named panel (`panels` sets their order, help text and whether they start open), `help` adds a line under it, and color controls offer the theme palette. The preview is rendered on the server from the same template.
