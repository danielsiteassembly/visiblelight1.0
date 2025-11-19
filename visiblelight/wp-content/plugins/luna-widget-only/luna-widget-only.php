<?php
/**
 * Plugin Name: Luna Chat — Widget (Client)
 * Description: Floating chat widget + shortcode with conversation logging. Pulls client facts from Visible Light Hub and blends them with AI answers. Includes chat history hydration and Hub-gated REST endpoints.
 * Version:     1.7.0
 * Author:      Visible Light
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

if (defined('LUNA_WIDGET_ONLY_BOOTSTRAPPED')) {
  return;
}
define('LUNA_WIDGET_ONLY_BOOTSTRAPPED', true);

// Load luna-compose.php if it exists
$compose_file = dirname(__FILE__) . '/luna-compose.php';
if (file_exists($compose_file)) {
  require_once $compose_file;
}

/* ============================================================
 * CONSTANTS & OPTIONS
 * ============================================================ */
if (!defined('LUNA_WIDGET_PLUGIN_VERSION')) define('LUNA_WIDGET_PLUGIN_VERSION', '1.7.0');
if (!defined('LUNA_WIDGET_OPT_COMPOSER_ENABLED')) define('LUNA_WIDGET_OPT_COMPOSER_ENABLED', 'luna_composer_enabled');
if (!defined('LUNA_WIDGET_ASSET_URL')) define('LUNA_WIDGET_ASSET_URL', plugin_dir_url(__FILE__));

define('LUNA_WIDGET_OPT_LICENSE',         'luna_widget_license');
define('LUNA_WIDGET_OPT_MODE',            'luna_widget_mode');           // 'shortcode' | 'widget'
define('LUNA_WIDGET_OPT_SETTINGS',        'luna_widget_ui_settings');    // array
define('LUNA_WIDGET_OPT_LICENSE_SERVER',  'luna_widget_license_server'); // hub base URL
define('LUNA_WIDGET_OPT_LAST_PING',       'luna_widget_last_ping');      // array {ts,url,code,err,body}
define('LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY', 'luna_widget_supercluster_only'); // '1' | '0'
define('LUNA_WIDGET_OPT_TRAINING', 'luna_training_data');

/* Cache */
define('LUNA_CACHE_PROFILE_TTL',          300); // 5 min

/* ============================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================ */
register_activation_hook(__FILE__, function () {
  if (!get_option(LUNA_WIDGET_OPT_MODE, null)) {
    update_option(LUNA_WIDGET_OPT_MODE, 'widget');
  }
  if (!get_option(LUNA_WIDGET_OPT_SETTINGS, null)) {
    update_option(LUNA_WIDGET_OPT_SETTINGS, array(
      'position'    => 'bottom-right',
      'title'       => 'Luna Chat',
      'avatar_url'  => '',
      'header_text' => "Hi, I'm Luna",
      'sub_text'    => 'How can I help today?',
    ));
  }
  if (!get_option(LUNA_WIDGET_OPT_LICENSE_SERVER, null)) {
    update_option(LUNA_WIDGET_OPT_LICENSE_SERVER, 'https://visiblelight.ai');
  }
  // Luna Composer is now default functionality - no activation needed
});

register_deactivation_hook(__FILE__, function () {
  // Cleanup if needed
});

/* ============================================================
 * ADMIN MENU (Top-level)
 * ============================================================ */
add_action('admin_menu', function () {
  // Add parent menu - clicking this will show Settings page
  add_menu_page(
    'Luna Widget',
    'Luna Widget',
    'manage_options',
    'luna-widget',
    'luna_widget_admin_page',
    'dashicons-format-chat',
    64
  );
  
  // Add Settings as the first submenu (replaces auto-generated "Luna Widget" submenu)
  // This makes Settings the default page when clicking the parent menu
  add_submenu_page(
    'luna-widget',
    'Settings',
    'Settings',
    'manage_options',
    'luna-widget', // Same slug as parent = replaces auto-generated submenu
    'luna_widget_admin_page'
  );
  
  // Add Compose submenu
  add_submenu_page(
    'luna-widget',
    'Compose',
    'Compose',
    'manage_options',
    'luna-compose',
    'luna_compose_admin_page'
  );
  
  // Add Chat submenu (placed after Compose)
  add_submenu_page(
    'luna-widget',
    'Chat',
    'Chat',
    'manage_options',
    'luna-chat',
    'luna_chat_admin_page'
  );
  
  // Add Training submenu (placed after Chat)
  add_submenu_page(
    'luna-widget',
    'Training',
    'Training',
    'manage_options',
    'luna-training',
    'luna_training_admin_page'
  );
});

/* ============================================================
 * SETTINGS
 * ============================================================ */
add_action('admin_init', function () {
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_LICENSE, array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return preg_replace('/[^A-Za-z0-9\-\_]/','', (string)$v); },
    'default' => '',
  ));
  register_setting('luna_widget_settings', 'luna_openai_api_key', array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return trim((string)$v); },
    'default' => '',
  ));
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_LICENSE_SERVER, array(
    'type' => 'string',
    'sanitize_callback' => function($v){
      $v = trim((string)$v);
      if ($v === '') return 'https://visiblelight.ai';
      $v = preg_replace('#/+$#','',$v);
      $v = preg_replace('#^http://#i','https://',$v);
      return esc_url_raw($v);
    },
    'default' => 'https://visiblelight.ai',
  ));
  // LUNA_WIDGET_OPT_MODE is deprecated - widget mode is always active
  // Keeping registration for backwards compatibility but it's no longer used
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_MODE, array(
    'type' => 'string',
    'sanitize_callback' => function($v){ return 'widget'; }, // Always widget mode
    'default' => 'widget',
  ));
  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_SETTINGS, array(
    'type' => 'array',
    'sanitize_callback' => function($a){
      $a = is_array($a) ? $a : array();
      $pos = isset($a['position']) ? strtolower((string)$a['position']) : 'bottom-right';
      $valid_positions = array('top-left','top-center','top-right','bottom-left','bottom-center','bottom-right');
      if (!in_array($pos, $valid_positions, true)) $pos = 'bottom-right';
      return array(
        'position'    => $pos,
        'title'       => sanitize_text_field(isset($a['title']) ? $a['title'] : 'Luna Chat'),
        'avatar_url'  => esc_url_raw(isset($a['avatar_url']) ? $a['avatar_url'] : ''),
        'header_text' => sanitize_text_field(isset($a['header_text']) ? $a['header_text'] : "Hi, I'm Luna"),
        'sub_text'    => sanitize_text_field(isset($a['sub_text']) ? $a['sub_text'] : 'How can I help today?'),
        'button_desc_chat'    => sanitize_textarea_field(isset($a['button_desc_chat']) ? $a['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.'),
        'button_desc_report'  => sanitize_textarea_field(isset($a['button_desc_report']) ? $a['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.'),
        'button_desc_compose' => sanitize_textarea_field(isset($a['button_desc_compose']) ? $a['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.'),
        'button_desc_automate' => sanitize_textarea_field(isset($a['button_desc_automate']) ? $a['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.'),
      );
    },
    'default' => array(),
  ));

  register_setting('luna_widget_settings', LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, array(
    'type' => 'string',
    'sanitize_callback' => function($value) {
      return $value === '1' ? '1' : '0';
    },
    'default' => '0',
  ));
});

/* Helper function for settings page */
function luna_widget_hub_base() {
  return get_option(LUNA_WIDGET_OPT_LICENSE_SERVER, 'https://visiblelight.ai');
}

function luna_widget_training_industry_options() {
  return array(
    'Primary Sector (Raw Material Extraction)' => array(
      'primary_agriculture' => 'Agriculture',
      'primary_forestry' => 'Forestry',
      'primary_fishing' => 'Fishing',
      'primary_hunting' => 'Hunting',
      'primary_mining' => 'Mining and Quarrying',
    ),
    'Secondary Sector - Manufacturing' => array(
      'manufacturing_food_beverage' => 'Food and Beverage',
      'manufacturing_apparel' => 'Apparel',
      'manufacturing_chemicals' => 'Chemicals',
      'manufacturing_computers_electronics' => 'Computer and Electronic Products',
      'manufacturing_transportation_equipment' => 'Transportation Equipment (Automotive & Aerospace)',
      'manufacturing_machinery' => 'Machinery',
    ),
    'Secondary Sector - Construction' => array(
      'construction_residential' => 'Residential Building Construction',
      'construction_nonresidential' => 'Nonresidential Building Construction',
      'construction_civil_engineering' => 'Civil Engineering',
    ),
    'Professional and Business Services' => array(
      'services_financial' => 'Financial Services',
      'services_legal' => 'Legal Services',
      'services_advertising' => 'Advertising',
      'services_real_estate' => 'Real Estate',
      'services_admin_support' => 'Administrative and Support Services',
    ),
    'Information and Technology' => array(
      'info_it' => 'Information Technology',
      'info_telecom' => 'Telecommunications',
      'info_data_internet' => 'Data and Internet Services',
      'info_media_entertainment' => 'Media and Entertainment (TV, Movies, Music)',
    ),
    'Health and Education' => array(
      'health_healthcare_social' => 'Healthcare and Social Assistance',
      'health_pharma_biotech' => 'Pharmaceutical and Biotechnology',
      'health_education' => 'Educational Services',
    ),
    'Retail and Hospitality' => array(
      'retail_trade' => 'Retail Trade',
      'retail_accommodation_food' => 'Accommodation and Food Services (Restaurants)',
      'retail_hospitality' => 'Hospitality',
    ),
    'Transportation and Logistics' => array(
      'transport_logistics' => 'Transportation, Storage, and Distribution',
      'transport_air_sea_land' => 'Air, Sea, and Land Transportation',
    ),
    'Utilities and Energy' => array(
      'utilities_general' => 'Utilities',
      'utilities_energy_markets' => 'Energy Markets',
      'utilities_oil_gas' => 'Oil and Gas',
    ),
    'Other Services' => array(
      'other_arts_entertainment' => 'Arts, Entertainment, and Recreation',
      'other_waste_management' => 'Waste Management and Remediation Services',
    ),
  );
}

function luna_widget_training_industry_label($value) {
  foreach (luna_widget_training_industry_options() as $group => $options) {
    if (isset($options[$value])) {
      return $options[$value];
    }
  }
  return $value;
}

function luna_widget_get_training_data() {
  $data = get_option(LUNA_WIDGET_OPT_TRAINING, array());
  if (!is_array($data)) {
    $data = array();
  }
  return $data;
}

/* ============================================================
 * REST API ENDPOINTS
 * ============================================================ */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/test-connection', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_test_connection',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ));
  
  // Sync WordPress data to Hub endpoint
  register_rest_route('luna_widget/v1', '/sync-to-hub', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_sync_to_hub',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ));
});

/**
 * Test connection to VL Hub and generate summary using OpenAI
 */
function luna_widget_test_connection($request) {
  $license = get_option(LUNA_WIDGET_OPT_LICENSE, '');
  $hub_base = luna_widget_hub_base();
  $openai_key = get_option('luna_openai_api_key', '');
  
  if (empty($license)) {
    return new WP_Error('no_license', 'License key is required. Please enter your Corporate License Code in the settings.', array('status' => 400));
  }
  
  // Build endpoint URL
  $endpoint_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/all-connections?license=' . urlencode($license);
  
  // Fetch data from VL Hub
  $response = wp_remote_get($endpoint_url, array(
    'timeout' => 30,
    'sslverify' => true,
  ));
  
  if (is_wp_error($response)) {
    return new WP_Error('fetch_error', 'Failed to fetch data from VL Hub: ' . $response->get_error_message(), array('status' => 500));
  }
  
  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  
  if ($response_code !== 200) {
    return new WP_Error('http_error', 'VL Hub returned error code ' . $response_code . ': ' . $response_body, array('status' => $response_code));
  }
  
  $data = json_decode($response_body, true);
  
  if (json_last_error() !== JSON_ERROR_NONE) {
    return new WP_Error('json_error', 'Invalid JSON response from VL Hub: ' . json_last_error_msg(), array('status' => 500));
  }
  
  // Generate summary using OpenAI if API key is available
  $summary = '';
  if (!empty($openai_key) && !empty($data)) {
    $summary = luna_widget_generate_openai_summary($data, $openai_key);
  }
  
  // If no OpenAI summary, create a basic summary
  if (empty($summary)) {
    $summary = luna_widget_generate_basic_summary($data);
  }
  
  return rest_ensure_response(array(
    'success' => true,
    'summary' => $summary,
    'raw_data' => $data,
    'endpoint' => $endpoint_url,
    'response_code' => $response_code,
  ));
}

/**
 * Extract and categorize all data from VL Hub Profile
 */
