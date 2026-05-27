<?php
/**
 * Admin page for the Instagram Archive Importer plugin.
 *
 * @package Instagram_Archive_Importer
 * @since   1.0.0
 */

/**
 * Registers and renders the Tools > Instagram Importer admin page.
 */
class InstagramImporterAdminPage {

	/**
	 * WordPress option key for all plugin settings.
	 */
	const OPTION_KEY = 'b35_ig_importer_settings';

	/**
	 * Menu slug used for the Tools submenu page.
	 */
	const MENU_SLUG = 'b35-ig-importer';

	/**
	 * Absolute path to the plugin root directory (with trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * URL to the plugin root directory (with trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_dir Absolute path to the plugin root directory.
	 * @param string $plugin_url URL to the plugin root directory.
	 */
	public function __construct( string $plugin_dir, string $plugin_url ) {
		$this->plugin_dir = $plugin_dir;
		$this->plugin_url = $plugin_url;

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_import_file_types' ) );
	}

	/**
	 * Registers the Tools > Instagram Importer submenu page.
	 */
	public function add_menu_page(): void {
		add_management_page(
			__( 'Instagram Importer', 'b35-instagram-archive-importer' ),
			__( 'Instagram Importer', 'b35-instagram-archive-importer' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the media library and admin page script on the plugin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'b35-ig-admin-page',
			$this->plugin_url . 'js/admin-page.js',
			array( 'media-views' ),
			'1.0',
			true
		);
	}

	/**
	 * Extends the allowed upload MIME types to include JSON and CSV for admin users.
	 *
	 * @param array $mimes Existing allowed MIME types.
	 * @return array Modified allowed MIME types.
	 */
	public function allow_import_file_types( array $mimes ): array {
		if ( is_admin() ) {
			$mimes['json'] = 'application/json';
			$mimes['csv']  = 'text/csv';
		}
		return $mimes;
	}

	/**
	 * Registers the settings group, sections, and fields via the WP Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			'b35_ig_importer',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'b35_ig_import_settings',
			__( 'Import Settings', 'b35-instagram-archive-importer' ),
			'__return_false',
			self::MENU_SLUG
		);

		add_settings_field(
			'export_uri',
			__( 'Media base URL', 'b35-instagram-archive-importer' ),
			array( $this, 'render_export_uri_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'category',
			__( 'Post category', 'b35-instagram-archive-importer' ),
			array( $this, 'render_category_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'author',
			__( 'Post author', 'b35-instagram-archive-importer' ),
			array( $this, 'render_author_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'post_status',
			__( 'Post status', 'b35-instagram-archive-importer' ),
			array( $this, 'render_post_status_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'tag_taxonomy',
			__( 'Hashtag taxonomy', 'b35-instagram-archive-importer' ),
			array( $this, 'render_tag_taxonomy_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'location_taxonomy',
			__( 'Location taxonomy', 'b35-instagram-archive-importer' ),
			array( $this, 'render_location_taxonomy_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_field(
			'sslverify',
			__( 'SSL verification', 'b35-instagram-archive-importer' ),
			array( $this, 'render_sslverify_field' ),
			self::MENU_SLUG,
			'b35_ig_import_settings'
		);

		add_settings_section(
			'b35_ig_files',
			__( 'Archive Files', 'b35-instagram-archive-importer' ),
			'__return_false',
			self::MENU_SLUG
		);

		add_settings_field(
			'json_file',
			__( 'Instagram JSON', 'b35-instagram-archive-importer' ),
			array( $this, 'render_json_field' ),
			self::MENU_SLUG,
			'b35_ig_files'
		);

		add_settings_field(
			'csv_file',
			__( 'Locations CSV', 'b35-instagram-archive-importer' ),
			array( $this, 'render_csv_field' ),
			self::MENU_SLUG,
			'b35_ig_files'
		);
	}

	/**
	 * Sanitizes and validates settings before saving to the database.
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings array.
	 */
	public function sanitize_settings( array $input ): array {
		$allowed_statuses  = array( 'publish', 'draft' );
		$post_status       = $input['post_status'] ?? 'publish';
		$tag_taxonomy      = sanitize_key( $input['tag_taxonomy'] ?? 'ig_tag' );
		$location_taxonomy = sanitize_key( $input['location_taxonomy'] ?? 'ig_location' );

		return array(
			'export_uri'         => esc_url_raw( $input['export_uri'] ?? '' ),
			'category'           => sanitize_key( $input['category'] ?? '' ),
			'author'             => absint( $input['author'] ?? 0 ),
			'post_status'        => in_array( $post_status, $allowed_statuses, true ) ? $post_status : 'publish',
			'tag_taxonomy'       => $tag_taxonomy ? $tag_taxonomy : 'ig_tag',
			'location_taxonomy'  => $location_taxonomy ? $location_taxonomy : 'ig_location',
			'sslverify'          => ! empty( $input['sslverify'] ),
			'json_attachment_id' => absint( $input['json_attachment_id'] ?? 0 ),
			'csv_attachment_id'  => absint( $input['csv_attachment_id'] ?? 0 ),
		);
	}

	/**
	 * Returns saved options merged with defaults.
	 *
	 * @return array Plugin settings with defaults applied.
	 */
	private function get_options(): array {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'export_uri'         => '',
				'category'           => 'photography',
				'author'             => 0,
				'post_status'        => 'publish',
				'tag_taxonomy'       => 'ig_tag',
				'location_taxonomy'  => 'ig_location',
				'sslverify'          => true,
				'json_attachment_id' => 0,
				'csv_attachment_id'  => 0,
			)
		);
	}

	/**
	 * Renders the Export URI settings field.
	 */
	public function render_export_uri_field(): void {
		$opts = $this->get_options();
		printf(
			'<input type="url" name="%s[export_uri]" value="%s" class="regular-text" placeholder="https://example.com/wp-content/uploads/instagram/">',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $opts['export_uri'] )
		);
		echo '<p class="description">' . esc_html__( 'Base URL where the Instagram media files are hosted.', 'b35-instagram-archive-importer' ) . '</p>';
	}

	/**
	 * Renders the post category dropdown settings field.
	 */
	public function render_category_field(): void {
		$opts       = $this->get_options();
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		echo '<select name="' . esc_attr( self::OPTION_KEY . '[category]' ) . '">';
		echo '<option value="">' . esc_html__( '— Select category —', 'b35-instagram-archive-importer' ) . '</option>';
		foreach ( $categories as $cat ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $cat->slug ),
				selected( $opts['category'], $cat->slug, false ),
				esc_html( $cat->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the post author dropdown settings field.
	 */
	public function render_author_field(): void {
		$opts  = $this->get_options();
		$users = get_users(
			array(
				'capability' => 'publish_posts',
				'orderby'    => 'display_name',
			)
		);
		echo '<select name="' . esc_attr( self::OPTION_KEY . '[author]' ) . '">';
		echo '<option value="0">' . esc_html__( '— Select author —', 'b35-instagram-archive-importer' ) . '</option>';
		foreach ( $users as $user ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $user->ID,
				selected( $opts['author'], $user->ID, false ),
				esc_html( $user->display_name )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the post status select field.
	 */
	public function render_post_status_field(): void {
		$opts     = $this->get_options();
		$statuses = array(
			'publish' => __( 'Published', 'b35-instagram-archive-importer' ),
			'draft'   => __( 'Draft', 'b35-instagram-archive-importer' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_KEY . '[post_status]' ) . '">';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $opts['post_status'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the hashtag taxonomy select field.
	 */
	public function render_tag_taxonomy_field(): void {
		$opts = $this->get_options();
		$this->render_taxonomy_select( self::OPTION_KEY . '[tag_taxonomy]', $opts['tag_taxonomy'] );
		echo '<p class="description">' . esc_html__( 'Taxonomy used for hashtags extracted from captions. Defaults to ig_tag (created automatically).', 'b35-instagram-archive-importer' ) . '</p>';
	}

	/**
	 * Renders the location taxonomy select field.
	 */
	public function render_location_taxonomy_field(): void {
		$opts = $this->get_options();
		$this->render_taxonomy_select( self::OPTION_KEY . '[location_taxonomy]', $opts['location_taxonomy'] );
		echo '<p class="description">' . esc_html__( 'Taxonomy used for locations. Defaults to ig_location (created automatically).', 'b35-instagram-archive-importer' ) . '</p>';
	}

	/**
	 * Renders a select element listing all taxonomies registered for posts.
	 *
	 * @param string $name    The name attribute for the select element.
	 * @param string $current The currently selected taxonomy slug.
	 */
	private function render_taxonomy_select( string $name, string $current ): void {
		$taxonomies = get_object_taxonomies( 'post', 'objects' );
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $taxonomies as $taxonomy ) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( $taxonomy->name ),
				selected( $current, $taxonomy->name, false ),
				esc_html( $taxonomy->label ),
				esc_html( $taxonomy->name )
			);
		}
		// Show the current value even if its taxonomy is not yet registered.
		if ( ! isset( $taxonomies[ $current ] ) ) {
			printf(
				'<option value="%s" selected="selected">%s</option>',
				esc_attr( $current ),
				esc_html( $current )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the SSL verification checkbox field.
	 */
	public function render_sslverify_field(): void {
		$opts = $this->get_options();
		printf(
			'<label><input type="checkbox" name="%s[sslverify]" value="1"%s> %s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $opts['sslverify'], true, false ),
			esc_html__( 'Verify SSL certificates', 'b35-instagram-archive-importer' )
		);
		echo '<p class="description">' . esc_html__( 'Uncheck when fetching media from a local or staging server with a self-signed certificate (e.g. ddev).', 'b35-instagram-archive-importer' ) . '</p>';
	}

	/**
	 * Renders the Instagram JSON file picker field.
	 */
	public function render_json_field(): void {
		$opts          = $this->get_options();
		$attachment_id = (int) $opts['json_attachment_id'];
		$filename      = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
		?>
		<input type="hidden" id="b35-ig-json-id"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[json_attachment_id]"
			value="<?php echo esc_attr( $attachment_id ); ?>">
		<button type="button" id="b35-ig-select-json" class="button"
			data-title="<?php esc_attr_e( 'Select Instagram JSON file', 'b35-instagram-archive-importer' ); ?>">
			<?php esc_html_e( 'Select file', 'b35-instagram-archive-importer' ); ?>
		</button>
		<span id="b35-ig-json-name" style="margin-left:8px"><?php echo esc_html( $filename ); ?></span>
		<p class="description"><?php esc_html_e( 'The posts_1.json file from your Instagram data export.', 'b35-instagram-archive-importer' ); ?></p>
		<?php
	}

	/**
	 * Renders the locations CSV file picker field.
	 */
	public function render_csv_field(): void {
		$opts          = $this->get_options();
		$attachment_id = (int) $opts['csv_attachment_id'];
		$filename      = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
		?>
		<input type="hidden" id="b35-ig-csv-id"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[csv_attachment_id]"
			value="<?php echo esc_attr( $attachment_id ); ?>">
		<button type="button" id="b35-ig-select-csv" class="button"
			data-title="<?php esc_attr_e( 'Select locations CSV file', 'b35-instagram-archive-importer' ); ?>">
			<?php esc_html_e( 'Select file', 'b35-instagram-archive-importer' ); ?>
		</button>
		<span id="b35-ig-csv-name" style="margin-left:8px"><?php echo esc_html( $filename ); ?></span>
		<p class="description"><?php esc_html_e( 'CSV with columns: timestamp, location name, latitude, longitude.', 'b35-instagram-archive-importer' ); ?></p>
		<?php
	}

	/**
	 * Renders the test import report stored by the importer after a test run.
	 *
	 * @param array|false|null $report Report data from the transient, or null/false if absent.
	 */
	private function render_test_report( $report ): void {
		if ( ! is_array( $report ) ) {
			return;
		}

		if ( isset( $report['fatal'] ) ) {
			?>
			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'Test import failed:', 'b35-instagram-archive-importer' ); ?></strong>
				<?php echo esc_html( $report['fatal'] ); ?></p>
			</div>
			<?php
			return;
		}

		$has_errors  = ! empty( $report['errors'] );
		$notice_type = $has_errors ? 'notice-warning' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $notice_type ); ?> inline" style="padding-bottom:1em">
			<h3 style="margin-top:.5em"><?php esc_html_e( 'Test Import Report', 'b35-instagram-archive-importer' ); ?></h3>
			<table class="form-table" role="presentation" style="margin:0">
				<tr>
					<th><?php esc_html_e( 'Post', 'b35-instagram-archive-importer' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $report['edit_url'] ); ?>"><?php echo esc_html( $report['post_title'] ); ?></a>
						<span class="description">(ID: <?php echo (int) $report['post_id']; ?>)</span>
						&mdash; <a href="<?php echo esc_url( $report['view_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'b35-instagram-archive-importer' ); ?></a>
						&mdash; <?php echo esc_html( $report['post_date'] ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Media', 'b35-instagram-archive-importer' ); ?></th>
					<td>
						<?php if ( empty( $report['media'] ) ) : ?>
							<em><?php esc_html_e( 'None', 'b35-instagram-archive-importer' ); ?></em>
						<?php else : ?>
							<?php foreach ( $report['media'] as $item ) : ?>
								<?php echo esc_html( $item['filename'] ); ?>
								<span class="description">(<?php echo esc_html( $item['type'] ); ?>, ID: <?php echo (int) $item['id']; ?>)</span><br>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Tags', 'b35-instagram-archive-importer' ); ?></th>
					<td>
						<?php if ( empty( $report['tags'] ) ) : ?>
							<em><?php esc_html_e( 'None', 'b35-instagram-archive-importer' ); ?></em>
						<?php else : ?>
							<?php echo esc_html( implode( ', ', $report['tags'] ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Location', 'b35-instagram-archive-importer' ); ?></th>
					<td>
						<?php if ( $report['location'] ) : ?>
							<?php echo esc_html( $report['location'] ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'None', 'b35-instagram-archive-importer' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php if ( $has_errors ) : ?>
				<p><strong><?php esc_html_e( 'Errors:', 'b35-instagram-archive-importer' ); ?></strong></p>
				<ul style="margin:.5em 0 0 1.5em;list-style:disc">
					<?php foreach ( $report['errors'] as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the full admin page: settings form and import trigger.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts       = $this->get_options();
		$json_ready = $opts['json_attachment_id'] && get_attached_file( (int) $opts['json_attachment_id'] );
		$csv_ready  = $opts['csv_attachment_id'] && get_attached_file( (int) $opts['csv_attachment_id'] );
		$import_url = wp_nonce_url(
			admin_url( '?action=b35-instagram-archive-importer-import' ),
			'b35-instagram-archive-importer-import'
		);
		$test_url   = wp_nonce_url(
			admin_url( '?action=b35-instagram-archive-importer-test' ),
			'b35-instagram-archive-importer-test'
		);

		$test_report = null;
		if ( isset( $_GET['b35_test_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$transient_key = 'b35_ig_test_import_' . get_current_user_id();
			$test_report   = get_transient( $transient_key );
			delete_transient( $transient_key );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instagram Archive Importer', 'b35-instagram-archive-importer' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'b35_ig_importer' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'b35-instagram-archive-importer' ) );
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Test Import', 'b35-instagram-archive-importer' ); ?></h2>

			<?php $this->render_test_report( $test_report ); ?>

			<?php if ( $json_ready ) : ?>
				<p><?php esc_html_e( 'Imports the first post from the JSON file so you can verify your settings before running the full import. A real post will be created.', 'b35-instagram-archive-importer' ); ?></p>
				<a href="<?php echo esc_url( $test_url ); ?>" class="button">
					<?php esc_html_e( 'Test Import', 'b35-instagram-archive-importer' ); ?>
				</a>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Select a JSON file above and save settings to enable the test import.', 'b35-instagram-archive-importer' ); ?></p>
				<button class="button" disabled><?php esc_html_e( 'Test Import', 'b35-instagram-archive-importer' ); ?></button>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Run Import', 'b35-instagram-archive-importer' ); ?></h2>

			<?php if ( ! $json_ready || ! $csv_ready ) : ?>
				<p class="description">
					<?php esc_html_e( 'Select both archive files above and save settings before importing.', 'b35-instagram-archive-importer' ); ?>
				</p>
				<button class="button button-primary" disabled><?php esc_html_e( 'Start Import', 'b35-instagram-archive-importer' ); ?></button>
			<?php else : ?>
				<p><?php esc_html_e( 'Files are ready. Click to start the import — this may take a while.', 'b35-instagram-archive-importer' ); ?></p>
				<a href="<?php echo esc_url( $import_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Start Import', 'b35-instagram-archive-importer' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}
}
