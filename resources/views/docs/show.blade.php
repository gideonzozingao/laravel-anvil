{{--
    Swagger UI shell for Laravel Anvil's generated OpenAPI docs.

    Published copy: resources/views/vendor/anvil/docs/show.blade.php
    (run `php artisan vendor:publish --tag=anvil-views` to get one).
    Laravel's view finder prefers the published copy automatically, so this
    file can be edited freely per-project without touching the package.

    Variables passed in by DocsController::render():
      $urls       list<array{name: string, url: string}>  every available version
      $primary    string   the version this page loads by default
      $title      string   app/spec title
      $uiVersion  string   swagger-ui-dist version, used only for the CDN fallback
      $assetBase  ?string  local asset path set by anvil:install:swagger-ui,
                           or null to fall back to the CDN
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title }} — API Documentation ({{ $primary }})</title>

    @if ($assetBase)
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css" />
    @else
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css" />
    @endif

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        @if (count($urls) === 1)
            #swagger-ui .topbar {
                display: none;
            }
        @else
            #swagger-ui .topbar {
                background: #16213e;
            }
        @endif

        .anvil-header {
            background: #1a1a2e;
            color: #fff;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .anvil-logo {
            width: 46px;
            height: 46px;
            flex: 0 0 auto;
            display: block;
        }

        .anvil-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1;
        }

        .anvil-divider {
            width: 1px;
            height: 26px;
            background: rgba(255, 255, 255, 0.18);
            margin: 0 2px;
        }

        .anvil-sub {
            font-size: 13px;
            font-weight: 400;
            opacity: 0.55;
            letter-spacing: 0.2px;
        }

        .anvil-version {
            margin-left: auto;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.6px;
            background: rgba(255, 255, 255, 0.10);
            padding: 5px 11px;
            border-radius: 999px;
        }
    </style>
</head>

<body>
    <header class="anvil-header">
        @include('anvil::docs.partials.logo')
        <span class="anvil-title">{{ $title }}</span>
        <span class="anvil-divider"></span>
        <span class="anvil-sub">API Documentation</span>
        <span class="anvil-version">{{ $primary }}</span>
    </header>

    <div id="swagger-ui"></div>

    @if ($assetBase)
        <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
        <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js"></script>
    @else
        <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
        <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js"></script>
    @endif

    <script>
        window.onload = () => {
            SwaggerUIBundle({
                urls: @json($urls),
                "urls.primaryName": @json($primary),
                dom_id: '#swagger-ui',
                presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                layout: 'StandaloneLayout',
                deepLinking: true,
                displayRequestDuration: true,
                defaultModelsExpandDepth: 2,
                defaultModelExpandDepth: 2,
                tryItOutEnabled: true,
                filter: true,
                syntaxHighlight: {
                    activate: true,
                    theme: 'agate'
                },
            });
        };
    </script>
</body>

</html>
