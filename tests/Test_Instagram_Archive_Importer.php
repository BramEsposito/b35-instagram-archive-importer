<?php
/**
 * Tests for InstagramArchiveImporter.
 */
class Test_Instagram_Archive_Importer extends WP_UnitTestCase {

	private InstagramArchiveImporter $importer;

	public function set_up(): void {
		parent::set_up();
		$this->importer = new InstagramArchiveImporter( trailingslashit( dirname( __DIR__ ) ) );
		// Register taxonomies and load settings.
		$this->importer->init();
		// Ensure the default category exists so add_post() can assign it.
		wp_insert_term( 'Photography', 'category', array( 'slug' => 'photography' ) );
		// ig_location is registered by b35-photoblog in production; register it here for tests.
		if ( ! taxonomy_exists( 'ig_location' ) ) {
			register_taxonomy( 'ig_location', array( 'post' ) );
		}
	}

	// -------------------------------------------------------------------------
	// fmt_time()
	// -------------------------------------------------------------------------

	public function test_fmt_time_unix_zero(): void {
		$this->assertSame( '1970-01-01 00:00:00', $this->importer->fmt_time( 0, true ) );
	}

	public function test_fmt_time_known_timestamp(): void {
		// 2024-01-15 12:00:00 UTC.
		$this->assertSame( '2024-01-15 12:00:00', $this->importer->fmt_time( 1705320000, true ) );
	}

	public function test_fmt_time_date_string(): void {
		$this->assertSame( '2024-06-01 00:00:00', $this->importer->fmt_time( '2024-06-01', false ) );
	}

	// -------------------------------------------------------------------------
	// get_paragraph_html()
	// -------------------------------------------------------------------------

	public function test_get_paragraph_html_wraps_content_in_block(): void {
		$html = $this->importer->get_paragraph_html( 'Hello world' );

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $html );
		$this->assertStringContainsString( '<p>Hello world</p>', $html );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $html );
	}

	// -------------------------------------------------------------------------
	// get_image_html()
	// -------------------------------------------------------------------------

	public function test_get_image_html_contains_block_comment_and_class(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.jpg'
		);

		$html = $this->importer->get_image_html( $attachment_id );

		$this->assertStringContainsString( '<!-- wp:image', $html );
		$this->assertStringContainsString( "wp-image-{$attachment_id}", $html );
		$this->assertStringContainsString( '<!-- /wp:image -->', $html );
	}

	public function test_get_image_html_includes_figcaption_when_provided(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.jpg'
		);

		$html = $this->importer->get_image_html( $attachment_id, 'A caption' );

		$this->assertStringContainsString( 'wp-element-caption', $html );
		$this->assertStringContainsString( 'A caption', $html );
	}

	// -------------------------------------------------------------------------
	// get_video_html()
	// -------------------------------------------------------------------------

	public function test_get_video_html_contains_block_comment(): void {
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/test-image.jpg'
		);

		$html = $this->importer->get_video_html( $attachment_id );

		$this->assertStringContainsString( '<!-- wp:video', $html );
		$this->assertStringContainsString( "\"id\":{$attachment_id}", $html );
	}

	// -------------------------------------------------------------------------
	// parse_and_add_tags()
	// -------------------------------------------------------------------------

	public function test_parse_and_add_tags_extracts_hashtags(): void {
		$post_id = self::factory()->post->create();
		$this->set_post_id( $post_id );

		$this->importer->parse_and_add_tags( 'Beautiful sunset #travel #photography today' );

		$slugs = wp_get_object_terms( $post_id, 'ig_tag', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'travel', $slugs );
		$this->assertContains( 'photography', $slugs );
	}

	public function test_parse_and_add_tags_ignores_plain_words(): void {
		$post_id = self::factory()->post->create();
		$this->set_post_id( $post_id );

		$this->importer->parse_and_add_tags( 'No hashtags here at all' );

		$terms = wp_get_object_terms( $post_id, 'ig_tag' );
		$this->assertEmpty( $terms );
	}

	// -------------------------------------------------------------------------
	// add_post()
	// -------------------------------------------------------------------------

	public function test_add_post_inserts_post_with_correct_title_and_status(): void {
		$this->importer->add_post(
			array(
				'post_title'   => 'Sunset in Antwerp',
				'post_time'    => '2024-01-15 12:00:00',
				'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
			)
		);

		$post_id = $this->get_post_id();
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertSame( 'Sunset in Antwerp', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2024-01-15 12:00:00', $post->post_date );
	}

	public function test_add_post_assigns_category(): void {
		$this->importer->add_post(
			array(
				'post_title'   => 'Category test',
				'post_time'    => '2024-01-15 12:00:00',
				'post_content' => '',
			)
		);

		$post_id    = $this->get_post_id();
		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'slugs' ) );
		$this->assertContains( 'photography', $categories );
	}

	// -------------------------------------------------------------------------
	// add_location()
	// -------------------------------------------------------------------------

	public function test_add_location_assigns_term_for_matching_timestamp(): void {
		$post_id = self::factory()->post->create();
		$this->set_post_id( $post_id );
		$this->set_location_data(
			array(
				array( '1693935233', 'Antwerp, Belgium', '51.2157', '4.4141' ),
			)
		);

		$this->importer->add_location( 1693935233 );

		$terms = wp_get_object_terms( $post_id, 'ig_location', array( 'fields' => 'names' ) );
		$this->assertContains( 'Antwerp, Belgium', $terms );
	}

	public function test_add_location_records_error_when_timestamp_unmatched(): void {
		$post_id = self::factory()->post->create();
		$this->set_post_id( $post_id );
		$this->set_location_data( array() );

		$this->importer->add_location( 9999999999 );

		$errors = $this->get_errors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( '9999999999', $errors[0] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function set_post_id( int $post_id ): void {
		$prop = ( new ReflectionClass( $this->importer ) )->getProperty( 'post_id' );
		$prop->setAccessible( true );
		$prop->setValue( $this->importer, $post_id );
	}

	private function get_post_id(): int {
		$prop = ( new ReflectionClass( $this->importer ) )->getProperty( 'post_id' );
		$prop->setAccessible( true );
		return $prop->getValue( $this->importer );
	}

	private function set_location_data( array $data ): void {
		$prop = ( new ReflectionClass( $this->importer ) )->getProperty( 'location_data' );
		$prop->setAccessible( true );
		$prop->setValue( $this->importer, $data );
	}

	private function get_errors(): array {
		$prop = ( new ReflectionClass( $this->importer ) )->getProperty( 'errors' );
		$prop->setAccessible( true );
		return $prop->getValue( $this->importer );
	}
}
