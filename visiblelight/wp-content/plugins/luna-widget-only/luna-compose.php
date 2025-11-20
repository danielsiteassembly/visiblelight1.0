<?php
/**
 * Luna Compose - Essentials Management
 * 
 * This file extends the Luna Widget plugin with Essentials (formerly canned prompts/responses) functionality.
 * Essentials are used as commands to feed to Luna Compose for auto-generating long-form, data-driven responses.
 * 
 * @package Luna_Widget
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

// Only load if main plugin is active
if (!defined('LUNA_WIDGET_ONLY_BOOTSTRAPPED')) {
  return;
}

/* ============================================================
 * REGISTER ESSENTIALS POST TYPE (formerly luna_canned_response)
 * ============================================================ */
add_action('init', function() {
  $labels = array(
    'name'               => __('Essentials', 'luna'),
    'singular_name'      => __('Essential', 'luna'),
    'add_new'            => __('Add New Essential', 'luna'),
    'add_new_item'       => __('Add New Essential', 'luna'),
    'edit_item'          => __('Edit Essential', 'luna'),
    'new_item'           => __('New Essential', 'luna'),
    'view_item'          => __('View Essential', 'luna'),
    'search_items'       => __('Search Essentials', 'luna'),
    'not_found'          => __('No essentials found.', 'luna'),
    'not_found_in_trash' => __('No essentials found in Trash.', 'luna'),
    'menu_name'          => __('Essentials', 'luna'),
  );

  register_post_type('luna_essentials', array(
    'labels'              => $labels,
    'public'              => false,
    'show_ui'             => true,
    'show_in_menu'        => 'luna-widget',
    'show_in_rest'        => true,
    'capability_type'     => 'post',
    'map_meta_cap'        => true,
    'supports'            => array('title', 'editor', 'revisions'),
    'menu_icon'           => 'dashicons-text-page',
    'menu_position'       => 26,
    'taxonomies'          => array('luna_essential_category'),
  ));
  
  // Register Categories Taxonomy for Luna Essentials
  $category_labels = array(
    'name'                       => __('Categories', 'luna'),
    'singular_name'              => __('Category', 'luna'),
    'search_items'               => __('Search Categories', 'luna'),
    'popular_items'              => __('Popular Categories', 'luna'),
    'all_items'                  => __('All Categories', 'luna'),
    'parent_item'                => __('Parent Category', 'luna'),
    'parent_item_colon'          => __('Parent Category:', 'luna'),
    'edit_item'                  => __('Edit Category', 'luna'),
    'update_item'                => __('Update Category', 'luna'),
    'add_new_item'               => __('Add New Category', 'luna'),
    'new_item_name'              => __('New Category Name', 'luna'),
    'separate_items_with_commas' => __('Separate categories with commas', 'luna'),
    'add_or_remove_items'        => __('Add or remove categories', 'luna'),
    'choose_from_most_used'      => __('Choose from the most used categories', 'luna'),
    'not_found'                  => __('No categories found.', 'luna'),
    'menu_name'                  => __('Categories', 'luna'),
  );
  
  register_taxonomy('luna_essential_category', array('luna_essentials'), array(
    'hierarchical'          => true,
    'labels'                => $category_labels,
    'show_ui'               => true,
    'show_admin_column'     => true,
    'show_in_rest'          => true,
    'query_var'             => true,
    'rewrite'               => array('slug' => 'luna-essential-category'),
    'public'                => false,
    'show_in_menu'          => true,
    'capabilities'          => array(
      'manage_terms' => 'manage_options',
      'edit_terms'   => 'manage_options',
      'delete_terms' => 'manage_options',
      'assign_terms' => 'edit_posts',
    ),
  ));
});

/* ============================================================
 * HELPER FUNCTIONS
 * ============================================================ */

/**
 * Normalize prompt text for matching
 */
function luna_essentials_normalize_prompt_text($value) {
  $value = is_string($value) ? $value : '';
  $value = wp_strip_all_tags($value);
  $value = html_entity_decode($value, ENT_QUOTES, get_option('blog_charset', 'UTF-8'));
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim($value);
}

/**
 * Prepare content for display
 */
