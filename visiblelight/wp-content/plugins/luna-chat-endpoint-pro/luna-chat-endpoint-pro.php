<?php
/**
 * Plugin Name: Luna Chat Endpoint Pro v1.1
 * Description: Hub-side REST API endpoints for Luna Chat system. Handles all API requests from client sites.
 * Version:     2.0.0
 * Author:      Visible Light
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================================
 * Core Hub Endpoints Only
 * ========================================================================= */

/**
 * Chat endpoint for client sites
 */
add_action('rest_api_init', function () {
  register_rest_route('luna/v1', '/chat-live', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $tenant = $req->get_param('tenant') ?: 'demo';
      $prompt = $req->get_param('prompt') ?: '';
      
      if (empty($prompt)) {
        return new WP_REST_Response(['answer' => 'Please provide a message.'], 400);
      }
      
      // Simple response for Hub
      return new WP_REST_Response([
        'answer' => 'This is a Hub endpoint. Client sites should use their own chat functionality.',
        'sources' => [],
        'actions' => [],
        'confidence' => 0.8,
      ], 200);
    },
  ]);
});

/**
 * Health check endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna/v1', '/health', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      return new WP_REST_Response([
        'status' => 'ok',
        'message' => 'Luna Hub endpoints are working',
        'timestamp' => current_time('mysql'),
      ], 200);
    },
  ]);
});

/**
 * Conversations endpoint for client sites to log conversations
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/conversations/log', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $conversation_data = $req->get_json_params();
      if (!$conversation_data) {
        return new WP_REST_Response(['error' => 'Invalid conversation data'], 400);
      }
      
      // Store conversation in Hub
      $conversations = get_option('vl_hub_conversations', []);
      if (!is_array($conversations)) $conversations = [];
      
      $conversation_id = $conversation_data['id'] ?? uniqid('conv_');
      $conversations[$conversation_id] = [
        'license' => $license,
        'site' => home_url(),
        'started_at' => $conversation_data['started_at'] ?? current_time('mysql'),
        'transcript' => $conversation_data['transcript'] ?? [],
        'logged_at' => current_time('mysql'),
      ];
      
      update_option('vl_hub_conversations', $conversations);
      
      return new WP_REST_Response(['ok' => true, 'id' => $conversation_id], 200);
    }
  ]);
});

/**
 * System comprehensive endpoint for client sites (GET to fetch, POST to store)
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/system/comprehensive', [
    'methods' => ['GET', 'POST'],
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }

      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }

      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }

      // Handle POST (store data from client)
      if ($req->get_method() === 'POST') {
        $comprehensive_data = $req->get_json_params();
        if (!$comprehensive_data) {
          return new WP_REST_Response(['error' => 'Invalid comprehensive data'], 400);
        }

        // Store comprehensive data in Hub profiles
        $profiles = get_option('vl_hub_profiles', []);
        if (!isset($profiles[$license_id])) {
          $profiles[$license_id] = [];
        }

        // Update with comprehensive data
        $profiles[$license_id] = array_merge($profiles[$license_id], $comprehensive_data);
        $profiles[$license_id]['last_updated'] = current_time('mysql');

        update_option('vl_hub_profiles', $profiles);

        error_log('[Luna Hub] Stored comprehensive data for license_id: ' . $license_id);

        return new WP_REST_Response(['ok' => true, 'message' => 'Comprehensive data stored'], 200);
      }

      // Handle GET (return data to client)
      $profile = vl_hub_profile_resolve($license, ['force_refresh' => (bool) $req->get_param('refresh')]);
      if (is_wp_error($profile)) {
        $status = (int) ($profile->get_error_data('status') ?? 500);
        return new WP_REST_Response(['error' => $profile->get_error_message()], $status);
      }

      return new WP_REST_Response($profile, 200);
    }
  ]);
});

/**
 * Security data endpoint for client sites
 */
add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/profile/security', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $request_data = $req->get_json_params();
      if (!$request_data) {
        return new WP_REST_Response(['error' => 'Invalid security data'], 400);
      }
      
      // Extract security data from the payload
      $security_data = isset($request_data['security']) ? $request_data['security'] : $request_data;
      
      // Debug logging
      error_log('[Luna Hub] Security data received for license: ' . substr($license, 0, 8) . '...');
      error_log('[Luna Hub] Security data: ' . print_r($security_data, true));
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Store security data in Hub profiles
      $profiles = get_option('vl_hub_profiles', []);
      if (!isset($profiles[$license_id])) {
        $profiles[$license_id] = [];
      }
      
      $profiles[$license_id]['security'] = $security_data;
      $profiles[$license_id]['last_updated'] = current_time('mysql');
      
      update_option('vl_hub_profiles', $profiles);
      
      // Debug: Log what was stored
      error_log('[Luna Hub] Stored security data for license_id: ' . $license_id);
      error_log('[Luna Hub] Stored data: ' . print_r($security_data, true));
      
      return new WP_REST_Response(['ok' => true, 'message' => 'Security data stored'], 200);
    }
  ]);
});

/* =========================================================================
 * Hub profile utilities
 * ========================================================================= */

/**
 * Resolve a license key to its registry record.
 */
function vl_hub_find_license_record(string $license_key): array {
  $licenses = get_option('vl_licenses_registry', []);
  foreach ($licenses as $id => $row) {
    if (isset($row['key']) && hash_equals((string) $row['key'], $license_key)) {
      return ['id' => $id, 'record' => is_array($row) ? $row : [], 'license' => $license_key];
    }
  }

  return [];
}

/**
 * Determine whether a stored profile is missing WordPress inventory details.
 */
function vl_hub_profile_missing_inventory(array $profile): bool {
  $required_arrays = ['posts', 'pages', 'plugins', 'themes', 'users'];
  foreach ($required_arrays as $key) {
    if (!array_key_exists($key, $profile) || !is_array($profile[$key])) {
      return true;
    }
  }

  return false;
}

/**
 * Fetch a remote endpoint from a client site with the given license header.
 */
