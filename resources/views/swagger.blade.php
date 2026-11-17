<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trial Balance Classifier API</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"
    >

    <style>
        /* Dark theme */
        html,
        body {
            background: #111418;
            margin: 0;
        }

        .swagger-ui {
            filter: invert(88%) hue-rotate(180deg);
        }

        .swagger-ui img,
        .swagger-ui .microlight {
            filter: invert(100%) hue-rotate(180deg);
        }

        .swagger-ui .topbar {
            display: none;
        }
    </style>
</head>

<body>

<div id="swagger-ui"></div>

<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>

<script>
    SwaggerUIBundle({
        url: '/docs/openapi.yaml',   // same origin: works on any host or port (asset() would use APP_URL)
        dom_id: '#swagger-ui',
        deepLinking: true,
        docExpansion: 'list',
        defaultModelsExpandDepth: 1,
        displayRequestDuration: true,
        tryItOutEnabled: true,
        filter: true,
    });
</script>

</body>
</html>