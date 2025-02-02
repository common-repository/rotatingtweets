<?php
/*
Plugin Name: Rotating Tweets (Twitter widget & shortcode)
Description: Replaces a shortcode such as [rotatingtweets screen_name='your_twitter_name'], or a widget, with a rotating tweets display 
Version: 1.9.11
Text Domain: rotatingtweets
Domain Path: /languages
Author: Martin Tod
Author URI: http://www.martintod.org.uk
License: GPLv2
*/
/*  Copyright 2020-24 Martin Tod email : martin@martintod.org.uk)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
/**
 * Replaces a shortcode such as [rotatingtweets screen_name='your_twitter_name'], or a widget, with a rotating tweets display 
 *
 * @package WordPress
 * @since 3.3.2
 *
 */
require_once('lib/wp_twitteroauth.php');
/**
 * rotatingtweets_Widget_Class
 * Shows tweets sequentially for a given user
 */
class rotatingtweets_Widget extends WP_Widget {
    /** constructor */
    public function __construct() {
		// Does the JavaScript support selective refresh?
		
		
		parent::__construct(
			'rotatingtweets_widget', // Base ID
			__( 'Rotating Tweets', 'rotatingtweets' ), // Name
			array( 
				'description' => __('A widget to show tweets for a particular user in rotation.', 'rotatingtweets'), 
				 'customize_selective_refresh' => true,
			) // Args
		);
		if ( is_active_widget( false, false, $this->id_base, true )  || function_exists('is_customize_preview') && is_customize_preview() ) {
			rotatingtweets_enqueue_scripts(); 
		}
	}

    /** @see WP_Widget::widget */
    public function widget($args, $instance) {		
		extract( $args );
		if(!isset($instance['title'])):
			$instance = array(
				'title'			=>	'',
				'tw_show_follow'	=>	FALSE,
				'tw_show_type'		=>	0,
				'tw_screen_name'	=>	'',
				'tw_include_rts'	=>	FALSE,
				'tw_exclude_replies'=>	FALSE,
				'tw_tweet_count'	=>	5,
				'tw_rotation_type'	=>	'scrollUp',
				'tw_official_format'=>	FALSE,
				'tw_hide_meta_timestamp' => FALSE,
				'tw_hide_meta_screen_name' => FALSE,
				'tw_hide_meta_via'	=> 	FALSE,
				'tw_show_meta_reply_retweet_favorite' => FALSE,
				'tw_show_line_breaks' => FALSE
			);
		endif;
        $title = apply_filters('widget_title', $instance['title']);
		$positive_variables = array('screen_name','shorten_links','include_rts','exclude_replies','links_in_new_window','tweet_count','show_follow','timeout','rotation_type','show_meta_reply_retweet_favorite','official_format','show_type','list_tag','search','show_line_breaks');
		$newargs['displaytype']='widget';
		$newargs['w3tc_render_to']=$args['widget_id'];
		foreach($positive_variables as $var) {
			if(isset($instance['tw_'.$var])):
				$newargs[$var] = $instance['tw_'.$var];
			endif;
		}
		$negative_variables = array('meta_timestamp','meta_screen_name','meta_via');
		foreach($negative_variables as $var) {
			if(isset($instance['tw_hide_'.$var])):
				$newargs['show_'.$var] = !$instance['tw_hide_'.$var];
			endif;
		}
		switch($newargs['show_follow']) {
		case 2: 
			$newargs['no_show_count'] = TRUE;
			$newargs['no_show_screen_name'] = FALSE;
			break;
		case 3: 
			$newargs['no_show_count'] = FALSE;
			$newargs['no_show_screen_name'] = TRUE;
			break;
		case 4:
			$newargs['no_show_count'] = TRUE;
			$newargs['no_show_screen_name'] = TRUE;
			break;
		default: 
			$newargs['no_show_count'] = FALSE;
			$newargs['no_show_screen_name'] = FALSE;
			break;
		}
		if(empty($newargs['timeout'])) $newargs['timeout'] = 4000;
		$newargs['screen_name'] = rotatingtweets_clean_screen_name($newargs['screen_name']);
		$newargs['text_cache_id'] = "rt-wg-".md5(serialize($newargs));
//		$rt_tweet_string = rotatingtweets_get_transient($newargs['text_cache_id']);
		$rotatingtweets_otk = rotatingtweets_get_transient($newargs['text_cache_id']&'-otk');
		if($rotatingtweets_otk):
			$rt_tweet_string = rotatingtweets_get_transient($newargs['text_cache_id']);
		else:
			$rt_tweet_string = "";
			// Automatically delete text cache every 10 minutes
			rotatingtweets_set_transient($newargs['text_cache_id']&'-otk',1,10*60);
			delete_transient($newargs['text_cache_id']);
		endif;

		echo wp_kses_post($before_widget);
		if ( $title ):
				echo wp_kses_post($before_title . $title . $after_title); 
		endif;		
		if(empty($rt_tweet_string)):
			switch($newargs['show_type']) {
				// Favourites
				case 1:
					$tweets = rotatingtweets_get_tweets($newargs['screen_name'],$newargs['include_rts'],$newargs['exclude_replies'],true);
					break;
				// Search
				case 2:	
					$tweets = rotatingtweets_get_tweets($newargs['screen_name'],$newargs['include_rts'],$newargs['exclude_replies'],false,$newargs['search']);
	//				$newargs['screen_name'] = '';   // Originally put in to avoid confusion when people have a 'follow' button and a search tweet
					break;
				// List
				case 3:	
					$tweets = rotatingtweets_get_tweets($newargs['screen_name'],$newargs['include_rts'],$newargs['exclude_replies'],false,false,$newargs['list_tag']);
					break;	
				// Buddypress
				case 4:		
					if( function_exists('bp_displayed_user_id')):
						global $bp;
						$rt_buddyid = bp_displayed_user_id();
						$rt_buddyargs = array ('field' => 'Twitter', 'user_id'=> $rt_buddyid );
						print_r($rt_buddyargs);
						$rt_buddytwitter = bp_get_profile_field_data( $rt_buddyargs );
						$tweets = rotatingtweets_get_tweets($rt_buddytwitter,$newargs['include_rts'],$newargs['exclude_replies']);
						break;
					endif;
				// User name
				case 0:	
				default:
					$tweets = rotatingtweets_get_tweets($newargs['screen_name'],$newargs['include_rts'],$newargs['exclude_replies']);
					break;
			}
			if($tweets):
				$rt_tweet_string = rotating_tweets_display($tweets,$newargs,false);
			endif;
		elseif(WP_DEBUG):
			$rt_tweet_string .= "<!-- Transient ".$newargs['text_cache_id']." loaded -->";
		endif;
		echo wp_kses_post($rt_tweet_string.$after_widget);
    }

    /** @see WP_Widget::update */
	public function update($new_instance, $old_instance) {				
		$instance = $old_instance;
		$instance['title'] = wp_strip_all_tags($new_instance['title']);
		$instance['tw_screen_name'] = rotatingtweets_clean_screen_name($new_instance['tw_screen_name']);
		$instance['tw_list_tag'] = wp_strip_all_tags(trim($new_instance['tw_list_tag']));
		$instance['tw_search'] = wp_strip_all_tags(trim($new_instance['tw_search']));
		$instance['tw_rotation_type'] = wp_strip_all_tags(trim($new_instance['tw_rotation_type']));
		$instance['tw_include_rts'] = absint($new_instance['tw_include_rts']);
		$instance['tw_links_in_new_window'] = absint($new_instance['tw_links_in_new_window']);
		$instance['tw_exclude_replies'] = absint($new_instance['tw_exclude_replies']);
		$instance['tw_shorten_links'] = absint($new_instance['tw_shorten_links']);
		$instance['tw_tweet_count'] = max(1,intval($new_instance['tw_tweet_count']));
		$instance['tw_show_follow'] = absint($new_instance['tw_show_follow']);
		$instance['tw_show_type'] = absint($new_instance['tw_show_type']);
		$instance['tw_show_line_breaks'] = absint($new_instance['tw_show_line_breaks']);
		# Complicated way to ensure the defaults remain as they were before the 0.500 upgrade - i.e. showing meta timestamp, screen name and via, but not reply, retweet, favorite
		$instance['tw_hide_meta_timestamp'] = !$new_instance['tw_show_meta_timestamp'];
		$instance['tw_hide_meta_screen_name'] = !$new_instance['tw_show_meta_screen_name'];
		$instance['tw_hide_meta_via'] = !$new_instance['tw_show_meta_via'];
		$instance['tw_official_format'] = absint($new_instance['tw_official_format']);
		$instance['tw_show_meta_reply_retweet_favorite'] = absint($new_instance['tw_show_meta_reply_retweet_favorite']);
		$instance['tw_timeout'] = max(min(intval($new_instance['tw_timeout']/1000)*1000,20000),3000);
	return $instance;
    }
	
    /** @see WP_Widget::form */
    public function form($instance) {				
		$variables = array( 
			'title' => array('title','','string'),
			'tw_screen_name' => array ('tw_screen_name','', 'string'),
			'tw_rotation_type' => array('tw_rotation_type','scrollUp', 'string'),
			'tw_include_rts' => array('tw_include_rts', false, 'boolean'),
			'tw_exclude_replies' => array('tw_exclude_replies', false, 'boolean'),
			'tw_tweet_count' => array('tw_tweet_count',5,'number'),
			'tw_show_follow' => array('tw_show_follow',false, 'boolean'),
			'tw_shorten_links' => array('tw_shorten_links',false, 'boolean'),
			'tw_official_format' => array('tw_official_format',0,'format'),
			'tw_show_type' => array('tw_show_type',0,'number'),
			'tw_links_in_new_window' => array('tw_links_in_new_window',false, 'boolean'),
			'tw_show_line_breaks' => array('tw_show_line_breaks',false, 'boolean'),
			'tw_hide_meta_timestamp' => array('tw_show_meta_timestamp',true, 'notboolean',true),
			'tw_hide_meta_screen_name' => array('tw_show_meta_screen_name',true, 'notboolean',true),
			'tw_hide_meta_via'=> array('tw_show_meta_via',true,'notboolean',true),
			'tw_show_meta_reply_retweet_favorite' => array('tw_show_meta_reply_retweet_favorite',false,'boolean',true),
			'tw_timeout' => array('tw_timeout',4000,'number'),
			'tw_list_tag' => array('tw_list_tag','','string'),
			'tw_search' => array('tw_search','','string')
		);
		foreach($variables as $var => $val) {
			if(isset($instance[$var])):
				switch($val[2]):
					case "string":
						${$val[0]} = esc_attr(trim($instance[$var]));
						break;
					case "format":
						if($instance[$var]==='custom'):
							${$val[0]} = 'custom';
						else:
							${$val[0]} = absint($instance[$var]);
						endif;
						break;
					case "number":
					case "boolean":
						${$val[0]} = absint($instance[$var]);
						break;
					case "notboolean":
						${$val[0]} = !$instance[$var];
						break;
				endswitch;
			else:
				${$val[0]} = $val[1];
			endif;
			if(isset($val[3])):
				$metaoption[$val[0]]=${$val[0]};
				unset(${$val[0]});
			endif;
		}
        ?>
		<p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:','rotatingtweets'); ?> <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></label></p>
		<?php
		$hidestr ='';
		if($tw_show_type < 3) $hidestr = ' style="display:none;"';
		if($tw_show_type != 2):
			$hidesearch = ' style="display:none;"';
			$hideuser = '';
		else:
			$hideuser =  ' style="display:none;"';
			$hidesearch = '';
		endif;
		?>
		<p class='rtw_ad_not_search' <?php echo esc_attr($hideuser);?>><label for="<?php echo esc_attr($this->get_field_id('tw_screen_name')); ?>"><?php esc_html_e('Twitter name:','rotatingtweets'); ?><input class="widefat" id="<?php echo esc_attr($this->get_field_id('tw_screen_name')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_screen_name')); ?>"  value="<?php echo esc_attr($tw_screen_name); ?>" /></label></p>
		<p class='rtw_ad_search'<?php echo esc_attr($hidesearch);?>><label for="<?php echo esc_attr($this->get_field_id('tw_search')); ?>"><?php esc_html_e('Search:','rotatingtweets'); ?><input class="widefat" id="<?php echo esc_attr($this->get_field_id('tw_search')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_search')); ?>"  value="<?php echo esc_attr($tw_search); ?>" /></label></p>
		<p class='rtw_ad_list_tag' <?php echo esc_attr($hidestr);?>><label for="<?php echo esc_attr($this->get_field_id('tw_list_tag')); ?>"><?php esc_html_e('List Tag:','rotatingtweets'); ?> <input class="widefat" id="<?php echo esc_attr($this->get_field_id('tw_list_tag')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_list_tag')); ?>"  value="<?php echo esc_attr($tw_list_tag); ?>" /></label></p>
		<p><?php esc_html_e('Type of Tweets?','rotatingtweets'); ?></p><p>
		<?php
		$typeoptions = array (
							"0" => __("User timeline (default)",'rotatingtweets'),
							"1" => __("Favorites",'rotatingtweets'),
							"2" => __("Search",'rotatingtweets'),
							"3" => __("List",'rotatingtweets')
		);
		if (is_plugin_active('buddypress/bp-loader.php')):
//			$typeoptions["4"] = __("User timeline (BuddyPress)",'rotatingtweets');
		elseif($tw_show_type==4):
			$tw_show_type = 0;
		endif;
		foreach ($typeoptions as $val => $html) {
			echo "<input type='radio' value='".esc_attr($val)."' id='".esc_attr($this->get_field_id('tw_show_type_'.$val))."' name= '".esc_attr($this->get_field_name('tw_show_type'))."'";
			if($tw_show_type==$val): ?> checked="checked" <?php endif; 
			echo " class='rtw_ad_type'><label for='".esc_attr($this->get_field_id('tw_show_type_'.$val))."'>".esc_html($html)."</label><br />";
		};
		?></p>
		<p><input id="<?php echo esc_attr($this->get_field_id('tw_include_rts')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_include_rts')); ?>" type="checkbox" value="1" <?php if($tw_include_rts==1): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id('tw_include_rts')); ?>"> <?php esc_html_e('Include retweets?','rotatingtweets'); ?></label>
		<br /><input id="<?php echo esc_attr($this->get_field_id('tw_exclude_replies')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_exclude_replies')); ?>" type="checkbox" value="1" <?php if($tw_exclude_replies==1): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id('tw_exclude_replies')); ?>"> <?php esc_html_e('Exclude replies?','rotatingtweets'); ?></label>
		<br /><input id="<?php echo esc_attr($this->get_field_id('tw_shorten_links')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_shorten_links')); ?>" type="checkbox" value="1" <?php if(!empty($tw_shorten_links)): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id('tw_shorten_links')); ?>"> <?php esc_html_e('Shorten links?','rotatingtweets'); ?></label>
		<br /><input id="<?php echo esc_attr($this->get_field_id('tw_show_line_breaks')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_show_line_breaks')); ?>" type="checkbox" value="1" <?php if(!empty($tw_show_line_breaks)): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id('tw_show_line_breaks')); ?>"> <?php esc_html_e('Show line breaks?','rotatingtweets'); ?></label>
		<br /><input id="<?php echo esc_attr($this->get_field_id('tw_links_in_new_window')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_links_in_new_window')); ?>" type="checkbox" value="1" <?php if($tw_links_in_new_window==1): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id('tw_links_in_new_window')); ?>"> <?php esc_html_e('Open all links in new window or tab?','rotatingtweets'); ?></label></p>
		<p><label for="<?php echo esc_attr($this->get_field_id('tw_tweet_count')); ?>"><?php esc_html_e('How many tweets?','rotatingtweets'); ?> <select id="<?php echo esc_attr($this->get_field_id('tw_tweet_count')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_tweet_count'));?>">
		<?php 
		for ($i=1; $i<61; $i++) {
			echo "\n\t<option value='".esc_attr($i)."' ";
		if($tw_tweet_count==$i): ?>selected="selected" <?php endif; 
			echo ">".esc_html($i)."</option>";
		}			
		?></select></label></p>
		<p><label for="<?php echo esc_attr($this->get_field_id('tw_timeout')); ?>"><?php esc_html_e('Speed','rotatingtweets'); ?> <select id="<?php echo esc_attr($this->get_field_id('tw_timeout')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_timeout'));?>">
		<?php 
		$timeoutoptions = array (
							"3000" => __("Faster (3 seconds)",'rotatingtweets'),
							"4000" => __("Normal (4 seconds)",'rotatingtweets'),
							"5000" => __("Slower (5 seconds)",'rotatingtweets'),
							"6000" => __("Slowest (6 seconds)",'rotatingtweets'),
							"20000" => __("Ultra slow (20 seconds)",'rotatingtweets'),
		);
		foreach ($timeoutoptions as $val => $words) {
			echo "\n\t<option value='".esc_attr($val)."' ";
		if($tw_timeout==$val): ?>selected="selected" <?php endif; 
			echo ">".esc_html($words)."</option>";
		}			
		?></select></label></p>
		<?php
		# For reference, all the rotations that look good.
		# $goodRotations = array('blindX','blindY','blindZ','cover','curtainY','fade','growY','none','scrollUp','scrollDown','scrollLeft','scrollRight','scrollHorz','scrollVert','shuffle','toss','turnUp','turnDown','uncover');
		$rotationoptions = rotatingtweets_possible_rotations(true);
		asort($rotationoptions);
		?>
		<p><label for="<?php echo esc_attr($this->get_field_id('tw_rotation_type')); ?>"><?php esc_html_e('Type of rotation','rotatingtweets'); ?> <select id="<?php echo esc_attr($this->get_field_id('tw_rotation_type')); ?>" name="<?php echo esc_attr($this->get_field_name('tw_rotation_type'));?>">
		<?php 		
		foreach ($rotationoptions as $val => $words) {
			echo "\n\t<option value='".esc_attr($val)."' ";
		if($tw_rotation_type==$val): ?>selected="selected" <?php endif; 
			echo ">".esc_html($words)."</option>";
		}			
		?></select></label></p>
		<?php /* Ask about which Tweet details to show */ ?>
		<p><?php esc_html_e('Display format','rotatingtweets'); ?></p>
