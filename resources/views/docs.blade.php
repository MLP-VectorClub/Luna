<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MLP Vector Club OpenAPI Documentation</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@3/swagger-ui.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Arimo:400,700,400italic&display=swap">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width,height=device-height,initial-scale=1,maximum-scale=2">
    <style>
        .notice {
            font-family: "Arimo", sans-serif;
            display: block;
            margin: 0;
            border: 2px solid;
            border-radius: 7px;
            color: #c00;
            background-color: #fdd;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
        }
    </style>
</head>

<body>
<div class="notice">This API is still in development, everything is subject to change and the database may be wiped
    multiple times before a stable release. Use with caution.
</div>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@3/swagger-ui-bundle.js"></script>
<script type="text/javascript">
    window.onload = function () {
        var cookieName = 'XSRF-TOKEN';
        function getCookie(name) {
            var value = "; " + document.cookie;
            var parts = value.split("; " + name + "=");
            if (parts.length === 2) return decodeURIComponent(parts.pop().split(";").shift());
        }
        SwaggerUIBundle({
            deepLinking: true,
            dom_id: '#swagger-ui',
            showExtensions: true,
            showCommonExtensions: true,
            url: '{!! $file_url !!}',
            requestInterceptor: req => {
                req.headers['X-' + cookieName] = getCookie(cookieName);
                return req;
            }
        });
    };
</script>
</body>
</html>
