<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Desarrolladores</title>
    <style>body { margin: 0; }</style>
</head>
<body>
    <div id="api-reference"></div>
    <noscript>Descarga la especificación en <a href="{{ $specUrl }}">{{ $specUrl }}</a>.</noscript>
    <script src="{{ $scalarUrl }}"></script>
    <script>
        Scalar.createApiReference('#api-reference', { url: @json($specUrl), hideClientButton: false });
    </script>
</body>
</html>
