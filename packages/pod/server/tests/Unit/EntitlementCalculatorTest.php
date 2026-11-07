<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Entitlements\EntitlementCalculator;
use Fleetbase\Pod\Support\Entitlements\ProgrammeItem;
use Fleetbase\Pod\Tests\TestCase;

final class EntitlementCalculatorTest extends TestCase
{
    private function items(): array
    {
        return [
            ProgrammeItem::fromArray(['sku' => 'RICE-25', 'name' => 'Rice seed NERICA, 25 kg', 'category' => 'seed', 'unit' => 'bag']),
            ProgrammeItem::fromArray(['sku' => 'NPK-50', 'name' => 'NPK 15-15-15, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag', 'max_per_beneficiary' => 4]),
            ProgrammeItem::fromArray(['sku' => 'UREA-50', 'name' => 'Urea, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag']),
            ProgrammeItem::fromArray(['sku' => 'HERB-1L', 'name' => 'Herbicide, 1 litre', 'category' => 'pesticide', 'unit' => 'bottle', 'max_per_beneficiary' => 2]),
            ProgrammeItem::fromArray(['sku' => 'DEWORM', 'name' => 'Dewormer dose', 'category' => 'vet_drug', 'unit' => 'dose']),
        ];
    }

    private function farmer(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'farmer', 'sex' => 'female', 'crop' => 'Rice, Cassava', 'group_name' => null,
            'admin_1' => 'SL01', 'admin_2' => 'SL0101', 'admin_3' => 'SL010101', 'admin_4' => null,
            'household_size' => 6, 'farm_size' => '2 acres', 'livestock' => ['goats' => 5], 'trained_restricted_products' => false,
        ];
    }

    private function quantities(array $result): array
    {
        return array_column($result['lines'], 'quantity', 'sku');
    }

    public function testAFixedPackageForEveryFarmer(): void
    {
        $calc = new EntitlementCalculator($this->items(), [
            ['sku' => 'RICE-25', 'quantity' => 1],
            ['sku' => 'NPK-50', 'quantity' => 2],
        ]);

        $this->assertSame(['RICE-25' => 1.0, 'NPK-50' => 2.0], $this->quantities($calc->calculate($this->farmer())));
        $this->assertSame('bag', $calc->calculate($this->farmer())['lines'][0]['unit']);
    }

    public function testQuantityPerAcreIsRoundedUpAndCapped(): void
    {
        $calc = new EntitlementCalculator($this->items(), [
            ['sku' => 'NPK-50', 'quantity' => 1, 'per' => 'farm_size_acres'],
            ['sku' => 'UREA-50', 'quantity' => 0.5, 'per' => 'farm_size_acres', 'max' => 2],
        ]);

        $this->assertSame(['NPK-50' => 2.0, 'UREA-50' => 1.0], $this->quantities($calc->calculate($this->farmer(['farm_size' => '2 acres']))));
        $this->assertSame(['NPK-50' => 3.0, 'UREA-50' => 2.0], $this->quantities($calc->calculate($this->farmer(['farm_size' => '2.5 acres']))));
        // 3 hectares is about 7.4 acres: 8 bags, but NPK is capped at 4 per farmer by the item.
        $this->assertSame(['NPK-50' => 4.0, 'UREA-50' => 2.0], $this->quantities($calc->calculate($this->farmer(['farm_size' => '3 ha']))));
    }

    public function testAFarmerWithoutTheNeededFigureIsSkippedWithAReason(): void
    {
        $calc   = new EntitlementCalculator($this->items(), [['sku' => 'NPK-50', 'quantity' => 1, 'per' => 'farm_size_acres']]);
        $result = $calc->calculate($this->farmer(['farm_size' => null]));

        $this->assertSame([], $result['lines']);
        $this->assertSame([['sku' => 'NPK-50', 'reason' => 'no_farm_size_acres', 'rule' => 0]], $result['skipped']);
    }

    public function testTheFirstMatchingRuleForAnItemWins(): void
    {
        $calc = new EntitlementCalculator($this->items(), [
            ['sku' => 'RICE-25', 'quantity' => 2, 'when' => ['sex' => 'female', 'crop' => ['rice']]],
            ['sku' => 'RICE-25', 'quantity' => 1],
        ]);

        $this->assertSame(['RICE-25' => 2.0], $this->quantities($calc->calculate($this->farmer())));
        $this->assertSame(['RICE-25' => 1.0], $this->quantities($calc->calculate($this->farmer(['sex' => 'male']))));
        $this->assertSame(['RICE-25' => 1.0], $this->quantities($calc->calculate($this->farmer(['crop' => 'Groundnut']))));
    }

    public function testConditionsOnPlaceAndType(): void
    {
        $calc = new EntitlementCalculator($this->items(), [
            ['sku' => 'RICE-25', 'quantity' => 1, 'when' => ['admin_2' => ['SL0101', 'SL0102'], 'type' => 'farmer']],
        ]);

        $this->assertCount(1, $calc->calculate($this->farmer())['lines']);
        $this->assertCount(0, $calc->calculate($this->farmer(['admin_2' => 'SL0301']))['lines']);
        $this->assertCount(0, $calc->calculate($this->farmer(['type' => 'animal_health_worker']))['lines']);
    }

    public function testRestrictedItemsOnlyForTrainedFarmers(): void
    {
        $calc = new EntitlementCalculator($this->items(), [['sku' => 'HERB-1L', 'quantity' => 3]]);

        $untrained = $calc->calculate($this->farmer());
        $this->assertSame([], $untrained['lines']);
        $this->assertSame('not_trained', $untrained['skipped'][0]['reason']);

        // Trained: 3 asked for, capped at the item's 2 per farmer.
        $this->assertSame(['HERB-1L' => 2.0], $this->quantities($calc->calculate($this->farmer(['trained_restricted_products' => true]))));
    }

    public function testLivestockInputsPerAnimal(): void
    {
        $calc = new EntitlementCalculator($this->items(), [['sku' => 'DEWORM', 'quantity' => 1, 'per' => 'livestock.goats']]);

        $this->assertSame(['DEWORM' => 5.0], $this->quantities($calc->calculate($this->farmer(['trained_restricted_products' => true]))));
        $this->assertSame('no_livestock_goats', $calc->calculate($this->farmer(['livestock' => [], 'trained_restricted_products' => true]))['skipped'][0]['reason']);
    }

    public function testReadsFarmSizesAsTypedInLists(): void
    {
        $this->assertEqualsWithDelta(0.8094, EntitlementCalculator::farmSizeHectares('2 acres'), 0.001);
        $this->assertEqualsWithDelta(0.8094, EntitlementCalculator::farmSizeHectares('2'), 0.001, 'a bare number is acres');
        $this->assertEqualsWithDelta(1.5, EntitlementCalculator::farmSizeHectares('1,5 ha'), 0.001);
        $this->assertEqualsWithDelta(0.5, EntitlementCalculator::farmSizeHectares('0.5 Hectare'), 0.001);
        $this->assertNull(EntitlementCalculator::farmSizeHectares('big'));
        $this->assertNull(EntitlementCalculator::farmSizeHectares('2 plots'));
        $this->assertNull(EntitlementCalculator::farmSizeHectares(''));
    }

    public function testRejectsBadRulesWithAClearMessage(): void
    {
        $bad = [
            [['sku' => 'MAIZE', 'quantity' => 1], 'not in the programme'],
            [['sku' => 'NPK-50', 'quantity' => 0], 'quantity must be more than 0'],
            [['sku' => 'NPK-50', 'quantity' => 1, 'per' => 'cows'], '"per" must be'],
            [['sku' => 'NPK-50', 'quantity' => 1, 'min' => 3, 'max' => 2], '"min" is more than "max"'],
            [['sku' => 'NPK-50', 'quantity' => 1, 'when' => ['district' => 'Bo']], 'unknown condition "district"'],
        ];

        foreach ($bad as [$rule, $message]) {
            try {
                new EntitlementCalculator($this->items(), [$rule]);
                $this->fail('Accepted ' . json_encode($rule));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringStartsWith('Rule 1:', $e->getMessage());
            }
        }
    }

    public function testItemDefaultsForRestrictedAndColdChain(): void
    {
        $vaccine = ProgrammeItem::fromArray(['sku' => 'PPR-VAC', 'name' => 'PPR vaccine', 'category' => 'vaccine', 'unit' => 'dose']);
        $seed    = ProgrammeItem::fromArray(['sku' => 'RICE-25', 'name' => 'Rice', 'category' => 'seed', 'unit' => 'bag']);

        $this->assertTrue($vaccine->restricted);
        $this->assertTrue($vaccine->coldChain);
        $this->assertFalse($seed->restricted);

        $this->expectException(\InvalidArgumentException::class);
        ProgrammeItem::fromArray(['sku' => 'bad sku!', 'name' => 'x', 'category' => 'seed', 'unit' => 'bag']);
    }
}