function vl_hub_fetch_client_endpoint(string $site_url, string $path, string $license_key, array $query = []) {
  $site_url = trim($site_url);
  if ($site_url === '') {
    return null;
  }

  $base = rtrim($site_url, '/');
  $url  = $base . $path;
  if ($query) {
    $url = add_query_arg($query, $url);
  }

  $response = wp_remote_get($url, [
    'timeout'   => 15,
    'headers'   => [
      'X-Luna-License' => $license_key,
      'Accept'         => 'application/json',
    ],
    'sslverify' => false,
  ]);

  if (is_wp_error($response)) {
    error_log('[Luna Hub] Failed to fetch client endpoint ' . $url . ': ' . $response->get_error_message());
    return null;
  }

  $code = (int) wp_remote_retrieve_response_code($response);
  if ($code < 200 || $code >= 300) {
    error_log('[Luna Hub] HTTP ' . $code . ' fetching client endpoint ' . $url);
    return null;
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (!is_array($body)) {
    error_log('[Luna Hub] Invalid JSON from client endpoint ' . $url);
    return null;
  }

  return $body;
}

/**
 * Refresh the stored profile for a license by querying the client site directly.
 */
function vl_hub_refresh_profile_from_client(array $license_info, array $profile = []): array {
  $license_key   = $license_info['license'];
  $license_id    = $license_info['id'];
  $license_record= $license_info['record'];
  $site_url      = isset($license_record['site']) ? (string) $license_record['site'] : '';

  if ($site_url === '') {
    return $profile;
  }

  if (!is_array($profile)) {
    $profile = [];
  }

  // System snapshot (site + WordPress + plugins/themes overview)
  $system_snapshot = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/system/site', $license_key);
  if (is_array($system_snapshot)) {
    if (isset($system_snapshot['site']) && is_array($system_snapshot['site'])) {
      $profile['site'] = $system_snapshot['site'];
    }
    if (isset($system_snapshot['wordpress']) && is_array($system_snapshot['wordpress'])) {
      $profile['wordpress'] = $system_snapshot['wordpress'];
    }
    if (isset($system_snapshot['plugins']) && is_array($system_snapshot['plugins'])) {
      $profile['plugins'] = $system_snapshot['plugins'];
    }
    if (isset($system_snapshot['themes']) && is_array($system_snapshot['themes'])) {
      $profile['themes'] = $system_snapshot['themes'];
    }
  }

  // Detailed plugin inventory
  $plugins_response = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/plugins', $license_key);
  if (is_array($plugins_response) && isset($plugins_response['items']) && is_array($plugins_response['items'])) {
    $profile['plugins'] = $plugins_response['items'];
  }

  // Detailed theme inventory
  $themes_response = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/themes', $license_key);
  if (is_array($themes_response) && isset($themes_response['items']) && is_array($themes_response['items'])) {
    $profile['themes'] = $themes_response['items'];
  }

  // Posts and pages
  $posts_response = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/content/posts', $license_key, ['per_page' => 100]);
  if (is_array($posts_response)) {
    $profile['posts'] = isset($posts_response['items']) && is_array($posts_response['items']) ? $posts_response['items'] : [];
    if (!isset($profile['content']) || !is_array($profile['content'])) {
      $profile['content'] = [];
    }
    if (isset($posts_response['total'])) {
      $profile['content']['posts_total'] = (int) $posts_response['total'];
    }
  }

  $pages_response = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/content/pages', $license_key, ['per_page' => 100]);
  if (is_array($pages_response)) {
    $profile['pages'] = isset($pages_response['items']) && is_array($pages_response['items']) ? $pages_response['items'] : [];
    if (!isset($profile['content']) || !is_array($profile['content'])) {
      $profile['content'] = [];
    }
    if (isset($pages_response['total'])) {
      $profile['content']['pages_total'] = (int) $pages_response['total'];
    }
  }

  // Users roster
  $users_response = vl_hub_fetch_client_endpoint($site_url, '/wp-json/luna_widget/v1/users', $license_key, ['per_page' => 100]);
  if (is_array($users_response)) {
    $profile['users'] = isset($users_response['items']) && is_array($users_response['items']) ? $users_response['items'] : [];
    if (isset($users_response['total'])) {
      $profile['users_total'] = (int) $users_response['total'];
    }
  }

  // Populate counts
  $posts_total  = isset($profile['content']['posts_total']) ? (int) $profile['content']['posts_total'] : (is_array($profile['posts'] ?? null) ? count($profile['posts']) : 0);
  $pages_total  = isset($profile['content']['pages_total']) ? (int) $profile['content']['pages_total'] : (is_array($profile['pages'] ?? null) ? count($profile['pages']) : 0);
  $users_total  = isset($profile['users_total']) ? (int) $profile['users_total'] : (is_array($profile['users'] ?? null) ? count($profile['users']) : 0);
  $plugins_total= is_array($profile['plugins'] ?? null) ? count($profile['plugins']) : 0;

  $profile['counts'] = [
    'posts'   => $posts_total,
    'pages'   => $pages_total,
    'users'   => $users_total,
    'plugins' => $plugins_total,
  ];

  // Maintain legacy underscore keys expected by some consumers
  $profile['_posts'] = $profile['posts'] ?? [];
  $profile['_pages'] = $profile['pages'] ?? [];
  $profile['_users'] = $profile['users'] ?? [];

  // Ensure base metadata is available
  $home_url = $profile['site']['home_url'] ?? ($license_record['site'] ?? '');
  if ($home_url) {
    $profile['home_url'] = $home_url;
  }
  if (!isset($profile['https'])) {
    if (isset($profile['site']['https'])) {
      $profile['https'] = (bool) $profile['site']['https'];
    } elseif ($home_url) {
      $profile['https'] = (stripos($home_url, 'https://') === 0);
    }
  }

  $profile['license_id']   = $license_id;
  $profile['license_key']  = $license_key;
  if (!isset($profile['client_name']) && !empty($license_record['client'])) {
    $profile['client_name'] = $license_record['client'];
  }

  $profile['profile_last_synced'] = current_time('mysql');

  return $profile;
}

/**
 * Resolve and optionally refresh the stored profile for a license key.
 */
function vl_hub_profile_resolve(string $license_key, array $options = []) {
  $license_info = vl_hub_find_license_record($license_key);
  if (!$license_info) {
    return new WP_Error('license_not_found', __('License not found', 'visible-light'), ['status' => 404]);
  }

  $profiles = get_option('vl_hub_profiles', []);
  $stored   = isset($profiles[$license_info['id']]) && is_array($profiles[$license_info['id']]) ? $profiles[$license_info['id']] : [];

  $force_refresh = !empty($options['force_refresh']);
  if ($force_refresh || vl_hub_profile_missing_inventory($stored)) {
    $stored = vl_hub_refresh_profile_from_client($license_info, $stored);
    $profiles[$license_info['id']] = $stored;
    update_option('vl_hub_profiles', $profiles);
  }

  if (!is_array($stored)) {
    $stored = [];
  }

  if (empty($stored)) {
    $home_url = isset($license_info['record']['site']) ? (string) $license_info['record']['site'] : '';
    $stored = [
      'site'    => ['home_url' => $home_url, 'https' => stripos($home_url, 'https://') === 0],
      'posts'   => [],
      'pages'   => [],
      'plugins' => [],
      'themes'  => [],
      'users'   => [],
      'content' => [],
    ];
  }

  if (!isset($stored['posts']) && isset($stored['_posts']) && is_array($stored['_posts'])) {
    $stored['posts'] = $stored['_posts'];
  }
  if (!isset($stored['pages']) && isset($stored['_pages']) && is_array($stored['_pages'])) {
    $stored['pages'] = $stored['_pages'];
  }
  if (!isset($stored['users']) && isset($stored['_users']) && is_array($stored['_users'])) {
    $stored['users'] = $stored['_users'];
  }

  if (!isset($stored['license_id'])) {
    $stored['license_id'] = $license_info['id'];
  }
  if (!isset($stored['license_key'])) {
    $stored['license_key'] = $license_key;
  }
  if (!isset($stored['client_name']) && !empty($license_info['record']['client'])) {
    $stored['client_name'] = $license_info['record']['client'];
  }

  if (!isset($stored['home_url'])) {
    $stored['home_url'] = $stored['site']['home_url'] ?? ($license_info['record']['site'] ?? '');
  }
  if (!isset($stored['https'])) {
    if (isset($stored['site']['https'])) {
      $stored['https'] = (bool) $stored['site']['https'];
    } elseif (!empty($stored['home_url'])) {
      $stored['https'] = stripos($stored['home_url'], 'https://') === 0;
    }
  }

  if (!isset($stored['counts']) || !is_array($stored['counts'])) {
    $stored['counts'] = [];
  }
  $stored['counts']['posts'] = isset($stored['content']['posts_total']) ? (int) $stored['content']['posts_total'] : (is_array($stored['posts'] ?? null) ? count($stored['posts']) : 0);
  $stored['counts']['pages'] = isset($stored['content']['pages_total']) ? (int) $stored['content']['pages_total'] : (is_array($stored['pages'] ?? null) ? count($stored['pages']) : 0);
  $stored['counts']['users'] = isset($stored['users_total']) ? (int) $stored['users_total'] : (is_array($stored['users'] ?? null) ? count($stored['users']) : 0);
  $stored['counts']['plugins'] = is_array($stored['plugins'] ?? null) ? count($stored['plugins']) : 0;

  if (!isset($stored['profile_last_synced'])) {
    $stored['profile_last_synced'] = current_time('mysql');
  }

  return $stored;
}

/* =========================================================================
 * VL Hub profile endpoint
 * ========================================================================= */

add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/profile', [
    'methods' => ['GET', 'POST'],
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }

      if ($req->get_method() === 'POST') {
        $license_info = vl_hub_find_license_record($license);
        if (!$license_info) {
          return new WP_REST_Response(['error' => 'License not found'], 404);
        }

        $payload = $req->get_json_params();
        if (!is_array($payload)) {
          return new WP_REST_Response(['error' => 'Invalid profile payload'], 400);
        }

        $profiles = get_option('vl_hub_profiles', []);
        $current  = isset($profiles[$license_info['id']]) && is_array($profiles[$license_info['id']]) ? $profiles[$license_info['id']] : [];
        $profiles[$license_info['id']] = array_merge($current, $payload, [
          'license_id'          => $license_info['id'],
          'license_key'         => $license,
          'profile_last_synced' => current_time('mysql'),
        ]);
        update_option('vl_hub_profiles', $profiles);

        return new WP_REST_Response(['ok' => true, 'message' => 'Profile stored'], 200);
      }

      $profile = vl_hub_profile_resolve($license, ['force_refresh' => (bool) $req->get_param('refresh')]);
      if (is_wp_error($profile)) {
        $status = (int) ($profile->get_error_data('status') ?? 500);
        return new WP_REST_Response(['error' => $profile->get_error_message()], $status);
      }

      return new WP_REST_Response($profile, 200);
    },
  ]);
});

/**
 * Session start endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/chat/session-start', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $data = $req->get_json_params();
      if (!$data || !isset($data['session_id'])) {
        return new WP_REST_Response(['error' => 'Session ID required'], 400);
      }
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Store session start data
      $session_starts = get_option('vl_hub_session_starts', []);
      if (!isset($session_starts[$license_id])) {
        $session_starts[$license_id] = [];
      }
      
      $session_starts[$license_id][] = [
        'session_id' => $data['session_id'],
        'started_at' => $data['started_at'] ?? current_time('mysql'),
        'timestamp' => time()
      ];
      
      update_option('vl_hub_session_starts', $session_starts);
      
      error_log('[Luna Hub] Session started for license_id: ' . $license_id . ', session: ' . $data['session_id']);
      
      return new WP_REST_Response(['ok' => true, 'message' => 'Session start recorded'], 200);
    }
  ]);
});

/**
 * Session end endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/chat/session-end', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $data = $req->get_json_params();
      if (!$data || !isset($data['session_id'])) {
        return new WP_REST_Response(['error' => 'Session ID required'], 400);
      }
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Store session end data
      $session_ends = get_option('vl_hub_session_ends', []);
      if (!isset($session_ends[$license_id])) {
        $session_ends[$license_id] = [];
      }
      
      $session_ends[$license_id][] = [
        'session_id' => $data['session_id'],
        'reason' => $data['reason'] ?? 'unknown',
        'ended_at' => $data['ended_at'] ?? current_time('mysql'),
        'timestamp' => time()
      ];
      
      update_option('vl_hub_session_ends', $session_ends);
      
      error_log('[Luna Hub] Session ended for license_id: ' . $license_id . ', session: ' . $data['session_id'] . ', reason: ' . ($data['reason'] ?? 'unknown'));
      
      return new WP_REST_Response(['ok' => true, 'message' => 'Session end recorded'], 200);
    }
  ]);
});

/**
 * Conversation logging endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/conversations/log', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $conversation_data = $req->get_json_params();
      if (!$conversation_data) {
        return new WP_REST_Response(['error' => 'Invalid conversation data'], 400);
      }
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Store conversation data
      $conversations = get_option('vl_hub_conversations', []);
      if (!isset($conversations[$license_id])) {
        $conversations[$license_id] = [];
      }
      
      $conversations[$license_id][] = [
        'id' => $conversation_data['id'] ?? 'conv_' . uniqid('', true),
        'started_at' => $conversation_data['started_at'] ?? current_time('mysql'),
        'transcript' => $conversation_data['transcript'] ?? [],
        'received_at' => current_time('mysql'),
        'timestamp' => time()
      ];
      
      update_option('vl_hub_conversations', $conversations);
      
      error_log('[Luna Hub] Conversation logged for license_id: ' . $license_id . ', conv_id: ' . ($conversation_data['id'] ?? 'unknown'));

      return new WP_REST_Response(['ok' => true, 'message' => 'Conversation logged'], 200);
    }
  ]);
});

/* =========================================================================
 * AI Constellation dataset endpoint
 * ========================================================================= */

