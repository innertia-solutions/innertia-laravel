<x-innertia::mail.layout :preview="$preview ?? null">

    @foreach($blocks as $block)

        @if($block['type'] === 'title')
            <h1 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#18181b;line-height:1.3;">
                {{ $block['text'] }}
            </h1>

        @elseif($block['type'] === 'line')
            <p style="margin:0 0 16px;font-size:15px;color:#71717a;line-height:1.6;">
                {!! $block['text'] !!}
            </p>

        @elseif($block['type'] === 'button')
            <x-innertia::mail.button :url="$block['url']">
                {{ $block['label'] }}
            </x-innertia::mail.button>

        @elseif($block['type'] === 'table')
            <x-innertia::mail.table
                :headers="$block['headers']"
                :rows="$block['rows']"
            />

        @elseif($block['type'] === 'panel')
            <x-innertia::mail.panel :type="$block['panelType']">
                {{ $block['text'] }}
            </x-innertia::mail.panel>

        @endif

    @endforeach

</x-innertia::mail.layout>
