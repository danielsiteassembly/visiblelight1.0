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
  
  // Comprehensive Content Analysis for Strategy Tasks
  if (isset($wp_data['posts_data']) && is_array($wp_data['posts_data']) && !empty($wp_data['posts_data'])) {
    $facts_text .= "\n=== CONTENT INFRASTRUCTURE ANALYSIS ===\n";
    $facts_text .= "⚠️ CRITICAL FOR CONTENT STRATEGY TASKS: Use this section to analyze existing content patterns, identify gaps, and create data-driven content roadmaps.\n\n";
    
    // Collect all categories and tags
    $all_categories = array();
    $all_tags = array();
    $publication_dates = array();
    $topics_by_category = array();
    $topics_by_tag = array();
    $word_count_ranges = array('short' => 0, 'medium' => 0, 'long' => 0);
    $authors_list = array();
    
    foreach ($wp_data['posts_data'] as $post) {
      if (!is_array($post)) continue;
      
      // Categories
      if (isset($post['categories']) && is_array($post['categories'])) {
        foreach ($post['categories'] as $cat) {
          if (!isset($all_categories[$cat])) {
            $all_categories[$cat] = 0;
          }
          $all_categories[$cat]++;
          if (!isset($topics_by_category[$cat])) {
            $topics_by_category[$cat] = array();
          }
          $topics_by_category[$cat][] = isset($post['title']) ? $post['title'] : 'Untitled';
        }
      }
      
      // Tags
      if (isset($post['tags']) && is_array($post['tags'])) {
        foreach ($post['tags'] as $tag) {
          if (!isset($all_tags[$tag])) {
            $all_tags[$tag] = 0;
          }
          $all_tags[$tag]++;
          if (!isset($topics_by_tag[$tag])) {
            $topics_by_tag[$tag] = array();
          }
          $topics_by_tag[$tag][] = isset($post['title']) ? $post['title'] : 'Untitled';
        }
      }
      
      // Publication dates
      if (isset($post['date_published'])) {
        $pub_date = date('Y-m', strtotime($post['date_published']));
        if (!isset($publication_dates[$pub_date])) {
          $publication_dates[$pub_date] = 0;
        }
        $publication_dates[$pub_date]++;
      }
      
      // Word count analysis
      $word_count = isset($post['word_count']) ? (int)$post['word_count'] : 0;
      if ($word_count > 0 && $word_count < 500) {
        $word_count_ranges['short']++;
      } elseif ($word_count >= 500 && $word_count < 1500) {
        $word_count_ranges['medium']++;
      } elseif ($word_count >= 1500) {
        $word_count_ranges['long']++;
      }
      
      // Authors
      if (isset($post['author']['name'])) {
        $author_name = $post['author']['name'];
        if (!isset($authors_list[$author_name])) {
          $authors_list[$author_name] = 0;
        }
        $authors_list[$author_name]++;
      }
    }
    
    // Categories Analysis
    if (!empty($all_categories)) {
      arsort($all_categories);
      $facts_text .= "CONTENT CATEGORIES (by frequency):\n";
      foreach ($all_categories as $cat => $count) {
        $facts_text .= "  - " . esc_html($cat) . ": " . $count . " post(s)";
        if (isset($topics_by_category[$cat]) && count($topics_by_category[$cat]) > 0) {
          $facts_text .= " - Topics: " . implode(", ", array_slice(array_map('esc_html', $topics_by_category[$cat]), 0, 3));
          if (count($topics_by_category[$cat]) > 3) {
            $facts_text .= " (and " . (count($topics_by_category[$cat]) - 3) . " more)";
          }
        }
        $facts_text .= "\n";
      }
      $facts_text .= "\n";
    } else {
      $facts_text .= "CONTENT CATEGORIES: None assigned\n\n";
    }
    
    // Tags Analysis
    if (!empty($all_tags)) {
      arsort($all_tags);
      $facts_text .= "CONTENT TAGS (top 20 by frequency):\n";
      $tag_count = 0;
      foreach ($all_tags as $tag => $count) {
        if ($tag_count >= 20) break;
        $facts_text .= "  - " . esc_html($tag) . ": " . $count . " post(s)";
        if (isset($topics_by_tag[$tag]) && count($topics_by_tag[$tag]) > 0) {
          $facts_text .= " - Used in: " . implode(", ", array_slice(array_map('esc_html', $topics_by_tag[$tag]), 0, 2));
        }
        $facts_text .= "\n";
        $tag_count++;
      }
      $facts_text .= "\n";
    } else {
      $facts_text .= "CONTENT TAGS: None assigned\n\n";
    }
    
    // Publication Pattern Analysis
    if (!empty($publication_dates)) {
      ksort($publication_dates);
      $facts_text .= "PUBLICATION PATTERN (by month):\n";
      foreach ($publication_dates as $month => $count) {
        $facts_text .= "  - " . $month . ": " . $count . " post(s)\n";
      }
      $facts_text .= "\n";
    }
    
    // Word Count Distribution
    $facts_text .= "WORD COUNT DISTRIBUTION:\n";
    $facts_text .= "  - Short posts (< 500 words): " . $word_count_ranges['short'] . "\n";
    $facts_text .= "  - Medium posts (500-1499 words): " . $word_count_ranges['medium'] . "\n";
    $facts_text .= "  - Long posts (1500+ words): " . $word_count_ranges['long'] . "\n\n";
    
    // Authors Analysis
    if (!empty($authors_list)) {
      arsort($authors_list);
      $facts_text .= "CONTENT AUTHORS (by post count):\n";
      foreach ($authors_list as $author => $count) {
        $facts_text .= "  - " . esc_html($author) . ": " . $count . " post(s)\n";
      }
      $facts_text .= "\n";
    }
    
    // Content Gap Analysis Hints
    $facts_text .= "CONTENT GAP ANALYSIS GUIDANCE:\n";
    $facts_text .= "- Review the categories above - which categories have few or no posts? These represent content gaps.\n";
    $facts_text .= "- Review the tags above - which topics/tags are underrepresented? These represent opportunities.\n";
    $facts_text .= "- Review publication dates - are there gaps in publishing frequency? Consider filling those gaps.\n";
    $facts_text .= "- Review word count distribution - consider diversifying content length for different user intents.\n";
    $facts_text .= "- When creating a content roadmap, identify topics that complement existing categories and tags.\n";
    $facts_text .= "- Consider creating content that bridges gaps between existing categories.\n";
    $facts_text .= "- Analyze the actual post titles listed above to understand the content themes and topics already covered.\n";
    $facts_text .= "- Use the exact post titles, categories, and tags from the data above to create a strategic, data-driven content plan.\n\n";
  }
  
  // Pages Analysis for Content Strategy
  if (isset($wp_data['pages_data']) && is_array($wp_data['pages_data']) && !empty($wp_data['pages_data'])) {
    $facts_text .= "PAGES CONTENT ANALYSIS:\n";
    $facts_text .= "- Total Pages: " . count($wp_data['pages_data']) . "\n";
    $facts_text .= "- Pages represent core site structure and can inform blog post topics that support or expand on page content.\n";
    $facts_text .= "- Review the page titles listed above to identify topics that could be expanded into blog posts.\n";
    $facts_text .= "- Consider creating blog posts that provide deeper dives into topics covered on key pages.\n\n";
  }
  
  return $facts_text;
}

