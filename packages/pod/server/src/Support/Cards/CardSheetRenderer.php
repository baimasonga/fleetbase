<?php

namespace Fleetbase\Pod\Support\Cards;

/**
 * Lays farmer cards out on A4 sheets as HTML for the PDF renderer
 * (AGRI-INPUTS.md §7.2).
 *
 * Each card is ID-card size (85.6 × 54 mm), 8 per page in 2 columns × 4 rows,
 * with thin cut lines. The QR code (a PNG; dompdf mis-sizes SVG) holds the signed card code; the code is
 * also printed in small type so it can be typed in if a camera can't read it.
 *
 * The HTML only uses what dompdf supports: tables, absolute sizes in mm,
 * images as data URIs, and the built-in DejaVu Sans font.
 */
final class CardSheetRenderer
{
    public const PER_PAGE = 8;

    /**
     * @param list<CardData> $cards
     */
    public function render(array $cards, string $title = 'Farmer cards'): string
    {
        if (!$cards) {
            throw new \InvalidArgumentException('There are no cards to print.');
        }

        $pages = [];
        foreach (array_chunk($cards, self::PER_PAGE) as $pageCards) {
            $rows = [];
            foreach (array_chunk($pageCards, 2) as $pair) {
                $cells = array_map(fn (CardData $card) => '<td class="slot">' . $this->card($card) . '</td>', $pair);
                if (count($cells) === 1) {
                    $cells[] = '<td class="slot empty"></td>';
                }
                $rows[] = '<tr>' . implode('', $cells) . '</tr>';
            }
            $pages[] = '<table class="sheet">' . implode('', $rows) . '</table>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $this->e($title) . '</title><style>' . $this->css() . '</style></head><body>'
            . implode('<div class="break"></div>', $pages)
            . '</body></html>';
    }

    private function card(CardData $card): string
    {
        $details = [];
        if ($card->farmerRef) {
            $details[] = 'Ref ' . $this->e($card->farmerRef);
        }
        $person = array_filter([
            $card->sex ? ucfirst($card->sex) : null,
            $card->birthYear ? 'born ' . $card->birthYear : null,
        ]);
        if ($person) {
            $details[] = $this->e(implode(', ', $person));
        }

        $photo = $card->photoDataUri
            ? '<img class="photo" src="' . $this->e($card->photoDataUri) . '" alt="">'
            : '<div class="photo nophoto">PHOTO</div>';

        $issue = $card->issueNumber > 1 ? '<div class="reissue">Replacement card no. ' . $card->issueNumber . '</div>' : '';

        return '<div class="card">'
            . '<div class="head">' . $this->e($card->programmeName) . ($card->season ? ' &middot; ' . $this->e($card->season) : '') . '</div>'
            . '<table class="body"><tr>'
            . '<td class="left">' . $photo . '</td>'
            . '<td class="middle">'
            . '<div class="name">' . $this->e($card->name) . '</div>'
            . ($details ? '<div class="line">' . implode(' &middot; ', $details) . '</div>' : '')
            . ($card->village ? '<div class="line">' . $this->e($card->village) . '</div>' : '')
            . ($card->placeLine ? '<div class="line">' . $this->e($card->placeLine) . '</div>' : '')
            . '<div class="id">' . $this->e($card->beneficiaryId) . '</div>'
            . $issue
            . '</td>'
            . '<td class="right"><img class="qr" src="' . QrCode::pngDataUri($card->code) . '" alt=""></td>'
            . '</tr></table>'
            . '<div class="code">' . $this->e($card->code) . '</div>'
            . '<div class="foot">Bring this card to collect your inputs. Do not give it to anyone else.'
            . ($card->issuer ? ' Issued by ' . $this->e($card->issuer) . '.' : '') . '</div>'
            . '</div>';
    }

    private function css(): string
    {
        return <<<'CSS'
@page { size: A4 portrait; margin: 12mm 15mm; }
body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #111; }
.break { page-break-after: always; }
table.sheet { border-collapse: collapse; }
td.slot { width: 85.6mm; height: 54mm; padding: 0; border: 0.2mm dashed #999; vertical-align: top; }
td.slot.empty { border: 0; }
.card { width: 85.6mm; height: 54mm; position: relative; overflow: hidden; }
.head { background: #1f5c2e; color: #fff; font-size: 7.5pt; font-weight: bold; padding: 1.4mm 2.5mm; height: 3.4mm; overflow: hidden; white-space: nowrap; }
table.body { border-collapse: collapse; margin: 1.6mm 2mm 0 2mm; }
td.left { width: 19mm; vertical-align: top; padding: 0; }
td.middle { vertical-align: top; padding: 0 1.5mm; width: 37mm; }
td.right { width: 24mm; vertical-align: top; padding: 0; }
.photo { width: 18mm; height: 22mm; border: 0.2mm solid #999; }
.nophoto { font-size: 5.5pt; color: #999; text-align: center; padding-top: 9.5mm; height: 12.5mm; }
.qr { width: 23mm; height: 23mm; }
.name { font-size: 9pt; font-weight: bold; line-height: 1.15; margin-bottom: 0.8mm; }
.line { font-size: 6.5pt; line-height: 1.25; }
.id { font-size: 6.5pt; font-family: "DejaVu Sans Mono", monospace; margin-top: 0.8mm; }
.reissue { font-size: 6pt; font-weight: bold; color: #8a1c1c; margin-top: 0.6mm; }
.code { font-family: "DejaVu Sans Mono", monospace; font-size: 4.6pt; color: #333; margin: 1mm 2mm 0 2mm; word-wrap: break-word; }
.foot { position: absolute; bottom: 1.3mm; left: 2mm; right: 2mm; font-size: 5.4pt; color: #444; line-height: 1.2; }
CSS;
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
