/**
 * Digital Employee Framework — Agent Persona Helper
 *
 * Shared across def-core-customer-chat.js, staff-ai.js, and
 * setup-assistant-drawer.js. Renders a "X is helping" divider in the
 * message stream when an Orchestrator V2 specialist takes over from the
 * Concierge, and prefixes the thinking-status row with the current
 * speaker's name. Spec V1.4 §6.
 *
 * Per-channel concerns the caller must supply:
 *   - specialistLabels   — agent_id → display name map
 *   - dividerCssClass    — CSS class for the divider element (per-channel
 *                          because Customer Chat lives in a Shadow DOM,
 *                          so global CSS sharing isn't an option)
 *   - thinkingLabelSelector — selector for the label inside the thinking
 *                             status row (varies: .cc-tool-label,
 *                             .tool-label, .def-sa-tool-label)
 *   - appendDivider(div) — channel-specific append-and-scroll
 *
 * No DOM globals are consulted — controllers are independent and safe to
 * instantiate in Shadow DOM.
 */
(function (win) {
	'use strict';

	// Platform-wide default display names for known specialists. Channels
	// merge channel-specific overrides on top of this. Adding a new specialist
	// to a Concierge's roster (DEF-side) does NOT require a def-core change —
	// the agent_id either matches a default here, a channel override below,
	// or falls through to a snake-case → Title Case humaniser in
	// specialistDisplayName().
	var DEFAULT_LABELS = {
		'sales_assistant':                'Sales Assistant',
		'support_assistant':              'Support Assistant',
		'staff_knowledge_assistant':      'Knowledge Assistant',
		'management_knowledge_assistant': 'Management Knowledge',
		'ap_clerk_assistant':             'AP Clerk',
		'setup_assistant':                'Setup Assistant',
	};

	function mergeLabels(channelLabels) {
		var merged = {};
		var k;
		for (k in DEFAULT_LABELS) {
			if (Object.prototype.hasOwnProperty.call(DEFAULT_LABELS, k)) {
				merged[k] = DEFAULT_LABELS[k];
			}
		}
		if (channelLabels) {
			for (k in channelLabels) {
				if (Object.prototype.hasOwnProperty.call(channelLabels, k)) {
					merged[k] = channelLabels[k];
				}
			}
		}
		return merged;
	}

	function createController(options) {
		var labels    = mergeLabels(options && options.specialistLabels);
		var dClass    = (options && options.dividerCssClass) || 'def-speaker-divider';
		var thinkSel  = (options && options.thinkingLabelSelector) || '.cc-tool-label';
		var appendFn  = (options && options.appendDivider) || function () {};

		var currentAgent = null;

		function specialistDisplayName(agentId) {
			if (!agentId || agentId === 'concierge') return '';
			if (labels[agentId]) return labels[agentId];
			return agentId.replace(/_/g, ' ').replace(/\b\w/g, function (c) {
				return c.toUpperCase();
			});
		}

		function formatThinkingLabel(statusText) {
			var name = specialistDisplayName(currentAgent);
			var msg  = (statusText || '').toString().trim() || 'Thinking…';
			return name ? (name + ' — ' + msg) : msg;
		}

		function renderSpeakerDivider(agentId) {
			var label = specialistDisplayName(agentId) || 'Assistant';
			var div   = document.createElement('div');
			div.className   = dClass;
			div.textContent = label + ' is helping';
			appendFn(div);
		}

		function refreshThinkingRowSpeaker(thinkingStatusEl) {
			if (!thinkingStatusEl) return;
			var labelEl = thinkingStatusEl.querySelector(thinkSel);
			if (!labelEl) return;
			// Strip any existing "Name — " prefix so repeated refreshes don't
			// stack (e.g. "Sales — Sales — Thinking…").
			var current = labelEl.textContent || '';
			var em      = ' — ';
			var idx     = current.indexOf(em);
			var raw     = idx >= 0 ? current.slice(idx + em.length) : current;
			labelEl.textContent = formatThinkingLabel(raw);
		}

		/**
		 * Inspect an SSE event for an `agent` tag and update internal state.
		 * Renders a divider when the active speaker changes; refreshes the
		 * thinking-row label in place. Safe to call on every event.
		 */
		function handleEvent(evt, thinkingStatusEl) {
			var evtAgent = (evt && evt.agent) ? String(evt.agent) : null;
			var isSpecialist = evtAgent && evtAgent !== 'concierge';
			if (isSpecialist && evtAgent !== currentAgent) {
				renderSpeakerDivider(evtAgent);
				currentAgent = evtAgent;
				refreshThinkingRowSpeaker(thinkingStatusEl);
			} else if (evtAgent === 'concierge' && currentAgent !== null) {
				// Specialist done; Concierge speaks again. No divider; just
				// strip any lingering specialist prefix.
				currentAgent = null;
				refreshThinkingRowSpeaker(thinkingStatusEl);
			}
		}

		function reset() { currentAgent = null; }

		function getCurrentAgent() { return currentAgent; }

		return {
			handleEvent: handleEvent,
			formatThinkingLabel: formatThinkingLabel,
			reset: reset,
			getCurrentAgent: getCurrentAgent,
		};
	}

	win.DefPersona = { createController: createController };
})(window);