function luna_widget_extract_profile_data($data) {
  $profile_data = array(
    'ok' => isset($data['ok']) ? $data['ok'] : false,
    'license_key' => '',
    'categories' => array(),
    'streams' => array(),
    'zones' => array(),
    'servers' => array(),
    'backups' => array(),
    'installs' => array(),
    'connections' => array(),
    'analytics' => array(), // GA4, GSC
    'search' => array(), // GSC, SEO
    'competitors' => array(), // Competitor analysis
    'content' => array(), // WordPress data
    'compliance' => array(), // SSL/TLS, security
    'total_items' => 0,
  );
  
  // Handle nested data structure (data.client_streams) or flat structure (client_streams)
  $profile = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
  
  // Preserve merged sources information if available
  if (isset($data['_merged_sources'])) {
    $profile_data['_merged_sources'] = $data['_merged_sources'];
  }
  
  // Extract license key
  if (isset($profile['license_key'])) {
    $profile_data['license_key'] = $profile['license_key'];
  }
  
  // Extract categories
  if (isset($profile['categories']) && is_array($profile['categories'])) {
    $profile_data['categories'] = $profile['categories'];
  }
  
  // Extract client_streams (data streams) - can be at top level or nested
  $streams_source = isset($profile['client_streams']) ? $profile['client_streams'] : (isset($data['client_streams']) ? $data['client_streams'] : array());
  if (is_array($streams_source) && !empty($streams_source)) {
    foreach ($streams_source as $stream_id => $stream) {
      if (is_array($stream)) {
        $stream_name = isset($stream['name']) ? $stream['name'] : $stream_id;
        $stream_status = isset($stream['status']) ? $stream['status'] : 'unknown';
        $stream_categories = isset($stream['categories']) && is_array($stream['categories']) ? $stream['categories'] : array();
        $health_score = isset($stream['health_score']) ? floatval($stream['health_score']) : 0;
        
        $profile_data['streams'][] = array(
          'id' => $stream_id,
          'name' => $stream_name,
          'status' => $stream_status,
          'categories' => $stream_categories,
          'health_score' => $health_score,
        );
        $profile_data['total_items']++;
      }
    }
  }
  
  // Add Training Data as a stream (Luna Intel)
  $training_items = luna_widget_get_training_data();
  if (!empty($training_items)) {
    $training_stream_data = array();
    $has_luna_responses = false;
    foreach ($training_items as $index => $training_item) {
      // Include all training data including Luna test responses
      $stream_item = $training_item;
      if (isset($training_item['luna_test_response'])) {
        $has_luna_responses = true;
        $stream_item['luna_consumed'] = true;
        $stream_item['luna_consumed_date'] = isset($training_item['luna_test_response_date']) ? $training_item['luna_test_response_date'] : '';
      }
      $training_stream_data[] = $stream_item;
    }
    
    $profile_data['streams'][] = array(
      'id' => 'training_data',
      'name' => 'Training Data',
      'status' => 'active',
      'categories' => array('luna-intel', 'training', 'company-data'),
      'health_score' => 100.0,
      'description' => 'Company training data for Luna AI context and responses. This data is automatically consumed by Luna and used to provide context-aware, data-driven responses.',
      'training_items' => $training_stream_data,
      'item_count' => count($training_items),
      'luna_consumed' => $has_luna_responses,
      'last_updated' => !empty($training_items) && isset($training_items[count($training_items) - 1]['created']) ? $training_items[count($training_items) - 1]['created'] : current_time('mysql'),
    );
    $profile_data['total_items']++;
  }
  
  // Scan all top-level keys in profile for various data types
  // This ensures we capture ALL data items, not just those in client_streams
  foreach ($profile as $key => $item) {
    if (!is_array($item)) continue;
    
    // Skip non-data keys
    if (in_array($key, array('license_key', 'categories', 'client_streams', 'ok', 'data'))) continue;
    
    $item_name = isset($item['name']) ? $item['name'] : $key;
    $item_status = isset($item['status']) ? $item['status'] : 'unknown';
    $item_categories = isset($item['categories']) && is_array($item['categories']) ? $item['categories'] : array();
    $health_score = isset($item['health_score']) ? floatval($item['health_score']) : 0;
    $item_description = isset($item['description']) ? $item['description'] : '';
    
    // Skip removed items
    if (isset($item['removed']) && $item['removed'] === true) continue;
    if (isset($item['removed_at']) && !empty($item['removed_at'])) continue;
    
    // Cloudflare zones
    if (strpos($key, 'cloudflare') !== false || (isset($item['cloudflare_zone_name']) || isset($item['cloudflare_data']) || (isset($item['name']) && stripos($item['name'], 'Cloudflare') !== false))) {
      $zone_name = isset($item['cloudflare_zone_name']) ? $item['cloudflare_zone_name'] : (isset($item['cloudflare_data']['name']) ? $item['cloudflare_data']['name'] : $item_name);
      $cf_data = array(
        'name' => $zone_name,
        'status' => $item_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'cloudflare_data' => isset($item['cloudflare_data']) ? $item['cloudflare_data'] : array(),
        'cloudflare_zone_name' => isset($item['cloudflare_zone_name']) ? $item['cloudflare_zone_name'] : '',
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['compliance'][] = $cf_data;
      $profile_data['zones'][] = $cf_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // Liquid Web servers/assets
    if (strpos($key, 'liquidweb') !== false || (isset($item['name']) && (stripos($item['name'], 'Liquid Web') !== false || stripos($item['name'], 'ThreatDown') !== false))) {
      $profile_data['servers'][] = array('name' => $item_name, 'status' => $item_status, 'health_score' => $health_score, 'description' => $item_description);
      $profile_data['total_items']++;
      continue;
    }
    
    // AWS S3 buckets
    if (strpos($key, 'aws_s3') !== false || strpos($key, 's3') !== false || (isset($item['name']) && stripos($item['name'], 'S3') !== false) || isset($item['bucket_name'])) {
      $bucket_name = isset($item['bucket_name']) ? $item['bucket_name'] : $item_name;
      $profile_data['connections'][] = array('type' => 'AWS S3', 'name' => $bucket_name, 'status' => $item_status, 'health_score' => $health_score, 'description' => $item_description);
      $profile_data['total_items']++;
      continue;
    }
    
    // SSL/TLS certificates
    if (strpos($key, 'ssl_tls') !== false || isset($item['ssl_tls_data']) || (isset($item['name']) && (stripos($item['name'], 'SSL') !== false || stripos($item['name'], 'TLS') !== false))) {
      $cert_name = isset($item['ssl_tls_data']['certificate']) ? $item['ssl_tls_data']['certificate'] : $item_name;
      $cert_status = isset($item['ssl_tls_data']['status']) ? $item['ssl_tls_data']['status'] : $item_status;
      $ssl_data = array(
        'type' => 'SSL/TLS',
        'name' => $cert_name,
        'status' => $cert_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'ssl_tls_data' => isset($item['ssl_tls_data']) ? $item['ssl_tls_data'] : array(),
        'expires' => isset($item['ssl_tls_data']['expires']) ? $item['ssl_tls_data']['expires'] : '',
        'issuer' => isset($item['ssl_tls_data']['issuer']) ? $item['ssl_tls_data']['issuer'] : '',
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['compliance'][] = $ssl_data;
      $profile_data['connections'][] = $ssl_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // Competitor analysis
    if (strpos($key, 'competitor') !== false || isset($item['competitor_url']) || (isset($item['name']) && stripos($item['name'], 'Competitor') !== false)) {
      $comp_name = isset($item['competitor_url']) ? $item['competitor_url'] : $item_name;
      $comp_data = array(
        'type' => 'Competitor Analysis',
        'name' => $comp_name,
        'status' => $item_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'competitor_url' => isset($item['competitor_url']) ? $item['competitor_url'] : '',
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['competitors'][] = $comp_data;
      $profile_data['connections'][] = $comp_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // Lighthouse reports
    if (strpos($key, 'lighthouse') !== false || isset($item['report_data']) || (isset($item['name']) && stripos($item['name'], 'Lighthouse') !== false)) {
      $lh_name = isset($item['url']) ? $item['url'] : $item_name;
      $profile_data['connections'][] = array('type' => 'Lighthouse', 'name' => $lh_name, 'status' => $item_status, 'health_score' => $health_score, 'description' => $item_description);
      $profile_data['total_items']++;
      continue;
    }
    
    // Google Analytics 4 (can be in client_streams or top-level)
    if (strpos($key, 'ga4') !== false || isset($item['ga4_property_id']) || isset($item['ga4_metrics']) || (isset($item['name']) && stripos($item['name'], 'Google Analytics') !== false)) {
      $ga_name = isset($item['ga4_property_id']) ? 'GA4 Property ' . $item['ga4_property_id'] : $item_name;
      $ga_data = array(
        'type' => 'Google Analytics 4',
        'name' => $ga_name,
        'status' => $item_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'property_id' => isset($item['ga4_property_id']) ? $item['ga4_property_id'] : '',
        'metrics' => isset($item['ga4_metrics']) ? $item['ga4_metrics'] : array(),
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['analytics'][] = $ga_data;
      $profile_data['connections'][] = $ga_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // Google Search Console
    if (strpos($key, 'google_search_console') !== false || strpos($key, 'gsc') !== false || isset($item['gsc_data']) || (isset($item['name']) && stripos($item['name'], 'Search Console') !== false)) {
      $gsc_data = array(
        'type' => 'Google Search Console',
        'name' => $item_name,
        'status' => $item_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'site_url' => isset($item['site_url']) ? $item['site_url'] : (isset($item['url']) ? $item['url'] : ''),
        'gsc_data' => isset($item['gsc_data']) ? $item['gsc_data'] : array(),
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['search'][] = $gsc_data;
      $profile_data['analytics'][] = $gsc_data;
      $profile_data['connections'][] = $gsc_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // WordPress installs/data
    if (strpos($key, 'wordpress') !== false || isset($item['wp_core_data']) || isset($item['wp_version']) || (isset($item['name']) && stripos($item['name'], 'WordPress') !== false)) {
      $wp_name = isset($item['site_url']) ? $item['site_url'] : (isset($item['url']) ? $item['url'] : $item_name);
      $wp_data = array(
        'name' => $wp_name,
        'status' => $item_status,
        'health_score' => $health_score,
        'description' => $item_description,
        'wp_version' => isset($item['wp_version']) ? $item['wp_version'] : '',
        'wp_core_data' => isset($item['wp_core_data']) ? $item['wp_core_data'] : array(),
        'posts_total' => isset($item['posts_total']) ? intval($item['posts_total']) : 0,
        'pages_total' => isset($item['pages_total']) ? intval($item['pages_total']) : 0,
        'users_total' => isset($item['users_total']) ? intval($item['users_total']) : 0,
        'plugins_total' => isset($item['plugins_total']) ? intval($item['plugins_total']) : 0,
        'themes_total' => isset($item['themes_total']) ? intval($item['themes_total']) : 0,
        'last_updated' => isset($item['last_updated']) ? $item['last_updated'] : ''
      );
      $profile_data['content'][] = $wp_data;
      $profile_data['installs'][] = $wp_data;
      $profile_data['total_items']++;
      continue;
    }
    
    // Any other item with status and categories/health_score (generic data stream)
    // This catches ALL remaining data items that have identifying characteristics
    if (isset($item['status']) && (isset($item['categories']) || $health_score > 0 || isset($item['id']) || isset($item['last_updated']))) {
      // Add as a data stream if it has categories or health score
      if (!empty($item_categories) || $health_score > 0) {
        $profile_data['streams'][] = array(
          'id' => isset($item['id']) ? $item['id'] : $key,
          'name' => $item_name,
          'status' => $item_status,
          'categories' => $item_categories,
          'health_score' => $health_score,
          'description' => $item_description,
        );
        $profile_data['total_items']++;
      } elseif (isset($item['id']) || isset($item['last_updated'])) {
        // Even without categories, if it has an ID or last_updated, it's likely a data item
        $profile_data['streams'][] = array(
          'id' => isset($item['id']) ? $item['id'] : $key,
          'name' => $item_name,
          'status' => $item_status,
          'categories' => $item_categories,
          'health_score' => $health_score,
          'description' => $item_description,
        );
        $profile_data['total_items']++;
      }
    }
  }
  
  return $profile_data;
}

/**
 * Generate summary using OpenAI API
 */
function luna_widget_generate_openai_summary($data, $api_key) {
  // Extract all profile data
  $profile_data = luna_widget_extract_profile_data($data);
  
  // Create enhanced prompt for OpenAI with detailed sections
  $prompt = "You are a Senior WebOps engineer providing a comprehensive analysis of a client's digital ecosystem. Analyze the following VL Hub Profile data and provide a detailed, data-driven summary in a friendly, professional tone.\n\n";
  $prompt .= "Structure your response with the following sections:\n\n";
  $prompt .= "1. EXECUTIVE OVERVIEW (1 paragraph)\n";
  $prompt .= "   - Total number of data streams, WordPress installs, Cloudflare zones, servers, and all connections\n";
  $prompt .= "   - Overall health status and key metrics\n";
  $prompt .= "   - High-level assessment of digital infrastructure robustness\n\n";
  $prompt .= "2. ANALYTICS & MEASUREMENT (1-2 paragraphs)\n";
  $prompt .= "   - Google Analytics 4: Property IDs, health scores, active status, key metrics if available\n";
  $prompt .= "   - Google Search Console: Site performance, indexing status, search visibility\n";
  $prompt .= "   - Data quality and completeness\n";
  $prompt .= "   - Insights on tracking coverage and measurement capabilities\n\n";
  $prompt .= "3. SEARCH & DISCOVERABILITY (1 paragraph)\n";
  $prompt .= "   - Google Search Console integration status\n";
  $prompt .= "   - Search performance metrics if available\n";
  $prompt .= "   - SEO monitoring capabilities\n";
  $prompt .= "   - Search visibility and indexing health\n\n";
  $prompt .= "4. COMPETITIVE INTELLIGENCE (1 paragraph)\n";
  $prompt .= "   - Number of competitor analyses tracked\n";
  $prompt .= "   - Competitor URLs and monitoring status\n";
  $prompt .= "   - Health scores and data freshness\n";
  $prompt .= "   - Market positioning insights available\n\n";
  $prompt .= "5. CONTENT & CMS (1 paragraph)\n";
  $prompt .= "   - WordPress installs: Count, versions, update status\n";
  $prompt .= "   - WordPress data streams: Posts, pages, users, plugins, themes\n";
  $prompt .= "   - Content health scores and management capabilities\n";
  $prompt .= "   - CMS infrastructure status\n\n";
  $prompt .= "6. COMPLIANCE & SECURITY (1 paragraph)\n";
  $prompt .= "   - SSL/TLS certificates: Status, expiration dates, validity\n";
  $prompt .= "   - Cloudflare zones: Protection status, security features\n";
  $prompt .= "   - Security infrastructure health\n";
  $prompt .= "   - Compliance monitoring capabilities\n\n";
  $prompt .= "7. PERFORMANCE & INFRASTRUCTURE (1 paragraph)\n";
  $prompt .= "   - Lighthouse reports: Performance scores, opportunities\n";
  $prompt .= "   - Server infrastructure: Liquid Web, AWS S3, hosting status\n";
  $prompt .= "   - Performance monitoring coverage\n";
  $prompt .= "   - Infrastructure reliability indicators\n\n";
  $prompt .= "8. RECOMMENDATIONS (1 paragraph)\n";
  $prompt .= "   - Key areas for improvement based on health scores\n";
  $prompt .= "   - Missing or incomplete data streams\n";
  $prompt .= "   - Strategic next steps for optimization\n\n";
  $prompt .= "Write in a friendly, Senior WebOps engineer tone - be conversational but professional, data-driven, and actionable. Use specific numbers, health scores, and status indicators from the data.\n\n";
  $prompt .= "Profile Data Summary:\n" . json_encode($profile_data, JSON_PRETTY_PRINT);
  
  // Call OpenAI API
  $openai_response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
    'timeout' => 30,
    'headers' => array(
      'Authorization' => 'Bearer ' . $api_key,
      'Content-Type' => 'application/json',
    ),
    'body' => json_encode(array(
      'model' => 'gpt-4o-mini',
      'messages' => array(
        array(
          'role' => 'system',
          'content' => 'You are a helpful assistant that summarizes VL Hub Profile data in a clear, professional manner.',
        ),
        array(
          'role' => 'user',
          'content' => $prompt,
        ),
      ),
      'max_tokens' => 1500,
      'temperature' => 0.7,
    )),
  ));
  
  if (is_wp_error($openai_response)) {
    return 'Failed to generate AI summary: ' . $openai_response->get_error_message();
  }
  
  $openai_body = json_decode(wp_remote_retrieve_body($openai_response), true);
  
  if (isset($openai_body['choices'][0]['message']['content'])) {
    return trim($openai_body['choices'][0]['message']['content']);
  }
  
  return 'Failed to generate AI summary.';
}

/**
 * Generate basic summary without OpenAI
 */
function luna_widget_generate_basic_summary($data) {
  // Extract all profile data
  $profile_data = luna_widget_extract_profile_data($data);
  
  $summary = "VL Hub Profile Data Summary:\n\n";
  
  if ($profile_data['ok']) {
    $summary .= "✓ Connection successful - Data retrieved from VL Hub.\n\n";
  } else {
    $summary .= "⚠ Connection status: " . ($profile_data['ok'] ? 'OK' : 'Not OK') . "\n\n";
  }
  
  if (!empty($profile_data['license_key'])) {
    $summary .= "License Key: " . $profile_data['license_key'] . "\n\n";
  }
  
  // Categories
  if (!empty($profile_data['categories'])) {
    $summary .= "Categories: " . implode(', ', $profile_data['categories']) . "\n\n";
  }
  
  // Data Streams
  if (!empty($profile_data['streams'])) {
    $stream_count = count($profile_data['streams']);
    $active_streams = 0;
    $summary .= "Data Streams: " . $stream_count . "\n";
    foreach ($profile_data['streams'] as $stream) {
      $status = $stream['status'];
      $summary .= "  • " . $stream['name'];
      if (!empty($stream['description'])) {
        $summary .= " (" . $stream['description'] . ")";
      }
      $summary .= " - Status: " . ucfirst($status);
      if (isset($stream['health_score']) && $stream['health_score'] > 0) {
        $summary .= " (Health: " . number_format($stream['health_score'], 1) . "%)";
      }
      if (!empty($stream['categories'])) {
        $summary .= " [" . implode(', ', $stream['categories']) . "]";
      }
      $summary .= "\n";
      if ($status === 'active') {
        $active_streams++;
      }
    }
    $summary .= "Active Streams: " . $active_streams . " / " . $stream_count . "\n\n";
  }
  
  // WordPress Installs
  if (!empty($profile_data['installs'])) {
    $install_count = count($profile_data['installs']);
    $active_installs = 0;
    $summary .= "WordPress Installs: " . $install_count . "\n";
    foreach ($profile_data['installs'] as $install) {
      $summary .= "  • " . $install['name'] . " - Status: " . ucfirst($install['status']);
      if (isset($install['health_score']) && $install['health_score'] > 0) {
        $summary .= " (Health: " . number_format($install['health_score'], 1) . "%)";
      }
      $summary .= "\n";
      if ($install['status'] === 'active') {
        $active_installs++;
      }
    }
    $summary .= "Active Installs: " . $active_installs . " / " . $install_count . "\n\n";
  }
  
  // Cloudflare Zones
  if (!empty($profile_data['zones'])) {
    $zone_count = count($profile_data['zones']);
    $active_zones = 0;
    $summary .= "Cloudflare Zones: " . $zone_count . "\n";
    foreach ($profile_data['zones'] as $zone) {
      $summary .= "  • " . $zone['name'] . " - Status: " . ucfirst($zone['status']) . "\n";
      if ($zone['status'] === 'active') {
        $active_zones++;
      }
    }
    $summary .= "Active Zones: " . $active_zones . " / " . $zone_count . "\n\n";
  }
  
  // Servers
  if (!empty($profile_data['servers'])) {
    $server_count = count($profile_data['servers']);
    $active_servers = 0;
    $summary .= "Servers: " . $server_count . "\n";
    foreach ($profile_data['servers'] as $server) {
      $summary .= "  • " . $server['name'] . " - Status: " . ucfirst($server['status']) . "\n";
      if ($server['status'] === 'active') {
        $active_servers++;
      }
    }
    $summary .= "Active Servers: " . $active_servers . " / " . $server_count . "\n\n";
  }
  
  // Other Connections
  if (!empty($profile_data['connections'])) {
    $conn_count = count($profile_data['connections']);
    $active_conns = 0;
    $summary .= "Other Connections: " . $conn_count . "\n";
    foreach ($profile_data['connections'] as $conn) {
      $summary .= "  • " . $conn['type'] . ": " . $conn['name'] . " - Status: " . ucfirst($conn['status']) . "\n";
      if ($conn['status'] === 'active') {
        $active_conns++;
      }
    }
    $summary .= "Active Connections: " . $active_conns . " / " . $conn_count . "\n\n";
  }
  
  // Total summary
  if ($profile_data['total_items'] > 0) {
    $summary .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $summary .= "Total Items Found: " . $profile_data['total_items'] . "\n";
  } else {
    $summary .= "⚠ No data streams, zones, servers, or connections found in the response.\n";
    $summary .= "This may indicate that the license key is not associated with any connected services yet.\n";
  }
  
  return $summary;
}

/**
 * Collect comprehensive WordPress data for syncing to Hub
 */
function luna_widget_collect_wordpress_data() {
  global $wp_version, $wpdb;
  
  // Get core update status
  $core_updates = get_site_transient('update_core');
  $is_update_available = !empty($core_updates->updates) && $core_updates->updates[0]->response === 'upgrade';
  
  // Get counts
  $posts_count = wp_count_posts('post');
  $pages_count = wp_count_posts('page');
  $users_count = count_users();
  $plugins = get_plugins();
  $themes = wp_get_themes();
  $comments_count = wp_count_comments();
  
  // Get plugin update information
  $plugin_updates = get_site_transient('update_plugins');
  $plugin_update_count = 0;
  $plugins_needing_update = array();
  if ($plugin_updates && isset($plugin_updates->response)) {
    $plugin_update_count = count($plugin_updates->response);
    $plugins_needing_update = array_keys($plugin_updates->response);
  }
  
  // Get active plugins
  $active_plugins = get_option('active_plugins', array());
  
  // Collect detailed plugin data
  $plugins_data = array();
  foreach ($plugins as $plugin_file => $plugin_data) {
    $is_active = in_array($plugin_file, $active_plugins);
    $needs_update = in_array($plugin_file, $plugins_needing_update);
    
    // Try to get activation date from options (if stored)
    $activation_key = 'plugin_activation_' . md5($plugin_file);
    $activation_date = get_option($activation_key, null);
    
    // Get plugin metadata
    $plugin_metadata = array();
    if (function_exists('get_plugin_data')) {
      $plugin_metadata = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
    }
    
    $plugins_data[] = array(
      'file' => $plugin_file,
      'name' => isset($plugin_data['Name']) ? $plugin_data['Name'] : basename($plugin_file),
      'version' => isset($plugin_data['Version']) ? $plugin_data['Version'] : 'Unknown',
      'author' => isset($plugin_data['Author']) ? $plugin_data['Author'] : 'Unknown',
      'author_uri' => isset($plugin_data['AuthorURI']) ? $plugin_data['AuthorURI'] : '',
      'description' => isset($plugin_data['Description']) ? $plugin_data['Description'] : '',
      'plugin_uri' => isset($plugin_data['PluginURI']) ? $plugin_data['PluginURI'] : '',
      'text_domain' => isset($plugin_data['TextDomain']) ? $plugin_data['TextDomain'] : '',
      'domain_path' => isset($plugin_data['DomainPath']) ? $plugin_data['DomainPath'] : '',
      'network' => isset($plugin_data['Network']) ? $plugin_data['Network'] : false,
      'requires_wp' => isset($plugin_data['RequiresWP']) ? $plugin_data['RequiresWP'] : '',
      'tested_wp' => isset($plugin_data['TestedWP']) ? $plugin_data['TestedWP'] : '',
      'requires_php' => isset($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '',
      'status' => $is_active ? 'active' : 'inactive',
      'needs_update' => $needs_update,
      'update_version' => $needs_update && isset($plugin_updates->response[$plugin_file]->new_version) ? $plugin_updates->response[$plugin_file]->new_version : null,
      'activation_date' => $activation_date,
      'last_modified' => file_exists(WP_PLUGIN_DIR . '/' . $plugin_file) ? date('Y-m-d H:i:s', filemtime(WP_PLUGIN_DIR . '/' . $plugin_file)) : null,
    );
  }
  
  // Get theme update information
  $theme_updates = get_site_transient('update_themes');
  $theme_update_count = 0;
  $themes_needing_update = array();
  if ($theme_updates && isset($theme_updates->response)) {
    $theme_update_count = count($theme_updates->response);
    $themes_needing_update = array_keys($theme_updates->response);
  }
  
  // Get active theme
  $active_theme = wp_get_theme();
  
  // Collect detailed theme data
  $themes_data = array();
  foreach ($themes as $theme_slug => $theme_obj) {
    $is_active = ($theme_slug === $active_theme->get_stylesheet());
    $needs_update = in_array($theme_slug, $themes_needing_update);
    
    $themes_data[] = array(
      'slug' => $theme_slug,
      'name' => $theme_obj->get('Name'),
      'version' => $theme_obj->get('Version'),
      'author' => $theme_obj->get('Author'),
      'author_uri' => $theme_obj->get('AuthorURI'),
      'description' => $theme_obj->get('Description'),
      'theme_uri' => $theme_obj->get('ThemeURI'),
      'text_domain' => $theme_obj->get('TextDomain'),
      'domain_path' => $theme_obj->get('DomainPath'),
      'requires_wp' => $theme_obj->get('RequiresWP'),
      'tested_wp' => $theme_obj->get('TestedWP'),
      'requires_php' => $theme_obj->get('RequiresPHP'),
      'status' => $is_active ? 'active' : 'inactive',
      'needs_update' => $needs_update,
      'update_version' => $needs_update && isset($theme_updates->response[$theme_slug]['new_version']) ? $theme_updates->response[$theme_slug]['new_version'] : null,
      'template' => $theme_obj->get('Template'),
      'parent' => $theme_obj->parent() ? $theme_obj->parent()->get('Name') : null,
      'last_modified' => file_exists($theme_obj->get_stylesheet_directory()) ? date('Y-m-d H:i:s', filemtime($theme_obj->get_stylesheet_directory())) : null,
    );
  }
  
  // Collect detailed user data
  $users_data = array();
  $users_query = get_users(array(
    'number' => -1, // Get all users
    'orderby' => 'registered',
    'order' => 'DESC',
  ));
  
  foreach ($users_query as $user) {
    $user_meta = get_user_meta($user->ID);
    $users_data[] = array(
      'id' => $user->ID,
      'login' => $user->user_login,
      'email' => $user->user_email,
      'display_name' => $user->display_name,
      'first_name' => get_user_meta($user->ID, 'first_name', true),
      'last_name' => get_user_meta($user->ID, 'last_name', true),
      'nickname' => get_user_meta($user->ID, 'nickname', true),
      'roles' => $user->roles,
      'registered' => $user->user_registered,
      'url' => $user->user_url,
      'description' => get_user_meta($user->ID, 'description', true),
      'last_login' => get_user_meta($user->ID, 'last_login', true), // If tracked by a plugin
      'post_count' => count_user_posts($user->ID),
    );
  }
  
  // Collect detailed comment data
  $comments_data = array();
  $comments_query = get_comments(array(
    'status' => 'all',
    'number' => 100, // Get last 100 comments
    'orderby' => 'comment_date',
    'order' => 'DESC',
  ));
  
  foreach ($comments_query as $comment) {
    $comments_data[] = array(
      'id' => $comment->comment_ID,
      'post_id' => $comment->comment_post_ID,
      'post_title' => get_the_title($comment->comment_post_ID),
      'author' => $comment->comment_author,
      'author_email' => $comment->comment_author_email,
      'author_url' => $comment->comment_author_url,
      'author_ip' => $comment->comment_author_IP,
      'date' => $comment->comment_date,
      'date_gmt' => $comment->comment_date_gmt,
      'content' => $comment->comment_content,
      'approved' => $comment->comment_approved,
      'status' => wp_get_comment_status($comment->comment_ID),
      'type' => $comment->comment_type,
      'parent' => $comment->comment_parent,
      'user_id' => $comment->user_id,
      'karma' => $comment->comment_karma,
    );
  }
  
  // Collect detailed post data
  $posts_query = new WP_Query(array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => -1, // Get all posts
    'orderby' => 'date',
    'order' => 'DESC',
  ));
  
  $posts_data = array();
  $all_keywords = array();
  $total_word_count = 0;
  
  foreach ($posts_query->posts as $post) {
    setup_postdata($post);
    
    // Get post content and calculate word count
    $content = get_post_field('post_content', $post->ID);
    $word_count = str_word_count(strip_tags($content));
    $total_word_count += $word_count;
    
    // Get categories
    $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
    
    // Get tags
    $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
    
    // Get author info
    $author_id = get_post_field('post_author', $post->ID);
    $author = get_userdata($author_id);
    
    // Get engagement metrics (comments, views if available)
    $post_comments = get_comments(array(
      'post_id' => $post->ID,
      'status' => 'approve',
      'count' => true,
    ));
    
    // Try to get view count from popular plugins or meta
    $view_count = get_post_meta($post->ID, 'post_views_count', true);
    if (empty($view_count)) {
      $view_count = get_post_meta($post->ID, 'views', true);
    }
    if (empty($view_count)) {
      $view_count = get_post_meta($post->ID, 'wpp_count', true);
    }
    
    // Extract keywords from post content and title
    $post_text = strtolower($post->post_title . ' ' . strip_tags($content));
    $words = str_word_count($post_text, 1);
    // Filter out common stop words
    $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been', 'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'just', 'now');
    $keywords = array_filter($words, function($word) use ($stop_words) {
      return strlen($word) > 3 && !in_array($word, $stop_words);
    });
    
    // Count keyword frequency
    $keyword_counts = array_count_values($keywords);
    foreach ($keyword_counts as $keyword => $count) {
      if (!isset($all_keywords[$keyword])) {
        $all_keywords[$keyword] = 0;
      }
      $all_keywords[$keyword] += $count;
    }
    
    $posts_data[] = array(
      'id' => $post->ID,
      'title' => $post->post_title,
      'slug' => $post->post_name,
      'excerpt' => get_the_excerpt($post->ID),
      'categories' => $categories,
      'tags' => $tags,
      'author' => array(
        'id' => $author_id,
        'name' => $author ? $author->display_name : 'Unknown',
        'login' => $author ? $author->user_login : '',
        'email' => $author ? $author->user_email : '',
      ),
      'date_published' => $post->post_date,
      'date_published_gmt' => $post->post_date_gmt,
      'last_updated' => $post->post_modified,
      'last_updated_gmt' => $post->post_modified_gmt,
      'word_count' => $word_count,
      'engagement' => array(
        'comments' => (int)$post_comments,
        'views' => !empty($view_count) ? (int)$view_count : 0,
      ),
      'url' => get_permalink($post->ID),
      'status' => $post->post_status,
    );
  }
  
  wp_reset_postdata();
  
  // Get top keywords by usage (top 20)
  arsort($all_keywords);
  $top_keywords = array_slice($all_keywords, 0, 20, true);
  
  // Collect page data as well
  $pages_query = new WP_Query(array(
    'post_type' => 'page',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
  ));
  
  $pages_data = array();
  
  foreach ($pages_query->posts as $page) {
    $content = get_post_field('post_content', $page->ID);
    $word_count = str_word_count(strip_tags($content));
    
    $author_id = get_post_field('post_author', $page->ID);
    $author = get_userdata($author_id);
    
    $pages_data[] = array(
      'id' => $page->ID,
      'title' => $page->post_title,
      'slug' => $page->post_name,
      'excerpt' => get_the_excerpt($page->ID),
      'author' => array(
        'id' => $author_id,
        'name' => $author ? $author->display_name : 'Unknown',
        'login' => $author ? $author->user_login : '',
      ),
      'date_published' => $page->post_date,
      'last_updated' => $page->post_modified,
      'word_count' => $word_count,
      'url' => get_permalink($page->ID),
      'parent' => $page->post_parent,
    );
  }
  
  wp_reset_postdata();
  
  // Build wp_core_data
  $wp_core_data = array(
    'version' => $wp_version,
    'update_available' => $is_update_available,
    'latest_version' => $is_update_available ? $core_updates->updates[0]->version : $wp_version,
    'php_version' => PHP_VERSION,
    'mysql_version' => $wpdb->db_version(),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_vars' => ini_get('max_input_vars'),
    'is_multisite' => is_multisite(),
    'site_url' => get_site_url(),
    'home_url' => get_home_url(),
    'admin_email' => get_option('admin_email'),
    'timezone' => get_option('timezone_string'),
    'date_format' => get_option('date_format'),
    'time_format' => get_option('time_format'),
    'start_of_week' => get_option('start_of_week'),
    'language' => get_option('WPLANG'),
    'permalink_structure' => get_option('permalink_structure'),
    'users_can_register' => get_option('users_can_register'),
    'default_role' => get_option('default_role'),
    'comment_moderation' => get_option('comment_moderation'),
    'comment_registration' => get_option('comment_registration'),
    'close_comments_for_old_posts' => get_option('close_comments_for_old_posts'),
    'close_comments_days_old' => get_option('close_comments_days_old'),
    'thread_comments' => get_option('thread_comments'),
    'thread_comments_depth' => get_option('thread_comments_depth'),
    'page_comments' => get_option('page_comments'),
    'comments_per_page' => get_option('comments_per_page'),
    'default_comments_page' => get_option('default_comments_page'),
    'comment_order' => get_option('comment_order'),
  );
  
  // Build WordPress data structure matching Hub format
  $wordpress_data = array(
    'name' => 'WordPress Data',
    'description' => 'WordPress site data from client ' . home_url('/'),
    'url' => home_url('/'),
    'categories' => array('content', 'cms'),
    'health_score' => 100, // Can be calculated based on updates, security, etc.
    'error_count' => 0,
    'warning_count' => 0,
    'status' => 'active',
    'last_updated' => current_time('mysql'),
    'wp_version' => $wp_version,
    'php_version' => PHP_VERSION,
    'mysql_version' => $wpdb->db_version(),
    'posts_total' => (int)$posts_count->publish,
    'pages_total' => (int)$pages_count->publish,
    'users_total' => (int)$users_count['total_users'],
    'plugins_total' => count($plugins),
    'plugins_active' => count($active_plugins),
    'plugins_needing_update' => $plugin_update_count,
    'themes_total' => count($themes),
    'themes_needing_update' => $theme_update_count,
    'comments_total' => (int)$comments_count->total_comments,
    'wp_core_data' => $wp_core_data,
    'posts_data' => $posts_data,
    'pages_data' => $pages_data,
    'plugins_data' => $plugins_data,
    'themes_data' => $themes_data,
    'users_data' => $users_data,
    'comments_data' => $comments_data,
    'content_metrics' => array(
      'total_word_count' => $total_word_count,
      'average_word_count_per_post' => count($posts_data) > 0 ? round($total_word_count / count($posts_data)) : 0,
      'top_keywords' => $top_keywords,
    ),
    'source_url' => home_url('/'),
    'report_link' => '#wordpress-site-overview',
    'license_key' => get_option(LUNA_WIDGET_OPT_LICENSE, ''),
    'created' => current_time('mysql'),
  );
  
  return $wordpress_data;
}

/**
 * Sync WordPress data to VL Hub
 */
function luna_widget_sync_to_hub($request) {
  $license = get_option(LUNA_WIDGET_OPT_LICENSE, '');
  $hub_base = luna_widget_hub_base();
  
  if (empty($license)) {
    return new WP_Error('no_license', 'License key is required. Please enter your Corporate License Code in the settings.', array('status' => 400));
  }
  
  // Collect WordPress data
  $wordpress_data = luna_widget_collect_wordpress_data();
  
  // Generate unique ID for this WordPress data stream
  $data_id = 'wordpress_data_' . md5(home_url('/') . $license);
  $wordpress_data['id'] = $data_id;
  
  // Send to Hub using sync-client-data endpoint
  // The endpoint expects license as query parameter and category + data in JSON body
  $endpoint_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/sync-client-data?license=' . urlencode($license) . '&category=content';
  
  // Send the full WordPress data structure
  // The Hub will store it in the data streams with the ID we generated
  $response = wp_remote_post($endpoint_url, array(
    'timeout' => 30,
    'sslverify' => true,
    'headers' => array(
      'Content-Type' => 'application/json',
      'X-Luna-License' => $license,
      'X-Luna-Site' => home_url('/'),
    ),
    'body' => json_encode(array(
      'license' => $license,
      'category' => 'content',
      // Send the full WordPress data structure that matches the Hub's expected format
      'wordpress_data' => array(
        $data_id => $wordpress_data
      ),
      // Also include posts and pages arrays for the content category handler
      'posts' => isset($wordpress_data['posts_data']) ? $wordpress_data['posts_data'] : array(),
      'pages' => isset($wordpress_data['pages_data']) ? $wordpress_data['pages_data'] : array(),
    )),
  ));
  
  if (is_wp_error($response)) {
    error_log('[Luna Widget] Sync to Hub error: ' . $response->get_error_message());
    return new WP_Error('sync_error', 'Failed to sync data to VL Hub: ' . $response->get_error_message(), array('status' => 500));
  }
  
  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  
  if ($response_code !== 200 && $response_code !== 201) {
    error_log('[Luna Widget] Hub returned error code ' . $response_code . ': ' . $response_body);
    return new WP_Error('http_error', 'VL Hub returned error code ' . $response_code . ': ' . $response_body, array('status' => $response_code));
  }
  
  $data = json_decode($response_body, true);
  
  // Also sync Training Data to Hub
  $training_sync_result = luna_widget_sync_training_data_to_hub($license, $hub_base);
  
  $response_data = array(
    'success' => true,
    'message' => 'WordPress data has been successfully synced to VL Hub Profile. The data will appear in the "All Data Streams" table under "WordPress CMS" in your VL Hub Profile.',
    'data' => array(
      'wp_version' => $wordpress_data['wp_version'],
      'php_version' => $wordpress_data['php_version'],
      'posts_total' => $wordpress_data['posts_total'],
      'pages_total' => $wordpress_data['pages_total'],
      'users_total' => $wordpress_data['users_total'],
      'plugins_total' => $wordpress_data['plugins_total'],
      'themes_total' => $wordpress_data['themes_total'],
      'last_updated' => $wordpress_data['last_updated'],
    ),
    'hub_response' => $data,
  );
  
  if ($training_sync_result['success']) {
    $response_data['training_sync'] = $training_sync_result;
    $response_data['message'] .= ' Training Data has also been synced and will appear as a "Luna Intel" data stream.';
  }
  
  return rest_ensure_response($response_data);
}

/**
 * Sync Training Data to VL Hub as a data stream
 */
function luna_widget_sync_training_data_to_hub($license, $hub_base) {
  $training_items = luna_widget_get_training_data();
  
  if (empty($training_items)) {
    return array('success' => false, 'message' => 'No training data to sync');
  }
  
  // Build training data stream structure
  $training_stream_data = array();
  $has_luna_responses = false;
  foreach ($training_items as $index => $training_item) {
    $stream_item = $training_item;
    if (isset($training_item['luna_test_response'])) {
      $has_luna_responses = true;
      $stream_item['luna_consumed'] = true;
      $stream_item['luna_consumed_date'] = isset($training_item['luna_test_response_date']) ? $training_item['luna_test_response_date'] : '';
    }
    $training_stream_data[] = $stream_item;
  }
  
  $training_data = array(
    'id' => 'training_data',
    'name' => 'Training Data',
    'status' => 'active',
    'categories' => array('luna-intel', 'training', 'company-data'),
    'health_score' => 100.0,
    'description' => 'Company training data for Luna AI context and responses. This data is automatically consumed by Luna and used to provide context-aware, data-driven responses.',
    'training_items' => $training_stream_data,
    'item_count' => count($training_items),
    'luna_consumed' => $has_luna_responses,
    'last_updated' => !empty($training_items) && isset($training_items[count($training_items) - 1]['created']) ? $training_items[count($training_items) - 1]['created'] : current_time('mysql'),
  );
  
  // Generate unique ID for this training data stream
  $data_id = 'training_data_' . md5(home_url('/') . $license);
  $training_data['id'] = $data_id;
  
  // Send to Hub using sync-client-data endpoint with category=luna-intel
  $endpoint_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/sync-client-data?license=' . urlencode($license) . '&category=luna-intel';
  
  $response = wp_remote_post($endpoint_url, array(
    'timeout' => 30,
    'sslverify' => true,
    'headers' => array(
      'Content-Type' => 'application/json',
      'X-Luna-License' => $license,
      'X-Luna-Site' => home_url('/'),
    ),
    'body' => json_encode(array(
      'license' => $license,
      'category' => 'luna-intel',
      'data' => $training_data,
    )),
  ));
  
  if (is_wp_error($response)) {
    error_log('[Luna Widget] Training data sync error: ' . $response->get_error_message());
    return array('success' => false, 'message' => 'Failed to sync training data: ' . $response->get_error_message());
  }
  
  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  
  if ($response_code !== 200 && $response_code !== 201) {
    error_log('[Luna Widget] Hub returned error code ' . $response_code . ' for training data: ' . $response_body);
    return array('success' => false, 'message' => 'Hub returned error code ' . $response_code);
  }
  
  $data = json_decode($response_body, true);
  
  return array(
    'success' => true,
    'message' => 'Training Data synced successfully',
    'item_count' => count($training_items),
    'hub_response' => $data,
  );
}

/* Settings page */
function luna_widget_admin_page(){
  if (!current_user_can('manage_options')) return;
  $mode  = get_option(LUNA_WIDGET_OPT_MODE, 'widget');
  $ui    = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
  $lic   = get_option(LUNA_WIDGET_OPT_LICENSE, '');
  $hub   = luna_widget_hub_base();
  $last  = get_option(LUNA_WIDGET_OPT_LAST_PING, array());
  ?>
  <div class="wrap">
    <h1>Luna Chat — Widget</h1>

    <div class="notice notice-info" style="padding:8px 12px;margin-top:10px;">
      <strong>Hub connection:</strong>
      <?php if (!empty($last['code'])): ?>
        Response <code><?php echo (int)$last['code']; ?></code> at <?php echo esc_html(isset($last['ts']) ? $last['ts'] : ''); ?>.
      <?php else: ?>
        No heartbeat recorded yet.
      <?php endif; ?>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <button type="button" class="button button-primary" id="luna-test-connection">Test Connection</button>
        <button type="button" class="button button-secondary" id="luna-sync-to-hub">Sync to Hub</button>
        <span style="opacity:.8;">Hub: <?php echo esc_html($hub); ?></span>
      </div>
      <div id="luna-test-connection-results" style="margin-top:12px;display:none;">
        <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #00a32a;padding:12px;margin-top:8px;">
          <h3 style="margin:0 0 8px 0;">Connection Test Results</h3>
          <div id="luna-test-connection-content" style="max-height:400px;overflow-y:auto;"></div>
        </div>
      </div>
      <div id="luna-sync-to-hub-results" style="margin-top:12px;display:none;">
        <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:12px;margin-top:8px;">
          <h3 style="margin:0 0 8px 0;">Sync to Hub Results</h3>
          <div id="luna-sync-to-hub-content" style="max-height:400px;overflow-y:auto;"></div>
        </div>
      </div>
    </div>

    <form method="post" action="options.php">
      <?php settings_fields('luna_widget_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Corporate License Code</th>
          <td>
            <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_LICENSE); ?>" value="<?php echo esc_attr($lic); ?>" class="regular-text code" placeholder="VL-XXXX-XXXX-XXXX" />
            <p class="description">Required for secured Hub data.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">License Server (Hub)</th>
          <td>
            <input type="url" name="<?php echo esc_attr(LUNA_WIDGET_OPT_LICENSE_SERVER); ?>" value="<?php echo esc_url($hub); ?>" class="regular-text code" placeholder="https://visiblelight.ai" />
            <p class="description">HTTPS enforced; trailing slashes removed automatically.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Display Options</th>
          <td>
            <label style="display:block;margin-top:.4rem;">
              <input type="checkbox" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY); ?>" value="1" <?php checked(get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0'), '1'); ?>>
              Supercluster only
            </label>
            <p class="description" style="margin-top:.25rem;margin-left:1.5rem;">When enabled, the widget will only appear in Supercluster and not on the frontend site.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Widget UI</th>
          <td>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Title</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[title]" value="<?php echo esc_attr(isset($ui['title']) ? $ui['title'] : 'Luna Chat'); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Avatar URL</span>
              <input type="url" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[avatar_url]" value="<?php echo esc_url(isset($ui['avatar_url']) ? $ui['avatar_url'] : ''); ?>" class="regular-text code" placeholder="https://…/luna.png" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Header text</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[header_text]" value="<?php echo esc_attr(isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna"); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Sub text</span>
              <input type="text" name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[sub_text]" value="<?php echo esc_attr(isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?'); ?>" />
            </label>
            <label style="display:block;margin:.25rem 0;">
              <span style="display:inline-block;width:140px;">Position</span>
              <?php $pos = isset($ui['position']) ? $ui['position'] : 'bottom-right'; ?>
              <select name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[position]">
                <?php foreach (array('top-left','top-center','top-right','bottom-left','bottom-center','bottom-right') as $p): ?>
                  <option value="<?php echo esc_attr($p); ?>" <?php selected($p, $pos); ?>><?php echo esc_html($p); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row">Button Descriptions</th>
          <td>
            <p class="description" style="margin-bottom:1rem;">Customize the descriptions that appear when users hover over the "?" icon on each Luna greeting button.</p>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Chat</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_chat]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_chat']) ? $ui['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Report</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_report]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_report']) ? $ui['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Compose</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_compose]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_compose']) ? $ui['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.'); ?></textarea>
            </label>
            <label style="display:block;margin:.75rem 0;">
              <span style="display:inline-block;width:140px;vertical-align:top;padding-top:4px;">Luna Automate</span>
              <textarea name="<?php echo esc_attr(LUNA_WIDGET_OPT_SETTINGS); ?>[button_desc_automate]" rows="2" style="width:400px;max-width:100%;"><?php echo esc_textarea(isset($ui['button_desc_automate']) ? $ui['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.'); ?></textarea>
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row">OpenAI API key</th>
          <td>
            <input type="password" name="luna_openai_api_key"
                   value="<?php echo esc_attr( get_option('luna_openai_api_key','') ); ?>"
                   class="regular-text code" placeholder="sk-..." />
            <p class="description">If present, AI answers are blended with Hub facts. Otherwise, deterministic replies only.</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Save changes'); ?>
    </form>
  </div>

  <script>
    (function(){
      const testBtn = document.getElementById('luna-test-connection');
      const resultsDiv = document.getElementById('luna-test-connection-results');
      const contentDiv = document.getElementById('luna-test-connection-content');
      
      if (testBtn) {
        testBtn.addEventListener('click', async function(e) {
          e.preventDefault();
          
          // Disable button and show loading
          testBtn.disabled = true;
          testBtn.textContent = 'Testing...';
          resultsDiv.style.display = 'block';
          contentDiv.innerHTML = '<p style=\'margin:0;\'><span class=\'spinner is-active\' style=\'float:none;margin:0 8px 0 0;\'></span>Fetching data from VL Hub...</p>';
          
          try {
            const response = await fetch('<?php echo esc_url_raw(rest_url('luna_widget/v1/test-connection')); ?>', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
              }
            });
            
            const data = await response.json();
            
            if (data.success) {
              const summaryText = data.summary || 'No summary available';
              contentDiv.innerHTML = '<div style=\'white-space:pre-wrap;font-family:monospace;font-size:12px;line-height:1.6;\'>' + 
                '<h4 style=\'margin:0 0 12px 0;color:#00a32a;\'>✓ Connection Successful</h4>' +
                '<div id=\'luna-summary-container\' style=\'background:#f0f0f1;padding:12px;border-radius:4px;margin-bottom:12px;position:relative;\'>' +
                '<div style=\'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;\'>' +
                '<strong>Summary:</strong>' +
                '<button id=\'luna-copy-summary-btn\' style=\'display:none;background:transparent;border:1px solid #ccc;border-radius:3px;padding:4px 8px;cursor:pointer;font-size:11px;color:#2271b1;transition:all 0.2s;\' title=\'Copy summary to clipboard\'>' +
                '<span class=\'dashicons dashicons-clipboard\' style=\'font-size:16px;width:16px;height:16px;line-height:1;\'></span>' +
                '</button>' +
                '</div>' +
                '<div id=\'luna-summary-text\'>' + summaryText + '</div>' +
                '</div>' +
                (data.raw_data ? '<details style=\'margin-top:12px;\'><summary style=\'cursor:pointer;font-weight:600;\'>View Raw Data</summary><pre style=\'background:#f0f0f1;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;max-height:300px;overflow-y:auto;\'>' + 
                JSON.stringify(data.raw_data, null, 2) + '</pre></details>' : '') +
                '</div>';
              
              // Show copy button on hover
              const summaryContainer = document.getElementById('luna-summary-container');
              const copyBtn = document.getElementById('luna-copy-summary-btn');
              if (summaryContainer && copyBtn) {
                summaryContainer.addEventListener('mouseenter', function() {
                  copyBtn.style.display = 'block';
                });
                summaryContainer.addEventListener('mouseleave', function() {
                  copyBtn.style.display = 'none';
                });
                
                // Copy to clipboard on click
                copyBtn.addEventListener('click', function(e) {
                  e.preventDefault();
                  e.stopPropagation();
                  const summaryTextEl = document.getElementById('luna-summary-text');
                  if (summaryTextEl) {
                    const textToCopy = summaryTextEl.textContent || summaryTextEl.innerText;
                    navigator.clipboard.writeText(textToCopy).then(function() {
                      copyBtn.innerHTML = '<span class=\'dashicons dashicons-yes\' style=\'font-size:16px;width:16px;height:16px;line-height:1;color:#00a32a;\'></span>';
                      copyBtn.style.borderColor = '#00a32a';
                      copyBtn.style.color = '#00a32a';
                      setTimeout(function() {
                        copyBtn.innerHTML = '<span class=\'dashicons dashicons-clipboard\' style=\'font-size:16px;width:16px;height:16px;line-height:1;\'></span>';
                        copyBtn.style.borderColor = '#ccc';
                        copyBtn.style.color = '#2271b1';
                      }, 2000);
                    }).catch(function(err) {
                      console.error('Failed to copy:', err);
                      copyBtn.innerHTML = '<span class=\'dashicons dashicons-warning\' style=\'font-size:16px;width:16px;height:16px;line-height:1;color:#d63638;\'></span>';
                      setTimeout(function() {
                        copyBtn.innerHTML = '<span class=\'dashicons dashicons-clipboard\' style=\'font-size:16px;width:16px;height:16px;line-height:1;\'></span>';
                      }, 2000);
                    });
                  }
                });
              }
            } else {
              contentDiv.innerHTML = '<div style=\'color:#d63638;\'><strong>✗ Connection Failed</strong><br>' + 
                (data.message || 'Unknown error occurred') + '</div>';
            }
          } catch (error) {
            contentDiv.innerHTML = '<div style=\'color:#d63638;\'><strong>✗ Error</strong><br>' + 
              error.message + '</div>';
          } finally {
            testBtn.disabled = false;
            testBtn.textContent = 'Test Connection';
          }
        });
      }
      
      const syncBtn = document.getElementById('luna-sync-to-hub');
      const syncResultsDiv = document.getElementById('luna-sync-to-hub-results');
      const syncContentDiv = document.getElementById('luna-sync-to-hub-content');
      
      if (syncBtn) {
        syncBtn.addEventListener('click', async function(e) {
          e.preventDefault();
          
          // Disable button and show loading
          syncBtn.disabled = true;
          syncBtn.textContent = 'Syncing...';
          syncResultsDiv.style.display = 'block';
          syncContentDiv.innerHTML = '<p style=\'margin:0;\'><span class=\'spinner is-active\' style=\'float:none;margin:0 8px 0 0;\'></span>Collecting WordPress data and syncing to VL Hub...</p>';
          
          try {
            const response = await fetch('<?php echo esc_url_raw(rest_url('luna_widget/v1/sync-to-hub')); ?>', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
              }
            });
            
            const data = await response.json();
            
            if (data.success) {
              syncContentDiv.innerHTML = '<div style=\'white-space:pre-wrap;font-family:monospace;font-size:12px;line-height:1.6;\'>' + 
                '<h4 style=\'margin:0 0 12px 0;color:#00a32a;\'>✓ Sync Successful</h4>' +
                '<div style=\'background:#f0f0f1;padding:12px;border-radius:4px;margin-bottom:12px;\'>' +
                '<strong>Message:</strong><br>' + 
                (data.message || 'WordPress data has been synced to VL Hub Profile successfully.') +
                '</div>' +
                (data.data ? '<details style=\'margin-top:12px;\'><summary style=\'cursor:pointer;font-weight:600;\'>View Synced Data Summary</summary><pre style=\'background:#f0f0f1;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;max-height:300px;overflow-y:auto;\'>' + 
                JSON.stringify(data.data, null, 2) + '</pre></details>' : '') +
                '</div>';
            } else {
              syncContentDiv.innerHTML = '<div style=\'color:#d63638;\'><strong>✗ Sync Failed</strong><br>' + 
                (data.message || data.error || 'Unknown error occurred') + '</div>';
            }
          } catch (error) {
            syncContentDiv.innerHTML = '<div style=\'color:#d63638;\'><strong>✗ Error</strong><br>' + 
              error.message + '</div>';
          } finally {
            syncBtn.disabled = false;
            syncBtn.textContent = 'Sync to Hub';
          }
        });
      }
    })();
  </script>
  <?php
}

/* ============================================================
 * HELPER FUNCTIONS
 * ============================================================ */
function luna_get_license() { 
  return trim((string) get_option(LUNA_WIDGET_OPT_LICENSE, '')); 
}

function luna_widget_hub_url($path = '') {
  $path = '/' . ltrim($path, '/');
  return luna_widget_hub_base() . $path;
}

/* ============================================================
 * HUB DATA FETCHING (Using new connection fetcher)
 * ============================================================ */
/**
 * Fetch comprehensive data from VL Hub using both all-connections and data-streams endpoints
 * This merges data from both sources to provide complete coverage
 */
function luna_widget_fetch_hub_data($force_refresh = false) {
  $license = luna_get_license();
  if (empty($license)) {
    error_log('[Luna Widget] No license key found');
    return null;
  }
  
  $cache_key = 'luna_widget_hub_data_' . md5($license . '|' . luna_widget_hub_base());
  
  if (!$force_refresh) {
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached)) {
      error_log('[Luna Widget] Using cached hub data');
      return $cached;
    }
  }
  
  $hub_base = luna_widget_hub_base();
  $merged_data = array(
    'source' => 'merged',
    'all_connections' => null,
    'data_streams' => null,
    'merged_at' => current_time('mysql'),
  );
  
  // Fetch from all-connections endpoint
  $all_connections_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/all-connections?license=' . urlencode($license);
  error_log('[Luna Widget] Fetching from all-connections: ' . $all_connections_url);
  
  $response1 = wp_remote_get($all_connections_url, array(
    'timeout' => 30,
    'sslverify' => true,
    'headers' => array(
      'X-Luna-License' => $license,
      'X-Luna-Site' => home_url('/'),
      'Accept' => 'application/json',
    ),
  ));
  
  if (!is_wp_error($response1)) {
    $response_code1 = wp_remote_retrieve_response_code($response1);
    $response_body1 = wp_remote_retrieve_body($response1);
    
    if ($response_code1 === 200) {
      $data1 = json_decode($response_body1, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $merged_data['all_connections'] = $data1;
        error_log('[Luna Widget] Successfully fetched all-connections data');
      } else {
        error_log('[Luna Widget] Invalid JSON from all-connections: ' . json_last_error_msg());
      }
    } else {
      error_log('[Luna Widget] all-connections returned error code ' . $response_code1);
    }
  } else {
    error_log('[Luna Widget] Error fetching all-connections: ' . $response1->get_error_message());
  }
  
  // Fetch from data-streams endpoint
  $data_streams_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/data-streams?license=' . urlencode($license);
  error_log('[Luna Widget] Fetching from data-streams: ' . $data_streams_url);
  
  $response2 = wp_remote_get($data_streams_url, array(
    'timeout' => 30,
    'sslverify' => true,
    'headers' => array(
      'X-Luna-License' => $license,
      'X-Luna-Site' => home_url('/'),
      'Accept' => 'application/json',
    ),
  ));
  
  if (!is_wp_error($response2)) {
    $response_code2 = wp_remote_retrieve_response_code($response2);
    $response_body2 = wp_remote_retrieve_body($response2);
    
    if ($response_code2 === 200) {
      $data2 = json_decode($response_body2, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $merged_data['data_streams'] = $data2;
        error_log('[Luna Widget] Successfully fetched data-streams data');
      } else {
        error_log('[Luna Widget] Invalid JSON from data-streams: ' . json_last_error_msg());
      }
    } else {
      error_log('[Luna Widget] data-streams returned error code ' . $response_code2);
    }
  } else {
    error_log('[Luna Widget] Error fetching data-streams: ' . $response2->get_error_message());
  }
  
  // Merge the data intelligently
  // Priority: all_connections data takes precedence, but data_streams supplements it
  $final_data = $merged_data['all_connections'];
  
  // If we have data_streams, merge it in
  if (!empty($merged_data['data_streams'])) {
    $streams_data = $merged_data['data_streams'];
    
    // Merge streams from data-streams into the final data structure
    if (isset($streams_data['data']) && is_array($streams_data['data'])) {
      // If final_data doesn't have a 'data' key, create it
      if (!isset($final_data['data'])) {
        $final_data['data'] = array();
      }
      
      // Merge client_streams if they exist in data-streams
      if (isset($streams_data['data']['client_streams']) && is_array($streams_data['data']['client_streams'])) {
        if (!isset($final_data['data']['client_streams'])) {
          $final_data['data']['client_streams'] = array();
        }
        // Merge streams, with all_connections taking precedence for duplicates
        foreach ($streams_data['data']['client_streams'] as $stream_id => $stream) {
          if (!isset($final_data['data']['client_streams'][$stream_id])) {
            $final_data['data']['client_streams'][$stream_id] = $stream;
            $final_data['data']['client_streams'][$stream_id]['_source'] = 'data-streams';
          } else {
            // Merge any missing or empty fields from data-streams into the existing all-connections stream
            $existing_stream = &$final_data['data']['client_streams'][$stream_id];

            foreach ($stream as $key => $value) {
              $existing_value = isset($existing_stream[$key]) ? $existing_stream[$key] : null;

              $is_missing = !isset($existing_stream[$key]);
              $is_empty_array = is_array($existing_value) && empty($existing_value);
              $is_empty_scalar = !is_array($existing_value) && ($existing_value === '' || $existing_value === null);

              if ($is_missing || $is_empty_array || $is_empty_scalar) {
                $existing_stream[$key] = $value;
              } elseif (is_array($existing_value) && is_array($value) && !empty($value)) {
                // Preserve existing values but add any additional keys from data-streams
                $existing_stream[$key] = array_merge($value, $existing_value);
              }
            }

            // Mark as cross-referenced
            $existing_stream['_cross_referenced'] = true;
            $existing_stream['_sources'] = array('all-connections', 'data-streams');
          }
        }
      }

      // Add any other top-level data from data-streams that doesn't exist in all_connections
      foreach ($streams_data['data'] as $key => $value) {
        if ($key === 'client_streams') {
          continue;
        }

        $existing_value = isset($final_data['data'][$key]) ? $final_data['data'][$key] : null;
        $is_missing = !isset($final_data['data'][$key]);
        $is_empty_array = is_array($existing_value) && empty($existing_value);
        $is_empty_scalar = !is_array($existing_value) && ($existing_value === '' || $existing_value === null);

        if ($is_missing || $is_empty_array || $is_empty_scalar) {
          $final_data['data'][$key] = $value;
          if (is_array($value)) {
            $final_data['data'][$key]['_source'] = 'data-streams';
          }
        } elseif (is_array($existing_value) && is_array($value) && !empty($value)) {
          // Merge supplemental metadata while keeping all-connections values authoritative
          $final_data['data'][$key] = array_merge($value, $existing_value);
          $final_data['data'][$key]['_sources'] = array('all-connections', 'data-streams');
        }
      }
    }
    
    // Add metadata about the merge
    $final_data['_merged_sources'] = array(
      'all_connections' => !empty($merged_data['all_connections']),
      'data_streams' => !empty($merged_data['data_streams']),
      'merged_at' => $merged_data['merged_at'],
    );
  }
  
  // If we only have data_streams (no all_connections), use that
  if (empty($final_data) && !empty($merged_data['data_streams'])) {
    $final_data = $merged_data['data_streams'];
    $final_data['_merged_sources'] = array(
      'all_connections' => false,
      'data_streams' => true,
      'merged_at' => $merged_data['merged_at'],
    );
  }
  
  if (empty($final_data)) {
    error_log('[Luna Widget] No data retrieved from either endpoint');
    return null;
  }

  // Attach raw payloads so downstream consumers (Luna Chat/Compose) can see everything
  $final_data['_raw_sources'] = array(
    'all_connections' => $merged_data['all_connections'],
    'data_streams'    => $merged_data['data_streams'],
  );

  // Cache the merged data
  set_transient($cache_key, $final_data, LUNA_CACHE_PROFILE_TTL);
  
  error_log('[Luna Widget] Successfully fetched and merged data from both endpoints');
  return $final_data;
}

