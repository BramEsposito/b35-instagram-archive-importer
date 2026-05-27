<?php
/**
 * Instagram Archive Importer main class.
 *
 * @package Instagram_Archive_Importer
 * @since   1.0.0
 */

/**
 * Imports Instagram archive posts into WordPress.
 */
class InstagramArchiveImporter {

	/**
	 * WordPress category name for imported posts.
	 *
	 * @var string
	 */
	private string $category_name;

	/**
	 * IG tag taxonomy configuration.
	 *
	 * @var array
	 */
	private array $ig_tag_taxonomy;

	/**
	 * IG location taxonomy configuration.
	 *
	 * @var array
	 */
	private array $ig_location_taxonomy;

	/**
	 * Current WordPress post ID being processed.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Absolute path to the plugin root directory.
	 *
	 * @var string
	 */
	private string $plugin_root;

	/**
	 * Absolute path to the Instagram JSON feed file.
	 *
	 * @var string
	 */
	private string $json_file;

	/**
	 * Absolute path to the locations CSV file.
	 *
	 * @var string
	 */
	private string $locations_csv;

	/**
	 * URI prefix for Instagram media files on the CDN.
	 *
	 * @var string
	 */
	private string $export_uri;

	/**
	 * WordPress user ID assigned as post author.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Post status for imported posts ('publish' or 'draft').
	 *
	 * @var string
	 */
	private string $post_status;

	/**
	 * Whether to verify SSL certificates when downloading media files.
	 *
	 * @var bool
	 */
	private bool $sslverify;

	/**
	 * Parsed location data rows from locations.csv.
	 *
	 * @var array
	 */
	private array $location_data = array();

