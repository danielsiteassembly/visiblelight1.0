<?php
/**
 * REST API endpoints for VL LAS.
 *
 * File: includes/rest/class-vl-las-rest.php
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'VL_LAS_REST' ) ) :

class VL_LAS_REST {

	/**
	 * Route namespace helper.
	 */
	protected static function ns() {
		return 'vl-las/v1';
	}

	/**
	 * Capability check.
	 */
	protected static function can_admin() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register all routes.
	 */
	public static function register_routes() {

		// --- Health: GET /ping
		register_rest_route( self::ns(), '/ping', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				return rest_ensure_response( array(
					'ok'      => true,
					'plugin'  => 'vl-las',
					'version' => defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : 'dev',
					'time'    => time(),
				) );
			},
		) );

		// --- Introspection: GET /routes
		register_rest_route( self::ns(), '/routes', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$server = rest_get_server();
				$items  = array();
				if ( $server && method_exists( $server, 'get_routes' ) ) {
					foreach ( $server->get_routes() as $route => $defs ) {
						if ( strpos( $route, '/vl-las/v1/' ) !== 0 ) continue;
						$methods = array();
						foreach ( (array) $defs as $d ) {
							if ( ! empty( $d['methods'] ) ) $methods[] = $d['methods'];
						}
						$items[] = array( 'route' => $route, 'methods' => $methods );
					}
				}
				return rest_ensure_response( array( 'ok' => true, 'routes' => $items ) );
			},
		) );

		// --- GET /reports — list stored reports
		register_rest_route( self::ns(), '/reports', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'args'                => array(
				'page'     => array( 'required' => false, 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
			),
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return rest_ensure_response( array( 'ok' => true, 'items' => array(), 'total' => 0, 'pages' => 0 ) );
				}
				$page     = max( 1, (int) $req->get_param( 'page' ) );
				$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
				try {
					$listing = \VL_LAS_Audit_Store::list( $page, $per_page ); // <-- matches your class
					return rest_ensure_response( array( 'ok' => true ) + $listing );
				} catch ( \Throwable $t ) {
					return rest_ensure_response( array( 'ok' => false, 'error' => $t->getMessage() ) );
				}
			},
		) );

		// --- GET /report/{id} — fetch single report
		register_rest_route( self::ns(), '/report/(?P<id>[\w\-]+)', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return new \WP_Error( 'vl_las_no_store', 'Storage not available.', array( 'status' => 404 ) );
				}
				$id  = (int) $req['id'];
				$row = \VL_LAS_Audit_Store::get( $id ); // <-- matches your class
				if ( ! $row ) {
					return new \WP_Error( 'vl_las_not_found', 'Report not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $row );
			},
		) );

		// --- GET /report/{id}/download — download JSON
		register_rest_route( self::ns(), '/report/(?P<id>[\w\-]+)/download', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function ( \WP_REST_Request $req ) {
				if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
					return new \WP_Error( 'vl_las_no_store', 'Storage not available.', array( 'status' => 404 ) );
				}
				$id  = (int) $req['id'];
				$row = \VL_LAS_Audit_Store::get( $id ); // <-- matches your class
				if ( ! $row ) {
					return new \WP_Error( 'vl_las_not_found', 'Report not found.', array( 'status' => 404 ) );
				}
				$filename = 'vl-las-report-' . $id . '.json';
				nocache_headers();
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'utf-8' ) );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				echo wp_json_encode( $row['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				exit;
			},
		) );

		// --- GET /audit2 — quick probe
		register_rest_route( self::ns(), '/audit2', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'callback'            => function () {
				return rest_ensure_response( array( 'ok' => true, 'alive' => 'audit2 GET ok' ) );
			},
		) );

		// --- POST /audit2 — main audit runner (regex-only, safe)
		register_rest_route( self::ns(), '/audit2', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => function () { return self::can_admin(); },
			'args'                => array(
				'url'  => array( 'required' => false, 'type' => 'string' ),
				'html' => array( 'required' => false, 'type' => 'string' ),
			),
			'callback'            => array( __CLASS__, 'handle_audit_post' ),
		) );

		// --- POST /audit — compatibility alias to /audit2
                register_rest_route( self::ns(), '/audit', array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'args'                => array(
                                'url'  => array( 'required' => false, 'type' => 'string' ),
                                'html' => array( 'required' => false, 'type' => 'string' ),
                        ),
                        'callback'            => array( __CLASS__, 'handle_audit_post' ),
                ) );

                // --- POST /gemini-test — test Gemini API key
                register_rest_route( self::ns(), '/gemini-test', array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_gemini_test' ),
                ) );

                // --- GET /soc2/report — latest SOC 2 bundle
                register_rest_route( self::ns(), '/soc2/report', array(
                        'methods'             => \WP_REST_Server::READABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_soc2_get' ),
                ) );

                // --- GET /soc2/reports — list all SOC 2 reports
                register_rest_route( self::ns(), '/soc2/reports', array(
                        'methods'             => \WP_REST_Server::READABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_soc2_list' ),
                ) );

                // --- GET /soc2/report/{id} — get specific SOC 2 report
                register_rest_route( self::ns(), '/soc2/report/(?P<id>[\d]+)', array(
                        'methods'             => \WP_REST_Server::READABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_soc2_get_by_id' ),
                ) );

                // --- POST /soc2/run — trigger full sync
                register_rest_route( self::ns(), '/soc2/run', array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_soc2_run' ),
                ) );

                // --- POST /license-sync — test license connection to VL Hub
                register_rest_route( self::ns(), '/license-sync', array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'permission_callback' => function () { return self::can_admin(); },
                        'callback'            => array( __CLASS__, 'handle_license_sync' ),
                ) );
        }

        /**
         * Handle POST /audit2 (and /audit).
         * Uses VL_LAS_Audit_Regex if present; does not autoload heavy classes.
         */
        public static function handle_audit_post( \WP_REST_Request $req ) {
                try {
                        $html = (string) $req->get_param( 'html' );
			$url  = (string) $req->get_param( 'url' );
			$url  = $url ? esc_url_raw( $url ) : home_url( '/' );

			// Only proceed if regex engine is already loaded.
			if ( ! class_exists( 'VL_LAS_Audit_Regex', false ) ) {
				return rest_ensure_response( array(
					'ok'    => false,
					'error' => 'Regex audit engine not loaded. Ensure class-vl-las-audit-regex.php is required by the main plugin file.',
				) );
				}

			// Run audit (safe regex).
			if ( method_exists( '\VL_LAS_Audit_Regex', 'run' ) ) {
				$report = ( $html !== '' )
					? \VL_LAS_Audit_Regex::run( $html, null )
					: \VL_LAS_Audit_Regex::run( '', $url );
			} else {
				return rest_ensure_response( array(
					'ok'    => false,
					'error' => 'VL_LAS_Audit_Regex::run not found.',
				) );
			}

			// Normalize fields for UI.
			if ( is_array( $report ) ) {
				if ( empty( $report['url'] ) )        $report['url']        = $url;
				if ( empty( $report['created_at'] ) ) $report['created_at'] = time();
			} else {
				$report = array(
					'raw'        => $report,
					'url'        => $url,
					'created_at' => time(),
				);
			}

			// Best-effort store (never fatal if DB not ready).
			if ( class_exists( 'VL_LAS_Audit_Store' ) && method_exists( 'VL_LAS_Audit_Store', 'save' ) ) {
				try {
					if ( method_exists( 'VL_LAS_Audit_Store', 'install' ) ) {
						\VL_LAS_Audit_Store::install();
					}
					$insert_id          = \VL_LAS_Audit_Store::save( $report ); // <-- matches your class
					$report['report_id'] = $insert_id;
				} catch ( \Throwable $t ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[VL_LAS] save error: ' . $t->getMessage() );
					}
				}
			}

			// Wrap as { ok, report } for admin.js pretty view.
			return rest_ensure_response( array( 'ok' => true, 'report' => $report ) );

		} catch ( \Throwable $t ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VL_LAS] audit2 fatal: ' . $t->getMessage() . "\n" . $t->getTraceAsString() );
			}
			return new \WP_Error( 'vl_las_internal', 'Audit crashed: ' . $t->getMessage(), array( 'status' => 500 ) );
                }
        }

        /**
         * Handle GET /soc2/report.
         */
        public static function handle_soc2_get( \WP_REST_Request $req ) {
                if ( ! self::can_admin() ) {
                        return new \WP_Error(
                                'rest_forbidden',
                                __( 'Sorry, you are not allowed to access SOC 2 reports.', 'vl-las' ),
                                array( 'status' => rest_authorization_required_code() )
                        );
                }

                if ( ! class_exists( '\\VL_LAS_SOC2' ) ) {
                        return rest_ensure_response( array(
                                'ok'    => false,
                                'error' => __( 'SOC 2 module unavailable.', 'vl-las' ),
                        ) );
                }

                $enabled = (bool) get_option( 'vl_las_soc2_enabled', 0 );
                $bundle  = \VL_LAS_SOC2::get_cached_bundle();

                return rest_ensure_response( array(
                        'ok'       => true,
                        'enabled'  => $enabled,
                        'report'   => $bundle['report'],
                        'snapshot' => $bundle['snapshot'],
                        'meta'     => $bundle['meta'],
                ) );
        }

        /**
         * Handle GET /soc2/reports — list all SOC 2 reports.
         */
        public static function handle_soc2_list( \WP_REST_Request $req ) {
                if ( ! self::can_admin() ) {
                        return new \WP_Error(
                                'rest_forbidden',
                                __( 'Sorry, you are not allowed to access SOC 2 reports.', 'vl-las' ),
                                array( 'status' => rest_authorization_required_code() )
                        );
                }

                if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
                        return rest_ensure_response( array(
                                'ok'    => false,
                                'error' => __( 'Storage not available.', 'vl-las' ),
                                'items' => array(),
                        ) );
                }

                $page     = (int) $req->get_param( 'page' );
                $per_page = (int) $req->get_param( 'per_page' );
                if ( $page < 1 ) $page = 1;
                if ( $per_page < 1 ) $per_page = 50;

                // Get all reports with engine='soc2'
                global $wpdb;
                $table = \VL_LAS_Audit_Store::table();
                $offset = ( $page - 1 ) * $per_page;

                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, created_at, engine, url, summary
                                 FROM {$table}
                                 WHERE engine = 'soc2'
                                 ORDER BY id DESC
                                 LIMIT %d OFFSET %d",
                                $per_page, $offset
                        ),
                        ARRAY_A
                );

                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE engine = %s", 'soc2' ) );

                // Decode summary JSON and extract trust services
                foreach ( (array) $rows as &$r ) {
                        $r['summary'] = $r['summary'] ? json_decode( $r['summary'], true ) : null;
                        $trust_services = isset( $r['summary']['trust_services'] ) ? $r['summary']['trust_services'] : array();
                        $r['trust_services'] = is_array( $trust_services ) ? implode( ', ', $trust_services ) : '';
                }

                return rest_ensure_response( array(
                        'ok'        => true,
                        'items'     => $rows ?: array(),
                        'page'      => $page,
                        'per_page'  => $per_page,
                        'total'     => $total,
                        'pages'     => $per_page ? (int) ceil( $total / $per_page ) : 1,
                ) );
        }

        /**
         * Handle GET /soc2/report/{id} — get specific SOC 2 report.
         */
        public static function handle_soc2_get_by_id( \WP_REST_Request $req ) {
                if ( ! self::can_admin() ) {
                        return new \WP_Error(
                                'rest_forbidden',
                                __( 'Sorry, you are not allowed to access SOC 2 reports.', 'vl-las' ),
                                array( 'status' => rest_authorization_required_code() )
                        );
                }

                if ( ! class_exists( 'VL_LAS_Audit_Store' ) ) {
                        return new \WP_Error( 'vl_las_no_store', 'Storage not available.', array( 'status' => 404 ) );
                }

                $id  = (int) $req['id'];
                $row = \VL_LAS_Audit_Store::get( $id );
                if ( ! $row ) {
                        return new \WP_Error( 'vl_las_not_found', 'Report not found.', array( 'status' => 404 ) );
                }

                // Verify it's a SOC 2 report
                if ( $row['engine'] !== 'soc2' ) {
                        return new \WP_Error( 'vl_las_invalid_type', 'Not a SOC 2 report.', array( 'status' => 400 ) );
                }

                return rest_ensure_response( $row );
        }

        /**
         * Handle POST /soc2/run.
         */
        public static function handle_soc2_run( \WP_REST_Request $req ) {
                if ( ! self::can_admin() ) {
                        return new \WP_Error(
                                'rest_forbidden',
                                __( 'Sorry, you are not allowed to access SOC 2 reports.', 'vl-las' ),
                                array( 'status' => rest_authorization_required_code() )
                        );
                }

                if ( ! class_exists( '\\VL_LAS_SOC2' ) ) {
                        return rest_ensure_response( array(
                                'ok'    => false,
                                'error' => __( 'SOC 2 module unavailable.', 'vl-las' ),
                        ) );
                }

                if ( ! get_option( 'vl_las_soc2_enabled', 0 ) ) {
                        return rest_ensure_response( array(
                                'ok'    => false,
                                'error' => __( 'Enable SOC 2 automation in settings before running a sync.', 'vl-las' ),
                        ) );
                }

                try {
                        $result = \VL_LAS_SOC2::run_full_report();
                        $result['enabled'] = true;
                        return rest_ensure_response( array_merge( array( 'ok' => true ), $result ) );
                } catch ( \Throwable $t ) {
                        return rest_ensure_response( array(
                                'ok'    => false,
                                'error' => sanitize_text_field( $t->getMessage() ),
                        ) );
                }
        }

        /**
         * Handle POST /gemini-test.
         * Test the Gemini API key by making a real request to the Gemini API.
         */
	public static function handle_gemini_test( \WP_REST_Request $req ) {
		try {
			$key = trim( (string) get_option( 'vl_las_gemini_api_key', '' ) );
			if ( empty( $key ) ) {
				return rest_ensure_response( array( 'ok' => false, 'error' => 'No Gemini API key saved.' ) );
			}
			
			// Test the API key by making a real request to Gemini API
			$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=' . urlencode( $key );
			
			$test_payload = array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => 'Hello, this is a test message. Please respond with "API test successful".' )
						)
					)
				),
				'generationConfig' => array(
					'maxOutputTokens' => 50,
					'temperature' => 0.1
				)
			);
			
			$response = wp_remote_post( $api_url, array(
				'method' => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => json_encode( $test_payload ),
				'timeout' => 30,
			) );
			
			if ( is_wp_error( $response ) ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => 'Network error: ' . $response->get_error_message(),
					'via' => 'vl-las-rest-class'
				) );
			}
			
			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			
			if ( $response_code !== 200 ) {
				$error_data = json_decode( $response_body, true );
				$error_message = 'HTTP ' . $response_code;
				if ( isset( $error_data['error']['message'] ) ) {
					$error_message .= ': ' . $error_data['error']['message'];
				}
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => $error_message,
					'status_code' => $response_code,
					'via' => 'vl-las-rest-class'
				) );
			}
			
			$data = json_decode( $response_body, true );
			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return rest_ensure_response( array( 
					'ok' => true, 
					'status' => 'API test successful',
					'response' => $data['candidates'][0]['content']['parts'][0]['text'],
					'via' => 'vl-las-rest-class'
				) );
			} else {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => 'Unexpected API response format',
					'raw_response' => $response_body,
					'via' => 'vl-las-rest-class'
				) );
			}
		} catch ( \Throwable $t ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VL_LAS] gemini-test fatal: ' . $t->getMessage() . "\n" . $t->getTraceAsString() );
			}
			return new \WP_Error( 'vl_las_gemini_test_failed', 'Gemini test crashed: ' . $t->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Handle POST /license-sync.
	 * Test the license connection to VL Hub.
	 */
	public static function handle_license_sync( \WP_REST_Request $req ) {
		try {
			// Get license from request parameter or saved option
			$license_param = trim( (string) $req->get_param( 'license' ) );
			$license = $license_param ? $license_param : trim( (string) get_option( 'vl_las_license_code', '' ) );
			
			if ( empty( $license ) ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => __( 'No Corporate License Code provided. Please enter your license code first.', 'vl-las' )
				) );
			}
			
			// If license was provided in request, save it temporarily for testing
			if ( $license_param && $license_param !== get_option( 'vl_las_license_code', '' ) ) {
				// Don't save yet - just use it for testing
			}

			// Get SOC 2 endpoint (or default) - this will auto-fix if needed
			$endpoint_before = get_option( 'vl_las_soc2_endpoint', '' );
			$endpoint = class_exists( 'VL_LAS_SOC2' ) ? \VL_LAS_SOC2::get_endpoint() : 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot';
			$endpoint_after = get_option( 'vl_las_soc2_endpoint', '' );
			$endpoint_auto_fixed = ( $endpoint_before !== $endpoint_after && strpos( $endpoint_after, '/wp-json/vl-hub/v1/soc2' ) !== false );
			
			// Test connection with license - use same pattern as Luna Widget
			$args = array(
				'headers' => array(
					'Accept'        => 'application/json',
					'User-Agent'    => 'VL-LAS/' . ( defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : 'dev' ),
					'X-VL-License'  => $license,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
				'sslverify' => true,
				'redirection' => 5,
			);

			$response = wp_remote_get( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => sprintf( __( 'Connection failed: %s', 'vl-las' ), $response->get_error_message() )
				) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code === 200 || $code === 201 ) {
				$data = json_decode( $body, true );
				$message = __( 'License validated successfully. You can now sync SOC 2 data.', 'vl-las' );
				if ( $endpoint_auto_fixed ) {
					$message = __( 'License validated successfully. Endpoint URL was automatically corrected to include "hub" subdomain.', 'vl-las' );
				}
				return rest_ensure_response( array( 
					'ok' => true, 
					'status' => __( 'Connection successful', 'vl-las' ),
					'message' => $message,
					'endpoint' => $endpoint,
					'response_code' => $code,
					'endpoint_auto_fixed' => $endpoint_auto_fixed,
				) );
			} elseif ( $code === 401 || $code === 403 ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => __( 'License authentication failed. Please verify your Corporate License Code with Visible Light.', 'vl-las' ),
					'response_code' => $code,
				) );
			} elseif ( $code === 404 ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => sprintf( __( 'Endpoint not found (HTTP 404). Please verify the SOC 2 endpoint URL: %s', 'vl-las' ), $endpoint ),
					'response_code' => $code,
				) );
			} elseif ( $code === 526 ) {
				// HTTP 526 is a Cloudflare-specific error: Invalid SSL Certificate
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => __( 'SSL certificate validation failed (HTTP 526). This may be a temporary Cloudflare issue. Please try again in a few moments, or contact Visible Light support if the issue persists.', 'vl-las' ),
					'response_code' => $code,
					'endpoint' => $endpoint,
				) );
			} elseif ( $code >= 500 && $code < 600 ) {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => sprintf( __( 'Server error from VL Hub (HTTP %d). This may be a temporary issue. Please try again in a few moments.', 'vl-las' ), $code ),
					'response_code' => $code,
					'endpoint' => $endpoint,
				) );
			} else {
				return rest_ensure_response( array( 
					'ok' => false, 
					'error' => sprintf( __( 'Unexpected response from VL Hub (HTTP %d). Please contact Visible Light support.', 'vl-las' ), $code ),
					'response_code' => $code,
					'endpoint' => $endpoint,
				) );
			}
		} catch ( \Throwable $t ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[VL_LAS] license-sync fatal: ' . $t->getMessage() . "\n" . $t->getTraceAsString() );
			}
			return new \WP_Error( 'vl_las_license_sync_failed', 'License sync crashed: ' . $t->getMessage(), array( 'status' => 500 ) );
		}
	}
}

endif;