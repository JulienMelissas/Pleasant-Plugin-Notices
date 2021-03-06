<?php

// TODO license & credits

if ( ! defined('ABSPATH') ) die;

class Pleasant_Plugin_Notices {

	// class instance
	private static $instance = null;

	// WordPress plugin thickbox url, used on the plugins page
	private $thickbox_url_pattern = 'plugin-install.php?tab=plugin-information&plugin=%s&TB_iframe=true&width=600&height=550';

	// Anchor tag with thickbox properties
	private $thickbox_link_pattern = '<a href="%1$s" class="thickbox">%2$s</a>';

	// non-thickbox anchor tag
	private $link_pattern = '<a href="%1$s" target="_blank">%2$s</a>';

	// The notice prefix
	private $notice_prefix = '';

	// Generic notice text
	private $notice_text_pattern = '';

	// wp option_table option name
	private $option_name = 'pleasant_plugin_notices';

	// variable used to pass dismiss action
	private $dismiss_url_var = 'ppn_dismiss';

	/*
	 * almost everything defaults to false for easy logic when parsing
	 * the exception being 'public', since true is the most-likely use-case
	 */ 
	private $default_dependency = array(
		'name'        => false,
		'plugin_url'  => false,
		'public'      => true,
		'notice'      => false,
		'plugin_data' => false,
	);

	/* 
	 * the wp_kses allowed_html arguments
	 * for when an dependency provides a custom notice
	 */ 
	private $notice_allowed_html = array(
		'a' => array(
			'href'  => array(),
			'title' => array(),
			'class' => array(),
			'target' => array(),
		),
		'br'     => array(),
		'em'     => array(),
		'strong' => array(),
	);

	/**
	 * Get the library's instance.
	 * Using singleton pattern so the library's construction can only happen once
	 *
	 * @return null|Pleasant_Plugin_Notices
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into WordPress
	 */
	private function __construct(){

		$this->notice_prefix = __( 'Theme dependency notice', 'pleasant-plugin-notices' );

		$this->notice_text_pattern = __( 'This theme requires the %s plugin', 'pleasant-plugin-notices' );
		
		// If the current user can install / deactivate plugins display notices
		if ( current_user_can( 'install_plugins' ) ) {
			add_action( 'admin_notices', array( $this, 'check_dependencies' ) );
		}
	}

	/**
	 * Gather all dependencies
	 *
	 * @return mixed|void
	 */
	function get_dependencies() {

		// The filter where all plugins needed will be passed
		$dependencies = apply_filters( 'pleasant_plugin_notices', array() );
		$notices_data = get_option( $this->option_name, array() );

		// check for dismissal
		if ( isset( $_GET[ $this->dismiss_url_var ] ) ) {
			$notices_data[ $_GET[ $this->dismiss_url_var ] ]['dismissed'] = true;
		}

		// save our notices data
		update_option( $this->option_name, $notices_data );

		// loop through dependencies and preprocess them
		foreach ( $dependencies as $slug => $dependency ) {
			// merge with default values
			$dependency = wp_parse_args( $dependency, $this->default_dependency );

			// remember plugin data within the dependency for later
			$dependency['plugin_data'] = $this->get_plugin_data_from_slug( $slug );
			$dependency['slug'] = $slug;

			// determine if this dependency has been dismissed already
			$dependency['dismissed'] = false;

			if ( isset( $notices_data[ $slug ] ) && $notices_data[ $slug ]['dismissed'] ) {
				$dependency['dismissed'] = true;
			}

			// get the dependency name if not defined
			if ( ! $dependency['name'] ) {
				// plugin_data takes precedence
				// default to slug
				$dependency['name'] = ( $dependency['plugin_data'] ) ? $dependency['plugin_data']['Name'] : $dependency['slug'];
			}

			$dependencies[ $slug ] = $dependency;
		}

		return $dependencies;
	}

