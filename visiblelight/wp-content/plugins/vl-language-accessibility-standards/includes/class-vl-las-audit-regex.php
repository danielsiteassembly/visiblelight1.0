<?php
/**
 * Regex-only WCAG-ish audit (safe, no DOM).
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Audit_Regex {

    /**
     * Run audit against an HTML string (preferred) or a fetched URL fallback.
     *
     * @param string $html Raw HTML (optional but recommended).
     * @param string $url  For metadata; not fetched here.
     * @return array Report payload
     */
    public static function run( $html, $url ) {
        $now  = current_time( 'mysql', 1 ); // GMT
        $out  = array(
            'ok'      => true,
            'engine'  => 'regex',
            'ts'      => $now,
            'url'     => esc_url_raw( $url ?: home_url('/') ),
            'summary' => array(),
            'checks'  => array(),
        );

        if ( ! is_string( $html ) ) {
            $html = '';
        }

        // Basic helpers
        $has = function( $pattern ) use ( $html ) {
            return (bool) preg_match( $pattern, $html );
        };
        $count = function( $pattern ) use ( $html ) {
            return preg_match_all( $pattern, $html, $m );
        };

        // --- Individual checks (boolean + details where relevant) ---

        // Title present
        self::push( $out, 'document_title', array(
            'ok'   => $has( '/<title\b[^>]*>.*?<\/title>/is' ),
            'why'  => 'Ensure Document has a <title> element',
        ) );

        // <html lang="..">
        self::push( $out, 'html_lang', array(
            'ok'   => $has( '/<html\b[^>]*\blang=[\'"][^\'"]+[\'"][^>]*>/i' ),
            'why'  => 'Ensure <html> element has [lang] attribute',
        ) );

        // Images have alt (flags images missing alt)
        $img_total = $count( '/<img\b[^>]*>/i' );
        $img_noalt = $count( '/<img\b(?![^>]*\balt=)[^>]*>/i' );
        self::push( $out, 'img_alt', array(
            'ok'      => ($img_total === 0) ? true : ($img_noalt === 0),
            'why'     => 'Ensure image elements have [alt] attributes',
            'metrics' => compact( 'img_total', 'img_noalt' ),
        ) );

        // Buttons have accessible name (text or aria-label)
        $btn_total = $count( '/<button\b[^>]*>.*?<\/button>/is' ) + $count( '/<input\b[^>]*\btype=[\'"](button|submit|reset)[\'"][^>]*>/i' );
        $btn_bad   = $count( '/<button\b(?:(?!aria-label).)*?>\s*<\/button>/is' ) + $count( '/<input\b[^>]*\btype=[\'"](button|submit|reset)[\'"][^>]*?(?!(value|aria-label)=)[^>]*>/i' );
        self::push( $out, 'buttons_accessible_name', array(
            'ok'      => ($btn_total === 0) ? true : ($btn_bad === 0),
            'why'     => 'Ensure buttons have an accessible name',
            'metrics' => compact( 'btn_total', 'btn_bad' ),
        ) );

        // Links have discernable names (text, img alt, or aria-label)
        $a_total = $count( '/<a\b[^>]*>/i' );
        $a_bad_text = $count( '/<a\b[^>]*>(?:\s|&nbsp;|<\!--.*?-->)*<\/a>/is' ); // empty anchors
        $a_bad_aria = $count( '/<a\b(?![^>]*aria-label=)[^>]*>(?:\s|&nbsp;|<img\b(?![^>]*alt=)[^>]*>)*<\/a>/is' );
        self::push( $out, 'links_discernable', array(
            'ok'      => ($a_total === 0) ? true : ($a_bad_text + $a_bad_aria === 0),
            'why'     => 'Ensure links have discernable names',
            'metrics' => array( 'a_total' => $a_total, 'a_bad_empty' => $a_bad_text, 'a_bad_missing_label' => $a_bad_aria ),
        ) );

        // Meta viewport anti-patterns
        $bad_viewport = $has( '/<meta\b[^>]*name=[\'"]viewport[\'"][^>]*\b(user-scalable\s*=\s*no|maximum-scale\s*=\s*(?:[0-4](?:\.\d+)?|[01]))/i' );
        self::push( $out, 'viewport_scaling', array(
            'ok'  => ! $bad_viewport,
            'why' => 'Ensure [user-scalable="no"] is not used and [maximum-scale] is not less than 5',
        ) );

        // Basic ARIA validity hints (very light regex sanity)
        $bad_aria_misspell = $has( '/\baria-[a-z0-9-]*[A-Z][a-zA-Z0-9-]*=/i' ); // uppercase inside aria-* is suspicious
        self::push( $out, 'aria_attributes_valid', array(
            'ok'  => ! $bad_aria_misspell,
            'why' => 'Ensure [aria-*] attributes are valid and not misspelled',
        ) );

        // Headings descending (heuristic)
        $headings = array();
        if ( preg_match_all( '/<(h[1-6])\b[^>]*>.*?<\/\1>/is', $html, $m ) ) {
            foreach ( $m[1] as $h ) {
                $headings[] = (int) substr( $h, 1 );
            }
        }
        $non_descending = 0;
        if ( $headings ) {
            $prev = $headings[0];
            foreach ( $headings as $idx => $lev ) {
                if ( $idx === 0 ) continue;
                if ( $lev - $prev > 1 ) $non_descending++;
                $prev = $lev;
            }
        }
        self::push( $out, 'headings_sequential', array(
            'ok'      => ($headings ? $non_descending === 0 : true),
            'why'     => 'Ensure heading elements are in sequentially-descending order',
            'metrics' => array( 'headings' => $headings, 'breaks' => $non_descending ),
        ) );

        // Touch target heuristic (can’t measure dimensions w/o layout; just flag presence of tiny <a> with 1 char)
        $tiny_links = $count( '/<a\b[^>]*>[\s\S]{0,1}<\/a>/i' );
        self::push( $out, 'touch_target_size_hint', array(
            'ok'      => ($tiny_links === 0),
            'why'     => 'Ensure touch targets have sufficient size and spacing (heuristic)',
            'metrics' => array( 'tiny_links' => $tiny_links ),
        ) );

        // Lists contain only <li> (basic)
        $bad_ul = $has( '/<ul\b[^>]*>(?:(?!<\/ul>).)*<(?!li\b|\/li\b|script\b|template\b)[a-z]/is' );
        $bad_ol = $has( '/<ol\b[^>]*>(?:(?!<\/ol>).)*<(?!li\b|\/li\b|script\b|template\b)[a-z]/is' );
        self::push( $out, 'lists_only_li', array(
            'ok'  => ! ($bad_ul || $bad_ol),
            'why' => 'Ensure Lists contain only <li> elements and script/template',
        ) );

        // Final summary
        $total  = count( $out['checks'] );
        $passed = count( array_filter( $out['checks'], function( $c ){ return ! empty( $c['ok'] ); } ) );
        $failed = $total - $passed;
        
        // Calculate Error Density (errors per total elements checked)
        $error_density = $total > 0 ? round( ( $failed / $total ) * 100, 2 ) : 0;
        
        // Map checks to WCAG Levels and calculate compliance
        $wcag_mapping = self::map_checks_to_wcag( $out['checks'] );
        $wcag_compliance = self::calculate_wcag_compliance( $wcag_mapping, $out['checks'] );
        
        // Count Unique Issues (unique failure types)
        $unique_issues = self::count_unique_issues( $out['checks'] );
        
        // Categorize User Impact
        $user_impact = self::categorize_user_impact( $out['checks'] );
        
        // Calculate Keyboard Accessibility Score (with detailed breakdown)
        $keyboard_data = self::calculate_keyboard_accessibility( $html, $out['checks'] );
        $keyboard_score = is_array( $keyboard_data ) ? $keyboard_data['score'] : $keyboard_data;
        
        // Calculate Screen Reader Compatibility Score (with detailed breakdown)
        $screen_reader_data = self::calculate_screen_reader_compatibility( $html, $out['checks'] );
        $screen_reader_score = is_array( $screen_reader_data ) ? $screen_reader_data['score'] : $screen_reader_data;
        
        $out['summary'] = array(
            'passed' => $passed,
            'total'  => $total,
            'score'  => $total ? round( ( $passed / $total ) * 100 ) : 100,
            'error_density' => $error_density,
            'wcag_compliance' => $wcag_compliance,
            'unique_issues' => $unique_issues,
            'user_impact' => $user_impact,
            'keyboard_accessibility_score' => $keyboard_score,
            'keyboard_accessibility_details' => is_array( $keyboard_data ) ? $keyboard_data : null,
            'screen_reader_compatibility' => $screen_reader_score,
            'screen_reader_compatibility_details' => is_array( $screen_reader_data ) ? $screen_reader_data : null,
        );

        return $out;
    }

    private static function push( array &$out, $id, array $data ) {
        $data['id'] = (string) $id;
        $out['checks'][] = $data;
    }

    /**
     * Map checks to WCAG 2.1 Levels (A, AA, AAA)
     */
    private static function map_checks_to_wcag( array $checks ) {
        $wcag_map = array(
            'document_title' => array( 'level' => 'A', 'criterion' => '2.4.2' ),
            'html_lang' => array( 'level' => 'A', 'criterion' => '3.1.1' ),
            'img_alt' => array( 'level' => 'A', 'criterion' => '1.1.1' ),
            'buttons_accessible_name' => array( 'level' => 'A', 'criterion' => '4.1.2' ),
            'links_discernable' => array( 'level' => 'A', 'criterion' => '2.4.4' ),
            'viewport_scaling' => array( 'level' => 'AA', 'criterion' => '1.4.4' ),
            'aria_attributes_valid' => array( 'level' => 'A', 'criterion' => '4.1.2' ),
            'headings_sequential' => array( 'level' => 'A', 'criterion' => '1.3.1' ),
            'touch_target_size_hint' => array( 'level' => 'AA', 'criterion' => '2.5.5' ),
            'lists_only_li' => array( 'level' => 'A', 'criterion' => '1.3.1' ),
        );
        
        $mapped = array();
        foreach ( $checks as $check ) {
            $id = isset( $check['id'] ) ? $check['id'] : '';
            if ( isset( $wcag_map[ $id ] ) ) {
                $mapped[ $id ] = $wcag_map[ $id ];
            }
        }
        
        return $mapped;
    }

    /**
     * Calculate WCAG Compliance by level
     */
    private static function calculate_wcag_compliance( array $wcag_map, array $checks ) {
        $levels = array( 'A' => array( 'total' => 0, 'passed' => 0 ), 'AA' => array( 'total' => 0, 'passed' => 0 ), 'AAA' => array( 'total' => 0, 'passed' => 0 ) );
        
        foreach ( $checks as $check ) {
            $id = isset( $check['id'] ) ? $check['id'] : '';
            if ( isset( $wcag_map[ $id ] ) ) {
                $level = $wcag_map[ $id ]['level'];
                if ( isset( $levels[ $level ] ) ) {
                    $levels[ $level ]['total']++;
                    if ( ! empty( $check['ok'] ) ) {
                        $levels[ $level ]['passed']++;
                    }
                }
            }
        }
        
        $compliance = array();
        foreach ( $levels as $level => $data ) {
            $compliance[ $level ] = array(
                'total' => $data['total'],
                'passed' => $data['passed'],
                'score' => $data['total'] > 0 ? round( ( $data['passed'] / $data['total'] ) * 100 ) : 100,
                'compliant' => $data['total'] > 0 && $data['passed'] === $data['total'],
            );
        }
        
        return $compliance;
    }

    /**
     * Count unique issues (unique failure types)
     */
    private static function count_unique_issues( array $checks ) {
        $failed_checks = array_filter( $checks, function( $c ){ return empty( $c['ok'] ); } );
        $unique_types = array();
        foreach ( $failed_checks as $check ) {
            $id = isset( $check['id'] ) ? $check['id'] : '';
            if ( $id && ! in_array( $id, $unique_types, true ) ) {
                $unique_types[] = $id;
            }
        }
        return count( $unique_types );
    }

    /**
     * Categorize User Impact (Critical, High, Medium, Low)
     */
    private static function categorize_user_impact( array $checks ) {
        // Impact mapping based on WCAG level and check type
        $impact_map = array(
            'document_title' => 'critical',      // Blocks screen readers
            'html_lang' => 'high',              // Language detection critical
            'img_alt' => 'high',                // Visual content accessibility
            'buttons_accessible_name' => 'critical', // Blocks interaction
            'links_discernable' => 'high',      // Navigation critical
            'viewport_scaling' => 'medium',     // Mobile usability
            'aria_attributes_valid' => 'high', // Screen reader compatibility
            'headings_sequential' => 'medium', // Structure/navigation
            'touch_target_size_hint' => 'medium', // Mobile usability
            'lists_only_li' => 'low',           // Structure issue
        );
        
        $impact_counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
        
        foreach ( $checks as $check ) {
            if ( empty( $check['ok'] ) ) {
                $id = isset( $check['id'] ) ? $check['id'] : '';
                $impact = isset( $impact_map[ $id ] ) ? $impact_map[ $id ] : 'medium';
                if ( isset( $impact_counts[ $impact ] ) ) {
                    $impact_counts[ $impact ]++;
                }
            }
        }
        
        return $impact_counts;
    }

    /**
     * Calculate Keyboard Accessibility Score (returns detailed breakdown)
     */
    private static function calculate_keyboard_accessibility( $html, array $checks ) {
        // Count interactive elements that should be keyboard accessible
        $button_count = preg_match_all( '/<(?:button|input\b[^>]*type=[\'"](?:button|submit|reset)[\'"])[^>]*>/i', $html );
        $link_count = preg_match_all( '/<a\b[^>]*>/i', $html );
        $form_count = preg_match_all( '/<(?:input|select|textarea)\b[^>]*>/i', $html );
        $interactive_count = $button_count + $link_count + $form_count;
        
        if ( $interactive_count === 0 ) {
            return array(
                'score' => 100,
                'total_elements' => 0,
                'accessible_elements' => 0,
                'breakdown' => array(
                    'buttons' => array( 'total' => 0, 'accessible' => 0, 'check_passed' => true ),
                    'links' => array( 'total' => 0, 'accessible' => 0, 'check_passed' => true ),
                    'forms' => array( 'total' => 0, 'accessible' => 0, 'check_passed' => true ),
                ),
            );
        }
        
        // Count accessible elements based on check results
        $keyboard_accessible = 0;
        $buttons_accessible = 0;
        $links_accessible = 0;
        $buttons_check_passed = false;
        $links_check_passed = false;
        
        // Buttons are keyboard accessible if they have accessible names
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'buttons_accessible_name' ) {
                $buttons_check_passed = ! empty( $check['ok'] );
                if ( $buttons_check_passed ) {
                    $buttons_accessible = $button_count;
                    $keyboard_accessible += $button_count;
                }
                break;
            }
        }
        
        // Links are keyboard accessible if they have discernable names
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'links_discernable' ) {
                $links_check_passed = ! empty( $check['ok'] );
                if ( $links_check_passed ) {
                    $links_accessible = $link_count;
                    $keyboard_accessible += $link_count;
                }
                break;
            }
        }
        
        // Form elements are generally keyboard accessible by default
        $keyboard_accessible += $form_count;
        
        return array(
            'score' => round( ( $keyboard_accessible / $interactive_count ) * 100 ),
            'total_elements' => $interactive_count,
            'accessible_elements' => $keyboard_accessible,
            'breakdown' => array(
                'buttons' => array( 'total' => $button_count, 'accessible' => $buttons_accessible, 'check_passed' => $buttons_check_passed ),
                'links' => array( 'total' => $link_count, 'accessible' => $links_accessible, 'check_passed' => $links_check_passed ),
                'forms' => array( 'total' => $form_count, 'accessible' => $form_count, 'check_passed' => true ),
            ),
        );
    }

    /**
     * Calculate Screen Reader Compatibility Score (returns detailed breakdown)
     */
    private static function calculate_screen_reader_compatibility( $html, array $checks ) {
        // Count elements that need to be screen reader compatible
        $img_count = preg_match_all( '/<img\b[^>]*>/i', $html );
        $button_count = preg_match_all( '/<(?:button|input\b[^>]*type=[\'"](?:button|submit|reset)[\'"])[^>]*>/i', $html );
        $link_count = preg_match_all( '/<a\b[^>]*>/i', $html );
        $heading_count = preg_match_all( '/<h[1-6]\b[^>]*>/i', $html );
        $aria_count = preg_match_all( '/\baria-[a-z-]+=/i', $html );
        
        // Document title and lang are critical (count as 2 elements)
        $has_title = preg_match( '/<title\b[^>]*>.*?<\/title>/is', $html );
        $has_lang = preg_match( '/<html\b[^>]*\blang=[\'"][^\'"]+[\'"][^>]*>/i', $html );
        
        $total_elements = $img_count + $button_count + $link_count + $heading_count + $aria_count + 2; // +2 for title/lang
        
        if ( $total_elements === 0 ) {
            return array(
                'score' => 100,
                'total_elements' => 0,
                'compatible_elements' => 0,
                'breakdown' => array(
                    'images' => array( 'total' => 0, 'compatible' => 0, 'check_passed' => true ),
                    'buttons' => array( 'total' => 0, 'compatible' => 0, 'check_passed' => true ),
                    'links' => array( 'total' => 0, 'compatible' => 0, 'check_passed' => true ),
                    'headings' => array( 'total' => 0, 'compatible' => 0, 'check_passed' => true ),
                    'aria' => array( 'total' => 0, 'compatible' => 0, 'check_passed' => true ),
                    'document' => array( 'title' => false, 'lang' => false, 'both_passed' => false ),
                ),
            );
        }
        
        $screen_reader_compatible = 0;
        $images_compatible = 0;
        $buttons_compatible = 0;
        $links_compatible = 0;
        $headings_compatible = 0;
        $aria_compatible = 0;
        $images_check_passed = false;
        $buttons_check_passed = false;
        $links_check_passed = false;
        $headings_check_passed = false;
        $aria_check_passed = false;
        
        // Check images (need alt)
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'img_alt' ) {
                $images_check_passed = ! empty( $check['ok'] );
                if ( $images_check_passed ) {
                    $images_compatible = $img_count;
                    $screen_reader_compatible += $img_count;
                }
                break;
            }
        }
        
        // Check buttons (need accessible name)
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'buttons_accessible_name' ) {
                $buttons_check_passed = ! empty( $check['ok'] );
                if ( $buttons_check_passed ) {
                    $buttons_compatible = $button_count;
                    $screen_reader_compatible += $button_count;
                }
                break;
            }
        }
        
        // Check links (need discernable name)
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'links_discernable' ) {
                $links_check_passed = ! empty( $check['ok'] );
                if ( $links_check_passed ) {
                    $links_compatible = $link_count;
                    $screen_reader_compatible += $link_count;
                }
                break;
            }
        }
        
        // Check headings (structure)
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'headings_sequential' ) {
                $headings_check_passed = ! empty( $check['ok'] );
                if ( $headings_check_passed ) {
                    $headings_compatible = $heading_count;
                    $screen_reader_compatible += $heading_count;
                }
                break;
            }
        }
        
        // Check ARIA validity
        foreach ( $checks as $check ) {
            if ( isset( $check['id'] ) && $check['id'] === 'aria_attributes_valid' ) {
                $aria_check_passed = ! empty( $check['ok'] );
                if ( $aria_check_passed ) {
                    $aria_compatible = $aria_count;
                    $screen_reader_compatible += $aria_count;
                }
                break;
            }
        }
        
        // Document title and lang are critical
        $document_compatible = 0;
        if ( $has_title && $has_lang ) {
            $document_compatible = 2;
            $screen_reader_compatible += 2;
        }
        
        return array(
            'score' => round( ( $screen_reader_compatible / $total_elements ) * 100 ),
            'total_elements' => $total_elements,
            'compatible_elements' => $screen_reader_compatible,
            'breakdown' => array(
                'images' => array( 'total' => $img_count, 'compatible' => $images_compatible, 'check_passed' => $images_check_passed ),
                'buttons' => array( 'total' => $button_count, 'compatible' => $buttons_compatible, 'check_passed' => $buttons_check_passed ),
                'links' => array( 'total' => $link_count, 'compatible' => $links_compatible, 'check_passed' => $links_check_passed ),
                'headings' => array( 'total' => $heading_count, 'compatible' => $headings_compatible, 'check_passed' => $headings_check_passed ),
                'aria' => array( 'total' => $aria_count, 'compatible' => $aria_compatible, 'check_passed' => $aria_check_passed ),
                'document' => array( 'title' => $has_title, 'lang' => $has_lang, 'both_passed' => ( $has_title && $has_lang ), 'compatible' => $document_compatible ),
            ),
        );
    }
}
