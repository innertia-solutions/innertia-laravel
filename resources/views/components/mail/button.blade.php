@props(['url', 'align' => 'center'])
@php
    $color = config('innertia.mail.brand_color', '#6366f1');
@endphp
<table cellpadding="0" cellspacing="0" role="presentation" style="margin:28px auto;">
    <tr>
        <td align="{{ $align }}" style="border-radius:6px;background-color:{{ $color }};">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:200px;" arcsize="13%"
                fill="true" fillcolor="{{ $color }}" stroke="false">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:sans-serif;font-size:15px;font-weight:600;">{{ $slot }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}"
               style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;background-color:{{ $color }};mso-hide:all;">
                {{ $slot }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
