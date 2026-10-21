<?php
/**
 * W77 — the page context lists the page's pictures (customer-chat page-reads
 * runsheet D-PR4).
 *
 * The featured image, a product's gallery, then each <img> in the post's stored
 * content, one {url, alt, caption} per URL. An attachment is listed at `large`,
 * with its own alt text (else the tag's) and caption. None on an archive, a
 * search, an author page, or a password-protected post.
 *
 * @package def-core
 */

declare(strict_types=1);

class Test_Page_Context_Images extends WP_UnitTestCase {

	private function picture( string $file, string $alt = '', string $caption = '' ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => $caption,
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	private function listed( int $id, string $alt, string $caption ): array {
		return array(
			'url'     => wp_get_attachment_url( $id ),
			'alt'     => $alt,
			'caption' => $caption,
		);
	}

	public function test_featured_then_content_pictures_in_order_without_repeats(): void {
		$front  = $this->picture( 'front.jpg', 'Front view', 'The kettle' );
		$inside = $this->picture( 'inside.jpg', '', 'Inside the lid' );
		$long   = str_repeat( 'a', 250 );
		$post   = self::factory()->post->create(
			array(
				'post_content' =>
					'<p>Intro</p>'
					. '<img class="wp-image-' . $inside . ' size-full" src="https://example.org/old.jpg" alt="Lid &lt;b&gt;open&lt;/b&gt;">'
					. '<img src="https://example.org/plain.jpg" alt="' . $long . '">'
					. '<img class="wp-image-' . $front . '" src="https://example.org/front-again.jpg">'
					. '<img src="data:image/png;base64,AAAA" alt="inline">',
			)
		);
		set_post_thumbnail( $post, $front );
		$this->go_to( get_permalink( $post ) );

		$this->assertSame(
			array(
				$this->listed( $front, 'Front view', 'The kettle' ),
				$this->listed( $inside, 'Lid open', 'Inside the lid' ),
				array( 'url' => 'https://example.org/plain.jpg', 'alt' => str_repeat( 'a', 200 ), 'caption' => '' ),
			),
			DEF_Core_Page_Context::collect_page_images( $post )
		);
	}

	public function test_a_products_gallery_follows_its_featured_image(): void {
		register_post_type( 'product', array( 'public' => true ) );
		$front   = $this->picture( 'front.jpg', 'Front' );
		$side    = $this->picture( 'side.jpg', 'Side' );
		$back    = $this->picture( 'back.jpg', 'Back' );
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		set_post_thumbnail( $product, $front );
		update_post_meta( $product, '_product_image_gallery', $side . ',' . $back );
		$this->go_to( get_permalink( $product ) );

		$this->assertSame(
			array( $this->listed( $front, 'Front', '' ), $this->listed( $side, 'Side', '' ), $this->listed( $back, 'Back', '' ) ),
			DEF_Core_Page_Context::collect_page_images( $product )
		);
		unregister_post_type( 'product' );
	}

	public function test_build_payload_carries_the_pictures(): void {
		$front = $this->picture( 'front.jpg', 'Front' );
		$post  = self::factory()->post->create();
		set_post_thumbnail( $post, $front );
		$this->go_to( get_permalink( $post ) );

		$this->assertSame( array( $this->listed( $front, 'Front', '' ) ), DEF_Core_Page_Context::build_payload()['images'] );
	}

	public function test_a_password_protected_post_lists_none(): void {
		$post = self::factory()->post->create( array( 'post_password' => 'secret' ) );
		set_post_thumbnail( $post, $this->picture( 'front.jpg', 'Front' ) );
		$this->go_to( get_permalink( $post ) );

		$this->assertSame( array(), DEF_Core_Page_Context::collect_page_images( $post ) );
	}

	/**
	 * On an archive, a search or an author page the queried id is a term or a user,
	 * never a post — so even a real post id with a picture lists none there.
	 */
	public function test_an_archive_a_search_or_an_author_page_lists_none(): void {
		$post = self::factory()->post->create( array( 'post_author' => self::factory()->user->create() ) );
		set_post_thumbnail( $post, $this->picture( 'front.jpg', 'Front' ) );
		$cat = self::factory()->category->create();

		foreach ( array(
			'taxonomy_archive' => get_category_link( $cat ),
			'search'           => home_url( '/?s=kettle' ),
			'other'            => get_author_posts_url( (int) get_post_field( 'post_author', $post ) ),
		) as $type => $url ) {
			$this->go_to( $url );
			$this->assertSame( $type, DEF_Core_Page_Context::detect_page_type() );
			$this->assertSame( array(), DEF_Core_Page_Context::collect_page_images( $post ), $type );
		}
	}
}
