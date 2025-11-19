<?php
/**
 * Admin settings and pages.
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Admin {

    private static $instance = null;

    /**
     * Singleton
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    add_action( 'admin_menu', array( $this, 'add_menu' ) );
    add_action( 'admin_init', array( $this, 'register_settings' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

    // NEW: fallback injector – if admin.js somehow doesn't load,
    // we’ll inject it ourselves at the bottom of the page.
    add_action( 'admin_footer', array( $this, 'print_fallback_loader' ), 99 );
}

/**
 * Enqueue admin assets (force-load + localize REST info).
 * Loads on ALL admin screens for now so we can see it in Network → Status 200.
 * We’ll narrow to just the settings page after we confirm it’s loading.
 */
public function enqueue( $hook ) {
    // Only load our JS on Settings → VL Language & Accessibility
    if ( $hook !== 'settings_page_vl-las' ) {
        return;
    }

    // Build asset URL/version
    $asset_rel  = 'assets/js/admin.js';
    $asset_path = trailingslashit( VL_LAS_PATH ) . $asset_rel;
    $asset_url  = trailingslashit( VL_LAS_URL )  . $asset_rel;

    $ver = defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : '1.0.0';
    if ( file_exists( $asset_path ) ) {
        $mt = @filemtime( $asset_path );
        if ( $mt ) { $ver = $mt; }
    }

    wp_enqueue_script(
        'vl-las-admin',
        $asset_url,
        array( 'jquery' ),
        $ver,
        true
    );

    // Expose REST info to admin.js as window.VLLAS
    wp_localize_script(
        'vl-las-admin',
        'VLLAS',
        array(
            'rest' => array(
                'root'  => esc_url_raw( rest_url( 'vl-las/v1' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ),
        )
    );
}


/**
 * Fallback loader: if for any reason admin.js didn’t load (minify/concat/defer, etc.),
 * inject it dynamically and also provide minimal fallbacks for:
 *  - Clicking “Scan Homepage Now”
 *  - Loading the Past Reports list
 */
public function print_fallback_loader() {
    $asset_url = trailingslashit( VL_LAS_URL ) . 'assets/js/admin.js';
    $ver       = defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : '1.0.0';
    ?>
    <script>
    (function(){
      var needInject = (typeof window.VLLAS === 'undefined'); // admin.js didn't localize window.VLLAS

      function joinUrl(base, path){
        if(!base) return path||'';
        return String(base).replace(/\/+$/,'') + '/' + String(path||'').replace(/^\/+/,'');
      }
      function withNonce(url, nonce){
        return url + (url.indexOf('?')>=0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(nonce||'');
      }
      
      // Make helper functions globally available for vlLasViewAudit
      window.vlLasJoinUrl = joinUrl;
      window.vlLasWithNonce = withNonce;
      
      // Helper function to escape HTML
      function escapeHtml(s){
        if(s == null) return '';
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }
      window.vlLasEscapeHtml = escapeHtml;

      // ────────────── Fallback: bind Scan button ──────────────
      function bindInlineAudit(){
        var b = document.getElementById('vl-las-run-audit');
        if(!b || b.__vlLasBound) return;
        b.__vlLasBound = true;

        b.addEventListener('click', function(){
          var out   = document.getElementById('vl-las-audit-result');
          var root  = (window.VLLAS && VLLAS.rest && VLLAS.rest.root)  || (b.getAttribute('data-rest-root') || '/wp-json/vl-las/v1');
          var path  = b.getAttribute('data-rest-path') || 'audit2';
          var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || b.getAttribute('data-nonce') || '';

          if(out){ out.textContent = 'Running…'; out.setAttribute('aria-busy','true'); }
          var html = '<!DOCTYPE html><html><head><title>Probe</title></head><body>ok</body></html>';

          fetch(withNonce(joinUrl(root, path), nonce), {
            method: 'POST',
            headers: {
              'Content-Type':'application/json; charset=utf-8',
              'X-WP-Nonce': nonce,
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ url: location.origin + '/', html: html })
          })
          .then(function(r){ return r.text().then(function(t){ return {status:r.status, text:t}; }); })
          .then(function(x){
            var obj; try{ obj = JSON.parse(x.text); }catch(e){}
            if(out){
              out.innerHTML = '';
              var pre = document.createElement('pre');
              pre.textContent = obj ? JSON.stringify(obj, null, 2) : x.text;
              out.appendChild(pre);
              out.setAttribute('aria-busy','false');
            }
            console.info('[VL_LAS fallback] Audit HTTP', x.status);
            // Auto-reload page after successful scan to show updated reports
            if(x.status === 200 && obj && obj.ok){
              setTimeout(function(){
                window.location.reload();
              }, 1000); // Wait 1 second to show the results before reloading
            }
          })
          .catch(function(err){
            if(out){ out.textContent = 'Audit error: ' + (err && err.message ? err.message : String(err)); out.setAttribute('aria-busy','false'); }
            console.warn('[VL_LAS fallback] fetch error:', err);
          });
        });
      }

      // ────────────── Fallback: bind Gemini test button ──────────────
      function bindGeminiTestButton(){
        var btn = document.getElementById('vl-las-test-gemini');
        if(!btn || btn.__vlLasGeminiBound) return;
        btn.__vlLasGeminiBound = true;
        
        // Wait a bit for admin class to load and localize script
        setTimeout(function() {
          // Re-check for nonce after delay
          if (!window.VLLAS || !window.VLLAS.rest || !window.VLLAS.rest.nonce) {
            console.warn('[VL_LAS] VLLAS object not found, using fallback nonce detection');
          }
        }, 100);

        btn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopImmediatePropagation();

          var status = document.getElementById('vl-las-gemini-test-status');
          var jsonDiv = document.getElementById('vl-las-gemini-test-json');
          var jsonPre = jsonDiv ? jsonDiv.querySelector('pre') : null;
          
          var root = (window.VLLAS && VLLAS.rest && VLLAS.rest.root) || '/wp-json/vl-las/v1';
          var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || '';
          
          // Fallback: Try to get nonce from WordPress REST API
          if (!nonce) {
            // Try meta tag first
            var nonceMeta = document.querySelector('meta[name="wp-rest-nonce"]');
            if (nonceMeta) {
              nonce = nonceMeta.getAttribute('content');
            }
            
            // Try wpApiSettings
            if (!nonce && window.wpApiSettings) {
              nonce = window.wpApiSettings.nonce;
            }
            
            // Try to get nonce from WordPress REST API directly
            if (!nonce) {
              // Look for any script tag that might contain nonce
              var scripts = document.querySelectorAll('script');
              for (var i = 0; i < scripts.length; i++) {
                var scriptContent = scripts[i].textContent || scripts[i].innerHTML;
                if (scriptContent && scriptContent.indexOf('wp_rest') !== -1) {
                  var match = scriptContent.match(/"nonce":"([^"]+)"/);
                  if (match) {
                    nonce = match[1];
                    break;
                  }
                }
              }
            }
          }
          
          if (!nonce) {
            // Last resort: try to generate nonce from WordPress REST API
            console.warn('[VL_LAS] No nonce found, attempting to get one from WordPress REST API');
            
            // Try to get nonce from WordPress REST API
            fetch('/wp-json/wp/v2/users/me', {
              method: 'GET',
              credentials: 'same-origin'
            })
            .then(function(response) {
              if (response.ok) {
                // If we can access the API, try to get nonce from response headers
                var restNonce = response.headers.get('X-WP-Nonce');
                if (restNonce) {
                  nonce = restNonce;
                  console.info('[VL_LAS] Got nonce from REST API headers');
                } else {
                  // Generate a basic nonce (this is a fallback)
                  nonce = 'fallback-nonce-' + Date.now();
                  console.warn('[VL_LAS] Using fallback nonce');
                }
              } else {
                nonce = 'fallback-nonce-' + Date.now();
                console.warn('[VL_LAS] Using fallback nonce due to API access failure');
              }
            })
            .catch(function() {
              nonce = 'fallback-nonce-' + Date.now();
              console.warn('[VL_LAS] Using fallback nonce due to network error');
            })
            .finally(function() {
              if (!nonce) {
                if(status){ 
                  status.textContent = 'Failed: No nonce available';
                  status.style.color = 'red';
                  status.setAttribute('aria-busy','false');
                }
                return;
              }
              
              // Continue with the API call using the nonce we found/generated
              proceedWithGeminiTest();
            });
            
            return;
          }
          
          // If we have a nonce, proceed directly
          proceedWithGeminiTest();
          
          function proceedWithGeminiTest() {
            if(status){ 
              status.textContent = 'Testing…'; 
              status.setAttribute('aria-busy','true');
              status.style.color = '';
            }
            if(jsonDiv){ jsonDiv.style.display = 'none'; }
            if(jsonPre){ jsonPre.textContent = ''; }

            fetch(withNonce(joinUrl(root, 'gemini-test'), nonce), {
              method: 'POST',
              headers: {
                'Content-Type':'application/json; charset=utf-8',
                'X-WP-Nonce': nonce,
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: JSON.stringify({})
            })
            .then(function(response){ 
              console.info('[VL_LAS] Gemini Test HTTP', response.status);
              return response.json().catch(function(){ 
                return { ok:false, error:'Invalid JSON from server' }; 
              }); 
            })
            .then(function(resp){
              var ok = resp && resp.ok === true;
              var code = resp && resp.status ? ' ' + resp.status : '';
              if(status){ 
                status.textContent = ok ? 'OK' + code : 'Failed' + code;
                status.style.color = ok ? 'green' : 'red';
                status.setAttribute('aria-busy','false');
              }
              if(jsonPre){
                jsonPre.textContent = JSON.stringify(resp, null, 2);
                if(jsonDiv){ jsonDiv.style.display = 'block'; }
              }
            })
            .catch(function(err){
              if(status){ 
                status.textContent = 'Failed: ' + (err && err.message ? err.message : String(err));
                status.style.color = 'red';
                status.setAttribute('aria-busy','false');
              }
              console.warn('[VL_LAS] Gemini test error:', err);
            });
          }
        });
      }

      // ────────────── Fallback: load Past Reports ──────────────
      function renderReports(host, list){
        host.innerHTML = '';
        if(!list || !list.length){
          host.innerHTML = '<p>No reports yet.</p>';
          return;
        }
        var tbl = document.createElement('table');
        tbl.className = 'widefat striped';
        tbl.innerHTML = '<thead><tr><th>ID</th><th>Date</th><th>URL</th><th>Issues</th><th>Actions</th></tr></thead><tbody></tbody>';
        var tb = tbl.querySelector('tbody');
        list.forEach(function(it){
          var id   = it.id || it.report_id || '';
          var date = it.created_at || it.date || '';
          try { var d = new Date(date); if(!isNaN(d)) date = d.toLocaleString(); } catch(e){}
          var url  = it.url || '';
          var issues = (typeof it.issues !== 'undefined')
              ? it.issues
              : (it.counts && typeof it.counts.fail !== 'undefined' ? it.counts.fail
                 : (it.summary && typeof it.summary.passed !== 'undefined' && typeof it.summary.total !== 'undefined'
                    ? (it.summary.total - it.summary.passed) : ''));
          var tr = document.createElement('tr');
          var viewBtn = '<button type="button" class="button button-small" onclick="vlLasViewAudit(' + id + ')">View Audit</button>';
          tr.innerHTML = '<td>'+id+'</td><td>'+date+'</td><td>'+url+'</td><td>'+issues+'</td><td>'+viewBtn+'</td>';
          tb.appendChild(tr);
        });
        host.appendChild(tbl);
      }

      // ────────────── View Audit Report ──────────────
      window.vlLasViewAudit = function(reportId){
        var btn = document.getElementById('vl-las-run-audit');
        var root = (window.VLLAS && VLLAS.rest && VLLAS.rest.root) || (btn && btn.getAttribute('data-rest-root')) || '/wp-json/vl-las/v1';
        var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || (btn && btn.getAttribute('data-nonce')) || '';
        
        // Helper functions (use global versions if available, otherwise define inline)
        var joinUrl = window.vlLasJoinUrl || function(base, path){
          if(!base) return path||'';
          return String(base).replace(/\/+$/,'') + '/' + String(path||'').replace(/^\/+/,'');
        };
        var withNonce = window.vlLasWithNonce || function(url, nonce){
          return url + (url.indexOf('?')>=0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(nonce||'');
        };
        var escapeHtml = window.vlLasEscapeHtml || function(s){
          if(s == null) return '';
          return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        };
        
        // Create or get modal
        var modal = document.getElementById('vl-las-audit-modal');
        if(!modal){
          modal = document.createElement('div');
          modal.id = 'vl-las-audit-modal';
          modal.style.cssText = 'position:fixed;z-index:100000;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);display:none;';
          modal.innerHTML = '<div style="background-color:#fff;margin:5% auto;padding:20px;border-radius:8px;width:80%;max-width:900px;max-height:80vh;overflow-y:auto;position:relative;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:10px;border-bottom:1px solid #ddd;">' +
            '<h2 style="margin:0;">Audit Report</h2>' +
            '<button type="button" onclick="vlLasCloseAudit()" style="font-size:24px;font-weight:bold;cursor:pointer;color:#666;background:none;border:none;padding:0;width:30px;height:30px;">&times;</button>' +
            '</div>' +
            '<div id="vl-las-audit-modal-content" style="max-height:60vh;overflow-y:auto;"></div>' +
            '<div style="margin-top:20px;text-align:right;">' +
            '<button type="button" class="button" onclick="vlLasCloseAudit()">Close</button>' +
            '</div></div>';
          document.body.appendChild(modal);
        }
        
        var content = document.getElementById('vl-las-audit-modal-content');
        content.innerHTML = '<p>Loading report...</p>';
        modal.style.display = 'block';
        
        fetch(withNonce(joinUrl(root, 'report/' + reportId), nonce), {
          method: 'GET',
          headers: { 'X-WP-Nonce': nonce, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ 
          if(!r.ok) {
            return r.json().then(function(err){ return { ok:false, error:err.message || 'HTTP ' + r.status }; });
          }
          return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; }); 
        })
        .then(function(resp){
          if(resp && (resp.ok === false || resp.error)){
            content.innerHTML = '<p style="color:red;">Error: ' + (resp.error || 'Failed to load report') + '</p>';
            return;
          }
          
          // Display the report data - resp contains: id, created_at, engine, url, summary, report
          var reportData = resp.report || resp;
          var summary = reportData.summary || {};
          var checks = reportData.checks || reportData.findings || [];
          
          // Build pretty report display
          var html = '<div style="padding:8px 10px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
          html += '<div><strong>URL:</strong> ' + escapeHtml(resp.url || reportData.url || '(n/a)') + '</div>';
          html += '<div><strong>When:</strong> ' + escapeHtml(resp.created_at || reportData.created_at || '(n/a)') + '</div>';
          if(summary.passed !== undefined && summary.total !== undefined){
            html += '<div><strong>Passed:</strong> ' + escapeHtml(summary.passed) + ' / ' + escapeHtml(summary.total) + '</div>';
          }
          if(summary.score !== undefined){
            html += '<div><strong>Score:</strong> ' + escapeHtml(summary.score) + '%</div>';
          }
          html += '</div>';
          
          // New Metrics Section
          html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#f9f9f9;margin-bottom:12px;">';
          html += '<h3 style="margin-top:0;margin-bottom:12px;font-size:16px;">Accessibility Metrics</h3>';
          html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
          
          // Error Density
          if(summary.error_density !== undefined){
            var failed = (summary.total || 0) - (summary.passed || 0);
            var failedChecks = [];
            var checkNames = {
              'document_title': 'Document Title',
              'html_lang': 'HTML Language Attribute',
              'img_alt': 'Image Alt Attributes',
              'buttons_accessible_name': 'Button Accessible Names',
              'links_discernable': 'Link Discernable Names',
              'viewport_scaling': 'Viewport Scaling',
              'aria_attributes_valid': 'ARIA Attributes Valid',
              'headings_sequential': 'Headings Sequential Order',
              'touch_target_size_hint': 'Touch Target Size',
              'lists_only_li': 'Lists Contain Only LI Elements'
            };
            if(checks && Array.isArray(checks)){
              checks.forEach(function(check){
                if(check.ok === false || !check.ok){
                  var checkName = checkNames[check.id] || check.id || check.why || 'Unknown Check';
                  failedChecks.push(checkName);
                }
              });
            }
            html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;">';
            html += '<div style="font-size:24px;font-weight:bold;color:#d63638;">' + escapeHtml(summary.error_density.toFixed(1)) + '%</div>';
            html += '<div style="font-size:12px;color:#666;margin-top:4px;">Error Density</div>';
            html += '<details style="margin-top:8px;font-size:11px;"><summary style="cursor:pointer;color:#2271b1;">Show calculation</summary>';
            html += '<div style="margin-top:6px;padding:6px;background:#f9f9f9;border-radius:3px;font-size:10px;">';
            html += '<div><strong>Failed Checks:</strong> ' + escapeHtml(failed) + '</div>';
            html += '<div><strong>Total Checks:</strong> ' + escapeHtml(summary.total || 0) + '</div>';
            if(failedChecks.length > 0){
              html += '<div style="margin-top:6px;"><strong>Failed Check Types:</strong><ul style="margin:4px 0 0 20px;padding:0;">';
              failedChecks.forEach(function(name){
                html += '<li>' + escapeHtml(name) + '</li>';
              });
              html += '</ul></div>';
            }
            html += '<div style="margin-top:4px;color:#666;">Score: (' + escapeHtml(failed) + ' / ' + escapeHtml(summary.total || 0) + ') &times; 100 = ' + escapeHtml(summary.error_density.toFixed(1)) + '%</div>';
            html += '</div></details>';
            html += '</div>';
          }
          
          // Unique Issues
          if(summary.unique_issues !== undefined){
            var uniqueFailedChecks = [];
            var checkNames = {
              'document_title': 'Document Title',
              'html_lang': 'HTML Language Attribute',
              'img_alt': 'Image Alt Attributes',
              'buttons_accessible_name': 'Button Accessible Names',
              'links_discernable': 'Link Discernable Names',
              'viewport_scaling': 'Viewport Scaling',
              'aria_attributes_valid': 'ARIA Attributes Valid',
              'headings_sequential': 'Headings Sequential Order',
              'touch_target_size_hint': 'Touch Target Size',
              'lists_only_li': 'Lists Contain Only LI Elements'
            };
            if(checks && Array.isArray(checks)){
              var seen = {};
              checks.forEach(function(check){
                if((check.ok === false || !check.ok) && check.id && !seen[check.id]){
                  seen[check.id] = true;
                  var checkName = checkNames[check.id] || check.id || check.why || 'Unknown Check';
                  uniqueFailedChecks.push(checkName);
                }
              });
            }
            html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;">';
            html += '<div style="font-size:24px;font-weight:bold;color:#d63638;">' + escapeHtml(summary.unique_issues) + '</div>';
            html += '<div style="font-size:12px;color:#666;margin-top:4px;">Unique Issues</div>';
            html += '<details style="margin-top:8px;font-size:11px;"><summary style="cursor:pointer;color:#2271b1;">Show calculation</summary>';
            html += '<div style="margin-top:6px;padding:6px;background:#f9f9f9;border-radius:3px;font-size:10px;">';
            html += '<div><strong>Failure Type Definition:</strong> Each unique accessibility check that failed, regardless of how many instances exist on the page.</div>';
            if(uniqueFailedChecks.length > 0){
              html += '<div style="margin-top:6px;"><strong>Unique Failure Types Found:</strong><ul style="margin:4px 0 0 20px;padding:0;">';
              uniqueFailedChecks.forEach(function(name){
                html += '<li>' + escapeHtml(name) + '</li>';
              });
              html += '</ul></div>';
            }
            html += '<div style="margin-top:4px;color:#666;">Total unique failure types: ' + escapeHtml(summary.unique_issues) + '</div>';
            html += '</div></details>';
            html += '</div>';
          }
          
          // WCAG Compliance
          if(summary.wcag_compliance){
            var wcag = summary.wcag_compliance;
            var wcagHtml = '';
            if(wcag.A){
              var aScore = wcag.A.score || 0;
              var aCompliant = wcag.A.compliant ? '\u2713' : '\u2717';
              wcagHtml += '<div style="margin-top:4px;"><strong>Level A:</strong> ' + escapeHtml(aScore) + '% ' + aCompliant + ' (' + escapeHtml(wcag.A.passed || 0) + '/' + escapeHtml(wcag.A.total || 0) + ' passed)</div>';
            }
            if(wcag.AA){
              var aaScore = wcag.AA.score || 0;
              var aaCompliant = wcag.AA.compliant ? '\u2713' : '\u2717';
              wcagHtml += '<div style="margin-top:4px;"><strong>Level AA:</strong> ' + escapeHtml(aaScore) + '% ' + aaCompliant + ' (' + escapeHtml(wcag.AA.passed || 0) + '/' + escapeHtml(wcag.AA.total || 0) + ' passed)</div>';
            }
            if(wcag.AAA){
              var aaaScore = wcag.AAA.score || 0;
              var aaaCompliant = wcag.AAA.compliant ? '\u2713' : '\u2717';
              wcagHtml += '<div style="margin-top:4px;"><strong>Level AAA:</strong> ' + escapeHtml(aaaScore) + '% ' + aaaCompliant + ' (' + escapeHtml(wcag.AAA.passed || 0) + '/' + escapeHtml(wcag.AAA.total || 0) + ' passed)</div>';
            }
            if(wcagHtml){
              html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;grid-column:span 2;">';
              html += '<div style="font-size:14px;font-weight:bold;margin-bottom:4px;">WCAG Compliance</div>';
              html += wcagHtml;
              var wcagDetails = '';
              var checkMapping = {
                'document_title': { level: 'A', criterion: '2.4.2', name: 'Document Title' },
                'html_lang': { level: 'A', criterion: '3.1.1', name: 'HTML Language Attribute' },
                'img_alt': { level: 'A', criterion: '1.1.1', name: 'Image Alt Attributes' },
                'buttons_accessible_name': { level: 'A', criterion: '4.1.2', name: 'Button Accessible Names' },
                'links_discernable': { level: 'A', criterion: '2.4.4', name: 'Link Discernable Names' },
                'viewport_scaling': { level: 'AA', criterion: '1.4.4', name: 'Viewport Scaling' },
                'aria_attributes_valid': { level: 'A', criterion: '4.1.2', name: 'ARIA Attributes Valid' },
                'headings_sequential': { level: 'A', criterion: '1.3.1', name: 'Headings Sequential Order' },
                'touch_target_size_hint': { level: 'AA', criterion: '2.5.5', name: 'Touch Target Size' },
                'lists_only_li': { level: 'A', criterion: '1.3.1', name: 'Lists Contain Only LI Elements' }
              };
              if(checks && Array.isArray(checks)){
                var levelA = [], levelAA = [], levelAAA = [];
                checks.forEach(function(check){
                  var mapping = checkMapping[check.id];
                  if(mapping){
                    var checkInfo = mapping.name + ' (WCAG ' + mapping.level + ': ' + mapping.criterion + ')';
                    if(mapping.level === 'A') levelA.push(checkInfo);
                    else if(mapping.level === 'AA') levelAA.push(checkInfo);
                    else if(mapping.level === 'AAA') levelAAA.push(checkInfo);
                  }
                });
                if(levelA.length > 0){
                  wcagDetails += '<div style="margin-top:8px;"><strong>Level A Checks Tested:</strong><ul style="margin:4px 0 0 20px;padding:0;font-size:10px;">';
                  levelA.forEach(function(name){ wcagDetails += '<li>' + escapeHtml(name) + '</li>'; });
                  wcagDetails += '</ul></div>';
                }
                if(levelAA.length > 0){
                  wcagDetails += '<div style="margin-top:8px;"><strong>Level AA Checks Tested:</strong><ul style="margin:4px 0 0 20px;padding:0;font-size:10px;">';
                  levelAA.forEach(function(name){ wcagDetails += '<li>' + escapeHtml(name) + '</li>'; });
                  wcagDetails += '</ul></div>';
                }
                if(levelAAA.length > 0){
                  wcagDetails += '<div style="margin-top:8px;"><strong>Level AAA Checks Tested:</strong><ul style="margin:4px 0 0 20px;padding:0;font-size:10px;">';
                  levelAAA.forEach(function(name){ wcagDetails += '<li>' + escapeHtml(name) + '</li>'; });
                  wcagDetails += '</ul></div>';
                }
              }
              html += '<details style="margin-top:8px;font-size:11px;"><summary style="cursor:pointer;color:#2271b1;">Show calculation</summary>';
              html += '<div style="margin-top:6px;padding:6px;background:#f9f9f9;border-radius:3px;font-size:10px;">';
              html += '<div><strong>WCAG 2.1 Standards:</strong></div>';
              html += '<div style="margin-top:4px;"><strong>Level A:</strong> Basic accessibility requirements. Must be met for minimum accessibility compliance.</div>';
              html += '<div style="margin-top:4px;"><strong>Level AA:</strong> Enhanced accessibility requirements. Recommended for most websites and required for many organizations.</div>';
              html += '<div style="margin-top:4px;"><strong>Level AAA:</strong> Highest level of accessibility. Optional and often difficult to achieve for all content.</div>';
              html += '<div style="margin-top:6px;color:#666;">Checks are mapped to WCAG 2.1 Levels based on their criteria. Score = (Passed Checks / Total Checks) &times; 100 for each level. Compliant = All checks for that level passed.</div>';
              html += wcagDetails;
              html += '</div></details>';
              html += '</div>';
            }
          }
          
          // User Impact
          if(summary.user_impact){
            var impact = summary.user_impact;
            var impactHtml = '';
            if(impact.critical > 0) impactHtml += '<div style="margin-top:4px;"><span style="color:#d63638;">●</span> <strong>Critical:</strong> ' + escapeHtml(impact.critical) + '</div>';
            if(impact.high > 0) impactHtml += '<div style="margin-top:4px;"><span style="color:#f56e28;">●</span> <strong>High:</strong> ' + escapeHtml(impact.high) + '</div>';
            if(impact.medium > 0) impactHtml += '<div style="margin-top:4px;"><span style="color:#f0b849;">●</span> <strong>Medium:</strong> ' + escapeHtml(impact.medium) + '</div>';
            if(impact.low > 0) impactHtml += '<div style="margin-top:4px;"><span style="color:#00a32a;">●</span> <strong>Low:</strong> ' + escapeHtml(impact.low) + '</div>';
            if(impactHtml){
              html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;grid-column:span 2;">';
              html += '<div style="font-size:14px;font-weight:bold;margin-bottom:4px;">User Impact</div>';
              html += impactHtml;
              html += '</div>';
            }
          }
          
          // Keyboard Accessibility Score
          if(summary.keyboard_accessibility_score !== undefined){
            var kbScore = summary.keyboard_accessibility_score;
            var kbDetails = summary.keyboard_accessibility_details;
            var kbColor = kbScore >= 80 ? '#00a32a' : kbScore >= 60 ? '#f0b849' : '#d63638';
            html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;">';
            html += '<div style="font-size:24px;font-weight:bold;color:' + kbColor + ';">' + escapeHtml(kbScore) + '%</div>';
            html += '<div style="font-size:12px;color:#666;margin-top:4px;">Keyboard Accessibility</div>';
            if(kbDetails && kbDetails.breakdown){
              var kbBreakdown = kbDetails.breakdown;
              html += '<details style="margin-top:8px;font-size:11px;"><summary style="cursor:pointer;color:#2271b1;">Show calculation</summary>';
              html += '<div style="margin-top:6px;padding:6px;background:#f9f9f9;border-radius:3px;">';
              html += '<div><strong>Total Interactive Elements:</strong> ' + escapeHtml(kbDetails.total_elements || 0) + '</div>';
              html += '<div><strong>Accessible Elements:</strong> ' + escapeHtml(kbDetails.accessible_elements || 0) + '</div>';
              html += '<div style="margin-top:6px;font-size:10px;">';
              html += '<div>• Buttons: ' + escapeHtml(kbBreakdown.buttons.total || 0) + ' total, ' + escapeHtml(kbBreakdown.buttons.accessible || 0) + ' accessible ' + (kbBreakdown.buttons.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Links: ' + escapeHtml(kbBreakdown.links.total || 0) + ' total, ' + escapeHtml(kbBreakdown.links.accessible || 0) + ' accessible ' + (kbBreakdown.links.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Forms: ' + escapeHtml(kbBreakdown.forms.total || 0) + ' total, ' + escapeHtml(kbBreakdown.forms.accessible || 0) + ' accessible \u2713</div>';
              html += '</div>';
              html += '<div style="margin-top:4px;font-size:10px;color:#666;">Score: (' + escapeHtml(kbDetails.accessible_elements || 0) + ' / ' + escapeHtml(kbDetails.total_elements || 0) + ') &times; 100 = ' + escapeHtml(kbScore) + '%</div>';
              html += '</div></details>';
            }
            html += '</div>';
          }
          
          // Screen Reader Compatibility
          if(summary.screen_reader_compatibility !== undefined){
            var srScore = summary.screen_reader_compatibility;
            var srDetails = summary.screen_reader_compatibility_details;
            var srColor = srScore >= 80 ? '#00a32a' : srScore >= 60 ? '#f0b849' : '#d63638';
            html += '<div style="padding:8px;background:#fff;border-radius:4px;border:1px solid #ddd;">';
            html += '<div style="font-size:24px;font-weight:bold;color:' + srColor + ';">' + escapeHtml(srScore) + '%</div>';
            html += '<div style="font-size:12px;color:#666;margin-top:4px;">Screen Reader Compatibility</div>';
            if(srDetails && srDetails.breakdown){
              var srBreakdown = srDetails.breakdown;
              html += '<details style="margin-top:8px;font-size:11px;"><summary style="cursor:pointer;color:#2271b1;">Show calculation</summary>';
              html += '<div style="margin-top:6px;padding:6px;background:#f9f9f9;border-radius:3px;">';
              html += '<div><strong>Total Elements:</strong> ' + escapeHtml(srDetails.total_elements || 0) + '</div>';
              html += '<div><strong>Compatible Elements:</strong> ' + escapeHtml(srDetails.compatible_elements || 0) + '</div>';
              html += '<div style="margin-top:6px;font-size:10px;">';
              html += '<div>• Images: ' + escapeHtml(srBreakdown.images.total || 0) + ' total, ' + escapeHtml(srBreakdown.images.compatible || 0) + ' compatible ' + (srBreakdown.images.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Buttons: ' + escapeHtml(srBreakdown.buttons.total || 0) + ' total, ' + escapeHtml(srBreakdown.buttons.compatible || 0) + ' compatible ' + (srBreakdown.buttons.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Links: ' + escapeHtml(srBreakdown.links.total || 0) + ' total, ' + escapeHtml(srBreakdown.links.compatible || 0) + ' compatible ' + (srBreakdown.links.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Headings: ' + escapeHtml(srBreakdown.headings.total || 0) + ' total, ' + escapeHtml(srBreakdown.headings.compatible || 0) + ' compatible ' + (srBreakdown.headings.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• ARIA: ' + escapeHtml(srBreakdown.aria.total || 0) + ' total, ' + escapeHtml(srBreakdown.aria.compatible || 0) + ' compatible ' + (srBreakdown.aria.check_passed ? '\u2713' : '\u2717') + '</div>';
              html += '<div>• Document: Title ' + (srBreakdown.document.title ? '\u2713' : '\u2717') + ', Lang ' + (srBreakdown.document.lang ? '\u2713' : '\u2717') + '</div>';
              html += '</div>';
              html += '<div style="margin-top:4px;font-size:10px;color:#666;">Score: (' + escapeHtml(srDetails.compatible_elements || 0) + ' / ' + escapeHtml(srDetails.total_elements || 0) + ') &times; 100 = ' + escapeHtml(srScore) + '%</div>';
              html += '</div></details>';
            }
            html += '</div>';
          }
          
          html += '</div></div>';
          
          // Display checks/findings if available
          if(checks.length > 0){
            html += '<table class="widefat striped" style="margin-top:10px;"><thead><tr><th style="width:28%">Check</th><th style="width:12%">Status</th><th>Notes</th></tr></thead><tbody>';
            checks.forEach(function(f){
              var key = f.key || f.id || f.rule || '(rule)';
              var okTxt = (f.ok === false ? 'Fail' : 'Pass');
              var note = f.msg || f.note || f.why || '';
              html += '<tr><td>' + escapeHtml(key) + '</td><td>' + escapeHtml(okTxt) + '</td><td>' + escapeHtml(note) + '</td></tr>';
            });
            html += '</tbody></table>';
          } else {
            html += '<p style="margin-top:10px;">No findings/checks array present.</p>';
          }
          
          // Add raw JSON details
          html += '<details style="margin-top:12px;"><summary>Show raw JSON</summary>';
          html += '<pre style="max-height:420px;overflow:auto;margin:6px 0 0;background:#f9f9f9;padding:15px;border:1px solid #ddd;border-radius:4px;white-space:pre-wrap;word-wrap:break-word;">';
          html += escapeHtml(JSON.stringify(reportData, null, 2));
          html += '</pre></details>';
          
          content.innerHTML = html;
        })
        .catch(function(err){
          content.innerHTML = '<p style="color:red;">Error loading report: ' + (err && err.message ? err.message : String(err)) + '</p>';
        });
      };
      
      window.vlLasCloseAudit = function(){
        var modal = document.getElementById('vl-las-audit-modal');
        if(modal){
          modal.style.display = 'none';
        }
      };

      function fallbackLoadReports(){
        var host = document.getElementById('vl-las-audit-list');
        if(!host) return;
        var btn   = document.getElementById('vl-las-run-audit');
        var root  = (window.VLLAS && VLLAS.rest && VLLAS.rest.root)  || (btn && btn.getAttribute('data-rest-root')) || '/wp-json/vl-las/v1';
        var nonce = (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) || (btn && btn.getAttribute('data-nonce')) || '';

        host.innerHTML = '<p>Loading reports…</p>';

        fetch(withNonce(joinUrl(root, 'reports?per_page=20'), nonce), {
          method: 'GET',
          headers: { 'X-WP-Nonce': nonce, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.json(); })
        .then(function(resp){
          var list = (resp && resp.ok && Array.isArray(resp.items)) ? resp.items :
                     (Array.isArray(resp) ? resp : []);
          renderReports(host, list);
          console.info('[VL_LAS fallback] Reports loaded');
        })
        .catch(function(err){
          host.innerHTML = '<p>Failed to load reports.</p>';
          console.warn('[VL_LAS fallback] reports error:', err);
        });
      }

      // ────────────── License Sync Button Fallback ──────────────
      function bindLicenseSyncButton(){
        var btn = document.getElementById('vl-las-sync-hub');
        if(!btn || btn.__vlLasLicenseBound) return;
        btn.__vlLasLicenseBound = true;
        
        btn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopImmediatePropagation();
          
          var restRoot = btn.getAttribute('data-rest-root') || '/wp-json/vl-las/v1';
          var nonce = btn.getAttribute('data-nonce') || '';
          var statusEl = document.getElementById('vl-las-sync-hub-status');
          var licenseInput = document.getElementById('vl-las-license-code');
          
          if(!restRoot || !nonce){
            if(statusEl) statusEl.textContent = 'REST not initialized.';
            return;
          }
          
          // Get license from input field
          var license = licenseInput ? licenseInput.value.trim() : '';
          if(!license){
            if(statusEl) {
              statusEl.textContent = 'Please enter a license code first.';
              statusEl.style.color = '#d63638';
            }
            return;
          }
          
          if(statusEl) {
            statusEl.textContent = 'Validating connection…';
            statusEl.style.color = '';
          }
          btn.disabled = true;
          
          var url = withNonce(joinUrl(restRoot, 'license-sync'), nonce);
          
          fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json; charset=utf-8',
              'X-WP-Nonce': nonce,
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ license: license })
          })
          .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; }); })
          .then(function(resp){
            if(resp && resp.ok){
              if(statusEl) {
                statusEl.textContent = '\u2713 ' + (resp.message || resp.status || 'Connection successful');
                statusEl.style.color = '#00a32a';
              }
              // If endpoint was auto-fixed, reload page to show updated endpoint
              if(resp.endpoint_auto_fixed){
                setTimeout(function(){
                  window.location.reload();
                }, 1500);
              }
            } else {
              var err = resp && resp.error ? resp.error : 'Connection failed';
              var msg = '\u2717 ' + err;
              // If there's a suggested endpoint, show it
              if(resp && resp.suggested_endpoint){
                msg += '\n\nSuggested endpoint: ' + resp.suggested_endpoint;
              }
              if(statusEl) {
                statusEl.textContent = msg;
                statusEl.style.color = '#d63638';
                statusEl.style.whiteSpace = 'pre-line';
              }
            }
          })
          .catch(function(err){
            var msg = 'Connection failed';
            if(err && err.message) msg += ': ' + err.message;
            if(statusEl) {
              statusEl.textContent = '\u2717 ' + msg;
              statusEl.style.color = '#d63638';
            }
          })
          .finally(function(){
            btn.disabled = false;
          });
        });
      }

      // ────────────── SOC 2 Button Fallback ──────────────
      function bindSoc2Button(){
        var btn = document.getElementById('vl-las-soc2-run');
        if(!btn || btn.__vlLasSoc2Bound) return;
        btn.__vlLasSoc2Bound = true;
        
        btn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopImmediatePropagation();
          
          var restRoot = btn.getAttribute('data-rest-root') || '/wp-json/vl-las/v1';
          var nonce = btn.getAttribute('data-nonce') || '';
          var statusEl = document.getElementById('vl-las-soc2-status');
          
          if(!restRoot || !nonce){
            if(statusEl) statusEl.textContent = 'REST not initialized for SOC 2 automation.';
            return;
          }
          
          if(statusEl) statusEl.textContent = 'Syncing with VL Hub…';
          btn.disabled = true;
          
          var url = withNonce(joinUrl(restRoot, 'soc2/run'), nonce);
          
          fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json; charset=utf-8',
              'X-WP-Nonce': nonce,
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({})
          })
          .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; }); })
          .then(function(resp){
            if(resp && resp.ok && resp.report){
              if(statusEl) statusEl.textContent = 'SOC 2 report generated successfully.';
              // Reload page to show updated report
              setTimeout(function(){
                window.location.reload();
              }, 1000);
            } else {
              var err = resp && (resp.error || resp.message) ? resp.error || resp.message : 'Unexpected response';
              if(statusEl) statusEl.textContent = 'SOC 2 sync failed: ' + err;
            }
          })
          .catch(function(err){
            var msg = 'SOC 2 sync failed';
            if(err && err.message) msg += ': ' + err.message;
            if(statusEl) statusEl.textContent = msg;
          })
          .finally(function(){
            btn.disabled = false;
          });
        });
      }

      // If needed, inject admin.js (still keep fallbacks for safety)
      if (needInject) {
        var s = document.createElement('script');
        s.src = '<?php echo esc_js( $asset_url ); ?>?v=<?php echo esc_js( $ver ); ?>&cb=' + Date.now();
        s.async = false;
        s.onload = function(){
          console.info('[VL_LAS fallback] admin.js injected');
          bindInlineAudit();
          bindGeminiTestButton();
          fallbackLoadReports();
          bindLicenseSyncButton();
          bindSoc2Button();
        };
        document.head.appendChild(s);
      } else {
        // admin.js present → still ensure both fallbacks are bound (harmless no-ops if admin.js already did it)
        bindInlineAudit();
        bindGeminiTestButton();
        fallbackLoadReports();
        bindLicenseSyncButton();
        bindSoc2Button();
      }
    })();
    </script>
    <?php
}


    /**
     * Add settings page under Settings.
     */
    public function add_menu() {
        add_options_page(
            __( 'VL Language & Accessibility', 'vl-las' ),
            __( 'VL Language & Accessibility', 'vl-las' ),
            'manage_options',
            'vl-las',
            array( $this, 'render_settings' )
        );
    }

    /**
     * Help/usage content for the Languages section.
     */
    public function languages_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e('How to use language detection & translation', 'vl-las'); ?></strong></p>
            <ol style="margin-left:20px;">
                <li><strong><?php esc_html_e('Manual choice wins', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Visitors can choose a language via your switcher (cookie saved).', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('URL param for testing', 'vl-las'); ?>:</strong>
                    <code>?vl_lang=es</code>
                    <?php esc_html_e('forces Spanish and sets the cookie.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Browser preference (optional)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('If enabled below, we use Accept-Language only when no manual choice exists.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('HTML lang (optional)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('If enabled, the current language is reflected in the <html lang> attribute for a11y/SEO.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Inline translation shortcode', 'vl-las'); ?>:</strong>
                    <div style="margin-top:6px;">
                        <code>[vl_t]Welcome to our site![/vl_t]</code><br/>
                        <code>[vl_t lang="es"]Override to force Spanish here[/vl_t]</code>
                    </div>
                    <em><?php esc_html_e('Requires a valid Gemini 2.5 API key and the “Translate with Gemini 2.5” toggle enabled.', 'vl-las'); ?></em>
                </li>
            </ol>
        </div>
        <?php
    }

    /**
     * Help/usage content for the Compliance section.
     */
    public function compliance_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e('How to use cookie banner & legal translations', 'vl-las'); ?></strong></p>
            <ul style="margin-left:20px; list-style:disc;">
                <li><strong><?php esc_html_e('Cookie Banner Message', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Enter your short message below. If “Auto-translate Cookie Banner Message” is enabled, it is translated to the visitor’s language.', 'vl-las'); ?>
                </li>
                <li><strong><?php esc_html_e('Translated legal shortcodes (opt-in)', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Use these variants on pages where you want translated output:', 'vl-las'); ?>
                    <div style="margin-top:6px;">
                        <code>[vl_privacy_policy_t]</code><br/>
                        <code>[vl_terms_t]</code><br/>
                        <code>[vl_copyright_t]</code><br/>
                        <code>[vl_data_privacy_t]</code><br/>
                        <code>[vl_cookie_t]</code>
                    </div>
                    <em><?php esc_html_e('They respect the master “Translate with Gemini 2.5” toggle.', 'vl-las'); ?></em>
                </li>
                <li><strong><?php esc_html_e('Styling', 'vl-las'); ?>:</strong>
                    <?php esc_html_e('Buttons/links keep your Customizer CSS. The message text can be styled with', 'vl-las'); ?>
                    <code>.vl-las-cookie__message</code>.
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * Help/usage content for SOC 2 automation.
     */
    public function soc2_help() {
        ?>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong><?php esc_html_e( 'Enterprise SOC 2 automation overview', 'vl-las' ); ?></strong></p>
            <ul style="margin-left:20px; list-style:disc;">
                <li><?php esc_html_e( 'Ensure your Corporate License Code is saved so the plugin can authenticate with the VL Hub.', 'vl-las' ); ?></li>
                <li><?php esc_html_e( 'Click “Sync & Generate SOC 2 Report” to pull the latest controls, evidence, and risk data into WordPress.', 'vl-las' ); ?></li>
                <li><?php esc_html_e( 'Download the JSON or Markdown package to hand off to executive stakeholders, auditors, or investors.', 'vl-las' ); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {

        // Sections (page slug = 'vl-las') — using callbacks for help panels
        add_settings_section( 'vl_las_languages',     __( 'Languages', 'vl-las' ),    array( $this, 'languages_help' ), 'vl-las' );
        add_settings_section( 'vl_las_compliance',    __( 'Compliance', 'vl-las' ),   array( $this, 'compliance_help' ), 'vl-las' );
        add_settings_section( 'vl_las_accessibility', __( 'Accessibility', 'vl-las' ),  null, 'vl-las' );
        add_settings_section( 'vl_las_security',      __( 'Security & License', 'vl-las' ), null, 'vl-las' );
        add_settings_section( 'vl_las_audit',         __( 'Audit (WCAG 2.1 AA)', 'vl-las' ), null, 'vl-las' );
        add_settings_section( 'vl_las_soc2',          __( 'SOC 2 Type II Automation', 'vl-las' ), array( $this, 'soc2_help' ), 'vl-las' );

        /**
         * Languages: list + Gemini 2.5 + detection + translate toggles
         */
        register_setting( 'vl-las', 'vl_las_languages',         array( $this, 'sanitize_array' ) );
        register_setting( 'vl-las', 'vl_las_gemini_api_key',    array( $this, 'sanitize_text' ) );
        register_setting( 'vl-las', 'vl_las_lang_detect',       array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_apply_html_lang',   array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_translate_enable',  array( $this, 'sanitize_int' ) );

        add_settings_field(
            'vl_las_languages_field',
            __( 'Available Languages', 'vl-las' ),
            array( $this, 'languages_field' ),
            'vl-las',
            'vl_las_languages'
        );

        add_settings_field(
            'vl_las_gemini',
            __( 'Gemini 2.5 API Key', 'vl-las' ),
            array( $this, 'text_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'         => 'gemini_api_key',
                'placeholder' => 'AIza...',
            )
        );

        // Validate Gemini Key (button + status area)
        add_settings_field(
            'vl_las_gemini_test',
            __( 'Validate Gemini API Key', 'vl-las' ),
            function () {
                $raw    = trim( (string) get_option( 'vl_las_gemini_api_key', '' ) );
                $masked = $raw ? ( '••••' . substr( $raw, -4 ) ) : __( '(no key saved)', 'vl-las' );
                echo '<p style="margin:0 0 6px 0;">' . sprintf( esc_html__( 'Saved key: %s', 'vl-las' ), '<code>'.$masked.'</code>' ) . '</p>';
                echo '<button type="button" class="button" id="vl-las-test-gemini">'. esc_html__( 'Run Test', 'vl-las' ) .'</button> ';
                echo '<span id="vl-las-gemini-test-status" style="margin-left:8px;"></span>';
                echo '<div id="vl-las-gemini-test-json" style="margin-top:8px; display:none;"><pre style="max-height:220px; overflow:auto;"></pre></div>';
                echo '<p class="description">'. esc_html__( 'Checks connectivity and returns the API response status. No page content is sent.', 'vl-las' ) .'</p>';
            },
            'vl-las',
            'vl_las_languages'
        );

        add_settings_field(
            'vl_las_lang_detect',
            __( 'Auto-detect by Browser', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'lang_detect',
                'label' => __( 'Use browser “Accept-Language” if no language is chosen yet', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_apply_html_lang',
            __( 'Apply to <html lang>', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'apply_html_lang',
                'label' => __( 'Reflect the current language in the <html lang> attribute', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_translate_enable',
            __( 'Translate with Gemini 2.5', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_languages',
            array(
                'key'   => 'translate_enable',
                'label' => __( 'Enable on-the-fly translation where used', 'vl-las' ),
            )
        );

        /**
         * Compliance: legal texts + cookie banner + translate toggles
         */
        $legal_fields = array(
            'data_privacy'   => __( 'Data Privacy Laws Disclosure', 'vl-las' ),
            'privacy_policy' => __( 'Privacy Policy', 'vl-las' ),
            'cookie'         => __( 'Cookie Consent Disclosure', 'vl-las' ),
            'terms'          => __( 'Terms and Conditions', 'vl-las' ),
            'copyright'      => __( 'Copyright Notice', 'vl-las' ),
        );

        foreach ( $legal_fields as $key => $label ) {
            register_setting( 'vl-las', 'vl_las_legal_' . $key, array( $this, 'sanitize_html' ) );
            add_settings_field(
                'vl_las_legal_' . $key,
                $label . ' ' . sprintf( esc_html__( '(Shortcode: [%s])', 'vl-las' ), 'vl_' . $key ),
                array( $this, 'textarea_field' ),
                'vl-las',
                'vl_las_compliance',
                array( 'key' => 'legal_' . $key )
            );
        }

        // Cookie Banner Message
        register_setting(
            'vl-las',
            'vl_las_cookie_message',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default'           => '',
            )
        );

        add_settings_field(
            'vl_las_cookie_message',
            __( 'Cookie Banner Message', 'vl-las' ),
            function() {
                $val = get_option( 'vl_las_cookie_message', '' );
                echo '<textarea name="vl_las_cookie_message" rows="3" class="large-text" placeholder="' .
                    esc_attr__( 'Short message shown on the banner (left of Accept).', 'vl-las' ) .
                    '">'. esc_textarea( $val ) .'</textarea>';
                echo '<p class="description">' .
                    esc_html__( 'Keep it short. You can style its color in Customizer → Additional CSS using', 'vl-las' ) .
                    ' <code>.vl-las-cookie__message</code>.</p>';
            },
            'vl-las',
            'vl_las_compliance'
        );

        // Cookie consent controls
        register_setting( 'vl-las', 'vl_las_cookie_consent_enabled', array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_cookie_visibility',      array( $this, 'sanitize_text' ) );
        register_setting( 'vl-las', 'vl_las_cookie_position',        array( $this, 'sanitize_text' ) );

        add_settings_field(
            'vl_las_cookie_enabled',
            __( 'Cookie Consent', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'cookie_consent_enabled',
                'label' => __( 'Enable Cookie Consent Banner', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_cookie_visibility',
            __( 'Banner Visibility', 'vl-las' ),
            array( $this, 'select_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'     => 'cookie_visibility',
                'options' => array(
                    'show' => __( 'Show', 'vl-las' ),
                    'hide' => __( 'Hide', 'vl-las' ),
                ),
            )
        );

        add_settings_field(
            'vl_las_cookie_position',
            __( 'Banner Position', 'vl-las' ),
            array( $this, 'select_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'     => 'cookie_position',
                'options' => array(
                    'bottom-left'  => __( 'Bottom Left', 'vl-las' ),
                    'bottom-right' => __( 'Bottom Right', 'vl-las' ),
                ),
            )
        );

        // Translate toggles (compliance)
        register_setting( 'vl-las', 'vl_las_translate_cookie', array( $this, 'sanitize_int' ) );
        register_setting( 'vl-las', 'vl_las_translate_legal',  array( $this, 'sanitize_int' ) );

        add_settings_field(
            'vl_las_translate_cookie',
            __( 'Auto-translate Cookie Banner Message', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'translate_cookie',
                'label' => __( 'Translate the banner message to the visitor’s language', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_translate_legal',
            __( 'Auto-translate Legal Docs (shortcodes)', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_compliance',
            array(
                'key'   => 'translate_legal',
                'label' => __( 'When using the “*_t” legal shortcodes, translate output to visitor’s language', 'vl-las' ),
            )
        );

        /**
         * Accessibility
         */
        register_setting( 'vl-las', 'vl_las_high_contrast', array( $this, 'sanitize_int' ) );
        add_settings_field(
            'vl_las_high_contrast',
            __( 'Native high-contrast CSS', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_accessibility',
            array(
                'key'   => 'high_contrast',
                'label' => __( 'Enable sitewide high-contrast stylesheet', 'vl-las' ),
            )
        );

        /**
         * Security & License
         */
        register_setting( 'vl-las', 'vl_las_license_code', array( $this, 'sanitize_text' ) );
        add_settings_field(
            'vl_las_license_code',
            __( 'Corporate License Code', 'vl-las' ),
            array( $this, 'license_code_field' ),
            'vl-las',
            'vl_las_security'
        );

        /**
         * Audit UI (engine + button + JSON toggle + past reports list)
         */

        // Persisted options
        register_setting( 'vl-las', 'vl_las_audit_engine', array( $this, 'sanitize_audit_engine' ) ); // 0 off, 1 diag, 2 regex
        register_setting( 'vl-las', 'vl_las_audit_show_json', array( $this, 'sanitize_int' ) );

        // Engine radios
        add_settings_field(
            'vl_las_audit_engine',
            __( 'Audit Engine', 'vl-las' ),
            function(){
                $val  = (int) get_option( 'vl_las_audit_engine', 2 );
                $opts = array(
                    0 => __( 'Off', 'vl-las' ),
                    1 => __( 'Diagnostics (safe echo)', 'vl-las' ),
                    2 => __( 'Regex-only (recommended)', 'vl-las' ),
                );
                echo '<fieldset>';
                foreach ( $opts as $k => $label ) {
                    printf(
                        '<label style="display:block;margin:2px 0;"><input type="radio" name="vl_las_audit_engine" value="%1$d" %3$s> %2$s</label>',
                        (int) $k,
                        esc_html( $label ),
                        checked( $val, $k, false )
                    );
                }
                echo '</fieldset>';
            },
            'vl-las',
            'vl_las_audit'
        );

        // Show raw JSON toggle
        add_settings_field(
            'vl_las_audit_show_json',
            __( 'Show raw JSON', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_audit',
            array(
                'key'   => 'audit_show_json',
                'label' => __( 'Display raw JSON under results', 'vl-las' ),
            )
        );

        // Run button + results (use a named method to avoid any closure edge-cases)
        add_settings_field(
            'vl_las_audit_btn',
            __( 'Run Accessibility Audit', 'vl-las' ),
            array( $this, 'audit_button_field' ),
            'vl-las',
            'vl_las_audit'
        );

        // Past reports list container (admin.js fills via REST)
        add_settings_field(
            'vl_las_audit_list',
            __( 'Past Reports', 'vl-las' ),
            function(){
                echo '<div id="vl-las-audit-list"></div>';
            },
            'vl-las',
            'vl_las_audit'
        );

        /**
         * SOC 2 automation
         */
        register_setting( 'vl-las', 'vl_las_soc2_enabled', array( $this, 'sanitize_int' ) );
        register_setting(
            'vl-las',
            'vl_las_soc2_endpoint',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot',
            )
        );

        add_settings_field(
            'vl_las_soc2_enabled',
            __( 'Enable SOC 2 Automation', 'vl-las' ),
            array( $this, 'checkbox_field' ),
            'vl-las',
            'vl_las_soc2',
            array(
                'key'   => 'soc2_enabled',
                'label' => __( 'Allow the plugin to sync SOC 2 evidence from the VL Hub', 'vl-las' ),
            )
        );

        add_settings_field(
            'vl_las_soc2_endpoint',
            __( 'VL Hub SOC 2 Endpoint', 'vl-las' ),
            array( $this, 'text_field' ),
            'vl-las',
            'vl_las_soc2',
            array(
                'key'         => 'soc2_endpoint',
                'placeholder' => 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot',
            )
        );

        add_settings_field(
            'vl_las_soc2_runner',
            __( 'Enterprise Report Generator', 'vl-las' ),
            array( $this, 'soc2_run_field' ),
            'vl-las',
            'vl_las_soc2'
        );
    }

    // ----------------------------
    // Sanitize helpers
    // ----------------------------
    public function sanitize_text( $val ) {
        return sanitize_text_field( $val );
    }

    public function sanitize_int( $val ) {
        return (int) $val ? 1 : 0;
    }

    public function sanitize_array( $val ) {
        return is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : array();
    }

    public function sanitize_html( $val ) {
        return wp_kses_post( $val );
    }

    public function sanitize_audit_engine( $val ) {
        $v = is_numeric( $val ) ? (int) $val : 0;
        // Allowed: 0 = Off, 1 = Diagnostics, 2 = Regex-only
        return in_array( $v, array( 0, 1, 2 ), true ) ? $v : 2;
    }

    public function sanitize_soc2_endpoint( $val ) {
        $raw_value = trim( (string) $val );
        $default   = defined( 'VL_LAS_SOC2_ENDPOINT_DEFAULT' )
            ? VL_LAS_SOC2_ENDPOINT_DEFAULT
            : 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot';
        $previous  = get_option( 'vl_las_soc2_endpoint', $default );

        if ( '' === $raw_value ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_empty',
                __( 'Provide the SOC 2 snapshot endpoint assigned to your organization by Visible Light.', 'vl-las' )
            );
            return $previous;
        }

        $url = esc_url_raw( $raw_value );
        if ( '' === $url ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_invalid',
                __( 'Enter a valid HTTPS URL for the VL Hub SOC 2 endpoint.', 'vl-las' )
            );
            return $previous;
        }

        $parts = wp_parse_url( $url );
        if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_parts',
                __( 'The SOC 2 endpoint must include a hostname and path.', 'vl-las' )
            );
            return $previous;
        }

        if ( 'https' !== strtolower( $parts['scheme'] ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_scheme',
                __( 'The SOC 2 endpoint must use HTTPS.', 'vl-las' )
            );
            return $previous;
        }

        if ( empty( $parts['path'] ) || ( false === strpos( $parts['path'], '/soc2' ) && false === strpos( $parts['path'], '/wp-json/vl-hub/v1/soc2' ) ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_path',
                __( 'The SOC 2 endpoint should point to the /wp-json/vl-hub/v1/soc2/snapshot service provided by Visible Light.', 'vl-las' )
            );
            return $previous;
        }

        $headers = array( 'Accept' => 'application/json' );
        $license = trim( (string) get_option( 'vl_las_license_code', '' ) );
        if ( '' !== $license ) {
            $headers['X-VL-License'] = $license;
        }

        $response = wp_remote_get( $url, array(
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_unreachable',
                sprintf(
                    /* translators: %s: WordPress HTTP error message. */
                    __( 'Could not connect to the VL Hub endpoint: %s', 'vl-las' ),
                    $response->get_error_message()
                )
            );
            return $previous;
        }

        $code       = (int) wp_remote_retrieve_response_code( $response );
        $valid_codes = apply_filters(
            'vl_las_soc2_endpoint_valid_codes',
            array( 200, 201, 202, 203, 204, 206, 401, 403 )
        );

        if ( ! in_array( $code, $valid_codes, true ) ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_http',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Unexpected response from the VL Hub endpoint (HTTP %d).', 'vl-las' ),
                    $code
                )
            );
            return $previous;
        }

        if ( $code >= 400 ) {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_auth',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Endpoint reachable (HTTP %d). Confirm your Corporate License Code with Visible Light.', 'vl-las' ),
                    $code
                ),
                'updated'
            );
        } else {
            add_settings_error(
                'vl_las_soc2_endpoint',
                'vl_las_soc2_endpoint_ok',
                __( 'Connection to the VL Hub SOC 2 endpoint succeeded.', 'vl-las' ),
                'updated'
            );
        }

        return $url;
    }

    // ----------------------------
    // Field renderers
    // ----------------------------
    public function render_settings() {
        include VL_LAS_PATH . 'admin/views/settings-page.php';
    }

    public function languages_field() {
        $langs = self::languages_list();
        $saved = (array) get_option( 'vl_las_languages', array( 'English' ) );

        echo '<div class="vl-las-grid">';
        foreach ( $langs as $l ) {
            $checked = in_array( $l, $saved, true ) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" name="vl_las_languages[]" value="%1$s" %3$s> %2$s</label>',
                esc_attr( $l ),
                esc_html( $l ),
                $checked
            );
        }
        echo '</div>';

        echo '<p class="description">' .
            esc_html__( 'Optionally uses your Gemini 2.5 API key to power language detection/translation utilities in shortcodes and future features.', 'vl-las' ) .
            '</p>';
    }

    public function text_field( $args ) {
        $key = $args['key'];
        $val = get_option( 'vl_las_' . $key, '' );

        printf(
            '<input type="text" name="vl_las_%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr( $key ),
            esc_attr( $val ),
            isset( $args['placeholder'] ) ? esc_attr( $args['placeholder'] ) : ''
        );
    }

    /**
     * Field renderer: License Code with Sync To Hub button.
     */
    public function license_code_field() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );
        $license   = get_option( 'vl_las_license_code', '' );
        $masked    = $license ? ( '••••' . substr( $license, -4 ) ) : __( '(no license saved)', 'vl-las' );

        echo '<p style="margin:0 0 6px 0;">';
        printf(
            '<input type="text" name="vl_las_license_code" value="%s" class="regular-text" placeholder="%s" id="vl-las-license-code" />',
            esc_attr( $license ),
            esc_attr__( 'Enter your Corporate License Code...', 'vl-las' )
        );
        echo '</p>';

        echo '<p style="margin:0 0 6px 0;">';
        printf(
            esc_html__( 'Saved license: %s', 'vl-las' ),
            '<code>' . esc_html( $masked ) . '</code>'
        );
        echo '</p>';

        echo '<p>';
        echo '<button type="button" class="button" id="vl-las-sync-hub"';
        echo ' data-rest-root="' . esc_attr( $rest_root ) . '"';
        echo ' data-nonce="' . esc_attr( $nonce ) . '">';
        echo esc_html__( 'Sync To Hub', 'vl-las' );
        echo '</button> ';
        echo '<span id="vl-las-sync-hub-status" style="margin-left:8px;"></span>';
        echo '</p>';

        echo '<p class="description">';
        esc_html_e( 'Enter your Corporate License Code and click "Sync To Hub" to validate the connection. This license is used to authenticate with the VL Hub for SOC 2 data synchronization.', 'vl-las' );
        echo '</p>';
    }

    public function soc2_endpoint_field() {
        $default = defined( 'VL_LAS_SOC2_ENDPOINT_DEFAULT' )
            ? VL_LAS_SOC2_ENDPOINT_DEFAULT
            : 'https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot';
        $value = get_option( 'vl_las_soc2_endpoint', $default );

        printf(
            '<input type="url" name="vl_las_soc2_endpoint" value="%1$s" class="regular-text code" placeholder="%2$s" pattern="https://.*" />',
            esc_attr( $value ),
            esc_attr( $default )
        );

        echo '<p class="description">' . esc_html__( 'Provide the tenant-specific SOC 2 snapshot URL issued by Visible Light. The plugin expects a secure endpoint hosted on the VL Hub.', 'vl-las' ) . '</p>';
        printf(
            '<p class="description">%s</p>',
            wp_kses_post(
                sprintf(
                    /* translators: %s: example SOC 2 endpoint URL */
                    __( 'Example endpoint: %s (replace the tenant token with the value Visible Light provides).', 'vl-las' ),
                    '<code>https://visiblelight.ai/wp-json/vl-hub/v1/soc2/snapshot?tenant=your-company-slug</code>'
                )
            )
        );
        echo '<p class="description">' . esc_html__( 'Saving this field pings the VL Hub immediately to validate the connection.', 'vl-las' ) . '</p>';
    }

    public function textarea_field( $args ) {
        $key = $args['key'];
        $val = get_option( 'vl_las_' . $key, '' );

        printf(
            '<textarea name="vl_las_%1$s" rows="6" class="large-text code">%2$s</textarea>',
            esc_attr( $key ),
            esc_textarea( $val )
        );
    }

    public function checkbox_field( $args ) {
        $key   = $args['key'];
        $label = isset( $args['label'] ) ? $args['label'] : '';
        $val   = (int) get_option( 'vl_las_' . $key, 0 );

        printf(
            '<label><input type="checkbox" name="vl_las_%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr( $key ),
            checked( 1, $val, false ),
            esc_html( $label )
        );
    }

    public function select_field( $args ) {
        $key     = $args['key'];
        $options = (array) $args['options'];
        $val     = get_option( 'vl_las_' . $key, '' );

        echo '<select name="vl_las_' . esc_attr( $key ) . '">';
        foreach ( $options as $k => $label ) {
            printf(
                '<option value="%1$s" %3$s>%2$s</option>',
                esc_attr( $k ),
                esc_html( $label ),
                selected( $val, $k, false )
            );
        }
        echo '</select>';
    }

    /**
     * Field renderer: Audit run button + results container.
     * Uses data-rest-path="audit2" to avoid conflicts with other plugins' /audit routes.
     */
    public function audit_button_field() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );

        // Force visible in case an admin stylesheet hides buttons by id.
        echo '<style>#vl-las-run-audit{display:inline-block!important;}</style>';

        echo '<button type="button" class="button button-primary" id="vl-las-run-audit"'
            . ' data-rest-root="' . esc_attr( $rest_root ) . '"'
            . ' data-rest-path="audit2"' // <-- use the working route
            . ' data-nonce="' . esc_attr( $nonce ) . '">'
            . esc_html__( 'Scan Homepage Now', 'vl-las' )
            . '</button>';

        echo '<div id="vl-las-audit-result" style="margin-top:10px;"></div>';

        // Inline fallback click-handler (only binds once).
        ?>
        <script>
        (function(){
          if (window.vlLasAuditInlineBound) return;
          window.vlLasAuditInlineBound = true;

          function joinUrl(base, path){
            if(!base) return path;
            return String(base).replace(/\/+$/,'') + '/' + String(path).replace(/^\/+/,'');
          }

          var btn = document.getElementById('vl-las-run-audit');
          if(!btn) return;

          btn.addEventListener('click', function(){
            var out = document.getElementById('vl-las-audit-result');
            if(out){ out.textContent = 'Running…'; out.setAttribute('aria-busy','true'); }

            var restRoot = btn.getAttribute('data-rest-root') || '/wp-json/vl-las/v1';
            var restPath = btn.getAttribute('data-rest-path') || 'audit2'; // <-- default to audit2
            var nonce    = btn.getAttribute('data-nonce') || '';

            var html = null;
            try {
              var doc = document.documentElement;
              if (doc) html = '<!DOCTYPE html>' + doc.outerHTML;
            } catch(e){}

            fetch(joinUrl(restRoot, restPath), {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'X-WP-Nonce': nonce
              },
              body: JSON.stringify({ url: window.location.origin + '/', html: html })
            }).then(function(r){
              return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; });
            }).then(function(resp){
              var text = JSON.stringify((resp && resp.ok && resp.report) ? resp.report : resp, null, 2);
              if(out){
                var pre = document.createElement('pre');
                pre.textContent = text;
                out.innerHTML = '';
                out.appendChild(pre);
              }
              // Auto-reload page after successful scan to show updated reports
              if(resp && resp.ok){
                setTimeout(function(){
                  window.location.reload();
                }, 1000); // Wait 1 second to show the results before reloading
              }
            }).catch(function(err){
              if(out){ out.textContent = 'Audit request error: ' + (err && err.message ? err.message : String(err)); }
            }).finally(function(){
              if(out){ out.setAttribute('aria-busy','false'); }
            });
          });
        })();
        </script>
        <?php
    }

    /**
     * Render the SOC 2 automation controls.
     */
    public function soc2_run_field() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );

        echo '<button type="button" class="button button-primary" id="vl-las-soc2-run"'
            . ' data-rest-root="' . esc_attr( $rest_root ) . '"'
            . ' data-nonce="' . esc_attr( $nonce ) . '">'
            . esc_html__( 'Sync & Generate SOC 2 Report', 'vl-las' )
            . '</button>';

        echo '<div id="vl-las-soc2-status" style="margin-top:10px;"></div>';

        // Note: Reports list container is now in settings-page.php outside the table structure

        // Inline fallback for SOC 2 button
        ?>
        <script>
        (function(){
          if (window.vlLasSoc2InlineBound) return;
          window.vlLasSoc2InlineBound = true;

          function joinUrl(base, path){
            if(!base) return path;
            return String(base).replace(/\/+$/,'') + '/' + String(path).replace(/^\/+/,'');
          }
          
          // Make joinUrl globally available for vlLasViewSoc2Report
          window.vlLasJoinUrl = joinUrl;

          var btn = document.getElementById('vl-las-soc2-run');
          var isGenerating = false;
          if(btn){
            btn.addEventListener('click', function(){
              // Prevent duplicate clicks
              if(isGenerating){
                return;
              }
              isGenerating = true;
              btn.disabled = true;

              var status = document.getElementById('vl-las-soc2-status');
              if(status){ 
                status.textContent = 'Generating SOC 2 report...'; 
                status.style.color = '#666';
                status.setAttribute('aria-busy','true'); 
              }

              var restRoot = btn.getAttribute('data-rest-root') || '/wp-json/vl-las/v1';
              var nonce    = btn.getAttribute('data-nonce') || '';

              fetch(joinUrl(restRoot, 'soc2/run'), {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json; charset=utf-8',
                  'X-WP-Nonce': nonce
                }
              }).then(function(r){
                return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; });
              }).then(function(resp){
                isGenerating = false;
                btn.disabled = false;
                if(status){
                  status.setAttribute('aria-busy','false');
                  if(resp && resp.ok){
                    var successSpan = document.createElement('span');
                    successSpan.style.cssText = 'color:#00a32a;font-weight:normal;';
                    successSpan.appendChild(document.createTextNode('\u2713 SOC 2 report generated successfully.'));
                    status.innerHTML = '';
                    status.appendChild(successSpan);
                    status.style.setProperty('color', '#00a32a', 'important');
                    status.style.setProperty('font-weight', 'normal', 'important');
                    // Reload after a delay to show the new report
                    setTimeout(function(){ 
                      window.location.href = window.location.href.split('&')[0] + '&tab=soc2';
                    }, 1500);
                  } else {
                    var errorMsg = (resp && resp.error) ? String(resp.error) : 'Failed to generate report';
                    var safeMsg = String(errorMsg).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    var errorSpan = document.createElement('span');
                    errorSpan.style.cssText = 'color:#d63638;font-weight:normal;';
                    errorSpan.appendChild(document.createTextNode('\u2717 ' + safeMsg));
                    status.innerHTML = '';
                    status.appendChild(errorSpan);
                    status.style.setProperty('color', '#d63638', 'important');
                    status.style.setProperty('font-weight', 'normal', 'important');
                  }
                }
              }).catch(function(err){
                isGenerating = false;
                btn.disabled = false;
                if(status){ 
                  var errMsg = (err && err.message) ? String(err.message) : String(err);
                  var safeMsg = String(errMsg).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                  var errorSpan = document.createElement('span');
                  errorSpan.style.cssText = 'color:#d63638;font-weight:normal;';
                  errorSpan.appendChild(document.createTextNode('\u2717 Error: ' + safeMsg));
                  status.innerHTML = '';
                  status.appendChild(errorSpan);
                  status.style.setProperty('color', '#d63638', 'important');
                  status.style.setProperty('font-weight', 'normal', 'important');
                  status.setAttribute('aria-busy','false');
                }
              });
            });
          }

          // Load SOC 2 reports list
          function loadSoc2Reports(){
            var container = document.getElementById('vl-las-soc2-reports-list');
            if(!container) {
              console.error('[VL_LAS] SOC 2 reports container not found');
              return;
            }

            // Get rest root and nonce from button or use defaults
            var btnElement = document.getElementById('vl-las-soc2-run');
            var restRoot = btnElement ? btnElement.getAttribute('data-rest-root') : '/wp-json/vl-las/v1';
            var nonce    = btnElement ? btnElement.getAttribute('data-nonce') : '';

            // Check if already loaded (prevent duplicate loads) - but allow reload if explicitly reset
            if(container.dataset.loaded === 'true'){
              console.log('[VL_LAS] Reports already loaded, skipping...');
              // Check if we're on the SOC 2 tab - if so, allow reload
              var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
              var isActive = soc2TabContent && soc2TabContent.classList.contains('active');
              if(!isActive){
                return;
              }
              // If tab is active, reset and reload
              console.log('[VL_LAS] Tab is active, forcing reload...');
              container.dataset.loaded = 'false';
            }
            
            // Mark as loading
            container.dataset.loaded = 'loading';

            // Show loading state
            container.innerHTML = '<p>Loading SOC 2 reports...</p>';
            console.log('[VL_LAS] Fetching SOC 2 reports from:', restRoot + '/soc2/reports');

            // Build URL properly
            var url = restRoot.replace(/\/+$/, '') + '/soc2/reports?per_page=50';
            console.log('[VL_LAS] Full URL:', url);

            fetch(url, {
              method: 'GET',
              headers: {
                'X-WP-Nonce': nonce
              },
              credentials: 'same-origin'
            }).then(function(r){
              console.log('[VL_LAS] Response status:', r.status, r.statusText);
              if(!r.ok){
                console.error('[VL_LAS] SOC 2 reports HTTP error:', r.status, r.statusText);
                return r.text().then(function(text){
                  console.error('[VL_LAS] Response body:', text);
                  return { ok:false, items:[], error:'HTTP ' + r.status + ': ' + r.statusText };
                });
              }
              return r.json().catch(function(e){
                console.error('[VL_LAS] JSON parse error:', e);
                return { ok:false, items:[], error:'Invalid JSON from server' };
              });
            }).then(function(resp){
              console.log('[VL_LAS] Response data:', resp);
              container.dataset.loaded = 'true';
              
              if(!resp || !resp.ok){
                var errorMsg = resp && resp.error ? resp.error : 'Unknown error';
                container.innerHTML = '<p style="color:#d63638;">Error loading reports: ' + errorMsg + '</p>';
                console.error('[VL_LAS] SOC 2 reports error:', resp);
                return;
              }
              
              if(!resp.items || !resp.items.length){
                container.innerHTML = '<p>No SOC 2 reports generated yet.</p>';
                console.log('[VL_LAS] No reports found');
                return;
              }

              console.log('[VL_LAS] Found', resp.items.length, 'reports');
              var html = '<h3>Past SOC 2 Reports</h3>';
              html += '<table class="widefat striped">';
              html += '<thead><tr><th>ID</th><th>Date</th><th>Trust Services</th><th>Actions</th></tr></thead><tbody>';
              
              resp.items.forEach(function(item){
                var id = item.id || '';
                var date = item.created_at || '';
                try { var d = new Date(date); if(!isNaN(d)) date = d.toLocaleString(); } catch(e){}
                var trustServices = item.trust_services || 'N/A';
                // Escape HTML to prevent XSS
                var escapeHtml = function(str) {
                  if(!str) return '';
                  var div = document.createElement('div');
                  div.textContent = str;
                  return div.innerHTML;
                };
                html += '<tr>';
                html += '<td>' + escapeHtml(String(id)) + '</td>';
                html += '<td>' + escapeHtml(String(date)) + '</td>';
                html += '<td>' + escapeHtml(String(trustServices)) + '</td>';
                html += '<td><button type="button" class="button button-small" onclick="vlLasViewSoc2Report(' + parseInt(id) + ')">View Report</button></td>';
                html += '</tr>';
              });
              
              html += '</tbody></table>';
              container.innerHTML = html;
              console.log('[VL_LAS] Table rendered successfully');
            }).catch(function(err){
              container.dataset.loaded = 'error';
              container.innerHTML = '<p style="color:#d63638;">Error loading reports: ' + (err && err.message ? err.message : String(err)) + '</p>';
              console.error('[VL_LAS] SOC 2 reports fetch error:', err);
            });
          }
          
          // Make function globally accessible for debugging
          window.vlLasLoadSoc2Reports = loadSoc2Reports;
          
          // Also add a simple direct call that always tries to load if container exists and we're on SOC 2 tab
          // This runs immediately and also after delays to catch the container when it's ready
          (function forceLoadSoc2Reports(){
            function tryForceLoad(){
              // Check URL parameter - this is the most reliable indicator
              var urlParams = new URLSearchParams(window.location.search);
              var tabParam = urlParams.get('tab');
              
              // Check if SOC 2 tab is active
              var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
              var isActive = soc2TabContent && soc2TabContent.classList.contains('active');
              
              // If URL has tab=soc2, always try to load (don't check isActive)
              if(tabParam === 'soc2'){
                var container = document.getElementById('vl-las-soc2-reports-list');
                if(container){
                  // Force load regardless of loaded state
                  console.log('[VL_LAS] Force loading SOC 2 reports (URL has tab=soc2)...');
                  container.dataset.loaded = 'false';
                  loadSoc2Reports();
                  return true;
                }
              } else if(isActive){
                // Tab is active but URL doesn't have param, still try to load
                var container = document.getElementById('vl-las-soc2-reports-list');
                if(container){
                  console.log('[VL_LAS] Force loading SOC 2 reports (tab is active)...');
                  container.dataset.loaded = 'false';
                  loadSoc2Reports();
                  return true;
                }
              }
              return false;
            }
            
            // Try immediately
            if(!tryForceLoad()){
              // Try again after delays
              setTimeout(function(){ tryForceLoad(); }, 300);
              setTimeout(function(){ tryForceLoad(); }, 800);
              setTimeout(function(){ tryForceLoad(); }, 1500);
            }
          })();
        </script>
        <script>
          // View SOC 2 Report function - defined outside IIFE to ensure it's always available
          window.vlLasViewSoc2Report = function(reportId){
            console.log('[VL_LAS] vlLasViewSoc2Report called with reportId:', reportId);
            // Helper function for URL joining
            function joinUrl(base, path){
              if(!base) return path;
              return String(base).replace(/\/+$/,'') + '/' + String(path).replace(/^\/+/,'');
            }
            
            // Use global joinUrl if available, otherwise use local
            var urlJoin = window.vlLasJoinUrl || joinUrl;
            
            var btnElement = document.getElementById('vl-las-soc2-run');
            var restRoot = btnElement ? btnElement.getAttribute('data-rest-root') : '/wp-json/vl-las/v1';
            var nonce    = btnElement ? btnElement.getAttribute('data-nonce') : '';

            // Create or get modal
            var modal = document.getElementById('vl-las-soc2-modal');
            if(!modal){
              modal = document.createElement('div');
              modal.id = 'vl-las-soc2-modal';
              modal.style.cssText = 'position:fixed;z-index:100000;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);display:none;';
              modal.innerHTML = '<div style="background-color:#fff;margin:2% auto;padding:20px;border-radius:8px;width:90%;max-width:1200px;max-height:90vh;overflow-y:auto;position:relative;">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:10px;border-bottom:1px solid #ddd;">' +
                '<h2 style="margin:0;">SOC 2 Type II Report</h2>' +
                '<button type="button" onclick="if(typeof window.vlLasCloseSoc2Report === \'function\'){ window.vlLasCloseSoc2Report(); }" style="font-size:24px;font-weight:bold;cursor:pointer;color:#666;background:none;border:none;padding:0;width:30px;height:30px;">&times;</button>' +
                '</div>' +
                '<div id="vl-las-soc2-modal-content" style="max-height:75vh;overflow-y:auto;"></div>' +
                '<div style="margin-top:20px;text-align:right;">' +
                '<button type="button" class="button" onclick="if(typeof window.vlLasCloseSoc2Report === \'function\'){ window.vlLasCloseSoc2Report(); }">Close</button>' +
                '</div></div>';
              document.body.appendChild(modal);
            }

            var content = document.getElementById('vl-las-soc2-modal-content');
            if(!content){
              console.error('[VL_LAS] Modal content not found');
              return;
            }
            
            content.innerHTML = '<p>Loading report...</p>';
            modal.style.display = 'block';

            var reportUrl = urlJoin(restRoot, 'soc2/report/' + reportId);
            console.log('[VL_LAS] Fetching SOC 2 report from:', reportUrl);
            
            fetch(reportUrl, {
              method: 'GET',
              headers: { 'X-WP-Nonce': nonce, 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ 
              if(!r.ok) {
                return r.json().then(function(err){ return { ok:false, error:err.message || 'HTTP ' + r.status }; });
              }
              return r.json().catch(function(){ return { ok:false, error:'Invalid JSON from server' }; }); 
            })
            .then(function(resp){
              if(resp && (resp.ok === false || resp.error)){
                content.innerHTML = '<p style="color:red;">Error: ' + (resp.error || 'Failed to load report') + '</p>';
                return;
              }

              // Escape HTML helper function
              function escapeHtml(str) {
                if(!str && str !== 0) return '';
                var div = document.createElement('div');
                div.textContent = String(str);
                return div.innerHTML;
              }

              // Display the full SOC 2 report
              // Handle nested report structure: resp.report.report contains the actual report data
              var actualReport = resp.report && resp.report.report ? resp.report.report : (resp.report || {});
              var reportData = actualReport;
              var snapshot = resp.snapshot || (resp.report && resp.report.snapshot ? resp.report.snapshot : {});
              var meta = resp.meta || (resp.report && resp.report.meta ? resp.report.meta : {});

              var html = '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#f9f9f9;margin-bottom:12px;">';
              var generatedAt = resp.created_at || meta.generated_at || (reportData.meta && reportData.meta.generated_at) || 'N/A';
              html += '<div><strong>Generated:</strong> ' + escapeHtml(generatedAt) + '</div>';
              var reportUrl = resp.url || window.location.origin || 'N/A';
              html += '<div><strong>URL:</strong> ' + escapeHtml(reportUrl) + '</div>';
              var reportType = (reportData.meta && reportData.meta.type) ? reportData.meta.type : 'SOC 2 Type II';
              html += '<div><strong>Report Type:</strong> ' + escapeHtml(reportType) + '</div>';
              html += '</div>';

              // Trust Services
              if(reportData.trust_services && reportData.trust_services.selected){
                html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
                html += '<h3 style="margin-top:0;">Trust Services</h3>';
                var trustServicesText = Array.isArray(reportData.trust_services.selected) ? reportData.trust_services.selected.join(', ') : String(reportData.trust_services.selected);
                html += '<p>' + escapeHtml(trustServicesText) + '</p>';
                html += '</div>';
              }

              // Control Environment & Control Matrix - Human Readable Table
              var controlEnvironment = reportData.control_environment || {};
              var controlDomains = controlEnvironment.domains || {};
              var controlMatrix = controlEnvironment.control_matrix || {};
              var controlTests = reportData.control_tests || {};
              var riskAssessment = reportData.risk_assessment || {};

              // Main SOC 2 Audit Report Table
              html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
              html += '<h3 style="margin-top:0;">SOC 2 Audit Report - Control Assessment</h3>';
              html += '<table class="widefat striped" style="margin-top:12px;">';
              html += '<thead><tr><th style="width:15%;">Control ID</th><th style="width:20%;">Control Domain</th><th style="width:30%;">Description</th><th style="width:15%;">Test Result</th><th style="width:20%;">Findings</th></tr></thead>';
              html += '<tbody>';

              // Build a map of tested control IDs from procedures to avoid duplicates
              var testedControlIds = {};
              if(controlTests && controlTests.procedures && Array.isArray(controlTests.procedures)){
                controlTests.procedures.forEach(function(procedure){
                  if(procedure && procedure.control_id){
                    testedControlIds[procedure.control_id] = true;
                  }
                });
              }

              // Process Control Domains
              var rowCount = 0;
              Object.keys(controlDomains).forEach(function(domainKey){
                var domain = controlDomains[domainKey];
                var domainName = domain.label || domain.name || domainKey;
                var domainDesc = domain.description || '';
                var domainStatus = domain.status || 'operating';
                var controls = domain.controls || [];

                // If domain has controls array with items, process each control
                if(Array.isArray(controls) && controls.length > 0){
                  controls.forEach(function(control){
                    var controlId = control.id || control.code || 'CC' + rowCount;
                    
                    // Skip if this control has test procedures (will be shown in procedures section)
                    if(testedControlIds[controlId]){
                      return;
                    }
                    
                    rowCount++;
                    var controlDesc = control.description || control.name || '';
                    var testResult = 'Not Tested';
                    var findings = 'N/A';

                    // Check control tests for this control
                    if(controlTests[controlId]){
                      var test = controlTests[controlId];
                      if(typeof test === 'object'){
                        testResult = test.result || test.status || 'Tested';
                        findings = test.findings || test.description || test.notes || 'No findings';
                      } else {
                        testResult = test;
                      }
                    } else if(controlTests[domainKey + '_' + controlId]){
                      var test = controlTests[domainKey + '_' + controlId];
                      if(typeof test === 'object'){
                        testResult = test.result || test.status || 'Tested';
                        findings = test.findings || test.description || test.notes || 'No findings';
                      } else {
                        testResult = test;
                      }
                    }
                    
                    // Skip "Not Tested" entries if there are test procedures
                    if(testResult === 'Not Tested' && Object.keys(testedControlIds).length > 0){
                      return;
                    }

                    // Color code test results
                    var resultColor = '#666';
                    if(testResult === 'Passed' || testResult === 'Compliant' || testResult === 'Effective'){
                      resultColor = '#00a32a';
                    } else if(testResult === 'Failed' || testResult === 'Non-Compliant' || testResult === 'Ineffective'){
                      resultColor = '#d63638';
                    } else if(testResult === 'Partially Compliant' || testResult === 'Partially Effective'){
                      resultColor = '#dba617';
                    }

                    html += '<tr>';
                    html += '<td><strong>' + escapeHtml(controlId) + '</strong></td>';
                    html += '<td>' + escapeHtml(domainName) + '</td>';
                    html += '<td>' + escapeHtml(controlDesc || domainDesc || 'N/A') + '</td>';
                    html += '<td style="color:' + resultColor + ';font-weight:bold;">' + escapeHtml(testResult) + '</td>';
                    html += '<td>' + escapeHtml(findings || 'N/A') + '</td>';
                    html += '</tr>';
                  });
                } else {
                  // If no controls array or empty, treat domain as a single control
                  // This handles the case where domains exist but have no individual controls
                  var domainId = domain.id || domain.code || domainKey;
                  
                  // Skip if this domain has test procedures (will be shown in procedures section)
                  if(testedControlIds[domainId] || testedControlIds[domainKey]){
                    return;
                  }
                  
                  rowCount++;
                  var testResult = domainStatus === 'operating' ? 'Operating' : (domainStatus || 'Not Tested');
                  var findings = 'N/A';

                  // Check if there are any test procedures for this domain
                  if(controlTests && controlTests.procedures && Array.isArray(controlTests.procedures)){
                    var domainProcedures = controlTests.procedures.filter(function(proc){
                      return proc && (proc.domain === domainKey || proc.domain === domainName || proc.control_domain === domainKey);
                    });
                    if(domainProcedures.length > 0){
                      testResult = 'Tested';
                      findings = domainProcedures.length + ' test procedure(s) performed';
                    }
                  }

                  // Check control tests directly
                  if(controlTests[domainId] || controlTests[domainKey]){
                    var test = controlTests[domainId] || controlTests[domainKey];
                    if(typeof test === 'object'){
                      testResult = test.result || test.status || testResult;
                      findings = test.findings || test.description || test.notes || findings;
                    } else {
                      testResult = test;
                    }
                  }
                  
                  // Skip "Not Tested" entries if there are test procedures
                  if(testResult === 'Not Tested' && Object.keys(testedControlIds).length > 0){
                    return;
                  }

                  // Color code test results based on status
                  var resultColor = '#666';
                  if(testResult === 'Operating' || testResult === 'Passed' || testResult === 'Compliant' || testResult === 'Effective'){
                    resultColor = '#00a32a';
                  } else if(testResult === 'Failed' || testResult === 'Non-Compliant' || testResult === 'Ineffective'){
                    resultColor = '#d63638';
                  } else if(testResult === 'Partially Compliant' || testResult === 'Partially Effective'){
                    resultColor = '#dba617';
                  } else if(testResult === 'Tested'){
                    resultColor = '#0073aa';
                  }

                  html += '<tr>';
                  html += '<td><strong>' + escapeHtml(domainId) + '</strong></td>';
                  html += '<td>' + escapeHtml(domainName) + '</td>';
                  var domainDescText = domainDesc;
                  if(!domainDescText){
                    var domainNameEscaped = String(domainName || '');
                    var domainStatusEscaped = String(domainStatus || '');
                    domainDescText = 'Control domain: ' + domainNameEscaped + ' (Status: ' + domainStatusEscaped + ')';
                  }
                  html += '<td>' + escapeHtml(domainDescText) + '</td>';
                  html += '<td style="color:' + resultColor + ';font-weight:bold;">' + escapeHtml(testResult) + '</td>';
                  var findingsText = findings;
                  if(!findingsText){
                    var domainStatusEscaped2 = String(domainStatus || '');
                    findingsText = 'Domain status: ' + domainStatusEscaped2;
                  }
                  html += '<td>' + escapeHtml(findingsText) + '</td>';
                  html += '</tr>';
                }
              });

              // Process Control Tests procedures if they exist
              if(controlTests && controlTests.procedures && Array.isArray(controlTests.procedures) && controlTests.procedures.length > 0){
                controlTests.procedures.forEach(function(procedure){
                  if(!procedure || !procedure.control_id) return;
                  
                  rowCount++;
                  var controlId = procedure.control_id || '';
                  var testResult = procedure.result || 'Not Tested';
                  var findings = procedure.findings || procedure.procedure || 'No findings';
                  var testDesc = procedure.procedure || procedure.description || 'Control test procedure';

                  // Color code test results
                  var resultColor = '#666';
                  if(testResult === 'Passed' || testResult === 'Compliant' || testResult === 'Effective' || testResult === 'Operating'){
                    resultColor = '#00a32a';
                  } else if(testResult === 'Failed' || testResult === 'Non-Compliant' || testResult === 'Ineffective' || testResult === 'Deficient'){
                    resultColor = '#d63638';
                  } else if(testResult === 'Partially Compliant' || testResult === 'Partially Effective'){
                    resultColor = '#dba617';
                  } else if(testResult === 'Tested'){
                    resultColor = '#0073aa';
                  }

                  // Find domain name
                  var domainName = 'Control Test';
                  if(procedure.control_domain && controlDomains[procedure.control_domain]){
                    domainName = controlDomains[procedure.control_domain].label || procedure.control_domain;
                  }

                  html += '<tr>';
                  html += '<td><strong>' + escapeHtml(controlId) + '</strong></td>';
                  html += '<td>' + escapeHtml(domainName) + '</td>';
                  html += '<td>' + escapeHtml(testDesc) + '</td>';
                  html += '<td style="color:' + resultColor + ';font-weight:bold;">' + escapeHtml(testResult) + '</td>';
                  html += '<td>' + escapeHtml(findings || 'N/A') + '</td>';
                  html += '</tr>';
                });
              }
              
              // Process Control Tests directly if they exist (fallback)
              if(Object.keys(controlTests).length > 0){
                Object.keys(controlTests).forEach(function(testKey){
                  if(testKey === 'observation_period' || testKey === 'procedures' || testKey === 'evidence_summary' || testKey === 'type') return;
                  
                  var test = controlTests[testKey];
                  var testResult = 'Not Tested';
                  var findings = 'N/A';
                  var testDesc = '';

                  if(typeof test === 'object'){
                    testResult = test.result || test.status || test.outcome || 'Tested';
                    findings = test.findings || test.description || test.notes || test.summary || 'No findings';
                    testDesc = test.description || test.name || test.control_description || '';
                  } else {
                    testResult = test;
                  }

                  // Check if this test was already added
                  var alreadyAdded = false;
                  var testRows = html.match(/<tr>/g);
                  if(testRows && testRows.length > 0){
                    // Simple check - if test key matches a control ID, skip
                    var testIdMatch = testKey.match(/^(CC|DC|CC\d+|DC\d+)/i);
                    if(!testIdMatch){
                      alreadyAdded = false;
                    }
                  }

                  if(!alreadyAdded && testDesc){
                    rowCount++;
                    var resultColor = '#666';
                    if(testResult === 'Passed' || testResult === 'Compliant' || testResult === 'Effective'){
                      resultColor = '#00a32a';
                    } else if(testResult === 'Failed' || testResult === 'Non-Compliant' || testResult === 'Ineffective'){
                      resultColor = '#d63638';
                    } else if(testResult === 'Partially Compliant' || testResult === 'Partially Effective'){
                      resultColor = '#dba617';
                    }

                    html += '<tr>';
                    html += '<td><strong>' + escapeHtml(testKey) + '</strong></td>';
                    html += '<td>Control Test</td>';
                    html += '<td>' + escapeHtml(testDesc) + '</td>';
                    html += '<td style="color:' + resultColor + ';font-weight:bold;">' + escapeHtml(testResult) + '</td>';
                    html += '<td>' + escapeHtml(findings || 'N/A') + '</td>';
                    html += '</tr>';
                  }
                });
              }

              if(rowCount === 0){
                html += '<tr><td colspan="5" style="text-align:center;padding:20px;color:#666;">No control assessments found in this report.</td></tr>';
              }

              html += '</tbody></table>';
              html += '</div>';

              // Risk Assessment Table
              // Handle both old format (array) and new format (object with matrix)
              var risks = [];
              if(riskAssessment){
                if(Array.isArray(riskAssessment)){
                  risks = riskAssessment;
                } else if(riskAssessment.matrix && Array.isArray(riskAssessment.matrix)){
                  risks = riskAssessment.matrix;
                } else if(Object.keys(riskAssessment).length > 0){
                  risks = Object.values(riskAssessment);
                }
              }
              
              if(risks.length > 0){
                html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
                html += '<h3 style="margin-top:0;">Risk Assessment</h3>';
                html += '<table class="widefat striped" style="margin-top:12px;">';
                html += '<thead><tr><th style="width:20%;">Risk ID</th><th style="width:25%;">Title</th><th style="width:30%;">Description</th><th style="width:15%;">Severity</th><th style="width:10%;">Status</th></tr></thead>';
                html += '<tbody>';
                
                risks.forEach(function(risk){
                  var riskId = risk.id || risk.code || 'R' + (risks.indexOf(risk) + 1);
                  var riskTitle = risk.title || risk.name || 'Unnamed Risk';
                  var riskDesc = risk.description || risk.details || 'N/A';
                  var riskSeverity = risk.severity || risk.level || 'Medium';
                  var riskStatus = risk.status || risk.mitigation_status || 'Open';

                  var severityColor = '#666';
                  if(riskSeverity === 'Critical' || riskSeverity === 'High'){
                    severityColor = '#d63638';
                  } else if(riskSeverity === 'Medium'){
                    severityColor = '#dba617';
                  } else if(riskSeverity === 'Low'){
                    severityColor = '#00a32a';
                  }

                  html += '<tr>';
                  html += '<td><strong>' + escapeHtml(riskId) + '</strong></td>';
                  html += '<td>' + escapeHtml(riskTitle) + '</td>';
                  html += '<td>' + escapeHtml(riskDesc) + '</td>';
                  html += '<td style="color:' + severityColor + ';font-weight:bold;">' + escapeHtml(riskSeverity) + '</td>';
                  html += '<td>' + escapeHtml(riskStatus) + '</td>';
                  html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
              }

              // Supporting Artifacts & Evidence Table
              var supportingArtifacts = reportData.supporting_artifacts || {};
              if(Object.keys(supportingArtifacts).length > 0){
                html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
                html += '<h3 style="margin-top:0;">Supporting Artifacts & Evidence</h3>';
                html += '<table class="widefat striped" style="margin-top:12px;">';
                html += '<thead><tr><th style="width:20%;">Artifact ID</th><th style="width:30%;">Type</th><th style="width:50%;">Description</th></tr></thead>';
                html += '<tbody>';
                
                Object.keys(supportingArtifacts).forEach(function(artifactKey){
                  var artifact = supportingArtifacts[artifactKey];
                  var artifactType = artifact.type || artifactKey;
                  var artifactDesc = '';

                  if(typeof artifact === 'object'){
                    try {
                      artifactDesc = artifact.description || artifact.name || artifact.title || JSON.stringify(artifact);
                    } catch(e) {
                      artifactDesc = 'Unable to serialize artifact';
                    }
                  } else {
                    artifactDesc = artifact;
                  }

                  var artifactDescText = artifactDesc.length > 200 ? artifactDesc.substring(0, 200) + '...' : artifactDesc;
                  html += '<tr>';
                  html += '<td><strong>' + escapeHtml(artifactKey) + '</strong></td>';
                  html += '<td>' + escapeHtml(artifactType) + '</td>';
                  html += '<td>' + escapeHtml(artifactDescText) + '</td>';
                  html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
              }

              // System Description
              if(reportData.system_description){
                html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
                html += '<h3 style="margin-top:0;">System Description</h3>';
                if(typeof reportData.system_description === 'object'){
                  var sysDesc = reportData.system_description;
                  if(sysDesc.technical_stack){
                    var techStackJson = JSON.stringify(sysDesc.technical_stack, null, 2);
                    html += '<p><strong>Technical Stack:</strong> <pre style="display:inline;font-size:11px;">' + escapeHtml(techStackJson) + '</pre></p>';
                  }
                  if(sysDesc.overview){
                    html += '<p>' + escapeHtml(sysDesc.overview) + '</p>';
                  }
                  var sysDescJson = JSON.stringify(sysDesc, null, 2);
                  html += '<pre style="font-size:12px;overflow-x:auto;max-height:300px;overflow-y:auto;background:#f9f9f9;padding:12px;border-radius:4px;">' + escapeHtml(sysDescJson) + '</pre>';
                } else {
                  html += '<p>' + escapeHtml(reportData.system_description) + '</p>';
                }
                html += '</div>';
              }

              // Observation Period
              if(controlTests.observation_period){
                html += '<div style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
                html += '<h3 style="margin-top:0;">Observation Period</h3>';
                if(typeof controlTests.observation_period === 'object'){
                  html += '<p><strong>Start:</strong> ' + escapeHtml(controlTests.observation_period.start || controlTests.observation_period.from || 'N/A') + '</p>';
                  html += '<p><strong>End:</strong> ' + escapeHtml(controlTests.observation_period.end || controlTests.observation_period.to || 'N/A') + '</p>';
                  html += '<p><strong>Duration:</strong> ' + escapeHtml(controlTests.observation_period.duration || 'N/A') + '</p>';
                } else {
                  html += '<p>' + escapeHtml(controlTests.observation_period) + '</p>';
                }
                html += '</div>';
              }

              // Full Report JSON (collapsible)
              html += '<details style="padding:12px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;margin-bottom:12px;">';
              html += '<summary style="cursor:pointer;font-weight:bold;margin-bottom:8px;">View Full Report JSON</summary>';
              var jsonString = JSON.stringify(resp, null, 2);
              html += '<pre style="font-size:11px;overflow-x:auto;max-height:400px;overflow-y:auto;background:#f9f9f9;padding:12px;border-radius:4px;">' + escapeHtml(jsonString) + '</pre>';
              html += '</details>';

              content.innerHTML = html;
            }).catch(function(err){
              content.innerHTML = '<p style="color:red;">Error loading report: ' + (err && err.message ? err.message : String(err)) + '</p>';
            });
          };
          
          // Close SOC 2 Report function - also defined outside IIFE
          window.vlLasCloseSoc2Report = function(){
            var modal = document.getElementById('vl-las-soc2-modal');
            if(modal) modal.style.display = 'none';
          };
          
          // Verify functions are defined
          try {
            console.log('[VL_LAS] SOC 2 report functions defined:', {
              viewReport: typeof window.vlLasViewSoc2Report,
              closeReport: typeof window.vlLasCloseSoc2Report
            });
            if(typeof window.vlLasViewSoc2Report !== 'function'){
              console.error('[VL_LAS] vlLasViewSoc2Report is not a function!');
            }
            if(typeof window.vlLasCloseSoc2Report !== 'function'){
              console.error('[VL_LAS] vlLasCloseSoc2Report is not a function!');
            }
          } catch(e) {
            console.error('[VL_LAS] Error checking function definitions:', e);
          }
        </script>
        <script>
          // Simple function to check if we should load reports
          function shouldLoadSoc2Reports(){
            var urlParams = new URLSearchParams(window.location.search);
            var tabParam = urlParams.get('tab');
            var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
            var isActive = soc2TabContent && soc2TabContent.classList.contains('active');
            return tabParam === 'soc2' || isActive;
          }
          
          // Initialize on page load - simplified approach
          function initSoc2ReportsLoader(){
            console.log('[VL_LAS] initSoc2ReportsLoader called');
            var container = document.getElementById('vl-las-soc2-reports-list');
            
            if(!container) {
              console.warn('[VL_LAS] Container not found, retrying in 500ms...');
              setTimeout(initSoc2ReportsLoader, 500);
              return;
            }
            
            // Check if we should load
            if(shouldLoadSoc2Reports()){
              console.log('[VL_LAS] SOC 2 tab is active, loading reports...');
              container.dataset.loaded = 'false';
              loadSoc2Reports();
            } else {
              console.log('[VL_LAS] SOC 2 tab not active, setting up watchers...');
            }
            
            // Set up MutationObserver to watch for tab content visibility changes
            var soc2TabContent = document.querySelector('.vl-las-tab-content[data-tab-content="soc2"]');
            if(soc2TabContent){
              console.log('[VL_LAS] Setting up MutationObserver');
              var observer = new MutationObserver(function(mutations){
                mutations.forEach(function(mutation){
                  if(mutation.type === 'attributes' && mutation.attributeName === 'class'){
                    if(soc2TabContent.classList.contains('active')){
                      console.log('[VL_LAS] Tab became active via MutationObserver');
                      var container = document.getElementById('vl-las-soc2-reports-list');
                      if(container && container.dataset.loaded !== 'loading'){
                        container.dataset.loaded = 'false';
                        loadSoc2Reports();
                      }
                    }
                  }
                });
              });
              observer.observe(soc2TabContent, { attributes: true, attributeFilter: ['class'] });
            }
            
            // Listen for custom tab switch event
            document.addEventListener('vl-las-tab-switched', function(e){
              if(e.detail && e.detail.tab === 'soc2'){
                console.log('[VL_LAS] Tab switched to SOC 2 via event');
                var container = document.getElementById('vl-las-soc2-reports-list');
                if(container){
                  container.dataset.loaded = 'false';
                  loadSoc2Reports();
                }
              }
            });
          }
          
          // Force load immediately if on SOC 2 tab
          (function(){
            var urlParams = new URLSearchParams(window.location.search);
            var tabParam = urlParams.get('tab');
            if(tabParam === 'soc2'){
              console.log('[VL_LAS] SOC 2 tab detected on page load, loading reports immediately...');
              setTimeout(function(){
                var container = document.getElementById('vl-las-soc2-reports-list');
                if(container){
                  container.dataset.loaded = 'false';
                  loadSoc2Reports();
                } else {
                  console.warn('[VL_LAS] Container not found on immediate check');
                }
              }, 200);
            }
          })();
          
          // Run when DOM is ready
          if(document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){
              setTimeout(initSoc2ReportsLoader, 300);
            });
          } else {
            // DOM already ready, run immediately
            setTimeout(initSoc2ReportsLoader, 300);
          }
          
          // Multiple fallbacks to ensure it loads
          setTimeout(function(){
            var container = document.getElementById('vl-las-soc2-reports-list');
            if(container && shouldLoadSoc2Reports() && container.dataset.loaded !== 'true' && container.dataset.loaded !== 'loading'){
              console.log('[VL_LAS] Fallback 1: Loading reports');
              container.dataset.loaded = 'false';
              loadSoc2Reports();
            }
          }, 1000);
          
          setTimeout(function(){
            var container = document.getElementById('vl-las-soc2-reports-list');
            if(container && shouldLoadSoc2Reports() && container.dataset.loaded !== 'true' && container.dataset.loaded !== 'loading'){
              console.log('[VL_LAS] Fallback 2: Loading reports');
              container.dataset.loaded = 'false';
              loadSoc2Reports();
            }
          }, 2500);
          
          setTimeout(function(){
            var container = document.getElementById('vl-las-soc2-reports-list');
            if(container && shouldLoadSoc2Reports() && container.dataset.loaded !== 'true' && container.dataset.loaded !== 'loading'){
              console.log('[VL_LAS] Fallback 3: Loading reports');
              container.dataset.loaded = 'false';
              loadSoc2Reports();
            }
          }, 4000);
        })();
        </script>
        <?php
    }

    public function soc2_run_field_old() {
        $rest_root = esc_url_raw( rest_url( 'vl-las/v1' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );

        $bundle = class_exists( 'VL_LAS_SOC2' ) ? \VL_LAS_SOC2::get_cached_bundle() : array();
        $bundle['enabled'] = (bool) get_option( 'vl_las_soc2_enabled', 0 );
        $meta   = isset( $bundle['meta'] ) && is_array( $bundle['meta'] ) ? $bundle['meta'] : array();
        $report = isset( $bundle['report'] ) && is_array( $bundle['report'] ) ? $bundle['report'] : array();

        $last_generated = isset( $meta['generated_at'] ) ? sanitize_text_field( $meta['generated_at'] ) : '';
        $trusts         = array();
        if ( ! empty( $meta['trust_services'] ) && is_array( $meta['trust_services'] ) ) {
            foreach ( $meta['trust_services'] as $tsc ) {
                $trusts[] = sanitize_text_field( $tsc );
            }
        }

        $has_report   = ! empty( $report );
        $trust_summary = $trusts ? implode( ', ', $trusts ) : __( 'baseline criteria', 'vl-las' );
        $status_text   = $has_report
            ? sprintf(
                /* translators: 1: generated time, 2: trust services criteria list */
                __( 'Last generated on %1$s covering %2$s.', 'vl-las' ),
                $last_generated,
                $trust_summary
            )
            : __( 'No SOC 2 report generated yet.', 'vl-las' );

        echo '<p class="description">' . esc_html__( 'Runs a full SOC 2 Type II sync from the VL Hub and prepares an executive-ready package.', 'vl-las' ) . '</p>';

        echo '<p>';
        echo '<button type="button" class="button button-primary" id="vl-las-soc2-run"';
        echo ' data-rest-root="' . esc_attr( $rest_root ) . '"';
        echo ' data-rest-path="soc2/run"';
        echo ' data-nonce="' . esc_attr( $nonce ) . '">';
        echo esc_html__( 'Sync & Generate SOC 2 Report', 'vl-las' );
        echo '</button> ';

        $disabled = $has_report ? '' : ' disabled="disabled"';
        echo '<button type="button" class="button" id="vl-las-soc2-download-json"' . $disabled . '>' . esc_html__( 'Download JSON', 'vl-las' ) . '</button> ';
        echo '<button type="button" class="button" id="vl-las-soc2-download-markdown"' . $disabled . '>' . esc_html__( 'Download Markdown', 'vl-las' ) . '</button>';
        echo '</p>';

        echo '<div id="vl-las-soc2-status" style="margin-top:8px;">' . esc_html( $status_text ) . '</div>';
        echo '<div id="vl-las-soc2-report" class="vl-las-soc2-report" style="margin-top:12px;"></div>';
        echo '<details id="vl-las-soc2-raw" style="margin-top:12px; display:none;">'
            . '<summary>' . esc_html__( 'Show raw SOC 2 JSON', 'vl-las' ) . '</summary>'
            . '<pre style="max-height:340px; overflow:auto;"></pre>'
            . '</details>';

        echo '<script>window.VLLAS = window.VLLAS || {}; window.VLLAS.soc2Initial = ' . wp_json_encode( $bundle ) . ';</script>';

        if ( $has_report ) {
            $raw_json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            echo '<script>window.VLLAS.soc2InitialRaw = ' . wp_json_encode( $raw_json ) . ';</script>';
        } else {
            echo '<script>window.VLLAS.soc2InitialRaw = null;</script>';
        }
    }

    /**
     * Normalized language list (duplicates/typos fixed).
     */
    public static function languages_list() {
        return array(
            'English',
            'Spanish',
            'Arabic',
            'Russian',
            'Vietnamese',
            'Tagalog',
            'German',
            'French',
            'Mandarin',
            'Cantonese',
            'Chinese',
            'Portuguese',
            'Japanese',
            'Telugu',
            'Polish',
            'Italian',
            'Hindi',
            'Bengali',
            'Urdu',
        );
    }
}