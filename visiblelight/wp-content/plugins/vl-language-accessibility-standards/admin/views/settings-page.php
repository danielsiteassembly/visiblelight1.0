<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Get active tab from URL or default to first tab
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'languages';
$tabs = array(
    'languages' => __( 'Language & Translation', 'vl-las' ),
    'compliance' => __( 'Legal & Cookies', 'vl-las' ),
    'accessibility' => __( 'Contrast Settings', 'vl-las' ),
    'security' => __( 'VL License Key', 'vl-las' ),
    'audit' => __( 'WCAG 2.1', 'vl-las' ),
    'soc2' => __( 'SOC 2 Type II', 'vl-las' ),
    'hipaa' => __( 'HIPAA', 'vl-las' ),
);
?>
<style>
.vl-las-tabs {
    border-bottom: 1px solid #ccd0d4;
    margin: 20px 0 0;
    padding: 0;
}
.vl-las-tabs .nav-tab {
    margin: 0 0 -1px;
    padding: 10px 15px;
    font-size: 14px;
    line-height: 1.71428571;
    font-weight: 600;
    background: #f6f7f7;
    border: 1px solid #ccd0d4;
    border-bottom: none;
    color: #50575e;
    text-decoration: none;
    display: inline-block;
    cursor: pointer;
}
.vl-las-tabs .nav-tab:hover {
    background: #f0f0f1;
    border-color: #8c8f94;
    color: #1d2327;
}
.vl-las-tabs .nav-tab-active {
    background: #fff;
    border-bottom-color: #fff;
    color: #1d2327;
    margin-bottom: -1px;
    padding-bottom: 11px;
}
.vl-las-tab-content {
    display: none;
    padding: 20px 0;
}
.vl-las-tab-content.active {
    display: block;
}
.vl-las-tab-section {
    margin-bottom: 30px;
}
</style>
<div class="wrap vl-las-settings-wrap">
    <h1><?php esc_html_e( 'VL Language & Accessibility Standards', 'vl-las' ); ?></h1>

    <?php
    // Surface Settings API messages (sanitization errors, updated notices, etc.)
    settings_errors();
    ?>

    <nav class="vl-las-tabs">
        <?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
            <a href="?page=vl-las&tab=<?php echo esc_attr( $tab_id ); ?>" 
               class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>"
               data-tab="<?php echo esc_attr( $tab_id ); ?>">
                <?php echo esc_html( $tab_label ); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="options.php">
        <?php
            // Nonce + option group
            settings_fields( 'vl-las' );

            // Languages Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'languages' ? 'active' : ''; ?>" data-tab-content="languages">
                <?php
                // Only show fields from vl_las_languages section
                global $wp_settings_sections, $wp_settings_fields;
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_languages' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
            </div>

            <?php
            // Compliance Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'compliance' ? 'active' : ''; ?>" data-tab-content="compliance">
                <?php
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_compliance' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
            </div>

            <?php
            // Accessibility Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'accessibility' ? 'active' : ''; ?>" data-tab-content="accessibility">
                <?php
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_accessibility' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
            </div>

            <?php
            // Security License Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'security' ? 'active' : ''; ?>" data-tab-content="security">
                <?php
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_security' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
            </div>

            <?php
            // Audit Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'audit' ? 'active' : ''; ?>" data-tab-content="audit">
                <?php
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_audit' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
            </div>

            <?php
            // SOC 2 Tab
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'soc2' ? 'active' : ''; ?>" data-tab-content="soc2">
                <?php
                if ( isset( $wp_settings_sections['vl-las'] ) && isset( $wp_settings_fields['vl-las'] ) ) {
                    foreach ( (array) $wp_settings_sections['vl-las'] as $section ) {
                        if ( $section['id'] === 'vl_las_soc2' ) {
                            if ( $section['callback'] ) {
                                call_user_func( $section['callback'], $section );
                            }
                            if ( isset( $wp_settings_fields['vl-las'][ $section['id'] ] ) ) {
                                echo '<table class="form-table" role="presentation">';
                                do_settings_fields( 'vl-las', $section['id'] );
                                echo '</table>';
                            }
                        }
                    }
                }
                ?>
                <!-- SOC 2 Reports Container - Outside table structure -->
                <div id="vl-las-soc2-reports-list" style="margin-top:30px;"></div>
                <script>
                // Simple, direct script to load SOC 2 reports when this tab is active
                (function(){
                  var hasLoaded = false;
                  var isLoading = false;
                  
                  function loadSoc2ReportsNow(){
                    var container = document.getElementById('vl-las-soc2-reports-list');
                    if(!container) return;
                    
                    // Prevent duplicate loads
                    if(isLoading || hasLoaded) return;
                    
                    // Check if we're on SOC 2 tab
                    var urlParams = new URLSearchParams(window.location.search);
                    var tabParam = urlParams.get('tab');
                    var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
                    var isActive = soc2TabContent && soc2TabContent.classList.contains('active');
                    
                    if(tabParam !== 'soc2' && !isActive) return;
                    
                    isLoading = true;
                    
                    // Get button for REST root and nonce
                    var btn = document.getElementById('vl-las-soc2-run');
                    var restRoot = btn ? btn.getAttribute('data-rest-root') : '/wp-json/vl-las/v1';
                    var nonce = btn ? btn.getAttribute('data-nonce') : '';
                    
                    container.innerHTML = '<p>Loading SOC 2 reports...</p>';
                    
                    var url = restRoot.replace(/\/+$/, '') + '/soc2/reports?per_page=50';
                    
                    fetch(url, {
                      method: 'GET',
                      headers: { 'X-WP-Nonce': nonce },
                      credentials: 'same-origin'
                    })
                    .then(function(r){ return r.ok ? r.json() : { ok: false, items: [], error: 'HTTP ' + r.status }; })
                    .then(function(resp){
                      isLoading = false;
                      hasLoaded = true;
                      
                      if(!resp || !resp.ok || !resp.items || !resp.items.length){
                        container.innerHTML = '<p>' + (resp && resp.error ? resp.error : 'No SOC 2 reports generated yet.') + '</p>';
                        return;
                      }
                      
                      var html = '<h3>Past SOC 2 Reports</h3><table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Trust Services</th><th>Actions</th></tr></thead><tbody>';
                      
                      resp.items.forEach(function(item){
                        var id = item.id || '';
                        var date = item.created_at || '';
                        try{ var d = new Date(date); if(!isNaN(d)) date = d.toLocaleString(); } catch(e){}
                        var trustServices = item.trust_services || 'N/A';
                        
                        function escapeHtml(str){
                          if(!str && str !== 0) return '';
                          var div = document.createElement('div');
                          div.textContent = String(str);
                          return div.innerHTML;
                        }
                        
                        html += '<tr>';
                        html += '<td>' + escapeHtml(id) + '</td>';
                        html += '<td>' + escapeHtml(date) + '</td>';
                        html += '<td>' + escapeHtml(trustServices) + '</td>';
                        html += '<td><button type="button" class="button button-small" onclick="if(typeof window.vlLasViewSoc2Report === \'function\'){ window.vlLasViewSoc2Report(' + parseInt(id) + '); } else { alert(\'View Report function not available. Please refresh the page.\'); }">View Report</button></td>';
                        html += '</tr>';
                      });
                      
                      html += '</tbody></table>';
                      container.innerHTML = html;
                    })
                    .catch(function(err){
                      isLoading = false;
                      container.innerHTML = '<p style="color:#d63638;">Error loading reports: ' + (err.message || String(err)) + '</p>';
                    });
                  }
                  
                  // Only set up one trigger - when tab becomes active
                  var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
                  if(soc2TabContent){
                    // Check if already active
                    if(soc2TabContent.classList.contains('active')){
                      setTimeout(loadSoc2ReportsNow, 200);
                    }
                    
                    // Watch for tab activation
                    var observer = new MutationObserver(function(){
                      if(soc2TabContent.classList.contains('active') && !hasLoaded){
                        setTimeout(loadSoc2ReportsNow, 200);
                      }
                    });
                    observer.observe(soc2TabContent, { attributes: true, attributeFilter: ['class'] });
                  }
                  
                  // Listen for tab switch event
                  document.addEventListener('vl-las-tab-switched', function(e){
                    if(e.detail && e.detail.tab === 'soc2' && !hasLoaded){
                      setTimeout(loadSoc2ReportsNow, 200);
                    }
                  });
                  
                  // Check URL parameter on page load
                  var urlParams = new URLSearchParams(window.location.search);
                  if(urlParams.get('tab') === 'soc2' && !hasLoaded){
                    setTimeout(loadSoc2ReportsNow, 300);
                  }
                })();
                </script>
            </div>

            <?php
            // HIPAA Tab (empty for now)
            ?>
            <div class="vl-las-tab-content <?php echo $active_tab === 'hipaa' ? 'active' : ''; ?>" data-tab-content="hipaa">
                <div class="vl-las-tab-section">
                    <h2><?php esc_html_e( 'HIPAA Compliance', 'vl-las' ); ?></h2>
                    <p><?php esc_html_e( 'HIPAA functionality will be added in a future update.', 'vl-las' ); ?></p>
                </div>
            </div>

            <?php
            // Save button (shown on all tabs)
            submit_button();
        ?>
    </form>

    <hr/>

    <p>
        <?php
        echo wp_kses_post( sprintf(
            /* translators: 1: WP Plugin Guidelines URL, 2: WCAG 2.1 AA URL */
            __( 'This plugin follows the <a href="%1$s" target="_blank" rel="noopener">WordPress.org Plugin Guidelines</a> and provides tools aligned with <a href="%2$s" target="_blank" rel="noopener">WCAG 2.1 AA</a>.', 'vl-las' ),
            esc_url( 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/' ),
            esc_url( 'https://www.w3.org/TR/WCAG21/' )
        ) );
        ?>
    </p>
</div>

<script>
(function() {
    // Tab switching functionality
    var tabs = document.querySelectorAll('.vl-las-tabs .nav-tab');
    var tabContents = document.querySelectorAll('.vl-las-tab-content');
    
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            
            var targetTab = this.getAttribute('data-tab');
            
            // Update active tab
            tabs.forEach(function(t) { t.classList.remove('nav-tab-active'); });
            this.classList.add('nav-tab-active');
            
            // Update active content
            tabContents.forEach(function(content) {
                content.classList.remove('active');
                if (content.getAttribute('data-tab-content') === targetTab) {
                    content.classList.add('active');
                }
            });
            
            // Update URL without reload
            var url = new URL(window.location);
            url.searchParams.set('tab', targetTab);
            window.history.pushState({}, '', url);
            
            // Trigger custom event for tab switch
            var event = new CustomEvent('vl-las-tab-switched', { detail: { tab: targetTab } });
            document.dispatchEvent(event);
            
            // Directly load SOC 2 reports if SOC 2 tab is clicked
            if(targetTab === 'soc2'){
                setTimeout(function(){
                    if(typeof window.vlLasLoadSoc2Reports === 'function'){
                        var container = document.getElementById('vl-las-soc2-reports-list');
                        if(container){
                            container.dataset.loaded = 'false';
                            window.vlLasLoadSoc2Reports();
                        }
                    }
                }, 100);
            }
        });
    });
})();
</script>
