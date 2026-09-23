/**
 * The "Chat Button" editor block (8.7.0). Vanilla wp.element.createElement — no
 * JSX, no build step. Title, category, icon and attributes come from the server
 * registration (DEF_Core_Chat_Button_Block); the block is server-rendered, so
 * save() returns null and the front-end markup, with the loader's trigger
 * attributes, comes from DEF_Core_Chat_Button_Block::render().
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !wp.blockEditor || !wp.components) return;

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var defaults = window.DefCoreChatButtonBlock || {};

	wp.blocks.registerBlockType('def-core/chat-button', {
		edit: function (props) {
			var attrs = props.attributes;
			var set = props.setAttributes;
			var blockProps = wp.blockEditor.useBlockProps({ className: 'wp-block-button' });

			return el(wp.element.Fragment, null,
				el(wp.blockEditor.InspectorControls, null,
					el(wp.components.PanelBody, { title: __('Chat Button', 'digital-employees'), initialOpen: true },
						el(wp.components.TextControl, {
							label: __('Label', 'digital-employees'),
							value: attrs.label,
							placeholder: defaults.label || '',
							onChange: function (v) { set({ label: v }); },
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						}),
						el(wp.components.TextareaControl, {
							label: __('Question to ask', 'digital-employees'),
							help: __("Sent as the visitor's first message when the button is clicked. Leave blank to open the chat with nothing sent.", 'digital-employees'),
							value: attrs.prompt,
							rows: 3,
							onChange: function (v) { set({ prompt: v }); },
							__nextHasNoMarginBottom: true,
						})
					)
				),
				el('div', blockProps,
					el('span', { className: 'wp-block-button__link wp-element-button' },
						attrs.label || defaults.label || __('Chat', 'digital-employees'))
				)
			);
		},
		save: function () { return null; },
	});
})(window.wp);
