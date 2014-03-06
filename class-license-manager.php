<?php

interface iYoast_License_Manager {

	public function specific_hooks();
	public function setup_auto_updater();

}

abstract class Yoast_License_Manager implements iYoast_License_Manager {


	/**
	* @const VERSION The version number of the License_Manager class
	*/
	const VERSION = 1;

	/**
	* @var string The URL of the shop running the EDD API. 
	*/
	protected $api_url;

	/**
	* @var string The item name in the EDD shop.
	*/
	protected $item_name;

	/**
	* @var string The theme slug or plugin file
	*/
	protected $slug;

	/**
	* @var string The version number of the item
	*/ 
	protected $version;

	/**
	* @var string The absolute url on which users can purchase a license
	*/
	protected $item_url;

	/**
	* @var string Absolute admin URL on which users can enter their license key.
	*/
	protected $license_page_url = '#';

	/**
	* @var string The text domain used for translating strings
	*/
	protected $text_domain = 'yoast';

	/**
	* @var string The item author
	*/ 
	protected $author = 'Yoast';

	/**
	* @var string 
	*/
	private $license_constant_name = '';

	/**
	* @var boolean True if license is defined with a constant
	*/
	private $license_constant_is_defined = false;

	/**
	* @var boolean True if remote license activation just failed
	*/
	private $remote_license_activation_failed = false;

	/**
	* @var array Array of license related options
	*/
	private $options = array();

	/**
	* @var string Used to prefix ID's, option names, etc..
	*/
	protected $prefix;

	/**
	 * Constructor
	 *
	 * @param string $api_url The url running the EDD API
	 * @param string $item_name The item name in the EDD shop
	 * @param string $slug The theme slug or plugin file
	 * @param string $version The version number of the item 
	 * @param string $item_url The absolute url on which users can purchase a license
	 * @param string $license_page Relative admin URL on which users can enter their license key
	 * @param string $text_domain The text domain used for translating strings
	 * @param stirng $author The plugin or theme author
	 */
	public function __construct( $api_url, $item_name, $slug, $version, $item_url = '', $license_page = '', $text_domain = '', $author = '' ) {

		$this->api_url = $api_url;
		$this->item_name = $item_name;
		$this->slug = $slug;
		$this->version = $version;	
		
		// set item_url or default to shop url
		if( $this->item_url !== '' ) {
			$this->item_url = $item_url;
		} else {
			$this->item_url = $this->api_url;
		}

		// set page on which users can enter their license
		$this->license_page_url = admin_url( $license_page );
				
		// set text domain, if given
		if( $text_domain !== '' ) {
			$this->text_domain = $text_domain;
		}

		// set author, if given
		if( $author !== '' ) {
			$this->author = $author;
		}

		// set prefix
		$this->prefix = sanitize_title_with_dashes( $this->item_name . '_', null, 'save' );

		// setup hooks
		$this->hooks();

		// maybe set license key from constant
		$this->maybe_set_license_key_from_constant();		
	}

	/**
	* Sets the store URL on which users can purchase, upgrade or renew their license.
	* 
	* @param string $item_url
	*/
	public function set_item_url( $item_url ) {
		$this->item_url = $item_url;
	}

	/**
	* Sets the URL on which users can enter their license key
	*
	* @param string $license_page Relative admin URL on which users can enter their license key
	*/
	public function set_license_page( $license_page ) {
		$this->license_page_url = admin_url( $license_page );
	}

	/**
	* Sets the item author
	*
	* @param string $author
	*/ 
	public function set_author( $author ) {
		$this->author = $author;
	}

	/**
	* Sets the item text domain
	*
	* @param string $text_domain
	*/
	public function set_text_domain( $text_domain ) {
		$this->text_domain = $text_domain;
	}

	/**
	* Setup hooks
	*/
	private function hooks() {
		// show admin notice if license is not active
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
		add_action( 'admin_init', array( $this, 'catch_post_request') );

		// setup item type (plugin|theme) specific hooks
		$this->specific_hooks();

		// setup the auto updater
		$this->setup_auto_updater();
	}

	/**
	* Display license specific admin notices
	*/
	public function display_admin_notices() {

		// show notice if license is invalid
		if( ! $this->license_is_valid() ) {
		?>
		<div class="error">
			<p><?php printf( __( '<b>Warning!</b> Your %s license is inactive which means you\'re missing out on updated and support! <a href="%s">Enter your license key</a> or <a href="%s" target="_blank">get a license here</a>.', $this->text_domain ), $this->item_name, $this->license_page_url, $this->item_url ); ?></p>
		</div>
		<?php
		}
	}

	/**
	* Set a notice to display in the admin area
	*
	* @param string $type error|updated
	* @param string $message The message to display
	*/
	protected function set_notice( $message, $success = true ) {
		$css_class = ( $success ) ? 'updated' : 'error';
		add_settings_error( $this->prefix . 'license', 'license-notice', $message, $css_class );
	}

