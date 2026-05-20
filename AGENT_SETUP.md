# Pickaxe WordPress SSO Setup Guide

This guide is for the technical agent helping a WordPress site owner install and test the Pickaxe
Embed SSO plugin.

## Goal

Logged-in WordPress users should be recognized by the Pickaxe embed without signing in again inside
the embed.

The WordPress plugin signs a short-lived JWT for the current WordPress user. Pickaxe verifies that
JWT using the customer's registered SSO config and creates an embed session.

## Files

Download the plugin zip from the GitHub release:

```text
https://github.com/nathanielmhld/pickaxe-embed-sso-wordpress/releases
```

Use:

```text
pickaxe-embed-sso.zip
```

## WordPress Install

1. Log in to WordPress admin.
2. Go to **Plugins -> Add New -> Upload Plugin**.
3. Upload `pickaxe-embed-sso.zip`.
4. Activate **Pickaxe Embed SSO**.
5. Go to **Settings -> Pickaxe Embed SSO**.

## WordPress Plugin Settings

Click **Generate WordPress SSO Config** in the plugin settings page. This creates the WordPress-side
ES256 key pair and fills most settings automatically.

Then add the deployment ID from the normal Pickaxe embed snippet:

```text
Default deployment ID: deployment-...
```

Generated defaults:

```text
Issuer: WordPress home URL origin
Audience: pickaxe-embed
Key ID: wordpress-sso-key-...
Embed service origin: https://embed.pickaxe.co
Embed script URL: https://studio.pickaxe.co/api/embed/bundle.js
Auth provider label: wordpress-native
Token TTL seconds: 60
```

For production, prefer defining secrets in `wp-config.php` instead of storing the private key in the
WordPress database:

```php
define('PICKAXE_SSO_CUSTOMER_ID', '...');
define('PICKAXE_SSO_DEFAULT_DEPLOYMENT_ID', 'deployment-your-id');
define('PICKAXE_SSO_ISSUER', 'https://customer-site.example');
define('PICKAXE_SSO_AUDIENCE', 'pickaxe-embed');
define('PICKAXE_SSO_KEY_ID', '...');
define('PICKAXE_SSO_PRIVATE_KEY_PEM', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----");
define('PICKAXE_SSO_EMBED_SERVICE_ORIGIN', '...');
define('PICKAXE_SSO_EMBED_SCRIPT_URL', '...');
define('PICKAXE_SSO_EMBED_MODE', 'script');
define('PICKAXE_SSO_DEFAULT_PICKAXE_ID', '...');
define('PICKAXE_SSO_IFRAME_SRC', 'https://studio.pickaxe.co/_embed/your-pickaxe-id?d=deployment-your-id');
define('PICKAXE_SSO_AUTH_PROVIDER', 'wordpress-native');
define('PICKAXE_SSO_TOKEN_TTL_SECONDS', 60);
```

## Pickaxe SSO Config

On the Pickaxe side, create or update the workspace/deployment SSO configuration for this customer.

The Pickaxe config must match the WordPress plugin settings exactly:

```text
enabled: true
issuer: same value as PICKAXE_SSO_ISSUER
audience: pickaxe-embed
key_id: same value as PICKAXE_SSO_KEY_ID
public_key: public key matching the WordPress private key
allowed_origins: include the WordPress site origin
```

Example:

```text
enabled: true
issuer: https://www.customer-site.com
audience: pickaxe-embed
key_id: acme-wordpress-key-2026-04
allowed_origins:
  - https://www.customer-site.com
```

Important details:

- The WordPress plugin stores the **private** key.
- Pickaxe stores the matching **public** key.
- `issuer`, `audience`, and `key_id` must match exactly.
- `allowed_origins` must include the browser origin where the embed page is loaded.
- If the WordPress site uses both `https://example.com` and `https://www.example.com`, include the
  actual origin used by the embed page.

## JWT Contract

The plugin signs an ES256 JWT with this payload mapping:

