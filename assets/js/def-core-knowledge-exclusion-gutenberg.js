/**
 * Gutenberg sidebar panel: Digital Employees → Exclude from knowledge.
 *
 * Stand-alone panel (like Discussion / Excerpt) registered via
 * PluginDocumentSettingPanel. Single checkbox bound to the
 * `_def_exclude_from_ingestion` post meta the PHP class registers with
 * `show_in_rest: true`.
 *
 * Vanilla WP element / no JSX build step. Uses wp.element.createElement so
 * no transpilation is required — keeps def-core's zero-build-pipeline
 * convention.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.editor || !wp.components || !wp.data || !wp.element) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = (wp.editPost && wp.editPost.PluginDocumentSettingPanel)
		|| wp.editor.PluginDocumentSettingPanel;
	var CheckboxControl = wp.components.CheckboxControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

	if (!registerPlugin || !PluginDocumentSettingPanel) {
		// Older WP without the sidebar APIs — silently no-op rather than crash the editor.
		return;
	}

	var META_KEY = '_def_exclude_from_ingestion';

	function ExclusionPanel() {
		var data = useSelect(function (select) {
			var core = select('core/editor');
			return {
				meta: core.getEditedPostAttribute('meta') || {},
				postType: core.getCurrentPostType(),
			};
		}, []);

		var editPost = useDispatch('core/editor').editPost;

		// Defensive: if the meta key isn't exposed for this post type (e.g. a
		// CPT not registered as ingestible), don't render the panel at all.
		if (!data.meta || typeof data.meta[META_KEY] === 'undefined') {
			return null;
		}

		var checked = !!data.meta[META_KEY];

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'def-core-knowledge-exclusion',
				title: __('Digital Employees', 'digital-employees'),
				className: 'def-core-knowledge-exclusion-panel',
			},
			createElement(CheckboxControl, {
				label: __('Exclude from Digital Employee knowledge', 'digital-employees'),
				help: __(
					'When checked, this item is skipped during knowledge ingestion. If it was previously indexed, it will be removed from the search index on the next sync (Tenant Portal → Knowledge → Sync Now — Full).',
					'digital-employees'
				),
				checked: checked,
				onChange: function (next) {
					var update = {};
					update[META_KEY] = !!next;
					editPost({ meta: update });
				},
			})
		);
	}

	registerPlugin('def-core-knowledge-exclusion', {
		render: ExclusionPanel,
		icon: null,
	});
})(window.wp);
