<?php

namespace Fleetbase\Pod\Support\Distribution;

/**
 * The distribution day report ("Event ePOD", AGRI-INPUTS.md §11): who received
 * what, totals by sex and age band, the stock reconciliation, discrepancies,
 * witnesses and the seal hash. HTML for dompdf, A4 landscape.
 */
final class EventReportRenderer
{
    private const FLAGS = [
        'outside_geofence'      => 'outside the area',
        'no_location'           => 'no GPS',
        'no_reference_location' => 'no household GPS',
        'identity_l1'           => 'ID level 1 (witness)',
        'proxy'                 => 'proxy',
    ];

    private const AGE_BANDS = ['15_24' => '15–24', '25_35' => '25–35', '36_59' => '36–59', '60_plus' => '60+', 'under_15' => 'Under 15', 'unknown' => 'Unknown'];

    /**
     * @param array $report see DistributionService / controller for the shape
     */
    public function render(array $report): string
    {
        $e      = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $num    = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
        $totals = $report['totals'] ?? [];
        $served = $totals['served'] ?? ['total' => 0, 'by_sex' => [], 'by_age_band' => [], 'youth_15_35' => 0];

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e($report['event']['name'] ?? 'Distribution report') . '</title><style>' . $this->css() . '</style></head><body>';

        $html .= '<h1>Distribution report: ' . $e($report['event']['name'] ?? '') . '</h1>';
        $html .= '<table class="meta"><tr>'
            . '<td><b>Programme</b><br>' . $e($report['programme']['name'] ?? '') . ($report['programme']['season'] ?? null ? '<br>' . $e($report['programme']['season']) : '') . '</td>'
            . '<td><b>' . (($report['event']['kind'] ?? 'point') === 'route' ? 'Door-to-door route from' : 'Distribution point') . '</b><br>' . $e($report['site']['name'] ?? '') . '<br>' . $e($report['site']['place'] ?? '') . '</td>'
            . '<td><b>Opened' . (isset($report['event']['timezone']) ? ' (' . $e($report['event']['timezone']) . ')' : '') . '</b><br>' . $e($report['event']['opened_at'] ?? '-') . '<br><b>Closed</b><br>' . $e($report['event']['closed_at'] ?? 'Still open') . '</td>'
            . '<td><b>Status</b><br>' . $e(ucfirst($report['event']['status'] ?? '')) . '<br><b>Reference</b><br>' . $e($report['event']['public_id'] ?? '') . '</td>'
            . '</tr></table>';

        // Summary
        $html .= '<h2>Summary</h2><table class="grid"><tr><th>Farmers assigned</th><th>Farmers served</th><th>Women</th><th>Men</th><th>Youth (15–35)</th><th>Partial</th><th>Failed attempts</th><th>Proxy</th><th>Flagged</th></tr><tr>'
            . '<td>' . (int) ($totals['assigned'] ?? 0) . '</td><td>' . (int) $served['total'] . '</td><td>' . (int) ($served['by_sex']['female'] ?? 0) . '</td><td>' . (int) ($served['by_sex']['male'] ?? 0) . '</td><td>' . (int) $served['youth_15_35'] . '</td>'
            . '<td>' . (int) ($totals['partial'] ?? 0) . '</td><td>' . (int) ($totals['failed_attempts'] ?? 0) . '</td><td>' . (int) ($totals['proxy'] ?? 0) . '</td><td>' . (int) ($totals['flagged'] ?? 0) . '</td></tr></table>';

        $html .= '<table class="grid half"><tr><th>Age band</th>';
        foreach (self::AGE_BANDS as $label) {
            $html .= '<th>' . $e($label) . '</th>';
        }
        $html .= '</tr><tr><td>Farmers served</td>';
        foreach (array_keys(self::AGE_BANDS) as $band) {
            $html .= '<td>' . (int) ($served['by_age_band'][$band] ?? 0) . '</td>';
        }
        $html .= '</tr></table>';

        // Reconciliation
        $rec = $report['reconciliation'] ?? null;
        $html .= '<h2>Stock reconciliation</h2>';
        if ($rec) {
            $html .= '<table class="grid"><tr><th>Item</th><th>Lot</th><th>Opening</th><th>Handed over</th><th>Closing count</th><th>Discrepancies</th><th>Difference</th></tr>';
            foreach ($rec['lines'] ?? [] as $line) {
                $html .= '<tr' . ($line['balanced'] ? '' : ' class="bad"') . '><td>' . $e($line['sku']) . '</td><td>' . $e($line['lot'] ?? '') . '</td><td>' . $num($line['opening']) . '</td><td>' . $num($line['handed_over']) . '</td><td>' . $num($line['closing']) . '</td><td>' . $num($line['discrepancies']) . '</td><td>' . $num($line['variance']) . '</td></tr>';
            }
            $html .= '</table><p class="' . ($rec['balanced'] ? 'ok' : 'badtext') . '">' . ($rec['balanced'] ? 'Balanced: every unit is accounted for.' : 'Not balanced.') . '</p>';
        } else {
            $html .= '<p>Not reconciled yet: the distribution is still open.</p>';
        }

        if (!empty($report['discrepancies'])) {
            $html .= '<h2>Discrepancies</h2><table class="grid"><tr><th>Item</th><th>Lot</th><th>Quantity</th><th>Reason</th><th>Liable</th><th>Explanation</th></tr>';
            foreach ($report['discrepancies'] as $d) {
                $html .= '<tr><td>' . $e($d['sku']) . '</td><td>' . $e($d['lot']) . '</td><td>' . $num($d['quantity']) . '</td><td>' . $e(str_replace('_', ' ', $d['reason'])) . '</td><td>' . $e($d['liable_party']) . '</td><td>' . $e($d['notes']) . '</td></tr>';
            }
            $html .= '</table>';
        }

        // Handovers
        $html .= '<h2>Handovers (' . count($report['handovers'] ?? []) . ')</h2><table class="grid small"><tr><th>#</th><th>Time</th><th>Farmer</th><th>Sex</th><th>Received by</th><th>ID level</th><th>Items</th><th>Outcome</th><th>Flags</th><th>Seal</th></tr>';
        foreach ($report['handovers'] ?? [] as $i => $h) {
            $items = implode(', ', array_map(fn ($l) => $num($l['quantity']) . ' ' . $l['sku'] . ($l['lot'] !== '' ? ' (' . $l['lot'] . ')' : ''), $h['lines'] ?? []));
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($h['time']) . '</td><td>' . $e($h['name']) . '<br><span class="mono">' . $e($h['beneficiary']) . '</span></td><td>' . $e($h['sex']) . '</td>'
                . '<td>' . $e($h['received_by'] === 'self' ? 'Farmer' : str_replace('_', ' ', $h['received_by'] ?? '') . ': ' . $h['recipient_name']) . '</td>'
                . '<td>' . $e($h['identity_level'] ?? '') . '</td><td>' . $e($items ?: '-') . '</td><td>' . $e($h['outcome'] . ($h['failure_reason'] ? ': ' . str_replace('_', ' ', $h['failure_reason']) : '')) . '</td>'
                . '<td>' . $e(implode(', ', array_map(fn ($f) => self::FLAGS[$f] ?? str_replace('_', ' ', $f), $h['flags'] ?? []))) . '</td><td class="mono">' . $e(substr((string) $h['seal_hash'], 0, 12)) . '</td></tr>';
        }
        $html .= '</table>';

        // Witnesses
        $html .= '<h2>Witnesses</h2><table class="grid half"><tr><th>When</th><th>Name</th><th>Role</th><th>Signature</th></tr>';
        foreach (['open' => 'Opening', 'close' => 'Closing'] as $key => $label) {
            foreach ($report['witnesses'][$key] ?? [] as $w) {
                $html .= '<tr><td>' . $label . '</td><td>' . $e($w['name']) . '</td><td>' . $e(str_replace('_', ' ', $w['role'])) . '</td><td class="sign">' . ($w['signature'] ? 'Signed in app' : '') . '</td></tr>';
            }
        }
        $html .= '</table>';

        $html .= '<p class="seal">Seal: <span class="mono">' . $e($report['event']['seal_hash'] ?? 'not sealed (still open)') . '</span><br>'
            . 'The seal is a SHA-256 hash of this distribution\'s records. Any later change to them changes the hash. Generated ' . $e($report['generated_at'] ?? '') . '.</p>';

        return $html . '</body></html>';
    }

    private function css(): string
    {
        return <<<'CSS'
@page { size: A4 landscape; margin: 12mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
h1 { font-size: 14pt; margin: 0 0 3mm 0; color: #1f5c2e; }
h2 { font-size: 10.5pt; margin: 5mm 0 1.5mm 0; color: #1f5c2e; }
table.meta { width: 100%; border-collapse: collapse; }
table.meta td { vertical-align: top; padding: 1.5mm 3mm 1.5mm 0; width: 25%; }
table.grid { border-collapse: collapse; width: 100%; margin-bottom: 2mm; }
table.grid.half { width: 60%; }
table.grid th, table.grid td { border: 0.2mm solid #999; padding: 1mm 1.5mm; text-align: left; vertical-align: top; }
table.grid th { background: #e8efe9; }
table.grid.small td, table.grid.small th { font-size: 7.5pt; }
tr.bad td { background: #f8dcdc; }
.ok { color: #1f5c2e; font-weight: bold; }
.badtext { color: #8a1c1c; font-weight: bold; }
.mono { font-family: "DejaVu Sans Mono", monospace; font-size: 7pt; }
.sign { height: 7mm; width: 40mm; }
.seal { margin-top: 5mm; font-size: 7.5pt; color: #444; }
CSS;
    }
}
