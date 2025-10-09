<?php
/**
 * Plugin Name: SportAdmin WP-plugin
 * Description: Visar truppsidor från SportAdmin. Välj klubb/år, hämta grupper, sortera/gruppera och skapa undersidor automatiskt. Shortcode: [sa_trupp]
 * Version: 1.2.2
 * Author: SportAdmin
 * Text Domain: sportadmin-wp
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
   Constants (guards så de inte definieras två gånger)
--------------------------------------------------------------------------- */
if (!defined('SA_WP_VERSION'))         define('SA_WP_VERSION', '1.2.2');
if (!defined('SA_WP_API_KEY'))         define('SA_WP_API_KEY', 'sa_wp_api_key');
if (!defined('SA_WP_CLUB'))            define('SA_WP_CLUB', 'sa_wp_club_id');
if (!defined('SA_WP_YEAR'))            define('SA_WP_YEAR', 'sa_wp_member_year');
if (!defined('SA_WP_ALL_GROUPS'))      define('SA_WP_ALL_GROUPS', 'sa_wp_all_groups');
if (!defined('SA_WP_GROUP_SETTINGS'))  define('SA_WP_GROUP_SETTINGS', 'sa_wp_group_settings');
if (!defined('SA_WP_SECTIONS_ORDER'))  define('SA_WP_SECTIONS_ORDER', 'sa_wp_sections_order');
if (!defined('SA_WP_PARENT_PAGE'))     define('SA_WP_PARENT_PAGE', 'sa_wp_parent_page_id');
if (!defined('SA_WP_ACCORDION_COLORS')) define('SA_WP_ACCORDION_COLORS', 'sa_wp_accordion_colors');

/* -------------------------------------------------------------------------
   Helpers
--------------------------------------------------------------------------- */
if (!function_exists('sa_wp_api_base')) {
    function sa_wp_api_base() { return 'https://api.sportadmin.se'; }
}
if (!function_exists('sa_wp_auth')) {
    function sa_wp_auth() {
        $k = get_option(SA_WP_API_KEY, '');
        return ['Authorization' => $k];
    }
}
if (!function_exists('sa_wp_fetch')) {
    function sa_wp_fetch($url) {
        $res = wp_remote_get($url, ['headers' => sa_wp_auth(), 'timeout' => 20]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 300) return new WP_Error('bad_status', $code.' '.wp_remote_retrieve_response_message($res), ['body'=>$body]);
        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) return new WP_Error('bad_json', 'Invalid JSON');
        return $json;
    }
}
if (!function_exists('sa_wp_slug')) {
    function sa_wp_slug($t) {
        $t = function_exists('remove_accents') ? remove_accents((string)$t) : (string)$t;
        $t = strtolower(trim($t));
        $t = preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim($t, '-');
        return $t ?: 'group';
    }
}
if (!function_exists('sa_wp_persons')) {
    function sa_wp_persons($club, $year, $ttl = 600) {
        $url = rtrim(sa_wp_api_base(),'/').'/v3/Persons?clubId='.rawurlencode($club).'&memberYear='.rawurlencode($year);
        $key = 'sa_wp_'.md5($url);
        if ($ttl > 0) {
            $cached = get_transient($key);
            if ($cached !== false) return $cached;
        }
        $data = sa_wp_fetch($url);
        if (!is_wp_error($data) && $ttl > 0) set_transient($key, $data, $ttl);
        return $data;
    }
}
if (!function_exists('sa_wp_persons_array')) {
    function sa_wp_persons_array($d) {
        if (!is_array($d)) return [];
        foreach (['items','persons','Persons','data','Data'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) return $d[$k];
        }
        return array_values($d) === $d ? $d : [];
    }
}
if (!function_exists('sa_wp_groups_from_persons')) {
    function sa_wp_groups_from_persons($arr) {
        $out = [];
        foreach ((array)$arr as $p) {
            $gs = null;
            foreach (['groups','Groups','group','Group','teams','Teams','team','Team','Lag','Grupper'] as $k) {
                if (isset($p[$k]) && !empty($p[$k])) { $gs = $p[$k]; break; }
            }
            if (is_string($gs)) $gs = [$gs];
            if (!is_array($gs)) $gs = [];
            foreach ($gs as $g) {
                $name = is_array($g) ? ($g['groupName'] ?? ($g['GroupName'] ?? ($g['name'] ?? ($g['Name'] ?? null)))) : $g;
                if (!$name) continue;
                $out[sa_wp_slug($name)] = $name;
            }
        }
        ksort($out, SORT_NATURAL|SORT_FLAG_CASE);
        return $out;
    }
}
if (!function_exists('sa_wp_filter_persons_group')) {
    function sa_wp_filter_persons_group($arr, $name) {
        $out = [];
        foreach ((array)$arr as $p) {
            $gs = null;
            foreach (['groups','Groups','group','Group','teams','Teams','team','Team','Lag','Grupper'] as $k) {
                if (isset($p[$k]) && !empty($p[$k])) { $gs = $p[$k]; break; }
            }
            if (is_string($gs)) $gs = [$gs];
            if (!is_array($gs)) $gs = [];
            foreach ($gs as $g) {
                $n = is_array($g) ? ($g['groupName'] ?? ($g['GroupName'] ?? ($g['name'] ?? ($g['Name'] ?? null)))) : $g;
                if ($n && strcasecmp($n, $name) === 0) { $out[] = $p; break; }
            }
        }
        return $out;
    }
}
if (!function_exists('sa_wp_person_name')) {
    function sa_wp_person_name($p) {
        $n = $p['name'] ?? ($p['Name'] ?? '');
        if (!$n) {
            $n = trim(($p['firstName'] ?? ($p['FirstName'] ?? '')) .' '. ($p['lastName'] ?? ($p['LastName'] ?? '')));
        }
        return esc_html($n ?: '—');
    }
}