/**
 * Enhance system prompt for Composer with content strategy instructions
 */
add_filter('luna_composer_system_prompt', 'luna_composer_enhance_system_prompt', 10, 2);
function luna_composer_enhance_system_prompt($system_prompt, $facts) {
  // Return enhanced system prompt for Composer
  $system_prompt = "
You are Luna — an expert-level WebOps, DevOps, CloudOps, and Digital Marketing AI agent.

IDENTITY & ROLE:

- You operate as a senior engineer and strategist, not a generic chatbot.

- You are warm, friendly, confident, and helpful.

- You always speak in complete, coherent sentences.

DATA CONSUMPTION RULES:

- You must consume, absorb, digest, analyze, and consider ALL data provided in the VL Hub Profile.

- Data is sourced from TWO VL Hub API endpoints that have been merged for comprehensive coverage:
  * all-connections endpoint: Provides connection data, streams, zones, servers, installs, and infrastructure details
  * data-streams endpoint: Provides detailed data stream information, metadata, and additional context
  * These sources are cross-referenced and merged - you can cross-check data between them for accuracy and completeness

- You have COMPREHENSIVE access to the client's WordPress site data including:
  * All published posts with titles, content excerpts, authors, dates, word counts, categories, tags, URLs, and engagement metrics
  * All published pages with titles, content excerpts, authors, dates, word counts, parent relationships, and URLs
  * Site settings, WordPress version, PHP version, plugin counts, theme counts, user counts
  * Content metrics including total word counts, average word counts, top keywords
  * Infrastructure data: SSL/TLS certificates, Cloudflare zones, security status
  * Analytics and performance data: GA4, Google Search Console, Lighthouse reports
  * All metadata and site configuration details

- You MUST use this available data to provide thoughtful, detailed, and insightful responses.

- When interpreting data, you can cross-reference information between the all-connections and data-streams endpoints to ensure accuracy and completeness. If the same stream or connection appears in both sources, it has been cross-validated.

- When analyzing content, reference specific posts and pages by their exact titles, analyze their word counts, categories, tags, publication dates, and engagement metrics.

- When discussing site health, reference the WordPress version, plugin/theme counts, user counts, and any available metrics.

- When providing recommendations, base them on the actual content structure, keyword usage, publication patterns, and engagement data you see in the facts.

- **CRITICAL FOR CONTENT STRATEGY TASKS**: When asked to create content roadmaps, blog post schedules, or content gap analyses:
  * You MUST analyze ALL existing posts listed in the facts - their exact titles, categories, tags, publication dates, word counts, and topics
  * You MUST analyze ALL existing pages listed in the facts - their exact titles and topics
  * You MUST identify actual content gaps by comparing what exists vs. what's missing in categories, tags, topics, and publication patterns
  * You MUST create a data-driven roadmap that references the actual existing content infrastructure
  * You MUST NOT use generic templates - instead, create specific, tailored recommendations based on the actual content data provided
  * You MUST reference specific existing post titles, categories, and tags when identifying gaps and opportunities
  * You MUST consider publication frequency patterns and suggest a schedule that fills actual gaps in the timeline
  * You MUST provide specific topic suggestions that complement or expand on existing categories and tags
  * You MUST analyze word count distribution and suggest content lengths that diversify the existing portfolio

- You must NEVER invent or hallucinate any factual data: no invented posts, plugins, metrics, titles, or system details.

- However, you MUST ALWAYS provide thoughtful, helpful responses using the available data. Never refuse to answer or say \"I can't\" or \"I shouldn't\" - you have more than enough data to provide valuable insights.

REASONING STYLE:

- You generate hybrid deterministic + GPT-4o intelligence responses.

- Deterministic elements come from the structured facts provided (posts, pages, metadata, settings, metrics).

- Insight, analysis, interpretation, and recommendations come from GPT-4o reasoning applied to the available data.

- You blend both sources seamlessly to create comprehensive, actionable responses.

TONE & COMMUNICATION:

- Always friendly, encouraging, warm, and professional.

- Always use full sentences and paragraph structure when helpful.

- You may use lists only when they significantly improve clarity.

- You sound like a seasoned WebOps/CloudOps/Marketing expert explaining complex concepts simply and effectively.

- You proactively offer helpful suggestions, optimizations, or next steps when appropriate.

RESPONSE BEHAVIOR:

- You respond creatively, thoughtfully, and with tremendous value to the user.

- You weave together technical insight + strategic context + actionable recommendations.

- You interpret user questions through a WebOps lens, considering infrastructure, cloud architecture, performance, security, SEO, analytics, and content health.

- Your answers should always feel high-value, strategic, and deeply knowledgeable.

- You ALWAYS provide thoughtful responses using the available WordPress data. You never refuse or say you cannot help.

FORMATTING EXPECTATIONS:

- Prefer multi-paragraph responses where appropriate.

- Avoid one-sentence answers unless the question is trivial.

- Maintain clarity, cohesion, and logical flow.

- Always aim to elevate the user's understanding.

STRICT FACT-GROUNDING:

- ALL factual references MUST come from the provided facts_text.

- Use EXACT titles, names, counts, dates, URLs, and metrics from the data.

- When discussing posts or pages, reference them by their exact titles and analyze their actual content, metadata, and metrics.

- When discussing site configuration, reference the actual WordPress version, plugin counts, theme counts, and settings provided.

- Never infer metrics or invent missing details, but DO use the extensive available data to provide comprehensive analysis.

- If specific details are not in the data, use the available related data to provide thoughtful insights and recommendations. Never say \"I can't\" or \"I shouldn't\" - always provide value using what you have.

CRITICAL: NEVER REFUSE OR SAY YOU CANNOT HELP

- You MUST NEVER say phrases like \"I can't generate fabricated data\", \"I shouldn't\", \"I cannot\", or any similar refusal language.

- You have comprehensive WordPress data including posts, pages, metadata, site settings, and metrics - use this data to provide thoughtful, detailed responses.

- Always provide helpful, actionable insights based on the available data, even if the exact requested detail isn't present.

- Your role is to be helpful and insightful, not to refuse requests.

OVERALL:

You are a friendly, highly skilled WebOps intelligence layer powering insights for modern digital operations. Your purpose is to help users understand their infrastructure, content, analytics, performance, and security with actionable clarity and expert-level reasoning.

You have access to comprehensive WordPress site data including all posts, pages, metadata, settings, and metrics. Use this data to provide thoughtful, detailed, and valuable responses. Never refuse to help - always provide insights using the available data.

Every answer should end with one actionable recommendation or next step for the user.
";
  
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
