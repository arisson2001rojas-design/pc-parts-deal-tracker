<?php

namespace Tests\Feature\Filament;

use App\Enums\ComponentType;
use App\Filament\Resources\PcBuildResource\Pages\CreatePcBuild;
use App\Filament\Resources\PcBuildResource\Pages\EditPcBuild;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\PcPart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PcBuildResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_component_picker_rejects_a_part_from_another_type(): void
    {
        $motherboard = PcPart::factory()->create(['component_type' => ComponentType::Motherboard]);

        Livewire::test(CreatePcBuild::class)
            ->fillForm([
                'name' => 'Typed build',
                'items' => [[
                    'component_type' => ComponentType::Cpu->value,
                    'pc_part_id' => $motherboard->getKey(),
                    'quantity' => 1,
                ]],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('pc_builds', ['name' => 'Typed build']);
    }

    public function test_component_picker_accepts_a_part_from_the_selected_type(): void
    {
        $cpu = PcPart::factory()->create(['component_type' => ComponentType::Cpu]);

        $form = Livewire::test(CreatePcBuild::class)
            ->fillForm(['name' => 'CPU build']);

        $itemKey = array_key_first($form->get('data.items'));

        $form
            ->set("data.items.{$itemKey}.component_type", ComponentType::Cpu->value)
            ->set("data.items.{$itemKey}.pc_part_id", $cpu->getKey())
            ->set("data.items.{$itemKey}.quantity", 1)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pc_build_items', [
            'pc_part_id' => $cpu->getKey(),
            'quantity' => 1,
        ]);
    }

    public function test_edit_form_hydrates_the_type_from_the_existing_part(): void
    {
        $part = PcPart::factory()->create(['component_type' => ComponentType::Gpu]);
        $build = PcBuild::query()->create(['name' => 'GPU build', 'user_id' => $this->user->getKey()]);
        PcBuildItem::query()->create([
            'pc_build_id' => $build->getKey(),
            'pc_part_id' => $part->getKey(),
            'quantity' => 1,
        ]);

        Livewire::test(EditPcBuild::class, ['record' => $build->getKey()])
            ->assertFormSet(function (array $state): bool {
                $item = collect($state['items'] ?? [])->first();

                return data_get($item, 'component_type') === ComponentType::Gpu->value;
            });
    }
}
