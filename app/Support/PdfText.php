<?php

namespace App\Support;

/**
 * Text helper for the dompdf templates. dompdf picks ONE font per element and never
 * falls back per character, so a Tamil name inside a DejaVu cell prints as boxes.
 * Any string that carries Tamil letters is wrapped in a span set to the embedded
 * Noto Sans Tamil face (storage/fonts/NotoSansTamil-Regular.ttf), which also covers
 * Latin, digits, ₹ and punctuation, so mixed strings stay in one font.
 *
 * dompdf has no OpenType shaping: prefixed vowel signs (ெ ே ை) print after their
 * consonant. The text is complete and readable, but not typeset like a browser would.
 */
final class PdfText
{
    /** Escaped HTML for a cell / label, in the Tamil face when the text needs it. */
    public static function html(mixed $value): string
    {
        $text = (string) ($value ?? '');
        $escaped = e($text);

        return preg_match('/\p{Tamil}/u', $text) ? '<span class="ta">'.$escaped.'</span>' : $escaped;
    }
}