/* -------------------------------------------------------------------------
   Admin: meny + settings
--------------------------------------------------------------------------- */
add_action('admin_menu', function() {
    add_options_page('SportAdmin API','SportAdmin API','manage_options','sportadmin-wp','sa_wp_settings_page');
});

add_action('admin_init', function() {
    register_setting('sa_wp_group', SA_WP_API_KEY);
    register_setting('sa_wp_group', SA_WP_CLUB);
    register_setting('sa_wp_group', SA_WP_YEAR);
    register_setting('sa_wp_group', SA_WP_SECTIONS_ORDER);
    register_setting('sa_wp_group', SA_WP_PARENT_PAGE, 'absint');
    register_setting('sa_wp_group', SA_WP_ACCORDION_COLORS);

    add_settings_section('sa_wp_sec','Autentisering & Standarder', function(){
        echo '<p>Fyll i API-nyckel samt standard Klubb-ID & Medlemsår.</p>';
    }, 'sportadmin-wp');

    add_settings_field('key','API-nyckel', function(){
        printf('<input type="password" name="%s" value="%s" class="regular-text" />',
            esc_attr(SA_WP_API_KEY), esc_attr(get_option(SA_WP_API_KEY,'')));
    }, 'sportadmin-wp', 'sa_wp_sec');

    add_settings_field('club','Klubb-ID', function(){
        printf('<input type="text" name="%s" value="%s" class="regular-text" />',
            esc_attr(SA_WP_CLUB), esc_attr(get_option(SA_WP_CLUB,'')));
    }, 'sportadmin-wp', 'sa_wp_sec');

    add_settings_field('year','Medlemsår', function(){
        printf('<input type="text" name="%s" value="%s" class="regular-text" />',
            esc_attr(SA_WP_YEAR), esc_attr(get_option(SA_WP_YEAR,'')));
    }, 'sportadmin-wp', 'sa_wp_sec');

    add_settings_field('sections','Sektioners ordning', function(){
        printf('<input type="text" name="%s" value="%s" class="regular-text" placeholder="Seniorlag, Akademi, Ungdom" />',
            esc_attr(SA_WP_SECTIONS_ORDER), esc_attr(get_option(SA_WP_SECTIONS_ORDER,'')));
    }, 'sportadmin-wp', 'sa_wp_sec');

    add_settings_field('parent','Föräldersida', function(){
        wp_dropdown_pages([
            'name' => SA_WP_PARENT_PAGE,
            'selected' => (int)get_option(SA_WP_PARENT_PAGE,0),
            'show_option_none' => '(Skapa ny "Trupper"-sida automatiskt)'
        ]);
    }, 'sportadmin-wp', 'sa_wp_sec');

});

