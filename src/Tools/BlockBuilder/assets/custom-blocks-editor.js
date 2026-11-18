/**
 * Editor side of the data-driven custom blocks (issue #852). Plain script, no
 * build step. Registers every active wpmcp/* custom block from the payload
 * Block_Registry prints (window.wpmcpCustomBlocks): sidebar controls grouped
 * into named panels, a server-rendered preview, and save() returning null
 * because the block is dynamic. The template never reaches the browser.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var blocks = window.wpmcpCustomBlocks || [];

	// Attributes block supports own; the preview leaves them out so the
	// styles are applied once, by useBlockProps() on the editor wrapper.
	var SUPPORT_KEYS = ['style', 'className', 'align', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'borderColor', 'lock', 'metadata'];

	function themePalette() {
		try {
			if (be.useSettings) {
				return be.useSettings('color.palette')[0] || [];
			}
			if (be.useSetting) {
				return be.useSetting('color.palette') || [];
			}
		} catch (e) {}
		return [];
	}

	function mediaControl(field, value, onChange) {
		value = value && typeof value === 'object' ? value : {};
		var picker = be.MediaUploadCheck && be.MediaUpload
			? el(be.MediaUploadCheck, null, el(be.MediaUpload, {
				allowedTypes: ['image'],
				value: value.id,
				onSelect: function (m) {
					onChange({ id: m.id, url: m.url, alt: m.alt || '' });
				},
				render: function (o) {
					return el(c.Button, { variant: 'secondary', onClick: o.open }, value.url ? 'Replace' : 'Select');
				}
			}))
			: null;
		return el(c.BaseControl, { label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			value.url ? el('img', { src: value.url, alt: '', style: { maxWidth: '100%', display: 'block', marginBottom: '8px' } }) : null,
			picker,
			value.url ? el(c.Button, { variant: 'link', isDestructive: true, onClick: function () { onChange({}); } }, 'Remove') : null,
			el(c.TextControl, {
				label: 'Alt text',
				value: value.alt || '',
				onChange: function (v) { onChange(Object.assign({}, value, { alt: v })); }
			})
		);
	}

	function linkControl(field, value, onChange) {
		value = value && typeof value === 'object' ? value : {};
		function set(key, v) {
			var next = Object.assign({}, value);
			next[key] = v;
			onChange(next);
		}
		return el(c.BaseControl, { label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			el(c.TextControl, { label: 'Text', value: value.text || '', onChange: function (v) { set('text', v); } }),
			el(c.TextControl, { label: 'URL', type: 'url', value: value.url || '', onChange: function (v) { set('url', v); } }),
			el(c.ToggleControl, {
				label: 'Open in a new tab',
				checked: value.target === '_blank',
				onChange: function (v) { set('target', v ? '_blank' : ''); }
			})
		);
	}

	function repeaterControl(field, value, onChange, palette) {
		var items = Array.isArray(value) ? value : [];
		var max = field.max || 0;
		var min = field.min || 0;
		function update(i, item) {
			var next = items.slice();
			next[i] = item;
			onChange(next);
		}
		function move(i, d) {
			var next = items.slice();
			var t = next[i];
			next[i] = next[i + d];
			next[i + d] = t;
			onChange(next);
		}
		return el(c.BaseControl, { label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			items.map(function (item, i) {
				item = item && typeof item === 'object' ? item : {};
				return el('div', { key: i, className: 'wpmcp-cb-item', style: { border: '1px solid #ddd', padding: '8px', marginBottom: '8px' } },
					(field.fields || []).map(function (sub) {
						return control(sub, item[sub.name], function (v) {
							var next = Object.assign({}, item);
							next[sub.name] = v;
							update(i, next);
						}, palette);
					}),
					el('div', { style: { display: 'flex', gap: '4px' } },
						el(c.Button, { size: 'small', variant: 'tertiary', disabled: i === 0, onClick: function () { move(i, -1); } }, 'Up'),
						el(c.Button, { size: 'small', variant: 'tertiary', disabled: i === items.length - 1, onClick: function () { move(i, 1); } }, 'Down'),
						el(c.Button, {
							size: 'small',
							variant: 'tertiary',
							isDestructive: true,
							disabled: items.length <= min,
							onClick: function () { onChange(items.filter(function (_, j) { return j !== i; })); }
						}, 'Remove')
					)
				);
			}),
			el(c.Button, {
				variant: 'secondary',
				disabled: max > 0 && items.length >= max,
				onClick: function () { onChange(items.concat([{}])); }
			}, 'Add item')
		);
	}

	function control(field, value, onChange, palette) {
		var common = { key: field.name, label: field.label, help: field.help || undefined };
		switch (field.type) {
			case 'richtext':
				return el(c.TextareaControl, Object.assign(common, { value: value || '', onChange: onChange }));
			case 'number':
				return el(c.TextControl, Object.assign(common, {
					type: 'number',
					value: value === undefined || value === '' ? '' : value,
					onChange: function (v) { onChange(v === '' ? '' : parseFloat(v)); }
				}));
			case 'boolean':
				return el(c.ToggleControl, Object.assign(common, { checked: !!value, onChange: onChange }));
			case 'color':
				return el(c.BaseControl, Object.assign(common, { __nextHasNoMarginBottom: true }),
					el(c.ColorPalette, { colors: palette, value: value || undefined, onChange: function (v) { onChange(v || ''); } }));
			case 'select':
				return el(c.SelectControl, Object.assign(common, {
					value: value || '',
					options: [{ value: '', label: '-' }].concat(field.options || []),
					onChange: onChange
				}));
			case 'media':
				return el(Fragment, { key: field.name }, mediaControl(field, value, onChange));
			case 'link':
				return el(Fragment, { key: field.name }, linkControl(field, value, onChange));
			case 'repeater':
				return el(Fragment, { key: field.name }, repeaterControl(field, value, onChange, palette));
			default:
				return el(c.TextControl, Object.assign(common, { value: value || '', onChange: onChange }));
		}
	}

	function groupByPanel(def) {
		var order = [];
		var byTitle = {};
		(def.panels || []).forEach(function (p) {
			if (!byTitle[p.title]) {
				byTitle[p.title] = { title: p.title, help: p.help, opened: p.opened !== false, fields: [] };
				order.push(p.title);
			}
		});
		(def.attributes || []).forEach(function (a) {
			var title = a.panel || 'Settings';
			if (!byTitle[title]) {
				byTitle[title] = { title: title, help: '', opened: true, fields: [] };
				order.push(title);
			}
			byTitle[title].fields.push(a);
		});
		return order.map(function (t) { return byTitle[t]; }).filter(function (p) { return p.fields.length; });
	}

	blocks.forEach(function (def) {
		if (!def || !def.name || (wp.blocks.getBlockType && wp.blocks.getBlockType(def.name))) {
			return;
		}
		var panels = groupByPanel(def);
		wp.blocks.registerBlockType(def.name, {
			title: def.title,
			category: def.category || 'widgets',
			icon: 'screenoptions',
			edit: function (props) {
				var palette = themePalette();
				var blockProps = be.useBlockProps();
				var preview = {};
				Object.keys(props.attributes).forEach(function (k) {
					if (SUPPORT_KEYS.indexOf(k) === -1) {
						preview[k] = props.attributes[k];
					}
				});
				return el(Fragment, null,
					el(be.InspectorControls, null, panels.map(function (panel) {
						return el(c.PanelBody, { key: panel.title, title: panel.title, initialOpen: panel.opened },
							panel.help ? el('p', { className: 'components-base-control__help' }, panel.help) : null,
							panel.fields.map(function (field) {
								return control(field, props.attributes[field.name], function (v) {
									var next = {};
									next[field.name] = v;
									props.setAttributes(next);
								}, palette);
							})
						);
					})),
					el('div', blockProps, ServerSideRender
						? el(ServerSideRender, { block: def.name, attributes: preview, httpMethod: 'POST' })
						: el('p', null, def.title))
				);
			},
			save: function () {
				return null;
			}
		});
	});
})(window.wp);
