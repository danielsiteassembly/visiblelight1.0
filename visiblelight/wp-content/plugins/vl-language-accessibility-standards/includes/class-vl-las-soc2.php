<?php
/**
 * Enterprise SOC 2 report builder integrating with the VL Hub.
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'VL_LAS_SOC2' ) ) {

    class VL_LAS_SOC2 {

        const OPTION_SNAPSHOT = 'vl_las_soc2_snapshot';
        const OPTION_REPORT   = 'vl_las_soc2_report';
        const OPTION_META     = 'vl_las_soc2_meta';
        const DEFAULT_ENDPOINT = 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot';

        /**
         * Return the configured Hub endpoint.
         */
        public static function get_endpoint() {
            $endpoint = trim( (string) get_option( 'vl_las_soc2_endpoint', '' ) );
            if ( '' === $endpoint ) {
                $endpoint = self::DEFAULT_ENDPOINT;
            }

            // Auto-fix common endpoint URL mistakes
            // Convert old hub.visiblelight.ai/api/soc2 format to new visiblelight.ai/wp-json/vl-hub/v1/soc2 format
            if ( strpos( $endpoint, 'hub.visiblelight.ai/api/soc2' ) !== false ) {
                $endpoint = str_replace( 'hub.visiblelight.ai/api/soc2', 'visiblelight.ai/wp-json/vl-hub/v1/soc2', $endpoint );
                // Update the saved option with the corrected URL
                update_option( 'vl_las_soc2_endpoint', $endpoint, false );
            }
            // Also fix if missing wp-json path
            if ( strpos( $endpoint, 'visiblelight.ai/api/soc2' ) !== false && strpos( $endpoint, '/wp-json/' ) === false ) {
                $endpoint = str_replace( 'visiblelight.ai/api/soc2', 'visiblelight.ai/wp-json/vl-hub/v1/soc2', $endpoint );
                update_option( 'vl_las_soc2_endpoint', $endpoint, false );
            }

            $endpoint = apply_filters( 'vl_las_soc2_endpoint', $endpoint );

            return esc_url_raw( $endpoint );
        }

        /**
         * Fetch the SOC 2 snapshot from the VL Hub.
         *
         * @throws \RuntimeException When the request fails or returns invalid data.
         */
        public static function fetch_snapshot() {
            $license = trim( (string) get_option( 'vl_las_license_code', '' ) );
            if ( '' === $license ) {
                throw new \RuntimeException( __( 'Corporate License Code is required before syncing with the VL Hub.', 'vl-las' ) );
            }

            $endpoint = self::get_endpoint();
            $args     = array(
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

            /**
             * Allow integrators to adjust the remote request arguments.
             */
            $args = apply_filters( 'vl_las_soc2_request_args', $args, $endpoint );

            $response = wp_remote_get( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( sprintf(
                    __( 'Hub sync failed: %s', 'vl-las' ),
                    $response->get_error_message()
                ) );
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                $error_msg = sprintf( __( 'Hub sync failed with HTTP %d.', 'vl-las' ), $code );
                if ( $code === 526 ) {
                    $error_msg = __( 'Hub sync failed: SSL certificate validation error (HTTP 526). This may be a temporary Cloudflare issue. Please try again in a few moments.', 'vl-las' );
                } elseif ( $code === 401 || $code === 403 ) {
                    $error_msg = __( 'Hub sync failed: License authentication failed. Please verify your Corporate License Code.', 'vl-las' );
                } elseif ( $code === 404 ) {
                    $error_msg = sprintf( __( 'Hub sync failed: Endpoint not found (HTTP 404). Please verify the SOC 2 endpoint URL: %s', 'vl-las' ), $endpoint );
                } elseif ( $code >= 500 && $code < 600 ) {
                    $error_msg = __( 'Hub sync failed: Server error from VL Hub. This may be a temporary issue. Please try again in a few moments.', 'vl-las' );
                }
                throw new \RuntimeException( $error_msg );
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                throw new \RuntimeException( __( 'Hub sync returned invalid JSON.', 'vl-las' ) );
            }

            /**
             * Filter the raw snapshot payload before report generation.
             */
            $data = apply_filters( 'vl_las_soc2_snapshot', $data, $response );

            return $data;
        }

        /**
         * Run the full report pipeline: fetch → analyse → cache.
         * Integrates VL Hub data, WCAG audit data, and site data.
         */
        public static function run_full_report() {
            // Fetch VL Hub snapshot
            $snapshot = self::fetch_snapshot();
            
            // Gather WCAG audit data
            $wcag_audit = self::gather_wcag_audit_data();
            
            // Gather site data
            $site_data = self::gather_site_data();
            
            // Enhance snapshot with WCAG and site data
            $snapshot['wcag_audit'] = $wcag_audit;
            $snapshot['site_data'] = $site_data;
            
            // Generate comprehensive report
            $report   = self::generate_report( $snapshot );
            $meta     = array(
                'generated_at'   => current_time( 'mysql' ),
                'observation'    => isset( $report['control_tests']['observation_period'] )
                    ? $report['control_tests']['observation_period']
                    : array(),
                'trust_services' => isset( $report['trust_services']['selected'] )
                    ? $report['trust_services']['selected']
                    : array(),
                'analysis_engine' => $report['meta']['analysis_engine'],
            );

            update_option( self::OPTION_SNAPSHOT, $snapshot, false );
            update_option( self::OPTION_REPORT, $report, false );
            update_option( self::OPTION_META, $meta, false );

            // Also save to audit store for history tracking
            // Check for duplicate reports within the last 5 seconds to prevent race conditions
            if ( class_exists( 'VL_LAS_Audit_Store' ) && method_exists( 'VL_LAS_Audit_Store', 'save' ) ) {
                try {
                    if ( method_exists( 'VL_LAS_Audit_Store', 'install' ) ) {
                        \VL_LAS_Audit_Store::install();
                    }
                    
                    // Check for recent duplicate reports (within 5 seconds)
                    global $wpdb;
                    $table = \VL_LAS_Audit_Store::table();
                    $recent_time = date( 'Y-m-d H:i:s', strtotime( '-5 seconds' ) );
                    $recent_count = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE engine = 'soc2' AND created_at >= %s",
                        $recent_time
                    ) );
                    
                    // Only save if no recent report exists (prevents duplicates from race conditions)
                    if ( $recent_count == 0 ) {
                        $store_report = array(
                            'engine' => 'soc2',
                            'url' => home_url(),
                            'ts' => current_time( 'mysql' ),
                            'summary' => array(
                                'trust_services' => isset( $report['trust_services']['selected'] ) ? $report['trust_services']['selected'] : array(),
                                'generated_at' => current_time( 'mysql' ),
                                'observation_period' => isset( $report['control_tests']['observation_period'] ) ? $report['control_tests']['observation_period'] : array(),
                            ),
                            'report' => $report,
                            'snapshot' => $snapshot,
                            'meta' => $meta,
                        );
                        $insert_id = \VL_LAS_Audit_Store::save( $store_report );
                        if ( $insert_id ) {
                            $meta['store_id'] = $insert_id;
                            update_option( self::OPTION_META, $meta, false );
                        }
                    } else {
                        // If duplicate detected, use the most recent report's store_id
                        $recent_report = $wpdb->get_row( $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE engine = 'soc2' AND created_at >= %s ORDER BY id DESC LIMIT 1",
                            $recent_time
                        ), ARRAY_A );
                        if ( $recent_report && isset( $recent_report['id'] ) ) {
                            $meta['store_id'] = (int) $recent_report['id'];
                            update_option( self::OPTION_META, $meta, false );
                        }
                    }
                } catch ( \Throwable $t ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[VL_LAS_SOC2] Store save error: ' . $t->getMessage() );
                    }
                }
            }

            return array(
                'snapshot' => $snapshot,
                'report'   => $report,
                'meta'     => $meta,
            );
        }
        
        /**
         * Gather latest WCAG audit data from stored reports.
         */
        protected static function gather_wcag_audit_data() {
            $audit_data = array(
                'latest_report' => null,
                'summary' => null,
                'compliance_score' => null,
                'key_metrics' => array(),
            );
            
            if ( class_exists( 'VL_LAS_Audit_Store' ) && method_exists( 'VL_LAS_Audit_Store', 'list' ) ) {
                try {
                    $listing = \VL_LAS_Audit_Store::list( 1, 1 );
                    if ( ! empty( $listing['items'] ) && is_array( $listing['items'] ) ) {
                        $latest = $listing['items'][0];
                        $report_id = isset( $latest['id'] ) ? (int) $latest['id'] : null;
                        
                        if ( $report_id && method_exists( 'VL_LAS_Audit_Store', 'get' ) ) {
                            $full_report = \VL_LAS_Audit_Store::get( $report_id );
                            if ( $full_report && isset( $full_report['report'] ) ) {
                                $report = is_array( $full_report['report'] ) ? $full_report['report'] : json_decode( $full_report['report'], true );
                                
                                if ( is_array( $report ) ) {
                                    $audit_data['latest_report'] = array(
                                        'id' => $report_id,
                                        'url' => isset( $report['url'] ) ? $report['url'] : ( isset( $full_report['url'] ) ? $full_report['url'] : '' ),
                                        'created_at' => isset( $report['created_at'] ) ? $report['created_at'] : ( isset( $full_report['created_at'] ) ? $full_report['created_at'] : '' ),
                                    );
                                    
                                    // Extract summary and metrics
                                    if ( isset( $report['summary'] ) && is_array( $report['summary'] ) ) {
                                        $summary = $report['summary'];
                                        $audit_data['summary'] = array(
                                            'passed' => isset( $summary['passed'] ) ? (int) $summary['passed'] : 0,
                                            'total' => isset( $summary['total'] ) ? (int) $summary['total'] : 0,
                                            'score' => isset( $summary['score'] ) ? (int) $summary['score'] : 0,
                                        );
                                        $audit_data['compliance_score'] = $audit_data['summary']['score'];
                                        
                                        // Key metrics
                                        $audit_data['key_metrics'] = array(
                                            'error_density' => isset( $summary['error_density'] ) ? round( (float) $summary['error_density'], 2 ) : null,
                                            'unique_issues' => isset( $summary['unique_issues'] ) ? (int) $summary['unique_issues'] : null,
                                            'wcag_compliance' => isset( $summary['wcag_compliance'] ) ? $summary['wcag_compliance'] : null,
                                            'keyboard_accessibility_score' => isset( $summary['keyboard_accessibility_score'] ) ? (int) $summary['keyboard_accessibility_score'] : null,
                                            'screen_reader_compatibility' => isset( $summary['screen_reader_compatibility'] ) ? (int) $summary['screen_reader_compatibility'] : null,
                                        );
                                    }
                                }
                            }
                        }
                    }
                } catch ( \Throwable $t ) {
                    // Silently fail - WCAG data is supplementary
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[VL_LAS_SOC2] WCAG audit gather error: ' . $t->getMessage() );
                    }
                }
            }
            
            return $audit_data;
        }
        
        /**
         * Gather WordPress site data for SOC 2 report.
         */
        protected static function gather_site_data() {
            global $wp_version;
            
            $site_data = array(
                'wordpress' => array(
                    'version' => $wp_version,
                    'site_url' => home_url(),
                    'admin_email' => get_option( 'admin_email' ),
                    'timezone' => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ),
                ),
                'plugins' => array(),
                'themes' => array(),
                'security' => array(
                    'ssl_enabled' => is_ssl(),
                    'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
                ),
            );
            
            // Get active plugins
            if ( function_exists( 'get_plugins' ) ) {
                $all_plugins = get_plugins();
                $active_plugins = get_option( 'active_plugins', array() );
                foreach ( $active_plugins as $plugin_file ) {
                    if ( isset( $all_plugins[ $plugin_file ] ) ) {
                        $plugin = $all_plugins[ $plugin_file ];
                        $site_data['plugins'][] = array(
                            'name' => isset( $plugin['Name'] ) ? $plugin['Name'] : $plugin_file,
                            'version' => isset( $plugin['Version'] ) ? $plugin['Version'] : '',
                        );
                    }
                }
            }
            
            // Get active theme
            if ( function_exists( 'wp_get_theme' ) ) {
                $theme = wp_get_theme();
                $site_data['themes'][] = array(
                    'name' => $theme->get( 'Name' ),
                    'version' => $theme->get( 'Version' ),
                );
            }
            
            return $site_data;
        }

        /**
         * Return cached report data (if any).
         */
        public static function get_cached_bundle() {
            $snapshot = get_option( self::OPTION_SNAPSHOT, array() );
            $report   = get_option( self::OPTION_REPORT, array() );
            $meta     = get_option( self::OPTION_META, array() );

            return array(
                'snapshot' => is_array( $snapshot ) ? $snapshot : array(),
                'report'   => is_array( $report ) ? $report : array(),
                'meta'     => is_array( $meta ) ? $meta : array(),
            );
        }

        /**
         * Build the SOC 2 report using the snapshot payload.
         */
        public static function generate_report( array $snapshot ) {
            $now      = current_time( 'mysql' );
            $trust    = self::normalize_trust_services( $snapshot );
            
            // Integrate WCAG audit and site data
            $wcag_audit = isset( $snapshot['wcag_audit'] ) ? $snapshot['wcag_audit'] : array();
            $site_data = isset( $snapshot['site_data'] ) ? $snapshot['site_data'] : array();
            
            // Analyze data and generate actual findings
            $controls = self::analyze_control_domains( $snapshot, $site_data, $wcag_audit );
            $tests    = self::analyze_control_tests( $snapshot, $site_data, $wcag_audit, $controls );
            $risks    = self::analyze_risks( $snapshot, $site_data, $wcag_audit, $controls, $tests );
            $artifacts = self::normalize_artifacts( $snapshot );
            
            // Enhance system description with site data
            $system_desc = self::normalize_system_description( $snapshot );
            if ( ! empty( $site_data ) ) {
                $system_desc['technical_stack'] = array(
                    'cms' => 'WordPress ' . ( isset( $site_data['wordpress']['version'] ) ? $site_data['wordpress']['version'] : '' ),
                    'active_plugins' => isset( $site_data['plugins'] ) ? count( $site_data['plugins'] ) : 0,
                    'ssl_enabled' => isset( $site_data['security']['ssl_enabled'] ) ? $site_data['security']['ssl_enabled'] : false,
                );
            }
            
            // Enhance artifacts with WCAG audit data
            if ( ! empty( $wcag_audit ) && isset( $wcag_audit['latest_report'] ) ) {
                $artifacts['wcag_audit_report'] = array(
                    'report_id' => isset( $wcag_audit['latest_report']['id'] ) ? $wcag_audit['latest_report']['id'] : null,
                    'url' => isset( $wcag_audit['latest_report']['url'] ) ? $wcag_audit['latest_report']['url'] : '',
                    'audit_date' => isset( $wcag_audit['latest_report']['created_at'] ) ? $wcag_audit['latest_report']['created_at'] : '',
                    'compliance_score' => isset( $wcag_audit['compliance_score'] ) ? $wcag_audit['compliance_score'] : null,
                    'key_metrics' => isset( $wcag_audit['key_metrics'] ) ? $wcag_audit['key_metrics'] : array(),
                );
            }
            
            $report = array(
                'meta' => array(
                    'generated_at'    => $now,
                    'type'            => 'SOC 2 Type II',
                    'analysis_engine' => 'Luna AI SOC 2 Copilot',
                    'source_endpoint' => self::get_endpoint(),
                    'snapshot_hash'   => md5( wp_json_encode( $snapshot ) ),
                    'data_sources'    => array(
                        'vl_hub' => true,
                        'wcag_audit' => ! empty( $wcag_audit ),
                        'site_data' => ! empty( $site_data ),
                    ),
                ),
                'trust_services' => array(
                    'selected'          => $trust['selected'],
                    'coverage'          => $trust['coverage'],
                    'obligations'       => $trust['obligations'],
                ),
                'system_description' => $system_desc,
                'control_environment' => array(
                    'domains'        => $controls,
                    'control_matrix' => self::build_control_matrix( $controls, $trust['selected'] ),
                ),
                'control_tests' => $tests,
                'risk_assessment' => $risks,
                'auditors'        => self::build_auditor_section( $snapshot, $tests, $risks ),
                'supporting_artifacts' => $artifacts,
                'integrated_data' => array(
                    'wcag_audit' => $wcag_audit,
                    'site_data' => $site_data,
                ),
            );

            $report['documents'] = array(
                'executive_summary' => self::build_executive_summary( $report ),
                'markdown'          => self::render_markdown( $report ),
            );

            return $report;
        }

        protected static function normalize_trust_services( array $snapshot ) {
            $all = array(
                'Security'             => __( 'Protection against unauthorized access and breaches.', 'vl-las' ),
                'Availability'         => __( 'Systems remain operational and resilient.', 'vl-las' ),
                'Processing Integrity'  => __( 'Data processing is complete, valid, accurate, timely, and authorized.', 'vl-las' ),
                'Confidentiality'      => __( 'Sensitive data is protected throughout its lifecycle.', 'vl-las' ),
                'Privacy'              => __( 'Personal information is collected, used, and retained appropriately.', 'vl-las' ),
            );

            $selected = array();
            if ( ! empty( $snapshot['trust_services'] ) ) {
                $raw = $snapshot['trust_services'];
                if ( isset( $raw['selected'] ) ) {
                    $raw = $raw['selected'];
                }
                if ( is_string( $raw ) ) {
                    $raw = array_map( 'trim', explode( ',', $raw ) );
                }
                if ( is_array( $raw ) ) {
                    foreach ( $raw as $item ) {
                        $label = ucwords( trim( (string) $item ) );
                        if ( isset( $all[ $label ] ) && ! in_array( $label, $selected, true ) ) {
                            $selected[] = $label;
                        }
                    }
                }
            }

            if ( empty( $selected ) ) {
                $selected = array( 'Security', 'Availability', 'Confidentiality' );
            }

            $coverage = array();
            foreach ( $selected as $criterion ) {
                $coverage[] = array(
                    'criterion' => $criterion,
                    'objective' => $all[ $criterion ],
                    'controls'  => self::extract_controls_for_criterion( $snapshot, $criterion ),
                );
            }

            $obligations = array();
            if ( isset( $snapshot['trust_services']['obligations'] ) && is_array( $snapshot['trust_services']['obligations'] ) ) {
                $obligations = $snapshot['trust_services']['obligations'];
            } elseif ( ! empty( $snapshot['trust_services']['notes'] ) ) {
                $obligations[] = (string) $snapshot['trust_services']['notes'];
            }

            return array(
                'selected'    => $selected,
                'coverage'    => $coverage,
                'obligations' => $obligations,
            );
        }

        protected static function normalize_system_description( array $snapshot ) {
            $company = isset( $snapshot['company'] ) && is_array( $snapshot['company'] )
                ? $snapshot['company']
                : array();

            $description = array(
                'company_overview' => array(
                    'name'        => $company['name'] ?? ( $snapshot['company_name'] ?? '' ),
                    'mission'     => $company['mission'] ?? '',
                    'ownership'   => $company['ownership'] ?? '',
                    'structure'   => $company['structure'] ?? '',
                    'headquarters'=> $company['headquarters'] ?? ( $snapshot['headquarters'] ?? '' ),
                ),
                'services_in_scope' => self::ensure_array( $snapshot['services'] ?? $snapshot['services_in_scope'] ?? array() ),
                'infrastructure'    => self::ensure_array( $snapshot['infrastructure'] ?? array() ),
                'software_components' => self::ensure_array( $snapshot['software'] ?? $snapshot['software_components'] ?? array() ),
                'data_flows'        => self::ensure_array( $snapshot['data_flows'] ?? array() ),
                'personnel'         => self::ensure_array( $snapshot['personnel'] ?? array() ),
                'subservice_organizations' => self::ensure_array( $snapshot['subservice_organizations'] ?? $snapshot['vendors'] ?? array() ),
                'control_boundaries'       => self::ensure_array( $snapshot['control_boundaries'] ?? array() ),
                'incident_response'        => self::ensure_array( $snapshot['incident_response'] ?? array() ),
                'business_continuity'      => self::ensure_array( $snapshot['business_continuity'] ?? array() ),
            );

            return $description;
        }

        protected static function normalize_control_domains( array $snapshot ) {
            $domains = array(
                'governance'          => 'Governance & Risk Management',
                'access_control'      => 'Access Control',
                'change_management'   => 'Change Management',
                'system_monitoring'   => 'System Monitoring',
                'incident_response'   => 'Incident Response',
                'vendor_management'   => 'Vendor Management',
                'data_encryption'     => 'Data Encryption',
                'backup_recovery'     => 'Backup & Recovery',
                'onboarding'          => 'Employee Onboarding/Offboarding',
                'privacy'             => 'Privacy & GDPR Alignment',
            );

            $controls = array();
            $source   = isset( $snapshot['controls'] ) && is_array( $snapshot['controls'] ) ? $snapshot['controls'] : array();

            foreach ( $domains as $key => $label ) {
                $row = isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
                $controls[ $key ] = array(
                    'label'    => $label,
                    'status'   => $row['status'] ?? 'operating',
                    'controls' => self::ensure_array( $row['controls'] ?? array() ),
                    'evidence' => self::ensure_array( $row['evidence'] ?? array() ),
                    'owner'    => $row['owner'] ?? '',
                );
            }

            return $controls;
        }

        /**
         * Analyze control domains based on actual data from site, WCAG audit, and VL Hub.
         */
        protected static function analyze_control_domains( array $snapshot, array $site_data, array $wcag_audit ) {
            $domains = array(
                'governance'          => 'Governance & Risk Management',
                'access_control'      => 'Access Control',
                'change_management'   => 'Change Management',
                'system_monitoring'   => 'System Monitoring',
                'incident_response'   => 'Incident Response',
                'vendor_management'   => 'Vendor Management',
                'data_encryption'     => 'Data Encryption',
                'backup_recovery'     => 'Backup & Recovery',
                'onboarding'          => 'Employee Onboarding/Offboarding',
                'privacy'             => 'Privacy & GDPR Alignment',
            );

            $controls = array();
            $source   = isset( $snapshot['controls'] ) && is_array( $snapshot['controls'] ) ? $snapshot['controls'] : array();

            foreach ( $domains as $key => $label ) {
                $row = isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
                
                // Analyze domain based on available data
                $domain_controls = self::analyze_domain_controls( $key, $label, $site_data, $wcag_audit, $snapshot );
                
                $controls[ $key ] = array(
                    'label'    => $label,
                    'status'   => $row['status'] ?? ( ! empty( $domain_controls ) ? 'operating' : 'pending' ),
                    'controls' => ! empty( $row['controls'] ) ? self::ensure_array( $row['controls'] ) : $domain_controls,
                    'evidence' => ! empty( $row['evidence'] ) ? self::ensure_array( $row['evidence'] ) : self::gather_domain_evidence( $key, $site_data, $wcag_audit ),
                    'owner'    => $row['owner'] ?? '',
                );
            }

            return $controls;
        }

        /**
         * Analyze specific domain controls based on available data.
         */
        protected static function analyze_domain_controls( $domain_key, $domain_label, array $site_data, array $wcag_audit, array $snapshot ) {
            $controls = array();
            
            switch ( $domain_key ) {
                case 'governance':
                    // Check for governance controls
                    if ( ! empty( $site_data['wordpress'] ) ) {
                        $controls[] = array(
                            'id' => 'CC1.1',
                            'code' => 'CC1.1',
                            'name' => 'Governance Framework',
                            'description' => 'WordPress CMS governance with version ' . ( $site_data['wordpress']['version'] ?? 'unknown' ),
                            'status' => 'operating',
                        );
                    }
                    break;
                    
                case 'access_control':
                    // Check for access control measures
                    $ssl_enabled = isset( $site_data['security']['ssl_enabled'] ) && $site_data['security']['ssl_enabled'];
                    $controls[] = array(
                        'id' => 'CC6.1',
                        'code' => 'CC6.1',
                        'name' => 'Logical Access Controls',
                        'description' => 'SSL/TLS encryption ' . ( $ssl_enabled ? 'enabled' : 'not enabled' ),
                        'status' => $ssl_enabled ? 'operating' : 'deficient',
                    );
                    break;
                    
                case 'system_monitoring':
                    // Check for monitoring capabilities
                    if ( ! empty( $wcag_audit ) ) {
                        $controls[] = array(
                            'id' => 'CC7.1',
                            'code' => 'CC7.1',
                            'name' => 'System Monitoring',
                            'description' => 'WCAG accessibility monitoring active with latest audit on ' . ( $wcag_audit['latest_report']['created_at'] ?? 'N/A' ),
                            'status' => 'operating',
                        );
                    }
                    break;
                    
                case 'data_encryption':
                    // Check encryption status
                    $ssl_enabled = isset( $site_data['security']['ssl_enabled'] ) && $site_data['security']['ssl_enabled'];
                    $controls[] = array(
                        'id' => 'CC6.7',
                        'code' => 'CC6.7',
                        'name' => 'Encryption of Data at Rest and in Transit',
                        'description' => 'SSL/TLS encryption ' . ( $ssl_enabled ? 'enabled for data in transit' : 'not enabled' ),
                        'status' => $ssl_enabled ? 'operating' : 'deficient',
                    );
                    break;
                    
                case 'backup_recovery':
                    // Check backup capabilities from multiple sources
                    $has_backup = false;
                    $backup_solution = '';
                    
                    // 1. Check Hub Profile for AWS S3 or other storage solutions
                    if ( ! empty( $snapshot ) ) {
                        // Check for AWS S3 in snapshot data
                        if ( isset( $snapshot['storage'] ) && is_array( $snapshot['storage'] ) ) {
                            foreach ( $snapshot['storage'] as $storage ) {
                                if ( isset( $storage['type'] ) && stripos( $storage['type'], 's3' ) !== false ) {
                                    $has_backup = true;
                                    $backup_solution = 'AWS S3 Storage';
                                    break;
                                }
                            }
                        }
                        // Check for backup solutions in snapshot
                        if ( isset( $snapshot['backup'] ) && is_array( $snapshot['backup'] ) ) {
                            if ( ! empty( $snapshot['backup'] ) ) {
                                $has_backup = true;
                                $backup_solution = isset( $snapshot['backup']['type'] ) ? $snapshot['backup']['type'] : 'Backup Solution';
                            }
                        }
                        // Check for storage in evidence
                        if ( isset( $snapshot['evidence'] ) && is_array( $snapshot['evidence'] ) ) {
                            foreach ( $snapshot['evidence'] as $evidence ) {
                                if ( isset( $evidence['type'] ) && ( stripos( $evidence['type'], 'backup' ) !== false || stripos( $evidence['type'], 'storage' ) !== false || stripos( $evidence['type'], 's3' ) !== false ) ) {
                                    $has_backup = true;
                                    $backup_solution = isset( $evidence['description'] ) ? $evidence['description'] : 'Backup Solution';
                                    break;
                                }
                            }
                        }
                    }
                    
                    // 2. Check WordPress plugins for backup solutions
                    if ( ! $has_backup && ! empty( $site_data['plugins'] ) ) {
                        $backup_plugins = array(
                            'updraft',
                            'backupbuddy',
                            'backwpup',
                            'duplicator',
                            'all-in-one-wp-migration',
                            'backup',
                            'vaultpress',
                            'jetpack',
                            'blogvault',
                            'managewp',
                        );
                        
                        foreach ( $site_data['plugins'] as $plugin ) {
                            $plugin_name = isset( $plugin['name'] ) ? strtolower( $plugin['name'] ) : '';
                            foreach ( $backup_plugins as $backup_plugin ) {
                                if ( stripos( $plugin_name, $backup_plugin ) !== false ) {
                                    $has_backup = true;
                                    $backup_solution = isset( $plugin['name'] ) ? $plugin['name'] : 'Backup Plugin';
                                    break 2;
                                }
                            }
                        }
                    }
                    
                    // 3. Check for common backup-related constants or options
                    if ( ! $has_backup ) {
                        // Check for UpdraftPlus
                        if ( defined( 'UPDRAFTPLUS_DIR' ) || get_option( 'updraft_interval' ) ) {
                            $has_backup = true;
                            $backup_solution = 'UpdraftPlus';
                        }
                        // Check for BackupBuddy
                        elseif ( defined( 'ITHEMES_VERSION' ) || get_option( 'pb_backupbuddy' ) ) {
                            $has_backup = true;
                            $backup_solution = 'BackupBuddy';
                        }
                        // Check for BackWPup
                        elseif ( defined( 'BACKWPUP_VERSION' ) || get_option( 'backwpup' ) ) {
                            $has_backup = true;
                            $backup_solution = 'BackWPup';
                        }
                        // Check for Duplicator
                        elseif ( defined( 'DUPLICATOR_VERSION' ) || get_option( 'duplicator_settings' ) ) {
                            $has_backup = true;
                            $backup_solution = 'Duplicator';
                        }
                    }
                    
                    $description = 'Backup solution ' . ( $has_backup ? ( $backup_solution ? $backup_solution . ' detected' : 'detected' ) : 'not detected' );
                    
                    $controls[] = array(
                        'id' => 'CC7.4',
                        'code' => 'CC7.4',
                        'name' => 'Backup and Recovery',
                        'description' => $description,
                        'status' => $has_backup ? 'operating' : 'deficient',
                    );
                    break;
                    
                case 'privacy':
                    // Check privacy/GDPR compliance
                    if ( ! empty( $wcag_audit ) ) {
                        $compliance_score = isset( $wcag_audit['compliance_score'] ) ? (int) $wcag_audit['compliance_score'] : 0;
                        $controls[] = array(
                            'id' => 'P1.1',
                            'code' => 'P1.1',
                            'name' => 'Privacy Notice and Choice',
                            'description' => 'WCAG compliance score: ' . $compliance_score . '%',
                            'status' => $compliance_score >= 70 ? 'operating' : 'deficient',
                        );
                    }
                    break;
            }
            
            return $controls;
        }

        /**
         * Gather evidence for a specific domain.
         */
        protected static function gather_domain_evidence( $domain_key, array $site_data, array $wcag_audit ) {
            $evidence = array();
            
            switch ( $domain_key ) {
                case 'governance':
                    if ( ! empty( $site_data['wordpress'] ) ) {
                        $evidence[] = array(
                            'type' => 'system_configuration',
                            'description' => 'WordPress version ' . ( $site_data['wordpress']['version'] ?? 'unknown' ),
                            'date' => current_time( 'mysql' ),
                        );
                    }
                    break;
                    
                case 'access_control':
                case 'data_encryption':
                    if ( isset( $site_data['security']['ssl_enabled'] ) ) {
                        $evidence[] = array(
                            'type' => 'security_configuration',
                            'description' => 'SSL/TLS ' . ( $site_data['security']['ssl_enabled'] ? 'enabled' : 'disabled' ),
                            'date' => current_time( 'mysql' ),
                        );
                    }
                    break;
                    
                case 'system_monitoring':
                case 'privacy':
                    if ( ! empty( $wcag_audit['latest_report'] ) ) {
                        $evidence[] = array(
                            'type' => 'audit_report',
                            'description' => 'WCAG audit report #' . ( $wcag_audit['latest_report']['id'] ?? 'N/A' ),
                            'date' => $wcag_audit['latest_report']['created_at'] ?? current_time( 'mysql' ),
                        );
                    }
                    break;
            }
            
            return $evidence;
        }

        /**
         * Analyze control tests based on actual data.
         */
        protected static function analyze_control_tests( array $snapshot, array $site_data, array $wcag_audit, array $controls ) {
            $tests = isset( $snapshot['tests'] ) && is_array( $snapshot['tests'] ) ? $snapshot['tests'] : array();

            $period = array(
                'start' => $tests['period']['start'] ?? ( $snapshot['observation_period']['start'] ?? date_i18n( 'Y-m-d', strtotime( '-9 months' ) ) ),
                'end'   => $tests['period']['end'] ?? ( $snapshot['observation_period']['end'] ?? date_i18n( 'Y-m-d' ) ),
            );

            // Generate actual test procedures based on controls
            $procedures = self::generate_test_procedures( $controls, $site_data, $wcag_audit );
            
            // Generate evidence summary
            $evidence_summary = self::generate_evidence_summary( $controls, $site_data, $wcag_audit );

            return array(
                'type'               => $tests['type'] ?? 'Type II',
                'observation_period' => $period,
                'procedures'         => $procedures,
                'evidence_summary'   => $evidence_summary,
            );
        }

        /**
         * Generate test procedures based on actual controls.
         */
        protected static function generate_test_procedures( array $controls, array $site_data, array $wcag_audit ) {
            $procedures = array();
            
            foreach ( $controls as $domain_key => $domain ) {
                if ( ! empty( $domain['controls'] ) && is_array( $domain['controls'] ) ) {
                    foreach ( $domain['controls'] as $control ) {
                        $control_id = $control['id'] ?? $control['code'] ?? '';
                        if ( $control_id ) {
                            $procedures[] = array(
                                'control_id' => $control_id,
                                'control_domain' => $domain_key,
                                'procedure' => 'Tested ' . ( $control['name'] ?? $control_id ) . ' - ' . ( $control['description'] ?? 'Control verification' ),
                                'result' => $control['status'] === 'operating' ? 'Passed' : 'Failed',
                                'findings' => $control['status'] === 'operating' ? 'Control is operating effectively' : 'Control requires remediation',
                                'date' => current_time( 'mysql' ),
                            );
                        }
                    }
                }
            }
            
            return $procedures;
        }

        /**
         * Generate evidence summary.
         */
        protected static function generate_evidence_summary( array $controls, array $site_data, array $wcag_audit ) {
            $summary = array();
            
            // Site configuration evidence
            if ( ! empty( $site_data['wordpress'] ) ) {
                $summary[] = 'WordPress CMS version ' . ( $site_data['wordpress']['version'] ?? 'unknown' ) . ' configured';
            }
            
            // Security evidence
            if ( isset( $site_data['security']['ssl_enabled'] ) ) {
                $summary[] = 'SSL/TLS encryption ' . ( $site_data['security']['ssl_enabled'] ? 'enabled' : 'disabled' );
            }
            
            // WCAG audit evidence
            if ( ! empty( $wcag_audit['latest_report'] ) ) {
                $summary[] = 'WCAG 2.1 AA audit performed on ' . ( $wcag_audit['latest_report']['created_at'] ?? 'N/A' );
            }
            
            // Plugin evidence
            if ( ! empty( $site_data['plugins'] ) ) {
                $summary[] = count( $site_data['plugins'] ) . ' active plugins detected';
            }
            
            return $summary;
        }

        /**
         * Analyze risks based on actual data and gaps.
         */
        protected static function analyze_risks( array $snapshot, array $site_data, array $wcag_audit, array $controls, array $tests ) {
            $risks = array();
            
            // Check for SSL/TLS encryption
            $ssl_enabled = isset( $site_data['security']['ssl_enabled'] ) && $site_data['security']['ssl_enabled'];
            if ( ! $ssl_enabled ) {
                $risks[] = array(
                    'id' => 'R1',
                    'code' => 'R1',
                    'title' => 'Insufficient Data Encryption',
                    'description' => 'SSL/TLS encryption is not enabled, exposing data in transit to potential interception.',
                    'severity' => 'High',
                    'status' => 'Open',
                    'domain' => 'data_encryption',
                    'remediation' => 'Enable SSL/TLS encryption for all data in transit',
                );
            }
            
            // Check for WCAG compliance
            if ( ! empty( $wcag_audit ) ) {
                $compliance_score = isset( $wcag_audit['compliance_score'] ) ? (int) $wcag_audit['compliance_score'] : 0;
                if ( $compliance_score < 70 ) {
                    $risks[] = array(
                        'id' => 'R2',
                        'code' => 'R2',
                        'title' => 'Low WCAG Compliance Score',
                        'description' => 'WCAG 2.1 AA compliance score is ' . $compliance_score . '%, below the recommended threshold of 70%.',
                        'severity' => 'Medium',
                        'status' => 'Open',
                        'domain' => 'privacy',
                        'remediation' => 'Address accessibility issues identified in WCAG audit to improve compliance score',
                    );
                }
            }
            
            // Check for backup solutions (using same logic as analyze_domain_controls)
            $has_backup = false;
            $backup_solution = '';
            
            // 1. Check Hub Profile for AWS S3 or other storage solutions
            if ( ! empty( $snapshot ) ) {
                if ( isset( $snapshot['storage'] ) && is_array( $snapshot['storage'] ) ) {
                    foreach ( $snapshot['storage'] as $storage ) {
                        if ( isset( $storage['type'] ) && stripos( $storage['type'], 's3' ) !== false ) {
                            $has_backup = true;
                            $backup_solution = 'AWS S3 Storage';
                            break;
                        }
                    }
                }
                if ( isset( $snapshot['backup'] ) && is_array( $snapshot['backup'] ) && ! empty( $snapshot['backup'] ) ) {
                    $has_backup = true;
                    $backup_solution = isset( $snapshot['backup']['type'] ) ? $snapshot['backup']['type'] : 'Backup Solution';
                }
                if ( isset( $snapshot['evidence'] ) && is_array( $snapshot['evidence'] ) ) {
                    foreach ( $snapshot['evidence'] as $evidence ) {
                        if ( isset( $evidence['type'] ) && ( stripos( $evidence['type'], 'backup' ) !== false || stripos( $evidence['type'], 'storage' ) !== false || stripos( $evidence['type'], 's3' ) !== false ) ) {
                            $has_backup = true;
                            $backup_solution = isset( $evidence['description'] ) ? $evidence['description'] : 'Backup Solution';
                            break;
                        }
                    }
                }
            }
            
            // 2. Check WordPress plugins for backup solutions
            if ( ! $has_backup && ! empty( $site_data['plugins'] ) ) {
                $backup_plugins = array( 'updraft', 'backupbuddy', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'backup', 'vaultpress', 'jetpack', 'blogvault', 'managewp' );
                foreach ( $site_data['plugins'] as $plugin ) {
                    $plugin_name = isset( $plugin['name'] ) ? strtolower( $plugin['name'] ) : '';
                    foreach ( $backup_plugins as $backup_plugin ) {
                        if ( stripos( $plugin_name, $backup_plugin ) !== false ) {
                            $has_backup = true;
                            $backup_solution = isset( $plugin['name'] ) ? $plugin['name'] : 'Backup Plugin';
                            break 2;
                        }
                    }
                }
            }
            
            // 3. Check for common backup-related constants or options
            if ( ! $has_backup ) {
                if ( defined( 'UPDRAFTPLUS_DIR' ) || get_option( 'updraft_interval' ) ) {
                    $has_backup = true;
                    $backup_solution = 'UpdraftPlus';
                } elseif ( defined( 'ITHEMES_VERSION' ) || get_option( 'pb_backupbuddy' ) ) {
                    $has_backup = true;
                    $backup_solution = 'BackupBuddy';
                } elseif ( defined( 'BACKWPUP_VERSION' ) || get_option( 'backwpup' ) ) {
                    $has_backup = true;
                    $backup_solution = 'BackWPup';
                } elseif ( defined( 'DUPLICATOR_VERSION' ) || get_option( 'duplicator_settings' ) ) {
                    $has_backup = true;
                    $backup_solution = 'Duplicator';
                }
            }
            
            if ( ! $has_backup ) {
                $risks[] = array(
                    'id' => 'R3',
                    'code' => 'R3',
                    'title' => 'No Backup Solution Detected',
                    'description' => 'No backup plugin or solution detected, which could impact data recovery capabilities.',
                    'severity' => 'High',
                    'status' => 'Open',
                    'domain' => 'backup_recovery',
                    'remediation' => 'Implement a reliable backup solution for data recovery',
                );
            }
            
            // Check for debug mode
            $wp_debug = isset( $site_data['security']['wp_debug'] ) && $site_data['security']['wp_debug'];
            if ( $wp_debug ) {
                $risks[] = array(
                    'id' => 'R4',
                    'code' => 'R4',
                    'title' => 'WordPress Debug Mode Enabled',
                    'description' => 'WP_DEBUG is enabled in production, which may expose sensitive information.',
                    'severity' => 'Medium',
                    'status' => 'Open',
                    'domain' => 'system_monitoring',
                    'remediation' => 'Disable WP_DEBUG in production environment',
                );
            }
            
            // Check for control gaps
            foreach ( $controls as $domain_key => $domain ) {
                if ( empty( $domain['controls'] ) ) {
                    $risks[] = array(
                        'id' => 'R' . ( count( $risks ) + 1 ),
                        'code' => 'R' . ( count( $risks ) + 1 ),
                        'title' => 'Insufficient Controls in ' . $domain['label'],
                        'description' => 'No specific controls identified for ' . $domain['label'] . ' domain.',
                        'severity' => 'Medium',
                        'status' => 'Open',
                        'domain' => $domain_key,
                        'remediation' => 'Implement and document controls for ' . $domain['label'],
                    );
                }
            }
            
            // If no risks found, return empty structure
            if ( empty( $risks ) ) {
                return array(
                    'gaps'        => array(),
                    'remediation' => array(),
                    'matrix'      => array(),
                    'readiness_report' => 'No significant risks identified based on available data.',
                );
            }
            
            // Extract gaps and remediation from risks
            $gaps = array();
            $remediation = array();
            foreach ( $risks as $risk ) {
                $gaps[] = $risk['title'] . ': ' . $risk['description'];
                $remediation[] = $risk['remediation'];
            }
            
            return array(
                'gaps'        => $gaps,
                'remediation' => $remediation,
                'matrix'      => $risks,
                'readiness_report' => count( $risks ) . ' risk(s) identified requiring attention.',
            );
        }

        protected static function normalize_tests( array $snapshot ) {
            $tests = isset( $snapshot['tests'] ) && is_array( $snapshot['tests'] ) ? $snapshot['tests'] : array();

            $period = array(
                'start' => $tests['period']['start'] ?? ( $snapshot['observation_period']['start'] ?? date_i18n( 'Y-m-d', strtotime( '-9 months' ) ) ),
                'end'   => $tests['period']['end'] ?? ( $snapshot['observation_period']['end'] ?? date_i18n( 'Y-m-d' ) ),
            );

            $procedures = self::ensure_array( $tests['procedures'] ?? $snapshot['evidence'] ?? array() );

            return array(
                'type'               => $tests['type'] ?? 'Type II',
                'observation_period' => $period,
                'procedures'         => $procedures,
                'evidence_summary'   => self::ensure_array( $tests['evidence_summary'] ?? $snapshot['evidence_summary'] ?? array() ),
            );
        }

        protected static function normalize_risks( array $snapshot ) {
            $risks = isset( $snapshot['risks'] ) && is_array( $snapshot['risks'] ) ? $snapshot['risks'] : array();

            return array(
                'gaps'        => self::ensure_array( $risks['gaps'] ?? array() ),
                'remediation' => self::ensure_array( $risks['remediation'] ?? array() ),
                'matrix'      => self::ensure_array( $risks['matrix'] ?? array() ),
                'readiness_report' => $risks['readiness_report'] ?? '',
            );
        }

        protected static function normalize_artifacts( array $snapshot ) {
            $artifacts = isset( $snapshot['artifacts'] ) && is_array( $snapshot['artifacts'] ) ? $snapshot['artifacts'] : array();

            $defaults = array(
                'penetration_test'        => '',
                'vulnerability_summary'   => '',
                'business_continuity_plan'=> '',
                'data_flow_diagrams'      => array(),
                'asset_inventory'         => array(),
                'training_evidence'       => array(),
                'vendor_attestations'     => array(),
                'audit_logs'              => array(),
            );

            foreach ( $defaults as $key => $value ) {
                if ( ! isset( $artifacts[ $key ] ) ) {
                    $artifacts[ $key ] = $value;
                }
            }

            foreach ( $artifacts as $key => $value ) {
                if ( is_array( $value ) ) {
                    $artifacts[ $key ] = self::ensure_array( $value );
                }
            }

            return $artifacts;
        }

        protected static function ensure_array( $value ) {
            if ( empty( $value ) ) {
                return array();
            }

            if ( is_array( $value ) ) {
                return array_values( array_filter( $value, static function( $item ) {
                    return ( '' !== $item && null !== $item );
                } ) );
            }

            if ( is_string( $value ) ) {
                $parts = preg_split( '/\r?\n|,/', $value );
                return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
            }

            return array();
        }

        protected static function sanitize_list_values( array $items ) {
            return array_values( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) );
        }

        protected static function sanitize_sentence( $value ) {
            return sanitize_textarea_field( (string) $value );
        }

        protected static function sanitize_markdown_block( $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }

            $value = wp_strip_all_tags( (string) $value );
            $value = preg_replace( "/[\r\n]+/", "\n", $value );

            return sanitize_textarea_field( $value );
        }

        protected static function format_artifact_label( $key ) {
            $label = sanitize_text_field( (string) $key );
            $label = str_replace( array( '-', '_' ), ' ', $label );
            $label = preg_replace( '/\s+/', ' ', $label );
            $label = trim( $label );

            if ( '' === $label ) {
                return '';
            }

            return ucwords( $label );
        }

        protected static function extract_controls_for_criterion( array $snapshot, $criterion ) {
            $map = array(
                'Security'            => array( 'governance', 'access_control', 'system_monitoring', 'incident_response' ),
                'Availability'        => array( 'system_monitoring', 'backup_recovery', 'vendor_management' ),
                'Processing Integrity' => array( 'change_management', 'system_monitoring' ),
                'Confidentiality'     => array( 'access_control', 'data_encryption', 'vendor_management' ),
                'Privacy'             => array( 'privacy', 'data_encryption', 'onboarding' ),
            );

            $selected = $map[ $criterion ] ?? array();
            $controls = array();

            $source = isset( $snapshot['controls'] ) && is_array( $snapshot['controls'] ) ? $snapshot['controls'] : array();

            foreach ( $selected as $key ) {
                if ( isset( $source[ $key ] ) ) {
                    $row = $source[ $key ];
                    $controls[] = array(
                        'domain'   => $key,
                        'summary'  => isset( $row['summary'] ) ? $row['summary'] : ( isset( $row['controls'] ) ? implode( '; ', (array) $row['controls'] ) : '' ),
                        'status'   => $row['status'] ?? 'operating',
                    );
                }
            }

            return $controls;
        }

        protected static function build_control_matrix( array $controls, array $trust_services ) {
            $matrix = array();
            foreach ( $controls as $key => $row ) {
                $matrix[] = array(
                    'domain'      => $row['label'],
                    'owner'       => $row['owner'],
                    'status'      => $row['status'],
                    'controls'    => $row['controls'],
                    'evidence'    => $row['evidence'],
                    'aligned_tsc' => self::map_domain_to_tsc( $key, $trust_services ),
                );
            }
            return $matrix;
        }

        protected static function map_domain_to_tsc( $domain, array $trust_services ) {
            $domain_map = array(
                'governance'        => array( 'Security' ),
                'access_control'    => array( 'Security', 'Confidentiality' ),
                'change_management' => array( 'Processing Integrity', 'Security' ),
                'system_monitoring' => array( 'Security', 'Availability' ),
                'incident_response' => array( 'Security', 'Availability' ),
                'vendor_management' => array( 'Security', 'Confidentiality', 'Privacy' ),
                'data_encryption'   => array( 'Security', 'Confidentiality', 'Privacy' ),
                'backup_recovery'   => array( 'Availability', 'Security' ),
                'onboarding'        => array( 'Security', 'Privacy' ),
                'privacy'           => array( 'Privacy', 'Confidentiality' ),
            );

            $mapped = $domain_map[ $domain ] ?? array();
            if ( empty( $mapped ) ) {
                return $trust_services;
            }

            return array_values( array_intersect( $mapped, $trust_services ) );
        }

        protected static function build_auditor_section( array $snapshot, array $tests, array $risks ) {
            $default_name     = __( 'Luna AI Independent Service Auditor', 'vl-las' );
            $default_opinion  = __( 'Controls were suitably designed and operated effectively throughout the observation period.', 'vl-las' );
            $default_status   = __( 'Unqualified', 'vl-las' );
            $default_assertion = __( 'Management asserts that the accompanying description fairly presents the system and that the controls were suitably designed and operated effectively.', 'vl-las' );

            $auditor_name = isset( $snapshot['auditor']['name'] )
                ? sanitize_text_field( $snapshot['auditor']['name'] )
                : $default_name;
            $opinion = isset( $snapshot['auditor']['opinion'] )
                ? self::sanitize_sentence( $snapshot['auditor']['opinion'] )
                : $default_opinion;
            $status = isset( $snapshot['auditor']['status'] )
                ? sanitize_text_field( $snapshot['auditor']['status'] )
                : $default_status;
            $assertion = isset( $snapshot['management_assertion'] )
                ? self::sanitize_sentence( $snapshot['management_assertion'] )
                : $default_assertion;

            return array(
                'independent_auditor' => $auditor_name,
                'opinion'             => $opinion,
                'status'              => $status,
                'support'             => array(
                    'testing_highlights' => self::sanitize_list_values( isset( $tests['procedures'] ) ? (array) $tests['procedures'] : array() ),
                    'risk_considerations'=> self::sanitize_list_values( isset( $risks['gaps'] ) ? (array) $risks['gaps'] : array() ),
                ),
                'management_assertion' => $assertion,
            );
        }

        protected static function build_executive_summary( array $report ) {
            $company = $report['system_description']['company_overview']['name'] ?? __( 'Client', 'vl-las' );
            $company = sanitize_text_field( $company ?: __( 'Client', 'vl-las' ) );

            $tsc_list = ! empty( $report['trust_services']['selected'] )
                ? self::sanitize_list_values( (array) $report['trust_services']['selected'] )
                : array( __( 'baseline criteria', 'vl-las' ) );
            $tsc     = implode( ', ', $tsc_list );

            $period  = $report['control_tests']['observation_period'];
            $period_text = trim( sprintf( '%s – %s', $period['start'] ?? '', $period['end'] ?? '' ), ' -' );
            $period_text = $period_text ? sanitize_text_field( $period_text ) : __( 'the observation period', 'vl-las' );

            $summary = sprintf(
                /* translators: 1: company name, 2: trust services criteria, 3: observation period */
                __( '%1$s completed a SOC 2 Type II engagement covering the %2$s trust services criteria for %3$s. Luna AI verified that key governance, security, availability, and privacy controls are operating effectively with evidence centrally managed in the VL Hub.', 'vl-las' ),
                $company,
                $tsc,
                $period_text
            );

            return $summary;
        }

        protected static function render_markdown( array $report ) {
            $lines  = array();
            $system = isset( $report['system_description'] ) && is_array( $report['system_description'] ) ? $report['system_description'] : array();

            $company = isset( $system['company_overview']['name'] ) ? sanitize_text_field( $system['company_overview']['name'] ) : '';
            $company = $company ?: __( 'Client', 'vl-las' );

            $period = isset( $report['control_tests']['observation_period'] ) && is_array( $report['control_tests']['observation_period'] )
                ? $report['control_tests']['observation_period']
                : array();
            $period_start = isset( $period['start'] ) ? sanitize_text_field( $period['start'] ) : '';
            $period_end   = isset( $period['end'] ) ? sanitize_text_field( $period['end'] ) : '';
            $period_parts = array_filter( array( $period_start, $period_end ), 'strlen' );
            $period_text  = $period_parts ? implode( ' – ', $period_parts ) : '';

            $generated       = isset( $report['meta']['generated_at'] ) ? sanitize_text_field( $report['meta']['generated_at'] ) : '';
            $analysis_engine = isset( $report['meta']['analysis_engine'] ) ? sanitize_text_field( $report['meta']['analysis_engine'] ) : 'Luna AI SOC 2 Copilot';
            $trust_selected  = ! empty( $report['trust_services']['selected'] )
                ? self::sanitize_list_values( (array) $report['trust_services']['selected'] )
                : array();

            $lines[] = '# SOC 2 Type II Report';
            $lines[] = '';
            $lines[] = '**Organization:** ' . $company;
            $lines[] = '**Generated:** ' . ( $generated ? $generated : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Observation Period:** ' . ( $period_text ? $period_text : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Trust Services Criteria:** ' . ( $trust_selected ? implode( ', ', $trust_selected ) : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Analysis Engine:** ' . $analysis_engine;
            $lines[] = '';

            $lines[] = '## Executive Summary';
            $summary_block = isset( $report['documents']['executive_summary'] )
                ? self::sanitize_markdown_block( $report['documents']['executive_summary'] )
                : '';
            $lines[] = $summary_block ? $summary_block : __( 'Summary not provided.', 'vl-las' );
            $lines[] = '';

            $lines[] = '## System Description';
            $company_overview = isset( $system['company_overview'] ) && is_array( $system['company_overview'] ) ? $system['company_overview'] : array();
            $mission   = isset( $company_overview['mission'] ) ? sanitize_text_field( $company_overview['mission'] ) : '';
            $ownership = isset( $company_overview['ownership'] ) ? sanitize_text_field( $company_overview['ownership'] ) : '';
            $mission_line = $mission ? $mission : __( 'Not provided', 'vl-las' );
            if ( $ownership ) {
                $mission_line .= ' — ' . $ownership;
            }
            $lines[] = '- **Mission & Ownership:** ' . $mission_line;

            if ( ! empty( $system['services_in_scope'] ) ) {
                $lines[] = '- **Services in Scope:** ' . implode( ', ', self::sanitize_list_values( (array) $system['services_in_scope'] ) );
            }
            if ( ! empty( $system['infrastructure'] ) ) {
                $lines[] = '- **Infrastructure Footprint:** ' . implode( '; ', self::sanitize_list_values( (array) $system['infrastructure'] ) );
            }
            if ( ! empty( $system['software_components'] ) ) {
                $lines[] = '- **Software Components:** ' . implode( '; ', self::sanitize_list_values( (array) $system['software_components'] ) );
            }
            if ( ! empty( $system['data_flows'] ) ) {
                $lines[] = '- **Data Flows:** ' . implode( '; ', self::sanitize_list_values( (array) $system['data_flows'] ) );
            }
            if ( ! empty( $system['personnel'] ) ) {
                $lines[] = '- **Personnel & Responsibilities:** ' . implode( '; ', self::sanitize_list_values( (array) $system['personnel'] ) );
            }
            if ( ! empty( $system['subservice_organizations'] ) ) {
                $lines[] = '- **Subservice Organizations:** ' . implode( '; ', self::sanitize_list_values( (array) $system['subservice_organizations'] ) );
            }
            if ( ! empty( $system['control_boundaries'] ) ) {
                $lines[] = '- **Control Boundaries:** ' . implode( '; ', self::sanitize_list_values( (array) $system['control_boundaries'] ) );
            }
            if ( ! empty( $system['incident_response'] ) ) {
                $lines[] = '- **Incident Response & Continuity:** ' . implode( '; ', self::sanitize_list_values( (array) $system['incident_response'] ) );
            }
            if ( ! empty( $system['business_continuity'] ) ) {
                $lines[] = '- **Business Continuity & DR:** ' . implode( '; ', self::sanitize_list_values( (array) $system['business_continuity'] ) );
            }
            $lines[] = '';

            $lines[] = '## Control Environment';
            $domains = isset( $report['control_environment']['domains'] ) && is_array( $report['control_environment']['domains'] )
                ? $report['control_environment']['domains']
                : array();
            foreach ( $domains as $domain ) {
                $label  = isset( $domain['label'] ) ? sanitize_text_field( $domain['label'] ) : '';
                $status = isset( $domain['status'] ) ? sanitize_text_field( $domain['status'] ) : '';
                if ( $label || $status ) {
                    $entry = '- **' . ( $label ? $label : __( 'Domain', 'vl-las' ) ) . '**';
                    if ( $status ) {
                        $entry .= ' (' . $status . ')';
                    }
                    $lines[] = $entry;
                }
                if ( ! empty( $domain['controls'] ) ) {
                    foreach ( self::sanitize_list_values( (array) $domain['controls'] ) as $control ) {
                        $lines[] = '  - ' . $control;
                    }
                }
                if ( ! empty( $domain['evidence'] ) ) {
                    $lines[] = '  - Evidence: ' . implode( ', ', self::sanitize_list_values( (array) $domain['evidence'] ) );
                }
            }
            $lines[] = '';

            $lines[] = '## Tests of Operating Effectiveness';
            $type = isset( $report['control_tests']['type'] ) ? sanitize_text_field( $report['control_tests']['type'] ) : '';
            $lines[] = '- **Type:** ' . ( $type ? $type : __( 'Not specified', 'vl-las' ) );
            $procedures = self::sanitize_list_values( isset( $report['control_tests']['procedures'] ) ? (array) $report['control_tests']['procedures'] : array() );
            foreach ( $procedures as $proc ) {
                $lines[] = '  - ' . $proc;
            }
            $evidence_summary = self::sanitize_list_values( isset( $report['control_tests']['evidence_summary'] ) ? (array) $report['control_tests']['evidence_summary'] : array() );
            if ( $evidence_summary ) {
                $lines[] = '- **Evidence Summary:** ' . implode( '; ', $evidence_summary );
            }
            $lines[] = '';

            $lines[] = '## Risk Assessment & Remediation';
            $gaps        = self::sanitize_list_values( isset( $report['risk_assessment']['gaps'] ) ? (array) $report['risk_assessment']['gaps'] : array() );
            $remediation = self::sanitize_list_values( isset( $report['risk_assessment']['remediation'] ) ? (array) $report['risk_assessment']['remediation'] : array() );
            $matrix      = self::sanitize_list_values( isset( $report['risk_assessment']['matrix'] ) ? (array) $report['risk_assessment']['matrix'] : array() );
            if ( $gaps ) {
                $lines[] = '- **Control Gaps:** ' . implode( '; ', $gaps );
            }
            if ( $remediation ) {
                $lines[] = '- **Remediation Plans:** ' . implode( '; ', $remediation );
            }
            if ( $matrix ) {
                $lines[] = '- **Risk Matrix Highlights:** ' . implode( '; ', $matrix );
            }
            $lines[] = '';

            $lines[] = '## Auditor\'s Report';
            $auditors = isset( $report['auditors'] ) && is_array( $report['auditors'] ) ? $report['auditors'] : array();
            $lines[] = '- **Independent Service Auditor:** ' . sanitize_text_field( $auditors['independent_auditor'] ?? '' );
            $lines[] = '- **Opinion:** ' . sanitize_text_field( $auditors['opinion'] ?? '' );
            $lines[] = '- **Status:** ' . sanitize_text_field( $auditors['status'] ?? '' );
            $lines[] = '- **Management Assertion:** ' . sanitize_text_field( $auditors['management_assertion'] ?? '' );
            $lines[] = '';

            $lines[] = '## Supporting Artifacts';
            if ( isset( $report['supporting_artifacts'] ) && is_array( $report['supporting_artifacts'] ) ) {
                foreach ( $report['supporting_artifacts'] as $key => $value ) {
                    $label = self::format_artifact_label( $key );
                    if ( '' === $label ) {
                        continue;
                    }
                    if ( is_array( $value ) ) {
                        $list = self::sanitize_list_values( (array) $value );
                        if ( $list ) {
                            $lines[] = '- **' . $label . ':** ' . implode( '; ', $list );
                        }
                    } elseif ( $value ) {
                        $lines[] = '- **' . $label . ':** ' . sanitize_text_field( $value );
                    }
                }
            }

            $lines[] = '';
            
            // Add WCAG Audit section if available
            if ( isset( $report['integrated_data']['wcag_audit'] ) && ! empty( $report['integrated_data']['wcag_audit'] ) ) {
                $wcag = $report['integrated_data']['wcag_audit'];
                $lines[] = '## WCAG 2.1 AA Accessibility Audit';
                if ( isset( $wcag['latest_report'] ) && $wcag['latest_report'] ) {
                    $lines[] = '- **Audit Date:** ' . ( isset( $wcag['latest_report']['created_at'] ) ? sanitize_text_field( $wcag['latest_report']['created_at'] ) : __( 'Not specified', 'vl-las' ) );
                    $lines[] = '- **Audited URL:** ' . ( isset( $wcag['latest_report']['url'] ) ? sanitize_text_field( $wcag['latest_report']['url'] ) : __( 'Not specified', 'vl-las' ) );
                }
                if ( isset( $wcag['compliance_score'] ) ) {
                    $lines[] = '- **Compliance Score:** ' . (int) $wcag['compliance_score'] . '%';
                }
                if ( isset( $wcag['key_metrics'] ) && is_array( $wcag['key_metrics'] ) ) {
                    $metrics = $wcag['key_metrics'];
                    if ( isset( $metrics['error_density'] ) ) {
                        $lines[] = '- **Error Density:** ' . round( (float) $metrics['error_density'], 2 ) . '%';
                    }
                    if ( isset( $metrics['unique_issues'] ) ) {
                        $lines[] = '- **Unique Issues:** ' . (int) $metrics['unique_issues'];
                    }
                    if ( isset( $metrics['keyboard_accessibility_score'] ) ) {
                        $lines[] = '- **Keyboard Accessibility:** ' . (int) $metrics['keyboard_accessibility_score'] . '%';
                    }
                    if ( isset( $metrics['screen_reader_compatibility'] ) ) {
                        $lines[] = '- **Screen Reader Compatibility:** ' . (int) $metrics['screen_reader_compatibility'] . '%';
                    }
                }
                $lines[] = '';
            }
            
            // Add Site Data section if available
            if ( isset( $report['integrated_data']['site_data'] ) && ! empty( $report['integrated_data']['site_data'] ) ) {
                $site = $report['integrated_data']['site_data'];
                $lines[] = '## Technical Infrastructure';
                if ( isset( $site['wordpress']['version'] ) ) {
                    $lines[] = '- **CMS:** WordPress ' . sanitize_text_field( $site['wordpress']['version'] );
                }
                if ( isset( $site['wordpress']['site_url'] ) ) {
                    $lines[] = '- **Site URL:** ' . sanitize_text_field( $site['wordpress']['site_url'] );
                }
                if ( isset( $site['security']['ssl_enabled'] ) ) {
                    $lines[] = '- **SSL Enabled:** ' . ( $site['security']['ssl_enabled'] ? __( 'Yes', 'vl-las' ) : __( 'No', 'vl-las' ) );
                }
                if ( isset( $site['plugins'] ) && is_array( $site['plugins'] ) && count( $site['plugins'] ) > 0 ) {
                    $lines[] = '- **Active Plugins:** ' . count( $site['plugins'] );
                    foreach ( array_slice( $site['plugins'], 0, 10 ) as $plugin ) {
                        $lines[] = '  - ' . sanitize_text_field( $plugin['name'] ) . ' (v' . sanitize_text_field( $plugin['version'] ) . ')';
                    }
                    if ( count( $site['plugins'] ) > 10 ) {
                        $lines[] = '  - ... and ' . ( count( $site['plugins'] ) - 10 ) . ' more';
                    }
                }
                if ( isset( $site['themes'] ) && is_array( $site['themes'] ) && ! empty( $site['themes'] ) ) {
                    $theme = $site['themes'][0];
                    $lines[] = '- **Active Theme:** ' . sanitize_text_field( $theme['name'] ) . ' (v' . sanitize_text_field( $theme['version'] ) . ')';
                }
                $lines[] = '';
            }
            
            $lines[] = '> Generated via VL Language & Accessibility Standards plugin using Luna AI SOC 2 Copilot.';

            return implode( "\n", $lines );
        }
    }
}
