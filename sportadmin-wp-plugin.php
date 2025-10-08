<?php
/**
 * Plugin Name: SportAdmin WP-plugin
 * Description: Truppsidor från SportAdmin. Välj klubb/år, hämta grupper, sortera/gruppera och skapa undersidor automatiskt. Använd [sa_trupp].
 * Version: 1.2.0
 * Author: SportAdmin
 * Text Domain: sportadmin-wp
 */

if (!defined('ABSPATH')) exit;

define('SA_WP_VERSION','1.2.0');
define('SA_WP_API_KEY','sa_wp_api_key');
define('SA_WP_CLUB','sa_wp_club_id');
define('SA_WP_YEAR','sa_wp_member_year');
define('SA_WP_ALL_GROUPS','sa_wp_all_groups');
define('SA_WP_GROUP_SETTINGS','sa_wp_group_settings');
define('SA_WP_SECTIONS_ORDER','sa_wp_sections_order');
define('SA_WP_PARENT_PAGE','sa_wp_parent_page_id');

function sa_wp_api_base(){ return 'https://api.sportadmin.se'; }
function sa_wp_auth(){ $k=get_option(SA_WP_API_KEY,''); return ['Authorization'=>$k]; }
function sa_wp_fetch($u){ $r=wp_remote_get($u,['headers'=>sa_wp_auth(),'timeout'=>20]); if(is_wp_error($r))return $r; $c=wp_remote_retrieve_response_code($r); $b=wp_remote_retrieve_body($r); if($c<200||$c>=300)return new WP_Error('bad_status',"$c ".wp_remote_retrieve_response_message($r),['body'=>$b]); $j=json_decode($b,true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('bad_json','JSON'); return $j; }
function sa_wp_slug($t){ $t=strtolower(function_exists('remove_accents')?remove_accents(trim((string)$t)):trim((string)$t)); $t=preg_replace('~[^a-z0-9]+~','-',$t); return trim($t,'-')?:'group'; }
function sa_wp_persons($club,$year,$ttl=600){ $u=rtrim(sa_wp_api_base(),'/').'/v3/Persons?clubId='.rawurlencode($club).'&memberYear='.rawurlencode($year); $k='sa_wp_'.md5($u); if($ttl>0){$c=get_transient($k); if($c!==false)return $c;} $d=sa_wp_fetch($u); if(!is_wp_error($d)&&$ttl>0)set_transient($k,$d,$ttl); return $d; }
function sa_wp_persons_array($d){ if(!is_array($d))return []; foreach(['items','persons','Persons','data','Data'] as $k){ if(isset($d[$k])&&is_array($d[$k])) return $d[$k]; } if(array_keys($d)===range(0,count($d)-1)) return $d; return []; }
function sa_wp_groups_from_persons($arr){ $o=[]; foreach((array)$arr as $p){ $gs=null; foreach(['groups','Groups','group','Group','teams','Teams','team','Team','Lag','Grupper'] as $k){ if(isset($p[$k])&&!empty($p[$k])){$gs=$p[$k]; break;} } if(is_string($gs))$gs=[$gs]; if(!is_array($gs))$gs=[]; foreach($gs as $g){ $n=is_array($g)?($g['groupName']??($g['GroupName']??($g['name']??($g['Name']??null)))):$g; if(!$n)continue; $o[sa_wp_slug($n)]=$n; } } ksort($o,SORT_NATURAL|SORT_FLAG_CASE); return $o; }
function sa_wp_filter_persons_group($arr,$name){ $o=[]; foreach((array)$arr as $p){ $gs=null; foreach(['groups','Groups','group','Group','teams','Teams','team','Team','Lag','Grupper'] as $k){ if(isset($p[$k])&&!empty($p[$k])){$gs=$p[$k]; break;} } if(is_string($gs))$gs=[$gs]; if(!is_array($gs))$gs=[]; foreach($gs as $g){ $n=is_array($g)?($g['groupName']??($g['GroupName']??($g['name']??($g['Name']??null)))):$g; if($n && strcasecmp($n,$name)===0){$o[]=$p; break;} } } return $o; }
function sa_wp_person_name($p){ $n=$p['name']??($p['Name']??''); if(!$n){ $n=trim(($p['firstName']??($p['FirstName']??'')) .' '.($p['lastName']??($p['LastName']??''))); } return esc_html($n?:'—'); }

add_action('admin_menu', function(){ add_options_page('SportAdmin API','SportAdmin API','manage_options','sportadmin-wp','sa_wp_settings_page'); });
add_action('admin_init', function(){
  register_setting('sa_wp_group',SA_WP_API_KEY);
  register_setting('sa_wp_group',SA_WP_CLUB);
  register_setting('sa_wp_group',SA_WP_YEAR);
  register_setting('sa_wp_group',SA_WP_SECTIONS_ORDER);
  register_setting('sa_wp_group',SA_WP_PARENT_PAGE,'absint');

  add_settings_section('sa_wp_sec','Autentisering & Standarder',function(){echo '<p>Fyll i API-nyckel samt standard klubb & år.</p>';},'sportadmin-wp');
  add_settings_field('key','API-nyckel',function(){printf('<input type="password" name="%s" value="%s" class="regular-text" />',esc_attr(SA_WP_API_KEY),esc_attr(get_option(SA_WP_API_KEY,'')));},'sportadmin-wp','sa_wp_sec');
  add_settings_field('club','Klubb-ID',function(){printf('<input type="text" name="%s" value="%s" class="regular-text" />',esc_attr(SA_WP_CLUB),esc_attr(get_option(SA_WP_CLUB,'')));},'sportadmin-wp','sa_wp_sec');
  add_settings_field('year','Medlemsår',function(){printf('<input type="text" name="%s" value="%s" class="regular-text" />',esc_attr(SA_WP_YEAR),esc_attr(get_option(SA_WP_YEAR,'')));},'sportadmin-wp','sa_wp_sec');
  add_settings_field('sections','Sektioners ordning',function(){printf('<input type="text" name="%s" value="%s" class="regular-text" placeholder="Seniorlag, Akademi, Ungdom" />',esc_attr(SA_WP_SECTIONS_ORDER),esc_attr(get_option(SA_WP_SECTIONS_ORDER,'')));},'sportadmin-wp','sa_wp_sec');
  add_settings_field('parent','Föräldersida',function(){wp_dropdown_pages(['name'=>SA_WP_PARENT_PAGE,'selected'=>(int)get_option(SA_WP_PARENT_PAGE,0),'show_option_none'=>'(Skapa ny “Trupper”-sida automatiskt)']);},'sportadmin-wp','sa_wp_sec');
});

function sa_wp_settings_page(){
  if(!current_user_can('manage_options')) return;
  $all=get_option(SA_WP_ALL_GROUPS,[]);
  $cfg=get_option(SA_WP_GROUP_SETTINGS,[]);
  echo '<div class="wrap"><h1>SportAdmin API</h1>';
  echo '<form method="post" action="options.php">'; settings_fields('sa_wp_group'); do_settings_sections('sportadmin-wp'); submit_button(); echo '</form>';

  echo '<hr><h2>Grupper</h2>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-bottom:1em">';
  wp_nonce_field('sa_wp_groups');
  echo '<input type="hidden" name="action" value="sa_wp_fetch_groups" /><button class="button button-primary">Hämta grupper</button></form>';

  if(empty($all)){ echo '<p>Inga grupper inlästa än.</p>'; }
  else{
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('sa_wp_groups');
    echo '<input type="hidden" name="action" value="sa_wp_save_groups" />';
    echo '<table class="widefat striped"><thead><tr><th>Visa</th><th>Grupp</th><th>Ordning</th><th>Sektion</th></tr></thead><tbody>';
    foreach($all as $slug=>$name){
      $row=$cfg[$slug]??['name'=>$name,'enabled'=>1,'order'=>1000,'section'=>''];
      printf('<tr><td><input type="checkbox" name="sa_wp_group[%s][enabled]" %s /></td><td><input type="text" name="sa_wp_group[%s][name]" value="%s" /></td><td><input type="number" name="sa_wp_group[%s][order]" value="%d" style="width:90px"/></td><td><input type="text" name="sa_wp_group[%s][section]" value="%s" /></td></tr>',
        esc_attr($slug),($row['enabled']?'checked':''),esc_attr($slug),esc_attr($row['name']),esc_attr($slug),(int)$row['order'],esc_attr($slug),esc_attr($row['section']));
    }
    echo '</tbody></table>';
    submit_button('Spara val');
    echo '</form>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:1em">';
    wp_nonce_field('sa_wp_groups');
    echo '<input type="hidden" name="action" value="sa_wp_make_pages" /><button class="button">Skapa/uppdatera undersidor</button></form>';
  }
  echo '</div>';
}

add_action('admin_post_sa_wp_fetch_groups',function(){
  if(!current_user_can('manage_options'))wp_die('forbidden',403);
  check_admin_referer('sa_wp_groups');
  $d=sa_wp_persons(get_option(SA_WP_CLUB,''),get_option(SA_WP_YEAR,''),0);
  if(is_wp_error($d)){wp_redirect(add_query_arg('sa_wp_msg','err',wp_get_referer())); exit;}
  $pers=sa_wp_persons_array($d);
  $groups=sa_wp_groups_from_persons($pers);
  update_option(SA_WP_ALL_GROUPS,$groups,false);
  $cfg=get_option(SA_WP_GROUP_SETTINGS,[]);
  foreach($groups as $slug=>$name){
    if(!isset($cfg[$slug])) $cfg[$slug]=['name'=>$name,'enabled'=>1,'order'=>1000,'section'=>''];
    else $cfg[$slug]['name']=$name;
  }
  update_option(SA_WP_GROUP_SETTINGS,$cfg,false);
  wp_redirect(add_query_arg('sa_wp_msg','loaded',wp_get_referer())); exit;
});

add_action('admin_post_sa_wp_save_groups',function(){
  if(!current_user_can('manage_options'))wp_die('forbidden',403);
  check_admin_referer('sa_wp_groups');
  $p=(array)($_POST['sa_wp_group']??[]);
  $cfg=[];
  foreach($p as $slug=>$r){
    $cfg[$slug]=[
      'name'=>sanitize_text_field($r['name']??$slug),
      'enabled'=>isset($r['enabled'])?1:0,
      'order'=>isset($r['order'])?intval($r['order']):1000,
      'section'=>sanitize_text_field($r['section']??'')
    ];
  }
  update_option(SA_WP_GROUP_SETTINGS,$cfg,false);
  wp_redirect(add_query_arg('sa_wp_msg','saved',wp_get_referer())); exit;
});

add_action('admin_post_sa_wp_make_pages',function(){
  if(!current_user_can('manage_options'))wp_die('forbidden',403);
  check_admin_referer('sa_wp_groups');
  $cfg=get_option(SA_WP_GROUP_SETTINGS,[]);
  $parent=(int)get_option(SA_WP_PARENT_PAGE,0);
  if(!$parent){
    $p=get_page_by_path('trupper');
    if($p)$parent=$p->ID;
    else $parent=wp_insert_post(['post_title'=>'Trupper','post_name'=>'trupper','post_type'=>'page','post_status'=>'publish','post_content'=>'[sa_trupp]']);
    update_option(SA_WP_PARENT_PAGE,$parent,false);
  }
  foreach($cfg as $slug=>$row){
    if(empty($row['enabled']))continue;
    $title=$row['name'];
    $content='[sa_trupp group="'.esc_html($title).'" menu="false"]';
    $exist=get_page_by_path($slug,'OBJECT','page');
    if($exist){ wp_update_post(['ID'=>$exist->ID,'post_parent'=>$parent,'post_title'=>$title,'post_content'=>$content]); }
    else{ wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_type'=>'page','post_status'=>'publish','post_parent'=>$parent,'post_content'=>$content]); }
  }
  wp_redirect(add_query_arg('sa_wp_msg','pages',wp_get_referer())); exit;
});

add_shortcode('sa_trupp',function($atts){
  $a=shortcode_atts(['club'=>'','year'=>'','group'=>'','cache'=>'600','menu'=>'true'],$atts,'sa_trupp');
  $club=$a['club']?:get_option(SA_WP_CLUB,'');
  $year=$a['year']?:get_option(SA_WP_YEAR,'');
  if(!$club||!$year) return current_user_can('manage_options')?'<div class="notice notice-warning"><p>Ange klubb & år i inställningarna.</p></div>':'';
  $d=sa_wp_persons($club,$year,intval($a['cache']));
  if(is_wp_error($d)) return current_user_can('manage_options')?'<div class="notice notice-warning"><p>'.esc_html($d->get_error_message()).'</p></div>':'';
  $persons=sa_wp_persons_array($d);
  $groups=sa_wp_groups_from_persons($persons);
  $cfg=get_option(SA_WP_GROUP_SETTINGS,[]);
  if(!empty($cfg)){
    $groups=array_intersect_key($groups,$cfg);
    uasort($groups,function($A,$B)use($cfg){
      $sa=$cfg[sa_wp_slug($A)]['order']??1000;
      $sb=$cfg[sa_wp_slug($B)]['order']??1000;
      return $sa<=>$sb ?: strcasecmp($A,$B);
    });
  }
  $sel=$a['group'];
  if(!$sel && isset($_GET['sa_group'])){
    $slug=sanitize_key($_GET['sa_group']);
    $sel=$groups[$slug]??'';
  }

  ob_start();
  if($a['menu']!=='false'){
    $sections=[]; $ord=array_filter(array_map('trim',explode(',',get_option(SA_WP_SECTIONS_ORDER,''))));
    foreach($groups as $slug=>$name){
      $sec=$cfg[sa_wp_slug($name)]['section']??'';
      $sec=$sec?:'Övrigt';
      $sections[$sec][$slug]=$name;
    }
    $keys=$ord?$ord:array_keys($sections);
    echo '<nav class="sa-wp-menu">';
    foreach($keys as $sk){
      if(empty($sections[$sk]))continue;
      echo '<div class="sa-wp-section"><strong>'.esc_html($sk).'</strong><ul>';
      foreach($sections[$sk] as $slug=>$name){
        $url=esc_url(add_query_arg('sa_group',$slug,remove_query_arg('sa_group')));
        echo '<li><a href="'.$url.'">'.esc_html($name).'</a></li>';
      }
      echo '</ul></div>';
    }
    echo '</nav>';
  }

  if($sel){
    $list=sa_wp_filter_persons_group($persons,$sel);
    echo '<h2>'.esc_html($sel).'</h2><ul class="sa-wp-persons">';
    foreach($list as $p) echo '<li>'.sa_wp_person_name($p).'</li>';
    echo '</ul>';
  } else {
    echo '<p>Välj en grupp i menyn ovan.</p>';
  }

  return ob_get_clean();
});

add_action('wp_enqueue_scripts',function(){
  wp_enqueue_style('sportadmin-wp',plugins_url('assets/style.css',__FILE__),[],SA_WP_VERSION);
});