add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/constellation', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => 'vl_rest_constellation_dataset',
    'args'     => [
      'license' => [
        'type' => 'string',
        'required' => false,
      ],
    ],
  ]);
});

/**
 * Build a constellation dataset representing Hub + widget telemetry.
 */
function vl_rest_constellation_dataset(WP_REST_Request $req): WP_REST_Response {
  $license_filter = trim((string)$req->get_param('license'));
  $data = vl_constellation_build_dataset($license_filter);
  return new WP_REST_Response($data, 200);
}

/**
 * Assemble constellation data for all licenses or a single filtered license.
 */
function vl_constellation_build_dataset(string $license_filter = ''): array {
  $licenses      = get_option('vl_licenses_registry', []);
  $profiles      = get_option('vl_hub_profiles', []);
  $conversations = get_option('vl_hub_conversations', []);
  $session_starts = get_option('vl_hub_session_starts', []);
  $session_ends   = get_option('vl_hub_session_ends', []);
  $connections    = get_option('vl_client_connections', []);

  $clients = [];
  foreach ($licenses as $license_id => $row) {
    if ($license_filter !== '') {
      $matches = false;
      if (stripos($license_id, $license_filter) !== false) {
        $matches = true;
      } elseif (!empty($row['key']) && stripos((string)$row['key'], $license_filter) !== false) {
        $matches = true;
      } elseif (!empty($row['client']) && stripos((string)$row['client'], $license_filter) !== false) {
        $matches = true;
      }
      if (!$matches) {
        continue;
      }
    }

    $profile   = is_array($profiles[$license_id] ?? null) ? $profiles[$license_id] : [];
    $client_ds = vl_constellation_build_client(
      (string)$license_id,
      is_array($row) ? $row : [],
      $profile,
      is_array($conversations[$license_id] ?? null) ? $conversations[$license_id] : [],
      is_array($session_starts[$license_id] ?? null) ? $session_starts[$license_id] : [],
      is_array($session_ends[$license_id] ?? null) ? $session_ends[$license_id] : [],
      is_array($connections[$license_id] ?? null) ? $connections[$license_id] : []
    );

    $clients[] = $client_ds;
  }

  usort($clients, function ($a, $b) {
    return strcasecmp($a['client'], $b['client']);
  });

  return [
    'generated_at'  => current_time('mysql'),
    'total_clients' => count($clients),
    'clients'       => $clients,
  ];
}

/**
 * Build the constellation node map for an individual client license.
 */
function vl_constellation_build_client(string $license_id, array $license_row, array $profile, array $conversations, array $session_starts, array $session_ends, array $connections): array {
  $palette = [
    'identity'       => '#7ee787',
    'infrastructure' => '#58a6ff',
    'security'       => '#f85149',
    'content'        => '#f2cc60',
    'plugins'        => '#d2a8ff',
    'themes'         => '#8b949e',
    'users'          => '#79c0ff',
    'ai'             => '#bc8cff',
    'sessions'       => '#56d364',
    'integrations'   => '#ffa657',
  ];

  $icons = [
    'identity'       => 'visiblelightailogoonly.svg',
    'infrastructure' => 'arrows-rotate-reverse-regular-full.svg',
    'security'       => 'eye-slash-light-full.svg',
    'content'        => 'play-regular-full.svg',
    'plugins'        => 'plus-solid-full.svg',
    'themes'         => 'visiblelightailogo.svg',
    'users'          => 'eye-regular-full.svg',
    'ai'             => 'visiblelightailogo.svg',
    'sessions'       => 'arrows-rotate-reverse-regular-full.svg',
    'integrations'   => 'minus-solid-full.svg',
  ];

  $client = [
    'license_id'   => $license_id,
    'license_key'  => vl_constellation_redact_key($license_row['key'] ?? ''),
    'client'       => vl_constellation_string($license_row['client'] ?? 'Unassigned Client'),
    'site'         => vl_constellation_string($license_row['site'] ?? ''),
    'active'       => !empty($license_row['active']),
    'created'      => vl_constellation_date($license_row['created'] ?? 0),
    'last_seen'    => vl_constellation_date($license_row['last_seen'] ?? 0),
    'categories'   => [],
  ];

  $client['categories'][] = vl_constellation_identity_category($palette['identity'], $icons['identity'], $license_row, $profile);
  $client['categories'][] = vl_constellation_infrastructure_category($palette['infrastructure'], $icons['infrastructure'], $license_row, $profile);
  $client['categories'][] = vl_constellation_security_category($palette['security'], $icons['security'], $profile);
  $client['categories'][] = vl_constellation_content_category($palette['content'], $icons['content'], $profile);
  $client['categories'][] = vl_constellation_plugins_category($palette['plugins'], $icons['plugins'], $profile);
  $client['categories'][] = vl_constellation_theme_category($palette['themes'], $icons['themes'], $profile);
  $client['categories'][] = vl_constellation_users_category($palette['users'], $icons['users'], $profile);
  $client['categories'][] = vl_constellation_ai_category($palette['ai'], $icons['ai'], $conversations);
  $client['categories'][] = vl_constellation_sessions_category($palette['sessions'], $icons['sessions'], $session_starts, $session_ends);
  $client['categories'][] = vl_constellation_integrations_category($palette['integrations'], $icons['integrations'], $connections);

  return $client;
}

function vl_constellation_identity_category(string $color, string $icon, array $license_row, array $profile): array {
  $nodes = [];
  $nodes[] = vl_constellation_node('client', 'Client', $color, 6, vl_constellation_string($license_row['client'] ?? 'Unassigned'));
  $nodes[] = vl_constellation_node('site', 'Primary Site', $color, 6, vl_constellation_string($license_row['site'] ?? ($profile['site'] ?? 'Unknown')));
  $nodes[] = vl_constellation_node('status', 'License Status', $color, !empty($license_row['active']) ? 8 : 4, !empty($license_row['active']) ? 'Active' : 'Inactive');
  $nodes[] = vl_constellation_node('heartbeat', 'Last Heartbeat', $color, 5, vl_constellation_time_ago($license_row['last_seen'] ?? 0));
  if (!empty($license_row['plugin_version'])) {
    $nodes[] = vl_constellation_node('widget_version', 'Widget Version', $color, 5, 'v' . vl_constellation_string($license_row['plugin_version']));
  } elseif (!empty($profile['wordpress']['version'])) {
    $nodes[] = vl_constellation_node('wordpress_version', 'WordPress Version', $color, 4, 'v' . vl_constellation_string($profile['wordpress']['version']));
  }

  return vl_constellation_category('identity', 'Identity & Licensing', $color, $icon, $nodes);
}

function vl_constellation_infrastructure_category(string $color, string $icon, array $license_row, array $profile): array {
  $nodes = [];
  $https = isset($profile['https']) ? (bool)$profile['https'] : null;
  $nodes[] = vl_constellation_node('https', 'HTTPS', $color, $https ? 7 : 4, $https === null ? 'Unknown' : ($https ? 'Secured' : 'Not secure'));

  $wp_version = $profile['wordpress']['version'] ?? ($license_row['wp_version'] ?? '');
  if ($wp_version) {
    $nodes[] = vl_constellation_node('wp_version', 'WordPress Core', $color, 5, 'v' . vl_constellation_string($wp_version));
  }

  $theme_name = $profile['wordpress']['theme']['name'] ?? '';
  if ($theme_name) {
    $nodes[] = vl_constellation_node('theme', 'Active Theme', $color, 5, vl_constellation_string($theme_name));
  }

  $plugin_count = is_array($profile['plugins'] ?? null) ? count($profile['plugins']) : 0;
  if ($plugin_count) {
    $nodes[] = vl_constellation_node('plugin_count', 'Plugins Installed', $color, min(10, max(3, $plugin_count)), $plugin_count . ' plugins');
  }

  $connections = is_array($profile['connections'] ?? null) ? $profile['connections'] : [];
  if ($connections) {
    $nodes[] = vl_constellation_node('connections', 'Remote Connections', $color, min(10, count($connections) + 3), count($connections) . ' integrations');
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('infrastructure_placeholder', 'Infrastructure', $color, 3, 'Awaiting telemetry');
  }

  return vl_constellation_category('infrastructure', 'Infrastructure & Platform', $color, $icon, $nodes);
}

