<?php
/**
 * Plugin Name: Pickaxe Embed SSO
 * Description: Signs short-lived Pickaxe embed SSO tokens for logged-in WordPress users.
 * Version: 0.1.2
 * Author: Pickaxe
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: pickaxe-embed-sso
 *
 * @package PickaxeEmbedSSO
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Pickaxe_Embed_SSO {
    private const OPTION_NAME = 'pickaxe_embed_sso_settings';
    private const REST_NAMESPACE = 'pickaxe-sso/v1';
    private const REST_ROUTE = '/embed-token';
    private const DEFAULT_AUDIENCE = 'pickaxe-embed';
    private const DEFAULT_AUTH_PROVIDER = 'wordpress-native';
    private const DEFAULT_TOKEN_TTL_SECONDS = 60;

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_shortcode('pickaxe_embed', [__CLASS__, 'render_embed_shortcode']);
    }

    public static function register_rest_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'handle_embed_token_request'],
                'permission_callback' => static function (): bool {
                    return is_user_logged_in();
                },
            ]
        );
    }

    public static function handle_embed_token_request(WP_REST_Request $request): WP_REST_Response {
        unset($request);

        if (!is_user_logged_in()) {
            return new WP_REST_Response(['error' => 'A WordPress user must be logged in first.'], 401);
        }

        $settings = self::get_settings();
        $missing = self::missing_required_settings($settings);
        if ($missing) {
            return new WP_REST_Response(
                [
                    'error' => 'Pickaxe SSO is not configured.',
                    'missing' => $missing,
                ],
                500
            );
        }

        $user = wp_get_current_user();

        try {
            $token = self::create_login_token($user, $settings);
        } catch (Throwable $error) {
            return new WP_REST_Response(
                [
                    'error' => 'Unable to sign Pickaxe SSO token.',
                    'detail' => $error->getMessage(),
                ],
                500
            );
        }

        return new WP_REST_Response(
            [
                'token' => $token,
                'expiresInSeconds' => (int) $settings['token_ttl_seconds'],
                'payloadMapping' => self::get_payload_mapping($user, $settings),
            ]
        );
    }

    public static function render_embed_shortcode($atts = []): string {
        $settings = self::get_settings();
        $atts = is_array($atts) ? $atts : [];
        $atts = shortcode_atts(
            [
                'deployment_id' => '',
                'target_id' => '',
                'script_url' => $settings['embed_script_url'],
                'service_origin' => $settings['embed_service_origin'],
            ],
            $atts,
            'pickaxe_embed'
        );

        $target_id = sanitize_html_class($atts['deployment_id'] ?: $atts['target_id']);
        if (!$target_id) {
            if (current_user_can('manage_options')) {
                return '<div class="pickaxe-embed-sso-error">Pickaxe Embed SSO is missing a deployment ID. Use <code>[pickaxe_embed deployment_id="deployment-your-id"]</code>.</div>';
            }

            return '';
        }

        $config = [
            'target' => '#' . $target_id,
            'scriptUrl' => esc_url_raw($atts['script_url']),
            'serviceOrigin' => esc_url_raw($atts['service_origin']),
            'tokenUrl' => rest_url(self::REST_NAMESPACE . self::REST_ROUTE),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
        ];

        $html = '<div id="' . esc_attr($target_id) . '" class="pickaxe-embed-sso"></div>';
        $html .= '<script>window.PickaxeEmbedSSOConfig = ' . wp_json_encode($config) . ';</script>';
        $html .= '<script src="' . esc_url(plugin_dir_url(__FILE__) . 'assets/pickaxe-embed-sso.js') . '" defer></script>';

        return $html;
    }

    public static function register_settings_page(): void {
        add_options_page(
            'Pickaxe Embed SSO',
            'Pickaxe Embed SSO',
            'manage_options',
            'pickaxe-embed-sso',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            'pickaxe_embed_sso',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => [],
            ]
        );

        add_settings_section(
            'pickaxe_embed_sso_contract',
            'SSO contract',
            static function (): void {
                echo '<p>Values must match the customer configuration registered with Pickaxe.</p>';
            },
            'pickaxe-embed-sso'
        );

        foreach (self::settings_fields() as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                [__CLASS__, 'render_settings_field'],
                'pickaxe-embed-sso',
                'pickaxe_embed_sso_contract',
                [
                    'key' => $key,
                    'field' => $field,
                ]
            );
        }
    }

    public static function sanitize_settings(array $input): array {
        $output = [];

        foreach (self::settings_fields() as $key => $field) {
            $value = isset($input[$key]) ? (string) $input[$key] : '';

            if ('textarea' === $field['type']) {
                $output[$key] = trim($value);
                continue;
            }

            if ('token_ttl_seconds' === $key) {
                $output[$key] = max(10, min(300, (int) $value));
                continue;
            }

            $output[$key] = sanitize_text_field($value);
        }

        return $output;
    }

    public static function render_settings_field(array $args): void {
        $settings = get_option(self::OPTION_NAME, []);
        $key = $args['key'];
        $field = $args['field'];
        $value = isset($settings[$key]) ? (string) $settings[$key] : ($field['default'] ?? '');
        $constant = $field['constant'] ?? null;
        $constant_defined = $constant && defined($constant);

        if ($constant_defined) {
            $value = (string) constant($constant);
        }

        $name = self::OPTION_NAME . '[' . esc_attr($key) . ']';

        if ('textarea' === $field['type']) {
            echo '<textarea class="large-text code" rows="8" name="' . esc_attr($name) . '" ' . disabled($constant_defined, true, false) . '>';
            echo esc_textarea($value);
            echo '</textarea>';
        } else {
            echo '<input class="regular-text" type="' . esc_attr($field['type']) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . disabled($constant_defined, true, false) . ' />';
        }

        if ($constant_defined) {
            echo '<p class="description">Controlled by <code>' . esc_html($constant) . '</code>.</p>';
        } elseif (!empty($field['description'])) {
            echo '<p class="description">' . esc_html($field['description']) . '</p>';
        }
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Pickaxe Embed SSO</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields('pickaxe_embed_sso');
        do_settings_sections('pickaxe-embed-sso');
        submit_button();
        echo '</form>';
        echo '<h2>Usage</h2>';
        echo '<p>Add <code>[pickaxe_embed deployment_id="deployment-your-id"]</code> to a page. The deployment ID must be the same <code>deployment-...</code> ID used by the Pickaxe embed. Logged-out visitors will see the normal embed login path. Logged-in visitors receive a short-lived signed SSO token.</p>';
        echo '</div>';
    }

    private static function settings_fields(): array {
        return [
            'customer_id' => [
                'label' => 'Customer ID',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_CUSTOMER_ID',
            ],
            'issuer' => [
                'label' => 'Issuer',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_ISSUER',
                'description' => 'Example: https://example.com',
            ],
            'audience' => [
                'label' => 'Audience',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_AUDIENCE',
                'default' => self::DEFAULT_AUDIENCE,
            ],
            'key_id' => [
                'label' => 'Key ID',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_KEY_ID',
            ],
            'private_key_pem' => [
                'label' => 'ES256 private key PEM',
                'type' => 'textarea',
                'constant' => 'PICKAXE_SSO_PRIVATE_KEY_PEM',
                'description' => 'Prefer defining this in wp-config.php instead of storing it in the database.',
            ],
            'embed_service_origin' => [
                'label' => 'Embed service origin',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_EMBED_SERVICE_ORIGIN',
                'description' => 'Example: https://embed.pickaxe.co',
            ],
            'embed_script_url' => [
                'label' => 'Embed script URL',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_EMBED_SCRIPT_URL',
                'description' => 'The Pickaxe embed loader URL.',
            ],
            'auth_provider' => [
                'label' => 'Auth provider label',
                'type' => 'text',
                'constant' => 'PICKAXE_SSO_AUTH_PROVIDER',
                'default' => self::DEFAULT_AUTH_PROVIDER,
            ],
            'token_ttl_seconds' => [
                'label' => 'Token TTL seconds',
                'type' => 'number',
                'constant' => 'PICKAXE_SSO_TOKEN_TTL_SECONDS',
                'default' => (string) self::DEFAULT_TOKEN_TTL_SECONDS,
                'description' => 'Allowed range: 10 to 300 seconds.',
            ],
        ];
    }

    private static function get_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        $settings = [];

        foreach (self::settings_fields() as $key => $field) {
            $constant = $field['constant'] ?? null;
            if ($constant && defined($constant)) {
                $settings[$key] = (string) constant($constant);
                continue;
            }

            $settings[$key] = isset($saved[$key]) && '' !== $saved[$key]
                ? (string) $saved[$key]
                : (string) ($field['default'] ?? '');
        }

        $settings['token_ttl_seconds'] = max(10, min(300, (int) $settings['token_ttl_seconds']));

        return $settings;
    }

    private static function missing_required_settings(array $settings): array {
        $required = ['customer_id', 'issuer', 'audience', 'key_id', 'private_key_pem'];

        return array_values(
            array_filter(
                $required,
                static function (string $key) use ($settings): bool {
                    return empty($settings[$key]);
                }
            )
        );
    }

    private static function create_login_token(WP_User $user, array $settings): string {
        $now = time();
        $subject = (string) $user->ID;
        $payload = [
            'customer_id' => $settings['customer_id'],
            'email' => $user->user_email,
            'name' => $user->display_name ?: $user->user_login,
            'external_user_id' => $subject,
            'auth_provider' => $settings['auth_provider'],
            'iss' => $settings['issuer'],
            'aud' => $settings['audience'],
            'sub' => $subject,
            'jti' => wp_generate_uuid4(),
            'iat' => $now,
            'exp' => $now + (int) $settings['token_ttl_seconds'],
        ];
        $header = [
            'alg' => 'ES256',
            'kid' => $settings['key_id'],
            'typ' => 'JWT',
        ];
        $signing_input = self::base64url_json($header) . '.' . self::base64url_json($payload);
        $signature = self::sign_es256($signing_input, $settings['private_key_pem']);

        return $signing_input . '.' . self::base64url_encode($signature);
    }

    private static function get_payload_mapping(WP_User $user, array $settings): array {
        $subject = (string) $user->ID;
        $name = $user->display_name ?: $user->user_login;

        return [
            [
                'wordpressField' => 'WP_User->ID',
                'wordpressValue' => $user->ID,
                'embedClaim' => 'sub',
                'embedValue' => $subject,
            ],
            [
                'wordpressField' => 'WP_User->ID',
                'wordpressValue' => $user->ID,
                'embedClaim' => 'external_user_id',
                'embedValue' => $subject,
            ],
            [
                'wordpressField' => 'WP_User->user_email',
                'wordpressValue' => $user->user_email,
                'embedClaim' => 'email',
                'embedValue' => $user->user_email,
            ],
            [
                'wordpressField' => 'WP_User->display_name',
                'wordpressValue' => $user->display_name,
                'embedClaim' => 'name',
                'embedValue' => $name,
            ],
            [
                'wordpressField' => 'native WordPress auth guard',
                'wordpressValue' => 'is_user_logged_in() === true',
                'embedClaim' => 'auth_provider',
                'embedValue' => $settings['auth_provider'],
            ],
            [
                'wordpressField' => 'registered customer config',
                'wordpressValue' => $settings['customer_id'],
                'embedClaim' => 'customer_id',
                'embedValue' => $settings['customer_id'],
            ],
            [
                'wordpressField' => 'registered customer config',
                'wordpressValue' => $settings['issuer'],
                'embedClaim' => 'iss',
                'embedValue' => $settings['issuer'],
            ],
            [
                'wordpressField' => 'registered customer config',
                'wordpressValue' => $settings['audience'],
                'embedClaim' => 'aud',
                'embedValue' => $settings['audience'],
            ],
        ];
    }

    private static function sign_es256(string $signing_input, string $private_key_pem): string {
        $private_key = openssl_pkey_get_private($private_key_pem);
        if (!$private_key) {
            throw new RuntimeException('Invalid ES256 private key.');
        }

        $ok = openssl_sign($signing_input, $der_signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('OpenSSL could not sign the JWT.');
        }

        return self::ecdsa_der_to_raw($der_signature, 32);
    }

    private static function ecdsa_der_to_raw(string $der_signature, int $part_length): string {
        $offset = 0;
        if (0x30 !== ord($der_signature[$offset++])) {
            throw new RuntimeException('Invalid ECDSA signature sequence.');
        }

        self::read_der_length($der_signature, $offset);
        $r = self::read_der_integer($der_signature, $offset);
        $s = self::read_der_integer($der_signature, $offset);

        return self::left_pad(self::trim_integer_padding($r), $part_length)
            . self::left_pad(self::trim_integer_padding($s), $part_length);
    }

    private static function read_der_integer(string $der, int &$offset): string {
        if (0x02 !== ord($der[$offset++])) {
            throw new RuntimeException('Invalid ECDSA integer.');
        }

        $length = self::read_der_length($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    private static function read_der_length(string $der, int &$offset): int {
        $length = ord($der[$offset++]);
        if ($length < 0x80) {
            return $length;
        }

        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) + ord($der[$offset++]);
        }

        return $length;
    }

    private static function trim_integer_padding(string $value): string {
        while (strlen($value) > 1 && "\x00" === $value[0]) {
            $value = substr($value, 1);
        }

        return $value;
    }

    private static function left_pad(string $value, int $length): string {
        if (strlen($value) > $length) {
            return substr($value, -$length);
        }

        return str_pad($value, $length, "\x00", STR_PAD_LEFT);
    }

    private static function base64url_json(array $value): string {
        return self::base64url_encode((string) wp_json_encode($value));
    }

    private static function base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

Pickaxe_Embed_SSO::init();
