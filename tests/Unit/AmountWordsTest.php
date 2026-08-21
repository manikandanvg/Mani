<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * amount_words() / tamil_number_words() — the cash-line denomination captions
 * on the Sales billing screen (board phase-1, 2026-08-21).
 */
class AmountWordsTest extends TestCase
{
    public function test_tamil_words_match_everyday_forms(): void
    {
        $this->assertSame('பத்தாயிரம்', tamil_number_words(10000));       // the board's example
        $this->assertSame('இருபத்தைந்து', tamil_number_words(25));
        $this->assertSame('இருநூற்று ஐம்பது', tamil_number_words(250));
        $this->assertSame('ஒரு லட்சம்', tamil_number_words(100000));
        $this->assertSame('இரண்டு லட்சத்து ஐம்பதாயிரம்', tamil_number_words(250000));
        $this->assertSame('பத்தாயிரத்து ஐந்நூறு', tamil_number_words(10500));
        $this->assertSame('பூஜ்ஜியம்', tamil_number_words(0));
    }

    public function test_amount_words_is_language_and_currency_aware(): void
    {
        $this->assertSame('Ten Thousand Rupees', amount_words(10000));
        $this->assertSame('பத்தாயிரம் ரூபாய்', amount_words(10000, 'ta'));
        $this->assertSame('Five Hundred Dirhams', amount_words(500, 'en', 'AED'));
        $this->assertSame('Two Lakh Fifty Thousand Rupees', amount_words(250000));
    }
}