function vl_constellation_security_category(string $color, string $icon, array $profile): array {
  $nodes = [];
  $security = is_array($profile['security'] ?? null) ? $profile['security'] : [];
  if ($security) {
    foreach (vl_constellation_flatten_security($security) as $row) {
      $nodes[] = vl_constellation_node($row['id'], $row['label'], $color, $row['value'], $row['detail']);
    }
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('security_placeholder', 'Security Signals', $color, 3, 'No security data reported');
  }

  return vl_constellation_category('security', 'Security & Compliance', $color, $icon, $nodes);
}

function vl_constellation_content_category(string $color, string $icon, array $profile): array {
  $nodes = [];
  $posts = is_array($profile['_posts'] ?? null) ? count($profile['_posts']) : (is_array($profile['posts'] ?? null) ? count($profile['posts']) : 0);
  $pages = is_array($profile['_pages'] ?? null) ? count($profile['_pages']) : 0;
  $media = is_array($profile['content']['media'] ?? null) ? count($profile['content']['media']) : 0;

  if ($posts) {
    $nodes[] = vl_constellation_node('posts', 'Published Posts', $color, min(10, max(3, $posts)), $posts . ' posts');
  }
  if ($pages) {
    $nodes[] = vl_constellation_node('pages', 'Published Pages', $color, min(9, max(3, $pages)), $pages . ' pages');
  }
  if ($media) {
    $nodes[] = vl_constellation_node('media', 'Media Items', $color, min(8, max(3, $media)), $media . ' assets');
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('content_placeholder', 'Content Footprint', $color, 3, 'Content metrics not synced yet');
  }

  return vl_constellation_category('content', 'Content Universe', $color, $icon, $nodes);
}

function vl_constellation_plugins_category(string $color, string $icon, array $profile): array {
  $nodes = [];
  $plugins = is_array($profile['plugins'] ?? null) ? $profile['plugins'] : [];

  $active = 0;
  foreach ($plugins as $plugin) {
    if (is_array($plugin) && !empty($plugin['is_active'])) {
      $active++;
    } elseif (is_array($plugin) && isset($plugin['status']) && stripos((string)$plugin['status'], 'active') !== false) {
      $active++;
    }
  }

  if ($plugins) {
    $nodes[] = vl_constellation_node('plugins_total', 'Installed Plugins', $color, min(10, max(3, count($plugins))), count($plugins) . ' total');
    $nodes[] = vl_constellation_node('plugins_active', 'Active Plugins', $color, min(10, max(3, $active)), $active . ' active');

    $top = array_slice($plugins, 0, 5);
    foreach ($top as $index => $plugin) {
      $label = vl_constellation_string($plugin['name'] ?? ($plugin['Name'] ?? 'Plugin ' . ($index + 1)));
      $version = vl_constellation_string($plugin['version'] ?? ($plugin['Version'] ?? ''));
      $detail = $version ? 'v' . $version : 'Version unknown';
      $nodes[] = vl_constellation_node('plugin_' . $index, $label, $color, 4, $detail);
    }
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('plugins_placeholder', 'Plugins', $color, 3, 'Plugins not reported');
  }

  return vl_constellation_category('plugins', 'Plugin Ecosystem', $color, $icon, $nodes);
}

function vl_constellation_theme_category(string $color, string $icon, array $profile): array {
  $nodes = [];
  $theme = is_array($profile['wordpress']['theme'] ?? null) ? $profile['wordpress']['theme'] : [];
  if ($theme) {
    $nodes[] = vl_constellation_node('theme_name', 'Theme Name', $color, 6, vl_constellation_string($theme['name'] ?? 'Theme'));
    if (!empty($theme['version'])) {
      $nodes[] = vl_constellation_node('theme_version', 'Theme Version', $color, 4, 'v' . vl_constellation_string($theme['version']));
    }
    $nodes[] = vl_constellation_node('theme_status', 'Active', $color, !empty($theme['is_active']) ? 6 : 3, !empty($theme['is_active']) ? 'Active' : 'Inactive');
  }

  $themes = is_array($profile['themes'] ?? null) ? $profile['themes'] : [];
  if ($themes) {
    $nodes[] = vl_constellation_node('themes_total', 'Available Themes', $color, min(8, max(3, count($themes))), count($themes) . ' themes');
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('themes_placeholder', 'Themes', $color, 3, 'Theme data not synced');
  }

  return vl_constellation_category('themes', 'Theme & Experience', $color, $icon, $nodes);
}

function vl_constellation_users_category(string $color, string $icon, array $profile): array {
  $nodes = [];
  $users = is_array($profile['users'] ?? null) ? $profile['users'] : (is_array($profile['_users'] ?? null) ? $profile['_users'] : []);

  if ($users) {
    $nodes[] = vl_constellation_node('users_total', 'User Accounts', $color, min(9, max(3, count($users))), count($users) . ' users');
    $roles = [];
    foreach ($users as $user) {
      if (!is_array($user)) continue;
      $role = $user['role'] ?? ($user['roles'][0] ?? 'user');
      $role = is_array($role) ? ($role[0] ?? 'user') : $role;
      $role = strtolower((string)$role);
      $roles[$role] = ($roles[$role] ?? 0) + 1;
    }
    arsort($roles);
    foreach (array_slice($roles, 0, 4, true) as $role => $count) {
      $nodes[] = vl_constellation_node('role_' . preg_replace('/[^a-z0-9]/', '_', $role), ucwords(str_replace('_', ' ', $role)), $color, min(8, max(3, $count + 3)), $count . ' users');
    }
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('users_placeholder', 'Users', $color, 3, 'User roster not available');
  }

  return vl_constellation_category('users', 'User Accounts & Roles', $color, $icon, $nodes);
}

function vl_constellation_ai_category(string $color, string $icon, array $conversations): array {
  $nodes = [];

  $conversation_count = count($conversations);
  if ($conversation_count) {
    $nodes[] = vl_constellation_node('conversations_total', 'Conversations', $color, min(10, max(4, $conversation_count + 3)), $conversation_count . ' logged');

    $messages = 0;
    $last = 0;
    foreach ($conversations as $conversation) {
      if (!is_array($conversation)) continue;
      $messages += is_array($conversation['transcript'] ?? null) ? count($conversation['transcript']) : 0;
      $ended = $conversation['timestamp'] ?? ($conversation['received_at'] ?? 0);
      if ($ended > $last) $last = (int)$ended;
    }
    if ($messages) {
      $nodes[] = vl_constellation_node('messages', 'Messages', $color, min(9, max(3, $messages / 2)), $messages . ' exchanges');
    }
    if ($last) {
      $nodes[] = vl_constellation_node('last_conversation', 'Last Conversation', $color, 6, vl_constellation_time_ago($last));
    }
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('conversations_placeholder', 'AI Chats', $color, 3, 'No conversations logged');
  }

  return vl_constellation_category('ai', 'AI Conversations', $color, $icon, $nodes);
}

function vl_constellation_sessions_category(string $color, string $icon, array $session_starts, array $session_ends): array {
  $nodes = [];
  $start_count = count($session_starts);
  $end_count   = count($session_ends);

  if ($start_count) {
    $nodes[] = vl_constellation_node('sessions_started', 'Sessions Started', $color, min(9, max(3, $start_count + 2)), $start_count . ' sessions');
  }
  if ($end_count) {
    $nodes[] = vl_constellation_node('sessions_closed', 'Sessions Closed', $color, min(9, max(3, $end_count + 2)), $end_count . ' sessions');
  }

  $timeouts = 0;
  $last_end = 0;
  foreach ($session_ends as $session) {
    if (!is_array($session)) continue;
    $reason = strtolower((string)($session['reason'] ?? ''));
    if (strpos($reason, 'timeout') !== false || strpos($reason, 'inactive') !== false) {
      $timeouts++;
    }
    $ended = $session['timestamp'] ?? ($session['ended_at'] ?? 0);
    if ($ended > $last_end) $last_end = (int)$ended;
  }

  if ($timeouts) {
    $nodes[] = vl_constellation_node('session_timeouts', 'Inactive Closures', $color, min(8, max(3, $timeouts + 2)), $timeouts . ' auto-closed');
  }
  if ($last_end) {
    $nodes[] = vl_constellation_node('last_session', 'Last Session', $color, 5, vl_constellation_time_ago($last_end));
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('sessions_placeholder', 'Sessions', $color, 3, 'No session telemetry yet');
  }

  return vl_constellation_category('sessions', 'Sessions & Engagement', $color, $icon, $nodes);
}

function vl_constellation_integrations_category(string $color, string $icon, array $connections): array {
  $nodes = [];
  if ($connections) {
    $nodes[] = vl_constellation_node('integrations_total', 'Integrations', $color, min(9, max(3, count($connections) + 2)), count($connections) . ' connected');
    $index = 0;
    foreach ($connections as $key => $row) {
      if ($index >= 5) break;
      if (is_array($row)) {
        $provider = $row['provider'] ?? ($row['name'] ?? $key);
        $status = !empty($row['status']) ? vl_constellation_string($row['status']) : (!empty($row['connected']) ? 'Connected' : 'Unknown');
      } else {
        $provider = $key;
        $status = is_scalar($row) ? (string)$row : 'Available';
      }
      $nodes[] = vl_constellation_node('integration_' . $index, vl_constellation_string((string)$provider), $color, 4, $status);
      $index++;
    }
  }

  if (!$nodes) {
    $nodes[] = vl_constellation_node('integrations_placeholder', 'Cloud Integrations', $color, 3, 'No connections synced');
  }

  return vl_constellation_category('integrations', 'Integrations & Signals', $color, $icon, $nodes);
}