if (!function_exists('sa_wp_settings_page')) {
function sa_wp_settings_page() {
    if (!current_user_can('manage_options')) return;
    $all = get_option(SA_WP_ALL_GROUPS, []);
    $cfg = get_option(SA_WP_GROUP_SETTINGS, []);
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
    
    echo '<div class="wrap"><h1>SportAdmin API</h1>';
    
    // Tab navigation
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=sportadmin-wp&tab=api" class="nav-tab ' . ($active_tab == 'api' ? 'nav-tab-active' : '') . '">API & Grunder</a>';
    echo '<a href="?page=sportadmin-wp&tab=groups" class="nav-tab ' . ($active_tab == 'groups' ? 'nav-tab-active' : '') . '">Grupper</a>';
    echo '<a href="?page=sportadmin-wp&tab=display" class="nav-tab ' . ($active_tab == 'display' ? 'nav-tab-active' : '') . '">Visning & Design</a>';
    echo '</h2>';

    // Tab content
    switch($active_tab) {
        case 'api':
            echo '<form method="post" action="options.php">';
            settings_fields('sa_wp_group');
            do_settings_sections('sportadmin-wp');
            submit_button();
            echo '</form>';
            break;
            
        case 'groups':
            echo '<h3>Hämta grupper från SportAdmin</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-bottom:2em">';
            wp_nonce_field('sa_wp_groups');
            echo '<input type="hidden" name="action" value="sa_wp_fetch_groups" />';
            echo '<button class="button button-primary">Hämta grupper</button></form>';

            if (empty($all)) {
                echo '<p>Inga grupper inlästa än. Klicka på "Hämta grupper" ovan för att börja.</p>';
            } else {
                echo '<h3>Hantera grupper</h3>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('sa_wp_groups');
                echo '<input type="hidden" name="action" value="sa_wp_save_groups" />';
                echo '<table class="widefat striped"><thead><tr><th>Visa</th><th>Grupp</th><th>Ordning</th><th>Sektion</th></tr></thead><tbody>';
                foreach ($all as $slug => $name) {
                    $row = $cfg[$slug] ?? ['name'=>$name,'enabled'=>1,'order'=>1000,'section'=>''];
                    printf(
                        '<tr>
                            <td><input type="checkbox" name="sa_wp_group[%1$s][enabled]" %2$s /></td>
                            <td><input type="text" name="sa_wp_group[%1$s][name]" value="%3$s" /></td>
                            <td><input type="number" name="sa_wp_group[%1$s][order]" value="%4$d" style="width:90px"/></td>
                            <td><input type="text" name="sa_wp_group[%1$s][section]" value="%5$s" /></td>
                        </tr>',
                        esc_attr($slug),
                        $row['enabled'] ? 'checked' : '',
                        esc_attr($row['name']),
                        (int)$row['order'],
                        esc_attr($row['section'])
                    );
                }
                echo '</tbody></table>';
                submit_button('Spara gruppinställningar');
                echo '</form>';

                echo '<h3>Skapa sidor</h3>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('sa_wp_groups');
                echo '<input type="hidden" name="action" value="sa_wp_make_pages" />';
                echo '<p>Skapa eller uppdatera WordPress-sidor för varje aktiverad grupp.</p>';
                echo '<button class="button button-secondary">Skapa/uppdatera undersidor</button></form>';
            }
            break;
            
        case 'display':
            echo '<h3>Visningsinställningar</h3>';
            echo '<form method="post" action="options.php">';
            settings_fields('sa_wp_group');
            
            echo '<table class="form-table">';
            echo '<tr><th><label for="'.SA_WP_SECTIONS_ORDER.'">Sektionsordning</label></th>';
            echo '<td><input type="text" name="'.SA_WP_SECTIONS_ORDER.'" value="'.esc_attr(get_option(SA_WP_SECTIONS_ORDER,'')).'" class="regular-text" placeholder="Senior, Ungdom, Akademi" /><br>';
            echo '<small>Ange i vilken ordning sektionerna ska visas (kommaseparerat)</small></td></tr>';
            echo '</table>';
            
            submit_button('Spara visningsinställningar');
            echo '</form>';
            
            echo '<hr><h3>Färginställningar för accordion</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('sa_wp_groups');
            echo '<input type="hidden" name="action" value="sa_wp_save_colors" />';
            
            $colors = get_option(SA_WP_ACCORDION_COLORS, []);
            echo '<table class="form-table"><tr>
                <th><label>Bakgrundsfärg (summary):</label></th>
                <td><input type="color" name="sa_wp_accordion_colors[background]" value="'.esc_attr($colors['background'] ?? '').'" /><br><small>Styr den röda bakgrunden för sektionerna</small></td>
            </tr><tr>
                <th><label>Öppen sektion (bakgrund):</label></th>
                <td><input type="color" name="sa_wp_accordion_colors[open]" value="'.esc_attr($colors['open'] ?? '').'" /><br><small>Bakgrundsfärg när en sektion är öppen (t.ex. gul)</small></td>
            </tr><tr>
                <th><label>Textfärg (stängd):</label></th>
                <td><input type="color" name="sa_wp_accordion_colors[text]" value="'.esc_attr($colors['text'] ?? '').'" /><br><small>Textfärg för stängda sektioner</small></td>
            </tr><tr>
                <th><label>Textfärg (öppen):</label></th>
                <td><input type="color" name="sa_wp_accordion_colors[open_text]" value="'.esc_attr($colors['open_text'] ?? '').'" /><br><small>Textfärg för öppna sektioner (för bättre kontrast)</small></td>
            </tr></table>';
            echo '<p class="description">Lämna tomt för att använda temats färger</p>';
            submit_button('Spara färger');
            echo '</form>';
            break;
    }

    echo '</div>';
}}

