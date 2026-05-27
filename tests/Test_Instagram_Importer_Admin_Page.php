<?php
/**
 * Tests for InstagramImporterAdminPage.
 */
class Test_Instagram_Importer_Admin_Page extends WP_UnitTestCase {

	private InstagramImporterAdminPage $admin_page;

	public function set_up(): void {
		parent::set_up();
		$this->admin_page = new InstagramImporterAdminPage(
			trailingslashit( dirname( __DIR__ ) ),
			'http://example.org/wp-content/plugins/b35-instagram-archive-importer/'
		);
	}

	// -------------------------------------------------------------------------
	// sanitize_settings()
	// -------------------------------------------------------------------------

	public function test_sanitize_settings_empty_input_returns_safe_defaults(): void {
		$result = $this->admin_page->sanitize_settings( array() );

		$this->assertSame( 'publish', $result['post_status'] );
		$this->assertSame( 'ig_tag', $result['tag_taxonomy'] );
		$this->assertSame( 'ig_location', $result['location_taxonomy'] );
		$this->assertSame( 0, $result['author'] );
		$this->assertSame( 0, $result['json_attachment_id'] );
		$this->assertSame( 0, $result['csv_attachment_id'] );
	}

	public function test_sanitize_settings_rejects_invalid_post_status(): void {
		$result = $this->admin_page->sanitize_settings( array( 'post_status' => 'trash' ) );
		$this->assertSame( 'publish', $result['post_status'] );
	}

	public function test_sanitize_settings_accepts_draft_status(): void {
		$result = $this->admin_page->sanitize_settings( array( 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', $result['post_status'] );
	}

	public function test_sanitize_settings_accepts_valid_taxonomy_slug(): void {
		$result = $this->admin_page->sanitize_settings( array( 'tag_taxonomy' => 'post_tag' ) );
		$this->assertSame( 'post_tag', $result['tag_taxonomy'] );
	}

	public function test_sanitize_settings_sanitizes_taxonomy_slug(): void {
		// sanitize_key strips non-alphanumeric chars and lowercases; it does not add hyphens.
		$result = $this->admin_page->sanitize_settings( array( 'tag_taxonomy' => 'My Custom Taxonomy!' ) );
		$this->assertSame( 'mycustomtaxonomy', $result['tag_taxonomy'] );
	}

	public function test_sanitize_settings_falls_back_to_default_when_taxonomy_empty(): void {
		$result = $this->admin_page->sanitize_settings( array( 'tag_taxonomy' => '' ) );
		$this->assertSame( 'ig_tag', $result['tag_taxonomy'] );
	}

	public function test_sanitize_settings_strips_unsafe_export_uri(): void {
		$result = $this->admin_page->sanitize_settings(
			array( 'export_uri' => 'javascript:alert(1)' )
		);
		$this->assertSame( '', $result['export_uri'] );
	}

	public function test_sanitize_settings_keeps_valid_export_uri(): void {
		$result = $this->admin_page->sanitize_settings(
			array( 'export_uri' => 'https://cdn.example.com/instagram/' )
		);
		$this->assertSame( 'https://cdn.example.com/instagram/', $result['export_uri'] );
	}

	public function test_sanitize_settings_casts_author_to_int(): void {
		$result = $this->admin_page->sanitize_settings( array( 'author' => '42abc' ) );
		$this->assertSame( 42, $result['author'] );
	}
}
