<?php

namespace Tests\Unit;

use App\Models\Machine;
use App\Services\AssetCodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCodeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_separator_case_and_whitespace_variants_resolve_one_catalog_machine(): void
    {
        $machine = $this->machine('T-XX0717', 'CHASSIS-NORMALIZED');

        foreach (['TXX0717', 'T-XX0717', 'T XX 0717', 'T X X 0 7 1 7', 'txx0717', 'T.XX.0717', 'T_XX_0717', 'T/XX/0717', 'T:XX:0717', 'T;XX;0717'] as $candidate) {
            $result = app(AssetCodeResolver::class)->resolve($candidate);
            $this->assertSame('MATCHED', $result['status'], $candidate);
            $this->assertSame($machine->id, $result['machine']->id, $candidate);
        }
    }

    public function test_normalized_collision_is_ambiguous_and_never_selects_a_machine(): void
    {
        $first = $this->machine('T-XX0717', 'CHASSIS-COLLISION-1');
        $second = $this->machine('T XX 0717', 'CHASSIS-COLLISION-2');

        $result = app(AssetCodeResolver::class)->resolve('T_XX_0717');

        $this->assertSame('AMBIGUOUS', $result['status']);
        $this->assertNull($result['machine']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $result['candidate_machine_ids']);
    }

    private function machine(string $assetCode, string $chassis): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => $chassis,
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
        ]);
    }
}