	/**
	 * Remotely activate License
	 * @return boolean True if the license is now activated, false if not
	 */
	public function activate_license() {

		$result = $this->call_license_api( 'activate' );

		if( $result ) {	

			// show success notice if license is valid
			if($result->license === 'valid') {
				$message = sprintf( __( "Your %s license has been activated. You have used %d/%d activations. ", $this->text_domain ), $this->item_name, $result->site_count, $result->license_limit );
			
				// add upgrade notice if user has less than 3 activations left
				if( $result->license_limit > 0 && ( $result->license_limit - $result->site_count ) <= 3 ) {
					$message .= sprintf( __( '<a href="%s">Did you know you can upgrade your license?</a>', $this->text_domain ), $this->item_url );
				// add extend notice if license is expiring in less than 1 month
				} elseif( strtotime( $result->expires ) < strtotime( "+1 month" ) ) {
					$days_left = round( ( strtotime( $result->expires ) - strtotime( "now" ) ) / 86400 );
					$message .= sprintf( __( '<a href="%s">Your license is expiring in %d days, would you like to extend it?</a>', $this->text_domain ), $this->item_url, $days_left );
				}

				$this->set_notice( $message, true );

			} else {

				if( isset($result->error) ) {
					
					if( $result->error === 'no_activations_left' ) {
						// show notice if user is at their activation limit
						$this->set_notice( sprintf( __('You\'ve reached your activation limit. You must <a href="%s">upgrade your license</a> to use it on this site.', $this->text_domain ), $this->item_url ), false );
					} elseif( $result->error == "expired" ) {
						// show notice if the license is expired
						$this->set_notice( sprintf( __('Your license is expired. You must <a href="%s">extend your license</a> in order to use it again.', $this->text_domain ), $this->item_url ), false );
					}

				} else {
					// show a general notice if it's any other error
					$this->set_notice( __( "Failed to activate your license, your license key seems to be invalid.", $this->text_domain ), false );
				}

				$this->remote_license_activation_failed = true;
			}

			$this->set_license_status( $result->license );
		}

		return ( $this->license_is_valid() );
	}

	/**
	 * Remotely deactivate License
	 * @return boolean True if the license is now deactivated, false if not
	 */
	public function deactivate_license () {

		$result = $this->call_license_api( 'deactivate' );

		if( $result ) {
			
			// show notice if license is deactivated
			if( $result->license === 'deactivated' ) {
				$this->set_notice( sprintf( __( "Your %s license has been deactivated.", $this->text_domain ), $this->item_name ) );				
			} else {
				$this->set_notice( sprintf( __( "Failed to deactivate your %s license.", $this->text_domain ), $this->item_name ), false );		
			}

			$this->set_license_status( $result->license );
		}

		return ( $this->get_license_status() === 'deactivated' );		
	}

	/**
	* @param string $action activate|deactivate
	* @return mixed 
	*/
	protected function call_license_api( $action ) {

		// don't make a request if license key is empty
		if( $this->get_license_key() === '' ) {
			return false;
		}

		// data to send in our API request
		$api_params = array(
			'edd_action' => $action . '_license',
			'license'    => $this->get_license_key(),
			'item_name'  => urlencode( trim( $this->item_name ) )
		);

		// create api request url
		$url = add_query_arg( $api_params, $this->api_url );

		// request parameters
		$request_params = array( 
			'timeout' => 20, 
			'sslverify' => false, 
			'headers' => array( 'Accept-Encoding' => '*' ) 
		);

		// fire request to shop
		$response = wp_remote_get( $url, $request_params );

		// make sure response came back okay
		if( is_wp_error( $response ) ) {

			// set notice, useful for debugging why remote requests are failing
			$this->set_notice( sprintf( __( "Request error: %s", $this->text_domain ), $response->get_error_message() ), false );

			return false;
		}

		// decode api response
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		return $license_data;
	}

	/**
	* Set the license status
	*
	* @param string $license_status
	*/
	public function set_license_status( $license_status ) {
		$this->set_option( 'status', $license_status );
	}

	/**
	* Get the license status
	*
	* @return string $license_status;
	*/
	public function get_license_status() {
		$license_status = $this->get_option( 'status' );
		return trim( $license_status );
	}

	/**
	* Set the license key
	*
	* @param string $license_key 
	*/
	public function set_license_key( $license_key ) {
		$this->set_option( 'key', $license_key );
	}

	/**
	* Gets the license key from constant or option
	*
	* @return string $license_key
	*/
	public function get_license_key() {
		$license_key = $this->get_option( 'key' );
		return trim( $license_key );
	}

	/**
	* Checks whether the license status is active
	*
	* @return boolean True if license is active
	*/
	public function license_is_valid() {
		return ( $this->get_license_status() === 'valid' );
	}