function vl_constellation_category(string $slug, string $label, string $color, string $icon, array $nodes): array {
  return [
    'slug'  => $slug,
    'name'  => $label,
    'color' => $color,
    'icon'  => $icon,
    'nodes' => array_values($nodes),
  ];
}

function vl_constellation_node(string $id, string $label, string $color, int $value, string $detail): array {
  return [
    'id'     => $id,
    'label'  => $label,
    'color'  => $color,
    'value'  => max(1, $value),
    'detail' => $detail,
  ];
}

function vl_constellation_flatten_security(array $security): array {
  $nodes = [];
  $index = 0;

  $walker = function ($prefix, $value) use (&$nodes, &$walker, &$index) {
    if (is_array($value)) {
      foreach ($value as $key => $child) {
        $walker(trim($prefix . ' ' . vl_constellation_human_label((string)$key)), $child);
      }
      return;
    }

    $label = trim($prefix);
    if ($label === '') {
      $label = 'Security Signal';
    }

    $detail = '';
    $score  = 4;

    if (is_bool($value)) {
      $detail = $value ? 'Enabled' : 'Disabled';
      $score = $value ? 7 : 3;
    } elseif (is_numeric($value)) {
      $detail = (string)$value;
      $score = (int)max(3, min(10, abs((float)$value) + 3));
    } elseif (is_string($value)) {
      $detail = trim($value) === '' ? 'Unavailable' : vl_constellation_string($value);
      $score = 4;
    } else {
      $detail = 'Reported';
    }

    $nodes[] = [
      'id'    => 'security_' . $index++,
      'label' => $label,
      'value' => $score,
      'detail'=> $detail,
    ];
  };

  $walker('', $security);

  return $nodes;
}

function vl_constellation_time_ago($timestamp): string {
  $timestamp = is_numeric($timestamp) ? (int)$timestamp : strtotime((string)$timestamp);
  if (!$timestamp) {
    return 'No activity recorded';
  }
  $diff = time() - $timestamp;
  if ($diff < 0) $diff = 0;

  $units = [
    ['year', 365*24*3600],
    ['month', 30*24*3600],
    ['day', 24*3600],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
  ];

  foreach ($units as [$name, $secs]) {
    if ($diff >= $secs) {
      $value = (int)floor($diff / $secs);
      return $value . ' ' . $name . ($value === 1 ? '' : 's') . ' ago';
    }
  }

  return 'Just now';
}

function vl_constellation_date($timestamp): string {
  if (empty($timestamp)) {
    return '';
  }
  if (is_numeric($timestamp)) {
    return date('c', (int)$timestamp);
  }
  $parsed = strtotime((string)$timestamp);
  return $parsed ? date('c', $parsed) : '';
}

function vl_constellation_string($value): string {
  return trim(wp_strip_all_tags((string)$value));
}

function vl_constellation_redact_key(string $key): string {
  $key = trim($key);
  if ($key === '') {
    return '';
  }
  if (strlen($key) <= 6) {
    return str_repeat('•', strlen($key));
  }
  return substr($key, 0, 4) . '…' . substr($key, -4);
}

function vl_constellation_human_label(string $key): string {
  $key = trim($key);
  if ($key === '') return 'Item';
  $key = str_replace(['_', '-'], ' ', $key);
  return ucwords(preg_replace('/\s+/', ' ', $key));
}

/**
 * Field validation endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/validate/field', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      $field = $req->get_param('field');
      if (!$field) {
        return new WP_REST_Response(['error' => 'Field name required'], 400);
      }
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Get client profile data
      $profiles = get_option('vl_hub_profiles', []);
      $profile = $profiles[$license_id] ?? [];
      
      // Validate the specific field
      $validation_result = vl_validate_field_mapping($profile, $field);
      
      return new WP_REST_Response([
        'field' => $field,
        'valid' => $validation_result['valid'],
        'value' => $validation_result['value'],
        'error' => $validation_result['error'] ?? null,
        'timestamp' => current_time('mysql')
      ], 200);
    }
  ]);
});

/**
 * Validate all fields endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/validate/all', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $license = $req->get_header('X-Luna-License') ?: $req->get_param('license');
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      // Find the license ID from the license key
      $licenses = get_option('vl_licenses_registry', []);
      $license_id = null;
      foreach ($licenses as $id => $lic) {
        if ($lic['key'] === $license) {
          $license_id = $id;
          break;
        }
      }
      
      if (!$license_id) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      // Get client profile data
      $profiles = get_option('vl_hub_profiles', []);
      $profile = $profiles[$license_id] ?? [];
      
      // Validate all fields
      $all_fields = [
        'tls_status', 'tls_version', 'tls_issuer', 'tls_provider_guess',
        'tls_valid_from', 'tls_valid_to', 'tls_host',
        'waf_provider', 'waf_last_audit',
        'ids_provider', 'ids_last_scan', 'ids_result', 'ids_schedule',
        'auth_mfa', 'auth_password_policy', 'auth_session_timeout', 'auth_sso_providers',
        'domain_registrar', 'domain_registered_on', 'domain_renewal_date', 'domain_auto_renew', 'domain_dns_records'
      ];
      
      $results = [];
      foreach ($all_fields as $field) {
        $results[$field] = vl_validate_field_mapping($profile, $field);
      }
      
      return new WP_REST_Response([
        'license_id' => $license_id,
        'validations' => $results,
        'timestamp' => current_time('mysql')
      ], 200);
    }
  ]);
});

/**
 * Field validation helper function
 */
function vl_validate_field_mapping($profile, $field) {
  $security = $profile['security'] ?? [];
  
  switch ($field) {
    case 'tls_status':
      $value = $security['tls']['status'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS status not found' : null
      ];
      
    case 'tls_version':
      $value = $security['tls']['version'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS version not found' : null
      ];
      
    case 'tls_issuer':
      $value = $security['tls']['issuer'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS issuer not found' : null
      ];
      
    case 'tls_provider_guess':
      $value = $security['tls']['provider_guess'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS provider guess not found' : null
      ];
      
    case 'tls_valid_from':
      $value = $security['tls']['valid_from'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS valid from date not found' : null
      ];
      
    case 'tls_valid_to':
      $value = $security['tls']['valid_to'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS valid to date not found' : null
      ];
      
    case 'tls_host':
      $value = $security['tls']['host'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'TLS host not found' : null
      ];
      
    case 'waf_provider':
      $value = $security['waf']['provider'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'WAF provider not found' : null
      ];
      
    case 'waf_last_audit':
      $value = $security['waf']['last_audit'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'WAF last audit not found' : null
      ];
      
    case 'ids_provider':
      $value = $security['ids']['provider'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'IDS provider not found' : null
      ];
      
    case 'ids_last_scan':
      $value = $security['ids']['last_scan'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'IDS last scan not found' : null
      ];
      
    case 'ids_result':
      $value = $security['ids']['result'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'IDS result not found' : null
      ];
      
    case 'ids_schedule':
      $value = $security['ids']['schedule'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'IDS schedule not found' : null
      ];
      
    case 'auth_mfa':
      $value = $security['auth']['mfa'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'MFA not found' : null
      ];
      
    case 'auth_password_policy':
      $value = $security['auth']['password_policy'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Password policy not found' : null
      ];
      
    case 'auth_session_timeout':
      $value = $security['auth']['session_timeout'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Session timeout not found' : null
      ];
      
    case 'auth_sso_providers':
      $value = $security['auth']['sso_providers'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'SSO providers not found' : null
      ];
      
    case 'domain_registrar':
      $value = $security['domain']['registrar'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Domain registrar not found' : null
      ];
      
    case 'domain_registered_on':
      $value = $security['domain']['registered_on'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Domain registration date not found' : null
      ];
      
    case 'domain_renewal_date':
      $value = $security['domain']['renewal_date'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Domain renewal date not found' : null
      ];
      
    case 'domain_auto_renew':
      $value = $security['domain']['auto_renew'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'Domain auto-renewal setting not found' : null
      ];
      
    case 'domain_dns_records':
      $value = $security['domain']['dns_records'] ?? '';
      return [
        'valid' => !empty($value),
        'value' => $value,
        'error' => empty($value) ? 'DNS records not found' : null
      ];
      
    default:
  return [
        'valid' => false,
        'value' => '',
        'error' => 'Unknown field: ' . $field
      ];
  }
}

/**
 * Test endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('luna_widget/v1', '/test', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      return new WP_REST_Response([
        'status' => 'ok',
        'message' => 'Luna Hub test endpoint working',
        'license' => $req->get_param('license') ?: 'none',
      ], 200);
    }
  ]);
});

/* =========================================================================
 * VL Client Authentication Endpoints
 * ========================================================================= */

