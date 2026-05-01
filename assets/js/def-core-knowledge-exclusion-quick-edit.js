/**
 * Quick Edit hydration: wp's inline-edit form is empty until JS reads the
 * row's current state. Read data-excluded from the list-column indicator.
 */
(function () {
	'use strict';
	if (typeof inlineEditPost === 'undefined' || typeof jQuery === 'undefined') return;
	var $ = jQuery;
	var orig = inlineEditPost.edit;
	inlineEditPost.edit = function (id) {
		var ret = orig.apply(this, arguments);
		var pid = parseInt(typeof id === 'object' ? $(id).attr('id').replace(/[^0-9]/g, '') : id, 10);
		if (!pid) return ret;
		var on = $('#post-' + pid).find('.def-core-exclusion-flag').attr('data-excluded') === '1';
		$('#edit-' + pid).find('input[name="_def_exclude_from_ingestion"]').prop('checked', on);
		return ret;
	};
})();
