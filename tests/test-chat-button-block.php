<?php
/**
 * Tests for DEF_Core_Chat_Button_Block::render() — the Chat Button block's front-end
 * markup (8.7.0): core button classes for the theme's styling, the loader's trigger
 * attributes for the behaviour, the shortcode's label fallback, everything escaped.
 */

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( array $extra = array() ): string {
		return 'class="' . esc_attr( $extra['class'] ?? '' ) . '"';
	}
}

require_once __DIR__ . '/../includes/class-def-core-chat-button-block.php';

$passed = 0;
$failed = 0;
$errors = array();

function assert_test( bool $condition, string $name ): void {
	global $passed, $failed, $errors;
	if ( $condition ) {
		$passed++;
		echo "  \xE2\x9C\x93 {$name}\n";
	} else {
		$failed++;
		$errors[] = $name;
		echo "  \xE2\x9C\x97 FAILED: {$name}\n";
	}
}

echo "=== DEF_Core_Chat_Button_Block::render ===\n\n";

_wp_test_reset_options();

$html = DEF_Core_Chat_Button_Block::render( array( 'label' => 'Ask Joe', 'prompt' => 'What could Widrow do for my business?' ) );
assert_test( false !== strpos( $html, '<div class="wp-block-button">' ), 'the wrapper carries the core button class' );
assert_test( false !== strpos( $html, 'class="wp-block-button__link wp-element-button"' ), 'the link carries the core button link classes' );
assert_test( false !== strpos( $html, ' data-def-chat-trigger' ), 'the link is a chat trigger' );
assert_test( false !== strpos( $html, 'data-def-chat-prompt="What could Widrow do for my business?"' ), 'the question rides as the prompt attribute' );
assert_test( false !== strpos( $html, '>Ask Joe</a>' ), 'the label is the link text' );

$html = DEF_Core_Chat_Button_Block::render( array( 'label' => 'Ask Joe', 'prompt' => '' ) );
assert_test( false === strpos( $html, 'data-def-chat-prompt' ), 'a blank question sends nothing: no prompt attribute' );
$html = DEF_Core_Chat_Button_Block::render( array( 'label' => 'Ask Joe', 'prompt' => "  \n " ) );
assert_test( false === strpos( $html, 'data-def-chat-prompt' ), 'a whitespace-only question counts as blank' );

$html = DEF_Core_Chat_Button_Block::render( array() );
assert_test( false !== strpos( $html, '>Chat</a>' ), 'no label and no option: the built-in "Chat"' );
update_option( 'def_core_chat_button_label', 'Talk to Sid' );
$html = DEF_Core_Chat_Button_Block::render( array( 'label' => '   ' ) );
assert_test( false !== strpos( $html, '>Talk to Sid</a>' ), 'a blank label falls back to the Chat Settings button label' );

$html = DEF_Core_Chat_Button_Block::render( array( 'label' => '<b>Ask</b>', 'prompt' => 'a" onmouseover="x() <script>' ) );
assert_test( false === strpos( $html, '<b>' ) && false !== strpos( $html, '&lt;b&gt;Ask&lt;/b&gt;' ), 'markup in the label is escaped' );
assert_test( false !== strpos( $html, 'data-def-chat-prompt="a&quot; onmouseover=&quot;x() &lt;script&gt;"' ), 'the question cannot break out of its attribute' );

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
	foreach ( $errors as $e ) {
		echo "  - {$e}\n";
	}
	exit( 1 );
}
