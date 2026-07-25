<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Importer;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImporterTest extends TestCase
{
    public function test_renders_successfully()
    {
        Livewire::actingAs(User::factory()->canImport()->create())
            ->test(Importer::class)
            ->assertStatus(200);
    }

    public function test_requires_permission()
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Importer::class)
            ->assertStatus(403);
    }

    public function test_bulk_deletes_owned_imports()
    {
        Storage::fake();
        $user = User::factory()->canImport()->create();
        $imports = Import::factory()->count(3)->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test(Importer::class)
            ->set('selectedIds', $imports->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('bulkDestroy')
            ->assertSet('message_type', 'success');

        foreach ($imports as $import) {
            $this->assertDatabaseMissing('imports', ['id' => $import->id]);
        }
    }

    public function test_bulk_destroy_skips_imports_the_caller_does_not_own()
    {
        Storage::fake();
        $me = User::factory()->canImport()->create();
        $someoneElse = User::factory()->canImport()->create();

        $mine = Import::factory()->create(['created_by' => $me->id]);
        $theirs = Import::factory()->create(['created_by' => $someoneElse->id]);

        Livewire::actingAs($me)
            ->test(Importer::class)
            ->set('selectedIds', [(string) $mine->id, (string) $theirs->id])
            ->call('bulkDestroy')
            ->assertSet('message_type', 'success');

        $this->assertDatabaseMissing('imports', ['id' => $mine->id]);
        $this->assertDatabaseHas('imports', ['id' => $theirs->id]);
    }

    public function test_bulk_destroy_all_denied_produces_error_message()
    {
        Storage::fake();
        $me = User::factory()->canImport()->create();
        $someoneElse = User::factory()->canImport()->create();

        $theirs = Import::factory()->count(2)->create(['created_by' => $someoneElse->id]);

        Livewire::actingAs($me)
            ->test(Importer::class)
            ->set('selectedIds', $theirs->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('bulkDestroy')
            ->assertSet('message_type', 'danger');

        // Neither import was deleted.
        foreach ($theirs as $import) {
            $this->assertDatabaseHas('imports', ['id' => $import->id]);
        }
    }

    public function test_superuser_can_bulk_delete_anyone_elses_imports()
    {
        Storage::fake();
        $superuser = User::factory()->superuser()->create();
        $owner = User::factory()->canImport()->create();
        $imports = Import::factory()->count(2)->create(['created_by' => $owner->id]);

        Livewire::actingAs($superuser)
            ->test(Importer::class)
            ->set('selectedIds', $imports->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('bulkDestroy')
            ->assertSet('message_type', 'success');

        foreach ($imports as $import) {
            $this->assertDatabaseMissing('imports', ['id' => $import->id]);
        }
    }

    public function test_bulk_destroy_with_no_selection_does_nothing()
    {
        Storage::fake();
        $user = User::factory()->canImport()->create();
        Import::factory()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test(Importer::class)
            ->call('bulkDestroy')
            ->assertSet('message', null);

        $this->assertDatabaseCount('imports', 1);
    }

    public function test_files_paginate_by_per_page()
    {
        $user = User::factory()->canImport()->create();
        Import::factory()->count(30)->create(['created_by' => $user->id]);

        $component = Livewire::actingAs($user)->test(Importer::class);

        // Default $perPage = 25; 30 imports means 25 on page 1, 5 on page 2.
        $this->assertCount(25, $component->instance()->files->items());
        $this->assertSame(30, $component->instance()->files->total());
        $this->assertTrue($component->instance()->files->hasPages());
    }

    public function test_select_all_selects_only_current_page_deletable_rows()
    {
        $me = User::factory()->canImport()->create();
        $someoneElse = User::factory()->canImport()->create();

        // 5 mine + 3 theirs = 8 imports on page 1 (fits under the 25 default).
        $mine = Import::factory()->count(5)->create(['created_by' => $me->id]);
        Import::factory()->count(3)->create(['created_by' => $someoneElse->id]);

        $component = Livewire::actingAs($me)
            ->test(Importer::class)
            ->set('selectAll', true);

        // Only the 5 the caller can delete are picked up.
        $selected = $component->get('selectedIds');
        $this->assertCount(5, $selected);
        $this->assertEquals(
            $mine->pluck('id')->sort()->values()->all(),
            collect($selected)->map(fn ($id) => (int) $id)->sort()->values()->all()
        );
    }

    public function test_changing_page_clears_selection()
    {
        $user = User::factory()->canImport()->create();
        Import::factory()->count(30)->create(['created_by' => $user->id]);

        $component = Livewire::actingAs($user)
            ->test(Importer::class)
            ->set('selectAll', true);

        $this->assertNotEmpty($component->get('selectedIds'));

        // Livewire's WithPagination exposes a setPage() method; calling it
        // triggers the updatedPage hook where we clear selection.
        $component->call('setPage', 2);

        $this->assertSame([], $component->get('selectedIds'));
        $this->assertFalse($component->get('selectAll'));
    }

    /**
     * Security regression pin. Before the fix in Importer::files, a
     * non-superuser with the import permission could see every other
     * user's uploaded CSV in the file list (created_by scoping was
     * absent, matching the API's per-owner filter was not applied).
     */
    public function test_file_list_excludes_imports_owned_by_other_users(): void
    {
        Storage::fake();
        $me = User::factory()->canImport()->create();
        $someoneElse = User::factory()->canImport()->create();

        $mine = Import::factory()->count(2)->create(['created_by' => $me->id]);
        $theirs = Import::factory()->count(3)->create(['created_by' => $someoneElse->id]);

        $files = Livewire::actingAs($me)
            ->test(Importer::class)
            ->get('files');

        $visibleIds = collect($files->items())->pluck('id')->all();
        sort($visibleIds);

        $expected = $mine->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $visibleIds, 'File list must only include imports owned by the caller');
        foreach ($theirs as $import) {
            $this->assertNotContains($import->id, $visibleIds);
        }
    }

    /**
     * Security regression pin. Before the fix in Importer::activeFile,
     * selectFile($id) would populate headerRow / typeOfImport /
     * field_map from ANY Import record, even one owned by another user.
     * With the owner scope in place, activeFile returns null for a
     * cross-user id and selectFile's "file not found" branch fires
     * without ever reading the target's preview state.
     */
    public function test_selecting_another_users_import_does_not_leak_preview(): void
    {
        Storage::fake();
        $me = User::factory()->canImport()->create();
        $someoneElse = User::factory()->canImport()->create();

        $theirImport = Import::factory()->create([
            'created_by' => $someoneElse->id,
            'header_row' => ['sensitive_column'],
            'import_type' => 'asset',
        ]);

        Livewire::actingAs($me)
            ->test(Importer::class)
            ->call('selectFile', $theirImport->id)
            ->assertSet('headerRow', [])
            ->assertSet('typeOfImport', null)
            ->assertSet('message_type', 'danger');
    }

    /**
     * Positive-path sanity: the owner still sees + selects their own imports.
     */
    public function test_owner_can_still_select_their_own_import(): void
    {
        Storage::fake();
        $me = User::factory()->canImport()->create();

        $mine = Import::factory()->create([
            'created_by' => $me->id,
            'header_row' => ['my_column'],
            'import_type' => 'asset',
        ]);

        Livewire::actingAs($me)
            ->test(Importer::class)
            ->call('selectFile', $mine->id)
            ->assertSet('headerRow', ['my_column'])
            ->assertSet('typeOfImport', 'asset');
    }

    /**
     * Superusers keep the cross-user visibility they've always had.
     */
    public function test_superuser_sees_all_imports(): void
    {
        Storage::fake();
        $superuser = User::factory()->superuser()->create();
        $someoneElse = User::factory()->canImport()->create();

        $theirs = Import::factory()->count(2)->create(['created_by' => $someoneElse->id]);

        $files = Livewire::actingAs($superuser)
            ->test(Importer::class)
            ->get('files');

        $visibleIds = collect($files->items())->pluck('id')->all();
        foreach ($theirs as $import) {
            $this->assertContains($import->id, $visibleIds);
        }
    }
}
