@props([
    'title'    => null,
    'preview'  => null,  // preheader text (hidden, shown in inbox preview)
])
@php
    $color    = config('innertia.mail.brand_color', '#6366f1');
    $logoUrl  = config('innertia.mail.logo_url', null);
    $appName  = config('app.name', 'App');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>{{ $title ?? $appName }}</title>
    <!--[if mso]>
    <noscript>
        <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    {{-- Preheader (inbox preview text, invisible in email body) --}}
    @if($preview)
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#f4f4f5;line-height:1px;">
        {{ $preview }}&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;
    </div>
    @endif

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f4f4f5;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;">

                    {{-- Logo / Brand header --}}
                    <tr>
                        <td align="center" style="padding:0 0 24px;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $appName }}" height="40"
                                     style="height:40px;max-width:180px;display:block;border:0;outline:none;text-decoration:none;" />
                            @else
                                <span style="font-size:22px;font-weight:700;color:{{ $color }};text-decoration:none;display:block;">
                                    {{ $appName }}
                                </span>
                            @endif
                        </td>
                    </tr>

                    {{-- Content card --}}
                    <tr>
                        <td style="background:#ffffff;border-radius:8px;padding:40px 40px 32px;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:24px 0 0;font-size:12px;color:#a1a1aa;line-height:1.6;">
                            &copy; {{ date('Y') }} {{ $appName }}. Todos los derechos reservados.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
