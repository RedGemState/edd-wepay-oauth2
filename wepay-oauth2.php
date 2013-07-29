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
 * @since Astoundify WePay oAuth2 0.1
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
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return The one true Crowd Funding
	 */
	public static function instance() {
		if ( ! class_exists( 'ATCF_CrowdFunding' ) || ! class_exists( 'Easy_Digital_Downloads' ) )
			return;

		if ( ! isset ( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	private function __construct() {
		$this->setup_globals();
		$this->setup_actions();
		$this->load_textdomain();
	}

	/** Private Methods *******************************************************/

	/**
	 * Set some smart defaults to class variables. Allow some of them to be
	 * filtered to allow for early overriding.
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return void
	 */
	private function setup_globals() {
		$this->file         = __FILE__;
		$this->basename     = apply_filters( 'awpo2_plugin_basenname', plugin_basename( $this->file ) );
		$this->plugin_dir   = apply_filters( 'awpo2_plugin_dir_path',  plugin_dir_path( $this->file ) );
		$this->plugin_url   = apply_filters( 'awpo2_plugin_dir_url',   plugin_dir_url ( $this->file ) );

		$this->lang_dir     = apply_filters( 'awpo2_lang_dir',     trailingslashit( $this->plugin_dir . 'languages' ) );

		$this->domain       = 'awpo2'; 
	}

	/**
	 * Setup the default hooks and actions
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return void
	 */
	private function setup_actions() {
		add_filter( 'atcf_shortcode_submit_hide', array( $this, 'shortcode_submit_hide' ) );
		add_action( 'template_redirect', array( $this, 'wepay_listener' ) );

		if ( ! is_admin() )
			return;
	}

	function wepay_listener() {
		global $edd_options, $edd_wepay;

		if ( ! is_page( $edd_options[ 'submit_page' ] ) )
			return;

		if ( ! isset( $_GET[ 'code' ] ) )
			return;

		if ( ! class_exists( 'WePay' ) )
			require ( $this->plugin_dir .  '/vendor/wepay.php' );

		$this->creds = $edd_wepay->get_api_credentials();

		if( edd_is_test_mode() )
			Wepay::useStaging( $this->creds['client_id'], $this->creds['client_secret'] );
		else
			Wepay::useProduction( $this->creds['client_id'], $this->creds['client_secret'] );

		$info = WePay::getToken( $_GET[ 'code' ], get_permalink() );
		
		if ( $info ) {
			$user         = wp_get_current_user();
			$access_token = $info->access_token;
			$wepay        = new WePay( $access_token );

			$response = $wepay->request( 'account/create/', array(
				'name'          => $user->user_email,
				'description'   => $user->user_nicename
			) );

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
		echo '<p>' . sprintf(  __( 'Before you may begin, you must first create an account on our payment processing service, <a href="http://wepay.com">WePay</a>.', 'awpo2' ) ) . '</p>';

		echo '<p>' . sprintf( __( '<a href="%s" class="button wepay-oauth-create-account">Create an account on WePay &rarr;</a>', 'awpo2' ), $this->send_to_wepay_url() ) . '</p>';
	}

	private function send_to_wepay_url() {
		global $edd_wepay;

		if ( ! class_exists( 'WePay' ) )
			require ( $this->plugin_dir .  '/vendor/wepay.php' );

		$this->creds = $edd_wepay->get_api_credentials();

		if( edd_is_test_mode() )
			Wepay::useStaging( $this->creds['client_id'], $this->creds['client_secret'] );
		else
			Wepay::useProduction( $this->creds['client_id'], $this->creds['client_secret'] );

		//$wepay = new WePay( $this->creds[ 'access_token' ] );

		$uri = WePay::getAuthorizationUri( array( 'manage_accounts', 'collect_payments', 'preapprove_payments', 'send_money' ), get_permalink() );

		return esc_url( $uri );
	}

	/**
	 * Loads the plugin language files
	 *
	 * @since Astoundify WePay oAuth2 0.1
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
 * @since Astoundify WePay oAuth2 0.1
 *
 * @return The one true Astoundify WePay oAuth2 Crowdfunding instance
 */
function awpo2() {
	return Astoundify_WePay_oAuth2::instance();
}
add_action( 'init', 'awpo2' );

/**
 * WePay fields on frontend submit and edit.
 *
 * @since CrowdFunding 1.3
 *
 * @return void
 */
function awpo2_shortcode_submit_field_wepay_creds( $atts, $campaign ) {
	$user = wp_get_current_user();

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );
	$account_uri  = $user->__get( 'wepay_account_uri' );
?>
	<p><?php printf( __( 'Funds will be sent to your <a href="%s">WePay</a> account.', 'awpo2' ), $account_uri ); ?></p>

	<input type="hidden" name="wepay_account_id" id="wepay_account_id" value="<?php echo $account_id; ?>" />
	<input type="hidden" name="wepay_access_token" id="wepay_access_token" value="<?php echo $access_token; ?>" />
<?php
}
add_action( 'atcf_shortcode_submit_fields', 'awpo2_shortcode_submit_field_wepay_creds', 105, 2 );

/**
 * PayPal Adaptive Payments field on backend.
 *
 * @since CrowdFunding 1.1
 *
 * @return void
 */
function awpo2_metabox_campaign_info_after_wepay_creds( $campaign ) {
	if ( 'auto-draft' == $campaign->data->post_status )
		return;

	$user         = get_user_by( 'id', get_post_field( 'post_author', $campaign->ID ) );

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );
?>
	<p>
		<strong><label for="wepay_account_id"><?php _e( 'WePay Account ID:', 'awpo2' ); ?></label></strong><br />
		<input type="text" name="wepay_account_id" id="wepay_account_id" class="regular-text" value="<?php echo esc_attr( $account_id ); ?>" />
	</p>

	<p>
		<strong><label for="wepay_access_token"><?php _e( 'WePay Access Token:', 'awpo2' ); ?></label></strong><br />
		<input type="text" name="wepay_access_token" id="wepay_access_token" class="regular-text" value="<?php echo esc_attr( $access_token ); ?>" />
	</p>
<?php
}
add_action( 'atcf_metabox_campaign_info_after', 'awpo2_metabox_campaign_info_after_wepay_creds' );

/**
 * Save WePay on the backend.
 *
 * @since CrowdFunding 1.3
 *
 * @return void
 */
function awpo2_metabox_save_wepay( $fields ) {
	$fields[] = 'wepay_account_id';
	$fields[] = 'wepay_access_token';

	return $fields;
}
add_filter( 'edd_metabox_fields_save', 'awpo2_metabox_save_wepay' );

/**
 * Figure out the WePay account info to send the funds to.
 *
 * @since CrowdFunding 1.3
 *
 * @return $creds
 */
function awpo2_gateway_wepay_edd_wepay_get_api_creds( $creds, $payment_id ) {
	global $edd_wepay;

	$cart_items  = edd_get_cart_contents();
	$session     = edd_get_purchase_session();
	$campaign_id = null;

	/**
	 * No cart items, check session
	 */
	if ( empty( $cart_items ) && ! empty( $session ) ) {
		$cart_items = $session[ 'downloads' ];
	} else if ( isset( $_GET[ 'edd-action' ] ) && 'charge_wepay_preapproval' == $_GET[ 'edd-action' ] && isset ( $_GET[ 'payment_id' ] ) ) {
		$cart_iterms = edd_get_payment_meta_downloads( absint( $_GET[ 'payment_id' ] ) );
	} else if ( isset( $_GET[ 'edd-action' ] ) && 'cancel_wepay_preapproval' == $_GET[ 'edd-action' ] && isset ( $_GET[ 'payment_id' ] ) ) {
		$cart_iterms = edd_get_payment_meta_downloads( absint( $_GET[ 'payment_id' ] ) );
	} else if ( $payment_id ) {
		$cart_iterms = edd_get_payment_meta_downloads( $payment_id );
	}

	if ( ! $cart_items || empty( $cart_items ) )
		return $creds;

	foreach ( $cart_items as $item ) {
		$campaign_id = $item[ 'id' ];

		break;
	}

	$campaign     = atcf_get_campaign( $campaign_id );

	if ( 0 == $campaign->ID )
		return $creds;

	$user         = get_user_by( 'id', get_post_field( 'post_author', $campaign->ID ) );

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );

	$creds[ 'access_token' ] = trim( $access_token );
	$creds[ 'account_id' ]   = trim( $account_id );

	return $creds;
}
add_filter( 'edd_wepay_get_api_creds', 'awpo2_gateway_wepay_edd_wepay_get_api_creds', 10, 2 );

/**
 * Additional WePay settings needed by Crowdfunding
 *
 * @since Appthemer Crowdfunding 1.3
 *
 * @param array $settings Existing WePay settings
 * @return array $settings Modified WePay settings
 */
function awpo2_gateway_wepay_settings( $settings ) {

	$settings[ 'wepay_app_fee' ] = array(
		'id' => 'wepay_app_fee',
		'name'  => __( 'Site Fee', 'awpo2' ),
		'desc'  => '% <span class="description">' . __( 'The percentage of each pledge amount the site keeps (on top of WePay fees) no more than 20%.', 'awpo2' ) . '</span>',
		'type'  => 'text',
		'size'  => 'small'
	);

	return $settings;
}
add_filter( 'edd_gateway_wepay_settings', 'awpo2_gateway_wepay_settings' );

/**
 * Calculate a fee to keep for the site.
 *
 * @since CrowdFunding 1.3
 *
 * @return $args
 */
function awpo2_gateway_wepay_edd_wepay_checkout_args( $args ) {
	global $edd_options;

	if ( '' == $edd_options[ 'wepay_app_fee' ] )
		return $args;

	$percent  = absint( $edd_options[ 'wepay_app_fee' ] ) / 100;
	$subtotal = edd_get_cart_subtotal();

	$fee = $subtotal * $percent;

	$args[ 'app_fee' ] = $fee;

	return $args;
}
add_filter( 'edd_wepay_checkout_args', 'awpo2_gateway_wepay_edd_wepay_checkout_args' );