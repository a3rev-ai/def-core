<?php
/**
 * Class DEF_Core_Chat_Button_Block
 *
 * The "Chat Button" editor block (8.7.0): a button that opens the Customer Chat,
 * optionally with a question sent as the visitor's first message. It renders the
 * core button markup (`wp-block-button` / `wp-block-button__link wp-element-button`)
 * so the theme's button styling applies, and carries the `data-def-chat-trigger` /
 * `data-def-chat-prompt` attributes the loader already listens for (8.5.0). The
 * block is server-rendered, so the editor never holds — and can never strip — those
 * attributes, which is what a core Button block did on its next save. The core
 * Button block itself is untouched; this block has its own inserter category.
 *
 * @package def-core
 * @since 8.7.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Chat Button block: registration, inserter category and front-end render.
 */
final class DEF_Core_Chat_Button_Block {

	const NAME     = 'def-core/chat-button';
	const CATEGORY = 'digital-employees';
	const SCRIPT   = 'def-core-chat-button-block';

	/**
	 * Hook the registration and the inserter category.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'add_category' ) );
	}

	/**
	 * The block's own inserter category, so it never sits among the core buttons.
	 *
	 * @param array $categories Registered block categories.
	 * @return array
	 */
	public static function add_category( array $categories ): array {
		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Digital Employees', 'digital-employees' ),
		);
		return $categories;
	}

	/**
	 * Register the editor script and the block (server-rendered; the editor gets
	 * title, category, icon and attributes from this registration).
	 */
	public static function register(): void {
		wp_register_script(
			self::SCRIPT,
			DEF_CORE_PLUGIN_URL . 'assets/js/def-core-chat-button-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			DEF_CORE_VERSION,
			true
		);
		// The editor preview shows the same label the front end falls back to.
		wp_localize_script( self::SCRIPT, 'DefCoreChatButtonBlock', array( 'label' => self::default_label() ) );

		register_block_type(
			self::NAME,
			array(
				'api_version'           => 3,
				'title'                 => __( 'Chat Button', 'digital-employees' ),
				'description'           => __( 'A button that opens the chat, with a question ready to ask.', 'digital-employees' ),
				'category'              => self::CATEGORY,
				'icon'                  => 'format-chat',
				'keywords'              => array( 'chat', 'ask', 'assistant', 'question' ),
				'attributes'            => array(
					'label'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'prompt' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'              => array( 'html' => false ),
				// Core's button stylesheet: on a block theme it loads only when a core Button
				// is on the page, so a page with just this block would lose inline-block + padding.
				'style_handles'         => array( 'wp-block-button' ),
				'editor_script_handles' => array( self::SCRIPT ),
				'render_callback'       => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Front-end markup. Core button classes for the theme's styling; the loader's
	 * trigger attributes for the behaviour; a <button>, as core renders a Button with
	 * no destination, so nothing happens if the loader is not there. A blank label falls back to the
	 * Chat Settings button label, as the shortcode does; a blank question opens the
	 * chat with nothing sent.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		$label  = trim( (string) ( $attributes['label'] ?? '' ) );
		$prompt = trim( (string) ( $attributes['prompt'] ?? '' ) );

		$prompt_attr = '' !== $prompt ? ' data-def-chat-prompt="' . esc_attr( $prompt ) . '"' : '';

		return sprintf(
			'<div %s><button type="button" class="wp-block-button__link wp-element-button" data-def-chat-trigger%s>%s</button></div>',
			get_block_wrapper_attributes( array( 'class' => 'wp-block-button' ) ),
			$prompt_attr,
			esc_html( '' !== $label ? $label : self::default_label() )
		);
	}

	/**
	 * The label when the block's is blank: the Chat Settings button label, else "Chat".
	 *
	 * @return string
	 */
	private static function default_label(): string {
		$label = trim( (string) get_option( 'def_core_chat_button_label', '' ) );
		return '' !== $label ? $label : __( 'Chat', 'digital-employees' );
	}
}
