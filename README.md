# Pickaxe Embed SSO WordPress Plugin

This plugin signs short-lived Pickaxe embed SSO tokens for logged-in WordPress users.

## Install

Copy `pickaxe-embed-sso` into:

```text
wp-content/plugins/pickaxe-embed-sso
```

Then activate **Pickaxe Embed SSO** in WordPress admin.

## Configure

Open **Settings -> Pickaxe Embed SSO** and set:

- Customer ID
- Issuer
- Audience, usually `pickaxe-embed`
- Key ID
- ES256 private key PEM
- Embed service origin
- Embed script URL

For production, prefer defining secrets in `wp-config.php` so the private key is not stored in the
database:

```php
define('PICKAXE_SSO_CUSTOMER_ID', 'acme-nextauth-demo');
define('PICKAXE_SSO_ISSUER', 'https://example.com');
define('PICKAXE_SSO_AUDIENCE', 'pickaxe-embed');
define('PICKAXE_SSO_KEY_ID', 'acme-key-2026-04');
define('PICKAXE_SSO_PRIVATE_KEY_PEM', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----");
define('PICKAXE_SSO_EMBED_SERVICE_ORIGIN', 'https://embed.pickaxe.co');
define('PICKAXE_SSO_EMBED_SCRIPT_URL', 'https://embed.pickaxe.co/embed-loader.js');
```

Optional constants:

```php
define('PICKAXE_SSO_AUTH_PROVIDER', 'wordpress-native');
define('PICKAXE_SSO_TOKEN_TTL_SECONDS', 60);
```

## Use

Add this shortcode to a page:

```text
[pickaxe_embed]
```

The shortcode loads the configured embed script and, for logged-in WordPress users, provides a
nonce-authenticated JWT callback. Logged-out visitors do not receive an SSO token.

## Token Endpoint

The plugin exposes:

```text
GET /wp-json/pickaxe-sso/v1/embed-token
```

The request must be made by a logged-in WordPress user and include a valid `X-WP-Nonce` header.

The response shape is:

```json
{
  "token": "eyJ...",
  "expiresInSeconds": 60,
  "payloadMapping": []
}
```

## WordPress Field Mapping

- `WP_User->ID` maps to `sub`
- `WP_User->ID` maps to `external_user_id`
- `WP_User->user_email` maps to `email`
- `WP_User->display_name` maps to `name`
- native WordPress auth maps to `auth_provider = wordpress-native`
- configured values supply `customer_id`, `iss`, `aud`, and `kid`

The JWT is signed as ES256 using the configured private key.
