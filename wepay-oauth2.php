<?php
/**
 * Plugin Name: Easy Digital Downloads - WePay oAuth2 for Crowdfunding
 * Plugin URI:  https://github.com/astoundify
 * Description: Enable users to create accounts on WePay automatically.
 * Author:      Astoundify
 * Author URI:  http://astoundify.com
 * Version:     0.3
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

	/**
	 * @var $creds
	 */
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
		add_filter( 'atcf_shortcode_submit_hide', array( $this, 'shortcode_submit_hide' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'wepay_listener' ) );

		if ( ! is_admin() )
			return;


		add_filter( 'edd_gateway_wepay_settings', array( $this, 'gateway_settings' ) );

		add_action( 'edit_user_profile', array( $this, 'profile_user_meta' ) );
		add_action( 'show_user_profile', array( $this, 'profile_user_meta' ) );
		add_action( 'personal_options_update', array( $this, 'profile_user_meta_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'profile_user_meta_update' ) );
	}

	/**
	 * Additional WePay settings needed by Crowdfunding
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @param array $settings Existing WePay settings
	 * @return array $settings Modified WePay settings
	 */
	function gateway_settings( $settings ) {
		$settings[ 'wepay_app_fee' ] = array(
			'id' => 'wepay_app_fee',
			'name'  => __( 'Site Fee', 'awpo2' ),
			'desc'  => '% <span class="description">' . __( 'The percentage of each pledge amount the site keeps (no more than 20%)', 'awpo2' ) . '</span>',
			'type'  => 'text',
			'size'  => 'small'
		);

		$settings[ 'wepay_flexible_fee' ] = array(
			'id'   => 'wepay_flexible_fee',
			'name' => __( 'Additional Flexible Fee', 'epap' ),
			'desc' => __( '%. <span class="description">If a campaign is flexible, increase commission by this percent. Total can not be more than 20%</span>', 'atcf' ),
			'type' => 'text',
			'size' => 'small'
		);

		return $settings;
	}

	/**
	 * When coming back from WePay, add the newly created
	 * tokens to the user meta.
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return void
	 */
	function wepay_listener() {
		global $edd_options, $edd_wepay;

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

	/**
	 * If the current user does not have any stored WePay information,
	 * show them the way to WePay and hide the submission form.
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return boolean
	 */
	public function shortcode_submit_hide( $hidden, $atts ) {
		$user = wp_get_current_user();

		if ( $atts[ 'editing' ] )
			return false;

		if ( ! $user->wepay_account_id ) {
			add_action( 'atcf_shortcode_submit_hidden', array( $this, 'send_to_wepay' ) );

			return true;
		}

		return false;
	}

	/**
	 * Create a link that sends them to WePay to create an account.
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return void
	 */
	public function send_to_wepay() {
		echo '<p>' . sprintf(  __( 'Before you may begin, you must first create an account on our payment processing service, <a href="http://wepay.com">WePay</a>.', 'awpo2' ) ) . '</p>';

		echo '<p>' . sprintf( '<a href="%s" class="button wepay-oauth-create-account">', $this->send_to_wepay_url() ) . __( 'Create an account on WePay &rarr;', 'awpo2' ) . '</a></p>';
	}

	/**
	 * Create the proper URL for sending to WePay
	 *
	 * @since Astoundify WePay oAuth2 0.1
	 *
	 * @return string $uri
	 */
	public function send_to_wepay_url( $redirect = null ) {
		global $edd_wepay;

		if ( ! class_exists( 'WePay' ) )
			require ( $this->plugin_dir .  '/vendor/wepay.php' );

		$this->creds = $edd_wepay->get_api_credentials();

		if( edd_is_test_mode() )
			Wepay::useStaging( $this->creds['client_id'], $this->creds['client_secret'] );
		else
			Wepay::useProduction( $this->creds['client_id'], $this->creds['client_secret'] );

		if ( ! $redirect )
			$redirect = get_permalink();

		$uri = WePay::getAuthorizationUri( array( 'manage_accounts', 'collect_payments', 'preapprove_payments', 'send_money' ), $redirect );

		return esc_url( $uri );
	}

	/**
	 * Manually set the WePay information.
	 *
	 * @since Astoundify WePay oAuth2 0.2
	 *
	 * @param WP_User $profileuser User data
	 * @return bool Always false
	 */
	function profile_user_meta( $profileuser ) {
		if ( ! current_user_can( 'edit_user', $profileuser->ID ) )
			return;
		?>

		<h3><?php esc_html_e( 'WePay Account', 'awpo2' ); ?></h3>

		<table class="form-table">
			<tbody>
				<tr>
					<th><label for="wepay_access_token"><?php esc_html_e( 'Access Token', 'awpo2' ); ?></label></th>
					<td>
						<input type="text" name="wepay_access_token" class="regular-text code" value="<?php echo esc_attr( $profileuser->__get( 'wepay_access_token' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th><label for="wepay_account_id"><?php esc_html_e( 'Account ID', 'awpo2' ); ?></label></th>
					<td>
						<input type="text" name="wepay_account_id" class="regular-text code" value="<?php echo esc_attr( $profileuser->__get( 'wepay_account_id' ) ); ?>" />
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Manually set the WePay information.
	 *
	 * @since Astoundify WePay oAuth2 0.2
	 *
	 * @param WP_User $profileuser User data
	 * @return bool Always false
	 */
	function profile_user_meta_update( $profileuser_id ) {
		if ( ! current_user_can( 'edit_user', $profileuser_id ) )
			return;
		
		$access_token = esc_attr( $_POST[ 'wepay_access_token' ] );
		$account_id   = esc_attr( $_POST[ 'wepay_account_id' ] );

		update_user_meta( $profileuser_id, 'wepay_access_token', $access_token );
		update_user_meta( $profileuser_id, 'wepay_account_id', $account_id );
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
 * @since Astoundify WePay oAuth2 0.1
 *
 * @return void
 */
function awpo2_shortcode_submit_field_wepay_creds( $fields ) {
	$user = wp_get_current_user();

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );
	$account_uri  = $user->__get( 'wepay_account_uri' );

	$fields[ 'wepay_acccount_uri' ] = array(
		'label'       => sprintf( __( 'Funds will be sent to your <a href="%s">WePay</a> account.', 'awpo2' ), $account_uri ),
		'default'     => $account_uri,
		'type'        => 'hidden',
		'editable'    => false,
		'placeholder' => null,
		'required'    => false,
		'priority'    => 36
	);

	$fields[ 'wepay_account_id' ] = array(
		'label'       => null,
		'default'     => $account_id,
		'type'        => 'hidden',
		'editable'    => false,
		'placeholder' => null,
		'required'    => false,
		'priority'    => 36
	);

	$fields[ 'wepay_access_token' ] = array(
		'label'       => null,
		'default'     => $access_token,
		'type'        => 'hidden',
		'editable'    => false,
		'placeholder' => null,
		'required'    => false,
		'priority'    => 36
	);

	return $fields;
}
add_filter( 'atcf_shortcode_submit_fields', 'awpo2_shortcode_submit_field_wepay_creds' );

/**
 * WePay field on backend.
 *
 * @since Astoundify WePay oAuth2 0.1
 *
 * @return void
 */
function awpo2_metabox_campaign_info_after_wepay_creds( $campaign ) {
	if ( 'auto-draft' == $campaign->data->post_status )
		return;

	$user         = get_user_by( 'id', get_post_field( 'post_author', $campaign->ID ) );

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );
	$account_uri  = $user->__get( 'wepay_account_uri' );
?>
	<p><strong><?php printf( __( 'Funds will be sent to %s <a href="%s">WePay</a> account.', 'awpo2' ), $user->user_email, $account_uri ); ?></strong></p>

	<p>
		<strong><label for="wepay_account_id"><?php _e( 'WePay Account ID:', 'awpo2' ); ?></label></strong><br />
		<input type="text" name="wepay_account_id" id="wepay_account_id" class="regular-text" value="<?php echo esc_attr( $account_id ); ?>" readonly="true" />
	</p>

	<p>
		<strong><label for="wepay_access_token"><?php _e( 'WePay Access Token:', 'awpo2' ); ?></label></strong><br />
		<input type="text" name="wepay_access_token" id="wepay_access_token" class="regular-text" value="<?php echo esc_attr( $access_token ); ?>" readonly="true" />
	</p>
<?php
}
add_action( 'atcf_metabox_campaign_info_after', 'awpo2_metabox_campaign_info_after_wepay_creds' );

/**
 * Add a link to create an account on WePay, or link to their current one.
 *
 * @since Astoundify WePay oAuth2 0.2
 *
 * @return void
 */
function awpo2_atcf_shortcode_profile() {
	$awpo2 = awpo2();
	$user  = wp_get_current_user();
?>
	<h3 class="atcf-profile-section wepay"><?php _e( 'WePay Account', 'awpo2' ); ?></h3>

	<?php if ( ! $user->__isset( 'wepay_account_id' ) ) : ?>

	<p><?php printf( '<a href="%s" class="button wepay-oauth-create-account">', $awpo2->send_to_wepay_url() ); ?><?php _e( 'Create an account on WePay &rarr;', 'awpo2' ); ?></a></p>

	<?php else : ?>
		
		<p><?php printf( __( 'Funds will be sent to your <a href="%s">WePay</a> account.', 'awpo2' ), esc_url( $user->__get( 'wepay_account_uri' ) ) ); ?></p>

	<?php endif; ?>
<?php
}
add_action( 'atcf_shortcode_profile', 'awpo2_atcf_shortcode_profile' );

/**
 * If the profile page has been redirected from WePay, show a message.
 *
 * @since Astoundify WePay oAuth2 0.2
 *
 * @return void
 */
function awpo2_message_atcf_shortcode_profile() {
	if ( ! isset( $_GET[ 'code' ] ) )
		return;
	?>
		<p class="edd_success"><?php echo esc_attr( __( 'Your WePay account has been associated with your account.', 'awpo2' ) ); ?></p>	
	<?php
}
add_action( 'atcf_shortcode_profile', 'awpo2_message_atcf_shortcode_profile', 1 );

/**
 * Figure out the WePay account info to send the funds to.
 *
 * @since Astoundify WePay oAuth2 0.1
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
		$meta = edd_get_payment_meta( $_GET[ 'payment_id' ] );
		$cart_items = maybe_unserialize( $meta[ 'downloads' ] );
	} else if ( isset( $_GET[ 'edd-action' ] ) && 'cancel_wepay_preapproval' == $_GET[ 'edd-action' ] && isset ( $_GET[ 'payment_id' ] ) ) {
		$meta = edd_get_payment_meta( $_GET[ 'payment_id' ] );
		$cart_items = maybe_unserialize( $meta[ 'downloads' ] );
	} else if ( $payment_id ) {
		$meta = edd_get_payment_meta( $payment_id );
		$cart_items = maybe_unserialize( $meta[ 'downloads' ] );
	}

	if ( ! $cart_items || empty( $cart_items ) )
		return $creds;

	foreach ( $cart_items as $item ) {
		$campaign_id = $item[ 'id' ];

		break;
	}

	if ( 0 == get_post( $campaign_id )->ID )
		return $creds;

	$campaign     = atcf_get_campaign( $campaign_id );

	$user         = get_user_by( 'id', get_post_field( 'post_author', $campaign->ID ) );

	$access_token = $user->__get( 'wepay_access_token' );
	$account_id   = $user->__get( 'wepay_account_id' );

	$creds[ 'access_token' ] = trim( $access_token );
	$creds[ 'account_id' ]   = trim( $account_id );

	//wp_die( print_r( $creds ) );

	return $creds;
}
add_filter( 'edd_wepay_get_api_creds', 'awpo2_gateway_wepay_edd_wepay_get_api_creds', 10, 2 );

/**
 * Calculate a fee to keep for the site.
 *
 * @since Astoundify WePay oAuth2 0.1
 *
 * @return $args
 */
function awpo2_gateway_wepay_edd_wepay_checkout_args( $args ) {
	global $edd_options;

	if ( '' == $edd_options[ 'wepay_app_fee' ] )
		return $args;

	$fee = absint( $edd_options[ 'wepay_app_fee' ] );

	if ( '' != $edd_optinos[ 'wepay_flexible_fee' ] ) {
		$fee = $fee + $edd_options[ 'wepay_flexible_fee' ];
	}

	$percent  = $fee / 100;
	$subtotal = edd_get_cart_subtotal();

	$fee = $subtotal * $percent;

	$args[ 'app_fee' ] = $fee;

	return $args;
}
add_filter( 'edd_wepay_checkout_args', 'awpo2_gateway_wepay_edd_wepay_checkout_args' );