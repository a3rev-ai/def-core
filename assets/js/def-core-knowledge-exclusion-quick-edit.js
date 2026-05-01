/**
 * Quick Edit hydration for the DEF knowledge-exclusion checkbox.
 *
 * WP's default Quick Edit form is empty until JS reads the row's current
 * value and ticks/unticks the checkbox. We piggyback on inlineEditPost.edit
 * (the global jQuery namespace WP uses for Quick Edit) and read the row's
 * indicator span (rendered by render_list_column in the PHP class).
 */
(function () {
	'use strict';

	if (typeof inlineEditPost === 'undefined' || typeof jQuery === 'undefined') {
		return;
	}

	var $ = jQuery;
	var META_KEY = '_def_exclude_from_ingestion';
	var originalEdit = inlineEditPost.edit;

	inlineEditPost.edit = function (id) {
		// Call the original first so the inline-edit row is in the DOM.
		var result = originalEdit.apply(this, arguments);
		var postId = 0;
		if (typeof id === 'object') {
			postId = parseInt($(id).attr('id').replace(/[^0-9]/g, ''), 10);
		} else {
			postId = parseInt(id, 10);
		}
		if (!postId) return result;

		var $row = $('#post-' + postId);
		var $editRow = $('#edit-' + postId);
		if (!$row.length || !$editRow.length) return result;

		// Read the indicator from the list-column cell. data-excluded is "1" if flagged.
		var excluded = $row.find('.def-core-exclusion-flag').data('excluded') === 1
			|| $row.find('.def-core-exclusion-flag').attr('data-excluded') === '1';

		$editRow.find('input[name="' + META_KEY + '"]').prop('checked', excluded);
		return result;
	};
})();
