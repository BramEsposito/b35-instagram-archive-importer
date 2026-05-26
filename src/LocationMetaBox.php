<?php

class LocationMetaBox
{
    private string $plugin_url;

    public function __construct(string $plugin_url)
    {
        $this->plugin_url = $plugin_url;
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('enqueue_block_editor_assets', [$this, 'remove_block_editor_panel']);
    }

    public function add_meta_box(): void
    {
        add_meta_box('ig_location', 'Location', [$this, 'render'], 'post', 'side');
        remove_meta_box('tagsdiv-ig_location', 'post', 'side');
    }

    // The block editor renders taxonomy panels via React — JS is the only way to hide it.
    public function remove_block_editor_panel(): void
    {
        $screen = get_current_screen();
        if (! $screen || ! $screen->is_block_editor() || $screen->post_type !== 'post') {
            return;
        }
        wp_add_inline_script(
            'wp-edit-post',
            "wp.domReady(function(){ wp.data.dispatch('core/editor').removeEditorPanel('taxonomy-panel-ig_location'); });"
        );
    }

    public function enqueue_scripts(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $api_key = defined('GOOGLE_PLACES_API') ? GOOGLE_PLACES_API : '';
        if (! $api_key) {
            return;
        }

        wp_enqueue_script(
            'ig-location-meta-box',
            $this->plugin_url.'js/location-meta-box.js',
            [],
            '1.0',
            true
        );

        wp_enqueue_script(
            'google-places',
            add_query_arg([
                'key' => $api_key,
                'libraries' => 'places',
                'callback' => 'igLocationInit',
                'loading' => 'async',
            ], 'https://maps.googleapis.com/maps/api/js'),
            ['ig-location-meta-box'],
            null,
            true
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('ig_location', 'ig_location_nonce');

        $terms = wp_get_object_terms($post->ID, 'ig_location');
        $name = '';
        $lat = '';
        $lon = '';
        $place_id = '';

        if (! empty($terms) && ! is_wp_error($terms)) {
            $term = $terms[0];
            $name = $term->name;
            $lat = get_term_meta($term->term_id, 'lat', true);
            $lon = get_term_meta($term->term_id, 'lon', true);
            $place_id = get_term_meta($term->term_id, 'place_id', true);
        }

        $has = ! empty($name);
        ?>
        <p>
            <input type="text" id="ig_location_search"
                   placeholder="Search for a location…"
                   style="width:100%;box-sizing:border-box"
                   autocomplete="off">
        </p>
        <p>
            <label for="ig_location_name" style="display:block;font-weight:600;margin-bottom:3px">
                Display name
            </label>
            <input type="text" id="ig_location_name" name="ig_location_name"
                   value="<?= esc_attr($name) ?>"
                   style="width:100%;box-sizing:border-box">
        </p>
        <input type="hidden" id="ig_location_lat"      name="ig_location_lat"      value="<?= esc_attr($lat) ?>">
        <input type="hidden" id="ig_location_lon"      name="ig_location_lon"      value="<?= esc_attr($lon) ?>">
        <input type="hidden" id="ig_location_place_id" name="ig_location_place_id" value="<?= esc_attr($place_id) ?>">
        <input type="hidden" id="ig_location_changed"  name="ig_location_changed"  value="0">
        <p id="ig_location_current" class="description" <?= $has ? '' : 'style="display:none"' ?>>
            📍 <span id="ig_location_preview_name"><?= esc_html($name) ?></span>
            <br><small id="ig_location_preview_coords"><?= ($lat && $lon) ? esc_html("$lat, $lon") : '' ?></small>
            <br><a href="#" id="ig_location_clear" style="color:#b32d2e">Remove location</a>
        </p>
        <?php
    }

    public function save(int $post_id): void
    {
        if (! isset($_POST['ig_location_nonce'])) {
            return;
        }
        if (! wp_verify_nonce($_POST['ig_location_nonce'], 'ig_location')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $name = sanitize_text_field($_POST['ig_location_name'] ?? '');
        $lat = sanitize_text_field($_POST['ig_location_lat'] ?? '');
        $lon = sanitize_text_field($_POST['ig_location_lon'] ?? '');
        $place_id = sanitize_text_field($_POST['ig_location_place_id'] ?? '');
        $changed = ($_POST['ig_location_changed'] ?? '0') === '1';

        // Empty name = clear location
        if (empty($name)) {
            wp_set_object_terms($post_id, [], 'ig_location');

            return;
        }

        // No new place selected — only update the display name on the existing term
        if (! $changed) {
            $existing = wp_get_object_terms($post_id, 'ig_location');
            if (! empty($existing) && ! is_wp_error($existing)) {
                $term = $existing[0];
                if ($term->name !== $name) {
                    wp_update_term($term->term_id, 'ig_location', ['name' => $name]);
                }
            }

            return;
        }

        // New place selected — find existing term by place_id or create one
        $term_id = null;

        if ($place_id) {
            $found = get_terms([
                'taxonomy' => 'ig_location',
                'hide_empty' => false,
                'meta_query' => [['key' => 'place_id', 'value' => $place_id]],
                'number' => 1,
            ]);
            if (! empty($found) && ! is_wp_error($found)) {
                $term_id = $found[0]->term_id;
                wp_update_term($term_id, 'ig_location', ['name' => $name]);
            }
        }

        if (! $term_id) {
            $result = wp_insert_term($name, 'ig_location');
            if (is_wp_error($result)) {
                $existing_term = get_term_by('name', $name, 'ig_location');
                if (! $existing_term) {
                    return;
                }
                $term_id = $existing_term->term_id;
            } else {
                $term_id = $result['term_id'];
            }
        }

        if ($place_id) {
            update_term_meta($term_id, 'place_id', $place_id);
        }
        if ($lat) {
            update_term_meta($term_id, 'lat', $lat);
        }
        if ($lon) {
            update_term_meta($term_id, 'lon', $lon);
        }
        if ($lat && $lon) {
            update_term_meta($term_id, 'latlong', "$lat,$lon");
        }

        wp_set_object_terms($post_id, $term_id, 'ig_location', false);
    }
}