```text
WP_User->ID           -> sub
WP_User->ID           -> external_user_id
WP_User->user_email   -> email
WP_User->display_name -> name
wordpress-native      -> auth_provider
configured value      -> customer_id
configured value      -> iss
configured value      -> aud
generated UUID        -> jti
current timestamp     -> iat
short expiration      -> exp
```

The JWT header is:

```json
{
  "alg": "ES256",
  "kid": "configured-key-id",
  "typ": "JWT"
}
```

## Add The Embed To A Page

Add this shortcode to the WordPress page where the embed should appear:

```text
[pickaxe_embed]
```

The shortcode uses the default deployment ID from plugin settings. You can override it per page:

```text
[pickaxe_embed deployment_id="deployment-your-id"]
```

Use the same `deployment-...` ID from the normal Pickaxe embed snippet. The production embed bundle scans for DOM nodes whose IDs start with `deployment-`, so the deployment ID must be configured somewhere.

When a visitor is logged in, the shortcode exposes a nonce-protected token callback to the embed.
When a visitor is logged out, the plugin does not mint a token, and the embed should fall back to
the normal unauthenticated/login behavior.

For a raw iframe embed, use:

```text
[pickaxe_embed mode="iframe" iframe_src="https://studio.pickaxe.co/_embed/your-pickaxe-id?d=deployment-your-id"]
```

Iframe mode uses the same JWT contract. The difference is transport: the iframe sends a
`pickaxe:sso:request` message to the parent page, the WordPress plugin replies with the signed JWT,
and the iframe exchanges it with Pickaxe for an embed session token. Do not put the JWT in the iframe
URL.

## Test Plan

1. Open the WordPress page while logged out.
2. Confirm the embed loads without SSO and does not receive a token.
3. Log in as a normal WordPress user.
4. Open the page containing `[pickaxe_embed]`.
5. Confirm the browser calls:

```text
/wp-json/pickaxe-sso/v1/embed-token
```

6. Confirm that endpoint returns:

```json
{
  "token": "eyJ...",
  "expiresInSeconds": 60
}
```

7. Confirm the embed exchanges that token with Pickaxe and shows the logged-in WordPress user's
   identity.

## Quick Endpoint Check

In the browser console while logged in:

```js
fetch('/wp-json/pickaxe-sso/v1/embed-token', {
  credentials: 'same-origin',
  headers: {
    Accept: 'application/json',
    'X-WP-Nonce': window.PickaxeEmbedSSOConfig?.nonce
  }
}).then(r => r.json()).then(console.log)
```

Expected result: a JSON response with a `token`.

If logged out, the endpoint should return `401`.

## Troubleshooting

### Token endpoint returns 401

The WordPress visitor is not logged in, or the REST nonce is missing/invalid.

Check:

- user is logged into WordPress
- the page is rendered by WordPress, not cached as a static anonymous page
- the request includes `X-WP-Nonce`

### Pickaxe rejects the token

Check that Pickaxe SSO config matches the WordPress plugin:

- `issuer`
- `audience`
- `key_id`
- public key
- allowed origin

Also confirm the WordPress server clock is reasonably accurate, since the JWT uses `iat` and `exp`.

### Signature verification fails

The public key in Pickaxe does not match the private key in WordPress, or the private key is not an
ES256-compatible EC private key.

Use a P-256 EC key pair.

### Embed loads but user is not recognized

Check whether the Pickaxe workspace allows auto-creating users from SSO or requires the user email
to already exist. The plugin sends both:

```text
external_user_id = WordPress user ID
email = WordPress user email
```

Pickaxe-side user matching policy determines whether that creates, links, or rejects the user.

For iframe mode, also confirm:

- the iframe source origin is the actual Pickaxe iframe origin, usually `https://studio.pickaxe.co`
- the WordPress origin is present in Pickaxe `allowed_origins`
- the iframe code supports the `pickaxe:sso:request` / `pickaxe:sso:response` bridge

## Security Notes

- Never expose the private key to browser JavaScript.
- Prefer storing the private key in `wp-config.php` or server-managed secrets.
- Keep token TTL short, usually 60 seconds.
- Use HTTPS.
- Test on staging before installing on production.