	/**
	 * Process dependencies and output any needed notices
	 */
	function check_dependencies() {

		$dependencies = $this->get_dependencies();

		// loop through dependencies and process them as notices
		foreach ( $dependencies as $slug => $dependency ) {

			// if the plugin is active, do not display a notice
			if ( $dependency['plugin_data'] && isset( $dependency['plugin_data']['plugin_file'] ) && is_plugin_active( $dependency['plugin_data']['plugin_file'] ) ) {
				continue;
			}

			// if notice has been dismissed, do not show it
			if ( $dependency['dismissed'] ) {
				continue;
			}

			// make a notice array that we'll ultimately pass along to the output method
			$notice = array(
			  'text' => '',
			  'dependency' => $dependency,
			  'dismiss_url' => $this->make_dismiss_url( $slug ),
			);

			// a custom notice overrides all other possible output
			if ( $dependency['notice'] ){
				$notice['text'] = wp_kses( $dependency['notice'], $this->notice_allowed_html );
			}

			// otherwise, parse the dependency and create a notice_text
			else {
				// the link in the notice which refers to the thickbox
				if ( $dependency['public'] ) {
					$link_string = $this->make_link( $dependency );
				}
				// otherwise the dependency isn't publicly available, and has no link
				else {
					$link_string = $dependency['name'];
				}

				// get the notice text
				$notice['text'] = sprintf( __( $this->notice_text_pattern ), $link_string );
			}

			$this->output_notice( $notice );
		}
	}

	/**
	 * Get the WordPress meta data for a plugin using its slug (directory)
	 *
	 * @param $plugin_slug
	 * @return bool|mixed
	 */
	function get_plugin_data_from_slug( $plugin_slug ) {

		if ( ! function_exists('get_plugins') ){
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// default to false
		$plugin_data = FALSE;

		// get all plugins within the plugin-slug directory
		$plugins = get_plugins( '/' . $plugin_slug ); // Retrieve all plugins.

		if  ( ! empty( $plugins ) ) {
			// the keys of the returned array are the plugin file names
			$plugin_keys = array_keys( $plugins );

			// get the the first plugin returned
			$plugin_data = array_shift( $plugins );

			// construct the plugin_file from the plugin-slug (directory)
			// and the plugin's key (file name);
			$plugin_data['plugin_file'] = $plugin_slug . '/' . $plugin_keys[0];
		}

		return $plugin_data;
	}


	/**
	 * Create a WordPress-admin-style thickbox popup link.
	 *
	 * @param $dependency
	 * @return string
	 */
	function make_link( $dependency ) {
		/** 
		 * get the url
		 * if plugin_url, we can't use thickbox
		 */ 
		if ( $dependency['plugin_url'] ) {
			$link_url = $dependency['plugin_url'];
			$link_pattern = $this->link_pattern;
		}
		// no url, make a thickbox like the admin plugins page uses
		else {
			$link_url = $this->make_thickbox_url( $dependency['slug'] );
			$link_pattern = $this->thickbox_link_pattern;
		}

		return sprintf( $link_pattern, esc_url( $link_url ), $dependency['name'] );
	}


	/**
	 * Make the appropriate thickbox url
	 *
	 * @param $plugin_slug
	 * @return string|void
	 */
	function make_thickbox_url( $plugin_slug ) {
		// the URL for the thickbox
		$thickbox_url = sprintf( $this->thickbox_url_pattern, $plugin_slug );

		if ( is_multisite() ) {
			$url = network_admin_url( $thickbox_url );
		}
		else {
			$url = admin_url( $thickbox_url );
		}

		return $url;
	}

	/**
	 * Create a link for dismissing an individual notice
	 *
	 * @param $plugin_slug
	 * @return string
	 */
	function make_dismiss_url( $plugin_slug ){
		global $pagenow;

		// retain current page url.uri.query
		$query = array();
		parse_str( $_SERVER['QUERY_STRING'], $query );

		// add our query var
		$query[ $this->dismiss_url_var ] = $plugin_slug;

		// make a relative uri
		$dismiss_url = $pagenow . '?' . http_build_query( $query );

		// convert relative uri into admin url
		if ( is_multisite() ) {
			$url = network_admin_url( $dismiss_url );
		}
		else {
			$url = admin_url( $dismiss_url );
		}

		return $url;
	}

	/**
	* Output HTML notice
	*
	* @param $notice
	*/
	function output_notice( $notice ) {
		?>
	    <div class="error">
		    <a href="<?php echo esc_attr( $notice['dismiss_url'] ); ?>">dismiss</a>
		    <p><strong><?php echo $this->notice_prefix; ?>: </strong><?php echo $notice['text']; ?></p>
	    </div>
		<?php
	}

} // end class Pleasant_Plugin_Notices

// initialize the library
Pleasant_Plugin_Notices::get_instance();