/**
 * Get comprehensive facts for Luna Chat/Compose
 * Uses our new extraction method that recognizes all data types
 */
function luna_widget_get_comprehensive_facts() {
  // Fetch hub data (use cache if available to avoid performance issues)
  $hub_data = luna_widget_fetch_hub_data(false);
  
  if (!$hub_data) {
    error_log('[Luna Widget] No hub_data returned, falling back to basic facts');
    // Fallback to basic local facts
    return luna_widget_get_basic_facts();
  }
  
  // Debug: Log the top-level structure
  error_log('[Luna Widget] hub_data structure - top level keys: ' . implode(', ', array_keys($hub_data)));
  
  // Extract profile data using our new method
  $profile_data = luna_widget_extract_profile_data($hub_data);
  
  // Build facts array for OpenAI
  $facts = array(
    'site_url' => home_url('/'),
    'https' => is_ssl(),
    'wp_version' => get_bloginfo('version'),
    'theme' => '',
    'theme_version' => '',
    'theme_active' => true,
    'counts' => array(
      'pages' => 0,
      'posts' => 0,
      'users' => 0,
      'plugins' => 0,
    ),
    'updates' => array(
      'plugins' => 0,
      'themes' => 0,
      'core' => 0,
    ),
    'comprehensive' => true,
    'profile_data' => $profile_data,
    'raw_hub_payloads' => array(),
  );

  // Preserve raw hub payloads for downstream analysis
  if (isset($hub_data['_raw_sources']) && is_array($hub_data['_raw_sources'])) {
    $facts['raw_hub_payloads'] = $hub_data['_raw_sources'];
  }
  $facts['raw_hub_payloads']['merged'] = $hub_data;
  
  // Extract WordPress data if available - check ALL possible locations
  $wordpress_data = null;
  
  // Debug: Log the structure we're working with
  error_log('[Luna Widget] Extracting WordPress data from hub_data. Top level keys: ' . implode(', ', array_keys($hub_data)));
  
  // Strategy 1: Check in hub_data['data']['client_streams'] (most common)
  if (isset($hub_data['data']['client_streams']) && is_array($hub_data['data']['client_streams'])) {
    error_log('[Luna Widget] Checking client_streams. Found ' . count($hub_data['data']['client_streams']) . ' streams');
    foreach ($hub_data['data']['client_streams'] as $stream_id => $stream) {
        if (!is_array($stream)) continue;
        
      // Check if this is WordPress data
        $is_wordpress = false;
      if (strpos($stream_id, 'wordpress') !== false) {
          $is_wordpress = true;
        } elseif (isset($stream['wp_version']) || isset($stream['wp_core_data'])) {
          $is_wordpress = true;
        } elseif (isset($stream['posts_data']) || isset($stream['pages_data'])) {
          $is_wordpress = true;
      } elseif (isset($stream['posts_total']) || isset($stream['pages_total'])) {
        $is_wordpress = true;
      } elseif (isset($stream['name']) && stripos($stream['name'], 'WordPress') !== false) {
        $is_wordpress = true;
        }
        
        if ($is_wordpress) {
          $wordpress_data = $stream;
        error_log('[Luna Widget] Found WordPress data in client_streams[' . $stream_id . ']. Has posts_data: ' . (isset($stream['posts_data']) ? 'yes (' . (is_array($stream['posts_data']) ? count($stream['posts_data']) : 'not array') . ' posts)' : 'no'));
          break;
        }
      }
    }
    
  // Strategy 2: Check at hub_data['data'] top level
  if (!$wordpress_data && isset($hub_data['data']) && is_array($hub_data['data'])) {
    error_log('[Luna Widget] WordPress data not in client_streams, checking top level of data');
    foreach ($hub_data['data'] as $key => $item) {
        if (!is_array($item)) continue;
        
      // Check if this is WordPress data
        $is_wordpress = false;
      if (strpos($key, 'wordpress') !== false) {
          $is_wordpress = true;
        } elseif (isset($item['wp_version']) || isset($item['wp_core_data'])) {
          $is_wordpress = true;
      } elseif (isset($item['posts_data']) || isset($item['pages_data'])) {
        $is_wordpress = true;
      } elseif (isset($item['posts_total']) || isset($item['pages_total'])) {
        $is_wordpress = true;
        } elseif (isset($item['name']) && stripos($item['name'], 'WordPress') !== false) {
          $is_wordpress = true;
      }
      
      if ($is_wordpress) {
        $wordpress_data = $item;
        error_log('[Luna Widget] Found WordPress data at data[' . $key . ']. Has posts_data: ' . (isset($item['posts_data']) ? 'yes (' . (is_array($item['posts_data']) ? count($item['posts_data']) : 'not array') . ' posts)' : 'no'));
        break;
      }
    }
  }
  
  // Strategy 3: Check at hub_data top level (flat structure)
  if (!$wordpress_data) {
    error_log('[Luna Widget] WordPress data not in data, checking hub_data top level');
    foreach ($hub_data as $key => $item) {
      if (!is_array($item)) continue;
      
      // Check if this is WordPress data
      $is_wordpress = false;
      if (strpos($key, 'wordpress') !== false) {
        $is_wordpress = true;
      } elseif (isset($item['wp_version']) || isset($item['wp_core_data'])) {
        $is_wordpress = true;
        } elseif (isset($item['posts_data']) || isset($item['pages_data'])) {
          $is_wordpress = true;
      } elseif (isset($item['posts_total']) || isset($item['pages_total'])) {
        $is_wordpress = true;
      } elseif (isset($item['name']) && stripos($item['name'], 'WordPress') !== false) {
        $is_wordpress = true;
        }
        
        if ($is_wordpress) {
          $wordpress_data = $item;
        error_log('[Luna Widget] Found WordPress data at hub_data[' . $key . ']. Has posts_data: ' . (isset($item['posts_data']) ? 'yes (' . (is_array($item['posts_data']) ? count($item['posts_data']) : 'not array') . ' posts)' : 'no'));
          break;
        }
      }
    }
  
  // Strategy 4: Explicitly check data-streams endpoint structure if still not found
  // Check if we have data_streams source but wordpress_data lacks detailed posts_data/pages_data
  $needs_detailed_data = !$wordpress_data || (isset($wordpress_data['posts_total']) && (int)$wordpress_data['posts_total'] > 0 && (!isset($wordpress_data['posts_data']) || empty($wordpress_data['posts_data'])));
  
  if ($needs_detailed_data && isset($hub_data['_merged_sources']['data_streams']) && $hub_data['_merged_sources']['data_streams']) {
    error_log('[Luna Widget] WordPress data missing detailed posts_data/pages_data; explicitly fetching data-streams');
    
    // Fetch data-streams directly to get detailed content data
    $license = luna_get_license();
    if (!empty($license)) {
      $hub_base = luna_widget_hub_base();
      $data_streams_url = rtrim($hub_base, '/') . '/wp-json/vl-hub/v1/data-streams?license=' . urlencode($license);
      
      $response = wp_remote_get($data_streams_url, array(
        'timeout' => 30,
        'sslverify' => true,
        'headers' => array(
          'X-Luna-License' => $license,
          'X-Luna-Site' => home_url('/'),
          'Accept' => 'application/json',
        ),
      ));
      
      if (!is_wp_error($response)) {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
          $data_streams = json_decode($response_body, true);
          if (json_last_error() === JSON_ERROR_NONE && is_array($data_streams)) {
            // Check various possible structures in data-streams
            $roots = array();
            
            if (isset($data_streams['data']) && is_array($data_streams['data'])) {
              $roots[] = $data_streams['data'];
              if (isset($data_streams['data']['client_streams']) && is_array($data_streams['data']['client_streams'])) {
                $roots[] = $data_streams['data']['client_streams'];
              }
            }
            $roots[] = $data_streams; // Also check top level
            
            foreach ($roots as $root) {
              if (!is_array($root)) continue;
              
              foreach ($root as $key => $item) {
                if (!is_array($item)) continue;
                
                $is_wordpress = false;
                
                if (strpos((string)$key, 'wordpress') !== false || strpos((string)$key, 'wordpress_data') !== false) {
                  $is_wordpress = true;
                } elseif (isset($item['wp_version']) || isset($item['wp_core_data'])) {
                  $is_wordpress = true;
                } elseif (isset($item['name']) && stripos($item['name'], 'WordPress') !== false) {
                  $is_wordpress = true;
                } elseif (isset($item['posts_data']) || isset($item['pages_data'])) {
                  $is_wordpress = true;
                } elseif (isset($item['posts_total']) || isset($item['pages_total'])) {
                  $is_wordpress = true;
                }
                
                if ($is_wordpress) {
                  // If we already have wordpress_data but it lacks detailed data, merge in the detailed data
                  if ($wordpress_data && is_array($wordpress_data)) {
                    // Merge detailed data into existing wordpress_data
                    if (isset($item['posts_data']) && is_array($item['posts_data']) && !empty($item['posts_data'])) {
                      $wordpress_data['posts_data'] = $item['posts_data'];
                      error_log('[Luna Widget] Merged detailed posts_data from data-streams (' . count($item['posts_data']) . ' posts)');
                    }
                    if (isset($item['pages_data']) && is_array($item['pages_data']) && !empty($item['pages_data'])) {
                      $wordpress_data['pages_data'] = $item['pages_data'];
                      error_log('[Luna Widget] Merged detailed pages_data from data-streams (' . count($item['pages_data']) . ' pages)');
                    }
                    // Merge any other detailed fields
                    foreach ($item as $detail_key => $detail_value) {
                      if (!isset($wordpress_data[$detail_key]) || empty($wordpress_data[$detail_key])) {
                        $wordpress_data[$detail_key] = $detail_value;
                      }
                    }
                  } else {
                    // No existing wordpress_data, use the one from data-streams
                    $wordpress_data = $item;
                  }
                  error_log('[Luna Widget] WordPress data extracted/enhanced from data-streams. Has posts_data: ' . (isset($wordpress_data['posts_data']) ? 'yes (' . (is_array($wordpress_data['posts_data']) ? count($wordpress_data['posts_data']) : 'not array') . ' posts)' : 'no'));
                  break 2;
                }
              }
            }
          }
        }
      }
    }
  }
  
  if (!$wordpress_data) {
    error_log('[Luna Widget] WARNING: No WordPress data found in either all-connections or data-streams after checking all locations');
  } else {
    // Log full structure to verify we have all data
    error_log('[Luna Widget] WordPress data keys: ' . implode(', ', array_keys($wordpress_data)));
    if (isset($wordpress_data['posts_data'])) {
      error_log('[Luna Widget] posts_data is array: ' . (is_array($wordpress_data['posts_data']) ? 'yes' : 'no'));
      if (is_array($wordpress_data['posts_data']) && !empty($wordpress_data['posts_data'])) {
        $first_post = $wordpress_data['posts_data'][0];
        error_log('[Luna Widget] First post keys: ' . (is_array($first_post) ? implode(', ', array_keys($first_post)) : 'not array'));
        if (is_array($first_post) && isset($first_post['title'])) {
          error_log('[Luna Widget] First post title: ' . $first_post['title']);
        }
      }
    }
  }
  
  // If WordPress data found, extract posts, pages, and other info
  if ($wordpress_data && is_array($wordpress_data)) {
    // Extract WordPress counts
    if (isset($wordpress_data['posts_total'])) {
      $facts['counts']['posts'] = (int)$wordpress_data['posts_total'];
    }
    if (isset($wordpress_data['pages_total'])) {
      $facts['counts']['pages'] = (int)$wordpress_data['pages_total'];
    }
    if (isset($wordpress_data['users_total'])) {
      $facts['counts']['users'] = (int)$wordpress_data['users_total'];
    }
    if (isset($wordpress_data['plugins_total'])) {
      $facts['counts']['plugins'] = (int)$wordpress_data['plugins_total'];
    }
    
    // Store full WordPress data for use in facts_text
    $facts['wordpress_data'] = $wordpress_data;
  }
  
  // Add extracted streams, zones, servers, connections
  $facts['streams'] = $profile_data['streams'];
  $facts['zones'] = $profile_data['zones'];
  $facts['servers'] = $profile_data['servers'];
  $facts['installs'] = $profile_data['installs'];
  $facts['connections'] = $profile_data['connections'];
  
  // Extract SSL/TLS data
  foreach ($profile_data['connections'] as $conn) {
    if ($conn['type'] === 'SSL/TLS') {
      $facts['ssl_tls'] = array(
        'certificate' => $conn['name'],
        'status' => $conn['status'],
      );
      break;
    }
  }
  
  // Extract Cloudflare data
  if (!empty($profile_data['zones'])) {
    $facts['cloudflare'] = array(
      'zones' => $profile_data['zones'],
      'connected' => true,
    );
  }
  
  return $facts;
}