/* -------------------------------------------------------------------------
   Admin-post actions
--------------------------------------------------------------------------- */
add_action('admin_post_sa_wp_fetch_groups', function() {
    if (!current_user_can('manage_options')) wp_die('forbidden', 403);
    check_admin_referer('sa_wp_groups');

    $club = get_option(SA_WP_CLUB,'');
    $year = get_option(SA_WP_YEAR,'');
    $data = sa_wp_persons($club, $year, 0);

    if (is_wp_error($data)) {
        wp_redirect(add_query_arg('sa_wp_msg', 'err', wp_get_referer())); exit;
    }

    $pers   = sa_wp_persons_array($data);
    $groups = sa_wp_groups_from_persons($pers);

    update_option(SA_WP_ALL_GROUPS, $groups, false);

    $cfg = get_option(SA_WP_GROUP_SETTINGS, []);
    foreach ($groups as $slug => $name) {
        if (!isset($cfg[$slug])) {
            $cfg[$slug] = ['name'=>$name,'enabled'=>1,'order'=>1000,'section'=>''];
        } else {
            $cfg[$slug]['name'] = $name;
        }
    }
    update_option(SA_WP_GROUP_SETTINGS, $cfg, false);
    wp_redirect(add_query_arg('sa_wp_msg', 'loaded', wp_get_referer())); exit;
});

add_action('admin_post_sa_wp_save_groups', function() {
    if (!current_user_can('manage_options')) wp_die('forbidden', 403);
    check_admin_referer('sa_wp_groups');

    $p = (array)($_POST['sa_wp_group'] ?? []);
    $cfg = [];
    foreach ($p as $slug => $r) {
        $cfg[$slug] = [
            'name'    => sanitize_text_field($r['name'] ?? $slug),
            'enabled' => isset($r['enabled']) ? 1 : 0,
            'order'   => isset($r['order']) ? intval($r['order']) : 1000,
            'section' => sanitize_text_field($r['section'] ?? '')
        ];
    }
    update_option(SA_WP_GROUP_SETTINGS, $cfg, false);
    wp_redirect(add_query_arg('sa_wp_msg', 'saved', wp_get_referer())); exit;
});

add_action('admin_post_sa_wp_make_pages', function() {
    if (!current_user_can('manage_options')) wp_die('forbidden', 403);
    check_admin_referer('sa_wp_groups');

    $cfg    = get_option(SA_WP_GROUP_SETTINGS, []);
    $parent = (int)get_option(SA_WP_PARENT_PAGE, 0);

    // Skapa/finn föräldrasida
    if (!$parent) {
        $existing = get_page_by_path('trupper');
        if ($existing) {
            $parent = (int)$existing->ID;
        } else {
            $pid = wp_insert_post([
                'post_title'   => 'Trupper',
                'post_name'    => 'trupper',
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '[sa_trupp]'
            ]);
            if (!is_wp_error($pid)) {
                $parent = (int)$pid;
            }
        }
        update_option(SA_WP_PARENT_PAGE, $parent, false);
    }

    foreach ($cfg as $slug => $row) {
        if (empty($row['enabled'])) continue;

        $title   = $row['name'];
        $content = '[sa_trupp group="'.esc_html($title).'" menu="false"]';

        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page) {
            $upd = wp_update_post([
                'ID'           => $page->ID,
                'post_parent'  => $parent,
                'post_title'   => $title,
                'post_content' => $content
            ], true);
            // skydda mot WP_Error → hoppa vidare
            if (is_wp_error($upd)) continue;
        } else {
            $new = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_parent'  => $parent,
                'post_content' => $content
            ], true);
            if (is_wp_error($new)) continue;
        }
    }

    wp_redirect(add_query_arg('sa_wp_msg', 'pages', wp_get_referer())); exit;
});

