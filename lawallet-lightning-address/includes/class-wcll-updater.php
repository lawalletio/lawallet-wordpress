<?php
/**
 * GitHub release updater.
 *
 * Lets WordPress update this plugin from the project's GitHub Releases when it
 * is distributed outside the WordPress.org directory (e.g. from
 * https://wordpress.lawallet.io). It hooks into the native update system, so
 * updates appear on Dashboard -> Updates and the Plugins screen as usual.
 *
 * It self-disables when the plugin is detected on the WordPress.org directory,
 * so the .org copy keeps updating natively. It can also be turned off with:
 *   define( 'WCLL_GITHUB_UPDATER', false );  // in wp-config.php
 * or the `wcll_enable_github_updater` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Updater {
	const REPO          = 'lawalletio/lawallet-wordpress';
	const ASSET         = 'lawallet-lightning-address.zip';
	const RELEASE_CACHE = 'wcll_gh_release';
	const DOTORG_CACHE  = 'wcll_is_dotorg';
	const CACHE_TTL     = 21600; // 6 hours

	private static $basename = '';
	private static $slug     = '';

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		self::$basename = plugin_basename( WCLL_PLUGIN_FILE );
		self::$slug     = dirname( self::$basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
	}

	private static function enabled() {
		$enabled = defined( 'WCLL_GITHUB_UPDATER' ) ? (bool) WCLL_GITHUB_UPDATER : true;
		return (bool) apply_filters( 'wcll_enable_github_updater', $enabled );
	}

	/**
	 * Inject the available update into the plugins update transient.
	 */
	public static function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		if ( empty( $transient->checked ) || self::is_dotorg_hosted() ) {
			return $transient;
		}

		$release = self::get_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		$item = (object) array(
			'slug'        => self::$slug,
			'plugin'      => self::$basename,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $release['package'],
			'tested'      => self::header( 'Tested up to' ),
			'requires'    => self::header( 'Requires at least' ),
			'requires_php' => self::header( 'Requires PHP' ),
			'icons'       => array( 'default' => WCLL_PLUGIN_URL . 'assets/bitcoin.png' ),
		);

		if ( version_compare( $release['version'], WCLL_VERSION, '>' ) ) {
			$transient->response[ self::$basename ] = $item;
			unset( $transient->no_update[ self::$basename ] );
		} else {
			$item->new_version = WCLL_VERSION;
			$item->package     = '';
			$transient->no_update[ self::$basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide the "View details" modal content.
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::$slug !== $args->slug ) {
			return $result;
		}

		$release = self::get_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Accept Bitcoin with your Lightning Address',
			'slug'          => self::$slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://lawallet.io">LaWallet</a>',
			'homepage'      => 'https://wordpress.lawallet.io',
			'requires'      => self::header( 'Requires at least' ),
			'requires_php'  => self::header( 'Requires PHP' ),
			'tested'        => self::header( 'Tested up to' ),
			'last_updated'  => $release['published_at'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Accept Bitcoin via Lightning Address in WooCommerce, connecting the most popular Lightning wallets.', 'lawallet-lightning-address' ),
				'changelog'   => self::format_changelog( $release['changelog'] ),
			),
		);
	}

	/**
	 * GitHub release ZIPs may extract to a folder that does not match the plugin
	 * slug. Rename it so WordPress installs the update in place.
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || self::$basename !== $args['plugin'] || ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::$slug;
		$desired = trailingslashit( $desired );

		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired ) ) {
			return $desired;
		}

		return $source;
	}

	public static function clear_cache( $upgrader, $data ) {
		if ( isset( $data['type'], $data['action'] ) && 'plugin' === $data['type'] && 'update' === $data['action'] ) {
			delete_transient( self::RELEASE_CACHE );
		}
	}

	/**
	 * Latest GitHub release, cached.
	 */
	private static function get_release() {
		$cached = get_transient( self::RELEASE_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LaWallet-Lightning-Address',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::RELEASE_CACHE, array(), HOUR_IN_SECONDS );
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::RELEASE_CACHE, array(), HOUR_IN_SECONDS );
			return array();
		}

		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
					$package = esc_url_raw( (string) $asset['browser_download_url'] );
					break;
				}
			}
		}

		$data = array(
			'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'      => $package,
			'html_url'     => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : '',
			'changelog'    => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published_at' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_transient( self::RELEASE_CACHE, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Detect whether this plugin slug is published on WordPress.org. If so, the
	 * GitHub updater stays out of the way so .org updates work normally. Cached
	 * for a week.
	 */
	private static function is_dotorg_hosted() {
		$cached = get_transient( self::DOTORG_CACHE );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$response = wp_remote_get(
			'https://api.wordpress.org/plugins/info/1.0/' . self::$slug . '.json',
			array( 'timeout' => 8 )
		);

		$on_dotorg = false;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$on_dotorg = is_array( $body ) && ! empty( $body['slug'] ) && ! isset( $body['error'] );
		}

		set_transient( self::DOTORG_CACHE, $on_dotorg ? 'yes' : 'no', WEEK_IN_SECONDS );
		return $on_dotorg;
	}

	private static function header( $key ) {
		static $headers = null;
		if ( null === $headers ) {
			$headers = get_file_data(
				WCLL_PLUGIN_FILE,
				array(
					'Tested up to'      => 'Tested up to',
					'Requires at least' => 'Requires at least',
					'Requires PHP'      => 'Requires PHP',
				)
			);
		}
		return isset( $headers[ $key ] ) ? $headers[ $key ] : '';
	}

	private static function format_changelog( $markdown ) {
		$text = wp_strip_all_tags( (string) $markdown );
		$text = preg_replace( '/^[\*\-] /m', '', $text );
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
		if ( empty( $lines ) ) {
			return '';
		}
		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>';
	}
}
