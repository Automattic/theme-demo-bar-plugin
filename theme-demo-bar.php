<?php
/**
 * Plugin Name:     Theme Demo Bar
 * Plugin URI:      https://github.com/Automattic/theme-demo-bar-plugin
 * Description:     Adds the demo bar on atomic theme demo sites.
 * Author:          Valter Lorran
 * Author URI:      https://github.com/Automattic/theme-demo-bar-plugin
 * Text Domain:     theme-demo-bar
 * Domain Path:     /languages
 * Version:         0.2.0
 *
 * @package         Theme_Demo_Bar
 */

// Load Dependencies
require_once __DIR__ . '/feature-plugins/class-headstart-generate-annotation-atomic.php';
require_once __DIR__ . '/feature-plugins/class-headstart-generate-annotation-simple.php';
require_once __DIR__ . '/feature-plugins/class-headstart-generate-annotation-page.php';

/**
 * Class Theme_Demo_Sites_Display
 */
class Theme_Demo_Sites_Display {
	/**
	 * Singleton instance.
	 *
	 * @var Theme_Demo_Sites_Display
	 */
	private static $instance = null;

	/**
	 * Theme data information, containing the cost and is_retired status.
	 *
	 * @var null|object
	 */
	private $premium_theme_data = null;

	/**
	 * Silence is golden
	 */
	private function __construct() {}

	/**
	 * Ooh, a singleton
	 *
	 * @uses self::setup
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Get premium theme data for the specified theme.
	 * The data structure is returned from the wpcom/v2/themes/{$stylesheet}/premium-details endpoint.
	 *
	 * @param string $stylesheet Theme slug.
	 * @return object|bool Returns false when the theme is not premium or we fail to look up the data, and a premium theme data object otherwise.
	 */
	private function get_premium_theme_data( $stylesheet ) {
		if ( null !== $this->premium_theme_data ) {
			return $this->premium_theme_data;
		}

		$country_code = $_SERVER['GEOIP_COUNTRY_CODE'] ?? 'US';

		$transient_key     = "atomic-premium-theme-data:$country_code:$stylesheet";
		$cached_theme_data = get_transient( $transient_key );
		if ( is_object( $cached_theme_data ) ) {
			return $cached_theme_data;
		}

		$response = Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
			"themes/{$stylesheet}/premium-details?country_code={$country_code}",
			'2',
			array(
				'method' => 'GET',
			),
			null,
			'wpcom'
		);

		if ( is_wp_error( $response ) || 200 !== $response['response']['code'] || ! isset( $response['body'] ) ) {
			return false;
		}

		$this->premium_theme_data = json_decode( wp_remote_retrieve_body( $response ) );
		set_transient( $transient_key, $this->premium_theme_data, 5 * MINUTE_IN_SECONDS );

