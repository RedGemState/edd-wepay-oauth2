<?php
/**
 * Plugin Name: WePay oAuth2 for Crowdfunding
 * Plugin URI:  https://github.com/astoundify
 * Description: Enable users to create accounts on WePay automatically.
 * Author:      Astoundify
 * Author URI:  http://astoundify.com
 * Version:     0.1
 * Text Domain: awpo2
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main WePay oAuth2 Crowdfunding Class
 *
 * @since Astoundify_WePay_oAuth2 0.1
 */
final class Astoundify_WePay_oAuth2 {

	/**
	 * @var crowdfunding_wepay The one true Astoundify_WePay_oAuth2
	 */
	private static $instance;

	private $creds;

	/**
	 * Main Astoundify_WePay_oAuth2 Instance
	 *
	 * Ensures that only one instance of Crowd Funding exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since Astoundify_WePay_oAuth2 0.1
	 *
	 * @return The one true Crowd Funding
	 */
	public static function instance() {
		if ( ! isset ( self::$instance ) ) {
			self::$instance = new Astoundify_WePay_oAuth2;
			self::$instance->setup_globals();
			self::$instance->includes();
			self::$instance->setup_actions();
		}

		return self::$instance;
	}

	/** Private Methods *******************************************************/

	/**
	 * Set some smart defaults to class variables. Allow some of them to be
	 * filtered to allow for early overriding.
	 *
	 * @since Astoundify_WePay_oAuth2 0.1
	 *
	 * @return void
	 */
	private function setup_globals() {
		/** Versions **********************************************************/

		$this->version    = '0.1';
		$this->db_version = '1';

		/** Paths *************************************************************/

		$this->file         = __FILE__;
		$this->basename     = apply_filters( 'awpo2_plugin_basenname', plugin_basename( $this->file ) );
		$this->plugin_dir   = apply_filters( 'awpo2_plugin_dir_path',  plugin_dir_path( $this->file ) );
		$this->plugin_url   = apply_filters( 'awpo2_plugin_dir_url',   plugin_dir_url ( $this->file ) );

		// Includes
		$this->includes_dir = apply_filters( 'awpo2_includes_dir', trailingslashit( $this->plugin_dir . 'includes'  ) );
		$this->includes_url = apply_filters( 'awpo2_includes_url', trailingslashit( $this->plugin_url . 'includes'  ) );

		$this->template_dir = apply_filters( 'awpo2_templates_dir', trailingslashit( $this->plugin_dir . 'templates'  ) );

		// Languages
		$this->lang_dir     = apply_filters( 'awpo2_lang_dir',     trailingslashit( $this->plugin_dir . 'languages' ) );

		/** Misc **************************************************************/

		$this->domain       = 'awpo2'; 
	}

	/**
	 * Include required files.
	 *
	 * @since Astoundify_WePay_oAuth2 0.1
	 *
	 * @return void
	 */
	private function includes() {
		global $edd_wepay;

		require $edd_wepay->plugin_dir . '/vendor/wepay.php';

		$this->creds = $edd_wepay->get_api_credentials();

		if( edd_is_test_mode() )
			Wepay::useStaging( $this->creds['client_id'], $this->creds['client_secret'] );
		else
			Wepay::useProduction( $this->creds['client_id'], $this->creds['client_secret'] );
	}

	/**
	 * Setup the default hooks and actions
	 *
	 * @since Astoundify_WePay_oAuth2 0.1
	 *
	 * @return void
	 */
	private function setup_actions() {
		add_filter( 'atcf_shortcode_submit_hide', array( $this, 'shortcode_submit_hide' ) );
		add_action( 'template_redirect', array( $this, 'wepay_listener' ) );

		$this->load_textdomain();

		if ( ! is_admin() )
			return;

	}

	function wepay_listener() {
		global $edd_options, $edd_wepay;

		if ( ! is_page( $edd_options[ 'submit_page' ] ) )
			return;

		if ( ! isset( $_GET[ 'code' ] ) )
			return;

		$info = WePay::getToken( $_GET[ 'code' ], get_permalink() );
		
		if ( $info ) {
			$access_token = $info->access_token;

			$wepay = new WePay( $access_token );

			$response = $wepay->request( 'account/create/', array(
				'name'          => get_bloginfo( 'name' ),
				'description'   => get_bloginfo( 'description' )
			) );

			$user = wp_get_current_user();

			update_user_meta( $user->ID, 'wepay_account_id', $response->account_id );
			update_user_meta( $user->ID, 'wepay_access_token', $access_token );
			update_user_meta( $user->ID, 'wepay_account_uri', $response->account_uri );
		} else {
			
		}
	}

	public function shortcode_submit_hide() {
		$user = wp_get_current_user();

		if ( ! $user->wepay_account_id ) {
			add_action( 'atcf_shortcode_submit_hidden', array( $this, 'send_to_wepay' ) );

			return true;
		}

		return false;
	}

	public function send_to_wepay() {
		echo '<p>' . sprintf(  __( 'Before you may begin, you must first create an account on our payment processing service, <a href="http://wepay.com">WePay</a>. <a href="%s">Create an account &rarr;</a>', 'awpo2' ), $this->send_to_wepay_url() ) . '</p>';
	}

	public function send_to_wepay_url() {
		global $edd_wepay;

		$wepay = new WePay( $this->creds[ 'access_token' ] );

		$uri = WePay::getAuthorizationUri( array( 'manage_accounts', 'collect_payments', 'preapprove_payments', 'send_money' ), get_permalink() );

		return esc_url( $uri );
	}

	/**
	 * Loads the plugin language files
	 *
	 * @since Astoundify_WePay_oAuth2 0.1
	 */
	public function load_textdomain() {
		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale', get_locale(), $this->domain );
		$mofile        = sprintf( '%1$s-%2$s.mo', $this->domain, $locale );

		// Setup paths to current locale file
		$mofile_local  = $this->lang_dir . $mofile;
		$mofile_global = WP_LANG_DIR . '/' . $this->domain . '/' . $mofile;

		// Look in global /wp-content/languages/awpo2 folder
		if ( file_exists( $mofile_global ) ) {
			return load_textdomain( $this->domain, $mofile_global );

		// Look in local /wp-content/plugins/wepay-oauth2/languages/ folder
		} elseif ( file_exists( $mofile_local ) ) {
			return load_textdomain( $this->domain, $mofile_local );
		}

		return false;
	}
}

/**
 * The main function responsible for returning the one true WePay oAuth2 Crowd Funding Instance
 * to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * Example: <?php $awpo2 = awpo2(); ?>
 *
 * @since Astoundify_WePay_oAuth2 0.1
 *
 * @return The one true Astoundify WePay oAuth2 Crowdfunding instance
 */
function awpo2() {
	return Astoundify_WePay_oAuth2::instance();
}

awpo2();