function luna_essentials_prepare_content($content) {
  $content = (string) apply_filters('the_content', $content);
  $content = str_replace(array("\r\n", "\r"), "\n", $content);
  $content = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $content);
  $content = preg_replace('/<\/(p|div|li|h[1-6])\s*>/i', '</$1>\n\n', $content);
  $content = wp_strip_all_tags($content);
  $content = html_entity_decode($content, ENT_QUOTES, get_option('blog_charset', 'UTF-8'));
  $content = preg_replace("/\n{3,}/", "\n\n", $content);
  $content = rtrim($content, "\n\r");
  return trim($content);
}

/**
 * Find matching essential by prompt
 */
function luna_essentials_find($prompt) {
  $normalized = luna_essentials_normalize_prompt_text($prompt);
  if ($normalized === '') {
    return null;
  }

  $posts = get_posts(array(
    'post_type'        => 'luna_essentials',
    'post_status'      => 'publish',
    'numberposts'      => -1,
    'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'order'            => 'ASC',
    'suppress_filters' => false,
  ));

  if (empty($posts)) {
    return null;
  }

  $normalized_lc = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
  $best = null;
  $best_score = 0.0;

  foreach ($posts as $post) {
    $title_normalized = luna_essentials_normalize_prompt_text($post->post_title);
    if ($title_normalized === '') {
      continue;
    }
    $title_lc = function_exists('mb_strtolower') ? mb_strtolower($title_normalized, 'UTF-8') : strtolower($title_normalized);

    if ($title_lc === $normalized_lc) {
      // Get categories for this essential
      $categories = array();
      $category_terms = get_the_terms($post->ID, 'luna_essential_category');
      if ($category_terms && !is_wp_error($category_terms)) {
        foreach ($category_terms as $term) {
          $categories[] = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
          );
        }
      }
      
      return array(
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'content' => luna_essentials_prepare_content($post->post_content),
        'categories' => $categories,
      );
    }

    $score = 0.0;
    if (function_exists('similar_text')) {
      similar_text($normalized_lc, $title_lc, $percent);
      $score = (float) $percent;
    } elseif (function_exists('levenshtein')) {
      $distance = levenshtein($normalized_lc, $title_lc);
      $max_len = max(strlen($normalized_lc), strlen($title_lc), 1);
      $score = 100.0 - (min($distance, $max_len) / $max_len * 100.0);
    } else {
      $score = strpos($normalized_lc, $title_lc) !== false || strpos($title_lc, $normalized_lc) !== false ? 100.0 : 0.0;
    }

    if ($score > $best_score) {
      $best_score = $score;
      $best = $post;
    }
  }

  if ($best && $best_score >= 55.0) {
    // Get categories for this essential
    $categories = array();
    $category_terms = get_the_terms($best->ID, 'luna_essential_category');
    if ($category_terms && !is_wp_error($category_terms)) {
      foreach ($category_terms as $term) {
        $categories[] = array(
          'id' => $term->term_id,
          'name' => $term->name,
          'slug' => $term->slug,
        );
      }
    }
    
    return array(
      'id'      => $best->ID,
      'title'   => $best->post_title,
      'content' => luna_essentials_prepare_content($best->post_content),
      'categories' => $categories,
    );
  }

  return null;
}

/**
 * Get recent composer entries
 */
function luna_composer_recent_entries($limit = 10) {
  return get_posts(array(
    'post_type'   => 'luna_compose',
    'post_status' => 'publish',
    'numberposts' => $limit,
    'orderby'     => 'date',
    'order'       => 'DESC',
  ));
}

/* ============================================================
 * COMPOSER ENHANCEMENTS - Facts Text & System Prompt
 * ============================================================ */

/**
 * Enhance facts text with comprehensive content analysis for Composer
 */
