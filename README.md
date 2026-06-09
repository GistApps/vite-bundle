<div>
  <p align="center">
  <img width="100" src="https://raw.githubusercontent.com/lhapaipai/vite-bundle/main/docs/symfony-vite.svg" alt="Symfony logo">
  </p>
  <p align="center">
    <img src="https://img.shields.io/packagist/v/pentatrion/vite-bundle?style=flat-square&logo=packagist">
    <img src="https://img.shields.io/github/actions/workflow/status/lhapaipai/symfony-vite-dev/vite-bundle-ci.yml?style=flat-square&label=vite-bundle%20CI&logo=github">

  </p>
</div>



# ViteBundle : Symfony integration with Vite

> [!IMPORTANT]
> This repository is a "subtree split": a read-only subset of that main repository [symfony-vite-dev](https://github.com/lhapaipai/symfony-vite-dev) which delivers to packagist only the necessary code.

> [!IMPORTANT]
> If you want to open issues, contribute, make PRs or consult examples you will have to go to the [symfony-vite-dev](https://github.com/lhapaipai/symfony-vite-dev) repository.


This bundle helps you render all the dynamic `script` and `link` tags needed.
Essentially, it provides two twig functions to load the correct scripts into your templates.

## Installation

Install the bundle with:

```console
composer require pentatrion/vite-bundle
```

```bash
npm install

# start your vite dev server
npm run dev
```

Add these twig functions in any template or base layout where you need to include a JavaScript entry:

```twig
{% block stylesheets %}
    {{ vite_entry_link_tags('app') }}
{% endblock %}

{% block javascripts %}
    {{ vite_entry_script_tags('app') }}

    {# if you are using React, you have to replace with this #}
    {{ vite_entry_script_tags('app', { dependency: 'react' }) }}
{% endblock %}
```

[Read the Docs to Learn More](https://symfony-vite.pentatrion.com).

## Remote Manifest (CDN) Support

By default, the bundle reads `entrypoints.json` and `manifest.json` from your local `public/` directory. You can instead serve these files from a remote URL (e.g. a Cloudflare CDN) using `manifest_prefix_url`.

### Basic setup

```yaml
# config/packages/pentatrion_vite.yaml
pentatrion_vite:
    manifest_prefix_url: 'https://cdn.example.com/build'
```

The bundle will fetch `https://cdn.example.com/build/entrypoints.json` and `https://cdn.example.com/build/manifest.json` on each request (when caching is disabled).

### With multiple named configs

```yaml
pentatrion_vite:
    default_config: app
    configs:
        app:
            build_directory: build
            manifest_prefix_url: 'https://cdn.example.com/build'
        admin:
            build_directory: build-admin
            manifest_prefix_url: 'https://cdn.example.com/build-admin'
```

### With caching

Since the remote files can be large and fetched on every request, enabling the cache pool is strongly recommended in production:

```yaml
pentatrion_vite:
    cache: true
    manifest_prefix_url: 'https://cdn.example.com/build'
```

> **Note:** With `cache: true`, the manifest is fetched once and stored until the cache is cleared. If your CDN files can update independently of a Symfony deploy (i.e. without a `cache:warmup`), use the cache-refresh endpoint below to invalidate on demand.

### Cache-refresh endpoint

When `cache: true` is set and your CDN files update independently, you can configure a secret token and expose an endpoint that clears the cached manifests.

**1. Generate a secret token and add it to your `.env`:**

```dotenv
# .env.local
VITE_CACHE_REFRESH_TOKEN=your-secret-token-here
```

**2. Configure the bundle:**

```yaml
# config/packages/pentatrion_vite.yaml
pentatrion_vite:
    cache: true
    manifest_prefix_url: 'https://cdn.example.com/build'
    cache_refresh_token: '%env(VITE_CACHE_REFRESH_TOKEN)%'
```

**3. Add a route in your app:**

```yaml
# config/routes/pentatrion_vite_cache.yaml
vite_cache_refresh:
    path: /_vite/cache-refresh
    controller: Pentatrion\ViteBundle\Controller\CacheRefreshController::refresh
    methods: [POST]
```

**4. Call the endpoint after pushing updated files to your CDN:**

```bash
curl -X POST https://yoursite.com/_vite/cache-refresh \
     -H "Authorization: Bearer your-secret-token-here"
```

A successful response:

```json
{
    "message": "Vite manifest cache cleared.",
    "keys": ["_default.entrypoints", "_default.manifest"]
}
```

The endpoint returns:
- `200` — cache cleared (or cache not enabled, nothing to do)
- `401` — wrong or missing token
- `404` — `cache_refresh_token` not configured

> **Security:** The token is compared with `hash_equals()` to prevent timing attacks. Keep `VITE_CACHE_REFRESH_TOKEN` out of version control and rotate it if exposed.

## Ecosystem

| Package                                                                 | Description               |
| ----------------------------------------------------------------------- | :------------------------ |
| [vite-plugin-symfony](https://github.com/lhapaipai/vite-plugin-symfony) | Vite plugin (read-only)   |
| [symfony-vite-dev](https://github.com/lhapaipai/symfony-vite-dev)       | Package for contributors  |

## License

[MIT](LICENSE).
