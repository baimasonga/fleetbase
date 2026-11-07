<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Cards\CardData;
use Fleetbase\Pod\Support\Cards\CardSheetRenderer;
use Fleetbase\Pod\Support\Cards\ProgrammeCard;
use Fleetbase\Pod\Support\Cards\QrCode;
use Fleetbase\Pod\Tests\TestCase;

final class CardSheetTest extends TestCase
{
    private function card(string $name = 'Aminata Kamara', int $issue = 1): CardData
    {
        $key = ProgrammeCard::programmeKey('secret', 'programme_k7d2xq9');

        return new CardData(ProgrammeCard::issue($key, 'programme_k7d2xq9', 'beneficiary_5e60aa3', $issue), $name, 'beneficiary_5e60aa3', $issue, 'Rice Seed and Fertiliser Support', '2027 Main Season', 'KLH-100', 'female', 1980, 'Baiwalla', 'Dea Chiefdom, Kailahun', 'Example NGO');
    }

    public function testACardCodeFitsAQrCodeWithFinderPatterns(): void
    {
        $matrix = QrCode::matrix($this->card()->code);

        $size = count($matrix);
        $this->assertGreaterThanOrEqual(25, $size);
        $this->assertSame(0, ($size - 17) % 4, 'QR sizes are 17 + 4 x version');
        // The three 7x7 finder squares have dark corners.
        $this->assertTrue($matrix[0][0]);
        $this->assertTrue($matrix[0][$size - 1]);
        $this->assertTrue($matrix[$size - 1][0]);
        $this->assertFalse($matrix[7][7], 'separator next to the top-left finder is light');
    }

    public function testDrawsPngAndSvg(): void
    {
        $code = $this->card()->code;
        $png  = QrCode::png($code, 4);
        $info = getimagesizefromstring($png);

        $this->assertSame('image/png', $info['mime']);
        $this->assertSame((count(QrCode::matrix($code)) + 8) * 4, $info[0], 'quiet zone of 4 modules each side');

        $svg = QrCode::svg($code, 20);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="20mm"', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function testEightCardsAPageAndEveryCodePrinted(): void
    {
        $cards = [];
        for ($i = 0; $i < 9; $i++) {
            $cards[] = $this->card('Farmer ' . $i);
        }

        $html = (new CardSheetRenderer())->render($cards);

        $this->assertSame(2, substr_count($html, '<table class="sheet">'), '9 cards make 2 pages');
        $this->assertSame(1, substr_count($html, 'class="break"'));
        $this->assertSame(9, substr_count($html, 'class="card"'));
        $this->assertSame(9, substr_count($html, 'data:image/png;base64,'));
        $this->assertSame(1, substr_count($html, 'slot empty'), 'the odd card gets an empty neighbour');
        $this->assertStringContainsString($cards[0]->code, $html);
    }

    public function testEscapesNamesAndMarksReplacementCards(): void
    {
        $html = (new CardSheetRenderer())->render([$this->card('<b>Mariama</b> & Co', 3)]);

        $this->assertStringContainsString('&lt;b&gt;Mariama&lt;/b&gt; &amp; Co', $html);
        $this->assertStringNotContainsString('<b>Mariama', $html);
        $this->assertStringContainsString('Replacement card no. 3', $html);
    }

    public function testRefusesAnEmptySheet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CardSheetRenderer())->render([]);
    }
}