add_filter('luna_composer_facts_text', 'luna_composer_enhance_facts_text', 10, 2);
function luna_composer_enhance_facts_text($facts_text, $facts) {
  if (!isset($facts['wordpress_data']) || !is_array($facts['wordpress_data'])) {
    return $facts_text;
  }

  $wp_data = $facts['wordpress_data'];

  // Lightweight content analysis for Composer without exploding token counts
  if (isset($wp_data['posts_data']) && is_array($wp_data['posts_data']) && !empty($wp_data['posts_data'])) {
    $facts_text .= "\n=== CONTENT PATTERNS (CONDENSED) ===\n";

    $all_categories = array();
    $all_tags = array();
    $publication_dates = array();
    $word_count_ranges = array('short' => 0, 'medium' => 0, 'long' => 0);

    foreach ($wp_data['posts_data'] as $post) {
      if (!is_array($post)) continue;

      // Categories and tags (counts only)
      if (isset($post['categories']) && is_array($post['categories'])) {
        foreach ($post['categories'] as $cat) {
          $all_categories[$cat] = isset($all_categories[$cat]) ? $all_categories[$cat] + 1 : 1;
        }
      }
      if (isset($post['tags']) && is_array($post['tags'])) {
        foreach ($post['tags'] as $tag) {
          $all_tags[$tag] = isset($all_tags[$tag]) ? $all_tags[$tag] + 1 : 1;
        }
      }

      // Publication dates (month buckets)
      if (isset($post['date_published'])) {
        $pub_date = date('Y-m', strtotime($post['date_published']));
        $publication_dates[$pub_date] = isset($publication_dates[$pub_date]) ? $publication_dates[$pub_date] + 1 : 1;
      }

      // Word count distribution
      $word_count = isset($post['word_count']) ? (int)$post['word_count'] : 0;
      if ($word_count > 0 && $word_count < 500) {
        $word_count_ranges['short']++;
      } elseif ($word_count >= 500 && $word_count < 1500) {
        $word_count_ranges['medium']++;
      } elseif ($word_count >= 1500) {
        $word_count_ranges['long']++;
      }
    }

    // Categories (top 5)
    if (!empty($all_categories)) {
      arsort($all_categories);
      $facts_text .= "Top categories by post count (max 5):\n";
      $cat_count = 0;
      foreach ($all_categories as $cat => $count) {
        $facts_text .= "- " . esc_html($cat) . ": " . $count . " post(s)\n";
        $cat_count++;
        if ($cat_count >= 5) break;
      }
    }

    // Tags (top 8)
    if (!empty($all_tags)) {
      arsort($all_tags);
      $facts_text .= "Top tags (max 8):\n";
      $tag_count = 0;
      foreach ($all_tags as $tag => $count) {
        $facts_text .= "- " . esc_html($tag) . ": " . $count . " post(s)\n";
        $tag_count++;
        if ($tag_count >= 8) break;
      }
    }

    // Publication cadence (last 12 months)
    if (!empty($publication_dates)) {
      krsort($publication_dates);
      $facts_text .= "Recent publication cadence (up to 12 months):\n";
      $month_count = 0;
      foreach ($publication_dates as $month => $count) {
        $facts_text .= "- " . $month . ": " . $count . " post(s)\n";
        $month_count++;
        if ($month_count >= 12) break;
      }
    }

    // Word count distribution summary
    $facts_text .= "Word count mix: short(<500): " . $word_count_ranges['short'] . ", medium(500-1499): " . $word_count_ranges['medium'] . ", long(1500+): " . $word_count_ranges['long'] . "\n";

    // Hard cap to avoid ballooning Composer prompts
    $content_cap = 4000;
    if (strlen($facts_text) > $content_cap) {
      $facts_text = substr($facts_text, 0, $content_cap) . "\n... [content analysis condensed to stay within OpenAI limits]\n";
    }
  }

  return $facts_text;
}

function luna_composer_enhance_system_prompt($system_prompt, $facts) {
  // Use a concise system prompt to keep Composer payloads small while staying data-grounded
  $system_prompt = "You are Luna — an expert WebOps/CloudOps content strategist. Use only the VL Hub facts provided. Write warm, concise paragraphs that blend factual details with brief insight and finish with one actionable next step. Note when lists are truncated. If JSON is requested, respond in a single compact line without extra whitespace. Avoid repetition and keep responses efficient to respect model limits.";

  return $system_prompt;
}

/* ============================================================
 * ADMIN PAGE
 * ============================================================ */

/**
 * Override the placeholder admin page with full implementation
 */
