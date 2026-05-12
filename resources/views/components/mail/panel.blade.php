@props(['type' => 'info'])
@php
    $styles = [
        'info'    => ['bg' => '#eff6ff', 'border' => '#93c5fd', 'text' => '#1e40af'],
        'success' => ['bg' => '#f0fdf4', 'border' => '#86efac', 'text' => '#166534'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'text' => '#92400e'],
        'danger'  => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'text' => '#991b1b'],
    ];
    $s = $styles[$type] ?? $styles['info'];
@endphp
<table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;margin:16px 0;">
    <tr>
        <td style="background-color:{{ $s['bg'] }};border-left:4px solid {{ $s['border'] }};
                   border-radius:4px;padding:14px 18px;">
            <span style="font-size:14px;color:{{ $s['text'] }};line-height:1.6;display:block;">
                {{ $slot }}
            </span>
        </td>
    </tr>
</table>