/**
 * Get basic facts from local WordPress (fallback)
 */
function luna_widget_get_basic_facts() {
  return array(
    'site_url' => home_url('/'),
    'https' => is_ssl(),
    'wp_version' => get_bloginfo('version'),
    'theme' => '',
    'theme_version' => '',
    'theme_active' => true,
    'counts' => array(
      'pages' => 0,
      'posts' => 0,
      'users' => 0,
      'plugins' => 0,
    ),
    'updates' => array(
      'plugins' => 0,
      'themes' => 0,
      'core' => 0,
    ),
    'comprehensive' => false,
  );
}

/* ============================================================
 * CONVERSATION MANAGEMENT
 * ============================================================ */
// Register custom post type for conversations
add_action('init', function() {
  register_post_type('luna_widget_convo', array(
    'public' => false,
    'show_ui' => false,
    'supports' => array('title'),
  ));
  
  register_post_type('luna_compose', array(
    'public' => false,
    'show_ui' => false,
    'supports' => array('title'),
  ));
});

function luna_widget_create_conversation_post($cid) {
  // First, check for existing conversation with this CID (most recent first)
  $existing = get_posts(array(
    'post_type'   => 'luna_widget_convo',
    'meta_key'    => 'luna_cid',
    'meta_value'  => $cid,
    'fields'      => 'ids',
    'numberposts' => 1,
    'post_status' => 'any',
    'orderby'     => 'date',
    'order'       => 'DESC',
  ));
  if ($existing && !empty($existing[0])) {
    // Return existing conversation - do not create duplicate
    return (int)$existing[0];
  }
  
  // Only create new conversation if none exists with this CID
  $pid = wp_insert_post(array(
    'post_type'   => 'luna_widget_convo',
    'post_title'  => 'Conversation ' . substr($cid, 0, 8),
    'post_status' => 'publish',
  ));
  if ($pid && !is_wp_error($pid)) {
    update_post_meta($pid, 'luna_cid', $cid);
    $existing_transcript = get_post_meta($pid, 'transcript', true);
    if (!is_array($existing_transcript)) {
      update_post_meta($pid, 'transcript', array());
    }
    return (int)$pid;
  }
  return 0;
}

function luna_widget_current_conversation_id() {
  $cookie_key = 'luna_widget_cid';
  if (empty($_COOKIE[$cookie_key])) {
    return 0;
  }
  $cid = sanitize_text_field(wp_unslash($_COOKIE[$cookie_key]));
  if ($cid === '' || !preg_match('/^lwc_/', $cid)) {
    return 0;
  }
  $existing = get_posts(array(
    'post_type'   => 'luna_widget_convo',
    'meta_key'    => 'luna_cid',
    'meta_value'  => $cid,
    'fields'      => 'ids',
    'numberposts' => 1,
    'post_status' => 'any',
    'orderby'     => 'date',
    'order'       => 'DESC',
  ));
  if ($existing && !empty($existing[0])) {
    return (int)$existing[0];
  }
  return 0;
}

function luna_conv_id($force_new = false) {
  $cookie_key = 'luna_widget_cid';
  $cid = isset($_COOKIE[$cookie_key]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_key])) : '';

  if (!$force_new) {
    if ($cid !== '' && preg_match('/^lwc_/', $cid)) {
      $pid = luna_widget_current_conversation_id();
      if ($pid) {
        @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
        $_COOKIE[$cookie_key] = $cid;
        return $pid;
      }
      @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
      $_COOKIE[$cookie_key] = $cid;
      return luna_widget_create_conversation_post($cid);
    }
  }

  $cid = 'lwc_' . uniqid('', true);
  @setcookie($cookie_key, $cid, time() + (86400 * 30), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true);
  $_COOKIE[$cookie_key] = $cid;
  return luna_widget_create_conversation_post($cid);
}

function luna_widget_close_conversation($pid, $reason = '') {
  if (!$pid) return;
  update_post_meta($pid, 'session_closed', time());
  if ($reason !== '') {
    update_post_meta($pid, 'session_closed_reason', sanitize_text_field($reason));
  }
}

function luna_log_turn($user, $assistant, $meta = array()) {
  $pid = luna_conv_id(); 
  if (!$pid) {
    error_log('[Luna Widget] luna_log_turn: No conversation ID available');
    return;
  }
  
  $t = get_post_meta($pid, 'transcript', true);
  if (!is_array($t)) {
    $t = array();
  }
  
  if (trim($user) !== '' || trim($assistant) !== '') {
    $t[] = array(
      'ts' => time(),
      'user' => trim((string)$user),
      'assistant' => trim((string)$assistant),
      'meta' => $meta
    );
    update_post_meta($pid, 'transcript', $t);
  }
}

/* ============================================================
 * COMPOSER DEFAULT PROMPTS
 * ============================================================ */
function luna_composer_default_prompts() {
  $defaults = array(
    array(
      'label'  => 'What can Luna help me with?',
      'prompt' => "Hey Luna! What can you help me with today?",
    ),
    array(
      'label'  => 'Site health overview',
      'prompt' => 'Can you give me a quick health check of my WordPress site?',
    ),
    array(
      'label'  => 'Pending updates',
      'prompt' => 'Do I have any plugin, theme, or WordPress core updates waiting?',
    ),
    array(
      'label'  => 'Security status',
      'prompt' => 'Is my SSL certificate active and are there any security concerns?',
    ),
    array(
      'label'  => 'Content inventory',
      'prompt' => 'How many pages and posts are on the site right now?',
    ),
    array(
      'label'  => 'Help contact info',
      'prompt' => 'Remind me how someone can contact our team for help.',
    ),
  );

  return apply_filters('luna_composer_default_prompts', $defaults);
}

/* ============================================================
 * OPENAI INTEGRATION
 * ============================================================ */
/**
 * Build OpenAI messages with facts using our new extraction method
 */
