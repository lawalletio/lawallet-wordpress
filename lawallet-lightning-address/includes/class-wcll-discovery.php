<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLL_Discovery {
	const OPTION_ENABLED     = 'lawallet_discovery_enabled';
	const OPTION_ENDPOINT    = 'lawallet_gateway_endpoint';
	const OPTION_VERIFIED_AT = 'lawallet_gateway_verified_at';
	const OPTION_LAST_ERROR  = 'lawallet_gateway_last_error';
	const OPTION_SETTINGS    = 'lawallet_gateway_server_settings';
	const QUERY_VAR          = 'lawallet_well_known';
	const NONCE_ACTION       = 'lawallet_save_gateway';
	const VERIFY_ACTION      = 'lawallet_verify_gateway';
	const CHECK_ACTION       = 'lawallet_check_gateway_endpoint';
	const DEFAULT_ENDPOINT   = 'https://beta.lawallet.io';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_post_lawallet_verify_gateway', array( __CLASS__, 'handle_verify_request' ) );
		add_action( 'wp_ajax_lawallet_check_gateway_endpoint', array( __CLASS__, 'handle_ajax_check_endpoint' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_well_known_requests' ), 0 );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_lawallet-lightning-address' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'lawallet-admin', WCLL_PLUGIN_URL . 'assets/css/lawallet-admin.css', array( 'dashicons' ), WCLL_VERSION );
		wp_enqueue_script( 'lawallet-admin', WCLL_PLUGIN_URL . 'assets/js/lawallet-admin.js', array(), WCLL_VERSION, true );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::CHECK_ACTION ),
			'i18n'    => array(
				'pending'            => __( 'Waiting for endpoint', 'lawallet-lightning-address' ),
				'loading'            => __( 'Checking LaWallet gateway', 'lawallet-lightning-address' ),
				'ready'              => __( 'LaWallet instance found', 'lawallet-lightning-address' ),
				'error'              => __( 'Verification failed', 'lawallet-lightning-address' ),
				'instanceEmptyTitle' => __( 'Connect a LaWallet instance', 'lawallet-lightning-address' ),
				'instanceEmptyMeta'  => __( 'Server details, avatar, cover, theme and socials will appear here after connection.', 'lawallet-lightning-address' ),
				'instanceReadyTitle' => __( 'Connected LaWallet instance', 'lawallet-lightning-address' ),
			),
		);

		wp_add_inline_script( 'lawallet-admin', 'window.WCLLDiscovery = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function activate() {
		self::register_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function register_admin_page() {
		add_options_page(
			__( 'Accept Bitcoin with your Lightning Address', 'lawallet-lightning-address' ),
			__( 'LaWallet', 'lawallet-lightning-address' ),
			'manage_options',
			'lawallet-lightning-address',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/(.*)$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function redirect_well_known_requests() {
		$path = self::well_known_path_from_request();
		if ( '' === $path || ! self::is_enabled() ) {
			return;
		}

		$endpoint = self::get_endpoint();
		if ( '' === $endpoint ) {
			status_header( 503 );
			wp_die(
				esc_html__( 'LaWallet gateway endpoint is not configured.', 'lawallet-lightning-address' ),
				esc_html__( 'Accept Bitcoin with your Lightning Address', 'lawallet-lightning-address' ),
				array( 'response' => 503 )
			);
		}

		$target = $endpoint . '/.well-known/' . $path;
		$query  = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' !== $query ) {
			$target .= '?' . $query;
		}

		$redirect_host = wp_parse_url( $target, PHP_URL_HOST );
		if ( $redirect_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $redirect_host ) {
					$hosts[] = $redirect_host;
					return array_unique( $hosts );
				}
			);
		}

		wp_safe_redirect( esc_url_raw( $target ), 307 );
		exit;
	}

	public static function handle_settings_save() {
		if ( ! isset( $_POST['lawallet_settings_submit'] ) && ! isset( $_POST['lawallet_disconnect_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update LaWallet settings.', 'lawallet-lightning-address' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( isset( $_POST['lawallet_disconnect_submit'] ) ) {
			update_option( self::OPTION_ENABLED, 'no' );
			delete_option( self::OPTION_LAST_ERROR );
			delete_option( self::OPTION_SETTINGS );
			delete_option( self::OPTION_VERIFIED_AT );
			flush_rewrite_rules();
			wp_safe_redirect( self::admin_url() );
			exit;
		}

		$endpoint = isset( $_POST['lawallet_gateway_endpoint'] ) ? self::normalize_endpoint( sanitize_text_field( wp_unslash( $_POST['lawallet_gateway_endpoint'] ) ) ) : '';

		update_option( self::OPTION_ENABLED, 'no' );

		if ( '' === $endpoint ) {
			update_option( self::OPTION_LAST_ERROR, __( 'Enter a valid http(s) LaWallet gateway endpoint before connecting.', 'lawallet-lightning-address' ) );
			delete_option( self::OPTION_SETTINGS );
			delete_option( self::OPTION_VERIFIED_AT );
			wp_safe_redirect( self::admin_url() );
			exit;
		}

		update_option( self::OPTION_ENDPOINT, $endpoint );

		$result = self::verify_endpoint( $endpoint );
		if ( $result['ok'] ) {
			update_option( self::OPTION_ENABLED, 'yes' );
			update_option( self::OPTION_VERIFIED_AT, gmdate( 'c' ) );
			update_option( self::OPTION_SETTINGS, $result['settings'] );
			delete_option( self::OPTION_LAST_ERROR );
		} else {
			delete_option( self::OPTION_VERIFIED_AT );
			delete_option( self::OPTION_SETTINGS );
			update_option( self::OPTION_LAST_ERROR, $result['message'] );
		}

		flush_rewrite_rules();

		wp_safe_redirect( self::admin_url() );
		exit;
	}

	public static function handle_verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to verify LaWallet settings.', 'lawallet-lightning-address' ) );
		}

		check_admin_referer( self::VERIFY_ACTION );

		$result = self::verify_endpoint( self::get_endpoint() );
		if ( $result['ok'] ) {
			update_option( self::OPTION_ENABLED, 'yes' );
			update_option( self::OPTION_VERIFIED_AT, gmdate( 'c' ) );
			update_option( self::OPTION_SETTINGS, $result['settings'] );
			delete_option( self::OPTION_LAST_ERROR );
		} else {
			update_option( self::OPTION_ENABLED, 'no' );
			delete_option( self::OPTION_VERIFIED_AT );
			delete_option( self::OPTION_SETTINGS );
			update_option( self::OPTION_LAST_ERROR, $result['message'] );
		}

		wp_safe_redirect( self::admin_url() );
		exit;
	}

	public static function handle_ajax_check_endpoint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to verify LaWallet settings.', 'lawallet-lightning-address' ) ),
				403
			);
		}

		check_ajax_referer( self::CHECK_ACTION, 'nonce' );

		$endpoint = isset( $_POST['endpoint'] ) ? self::normalize_endpoint( sanitize_text_field( wp_unslash( $_POST['endpoint'] ) ) ) : '';
		if ( '' === $endpoint ) {
			wp_send_json_success(
				array(
					'ok'       => false,
					'endpoint' => '',
					'message'  => __( 'Enter a valid http(s) LaWallet gateway endpoint.', 'lawallet-lightning-address' ),
				)
			);
		}

		$result = self::verify_endpoint( $endpoint );
		wp_send_json_success(
			array(
				'ok'       => (bool) $result['ok'],
				'endpoint' => $endpoint,
				'message'  => $result['ok'] ? __( 'LaWallet instance found.', 'lawallet-lightning-address' ) : $result['message'],
				'settings' => ! empty( $result['settings'] ) ? $result['settings'] : array(),
				'instance' => ! empty( $result['settings'] ) ? self::normalize_server_settings( $result['settings'], $endpoint ) : array(),
			)
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$discovery_enabled = self::is_enabled();
		$endpoint         = self::get_endpoint();
		$verified_at      = (string) get_option( self::OPTION_VERIFIED_AT, '' );
		$last_error       = (string) get_option( self::OPTION_LAST_ERROR, '' );
		$server_settings  = get_option( self::OPTION_SETTINGS, array() );
		$server_settings  = is_array( $server_settings ) ? $server_settings : array();
		$woocommerce_active    = class_exists( 'WooCommerce' );
		$woocommerce_installed = self::is_woocommerce_installed();
		$gateway_settings      = get_option( 'woocommerce_wcll_gateway_settings', array() );
		$payment_address       = is_array( $gateway_settings ) && ! empty( $gateway_settings['lightning_address'] ) ? $gateway_settings['lightning_address'] : '';
		$payment_status        = is_array( $gateway_settings ) && ! empty( $gateway_settings['lud21_verified'] ) ? $gateway_settings['lud21_verified'] : 'no';
		$gateway_url           = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wcll_gateway&wcll_setup=1' );
		$instance              = self::normalize_server_settings( $server_settings, $endpoint );
		$has_instance          = ! empty( $instance['name'] ) || ! empty( $instance['domain'] );
		$is_connected          = $discovery_enabled && $verified_at && $has_instance;
		$discovery_status      = $is_connected ? 'ready' : ( $last_error ? 'error' : 'pending' );
		$check_state           = $is_connected ? 'ready' : ( $last_error ? 'error' : 'pending' );
		$check_icon            = 'ready' === $check_state ? 'dashicons-yes-alt' : ( 'error' === $check_state ? 'dashicons-warning' : 'dashicons-minus' );
		$check_label           = 'ready' === $check_state ? __( 'LaWallet instance connected', 'lawallet-lightning-address' ) : ( 'error' === $check_state ? __( 'Verification failed', 'lawallet-lightning-address' ) : __( 'Waiting for endpoint', 'lawallet-lightning-address' ) );
		$connect_disabled      = 'ready' !== $check_state;
		$cover_styles          = array();
		if ( ! empty( $instance['theme'] ) ) {
			$cover_styles[] = 'background-color: ' . $instance['theme'];
		}
		if ( ! empty( $instance['cover'] ) ) {
			$cover_styles[] = "background-image: url('" . esc_url( $instance['cover'] ) . "')";
		}
		?>
		<div class="wrap lawallet-wrap">

			<h1><?php echo esc_html__( 'Accept Bitcoin with your Lightning Address', 'lawallet-lightning-address' ); ?></h1>

			<div class="lawallet-card">
				<h2><?php echo esc_html__( 'WooCommerce Lightning payments', 'lawallet-lightning-address' ); ?></h2>
				<?php if ( $woocommerce_active ) : ?>
					<p><?php echo esc_html__( 'Accept checkout payments with a merchant Lightning Address. Orders are completed only after the backend verifies LUD-21 settlement.', 'lawallet-lightning-address' ); ?></p>
					<span class="lawallet-status <?php echo 'yes' === $payment_status ? 'ready' : 'pending'; ?>">
						<?php
						if ( $payment_address ) {
							printf(
								/* translators: %s is a Lightning Address. */
								esc_html__( 'Payment Lightning Address: %s', 'lawallet-lightning-address' ),
								esc_html( $payment_address )
							);
						} else {
							echo esc_html__( 'Payment Lightning Address is not configured', 'lawallet-lightning-address' );
						}
						?>
					</span>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $gateway_url ); ?>">
							<?php echo esc_html__( 'Configure WooCommerce payments', 'lawallet-lightning-address' ); ?>
						</a>
					</p>
				<?php elseif ( $woocommerce_installed ) : ?>
					<p><?php echo esc_html__( 'WooCommerce is installed but inactive. Activate WooCommerce to enable Lightning checkout payments.', 'lawallet-lightning-address' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( self::woocommerce_plugins_url() ); ?>">
							<?php echo esc_html__( 'Open plugins', 'lawallet-lightning-address' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p><?php echo esc_html__( 'WooCommerce is required to accept checkout payments. Install WooCommerce from WordPress to enable this payment gateway.', 'lawallet-lightning-address' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( self::woocommerce_install_url() ); ?>">
							<?php echo esc_html__( 'Install WooCommerce', 'lawallet-lightning-address' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<div class="lawallet-card">
				<h2><?php echo esc_html__( 'Lightning Address for your users', 'lawallet-lightning-address' ); ?></h2>
				<?php if ( ! $is_connected ) : ?>
					<p class="lawallet-option-copy">
						<?php echo esc_html__( 'You should have a LaWallet instance running. This integration redirects LNURL and NIP-05 discovery from this WordPress domain to your LaWallet API gateway.', 'lawallet-lightning-address' ); ?>
						<a href="https://lawallet.io" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Learn more at lawallet.io', 'lawallet-lightning-address' ); ?></a>
					</p>
				<?php endif; ?>

				<span class="lawallet-status <?php echo esc_attr( $discovery_status ); ?>">
					<?php
					if ( $is_connected ) {
						echo esc_html__( 'Connected', 'lawallet-lightning-address' );
					} elseif ( $last_error ) {
						echo esc_html__( 'Could not connect', 'lawallet-lightning-address' );
					} else {
						echo esc_html__( 'Not connected', 'lawallet-lightning-address' );
					}
					?>
				</span>

				<?php if ( ! $is_connected && $last_error ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $last_error ); ?></p></div>
				<?php endif; ?>

				<?php if ( $is_connected ) : ?>
					<div class="lawallet-instance-preview" data-lawallet-instance-card>
						<div
							class="lawallet-instance-cover"
							data-lawallet-instance-cover
							<?php if ( $cover_styles ) : ?>
								style="<?php echo esc_attr( implode( '; ', $cover_styles ) ); ?>"
							<?php endif; ?>
						></div>
						<div class="lawallet-instance-body">
							<div
								class="lawallet-instance-avatar <?php echo empty( $instance['avatar'] ) ? 'is-empty' : ''; ?>"
								data-lawallet-instance-avatar
								<?php if ( ! empty( $instance['avatar'] ) ) : ?>
									style="background-image: url('<?php echo esc_url( $instance['avatar'] ); ?>');"
								<?php endif; ?>
							><?php echo empty( $instance['avatar'] ) ? esc_html( $instance['initials'] ) : ''; ?></div>
							<div class="lawallet-instance-content">
								<div class="lawallet-instance-name" data-lawallet-instance-name>
									<?php echo esc_html( $instance['name'] ); ?>
								</div>
								<p class="lawallet-instance-meta" data-lawallet-instance-meta>
									<?php echo esc_html( trim( $instance['domain'] . ' · ' . $instance['endpoint'], ' ·' ) ); ?>
								</p>
								<div class="lawallet-instance-details" data-lawallet-instance-details>
									<?php foreach ( $instance['details'] as $detail ) : ?>
										<span class="lawallet-detail-pill">
											<strong><?php echo esc_html( $detail['label'] ); ?></strong>
											<span><?php echo esc_html( $detail['value'] ); ?></span>
										</span>
									<?php endforeach; ?>
								</div>
								<div class="lawallet-socials" data-lawallet-instance-socials>
									<?php foreach ( $instance['socials'] as $social ) : ?>
										<?php if ( ! empty( $social['url'] ) ) : ?>
											<a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $social['label'] ); ?>
											</a>
										<?php else : ?>
											<span><?php echo esc_html( $social['label'] ); ?></span>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>

					<form method="post" action="<?php echo esc_url( self::admin_url() ); ?>" class="lawallet-grid">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<div class="lawallet-actions">
							<button
								type="submit"
								name="lawallet_disconnect_submit"
								value="1"
								class="button lawallet-submit-button"
								data-lawallet-submitting-text="<?php echo esc_attr__( 'Disconnecting', 'lawallet-lightning-address' ); ?>"
							>
								<?php echo esc_html__( 'Disconnect', 'lawallet-lightning-address' ); ?>
							</button>
						</div>
					</form>

					<h3><?php echo esc_html__( 'Generated discovery redirect', 'lawallet-lightning-address' ); ?></h3>
					<code class="lawallet-code">
						<?php echo esc_html( home_url( '/.well-known/*' ) . ' -> ' . $endpoint . '/.well-known/*' ); ?>
					</code>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( self::admin_url() ); ?>" class="lawallet-grid">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<label for="lawallet_gateway_endpoint">
							<strong><?php echo esc_html__( 'LaWallet API gateway endpoint', 'lawallet-lightning-address' ); ?></strong>
						</label>
						<div class="lawallet-endpoint-field">
							<input
								id="lawallet_gateway_endpoint"
								name="lawallet_gateway_endpoint"
								type="url"
								class="regular-text lawallet-input"
								placeholder="<?php echo esc_attr( self::DEFAULT_ENDPOINT ); ?>"
								value="<?php echo esc_attr( $endpoint ); ?>"
								data-lawallet-endpoint-input
							/>
							<span
								class="lawallet-endpoint-check is-<?php echo esc_attr( $check_state ); ?>"
								data-lawallet-endpoint-status
								title="<?php echo esc_attr( $check_label ); ?>"
							>
								<span class="dashicons <?php echo esc_attr( $check_icon ); ?>"></span>
								<span class="screen-reader-text" data-lawallet-endpoint-status-text><?php echo esc_html( $check_label ); ?></span>
							</span>
						</div>
						<p class="description">
							<?php echo esc_html__( 'Requests such as /.well-known/lnurlp/alice and /.well-known/nostr.json?name=alice will redirect to this gateway after connection.', 'lawallet-lightning-address' ); ?>
						</p>
						<div class="lawallet-actions">
							<button
								type="submit"
								name="lawallet_settings_submit"
								value="1"
								class="button button-primary lawallet-submit-button"
								data-lawallet-submitting-text="<?php echo esc_attr__( 'Connecting', 'lawallet-lightning-address' ); ?>"
								data-lawallet-connect-button
								<?php disabled( $connect_disabled ); ?>
							>
								<?php echo esc_html__( 'Connect', 'lawallet-lightning-address' ); ?>
							</button>
						</div>
					</form>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	public static function verify_endpoint( $endpoint ) {
		if ( '' === $endpoint ) {
			return array(
				'ok'      => false,
				'message' => __( 'Save a LaWallet API gateway endpoint first.', 'lawallet-lightning-address' ),
			);
		}

		$settings_url = $endpoint . '/api/settings';
		$response     = wp_remote_get(
			$settings_url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Accept Bitcoin with your Lightning Address',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$settings  = is_array( $body ) ? self::sanitize_server_settings( $body, $endpoint ) : array();
		$instance  = self::normalize_server_settings( $settings, $endpoint );
		$has_signal = is_array( $body ) && (
			isset( $body['community_name'] ) ||
			isset( $body['name'] ) ||
			isset( $body['domain'] ) ||
			isset( $body['endpoint'] ) ||
			isset( $body['subdomain'] )
		);

		if ( $status >= 200 && $status < 300 && $has_signal && ( ! empty( $instance['name'] ) || ! empty( $instance['domain'] ) || ! empty( $instance['endpoint'] ) ) ) {
			return array(
				'ok'       => true,
				'message'  => __( 'LaWallet instance connected.', 'lawallet-lightning-address' ),
				'settings' => $settings,
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d is the HTTP status code. */
				__( 'Endpoint did not look like a LaWallet gateway. /api/settings returned HTTP %d.', 'lawallet-lightning-address' ),
				$status
			),
		);
	}

	private static function sanitize_server_settings( $settings, $fallback_endpoint ) {
		$clean = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				$clean[ $key ] = '';
				continue;
			}

			if ( false !== strpos( $key, 'url' ) || in_array( $key, array( 'endpoint', 'subdomain' ), true ) ) {
				$clean[ $key ] = esc_url_raw( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		if ( empty( $clean['endpoint'] ) ) {
			$clean['endpoint'] = esc_url_raw( $fallback_endpoint );
		}

		return $clean;
	}

	private static function normalize_server_settings( $settings, $fallback_endpoint = '' ) {
		$settings = is_array( $settings ) ? $settings : array();
		if ( empty( $settings ) ) {
			return array(
				'name'     => '',
				'domain'   => '',
				'endpoint' => '',
				'theme'    => '#111827',
				'initials' => '',
				'avatar'   => '',
				'cover'    => '',
				'details'  => array(),
				'socials'  => array(),
			);
		}

		$endpoint = ! empty( $settings['endpoint'] ) ? (string) $settings['endpoint'] : (string) $fallback_endpoint;
		$domain   = ! empty( $settings['domain'] ) ? (string) $settings['domain'] : wp_parse_url( $endpoint, PHP_URL_HOST );
		$name     = self::first_setting( $settings, array( 'community_name', 'name', 'title' ) );
		$theme    = self::valid_hex_color( self::first_setting( $settings, array( 'brand_theme', 'theme_color' ) ) );
		$avatar   = self::first_url_setting( $settings, array( 'isotypo_url', 'avatar_url', 'icon_url' ) );
		$cover    = self::first_url_setting( $settings, array( 'cover_url', 'banner_url' ) );
		$details  = array();

		if ( $endpoint ) {
			$details[] = array(
				'label' => __( 'Endpoint', 'lawallet-lightning-address' ),
				'value' => $endpoint,
			);
		}

		if ( ! empty( $settings['subdomain'] ) && $settings['subdomain'] !== $endpoint ) {
			$details[] = array(
				'label' => __( 'Subdomain', 'lawallet-lightning-address' ),
				'value' => (string) $settings['subdomain'],
			);
		}

		if ( isset( $settings['maintenance_enabled'] ) && '' !== $settings['maintenance_enabled'] ) {
			$details[] = array(
				'label' => __( 'Maintenance', 'lawallet-lightning-address' ),
				'value' => self::truthy_string( $settings['maintenance_enabled'] ) ? __( 'Enabled', 'lawallet-lightning-address' ) : __( 'Off', 'lawallet-lightning-address' ),
			);
		}

		$socials = array_merge(
			self::social_item( __( 'Website', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_website', 'website' ) ), 'website' ),
			self::social_item( __( 'X/Twitter', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_twitter', 'twitter' ) ), 'twitter' ),
			self::social_item( __( 'Telegram', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_telegram', 'telegram' ) ), 'telegram' ),
			self::social_item( __( 'Discord', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_discord', 'discord' ) ), 'discord' ),
			self::social_item( __( 'Nostr', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_nostr', 'nostr' ) ), 'nostr' ),
			self::social_item( __( 'Email', 'lawallet-lightning-address' ), self::first_setting( $settings, array( 'social_email', 'email' ) ), 'email' )
		);

		return array(
			'name'     => $name,
			'domain'   => $domain ? (string) $domain : '',
			'endpoint' => $endpoint,
			'theme'    => $theme ? $theme : '#111827',
			'initials' => self::initials_from_name( $name ? $name : $domain ),
			'avatar'   => $avatar,
			'cover'    => $cover,
			'details'  => $details,
			'socials'  => $socials,
		);
	}

	private static function first_setting( $settings, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== trim( (string) $settings[ $key ] ) ) {
				return trim( (string) $settings[ $key ] );
			}
		}

		return '';
	}

	private static function first_url_setting( $settings, $keys ) {
		$value = self::first_setting( $settings, $keys );
		return $value ? esc_url_raw( $value ) : '';
	}

	private static function valid_hex_color( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : '';
	}

	private static function initials_from_name( $value ) {
		$value = trim( preg_replace( '/[^A-Za-z0-9]+/', ' ', (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		$initials = '';
		$parts    = preg_split( '/\s+/', $value );
		foreach ( $parts as $part ) {
			$initials .= substr( $part, 0, 1 );
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}

		if ( '' === $initials ) {
			$initials = substr( $value, 0, 2 );
		}

		return strtoupper( $initials );
	}

	private static function truthy_string( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'enabled' ), true );
	}

	private static function social_item( $label, $value, $type ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$url = '';
		if ( 'website' === $type ) {
			$url = preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value;
		} elseif ( 'twitter' === $type ) {
			$url = 'https://x.com/' . rawurlencode( ltrim( $value, '@' ) );
		} elseif ( 'telegram' === $type ) {
			$url = 'https://t.me/' . rawurlencode( ltrim( $value, '@' ) );
		} elseif ( 'email' === $type ) {
			$url = 'mailto:' . sanitize_email( $value );
		}

		return array(
			array(
				'label' => sprintf(
					/* translators: 1: social label, 2: social value. */
					__( '%1$s: %2$s', 'lawallet-lightning-address' ),
					$label,
					$value
				),
				'url'   => $url ? esc_url_raw( $url ) : '',
			),
		);
	}

	private static function well_known_path_from_request() {
		$query_path = get_query_var( self::QUERY_VAR );
		if ( is_string( $query_path ) && '' !== $query_path ) {
			return ltrim( $query_path, '/' );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$prefix = '/.well-known/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $prefix ) ), '/' );
	}

	private static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	private static function get_endpoint() {
		$endpoint = (string) get_option( self::OPTION_ENDPOINT, '' );
		return '' === $endpoint ? self::DEFAULT_ENDPOINT : $endpoint;
	}

	private static function normalize_endpoint( $value ) {
		$endpoint = trim( strtolower( (string) $value ) );
		$endpoint = rtrim( $endpoint, '/' );

		if ( '' === $endpoint ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$endpoint = 'https://' . $endpoint;
		}

		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return '';
		}

		return esc_url_raw( $endpoint );
	}

	private static function admin_url() {
		return admin_url( 'options-general.php?page=lawallet-lightning-address' );
	}

	private static function is_woocommerce_installed() {
		return file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
	}

	private static function woocommerce_install_url() {
		return admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
	}

	private static function woocommerce_plugins_url() {
		return admin_url( 'plugins.php?s=woocommerce&plugin_status=inactive' );
	}
}
