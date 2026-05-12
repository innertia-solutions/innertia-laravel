@props(['code'])
@php
    $color = config('innertia.mail.brand_color', '#6366f1');
@endphp
<table cellpadding="0" cellspacing="0" role="presentation" style="margin:28px auto;">
    <tr>
        <td align="center"
            style="background-color:#f4f4f5;border-radius:8px;border:2px dashed #d4d4d8;padding:20px 40px;">
            <span style="font-size:38px;font-weight:700;letter-spacing:0.25em;color:{{ $color }};font-family:'Courier New',Courier,monospace;display:block;">
                {{ $code }}
            </span>
        </td>
    </tr>
</table>