function luna_compose_admin_page_impl() {
  if (!current_user_can('manage_options')) {
    return;
  }

  $history = luna_composer_recent_entries(10);
  $essentials = get_posts(array(
    'post_type'        => 'luna_essentials',
    'post_status'      => 'publish',
    'numberposts'      => 10,
    'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'order'            => 'ASC',
    'suppress_filters' => false,
  ));

  ?>
  <div class="wrap luna-composer-admin">
    <h1>Luna Compose</h1>
    <p class="description">Manage Essentials (prompts and responses) that Luna Compose uses to auto-generate long-form, data-driven, intelligent responses.</p>

    <h2>Recent Composer History</h2>
    <?php if (!empty($history)) : ?>
      <ol class="luna-composer-history" style="max-width:900px;">
        <?php foreach ($history as $entry) :
          $prompt = get_post_meta($entry->ID, 'prompt', true);
          $answer = get_post_meta($entry->ID, 'answer', true);
          $content = get_post_meta($entry->ID, 'content', true);
          $timestamp = (int) get_post_meta($entry->ID, 'timestamp', true);
          $meta = get_post_meta($entry->ID, 'meta', true);
          $source = is_array($meta) && !empty($meta['source']) ? $meta['source'] : 'unknown';
          $time_display = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : get_the_date('', $entry);
          $response_text = $content ?: $answer;
          ?>
          <li style="margin-bottom:1.5rem;padding:1rem;border:1px solid #dfe4ea;border-radius:8px;background:#fff;">
            <strong><?php echo esc_html($time_display); ?></strong>
            <?php
            // Get feedback status
            $feedback_meta = get_post_meta($entry->ID, 'feedback', true);
            $feedback_status = '';
            if ($feedback_meta === 'like') {
              $feedback_status = '<span style="display:inline-block;margin-left:12px;padding:4px 8px;background:#8D8C00;color:#fff;border-radius:4px;font-size:0.85em;font-weight:600;">Liked</span>';
            } elseif ($feedback_meta === 'dislike') {
              $feedback_status = '<span style="display:inline-block;margin-left:12px;padding:4px 8px;background:#d63638;color:#fff;border-radius:4px;font-size:0.85em;font-weight:600;">Disliked</span>';
            }
            echo $feedback_status;
            ?>
            <div style="margin-top:.5rem;">
              <span style="display:block;font-weight:600;">Prompt:</span>
              <div style="margin-top:.35rem;white-space:pre-wrap;"><?php echo esc_html(wp_trim_words($prompt, 50, '…')); ?></div>
            </div>
            <div style="margin-top:.75rem;">
              <span style="display:block;font-weight:600;">Response (<?php echo esc_html($source); ?>):</span>
              <div style="margin-top:.35rem;white-space:pre-wrap;"><?php echo esc_html(wp_trim_words($response_text, 120, '…')); ?></div>
            </div>
            <div style="margin-top:.5rem;font-size:.9em;">
              <a href="<?php echo esc_url(get_edit_post_link($entry->ID)); ?>">View full entry</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else : ?>
      <p>No composer history recorded yet.</p>
    <?php endif; ?>

    <h2>Essentials</h2>
    <p class="description">Essentials are prompts and responses that Luna Compose uses to auto-generate long-form, data-driven, intelligent responses. The post title and body text serve as the "command" to feed to Luna Compose.</p>
    
    <?php if (!empty($essentials)) : ?>
      <table class="widefat fixed striped" style="max-width:900px;">
        <thead>
          <tr>
            <th scope="col">Prompt/Command</th>
            <th scope="col" style="width:20%;">Categories</th>
            <th scope="col" style="width:30%;">Response preview</th>
            <th scope="col" style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($essentials as $post) :
            $content = luna_essentials_prepare_content($post->post_content);
            $categories = get_the_terms($post->ID, 'luna_essential_category');
            $category_names = array();
            if ($categories && !is_wp_error($categories)) {
              foreach ($categories as $term) {
                $category_names[] = esc_html($term->name);
              }
            }
            ?>
            <tr>
              <td><?php echo esc_html($post->post_title); ?></td>
              <td><?php echo !empty($category_names) ? implode(', ', $category_names) : '<em>No categories</em>'; ?></td>
              <td><?php echo esc_html(wp_trim_words($content, 30, '…')); ?></td>
              <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:1rem;">
        <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=luna_essentials')); ?>">Manage all Essentials</a> 
        <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=luna_essentials')); ?>">Add New Essential</a>
      </p>
    <?php else : ?>
      <p>No essentials found. <a href="<?php echo esc_url(admin_url('post-new.php?post_type=luna_essentials')); ?>">Create your first essential</a> to provide commands for Luna Compose.</p>
    <?php endif; ?>
  </div>
  <?php
}

// Override the placeholder function
if (!function_exists('luna_compose_admin_page')) {
  function luna_compose_admin_page() {
    luna_compose_admin_page_impl();
  }
}
