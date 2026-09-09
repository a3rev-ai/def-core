<?php
/**
 * Staff AI application shell template.
 *
 * This is a standalone HTML document (not embedded in wp-admin).
 * It does NOT call wp_head() / wp_footer() by design — assets are
 * loaded via direct <link> and <script> tags.
 *
 * Expected variables (set by render_shell() before inclusion):
 * @var string  $channel   Always 'staff_ai'
 * @var WP_User $user      Current authenticated user
 * @var string  $api_base  REST API base URL
 * @var string  $nonce     WordPress REST nonce
 * @var string  $logo_html Pre-built HTML for header logo
 *
 * @package def-core
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Staff AI', 'digital-employees' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/staff-ai/manifest.json' ) ); ?>">
	<meta name="theme-color" content="#6366f1">
	<link rel="stylesheet" href="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/css/staff-ai.css' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/css/def-core-product-cards.css' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>">
	<script>
	// Theme init — must run before body renders to prevent flash.
	(function() {
		var saved = localStorage.getItem('staff-ai-theme');
		if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
			document.documentElement.classList.add('dark-theme');
		}
	})();
	</script>
</head>
<body>

	<div id="staff-ai-app"
		data-channel="<?php echo esc_attr( $channel ); ?>"
		data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
		data-user-email="<?php echo esc_attr( $user->user_email ); ?>"
		data-api-base="<?php echo esc_url( $api_base ); ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<!-- Sidebar overlay for mobile -->
		<div class="sidebar-overlay" id="sidebarOverlay"></div>

		<!-- Sidebar -->
		<aside class="sidebar" id="sidebar">
			<div class="sidebar-header">
				<button type="button" class="new-chat-btn" id="newChatBtn">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="12" y1="5" x2="12" y2="19"></line>
						<line x1="5" y1="12" x2="19" y2="12"></line>
					</svg>
					<?php echo esc_html__( 'New chat', 'digital-employees' ); ?>
				</button>
			</div>
			<!-- D-C7: an entry that opens a PAGE is a link to its route, so the
			     browser's back button, a phone's back gesture and a reload behave as
			     they do on any site, and the open page is marked aria-current="page".
			     With C3 all SIX entries are links, and not one of them still
			     declares itself the opener of a dialog — the sidebar is what it
			     looks like, a set of places. -->
			<nav class="sidebar-nav" aria-label="<?php echo esc_attr__( 'Staff AI sections', 'digital-employees' ); ?>">
				<a class="sidebar-nav-item" id="navProjects" href="#projects">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
					</svg>
					<?php echo esc_html__( 'Projects', 'digital-employees' ); ?>
				</a>
				<a class="sidebar-nav-item" id="navDocuments" href="#documents">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
						<polyline points="14 2 14 8 20 8"></polyline>
					</svg>
					<?php echo esc_html__( 'Documents', 'digital-employees' ); ?>
				</a>
				<a class="sidebar-nav-item" id="navScheduled" href="#scheduled">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="12" cy="12" r="9"></circle>
						<polyline points="12 7 12 12 15 14"></polyline>
					</svg>
					<?php echo esc_html__( 'Scheduled', 'digital-employees' ); ?>
				</a>
				<a class="sidebar-nav-item" id="navMemories" href="#memories">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
					</svg>
					<?php echo esc_html__( 'Memories', 'digital-employees' ); ?>
				</a>
				<a class="sidebar-nav-item" id="navUsage" href="#usage">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<line x1="18" y1="20" x2="18" y2="10"></line>
						<line x1="12" y1="20" x2="12" y2="4"></line>
						<line x1="6" y1="20" x2="6" y2="14"></line>
					</svg>
					<?php echo esc_html__( 'Usage', 'digital-employees' ); ?>
				</a>
				<a class="sidebar-nav-item" id="navConnections" href="#connections">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
						<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
					</svg>
					<?php echo esc_html__( 'Connections', 'digital-employees' ); ?>
				</a>
			</nav>
			<nav class="conversation-list" id="conversationList" aria-label="<?php echo esc_attr__( 'Conversations', 'digital-employees' ); ?>">
				<div class="conversation-list-placeholder" id="conversationPlaceholder">
					<?php echo esc_html__( 'No conversations yet', 'digital-employees' ); ?>
				</div>
			</nav>
			<div class="sidebar-footer">
				<?php echo esc_html__( 'Powered by DEF', 'digital-employees' ); ?>
			</div>
		</aside>

		<!-- Main chat -->
		<main class="chat-container chat-empty">
			<header class="chat-header">
				<button type="button" class="menu-toggle" id="menuToggle" aria-label="<?php echo esc_attr__( 'Toggle menu', 'digital-employees' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="3" y1="6" x2="21" y2="6"></line>
						<line x1="3" y1="12" x2="21" y2="12"></line>
						<line x1="3" y1="18" x2="21" y2="18"></line>
					</svg>
				</button>
				<div class="header-logo"><?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo is escaped in wp_get_attachment_image or esc_html ?></div>
				<span class="readonly-indicator" id="readonlyIndicator"><?php echo esc_html__( 'Read-only (shared)', 'digital-employees' ); ?></span>
				<div class="header-actions">
					<button type="button" class="header-btn" id="exportBtn" disabled><?php echo esc_html__( 'Export', 'digital-employees' ); ?></button>
					<button type="button" class="header-btn" id="shareBtn" disabled><?php echo esc_html__( 'Share', 'digital-employees' ); ?></button>
					<button type="button" class="header-btn header-btn-install" id="installBtn" style="display:none;" title="<?php echo esc_attr__( 'Install app', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="7 10 12 15 17 10"></polyline>
							<line x1="12" y1="15" x2="12" y2="3"></line>
						</svg>
						<?php echo esc_html__( 'Install', 'digital-employees' ); ?>
					</button>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="header-btn header-btn-icon" target="_blank" rel="noopener" title="<?php echo esc_attr__( 'Go to website', 'digital-employees' ); ?>" aria-label="<?php echo esc_attr__( 'Go to website', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
							<polyline points="15 3 21 3 21 9"></polyline>
							<line x1="10" y1="14" x2="21" y2="3"></line>
						</svg>
					</a>
					<a href="<?php echo esc_url( wp_logout_url( wp_login_url( home_url( '/staff-ai/' ) ) ) ); ?>" class="header-btn header-btn-icon" title="<?php echo esc_attr__( 'Log out', 'digital-employees' ); ?>" aria-label="<?php echo esc_attr__( 'Log out', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
							<polyline points="16 17 21 12 16 7"></polyline>
							<line x1="21" y1="12" x2="9" y2="12"></line>
						</svg>
					</a>
					<button type="button" class="theme-toggle" id="themeToggle" aria-label="<?php echo esc_attr__( 'Toggle theme', 'digital-employees' ); ?>">
						<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="5"></circle>
							<line x1="12" y1="1" x2="12" y2="3"></line>
							<line x1="12" y1="21" x2="12" y2="23"></line>
							<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
							<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
							<line x1="1" y1="12" x2="3" y2="12"></line>
							<line x1="21" y1="12" x2="23" y2="12"></line>
							<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
							<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
						</svg>
						<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
						</svg>
					</button>
					<!-- Mobile overflow menu -->
					<button type="button" class="header-overflow-toggle" id="headerOverflowToggle" aria-label="<?php echo esc_attr__( 'More options', 'digital-employees' ); ?>">
						<svg viewBox="0 0 24 24" fill="currentColor">
							<circle cx="12" cy="5" r="2"></circle>
							<circle cx="12" cy="12" r="2"></circle>
							<circle cx="12" cy="19" r="2"></circle>
						</svg>
					</button>
					<div class="header-overflow-menu" id="headerOverflowMenu">
						<button type="button" id="overflowExport"><?php echo esc_html__( 'Export', 'digital-employees' ); ?></button>
						<button type="button" id="overflowShare"><?php echo esc_html__( 'Share', 'digital-employees' ); ?></button>
						<button type="button" id="overflowCreate">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
								<polyline points="14 2 14 8 20 8"></polyline>
								<line x1="12" y1="11" x2="12" y2="17"></line>
								<line x1="9" y1="14" x2="15" y2="14"></line>
							</svg>
							<?php echo esc_html__( 'Create', 'digital-employees' ); ?>
						</button>
						<button type="button" id="overflowInstall" style="display:none;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
								<polyline points="7 10 12 15 17 10"></polyline>
								<line x1="12" y1="15" x2="12" y2="3"></line>
							</svg>
							<?php echo esc_html__( 'Install app', 'digital-employees' ); ?>
						</button>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
								<polyline points="15 3 21 3 21 9"></polyline>
								<line x1="10" y1="14" x2="21" y2="3"></line>
							</svg>
							<?php echo esc_html__( 'Go to website', 'digital-employees' ); ?>
						</a>
						<button type="button" id="overflowThemeToggle">
							<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="5"></circle>
								<line x1="12" y1="1" x2="12" y2="3"></line>
								<line x1="12" y1="21" x2="12" y2="23"></line>
							</svg>
							<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
							</svg>
							<?php echo esc_html__( 'Toggle theme', 'digital-employees' ); ?>
						</button>
						<a href="<?php echo esc_url( wp_logout_url( wp_login_url( home_url( '/staff-ai/' ) ) ) ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
								<polyline points="16 17 21 12 16 7"></polyline>
								<line x1="21" y1="12" x2="9" y2="12"></line>
							</svg>
							<?php echo esc_html__( 'Log out', 'digital-employees' ); ?>
						</a>
					</div>
				</div>
			</header>

			<!-- Projects — the same .console-page shell (D-C3, C2). The 7.8.0 card
			     workspace, body for body, out of the box it used to sit in: the
			     archived toggle, the status line and the card list are unchanged, the
			     modal's overlay, × and Refresh/Close are gone (a page loads on entry),
			     and the head carries the Ask entry and Create project. Projects P-D5's
			     rule holds — Ask Sue IS the help layer, so the page carries no
			     explainer paragraph.
			     (docs/projects-runsheet.md in the DEF repo: P-A the container, P-B chat
			     entry, P-C scheduled runs inside a project, P-D the doorway.) -->
			<section class="console-page console-page-compact" id="projectsPane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="projectsTitle" tabindex="-1"><?php echo esc_html__( 'Projects', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'Folders your assistant works from, each with its own governing documents.', 'digital-employees' ); ?></p>
					</div>
					<!-- The 7.8.0 create row, in the shell's actions slot: a name field and
					     the button beside the Ask entry. Enter in the field creates too. -->
					<div class="console-page-actions">
						<button type="button" class="modal-btn modal-btn-secondary projects-ask-btn"><?php echo esc_html__( 'Ask how Projects work', 'digital-employees' ); ?></button>
						<input type="text" class="form-input projects-create-name" id="projectsNewName" maxlength="120" aria-label="<?php echo esc_attr__( 'New project name', 'digital-employees' ); ?>" placeholder="<?php echo esc_attr__( 'New project name…', 'digital-employees' ); ?>">
						<button type="button" class="modal-btn modal-btn-primary" id="projectsCreateBtn"><?php echo esc_html__( 'Create project', 'digital-employees' ); ?></button>
					</div>
				</div>
				<label class="projects-archived-toggle">
					<input type="checkbox" id="projectsShowArchived">
					<?php echo esc_html__( 'Show archived', 'digital-employees' ); ?>
				</label>
				<div class="documents-status" id="projectsStatus"></div>
				<div class="projects-list" id="projectsList"></div>
			</section>

			<!-- Scheduled tasks — a console PAGE on the shared .console-page shell
			     (D-C3): a head with the title, one line of description and an actions
			     slot, then a body that scrolls with the page. showPage()/showChat()
			     swap it with the chat containers and drive #scheduled. -->
			<section class="console-page" id="scheduledPane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="scheduledTitle" tabindex="-1"><?php echo esc_html__( 'Scheduled tasks', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'Run tasks on a schedule or whenever you need them.', 'digital-employees' ); ?></p>
					</div>
					<div class="console-page-actions">
						<button type="button" class="modal-btn modal-btn-secondary" id="scheduledAskAssistant"><?php echo esc_html__( 'Ask how Scheduled Tasks work', 'digital-employees' ); ?></button>
						<button type="button" class="modal-btn modal-btn-primary" id="taskCreateBtn"><?php echo esc_html__( 'New task', 'digital-employees' ); ?></button>
					</div>
				</div>
				<div class="task-card-grid" id="taskCardGrid"></div>
				<div class="schedule-empty" id="scheduledEmpty" style="display:none;">
					<p><?php echo esc_html__( 'Nothing scheduled yet. Create your first task.', 'digital-employees' ); ?></p>
				</div>
				<div class="schedule-status" id="scheduledPaneStatus"></div>
			</section>

			<!-- My documents — the same .console-page shell (D-C3): document CARDS
			     grouped by month, one search box matching document OR project names,
			     the project filter and the per-card actions. Its actions slot stays
			     empty on purpose — the Ask entry belongs to the empty state below. -->
			<section class="console-page console-page-compact" id="documentsPane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="documentsTitle" tabindex="-1"><?php echo esc_html__( 'My documents', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'Documents created for you in Staff AI. Only you can see these.', 'digital-employees' ); ?></p>
					</div>
					<div class="console-page-actions"></div>
				</div>
				<div class="documents-controls">
					<input type="search" class="form-input documents-search" id="documentsSearch" aria-label="<?php echo esc_attr__( 'Search by document or project name', 'digital-employees' ); ?>" placeholder="<?php echo esc_attr__( 'Search by document or project name…', 'digital-employees' ); ?>">
					<select class="form-input documents-project-filter" id="documentsProjectFilter" aria-label="<?php echo esc_attr__( 'Filter by project', 'digital-employees' ); ?>">
						<option value=""><?php echo esc_html__( 'All documents', 'digital-employees' ); ?></option>
					</select>
				</div>
				<div class="documents-status" id="documentsStatus"></div>
				<div class="documents-grid" id="documentsGrid"></div>
				<!-- The empty state IS the entry point (2026-09-03): the line it replaces
				     told the reader to "ask me to create one" without giving them a way
				     to ask. JS decides when it shows. -->
				<div class="documents-empty" id="documentsEmptyState" style="display:none;">
					<button type="button" class="modal-btn modal-btn-primary" id="documentsAskAssistant"><?php echo esc_html__( 'Ask your assistant to create a document', 'digital-employees' ); ?></button>
				</div>
			</section>

			<!-- Memories — the Memories modal's body on the shared .console-page shell
			     (C3, D-C2/D-C3): the status line and the row list are the modal's,
			     unchanged. The overlay, the ×, and the footer's Refresh and Close are
			     gone — a page loads on entry (onEnter), and re-entering reloads. The
			     modal's first intro paragraph IS the header's one description line
			     now; its second (what deleting does) was help text, and the per-row
			     confirm already says it at the moment it matters. No primary action:
			     Delete belongs to a row, with its confirm, exactly as before. -->
			<section class="console-page" id="memoriesPane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="memoriesTitle" tabindex="-1"><?php echo esc_html__( 'Memories', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'Things Staff AI has noted from your conversations so you do not have to repeat yourself. Only you can see these — no administrator can read them.', 'digital-employees' ); ?></p>
					</div>
					<div class="console-page-actions">
						<button type="button" class="modal-btn modal-btn-secondary" id="memoriesAskAssistant"><?php echo esc_html__( 'Ask how Memories work', 'digital-employees' ); ?></button>
					</div>
				</div>
				<div class="memories-status" id="memoriesStatus"></div>
				<div class="memories-list" id="memoriesList"></div>
			</section>

			<!-- Weekly limits — the Usage modal's body on the shell (C3). Usage is the
			     ONE page of the three that keeps Refresh: its numbers move while you
			     read them (a reply streaming in the tab behind is spending the very
			     budget the bar is drawing), so re-reading without leaving the page is
			     the whole gesture. That makes it the page's primary action, and it is
			     filled like Projects' Create project and Scheduled's New task, with
			     the Ask entry secondary beside it. Memories and Connections change
			     only when you change them, and re-entering reloads. -->
			<section class="console-page" id="usagePane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="usageTitle" tabindex="-1"><?php echo esc_html__( 'Weekly limits', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'What you have used this week, and which models are using it.', 'digital-employees' ); ?></p>
					</div>
					<div class="console-page-actions">
						<button type="button" class="modal-btn modal-btn-secondary" id="usageAskAssistant"><?php echo esc_html__( 'Ask how Usage works', 'digital-employees' ); ?></button>
						<button type="button" class="modal-btn modal-btn-primary" id="usageRefresh"><?php echo esc_html__( 'Refresh', 'digital-employees' ); ?></button>
					</div>
				</div>
				<p class="usage-resets" id="usageResets"></p>
				<div class="usage-status" id="usageStatus"></div>
				<div class="usage-bars" id="usageBars"></div>
				<div class="usage-list" id="usageList"></div>
			</section>

			<!-- Connected accounts — the Connections modal's body on the shell (C3).
			     The status line and the app rows are the modal's, unchanged; the
			     intro paragraph is the header's description line. Connect, Disconnect,
			     the primary-for-chat pin and Connect another account are all PER ROW
			     and stay there — there is no page-level connect action to put in the
			     slot, because which app to connect is the choice a row makes. Its
			     confirms are unchanged. -->
			<section class="console-page" id="connectionsPane" hidden>
				<div class="console-page-head">
					<div>
						<h1 class="console-page-title" id="connectionsTitle" tabindex="-1"><?php echo esc_html__( 'Connected accounts', 'digital-employees' ); ?></h1>
						<p class="console-page-desc"><?php echo esc_html__( 'Connect your own accounts so actions (like sending a message) go out as you — not a shared account.', 'digital-employees' ); ?></p>
					</div>
					<div class="console-page-actions">
						<button type="button" class="modal-btn modal-btn-secondary" id="connectionsAskAssistant"><?php echo esc_html__( 'Ask how Connections work', 'digital-employees' ); ?></button>
					</div>
				</div>
				<div class="integrations-status" id="integrationsStatus"></div>
				<div class="integrations-list" id="integrationsList"></div>
			</section>

			<div class="messages-container" id="messagesContainer">
				<div class="messages-list" id="messagesList">
					<div class="welcome-message" id="welcomeMessage">
						<?php
						$first_name   = $user->first_name ?: $user->display_name;
						$woo_active   = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
						$is_manager   = $user->has_cap( 'def_management_access' );
						// Tweaks item 4 (Steve, 2026-09-02): the 16-bullet capability list is
						// gone — Claude/ChatGPT-style first load: the tenant's own logo, a
						// couple-of-words greeting, and the composer centred with it (the
						// .chat-empty flex centring in staff-ai.css). Capability discovery
						// now lives where "the chat IS the onboarding" put it: the Ask
						// buttons and the docs corpus. ($woo_active / $is_manager stay —
						// the tips build below still reads them.)
						?>
						<div id="welcomeFull">
							<div class="welcome-hero">
								<?php if ( ! empty( $welcome_logo_html ) ) : ?>
								<span class="welcome-logo"><?php echo $welcome_logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where built. ?></span>
								<?php endif; ?>
								<h1 class="welcome-greeting"><?php printf( esc_html__( 'Hi %s', 'digital-employees' ), esc_html( $first_name ) ); ?></h1>
							</div>
						</div>
						<p id="welcomeTip" class="welcome-tip" style="display:none;"></p>
					</div>
				</div>
			</div>

			<div class="info-banner" id="infoBanner"></div>
			<div class="error-banner" id="errorBanner"></div>

			<div class="composer-container" id="composerContainer">
				<!-- Drop overlay -->
				<div class="upload-drop-overlay" id="uploadDropOverlay">
					<div class="upload-drop-overlay-content">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="17 8 12 3 7 8"></polyline>
							<line x1="12" y1="3" x2="12" y2="15"></line>
						</svg>
						<span><?php echo esc_html__( 'Drop files here', 'digital-employees' ); ?></span>
					</div>
				</div>
				<!-- Hidden file input -->
				<input type="file" id="uploadFileInput" class="sr-only" multiple
					accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.txt,.md,.csv,.docx,.xlsx" />
				<div class="composer-wrapper">
					<!-- Staged files area -->
					<div class="upload-staged-area" id="uploadStagedArea" style="display: none;"
						aria-live="polite" aria-relevant="additions removals"></div>
					<div class="composer-row">
						<div class="composer">
							<div class="composer-scroll" id="composerScroll">
								<textarea
									class="composer-input"
									id="composerInput"
									placeholder="<?php echo esc_attr__( 'Send a message...', 'digital-employees' ); ?>"
									rows="1"></textarea>
							</div>
							<div class="composer-toolbar">
								<button type="button" class="upload-btn" id="uploadBtn"
									aria-label="<?php echo esc_attr__( 'Attach file', 'digital-employees' ); ?>"
									title="<?php echo esc_attr__( 'Attach file', 'digital-employees' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
										stroke-linecap="round" stroke-linejoin="round">
										<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
									</svg>
								</button>
								<button type="button" class="mic-btn" id="micBtn" data-state="idle"
									aria-label="<?php echo esc_attr__( 'Speak', 'digital-employees' ); ?>"
									title="<?php echo esc_attr__( 'Speak your message — a pause sends it', 'digital-employees' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
										stroke-linecap="round" stroke-linejoin="round">
										<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
										<path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
										<line x1="12" y1="19" x2="12" y2="23"></line>
										<line x1="8" y1="23" x2="16" y2="23"></line>
									</svg>
									<span class="mic-label" id="micLabel" hidden></span>
								</button>
								<button type="button" class="voice-btn" id="voiceBtn" data-mode="server"
									aria-label="<?php echo esc_attr__( 'Voice', 'digital-employees' ); ?>"
									title="<?php echo esc_attr__( 'How spoken replies are read back', 'digital-employees' ); ?>">
									<svg class="voice-icon-server" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
										<path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
									</svg>
									<svg class="voice-icon-device" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
										<line x1="12" y1="18" x2="12.01" y2="18"></line>
									</svg>
									<svg class="voice-icon-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
										<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
										<line x1="23" y1="9" x2="17" y2="15"></line>
										<line x1="17" y1="9" x2="23" y2="15"></line>
									</svg>
								</button>
								<select class="model-select" id="modelSelect" hidden aria-label="<?php echo esc_attr__( 'AI model', 'digital-employees' ); ?>" title="<?php echo esc_attr__( 'Choose the AI model for this session', 'digital-employees' ); ?>"></select>
								<button type="button" class="send-btn" id="sendBtn" disabled aria-label="<?php echo esc_attr__( 'Send message', 'digital-employees' ); ?>">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<line x1="22" y1="2" x2="11" y2="13"></line>
										<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
									</svg>
								</button>
							</div>
						</div>
						<button type="button" class="create-btn" id="createBtn">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
								<polyline points="14 2 14 8 20 8"></polyline>
								<line x1="12" y1="11" x2="12" y2="17"></line>
								<line x1="9" y1="14" x2="15" y2="14"></line>
							</svg>
							<?php echo esc_html__( 'Create', 'digital-employees' ); ?>
						</button>
					</div>
					<div class="composer-hint">
						<?php echo esc_html__( 'Press Enter to send, Shift+Enter for new line', 'digital-employees' ); ?>
					</div>
				</div>
			</div>
		</main>

		<!-- Share Modal -->
		<div class="modal-overlay" id="shareModal">
			<div class="modal" style="max-width: 520px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Share Conversation', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="shareModalClose">&times;</button>
				</div>
				<!-- Loading state -->
				<div class="share-loading" id="shareLoading">
					<div class="share-loading-spinner"></div>
					<p><?php echo esc_html__( 'Preparing share form...', 'digital-employees' ); ?></p>
				</div>
				<!-- Error state -->
				<div class="share-error" id="shareError" style="display:none;">
					<p id="shareErrorText"></p>
					<button type="button" class="modal-btn modal-btn-secondary" id="shareErrorClose"><?php echo esc_html__( 'Close', 'digital-employees' ); ?></button>
				</div>
				<!-- Form state -->
				<div id="shareFormContent" style="display:none;">
					<div class="modal-body">
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Share with', 'digital-employees' ); ?></label>
							<div class="token-select" id="shareRecipientTokenSelect">
								<div class="token-select-tokens" id="shareRecipientTokens">
									<span class="token-select-placeholder" id="shareRecipientPlaceholder"><?php echo esc_html__( 'Click to select recipients...', 'digital-employees' ); ?></span>
								</div>
								<div class="token-select-dropdown" id="shareRecipientDropdown"></div>
							</div>
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Subject', 'digital-employees' ); ?></label>
							<input type="text" class="form-input" id="shareSubject" placeholder="<?php echo esc_attr__( 'Brief summary...', 'digital-employees' ); ?>">
						</div>
						<div class="form-group">
							<label class="form-label"><?php echo esc_html__( 'Message', 'digital-employees' ); ?></label>
							<textarea class="form-input share-message-input" id="shareMessage" rows="4" placeholder="<?php echo esc_attr__( 'Summary and context for the recipient...', 'digital-employees' ); ?>"></textarea>
						</div>
						<div class="share-transcript-toggle">
							<label class="share-toggle-label">
								<input type="checkbox" id="shareTranscript" checked>
								<svg class="share-paperclip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
								<?php echo esc_html__( 'Include conversation transcript', 'digital-employees' ); ?>
							</label>
						</div>
						<div class="share-documents-section" id="shareDocumentsSection" style="display:none;">
							<label class="form-label"><?php echo esc_html__( 'Attach documents', 'digital-employees' ); ?></label>
							<div class="share-documents-list" id="shareDocumentsList"></div>
							<p class="share-documents-hint" id="shareDocumentsHint" style="display:none;"></p>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="modal-btn modal-btn-secondary" id="shareCancel"><?php echo esc_html__( 'Cancel', 'digital-employees' ); ?></button>
						<button type="button" class="modal-btn modal-btn-primary" id="shareSend" disabled><?php echo esc_html__( 'Send', 'digital-employees' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Create Tool Modal -->
		<div class="modal-overlay" id="createModal">
			<div class="modal" style="max-width: 480px;">
				<div class="modal-header">
					<span class="modal-title"><?php echo esc_html__( 'Create', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="createModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Type', 'digital-employees' ); ?></label>
						<select class="form-input" id="createToolType">
							<option value="document_creation"><?php echo esc_html__( 'Document', 'digital-employees' ); ?></option>
							<option value="spreadsheet_creation"><?php echo esc_html__( 'Spreadsheet', 'digital-employees' ); ?></option>
							<option value="image_generation"><?php echo esc_html__( 'Image', 'digital-employees' ); ?></option>
						</select>
					</div>
					<div class="form-group" id="createFormatGroup">
						<label class="form-label"><?php echo esc_html__( 'Format', 'digital-employees' ); ?></label>
						<select class="form-input" id="createFormat">
							<option value="docx">DOCX</option>
							<option value="pdf">PDF</option>
							<option value="md">Markdown</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Title (optional)', 'digital-employees' ); ?></label>
						<input type="text" class="form-input" id="createTitle" placeholder="<?php echo esc_attr__( 'My Document', 'digital-employees' ); ?>">
					</div>
					<div class="form-group">
						<label class="form-label"><?php echo esc_html__( 'Instructions', 'digital-employees' ); ?> <span style="color: var(--share-error-text);">*</span></label>
						<textarea class="form-input" id="createPrompt" rows="4" placeholder="<?php echo esc_attr__( 'Describe what you want to create...', 'digital-employees' ); ?>"></textarea>
					</div>
					<div class="error-banner" id="createError" style="margin: 0;"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="createCancel"><?php echo esc_html__( 'Cancel', 'digital-employees' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="createSubmit"><?php echo esc_html__( 'Create', 'digital-employees' ); ?></button>
				</div>
			</div>
		</div>
		<!-- The My Documents modal became the #documentsPane page (tweaks item 3, 2026-09-02). -->
		<!-- Connections, Memories and Usage became the #connectionsPane, #memoriesPane
		     and #usagePane pages (C3, v7.8.3). -->
		<!-- Document viewer (Projects P-D3, D-P14): read a document in place. The text
		     is set via textContent into a <pre> — never HTML (a project document is
		     untrusted content, D-P7). Reached from a document row's View and from a
		     project's slot lines. -->
		<div class="modal-overlay" id="documentViewerModal">
			<div class="modal" style="max-width: 720px;">
				<div class="modal-header">
					<span class="modal-title" id="documentViewerTitle"><?php echo esc_html__( 'Document', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="documentViewerClose">&times;</button>
				</div>
				<div class="modal-body">
					<div class="documents-status" id="documentViewerStatus"></div>
					<pre class="document-viewer-text" id="documentViewerText"></pre>
					<button type="button" class="modal-btn modal-btn-secondary" id="documentViewerMore" style="display:none;"><?php echo esc_html__( 'Show more', 'digital-employees' ); ?></button>
				</div>
				<div class="modal-footer">
					<a class="modal-btn modal-btn-secondary" id="documentViewerDownload" href="#" style="display:none;"><?php echo esc_html__( 'Download', 'digital-employees' ); ?></a>
					<button type="button" class="modal-btn modal-btn-secondary" id="documentViewerCloseBtn"><?php echo esc_html__( 'Close', 'digital-employees' ); ?></button>
				</div>
			</div>
		</div>
		<!-- Email Triage schedule (S4b) - the user's own daily digest settings -->
		<div class="modal-overlay" id="scheduleModal">
			<div class="modal" style="max-width: 480px;">
				<div class="modal-header">
					<span class="modal-title" id="scheduleTitle"><?php echo esc_html__( 'Scheduled tasks', 'digital-employees' ); ?></span>
					<button type="button" class="modal-close" id="scheduleModalClose">&times;</button>
				</div>
				<div class="modal-body">
					<!-- The task list lives on the #scheduledPane landing page now
					     (Phase 3, D-S7); this modal is the CREATOR/EDITOR only. Two
					     form views, one per task type; Free text is the DEFAULT (D-S2).
					     The type selector sits OUTSIDE both views (2026-09-03) so the
					     choice stays changeable while the popup is open: it used to live
					     inside the free-text view, so picking Email Triage hid the only
					     control that could pick anything else. Creation only (JS). -->
					<div class="form-group" id="taskTypeRow">
						<label class="form-label" for="taskType"><?php echo esc_html__( 'Task type', 'digital-employees' ); ?></label>
						<select class="form-input" id="taskType">
							<option value="freetext" selected><?php echo esc_html__( 'Custom task - tell Staff AI what to do', 'digital-employees' ); ?></option>
							<option value="email_triage"><?php echo esc_html__( 'Email Triage - mailbox digest with reply drafts', 'digital-employees' ); ?></option>
						</select>
					</div>
					<div id="taskFormView" style="display:none;">
						<div class="form-group">
							<label class="form-label" for="taskName"><?php echo esc_html__( 'Name', 'digital-employees' ); ?></label>
							<input type="text" class="form-input" id="taskName" maxlength="120" placeholder="<?php echo esc_attr__( 'Daily brief', 'digital-employees' ); ?>">
						</div>
						<div class="form-group">
							<label class="form-label" for="taskInstruction"><?php echo esc_html__( 'What should Staff AI do?', 'digital-employees' ); ?></label>
							<textarea class="form-input task-instruction" id="taskInstruction" rows="5" placeholder="<?php echo esc_attr__( 'Write my Monday planning checklist: the three questions I should answer before the week starts, and a blank plan I can fill in.', 'digital-employees' ); ?>"></textarea>
							<p class="form-hint"><?php echo esc_html__( 'The task runs with no tools: it can write, plan, summarise and remind, but it cannot read your email or calendar, browse the web, or send anything on your behalf.', 'digital-employees' ); ?></p>
						</div>
						<div class="form-group schedule-toggle-row">
							<label class="share-toggle-label"><input type="checkbox" id="taskEnabled" class="share-transcript-toggle" checked> <span id="taskEnabledLabel"><?php echo esc_html__( 'Run this task on its schedule', 'digital-employees' ); ?></span></label>
						</div>
						<div class="form-group">
							<label class="form-label" for="taskCadence"><?php echo esc_html__( 'Frequency', 'digital-employees' ); ?></label>
							<select class="form-input" id="taskCadence">
								<option value="manual"><?php echo esc_html__( 'Manual - only when I press Run now', 'digital-employees' ); ?></option>
								<option value="hourly"><?php echo esc_html__( 'Hourly', 'digital-employees' ); ?></option>
								<option value="daily" selected><?php echo esc_html__( 'Daily', 'digital-employees' ); ?></option>
								<option value="weekdays"><?php echo esc_html__( 'Weekdays - Monday to Friday', 'digital-employees' ); ?></option>
								<option value="weekly"><?php echo esc_html__( 'Weekly', 'digital-employees' ); ?></option>
							</select>
							<p class="form-hint" id="taskCadenceHint" style="display:none;"></p>
						</div>
						<!-- P3-C (D-S9): hidden until the tenant's own model list arrives
						     from /staff-ai/status - the chat switcher's source. No models
						     offered (e.g. provider not configured) = no row, default applies. -->
						<div class="form-group" id="taskModelRow" style="display:none;">
							<label class="form-label" for="taskModel"><?php echo esc_html__( 'Model', 'digital-employees' ); ?></label>
							<select class="form-input" id="taskModel">
								<option value="" selected><?php echo esc_html__( 'Default model', 'digital-employees' ); ?></option>
							</select>
							<p class="form-hint"><?php echo esc_html__( 'The model this task runs on. Default model follows your Staff AI setting.', 'digital-employees' ); ?></p>
						</div>
						<!-- Projects P-C: run the task INSIDE one of the user's projects. Hidden
						     until the user has a project (or the task is already bound). -->
						<div class="form-group" id="taskProjectRow" style="display:none;">
							<label class="form-label" for="taskProject"><?php echo esc_html__( 'Project', 'digital-employees' ); ?></label>
							<select class="form-input" id="taskProject">
								<option value="" selected><?php echo esc_html__( 'No project', 'digital-employees' ); ?></option>
							</select>
							<p class="form-hint"><?php echo esc_html__( 'The task runs inside this project: it reads the project\'s instructions, runsheet and session notes, and each run is recorded in the session notes.', 'digital-employees' ); ?></p>
						</div>
						<div class="form-group" id="taskWeekdayRow" style="display:none;">
							<label class="form-label" for="taskWeekday"><?php echo esc_html__( 'Day of the week', 'digital-employees' ); ?></label>
							<select class="form-input" id="taskWeekday">
								<option value="0" selected><?php echo esc_html__( 'Monday', 'digital-employees' ); ?></option>
								<option value="1"><?php echo esc_html__( 'Tuesday', 'digital-employees' ); ?></option>
								<option value="2"><?php echo esc_html__( 'Wednesday', 'digital-employees' ); ?></option>
								<option value="3"><?php echo esc_html__( 'Thursday', 'digital-employees' ); ?></option>
								<option value="4"><?php echo esc_html__( 'Friday', 'digital-employees' ); ?></option>
								<option value="5"><?php echo esc_html__( 'Saturday', 'digital-employees' ); ?></option>
								<option value="6"><?php echo esc_html__( 'Sunday', 'digital-employees' ); ?></option>
							</select>
						</div>
						<div class="form-group" id="taskTimeRow">
							<label class="form-label" for="taskTime"><?php echo esc_html__( 'Send time', 'digital-employees' ); ?></label>
							<input type="time" class="form-input" id="taskTime" value="07:00">
						</div>
						<div class="form-group" id="taskTzRow">
							<label class="form-label" for="taskTimezone"><?php echo esc_html__( 'Timezone', 'digital-employees' ); ?></label>
							<select class="form-input" id="taskTimezone"></select>
						</div>
						<div class="form-group">
							<span class="form-label"><?php echo esc_html__( 'Deliver to', 'digital-employees' ); ?></span>
							<label class="share-toggle-label schedule-dest-row"><input type="checkbox" id="taskDestEmail" class="share-transcript-toggle" checked> <?php echo esc_html__( 'Email - my own inbox', 'digital-employees' ); ?></label>
							<label class="share-toggle-label schedule-dest-row" id="taskDestSlackRow"><input type="checkbox" id="taskDestSlack" class="share-transcript-toggle"> <?php echo esc_html__( 'Slack - a direct message, through my own connection', 'digital-employees' ); ?></label>
							<label class="share-toggle-label schedule-dest-row" id="taskDestTeamsRow"><input type="checkbox" id="taskDestTeams" class="share-transcript-toggle"> <?php echo esc_html__( 'Teams - a chat message, through my own connection', 'digital-employees' ); ?></label>
							<?php /* A LABEL, not an explanation: which inbox "Email" actually means. It is hidden with the Email destination (JS), never stated for a run that will not be emailed. The Ask entry beside it always shows. */ ?>
							<p class="schedule-results-note">
								<span id="taskResultsLabel"><?php /* translators: %s: the WordPress account email a run's output is sent to. */ printf( esc_html__( 'Results go to: %s', 'digital-employees' ), esc_html( $user->user_email ) ); ?></span>
								<button type="button" class="schedule-results-ask"><?php echo esc_html__( 'Where do results go? Ask your assistant', 'digital-employees' ); ?></button>
							</p>
						</div>
						<div class="schedule-status" id="taskStatus"></div>
					</div>
					<div id="scheduleFormView" style="display:none;">
					<p class="schedule-intro"><?php echo esc_html__( 'Once a day, Staff AI can read your mailbox, stage reply drafts in your Drafts folder, and send you a digest of what needs your attention. Only you can turn this on, and only for your own mailbox.', 'digital-employees' ); ?></p>
					<div class="form-group schedule-toggle-row">
						<label class="share-toggle-label"><input type="checkbox" id="scheduleEnabled" class="share-transcript-toggle"> <?php echo esc_html__( 'Send me a daily inbox digest', 'digital-employees' ); ?></label>
					</div>
					<!-- Phase 4a (§5): the mailbox this digest reads, bound at save.
					     Hidden until the user's own connections load; no connections
					     (or an unreadable list) leaves the stored binding untouched. -->
					<div class="form-group" id="scheduleMailboxRow" style="display:none;">
						<label class="form-label" for="scheduleMailbox"><?php echo esc_html__( 'Mailbox', 'digital-employees' ); ?></label>
						<select class="form-input" id="scheduleMailbox"></select>
						<p class="form-hint"><?php echo esc_html__( 'The mailbox this digest reads. Staff AI reads exactly this connection - connecting another account later never silently changes it.', 'digital-employees' ); ?></p>
					</div>
					<div class="form-group">
						<label class="form-label" for="scheduleTime"><?php echo esc_html__( 'Send time', 'digital-employees' ); ?></label>
						<input type="time" class="form-input" id="scheduleTime" value="07:00">
					</div>
					<div class="form-group">
						<label class="form-label" for="scheduleTimezone"><?php echo esc_html__( 'Timezone', 'digital-employees' ); ?></label>
						<select class="form-input" id="scheduleTimezone"></select>
					</div>
					<div class="form-group">
						<span class="form-label"><?php echo esc_html__( 'Deliver to', 'digital-employees' ); ?></span>
						<label class="share-toggle-label schedule-dest-row"><input type="checkbox" id="scheduleDestEmail" class="share-transcript-toggle" checked> <?php echo esc_html__( 'Email - my own inbox', 'digital-employees' ); ?></label>
						<label class="share-toggle-label schedule-dest-row" id="scheduleDestSlackRow"><input type="checkbox" id="scheduleDestSlack" class="share-transcript-toggle"> <?php echo esc_html__( 'Slack - a direct message, through my own connection', 'digital-employees' ); ?></label>
						<label class="share-toggle-label schedule-dest-row" id="scheduleDestTeamsRow"><input type="checkbox" id="scheduleDestTeams" class="share-transcript-toggle"> <?php echo esc_html__( 'Teams - a chat message, through my own connection', 'digital-employees' ); ?></label>
						<p class="schedule-results-note">
							<span id="scheduleResultsLabel"><?php /* translators: %s: the WordPress account email a run's output is sent to. */ printf( esc_html__( 'Results go to: %s', 'digital-employees' ), esc_html( $user->user_email ) ); ?></span>
							<button type="button" class="schedule-results-ask"><?php echo esc_html__( 'Where do results go? Ask your assistant', 'digital-employees' ); ?></button>
						</p>
					</div>
					<!-- 6-A (runsheet §14i item 5): the correspondent context brief. The
					     CRM half has no switch - it is 2-4 record reads; this half searches
					     90 days per drafted message, so it is the half that carries a
					     cost/latency choice. DEFAULT ON: absent means on, everywhere. -->
					<div class="form-group">
						<span class="form-label"><?php echo esc_html__( 'When drafting a reply', 'digital-employees' ); ?></span>
						<label class="share-toggle-label schedule-dest-row"><input type="checkbox" id="scheduleSearchCorrespondent" class="share-transcript-toggle" checked> <?php echo esc_html__( 'Read this sender\'s own emails from the last 90 days', 'digital-employees' ); ?></label>
						<p class="form-hint"><?php echo esc_html__( 'Staff AI searches only the exact address that wrote to you, never the rest of your mailbox. Turn this off for faster, cheaper runs - drafts are then written from the message itself, plus that person\'s record in your CRM if one is connected.', 'digital-employees' ); ?></p>
					</div>
					<div class="schedule-status" id="scheduleStatus"></div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="modal-btn modal-btn-secondary" id="scheduleClose"><?php echo esc_html__( 'Close', 'digital-employees' ); ?></button>
					<button type="button" class="modal-btn modal-btn-primary" id="scheduleSave" style="display:none;"><?php echo esc_html__( 'Save schedule', 'digital-employees' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<script>
	var StaffAIConfig = {
		channel: <?php echo wp_json_encode( $channel ); ?>,
		userId: <?php echo wp_json_encode( (string) $user->ID ); ?>,
		userEmail: <?php echo wp_json_encode( $user->user_email ); ?>,
		apiBase: <?php echo wp_json_encode( $api_base ); ?>,
		nonce: <?php echo wp_json_encode( $nonce ); ?>,
		siteTimezone: <?php echo wp_json_encode( wp_timezone_string() ); ?>,
		homeUrl: <?php echo wp_json_encode( home_url( '/' ) ); ?>,
		chatStreamUrl: <?php echo wp_json_encode( rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai/chat/stream' ) ); ?>,
		statusUrl: <?php echo wp_json_encode( rest_url( DEF_CORE_API_NAME_SPACE . '/staff-ai/status' ) ); ?>,
		userName: <?php echo wp_json_encode( $first_name ); ?>,
		tips: <?php
			$tips = array(
				__( 'Did you know? I can summarise documents or extract information from uploaded files.', 'digital-employees' ),
				__( 'Did you know? You can drag and drop files directly into the chat to share them with me.', 'digital-employees' ),
				__( 'Did you know? I can create professional documents in Word, PDF, or Markdown format.', 'digital-employees' ),
				__( 'Did you know? I can generate spreadsheets from data you describe or provide.', 'digital-employees' ),
				__( 'Did you know? I can generate images and diagrams from your descriptions.', 'digital-employees' ),
				__( 'Did you know? I can help you brainstorm ideas, strategies, or solutions.', 'digital-employees' ),
				__( 'Did you know? I can help write or review code and technical documentation.', 'digital-employees' ),
				__( 'Did you know? I remember your preferences and project context across conversations.', 'digital-employees' ),
				__( 'Did you know? I can search the knowledge base to find answers for you.', 'digital-employees' ),
				__( 'Did you know? You can share any conversation with your team via email using the Share button.', 'digital-employees' ),
				__( 'Did you know? I can help structure processes, workflows, and frameworks.', 'digital-employees' ),
				__( 'Did you know? I can draft reports, policies, memos, and proposals.', 'digital-employees' ),
				__( 'Did you know? If I can\'t help, I\'ll offer to hand the conversation off to a colleague.', 'digital-employees' ),
			);
			if ( $woo_active ) {
				$tips[] = __( 'Did you know? I can search products and look up details for you.', 'digital-employees' );
				$tips[] = __( 'Did you know? I can look up customer orders and check order status.', 'digital-employees' );
			}
			if ( $is_manager ) {
				$tips[] = __( 'Did you know? I can access management-level documents and guidance.', 'digital-employees' );
				$tips[] = __( 'Did you know? I can help with planning, decision-making, and management-level analysis.', 'digital-employees' );
			}
			echo wp_json_encode( $tips );
		?>,
		upload: {
			allowedExtensions: <?php echo wp_json_encode( array(
				'.png', '.jpg', '.jpeg', '.gif', '.webp',
				'.pdf', '.txt', '.md', '.csv', '.docx', '.xlsx',
			) ); ?>
		},
		i18n: {
			failedToConnect: <?php echo wp_json_encode( __( 'Failed to connect to backend service.', 'digital-employees' ) ); ?>,
			checkStatus: <?php echo wp_json_encode( __( 'Check /wp-json/a3-ai/v1/staff-ai/status for diagnostics.', 'digital-employees' ) ); ?>,
			newConversation: <?php echo wp_json_encode( __( 'New conversation', 'digital-employees' ) ); ?>,
			failedToLoad: <?php echo wp_json_encode( __( 'Failed to load conversation.', 'digital-employees' ) ); ?>,
			sharedWith: <?php echo wp_json_encode( __( 'Shared with', 'digital-employees' ) ); ?>,
			shareFailed: <?php echo wp_json_encode( __( 'Share failed', 'digital-employees' ) ); ?>,
			internalHandoff: <?php echo wp_json_encode( __( 'Internal Handoff Suggested', 'digital-employees' ) ); ?>,
			shareHint: <?php echo wp_json_encode( __( 'Use the Share button to hand off this conversation to another team member.', 'digital-employees' ) ); ?>,
			download: <?php echo wp_json_encode( __( 'Download', 'digital-employees' ) ); ?>,
			file: <?php echo wp_json_encode( __( 'File', 'digital-employees' ) ); ?>,
			failedToSend: <?php echo wp_json_encode( __( 'Failed to send message. Please try again.', 'digital-employees' ) ); ?>,
			allRecipientsSelected: <?php echo wp_json_encode( __( 'All recipients selected', 'digital-employees' ) ); ?>,
			failedToPrepareShare: <?php echo wp_json_encode( __( 'Failed to prepare share form.', 'digital-employees' ) ); ?>,
			failedToSendShare: <?php echo wp_json_encode( __( 'Failed to send share email.', 'digital-employees' ) ); ?>,
			shareAttachLimit: <?php echo wp_json_encode( __( 'Selected documents exceed the 15MB attachment limit.', 'digital-employees' ) ); ?>,
			instructionsRequired: <?php echo wp_json_encode( __( 'Instructions are required.', 'digital-employees' ) ); ?>,
			dropFilesHere: <?php echo wp_json_encode( __( 'Drop files here', 'digital-employees' ) ); ?>,
			attachFile: <?php echo wp_json_encode( __( 'Attach file', 'digital-employees' ) ); ?>,
			unsupportedType: <?php echo wp_json_encode( __( 'Unsupported file type', 'digital-employees' ) ); ?>,
			retrySuffix: <?php /* translators: %d: seconds until the rate limit window reopens. */ echo wp_json_encode( __( '(retry in %ds)', 'digital-employees' ) ); ?>,
			uploadFailed: <?php echo wp_json_encode( __( 'Upload failed', 'digital-employees' ) ); ?>,
			uploadReadFailed: <?php echo wp_json_encode( __( 'Could not read the file. Please remove it, re-select it and try again.', 'digital-employees' ) ); ?>,
			stillWorking: <?php echo wp_json_encode( __( 'Your assistant is still working on this — reopen the chat in a minute to see the reply.', 'digital-employees' ) ); ?>,
			micStart: <?php echo wp_json_encode( __( 'Speak', 'digital-employees' ) ); ?>,
			micDenied: <?php echo wp_json_encode( __( 'The microphone is blocked for this site in your browser. Allow it in the site permissions (the icon beside the address bar) and try again.', 'digital-employees' ) ); ?>,
			micNotFound: <?php echo wp_json_encode( __( 'No microphone was found on this device.', 'digital-employees' ) ); ?>,
			micFailed: <?php echo wp_json_encode( __( "The microphone couldn't start (%e).", 'digital-employees' ) ); ?>,
			micBlockedBySite: <?php echo wp_json_encode( __( "This site's security settings block the microphone for every visitor (Permissions-Policy). The site admin needs to allow it for this site.", 'digital-employees' ) ); ?>,
			nothingHeard: <?php echo wp_json_encode( __( 'Nothing was heard. Try again a little closer to the microphone.', 'digital-employees' ) ); ?>,
			transcribeFailed: <?php echo wp_json_encode( __( 'That recording could not be transcribed. Please try again.', 'digital-employees' ) ); ?>,
			tapToSend: <?php echo wp_json_encode( __( 'Tap to send', 'digital-employees' ) ); ?>,
			listening: <?php echo wp_json_encode( __( 'Listening… pause when you\'re done, or tap to send', 'digital-employees' ) ); ?>,
			transcribing: <?php echo wp_json_encode( __( 'Transcribing…', 'digital-employees' ) ); ?>,
			answering: <?php echo wp_json_encode( __( '%s is answering · tap to end', 'digital-employees' ) ); ?>,
			assistant: <?php echo wp_json_encode( __( 'Your assistant', 'digital-employees' ) ); ?>,
			voiceEmployee: <?php echo wp_json_encode( __( "Reading back in %s's voice", 'digital-employees' ) ); ?>,
			voiceAssistant: <?php echo wp_json_encode( __( "Reading back in your assistant's voice", 'digital-employees' ) ); ?>,
			voiceDevice: <?php echo wp_json_encode( __( 'Reading back with your device voice', 'digital-employees' ) ); ?>,
			voiceOff: <?php echo wp_json_encode( __( 'Voice off', 'digital-employees' ) ); ?>,
			voiceNeedsStreaming: <?php echo wp_json_encode( __( 'Voice needs a browser that can stream replies.', 'digital-employees' ) ); ?>,
			tapToSpeakAgain: <?php echo wp_json_encode( __( 'Tap the mic to speak again.', 'digital-employees' ) ); ?>,
			micClosedIdle: <?php echo wp_json_encode( __( 'The mic closed — tap it to speak again.', 'digital-employees' ) ); ?>,
			voiceLogEmpty: <?php echo wp_json_encode( __( 'No voice events yet.', 'digital-employees' ) ); ?>,
			voicePlaybackFailed: <?php echo wp_json_encode( __( "Couldn't play %s's voice on this device (%e).", 'digital-employees' ) ); ?>,
			spoken: <?php echo wp_json_encode( __( 'Spoken', 'digital-employees' ) ); ?>,
			removeFailedFiles: <?php echo wp_json_encode( __( 'Some files failed to upload. Remove failed files and try again.', 'digital-employees' ) ); ?>,
			analyzingFiles: <?php echo wp_json_encode( __( 'Analyzing files...', 'digital-employees' ) ); ?>,
			documentsLoading: <?php echo wp_json_encode( __( 'Loading your documents…', 'digital-employees' ) ); ?>,
			documentsEmpty: <?php echo wp_json_encode( __( 'No documents yet.', 'digital-employees' ) ); ?>,
			documentsAsk: <?php echo wp_json_encode( __( 'Ask your assistant to create a document', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name, e.g. Sue. */ ?>
			documentsAskNamed: <?php echo wp_json_encode( __( 'Ask %s to create a document', 'digital-employees' ) ); ?>,
			documentsAskPrompt: <?php echo wp_json_encode( __( 'Create a document for me — ask me what it should cover, then write it and save it to my documents.', 'digital-employees' ) ); ?>,
			documentsLoadFailed: <?php echo wp_json_encode( __( 'Could not load your documents.', 'digital-employees' ) ); ?>,
			documentsDelete: <?php echo wp_json_encode( __( 'Delete', 'digital-employees' ) ); ?>,
			documentsConfirmDelete: <?php /* translators: %s: document title. */ echo wp_json_encode( __( 'Delete "%s"? This permanently removes it from your library.', 'digital-employees' ) ); ?>,
			documentsDeleteFailed: <?php echo wp_json_encode( __( 'Could not delete the document.', 'digital-employees' ) ); ?>,
			documentsNoMatches: <?php echo wp_json_encode( __( 'No documents match your search.', 'digital-employees' ) ); ?>,
			memoriesLoading: <?php echo wp_json_encode( __( 'Loading what Staff AI remembers…', 'digital-employees' ) ); ?>,
			memoriesEmpty: <?php echo wp_json_encode( __( 'Staff AI has not noted anything about you yet.', 'digital-employees' ) ); ?>,
			memoriesLoadFailed: <?php echo wp_json_encode( __( 'Could not load what Staff AI remembers. Nothing has been forgotten — try again in a moment.', 'digital-employees' ) ); ?>,
			memoriesDelete: <?php echo wp_json_encode( __( 'Delete', 'digital-employees' ) ); ?>,
			memoriesConfirmDelete: <?php /* translators: %s: the remembered fact. */ echo wp_json_encode( __( 'Delete "%s"? It is removed now, but can be noted again from conversations you still have.', 'digital-employees' ) ); ?>,
			memoriesDeleteFailed: <?php echo wp_json_encode( __( 'Could not delete that memory.', 'digital-employees' ) ); ?>,
			memoriesAsk: <?php echo wp_json_encode( __( 'Ask how Memories work', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name. */ ?>
			memoriesAskNamed: <?php echo wp_json_encode( __( 'Ask %s how Memories work', 'digital-employees' ) ); ?>,
			memoriesAskPrompt: <?php echo wp_json_encode( __( 'What do you remember about me, how do you decide what to note, and how do I stop something coming back after I delete it?', 'digital-employees' ) ); ?>,
			memoryCategoryRole: <?php echo wp_json_encode( __( 'Your role', 'digital-employees' ) ); ?>,
			memoryCategoryPreferences: <?php echo wp_json_encode( __( 'Preference', 'digital-employees' ) ); ?>,
			memoryCategoryProjects: <?php echo wp_json_encode( __( 'Project', 'digital-employees' ) ); ?>,
			memoryCategoryHabits: <?php echo wp_json_encode( __( 'How you work', 'digital-employees' ) ); ?>,
			memoryCategoryContext: <?php echo wp_json_encode( __( 'Background', 'digital-employees' ) ); ?>,
			usageLoading: <?php echo wp_json_encode( __( 'Loading your usage…', 'digital-employees' ) ); ?>,
			usageLoadFailed: <?php echo wp_json_encode( __( 'Could not load your usage. Nothing has changed — try again in a moment.', 'digital-employees' ) ); ?>,
			usageEmpty: <?php echo wp_json_encode( __( 'No usage yet this week.', 'digital-employees' ) ); ?>,
			usageResets: <?php /* translators: %s: date and time the weekly limit resets, in the reader's local time. */ echo wp_json_encode( __( 'Resets %s', 'digital-employees' ) ); ?>,
			usageAllModels: <?php echo wp_json_encode( __( 'All models', 'digital-employees' ) ); ?>,
			usageNoBudget: <?php echo wp_json_encode( __( 'no budget — unlimited', 'digital-employees' ) ); ?>,
			usageBudgetChecking: <?php echo wp_json_encode( __( 'checking…', 'digital-employees' ) ); ?>,
			usageOfBudget: <?php /* translators: %s: percentage of the weekly budget used. */ echo wp_json_encode( __( '%s of your weekly budget', 'digital-employees' ) ); ?>,
			usageShareOfUsage: <?php /* translators: %s: percentage of this week's tokens spent on one model. */ echo wp_json_encode( __( '%s of your usage', 'digital-employees' ) ); ?>,
			usageTokens: <?php /* translators: %s: formatted token count. */ echo wp_json_encode( __( '%s tokens', 'digital-employees' ) ); ?>,
			usageAsk: <?php echo wp_json_encode( __( 'Ask how Usage works', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name. */ ?>
			usageAskNamed: <?php echo wp_json_encode( __( 'Ask %s how Usage works', 'digital-employees' ) ); ?>,
			usageAskPrompt: <?php echo wp_json_encode( __( 'Explain my weekly limits — what counts toward the budget, what the two bars are telling me, and how I can get more done inside it.', 'digital-employees' ) ); ?>,
			scheduleLoading: <?php echo wp_json_encode( __( 'Loading your schedule…', 'digital-employees' ) ); ?>,
			scheduleLoadFailed: <?php echo wp_json_encode( __( 'Could not load your triage schedule. Nothing has changed - try again in a moment.', 'digital-employees' ) ); ?>,
			scheduleSaving: <?php echo wp_json_encode( __( 'Saving…', 'digital-employees' ) ); ?>,
			scheduleSaved: <?php echo wp_json_encode( __( 'Saved. Your digest follows this schedule from its next send time.', 'digital-employees' ) ); ?>,
			scheduleSaveFailed: <?php echo wp_json_encode( __( 'Could not save your triage schedule. Your previous settings are unchanged.', 'digital-employees' ) ); ?>,
			scheduleNeedDestination: <?php echo wp_json_encode( __( 'Pick at least one destination for your digest.', 'digital-employees' ) ); ?>,
			scheduleRunning: <?php echo wp_json_encode( __( 'Asking for a run…', 'digital-employees' ) ); ?>,
			scheduleRunQueued: <?php echo wp_json_encode( __( 'Triage will run shortly. Your digest arrives the same way your daily one does.', 'digital-employees' ) ); ?>,
			scheduleRunFailed: <?php echo wp_json_encode( __( 'Could not start a triage run. Your schedule is unchanged - try again in a moment.', 'digital-employees' ) ); ?>,
			scheduleEditTitle: <?php echo wp_json_encode( __( 'Email triage schedule', 'digital-employees' ) ); ?>,
			taskTriageName: <?php echo wp_json_encode( __( 'Email Triage', 'digital-employees' ) ); ?>,
			taskTriageDesc: <?php echo wp_json_encode( __( 'Reads your mailbox, drafts routine replies, and sends you a digest of what needs attention.', 'digital-employees' ) ); ?>,
			/* translators: %s is a time of day, e.g. 7:00 AM */
			taskEveryDay: <?php echo wp_json_encode( __( 'Every day at ~%s', 'digital-employees' ) ); ?>,
			/* translators: %s is minutes past the hour, e.g. 30 */
			taskEveryHour: <?php echo wp_json_encode( __( 'Every hour at ~:%s', 'digital-employees' ) ); ?>,
			/* translators: %s is a time of day, e.g. 7:00 AM */
			taskWeekdaysAt: <?php echo wp_json_encode( __( 'Weekdays at ~%s', 'digital-employees' ) ); ?>,
			/* translators: %1$s is a weekday name, %2$s is a time of day */
			taskWeeklyAt: <?php echo wp_json_encode( __( 'Every %1$s at ~%2$s', 'digital-employees' ) ); ?>,
			taskManualOnly: <?php echo wp_json_encode( __( 'Runs when you press Run now', 'digital-employees' ) ); ?>,
			taskHintManual: <?php echo wp_json_encode( __( 'This task never runs on a schedule. Use its Run now button whenever you want it.', 'digital-employees' ) ); ?>,
			taskEnabledLabel: <?php echo wp_json_encode( __( 'Run this task on its schedule', 'digital-employees' ) ); ?>,
			taskEnabledManualLabel: <?php echo wp_json_encode( __( 'Task is active - Run now only works while this is on', 'digital-employees' ) ); ?>,
			taskHintHourly: <?php echo wp_json_encode( __( 'Runs every hour, at the send time\'s minutes past the hour.', 'digital-employees' ) ); ?>,
			taskHintWeekdays: <?php echo wp_json_encode( __( 'Runs Monday to Friday at the send time.', 'digital-employees' ) ); ?>,
			taskHintWeekly: <?php echo wp_json_encode( __( 'Runs once a week, on the day you choose.', 'digital-employees' ) ); ?>,
			taskPaused: <?php echo wp_json_encode( __( 'Not scheduled', 'digital-employees' ) ); ?>,
			/* translators: %s is a run outcome, e.g. Succeeded */
			taskLastRun: <?php echo wp_json_encode( __( 'Last run: %s', 'digital-employees' ) ); ?>,
			runStatusRunning: <?php echo wp_json_encode( __( 'Running', 'digital-employees' ) ); ?>,
			runStatusSucceeded: <?php echo wp_json_encode( __( 'Succeeded', 'digital-employees' ) ); ?>,
			runStatusNothing: <?php echo wp_json_encode( __( 'Nothing to do', 'digital-employees' ) ); ?>,
			runStatusFailed: <?php echo wp_json_encode( __( 'Failed', 'digital-employees' ) ); ?>,
			runStatusSkipped: <?php echo wp_json_encode( __( 'Skipped', 'digital-employees' ) ); ?>,
			runStatusExpired: <?php echo wp_json_encode( __( 'Did not finish', 'digital-employees' ) ); ?>,
			taskRunNow: <?php echo wp_json_encode( __( 'Run now', 'digital-employees' ) ); ?>,
			taskEdit: <?php echo wp_json_encode( __( 'Edit', 'digital-employees' ) ); ?>,
			taskDelete: <?php echo wp_json_encode( __( 'Remove', 'digital-employees' ) ); ?>,
			taskDeleteConfirm: <?php echo wp_json_encode( __( 'Remove Email Triage? Your mailbox stays connected.', 'digital-employees' ) ); ?>,
			taskDeleting: <?php echo wp_json_encode( __( 'Removing…', 'digital-employees' ) ); ?>,
			taskDeleted: <?php echo wp_json_encode( __( 'Email Triage removed. Nothing is scheduled for you now.', 'digital-employees' ) ); ?>,
			taskDeleteFailed: <?php echo wp_json_encode( __( 'Could not remove your Email Triage setup. Nothing has changed - try again in a moment.', 'digital-employees' ) ); ?>,
			taskFormTitleNew: <?php echo wp_json_encode( __( 'New scheduled task', 'digital-employees' ) ); ?>,
			taskFormTitleEdit: <?php echo wp_json_encode( __( 'Edit scheduled task', 'digital-employees' ) ); ?>,
			tasksLoadFailed: <?php echo wp_json_encode( __( 'Could not load your scheduled tasks. Nothing has changed - try again in a moment.', 'digital-employees' ) ); ?>,
			taskNeedName: <?php echo wp_json_encode( __( 'Give the task a name.', 'digital-employees' ) ); ?>,
			taskNeedInstruction: <?php echo wp_json_encode( __( 'Tell Staff AI what the task should do.', 'digital-employees' ) ); ?>,
			taskSaved: <?php echo wp_json_encode( __( 'Saved. Your task follows its schedule from the next send time.', 'digital-employees' ) ); ?>,
			taskSavedManual: <?php echo wp_json_encode( __( 'Saved. Run it any time with its Run now button.', 'digital-employees' ) ); ?>,
			taskSaveFailed: <?php echo wp_json_encode( __( 'Could not save your task. Nothing has changed - try again in a moment.', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the task's name. */ ?>
			taskConfirmDeleteNamed: <?php echo wp_json_encode( __( 'Remove "%s"? Its schedule stops now; past run history is kept.', 'digital-employees' ) ); ?>,
			taskDeletedNamed: <?php echo wp_json_encode( __( 'Task removed. Its schedule has stopped.', 'digital-employees' ) ); ?>,
			taskRunQueued: <?php echo wp_json_encode( __( 'The task will run shortly. Its output arrives at the destinations you chose.', 'digital-employees' ) ); ?>,
			taskRunFailed: <?php echo wp_json_encode( __( 'Could not start the task. Its schedule is unchanged - try again in a moment.', 'digital-employees' ) ); ?>,
			taskRemoveFailed: <?php echo wp_json_encode( __( 'Could not remove your task. Nothing has changed - try again in a moment.', 'digital-employees' ) ); ?>,
			taskRunQueuedCard: <?php echo wp_json_encode( __( 'Queued — starts on the next run cycle', 'digital-employees' ) ); ?>,
			taskRunRunningNow: <?php echo wp_json_encode( __( 'Running now…', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the WordPress account email a run's output is sent to. */ ?>
			runStatusSentTo: <?php echo wp_json_encode( __( 'Sent to %s', 'digital-employees' ) ); ?>,
			taskDiscardForAsk: <?php echo wp_json_encode( __( 'Leave this task and open a chat? What you have typed here is not kept.', 'digital-employees' ) ); ?>,
			scheduleResultsAsk: <?php echo wp_json_encode( __( 'Where do results go? Ask your assistant', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name, e.g. Sue. */ ?>
			scheduleResultsAskNamed: <?php echo wp_json_encode( __( 'Where do results go? Ask %s', 'digital-employees' ) ); ?>,
			scheduleResultsAskPrompt: <?php echo wp_json_encode( __( 'Where do the results of my scheduled tasks go, and how do I change it?', 'digital-employees' ) ); ?>,
			projectsAsk: <?php echo wp_json_encode( __( 'Ask how Projects work', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name, e.g. Sue. */ ?>
			projectsAskNamed: <?php echo wp_json_encode( __( 'Ask %s how Projects work', 'digital-employees' ) ); ?>,
			projectsAskPrompt: <?php echo wp_json_encode( __( 'Walk me through creating and managing a Project, step by step — and create one for me when I\'m ready.', 'digital-employees' ) ); ?>,
			projectsYourAssistant: <?php echo wp_json_encode( __( 'your assistant', 'digital-employees' ) ); ?>,
			scheduledAsk: <?php echo wp_json_encode( __( 'Ask how Scheduled Tasks work', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name, e.g. Sue. */ ?>
			scheduledAskNamed: <?php echo wp_json_encode( __( 'Ask %s how Scheduled Tasks work', 'digital-employees' ) ); ?>,
			scheduledAskPrompt: <?php echo wp_json_encode( __( 'Walk me through how Scheduled Tasks work — the schedules I can choose, and custom tasks with examples of how I could use them — then set one up for me when I\'m ready.', 'digital-employees' ) ); ?>,
			documentsMoveProject: <?php echo wp_json_encode( __( 'Move to project…', 'digital-employees' ) ); ?>,
			documentsUndated: <?php echo wp_json_encode( __( 'Undated', 'digital-employees' ) ); ?>,
			/* D-C10 (7.8.1): every t( 'key' ) the console uses has its entry here.
			   A key that only ever had a JS fallback shipped English and no language
			   file could carry it; tests/test-staff-ai-i18n-coverage.php now fails the
			   build if one is added without an entry. The JS defaults stay as the
			   fallback they are. */
			chatOptions: <?php echo wp_json_encode( __( 'Chat options', 'digital-employees' ) ); ?>,
			chatRename: <?php echo wp_json_encode( __( 'Rename', 'digital-employees' ) ); ?>,
			chatRenamePrompt: <?php echo wp_json_encode( __( 'New chat name:', 'digital-employees' ) ); ?>,
			chatRenameFailed: <?php echo wp_json_encode( __( 'Could not rename the chat.', 'digital-employees' ) ); ?>,
			chatAddToProject: <?php echo wp_json_encode( __( 'Add to project…', 'digital-employees' ) ); ?>,
			chatDelete: <?php echo wp_json_encode( __( 'Delete', 'digital-employees' ) ); ?>,
			chatConfirmDelete: <?php echo wp_json_encode( __( 'Delete this chat? It disappears from your list now and is permanently forgotten a month later.', 'digital-employees' ) ); ?>,
			chatDeleteFailed: <?php echo wp_json_encode( __( 'Could not delete the chat.', 'digital-employees' ) ); ?>,
			chatPickProject: <?php echo wp_json_encode( __( 'Move to project', 'digital-employees' ) ); ?>,
			projectsLoadFailed: <?php echo wp_json_encode( __( 'Could not load your projects.', 'digital-employees' ) ); ?>,
			chatMoveFailed: <?php echo wp_json_encode( __( 'Could not move the chat.', 'digital-employees' ) ); ?>,
			chatRemoveFromProject: <?php echo wp_json_encode( __( 'Remove from project', 'digital-employees' ) ); ?>,
			chatNoProjects: <?php echo wp_json_encode( __( 'No projects yet — create one from the Projects page.', 'digital-employees' ) ); ?>,
			uploadTimeout: <?php echo wp_json_encode( __( 'Upload timed out. Please try again.', 'digital-employees' ) ); ?>,
			analyzeFiles: <?php echo wp_json_encode( __( 'Please analyze the attached file(s).', 'digital-employees' ) ); ?>,
			integrationsLoading: <?php echo wp_json_encode( __( 'Loading your connected accounts…', 'digital-employees' ) ); ?>,
			integrationsNotConfigured: <?php echo wp_json_encode( __( 'Integrations aren’t set up for your team yet. Ask an administrator to connect apps.', 'digital-employees' ) ); ?>,
			integrationsEmpty: <?php echo wp_json_encode( __( 'No connected apps yet. Ask an administrator to add integrations.', 'digital-employees' ) ); ?>,
			integrationsLoadFailed: <?php echo wp_json_encode( __( 'Could not load your connected accounts.', 'digital-employees' ) ); ?>,
			primaryMailboxLabel: <?php echo wp_json_encode( __( 'Primary for chat:', 'digital-employees' ) ); ?>,
			accountDisconnect: <?php echo wp_json_encode( __( 'Disconnect', 'digital-employees' ) ); ?>,
			primaryMailboxClear: <?php echo wp_json_encode( __( 'Clear', 'digital-employees' ) ); ?>,
			accountConnectAnother: <?php echo wp_json_encode( __( 'Connect another account', 'digital-employees' ) ); ?>,
			integrationsStarting: <?php echo wp_json_encode( __( 'Starting the connection…', 'digital-employees' ) ); ?>,
			integrationsFinish: <?php echo wp_json_encode( __( 'Finish connecting →', 'digital-employees' ) ); ?>,
			integrationsFinishDismiss: <?php echo wp_json_encode( __( 'Dismiss “Finish connecting”', 'digital-employees' ) ); ?>,
			integrationsAwaiting: <?php echo wp_json_encode( __( 'Click “Finish connecting”, approve access in the new tab, then return here — I’ll refresh automatically.', 'digital-employees' ) ); ?>,
			integrationsNoLink: <?php echo wp_json_encode( __( 'Could not start the connection. Please try again.', 'digital-employees' ) ); ?>,
			integrationsConnectFailed: <?php echo wp_json_encode( __( 'Could not start the connection.', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the account's name, e.g. an email address. */ ?>
			accountDisconnectConfirm: <?php echo wp_json_encode( __( 'Disconnect %s? This ends access to that account only — the app stays connected, and you can connect it again later.', 'digital-employees' ) ); ?>,
			integrationsDisconnecting: <?php echo wp_json_encode( __( 'Disconnecting…', 'digital-employees' ) ); ?>,
			accountDisconnectFailed: <?php echo wp_json_encode( __( 'That account could not be disconnected — it is unchanged.', 'digital-employees' ) ); ?>,
			accountDisconnected: <?php echo wp_json_encode( __( 'Account disconnected.', 'digital-employees' ) ); ?>,
			primaryMailboxSaving: <?php echo wp_json_encode( __( 'Saving your primary mailbox…', 'digital-employees' ) ); ?>,
			primaryMailboxSaved: <?php echo wp_json_encode( __( 'Primary mailbox saved.', 'digital-employees' ) ); ?>,
			primaryMailboxFailed: <?php echo wp_json_encode( __( 'Could not save your primary mailbox.', 'digital-employees' ) ); ?>,
			primaryMailboxClearing: <?php echo wp_json_encode( __( 'Clearing…', 'digital-employees' ) ); ?>,
			primaryMailboxCleared: <?php echo wp_json_encode( __( 'Primary cleared - chat uses the default account again.', 'digital-employees' ) ); ?>,
			integrationsReady: <?php echo wp_json_encode( __( 'Ready', 'digital-employees' ) ); ?>,
			integrationsConnected: <?php echo wp_json_encode( __( 'Connected', 'digital-employees' ) ); ?>,
			integrationsConnect: <?php echo wp_json_encode( __( 'Connect', 'digital-employees' ) ); ?>,
			integrationsDisconnect: <?php echo wp_json_encode( __( 'Disconnect', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the app’s name, e.g. Google Drive (used twice). */ ?>
			integrationsDisconnectConfirm: <?php echo wp_json_encode( __( 'Disconnect %s? This ends your own access. Your team’s connection to %s stays, and you can connect again later.', 'digital-employees' ) ); ?>,
			integrationsDisconnectPartial: <?php echo wp_json_encode( __( 'Some of your access could not be ended. Try again, or ask an administrator.', 'digital-employees' ) ); ?>,
			integrationsNothingToEnd: <?php echo wp_json_encode( __( 'No live connection to this app was found under the name we have for it. If you still have access, remove it in your account settings for that app, or ask an administrator.', 'digital-employees' ) ); ?>,
			integrationsDisconnected: <?php echo wp_json_encode( __( 'Disconnected. Ending access with the provider can take a moment.', 'digital-employees' ) ); ?>,
			integrationsDisconnectFailed: <?php echo wp_json_encode( __( 'Could not disconnect that app.', 'digital-employees' ) ); ?>,
			connectionsAsk: <?php echo wp_json_encode( __( 'Ask how Connections work', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name. */ ?>
			connectionsAskNamed: <?php echo wp_json_encode( __( 'Ask %s how Connections work', 'digital-employees' ) ); ?>,
			connectionsAskPrompt: <?php echo wp_json_encode( __( 'Walk me through connecting my own accounts — what connecting one lets you do on my behalf, what a primary mailbox is for, and how I disconnect one later.', 'digital-employees' ) ); ?>,
			projectChipLabel: <?php echo wp_json_encode( __( 'Project: ', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the document's length in characters. */ ?>
			documentViewerChars: <?php echo wp_json_encode( __( '%s characters', 'digital-employees' ) ); ?>,
			documentViewerFailed: <?php echo wp_json_encode( __( 'Could not read the document.', 'digital-employees' ) ); ?>,
			documentViewerTitle: <?php echo wp_json_encode( __( 'Document', 'digital-employees' ) ); ?>,
			documentViewerLoading: <?php echo wp_json_encode( __( 'Loading…', 'digital-employees' ) ); ?>,
			documentsAllProjects: <?php echo wp_json_encode( __( 'All documents', 'digital-employees' ) ); ?>,
			projectsArchived: <?php echo wp_json_encode( __( 'Archived', 'digital-employees' ) ); ?>,
			documentsOtherOnly: <?php echo wp_json_encode( __( 'Other documents only — the runsheet, session notes and instructions are on the project card.', 'digital-employees' ) ); ?>,
			documentsView: <?php echo wp_json_encode( __( 'View', 'digital-employees' ) ); ?>,
			documentsNoProject: <?php echo wp_json_encode( __( 'No project', 'digital-employees' ) ); ?>,
			documentsSlotNone: <?php echo wp_json_encode( __( 'Ordinary document', 'digital-employees' ) ); ?>,
			documentsSlotInstructions: <?php echo wp_json_encode( __( 'Instructions', 'digital-employees' ) ); ?>,
			documentsSlotRunsheet: <?php echo wp_json_encode( __( 'Runsheet', 'digital-employees' ) ); ?>,
			documentsSlotSessionNotes: <?php echo wp_json_encode( __( 'Session notes', 'digital-employees' ) ); ?>,
			save: <?php echo wp_json_encode( __( 'Save', 'digital-employees' ) ); ?>,
			documentsMoveFailed: <?php echo wp_json_encode( __( 'Could not move the document.', 'digital-employees' ) ); ?>,
			projectsTasksCheckFailed: <?php echo wp_json_encode( __( 'Could not check the tasks bound to this project.', 'digital-employees' ) ); ?>,
			<?php /* translators: %n: how many tasks; %p: the project name; %t: the task names. */ ?>
			projectsBoundTasksDisable: <?php echo wp_json_encode( __( "%n scheduled task(s) run inside \"%p\": %t.\n\nDisable them? OK = disable them. Cancel = keep them running WITHOUT the project's documents.", 'digital-employees' ) ); ?>,
			projectsBoundTasksUnbind: <?php echo wp_json_encode( __( 'Remove the project from those tasks? OK = they run on with no project. Cancel = leave them bound (they run without the archived project\'s documents until you change them).', 'digital-employees' ) ); ?>,
			projectsLoading: <?php echo wp_json_encode( __( 'Loading your projects…', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the assistant's name, e.g. Sue. */ ?>
			projectsEmpty: <?php echo wp_json_encode( __( 'No projects yet. Ask %s how Projects work, or use Create project above.', 'digital-employees' ) ); ?>,
			projectsActive: <?php echo wp_json_encode( __( 'Active', 'digital-employees' ) ); ?>,
			projectsOpen: <?php echo wp_json_encode( __( 'Open Project', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the project name. */ ?>
			projectsConfirmRestoreOpen: <?php echo wp_json_encode( __( 'Restore "%s" and open it? It moves back to your active projects.', 'digital-employees' ) ); ?>,
			projectsManage: <?php echo wp_json_encode( __( 'Manage project', 'digital-employees' ) ); ?>,
			projectsKnowledge: <?php echo wp_json_encode( __( 'Project knowledge', 'digital-employees' ) ); ?>,
			projectsSlotsLoading: <?php echo wp_json_encode( __( 'Loading…', 'digital-employees' ) ); ?>,
			projectsDocCountOne: <?php echo wp_json_encode( __( '1 document', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: how many documents. */ ?>
			projectsDocCount: <?php echo wp_json_encode( __( '%s documents', 'digital-employees' ) ); ?>,
			projectsSlotRunsheet: <?php echo wp_json_encode( __( 'Runsheet', 'digital-employees' ) ); ?>,
			projectsSlotSessionNotes: <?php echo wp_json_encode( __( 'Session notes', 'digital-employees' ) ); ?>,
			projectsSlotInstructions: <?php echo wp_json_encode( __( 'Instructions', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the document's version number. */ ?>
			projectsSlotVersion: <?php echo wp_json_encode( __( 'v%s', 'digital-employees' ) ); ?>,
			projectsSlotNotSet: <?php echo wp_json_encode( __( 'Not set — add', 'digital-employees' ) ); ?>,
			projectsOtherDocs: <?php echo wp_json_encode( __( 'Other documents', 'digital-employees' ) ); ?>,
			projectsFileCountOne: <?php echo wp_json_encode( __( '1 file', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: how many files. */ ?>
			projectsFileCount: <?php echo wp_json_encode( __( '%s files', 'digital-employees' ) ); ?>,
			projectsRename: <?php echo wp_json_encode( __( 'Rename', 'digital-employees' ) ); ?>,
			projectsRestore: <?php echo wp_json_encode( __( 'Restore', 'digital-employees' ) ); ?>,
			projectsArchive: <?php echo wp_json_encode( __( 'Archive', 'digital-employees' ) ); ?>,
			projectsDelete: <?php echo wp_json_encode( __( 'Delete', 'digital-employees' ) ); ?>,
			projectsBusy: <?php echo wp_json_encode( __( 'One change at a time…', 'digital-employees' ) ); ?>,
			projectsRenamePrompt: <?php echo wp_json_encode( __( 'New project name:', 'digital-employees' ) ); ?>,
			projectsSaveFailed: <?php echo wp_json_encode( __( 'Could not save the project.', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the project name. */ ?>
			projectsConfirmDelete: <?php echo wp_json_encode( __( 'Delete "%s"? Its documents are NOT deleted — they stay in your library.', 'digital-employees' ) ); ?>,
			projectsDeleteFailed: <?php echo wp_json_encode( __( 'Could not delete the project.', 'digital-employees' ) ); ?>,
			projectsCreateFailed: <?php echo wp_json_encode( __( 'Could not create the project.', 'digital-employees' ) ); ?>,
			<?php /* translators: %s: the project name. */ ?>
			taskProjectBadge: <?php echo wp_json_encode( __( 'Project: %s', 'digital-employees' ) ); ?>,
			taskProjectUnknown: <?php echo wp_json_encode( __( '(project)', 'digital-employees' ) ); ?>,
			taskNoProject: <?php echo wp_json_encode( __( 'No project', 'digital-employees' ) ); ?>,
			taskProjectArchived: <?php echo wp_json_encode( __( '(archived)', 'digital-employees' ) ); ?>,
			scheduleNewTitle: <?php echo wp_json_encode( __( 'New Email Triage schedule', 'digital-employees' ) ); ?>
		}
	};
	</script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/vendor/marked.min.js' ); ?>?ver=15.0.12"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/vendor/purify.min.js' ); ?>?ver=3.1.6"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/def-persona.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/def-core-product-cards.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/def-core-voice.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
	<script src="<?php echo esc_url( DEF_CORE_PLUGIN_URL . 'assets/js/staff-ai.js' ); ?>?ver=<?php echo esc_attr( DEF_CORE_VERSION ); ?>"></script>
</body>
</html>
