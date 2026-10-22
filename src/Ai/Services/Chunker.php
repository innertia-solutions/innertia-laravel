<?php

namespace Innertia\Ai\Services;

/**
 * Parte texto en fragmentos (~maxChars) respetando párrafos cuando puede, con
 * solape (overlap) al cortar párrafos largos. Entrada para el indexado RAG.
 */
class Chunker
{
    public function __construct(
        private int $maxChars = 1200,
        private int $overlap = 150,
    ) {}

    /** @return string[] */
    public function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $this->maxChars) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($buffer) + mb_strlen($paragraph) + 2 <= $this->maxChars) {
                $buffer = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            if (mb_strlen($paragraph) > $this->maxChars) {
                $start = 0;
                $length = mb_strlen($paragraph);
                $step = max(1, $this->maxChars - $this->overlap);
                while ($start < $length) {
                    $chunks[] = mb_substr($paragraph, $start, $this->maxChars);
                    $start += $step;
                }
            } else {
                $buffer = $paragraph;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
