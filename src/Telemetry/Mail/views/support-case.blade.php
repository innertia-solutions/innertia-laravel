<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $case->title }}</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color:#111; margin:0; padding:16px">
    <h2>{{ $case->title }}</h2>
    <ul>
        <li><strong>Clase:</strong> {{ $case->exception_class }}</li>
        <li><strong>Ubicación:</strong> {{ $case->file }}:{{ $case->line }}</li>
        <li><strong>Veces visto:</strong> {{ $case->times_seen }}</li>
        <li><strong>Primero:</strong> {{ $case->first_seen }}</li>
        <li><strong>Último:</strong> {{ $case->last_seen }}</li>
    </ul>

    <h3>Prompt de revisión (pégalo a la IA)</h3>
    <pre style="white-space:pre-wrap;background:#f6f8fa;padding:12px;border-radius:6px">{{ $prompt }}</pre>
</body>
</html>
