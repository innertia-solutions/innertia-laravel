@props(['headers' => [], 'rows' => []])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="border-collapse:collapse;margin:16px 0;width:100%;">
    @if(count($headers))
    <thead>
        <tr>
            @foreach($headers as $header)
            <th style="text-align:left;padding:8px 12px;font-size:11px;font-weight:600;
                       color:#71717a;border-bottom:2px solid #e4e4e7;
                       text-transform:uppercase;letter-spacing:0.06em;white-space:nowrap;">
                {{ $header }}
            </th>
            @endforeach
        </tr>
    </thead>
    @endif
    <tbody>
        @foreach($rows as $index => $row)
        <tr style="{{ $index % 2 === 1 ? 'background-color:#fafafa;' : '' }}">
            @foreach((array) $row as $cell)
            <td style="padding:10px 12px;font-size:14px;color:#3f3f46;
                       border-bottom:1px solid #f4f4f5;line-height:1.5;">
                {!! $cell !!}
            </td>
            @endforeach
        </tr>
        @endforeach
    </tbody>
</table>