function luna_openai_messages_with_facts($pid, $user_text, $facts, $is_comprehensive_report = false, $is_composer = false) {
  $site_url = isset($facts['site_url']) ? (string)$facts['site_url'] : home_url('/');
  $https = isset($facts['https']) ? ($facts['https'] ? 'yes' : 'no') : 'unknown';
  $wpv = isset($facts['wp_version']) && $facts['wp_version'] !== '' ? (string)$facts['wp_version'] : 'unknown';

  // Build facts text using our extracted profile data
  $facts_text = "=== COMPREHENSIVE FACTS FROM VISIBLE LIGHT HUB ===\n";
  $facts_text .= "CRITICAL: Data below is the client's real WordPress + digital infrastructure. Use ONLY these facts—never invent data. Match exact titles, authors, counts, metrics, URLs.\n";
  $facts_text .= "Summaries below are condensed for token efficiency; if items are truncated, note that remaining items exist.\n\n";

  // Hard caps to avoid runaway token counts
  $max_posts = 15;
  $max_pages = 15;
  $max_comments = 10;
  $max_raw_chars = 2000; // tighter raw JSON inclusion
  
  // Add information about data sources if available
  if (isset($facts['profile_data']['_merged_sources'])) {
    $sources = $facts['profile_data']['_merged_sources'];
    $facts_text .= "DATA SOURCES:\n";
    $facts_text .= "- Data has been merged from multiple VL Hub API endpoints for comprehensive coverage:\n";
    if (!empty($sources['all_connections'])) {
      $facts_text .= "  ✓ all-connections endpoint: Provides connection data, streams, zones, servers, installs\n";
    }
    if (!empty($sources['data_streams'])) {
      $facts_text .= "  ✓ data-streams endpoint: Provides detailed data stream information and metadata\n";
    }
    if (!empty($sources['merged_at'])) {
      $facts_text .= "- Data merged at: " . esc_html($sources['merged_at']) . "\n";
    }
    $facts_text .= "- You can cross-reference and cross-check data between these sources for accuracy.\n";
    $facts_text .= "- If the same stream or connection appears in both sources, it has been cross-validated.\n\n";
  }
  $facts_text .= "BASIC SITE INFORMATION:\n"
    . "- Site URL: " . $site_url . "\n"
    . "- HTTPS: " . $https . "\n"
    . "- WordPress: " . $wpv . "\n";
  
  // Add SSL/TLS data from extracted connections
  if (isset($facts['ssl_tls']) && is_array($facts['ssl_tls'])) {
    $ssl = $facts['ssl_tls'];
    $facts_text .= "- TLS/SSL Certificate: " . (isset($ssl['certificate']) ? $ssl['certificate'] : 'N/A');
    if (isset($ssl['status']) && $ssl['status'] === 'active') {
      $facts_text .= " (ACTIVE AND VALID)";
    }
    $facts_text .= "\n";
  }
  
  // Add Cloudflare data from extracted zones
  if (isset($facts['cloudflare']) && is_array($facts['cloudflare'])) {
    $cf = $facts['cloudflare'];
    if (isset($cf['zones']) && is_array($cf['zones']) && !empty($cf['zones'])) {
      $facts_text .= "- Cloudflare: CONFIGURED AND ACTIVE (" . count($cf['zones']) . " zone(s))\n";
      $facts_text .= "  * DDoS Protection: Enabled\n";
      $facts_text .= "  * Web Application Firewall (WAF): Enabled\n";
      $facts_text .= "  * CDN: Enabled\n";
    }
  }
  
  // Add data streams summary
  if (isset($facts['streams']) && is_array($facts['streams']) && !empty($facts['streams'])) {
    $facts_text .= "\nDATA STREAMS (" . count($facts['streams']) . "):\n";
    foreach ($facts['streams'] as $stream) {
      $facts_text .= "- " . $stream['name'] . " (" . ucfirst($stream['status']) . ")";
      if (isset($stream['health_score']) && $stream['health_score'] > 0) {
        $facts_text .= " - Health: " . number_format($stream['health_score'], 1) . "%";
      }
      if (isset($stream['categories']) && is_array($stream['categories'])) {
        $luna_intel = in_array('luna-intel', $stream['categories']);
        if ($luna_intel) {
          $facts_text .= " - Category: Luna Intel";
        }
      }
      $facts_text .= "\n";
      
      // If this is the Training Data stream, include detailed information
      if (isset($stream['id']) && $stream['id'] === 'training_data' && isset($stream['training_items']) && is_array($stream['training_items'])) {
        $facts_text .= "  Training Data Stream Details (Luna Intel):\n";
        $facts_text .= "  - Total Training Items: " . count($stream['training_items']) . "\n";
        if (isset($stream['luna_consumed']) && $stream['luna_consumed']) {
          $facts_text .= "  - Status: Luna has consumed and is using this training data\n";
        }
        foreach ($stream['training_items'] as $train_index => $train_item) {
          $facts_text .= "  - Training Item #" . ($train_index + 1) . ":\n";
          if (isset($train_item['industry_label'])) {
            $facts_text .= "    Industry: " . esc_html($train_item['industry_label']) . "\n";
          }
          if (isset($train_item['company_description'])) {
            $facts_text .= "    Company Description: " . esc_html(wp_trim_words($train_item['company_description'], 30)) . "\n";
          }
          if (isset($train_item['mission']) && !empty($train_item['mission'])) {
            $facts_text .= "    Mission: " . esc_html($train_item['mission']) . "\n";
          }
          if (isset($train_item['vision']) && !empty($train_item['vision'])) {
            $facts_text .= "    Vision: " . esc_html($train_item['vision']) . "\n";
          }
          if (isset($train_item['luna_test_response'])) {
            $facts_text .= "    Luna Test Response (Confirmation): " . esc_html(wp_trim_words($train_item['luna_test_response'], 100)) . "\n";
            if (isset($train_item['luna_test_response_date'])) {
              $facts_text .= "    Luna Consumed Date: " . esc_html($train_item['luna_test_response_date']) . "\n";
            }
          }
        }
      }
    }
  }
  
  // Add connections summary
  if (isset($facts['connections']) && is_array($facts['connections']) && !empty($facts['connections'])) {
    $facts_text .= "\nCONNECTIONS:\n";
    foreach ($facts['connections'] as $conn) {
      $facts_text .= "- " . $conn['type'] . ": " . $conn['name'] . " (" . ucfirst($conn['status']) . ")\n";
    }
  }
  
  // Add WordPress content data (posts, pages, etc.)
  if (isset($facts['wordpress_data']) && is_array($facts['wordpress_data'])) {
    $wp_data = $facts['wordpress_data'];
    
    // Debug: Log what we have
    error_log('[Luna Widget] Building facts_text with WordPress data. Has posts_data: ' . (isset($wp_data['posts_data']) ? 'yes (' . (is_array($wp_data['posts_data']) ? count($wp_data['posts_data']) : 'not array') . ' posts)' : 'no'));
    
    $facts_text .= "\nWORDPRESS CONTENT DATA:\n";
    
    // WordPress version and core info
    if (isset($wp_data['wp_version'])) {
      $facts_text .= "- WordPress Version: " . esc_html($wp_data['wp_version']) . "\n";
    }
    if (isset($wp_data['php_version'])) {
      $facts_text .= "- PHP Version: " . esc_html($wp_data['php_version']) . "\n";
    }
    
    // Content counts
    if (isset($wp_data['posts_total'])) {
      $facts_text .= "- Total Published Posts: " . number_format((int)$wp_data['posts_total']) . "\n";
    }
    if (isset($wp_data['pages_total'])) {
      $facts_text .= "- Total Published Pages: " . number_format((int)$wp_data['pages_total']) . "\n";
    }
    if (isset($wp_data['users_total'])) {
      $facts_text .= "- Total Users: " . number_format((int)$wp_data['users_total']) . "\n";
    }
    if (isset($wp_data['plugins_total'])) {
      $facts_text .= "- Total Plugins: " . number_format((int)$wp_data['plugins_total']) . "\n";
    }
    if (isset($wp_data['themes_total'])) {
      $facts_text .= "- Total Themes: " . number_format((int)$wp_data['themes_total']) . "\n";
    }
    if (isset($wp_data['comments_total'])) {
      $facts_text .= "- Total Comments: " . number_format((int)$wp_data['comments_total']) . "\n";
    }
    
    // Published Posts List
    if (isset($wp_data['posts_data']) && is_array($wp_data['posts_data']) && !empty($wp_data['posts_data'])) {
      $posts_count = count($wp_data['posts_data']);
      error_log('[Luna Widget] Found ' . $posts_count . ' posts in posts_data');
      $facts_text .= "\nPUBLISHED POSTS (" . $posts_count . " total, showing up to " . $max_posts . "):\n";
      $post_num = 1;
      foreach ($wp_data['posts_data'] as $post) {
        if (!is_array($post)) {
          error_log('[Luna Widget] Post #' . $post_num . ' is not an array');
          continue;
        }
        
        $title = isset($post['title']) ? esc_html($post['title']) : 'Untitled';
        $published = isset($post['date_published']) ? date('M j, Y', strtotime($post['date_published'])) : 'N/A';
        $word_count = isset($post['word_count']) ? number_format((int)$post['word_count']) : '0';
        $categories = isset($post['categories']) && is_array($post['categories']) ? implode(', ', $post['categories']) : 'None';
        $tags = isset($post['tags']) && is_array($post['tags']) ? implode(', ', $post['tags']) : 'None';
        $author = isset($post['author']['name']) ? esc_html($post['author']['name']) : 'Unknown';
        $comments_count = isset($post['engagement']['comments']) ? number_format((int)$post['engagement']['comments']) : '0';
        $views_count = isset($post['engagement']['views']) ? number_format((int)$post['engagement']['views']) : '0';
        $url = isset($post['url']) ? esc_url($post['url']) : '';
        
        error_log('[Luna Widget] Adding post #' . $post_num . ' to facts_text: ' . $title);
        
        $facts_text .= "\nPOST #" . $post_num . ":\n";
        $facts_text .= "  POST TITLE: " . $title . "\n";
        $facts_text .= "  POST ID: " . (isset($post['id']) ? (int)$post['id'] : 'N/A') . "\n";
        $facts_text .= "  POST SLUG: " . (isset($post['slug']) ? esc_html($post['slug']) : 'N/A') . "\n";
        if (isset($post['excerpt']) && !empty($post['excerpt'])) {
          $excerpt = wp_strip_all_tags($post['excerpt']);
          $facts_text .= "  POST EXCERPT: " . esc_html(substr($excerpt, 0, 200)) . "\n";
        }
        $facts_text .= "  Author: " . $author . "\n";
        $facts_text .= "  Published Date: " . $published . "\n";
        $facts_text .= "  Last Updated: " . (isset($post['last_updated']) ? date('M j, Y', strtotime($post['last_updated'])) : 'N/A') . "\n";
        $facts_text .= "  Word Count: " . $word_count . " words\n";
        if ($categories !== 'None') {
          $facts_text .= "  Categories: " . esc_html($categories) . "\n";
        }
        if ($tags !== 'None') {
          $facts_text .= "  Tags: " . esc_html($tags) . "\n";
        }
        $facts_text .= "  Comments: " . $comments_count . "\n";
        $facts_text .= "  Views: " . $views_count . "\n";
        if ($url) {
          $facts_text .= "  URL: " . $url . "\n";
        }
        $post_num++;
        if ($post_num > $max_posts) {
          $facts_text .= "... (" . ($posts_count - $max_posts) . " more posts not shown to conserve tokens)\n";
          break;
        }
      }
      $facts_text .= "\n";
    } else {
      error_log('[Luna Widget] No posts_data found or empty. wp_data keys: ' . (is_array($wp_data) ? implode(', ', array_keys($wp_data)) : 'not array'));
      if (isset($wp_data['posts_total']) && (int)$wp_data['posts_total'] > 0) {
        error_log('[Luna Widget] WARNING: posts_total is ' . $wp_data['posts_total'] . ' but posts_data is missing or empty!');
      }
    }
    
    // Published Pages List
    if (isset($wp_data['pages_data']) && is_array($wp_data['pages_data']) && !empty($wp_data['pages_data'])) {
      $pages_count = count($wp_data['pages_data']);
      $facts_text .= "\nPUBLISHED PAGES (" . $pages_count . " total, showing up to " . $max_pages . "):\n";
      $page_num = 1;
      foreach ($wp_data['pages_data'] as $page) {
        if (!is_array($page)) continue;
        $title = isset($page['title']) ? esc_html($page['title']) : 'Untitled';
        $published = isset($page['date_published']) ? date('M j, Y', strtotime($page['date_published'])) : 'N/A';
        $word_count = isset($page['word_count']) ? number_format((int)$page['word_count']) : '0';
        $author = isset($page['author']['name']) ? esc_html($page['author']['name']) : 'Unknown';
        $url = isset($page['url']) ? esc_url($page['url']) : '';
        
        $facts_text .= "\nPAGE #" . $page_num . ":\n";
        $facts_text .= "  PAGE TITLE: " . $title . "\n";
        $facts_text .= "  PAGE ID: " . (isset($page['id']) ? (int)$page['id'] : 'N/A') . "\n";
        $facts_text .= "  PAGE SLUG: " . (isset($page['slug']) ? esc_html($page['slug']) : 'N/A') . "\n";
        if (isset($page['excerpt']) && !empty($page['excerpt'])) {
          $excerpt = wp_strip_all_tags($page['excerpt']);
          $facts_text .= "  PAGE EXCERPT: " . esc_html(substr($excerpt, 0, 200)) . "\n";
        }
        $facts_text .= "  Author: " . $author . "\n";
        $facts_text .= "  Published Date: " . $published . "\n";
        $facts_text .= "  Last Updated: " . (isset($page['last_updated']) ? date('M j, Y', strtotime($page['last_updated'])) : 'N/A') . "\n";
        $facts_text .= "  Word Count: " . $word_count . " words\n";
        if (isset($page['parent']) && $page['parent'] > 0) {
          $facts_text .= "  Parent Page ID: " . (int)$page['parent'] . "\n";
        }
        if ($url) {
          $facts_text .= "  URL: " . $url . "\n";
        }
        $page_num++;
        if ($page_num > $max_pages) {
          $facts_text .= "... (" . ($pages_count - $max_pages) . " more pages not shown to conserve tokens)\n";
          break;
        }
      }
      $facts_text .= "\n";
    }
    
    // Content Metrics
    if (isset($wp_data['content_metrics']) && is_array($wp_data['content_metrics'])) {
      $metrics = $wp_data['content_metrics'];
      $facts_text .= "\nCONTENT METRICS:\n";
      if (isset($metrics['total_word_count'])) {
        $facts_text .= "- Total Word Count: " . number_format((int)$metrics['total_word_count']) . "\n";
      }
      if (isset($metrics['average_word_count_per_post'])) {
        $facts_text .= "- Average Words Per Post: " . number_format((int)$metrics['average_word_count_per_post']) . "\n";
      }
      if (isset($metrics['top_keywords']) && is_array($metrics['top_keywords']) && !empty($metrics['top_keywords'])) {
        $facts_text .= "- Top Keywords: ";
        $keyword_list = array();
        $count = 0;
        foreach ($metrics['top_keywords'] as $keyword => $usage_count) {
          if ($count >= 10) break; // Limit to top 10
          $keyword_list[] = $keyword . " (" . number_format((int)$usage_count) . ")";
          $count++;
        }
        $facts_text .= implode(", ", $keyword_list) . "\n";
      }
    }
    
    // WordPress Core Data
    if (isset($wp_data['wp_core_data']) && is_array($wp_data['wp_core_data'])) {
      $core = $wp_data['wp_core_data'];
      $facts_text .= "\nWORDPRESS CORE DATA:\n";
      if (isset($core['version'])) {
        $facts_text .= "- WordPress Version: " . esc_html($core['version']) . "\n";
      }
      if (isset($core['update_available'])) {
        $facts_text .= "- Update Available: " . ($core['update_available'] ? 'Yes' : 'No') . "\n";
        if ($core['update_available'] && isset($core['latest_version'])) {
          $facts_text .= "- Latest Version: " . esc_html($core['latest_version']) . "\n";
        }
      }
      if (isset($core['php_version'])) {
        $facts_text .= "- PHP Version: " . esc_html($core['php_version']) . "\n";
      }
      if (isset($core['mysql_version'])) {
        $facts_text .= "- MySQL Version: " . esc_html($core['mysql_version']) . "\n";
      }
      if (isset($core['memory_limit'])) {
        $facts_text .= "- Memory Limit: " . esc_html($core['memory_limit']) . "\n";
      }
      if (isset($core['is_multisite'])) {
        $facts_text .= "- Multisite: " . ($core['is_multisite'] ? 'Yes' : 'No') . "\n";
      }
    }
    
    // Plugins Data
    if (isset($wp_data['plugins_data']) && is_array($wp_data['plugins_data']) && !empty($wp_data['plugins_data'])) {
      $plugins_needing_update_count = isset($wp_data['plugins_needing_update']) ? (int)$wp_data['plugins_needing_update'] : 0;
      
      $facts_text .= "\nPLUGINS (" . count($wp_data['plugins_data']) . " total";
      if (isset($wp_data['plugins_active'])) {
        $facts_text .= ", " . (int)$wp_data['plugins_active'] . " active";
      }
      if ($plugins_needing_update_count > 0) {
        $facts_text .= ", ⚠️ " . $plugins_needing_update_count . " NEED UPDATE";
      }
      $facts_text .= "):\n";
      
      // Add prominent update summary at the top
      if ($plugins_needing_update_count > 0) {
        $facts_text .= "\n⚠️ UPDATE ALERT: " . $plugins_needing_update_count . " plugin(s) have updates available:\n";
        foreach ($wp_data['plugins_data'] as $plugin) {
          if (!is_array($plugin)) continue;
          $needs_update = isset($plugin['needs_update']) && $plugin['needs_update'];
          if ($needs_update) {
            $name = isset($plugin['name']) ? esc_html($plugin['name']) : 'Unknown';
            $version = isset($plugin['version']) ? esc_html($plugin['version']) : 'N/A';
            $update_version = isset($plugin['update_version']) ? esc_html($plugin['update_version']) : null;
            $facts_text .= "  - " . $name . ": " . $version . " → " . ($update_version ?: 'new version available') . "\n";
          }
        }
        $facts_text .= "\n";
      }
      
      foreach ($wp_data['plugins_data'] as $plugin) {
        if (!is_array($plugin)) continue;
        $name = isset($plugin['name']) ? esc_html($plugin['name']) : 'Unknown';
        $version = isset($plugin['version']) ? esc_html($plugin['version']) : 'N/A';
        $status = isset($plugin['status']) ? esc_html($plugin['status']) : 'unknown';
        $author = isset($plugin['author']) ? esc_html($plugin['author']) : 'Unknown';
        $needs_update = isset($plugin['needs_update']) && $plugin['needs_update'];
        $update_version = isset($plugin['update_version']) ? esc_html($plugin['update_version']) : null;
        $description = isset($plugin['description']) ? esc_html($plugin['description']) : '';
        $activation_date = isset($plugin['activation_date']) ? esc_html($plugin['activation_date']) : null;
        $last_modified = isset($plugin['last_modified']) ? esc_html($plugin['last_modified']) : null;
        
        $facts_text .= "\nPLUGIN: " . $name;
        if ($needs_update) {
          $facts_text .= " ⚠️ UPDATE AVAILABLE";
        }
        $facts_text .= "\n";
        // Include ALL plugin fields
        if (isset($plugin['file'])) $facts_text .= "  File: " . esc_html($plugin['file']) . "\n";
        $facts_text .= "  Status: " . ucfirst($status) . "\n";
        $facts_text .= "  Version: " . $version;
        if ($needs_update && $update_version) {
          $facts_text .= " → UPDATE TO: " . $update_version;
        }
        $facts_text .= "\n";
        $facts_text .= "  Author: " . $author . "\n";
        if (isset($plugin['author_uri']) && !empty($plugin['author_uri'])) {
          $facts_text .= "  Author URI: " . esc_url($plugin['author_uri']) . "\n";
        }
        if (isset($plugin['plugin_uri']) && !empty($plugin['plugin_uri'])) {
          $facts_text .= "  Plugin URI: " . esc_url($plugin['plugin_uri']) . "\n";
        }
        if (isset($plugin['text_domain']) && !empty($plugin['text_domain'])) {
          $facts_text .= "  Text Domain: " . esc_html($plugin['text_domain']) . "\n";
        }
        if (isset($plugin['domain_path']) && !empty($plugin['domain_path'])) {
          $facts_text .= "  Domain Path: " . esc_html($plugin['domain_path']) . "\n";
        }
        if (isset($plugin['network']) !== null) {
          $facts_text .= "  Network: " . ($plugin['network'] ? 'Yes' : 'No') . "\n";
        }
        if (isset($plugin['requires_wp']) && !empty($plugin['requires_wp'])) {
          $facts_text .= "  Requires WP: " . esc_html($plugin['requires_wp']) . "\n";
        }
        if (isset($plugin['tested_wp']) && !empty($plugin['tested_wp'])) {
          $facts_text .= "  Tested WP: " . esc_html($plugin['tested_wp']) . "\n";
        }
        if (isset($plugin['requires_php']) && !empty($plugin['requires_php'])) {
          $facts_text .= "  Requires PHP: " . esc_html($plugin['requires_php']) . "\n";
        }
        if ($description) {
          $facts_text .= "  Description: " . $description . "\n";
        }
        if ($activation_date) {
          $facts_text .= "  Activation Date: " . $activation_date . "\n";
        }
        if ($last_modified) {
          $facts_text .= "  Last Modified: " . $last_modified . "\n";
        }
      }
      $facts_text .= "\n";
    }
    
    // Themes Data
    if (isset($wp_data['themes_data']) && is_array($wp_data['themes_data']) && !empty($wp_data['themes_data'])) {
      $themes_needing_update_count = isset($wp_data['themes_needing_update']) ? (int)$wp_data['themes_needing_update'] : 0;
      
      $facts_text .= "\nTHEMES (" . count($wp_data['themes_data']) . " total";
      if ($themes_needing_update_count > 0) {
        $facts_text .= ", ⚠️ " . $themes_needing_update_count . " NEED UPDATE";
      }
      $facts_text .= "):\n";
      
      // Add prominent update summary at the top
      if ($themes_needing_update_count > 0) {
        $facts_text .= "\n⚠️ UPDATE ALERT: " . $themes_needing_update_count . " theme(s) have updates available:\n";
        foreach ($wp_data['themes_data'] as $theme) {
          if (!is_array($theme)) continue;
          $needs_update = isset($theme['needs_update']) && $theme['needs_update'];
          if ($needs_update) {
            $name = isset($theme['name']) ? esc_html($theme['name']) : 'Unknown';
            $version = isset($theme['version']) ? esc_html($theme['version']) : 'N/A';
            $update_version = isset($theme['update_version']) ? esc_html($theme['update_version']) : null;
            $facts_text .= "  - " . $name . ": " . $version . " → " . ($update_version ?: 'new version available') . "\n";
          }
        }
        $facts_text .= "\n";
      }
      
      foreach ($wp_data['themes_data'] as $theme) {
        if (!is_array($theme)) continue;
        $name = isset($theme['name']) ? esc_html($theme['name']) : 'Unknown';
        $version = isset($theme['version']) ? esc_html($theme['version']) : 'N/A';
        $status = isset($theme['status']) ? esc_html($theme['status']) : 'unknown';
        $author = isset($theme['author']) ? esc_html($theme['author']) : 'Unknown';
        $needs_update = isset($theme['needs_update']) && $theme['needs_update'];
        $update_version = isset($theme['update_version']) ? esc_html($theme['update_version']) : null;
        $description = isset($theme['description']) ? esc_html($theme['description']) : '';
        $last_modified = isset($theme['last_modified']) ? esc_html($theme['last_modified']) : null;
        
        $facts_text .= "\nTHEME: " . $name;
        if ($status === 'active') {
          $facts_text .= " (ACTIVE)";
        }
        if ($needs_update) {
          $facts_text .= " ⚠️ UPDATE AVAILABLE";
        }
        $facts_text .= "\n";
        // Include ALL theme fields
        if (isset($theme['slug'])) $facts_text .= "  Slug: " . esc_html($theme['slug']) . "\n";
        $facts_text .= "  Status: " . ucfirst($status) . "\n";
        $facts_text .= "  Version: " . $version;
        if ($needs_update && $update_version) {
          $facts_text .= " → UPDATE TO: " . $update_version;
        }
        $facts_text .= "\n";
        $facts_text .= "  Author: " . $author . "\n";
        if (isset($theme['author_uri']) && !empty($theme['author_uri'])) {
          $facts_text .= "  Author URI: " . esc_url($theme['author_uri']) . "\n";
        }
        if (isset($theme['theme_uri']) && !empty($theme['theme_uri'])) {
          $facts_text .= "  Theme URI: " . esc_url($theme['theme_uri']) . "\n";
        }
        if (isset($theme['text_domain']) && !empty($theme['text_domain'])) {
          $facts_text .= "  Text Domain: " . esc_html($theme['text_domain']) . "\n";
        }
        if (isset($theme['domain_path']) && !empty($theme['domain_path'])) {
          $facts_text .= "  Domain Path: " . esc_html($theme['domain_path']) . "\n";
        }
        if (isset($theme['requires_wp']) && !empty($theme['requires_wp'])) {
          $facts_text .= "  Requires WP: " . esc_html($theme['requires_wp']) . "\n";
        }
        if (isset($theme['tested_wp']) !== null) {
          $facts_text .= "  Tested WP: " . ($theme['tested_wp'] ? 'Yes' : 'No') . "\n";
        }
        if (isset($theme['requires_php']) && !empty($theme['requires_php'])) {
          $facts_text .= "  Requires PHP: " . esc_html($theme['requires_php']) . "\n";
        }
        if (isset($theme['template']) && !empty($theme['template'])) {
          $facts_text .= "  Template: " . esc_html($theme['template']) . "\n";
        }
        if (isset($theme['parent']) && !empty($theme['parent'])) {
          $facts_text .= "  Parent: " . esc_html($theme['parent']) . "\n";
        }
        if ($description) {
          $facts_text .= "  Description: " . esc_html(wp_trim_words($description, 50)) . "\n";
        }
        if ($last_modified) {
          $facts_text .= "  Last Modified: " . $last_modified . "\n";
        }
      }
      $facts_text .= "\n";
    }
    
    // Users Data
    if (isset($wp_data['users_data']) && is_array($wp_data['users_data']) && !empty($wp_data['users_data'])) {
      $facts_text .= "\nUSERS (" . count($wp_data['users_data']) . " total):\n";
      
      foreach ($wp_data['users_data'] as $user) {
        if (!is_array($user)) continue;
        $display_name = isset($user['display_name']) ? esc_html($user['display_name']) : 'Unknown';
        $login = isset($user['login']) ? esc_html($user['login']) : 'N/A';
        $email = isset($user['email']) ? esc_html($user['email']) : 'N/A';
        $roles = isset($user['roles']) && is_array($user['roles']) ? implode(', ', array_map('esc_html', $user['roles'])) : 'N/A';
        $registered = isset($user['registered']) ? date('M j, Y', strtotime($user['registered'])) : 'N/A';
        $post_count = isset($user['post_count']) ? (int)$user['post_count'] : 0;
        
        $facts_text .= "\nUSER: " . $display_name . "\n";
        // Include ALL user fields
        if (isset($user['id'])) $facts_text .= "  ID: " . (int)$user['id'] . "\n";
        $facts_text .= "  Login: " . $login . "\n";
        $facts_text .= "  Email: " . $email . "\n";
        if (isset($user['display_name'])) $facts_text .= "  Display Name: " . esc_html($user['display_name']) . "\n";
        if (isset($user['first_name']) && !empty($user['first_name'])) {
          $facts_text .= "  First Name: " . esc_html($user['first_name']) . "\n";
        }
        if (isset($user['last_name']) && !empty($user['last_name'])) {
          $facts_text .= "  Last Name: " . esc_html($user['last_name']) . "\n";
        }
        if (isset($user['nickname']) && !empty($user['nickname'])) {
          $facts_text .= "  Nickname: " . esc_html($user['nickname']) . "\n";
        }
        $facts_text .= "  Roles: " . $roles . "\n";
        $facts_text .= "  Registered: " . $registered . "\n";
        if (isset($user['description']) && !empty($user['description'])) {
          $facts_text .= "  Description: " . esc_html(wp_trim_words($user['description'], 30)) . "\n";
        }
        if (isset($user['last_login']) && !empty($user['last_login'])) {
          $facts_text .= "  Last Login: " . esc_html($user['last_login']) . "\n";
        }
        $facts_text .= "  Post Count: " . number_format($post_count) . "\n";
        if (isset($user['url']) && !empty($user['url'])) {
          $facts_text .= "  URL: " . esc_url($user['url']) . "\n";
        }
      }
      $facts_text .= "\n";
    }
    
    // Comments Data
    if (isset($wp_data['comments_data']) && is_array($wp_data['comments_data']) && !empty($wp_data['comments_data'])) {
      $comments_count = count($wp_data['comments_data']);
      $facts_text .= "\nRECENT COMMENTS (showing up to " . $max_comments . " of " . $comments_count . "):\n";

      $comment_num = 1;
      foreach ($wp_data['comments_data'] as $comment) {
        if (!is_array($comment)) continue;
        $author = isset($comment['author']) ? esc_html($comment['author']) : 'Unknown';
        $post_title = isset($comment['post_title']) ? esc_html($comment['post_title']) : 'N/A';
        $date = isset($comment['date']) ? date('M j, Y g:i a', strtotime($comment['date'])) : 'N/A';
        $status = isset($comment['status']) ? esc_html($comment['status']) : 'unknown';
        $content = isset($comment['content']) ? esc_html(wp_trim_words($comment['content'], 30)) : '';
        
        $facts_text .= "\nCOMMENT by " . $author . ":\n";
        $facts_text .= "  Post: " . $post_title . "\n";
        $facts_text .= "  Date: " . $date . "\n";
        $facts_text .= "  Status: " . ucfirst($status) . "\n";
        if ($content) {
          $facts_text .= "  Content: " . $content . "\n";
        }
        $comment_num++;
        if ($comment_num > $max_comments) {
          $facts_text .= "... (" . ($comments_count - $max_comments) . " more comments not shown to conserve tokens)\n";
          break;
        }
      }
      $facts_text .= "\n";
    }
  }
  
  // Add Training Data to facts text
  $training_items = luna_widget_get_training_data();
  if (!empty($training_items)) {
    $facts_text .= "\n=== COMPANY TRAINING DATA ===\n";
    $facts_text .= "⚠️ CRITICAL: This section contains company-specific training data that Luna MUST use to provide accurate, context-aware responses.\n";
    $facts_text .= "⚠️ This training data should be referenced when answering questions about the company, its mission, products, services, or operations.\n\n";
    
    foreach ($training_items as $index => $item) {
      $facts_text .= "TRAINING DATA ITEM #" . ($index + 1) . ":\n";
      if (isset($item['industry_label'])) {
        $facts_text .= "Industry: " . esc_html($item['industry_label']) . "\n";
      }
      if (isset($item['company_description'])) {
        $facts_text .= "Company Description: " . esc_html($item['company_description']) . "\n";
      } elseif (isset($item['summary'])) {
        // Backward compatibility
        $facts_text .= "Company Description: " . esc_html($item['summary']) . "\n";
      }
      if (isset($item['mission']) && !empty($item['mission'])) {
        $facts_text .= "Mission: " . esc_html($item['mission']) . "\n";
      }
      if (isset($item['vision']) && !empty($item['vision'])) {
        $facts_text .= "Vision: " . esc_html($item['vision']) . "\n";
      }
      if (isset($item['products']) && !empty($item['products'])) {
        $facts_text .= "Products: " . esc_html($item['products']) . "\n";
      }
      if (isset($item['services']) && !empty($item['services'])) {
        $facts_text .= "Services: " . esc_html($item['services']) . "\n";
      }
      if (isset($item['differentiators']) && !empty($item['differentiators'])) {
        $facts_text .= "Differentiators: " . esc_html($item['differentiators']) . "\n";
      }
      if (isset($item['critical_context']) && !empty($item['critical_context'])) {
        $facts_text .= "Critical Context: " . esc_html($item['critical_context']) . "\n";
      }
      if (isset($item['created'])) {
        $facts_text .= "Training Data Created: " . esc_html($item['created']) . "\n";
      }
      $facts_text .= "\n";
    }
  }

  // Include full raw payloads so GPT has access to every field coming from VL Hub
  if (!empty($facts['raw_hub_payloads']) && is_array($facts['raw_hub_payloads'])) {
    $facts_text .= "\nRAW HUB JSON SNAPSHOT (VERBATIM):\n";
    $max_raw_chars = 5000; // prevent oversized payloads from breaking OpenAI requests
    foreach (array('all_connections' => 'ALL CONNECTIONS ENDPOINT', 'data_streams' => 'DATA STREAMS ENDPOINT', 'merged' => 'MERGED PAYLOAD USED BY LUNA') as $raw_key => $label) {
      if (!empty($facts['raw_hub_payloads'][$raw_key])) {
        $facts_text .= "--- " . $label . " ---\n";
        // Use compact JSON to reduce tokens while keeping fields searchable
        $raw_json = wp_json_encode($facts['raw_hub_payloads'][$raw_key], JSON_UNESCAPED_SLASHES);
        if ($raw_json !== null) {
          $raw_len = strlen($raw_json);
          if ($raw_len > $max_raw_chars) {
            $facts_text .= substr($raw_json, 0, $max_raw_chars) . "\n... [truncated " . ($raw_len - $max_raw_chars) . " characters of raw payload to keep the request under the OpenAI limit]\n\n";
          } else {
            $facts_text .= $raw_json . "\n\n";
          }
        } else {
          $facts_text .= "[raw payload could not be encoded]\n\n";
        }
      }
    }
  }

  // Ensure the final facts_text stays within a safe length for OpenAI
  $max_facts_length = 24000;
  if (strlen($facts_text) > $max_facts_length) {
    $facts_text = substr($facts_text, 0, $max_facts_length) . "\n... [facts truncated to stay within model limits]\n";
  }

  // Allow Composer to enhance facts_text
  if ($is_composer) {
    $facts_text = apply_filters('luna_composer_facts_text', $facts_text, $facts);
  }
  
  // === BUILD SYSTEM PROMPT (FINAL VERSION) ===

  // Use enhanced system prompt for Luna Compose
  if ($is_composer) {
    $default_composer_prompt = "You are Luna — a senior WebOps/CloudOps/Marketing analyst. Speak warmly in concise paragraphs, never invent data, and note when lists are truncated. Use VL Hub facts (posts, pages, plugins, security, analytics, cloud) to explain meaning and end with one actionable next step. When JSON is requested, return compact single-line JSON without extra whitespace. Blend facts with brief analysis to stay efficient.";

    // Allow Composer to enhance system prompt (filter can override default)
    $system_prompt = apply_filters('luna_composer_system_prompt', $default_composer_prompt, $facts);
  } else {
    // Standard system prompt for Luna Chat
    $system_prompt = "You are Luna — a trusted WebOps/CloudOps advisor. Speak conversationally, stay factual, and never invent data. Use the supplied facts to connect WordPress, security, analytics, and cloud details to clear recommendations, ending with one next step. Keep responses compact and avoid unnecessary repetition.";
  }

  // Construct final messages
  $messages = array(
      array(
          'role' => 'system',
          'content' => $system_prompt . "\n\n" . $facts_text,
      ),
  );

  // Add conversation history
  if ($pid) {
      $transcript = get_post_meta($pid, 'transcript', true);
      if (is_array($transcript)) {
          foreach ($transcript as $turn) {
              if (!empty($turn['user'])) {
                  $messages[] = array('role' => 'user', 'content' => $turn['user']);
              }
              if (!empty($turn['assistant'])) {
                  $messages[] = array('role' => 'assistant', 'content' => $turn['assistant']);
              }
          }
      }
  }

  // Add the user's new message
  $messages[] = array('role' => 'user', 'content' => $user_text);
  
  return $messages;
}