	/**
	* Get all license related options
	*
	* @return array Array of license options
	*/
	protected function get_options() {

		// create option name
		$option_name = $this->prefix . 'license';

		// get array of options from db
		$options = get_option( $option_name, array( ) );

		// setup array of defaults
		$defaults = array(
			'key' => '',
			'status' => ''
		);

		// merge options with defaults
		$this->options = wp_parse_args( $options, $defaults );

		return $this->options;
	}

	/**
	* Set license related options
	*
	* @param array $options Array of new license options
	*/
	protected function set_options( array $options ) {
		// create option name
		$option_name = $this->prefix . 'license';

		// update db
		update_option( $option_name, $options );
	}

	/**
	* Gets a license related option
	*
	* @param string $name The option name
	* @return mixed The option value
	*/
	protected function get_option( $name ) {
		$options = $this->get_options();
		return $options[ $name ];
	}

	/**
	* Set a license related option
	*
	* @param string $name The option name
	* @param mixed $value The option value
	*/
	protected function set_option( $name, $value ) {
		// get options
		$options = $this->get_options();

		// update option
		$options[ $name ] = $value;

		// save options
		$this->set_options( $options );
	}

	/**
	* Show a form where users can enter their license key
	*
	* @param boolean $embedded Boolean indicating whether this form is embedded in another form?
	*/
	public function show_license_form( $embedded = true ) {

		$key_name = $this->prefix . 'license_key';
		$nonce_name = $this->prefix . 'license_nonce';
		$action_name = $this->prefix . 'license_action';

		
		$visible_license_key = $this->get_license_key();	

		// obfuscate license key
		$obfuscate = ( strlen( $this->get_license_key() ) > 5 && ( $this->license_is_valid() || ! $this->remote_license_activation_failed ) );

		if($obfuscate) {
			$visible_license_key = str_repeat('*', strlen( $this->get_license_key() ) - 4) . substr( $this->get_license_key(), -4 );
		}

		// make license key readonly when license key is valid or license is defined with a constant
		$readonly = ( $this->license_is_valid() || $this->license_constant_is_defined );
		
		require_once dirname( __FILE__ ) . '/views/form.php';		

		// enqueue script in the footer
		add_action( 'admin_footer', array( $this, 'output_script'), 99 );
	}

	/**
	* Check if the license form has been submitted
	*/
	public function catch_post_request() {

		$name = $this->prefix . 'license_key';

		// check if license key was posted and not empty
		if( ! isset( $_POST[$name] ) ) {
			return;
		}

		// run a quick security check
		$nonce_name = $this->prefix . 'license_nonce';

		if ( ! check_admin_referer( $nonce_name, $nonce_name ) ) {
			return; 
		}

		// @TODO: check for user cap?

		// get key from posted value
		$license_key = $_POST[$name];

		// check if license key doesn't accidentally contain asterisks
		if( strstr($license_key, '*') === false ) {

			// sanitize key
			$license_key = trim( sanitize_key( $_POST[$name] ) );

			// save license key
			$this->set_license_key( $license_key );
		}

		// does user have an activated valid license
		if( ! $this->license_is_valid() ) {

			// try to auto-activate license
			return $this->activate_license();	

		}	

		$action_name = $this->prefix . 'license_action';

		// was one of the action buttons clicked?
		if( isset( $_POST[ $action_name ] ) ) {
			
			$action = trim( $_POST[ $action_name ] );

			switch($action) {

				case 'activate':
					return $this->activate_license();
					break;

				case 'deactivate':
					return $this->deactivate_license();
					break;
			}

		}
		
	}

	/**
	* Output the script containing the YoastLicenseManager JS Object
	*
	* This takes care of disabling the 'activate' and 'deactivate' buttons
	*/
	public function output_script() {
		require_once dirname( __FILE__ ) . '/views/script.php';
	}

	/**
	* Set the constant used to define the license
	*
	* @param string $license_constant_name The license constant name
	*/
	public function set_license_constant_name( $license_constant_name ) {
		$this->license_constant_name = trim( $license_constant_name );
		$this->maybe_set_license_key_from_constant();
	}

	/**
	* Maybe set license key from a defined constant
	*/
	private function maybe_set_license_key_from_constant( ) {
		
		if( empty( $this->license_constant_name ) ) {
			// generate license constant name
			$this->license_constant_name = strtoupper( str_replace( array(' ', '-' ), '', sanitize_key( $this->item_name ) ) ) . '_LICENSE';
		}

		// set license key from constant
		if( defined( $this->license_constant_name ) ) {

			$license_constant_value = constant( $this->license_constant_name );

			// update license key value with value of constant
			if( $this->get_license_key() !== $license_constant_value ) {
				$this->set_license_key( $license_constant_value );
			}
			
			$this->license_constant_is_defined = true;
		}
	}

}