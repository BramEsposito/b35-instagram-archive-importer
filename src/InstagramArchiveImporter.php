<?php

// TODO: what happened to the video posts?
// TODO: import galleries
// TODO: add wp_nonces

class InstagramArchiveImporter
{
    private string $category_name;

    private array $ig_tag_taxonomy;

    private array $ig_location_taxonomy;

    private int $post_id;

    private string $plugin_root;

    private string $json_file;

    private string $export_uri;

    private int $user_id;

    private array $location_data = [];

    public function __construct($plugin_root)
    {
        $this->plugin_root = $plugin_root;
        add_action('admin_init', [$this, 'admin_init']);
        add_action('init', [$this, 'init']);
    }

    public function settings()
    {
        $upload_data = wp_upload_dir();
        $upload_dir = $upload_data['basedir'];

        // TODO: set via WP Admin UI
        $this->user_id = get_current_user_id();
        $this->category_name = 'photography';
        $this->ig_tag_taxonomy = [
            'name' => 'ig_tag',
            'singular' => 'photo tag',
            'plural' => 'photo tags',
        ];
        $this->ig_location_taxonomy = [
            'name' => 'ig_location',
            'singular' => 'location',
            'plural' => 'locations',
        ];
        // $this->category_name = get_option("instagram_archive_importer_category");
        // TODO: set via WP Admin UI
        $this->export_uri = 'https://bramesposito.com/wp-content/uploads/instagram/';
        // TODO: upload from WP Admin UI and store in upload_dir wp_upload_dir()
        $this->json_file = $upload_dir.'/posts_1.json'; // temporary use hardcoded file
        $this->locations_csv = $upload_dir.'/locations.csv'; // temporary use hardcoded file
    }

    public function init()
    {
        $this->settings();
        $this->ensure_taxonomy_exists($this->ig_tag_taxonomy);
        $this->ensure_taxonomy_exists($this->ig_location_taxonomy);
        add_filter('term_link', [$this, 'set_location_term_link'], 25, 3);
        $taxonomy = $this->ig_location_taxonomy['name'];
        add_filter("term_links-{$taxonomy}", [$this, 'set_location_term_link_target'], 25, 1);

        add_action('add_meta_boxes', [$this, 'add_meta_box']);
    }

    public function add_meta_box($links)
    {
        remove_meta_box('tagsdiv-ig_location', 'post', 'side');
    }

    public function set_location_term_link_target($links)
    {
        return array_map(function ($link) {
            return str_replace('rel="tag"', 'target="_blank" rel="nofollow"', $link);
        }, $links);
    }

    public function set_location_term_link($termlink, $term, $taxonomy)
    {
        if ($taxonomy === $this->ig_location_taxonomy['name']) {
            $latlong = get_term_meta($term->term_id, 'latlong', true);

            return sprintf('https://maps.google.com/?q=%s', $latlong);
        }

        return $termlink;
    }

    public function admin_init()
    {
        if ($_GET['action'] === 'instagram-archive-importer-import') {
            $this->init_import();
            add_action('admin_notices', [$this, 'display_notice']);
        }
    }

    private function init_import()
    {
        $this->settings();

        $this->ensure_taxonomy_exists($this->ig_tag_taxonomy);
        $this->ensure_taxonomy_exists($this->ig_location_taxonomy);
        $json_data = $this->parse_json_feed();
        if (($handle = fopen($this->locations_csv, 'rb')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $this->location_data[] = $data;
            }
        }
        $this->instagram_post_iterator($json_data);
    }