/**
 * Call OpenAI API with messages
 */
function luna_call_openai($messages, $api_key, $is_composer = false) {
  if (empty($api_key)) {
    return new WP_Error('no_api_key', 'OpenAI API key is not configured');
  }

  // For Luna Compose, use slightly higher temperature for more creative, thoughtful responses
  // while still maintaining factual accuracy based on available data
  $temperature = $is_composer ? 0.4 : 0.1;

  $payload = array(
    'model' => 'gpt-4o', // Align with license manager usage and broader availability
    'messages' => $messages,
    'temperature' => $temperature,
    'max_tokens' => 1200,
  );

  $encoded_body = wp_json_encode($payload);
  if ($encoded_body === false || $encoded_body === null) {
    return new WP_Error('openai_encode_error', 'Failed to encode OpenAI request payload');
  }

  $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
    'timeout' => 60,
    'headers' => array(
      'Authorization' => 'Bearer ' . $api_key,
      'Content-Type' => 'application/json',
    ),
    'body' => $encoded_body,
  ));

  if (is_wp_error($response)) {
    return $response;
  }

  $raw_body = wp_remote_retrieve_body($response);
  $body = json_decode($raw_body, true);
  $status = wp_remote_retrieve_response_code($response);
  if ($status < 200 || $status > 299) {
    $message = 'Failed to get response from OpenAI';
    if (isset($body['error']['message'])) {
      $message = $body['error']['message'];
    } elseif (isset($body['message'])) {
      $message = $body['message'];
    } elseif (!empty($raw_body)) {
      $snippet = substr($raw_body, 0, 300);
      $message = 'OpenAI HTTP ' . $status . ' - ' . $snippet;
    } else {
      $message = 'OpenAI HTTP ' . $status . ' with empty response body';
    }
    return new WP_Error('openai_error', $message);
  }

  if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
    return new WP_Error(
      'openai_error',
      'Malformed response from OpenAI: ' . json_last_error_msg() . ' - ' . substr($raw_body, 0, 300)
    );
  }

  if (isset($body['choices'][0]['message']['content'])) {
    return trim($body['choices'][0]['message']['content']);
  }

  if (isset($body['error']['message'])) {
    return new WP_Error('openai_error', $body['error']['message']);
  }

  return new WP_Error('openai_error', 'Failed to get response from OpenAI: unexpected response format');
}

/* ============================================================
 * REST API ENDPOINTS
 * ============================================================ */
add_action('rest_api_init', function () {
  // Chat endpoint
  register_rest_route('luna_widget/v1', '/chat', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_chat_handler',
    'permission_callback' => '__return_true', // Allow unauthenticated
  ));
  
  // Session management endpoints
  register_rest_route('luna_widget/v1', '/chat/inactive', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_rest_chat_inactive',
    'permission_callback' => '__return_true',
  ));
  
  register_rest_route('luna_widget/v1', '/chat/end-session', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_rest_chat_end_session',
    'permission_callback' => '__return_true',
  ));
  
  register_rest_route('luna_widget/v1', '/chat/reset-session', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_rest_chat_reset_session',
    'permission_callback' => '__return_true',
  ));
  
  // Widget HTML endpoint for Supercluster embedding
  register_rest_route('luna_widget/v1', '/widget/html', array(
    'methods' => 'GET',
    'callback' => 'luna_widget_get_html',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('luna_widget/v1', '/transcript', array(
    'methods' => 'GET',
    'callback' => 'luna_widget_export_transcript',
    'permission_callback' => '__return_true',
  ));

  // Composer fetch endpoint for history
  register_rest_route('luna_widget/v1', '/composer/fetch', array(
    'methods' => 'GET',
    'callback' => 'luna_widget_composer_fetch',
    'permission_callback' => '__return_true',
  ));

  // Composer save endpoint
  register_rest_route('luna_widget/v1', '/composer/save', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_composer_save',
    'permission_callback' => '__return_true',
  ));

  // Composer feedback endpoint (like/dislike)
  register_rest_route('luna_widget/v1', '/composer/feedback', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_composer_feedback',
    'permission_callback' => '__return_true',
  ));

  // Canned responses/Essentials endpoint for Luna Composer
  register_rest_route('luna_widget/v1', '/canned-responses', array(
    'methods' => 'GET',
    'callback' => 'luna_widget_canned_responses',
    'permission_callback' => '__return_true',
  ));
  
  register_rest_route('luna_widget/v1', '/training/test', array(
    'methods' => 'POST',
    'callback' => 'luna_widget_test_training',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ));

});

function luna_widget_export_transcript(WP_REST_Request $req) {
  // Add CORS headers for cross-origin requests
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
  header('Access-Control-Allow-Credentials: true');
  
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    status_header(200);
    exit;
  }
  
  // Try to get conversation ID from request parameter first (for closed sessions)
  $pid_param = $req->get_param('conversation_id');
  if (!empty($pid_param) && is_numeric($pid_param)) {
    $pid = (int)$pid_param;
    // Verify this is a valid conversation post
    $post = get_post($pid);
    if (!$post || $post->post_type !== 'luna_widget_convo') {
      $pid = 0;
    }
  } else {
    // Fall back to current conversation from cookie
  $pid = luna_widget_current_conversation_id();
  }
  
  // If still no conversation ID, try to get the most recently closed conversation
  if (!$pid) {
    $closed_conversations = get_posts(array(
      'post_type' => 'luna_widget_convo',
      'post_status' => 'any',
      'numberposts' => 1,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => array(
        array(
          'key' => 'session_closed',
          'compare' => 'EXISTS'
        )
      )
    ));
    if (!empty($closed_conversations)) {
      $pid = $closed_conversations[0]->ID;
    }
  }
  
  if (!$pid) {
      return new WP_REST_Response(array('ok' => false, 'error' => 'No conversation found. The session may have already ended.'), 404);
  }

  $t = get_post_meta($pid, 'transcript', true);
  if (!is_array($t)) $t = array();

  $out = "Luna Chat Transcript\n";
  $out .= "Conversation ID: {$pid}\n";
  $out .= "Generated: " . date('Y-m-d H:i:s') . "\n";
  $out .= "----------------------------------------\n\n";

  if (empty($t)) {
    $out .= "No messages in this conversation.\n";
  } else {
  foreach ($t as $turn) {
        $ts = isset($turn['ts']) ? date('Y-m-d H:i:s', $turn['ts']) : date('Y-m-d H:i:s');
      if (!empty($turn['user'])) {
          $out .= "[{$ts}] User: {$turn['user']}\n";
      }
      if (!empty($turn['assistant'])) {
          $out .= "[{$ts}] Luna: {$turn['assistant']}\n";
      }
      $out .= "\n";
    }
  }

  return new WP_REST_Response(array(
      'ok' => true,
      'filename' => 'luna-transcript-' . $pid . '.txt',
      'content' => $out
  ), 200);
}

/**
 * Fetch composer documents for history
 */
function luna_widget_composer_fetch(WP_REST_Request $req) {
  $license = $req->get_param('license');
  if (empty($license)) {
    return new WP_Error('no_license', 'License key is required.', array('status' => 400));
  }

  $document_id = $req->get_param('document_id');
  
  // Get documents from luna_compose post type for this license
  $args = array(
    'post_type' => 'luna_compose',
    'post_status' => 'any',
    'numberposts' => 30, // Last 30 days worth
    'meta_query' => array(
      array(
        'key' => 'license',
        'value' => $license,
        'compare' => '='
      )
    ),
    'orderby' => 'date',
    'order' => 'DESC'
  );
  
  // If specific document ID requested, fetch by document_id meta field
  if (!empty($document_id)) {
    $args['meta_query'][] = array(
      'key' => 'document_id',
      'value' => $document_id,
      'compare' => '='
    );
    $args['numberposts'] = 1;
  }

  $posts = get_posts($args);

  $documents = array();
  foreach ($posts as $post) {
    $doc_id = get_post_meta($post->ID, 'document_id', true);
    $prompt = get_post_meta($post->ID, 'prompt', true);
    $content = get_post_meta($post->ID, 'content', true);
    $feedback = get_post_meta($post->ID, 'feedback', true);
    $timestamp = get_post_meta($post->ID, 'timestamp', true);
    
    if (!$timestamp) {
      $timestamp = strtotime($post->post_date);
    }

    $documents[] = array(
      'id' => $doc_id ?: $post->ID, // Use document_id if available, otherwise fallback to post ID
      'prompt' => $prompt ?: $post->post_title,
      'content' => $content ?: '',
      'feedback' => $feedback ?: 'dislike',
      'timestamp' => $timestamp
    );
  }

  return rest_ensure_response(array(
    'ok' => true,
    'documents' => $documents
  ));
}

/**
 * Save composer document
 */
function luna_widget_composer_save(WP_REST_Request $req) {
  $license = $req->get_param('license');
  $document_id = $req->get_param('document_id');
  $prompt = $req->get_param('prompt');
  $content = $req->get_param('content');
  $feedback = $req->get_param('feedback');
  
  if (empty($license)) {
    return new WP_Error('no_license', 'License key is required.', array('status' => 400));
  }
  
  if (empty($document_id)) {
    return new WP_Error('no_document_id', 'Document ID is required.', array('status' => 400));
  }
  
  // Find existing document by document_id meta field (not post ID)
  $args = array(
    'post_type' => 'luna_compose',
    'post_status' => 'any',
    'numberposts' => 1,
    'meta_query' => array(
      array(
        'key' => 'document_id',
        'value' => $document_id,
        'compare' => '='
      ),
      array(
        'key' => 'license',
        'value' => $license,
        'compare' => '='
      )
    )
  );
  
  $existing_posts = get_posts($args);
  
  if (!empty($existing_posts)) {
    // Update existing document
    $post = $existing_posts[0];
    
    // Update post meta
    if ($prompt !== null) {
      update_post_meta($post->ID, 'prompt', $prompt);
    }
    if ($content !== null) {
      update_post_meta($post->ID, 'content', $content);
    }
    if ($feedback !== null) {
      update_post_meta($post->ID, 'feedback', $feedback);
    }
    update_post_meta($post->ID, 'timestamp', current_time('timestamp'));
    
    return rest_ensure_response(array(
      'ok' => true,
      'message' => 'Document updated successfully.',
      'document_id' => $document_id,
      'wp_post_id' => $post->ID
    ));
  } else {
    // Create new document
    $post_data = array(
      'post_type' => 'luna_compose',
      'post_status' => 'publish',
      'post_title' => $prompt ?: 'Untitled Document',
      'post_content' => ''
    );
    
    $new_post_id = wp_insert_post($post_data);
    
    if (is_wp_error($new_post_id)) {
      return new WP_Error('create_failed', 'Failed to create document.', array('status' => 500));
    }
    
    // Save metadata
    update_post_meta($new_post_id, 'license', $license);
    update_post_meta($new_post_id, 'document_id', $document_id);
    if ($prompt) {
      update_post_meta($new_post_id, 'prompt', $prompt);
    }
    if ($content) {
      update_post_meta($new_post_id, 'content', $content);
    }
    update_post_meta($new_post_id, 'feedback', $feedback ?: 'dislike');
    update_post_meta($new_post_id, 'timestamp', current_time('timestamp'));
    
    return rest_ensure_response(array(
      'ok' => true,
      'message' => 'Document created successfully.',
      'document_id' => $document_id,
      'wp_post_id' => $new_post_id
    ));
  }
}

/**
 * Save composer feedback (like/dislike)
 */
function luna_widget_composer_feedback(WP_REST_Request $req) {
  $license = $req->get_param('license');
  $document_id = $req->get_param('document_id');
  $feedback_type = $req->get_param('feedback_type');
  
  if (empty($license) || empty($document_id) || empty($feedback_type)) {
    return new WP_Error('missing_params', 'License, document_id, and feedback_type are required.', array('status' => 400));
  }
  
  if (!in_array($feedback_type, array('like', 'dislike'))) {
    return new WP_Error('invalid_feedback', 'Feedback type must be "like" or "dislike".', array('status' => 400));
  }
  
  // Find document by document_id meta field (not post ID)
  $args = array(
    'post_type' => 'luna_compose',
    'post_status' => 'any',
    'numberposts' => 1,
    'meta_query' => array(
      array(
        'key' => 'document_id',
        'value' => $document_id,
        'compare' => '='
      ),
      array(
        'key' => 'license',
        'value' => $license,
        'compare' => '='
      )
    )
  );
  
  $posts = get_posts($args);
  
  if (empty($posts)) {
    return new WP_Error('not_found', 'Document not found.', array('status' => 404));
  }
  
  $post = $posts[0];
  
  // Save feedback
  update_post_meta($post->ID, 'feedback', $feedback_type);
  update_post_meta($post->ID, 'feedback_timestamp', current_time('timestamp'));
  
  return rest_ensure_response(array(
    'ok' => true,
    'message' => 'Feedback saved successfully.',
    'feedback' => $feedback_type,
    'document_id' => $document_id,
    'wp_post_id' => $post->ID
  ));
}

/**
 * Fetch canned responses/Essentials for Luna Composer
 */
function luna_widget_canned_responses(WP_REST_Request $req) {
  $license = $req->get_param('license');
  
  // Get Essentials posts (luna_essentials post type) from client's WordPress site
  $essentials = get_posts(array(
    'post_type' => 'luna_essentials',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'),
    'order' => 'ASC',
    'suppress_filters' => false,
  ));

  $items = array();
  foreach ($essentials as $post) {
    // The prompt is the post title + body text (as command)
    $prompt = $post->post_title;
    if (!empty($post->post_content)) {
      $prompt .= "\n\n" . wp_strip_all_tags($post->post_content);
    }
    
    // Prepare content (the response template)
    $content = apply_filters('the_content', $post->post_content);
    $content = wp_strip_all_tags($content);
    
    // Create excerpt from content
    $excerpt = wp_trim_words($content, 30, '…');
    
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
    
    $items[] = array(
      'id' => $post->ID,
      'title' => $post->post_title,
      'prompt' => trim($prompt),
      'content' => trim($content),
      'excerpt' => $excerpt,
      'categories' => $categories,
    );
  }

  return rest_ensure_response(array(
    'ok' => true,
    'items' => $items
  ));
}

/**
 * Compose admin page placeholder - will be handled by luna-compose.php
 */
function luna_compose_admin_page() {
  // This will be overridden by luna-compose.php if it exists
  if (function_exists('luna_compose_admin_page_impl')) {
    luna_compose_admin_page_impl();
    return;
  }
}

/**
 * Training Admin Page
 */