<?php
		$officialoptions = array (
			0 => __('Original rotating tweets layout','rotatingtweets'),
			/* translators: %s is replaced with the latest link to the official X/Twitter guidelines */
			1 => sprintf(__("<a target='_blank' rel='noopener' href='%s'>Official Twitter guidelines</a> (regular)",'rotatingtweets'),'https://dev.twitter.com/overview/terms/display-requirements'),
			/* translators: %s is replaced with the latest link to the official X/Twitter guidelines */
			2 => sprintf(__("<a target='_blank' rel='noopener' href='%s'>Official Twitter guidelines</a> (wide)",'rotatingtweets'),'https://dev.twitter.com/overview/terms/display-requirements'),
		);
		if (function_exists('rotatingtweets_display_override')) {
			$officialoptions['custom'] = __('Custom display layout','rotatingtweets');  
		}
		foreach ($officialoptions as $val => $html) {
			echo "<input type='radio' value='".esc_attr($val)."' id='".esc_attr($this->get_field_id('tw_official_format_'.$val))."' name= '".esc_attr($this->get_field_name('tw_official_format'))."'";
			if($tw_official_format==$val): ?> checked="checked" <?php endif; 
			echo " class='rtw_ad_official'><label for='".esc_attr($this->get_field_id('tw_official_format_'.$val))."'>".esc_html($html)."</label><br />";
		};
		$hideStr='';
		if($tw_official_format > 0) $hideStr = ' style = "display:none;" ';
		?>
		<p /><div class='rtw_ad_tw_det' <?php echo esc_attr($hideStr);?>><p><?php esc_html_e('Show tweet details?','rotatingtweets'); ?></p><p>
		<?php
		$tweet_detail_options = array(
			'tw_show_meta_timestamp' => __('Time/date of tweet','rotatingtweets'),
			'tw_show_meta_screen_name' => __('Name of person tweeting','rotatingtweets'),
			'tw_show_meta_via' => __('Source of tweet','rotatingtweets'),
			'tw_show_meta_reply_retweet_favorite' => __("'reply &middot; retweet &middot; favorite' links",'rotatingtweets')
		);
		$tw_br='';
		foreach ($tweet_detail_options as $field => $text):
		echo wp_kses($tw_br,array('br'=>array()));
		?>
		<input id="<?php echo esc_attr($this->get_field_id($field)); ?>" name="<?php echo esc_attr($this->get_field_name($field)); ?>" type="checkbox" value="1" <?php if($metaoption[$field]==1): ?>checked="checked" <?php endif; ?>/><label for="<?php echo esc_attr($this->get_field_id($field)); ?>"> <?php echo esc_html($text); ?></label>
		<?php 
		$tw_br = "<br />";
		endforeach; ?></p></div>
		<div class='rtw_ad_sf'>
		<p><?php esc_html_e('Show follow button?','rotatingtweets'); ?></p>
<?php
		$showfollowoptions = array (
			0 => _x('None','Show follow button?','rotatingtweets'),
			1 => __("Show name and number of followers",'rotatingtweets'),
			2 => __("Show name only",'rotatingtweets'),
			3 => __("Show followers only",'rotatingtweets'),
			4 => __("Show button only",'rotatingtweets')
		);

		foreach ($showfollowoptions as $val => $html) {
			echo "<input type='radio' value='".esc_attr($val)."' id='".esc_attr($this->get_field_id('tw_tweet_count_'.$val))."' name= '".esc_attr($this->get_field_name('tw_show_follow'))."'";
			if($tw_show_follow==$val): ?> checked="checked" <?php endif; 
			echo "><label for='".esc_attr($this->get_field_id('tw_tweet_count_'.$val))."'>".esc_html($html)."</label><br />";
		}
		# This is an appalling hack to deal with the problem that jQuery gets broken when people hit save - as per http://lists.automattic.com/pipermail/wp-hackers/2011-March/037997.html - but it works!
//		echo "<script type='text/javascript' src='".plugins_url('js/rotating_tweet_admin.js', __FILE__)."'></script>";
		echo "</div>\n";
/*
		echo "</div>\n<script type='text/javascript'>\n";
		$rtw_admin_script_original = file_get_contents(plugin_dir_path(__FILE__).'js/rotating_tweet_admin.js');
		$rtw_admin_script_final = str_replace(
			array('.rtw_ad_official','.rtw_ad_type'),
			array('[name="'.$this->get_field_name('tw_official_format').'"]','[name="'.$this->get_field_name('tw_show_type').'"]'),
			$rtw_admin_script_original);
		echo $rtw_admin_script_final;
		echo "\n</script>";
*/
	}
} // class rotatingtweets_Widget

// register rotatingtweets_Widget widget
function rotatingtweets_register_widgets() {
	register_widget( 'rotatingtweets_Widget' );
}

add_action('widgets_init', 'rotatingtweets_register_widgets');

