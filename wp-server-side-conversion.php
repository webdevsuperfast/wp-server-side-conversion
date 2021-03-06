<?php
/*
Plugin Name: WP Server-side Conversion
Plugin URI:  https://github.com/webdevsuperfast/wp-server-side-conversion
Description: Server-side implementation of the Facebook Conversions API using Facebook Business SDK.
Version:     1.0.0
Author:      Rotsen Mark Acob
Author URI:  https://rotsenacob.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-server-side-conversion
Domain Path: /languages
*/

defined( 'ABSPATH' ) or die( esc_html_e( 'With great power comes great responsibility.', 'wp-server-side-conversion' ) );

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

define( 'SDK_DIR', plugin_dir_path( __FILE__ ) ); // Path to the SDK directory

$loader = include SDK_DIR . '/vendor/autoload.php';

use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\DeliveryCategory;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\Gender;
use FacebookAds\Object\ServerSide\UserData;

class WP_Server_Side_Conversion {
  public function __construct() {
    // Add settings page
    add_action( 'admin_menu', array( &$this, 'wssc_create_settings_page' ) );

    // Add settings sections
    add_action( 'admin_init', array( &$this, 'wssc_setup_sections' ) );
    
    // Add settings fields
    add_action( 'admin_init', array( &$this, 'wssc_setup_fields' ) );

    //* Add settings link in plugins directory
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( &$this, 'wssc_plugin_action_links' ) );

    add_action( 'wp_head', array( &$this, 'wssc_send_server_request' ), 10, 1 );

