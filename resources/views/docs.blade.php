<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentasi API Rental Mobil</title>
    {{-- Swagger UI dikunci ke versi 5.17.14 dan hash isinya (SRI): bila CDN mengirim berkas lain, browser menolaknya. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css"
          integrity="sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn" crossorigin="anonymous">
</head>
<body>
    <div id="swagger"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"
            integrity="sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep" crossorigin="anonymous"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: '/openapi.yaml',
            dom_id: '#swagger',
            persistAuthorization: true,
        });
    </script>
</body>
</html>