function luna_training_admin_page() {
  if (!current_user_can('manage_options')) {
    return;
  }
  
  $training_items = luna_widget_get_training_data();
  $notice = '';
  $notice_type = 'updated';
  
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['luna_training_action']) && $_POST['luna_training_action'] === 'add') {
    check_admin_referer('luna_training_add', 'luna_training_nonce');
    
    $industry = isset($_POST['luna_training_industry']) ? sanitize_text_field(wp_unslash($_POST['luna_training_industry'])) : '';
    $company_description = isset($_POST['luna_training_company_description']) ? wp_kses_post(wp_unslash($_POST['luna_training_company_description'])) : '';
    $mission = isset($_POST['luna_training_mission']) ? sanitize_text_field(wp_unslash($_POST['luna_training_mission'])) : '';
    $vision = isset($_POST['luna_training_vision']) ? sanitize_text_field(wp_unslash($_POST['luna_training_vision'])) : '';
    $products = isset($_POST['luna_training_products']) ? wp_kses_post(wp_unslash($_POST['luna_training_products'])) : '';
    $services = isset($_POST['luna_training_services']) ? wp_kses_post(wp_unslash($_POST['luna_training_services'])) : '';
    $differentiators = isset($_POST['luna_training_differentiators']) ? wp_kses_post(wp_unslash($_POST['luna_training_differentiators'])) : '';
    $critical_context = isset($_POST['luna_training_critical_context']) ? wp_kses_post(wp_unslash($_POST['luna_training_critical_context'])) : '';
    
    if ($industry && $company_description) {
      $training_items[] = array(
        'industry' => $industry,
        'industry_label' => luna_widget_training_industry_label($industry),
        'company_description' => $company_description,
        'mission' => $mission,
        'vision' => $vision,
        'products' => $products,
        'services' => $services,
        'differentiators' => $differentiators,
        'critical_context' => $critical_context,
        'created' => current_time('mysql'),
      );
      update_option(LUNA_WIDGET_OPT_TRAINING, $training_items);
      
      // Automatically sync training data to Hub
      $license = get_option(LUNA_WIDGET_OPT_LICENSE, '');
      $hub_base = luna_widget_hub_base();
      if (!empty($license)) {
        $sync_result = luna_widget_sync_training_data_to_hub($license, $hub_base);
        if ($sync_result['success']) {
          $notice = 'Training data item added successfully and synced to VL Hub Profile. It will appear as a "Luna Intel" data stream.';
        } else {
          $notice = 'Training data item added successfully. Hub sync failed: ' . $sync_result['message'];
        }
      } else {
        $notice = 'Training data item added successfully. Note: License key required to sync to Hub.';
      }
      $notice_type = 'updated';
    } else {
      $notice = 'Please select an industry and provide a company description.';
      $notice_type = 'error';
    }
  }
  
  ?>
  <div class="wrap luna-training-admin">
    <h1>Training</h1>
    <p class="description" style="max-width:720px;">Training data entries are used immediately by Luna and VL Hub GPT-4o prompts to provide accurate, context-aware responses for your organization.</p>
    
    <?php if (!empty($notice)) : ?>
      <div class="notice <?php echo esc_attr($notice_type); ?>">
        <p><?php echo esc_html($notice); ?></p>
      </div>
    <?php endif; ?>
    
    <h2 style="margin-top:2rem;">Training Data</h2>
    <table class="widefat striped" style="max-width:960px;">
      <thead>
        <tr>
          <th style="width:220px;">Industry</th>
          <th>Company Training Data</th>
          <th style="width:160px;">Created</th>
          <th style="width:120px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($training_items)) : ?>
          <?php foreach ($training_items as $index => $item) : ?>
            <tr data-training-index="<?php echo esc_attr($index); ?>">
              <td><strong><?php echo esc_html(isset($item['industry_label']) ? $item['industry_label'] : luna_widget_training_industry_label(isset($item['industry']) ? $item['industry'] : '')); ?></strong></td>
              <td>
                <?php 
                $display_text = '';
                if (isset($item['company_description'])) {
                  $display_text = wp_trim_words($item['company_description'], 30, '…');
                } elseif (isset($item['summary'])) {
                  // Backward compatibility
                  $display_text = wp_trim_words($item['summary'], 30, '…');
                }
                echo esc_html($display_text);
                ?>
              </td>
              <td><?php echo esc_html(isset($item['created']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item['created'])) : '—'); ?></td>
              <td>
                <button type="button" class="button button-small luna-test-training-btn" data-training-index="<?php echo esc_attr($index); ?>">Test Training</button>
                <div class="luna-test-response" data-training-index="<?php echo esc_attr($index); ?>" style="display:none;margin-top:8px;padding:8px;background:#f0f0f0;border-radius:4px;font-size:12px;"></div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else : ?>
          <tr>
            <td colspan="4">No training data has been added yet.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    
    <div style="margin-top:2rem;">
      <button type="button" class="button button-primary" id="luna-training-add-btn" aria-expanded="false" aria-controls="luna-training-form">Add Training Data</button>
    </div>
    
    <div id="luna-training-form" style="display:none;margin-top:1.5rem;max-width:720px;">
      <div class="postbox">
        <div class="postbox-header">
          <h2 class="hndle">New Luna Training Item</h2>
        </div>
        <div class="inside">
          <form method="post">
            <?php wp_nonce_field('luna_training_add', 'luna_training_nonce'); ?>
            <input type="hidden" name="luna_training_action" value="add" />
            <table class="form-table" role="presentation">
              <tbody>
                <tr>
                  <th scope="row"><label for="luna_training_industry">Industry</label></th>
                  <td>
                    <select name="luna_training_industry" id="luna_training_industry" class="regular-text" required style="max-width:360px;">
                      <option value="">Select an industry…</option>
                      <?php foreach (luna_widget_training_industry_options() as $group => $options) : ?>
                        <optgroup label="<?php echo esc_attr($group); ?>">
                          <?php foreach ($options as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                          <?php endforeach; ?>
                        </optgroup>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_company_description">Company Training Data</label></th>
                  <td>
                    <p class="description">Provide a comprehensive company description that Luna will use to understand your organization's core identity and context.</p>
                    <textarea name="luna_training_company_description" id="luna_training_company_description" rows="6" class="large-text" required placeholder="Company Description..."></textarea>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_mission">Mission</label></th>
                  <td>
                    <p class="description">The company's mission statement - what the organization aims to achieve and why it exists.</p>
                    <input type="text" name="luna_training_mission" id="luna_training_mission" class="regular-text" placeholder="Enter the company mission statement..." />
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_vision">Vision</label></th>
                  <td>
                    <p class="description">The company's vision statement - the long-term aspirational goal or desired future state.</p>
                    <input type="text" name="luna_training_vision" id="luna_training_vision" class="regular-text" placeholder="Enter the company vision statement..." />
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_products">Products</label></th>
                  <td>
                    <p class="description">List the company's products with titles and descriptions. Format: One product per line, or use structured format like "Product Title: Description".</p>
                    <textarea name="luna_training_products" id="luna_training_products" rows="6" class="large-text" placeholder="Product Title: Description&#10;Another Product: Description"></textarea>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_services">Services</label></th>
                  <td>
                    <p class="description">List the company's services with titles and descriptions. Format: One service per line, or use structured format like "Service Title: Description".</p>
                    <textarea name="luna_training_services" id="luna_training_services" rows="6" class="large-text" placeholder="Service Title: Description&#10;Another Service: Description"></textarea>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_differentiators">Differentiators</label></th>
                  <td>
                    <p class="description">What makes this company unique? Key competitive advantages, unique selling propositions, or distinguishing characteristics that set the company apart.</p>
                    <textarea name="luna_training_differentiators" id="luna_training_differentiators" rows="4" class="large-text" placeholder="List key differentiators, competitive advantages, or unique selling points..."></textarea>
                  </td>
                </tr>
                <tr>
                  <th scope="row"><label for="luna_training_critical_context">Critical Context</label></th>
                  <td>
                    <p class="description">Any critical information, context, or background that Luna must know to provide accurate, relevant responses. This could include company history, key relationships, important dates, strategic priorities, or any other context that would help Luna understand the organization better.</p>
                    <textarea name="luna_training_critical_context" id="luna_training_critical_context" rows="6" class="large-text" placeholder="Enter any critical context, background information, or important details Luna should know..."></textarea>
                  </td>
                </tr>
              </tbody>
            </table>
            <p><button type="submit" class="button button-primary">Save Training Item</button></p>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var btn = document.getElementById('luna-training-add-btn');
    var form = document.getElementById('luna-training-form');
    if (!btn || !form) return;
    btn.addEventListener('click', function(){
      var isHidden = form.style.display === 'none' || form.style.display === '';
      form.style.display = isHidden ? 'block' : 'none';
      btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
      if (isHidden) {
        var select = document.getElementById('luna_training_industry');
        if (select) {
          select.focus();
        }
      }
    });
    
    // Handle Test Training button clicks
    var testButtons = document.querySelectorAll('.luna-test-training-btn');
    testButtons.forEach(function(testBtn) {
      testBtn.addEventListener('click', function() {
        var index = this.getAttribute('data-training-index');
        var responseDiv = document.querySelector('.luna-test-response[data-training-index="' + index + '"]');
        if (!responseDiv) return;
        
        // Disable button and show loading
        this.disabled = true;
        this.textContent = 'Testing...';
        responseDiv.style.display = 'block';
        responseDiv.innerHTML = '<em>Testing training data with Luna...</em>';
        
        // Make API call
        fetch('<?php echo esc_url(rest_url('luna_widget/v1/training/test')); ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
          },
          body: JSON.stringify({
            training_index: parseInt(index)
          })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data.ok && data.response) {
            responseDiv.innerHTML = '<strong>Luna Response:</strong><br>' + data.response.replace(/\n/g, '<br>');
          } else {
            responseDiv.innerHTML = '<strong>Error:</strong> ' + (data.message || 'Failed to test training data');
          }
        })
        .catch(function(err) {
          responseDiv.innerHTML = '<strong>Error:</strong> ' + err.message;
        })
        .finally(function() {
          testBtn.disabled = false;
          testBtn.textContent = 'Test Training';
        });
      });
    });
  })();
  </script>
  <?php
}

/**
 * Test Training Data - REST API endpoint
 */
function luna_widget_test_training(WP_REST_Request $req) {
  if (!current_user_can('manage_options')) {
    return new WP_Error('forbidden', 'Insufficient permissions', array('status' => 403));
  }
  
  $training_index = $req->get_param('training_index');
  if ($training_index === null) {
    return new WP_Error('missing_param', 'training_index parameter is required', array('status' => 400));
  }
  
  $training_items = luna_widget_get_training_data();
  if (!isset($training_items[$training_index])) {
    return new WP_Error('not_found', 'Training data item not found', array('status' => 404));
  }
  
  $item = $training_items[$training_index];
  
  // Get client name (site name or admin user)
  $client_name = get_bloginfo('name');
  if (empty($client_name)) {
    $current_user = wp_get_current_user();
    $client_name = $current_user->display_name ?: 'Client';
  }
  
  // Build training data summary for Luna
  $training_summary = '';
  if (isset($item['company_description'])) {
    $training_summary .= 'Company Description: ' . wp_trim_words($item['company_description'], 50) . ' ';
  }
  if (isset($item['mission']) && !empty($item['mission'])) {
    $training_summary .= 'Mission: ' . $item['mission'] . ' ';
  }
  if (isset($item['vision']) && !empty($item['vision'])) {
    $training_summary .= 'Vision: ' . $item['vision'] . ' ';
  }
  if (isset($item['products']) && !empty($item['products'])) {
    $training_summary .= 'Products: ' . wp_trim_words($item['products'], 30) . ' ';
  }
  if (isset($item['services']) && !empty($item['services'])) {
    $training_summary .= 'Services: ' . wp_trim_words($item['services'], 30) . ' ';
  }
  if (isset($item['differentiators']) && !empty($item['differentiators'])) {
    $training_summary .= 'Differentiators: ' . wp_trim_words($item['differentiators'], 30) . ' ';
  }
  if (isset($item['critical_context']) && !empty($item['critical_context'])) {
    $training_summary .= 'Critical Context: ' . wp_trim_words($item['critical_context'], 30) . ' ';
  }
  
  // Get comprehensive facts including training data
  $facts = luna_widget_get_comprehensive_facts();
  
  // Add training data to facts
  $facts['training_data'] = $item;
  
  // Create a test prompt
  $test_prompt = "Please confirm that you have received and understood the training data for " . $client_name . ". Provide a thank you message and a one-paragraph summary of the training data you've received.";
  
  // Get OpenAI API key
  $api_key = get_option('luna_openai_api_key', '');
  if (empty($api_key)) {
    return new WP_Error('no_api_key', 'OpenAI API key is not configured', array('status' => 500));
  }
  
  // Build messages with facts
  $messages = luna_openai_messages_with_facts(null, $test_prompt, $facts, false, false);
  
  // Call OpenAI
  $answer = luna_call_openai($messages, $api_key, false);
  
  if (is_wp_error($answer)) {
    return new WP_Error('openai_error', $answer->get_error_message(), array('status' => 500));
  }
  
  // Format response to include the required elements
  $response = "Thank you for submitting your Training Data, " . $client_name . ". This is very helpful and will be used to provide thoughtful context to your data-driven AI generative responses and automations.\n\n";
  $response .= trim($answer);
  
  // Store Luna's test response with the training data item
  $training_items[$training_index]['luna_test_response'] = $response;
  $training_items[$training_index]['luna_test_response_date'] = current_time('mysql');
  $training_items[$training_index]['luna_test_summary'] = trim($training_summary);
  update_option(LUNA_WIDGET_OPT_TRAINING, $training_items);
  
  return rest_ensure_response(array(
    'ok' => true,
    'response' => $response,
    'training_summary' => trim($training_summary),
  ));
}

/**
 * Chat History Admin Page
 * Displays all Luna Chat conversations with full transcripts
 */
function luna_chat_admin_page() {
  if (!current_user_can('manage_options')) {
    return;
  }
  
  // Get all conversations
  $all_conversations = get_posts(array(
    'post_type' => 'luna_widget_convo',
    'post_status' => 'any',
    'numberposts' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
  ));
  
  // Group conversations by luna_cid (session ID) to merge all turns from the same session
  $sessions = array();
  foreach ($all_conversations as $convo) {
    $conversation_id = get_post_meta($convo->ID, 'luna_cid', true);
    
    // Use conversation ID as key, or fallback to post ID if no conversation ID
    $session_key = !empty($conversation_id) ? $conversation_id : 'post_' . $convo->ID;
    
    if (!isset($sessions[$session_key])) {
      $sessions[$session_key] = array(
        'conversation_id' => $conversation_id,
        'posts' => array(),
        'earliest_date' => $convo->post_date,
        'latest_date' => $convo->post_date,
        'session_closed' => null,
        'session_closed_reason' => null,
        'merged_transcript' => array(),
      );
    }
    
    // Add this post to the session
    $sessions[$session_key]['posts'][] = $convo->ID;
    
    // Update earliest/latest dates
    if (strtotime($convo->post_date) < strtotime($sessions[$session_key]['earliest_date'])) {
      $sessions[$session_key]['earliest_date'] = $convo->post_date;
    }
    if (strtotime($convo->post_date) > strtotime($sessions[$session_key]['latest_date'])) {
      $sessions[$session_key]['latest_date'] = $convo->post_date;
    }
    
    // Get transcript from this post
    $transcript = get_post_meta($convo->ID, 'transcript', true);
    if (is_array($transcript) && !empty($transcript)) {
      // Merge transcript into session transcript
      $sessions[$session_key]['merged_transcript'] = array_merge($sessions[$session_key]['merged_transcript'], $transcript);
    }
    
    // Check for session closed status (use the most recent one)
    $closed = get_post_meta($convo->ID, 'session_closed', true);
    if ($closed) {
      if (!$sessions[$session_key]['session_closed'] || $closed > $sessions[$session_key]['session_closed']) {
        $sessions[$session_key]['session_closed'] = $closed;
        $sessions[$session_key]['session_closed_reason'] = get_post_meta($convo->ID, 'session_closed_reason', true);
      }
    }
  }
  
  // Sort merged transcripts by timestamp
  foreach ($sessions as $key => $session) {
    usort($sessions[$key]['merged_transcript'], function($a, $b) {
      $ts_a = isset($a['ts']) ? $a['ts'] : 0;
      $ts_b = isset($b['ts']) ? $b['ts'] : 0;
      return $ts_a - $ts_b;
    });
  }
  
  ?>
  <div class="wrap luna-chat-admin">
    <h1>Luna Chat History</h1>
    <p class="description">View all Luna Chat conversations from the first greeting message to the final "Session has ended" message.</p>
    
    <?php if (empty($sessions)) : ?>
      <p>No chat conversations found yet.</p>
    <?php else : ?>
      <div class="luna-chat-conversations" style="margin-top: 2rem;">
        <?php foreach ($sessions as $session_key => $session) : 
          $transcript = $session['merged_transcript'];
          $session_closed = $session['session_closed'];
          $session_closed_reason = $session['session_closed_reason'];
          $conversation_id = $session['conversation_id'];
          $start_date = $session['earliest_date'];
          $end_date = $session_closed ? date('Y-m-d H:i:s', $session_closed) : 'Ongoing';
          ?>
          <div class="luna-chat-conversation" style="margin-bottom: 2rem; padding: 1.5rem; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #eee;">
              <div>
                <h2 style="margin: 0 0 0.5rem 0; font-size: 1.2em;">
                  Session <?php echo esc_html(substr($session_key, 0, 20)); ?>
                  <?php if ($conversation_id) : ?>
                    <span style="font-size: 0.85em; font-weight: normal; color: #666;">(<?php echo esc_html($conversation_id); ?>)</span>
                  <?php endif; ?>
                  <?php if (count($session['posts']) > 1) : ?>
                    <span style="font-size: 0.75em; font-weight: normal; color: #999; margin-left: 0.5rem;">[<?php echo count($session['posts']); ?> posts merged]</span>
                  <?php endif; ?>
                </h2>
                <div style="font-size: 0.9em; color: #666;">
                  <strong>Started:</strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($start_date))); ?>
                  <?php if ($session_closed) : ?>
                    <br><strong>Ended:</strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $session_closed)); ?>
                    <?php if ($session_closed_reason) : ?>
                      <span style="margin-left: 0.5rem; padding: 2px 8px; background: #f0f0f0; border-radius: 4px; font-size: 0.9em;">
                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $session_closed_reason))); ?>
                      </span>
                    <?php endif; ?>
                  <?php else : ?>
                    <br><span style="color: #00a32a; font-weight: 600;">● Active Session</span>
                  <?php endif; ?>
                </div>
              </div>
              <div style="font-size: 0.9em; color: #666;">
                <strong>Messages:</strong> <?php echo count($transcript); ?>
                <?php if (count($session['posts']) > 1) : ?>
                  <br><span style="font-size: 0.85em; color: #999;">(Merged from <?php echo count($session['posts']); ?> conversation post<?php echo count($session['posts']) > 1 ? 's' : ''; ?>)</span>
                <?php endif; ?>
              </div>
            </div>
            
            <?php if (empty($transcript)) : ?>
              <p style="color: #666; font-style: italic;">No messages in this conversation.</p>
            <?php else : ?>
              <div class="luna-chat-transcript" style="max-height: 600px; overflow-y: auto; padding: 1rem; background: #f9f9f9; border-radius: 6px;">
                <?php foreach ($transcript as $index => $turn) : 
                  $timestamp = isset($turn['ts']) ? $turn['ts'] : 0;
                  $time_display = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
                  ?>
                  <div class="luna-chat-turn" style="margin-bottom: 1.5rem;">
                    <?php if ($time_display) : ?>
                      <div style="font-size: 0.85em; color: #999; margin-bottom: 0.5rem;">
                        <?php echo esc_html($time_display); ?>
                      </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($turn['user'])) : ?>
                      <div class="luna-chat-user-message" style="margin-bottom: 0.75rem; padding: 0.75rem 1rem; background: #fff4e9; border-left: 3px solid #974C00; border-radius: 4px;">
                        <div style="font-size: 0.85em; font-weight: 600; color: #666; margin-bottom: 0.25rem;">User:</div>
                        <div style="white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($turn['user']); ?></div>
                      </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($turn['assistant'])) : ?>
                      <div class="luna-chat-assistant-message" style="margin-bottom: 0.75rem; padding: 0.75rem 1rem; background: #000; color: #fff4e9; border-left: 3px solid #8D8C00; border-radius: 4px;">
                        <div style="font-size: 0.85em; font-weight: 600; color: #8D8C00; margin-bottom: 0.25rem;">Luna:</div>
                        <div style="white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($turn['assistant']); ?></div>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                
                <?php if ($session_closed) : ?>
                  <div class="luna-chat-session-ended" style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f0f0f0; border-left: 3px solid #d63638; border-radius: 4px; font-style: italic; color: #666;">
                    <strong>Session Ended</strong>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * Main chat handler - uses our new comprehensive facts function
 */
function luna_widget_chat_handler(WP_REST_Request $req) {
  // CORS headers
  $add_cors = function($response) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed = array('https://supercluster.visiblelight.ai', 'https://visiblelight.ai');
    if (!empty($origin) && in_array($origin, $allowed)) {
      $response->header('Access-Control-Allow-Origin', $origin);
      $response->header('Access-Control-Allow-Credentials', 'true');
    } else {
      $response->header('Access-Control-Allow-Origin', '*');
    }
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, X-WP-Nonce, X-Luna-Composer');
    return $response;
  };
  
  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $response = new WP_REST_Response(null, 200);
    $response->header('Access-Control-Max-Age', '86400');
    return $add_cors($response);
  }
  
  try {
    $prompt = trim((string)$req->get_param('prompt'));
    if ($prompt === '') {
      $prompt = trim((string)$req->get_param('message'));
    }
    
    $is_greeting = (bool)$req->get_param('greeting');
    $is_composer = false;
    
    // Check for composer mode
    $context = $req->get_param('context');
    $mode = $req->get_param('mode');
    $composer_flag = $req->get_param('composer');
    
    if ($composer_flag === true || $composer_flag === '1' || $composer_flag === 1 || $composer_flag === 'true') {
      $is_composer = true;
    }
    
    if (!$is_composer && is_string($context)) {
      $normalized = strtolower(trim($context));
      if (in_array($normalized, array('composer', 'compose', 'luna_composer', 'luna_compose'), true)) {
        $is_composer = true;
      }
    }
    
    if (!$is_composer && !empty($_SERVER['HTTP_X_LUNA_COMPOSER'])) {
      $is_composer = true;
    }
    
    // Luna Composer is now default functionality - always enabled
    if (false) { // Removed composer disabled check
      $response = new WP_REST_Response(array(
        'answer' => __('Luna Composer is currently disabled by an administrator.', 'luna'),
        'meta' => array('source' => 'system', 'composer' => false),
      ), 200);
      return $add_cors($response);
    }
    
    // Handle initial greeting
    if (!$is_composer && ($is_greeting || $prompt === '')) {
      $greeting = "Hi, there! I'm Luna, your personal WebOps agent and AI companion. Let's start exploring!";
      $pid = luna_conv_id();
      $meta = array('source' => 'system', 'event' => 'initial_greeting');
      if ($pid) {
        $meta['conversation_id'] = $pid;
        luna_log_turn('', $greeting, $meta);
      }
      $response = new WP_REST_Response(array('answer' => $greeting, 'meta' => $meta), 200);
      return $add_cors($response);
    }
    
    if ($is_composer && $prompt === '') {
      $response = new WP_REST_Response(array(
        'error' => __('Please provide content for Luna Composer to reimagine.', 'luna'),
      ), 400);
      return $add_cors($response);
    }
    
    $pid = luna_conv_id();
    
    // Get comprehensive facts using our new function
    $facts = luna_widget_get_comprehensive_facts();
    
    // Get OpenAI API key
    $api_key = get_option('luna_openai_api_key', '');
    
    if (empty($api_key)) {
      // No API key - return deterministic response
      $answer = "I'm Luna, your WebOps assistant. I can help you with your WordPress site and digital infrastructure. ";
      $answer .= "To enable AI-powered responses, please configure your OpenAI API key in the Luna Widget settings.";
      $meta = array('source' => 'deterministic', 'openai' => false);
      if ($pid) {
        $meta['conversation_id'] = $pid;
        luna_log_turn($prompt, $answer, $meta);
      }
      $response = new WP_REST_Response(array('answer' => $answer, 'meta' => $meta), 200);
      return $add_cors($response);
    }
    
    // Build OpenAI messages
    $messages = luna_openai_messages_with_facts($pid, $prompt, $facts, false, $is_composer);
    
    // Call OpenAI (pass is_composer flag for appropriate temperature and response style)
    $answer = luna_call_openai($messages, $api_key, $is_composer);
    
    if (is_wp_error($answer)) {
      $error_msg = "I'm sorry, I encountered an error: " . $answer->get_error_message();
      $meta = array('source' => 'error', 'error' => $answer->get_error_code());
      if ($pid) {
        $meta['conversation_id'] = $pid;
        luna_log_turn($prompt, $error_msg, $meta);
      }
      $response = new WP_REST_Response(array('answer' => $error_msg, 'meta' => $meta), 200);
      return $add_cors($response);
    }
    
    // Log the turn
    $meta = array('source' => 'openai', 'composer' => $is_composer);
    if ($pid) {
      $meta['conversation_id'] = $pid;
      luna_log_turn($prompt, $answer, $meta);
    }
    
    $response = new WP_REST_Response(array('answer' => $answer, 'meta' => $meta), 200);
    return $add_cors($response);
    
  } catch (Exception $e) {
    error_log('[Luna Widget] Chat handler error: ' . $e->getMessage());
    $response = new WP_REST_Response(array(
      'error' => 'An error occurred processing your request.',
      'message' => $e->getMessage(),
    ), 500);
    return $add_cors($response);
  }
}

function luna_widget_rest_chat_inactive(WP_REST_Request $req) {
  $message = $req->get_param('message');
  if (!is_string($message) || trim($message) === '') {
    $message = "I haven't heard from you in a while, are you still there? If not, I'll close out this chat automatically in 3 minutes.";
  } else {
    $message = sanitize_text_field($message);
  }

  $pid = luna_conv_id();
  $meta = array('source' => 'system', 'event' => 'inactive_warning');
  if ($pid) {
    $meta['conversation_id'] = $pid;
    update_post_meta($pid, 'last_inactive_warning', time());
  }

  luna_log_turn('', $message, $meta);

  return new WP_REST_Response(array('message' => $message), 200);
}

function luna_widget_rest_chat_end_session(WP_REST_Request $req) {
  $pid = luna_conv_id();
  $message = $req->get_param('message');
  if (!is_string($message) || trim($message) === '') {
    $message = 'This chat session has been closed due to inactivity.';
  } else {
    $message = sanitize_text_field($message);
  }

  $reason = $req->get_param('reason');
  if (!is_string($reason) || trim($reason) === '') {
    $reason = 'manual';
  } else {
    $reason = sanitize_text_field($reason);
  }

  $already_closed = $pid ? (bool)get_post_meta($pid, 'session_closed', true) : false;
  if ($pid && !$already_closed) {
    luna_widget_close_conversation($pid, $reason);
    $meta = array(
      'source' => 'system',
      'event' => 'session_end',
      'reason' => $reason,
      'conversation_id' => $pid,
    );
    luna_log_turn('', $message, $meta);
  }

  return new WP_REST_Response(array(
    'closed' => (bool)$pid,
    'already_closed' => $already_closed,
    'conversation_id' => $pid,
    'message' => $message,
  ), 200);
}

function luna_widget_rest_chat_reset_session(WP_REST_Request $req) {
  $reason = $req->get_param('reason');
  if (!is_string($reason) || trim($reason) === '') {
    $reason = 'reset';
  } else {
    $reason = sanitize_text_field($reason);
  }

  $current = luna_widget_current_conversation_id();
  if ($current) {
    luna_widget_close_conversation($current, $reason);
  }

  $pid = luna_conv_id(true);
  if ($pid) {
    return new WP_REST_Response(array('reset' => true, 'conversation_id' => $pid), 200);
  }

  return new WP_REST_Response(array('reset' => false), 500);
}

/**
 * Get widget HTML/CSS/JS for Supercluster embedding
 */