	/**
	 * Error messages collected during an import run.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Number of posts skipped because they were already imported.
	 *
	 * @var int
	 */
	private int $skipped = 0;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_root Absolute path to the plugin root directory.
	 */
	public function __construct( $plugin_root ) {
		$this->plugin_root = $plugin_root;
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Loads plugin settings from the database, falling back to safe defaults.
	 */
	public function settings() {
		$opts = wp_parse_args(
			get_option( InstagramImporterAdminPage::OPTION_KEY, array() ),
			array(
				'export_uri'         => 'https://bramesposito.com/wp-content/uploads/instagram/',
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

		$this->user_id       = $opts['author'] ? (int) $opts['author'] : get_current_user_id();
		$this->category_name = $opts['category'] ? $opts['category'] : 'photography';
		$this->post_status   = $opts['post_status'];
		$this->export_uri    = $opts['export_uri'];
		$this->sslverify     = (bool) $opts['sslverify'];

		$upload_dir = wp_upload_dir()['basedir'];

		$json_path       = $opts['json_attachment_id'] ? get_attached_file( (int) $opts['json_attachment_id'] ) : null;
		$this->json_file = $json_path ? $json_path : $upload_dir . '/posts_1.json';

		$csv_path            = $opts['csv_attachment_id'] ? get_attached_file( (int) $opts['csv_attachment_id'] ) : null;
		$this->locations_csv = $csv_path ? $csv_path : $upload_dir . '/locations.csv';

		$tag_tax = $opts['tag_taxonomy'];
		$loc_tax = $opts['location_taxonomy'];

		$this->ig_tag_taxonomy      = array(
			'name'     => $tag_tax ? $tag_tax : 'ig_tag',
			'singular' => 'photo tag',
			'plural'   => 'photo tags',
		);
		$this->ig_location_taxonomy = array(
			'name'     => $loc_tax ? $loc_tax : 'ig_location',
			'singular' => 'location',
			'plural'   => 'locations',
		);
	}

	/**
	 * Registers taxonomies and filters on WordPress init.
	 */
	public function init() {
		$this->settings();
		$this->ensure_taxonomy_exists( $this->ig_tag_taxonomy );
		$this->ensure_taxonomy_exists( $this->ig_location_taxonomy );
		add_filter( 'term_link', array( $this, 'set_location_term_link' ), 25, 3 );
		$taxonomy = $this->ig_location_taxonomy['name'];
		add_filter( "term_links-{$taxonomy}", array( $this, 'set_location_term_link_target' ), 25, 1 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Removes the default ig_location taxonomy meta box from the post editor.
	 */
	public function add_meta_box() {
		remove_meta_box( 'tagsdiv-' . $this->ig_location_taxonomy['name'], 'post', 'side' );
	}

	/**
	 * Adds target="_blank" and rel="nofollow" to ig_location term links.
	 *
	 * @param array $links Array of term link HTML strings.
	 * @return array Modified array of term link HTML strings.
	 */
	public function set_location_term_link_target( $links ) {
		return array_map(
			function ( $link ) {
				return str_replace( 'rel="tag"', 'target="_blank" rel="nofollow"', $link );
			},
			$links
		);
	}

	/**
	 * Replaces ig_location term links with Google Maps URLs.
	 *
	 * @param string   $termlink The term link URL.
	 * @param \WP_Term $term     The term object.
	 * @param string   $taxonomy The taxonomy slug.
	 * @return string Modified or original term link URL.
	 */
	public function set_location_term_link( $termlink, $term, $taxonomy ) {
		if ( $this->ig_location_taxonomy['name'] === $taxonomy ) {
			$latlong = get_term_meta( $term->term_id, 'latlong', true );

			return sprintf( 'https://maps.google.com/?q=%s', $latlong );
		}

		return $termlink;
	}

	/**
	 * Handles the import action triggered via the admin URL query parameter.
	 *
	 * Trigger URL: wp_nonce_url( admin_url( '?action=b35-instagram-archive-importer-import' ), 'b35-instagram-archive-importer-import' )
	 */
	public function admin_init() {
		if ( ! isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'b35-instagram-archive-importer-import' !== $action && 'b35-instagram-archive-importer-test' !== $action ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'b35-instagram-archive-importer-import' === $action ) {
			check_admin_referer( 'b35-instagram-archive-importer-import' );
			$this->init_import();
			add_action( 'admin_notices', array( $this, 'display_notice' ) );
		} else {
			check_admin_referer( 'b35-instagram-archive-importer-test' );
			$this->run_test_import();
			wp_safe_redirect( admin_url( 'tools.php?page=' . InstagramImporterAdminPage::MENU_SLUG . '&b35_test_done=1' ) );
			exit;
		}
	}

	/**
	 * Loads settings, taxonomies, CSV data, and processes the full Instagram JSON feed.
	 */
	private function init_import() {
		$this->settings();

		$this->ensure_taxonomy_exists( $this->ig_tag_taxonomy );
		$this->ensure_taxonomy_exists( $this->ig_location_taxonomy );
		$json_data = $this->parse_json_feed();

		if ( null === $json_data ) {
			$this->add_error( __( 'Could not read or decode the JSON file.', 'b35-instagram-archive-importer' ) );
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$csv_content = $wp_filesystem->get_contents( $this->locations_csv );
		if ( $csv_content ) {
			foreach ( explode( "\n", trim( $csv_content ) ) as $line ) {
				$line = trim( $line );
				if ( $line ) {
					$this->location_data[] = str_getcsv( $line, ',', '"', '' );
				}
			}
		}
		$this->instagram_post_iterator( $json_data );
	}

	/**
	 * Registers a custom taxonomy if it does not already exist.
	 *
	 * @param array $taxonomy Taxonomy config with 'name', 'singular', and 'plural' keys.
	 */
	private function ensure_taxonomy_exists( $taxonomy ) {
		$category = get_taxonomy( $taxonomy['name'] );
		if ( ! $category ) {
			register_taxonomy(
				$taxonomy['name'],
				array( 'post' ),
				array(
					'hierarchical'          => false,
					'description'           => 'Category used for imported Instagram posts',
					'show_ui'               => true,
					'show_in_menu'          => true,
					'show_in_nav_menus'     => true,
					// Labels displayed in the WordPress Admin UI.
					'label'                 => $taxonomy['singular'],
					'labels'                => array(
						'name'              => $taxonomy['plural'],
						'singular_name'     => $taxonomy['singular'],
						'search_items'      => 'Search ' . $taxonomy['plural'],
						'all_items'         => 'All ' . $taxonomy['plural'],
						'parent_item'       => 'Parent ' . $taxonomy['singular'],
						'parent_item_colon' => 'Parent ' . $taxonomy['singular'] . ':',
						'edit_item'         => 'Edit ' . $taxonomy['singular'],
						'update_item'       => 'Update ' . $taxonomy['singular'],
						'add_new_item'      => 'Add New ' . $taxonomy['singular'],
						'new_item_name'     => 'New ' . $taxonomy['singular'] . ' Name',
						'menu_name'         => $taxonomy['plural'],
					),
					'public'                => true,
					'publicly_queryable'    => true,
					'query_var'             => true,
					'show_admin_column'     => true,
					'show_in_rest'          => true,
					'show_tagcloud'         => false,
					'rest_base'             => sanitize_title( $taxonomy['plural'] ),
					'rest_controller_class' => 'WP_REST_Terms_Controller',
					'rest_namespace'        => 'wp/v2',
					'show_in_quick_edit'    => false,
					'sort'                  => true,
					'show_in_graphql'       => false,
					// Slug configuration for this taxonomy.
					'rewrite'               => array(
						'slug'         => sanitize_title( $taxonomy['singular'] ),
						'with_front'   => false,
						'hierarchical' => true,
					),
				)
			);
		}
	}

	/**
	 * Reads and decodes the Instagram JSON feed file.
	 *
	 * @return array|null Parsed JSON data, or null on read/decode failure.
	 */
	private function parse_json_feed(): ?array {
		$json_object = file_get_contents( $this->json_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json_object ) {
			return null;
		}
		return json_decode( $json_object, true );
	}

	/**
	 * Iterates over all Instagram posts and processes each one.
	 *
	 * @param array $grams Array of Instagram post objects from the archive.
	 */
	private function instagram_post_iterator( $grams ) {
		foreach ( $grams as $gram ) {
			$this->parse_instagram_post( $gram );
		}
	}

	/**
	 * Parses a single Instagram post and creates a WordPress post from it.
	 *
	 * @throws \JsonException If EXIF JSON encoding fails.
	 *
	 * @param array $grampost Single Instagram post object from the archive.
	 */
	private function parse_instagram_post( $grampost ) {
		$source_uri = $grampost['media'][0]['uri'] ?? '';
		if ( $source_uri ) {
			$existing = get_posts(
				array(
					'post_type'     => 'post',
					'post_status'   => 'any',
					'meta_key'      => '_ig_source_uri', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'    => $source_uri,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'        => 'ids',
					'numberposts'   => 1,
					'no_found_rows' => true,
				)
			);
			if ( ! empty( $existing ) ) {
				++$this->skipped;
				return;
			}
		}

		$post_content = '';
		$title        = '';
		$time         = time();
		foreach ( $grampost['media'] as $grampic ) {
			$time  = $grampic['creation_timestamp'];
			$title = mb_convert_encoding( $grampic['title'], 'ISO-8859-1', 'UTF-8' );
			$exif  = '';
			if ( isset( $grampic['media_metadata']['photo_metadata']['exif_data'] ) ) {
				$exif = wp_json_encode(
					reset( $grampic['media_metadata']['photo_metadata']['exif_data'] ),
					JSON_PRETTY_PRINT
				);
			}

			$image_id = $this->upload_image(
				trailingslashit( $this->export_uri ) . $grampic['uri'],
				$this->fmt_time( $time, true ),
				$title,
				$exif,
			);

			if ( ! is_wp_error( $image_id ) ) {
				if ( isset( $grampic['media_metadata']['video_metadata'] ) ) {
					$post_content .= $this->get_video_html( $image_id );
				} else {
					$post_content .= $this->get_image_html( $image_id );
				}
			} else {
				$source = trailingslashit( $this->export_uri ) . $grampic['uri'];
				$this->add_error( $source . ': ' . $image_id->get_error_message() );
			}
		}
		if ( array_key_exists( 'title', $grampost ) ) {
			$title = mb_convert_encoding( $grampost['title'], 'ISO-8859-1', 'UTF-8' );
		}
		if ( array_key_exists( 'creation_timestamp', $grampost ) ) {
			$time = $grampost['creation_timestamp'];
		}
		$post_content .= $this->get_paragraph_html( $title );

		$this->add_post(
			array(
				'post_title'   => $title,
				'post_time'    => $this->fmt_time( $time, true ),
				'post_content' => $post_content,
			)
		);

		if ( $source_uri ) {
			add_post_meta( $this->post_id, '_ig_source_uri', $source_uri );
		}

		$this->parse_and_add_tags( $title );
		// Add location if available.
		$this->add_location( $time );
	}

	/**
	 * Collects an error message for display after the import completes.
	 *
	 * @param \WP_Error|string $error WP_Error object or plain error message string.
	 */
	private function add_error( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->errors[] = $error->get_error_message();
		} else {
			$this->errors[] = (string) $error;
		}
	}

	/**
	 * Generates Gutenberg image block HTML for a WordPress attachment.
	 *
	 * @param int         $image_id   WordPress attachment ID.
	 * @param string|null $figcaption Optional caption HTML string.
	 * @return string Gutenberg image block HTML.
	 */
	public function get_image_html( $image_id, $figcaption = null ): string {

		if ( ! empty( $figcaption ) ) {
			$figcaption = <<<FC
<figcaption class="wp-element-caption">$figcaption</figcaption>
FC;
		} else {
			$figcaption = '';
		}

		$image_url    = wp_get_attachment_url( $image_id );
		$image_alt    = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
		$post_content = <<<POST
<!-- wp:image {"lightbox":{"enabled":true},"id":$image_id,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="$image_url" alt="$image_alt" class="wp-image-$image_id"/>$figcaption</figure>
<!-- /wp:image -->
POST;

		return $post_content;
	}

	/**
	 * Generates Gutenberg video block HTML for a WordPress attachment.
	 *
	 * @param int         $video_id   WordPress attachment ID.
	 * @param string|null $figcaption Optional caption HTML string.
	 * @return string Gutenberg video block HTML.
	 */
	public function get_video_html( $video_id, $figcaption = null ): string {

		if ( ! empty( $figcaption ) ) {
			$figcaption = <<<FC
<figcaption class="wp-element-caption">$figcaption</figcaption>
FC;
		} else {
			$figcaption = '';
		}

		$video_url    = wp_get_attachment_url( $video_id );
		$video_alt    = get_post_meta( $video_id, '_wp_attachment_image_alt', true );
		$post_content = <<<POST
<!-- wp:video {"id":$video_id} -->
<figure class="wp-block-video"><video controls src="$video_url"></video>$figcaption</figure>
<!-- /wp:image -->
POST;

		return $post_content;
	}

	/**
	 * Generates a Gutenberg paragraph block wrapping the given text.
	 *
	 * @param string $content Text content for the paragraph.
	 * @return string Gutenberg paragraph block HTML.
	 */
	public function get_paragraph_html( $content ) {
		$post_content = <<<POST
<!-- wp:paragraph -->
<p>$content</p>
<!-- /wp:paragraph -->
POST;

		return $post_content;
	}

	/**
	 * Inserts a WordPress post and assigns it to the photography category.
	 *
	 * @param array $post Post data with 'post_title', 'post_time', and 'post_content' keys.
	 */
	public function add_post( $post ) {
		$wordpress_post = array(
			'post_title'   => $post['post_title'],
			'post_content' => $post['post_content'],
			'post_status'  => $this->post_status,
			'post_author'  => $this->user_id,
			'post_type'    => 'post',
			'post_date'    => $post['post_time'],
			'tax_input'    => array( 'post_format' => 'post-format-image' ),
		);
		$this->post_id  = wp_insert_post( $wordpress_post );

		$cat = get_category_by_slug( $this->category_name );
		wp_set_post_terms( $this->post_id, array( $cat->term_id ), 'category', false );
	}

	/**
	 * Formats a Unix timestamp or date string as a MySQL datetime string.
	 *
	 * @param int|string $timestring Unix timestamp (when $is_timestamp is true) or date string.
	 * @param bool       $is_timestamp When true, treat $timestring as a Unix timestamp integer.
	 * @return string MySQL-formatted datetime string (Y-m-d H:i:s).
	 */
	public function fmt_time( $timestring, $is_timestamp = false ) {
		if ( $is_timestamp ) {
			$date = (int) $timestring;
		} else {
			$date = strtotime( $timestring );
		}

		return gmdate( 'Y-m-d H:i:s', $date );
	}

	/**
	 * Downloads a remote media file and registers it as a WordPress attachment.
	 *
	 * @param string $file        URL of the remote media file.
	 * @param string $time        MySQL datetime string for the attachment post date.
	 * @param string $caption     Caption used as alt text and attachment title.
	 * @param string $description EXIF data JSON stored as attachment post content.
	 * @return int|\WP_Error Attachment post ID, or WP_Error on failure.
	 */
	public function upload_image( $file, $time, $caption, $description ) {
		if ( ! $this->sslverify ) {
			$disable_ssl = static function ( $args ) {
				$args['sslverify'] = false;
				return $args;
			};
			add_filter( 'http_request_args', $disable_ssl );
		}

		$file_array = array(
			'name'     => wp_basename( $file ),
			'tmp_name' => download_url( $file ),
		);

		if ( ! $this->sslverify ) {
			remove_filter( 'http_request_args', $disable_ssl );
		}

		// If error storing temporarily, return the error.
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return $file_array['tmp_name'];
		}

		$id = media_handle_sideload(
			$file_array,
			0,
			$caption,
			array(
				'post_date'    => $time,
				'image_alt'    => $caption,
				'post_content' => $description,
			)
		);
		add_post_meta( $id, '_wp_attachment_image_alt', $caption );
		add_post_meta( $id, '_source_url', $file );

		// Clean up the temp file on sideload failure.
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file_array['tmp_name'] );

			return $id;
		}

		return $id;
	}

	/**
	 * Displays import results as admin notices: success count and any errors.
	 */
	public function display_notice() {
		wp_admin_notice(
			__( 'Instagram import complete.', 'b35-instagram-archive-importer' ),
			array( 'type' => 'success' )
		);

		if ( $this->skipped > 0 ) {
			wp_admin_notice(
				sprintf(
					// translators: %d: number of skipped posts.
					_n( '%d post was already imported and skipped.', '%d posts were already imported and skipped.', $this->skipped, 'b35-instagram-archive-importer' ),
					$this->skipped
				),
				array( 'type' => 'info' )
			);
		}

		if ( ! empty( $this->errors ) ) {
			$items   = implode( '', array_map( fn( $e ) => '<li>' . esc_html( $e ) . '</li>', $this->errors ) );
			$message = sprintf(
				// translators: %d: number of errors.
				_n( '%d error occurred during import:', '%d errors occurred during import:', count( $this->errors ), 'b35-instagram-archive-importer' ),
				count( $this->errors )
			) . '<ul>' . $items . '</ul>';
			wp_admin_notice(
				$message,
				array(
					'type'           => 'error',
					'paragraph_wrap' => false,
				)
			);
		}
	}

	/**
	 * Extracts hashtags from a caption and assigns them as ig_tag taxonomy terms.
	 *
	 * @param string $text Caption text from the Instagram post.
	 */
	public function parse_and_add_tags( $text ) {
		$parts = explode( ' ', $text );
		$tags  = array_map(
			function ( $tagstring ) {
				return substr( $tagstring, 1 );
			},
			array_filter(
				$parts,
				function ( $part ) {
					return str_starts_with( $part, '#' );
				}
			)
		);
		wp_set_object_terms( $this->post_id, $tags, $this->ig_tag_taxonomy['name'], false );
	}

	/**
	 * Matches a Unix timestamp to a CSV location row and assigns the ig_location term.
	 *
	 * Matching uses timestamp because Instagram's Meta export omits location IDs.
	 *
	 * @param int $time Unix timestamp of the Instagram post.
	 */
	public function add_location( $time ) {
		$location_list = array_filter(
			$this->location_data,
			function ( $location ) use ( $time ) {
				return (string) $location[0] === (string) $time;
			}
		);
		if ( count( $location_list ) > 0 ) {
			$location = reset( $location_list );
			if ( count( $location ) > 1 ) {
				if ( ! term_exists( $location[1], $this->ig_location_taxonomy['name'] ) ) {
					$term_info = wp_insert_term( $location[1], $this->ig_location_taxonomy['name'] );
					add_term_meta(
						$term_info['term_taxonomy_id'],
						'latlong',
						$location[2] . ',' . $location[3]
					);
				}
				wp_set_object_terms( $this->post_id, $location[1], $this->ig_location_taxonomy['name'], false );
			}
		} else {
			$this->add_error(
				sprintf(
					// translators: 1: Location timestamp, 2: WordPress post ID.
					__( 'Location %1$s not found for post id %2$s', 'b35-instagram-archive-importer' ),
					$time,
					$this->post_id
				)
			);
		}
	}

	/**
	 * Adds a meta entry to the current post.
	 *
	 * @param string $name  Meta key.
	 * @param mixed  $value Meta value.
	 */
	public function add_meta( $name, $value ) {
		add_post_meta( $this->post_id, $name, $value );
	}

	/**
	 * Imports the first post from the JSON archive and stores a structured report as a transient.
	 */
	private function run_test_import(): void {
		$this->errors  = array();
		$this->skipped = 0;
		$this->settings();
		$this->ensure_taxonomy_exists( $this->ig_tag_taxonomy );
		$this->ensure_taxonomy_exists( $this->ig_location_taxonomy );

		$json_data = $this->parse_json_feed();
		if ( null === $json_data ) {
			$this->store_test_report( array( 'fatal' => __( 'Could not read or decode the JSON file.', 'b35-instagram-archive-importer' ) ) );
			return;
		}

		$first = reset( $json_data );
		if ( ! $first ) {
			$this->store_test_report( array( 'fatal' => __( 'No posts found in the JSON file.', 'b35-instagram-archive-importer' ) ) );
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$csv_content = $wp_filesystem->get_contents( $this->locations_csv );
		if ( $csv_content ) {
			foreach ( explode( "\n", trim( $csv_content ) ) as $line ) {
				$line = trim( $line );
				if ( $line ) {
					$this->location_data[] = str_getcsv( $line, ',', '"', '' );
				}
			}
		}

		$this->parse_instagram_post( $first );

		if ( $this->skipped > 0 ) {
			$this->store_test_report( array( 'skipped' => true ) );
			return;
		}

		$post      = get_post( $this->post_id );
		$children  = get_children(
			array(
				'post_parent' => $this->post_id,
				'post_type'   => 'attachment',
				'numberposts' => -1,
			)
		);
		$tags      = wp_get_object_terms( $this->post_id, $this->ig_tag_taxonomy['name'], array( 'fields' => 'names' ) );
		$locations = wp_get_object_terms( $this->post_id, $this->ig_location_taxonomy['name'], array( 'fields' => 'names' ) );

		$media = array();
		foreach ( $children as $child ) {
			$media[] = array(
				'id'         => $child->ID,
				'filename'   => basename( (string) get_attached_file( $child->ID ) ),
				'type'       => strstr( $child->post_mime_type, '/', true ),
				'source_url' => (string) get_post_meta( $child->ID, '_source_url', true ),
			);
		}

		$this->store_test_report(
			array(
				'post_id'    => $this->post_id,
				'post_title' => $post ? $post->post_title : '',
				'post_date'  => $post ? $post->post_date : '',
				'edit_url'   => get_edit_post_link( $this->post_id, 'raw' ),
				'view_url'   => get_permalink( $this->post_id ),
				'media'      => $media,
				'tags'       => is_wp_error( $tags ) ? array() : $tags,
				'location'   => ( ! is_wp_error( $locations ) && ! empty( $locations ) ) ? $locations[0] : null,
				'errors'     => $this->errors,
			)
		);
	}

	/**
	 * Stores the test import report as a short-lived transient keyed to the current user.
	 *
	 * @param array $report Report data to store.
	 */
	private function store_test_report( array $report ): void {
		set_transient( 'b35_ig_test_import_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}
}