add_action('admin_post_sa_wp_save_colors', function() {
    if (!current_user_can('manage_options')) wp_die('forbidden', 403);
    check_admin_referer('sa_wp_groups');
    
    $colors = [];
    if (isset($_POST['sa_wp_accordion_colors'])) {
            $colors = [
                'background' => sanitize_hex_color($_POST['sa_wp_accordion_colors']['background'] ?? ''),
                'open' => sanitize_hex_color($_POST['sa_wp_accordion_colors']['open'] ?? ''),
                'text' => sanitize_hex_color($_POST['sa_wp_accordion_colors']['text'] ?? ''),
                'open_text' => sanitize_hex_color($_POST['sa_wp_accordion_colors']['open_text'] ?? '')
            ];
    }
    update_option(SA_WP_ACCORDION_COLORS, $colors, false);
    wp_redirect(add_query_arg('sa_wp_msg', 'colors', wp_get_referer())); exit;
});

/* -------------------------------------------------------------------------
   Shortcode
--------------------------------------------------------------------------- */
// Registrera shortcoden
add_action('init', function() {
    add_shortcode('sa_trupp', 'sa_wp_shortcode_trupp');
});

// --- Shortcode: [sa_trupp layout="accordion|chips" menu="true|false" ...] ---
if (!function_exists('sa_wp_shortcode_trupp')) {
  function sa_wp_shortcode_trupp($atts) {
      $a = shortcode_atts([
          'club'   => '',
          'year'   => '',
          'group'  => '',
          'cache'  => '600',
          'menu'   => 'true',          // bakåtkompatibelt
          'layout' => 'accordion'      // NYTT: accordion (default) eller chips
      ], $atts, 'sa_trupp');
  
      $club = $a['club'] ?: get_option(SA_WP_CLUB, '');
      $year = $a['year'] ?: get_option(SA_WP_YEAR, '');
  
      if (!$club || !$year) {
          return current_user_can('manage_options')
              ? '<div class="notice notice-warning"><p>Ange klubb & år i inställningarna.</p></div>'
              : '';
      }
  
      $data = sa_wp_persons($club, $year, intval($a['cache']));
      if (is_wp_error($data)) {
          return current_user_can('manage_options')
              ? '<div class="notice notice-warning"><p>'.esc_html($data->get_error_message()).'</p></div>'
              : '';
      }
  
      $persons = sa_wp_persons_array($data);
      $groups  = sa_wp_groups_from_persons($persons);
      $cfg     = get_option(SA_WP_GROUP_SETTINGS, []);
  
      // Filtrera + sortera enligt admininställning
      if (!empty($cfg)) {
          // Filtrera bort disabled groups (enabled = 0)
          $enabledGroups = [];
          foreach ($groups as $slug => $name) {
              if (isset($cfg[$slug]) && !empty($cfg[$slug]['enabled'])) {
                  $enabledGroups[$slug] = $name;
              }
          }
          $groups = $enabledGroups;
          
          // Sortera enligt ordning
          uasort($groups, function($A, $B) use ($cfg) {
              $sa = $cfg[sa_wp_slug($A)]['order'] ?? 1000;
              $sb = $cfg[sa_wp_slug($B)]['order'] ?? 1000;
              return $sa <=> $sb ?: strcasecmp($A, $B);
          });
      }
  
      // Sektionera enligt admin (med valfri ordning i inställning)
      $sections = [];
      $ord = array_filter(array_map('trim', explode(',', get_option(SA_WP_SECTIONS_ORDER,''))));
      foreach ($groups as $slug => $name) {
          $sec = $cfg[sa_wp_slug($name)]['section'] ?? '';
          if ($sec === '' && preg_match('~^(F|P)\d{4}$~', $name)) {
              // exempel: P2011/F2012 => “Ungdom” om sektion saknas
              $sec = 'Ungdom';
          }
          $sec = $sec ?: 'Övrigt';
          $sections[$sec][$slug] = $name;
      }
      if ($ord) {
          $sections = array_replace(array_combine($ord, array_fill(0, count($ord), [])), $sections) + $sections;
      }
  
      // vald grupp via querystring
      $sel = $a['group'];
      if (!$sel && isset($_GET['sa_group'])) {
          $slug = sanitize_key($_GET['sa_group']);
          if (isset($groups[$slug])) $sel = $groups[$slug];
      }
  
      ob_start();
  
      // ====== LAYOUT: ACCORDION ======
      if ($a['layout'] === 'accordion') {
          // Lägg till anpassade färger som inline styles om de finns
          $colors = get_option(SA_WP_ACCORDION_COLORS, []);
          $style = '';
          if (!empty($colors['background'])) $style .= '--sa-acc-bg: ' . esc_attr($colors['background']) . '; --sa-acc-summary-bg: ' . esc_attr($colors['background']) . ';';
          if (!empty($colors['open'])) $style .= '--sa-acc-summary-open: ' . esc_attr($colors['open']) . ';';
          if (!empty($colors['text'])) $style .= '--sa-acc-text: ' . esc_attr($colors['text']) . '; --sa-acc-summary-text: ' . esc_attr($colors['text']) . ';';
          if (!empty($colors['open_text'])) $style .= '--sa-acc-open-text: ' . esc_attr($colors['open_text']) . ';';
          
          echo '<div class="sa-acc"' . ($style ? ' style="' . $style . '"' : '') . ' data-sa-accordion>';
  
          foreach ($sections as $secName => $items) {
              if (empty($items)) continue;
  
              // Öppna automatiskt sektionen om en grupp i den är vald
              $isOpen = $sel && in_array($sel, $items, true);
              echo '<details class="sa-acc__item"'.($isOpen ? ' open' : '').'>';
              echo '<summary class="sa-acc__summary">'.esc_html($secName).'</summary>';
              echo '<div class="sa-acc__panel"><ul class="sa-acc__list">';
  
              foreach ($items as $slug => $name) {
                  $url = esc_url(add_query_arg('sa_group', $slug, remove_query_arg('sa_group')));
                  echo '<li class="sa-acc__link"><a href="'.$url.'">'.esc_html($name).'</a></li>';
              }
  
              echo '</ul></div></details>';
          }
  
          echo '</div>'; // .sa-acc
  
      // ====== LAYOUT: CHIPS (gamla menyn) ======
      } else {
          // bakåtkompatibel chips-meny + personlista (som tidigare)
          if ($a['menu'] !== 'false') {
              echo '<nav class="sa-wp-menu">';
              foreach ($sections as $sec => $items) {
                  if (empty($items)) continue;
                  echo '<div class="sa-wp-section"><strong>'.esc_html($sec).'</strong><ul>';
                  foreach ($items as $slug => $name) {
                      $url = esc_url(add_query_arg('sa_group', $slug, remove_query_arg('sa_group')));
                      echo '<li><a href="'.$url.'">'.esc_html($name).'</a></li>';
                  }
                  echo '</ul></div>';
              }
              echo '</nav>';
          }
          if ($sel) {
              $list = sa_wp_filter_persons_group($persons, $sel);
              echo '<h2>'.esc_html($sel).'</h2><ul class="sa-wp-persons">';
              foreach ($list as $p) echo '<li>'.sa_wp_person_name($p).'</li>';
              echo '</ul>';
          } else {
              echo '<p>Välj en grupp i menyn ovan.</p>';
          }
      }
  
      return ob_get_clean();
  }}

