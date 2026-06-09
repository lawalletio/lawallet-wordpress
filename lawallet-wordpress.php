<?php
/**
 * Plugin Name: LaWallet Discovery
 * Plugin URI: https://github.com/lawallet/lawallet-wordpress
 * Description: Routes LNURL and NIP-05 .well-known discovery from WordPress to a LaWallet API gateway.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: LaWallet
 * Author URI: https://lawallet.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lawallet-wordpress
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LaWallet_Discovery_Plugin
{
    private const OPTION_ENDPOINT = 'lawallet_gateway_endpoint';
    private const OPTION_VERIFIED_AT = 'lawallet_gateway_verified_at';
    private const OPTION_LAST_ERROR = 'lawallet_gateway_last_error';
    private const NONCE_ACTION = 'lawallet_save_gateway';
    private const VERIFY_ACTION = 'lawallet_verify_gateway';

    public static function boot(): void
    {
        $plugin = new self();

        add_action('admin_menu', [$plugin, 'registerAdminPage']);
        add_action('admin_init', [$plugin, 'handleSettingsSave']);
        add_action('admin_post_lawallet_verify_gateway', [$plugin, 'handleVerifyRequest']);
        add_action('init', [$plugin, 'registerRewriteRules']);
        add_action('template_redirect', [$plugin, 'redirectWellKnownRequests'], 0);
        add_filter('query_vars', [$plugin, 'registerQueryVars']);

        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);
    }

    public function activate(): void
    {
        $this->registerRewriteRules();
        flush_rewrite_rules();
    }

    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function registerAdminPage(): void
    {
        add_options_page(
            __('LaWallet Discovery', 'lawallet-wordpress'),
            __('LaWallet Discovery', 'lawallet-wordpress'),
            'manage_options',
            'lawallet-discovery',
            [$this, 'renderAdminPage']
        );
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^\.well-known/(.*)$', 'index.php?lawallet_well_known=$matches[1]', 'top');
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'lawallet_well_known';
        return $vars;
    }

    public function redirectWellKnownRequests(): void
    {
        $path = $this->wellKnownPathFromRequest();
        if ($path === '') {
            return;
        }

        $endpoint = $this->getEndpoint();
        if ($endpoint === '') {
            status_header(503);
            wp_die(
                esc_html__('LaWallet gateway endpoint is not configured.', 'lawallet-wordpress'),
                esc_html__('LaWallet Discovery', 'lawallet-wordpress'),
                ['response' => 503]
            );
        }

        $target = $endpoint . '/.well-known/' . $path;
        $query = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])) : '';
        if ($query !== '') {
            $target .= '?' . $query;
        }

        wp_redirect(esc_url_raw($target), 307);
        exit;
    }

    public function handleSettingsSave(): void
    {
        if (!isset($_POST['lawallet_settings_submit'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to update LaWallet settings.', 'lawallet-wordpress'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $endpoint = isset($_POST['lawallet_gateway_endpoint'])
            ? $this->normalizeEndpoint(wp_unslash($_POST['lawallet_gateway_endpoint']))
            : '';

        if ($endpoint === '') {
            update_option(self::OPTION_LAST_ERROR, __('Enter a valid http(s) endpoint.', 'lawallet-wordpress'));
        } else {
            update_option(self::OPTION_ENDPOINT, $endpoint);
            delete_option(self::OPTION_LAST_ERROR);
            delete_option(self::OPTION_VERIFIED_AT);
            flush_rewrite_rules();
        }

        wp_safe_redirect($this->adminUrl());
        exit;
    }

    public function handleVerifyRequest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to verify LaWallet settings.', 'lawallet-wordpress'));
        }

        check_admin_referer(self::VERIFY_ACTION);

        $endpoint = $this->getEndpoint();
        $result = $this->verifyEndpoint($endpoint);

        if ($result['ok']) {
            update_option(self::OPTION_VERIFIED_AT, gmdate('c'));
            delete_option(self::OPTION_LAST_ERROR);
        } else {
            delete_option(self::OPTION_VERIFIED_AT);
            update_option(self::OPTION_LAST_ERROR, $result['message']);
        }

        wp_safe_redirect($this->adminUrl());
        exit;
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $endpoint = $this->getEndpoint();
        $verifiedAt = (string) get_option(self::OPTION_VERIFIED_AT, '');
        $lastError = (string) get_option(self::OPTION_LAST_ERROR, '');
        $status = $verifiedAt !== '' ? 'ready' : ($lastError !== '' ? 'error' : 'pending');
        ?>
        <div class="wrap lawallet-wrap">
            <style>
                .lawallet-card {
                    max-width: 760px;
                    margin-top: 20px;
                    padding: 24px;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    background: #fff;
                }
                .lawallet-status {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    margin: 8px 0 18px;
                    padding: 6px 10px;
                    border-radius: 999px;
                    background: #f0f0f1;
                    font-size: 13px;
                }
                .lawallet-status.ready { background: #edfaef; color: #0a7f28; }
                .lawallet-status.error { background: #fcf0f1; color: #b32d2e; }
                .lawallet-grid {
                    display: grid;
                    gap: 16px;
                }
                .lawallet-input {
                    width: 100%;
                    max-width: 560px;
                }
                .lawallet-code {
                    display: block;
                    max-width: 100%;
                    margin-top: 10px;
                    padding: 12px;
                    overflow-wrap: anywhere;
                    border-radius: 6px;
                    background: #f6f7f7;
                }
                .lawallet-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    align-items: center;
                }
            </style>

            <h1><?php echo esc_html__('LaWallet Discovery', 'lawallet-wordpress'); ?></h1>
            <div class="lawallet-card">
                <h2><?php echo esc_html__('Connect your LaWallet API gateway', 'lawallet-wordpress'); ?></h2>
                <p>
                    <?php echo esc_html__('Enter the endpoint where your LaWallet instance is hosted. This plugin redirects LNURL and NIP-05 discovery requests from WordPress to that gateway.', 'lawallet-wordpress'); ?>
                </p>

                <span class="lawallet-status <?php echo esc_attr($status); ?>">
                    <?php
                    if ($status === 'ready') {
                        echo esc_html__('Verified LaWallet gateway', 'lawallet-wordpress');
                    } elseif ($status === 'error') {
                        echo esc_html__('Verification failed', 'lawallet-wordpress');
                    } else {
                        echo esc_html__('Waiting for verification', 'lawallet-wordpress');
                    }
                    ?>
                </span>

                <?php if ($lastError !== '') : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html($lastError); ?></p></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('options-general.php?page=lawallet-discovery')); ?>" class="lawallet-grid">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <label for="lawallet_gateway_endpoint">
                        <strong><?php echo esc_html__('API gateway endpoint', 'lawallet-wordpress'); ?></strong>
                    </label>
                    <input
                        id="lawallet_gateway_endpoint"
                        name="lawallet_gateway_endpoint"
                        type="url"
                        class="regular-text lawallet-input"
                        placeholder="https://lawallet.example.com"
                        value="<?php echo esc_attr($endpoint); ?>"
                        required
                    />
                    <p class="description">
                        <?php echo esc_html__('Use the LaWallet instance URL, not the WordPress root domain.', 'lawallet-wordpress'); ?>
                    </p>
                    <div class="lawallet-actions">
                        <button type="submit" name="lawallet_settings_submit" class="button button-primary">
                            <?php echo esc_html__('Save endpoint', 'lawallet-wordpress'); ?>
                        </button>
                        <?php if ($endpoint !== '') : ?>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lawallet_verify_gateway'), self::VERIFY_ACTION)); ?>">
                                <?php echo esc_html__('Verify LaWallet', 'lawallet-wordpress'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($endpoint !== '') : ?>
                    <h3><?php echo esc_html__('Generated rewrite', 'lawallet-wordpress'); ?></h3>
                    <p><?php echo esc_html__('WordPress will redirect these discovery routes to your API gateway:', 'lawallet-wordpress'); ?></p>
                    <code class="lawallet-code">
                        <?php echo esc_html(home_url('/.well-known/*') . ' -> ' . $endpoint . '/.well-known/*'); ?>
                    </code>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function verifyEndpoint(string $endpoint): array
    {
        if ($endpoint === '') {
            return [
                'ok' => false,
                'message' => __('Save an API gateway endpoint first.', 'lawallet-wordpress'),
            ];
        }

        $probe = wp_generate_uuid4();
        $url = add_query_arg('probe', rawurlencode($probe), $endpoint . '/.well-known/lawallet.json');
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'LaWallet WordPress Discovery',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $isLawallet = is_array($body)
            && ($body['service'] ?? '') === 'lawallet'
            && ($body['probe'] ?? '') === $probe;

        if ($status >= 200 && $status < 300 && $isLawallet) {
            return [
                'ok' => true,
                'message' => __('LaWallet gateway verified.', 'lawallet-wordpress'),
            ];
        }

        return [
            'ok' => false,
            'message' => sprintf(
                /* translators: %d is the HTTP status code. */
                __('Endpoint did not return a valid LaWallet probe. Check returned HTTP %d.', 'lawallet-wordpress'),
                $status
            ),
        ];
    }

    private function wellKnownPathFromRequest(): string
    {
        $queryPath = get_query_var('lawallet_well_known');
        if (is_string($queryPath) && $queryPath !== '') {
            return ltrim($queryPath, '/');
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = parse_url((string) $requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        $prefix = '/.well-known/';
        if (strpos($path, $prefix) !== 0) {
            return '';
        }

        return ltrim(substr($path, strlen($prefix)), '/');
    }

    private function getEndpoint(): string
    {
        return (string) get_option(self::OPTION_ENDPOINT, '');
    }

    private function normalizeEndpoint($value): string
    {
        $endpoint = trim(strtolower((string) $value));
        $endpoint = rtrim($endpoint, '/');

        if ($endpoint === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = wp_parse_url($endpoint);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return '';
        }

        return esc_url_raw($endpoint);
    }

    private function adminUrl(): string
    {
        return admin_url('options-general.php?page=lawallet-discovery');
    }
}

LaWallet_Discovery_Plugin::boot();