    add_action( 'wp_head', array( &$this, 'wssc_send_browser_request' ), 10, 2 );

  }

  public function wssc_create_settings_page() {
    $page_title = __( 'WP Server Side Conversion Settings', 'wp-server-side-conversion' );
    $menu_title = __( 'WSSC Settings', 'wp-server-side-conversion' );
    $capability = 'manage_options';
    $slug = 'wssc_settings';

    $callback = array(
        &$this, 
        'wssc_settings_content'
    );
    $icon = 'dashicons-admin-plugins';
    $position = 100;

    add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $slug, $callback );
  }

  public function wssc_setup_sections() {
    add_settings_section(
      'wp_server_side_conversion_settings',
      __( 'Facebook Marketing API Settings', 'wp-server-side-conversion' ),
      array( &$this, 'wssc_section_callback' ),
      'wssc_settings'
    );
  }

  public function wssc_section_callback( $arguments ) {
    switch ( $arguments['id'] ) {
      case 'wp_server_side_conversion_settings':
        break;
    }
  }

  public function wssc_setup_fields() {
    $fields = array(
      array(
        'uid' => 'wssc_pixel_id',
        'section' => 'wp_server_side_conversion_settings',
        'label' => __( 'Pixel ID', 'wp-server-side-conversion' ),
        'type' => 'text',
        'supplimental' => __( 'Enter your Pixel ID', 'wp-server-side-conversion' ),
        'default' => ''
      ),
      array(
        'uid' => 'wssc_access_token',
        'section' => 'wp_server_side_conversion_settings',
        'label' => __( 'Access Token', 'wp-server-side-conversion' ),
        'type' => 'textarea',
        'supplimental' => __( 'Enter your Access Token', 'wp-server-side-conversion' ),
        'default' => ''
      ),
      array(
        'uid' => 'wssc_email_address',
        'section' => 'wp_server_side_conversion_settings',
        'label' => __( 'Email Address', 'wp-server-side-conversion' ),
        'type' => 'email',
        'supplimental' => __( 'This will be used as an external ID parameter', 'wp-server-side-conversion' ),
        'default' => ''
      ),
      array(
        'uid' => 'wssc_test_id',
        'section' => 'wp_server_side_conversion_settings',
        'label' => __( 'Test Event ID', 'wp-server-side-conversion' ),
        'type' => 'text',
        'supplimental' => __( 'Enter your test event ID', 'wp-server-side-conversion' ),
        'default' => ''
      )
    );

    foreach ( $fields as $field ) {
      add_settings_field(
        $field['uid'],
        $field['label'],
        array(
          &$this,
          'wssc_fields_callback'
        ),
        'wssc_settings',
        $field['section'],
        $field
      );

      register_setting(
        'wssc_settings',
        $field['uid']
      );
    }
  }

  public function wssc_fields_callback( $arguments ) {
    $value = get_option( $arguments['uid'] );

    if ( ! $value ) {
      $value = $arguments['default'];
    }

    switch( $arguments['type'] ){
      case 'text':
      case 'email':
      case 'password':
      case 'number':
        printf( '<input name="%1$s" id="%1$s" type="%2$s" value="%3$s" />', $arguments['uid'], $arguments['type'], $value );
        break;
      case 'textarea':
        printf( '<textarea name="%1$s" id="%1$s" rows="5" cols="50">%2$s</textarea>', $arguments['uid'], $value );
        break;
      case 'select':
      case 'multiselect':
        if( ! empty ( $arguments['options'] ) && is_array( $arguments['options'] ) ){
          $attributes = '';
          $options_markup = '';
          foreach( $arguments['options'] as $key => $label ){
              $options_markup .= sprintf( '<option value="%s" %s>%s</option>', $key, selected( $value[ array_search( $key, $value, true ) ], $key, false ), $label );
          }
          if( $arguments['type'] === 'multiselect' ){
              $attributes = ' multiple="multiple" ';
          }
          printf( '<select name="%1$s[]" id="%1$s" %2$s>%3$s</select>', $arguments['uid'], $attributes, $options_markup );
        }
        break;
      case 'radio':
      case 'checkbox':
        if( ! empty ( $arguments['options'] ) && is_array( $arguments['options'] ) ){
          $options_markup = '';
          $iterator = 0;
          foreach( $arguments['options'] as $key => $label ){
            $iterator++;
            $options_markup .= sprintf( '<label for="%1$s_%6$s"><input id="%1$s_%6$s" name="%1$s[]" type="%2$s" value="%3$s" %4$s /> %5$s</label><br/>', $arguments['uid'], $arguments['type'], $key, checked( $value[ array_search( $key, $value, true ) ], $key, false ), $label, $iterator );
          }
          printf( '<fieldset>%s</fieldset>', $options_markup );
        }
        break;
    }

    if( $supplimental = $arguments['supplimental'] ){
      printf( '<p class="description">%s</p>', $supplimental );
    }
  }

  public function wssc_settings_content() { ?>
    <?php 
    if ( ! current_user_can( 'manage_options' ) ) return; 
    ?>

    <div class="wrap">
        <h2><?php _e( 'Facebook Marketing API Settings', 'wp-server-side-conversion' ); ?></h2>
        <hr>
        <form action="options.php" method="post">
            <?php 
            settings_fields( 'wssc_settings' ); 
            do_settings_sections( 'wssc_settings' );
            submit_button();
            ?>
        </form>
    </div>
  <?php }

  public function wssc_plugin_action_links( $actions ) {
    $links = array(
      '<a href="'.esc_url( admin_url( '/options-general.php?page=wssc_settings' ) ).'">'.__( 'Settings', 'wp-server-side-conversion' ).'</a>'
    );

    $actions = array_merge( $actions, $links );

    return $actions;
  }

  public function wssc_send_server_request() {
    // Configuration.
    // Should fill in value before running this script
    $access_token = get_option( 'wssc_access_token' );
    $pixel_id = get_option( 'wssc_pixel_id' );
    $test_id = get_option( 'wssc_test_id' );
    $email = get_option( 'wssc_email_address' );

    if ( is_null( $access_token ) || is_null( $pixel_id ) ) {
      throw new Exception(
        'You must set your access token and pixel id before executing'
      );
    }

    // Initialize
    Api::init(null, null, $access_token);
    $api = Api::instance();
    $api->setLogger(new CurlLogger());

    $events = array();

    // It is recommended to send Client IP and User Agent for ServerSide API Events.
    $user_data = (new UserData())
      ->setClientIpAddress( $_SERVER['REMOTE_ADDR'] )
      ->setClientUserAgent( $_SERVER['HTTP_USER_AGENT'] );

    if ( $email ) :
      $user_data->setExternalId( $email );
    endif;

    if ( isset( $_COOKIE['_fbp'] ) ) :
      $user_data->setFbp( $_COOKIE['_fbp'] );
    endif;

    // Check whether first party Facebook Pixel Cookie is present
    if ( isset( $_COOKIE['_fbc'] ) ) :
      $user_data->setFbc( $_COOKIE['_fbc'] );
    endif;

    // Check whether Click Event is from Facebook
    if ( isset( $_GET['fbclid'] ) ) :
      $user_data->setFbc( 'fb.1.' . time() . '.' . $_GET['fbclid'] );
    endif;

    $event = (new Event())
      ->setEventName( 'PageView' )
      ->setEventTime( time() )
      ->setUserData( $user_data )
      ->setEventSourceUrl( get_permalink() )
      ->setEventId( 'event_' . get_the_ID() );
    
    array_push( $events, $event );

    $request = (new EventRequest( $pixel_id ) )
      ->setEvents($events);
    
    if ( $test_id ) :
      $request->setTestEventCode( $test_id );
    endif;

    $request->execute();
  }

  public function wssc_send_browser_request() {
    $pixel_id = get_option( 'wssc_pixel_id' );
    $test_id = get_option( 'wssc_test_id' );
    $email = get_option( 'wssc_email_address' );
    $event_id = 'event_' . get_the_ID();

    $script = "
    <!-- Facebook Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '$pixel_id');
    fbq('track', 'PageView', {
      'eventID': '$event_id'
    });
    </script>
    ";
    $script .= '
    <noscript>
    <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=327482741928777&ev=PageView&noscript=1&eventID=' . $event_id . '" />
    </noscript>
    <!-- End Facebook Pixel Code -->    
    ';
    
    if ( $pixel_id ) :
      echo $script;
    endif;
  }
}

new WP_Server_Side_Conversion();