/**
 * Client authentication check endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/auth-check', [
    'methods' => 'GET',
    'callback' => 'vl_check_client_auth',
    'permission_callback' => 'vl_check_client_auth_permissions'
  ]);
});

/**
 * Client data endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/client-data', [
    'methods' => 'GET',
    'callback' => 'vl_get_client_data',
    'permission_callback' => 'vl_check_client_auth_permissions'
  ]);
});

/**
 * Client list endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/clients', [
    'methods' => 'GET',
    'callback' => 'vl_get_clients_for_supercluster',
    'permission_callback' => 'vl_check_client_permissions_supercluster'
  ]);
});

/**
 * SOC 2 snapshot endpoint for VL LAS plugin
 */
add_action('rest_api_init', function () {
  register_rest_route('vl-hub/v1', '/soc2/snapshot', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      // Accept both X-VL-License (VL LAS) and X-Luna-License (Luna Widget) headers
      $license = $req->get_header('X-VL-License') ?: $req->get_header('X-Luna-License') ?: $req->get_param('license');
      
      if (!$license) {
        return new WP_REST_Response(['error' => 'License required'], 401);
      }
      
      // Find license record
      $license_info = vl_hub_find_license_record($license);
      if (!$license_info || empty($license_info['id'])) {
        return new WP_REST_Response(['error' => 'License not found'], 404);
      }
      
      $license_id = $license_info['id'];
      
      // Get stored SOC 2 snapshot data for this license
      $soc2_snapshots = get_option('vl_hub_soc2_snapshots', []);
      $snapshot = isset($soc2_snapshots[$license_id]) ? $soc2_snapshots[$license_id] : null;
      
      // If no stored snapshot, return a default structure
      if (!$snapshot || !is_array($snapshot)) {
        // Return a basic snapshot structure
        $snapshot = [
          'license_id' => $license_id,
          'license_key' => $license,
          'timestamp' => current_time('mysql'),
          'controls' => [],
          'evidence' => [],
          'risks' => [],
          'compliance_status' => 'pending',
          'last_updated' => current_time('mysql'),
        ];
      }
      
      // Ensure required fields are present
      $snapshot['license_id'] = $license_id;
      $snapshot['license_key'] = $license;
      $snapshot['timestamp'] = current_time('mysql');
      
      return new WP_REST_Response($snapshot, 200);
    },
  ]);
});

// Authentication check function for clients
function vl_check_client_auth($request) {
    if (!is_user_logged_in()) {
        return new WP_Error('not_authenticated', 'User not logged in', array('status' => 401));
    }
    
    $user = wp_get_current_user();
    $license_key = get_user_meta($user->ID, 'vl_license_key', true);
    $client_name = get_user_meta($user->ID, 'vl_client_name', true);
    
    return array(
        'success' => true,
        'user_id' => $user->ID,
        'username' => $user->user_login,
        'license_key' => $license_key,
        'client_name' => $client_name,
        'is_vl_client' => !empty($license_key)
    );
}

// Client data function
function vl_get_client_data($request) {
    if (!is_user_logged_in()) {
        return new WP_Error('not_authenticated', 'User not logged in', array('status' => 401));
    }
    
    $user = wp_get_current_user();
    $license_key = get_user_meta($user->ID, 'vl_license_key', true);
    $client_name = get_user_meta($user->ID, 'vl_client_name', true);
    
    if (empty($license_key)) {
        return new WP_Error('no_license', 'User is not a VL client', array('status' => 403));
    }
    
    $client_config = isset($client_configs[$license_key]) ? $client_configs[$license_key] : null;
    
    return array(
        'success' => true,
        'user_id' => $user->ID,
        'username' => $user->user_login,
        'license_key' => $license_key,
        'client_name' => $client_name,
        'client_config' => $client_config
    );
}

// Permission callbacks
function vl_check_client_auth_permissions($request) {
    return is_user_logged_in();
}

function vl_check_client_permissions_supercluster($request) {
    return true; // Allow public access for now
}

add_action('rest_api_init', function () {
  register_rest_route('luna_compose/v1', '/respond', [
    'methods'             => \WP_REST_Server::CREATABLE,
    'permission_callback' => '__return_true',
    'callback'            => 'vl_luna_compose_rest_respond',
    'args'                => [
      'prompt'  => [
        'type'     => 'string',
        'required' => true,
      ],
      'client'  => [
        'type'     => 'string',
        'required' => false,
      ],
      'refresh' => [
        'type'     => 'boolean',
        'required' => false,
      ],
    ],
  ]);
});

/* =========================================================================
 * VL Domain Ranking (VLDR) Integration for Luna Chat
 * ========================================================================= */

/**
 * Get VLDR score from Hub with caching to avoid chat-storming.
 * 
 * @param string $license_key The license key
 * @param string $domain The domain to check
 * @return array|null VLDR data or null on failure
 */
function luna_vldr_get_score_from_hub(string $license_key, string $domain): ?array {
  if (empty($license_key) || empty($domain)) {
    return null;
  }

  // Clean domain (remove protocol, www, trailing slashes)
  $domain = preg_replace('/^https?:\/\//', '', $domain);
  $domain = preg_replace('/^www\./', '', $domain);
  $domain = rtrim($domain, '/');
  $domain = strtolower($domain);

  // Cache key with 30-minute TTL
  $cache_key = 'luna_vldr_' . md5($license_key . '|' . $domain);
  $cached = get_transient($cache_key);
  if ($cached !== false && is_array($cached)) {
    return $cached;
  }

  // Fetch from Hub REST API
  $hub_url = apply_filters('luna_hub_base_url', 'https://visiblelight.ai');
  $url = trailingslashit($hub_url) . 'wp-json/vl-hub/v1/vldr?license=' . rawurlencode($license_key) . '&domain=' . rawurlencode($domain);

  $res = wp_remote_get($url, [
    'timeout' => 15,
    'sslverify' => true,
    'headers' => [
      'Accept' => 'application/json',
    ],
  ]);

  if (is_wp_error($res)) {
    error_log('[Luna VLDR] Error fetching from Hub: ' . $res->get_error_message());
    return null;
  }

  $code = wp_remote_retrieve_response_code($res);
  if ($code !== 200) {
    error_log('[Luna VLDR] HTTP ' . $code . ' from Hub for domain: ' . $domain);
    return null;
  }

  $body = wp_remote_retrieve_body($res);
  $data = json_decode($body, true);

  if (!is_array($data)) {
    error_log('[Luna VLDR] Invalid JSON response from Hub');
    return null;
  }

  // Check for success response
  if (!empty($data['ok']) && !empty($data['data']) && is_array($data['data'])) {
    $vldr_data = $data['data'];
    
    // Cache for 30 minutes
    set_transient($cache_key, $vldr_data, 30 * MINUTE_IN_SECONDS);
    
    return $vldr_data;
  }

  // Check for direct data structure (if no wrapper)
  if (!empty($data['domain']) && isset($data['vldr_score'])) {
    set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    return $data;
  }

  return null;
}

/**
 * Enrich chat context with VLDR data for competitors.
 * 
 * This filter allows Luna to answer "What's their Domain Ranking?" questions
 * by providing VLDR data in the chat context.
 * 
 * @param array $context The chat context array
 * @return array Enriched context with VLDR data
 */
