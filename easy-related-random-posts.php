<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/*
 * Plugin Name: ERRP: Enhanced Related Random Posts
 * Description: An enhanced plugin for displaying related or random posts to improve user engagement and content discovery.
 * Version:           2.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Boyd Duang
 * Author URI:        https://profiles.wordpress.org/mrboydwp/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Shortcode to display related or random posts
function errp_related_random_posts_shortcode($atts) {
    $options = get_option('errp_settings', array());
    
    // Get settings or use defaults
    $position = isset($options['errp_position']) ? $options['errp_position'] : 'after_content';
    $show_image = isset($options['errp_show_image']) ? (bool)$options['errp_show_image'] : false;
    $headline = isset($options['errp_headline']) ? $options['errp_headline'] : 'Related Posts';
    $limit = isset($options['errp_limit']) ? $options['errp_limit'] : 5;
    $display_type = isset($options['errp_display_type']) ? $options['errp_display_type'] : 'related';
    $layout = isset($options['errp_layout']) ? $options['errp_layout'] : 'list';
    $show_excerpt = isset($options['errp_show_excerpt']) ? (bool)$options['errp_show_excerpt'] : false;
    $excerpt_length = isset($options['errp_excerpt_length']) ? intval($options['errp_excerpt_length']) : 55;
    $cache_time = isset($options['errp_cache_time']) ? intval($options['errp_cache_time']) : 1800;

    // Only show on single posts or pages
    if (!is_singular('post') && !is_page()) {
        return '';
    }

    // Merge default settings with shortcode attributes
    $atts = shortcode_atts(array(
        'type' => $display_type,
        'layout' => $layout,
        'show_image' => $show_image,
        'show_excerpt' => $show_excerpt,
    ), $atts);

    // Validate and sanitize attributes
    $type = sanitize_text_field($atts['type']);
    $layout = sanitize_text_field($atts['layout']);
    $show_image = filter_var($atts['show_image'], FILTER_VALIDATE_BOOLEAN);
    $show_excerpt = filter_var($atts['show_excerpt'], FILTER_VALIDATE_BOOLEAN);

    // Cache key
    $cache_key = 'errp_' . $type . '_' . $layout . '_' . $limit . '_' . ($show_image ? '1' : '0') . '_' . ($show_excerpt ? '1' : '0') . '_' . get_the_ID();

    // Check if caching is enabled and data is cached
    if ($cache_time > 0) {
        $output = get_transient($cache_key);
        if (false !== $output) {
            return $output;
        }
    }

    // Get posts based on display type
    if ($type === 'random') {
        $posts = errp_get_random_posts($limit);
    } else {
        $posts = errp_get_related_posts(get_the_ID(), $limit);
    }

    $output = '';
    
    ob_start();

    if (!empty($posts)) {
        echo '<div class="errp-posts errp-layout-' . esc_attr($layout) . '">';
        echo '<h4 class="errp-headline">' . esc_html($headline) . '</h4>';
        
        if ($layout === 'grid') {
            echo '<div class="errp-grid">';
        } else {
            echo '<ul class="errp-list">';
        }
        
        $no_image_handling = isset($options['errp_no_image_handling']) ? $options['errp_no_image_handling'] : 'placeholder';
    
        foreach ($posts as $post) {
            setup_postdata($post);
            $post_id = $post->ID;
            
            if ($layout === 'grid') {
                echo '<div class="errp-grid-item">';
            } else {
                echo '<li class="errp-list-item">';
            }
            
            if ($show_image && $no_image_handling !== 'text_only') {
                echo errp_get_post_thumbnail($post_id, $no_image_handling);
            }
    
            echo '<div class="errp-content">';
            echo '<h5 class="errp-title"><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html(get_the_title($post_id)) . '</a></h5>';
      
            if ($show_excerpt) {
                $excerpt = errp_get_custom_excerpt($post_id, $excerpt_length);
                if (!empty($excerpt)) {
                    echo '<div class="errp-excerpt">' . $excerpt . '</div>';
                }
            }
            echo '</div>'; // Close .errp-content
        
            if ($layout === 'grid') {
                echo '</div>'; // Close .errp-grid-item
            } else {
                echo '</li>'; // Close .errp-list-item
            }
        }
        
        if ($layout === 'grid') {
            echo '</div>'; // Close grid
        } else {
            echo '</ul>'; // Close list
        }
    
        echo '</div>'; // Close .errp-posts
    
        wp_reset_postdata();
    }

    $output = ob_get_clean();

    // Cache the output if caching is enabled
    if ($cache_time > 0) {
        set_transient($cache_key, $output, $cache_time);
    }

    return $output;
}

// Get related posts
function errp_get_related_posts($post_id, $limit) {
    $options = get_option('errp_settings', array());
    $cache_time = isset($options['errp_related_cache_time']) ? intval($options['errp_related_cache_time']) : 21600;
    $cache_key = 'errp_related_posts_' . $post_id . '_' . $limit;
    $related_posts = get_transient($cache_key);

    if (false === $related_posts || $cache_time == 0) {
        $categories = wp_get_post_categories($post_id, array('fields' => 'ids'));
        $tags = wp_get_post_tags($post_id, array('fields' => 'ids'));

        $args = array(
            'post_type' => 'post',
            'posts_per_page' => $limit,
            'post__not_in' => array($post_id),
            'tax_query' => array(
                'relation' => 'OR',
                array(
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $categories,
                    'include_children' => false
                ),
                array(
                    'taxonomy' => 'post_tag',
                    'field' => 'term_id',
                    'terms' => $tags
                )
            ),
            'orderby' => 'rand',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );

        // Add filter for query args
        $args = apply_filters('errp_related_posts_query_args', $args, $post_id, $limit);

        $query = new WP_Query($args);
        $related_posts = $query->posts;

        if ($cache_time > 0) {
            set_transient($cache_key, $related_posts, $cache_time);
        }
    }

    return $related_posts;
}

// Get post thumbnail or placeholder
function errp_get_post_thumbnail($post_id, $no_image_handling) {
    $thumbnail_class = 'errp-thumbnail';
    $thumbnail_content = '';

    if (has_post_thumbnail($post_id)) {
        $thumbnail_content = get_the_post_thumbnail($post_id, 'medium');
    } else {
        switch ($no_image_handling) {
            case 'icon':
                $thumbnail_class .= ' errp-no-image';
                $thumbnail_content = '<span class="dashicons dashicons-format-image"></span>';
                break;
            case 'category':
                $thumbnail_class .= ' errp-category';
                $categories = get_the_category($post_id);
                $thumbnail_content = !empty($categories) ? esc_html($categories[0]->name) : '';
                break;
            case 'expand_content':
                $thumbnail_class = 'errp-expand-content';
                break;
            case 'hide':
                $thumbnail_class = 'errp-hide-image';
                break;
        }
    }

    return "<div class='{$thumbnail_class}'>{$thumbnail_content}</div>";
}
// Get custom excerpt
function errp_get_custom_excerpt($post_id, $length = 55) {
    $post = get_post($post_id);
    if (!$post) {
        return '';
    }

    $excerpt = $post->post_excerpt;
    if (empty($excerpt)) {
        $excerpt = $post->post_content;
    }

    $excerpt = strip_shortcodes($excerpt);
    $excerpt = wp_strip_all_tags($excerpt);
    $excerpt = wp_trim_words($excerpt, $length, '...');

    return $excerpt;
}

function errp_increase_memory_limit() {
    $current_limit = ini_get('memory_limit');
    $current_limit_int = wp_convert_hr_to_bytes($current_limit);
    
    if ($current_limit_int < 256 * 1024 * 1024) { // 256 MB
        $new_limit = '256M';
        if (wp_is_ini_value_changeable('memory_limit')) {
            @ini_set('memory_limit', $new_limit);
        } else {
            error_log('ERRP: Unable to increase memory limit. Current limit: ' . $current_limit);
        }
    }
}
add_action('init', 'errp_increase_memory_limit');

function errp_get_first_image_from_post() {
    $post = get_post();
    $content = $post->post_content;
    $first_img = '';
    $output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $content, $matches);
    if ($output) {
        $first_img = $matches[1][0];
    }
    return $first_img;
}
function errp_no_image_handling_field_callback() {
    $options = get_option('errp_settings', array());
    $no_image_handling = isset($options['errp_no_image_handling']) ? $options['errp_no_image_handling'] : 'icon';
    echo '<select name="errp_settings[errp_no_image_handling]">
        <option value="icon" ' . selected($no_image_handling, 'icon', false) . '>' . __('Use Image Icon', 'errp') . '</option>
        <option value="category" ' . selected($no_image_handling, 'category', false) . '>' . __('Show Category', 'errp') . '</option>
        <option value="expand_content" ' . selected($no_image_handling, 'expand_content', false) . '>' . __('Expand Content Area', 'errp') . '</option>
        <option value="hide" ' . selected($no_image_handling, 'hide', false) . '>' . __('Hide Image Area', 'errp') . '</option>
        <option value="text_only" ' . selected($no_image_handling, 'text_only', false) . '>' . __('Text Only (No Images)', 'errp') . '</option>
    </select>';
    echo '<p class="description">' . __('Choose how to handle posts without featured images. "Text Only" will remove all images, even for posts with featured images.', 'errp') . '</p>';
}

add_shortcode('errp_easy_related_random_posts', 'errp_related_random_posts_shortcode');
add_shortcode('errp_enhanced_posts', 'errp_related_random_posts_shortcode');

// Add settings menu
function errp_add_settings_menu() {
    add_options_page(
        'ERRP: Enhanced Related Random Posts Settings',
        'ERRP: Enhanced Related Random Posts',
        'manage_options',
        'errp-settings',
        'errp_settings_page'
    );
}
add_action('admin_menu', 'errp_add_settings_menu');

// Settings page content
function errp_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'errp'));
    }
    ?>
    <div class="wrap">
        <h2>ERRP: Enhanced Related Random Posts Settings</h2>

        <!-- Donation Links -->
        <div class="errp-donation-links" style="margin-bottom: 20px; padding: 10px; background-color: #f0f0f0; border-radius: 5px;">
            <h3><?php _e('Support the Development', 'errp'); ?></h3>
            <p><?php _e('If you find this plugin useful, please consider supporting its development:', 'errp'); ?></p>
            <a href="https://ko-fi.com/boyduang" target="_blank" class="button button-secondary" style="margin-right: 10px;">
                <?php _e('Donate via Ko-fi', 'errp'); ?>
            </a>
            <a href="https://buymeacoffee.com/boyduang" target="_blank" class="button button-secondary">
                <?php _e('Buy me a coffee', 'errp'); ?>
            </a>
        </div>


        <form method="post" action="options.php">
            <?php
            wp_nonce_field('errp_settings_nonce', 'errp_settings_nonce');
            settings_fields('errp-settings-group');
            do_settings_sections('errp-settings');
            submit_button();
            ?>
        </form>

        <h3>Cache Management</h3>
        <form method="post" action="">
            <?php wp_nonce_field('clear_cache_nonce', 'clear_cache_nonce'); ?>
            <input type="submit" name="clear_cache" class="button" value="Clear All Caches">
            <input type="submit" name="clear_related_cache" class="button" value="Clear Related Posts Cache">
            <input type="submit" name="clear_random_cache" class="button" value="Clear Random Posts Cache">
        </form>
    </div>
    <?php
}

function errp_custom_css_callback() {
    $custom_css = get_option('errp_custom_css', '');
    echo '<textarea name="errp_custom_css" rows="10" cols="50">' . esc_textarea($custom_css) . '</textarea>';
    echo '<p class="description">' . __('Custom CSS to override default styles. Example:', 'errp') . '<br>';
    echo '<code>.errp-posts { --errp-spacing: 1.5em; --errp-border-color: #ddd; }</code></p>';
}
// Get random posts
function errp_get_random_posts($limit) {
    $options = get_option('errp_settings', array());
    $cache_time = isset($options['errp_random_cache_time']) ? intval($options['errp_random_cache_time']) : 3600;
    $cache_key = 'errp_random_posts_' . $limit;
    $random_posts = get_transient($cache_key);

    if (false === $random_posts || $cache_time == 0) {
        $args = array(
            'posts_per_page' => min($limit * 3, 100),  // Fetch more posts for better randomization, but limit to 100
            'orderby' => 'rand',
            'post_type' => 'post',
            'post_status' => 'publish',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );

        // Add filter for query args
        $args = apply_filters('errp_random_posts_query_args', $args, $limit);

        $query = new WP_Query($args);
        $random_posts = $query->posts;
        shuffle($random_posts);  // Additional shuffle for better randomness
        $random_posts = array_slice($random_posts, 0, $limit);

        if ($cache_time > 0) {
            set_transient($cache_key, $random_posts, $cache_time);
        }
    }

    return $random_posts;
}
function errp_sanitize_settings($input) {
    if (!isset($_POST['errp_settings_nonce']) || !wp_verify_nonce($_POST['errp_settings_nonce'], 'errp_settings_nonce')) {
        add_settings_error('errp_messages', 'errp_message', __('Invalid nonce specified', 'errp'), 'error');
        return get_option('errp_settings', array());
    }

    $sanitized_input = array();
    // Sanitize each setting individually
    $sanitized_input['errp_headline'] = isset($input['errp_headline']) ? sanitize_text_field($input['errp_headline']) : '';
    $sanitized_input['errp_limit'] = isset($input['errp_limit']) ? min(max(intval($input['errp_limit']), 1), 10) : 5;
    if ($sanitized_input['errp_limit'] > 10) {
        add_settings_error('errp_messages', 'errp_message', __('Post limit cannot exceed 10. Value has been set to 10.', 'errp'), 'error');
        $sanitized_input['errp_limit'] = 10;
    }
    $sanitized_input['errp_display_type'] = isset($input['errp_display_type']) ? sanitize_text_field($input['errp_display_type']) : 'related';
    
    $sanitized_input['errp_layout'] = isset($input['errp_layout']) ? sanitize_text_field($input['errp_layout']) : 'list';
    $sanitized_input['errp_show_image'] = isset($input['errp_show_image']) ? 1 : 0;
    $sanitized_input['errp_show_excerpt'] = isset($input['errp_show_excerpt']) ? 1 : 0;
    $sanitized_input['errp_excerpt_length'] = isset($input['errp_excerpt_length']) ? intval($input['errp_excerpt_length']) : 55;
    $sanitized_input['errp_cache_time'] = isset($input['errp_cache_time']) ? intval($input['errp_cache_time']) : 1800;
    $sanitized_input['errp_no_image_handling'] = isset($input['errp_no_image_handling']) ? sanitize_text_field($input['errp_no_image_handling']) : 'placeholder';
    $sanitized_input['errp_position'] = isset($input['errp_position']) ? sanitize_text_field($input['errp_position']) : 'after_content';
    $sanitized_input['errp_primary_color'] = sanitize_hex_color($input['errp_primary_color']);
    $sanitized_input['errp_secondary_color'] = sanitize_hex_color($input['errp_secondary_color']);
    
    return $sanitized_input;
}
function errp_insert_related_random_posts($content) {
    // ตรวจสอบว่าเป็นหน้าหลักหรือหน้า archive หรือไม่
    if (is_front_page() || is_archive() || is_search()) {
        return $content;
    }

    $options = get_option('errp_settings', array());
    $position = isset($options['errp_position']) ? $options['errp_position'] : 'after_content';

    if ($position === 'manual') {
        return $content;
    }

    // ตรวจสอบว่าเป็น single post หรือ page
    if (is_singular('post') || is_page()) {
        $related_posts = errp_related_random_posts_shortcode(array());

        if ($position === 'before_content') {
            return $related_posts . $content;
        } else { // after_content เป็นค่าเริ่มต้น
            return $content . $related_posts;
        }
    }

    return $content;
}
add_filter('the_content', 'errp_insert_related_random_posts');

// Register and initialize settings

function errp_initialize_settings() {
    register_setting('errp-settings-group', 'errp_settings', 'errp_sanitize_settings');

    add_settings_section('errp-main-section', 'Plugin Settings', 'errp_settings_section_callback', 'errp-settings');

    add_settings_field('errp-headline-field', 'Headline Text', 'errp_headline_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-limit-field', 'Post Limit', 'errp_limit_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-display-type-field', 'Display Type', 'errp_display_type_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-layout-field', 'Layout', 'errp_layout_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-show-image-field', 'Show Featured Image', 'errp_show_image_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-show-excerpt-field', 'Show Excerpt', 'errp_show_excerpt_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-excerpt-length-field', 'Excerpt Length', 'errp_excerpt_length_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-cache-time-field', 'Cache Time (in seconds)', 'errp_cache_time_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-custom-css-field', __('Custom CSS', 'errp'), 'errp_custom_css_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-no-image-handling-field', __('No Featured Image Handling', 'errp'), 'errp_no_image_handling_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-position-field', 'Display Position', 'errp_position_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-color-fields', 'Color Settings', 'errp_color_fields_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-related-cache-time-field', 'Related Posts Cache Time (in seconds)', 'errp_related_cache_time_field_callback', 'errp-settings', 'errp-main-section');
    add_settings_field('errp-random-cache-time-field', 'Random Posts Cache Time (in seconds)', 'errp_random_cache_time_field_callback', 'errp-settings', 'errp-main-section');
    
}

function errp_related_cache_time_field_callback() {
    $options = get_option('errp_settings', array());
    $cache_time = isset($options['errp_related_cache_time']) ? intval($options['errp_related_cache_time']) : 21600; // 6 hours default
    echo '<input type="number" name="errp_settings[errp_related_cache_time]" value="' . esc_attr($cache_time) . '" min="0" />';
    echo '<p class="description">Enter 0 for no caching or a positive value for caching (in seconds).</p>';
}

function errp_random_cache_time_field_callback() {
    $options = get_option('errp_settings', array());
    $cache_time = isset($options['errp_random_cache_time']) ? intval($options['errp_random_cache_time']) : 3600; // 1 hour default
    echo '<input type="number" name="errp_settings[errp_random_cache_time]" value="' . esc_attr($cache_time) . '" min="0" />';
    echo '<p class="description">Enter 0 for no caching or a positive value for caching (in seconds).</p>';
}
function errp_position_field_callback() {
    $options = get_option('errp_settings', array());
    $position = isset($options['errp_position']) ? $options['errp_position'] : 'after_content';
    ?>
    <select name="errp_settings[errp_position]">
        <option value="after_content" <?php selected($position, 'after_content'); ?>><?php _e('After Content', 'errp'); ?></option>
        <option value="before_content" <?php selected($position, 'before_content'); ?>><?php _e('Before Content', 'errp'); ?></option>
        <option value="manual" <?php selected($position, 'manual'); ?>><?php _e('Manual (Use Shortcode)', 'errp'); ?></option>
    </select>
    <?php
    echo '<p class="description">' . __('Choose where to display related or random posts. Select "Manual" to use the shortcode.', 'errp') . '</p>';
    
}
function errp_admin_notices() {
    $screen = get_current_screen();
    if ($screen->id === 'settings_page_errp-settings') {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>New shortcode available: You can now use <code>[errp_enhanced_posts]</code> in addition to the existing <code>[errp_easy_related_random_posts]</code>.</p>';
        echo '</div>';
    }
}
//add_action('admin_notices', 'errp_admin_notices');

add_action('admin_init', 'errp_initialize_settings');

// Settings field callbacks
function errp_settings_section_callback() {
    echo 'Customize the display of enhanced related or random posts. You can use either of these shortcodes: <code>[errp_easy_related_random_posts]</code> or <code>[errp_enhanced_posts]</code>';
}

function errp_headline_field_callback() {
    $options = get_option('errp_settings', array());
    $headline = isset($options['errp_headline']) ? $options['errp_headline'] : __('Related Posts', 'errp');
    echo '<input type="text" name="errp_settings[errp_headline]" value="' . esc_attr($headline) . '" />';
}

function errp_limit_field_callback() {
    $options = get_option('errp_settings', array());
    $limit = isset($options['errp_limit']) ? $options['errp_limit'] : 5;
    echo '<input type="number" id="errp_limit" name="errp_settings[errp_limit]" value="' . esc_attr($limit) . '" min="1" max="10" />';
    echo '<p class="description">' . __('Enter a number between 1 and 10. Maximum allowed: 10 posts.', 'errp') . '</p>';
    ?>
    <script>
    document.getElementById('errp_limit').addEventListener('input', function(e) {
        var value = parseInt(this.value);
        if (value > 10) {
            this.value = 10;
            alert('Maximum allowed value is 10.');
        } else if (value < 1) {
            this.value = 1;
        }
    });
    </script>
    <?php
}

function errp_display_type_field_callback() {
    $options = get_option('errp_settings', array());
    $display_type = isset($options['errp_display_type']) ? $options['errp_display_type'] : 'related';
    echo '<select name="errp_settings[errp_display_type]">
        <option value="related" ' . selected($display_type, 'related', false) . '>' . __('Related Posts', 'errp') . '</option>
        <option value="random" ' . selected($display_type, 'random', false) . '>' . __('Random Posts', 'errp') . '</option>
    </select>';
}

function errp_layout_field_callback() {
    $options = get_option('errp_settings', array());
    $layout = isset($options['errp_layout']) ? $options['errp_layout'] : 'list';
    echo '<select name="errp_settings[errp_layout]">
        <option value="list" ' . selected($layout, 'list', false) . '>List</option>
        <option value="grid" ' . selected($layout, 'grid', false) . '>Grid</option>
    </select>';
}

function errp_show_image_field_callback() {
    $options = get_option('errp_settings', array());
    $show_image = isset($options['errp_show_image']) ? $options['errp_show_image'] : false;
    echo '<input type="checkbox" name="errp_settings[errp_show_image]" value="1" ' . checked(1, $show_image, false) . ' />';
    echo '<p class="description">' . __('Check this box to display featured images for posts.', 'errp') . '</p>';
}
 

function errp_show_excerpt_field_callback() {
    $options = get_option('errp_settings', array());
    $show_excerpt = isset($options['errp_show_excerpt']) ? (bool)$options['errp_show_excerpt'] : false;
    echo '<input type="checkbox" name="errp_settings[errp_show_excerpt]" value="1" ' . checked(1, $show_excerpt, false) . ' />';
    echo '<p class="description">' . __('Check this box to display excerpts for posts.', 'errp') . '</p>';
}

function errp_excerpt_length_field_callback() {
    $options = get_option('errp_settings', array());
    $excerpt_length = isset($options['errp_excerpt_length']) ? $options['errp_excerpt_length'] : 55;
    echo '<input type="number" name="errp_settings[errp_excerpt_length]" value="' . esc_attr($excerpt_length) . '" min="1" />';
}
function errp_cache_time_field_callback() {
    $options = get_option('errp_settings', array());
    $cache_time = isset($options['errp_cache_time']) ? intval($options['errp_cache_time']) : 1800;
    echo '<input type="number" name="errp_settings[errp_cache_time]" value="' . esc_attr($cache_time) . '" min="0" />';
    echo '<p class="description">Enter 0 for no caching or a positive value for caching (in seconds).</p>';
}

// Handle cache clearing
add_action('admin_init', 'errp_handle_cache_clearing');

function errp_handle_cache_clearing() {
    if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_nonce', 'clear_cache_nonce')) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_errp_%' OR option_name LIKE '_transient_timeout_errp_%'");
        add_settings_error('errp_messages', 'errp_message', __('All caches cleared successfully', 'errp'), 'updated');
    } elseif (isset($_POST['clear_related_cache']) && check_admin_referer('clear_cache_nonce', 'clear_cache_nonce')) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_errp_related_%' OR option_name LIKE '_transient_timeout_errp_related_%'");
        add_settings_error('errp_messages', 'errp_message', __('Related posts cache cleared successfully', 'errp'), 'updated');
    } elseif (isset($_POST['clear_random_cache']) && check_admin_referer('clear_cache_nonce', 'clear_cache_nonce')) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_errp_random_%' OR option_name LIKE '_transient_timeout_errp_random_%'");
        add_settings_error('errp_messages', 'errp_message', __('Random posts cache cleared successfully', 'errp'), 'updated');
    }
}

function errp_color_fields_callback() {
    $options = get_option('errp_settings', array());
    $default_primary = '#3498db';
    $default_secondary = '#2ecc71';
    $primary_color = isset($options['errp_primary_color']) ? $options['errp_primary_color'] : $default_primary;
    $secondary_color = isset($options['errp_secondary_color']) ? $options['errp_secondary_color'] : $default_secondary;
    
    echo '<div class="errp-color-settings">';
    echo '<label for="errp_primary_color">Primary Color: </label>';
    echo '<input type="color" id="errp_primary_color" name="errp_settings[errp_primary_color]" value="' . esc_attr($primary_color) . '">';
    
    echo '<label for="errp_secondary_color">Secondary Color: </label>';
    echo '<input type="color" id="errp_secondary_color" name="errp_settings[errp_secondary_color]" value="' . esc_attr($secondary_color) . '">';
    
    echo '<button type="button" id="errp_reset_colors" class="button button-secondary">Reset to Default Colors</button>';
    echo '</div>';
    
    ?>
    <script>
    jQuery(document).ready(function($) {
        const defaultColors = {
            primary: '<?php echo $default_primary; ?>',
            secondary: '<?php echo $default_secondary; ?>'
        };
        
        $('#errp_reset_colors').click(function() {
            $('#errp_primary_color').val(defaultColors.primary);
            $('#errp_secondary_color').val(defaultColors.secondary);
        });
    });
    </script>
    <?php
}
 
// Enqueue styles
function errp_enqueue_styles() {
    wp_enqueue_style('errp-styles', plugins_url('css/errp-styles.css', __FILE__), array(), '1.1.0');
    
    $options = get_option('errp_settings', array());
    $primary_color = isset($options['errp_primary_color']) ? $options['errp_primary_color'] : '#3498db';
    $secondary_color = isset($options['errp_secondary_color']) ? $options['errp_secondary_color'] : '#2ecc71';
    
    $custom_css = "
        .errp-posts {
            --errp-primary-color: {$primary_color};
            --errp-secondary-color: {$secondary_color};
        }
    ";
    
    $user_custom_css = get_option('errp_custom_css', '');
    if (!empty($user_custom_css)) {
        $custom_css .= $user_custom_css;
    }
    if (is_admin()) {
        wp_add_inline_style('wp-admin', '
            .errp-color-settings {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            .errp-color-settings label {
                margin-right: 5px;
            }
        ');
    }
    
    wp_add_inline_style('errp-styles', $custom_css);
}
add_action('wp_enqueue_scripts', 'errp_enqueue_styles');