function luna_widget_get_html(WP_REST_Request $req) {
  // Add CORS headers
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
  
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    status_header(200);
    exit;
  }
  
  // Check for vl_key parameter (for Supercluster embedding)
  $vl_key = $req->get_param('vl_key');
  $vl_key = $vl_key ? sanitize_text_field($vl_key) : '';
  
  // Check if plugin has license
  $license = trim((string)get_option(LUNA_WIDGET_OPT_LICENSE, ''));
  
  // If vl_key is provided, validate it matches the stored license
  if ($vl_key !== '') {
    if ($license === '' || $license !== $vl_key) {
      return new WP_REST_Response(array('ok' => false, 'error' => 'License key validation failed'), 403);
    }
  } else {
    // No vl_key provided - check license
    if ($license === '') {
      return new WP_REST_Response(array('ok' => false, 'error' => 'No license configured'), 403);
    }
  }
  
  // Get widget settings
  $ui = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
  $title = esc_html(isset($ui['title']) ? $ui['title'] : 'Luna Chat');
  $avatar = esc_url(isset($ui['avatar_url']) ? $ui['avatar_url'] : '');
  $hdr = esc_html(isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna");
  $sub = esc_html(isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?');
  
  // Get button descriptions (will be JSON encoded in JavaScript)
  $button_desc_chat = isset($ui['button_desc_chat']) ? $ui['button_desc_chat'] : 'Start a conversation with Luna to ask questions and get answers about your digital universe.';
  $button_desc_compose = isset($ui['button_desc_compose']) ? $ui['button_desc_compose'] : 'Access Luna Composer to use canned prompts and responses for quick interactions.';
  $button_desc_report = isset($ui['button_desc_report']) ? $ui['button_desc_report'] : 'Generate comprehensive reports about your site health, performance, and security.';
  $button_desc_automate = isset($ui['button_desc_automate']) ? $ui['button_desc_automate'] : 'Set up automated workflows and tasks with Luna to streamline your operations.';
  
  // For Supercluster, always position in bottom left
  $pos_css = 'bottom:20px;left:20px;';
  $panel_anchor = 'bottom:80px;left:2rem;right:auto;';
  
  // Generate CSS
  $css = "
    .luna-fab { position:relative !important; z-index:2147483646 !important; {$pos_css} }
    .luna-launcher{display:flex;align-items:center;gap:10px;background:#111;color:#fff4e9;border:1px solid #5A5753;border-radius:999px;padding:5px 17px 5px 8px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.25);width:100%;max-width:215px;}
    .luna-launcher .ava{width:42px;height:42px;border-radius:50%;background:#222;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;}
    .luna-launcher .txt{line-height:1.2;display:flex;flex-direction:column;flex:1;min-width:0;overflow:hidden;position:relative}
    .luna-launcher .txt span{max-width:222px !important;display:inline-block;white-space:nowrap;overflow:hidden}
    .luna-panel{position: fixed !important;z-index: 2147483647 !important; {$panel_anchor} width: clamp(320px,92vw,420px);max-height: min(70vh,560px);display: none;flex-direction: column;border-radius: 12px;border: 1px solid #232120;background: #000;color: #fff4e9;overflow: hidden;}
    .luna-panel.show{display:flex !important;z-index: 2147483647 !important;}
    .luna-head{padding:10px 12px;font-weight:600;background:#000;border-bottom:1px solid #333;display:flex;align-items:center;justify-content:space-between}
    .luna-thread{padding:10px 12px;overflow:auto;flex:1 1 auto}
    .luna-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #333}
    .luna-input{flex:1 1 auto;background:#111;color:#fff4e9;border:1px solid #333;border-radius:10px;padding:8px 10px}
    .luna-send{background:linear-gradient(270deg, #974C00 0%, #8D8C00 100%) !important;color:#000;border:none;border-radius:10px;padding:8px 12px;cursor:pointer;font-size: .88rem;font-weight: 600}
    .luna-thread .luna-msg{clear:both;margin:6px 0}
    .luna-thread .luna-user{float:right;background:#fff4e9;color:#000;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
    .luna-thread .luna-assistant{float:left;background:#000000;border:1px solid #1f1d1a;color:#fff4e9;display:inline-block;padding:10px;border-radius:10px;max-width:85%;word-wrap:break-word;line-height:1.25rem;}
    .luna-thread .luna-loading{float:left;background:#111;border:1px solid #333;color:#fff4e9;display:inline-block;padding:8px 10px;border-radius:10px;max-width:85%;word-wrap:break-word}
  ";
  
  // Generate HTML
  $html = '
    <div class="luna-fab" aria-live="polite">
      <button class="luna-launcher" aria-expanded="false" aria-controls="luna-panel" title="' . $title . '">
        <span class="ava">
          ' . ($avatar ? '<img src="' . $avatar . '" alt="" style="width:42px;height:42px;object-fit:cover">' : '
            <svg width="24" height="24" viewBox="0 0 36 36" fill="none" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#222"/><path d="M18 18a6 6 0 100-12 6 6 0 000 12zm0 2c-6 0-10 3.2-10 6v2h20v-2c0-2.8-4-6-10-6z" fill="#666"/></svg>
          ') . '
        </span>
        <span class="txt"><strong>' . $hdr . '</strong><span>' . $sub . '</span></span>
      </button>
      <div id="luna-panel" class="luna-panel" role="dialog" aria-label="' . $title . '">
        <div class="luna-head"><span>' . $title . '</span><button class="luna-close" style="background:transparent;border:0;color:#fff;cursor:pointer" aria-label="Close">✕</button></div>
        <div class="luna-thread"></div>
        <form class="luna-form"><input class="luna-input" placeholder="Ask Luna…" autocomplete="off"><button type="button" class="luna-send">Send</button></form>
        <div class="luna-end-modal" style="display:none; position:absolute; inset:0; background:rgba(0,0,0,0.75); align-items:center; justify-content:center; z-index:99999;">
          <div style="background:#111; border:1px solid #444; padding:20px; border-radius:10px; width:300px; text-align:center; color:#fff4e9;">
            <p style="margin-bottom:15px;">End this Luna session?</p>
            <button class="luna-end-confirm" style="margin-right:10px; padding:6px 14px; border:0; border-radius:6px; background:#B20000; color:white;">End Session</button>
            <button class="luna-end-cancel" style="padding:6px 14px; border:0; border-radius:6px; background:#333; color:white;">Close Window</button>
          </div>
        </div>
      </div>
    </div>
  ';
  
  // Generate JavaScript
  $chat_endpoint = rest_url('luna_widget/v1/chat');
  $end_session_endpoint = rest_url('luna_widget/v1/chat/end-session');
  $transcript_endpoint = rest_url('luna_widget/v1/transcript');
  $nonce = wp_create_nonce('wp_rest');
  
  $js = "
    (function(){
      var fab = document.querySelector('.luna-launcher');
      var panel = document.querySelector('#luna-panel');
      var overlay = document.createElement('div');
      overlay.className = 'luna-overlay';
      document.body.appendChild(overlay);
      
      if (fab && panel) {
        fab.addEventListener('click', function() {
          var isOpen = panel.classList.contains('show');
          if (isOpen) {
            panel.classList.remove('show');
            panel.style.display = 'none'; // Explicitly hide
            overlay.classList.remove('show');
            overlay.style.display = 'none'; // Explicitly hide
            fab.setAttribute('aria-expanded', 'false');
          } else {
            panel.classList.add('show');
            panel.style.display = ''; // Remove inline style to let CSS handle it
            overlay.classList.add('show');
            overlay.style.display = ''; // Remove inline style to let CSS handle it
            fab.setAttribute('aria-expanded', 'true');
            loadInitialGreeting();
          }
        });
        
        overlay.addEventListener('click', function() {
          panel.classList.remove('show');
          panel.style.display = 'none'; // Explicitly hide
          overlay.classList.remove('show');
          overlay.style.display = 'none'; // Explicitly hide
          fab.setAttribute('aria-expanded', 'false');
        });
        
        var closeBtn = panel.querySelector('.luna-close');
        if (closeBtn) {
          closeBtn.addEventListener('click', function() {
              const modal = panel.querySelector('.luna-end-modal');
              modal.style.display = 'flex';
          });
        }

        // END SESSION LOGIC
        panel.querySelector('.luna-end-cancel').addEventListener('click', function() {
            // Close the modal
            panel.querySelector('.luna-end-modal').style.display = 'none';
            
            // Minimize the panel and show launcher
            panel.classList.remove('show');
            panel.style.display = 'none'; // Explicitly hide the panel
            overlay.classList.remove('show');
            overlay.style.display = 'none'; // Explicitly hide the overlay
            fab.setAttribute('aria-expanded', 'false');
        });

        panel.querySelector('.luna-end-confirm').addEventListener('click', async function() {
            let conversationId = null;

            // Call end-session
            const endSessionRes = await fetch(" . json_encode(esc_url_raw($end_session_endpoint)) . ", {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ message: 'The session has ended.', reason: 'closed_by_user' })
            });
            
            // Get conversation ID from end-session response
            try {
                const endSessionData = await endSessionRes.json();
                if (endSessionData.conversation_id) {
                    conversationId = endSessionData.conversation_id;
                }
            } catch (e) {
                console.warn('[Luna Widget] Could not get conversation ID from end-session response:', e);
            }

            // Add final message to thread
            addMessage('assistant', 'The session has ended.');

            // Add download button
            const btn = document.createElement('button');
            btn.textContent = 'Download Transcript';
            btn.className = 'luna-download-transcript';
            btn.style.marginTop = '12px';
            btn.style.padding = '8px 14px';
            btn.style.borderRadius = '6px';
            btn.style.border = '1px solid #444';
            btn.style.background = '#000';
            btn.style.color = '#fff4e9';
            btn.style.cursor = 'pointer';

            btn.onclick = async function(e) {
                e.preventDefault();
                e.stopPropagation();
                try {
                    // Build transcript URL with conversation ID if available
                    let transcriptUrl = " . json_encode(esc_url_raw($transcript_endpoint)) . ";
                    if (conversationId) {
                        transcriptUrl += (transcriptUrl.includes('?') ? '&' : '?') + 'conversation_id=' + encodeURIComponent(conversationId);
                    }
                    
                    const res = await fetch(transcriptUrl, {
                        method: 'GET',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    if (!res.ok) {
                        console.error('[Luna Widget] Transcript fetch failed:', res.status, res.statusText);
                        alert('Failed to download transcript. Error: ' + res.status);
                        return;
                    }
                const data = await res.json();
                    if (data.ok && data.content) {
                        const blob = new Blob([data.content], { type: 'text/plain' });
                        const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                        a.download = data.filename || 'luna-transcript.txt';
                        document.body.appendChild(a);
                    a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(a.href);
                    } else {
                        console.error('[Luna Widget] Transcript data error:', data);
                        alert('Failed to download transcript. ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('[Luna Widget] Transcript download error:', error);
                    alert('Failed to download transcript: ' + error.message);
                }
            };

            thread.appendChild(btn);
            thread.scrollTop = thread.scrollHeight;

            // Close the modal
            panel.querySelector('.luna-end-modal').style.display = 'none';
            
            // Close the panel and show launcher
            panel.classList.remove('show');
            panel.style.display = 'none'; // Explicitly hide
            overlay.classList.remove('show');
            overlay.style.display = 'none'; // Explicitly hide
            fab.setAttribute('aria-expanded', 'false');
        });

        
        var form = panel.querySelector('.luna-form');
        var input = panel.querySelector('.luna-input');
        var thread = panel.querySelector('.luna-thread');
        
        if (form && input) {
          form.addEventListener('submit', function(e) {
            e.preventDefault();
            var prompt = input.value.trim();
            if (!prompt) return;
            
            input.value = '';
            addMessage('user', prompt);
            
            fetch('" . esc_url_raw($chat_endpoint) . "', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '" . esc_js($nonce) . "'
              },
              body: JSON.stringify({ prompt: prompt })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
              if (data.answer) {
                addMessage('assistant', data.answer);
              }
            })
            .catch(function(err) {
              addMessage('assistant', 'Sorry, I encountered an error. Please try again.');
            });
          });
        }
        
        function addMessage(role, text) {
          var msg = document.createElement('div');
          msg.className = 'luna-msg luna-' + role;
          msg.textContent = text;
          thread.appendChild(msg);
          thread.scrollTop = thread.scrollHeight;
          return msg; // Return the message element so buttons can be appended
        }
        
        function loadInitialGreeting() {
          if (thread.children.length === 0) {
            fetch('" . esc_url_raw($chat_endpoint) . "', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '" . esc_js($nonce) . "'
              },
              body: JSON.stringify({ greeting: true })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
              if (data.answer) {
                var msgEl = addMessage('assistant', data.answer);
                // Buttons are now shown in the popup, not in the panel
                // Removed addGreetingButtons call
              }
            });
          }
        }
        
        function addGreetingButtons(messageElement) {
          // Check if buttons already added
          if (messageElement.querySelector('.luna-chat-button')) {
            return;
          }
          
          // Get license key from URL or widget context
          var licenseKey = '';
          var urlParams = new URLSearchParams(window.location.search);
          var urlLicense = urlParams.get('license');
          if (urlLicense) {
            // Extract just the license key (before first /) if URL contains path segments
            var licenseMatch = urlLicense.match(/^([^/]+)/);
            if (licenseMatch) {
              licenseKey = licenseMatch[1];
            } else {
              licenseKey = urlLicense;
            }
          }
          
          // Button descriptions from settings
          var buttonDescChat = " . json_encode($button_desc_chat) . ";
          var buttonDescCompose = " . json_encode($button_desc_compose) . ";
          var buttonDescReport = " . json_encode($button_desc_report) . ";
          var buttonDescAutomate = " . json_encode($button_desc_automate) . ";
          
          // Create button container
          var buttonContainer = document.createElement('div');
          buttonContainer.style.cssText = 'margin-top: 12px; display: flex; gap: 8px; flex-direction: column;';
          
          // Helper function to create button with description
          function createButtonWithDesc(className, text, description, onClick, isHighlighted) {
            var buttonWrapper = document.createElement('div');
            buttonWrapper.style.cssText = 'position: relative;';
            
            var button = document.createElement('button');
            button.className = className;
            button.textContent = text;
            button.style.cssText = 'padding: 10px 16px; ' + 
              (isHighlighted ? 'background: linear-gradient(270deg, #974C00 0%, #8D8C00 100%); color: #000; border: none;' : 'background: #111; color: #fff4e9; border: 1px solid #333;') + 
              ' border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; width: 100%; position: relative;';
            button.addEventListener('click', onClick);
            
            // Add question mark icon for description
            var descIcon = document.createElement('span');
            descIcon.textContent = '?';
            descIcon.style.cssText = 'position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,0.2); color: ' + (isHighlighted ? '#000' : '#fff4e9') + '; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; cursor: help;';
            descIcon.title = description;
            button.appendChild(descIcon);
            
            buttonWrapper.appendChild(button);
            return buttonWrapper;
          }
          
          // Luna Chat button
          var chatButtonWrapper = createButtonWithDesc(
            'luna-chat-button',
            'Luna Chat',
            buttonDescChat,
            function() {
              var lunaInput = document.querySelector('.luna-input');
              if (lunaInput) {
                lunaInput.focus();
              }
            },
            true // highlighted
          );
          
          // Luna Compose button
          var composeButtonWrapper = createButtonWithDesc(
            'luna-compose-button',
            'Luna Compose',
            buttonDescCompose,
            function() {
              if (licenseKey) {
                var composeUrl = 'https://supercluster.visiblelight.ai/?license=' + encodeURIComponent(licenseKey) + '/luna-compose';
                window.location.href = composeUrl;
              } else {
                console.warn('[Luna Widget] License key not found, cannot redirect to Compose');
              }
            },
            false
          );
          
          // Luna Report button
          var reportButtonWrapper = createButtonWithDesc(
            'luna-report-button',
            'Luna Report',
            buttonDescReport,
            function() {
              // Placeholder for future Report functionality
              console.log('[Luna Widget] Luna Report clicked');
            },
            false
          );
          
          // Luna Automate button
          var automateButtonWrapper = createButtonWithDesc(
            'luna-automate-button',
            'Luna Automate',
            buttonDescAutomate,
            function() {
              // Placeholder for future Automate functionality
              console.log('[Luna Widget] Luna Automate clicked');
            },
            false
          );
          
          buttonContainer.appendChild(chatButtonWrapper);
          buttonContainer.appendChild(composeButtonWrapper);
          buttonContainer.appendChild(reportButtonWrapper);
          buttonContainer.appendChild(automateButtonWrapper);
          messageElement.appendChild(buttonContainer);
        }
      }
    })();
  ";
  
  return new WP_REST_Response(array(
    'ok' => true,
    'html' => $html,
    'css' => $css,
    'js' => $js,
  ), 200);
}

// Allow unauthenticated requests for chat endpoints
add_filter('rest_authentication_errors', function($result) {
  $rest_route = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  $rest_route_lower = strtolower($rest_route);
  
  if (strpos($rest_route_lower, '/wp-json/luna_widget/v1/chat') !== false || 
      strpos($rest_route_lower, 'luna_widget/v1/chat') !== false ||
      strpos($rest_route_lower, 'luna_widget/v1/widget/html') !== false ||
      (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' && strpos($rest_route_lower, 'luna_widget') !== false)) {
    return null; // Allow unauthenticated
  }
  
  return $result;
}, 20);

/* ============================================================
 * FRONTEND WIDGET RENDERING
 * ============================================================ */
add_action('wp_enqueue_scripts', function () {
  // Only enqueue if not supercluster-only
  $supercluster_only = get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0') === '1';
  
  if ($supercluster_only) {
    return; // Don't enqueue for supercluster-only
  }
  
  // Enqueue Luna Composer script if needed
  wp_register_script(
    'luna-composer',
    LUNA_WIDGET_ASSET_URL . 'assets/js/luna-composer.js',
    array(),
    LUNA_WIDGET_PLUGIN_VERSION,
    true
  );
});

// Add footer hook separately to avoid multiple registrations
// Only render widget if not supercluster-only
add_action('wp_footer', function() {
  $supercluster_only = get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0') === '1';
  
  if (!$supercluster_only) {
    luna_widget_render_widget();
  }
}, 20);

/**
 * Render the floating chat widget
 */
function luna_widget_render_widget() {
  $supercluster_only = get_option(LUNA_WIDGET_OPT_SUPERCLUSTER_ONLY, '0') === '1';
  
  if ($supercluster_only) {
    return;
  }
  
  $ui = get_option(LUNA_WIDGET_OPT_SETTINGS, array());
  $position = isset($ui['position']) ? $ui['position'] : 'bottom-right';
  $title = isset($ui['title']) ? $ui['title'] : 'Luna Chat';
  $avatar_url = isset($ui['avatar_url']) ? $ui['avatar_url'] : '';
  $header_text = isset($ui['header_text']) ? $ui['header_text'] : "Hi, I'm Luna";
  $sub_text = isset($ui['sub_text']) ? $ui['sub_text'] : 'How can I help today?';
  
  $rest_url = esc_url_raw(rest_url('luna_widget/v1/chat'));
  $nonce = wp_create_nonce('wp_rest');
  
  ?>
  <div id="luna-widget-container" class="luna-widget luna-widget-<?php echo esc_attr($position); ?>" style="display:none;">
    <div class="luna-widget-button" id="luna-widget-toggle">
      <?php if ($avatar_url): ?>
        <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($title); ?>" />
      <?php else: ?>
        <span class="luna-widget-icon">💬</span>
      <?php endif; ?>
    </div>
    <div class="luna-widget-panel" id="luna-widget-panel">
      <div class="luna-widget-header">
        <h3><?php echo esc_html($title); ?></h3>
        <button class="luna-widget-close" id="luna-widget-close">×</button>
      </div>
      <div class="luna-widget-greeting">
        <p class="luna-widget-header-text"><?php echo esc_html($header_text); ?></p>
        <p class="luna-widget-sub-text"><?php echo esc_html($sub_text); ?></p>
      </div>
      <div class="luna-widget-thread" id="luna-widget-thread"></div>
      <form class="luna-widget-form" id="luna-widget-form">
        <input type="text" class="luna-widget-input" id="luna-widget-input" placeholder="Ask Luna…" autocomplete="off" />
        <button type="submit" class="luna-widget-send">Send</button>
      </form>
    </div>
  </div>
  
  <style>
    .luna-widget { position: fixed; z-index: 999999; }
    .luna-widget-bottom-right { bottom: 20px; right: 20px; }
    .luna-widget-bottom-left { bottom: 20px; left: 20px; }
    .luna-widget-bottom-center { bottom: 20px; left: 50%; transform: translateX(-50%); }
    .luna-widget-top-right { top: 20px; right: 20px; }
    .luna-widget-top-left { top: 20px; left: 20px; }
    .luna-widget-top-center { top: 20px; left: 50%; transform: translateX(-50%); }
    .luna-widget-button { width: 60px; height: 60px; border-radius: 50%; background: #0073aa; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
    .luna-widget-panel { display: none; width: 400px; max-width: 90vw; height: 600px; max-height: 90vh; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); flex-direction: column; }
    .luna-widget-panel.active { display: flex; }
    .luna-widget-header { padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .luna-widget-close { background: none; border: none; font-size: 24px; cursor: pointer; }
    .luna-widget-thread { flex: 1; overflow-y: auto; padding: 15px; }
    .luna-widget-form { padding: 15px; border-top: 1px solid #eee; display: flex; gap: 10px; }
    .luna-widget-input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
    .luna-widget-send { padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer; }
  </style>
  
  <script>
    (function() {
      const container = document.getElementById('luna-widget-container');
      const toggle = document.getElementById('luna-widget-toggle');
      const panel = document.getElementById('luna-widget-panel');
      const close = document.getElementById('luna-widget-close');
      const form = document.getElementById('luna-widget-form');
      const input = document.getElementById('luna-widget-input');
      const thread = document.getElementById('luna-widget-thread');
      
      if (!container) return;
      
      container.style.display = 'block';
      
      toggle.addEventListener('click', function() {
        panel.classList.toggle('active');
      });
      
      close.addEventListener('click', function() {
        panel.classList.remove('active');
      });
      
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const prompt = input.value.trim();
        if (!prompt) return;
        
        input.value = '';
        addMessage('user', prompt);
        
        try {
          const response = await fetch('<?php echo $rest_url; ?>', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': '<?php echo $nonce; ?>'
            },
            body: JSON.stringify({ prompt: prompt })
          });
          
          const data = await response.json();
          if (data.answer) {
            addMessage('assistant', data.answer);
          }
        } catch (error) {
          addMessage('assistant', 'Sorry, I encountered an error. Please try again.');
        }
      });
      
      function addMessage(role, text) {
        const msg = document.createElement('div');
        msg.className = 'luna-message luna-message-' + role;
        msg.textContent = text;
        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
      }
    })();
  </script>
  <?php
}

/* ============================================================
 * SHORTCODES
 * ============================================================ */
add_shortcode('luna_chat', function($atts = array(), $content = '') {
  $atts = shortcode_atts(array('vl_key' => ''), $atts, 'luna_chat');
  
  $vl_key = !empty($atts['vl_key']) ? sanitize_text_field($atts['vl_key']) : '';
  
  if ($vl_key !== '') {
    $stored_license = trim((string)get_option(LUNA_WIDGET_OPT_LICENSE, ''));
    if ($stored_license === '' || $stored_license !== $vl_key) {
      return '<!-- [luna_chat] License key validation failed -->';
    }
  } else {
    // Shortcode is deprecated but kept for backwards compatibility
    // Widget is always active now
  }
  
  ob_start();
  ?>
  <div class="luna-wrap">
    <div class="luna-thread"></div>
    <form class="luna-form" onsubmit="return false;">
      <input class="luna-input" autocomplete="off" placeholder="Ask Luna…" />
      <button class="luna-send" type="submit">Send</button>
    </form>
  </div>
  <?php
  return ob_get_clean();
});

add_shortcode('luna_composer', function($atts = array(), $content = '') {
  // Luna Composer is now default functionality - always enabled
  // Removed disabled check

  wp_enqueue_script('luna-composer');

  static $composer_localized = false;
  if (!$composer_localized) {
    $prompts = array();
    foreach (luna_composer_default_prompts() as $prompt) {
      $label = isset($prompt['label']) ? (string)$prompt['label'] : '';
      $prompt_text = isset($prompt['prompt']) ? (string)$prompt['prompt'] : '';
      if ($label === '' || $prompt_text === '') {
        continue;
      }
      $prompts[] = array(
        'label' => sanitize_text_field($label),
        'prompt' => wp_strip_all_tags($prompt_text),
      );
    }

    wp_localize_script('luna-composer', 'lunaComposerSettings', array(
      'restUrlChat' => esc_url_raw(rest_url('luna_widget/v1/chat')),
      'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : null,
      'integrated' => true,
      'prompts' => $prompts,
    ));
    $composer_localized = true;
  }

  $id = esc_attr(wp_unique_id('luna-composer-'));
  $placeholder = apply_filters('luna_composer_placeholder', __('Describe what you need from Luna…', 'luna'));
  $inner_content = trim($content) !== '' ? do_shortcode($content) : '';

  ob_start();
  ?>
  <div class="luna-composer" data-luna-composer data-luna-composer-id="<?php echo $id; ?>">
    <div class="luna-composer__card">
      <div data-luna-prompts>
        <?php echo $inner_content ? wp_kses_post($inner_content) : ''; ?>
      </div>
      <form class="luna-composer__form" action="#" method="post" novalidate>
        <div
          class="luna-composer__editor is-empty"
          data-luna-composer-editor
          contenteditable="true"
          role="textbox"
          aria-multiline="true"
          spellcheck="true"
          data-placeholder="<?php echo esc_attr($placeholder); ?>"
        ></div>
        <div class="luna-composer__actions">
          <button type="submit" class="luna-composer__submit" data-luna-composer-submit>
            <?php esc_html_e('', 'luna'); ?>
          </button>
        </div>
      </form>
      <div class="luna-composer__response" data-luna-composer-response></div>
    </div>
  </div>
  <?php
  return ob_get_clean();
});