		return $this->premium_theme_data;
	}

	/**
	 * Register actions based on use case
	 *
	 * @uses add_action
	 * @return void
	 */
	protected function setup() {
		if ( isset( $_GET['demo'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action( 'init', array( $this, 'external_demo_init' ), 30 );
		} else {
			add_action( 'init', array( $this, 'activation_bar_init' ), 30 );
		}
	}

	/**
	 * Check if the current blog is eligible for the demo bar.
	 *
	 * @param int $id site id
	 * @return bool
	 */
	protected function demo_site_supports_activation_bar( $id ) {
		// Sanitize blog ID
		$id = (int) $id;

		// Certain demo sites aren't eligible, usually due to visual conflicts between the demo bar and the theme
		$ineligible = array(
			/**
			 * Example of how to add a demo site to the ineligible list:
			 * blog_id, // url of demo site - explanation of why it's ineligible
			 */
		);

		// If the theme is retired, the demo site shouldn't display the activation bar, since the theme probably isn't available to the viewer
		$current_theme = get_option( 'stylesheet' );

		if ( $current_theme && $this->wpcom_is_retired_theme( $current_theme ) ) {
			return false;
		}

		return ! in_array( $id, $ineligible, true );
	}

	/**
	 * Checks if the theme is retired.
	 *
	 * @param string $stylesheet stylesheet slug
	 * @return bool
	 */
	private function wpcom_is_retired_theme( $stylesheet ) {
		$premium_theme_data = $this->get_premium_theme_data( $stylesheet );
		return $premium_theme_data && $premium_theme_data->is_retired;
	}

	/**
	 * Prepare activation bar if current site is a theme demo site
	 *
	 * @global $blog_id
	 * @uses is_customize_preview
	 * @uses jetpack_is_mobile
	 * @uses add_action
	 * @uses add_filter
	 * @uses wp_enqueue_script
	 * @uses plugins_url
	 * @uses wp_enqueue_style
	 * @return null
	 */
	public function activation_bar_init() {
		// Make sure we don't have the cookie law widget when we are on a theme demo site
		unregister_widget( 'EU_Cookie_Law_Widget' );

		// Don't display activation bar in Customizer
		if ( is_customize_preview() ) {
			return;
		}

		// Don't display activation bar in Theme Preview
		if ( isset( $_GET['theme_preview'] ) && $_GET['theme_preview'] === 'true' ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Load activation bar code, if site is eligible
		global $blog_id;

		if ( ! jetpack_is_mobile() && $this->demo_site_supports_activation_bar( $blog_id ) ) {
			add_action( 'wp_footer', array( $this, 'activation_bar_init_widget' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'activation_bar_fonts' ) );
			add_filter( 'body_class', array( $this, 'activation_bar_body_classes' ) );

			wp_enqueue_style( 'demosite-activate', plugins_url( 'theme-demo-bar.css', __FILE__ ), array(), WPCOMSH_VERSION );
		}
	}

	/**
	 * Get the premium theme slug
	 *
	 * @param string $stylesheet stylesheet slug
	 * @return string
	 */
	private function get_theme_slug( $stylesheet ) {
		return "premium/{$stylesheet}";
	}

	/**
	 * Check if the theme is premium for a given stylesheet
	 *
	 * @param string $stylesheet stylesheet slug
	 * @return bool
	 */
	private function is_premium_theme( $stylesheet ) {
		$premium_theme_data = $this->get_premium_theme_data( $stylesheet );
		if ( ! $premium_theme_data ) {
			return false;
		}

		return true;
	}

	/**
	 * Output the trigger, widget, and form/button
	 *
	 * @global $current_blog
	 * @uses wp_get_theme
	 * @uses __
	 * @uses is_premium_theme
	 * @uses _e
	 * @uses esc_attr
	 * @uses esc_html
	 * @uses wp_nonce_field
	 * @uses add_query_arg
	 * @uses esc_url
	 * @action wp_footer
	 * @return void
	 */
	public function activation_bar_init_widget() {
		global $current_blog;

		// setup $theme object
		$theme = wp_get_theme();

		// Signup URL with theme and source parameters
		// 'theme' and 'demo-blog'
		$url = add_query_arg(
			array(
				'theme'   => rawurlencode( $this->get_theme_slug( $theme->stylesheet ) ),
				'premium' => $this->is_premium_theme( $theme->stylesheet ) ? 'true' : false,
				'ref'     => 'demo-blog',
			),
			'https://wordpress.com/start/with-theme'
		);

		$title    = __( 'Start your WordPress.com site with this theme.' );
		$tab_text = __( 'Sign Up Now' );
		$text     = __( 'Start a site with this theme.' );
		$button   = __( 'Activate' );

		if ( $this->is_premium_theme( $theme->stylesheet ) ) {
			$tab_text = __( 'Purchase' );
			$text     = __( 'Create a site and purchase this theme to start using it now.' );
			$button   = __( 'Purchase &amp; Activate' );
		}

		// if the site is premium, set $theme_price
		$theme_price = '';

		$premium_theme_data = $this->get_premium_theme_data( $theme->stylesheet );

		if ( $premium_theme_data && $this->is_premium_theme( $theme->stylesheet ) && $theme->is_allowed( 'network' ) ) {
			$theme_price = '<span class="theme-price">' . $premium_theme_data->cost . '</span>';
		}
		?>

		<div id="demosite-activate-wrap" class="demosite-activate">

			<header class="demosite-header">
				<span class="demosite-activate-logo">
					<?php $this->render_wordpress_logo(); ?>
				</span>
				<p class="demosite-tagline"><?php echo esc_html( $title ); ?></p>
				<a class="demosite-activate-trigger" href="<?php echo esc_url( $url ); ?>">
					<?php
						echo $tab_text; //phpcs:ignore
						echo $theme_price; //phpcs:ignore
					?>
				</a>
				<a class="demosite-activate-cta-arrow" href="<?php echo esc_url( $url ); ?>">
					<?php $this->render_cta_arrow(); ?>
				</a>
			</header>
		</div><!-- #demosite-activate-wrap -->
		<?php
	}

	/**
	 * Adds demo-site class array of body classes
	 *
	 * @param array $classes array of body classes
	 * @filter body_class
	 * @return array
	 */
	public function activation_bar_body_classes( $classes ) {
		$classes[] = 'demo-site';

		return $classes;
	}

	/**
	 * Enqueue Google Fonts
	 *
	 * @uses is_ssl
	 * @uses wp_enqueue_style
	 * @uses add_query_arg
	 * @action wp_enqueue_scripts
	 * @return void
	 */
	public function activation_bar_fonts() {
		$args = array(
			'family' => 'Open+Sans:300,300italic,400,400italic,600,600italic,700,700italic',
			'subset' => 'latin,latin-ext',
		);

		wp_enqueue_style( 'demosites-open-sans', add_query_arg( $args, 'https://fonts.googleapis.com/css' ), array(), WPCOMSH_VERSION );
	}

	/**
	 * Capture page contents and pass to custom output buffer handler
	 *
	 * @action init
	 * @return void
	 */
	public function external_demo_init() {
		ob_start( array( $this, 'external_demo_output_buffer' ) );
	}

	/**
	 * Modify all links to include the `demo` query string. This is to ensure that all
	 * links on the demo site contains the `demo` query string.
	 *
	 * @param string $output output buffer
	 * @uses home_url
	 * @uses untrailingslashit
	 * @uses add_query_arg
	 * @return string
	 */
	public function external_demo_output_buffer( $output ) {
		$home_url = home_url();
		$home_url = untrailingslashit( $home_url );

		/**
		 * Matches all links starting with the home URL in the output buffer.
		 *
		 * @link https://opengrok.a8c.com/source/xref/wpcom/wp-content/blog-plugins/theme-demo-sites-display.php?r=0eb79aeb&mo=20918&fi=602#606 Also uses this regex.
		 */
		if ( preg_match_all( '#<a[^>]+href=["|\'](' . $home_url . '[^"|\']*)["|\'][^>]*>#', $output, $tags ) ) {
			foreach ( $tags[0] as $key => $tag ) {
				if ( false !== strpos( $tag, 'wp-admin' ) ) {
					continue;
				}

				// Tries to replace the HTML code equivalent of the ampersand(&) with the actual ampersand.
				$demo_url = str_replace( '&#038;', '&', $tags[1][ $key ] );
				$demo_url = add_query_arg( 'demo', '', $demo_url );

				$new_tag = str_replace( $tags[1][ $key ], $demo_url, $tag );
				$output  = str_replace( $tag, $new_tag, $output );
			}
		}

		return $output;
	}

	/**
	 * Renders the WordPress logo
	 */
	protected function render_wordpress_logo() {
		?>
		<svg width="36" height="36" viewBox="0 0 23 24" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path fill-rule="evenodd" clip-rule="evenodd" d="M23 12C23 5.388 17.8365 0 11.5 0C5.152 0 0 5.388 0 12C0 18.624 5.152 24 11.5 24C17.8365 24 23 18.624 23 12ZM8.947 18.444L5.0255 7.464C5.658 7.44 6.371 7.368 6.371 7.368C6.946 7.296 6.877 6.012 6.302 6.036C6.302 6.036 4.6345 6.168 3.5765 6.168C3.3695 6.168 3.151 6.168 2.9095 6.156C4.738 3.228 7.9005 1.332 11.5 1.332C14.1795 1.332 16.6175 2.376 18.4575 4.14C17.6755 4.008 16.56 4.608 16.56 6.036C16.56 6.81358 16.9568 7.48075 17.403 8.23087L17.4031 8.23106L17.4033 8.23138L17.4035 8.23171C17.4666 8.33796 17.5308 8.44587 17.595 8.556C17.9975 9.288 18.2275 10.188 18.2275 11.508C18.2275 13.296 16.6175 17.508 16.6175 17.508L13.133 7.464C13.754 7.44 14.076 7.26 14.076 7.26C14.651 7.2 14.582 5.76 14.007 5.796C14.007 5.796 12.351 5.94 11.27 5.94C10.2695 5.94 8.5905 5.796 8.5905 5.796C8.0155 5.76 7.9465 7.236 8.5215 7.26L9.5795 7.356L11.0285 11.448L8.947 18.444ZM20.0466 11.9303L20.0215 12C19.1882 14.2893 18.3611 16.5983 17.5357 18.9027L17.5349 18.9048L17.5311 18.9153C17.2418 19.7231 16.9527 20.5304 16.6635 21.336C19.734 19.488 21.7235 15.948 21.7235 12C21.7235 10.152 21.321 8.448 20.516 6.9C20.862 9.67188 20.3306 11.1439 20.0466 11.9303ZM7.015 21.708C3.588 19.98 1.2765 16.236 1.2765 12C1.2765 10.44 1.541 9.024 2.1045 7.692C2.44894 8.6766 2.79338 9.66174 3.13793 10.6472C4.4269 14.3337 5.71737 18.0246 7.015 21.708ZM14.6165 22.128L11.6495 13.752C11.1028 15.4348 10.5521 17.1175 9.99975 18.8053C9.62251 19.958 9.24451 21.113 8.8665 22.272C9.6945 22.536 10.5915 22.668 11.5 22.668C12.5925 22.668 13.6275 22.476 14.6165 22.128Z"/>
		</svg>
		<?php
	}

	/**
	 * Renders the CTA arrow for mobile device
	 */
	protected function render_cta_arrow() {
		?>
		<svg width="24" height="20" viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.5 5H11M11 5L6.5 0.5M11 5L6.5 9.5"/></svg>
		<?php
	}
}

/**
 * Verifies if the current site is a demo site by checking for the `theme-demo-site` sticker
 *
 * @return bool
 */
function is_theme_demo_site() {
	return wpcomsh_is_site_sticker_active( 'theme-demo-site' );
}

/**
 * Instantiate only for known theme demo sites and block patterns source sites
 *
 * @uses is_theme_demo_site
 * @uses Theme_Demo_Sites_Display::instance
 * @return void
 */
function theme_demo_sites_display() {
	if ( is_theme_demo_site() ) {
		Theme_Demo_Sites_Display::instance();
	}
}
add_action( 'init', 'theme_demo_sites_display' );