/* -------------------------------------------------------------------------
   Assets
--------------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('sportadmin-wp', plugins_url('assets/style.css', __FILE__), [], SA_WP_VERSION);
    wp_enqueue_script('sportadmin-wp', plugins_url('assets/script.js', __FILE__), [], SA_WP_VERSION, true);
});

/* -------------------------------------------------------------------------
   Hooks – NAMNGIVNA FUNKTIONER (inte closures)
--------------------------------------------------------------------------- */
if (!function_exists('sa_wp_activate')) {
function sa_wp_activate() {
    // Här kan du lägga ev. migrering framåt
}}
if (!function_exists('sa_wp_uninstall')) {
function sa_wp_uninstall() {
    // Rensa bara våra egna options – inget annat
    delete_option(SA_WP_API_KEY);
    delete_option(SA_WP_CLUB);
    delete_option(SA_WP_YEAR);
    delete_option(SA_WP_ALL_GROUPS);
    delete_option(SA_WP_GROUP_SETTINGS);
    delete_option(SA_WP_SECTIONS_ORDER);
    delete_option(SA_WP_PARENT_PAGE);
    delete_option(SA_WP_ACCORDION_COLORS);
}}
register_activation_hook(__FILE__, 'sa_wp_activate');
register_uninstall_hook(__FILE__, 'sa_wp_uninstall');