    private function ensure_taxonomy_exists($taxonomy)
    {
        $category = get_taxonomy($taxonomy['name']);
        if (! $category) {
            register_taxonomy($taxonomy['name'], ['post'], [
                'hierarchical' => false,
                'description' => 'Category used for imported Instagram posts',
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_nav_menus' => true,
                // This array of options controls the labels displayed in the WordPress Admin UI
                'label' => $taxonomy['singular'],
                'labels' => [
                    'name' => _x($taxonomy['plural'], 'instagram-archive-importer'),
                    'singular_name' => _x($taxonomy['singular'], 'instagram-archive-importer'),
                    'search_items' => __('Search '.$taxonomy['plural'], 'instagram-archive-importer'),
                    'all_items' => __('All '.$taxonomy['plural'], 'instagram-archive-importer'),
                    'parent_item' => __('Parent '.$taxonomy['singular'], 'instagram-archive-importer'),
                    'parent_item_colon' => __('Parent '.$taxonomy['singular'].':', 'instagram-archive-importer'),
                    'edit_item' => __('Edit '.$taxonomy['singular'], 'instagram-archive-importer'),
                    'update_item' => __('Update '.$taxonomy['singular'], 'instagram-archive-importer'),
                    'add_new_item' => __('Add New '.$taxonomy['singular'], 'instagram-archive-importer'),
                    'new_item_name' => __('New '.$taxonomy['singular'].' Name', 'instagram-archive-importer'),
                    'menu_name' => __($taxonomy['plural'], 'instagram-archive-importer'),
                ],
                'public' => true,
                'publicly_queryable' => true,
                'query_var' => true,
                'show_admin_column' => true,
                'show_in_rest' => true,
                'show_tagcloud' => false,
                'rest_base' => sanitize_title($taxonomy['plural']),
                'rest_controller_class' => 'WP_REST_Terms_Controller',
                'rest_namespace' => 'wp/v2',
                'show_in_quick_edit' => false,
                'sort' => true,
                'show_in_graphql' => false,
                // Control the slugs used for this taxonomy
                'rewrite' => [
                    'slug' => sanitize_title($taxonomy['singular']), // This controls the base slug that will display before each term
                    'with_front' => false, // Don't display the category base before "/locations/"
                    'hierarchical' => true, // This will allow URL's like "/locations/boston/cambridge/"
                ],
            ]);
        }
    }

    private function parse_json_feed()
    {
        $json_object = file_get_contents($this->json_file);
        $json_data = json_decode($json_object, true);
        if ($json_data === null) {
            exit('Error decoding the JSON file');
        }

        return $json_data;
    }

    private function instagram_post_iterator($grams)
    {
        foreach ($grams as $gram) {
            $this->parse_instagram_post($gram);
        }
    }

    /**
     * @throws \JsonException
     */
    private function parse_instagram_post($grampost)
    {
        $post_content = '';
        $title = '';
        $time = time();
        foreach ($grampost['media'] as $grampic) {
            $time = $grampic['creation_timestamp'];
            $title = mb_convert_encoding($grampic['title'], 'ISO-8859-1', 'UTF-8');
            $exif = '';
            if (isset($grampic['media_metadata']['photo_metadata']['exif_data'])) {
                $exif = json_encode(
                    reset($grampic['media_metadata']['photo_metadata']['exif_data']),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                );
            }

            $image_id = $this->upload_image(
                trailingslashit($this->export_uri).$grampic['uri'],
                $this->fmt_time($time, true),
                $title,
                $exif,
            );

            if (! is_wp_error($image_id)) {
                // add html
                if (isset($grampic['media_metadata']['video_metadata'])) {
                    $post_content .= $this->get_video_html($image_id);
                } else {
                    $post_content .= $this->get_image_html($image_id);
                }
            } else {
                $this->add_error($image_id);
            }
        }
        if (array_key_exists('title', $grampost)) {
            $title = mb_convert_encoding($grampost['title'], 'ISO-8859-1', 'UTF-8');
        }
        if (array_key_exists('creation_timestamp', $grampost)) {
            $time = $grampost['creation_timestamp'];
        }
        $post_content .= $this->get_paragraph_html($title);

        $this->add_post([
            'post_title' => $title,
            'post_time' => $this->fmt_time($time, true),
            'post_content' => $post_content,
        ]);

        $this->parse_and_add_tags($title);
        // Add location if available
        $this->add_location($time);
    }

    private function add_error($error)
    {
        // TODO: implement
    }