add_filter('luna_chat_context_enrich', function (array $context): array {
  $license_key = $context['license_key'] ?? '';
  if (empty($license_key)) {
    return $context;
  }

  // Extract competitor domains from various sources
  $competitors = [];

  // Try to get from context directly
  if (!empty($context['competitors']) && is_array($context['competitors'])) {
    $competitors = $context['competitors'];
  }

  // Try to get from profile data
  if (empty($competitors) && !empty($context['profile'])) {
    $profile = $context['profile'];
    
    // Check for competitor analysis data
    if (!empty($profile['competitors']) && is_array($profile['competitors'])) {
      $competitors = array_column($profile['competitors'], 'domain');
    }
    
    // Check for competitor reports
    if (!empty($profile['competitor_reports']) && is_array($profile['competitor_reports'])) {
      foreach ($profile['competitor_reports'] as $report) {
        if (!empty($report['domain']) && !in_array($report['domain'], $competitors, true)) {
          $competitors[] = $report['domain'];
        }
      }
    }
    
    // Check for competitive analysis data streams
    if (!empty($profile['data_streams']) && is_array($profile['data_streams'])) {
      foreach ($profile['data_streams'] as $stream) {
        if (!empty($stream['categories']) && in_array('competitive', $stream['categories'], true)) {
          if (!empty($stream['competitor_url'])) {
            $domain = preg_replace('/^https?:\/\//', '', $stream['competitor_url']);
            $domain = preg_replace('/^www\./', '', $domain);
            $domain = rtrim($domain, '/');
            $domain = strtolower($domain);
            if (!in_array($domain, $competitors, true)) {
              $competitors[] = $domain;
            }
          }
        }
      }
    }
  }

  // Also check the client's own domain
  $client_domain = '';
  if (!empty($context['profile']['home_url'])) {
    $client_domain = preg_replace('/^https?:\/\//', '', $context['profile']['home_url']);
    $client_domain = preg_replace('/^www\./', '', $client_domain);
    $client_domain = rtrim($client_domain, '/');
    $client_domain = strtolower($client_domain);
  }

  // Fetch VLDR data for all competitors
  $vldr_rows = [];
  
  // Always include client domain if available
  if (!empty($client_domain)) {
    $client_vldr = luna_vldr_get_score_from_hub($license_key, $client_domain);
    if ($client_vldr) {
      $vldr_rows[] = [
        'domain' => $client_vldr['domain'] ?? $client_domain,
        'vldr'   => isset($client_vldr['vldr_score']) ? (float) $client_vldr['vldr_score'] : 0.0,
        'asof'   => $client_vldr['metric_date'] ?? '',
        'ref'    => isset($client_vldr['ref_domains']) ? (int) $client_vldr['ref_domains'] : 0,
        'idx'    => isset($client_vldr['indexed_pages']) ? (int) $client_vldr['indexed_pages'] : 0,
        'lh'     => isset($client_vldr['lighthouse_avg']) ? (int) $client_vldr['lighthouse_avg'] : 0,
        'sec'    => $client_vldr['security_grade'] ?? 'N/A',
        'agey'   => isset($client_vldr['domain_age_years']) ? (float) $client_vldr['domain_age_years'] : 0.0,
        'up'     => isset($client_vldr['uptime_percent']) ? (float) $client_vldr['uptime_percent'] : 0.0,
        'is_client' => true,
      ];
    }
  }

  // Fetch VLDR for competitors
  foreach ($competitors as $domain) {
    if (empty($domain) || !is_string($domain)) {
      continue;
    }

    // Skip if already fetched (client domain)
    if ($domain === $client_domain) {
      continue;
    }

    $row = luna_vldr_get_score_from_hub($license_key, $domain);
    if ($row) {
      $vldr_rows[] = [
        'domain' => $row['domain'] ?? $domain,
        'vldr'   => isset($row['vldr_score']) ? (float) $row['vldr_score'] : 0.0,
        'asof'   => $row['metric_date'] ?? '',
        'ref'    => isset($row['ref_domains']) ? (int) $row['ref_domains'] : 0,
        'idx'    => isset($row['indexed_pages']) ? (int) $row['indexed_pages'] : 0,
        'lh'     => isset($row['lighthouse_avg']) ? (int) $row['lighthouse_avg'] : 0,
        'sec'    => $row['security_grade'] ?? 'N/A',
        'agey'   => isset($row['domain_age_years']) ? (float) $row['domain_age_years'] : 0.0,
        'up'     => isset($row['uptime_percent']) ? (float) $row['uptime_percent'] : 0.0,
        'is_client' => false,
      ];
    }
  }

  // Add VLDR data to context if available
  if (!empty($vldr_rows)) {
    $context['vldr'] = $vldr_rows;
    
    // Add human-readable summary for chat responses
    $context['vldr_summary'] = [];
    foreach ($vldr_rows as $row) {
      $summary = sprintf(
        'Domain: %s | VL-DR Score: %.1f (as of %s) | Referring domains: ~%s | Indexed pages: ~%s | Lighthouse: %d | Security: %s | Age: %.1f yrs | Uptime: %.2f%%',
        $row['domain'],
        $row['vldr'],
        $row['asof'] ? date('M j, Y', strtotime($row['asof'])) : 'N/A',
        $row['ref'] > 0 ? number_format($row['ref'] / 1000, 1) . 'k' : '0',
        $row['idx'] > 0 ? number_format($row['idx'] / 1000, 1) . 'k' : '0',
        $row['lh'],
        $row['sec'],
        $row['agey'],
        $row['up']
      );
      $context['vldr_summary'][] = $summary;
    }
    
    // Add transparency note
    $context['vldr_transparency'] = 'VL-DR (Visible Light Domain Ranking) is computed from public indicators: Common Crawl/Index, Bing Web Search, SecurityHeaders.com, WHOIS, Visible Light Uptime monitoring, and Lighthouse performance scores.';
  }

  return $context;
}, 10, 1);

/**
 * Enrich profile data with ALL VL Hub data for comprehensive context.
 * This fetches data directly from VL Hub WordPress options (since we're on the Hub).
 */
