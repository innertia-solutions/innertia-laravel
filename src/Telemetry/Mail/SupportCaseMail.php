<?php

namespace Innertia\Telemetry\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Innertia\Telemetry\Cases\ReviewPromptBuilder;
use Innertia\Telemetry\Models\TelemetryCase;

class SupportCaseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TelemetryCase $case,
        public readonly bool $isReopened = false,
    ) {}

    public function build(): self
    {
        $prompt = (new ReviewPromptBuilder())->forCase($this->case);
        $prefix = $this->isReopened ? '[REABIERTO] ' : '[NUEVO] ';

        return $this->subject($prefix . 'Caso: ' . $this->case->title)
            ->view('innertia-telemetry::support-case', [
                'case'   => $this->case,
                'prompt' => $prompt,
            ]);
    }
}