    public function get_image_html($image_id, $figcaption = null): string
    {

        if (! empty($figcaption)) {
            $figcaption = <<<FC
<figcaption class="wp-element-caption">$figcaption</figcaption>
FC;
        } else {
            $figcaption = '';
        }

        $image_url = wp_get_attachment_url($image_id);
        $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $post_content = <<<POST
<!-- wp:image {"lightbox":{"enabled":true},"id":$image_id,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="$image_url" alt="$image_alt" class="wp-image-$image_id"/>$figcaption</figure>
<!-- /wp:image -->
POST;

        return $post_content;
    }

    public function get_video_html($video_id, $figcaption = null): string
    {

        if (! empty($figcaption)) {
            $figcaption = <<<FC
<figcaption class="wp-element-caption">$figcaption</figcaption>
FC;
        } else {
            $figcaption = '';
        }

        $video_url = wp_get_attachment_url($video_id);
        $video_alt = get_post_meta($video_id, '_wp_attachment_image_alt', true);
        $post_content = <<<POST
<!-- wp:video {"id":$video_id} -->
<figure class="wp-block-video"><video controls src="$video_url"></video>$figcaption</figure>
<!-- /wp:image -->
POST;

        return $post_content;
    }

    public function get_paragraph_html($string)
    {
        $post_content = <<<POST
<!-- wp:paragraph -->
<p>$string</p>
<!-- /wp:paragraph -->
POST;

        return $post_content;
    }

    public function add_post($post)
    {
        /*
             'post_author'           => $user_id,
            'post_content'          => '',
            'post_content_filtered' => '',
            'post_title'            => '',
            'post_excerpt'          => '',
            'post_status'           => 'draft',
            'post_type'             => 'post',
            'comment_status'        => '',
            'ping_status'           => '',
            'post_password'         => '',
            'to_ping'               => '',
            'pinged'                => '',
            'post_parent'           => 0,
            'menu_order'            => 0,
            'guid'                  => '',
            'import_id'             => 0,
            'context'               => '',
            'post_date'             => '',
            'post_date_gmt'         => '',
         */

        $wordpress_post = [
            'post_title' => $post['post_title'],
            'post_content' => $post['post_content'],
            'post_status' => 'publish',
            'post_author' => $this->user_id,
            'post_type' => 'post',
            'post_date' => $post['post_time'],
            'tax_input' => ['post_format' => 'post-format-image'],
        ];
        $this->post_id = wp_insert_post($wordpress_post);

        $cat = get_category_by_slug($this->category_name);
        wp_set_post_terms($this->post_id, [$cat->term_id], 'category', false);
    }

    public function fmt_time($timestring, $millis = false)
    {
        if (! $millis) {
            $date = strtotime($timestring);
        } else {
            $date = date($timestring);
        }

        return date('Y-m-d H:i:s', $date);
    }

    public function upload_image($file, $time, $caption, $description)
    {
        $file_array = ['name' => wp_basename($file), 'tmp_name' => download_url($file)];

        // If error storing temporarily, return the error.
        if (is_wp_error($file_array['tmp_name'])) {
            return $file_array['tmp_name'];
        }

        // Do the validation and storage stuff.
        $id = media_handle_sideload($file_array, 0, $caption, ['post_date' => $time, 'image_alt' => $caption, 'post_content' => $description]);
        // Add Image Alt data
        add_post_meta($id, '_wp_attachment_image_alt', $caption);

        // If error storing permanently, unlink.
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);

            return $id;
        }

        return $id;
    }

    public function display_notice()
    {
        wp_admin_notice(
            __('Importing instagram posts'),
            [
                'type' => 'success',
            ]
        );
    }

    public function parse_and_add_tags($text)
    {
        $parts = explode(' ', $text);
        $tags = array_map(
            function ($tagstring) {
                return substr($tagstring, 1);
            },
            array_filter($parts, function ($part) {
                return $part[0] === '#';
            })
        );
        wp_set_object_terms($this->post_id, $tags, $this->ig_tag_taxonomy['name'], false);
    }

    public function add_location($time)
    {
        // We try to match posts based on time, because id's are omitted from the Meta export
        $location_list = array_filter($this->location_data, function ($location) use ($time) {
            return (string) $location[0] === (string) $time;
        });
        if (count($location_list) > 0) {
            $location = reset($location_list);
            // Check if location exists in taxonomy
            if (count($location) > 1) {
                // name and latlong exist for this timestamp entry
                if (! term_exists($location[1], $this->ig_location_taxonomy['name'])) {
                    // Add tag with lat long metadata
                    $term_info = wp_insert_term($location[1], $this->ig_location_taxonomy['name']);
                    add_term_meta(
                        $term_info['term_taxonomy_id'],
                        'latlong',
                        $location[2].','.$location[3]
                    );
                }
                wp_set_object_terms($this->post_id, $location[1], $this->ig_location_taxonomy['name'], false);
            }
        } else {
            $this->add_error(
                sprintf(
                    __('Location %s not found for post id %s', 'instagram-archive-importer'),
                    $time,
                    $this->post_id
                )
            );
        }
    }

    public function add_meta($name, $value)
    {
        add_post_meta($this->post_id, $name, $value);
    }
}