# Converts Tweet timestamp into a time description
function rotatingtweets_contextualtime($small_ts, $large_ts=false) {
  if(!$large_ts) $large_ts = time();
  $n = $large_ts - $small_ts;
  if($n <= 1) return __('less than a second ago','rotatingtweets');
  /* translators: %d is replaced with the number of seconds since the tweet was posted */
  if($n < (60)) return sprintf(__('%d seconds ago','rotatingtweets'),$n);
  if($n < (60*60)) { $minutes = round($n/60); 
	if($n == 1):
		return __('about a minute ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of minutes since the tweet was posted */
		return sprintf(_n('about %d minute ago','about %d minutes ago',$minutes,'rotatingtweets'),$minutes); 
	endif;
}
  
  if($n < (60*60*16)) { $hours = round($n/(60*60)); 
	if($hours == 1):
		return __('about an hour ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of hours since the tweet was posted */
		return sprintf(_n('about %d hour ago','about %d hours ago',$hours,'rotatingtweets'),$hours); 
	endif;
}
  if($n < (time() - strtotime('yesterday'))) return __('yesterday','rotatingtweets');
  
  if($n < (60*60*24)) { $hours = round($n/(60*60)); 
	if($hours == 1):
		return __('about an hour ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of hours since the tweet was posted */
		return sprintf(_n('about %d hour ago','about %d hours ago',$hours,'rotatingtweets'),$hours); 
	endif;
  }
  if($n < (60*60*24*6.5)) { $days = round($n/(60*60*24)); 
	if($days ==1):
		return __('about a day ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of days since the tweet was posted */
		return sprintf(_n('about %d day ago','about %d days ago',$days,'rotatingtweets'),$days); 
	endif;
  }
  if($n < (time() - strtotime('last week'))) return __('last week','rotatingtweets');
  if($n < (60*60*24*7*3.5)) { $weeks = round($n/(60*60*24*7)); 
	if($weeks==1):
		return __('about a week ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of weeks since the tweet was posted */
		return sprintf(_n('about %d week ago','about %d weeks ago',$weeks,'rotatingtweets'),$weeks); 
	endif;
  } 
  if($n < (time() - strtotime('last month'))) return __('last month','rotatingtweets');
  
  if($n < (60*60*24*7*4*11.5)) { $months = round($n/(60*60*24*7*4)) ; 
	if($months==1):
		return __('about a month ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of months since the tweet was posted */
		return sprintf(_n('about %d month ago','about %d months ago',$months,'rotatingtweets'),$months);
	endif;
  }
  if($n < (time() - strtotime('last year'))) return __('last year','rotatingtweets');
  if($n >= (60*60*24*7*4*12)){$years=round($n/(60*60*24*7*52)) ;
	if($years==1):
		return __('about a year ago','rotatingtweets');
	else:
		/* translators: %d is replaced with the number of years since the tweet was posted */
		return sprintf(_n('about %d year ago','about %d years ago',$years,'rotatingtweets'),$years);
	endif;
  }
  return false;
}
# Converts Tweet timestamp into a short time description - as specified by Twitter
function rotatingtweets_contextualtime_short($small_ts, $large_ts=false) {
  if(!$large_ts) $large_ts = time();
  $n = $large_ts - $small_ts;
  /* translators: %d is replaced with the number of seconds since the tweet was posted */
  if($n < (60)) return sprintf(_x('%ds','abbreviated timestamp in seconds','rotatingtweets'),$n);
  /* translators: %d is replaced with the number of minutes since the tweet was posted */
  if($n < (60*60)) { $minutes = round($n/60); return sprintf(_x('%dm','abbreviated timestamp in minutes','rotatingtweets'),$minutes); }
  /* translators: %d is replaced with the number of hours since the tweet was posted */
  if($n < (60*60*24)) { $hours = round($n/(60*60)); return sprintf(_x('%dh','abbreviated timestamp in hours','rotatingtweets'),$hours); }
  if($n < (60*60*24*364)) return gmdate(_x('j M','short date format as per http://uk.php.net/manual/en/function.date.php','rotatingtweets'),$small_ts);
  return gmdate(_x('j M Y','slightly longer date format as per http://uk.php.net/manual/en/function.date.php','rotatingtweets'),$small_ts);
}
# Get reply,retweet,favorite intents - either words-only (option 0) or icons only (option 1) or both (option 2)
function rotatingtweets_intents($twitter_object,$lang, $icons = 1,$targetvalue='') {
	$addstring = array();
	$types = array (
		array ( 'link'=>'https://twitter.com/intent/tweet?in_reply_to=', 'icon'=>'images/reply.png', 'text' => __('reply', 'rotatingtweets')),
		array ( 'link'=>'https://twitter.com/intent/retweet?tweet_id=', 'icon'=>'images/retweet.png', 'text' => __('retweet', 'rotatingtweets')),
		array ( 'link'=>'https://twitter.com/intent/favorite?tweet_id=', 'icon'=>'images/favorite.png', 'text' => __('favorite', 'rotatingtweets'))
	);
	foreach($types as $type) {
		$string = "\n\t\t\t<a href='".$type['link'].$twitter_object['id_str']."' title='".esc_attr($type['text'])."' lang='{$lang}'{$targetvalue}>";
		switch($icons) {
		case 2:
			$addstring[] = $string."<img src='".plugin_dir_url( __FILE__ ).$type['icon']."' width='16' height='16' alt='".esc_attr($type['text'])."' /> {$type['text']}</a>";
			$glue = ' ';		
			break;
		case 1:
			$addstring[] = $string."<img src='".plugin_dir_url( __FILE__ ).$type['icon']."' width='16' height='16' alt='".esc_attr($type['text'])."' /></a>";
			$glue = '';
			break;
		case 0:
		default:
			$addstring[] = $string.$type['text'].'</a>';
			$glue = ' &middot; ';
			break;
		}
	}
	$string = implode($glue,$addstring);
	return($string);
}
// Produces a link to someone's name, icon or screen name (or to the text of your choice) using the 'intent' format for linking
function rotatingtweets_user_intent($person,$lang,$linkcontent,$targetvalue='',$iconsize='normal') {
	if(!is_array($person)) return;
	$return = "<a href='https://twitter.com/intent/user?screen_name=".urlencode($person['screen_name'])."' title='".esc_attr($person['name'])."' lang='{$lang}'{$targetvalue}>";
	switch($linkcontent){
	case 'icon':
		$before = '_normal.';
		$iconlink = $person['profile_image_url_https'];
		switch(strtolower($iconsize)) {
			case 'bigger':
				$after = '_bigger.';
				$iconlink = str_replace($before,$after,$iconlink);
				break;
			case 'mini':
				$after = '_mini.';
				$iconlink = str_replace($before,$after,$iconlink);
				break;
			case 'original':
				$after = '.';
				$iconlink = str_replace($before,$after,$iconlink);
				break;
			default:
				break;
		}
		$return .= "<img src='".esc_url($iconlink)."' alt='".esc_attr($person['name'])."' /></a>";
		break;
	case 'name':
		$return .= $person['name']."</a>";
		break;
	case 'screen_name':
		$return .= "@".$person['screen_name']."</a>";
		break;
	case 'blue_bird':
		/* translators: %s is replaced with name of the person or account to be followed */
		$return = "<a href='https://twitter.com/intent/user?screen_name=".urlencode($person['screen_name'])."' title='".esc_attr(sprintf(__('Follow @%s','rotatingtweets'),$person['name']))."' lang='{$lang}'{$targetvalue}>";
		$return .= '<img src="'.plugin_dir_url( __FILE__ ).'images/bird_blue_32.png" class="twitter_icon" alt="'.__('Twitter','rotatingtweets').'" /></a>';
		break;
	default:
		$return .= wp_strip_all_tags($linkcontent,'<img>')."</a>";
		break;
	}
	return ($return);
}
// Many thanks to Moondrop for highlighting the need to do this - https://wordpress.org/support/topic/no-tweets-available-mostly?replies=30
function rotatingtweets_set_transient($transient,$value,$expiration) {
	$expiration = max(intval($expiration),10);
	$newvalue = base64_encode(serialize($value));
	return set_transient($transient,$newvalue,$expiration);
}
function rotatingtweets_get_transient($transient) {
	$return = get_transient($transient);
	if(!$return):
		return $return;
	else:
		if (is_string($return)):
			$data = base64_decode($return);
			if (is_serialized($data)):
				return @unserialize($data);
			else:
				return $return;
			endif;
		else:
			return $return;
		endif;
	endif;
}

// Produces a linked timestamp for including in the tweet
function rotatingtweets_timestamp_link($twitter_object,$timetype = 'default',$targetvalue='') {
	$string = '<a '.$targetvalue.' href="https://twitter.com/twitterapi/status/'.$twitter_object['id_str'].'">';
	$tweettimestamp = strtotime($twitter_object['created_at'] );
	// echo "<!-- ".$twitter_object['created_at'] . " | " .get_option('timezone_string') ." | $tweettimestamp -->";
	switch($timetype) {
		case 'short':
			$string .= rotatingtweets_contextualtime_short($tweettimestamp);
			break;
		case 'long':
			$timezone_string = get_option('timezone_string');
			if(WP_DEBUG) {
				echo "\n<!-- Timezone debug";
				echo "\n- Default timezone used in this script: ".esc_html(date_default_timezone_get()); 
				echo "\n- Wordpress timezone setting:           ".esc_html($timezone_string);
			};
//			if(!empty($timezone_string)):
//				date_default_timezone_set( get_option('timezone_string') );
//			endif;
			if(WP_DEBUG) {
				echo "\n- Reset timezone used in this script:   ".esc_html(date_default_timezone_get()); 
				echo "\n- Tweet timestamp:                      ".esc_html($tweettimestamp);
				echo "\n- Time format:                          ".esc_html(get_option('time_format'));
				echo "\n- Display time:                         ".esc_html(date_i18n(get_option('time_format'),$tweettimestamp ));
				echo "\n-->";
			}
			$string .= date_i18n(get_option('time_format'),$tweettimestamp )." &middot; ".date_i18n(get_option('date_format') ,$tweettimestamp  );
			break;
		default:
			$string .= ucfirst(rotatingtweets_contextualtime($tweettimestamp));
			break;
	}
	$string .= '</a>';
	return ($string);
}
# Wraps the shortcode
function rotatingtweets_display($atts) {
	rotatingtweets_display_shortcode($atts,null,'',TRUE);
};
#
function rotatingtweets_link_to_screenname($link) {
	$match = '%(http://|https://|)(www\.|)twitter\.com/(#!\/|)([0-9a-z\_]+)%i';
	if(preg_match($match,$link,$result)):
		return($result[4]);
	else:
		return FALSE;
	endif;
}
# Processes the shortcode 
function rotatingtweets_display_shortcode( $atts, $content=null, $code="", $print=FALSE ) {
	// $atts    ::= twitter_id,include_rts,exclude_replies, $tweet_count,$show_follow
/**
	Possible values for get_cforms_entries()
	$screen_name :: [text]	Twitter user name
	$include_rts :: [boolean] include RTS - optional
	$exclude_replies :: [boolean] exclude replies - optional
	$tweet_count :: [integer] number of tweets to show - optional - default 5
	$show_follow :: [boolean] show follow button
	$no_show_count :: [boolean] remove count from follow button
	$no_show_screen_name :: [boolean] remove screen name from follow button
*/
	$args = shortcode_atts( array(
			'screen_name' => '',
			'user_meta_screen_name' => '',
			'url' => 'http://twitter.com/twitter',
			'include_rts' => FALSE,
			'only_rts' => FALSE,
			'exclude_replies' => FALSE,
			'tweet_count' => 5,
			'show_follow' => FALSE,
			'timeout' => 4000,
			'no_show_count' => FALSE,
			'no_show_screen_name' => FALSE,
			'large_follow_button' => FALSE,
			'show_meta_timestamp' => TRUE,
			'show_meta_screen_name' => TRUE,
			'show_meta_via' => TRUE,
			'show_meta_reply_retweet_favorite' => FALSE,
			'show_meta_prev_next' => FALSE,
			'show_meta_tweet_counter' => FALSE,
			'show_meta_pager' => FALSE,
			'show_meta_pager_blob' => '<a href="#">&bull;</a>',
			'rotation_type' => 'scrollUp',
			'official_format' => FALSE,
			'links_in_new_window' => FALSE,
			'url_length' => 29,
			'search' => FALSE,
			'list' => FALSE,
			'get_favorites' => FALSE,
			'ratelimit' => FALSE,
			'next' => __('next','rotatingtweets'),
			'prev' => __('prev','rotatingtweets'),
			'middot' => ' &middot; ',
			'np_pos' => 'top',
			'speed' => 1000,
			'offset' => 0,
			'link_all_text' => FALSE,
			'no_rotate' => FALSE,
			'show_media' => FALSE,
			'screen_name_plural' => 0,
			'tweet_length' => 0,
			'carousel_horizontal' => 0,
			'carousel_count' => 0,
			'carousel_responsive' => 0,
			'no_emoji' => 0,
			'show_tco_link' => 0,
			'w3tc_render_to' => '',
			'official_format_override'=>FALSE,
			'no_cache'=>FALSE,
			'text_cache_id'=>FALSE,
			'profile_image_size'=>'normal',
			'shuffle'=>0,
			'merge_cache'=>TRUE,
			'rtw_display_order'=>'info,main,media,meta',
			'collection' => FALSE,
			'auto_height' => 'calc',
			'msg_no_tweets' => __('No Tweets found','rotatingtweets'),
			'show_line_breaks'=>FALSE
		), $atts, 'rotatingtweets' ) ;
	extract($args);
	$screen_name = rotatingtweets_clean_screen_name($screen_name);
	if(empty($screen_name) && !empty($user_meta_screen_name)):
		$screen_name = get_user_meta( get_current_user_id() , $user_meta_screen_name, true ); 
		$args['screen_name'] = $screen_name;
		// if(WP_DEBUG) {
			echo "<!-- Variable ".esc_html($user_meta_screen_name)." => ".esc_html($screen_name)." -->";
		// }
		// get_post_meta( get_the_ID(), 'thumb', true );
	endif;
	if(empty($screen_name) && empty($search) && !empty($url) && empty($collection)):
		$screen_name = rotatingtweets_link_to_screenname($url);
		$args['screen_name'] = $screen_name;
		if(WP_DEBUG) {
			echo "<!-- ".esc_url($url)." => ".esc_html($screen_name)." -->";
		}
	endif;
	if($only_rts) $include_rts=true;
	$args['w3tc_render_to']=str_replace('widget','shortcode',$args['w3tc_render_to']);
	/* Test to clear broken transients */
	if($args['no_cache']) {
		$clearargs = $args;
		$clearargs['no_cache'] = FALSE;
		if(WP_DEBUG) echo "<!-- Clearing cache: stored cache = ".esc_html($args['text_cache_id'])." ; calculated cache = rt-sc-".esc_attr(md5(serialize($clearargs)))." -->";
		if($args['text_cache_id']):
			$clear_cache_id = $args['text_cache_id'];
		else:
			$clear_cache_id = "rt-sc-".md5(serialize($clearargs));
		endif;
		if(WP_DEBUG) echo "<!-- Clearing cache ".esc_attr($clear_cache_id)." -->";
		delete_transient($clear_cache_id);
	}
	if(!$args['text_cache_id']) $args['text_cache_id'] = "rt-sc-".md5(serialize($args));
	$args['displaytype']='shortcode';
	if(empty($screen_name)) $screen_name = 'twitter';
	# Makes sure the scripts are listed
	rotatingtweets_enqueue_scripts(); 
	# Kill old transients
	$rotatingtweets_otk = rotatingtweets_get_transient($args['text_cache_id']&'-otk');
	if($rotatingtweets_otk):
		$returnstring = rotatingtweets_get_transient($args['text_cache_id']);
	else:
		// Automatically delete text cache every 10 minutes
		rotatingtweets_set_transient($args['text_cache_id']&'-otk',1,10*60);
		delete_transient($args['text_cache_id']);
	endif;
	if(empty($returnstring)):
		$tweets = rotatingtweets_get_tweets($screen_name,$include_rts,$exclude_replies,$get_favorites,$search,$list,$args['merge_cache'],$collection);
		$returnstring = rotating_tweets_display($tweets,$args,$print);
	elseif(WP_DEBUG):
		$returnstring .= "<!-- Transient ".$args['text_cache_id']." loaded -->";
	endif;
	return $returnstring;
}
add_shortcode( 'rotatingtweets', 'rotatingtweets_display_shortcode' );

/*

Management page for the Twitter API options

*/
function rotatingtweets_settings_check() {
	$api = get_option('rotatingtweets-api-settings');
	$error = get_option('rotatingtweets_api_error');
	if(!empty($api)):
		$apistring = implode('',$api);
	endif;
	$optionslink = 'options-general.php?page=rotatingtweets';
	if(empty($apistring)):
	/* translators: %1$s is replaced with a link to the Rotating Tweets options page. %2$s is replaced with a link to the original Twitter article deprecating v1 of the API. */
		$msgString = __('Please update <a href="%2$s">your settings for Rotating Tweets</a>. The Twitter API <a href="%1$s">changed on June 11, 2013</a> and new settings are needed for Rotating Tweets to continue working.','rotatingtweets');
		// add_settings_error( 'rotatingtweets_settings_needed', esc_attr('rotatingtweets_settings_needed'), sprintf($msgString,'https://dev.twitter.com/calendar',$optionslink), 'error');
		echo "<div class='error'><p><strong>".wp_kses_post(sprintf($msgString,'https://web.archive.org/web/20131019200854/https://dev.twitter.com/blog/api-v1-is-retired',$optionslink))."</strong></p></div>";
	elseif( is_array($error) && ($error[0]['code'] == 32 )):
		// add_settings_error( 'rotatingtweets_settings_needed', esc_attr('rotatingtweets_settings_needed'), sprintf(__('Please update <a href="%1$s">your settings for Rotating Tweets</a>. Currently Twitter cannot authenticate you with the details you have given.','rotatingtweets'),$optionslink), 'error');
		/* translators: %1$s is replaced with a link to the Rotating Tweets options page. */
		echo "<div class='error'><p><strong>".wp_kses_post(sprintf(__('Please update <a href="%1$s">your settings for Rotating Tweets</a>. Currently Rotating Tweets cannot authenticate you with Twitter using the details you have given.','rotatingtweets'),$optionslink))."</strong></p></div>";
	endif;
};
add_action( 'admin_notices', 'rotatingtweets_settings_check' );

add_action( 'admin_menu', 'rotatingtweets_menu' );

function rotatingtweets_menu() {
	add_options_page( __('Rotating Tweets: Twitter API settings','rotatingtweets'), 'Rotating Tweets', 'manage_options', 'rotatingtweets', 'rotatingtweets_call_twitter_API_options' );
}

function rotatingtweets_call_twitter_API_options() {
	echo '<div class="wrap">';
	echo '<h2>'.esc_html__('Rotating Tweets: Twitter API settings','rotatingtweets').'</h2>';	
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.','rotatingtweets' ) );
	}
    /* translators: %s is replaced with the URL linking to information about Twitter/X's API change */
	echo wp_kses_post(sprintf(__('<p>Twitter <a href="%s">has changed</a> the way that they allow people to use the information in their tweets.</p><p>You need to take the following steps to make sure that Rotating Tweets can access the information it needs from Twitter:</p>','rotatingtweets'),'https://dev.twitter.com/blog/changes-coming-to-twitter-api'));
	/* translators: %s is replaced with the URL linking to the 'My Application Page' on Twitter/X */
	echo wp_kses_post(sprintf(__('<h3>Step 1:</h3><p>Go to the <a href="%s">My applications page</a> on the Twitter website to set up your website as a new Twitter \'application\'. You may need to log-in using your Twitter user name and password.</p>','rotatingtweets'),'https://dev.twitter.com/apps'));
	/* translators: %s is replaced with the URL linking to the page that lets you create a new application page on Twitter/X */
	echo wp_kses_post(sprintf(__('<h3>Step 2:</h3><p>If you don\'t already have a suitable \'application\' that you can use for your website, set one up on the <a href="%s">Create an Application page</a>.</p> <p>It\'s normally best to use the name, description and website URL of the website where you plan to use Rotating Tweets.</p><p>You don\'t need a Callback URL.</p>','rotatingtweets'),'https://dev.twitter.com/apps/new'));
	echo wp_kses_post(__('<h3>Step 3:</h3><p>After clicking <strong>Create your Twitter application</strong>, on the following page, click on <strong>Create my access token</strong>.</p>','rotatingtweets'));
	echo wp_kses_post(__('<h3>Step 4:</h3><p>Copy the <strong>Consumer key</strong>, <strong>Consumer secret</strong>, <strong>Access token</strong> and <strong>Access token secret</strong> from your Twitter application page into the settings below.</p>','rotatingtweets'));
	echo wp_kses_post(__('<h3>Step 5:</h3><p>Click on <strong>Save Changes</strong>.','rotatingtweets'));
	echo wp_kses_post(__('<h3>If there are any problems:</h3><p>If there are any problems, you should get an error message from Twitter displayed as a "rotating tweet" which should help diagnose the problem.</p>','rotatingtweets'));
	echo "<ol>\n\t<li>";
	esc_html_e('If you are getting problems with "rate limiting", try changing the first connection setting below to increase the time that Rotating Tweets waits before trying to get new data from Twitter.','rotatingtweets');
	echo "</li>\n\t<li>";
	esc_html_e('If you are getting time-out problems, try changing the second connection setting below to increase how long Rotating Tweets waits when connecting to Twitter before timing out.','rotatingtweets');
	echo "</li>\n\t<li>";
	esc_html_e('If the error message references SSL, try changing the "Verify SSL connection to Twitter" setting below to "No".','rotatingtweets');
	echo "\n</ol>\n<h3>";
	esc_html_e('Getting information from more than one Twitter account','rotatingtweets');
	echo "</h3>\n<p>";
	esc_html_e('Even though you are only entering one set of Twitter API data, Rotating Tweets will continue to support multiple widgets and shortcodes pulling from a variety of different Twitter accounts.','rotatingtweets');
	echo '</p><form method="post" action="options.php">';
	settings_fields( 'rotatingtweets_options' );
	do_settings_sections('rotatingtweets_api_settings');
	submit_button(esc_html__('Save Changes','rotatingtweets'));
	echo '</form></div>';
}
add_action('admin_init', 'rotatingtweets_admin_init');

function rotatingtweets_admin_init(){
// API settings
	register_setting( 'rotatingtweets_options', 'rotatingtweets-api-settings', 'rotatingtweets_api_validate' );
	add_settings_section('rotatingtweets_api_main', __('Twitter API Settings','rotatingtweets'), 'rotatingtweets_api_explanation', 'rotatingtweets_api_settings');
	add_settings_field('rotatingtweets_key', __('Twitter API Consumer Key','rotatingtweets'), 'rotatingtweets_option_show_key', 'rotatingtweets_api_settings', 'rotatingtweets_api_main');
	add_settings_field('rotatingtweets_secret', __('Twitter API Consumer Secret','rotatingtweets'), 'rotatingtweets_option_show_secret', 'rotatingtweets_api_settings', 'rotatingtweets_api_main');
	add_settings_field('rotatingtweets_token', __('Twitter API Access Token','rotatingtweets'), 'rotatingtweets_option_show_token', 'rotatingtweets_api_settings', 'rotatingtweets_api_main');
	add_settings_field('rotatingtweets_token_secret', __('Twitter API Access Token Secret','rotatingtweets'), 'rotatingtweets_option_show_token_secret', 'rotatingtweets_api_settings', 'rotatingtweets_api_main');
// Connection settings	
	add_settings_section('rotatingtweets_connection_main', __('Connection Settings','rotatingtweets'), 'rotatingtweets_connection_explanation', 'rotatingtweets_api_settings');
	add_settings_field('rotatingtweets_cache_delay', __('How often should Rotating Tweets try to get the latest tweets from Twitter?','rotatingtweets'), 'rotatingtweets_option_show_cache_delay','rotatingtweets_api_settings','rotatingtweets_connection_main');
	add_settings_field('rotatingtweets_timeout', __("When connecting to Twitter, how long should Rotating Tweets wait before timing out?",'rotatingtweets'), 'rotatingtweets_option_show_timeout','rotatingtweets_api_settings','rotatingtweets_connection_main');
	add_settings_field('rotatingtweets_ssl_verify', __('Verify SSL connection to Twitter','rotatingtweets'), 'rotatingtweets_option_show_ssl_verify','rotatingtweets_api_settings','rotatingtweets_connection_main');
//	JQuery settings
	add_settings_section('rotatingtweets_jquery_main', __('JavaScript Settings','rotatingtweets'), 'rotatingtweets_jquery_explanation', 'rotatingtweets_api_settings');
	add_settings_field('rotatingtweets_jquery_cycle_version', __('Version of JQuery Cycle','rotatingtweets'), 'rotatingtweets_option_show_cycle_version','rotatingtweets_api_settings','rotatingtweets_jquery_main');
	add_settings_field('rotatingtweets_js_in_footer', __('Where to load Rotating Tweets JavaScript','rotatingtweets'), 'rotatingtweets_option_show_in_footer','rotatingtweets_api_settings','rotatingtweets_jquery_main');
//	CSS settings
	add_settings_section('rotatingtweets_css_main', __('CSS Settings','rotatingtweets'), 'rotatingtweets_css_explanation', 'rotatingtweets_api_settings');
	add_settings_field('rotatingtweets_show_css', __('Use Rotating Tweets CSS Stylesheet','rotatingtweets'), 'rotatingtweets_option_show_css','rotatingtweets_api_settings','rotatingtweets_css_main');
}
function rotatingtweets_option_show_key() {
	$options = get_option('rotatingtweets-api-settings');
	echo "<input id='rotatingtweets_api_key_input' name='rotatingtweets-api-settings[key]' size='70' type='text' value='".esc_attr($options['key'])."' />";
}
function rotatingtweets_option_show_secret() {
	$options = get_option('rotatingtweets-api-settings');
	echo "<input id='rotatingtweets_api_secret_input' name='rotatingtweets-api-settings[secret]' size='70' type='text' value='".esc_attr($options['secret'])."' />";
}
function rotatingtweets_option_show_token() {
	$options = get_option('rotatingtweets-api-settings');
	echo "<input id='rotatingtweets_api_token_input' name='rotatingtweets-api-settings[token]' size='70' type='text' value='".esc_attr($options['token'])."' />";
}
function rotatingtweets_option_show_token_secret() {
	$options = get_option('rotatingtweets-api-settings');
	echo "<input id='rotatingtweets_api_token_secret_input' name='rotatingtweets-api-settings[token_secret]' size='70' type='text' value='".esc_attr($options['token_secret'])."' />";
}
function rotatingtweets_option_show_ssl_verify() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		1 => _x('Yes','Verify SSL connection to Twitter','rotatingtweets'),
		0 => _x('No','Verify SSL connection to Twitter','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_api_ssl_verify_input' name='rotatingtweets-api-settings[ssl_verify]'>";
	foreach($choice as $value => $text) {
		if($options['ssl_verify_off'] != $value ) {
			$selected = 'selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}
function rotatingtweets_option_show_timeout() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		1 => _x('1 second','Connection timeout','rotatingtweets'),
		3 => _x('3 seconds (default)','Connection timeout','rotatingtweets'),
		5 => _x('5 seconds','Connection timeout','rotatingtweets'),
		7 => _x('7 seconds','Connection timeout','rotatingtweets'),
		20 => _x('20 seconds','Connection timeout','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_api_timeout_input' name='rotatingtweets-api-settings[timeout]'>";
	if(!isset($options['timeout']))	$options['timeout'] = 3;
	foreach($choice as $value => $text) {
		if($options['timeout'] == $value ) {
			$selected = ' selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}
function rotatingtweets_option_show_cache_delay() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		60 => _x('1 minute','Cache Delay','rotatingtweets'),
		120 => _x('2 minutes (default)','Cache Delay','rotatingtweets'),
		300 => _x('5 minutes','Cache Delay','rotatingtweets'),
		3600 => _x('1 hour','Cache Delay','rotatingtweets'),
		86400 => _x('24 hours','Cache Delay','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_cache_delay_input' name='rotatingtweets-api-settings[cache_delay]'>";
	if(!isset($options['cache_delay'])) $options['cache_delay'] = 120;
	foreach($choice as $value => $text) {
		if($options['cache_delay'] == $value ) {
			$selected = ' selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}
function rotatingtweets_option_show_cycle_version() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		1 => _x('Version 1 (default)','Version of JQuery Cycle','rotatingtweets'),
		2 => _x('Version 2 (beta)','Version of JQuery Cycle','rotatingtweets'),
		3 => _x('None (advanced users who wish to use their own JavaScript only)','Version of JQuery Cycle','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_api_jquery_cycle_version_input' name='rotatingtweets-api-settings[jquery_cycle_version]'>";
	if(!isset($options['jquery_cycle_version']))	$options['jquery_cycle_version'] = 1;
	foreach($choice as $value => $text) {
		if($options['jquery_cycle_version'] == $value ) {
			$selected = ' selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}
function rotatingtweets_option_show_in_footer() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		0 => _x('Load in header (default)','Location of JavaScript','rotatingtweets'),
		1 => _x('Load in footer','Location of JavaScript','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_api_js_in_footer_input' name='rotatingtweets-api-settings[js_in_footer]'>";
	if(!isset($options['js_in_footer'])) $options['js_in_footer'] = FALSE;
	foreach($choice as $value => $text) {
		if($options['js_in_footer'] == $value ) {
			$selected = ' selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}

function rotatingtweets_option_show_css() {
	$options = get_option('rotatingtweets-api-settings');
	$choice = array(
		0 => _x('Show CSS stylesheet (default)','Whether to show CSS stylesheet','rotatingtweets'),
		1 => _x('Hide CSS stylesheet','Whether to show CSS stylesheet','rotatingtweets')
	);
	echo "\n<select id='rotatingtweets_api_show_css_input' name='rotatingtweets-api-settings[hide_css]'>";
	if(!isset($options['hide_css'])) $options['hide_css'] = FALSE;
	foreach($choice as $value => $text) {
		if($options['hide_css'] == $value ) {
			$selected = ' selected = "selected"';
		} else {
			$selected = '';
		}
		echo wp_kses("\n\t<option value='".$value."'".$selected.">".$text."</option>",array('option' => array('value'=> array(),'selected'=>array())));
	}
	echo "\n</select>";
}

// Explanatory text
function rotatingtweets_api_explanation() {
	
};
// Explanatory text
function rotatingtweets_connection_explanation() {
	
};
// Explanatory text
function rotatingtweets_jquery_explanation() {
};
// Explanatory text
function rotatingtweets_css_explanation() {
};
function rotatingtweets_clean_screen_name($input) {
	if ( preg_match('/[0-9a-zA-Z_]+/', $input, $output) ):
		return($output[0]);
	else:
		return FALSE;
	endif;
}

// validate our options
function rotatingtweets_api_validate($input) {
	$options = get_option('rotatingtweets-api-settings');
	$error = 0;
	// Check 'key'
	$options['key'] = trim($input['key']);
	if(!preg_match('/^[a-z0-9]+$/i', $options['key'])) {
		$options['key'] = '';
		$error = 1;
		add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-key'), __('Error: Twitter API Consumer Key not correctly formatted.','rotatingtweets'));
	}
	// Check 'secret'
	$options['secret'] = trim($input['secret']);
	if(!preg_match('/^[a-z0-9]+$/i', $options['secret'])) {
		$options['secret'] = '';
		$error = 1;
		add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-secret'), __('Error: Twitter API Consumer Secret not correctly formatted.','rotatingtweets'));
	}
	// Check 'token'
	$options['token'] = trim($input['token']);
	if(!preg_match('/^[a-z0-9]+\-[a-z0-9]+$/i', $options['token'])) {
		$options['token'] = '';
		$error = 1;
		add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-token'), __('Error: Twitter API Access Token not correctly formatted.','rotatingtweets'));
	}
	// Check 'token_secret'
	$options['token_secret'] = trim($input['token_secret']);
	if(!preg_match('/^[a-z0-9]+$/i', $options['token_secret'])) {
		$options['token_secret'] = '';
		$error = 1;
		add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-token-secret'), __('Error: Twitter API Access Token Secret not correctly formatted.','rotatingtweets'));
	}
	// Check 'ssl_verify'
	if(isset($input['ssl_verify']) && $input['ssl_verify']==0):
		$options['ssl_verify_off']=true;
	else:
		$options['ssl_verify_off']=false;
	endif;	
	// Check 'timeout'
	if(isset($input['timeout'])):
		$options['timeout'] = max(1,intval($input['timeout']));
	endif;
	// Check 'cache delay'
	if(isset($input['cache_delay'])):
		$options['cache_delay'] = max(60,intval($input['cache_delay']));
	else:
		$options['cache_delay']=120;
	endif;
	// Check 'jquery_cycle_version'
	if(isset($input['jquery_cycle_version'])):
		$options['jquery_cycle_version']=max(min(absint($input['jquery_cycle_version']),3),1);
	else:
		$options['jquery_cycle_version']=1;
	endif;
	// Check 'in footer'
	if(isset($input['js_in_footer'])):
		$options['js_in_footer'] = (bool) $input['js_in_footer'];
	else:
		$options['js_in_footer'] = FALSE;
	endif;
	// Check CSS
	if(isset($input['hide_css'])):
		$options['hide_css'] = (bool) $input['hide_css'];
	else:
		$options['hide_css'] = FALSE;
	endif;
	
	// Now a proper test
	if(empty($error)):
		$transientname = 'rotatingtweets_check_wp_remote_request'; // This whole code is to help someone who has a problem with wp_remote_request
		if(!rotatingtweets_get_transient($transientname)):
			rotatingtweets_set_transient($transientname,true,24*60*60);
			$test = rotatingtweets_call_twitter_API('statuses/user_timeline',NULL,$options);
			delete_transient($transientname);
			$error = get_option('rotatingtweets_api_error');
			if(!empty($error)):
				if($error[0]['type'] == 'Twitter'):
					/* translators: %1$s is replaced with the error message received from Twitter/X. %2$s is replaced with the URL linking to the 'My Application Page' on Twitter/X */
					add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-'.$error[0]['code']), sprintf(__('Error message received from Twitter: %1$s. <a href="%2$s">Please check your API key, secret, token and secret token on the Twitter website</a>.','rotatingtweets'),$error[0]['message'],'https://dev.twitter.com/apps'), 'error' );
				else:
					/* translators: %1$s is replaced with the error message received from Wordpress */
					add_settings_error( 'rotatingtweets', esc_attr('rotatingtweets-api-'.$error[0]['code']), sprintf(__('Error message received from Wordpress: %1$s. Please check your connection settings.','rotatingtweets'),$error[0]['message']), 'error' );
				endif;
			endif;
		endif;
	endif;
	return $options;
}
/*
And now the Twitter API itself!
*/

function rotatingtweets_call_twitter_API($command,$options = NULL,$api = NULL ) {
	if(empty($api)) $api = get_option('rotatingtweets-api-settings');
	if(!empty($api)):
		$connection = new rotatingtweets_TwitterOAuth($api['key'], $api['secret'], $api['token'], $api['token_secret'] );
		//    $result = $connection->get('statuses/user_timeline', $options);
		if(WP_DEBUG && ! is_admin()):
			echo "\n<!-- Using OAuth - version 1.1 of API - ".esc_attr($command)." -->\n";
		endif;
		if(isset($api['ssl_verify_off']) && $api['ssl_verify_off']):
			if(WP_DEBUG  && ! is_admin() ):
				echo "\n<!-- NOT verifying SSL peer -->\n";
			endif;
			$connection->ssl_verifypeer = FALSE;
		else:
			if(WP_DEBUG && ! is_admin() ):
				echo "\n<!-- Verifying SSL peer -->\n";
			endif;
			$connection->ssl_verifypeer = TRUE;
		endif;
		if(isset($api['timeout'])):
			$timeout = max(1,intval($api['timeout']));
			if(WP_DEBUG && ! is_admin() ):
				echo "\n<!-- Setting timeout to ".number_format($timeout)." seconds -->\n";
			endif;
			$connection->timeout = $timeout;
		endif;
		$result = $connection->get($command , $options);
	else:
		// Construct old style API command
		unset($string);
		if($command == 'application/rate_limit_status'):
			$command = 'account/rate_limit_status';
			unset($options);
		endif;
		if(is_array($options)):
			foreach($options as $name => $val) {
				$string[] = $name . "=" . urlencode($val);
			}
		endif;
		if($command != 'search/tweets'):
			$apicall = "https://api.twitter.com/1/".$command.".json";
		else:
			$apicall = "https://search.twitter.com/search.json";
		endif;
		if(!empty($string)) $apicall .= "?".implode('&',$string);
		if(WP_DEBUG  && ! is_admin() ) echo "<!-- Using version 1 of API - calling string ".esc_attr($apicall)." -->";
		$result = wp_remote_request($apicall);
	endif;
	if(!is_wp_error($result)):
		if(isset($result['body'])):
			$data = json_decode($result['body'],true);
			if(isset($data['errors'])):
				$data['errors'][0]['type'] = 'Twitter';
				if( empty($api) ) $errorstring[0]['message'] = 'Please enter valid Twitter API Settings on the Rotating Tweets settings page';
				if(WP_DEBUG  && ! is_admin() ):
					echo "<!-- Error message from Twitter - \n";
					print_r($data['errors']);
					echo "\n-->";
				endif;
				update_option('rotatingtweets_api_error',$data['errors']);
			else:
				if(WP_DEBUG  && ! is_admin() ) echo "<!-- Successfully read data from Twitter -->";
				delete_option('rotatingtweets_api_error');
			endif;
		else:
			if(WP_DEBUG  && ! is_admin() ):
				echo "<!-- Failed to read valid data from Twitter: problem with wp_remote_request(). Data read was: ";
				print_r($result);
				echo "\n-->";
			endif;
			$errorstring[0]['code']= 999;
			$errorstring[0]['message']= 'Failed to read valid data from Twitter: problem with wp_remote_request()';
			$errorstring[0]['type'] = 'Wordpress';
			update_option('rotatingtweets_api_error',$errorstring);
		endif;
	else:
		$errorstring = array();
		$errorstring[0]['code']= $result->get_error_code();
		$errorstring[0]['message']= $result->get_error_message();
		$errorstring[0]['type'] = 'Wordpress';
		if(WP_DEBUG  && ! is_admin() ) echo "<!-- Error message from Wordpress - ".esc_html($errorstring[0]['message'])." -->";
		update_option('rotatingtweets_api_error',$errorstring);
	endif;
	return($result);
}
function rotatingtweets_get_cache_delay() {
	$cacheoption = get_option('rotatingtweets-api-settings');
	if(!isset($cacheoption['cache_delay'])):
		$cache_delay = 120;
	else:
		$cache_delay = max(60,intval($cacheoption['cache_delay']));
	endif;
	return($cache_delay);
}
# Get the latest data from Twitter (or from a cache if it's been less than 2 minutes since the last load)
function rotatingtweets_get_tweets($tw_screen_name,$tw_include_rts,$tw_exclude_replies,$tw_get_favorites = FALSE,$tw_search = FALSE,$tw_list = FALSE, $tw_merge = TRUE, $tw_collection = FALSE ) {
	$rt_namesarray = array();
	if(empty($tw_search)):
		$possibledividers = array(' ',';',',');
		foreach($possibledividers as $possibledivider):
			if(strpos($tw_screen_name,$possibledivider) !== false ):
				$rt_namesarray = explode($possibledivider,$tw_screen_name);
			endif;
		endforeach;	
	endif;
	if(empty($rt_namesarray)):
		return rotatingtweets_get_tweets_sf($tw_screen_name,$tw_include_rts,$tw_exclude_replies,$tw_get_favorites,$tw_search,$tw_list, $tw_merge, $tw_collection );
	else:
		$rt_return = array();
		foreach($rt_namesarray as $rt_name):
			$rt_return = rotatingtweet_combine_jsons($rt_return,rotatingtweets_get_tweets_sf($rt_name,$tw_include_rts,$tw_exclude_replies,$tw_get_favorites,$tw_search,$tw_list, $tw_merge, $tw_collection ));
			if(WP_DEBUG) echo "<!-- Adding data from ".esc_html($rt_name)." -->";
		endforeach;
		return($rt_return);
	endif;
}
# Old rotatingtweets_get_tweets function - now used as subfunction
function rotatingtweets_get_tweets_sf($tw_screen_name,$tw_include_rts,$tw_exclude_replies,$tw_get_favorites = FALSE,$tw_search = FALSE,$tw_list = FALSE, $tw_merge = TRUE, $tw_collection = FALSE ) {
	# Set timer
	$rt_starttime = microtime(true);
	# Clear up variables
	$tw_screen_name = trim(remove_accents(str_replace('@','',$tw_screen_name)));
	if($tw_list):
		$tw_list = strtolower(sanitize_file_name( $tw_list ));
	endif;
	if(!empty($tw_search)):
		$tw_search = trim($tw_search);
	endif;
	if($tw_collection):
		$tw_collection = trim($tw_collection);
	endif;
	$cache_delay = rotatingtweets_get_cache_delay();
	if($tw_include_rts != 1) $tw_include_rts = 0;
	if($tw_exclude_replies != 1) $tw_exclude_replies = 0;
	
	# Get the option strong
	if($tw_search) {
		$stringname = 'search-'.$tw_include_rts.$tw_exclude_replies.'-'.$tw_search;
	} elseif($tw_collection) {
		$stringname = 'collection-'.$tw_collection;
	} elseif ($tw_get_favorites) {
		$stringname = $tw_screen_name.$tw_include_rts.$tw_exclude_replies.'favorites';
	} elseif ($tw_list) {
		$stringname = $tw_screen_name.$tw_include_rts.$tw_exclude_replies.'list-'.$tw_list;
	} else {
		$stringname = $tw_screen_name.$tw_include_rts.$tw_exclude_replies;
	}
	$transientname = substr('rtc-'.sanitize_file_name($stringname),0,45);
	$option = rotatingtweets_get_transient($transientname);

	if(WP_DEBUG && !$option):
		echo "<!-- Option failed to load -->";
		echo "<!-- Option \n";print_r($option);echo " -->";
	endif;

	# Attempt to deal with 'Cannot use string offset as an array' error
	$timegap = $cache_delay + 1;
	if(is_array($option)):
		if(isset($option[$stringname]['json'][0])):
			if(WP_DEBUG) echo "<!-- option[".esc_attr($stringname)."] exists -->";
			if(is_array($option[$stringname]['json'][0])):
				$latest_json = $option[$stringname]['json'];
				$latest_json_date = $option[$stringname]['datetime'];
				$timegap = time()-$latest_json_date;
				if(WP_DEBUG):
					echo "<!-- option[".esc_attr($stringname)."]['json'][0] is an array - ".number_format($timegap)." seconds since last load -->";
				endif;
			elseif(is_object($option[$stringname]['json'][0])):
				if(WP_DEBUG) echo "<!-- option[".esc_attr($stringname)."]['json'][0] is an object -->";
				unset($option[$stringname]);
			else:
				if(WP_DEBUG) echo "<!-- option[".esc_attr($stringname)."]['json'][0] is neither an object nor an array! -->";
				unset($option[$stringname]);
			endif;
		elseif(WP_DEBUG):
			echo "<!-- option[".esc_attr($stringname)."] does not exist -->";
		endif;
	else:
		if(WP_DEBUG):
			echo "\n<!-- var option is NOT an array\n";
			print_r($option);
			echo "\n-->";
		endif;
		unset($option);
	endif;
	# Checks if it is time to call Twitter directly yet or if it should use the cache
	if($timegap > $cache_delay):
		$apioptions = array('screen_name'=>$tw_screen_name,'include_entities'=>1,'count'=>60,'include_rts'=>$tw_include_rts,'exclude_replies'=>$tw_exclude_replies);
		// New option to support extended Tweets		
		$apioptions['tweet_mode']='extended';
		$twitterusers = FALSE;
		if($tw_search) {
			$apioptions['q']=$tw_search;
//			$apioptions['result_type']='recent';
			$twitterdata = rotatingtweets_call_twitter_API('search/tweets',$apioptions);
		} elseif($tw_collection) {
			$twitterdata = rotatingtweets_call_twitter_API('collections/entries',$apioptions);
			$twitterusers = rotatingtweets_call_twitter_API('collections/show',$apioptions);
		} elseif($tw_get_favorites) {
			$twitterdata = rotatingtweets_call_twitter_API('favorites/list',$apioptions);
		} elseif($tw_list) {
			unset($apioptions['screen_name']);
			$apioptions['slug']=$tw_list;
			$apioptions['owner_screen_name']=$tw_screen_name;
			$twitterdata = rotatingtweets_call_twitter_API('lists/statuses',$apioptions);
		} else {
			$twitterdata = rotatingtweets_call_twitter_API('statuses/user_timeline',$apioptions);
		}
		if(!is_wp_error($twitterdata)):
			if($twitterusers) {
				$twitterjson = rotatingtweets_transform_collection_data($twitterdata,$twitterusers);
			} else {
				$twitterjson = json_decode($twitterdata['body'],TRUE);
			}
			if(WP_DEBUG):
				$rt_time_taken = number_format(microtime(true)-$rt_starttime,4);
				echo "<!-- Rotating Tweets - got new data - time taken: ".number_format($rt_time_taken)." seconds -->";
//				echo "\n<!-- Data received from Twitter: \n";print_r($twitterjson);echo "\n-->\n";
			endif;
		else:
			rotatingtweets_set_transient('rotatingtweets_wp_error',$twitterdata->get_error_messages(), 120);
		endif;
	elseif(WP_DEBUG):
		$rt_time_taken = number_format(microtime(true)-$rt_starttime,4);
		echo "<!-- Rotating Tweets - used cache - ".number_format($cache_delay - $timegap)." seconds remaining  - time taken: ".number_format($rt_time_taken)." seconds -->";
	endif;
	# Checks for errors in the reply
	if(!empty($twitterjson['errors'])):
		# If there's an error, reset the cache timer to make sure we don't hit Twitter too hard and get rate limited.
		if(WP_DEBUG):
			echo "<!-- \n";
			print_r($twitterjson);
			echo "\n-->";
		endif;
		if( $twitterjson['errors'][0]['code'] == 88 ):
			$rate = rotatingtweets_get_rate_data();
			if($rate && $rate['remaining_hits'] == 0):
				$option[$stringname]['datetime']= $rate['reset_time_in_seconds'] - $cache_delay + 1;
				rotatingtweets_set_transient($transientname,$option,60*60*24*7);
			else:
				$option[$stringname]['datetime']=time();
				rotatingtweets_set_transient($transientname,$option,60*60*24*7);
			endif;
		else:
			$option[$stringname]['datetime']=time();
			rotatingtweets_set_transient($transientname,$option,60*60*24*7);
		endif;
	elseif(!empty($twitterjson['error'])):
		# If Twitter is being rate limited, delays the next load until the reset time
		# For some reason the rate limiting error has a different error variable!
		$rate = rotatingtweets_get_rate_data();
		if($rate && $rate['remaining_hits'] == 0):
			$option[$stringname]['datetime']= $rate['reset_time_in_seconds'] - $cache_delay + 1;
			rotatingtweets_set_transient($transientname,$option,60*60*24*7);
		endif;
	elseif(!empty($twitterjson)):
		unset($firstentry);
		if(isset($twitterjson['statuses'])):
			if(WP_DEBUG):
				echo "<!-- using [statuses] -->";
			endif;
			$twitterjson = $twitterjson['statuses'];
		elseif(isset($twitterjson['results'])):
			if(WP_DEBUG):
				echo "<!-- using [results] -->";
			endif;
			$twitterjson = $twitterjson['results'];
		else:
			if(WP_DEBUG):
				echo "<!-- neither statuses nor results - pull in full data -->";
			endif;
		endif;
		if(isset($twitterjson) && is_array($twitterjson) && isset($twitterjson[0] )) $firstentry = $twitterjson[0];
		if(!empty($firstentry['text']) || !empty($firstentry['full_text']) ):
			$number_returned_tweets = count($twitterjson);
			if(WP_DEBUG):
				echo "<!-- ".number_format($number_returned_tweets)." tweets returned -->\n<!-- \n";
				// print_r($twitterjson);
				echo "\n-->";
			endif;
			if( $tw_search && $tw_merge && $number_returned_tweets < 60 && isset($latest_json) && is_array($latest_json) && count($latest_json)>0 ):
				if(WP_DEBUG) echo "<!-- ".count($latest_json)." tweets in cache -->";
				$twitterjson = rotatingtweet_combine_jsons($twitterjson,$latest_json);
				if(WP_DEBUG) echo "<!-- ".count($twitterjson)." tweets in merged json -->";
			endif;
			$latest_json = rotatingtweets_shrink_json($twitterjson);
			$option[$stringname]['json']=$latest_json;
			$option[$stringname]['datetime']=time();
			$rtcacheresult = rotatingtweets_set_transient($transientname,$option,60*60*24*7);
			if($rtcacheresult && WP_DEBUG):
				echo "<!-- Successfully stored cache entry for ".esc_html($stringname)." in ".esc_html($transientname)." -->";
			elseif(WP_DEBUG):
				echo "<!-- Failed to store cache entry for ".esc_html($stringname)." in ".esc_html($transientname)." -->";
			endif;
		endif;
	endif;
	if(isset($latest_json)):
		return($latest_json);
	else:
		return;
	endif;
}
/* Collections use a different format to the standard API. This is an attempt to convert from one to the other! */
function rotatingtweets_transform_collection_data($twittertweets,$twitterusers) {
	$tweetobject = json_decode($twittertweets['body'],TRUE);
	if(isset($tweetobject['errors'])):
		return ($tweetobject);
	endif;
	if(WP_DEBUG):
		echo "<!--- Collection Tweets object ";
		print_r($tweetobject);
	endif;
	$userobject = json_decode($twitterusers['body'],TRUE);
	if(WP_DEBUG):
		echo "\n\n--- Collection Users object \n\n";
		print_r($userobject);
	endif;
	$pretweets = $tweets['objects']['tweets'];
	$posttweets = array();
	foreach($pretweets as $tweet):
		$tweet['user'] = $userobject['objects']['users'][$tweet['user']['id_str']] ;
		$posttweets[] = $tweet;
	endforeach;
	$return = array( 'results' => $posttweets );
	if(WP_DEBUG):
		echo "\n\n--- Combined object \n\n";
		print_r($return);
		echo "\n--->";
	endif;
	return $return;
}
function rotatingtweet_combine_jsons($a,$b) {
	if(is_array($a) && count($a) && is_array($b) && count($b)):
		$tweet_keys = array();
		foreach($a as $item) {
			$tweet_keys[] = $item['id_str'];
		}
		foreach($b as $item) {
			if( !empty($item['id_str']) && !in_array($item['id_str'],$tweet_keys) ):
				$a[]=$item;
			endif;
		}
		return rotatingtweets_sort_json($a);
	elseif(is_array($a) && count($a)):
		return($a);
	elseif(is_array($b) && count($b)):
		return($b);
	else:
		return null;
	endif;
}
function rotatingtweets_sort_json($a) {
	$sort_json = array();
	$return_json = array();
	foreach($a as $number => $item) {
		if(strtotime($item['created_at'])>0):
			$sort_json[$number] = strtotime($item['created_at']);
		endif;
	}
	arsort($sort_json);
	foreach($sort_json as $number => $item) {
		$return_json[] = $a[$number];
	}
	return $return_json;
}
function rotatingtweets_shrink_json($json) {
	$return = array();
	foreach($json as $item):
		$return[]=rotatingtweets_shrink_element($item);
	endforeach;
	if(WP_DEBUG):
		$startsize = strlen(json_encode($json));
		$endsize = strlen(json_encode($return));
		$shrink = (1-$endsize/$startsize)*100;
		echo  "<!-- Cachesize shrunk by ".number_format($shrink)."% -->";
	endif;
	return($return);
}
function rotatingtweets_shrink_element($json,$no_emoji=0) {
	global $args;
	$rt_top_elements = array('text','full_text','retweeted_status','quoted_status','user','entities','source','id_str','created_at','coordinates','display_text_range','in_reply_to_status_id_str','in_reply_to_user_id','in_reply_to_user_id_str','in_reply_to_screen_name');
	$return = array();
	foreach($rt_top_elements as $rt_element):
		if(isset($json[$rt_element])):
			switch($rt_element) {
			case "user":
				$return[$rt_element]=rotatingtweets_shrink_user($json[$rt_element]);
				break;
			case "entities":
			case "extended_entities":
				$return[$rt_element]=rotatingtweets_shrink_entities($json[$rt_element]);
				break;
			case "retweeted_status":
			case "quoted_status":
				$return[$rt_element]=rotatingtweets_shrink_element($json[$rt_element]);
				break;
			case "text":
			case "full_text":
				$json[$rt_element] = rotatingtweets_convert_charset($json[$rt_element]);			
			default:
				if($no_emoji):
					$before='/\\p{C}/u'; # Removed all 'other' characters - http://php.net/manual/en/regexp.reference.unicode.php
					$after='';
					$json[$rt_element] = str_replace($before,$after,$json[$rt_element]);
				endif;
/*	Experiment to deal with problem caused by emoji crashing a poorly configured database
				if(function_exists("mb_convert_encoding")):
					$return[$rt_element]=mb_convert_encoding($json[$rt_element], "UTF-8");
				else:
*/
				$return[$rt_element]=$json[$rt_element];
//				endif;
				break;
			};
		endif;
	endforeach;
	return($return);
}
function rotatingtweets_shrink_user($user) {
	$rt_user_elements = array('screen_name','id','name','profile_image_url_https','profile_image_url');
	$return = array();
	foreach($rt_user_elements as $rt_element):
		if(isset($user[$rt_element])) $return[$rt_element]=$user[$rt_element];
	endforeach;
	return($return);
}
function rotatingtweets_shrink_entities($json) {
	$rt_entity_elements = array('urls','media','user_mentions');
	$return = array();
	foreach($rt_entity_elements as $rt_element):
		if(isset($json[$rt_element])) $return[$rt_element]=$json[$rt_element];
	endforeach;
	return($return);
}

# Gets the rate limiting data to see how long it will be before we can tweet again
function rotatingtweets_get_rate_data() {
//	$callstring = "http://api.twitter.com/1/account/rate_limit_status.json";
//	$command = 'account/rate_limit_status';
	if(WP_DEBUG) echo "<!-- Retrieving Rate Data \n";
	$ratedata = rotatingtweets_call_twitter_API('application/rate_limit_status',array('resources'=>'statuses'));
//	$ratedata = wp_remote_request($callstring);
	if(!is_wp_error($ratedata)):
		$rate = json_decode($ratedata['body'],TRUE);
		if(isset($rate['resources']['statuses']['/statuses/user_timeline']['limit']) && $rate['resources']['statuses']['/statuses/user_timeline']['limit']>0):
			$newrate['hourly_limit']=$rate['resources']['statuses']['/statuses/user_timeline']['limit'];
			$newrate['remaining_hits']=$rate['resources']['statuses']['/statuses/user_timeline']['remaining'];
			$newrate['reset_time_in_seconds']=$rate['resources']['statuses']['/statuses/user_timeline']['reset'];
			if(WP_DEBUG):
				print_r($newrate);
				echo "\n -->";
			endif;
			return($newrate);
		else:
			if(WP_DEBUG):
				print_r($rate);
				echo "\n -->";
			endif;
			return($rate);
		endif;
	else:
		rotatingtweets_set_transient('rotatingtweets_wp_error',$ratedata->get_error_messages(), 120);
		return(FALSE);
	endif;
}

# Gets the language options
# Once a day finds out what language options Twitter has.  If there's any issue, pushes back the next attempt by another day.
function rotatingtweets_get_twitter_language() {
	$cache_delay = 60*60*24;
	$fallback = array ('id','da','ru','msa','ja','no','ur','nl','fa','hi','de','ko','sv','tr','fr','it','en','fil','pt','he','zh-tw','fi','pl','ar','es','hu','th','zh-cn');
	$optionname = 'rotatingtweets-twitter-languages';
	$option = get_option($optionname);
	# Attempt to deal with 'Cannot use string offset as an array' error
	if(is_array($option)):
		$latest_languages = $option['languages'];
		$latest_date = $option['datetime'];
		$timegap = time()-$latest_date;
	else:
		$latest_languages = $fallback;
		$timegap = $cache_delay + 1;
		$option['languages'] = $fallback;
		$option['datetime'] = time();
	endif;
	if($timegap > $cache_delay):
//		$callstring = "https://api.twitter.com/1/help/languages.json";
//		$twitterdata = wp_remote_request($callstring);
		if(WP_DEBUG) echo "<!-- Retrieving Twitter Language Options -->";
		$twitterdata = rotatingtweets_call_twitter_API('help/languages');
		if(!is_wp_error($twitterdata)):
			$twitterjson = json_decode($twitterdata['body'],TRUE);
			if(!empty($twitterjson['errors'])||!empty($twitterjson['error'])):
				# If there's an error, reset the cache timer to make sure we don't hit Twitter too hard and get rate limited.
				$option['datetime']=time();
				update_option($optionname,$option);
			else:
				# If there's regular data, then update the cache and return the data
				$latest_languages = array();
				if(is_array($twitterjson)):
					foreach($twitterjson as $langarray):
						$latest_languages[] = $langarray['code'];
					endforeach;
				endif;
				if(!empty($latest_languages)):
					$option['languages']=$latest_languages;
					$option['datetime']=time();
					update_option($optionname,$option);
					if(WP_DEBUG) echo "<!-- ".count($option['languages'])." language options successfully retrieved -->";
				endif;
			endif;
		else:
			$option['datetime']=time();
			update_option($optionname,$option);
		endif;
	endif;
	if(empty($latest_languages)) $latest_languages = $fallback;
	return($latest_languages);
}

# This function is used for debugging what happens when the site is rate-limited - best not used otherwise!
function rotatingtweets_trigger_rate_limiting() {
//	$callstring = "http://api.twitter.com/1/statuses/user_timeline.json?screen_name=twitter";
	$apidata = array('screen_name'=>'twitter');
	for ($i=1; $i<150; $i++) {
//		$ratedata = wp_remote_request($callstring);
		$ratedata = rotatingtweets_call_twitter_API('statuses/user_timeline',$apidata);
	}
}
function rotatingtweets_convert_charset($string) {
	if( defined('DB_CHARSET') && strtoupper(DB_CHARSET) !='UTF-8' && strtoupper(DB_CHARSET)!= 'UTF8' && strtoupper(DB_CHARSET)!= 'UTF8MB4'  && strtoupper(DB_CHARSET)!= '' ):
		$new_string = iconv("UTF-8",DB_CHARSET . '//TRANSLIT',$string);
		if(empty($new_string)):
			if(WP_DEBUG):
				echo "<!-- iconv to ".esc_html(DB_CHARSET)." failed -->";
			endif;
			return $string;
		else:
			return $new_string;
		endif;
	endif;
	return $string;
}
# Displays the tweets
function rotating_tweets_display($json,$args,$print=FALSE) {
	unset($result);
	$rt_cache_delay = rotatingtweets_get_cache_delay();
	$tweet_count = max(1,intval($args['tweet_count']));
	$timeout = max(intval($args['timeout']),0);
	if( isset($args['auto_height']) && strtolower($args['auto_height']) != "calc" && $args['auto_height'] != 1 ):
		$auto_height = -1;
	else:
		$auto_height = "calc";
	endif;
	$defaulturllength = 29;
	if(isset($args['url_length'])):
		$urllength = intval($args['url_length']);
		if($urllength < 1):
			$urllength = $defaulturllength;
		endif;
	elseif(isset($args['shorten_links']) && $args['shorten_links']==1 ): 
		$urllength = 20;
	else:
		$urllength = $defaulturllength;
	endif;
	if(isset($args['speed'])):
		$speed = max(100,intval($args['speed']));
	else:
		$speed = 1000;
	endif;
	# Check that the rotation type is valid. If not, leave it as 'scrollUp'
	$rotation_type = 'scrollUp';
	# Get Twitter language string
	$rtlocale = strtolower(get_locale());
	$rtlocaleMain = explode('_',$rtlocale);
	$possibleOptions = rotatingtweets_get_twitter_language();
	if(in_array($rtlocale,$possibleOptions)):
		$twitterlocale = $rtlocale;
	elseif(in_array($rtlocaleMain[0],$possibleOptions)):
		$twitterlocale = $rtlocaleMain[0];
	else:
		# Default
		$twitterlocale = 'en';
	endif;
	# Now get the possible rotationgs that are permitted
	$api = get_option('rotatingtweets-api-settings');
	$possibleRotations = rotatingtweets_possible_rotations();
	foreach($possibleRotations as $possibleRotation):
		if(strtolower($args['rotation_type']) == strtolower($possibleRotation)) $rotation_type = $possibleRotation;
	endforeach;
	# Create an ID that has most of the relevant info in - rotation type and speed of rotation
	$id = uniqid('rotatingtweets_'.$timeout.'_'.$rotation_type.'_'.$speed."_");
//	$id = uniqid('rotatingtweets_');
	$result = '';
	$nextprev = '';
	# Put in the 'next / prev' buttons - although not very styled!
	if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next']):
		$nextprev_prev = '<a href="#" class="'.$id.'_rtw_prev rtw_prev">'.wp_kses_post($args['prev']).'</a>';
		$nextprev_next = '<a href="#" class="'.$id.'_rtw_next rtw_next">'.wp_kses_post($args['next']).'</a>';
		$nextprev = $nextprev_prev.wp_kses_post($args['middot']).$nextprev_next;
		if(strtolower($args['np_pos'])=='top'):
			$result .= '<div class="rotatingtweets_nextprev">'.$nextprev.'</div>';
		elseif(strtolower($args['np_pos'])=='beforeafter'):
			$result .= '<div class="rotatingtweets_nextprev">'.$nextprev_prev.'</div>';		
		endif;
	endif;
	if(isset($args['no_rotate']) && $args['no_rotate']):
		$rotclass = 'norotatingtweets';
	else:
		$rotclass = 'rotatingtweets';
	endif;
	# Now set all the version 2 options
	$v2string = '';
	$v2options = array(
		'auto-height' => $auto_height,
		'fx' => $rotation_type,
		'pause-on-hover' => 'true',
		'timeout' => $timeout,
		'speed' => $speed,
		'easing' => 'swing',
		'slides'=> 'div.rotatingtweet'
	);
	// Uses the continuous settings recommended at http://jquery.malsup.com/cycle2/demo/continuous.php for cycle2
	if ( strtolower(get_stylesheet()) == 'magazino' || (isset($api['jquery_cycle_version']) && $api['jquery_cycle_version'] == 2) ):
		if($timeout == 0):
			$v2options['timeout'] = 1;
			$v2options['easing'] = 'linear';
		endif;
		if($rotation_type == 'carousel'):
			if(empty($args['carousel_horizontal'])):
				$v2options['carousel-vertical'] = true;
				if(isset($args['carousel_count']) && $args['carousel_count'] > 0):
					$v2options['carousel-visible'] = max(2,intval($args['carousel_count']));
				else:
					$v2options['carousel-visible'] = 3;
				endif;
			else:
				if(isset($args['carousel_count']) && $args['carousel_count'] > 0):
					$v2options['carousel-visible'] = max(2,intval($args['carousel_count']));
				endif;
				if(isset($args['carousel_responsive'])):
					$v2options['carousel-fluid'] = true;
				endif;
			endif;
		endif;
	endif;
	if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next']):
		$v2options['prev'] = '.'.$id.'_rtw_prev';
		$v2options['next'] = '.'.$id.'_rtw_next';
	endif;
	if(isset($args['show_meta_pager']) && $args['show_meta_pager']):
		$v2options['pager'] = '#'.$id.'_rtw_pager';
		$v2options['pager-template'] = $args['show_meta_pager_blob'];
	endif;
	if(! WP_DEBUG) $v2options['log'] = 'false';
	
	$v2stringelements = array();
	foreach ($v2options as $name => $value) {
		$v2stringelements[] = 'data-cycle-'.$name.'="'.esc_attr($value).'"';
	}
	$v2string = implode(' ',$v2stringelements);
	# Now finalise things
	if(WP_DEBUG):
		$result .= "\n<div class='$rotclass wp_debug rotatingtweets_format_".+intval($args['official_format'])."' id='$id' $v2string>";
	else:
		$result .= "\n<div class='$rotclass rotatingtweets_format_".+intval($args['official_format'])."' id='$id' $v2string>";
	endif;
	$error = get_option('rotatingtweets_api_error');
	if(!empty($error)):
		$result .= "\n<!-- ".esc_html($error[0]['type'])." error: ".esc_html($error[0]['code'])." - ".esc_html($error[0]['message'])." -->";
	endif;
	if(empty($json)):
		# $result .= "\n\t<div class = 'rotatingtweet'><p class='rtw_main'>". __('Problem retrieving data from Twitter','rotatingtweets'). "</p></div>";
		if(!empty($error)):
			$result .= "\n\t<div class = 'rotatingtweet'><p class='rtw_main'>". __('Problem retrieving data from Twitter','rotatingtweets'). "</p></div>";
			/* translators: The placeholders are replaced by details of the error received data from Twitter/X. %1$s is the error code, %2$s is the error message and %3$s is the error type */
			$result .= "\n<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>".sprintf(__('%3$s error code: %1$s - %2$s','rotatingtweets'), esc_html($error[0]['code']), esc_html($error[0]['message']),esc_html($error[0]['type'])). "</p></div>";
			$rt_cache_delay = 10;
			switch($error[0]['code']) {
				case 88:
					$rate = rotatingtweets_get_rate_data();
					# Check if the problem is rate limiting
					/* translators: The %s placeholder is replaced by a link to Twitter/X's page about rate-limiting */
					$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". sprintf(__('This website is currently <a href=\'%s\'>rate-limited by Twitter</a>.','rotatingtweets'),'https://developer.x.com/en/docs/rate-limits') . "</p></div>";
					if(isset($rate['hourly_limit']) && $rate['hourly_limit']>0 && $rate['remaining_hits'] == 0):
						$waittimevalue = intval(($rate['reset_time_in_seconds'] - time())/60);
						$rt_cache_delay = $rate['reset_time_in_seconds'] - time();
						/* translators: %d is replaced by the number of minutes before Rotating Tweets will attempt to get more data from Twitter/X */
						$waittime = sprintf(_n('Next attempt to get data will be in %d minute','Next attempt to get data will be in %d minutes',$waittimevalue,'rotatingtweets'),$waittimevalue);
						if($waittimevalue == 0) $waittime = __("Next attempt to get data will be in less than a minute",'rotatingtweets');
						$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>{$waittime}.</p></div>";
					endif;
					break;
				case 32:
					/* translators: The %s placeholders are replaced by a link to the Rotating Tweets settings page */
					$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". sprintf(__('Please check your <a href=\'%s\'>Rotating Tweets settings</a>.','rotatingtweets'),admin_url().'options-general.php?page=rotatingtweets')."</p></div>";
					break;
				case 34:
					$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". __('Please check the Twitter screen name or list slug in the widget or shortcode.','rotatingtweets')."</p></div>";
					break;
				default:
					switch($error[0]['type']) {
						case 'Twitter':
							/* translators: The %1$s placeholder is replaced by a link to the Twitter/X API status page and %2$s is replaced by a link to the Rotating Tweets settings page */
							$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". sprintf(__('Please check the Twitter name in the widget or shortcode, <a href=\'%2$s\'>Rotating Tweets settings</a> or the <a href=\'%1$s\'>Twitter API status</a>.','rotatingtweets'),'https://dev.twitter.com/status',admin_url().'options-general.php?page=rotatingtweets')."</p></div>";
							break;
						case 'Wordpress':
							/* translators: The %1$s placeholder is replaced by a link to the  Rotating Tweets settings page */
							$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". sprintf(__('Please check your PHP and server settings.','rotatingtweets'),'https://dev.twitter.com/status',admin_url().'options-general.php?page=rotatingtweets')."</p></div>";
							break;
						default:
							/* translators: The %1$s placeholder is replaced by a link to the Twitter/X API status page and %2$s is replaced by a link to the Rotating Tweets settings page */
							$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>". sprintf(__('Please check the Twitter name in the widget or shortcode, <a href=\'%2$s\'>Rotating Tweets settings</a> or the <a href=\'%1$s\'>Twitter API status</a>.','rotatingtweets'),'https://dev.twitter.com/status',admin_url().'options-general.php?page=rotatingtweets')."</p></div>";
							break;
					}
				break;
			}
		elseif(!empty($args['search'])):
			$result .= "\n\t<div class = 'rotatingtweet'><p class='rtw_main'>". __('Problem retrieving data from Twitter','rotatingtweets'). "</p></div>";
			/* translators: The %1$s placeholder is replaced by a link to the same search as the widget or shortcode is using on Twitter/X */
			$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>".sprintf(__('No Tweet results for search <a href="%2$s"><strong>%1$s</strong></a>','rotatingtweets'),esc_html($args['search']),esc_url('https://twitter.com/search?q='.urlencode($args['search']))). "</p></div>";
		else:
			$result .= "\n\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>".$args['msg_no_tweets']. "</p></div>";
		endif;
	else:
		$tweet_counter = 0;
		/*
		$rate = rotatingtweets_get_rate_data();
		# Check if the problem is rate limiting
		if($rate['hourly_limit']>0 && $rate['remaining_hits'] == 0):
			$waittimevalue = intval(($rate['reset_time_in_seconds'] - time())/60);
			$result .= "<!-- Rate limited - ";
			$result .= sprintf(_n('Next attempt to get data will be in %d minute','Next attempt to get data will be in %d minutes',$waittimevalue,'rotatingtweets'),$waittimevalue)." -->";
		endif;
		*/
		# Set up the link treatment
		if(isset($args['links_in_new_window']) && !empty($args['links_in_new_window']) ) {
			$targetvalue = ' target="_blank" rel="noopener" ';
		} else {
			$targetvalue = '';
		}
		if(count($json)==1):
			$firstelement = reset($json);
			$json[] = $firstelement;
		endif;
		if(isset($args['offset']) && $args['offset']>=1 && count($json)>1):
			for ($i = 1; $i <= $args['offset']; $i++) {
				$firstelement = array_shift($json);
				array_push($json,$firstelement);
			}
		endif;
		if(isset($args['shuffle']) && $args['shuffle']):
			shuffle($json);
		endif;
		foreach($json as $twitter_object):
		/*
			if(WP_DEBUG):
				echo "\n<!-- Twitter object in json ";
				print_r($twitter_object);
				echo "\n-->";
			endif;
		*/
/* Old if statement to include retweets (if wanted) and exclude replies
			if ((isset($args['only_rts']) && $args['only_rts'] && isset($twitter_object['retweeted_status'] )) || ((!isset($args['only_rts']) || !$args['only_rts']) && ( ! (  ($args['exclude_replies'] && isset($twitter_object['text']) && substr($twitter_object['text'],0,1)=='@') ||  (!$args['include_rts'] && isset($twitter_object['retweeted_status']))  )  ))):
*/
			if(
				(isset($args['only_rts']) && $args['only_rts'] && isset($twitter_object['retweeted_status'] ))   // If it's only RTs then include RTs
				|| 
				(
					(!isset($args['only_rts']) || !$args['only_rts']) 											// If it's not 'only RTs'
					&& 
					!(
						($args['exclude_replies'] && isset($twitter_object['in_reply_to_status_id_str'])) 		// And it's not a reply that needs to be excluded
						|| 
						( !$args['include_rts'] && isset($twitter_object['retweeted_status']))					// And it's not a rt that needs to be excluded
					)
				)
			):			
				$tweet_counter++;
				if($tweet_counter <= $tweet_count):
					if($tweet_counter == 1 || ( isset($args['no_rotate']) && $args['no_rotate'] ) || $rotation_type == 'carousel' ):
						$result .= "\n\t<div class = 'rotatingtweet'>";
					else:
						$result .= "\n\t<div class = 'rotatingtweet' style='display:none'>";				
					endif;
					# Now to process the text
					if(isset($twitter_object['text'])):
						$main_text = $twitter_object['text'];
					else:
						$main_official_display_text = mb_substr($twitter_object['full_text'],$twitter_object['display_text_range'][0],$twitter_object['display_text_range'][1]-$twitter_object['display_text_range'][0]);
						$main_text = $twitter_object['full_text'];
					endif;
					if(!empty($main_text)):
						$user = $twitter_object['user'];
						$tweetuser = $user;
						# Now the substitutions
						$entities = $twitter_object['entities'];
						# Fix up retweets, links, hashtags and use names
						unset($before);
						unset($after);
						unset($retweeter);
						# First clean up the retweets
						if(isset($twitter_object['retweeted_status'])):
							$rt_data = $twitter_object['retweeted_status'];
						else:
							unset($rt_data);
						endif;
						if(!empty($rt_data)):
						/*
							if(WP_DEBUG):
								echo "<!-- Print RT details: \n";
								print_r($rt_data);
								echo "\n-->";
							endif;
						*/
							$rt_user = $rt_data['user'];
							if(isset($rt_data['text'])):
								$rt_text = $rt_data['text'];
							else:
								$rt_official_display_text = mb_substr($rt_data['full_text'],$rt_data['display_text_range'][0],$rt_data['display_text_range'][1]-$rt_data['display_text_range'][0]);
								$rt_text = $rt_data['full_text'];
							endif;
							// The version numbers in this array remove RT and use the original text
							$rt_replace_array = array(1,2,3);
							if(in_array($args['official_format'],$rt_replace_array) || $args['official_format'] === 'custom' ):
								$main_text = $rt_text;
								$retweeter = $user;
								$tweetuser = $rt_user;
							else:
								$main_text = "RT @".$rt_user['screen_name'] . " " . $rt_text;
							endif;
							$before[] = "*@".$rt_user['screen_name']."\b*i";
							$after[] = rotatingtweets_user_intent($rt_user,$twitterlocale,'screen_name',$targetvalue);
							$entities = $rt_data['entities'];
						endif;
						# First the user mentions
						if(isset($args['tweet_length']) && $args['tweet_length']>0 ):
							$tweetwords = explode(" ",$main_text);
							$new_main_text = array_shift($tweetwords);
							foreach($tweetwords as $tweetword):
								if(strlen($new_main_text." ".$tweetword)<$args['tweet_length']):
									$new_main_text .= " ".$tweetword;
									if(WP_DEBUG):
										echo "<!-- adding '".esc_html($tweetword)."' -->";
									endif;
								else:
									$new_main_text .= "&hellip;";
									if(WP_DEBUG):
										echo "<!-- finishing and adding '&hellip;' -->";
									endif;
									break;
								endif;
							endforeach;
							$main_text = $new_main_text;
						endif;
						if(isset($entities['user_mentions'])):
							$user_mentions = $entities['user_mentions'];
						else:
							unset($user_mentions);
						endif;
						if(!empty($user_mentions)):
							foreach($user_mentions as $user_mention):
								$before[] = "*@".$user_mention['screen_name']."\b*i";
								$after[] = rotatingtweets_user_intent($user_mention,$twitterlocale,'screen_name',$targetvalue);
							endforeach;
							# Clearing up duplicates to avoid strange result (possibly risky?)
							$before = array_unique($before);
							$after = array_unique($after);
						endif;
						# Now the URLs
						if(isset($entities['urls'])):
							$urls = $entities['urls'];
						else:
							unset($urls);
						endif;
						if(!empty($urls)):
							foreach($urls as $url):
								$before[] = "*".$url['url']."*";
								$displayurl = $url['display_url'];
								if(strlen($displayurl)>$urllength):
									# PHP sometimes has a really hard time with unicode characters - this one removes the ellipsis
									$displayurl = str_replace(json_decode('"\u2026"'),"",$displayurl);
									$displayurl = mb_substr($displayurl,0,$urllength)."&hellip;";
								endif;
								if(isset($args['show_tco_link']) && $args['show_tco_link']):
									$after[] = "<a href='".$url['url']."' title='".$url['expanded_url']."'".$targetvalue." class='rtw_url_link'>".esc_html($url['url'])."</a>";								
								else:
									$after[] = "<a href='".$url['url']."' title='".$url['expanded_url']."'".$targetvalue." class='rtw_url_link'>".esc_html($displayurl)."</a>";
								endif;
							endforeach;
							$before = array_unique($before);
							$after = array_unique($after);
						endif;
						$show_media = '';
						if(isset($entities['media'])):
							$media = $entities['media'];
							$media_data = $media[0];
							if(isset($args['show_media']) && $args['show_media']):
								$alt = esc_html(trim(str_replace($media_data['url'],'',wp_strip_all_tags($main_text))));
								$before[] = "*".$media_data['url']."*";
								$after[] = "";
								$show_media = "<a href='{$media_data['url']}' title='{$alt}'{$targetvalue}><img src='{$media_data['media_url_https']}' alt='{$alt}' class='rtw_media_image' /></a>";
							endif;
						else:
							unset($media);
						endif;
						if(!empty($media)):
							foreach($media as $medium):
								$before[] = "*".$medium['url']."*";
								$displayurl = $medium['display_url'];
								if(strlen($displayurl)>$urllength):
									$displayurl = str_replace(json_decode('"\u2026"'),"",$displayurl);
									$displayurl = mb_substr($displayurl,0,$urllength)."&hellip;";
								endif;
								$after[] = "<a href='".$medium['url']."' title='".$medium['expanded_url']."'".$targetvalue." class='rtw_media_link'>".esc_html($displayurl)."</a>";
							endforeach;			
						endif;
	//					$before[]="%#([0-9]*[\p{L}a-zA-Z_]+\w*)%";
						# This is designed to find hashtags and turn them into links...
						$before[]="%#\b(\d*[^\d\s[:punct:]]+[^\s[:punct:]]*)%u";
						$after[]='<a href="https://twitter.com/search?q=%23$1&amp;src=hash" title="#$1"'.$targetvalue.' class="rtw_hashtag_link">#$1</a>';
						
						# Put line feeds in
						if(isset($args['show_line_breaks']) && $args['show_line_breaks']):
							$before[]="%[\r\n]%m";
							$after[]="<br />";
						endif;
						
						# Attempts to remove emoji - see http://www.regular-expressions.info/unicode.html https://en.wikipedia.org/wiki/Emoji
						if(isset($args['no_emoji']) && $args['no_emoji']):
							// $before[]='/\\p{InGreek_Extended}/u'; #Not supported by PCRE http://php.net/manual/en/regexp.reference.unicode.php
							$before[]='/\\p{C}/u'; # Removed all 'other' characters - http://php.net/manual/en/regexp.reference.unicode.php
							$after[]='';
						endif;	
/*
						if( defined('DB_CHARSET') && strtoupper(DB_CHARSET) !='UTF-8' && strtoupper(DB_CHARSET)!= 'UTF8' && strtoupper(DB_CHARSET)!= '' ):
							$new_text = iconv("UTF-8",DB_CHARSET . '//TRANSLIT',$main_text);
							if(empty($main_text)):
								if(WP_DEBUG):
									echo "<!-- iconv to ".DB_CHARSET." failed -->";
								endif;
							else:
								$main_text = $new_text;
							endif;
						endif;
*/
						$main_text = rotatingtweets_convert_charset($main_text);
						$new_text = preg_replace($before,$after,$main_text);
						if(empty($new_text)):
							if(WP_DEBUG):
								echo "<!-- preg_replace failed -->";
							endif;
							array_pop($before);
							$before[]="%#\b(\d*[^\d\s[:punct:]]+[^\s[:punct:]]*)%";
							$new_text = preg_replace($before,$after,$main_text);
							if(empty($new_text)):
								if(WP_DEBUG):
									echo "<!-- simplified preg_replace failed -->";
								endif;
							else:
								$main_text = $new_text;
							endif;
						else:
							$main_text = $new_text;
						endif;
						if(isset($args['link_all_text']) && $args['link_all_text']):
							$new_text = rotatingtweets_user_intent($tweetuser,$twitterlocale,$main_text,$targetvalue);
							if(empty($new_text)):
								if(WP_DEBUG):
									echo "<!-- linking all text failed -->";
								endif;
							else:
								$main_text = $new_text;
							endif;
						endif;
						// Attempt to deal with a very odd situation where no text is appearing
						if(empty($main_text)):
							if(WP_DEBUG):
								echo "<!-- Main Text Empty - Debug Data: \n";print_r($before);print_r($after);print_r($args);echo "\n-->\n";
							endif;
							$main_text = $twitter_object['text'];
						endif;
						# Set the iconsize
						if(isset($args['profile_image_size'])):
							$iconsize = $args['profile_image_size'];
						else:
							$iconsize = 'normal';
						endif;
						# Now sort out display order
						if(isset($args['rtw_display_order']) && $args['rtw_display_order'] ):
							$rt_display_order = explode(",",$args['rtw_display_order']);
						else:
							$rt_display_order = array('info','main','media','meta');
						endif;
						# Now for the different display options
						switch ($args['official_format']) {
						case 'custom':
							# This first option lets you use your own function to display tweets
							if (function_exists('rotatingtweets_display_override')) {
								if(!isset($retweeter)) $retweeter = '';
								$result .= rotatingtweets_display_override(	
									$args, $tweetuser, $main_text, $twitter_object, $twitterlocale, $targetvalue, $retweeter, $show_media, $nextprev );
								break;
							}
						case 0:
							$meta = '';
							foreach($rt_display_order as $rt_display_element):
								switch( strtolower(trim($rt_display_element))):
								case 'info':
									break;
								case 'main':
									# This is the original Rotating Tweets display routine
									$result .= "\n\t\t<p class='rtw_main'>$main_text</p>";
									break;
								case 'media':
									if(isset($args['show_media']) && !empty($show_media)):
										$result .= "<div class='rtw_media'>$show_media</div>";
									endif;
									break;
								case 'meta':
									if($args['show_meta_timestamp']):
										$meta .= rotatingtweets_timestamp_link($twitter_object,'default',$targetvalue);
									endif;
									if($args['show_meta_screen_name']):
										if(!empty($meta)) $meta .= ' ';
										if(isset($args['screen_name_plural'])):
											$screennamecount = max(1,$args['screen_name_plural']+1);
										else:
											$screennamecount = 1;
										endif;
										/* translators: The %1$s placeholder is replaced by a link to the Twitter/X user name and %2$s is replaced by the user name itself */
										$meta .= sprintf(_n('from <a href=\'%1$s\' title=\'%2$s\'>%2$s\'s Twitter</a>','from <a href=\'%1$s\' title=\'%2$s\'>%2$s\' Twitter</a>',$screennamecount,'rotatingtweets'),'https://twitter.com/intent/user?screen_name='.urlencode($user['screen_name']),$user['name']);
									endif;
									if($args['show_meta_via']):
										if(!empty($meta)) $meta .= ' ';
										/* translators: The %1$s placeholder is replaced by the source of the Tweet (pulled from Twitter/X) */
										$meta .=sprintf(__("via %s",'rotatingtweets'),$twitter_object['source']);
									endif;
									if($args['show_meta_reply_retweet_favorite']):
										if(!empty($meta)) $meta .= ' &middot; ';
										$meta .= rotatingtweets_intents($twitter_object,$twitterlocale, 0,$targetvalue);
									endif;
									if(isset($args['show_meta_tweet_counter']) && $args['show_meta_tweet_counter']):
										if(!empty($meta)) $meta .= ' &middot; ';
										/* translators: used for the tweet counter - e.g. 1 of 23, 3 of 34 */
										$meta .= sprintf(__('%1$s of %2$s','rotatingtweets'),$tweet_counter,$tweet_count);
									endif;
									if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
										if(!empty($meta)) $meta .= ' &middot; ';
										$meta .= $nextprev;
									endif;
									if(!empty($meta)) $result .= "\n\t\t<p class='rtw_meta'>".ucfirst($meta)."</p>";
									break;
								endswitch;
							endforeach;
							break;
						case 1:
							# This is an attempt to replicate the original Tweet
							foreach($rt_display_order as $rt_display_element):
								switch( strtolower(trim($rt_display_element))):
								case 'info':
									$result .= "\n\t<div class='rtw_info'>";
									$result .= "\n\t\t<div class='rtw_twitter_icon'><img src='".plugin_dir_url( __FILE__ )."images/twitter-bird-16x16.png' width='16' height='16' alt='".__('Twitter','rotatingtweets')."' /></div>";
									$result .= "\n\t\t<div class='rtw_icon'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'icon',$targetvalue,$iconsize)."</div>";
									$result .= "\n\t\t<div class='rtw_name'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'name',$targetvalue)."</div>";
									$result .= "\n\t\t<div class='rtw_id'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'screen_name',$targetvalue)."</div>";
									$result .= "\n\t</div>";
									break;
								case 'main':
									$result .= "\n\t<p class='rtw_main'>".$main_text."</p>";
									break;
								case 'media':
									if(isset($args['show_media']) && !empty($show_media)):
										$result .= "<div class='rtw_media'>$show_media</div>";
									endif;
									break;
								case 'meta':
									$result .= "\n\t<div class='rtw_meta'>";
									if($args['show_meta_reply_retweet_favorite'] || !isset($args['official_format_override']) || !$args['official_format_override'] ):
										$result .= "<div class='rtw_intents'>".rotatingtweets_intents($twitter_object,$twitterlocale, 1,$targetvalue).'</div>';
									endif;
									if($args['show_meta_timestamp'] || !isset($args['official_format_override']) || !$args['official_format_override'] ):						
										$result .= "\n\t<div class='rtw_timestamp'>".rotatingtweets_timestamp_link($twitter_object,'long',$targetvalue);
										if(isset($retweeter)) {
											/* translators: The %1$s placeholders is replaced by the name of the account which retweeted a particular Tweet */
											$result .= " &middot; </div>".rotatingtweets_user_intent($retweeter,$twitterlocale,sprintf(__('Retweeted by %s','rotatingtweets'),$retweeter['name']),$targetvalue);
										} else {
											$result .=  "</div>";
										}
									endif;
									if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
										$result .= " &middot; ".$nextprev;
									endif;
									$result .= "\n</div>";
									break;
								endswitch;
							endforeach;
							break;
						case 2:
							# This is a slightly adjusted version of the original tweet - designed for wide boxes - consistent with Twitter guidelines
							$result .= "\n\t\t<div class='rtw_wide'>";
							$result .= "\n\t\t<div class='rtw_wide_icon'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'icon',$targetvalue,$iconsize)."</div>";
							$result .= "\n\t\t<div class='rtw_wide_block'>";
							foreach($rt_display_order as $rt_display_element):
								switch( strtolower(trim($rt_display_element))):
								case 'info':
									$result .= "<div class='rtw_info'>";
									if($args['show_meta_timestamp'] || !isset($args['official_format_override']) || !$args['official_format_override'] ):
										$result .= "\n\t\t\t<div class='rtw_time_short'>".rotatingtweets_timestamp_link($twitter_object,'short',$targetvalue).'</div>';
									endif;
									$result .= "\n\t\t\t<div class='rtw_name'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'name',$targetvalue)."</div>";
									$result .= "\n\t\t\t<div class='rtw_id'>".rotatingtweets_user_intent($tweetuser,$twitterlocale,'screen_name',$targetvalue)."</div>";
									$result .= "\n\t\t</div>";
									break;
								case 'main':
									$result .= "\n\t\t<p class='rtw_main'>".$main_text."</p>";
									break;
								case 'media':
									if(isset($args['show_media']) && !empty($show_media)):
										$result .= "<div class='rtw_media'>$show_media</div>";
									endif;
									break;
	//						$result .= "\n\t\t<div class='rtw_meta'><div class='rtw_intents'>".rotatingtweets_intents($twitter_object,$twitterlocale, 1).'</div>';
								case 'meta':
									if(isset($retweeter)) {
										/* translators: The %s placeholder is replaced by the name of the account which retweeted the Tweet */
										$result .= "\n\t\t<div class='rtw_rt_meta'>".rotatingtweets_user_intent($retweeter,$twitterlocale,"<img src='".plugin_dir_url( __FILE__ )."images/retweet_on.png' width='16' height='16' alt='".sprintf(__('Retweeted by %s','rotatingtweets'),$retweeter['name'])."' />".sprintf(__('Retweeted by %s','rotatingtweets'),$retweeter['name']),$targetvalue)."</div>";
									}
									if($args['show_meta_reply_retweet_favorite'] || !isset($args['official_format_override']) || !$args['official_format_override'] || $args['displaytype']=='widget' || (isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets') ):
										$result .= "\n\t\t<div class='rtw_meta'><span class='rtw_expand' style='display:none;'>".__('Expand','rotatingtweets')."</span><span class='rtw_intents'>";
										if($args['show_meta_reply_retweet_favorite'] || !isset($args['official_format_override']) || !$args['official_format_override'] || $args['displaytype']=='widget' ):
											$result .= rotatingtweets_intents($twitter_object,$twitterlocale, 2,$targetvalue);
										endif;
										if(($args['show_meta_reply_retweet_favorite'] || !isset($args['official_format_override']) || !$args['official_format_override'] || $args['displaytype']=='widget' ) && (isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets')):
											$result .= wp_kses_post($args['middot']);
										endif;
										if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
											$result .= $nextprev;
										endif;
										$result .= "</span></div>";
									endif;
									$result .= "</div></div>";
									break;
								endswitch;
							endforeach;
							break;
						case 3:
							# This one uses the twitter standard approach for embedding via their javascript API - unfortunately I can't work out how to make it work with the rotating tweet javascript!  If anyone can work out how to calculate the height of a oEmbed Twitter tweet, I will be very grateful! :-)
							$result .= '<blockquote class="twitter-tweet">';
							$result .= "<p>".$main_text."</p>";
							$result .= '&mdash; '.$user['name'].' (@'.$user['screen_name'].') <a href="https://twitter.com/twitterapi/status/'.$twitter_object['id_str'].'" data-datetime="'.gmdate('c',strtotime($twitter_object['created_at'])).'"'.$targetvalue.'>'.date_i18n(get_option('date_format') ,strtotime($twitter_object['created_at'])).'</a>';
							$result .= '</blockquote>';
							break;
						case 4:
							$result .= "\n\t\t<p class='rtw_main'>$main_text</p>";
							$result .= "\n\t<div class='rtw_meta rtw_info'><div class='rtw_intents'>".rotatingtweets_intents($twitter_object,$twitterlocale, 1,$targetvalue).'</div>';
							if($args['show_meta_screen_name']):
								if(!empty($meta)) $meta .= ' ';
								if(isset($args['screen_name_plural'])):
									$screennamecount = max(1,$args['screen_name_plural']+1);
								else:
									$screennamecount = 1;
								endif;
								/* translators: The %1$s placeholder is replaced by a link to the Twitter/X user name and %2$s is replaced by the user name itself */
								$meta .= sprintf(_n('from <a href=\'%1$s\' title=\'%2$s\'>%2$s\'s Twitter</a>','from <a href=\'%1$s\' title=\'%2$s\'>%2$s\' Twitter</a>',$screennamecount,'rotatingtweets'),'https://twitter.com/intent/user?screen_name='.urlencode($user['screen_name']),$user['name']);
							endif;							
							$result .= rotatingtweets_timestamp_link($twitter_object,'long',$targetvalue);
							if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
								$result .= ' &middot; '.$nextprev;
							endif;
							$result .= "\n</div>";
							break;
						case 5:
							# This is an adjusted Rotating Tweets display routine
							$result .= "\n\t\t<p class='rtw_main'><img src='".plugin_dir_url( __FILE__ )."images/bird_16_black.png' alt='Twitter' />&nbsp;&nbsp; $main_text ";
							$meta = '';
							if($args['show_meta_timestamp']):
								$meta .= rotatingtweets_timestamp_link($twitter_object,'default',$targetvalue);
							endif;
							if($args['show_meta_screen_name']):
								if(!empty($meta)) $meta .= ' ';
								if(isset($args['screen_name_plural'])):
									$screennamecount = max(1,$args['screen_name_plural']+1);
								else:
									$screennamecount = 1;
								endif;
								/* translators: The %1$s placeholder is replaced by a link to the Twitter/X user name and %2$s is replaced by the user name itself */
								$meta .= sprintf(_n('from <a href=\'%1$s\' title=\'%2$s\'>%2$s\'s Twitter</a>','from <a href=\'%1$s\' title=\'%2$s\'>%2$s\' Twitter</a>',$screennamecount,'rotatingtweets'),'https://twitter.com/intent/user?screen_name='.urlencode($user['screen_name']),$user['name']);
							endif;
							if($args['show_meta_via']):
								if(!empty($meta)) $meta .= ' ';
								/* translators: The %s placeholder is replaced by the source of the Tweet (as returned from Twitter/X */
								$meta .=sprintf(__("via %s",'rotatingtweets'),$twitter_object['source']);
							endif;
							if($args['show_meta_reply_retweet_favorite']):
								if(!empty($meta)) $meta .= ' &middot; ';
								$meta .= rotatingtweets_intents($twitter_object,$twitterlocale, 0,$targetvalue);
							endif;
							if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
								if(!empty($meta)) $meta .= ' &middot; ';
								$meta .= $nextprev;
							endif;
							if(!empty($meta)) $result .= "\n\t\t<span class='rtw_meta'>".ucfirst($meta)."</span></p>";
							break;
						case 6:
							# This is the original Rotating Tweets display routine - adjusted for a user
							$result .= "\n\t\t<p class='rtw_main'>".rotatingtweets_user_intent($user,$twitterlocale,'blue_bird',$targetvalue).$main_text."</p>";
							$meta = '';
							if($args['show_meta_timestamp']):
								$meta .= rotatingtweets_timestamp_link($twitter_object,'default',$targetvalue);
							endif;
							if($args['show_meta_screen_name']):
								if(!empty($meta)) $meta .= ' ';
								if(isset($args['screen_name_plural'])):
									$screennamecount = max(1,$args['screen_name_plural']+1);
								else:
									$screennamecount = 1;
								endif;
								/* translators: The %1$s placeholder is replaced by a link to the Twitter/X user name and %2$s is replaced by the user name itself */
								$meta .= sprintf(_n('from <a href=\'%1$s\' title=\'%2$s\'>%2$s\'s Twitter</a>','from <a href=\'%1$s\' title=\'%2$s\'>%2$s\' Twitter</a>',$screennamecount,'rotatingtweets'),'https://twitter.com/intent/user?screen_name='.urlencode($user['screen_name']),$user['name']);
							endif;
							if($args['show_meta_via']):
								if(!empty($meta)) $meta .= ' ';
								/* translators: %s is replaced by the source of the tweet (according to X/Twitter) */
								$meta .=sprintf(__("via %s",'rotatingtweets'),$twitter_object['source']);
							endif;
							if($args['show_meta_reply_retweet_favorite']):
								if(!empty($meta)) $meta .= ' &middot; ';
								$meta .= rotatingtweets_intents($twitter_object,$twitterlocale, 0,$targetvalue);
							endif;

							if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && $args['np_pos']=='tweets'):
								if(!empty($meta)) $meta .= ' &middot; ';
								$meta .= $nextprev;
							endif;

							if(!empty($meta)) $result .= "\n\t\t<p class='rtw_meta'>".ucfirst($meta)."</p>";
							break;
						}
					else:
						$result .= "\n\t\t<p class='rtw_main'>".__("Problem retrieving data from Twitter.",'rotatingtweets')."</p></div>";
						$result .= "<!-- rotatingtweets plugin was unable to parse this data: ".print_r($json,TRUE)." -->";
						$result .= "\n\t\t<div class = 'rotatingtweet' style='display:none'><p class='rtw_main'>".__("Please check the comments on this page's HTML to understand more.",'rotatingtweets')."</p>";
					endif;
					$result .= "</div>";
				endif;
			endif;
		endforeach;
	endif;
	if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && isset($args['np_pos']) && $args['np_pos']=='insidebottom'):
		$result .= $nextprev;
	endif;
	$result .= "\n</div>";
	// Show meta progress blobs 
	if(isset($args['show_meta_pager']) && $args['show_meta_pager']):
		$result .= "<div id='".$id."_rtw_pager' class='rtw_pager'></div>";
	endif;
	if(isset($args['show_meta_prev_next']) && $args['show_meta_prev_next'] && isset($args['np_pos'])):
		if(strtolower($args['np_pos'])=='bottom'):
			$result .= '<div class="rotatingtweets_nextprev">'.$nextprev.'</div>';
		elseif(strtolower($args['np_pos'])=='beforeafter'):
			$result .= '<div class="rotatingtweets_nextprev">'.$nextprev_next.'</div>';		
		endif;
	endif;
	if($args['show_follow'] && !empty($args['screen_name']) && !strpos($args['screen_name'],' ') && !strpos($args['screen_name'],',') && !strpos($args['screen_name'],';')):
		$shortenvariables = '';
		if($args['no_show_count']) $shortenvariables = ' data-show-count="false"';
		if($args['no_show_screen_name']) $shortenvariables .= ' data-show-screen-name="false"';
		if(isset($args['large_follow_button']) && $args['large_follow_button'])  $shortenvariables .= ' data-size="large"';
		$args['screen_name'] = rotatingtweets_clean_screen_name($args['screen_name']);
		/* translators: %s is replaced with a link to the required Twitter screen name. */
		$followUserText = sprintf(__('Follow @%s','rotatingtweets'),wp_kses_post(str_replace('@','',$args['screen_name'])));
		$result .= "\n<div class='rtw_follow follow-button'><a href='https://twitter.com/".esc_attr($args['screen_name'])."' class='twitter-follow-button'{$shortenvariables} title='".$followUserText."' data-lang='{$twitterlocale}'>".$followUserText."</a></div>";
	endif;
	rotatingtweets_enqueue_scripts();
	$rt_cache_delay = max($rt_cache_delay,20);
	if( defined('W3TC_DYNAMIC_SECURITY') && function_exists('w3_instance') && !( isset($args['no_cache']) && $args['no_cache']==TRUE )):
		$w3config = w3_instance('W3_Config');
		$w3_pgcache_enabled = $w3config->get_boolean('pgcache.enabled');
		$w3_pgcache_engine = $w3config->get_string('pgcache.engine');
		$w3_late_init = $w3config->get_boolean('pgcache.late_init');
		$w3_debug = $w3config->get_boolean('pgcache.debug');
		$w3_browsercompression = $w3config->get_boolean('browsercache.enabled') && $w3config->get_boolean('browsercache.html.compression') && function_exists('gzencode') && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && (stristr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false);
		if( $w3_pgcache_enabled && ($w3_pgcache_engine != 'file_generic') && $w3_late_init && isset($args['w3tc_render_to']) && !empty($args['w3tc_render_to']) && !$w3_browsercompression):
			$rt_transient_name = substr(sanitize_file_name('rt_w3tc_'.$args['w3tc_render_to']),0,44);
			$rt_cached_args = $args;
			$rt_cached_args['no_cache']=TRUE;
			$rt_w3tc_cache_lifetime = $w3config->get_integer('pgcache.lifetime');
			$rt_cached_args['text_cache_id'] = "rt-mf-".substr($args['text_cache_id'],6,1000);
			rotatingtweets_set_transient($rt_transient_name,$rt_cached_args, $rt_w3tc_cache_lifetime * 2 );
			rotatingtweets_set_transient($rt_cached_args['text_cache_id'],$result,$rt_cache_delay);	
			$result = '<!-- mfunc '.W3TC_DYNAMIC_SECURITY.' $rt=rotatingtweets_get_transient("'.$rt_cached_args['text_cache_id'].'");if(!empty($rt)){echo $rt;}else{$args=rotatingtweets_get_transient("'.$rt_transient_name.'");rotatingtweets_display($args);}; --><!-- /mfunc '.W3TC_DYNAMIC_SECURITY.' -->';	
			if(WP_DEBUG || $w3_debug ):
				$result .= "<!-- Rotating Tweets W3TC Fragment Caching: Success ! -->";
			endif;
		elseif(WP_DEBUG || $w3_debug ):
			$result .= "<!-- Rotating Tweets W3TC Fragment Caching: Start Diagnostics -->";
			if( !defined ('W3TC_DYNAMIC_SECURITY' ) ):
				$result .= "<!-- W3TC_DYNAMIC_SECURITY not defined -->";
			endif;
			if( !$w3_pgcache_enabled ):
				$result .= "<!-- Page Cache not enabled on the W3 Total Cache settings page -->";
			endif;
			if( $w3_pgcache_engine == 'file_generic' ):
				$result .= "<!-- Fragment Caching does not work if Page Cache Method is 'Disk: Enhanced' -->";
			endif;
			if (!(isset($args['w3tc_render_to']) && !empty($args['w3tc_render_to']))):
				$result .= "<!-- Rotating Tweets shortcode option 'w3tc_render_to' not defined -->";
			endif;
			if (!$w3_late_init ):
				$result .= "<!-- 'Late Initialization' not enabled on the W3 Total Cache Page Cache settings page -->";
			endif;
			if ( $w3_browsercompression ):
				$result .= "<!-- HTTP Compression needs to be disabled on the W3 Total Cache Browser Cache settings page -->";
			endif;
			$result .= "<!-- Rotating Tweets W3TC Fragment Caching: End Diagnostics -->";
		endif;
	endif;

	$rt_set_transient = rotatingtweets_set_transient($args['text_cache_id'],$result,$rt_cache_delay);	
	if(WP_DEBUG):
		if($rt_set_transient):
			echo "<!-- Transient ".esc_attr($args['text_cache_id'])." stored for ".number_format($rt_cache_delay)." seconds -->";
		else:
			echo "<!-- Transient ".esc_attr($args['text_cache_id'])." FAILED to store for ".number_format($rt_cache_delay)." seconds -->";
		endif;
	endif;

	if($print) echo wp_kses_post($result);
	return($result);
}
# Load the language files - needs to come after the widget_init line - and possibly the shortcode one too!
function rotatingtweets_init() {
	load_plugin_textdomain( 'rotatingtweets', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
//	load_plugin_textdomain( 'rotatingtweets' );  // Previous attempt to use language packs
}
add_action('plugins_loaded', 'rotatingtweets_init');

function rotatingtweets_possible_rotations($dropbox = FALSE) {
	# Check if we're using jQuery Cycle 1 or 2 - sends back response for validity checking or raw data for a drop down box
	$api = get_option('rotatingtweets-api-settings');
	if(isset($api['jquery_cycle_version']) && $api['jquery_cycle_version']==2):
		if($dropbox):
			$possibleRotations = array (
				'scrollUp' => __('Scroll Up','rotatingtweets'),
				'scrollDown' => __('Scroll Down','rotatingtweets'),
				'scrollLeft' => __('Scroll Left','rotatingtweets'),
				'scrollRight' => __('Scroll Right','rotatingtweets'),
				'fade' => __('Fade','rotatingtweets'),
				'carousel' => __('Carousel','rotatingtweets'),
				'scrollLeftGap' => __('Scroll Left (with gap)','rotatingtweets')
			);
		else:
			$possibleRotations = array('scrollUp','scrollDown','scrollHorz','scrollLeft','scrollRight','toss','scrollVert','fade','carousel','scrollLeftGap');
		endif;
	else:
		if($dropbox):
			$possibleRotations = array (
				'scrollUp' => __('Scroll Up','rotatingtweets'),
				'scrollDown' => __('Scroll Down','rotatingtweets'),
				'scrollLeft' => __('Scroll Left','rotatingtweets'),
				'scrollRight' => __('Scroll Right','rotatingtweets'),
				'fade' => __('Fade','rotatingtweets')
			);
		else:
			$possibleRotations = array('blindX','blindY','blindZ','cover','curtainX','curtainY','fade','fadeZoom','growX','growY','none','scrollUp','scrollDown','scrollLeft','scrollRight','scrollHorz','scrollVert','shuffle','slideX','slideY','toss','turnUp','turnDown','turnLeft','turnRight','uncover','wipe','zoom');
		endif;
	endif;
	return($possibleRotations);
}

function rotatingtweets_enqueue_scripts() {
	wp_enqueue_script( 'jquery' ); 
	# Set the base versions of the strings
	$cyclejsfile = 'js/jquery.cycle.all.min.js';
	$rotatingtweetsjsfile = 'js/rotating_tweet.js';
	# Check for evil plug-ins
	if ( ! function_exists( 'is_plugin_active' ) )
		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
//		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	$dependence = array('jquery');
	if (is_plugin_active('wsi/wp-splash-image.php')) {
		//plugin is activated
		$dependence[] = 'jquery.tools.front';
	};
	if(function_exists('pwc_theme_name_scripts')) {
		$dependence[] = 'global-js';
	}
	# Check if we're using jQuery Cycle 1 or 2
	$api = get_option('rotatingtweets-api-settings');
	if(isset($api['jquery_cycle_version']) && $api['jquery_cycle_version']==3) return;
	if(!isset($api['js_in_footer'])) $api['js_in_footer'] = FALSE;
	$style = trim(strtolower(get_stylesheet()));
	$rt_data = get_plugin_data( __FILE__ );
	$rt_cycleversion = $rt_data;
	// Fixes a problem with the magazino template
	if($style == 'magazino' || (isset($api['jquery_cycle_version']) && $api['jquery_cycle_version']==2)):
/*
	'jquery-easing' => 'http://cdnjs.cloudflare.com/ajax/libs/jquery-easing/1.3/jquery.easing.min.js',
*/			
		if (is_plugin_active('cyclone-slider-2/cyclone-slider.php')):
			$cyclepath = str_replace('rotatingtweets/','cyclone-slider-2/cyclone-slider.php',plugin_dir_path( __FILE__ ));
			$rt_cycleversion = get_plugin_data( $cyclepath );
			$rt_enqueue_script_list = array(
				'jquery-cycle2' => plugins_url('cyclone-slider-2/libs/cycle2/jquery.cycle2.min.js'),
				'jquery-cycle2-carousel' => plugins_url('cyclone-slider-2/libs/cycle2/jquery.cycle2.carousel.min.js'),
				'jquery-cycle2-scrollvert' => plugins_url('cyclone-slider-2/libs/cycle2/jquery.cycle2.scrollVert.min.js'),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);
		elseif (is_plugin_active('cyclone-slider-pro/cyclone-slider.php')):
			$cyclepath = str_replace('rotatingtweets/','cyclone-slider-pro/cyclone-slider.php',plugin_dir_path( __FILE__ ));
			$rt_cycleversion = get_plugin_data( $cyclepath );
			$rt_enqueue_script_list = array(
				'jquery-cycle2' => plugins_url('cyclone-slider-pro/libs/cycle2/jquery.cycle2.min.js'),
				'jquery-cycle2-carousel' => plugins_url('cyclone-slider-pro/libs/cycle2/jquery.cycle2.carousel.min.js'),
				'jquery-cycle2-scrollvert' => plugins_url('cyclone-slider-pro/libs/cycle2/jquery.cycle2.scrollVert.min.js'),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);
		elseif ( $style == 'brilliance_pro' || $style == 'brilliance pro' ):
			$rt_enqueue_script_list = array(
				'cpotheme_cycle' => get_template_directory_uri() . '/core/scripts/jquery-cycle2-min.js' ,
				'jquery-cycle2_scrollvert' => plugins_url('js/jquery.cycle2.scrollVert.js', __FILE__),
				'jquery-cycle2_carousel' => plugins_url('js/jquery.cycle2.carousel.js', __FILE__),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);		
		elseif ( $style == 'digital catapult' || $style == 'digitalcatapult' ):
			$rt_enqueue_script_list = array(
				'jquery-cycle2' => get_template_directory_uri() . '/scripts/jquery.cycle2.min.js' ,
				'jquery-cycle2_scrollvert' => plugins_url('js/jquery.cycle2.scrollVert.js', __FILE__),
				'jquery-cycle2_carousel' => plugins_url('js/jquery.cycle2.carousel.js', __FILE__),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);		
		elseif ( $style == 'politicalpress child theme' || $style == 'politicalpress theme' || $style == 'politicalpress-child-theme' ):
			$rt_enqueue_script_list = array(
				'cycle2' => get_template_directory_uri() . '/js/jquery.cycle2.min.js' ,
				'jquery-cycle2_scrollvert' => plugins_url('js/jquery.cycle2.scrollVert.js', __FILE__),
				'jcarousel' => get_template_directory_uri().'/js/jquery.jcarousel.min.js' ,
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);
		elseif ( function_exists( 'newswire_custom_scripts' ) ):
			$rt_enqueue_script_list = array(
				'cycle2' => get_template_directory_uri() . '/library/js/jquery.cycle2.min.js' ,
				'cycle2_scrollvert' => get_template_directory_uri() . '/library/js/jquery.cycle2.scrollVert.min.js' ,
				'cycle2_carousel' => plugins_url('js/jquery.cycle2.carousel.js', __FILE__),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2_cyclone.js', __FILE__)
			);		
		else:
			$rt_enqueue_script_list = array(
				'jquery-cycle2-renamed' => plugins_url('js/jquery.cycle2.renamed.js', __FILE__),
				'jquery-cycle2-scrollvert-renamed' => plugins_url('js/jquery.cycle2.scrollVert.renamed.js', __FILE__),
				'jquery-cycle2-carousel-renamed' => plugins_url('js/jquery.cycle2.carousel.renamed.js', __FILE__),
				'rotating_tweet' => plugins_url('js/rotatingtweets_v2.js', __FILE__)
			);
	//		$dependence[]='jquery-effects-core';
		endif;
		foreach($rt_enqueue_script_list as $scriptname => $scriptlocation):
			if( $scriptname == 'rotating_tweet' ):
				$scriptver = $rt_data['Version'];
			else:
				$scriptver = $rt_cycleversion['Version'];
			endif;
			wp_enqueue_script($scriptname,$scriptlocation,$dependence,$scriptver,$api['js_in_footer']);
			$dependence[] = $scriptname;
		endforeach;
	else:
		# Get Stylesheet
		switch ($style):
			case 'bremen_theme':
			case 'zeebizzcard':
	//		case 'zeeStyle':
				wp_dequeue_script( 'zee_jquery-cycle');
				wp_enqueue_script( 'zee_jquery-cycle', plugins_url($cyclejsfile, __FILE__),$dependence,$rt_data['Version'],$api['js_in_footer']);
				$dependence[]='zee_jquery-cycle';
				break;
			case 'oxygen':
				wp_dequeue_script( 'oxygen_cycle');
				wp_enqueue_script( 'oxygen_cycle', plugins_url($cyclejsfile, __FILE__),$dependence,$rt_data['Version'],$api['js_in_footer']);
				$dependence[]='oxygen_cycle';
				break;		
			case 'avada':
			case 'avada child':
//			case 'avada-child-theme':
//			case 'avada child theme':
			case 'a52cars':
				wp_dequeue_script( 'jquery.cycle');
				wp_enqueue_script( 'jquery.cycle', plugins_url($cyclejsfile, __FILE__),$dependence,$rt_data['Version'],$api['js_in_footer'] );
				$dependence[]='jquery.cycle';
				break;
			default:
				wp_enqueue_script( 'jquery-cycle', plugins_url($cyclejsfile, __FILE__),$dependence,$rt_data['Version'],$api['js_in_footer'] );
				$dependence[]='jquery-cycle';
				break;
		endswitch;
		wp_enqueue_script( 'rotating_tweet', plugins_url($rotatingtweetsjsfile, __FILE__),$dependence,$rt_data['Version'],$api['js_in_footer'] );
	endif;
/*
	if($style == 'twentyfifteen'):
		wp_enqueue_script('rt_twentyfifteen',plugins_url('js/rt_twentyfifteen.js', __FILE__),array('rotating_tweet','twentyfifteen-script'),$rt_data['Version'],$api['js_in_footer']);
	endif;
*/
}
function rotatingtweets_enqueue_style() {
	if(is_admin()) return;
	$api = get_option('rotatingtweets-api-settings');
	$rt_data = get_plugin_data( __FILE__ );
	if(isset($api['hide_css']) && $api['hide_css']==1) return;
	wp_enqueue_style( 'rotatingtweets', plugins_url('css/style.css', __FILE__),array(), $rt_data['Version'] );  
	$uploads = wp_upload_dir();
	$personalstyle = array(
		plugin_dir_path(__FILE__).'css/yourstyle.css' => plugins_url('css/yourstyle.css', __FILE__),
		$uploads['basedir'].'/rotatingtweets.css' => $uploads['baseurl'].'/rotatingtweets.css'
	);
	$scriptname = 'rotatingtweet-yourstyle';
	$scriptcounter = 1;
	foreach($personalstyle as $dir => $url):
		if(file_exists( $dir )):
			wp_enqueue_style( $scriptname, $url,array(),$rt_data['Version']);
			$scriptname = 'rotatingtweet-yourstyle-'.$scriptcounter;
			$scriptcounter ++;
		endif;
	endforeach;
}
function rotatingtweets_enqueue_admin_scripts_widget_page($hook) {
	if( 'widgets.php' != $hook ) return;
	rotatingtweets_enqueue_admin_scripts($hook);
}
function rotatingtweets_enqueue_admin_scripts($hook) {
	$rt_data = get_plugin_data( __FILE__ );
	wp_enqueue_script( 'jquery' ); 
	wp_enqueue_script( 'rotating_tweet_admin', plugins_url('js/rotating_tweet_admin.js', __FILE__),array('jquery'),$rt_data['Version'],FALSE );		
}
add_action( 'admin_enqueue_scripts', 'rotatingtweets_enqueue_admin_scripts_widget_page' );
add_action( 'siteorigin_panel_enqueue_admin_scripts', 'rotatingtweets_enqueue_admin_scripts' );
/*
Forces the inclusion of Rotating Tweets CSS in the header - irrespective of whether the widget or shortcode is in use.  I wouldn't normally do this, but CSS needs to be in the header for HTML5 compliance (at least if the intention is not to break other browsers) - and short-code only pages won't do that without some really time-consuming and complicated code up front to check for this
*/
add_action('wp_enqueue_scripts','rotatingtweets_enqueue_style');
// add_action('wp_enqueue_scripts','rotatingtweets_enqueue_scripts'); // Use this if you are loading the tweet page via ajax
$style = strtolower(get_stylesheet());
if($style == 'gleam' || function_exists('siteorigin_panels_render') ):
	add_action('wp_enqueue_scripts','rotatingtweets_enqueue_scripts');
endif;

// Add the deactivation and uninstallation functions
function rotatingtweets_deactivate() {
	// Gets rid of the cache - but not the settings
	delete_option('rotatingtweets_api_error');
	delete_option('rotatingtweets-cache');
	delete_option('rotatingtweets-twitter-languages');
}
function rotatingtweets_uninstall() {
	// Gets rid of all data stored - including settings
	rotatingtweets_deactivate();
	delete_option('rotatingtweets-api-settings');
}

register_deactivation_hook( __FILE__, 'rotatingtweets_deactivate' );
register_uninstall_hook( __FILE__, 'rotatingtweets_uninstall' );

// Filters that can be used to adjust transports - if you have problems with connecting to Twitter, try commenting in one of the following lines
// From a brilliant post by Sam Wood http://wordpress.org/support/topic/warning-curl_exec-has-been-disabled?replies=6#post-920787
function rotatingtweets_block_transport() { return false; }
// add_filter('use_http_extension_transport', 'rotatingtweets_block_transport');
// add_filter('use_curl_transport', 'rotatingtweets_block_transport');
// add_filter('use_streams_transport', 'rotatingtweets_block_transport');
// add_filter('use_fopen_transport', 'rotatingtweets_block_transport');
// add_filter('use_fsockopen_transport', 'rotatingtweets_block_transport');


/** Support for Buddy Press */
/*
if ( function_exists('bp_is_user_profile') && bp_is_user_profile() ):
	add_action( 'bp_profile_header_meta', 'rotatingtweets_bpdisplay' );
endif;

function rotatingtweets_bpdisplay() {
	$bbpressTwittername = bp_get_profile_field_data( array('field'=>'Twitter') );
	if(!empty($bbpressTwittername)) {
		echo do_shortcode("[rotatingtweets screen_name='".$bbpressTwitterName."']");
	}
}
*/
?>