add_filter('vl_hub_profile_resolved', function (array $profile, string $license_key): array {
  if (empty($license_key)) {
    return $profile;
  }

  // Fetch ALL VL Hub data directly from WordPress options (we're on the Hub)
  // This includes SSL/TLS, Cloudflare, connections, data streams, competitor reports, etc.
  
  // 1. SSL/TLS Status
  $ssl_tls_settings = get_option('vl_ssl_tls_settings_' . $license_key, []);
  if (!empty($ssl_tls_settings) && is_array($ssl_tls_settings)) {
    $profile['ssl_tls'] = $ssl_tls_settings;
    if (!isset($profile['security'])) {
      $profile['security'] = [];
    }
    $profile['security']['ssl_tls'] = $ssl_tls_settings;
  }
  
  // 2. Cloudflare Data
  $cloudflare_settings = get_option('vl_cloudflare_settings_' . $license_key, []);
  $cloudflare_zones = get_option('vl_cloudflare_zones_' . $license_key, []);
  if (!empty($cloudflare_settings) || !empty($cloudflare_zones)) {
    $profile['cloudflare'] = [
      'connected' => !empty($cloudflare_settings['api_token']),
      'account_id' => $cloudflare_settings['account_id'] ?? '',
      'zones_count' => $cloudflare_settings['zones_count'] ?? (is_array($cloudflare_zones) ? count($cloudflare_zones) : 0),
      'zones' => $cloudflare_zones,
      'last_sync' => $cloudflare_settings['last_sync'] ?? '',
    ];
  }
  
  // 3. Client Streams
  // get_license_streams() now automatically filters removed streams and validates entries
  $client_streams = VL_License_Manager::get_license_streams($license_key);
  
  // Retroactively mark removed streams as "inactive" (for streams removed before status tracking was added)
  // This ensures previously removed streams are properly marked as inactive in the profile
  $removed = get_option('vl_removed_streams_' . $license_key, array());
  if (is_array($removed) && !empty($removed)) {
      foreach ($removed as $removed_stream_id) {
          if (isset($client_streams[$removed_stream_id])) {
              $client_streams[$removed_stream_id]['status'] = 'inactive';
              $client_streams[$removed_stream_id]['removed'] = true;
              if (!isset($client_streams[$removed_stream_id]['removed_at'])) {
                  $client_streams[$removed_stream_id]['removed_at'] = current_time('mysql');
              }
          }
      }
  }
  
  $profile['client_streams'] = $client_streams;
  
  // 4. Competitor Reports
  global $wpdb;
  $competitor_table = $wpdb->prefix . 'vl_competitor_reports';
  if ($wpdb->get_var("SHOW TABLES LIKE '$competitor_table'") === $competitor_table) {
    $competitor_reports = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $competitor_table WHERE license_key = %s ORDER BY last_scanned DESC",
      $license_key
    ), ARRAY_A);
    if (!empty($competitor_reports)) {
      $profile['competitor_reports'] = [];
      foreach ($competitor_reports as $report) {
        $report_data = json_decode($report['report_json'], true);
        if ($report_data) {
          $profile['competitor_reports'][] = [
            'competitor_url' => $report['competitor_url'],
            'last_scanned' => $report['last_scanned'],
            'report_data' => $report_data,
          ];
        }
      }
    }
  }
  
  // 5. AWS S3 Data
  $aws_s3_settings = get_option('vl_aws_s3_settings_' . $license_key, []);
  $aws_s3_data = get_option('vl_aws_s3_data_' . $license_key, []);
  if (!empty($aws_s3_settings) || !empty($aws_s3_data)) {
    $profile['aws_s3'] = [
      'connected' => !empty($aws_s3_settings['access_key_id']),
      'bucket_count' => $aws_s3_settings['bucket_count'] ?? 0,
      'object_count' => $aws_s3_settings['object_count'] ?? 0,
      'storage_used' => $aws_s3_settings['storage_used'] ?? '0 B',
      'last_sync' => $aws_s3_settings['last_sync'] ?? '',
      'buckets' => $aws_s3_data['buckets'] ?? [],
    ];
  }
  
  // 6. GA4 Data - use data streams (same method as rest_client_sites and rest_hub_profile)
  // This ensures consistency across all endpoints
  $ga4_stream = null;
  foreach ($license_streams as $stream_id => $stream_data) {
    // Skip if not an array
    if (!is_array($stream_data)) {
      continue;
    }
    
    // Check if this is a GA4 stream with metrics
    if (!empty($stream_data['ga4_metrics'])) {
      $ga4_stream = $stream_data;
      // Use first GA4 stream found (same as profile endpoint)
      break;
    }
  }
  
  // Extract GA4 data from stream if found
  if ($ga4_stream && !empty($ga4_stream['ga4_metrics'])) {
    $profile['ga4'] = [
      'connected' => true,
      'property_id' => $ga4_stream['ga4_property_id'] ?? '',
      'measurement_id' => $ga4_stream['ga4_measurement_id'] ?? '',
      'metrics' => $ga4_stream['ga4_metrics'],
      'last_sync' => $ga4_stream['ga4_last_synced'] ?? $ga4_stream['last_updated'] ?? '',
      'date_range' => $ga4_stream['ga4_date_range'] ?? [],
      'source_url' => $ga4_stream['source_url'] ?? '',
    ];
    
    // Extract GA4 dimensional data if available
    if (isset($ga4_stream['ga4_dimensions']) && is_array($ga4_stream['ga4_dimensions']) && !empty($ga4_stream['ga4_dimensions'])) {
      $profile['ga4']['dimensions'] = $ga4_stream['ga4_dimensions'];
    }
    
    // Also set ga4_metrics at top level for compatibility
    $profile['ga4_metrics'] = $ga4_stream['ga4_metrics'];
    $profile['ga4_last_synced'] = $ga4_stream['ga4_last_synced'] ?? $ga4_stream['last_updated'] ?? null;
    $profile['ga4_date_range'] = $ga4_stream['ga4_date_range'] ?? null;
    
    // Also set ga4_dimensions at top level for compatibility
    if (isset($ga4_stream['ga4_dimensions'])) {
      $profile['ga4_dimensions'] = $ga4_stream['ga4_dimensions'];
    }
  } else {
    // Fallback to old method if no stream found (for backwards compatibility)
    $ga4_settings = get_option('vl_ga4_settings_' . $license_key, []);
    $ga4_data = get_option('vl_ga4_data_' . $license_key, []);
    if (!empty($ga4_settings) || !empty($ga4_data)) {
      $profile['ga4'] = [
        'connected' => !empty($ga4_settings['property_id']),
        'property_id' => $ga4_settings['property_id'] ?? '',
        'metrics' => $ga4_data['metrics'] ?? [],
        'last_sync' => $ga4_settings['last_sync'] ?? '',
      ];
    }
  }
  
  // 7. Liquid Web Data
  $liquidweb_settings = get_option('vl_liquidweb_settings_' . $license_key, []);
  $liquidweb_assets = get_option('vl_liquidweb_assets_' . $license_key, []);
  if (!empty($liquidweb_settings) || !empty($liquidweb_assets)) {
    $profile['liquidweb'] = [
      'connected' => !empty($liquidweb_settings['account_number']),
      'assets_count' => is_array($liquidweb_assets) ? count($liquidweb_assets) : 0,
      'last_sync' => $liquidweb_settings['last_sync'] ?? '',
      'assets' => $liquidweb_assets ?? [],
    ];
  }
  
  // 8. Google Search Console Data
  $gsc_settings = get_option('vl_gsc_settings_' . $license_key, []);
  $gsc_data = get_option('vl_gsc_data_' . $license_key, []);
  if (!empty($gsc_settings) || !empty($gsc_data)) {
    $profile['gsc'] = [
      'connected' => !empty($gsc_settings['service_account_json']),
      'site_url' => $gsc_settings['site_url'] ?? '',
      'search_queries' => $gsc_data['search_queries'] ?? [],
      'last_sync' => $gsc_settings['last_sync'] ?? '',
    ];
  }
  
  // 9. Lighthouse/PageSpeed Data
  $pagespeed_settings = get_option('vl_pagespeed_settings_' . $license_key, []);
  $pagespeed_analyses = get_option('vl_pagespeed_analyses_' . $license_key, []);
  if (!empty($pagespeed_settings) || !empty($pagespeed_analyses)) {
    $profile['pagespeed'] = [
      'connected' => !empty($pagespeed_settings['url']),
      'url' => $pagespeed_settings['url'] ?? '',
      'analyses' => $pagespeed_analyses ?? [],
      'last_sync' => $pagespeed_settings['last_sync'] ?? '',
    ];
  }
  
  // 10. Connections Summary
  $connections = [
    'ssl_tls' => !empty($profile['ssl_tls']),
    'cloudflare' => !empty($profile['cloudflare']['connected']),
    'aws_s3' => !empty($profile['aws_s3']['connected']),
    'ga4' => !empty($profile['ga4']['connected']),
    'liquidweb' => !empty($profile['liquidweb']['connected']),
    'gsc' => !empty($profile['gsc']['connected']),
    'pagespeed' => !empty($profile['pagespeed']['connected']),
  ];
  $profile['connections'] = $connections;
  $profile['connections_count'] = count(array_filter($connections));

  // Also fetch from WordPress options directly as fallback/enhancement
  // Add competitor URLs from competitor settings if available
  $competitor_settings = get_option('vl_competitor_settings_' . $license_key, []);
  // Support both 'urls' (current) and 'competitor_urls' (legacy) for backwards compatibility
  $competitor_urls = [];
  if (!empty($competitor_settings['urls']) && is_array($competitor_settings['urls'])) {
    $competitor_urls = $competitor_settings['urls'];
  } elseif (!empty($competitor_settings['competitor_urls']) && is_array($competitor_settings['competitor_urls'])) {
    $competitor_urls = $competitor_settings['competitor_urls'];
  }
  
  if (!empty($competitor_urls)) {
    $profile['competitors'] = array_map(function($url) {
      $domain = preg_replace('/^https?:\/\//', '', $url);
      $domain = preg_replace('/^www\./', '', $domain);
      $domain = rtrim($domain, '/');
      return [
        'url' => $url,
        'domain' => strtolower($domain),
      ];
    }, array_filter($competitor_urls));
  }
  
  // Add performance metrics (Lighthouse/PageSpeed) if available
  $pagespeed_settings = get_option('vl_pagespeed_settings_' . $license_key, []);
  if (!empty($pagespeed_settings['analyses']) && is_array($pagespeed_settings['analyses'])) {
    $latest_analysis = end($pagespeed_settings['analyses']);
    if (is_array($latest_analysis)) {
      $profile['performance'] = [
        'lighthouse' => [
          'performance' => $latest_analysis['performance_score'] ?? 0,
          'accessibility' => $latest_analysis['accessibility_score'] ?? 0,
          'seo' => $latest_analysis['seo_score'] ?? 0,
          'best_practices' => $latest_analysis['best_practices_score'] ?? 0,
          'last_updated' => $latest_analysis['date'] ?? '',
        ],
      ];
    }
  }
  
  // Add SEO data (Search Console) if available
  $gsc_data = get_option('vl_gsc_data_' . $license_key, []);
  if (!empty($gsc_data) && is_array($gsc_data)) {
    $profile['seo'] = [
      'total_clicks' => 0,
      'total_impressions' => 0,
      'avg_ctr' => 0.0,
      'avg_position' => 0.0,
      'top_queries' => [],
    ];
    
    if (!empty($gsc_data['search_queries']) && is_array($gsc_data['search_queries'])) {
      $total_clicks = 0;
      $total_impressions = 0;
      $total_ctr = 0.0;
      $total_position = 0.0;
      $query_count = 0;
      
      foreach ($gsc_data['search_queries'] as $query) {
        $total_clicks += $query['clicks'] ?? 0;
        $total_impressions += $query['impressions'] ?? 0;
        $total_ctr += $query['ctr'] ?? 0.0;
        $total_position += $query['position'] ?? 0.0;
        $query_count++;
      }
      
      if ($query_count > 0) {
        $profile['seo']['total_clicks'] = $total_clicks;
        $profile['seo']['total_impressions'] = $total_impressions;
        $profile['seo']['avg_ctr'] = $total_ctr / $query_count;
        $profile['seo']['avg_position'] = $total_position / $query_count;
      }
      
      // Top 5 queries
      $top_queries = array_slice($gsc_data['search_queries'], 0, 5);
      $profile['seo']['top_queries'] = array_map(function($q) {
        return [
          'query' => $q['keys'][0] ?? '',
          'clicks' => $q['clicks'] ?? 0,
          'impressions' => $q['impressions'] ?? 0,
          'ctr' => ($q['ctr'] ?? 0.0) * 100,
          'position' => $q['position'] ?? 0.0,
        ];
      }, $top_queries);
    }
  }
  
  // Add data stream summary for better context
  // Use a helper function to get data streams safely
  $all_streams = get_option('vl_data_streams', []);
  if (!empty($all_streams[$license_key]) && is_array($all_streams[$license_key])) {
    $streams = $all_streams[$license_key];
    $split_streams = VL_License_Manager::split_streams_by_status($streams);
    $active_streams = $split_streams['active'];
    $inactive_streams = $split_streams['inactive'];

    $profile['data_streams_summary'] = [
      'total' => count($streams),
      'active' => count($active_streams),
      'inactive' => count($inactive_streams),
      'by_category' => [],
      'recent' => [],
    ];

    foreach ($active_streams as $stream_id => $stream) {
      if (!is_array($stream)) continue;

      if (!empty($stream['categories']) && is_array($stream['categories'])) {
        foreach ($stream['categories'] as $category) {
          if (!isset($profile['data_streams_summary']['by_category'][$category])) {
            $profile['data_streams_summary']['by_category'][$category] = 0;
          }
          $profile['data_streams_summary']['by_category'][$category]++;
        }
      }

      // Collect recent streams (last 5)
      if (!empty($stream['last_updated'])) {
        $profile['data_streams_summary']['recent'][] = [
          'id' => $stream_id,
          'name' => $stream['name'] ?? '',
          'category' => $stream['categories'][0] ?? '',
          'last_updated' => $stream['last_updated'],
        ];
      }
    }

    // Sort recent by last_updated descending
    usort($profile['data_streams_summary']['recent'], function($a, $b) {
      return strtotime($b['last_updated'] ?? '') <=> strtotime($a['last_updated'] ?? '');
    });

    $profile['data_streams_summary']['recent'] = array_slice($profile['data_streams_summary']['recent'], 0, 5);
  }

  // Sort recent by last_updated descending
  usort($profile['data_streams_summary']['recent'], function($a, $b) {
    return strtotime($b['last_updated'] ?? '') <=> strtotime($a['last_updated'] ?? '');
  });

  $profile['data_streams_summary']['recent'] = array_slice($profile['data_streams_summary']['recent'], 0, 5);

  return $profile;
}, 10, 2);