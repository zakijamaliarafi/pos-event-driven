<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);
});

test('category page renders the reference table and existing navbar', function () {
    Category::factory()->create(['name' => 'Pagi Sehat', 'slug' => 'pagi-sehat']);

    $this->get(route('menu.category'))->assertOk()
        ->assertSee('Kelola Kategori')->assertSee('Nama kategori')->assertSee('Slug')->assertSee('Aksi')
        ->assertSee('pagi-sehat')->assertSee('Cari kategori...')->assertSee('Tambah kategori')
        ->assertSee('Menampilkan 1 sampai 1 dari 1 kategori')
        ->assertSee('aria-label="Ubah kategori Pagi Sehat"', false)
        ->assertSee('aria-label="Hapus kategori Pagi Sehat"', false)
        ->assertSee('aria-current="page"', false)->assertDontSee('wire:poll', false);
});

test('category search filters names and resets pagination', function () {
    Category::factory()->count(11)->sequence(fn ($sequence) => [
        'name' => sprintf('Kategori %02d', $sequence->index),
        'slug' => sprintf('kategori-%02d', $sequence->index),
    ])->create();

    Livewire::test('pages::menu.manage')->set('section', 'categories')
        ->assertViewHas('categoryRows', fn (LengthAwarePaginator $rows) => $rows->count() === 10 && $rows->total() === 11 && $rows->onFirstPage() && $rows->hasMorePages())
        ->assertSee('Menampilkan 1 sampai 10 dari 11 kategori')
        ->call('nextPage', 'categoriesPage')->assertSee('Menampilkan 11 sampai 11 dari 11 kategori')
        ->assertViewHas('categoryRows', fn (LengthAwarePaginator $rows) => $rows->count() === 1 && ! $rows->hasMorePages())
        ->call('previousPage', 'categoriesPage')->assertSet('paginators.categoriesPage', 1)
        ->call('nextPage', 'categoriesPage')
        ->set('categorySearch', 'Kategori 03')->assertSet('paginators.categoriesPage', 1)
        ->assertSee('Menampilkan 1 sampai 1 dari 1 kategori')
        ->assertViewHas('categoryRows', fn (LengthAwarePaginator $rows) => $rows->pluck('name')->all() === ['Kategori 03'])
        ->assertViewHas('categories', fn ($categories) => $categories->isEmpty())
        ->set('categorySearch', 'missing')->assertSee('Tidak ada kategori yang cocok dengan pencarian.')
        ->assertSee('Menampilkan 0 sampai 0 dari 0 kategori')
        ->set('categorySearch', '')->assertSee('Menampilkan 1 sampai 10 dari 11 kategori');
});

test('empty category page has a distinct message and disabled pagination', function () {
    Livewire::test('pages::menu.manage')->set('section', 'categories')
        ->assertSee('Belum ada kategori.')->assertSee('Menampilkan 0 sampai 0 dari 0 kategori')
        ->assertViewHas('categoryRows', fn (LengthAwarePaginator $rows) => $rows->onFirstPage() && ! $rows->hasMorePages());

    $response = $this->get(route('menu.category'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//button[@disabled and (contains(., "Sebelumnya") or contains(., "Berikutnya"))]')->length)->toBe(2);
});

test('category modal clears form and errors when cancelled or reopened', function () {
    $category = Category::factory()->create(['name' => 'Modern']);

    Livewire::test('pages::menu.manage')->set('section', 'categories')->assertSet('showCategoryModal', false)
        ->call('editCategory', $category->id)->assertSet('showCategoryModal', true)
        ->assertSet('categoryId', $category->id)->assertSet('categoryName', 'Modern')->assertSee('Ubah kategori')
        ->set('categoryName', '')->call('saveCategory')->assertHasErrors('categoryName')
        ->assertSet('showCategoryModal', true)
        ->call('closeCategoryModal')->assertSet('showCategoryDiscardConfirmation', true)
        ->call('discardCategoryChanges')->assertSet('showCategoryModal', false)
        ->assertSet('categoryName', '')->assertSet('categoryId', null)->assertHasNoErrors()
        ->call('editCategory', $category->id)->call('createCategory')
        ->assertSet('showCategoryModal', true)->assertSet('categoryName', '')->assertSet('categoryId', null);

    expect($category->fresh()->name)->toBe('Modern');
});

test('invalid category names keep modal open without writing events', function (string $name) {
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('createCategory')
        ->set('categoryName', $name)->call('saveCategory')->assertHasErrors('categoryName')->assertSee('Masukkan nama kategori, maksimal 255 karakter.')
        ->assertSet('showCategoryModal', true);

    expect(Category::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
})->with(['', str_repeat('a', 256)]);

test('category modal creates and updates through durable catalog commands', function () {
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('createCategory')
        ->set('categoryName', 'Pagi Sehat')->call('saveCategory')->assertHasNoErrors()
        ->assertSet('showCategoryModal', false)->assertSet('categoryName', '')->assertSee('Kategori disimpan.');
    $category = Category::sole();

    expect($category->slug)->toBe('pagi-sehat')
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryCreated')->count())->toBe(1);

    $page->call('editCategory', $category->id)->set('categoryName', 'Modern')->call('saveCategory')
        ->assertHasNoErrors()->assertSet('showCategoryModal', false)->assertSet('categoryId', null);

    expect($category->fresh()->name)->toBe('Modern')->and($category->fresh()->slug)->toBe('modern')
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryUpdated')->count())->toBe(1);
});

test('saving and deleting categories resets the last page', function () {
    $categories = Category::factory()->count(11)->sequence(fn ($sequence) => [
        'name' => sprintf('Kategori %02d', $sequence->index),
        'slug' => sprintf('kategori-%02d', $sequence->index),
    ])->create();
    $last = $categories->last();

    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('nextPage', 'categoriesPage')
        ->call('editCategory', $last->id)->set('categoryName', 'Updated')->call('saveCategory')
        ->assertSet('paginators.categoriesPage', 1)
        ->call('nextPage', 'categoriesPage')->call('requestCategoryDelete', $last->id)->call('deleteCategory')
        ->assertSet('paginators.categoriesPage', 1)->assertSee('Menampilkan 1 sampai 10 dari 10 kategori');

    expect($last->fresh()->trashed())->toBeTrue()
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryDeleted')->count())->toBe(1);
});

test('category containing products cannot be deleted', function () {
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('requestCategoryDelete', $category->id)->call('deleteCategory')
        ->assertHasErrors('categoryDelete')->assertSee(__('catalog.errors.category_has_products'));

    expect($category->fresh()->trashed())->toBeFalse()
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryDeleted')->count())->toBe(0);
});

test('product selector keeps all categories beyond the first category page', function () {
    Category::factory()->count(11)->create();

    Livewire::test('pages::menu.manage')->assertSet('section', 'products')
        ->assertViewHas('categories', fn ($categories) => $categories->count() === 11)
        ->assertViewHas('categoryRows', null)->assertDontSee('Cari kategori...');
});

test('category slug collisions are shown in the modal without changing data', function (bool $deleted, bool $editing) {
    $existing = Category::factory()->create(['name' => 'Paket Nasi Ayam', 'slug' => 'paket-nasi-ayam']);
    if ($deleted) {
        $existing->delete();
    }
    $category = Category::factory()->create(['name' => 'Paket Nasi Ikan', 'slug' => 'paket-nasi-ikan']);
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories');
    if ($editing) {
        $page->call('editCategory', $category->id);
    } else {
        $page->call('createCategory');
    }

    $page->set('categoryName', 'Paket Nasi-Ayam')->call('saveCategory')
        ->assertHasErrors('categoryName')->assertSet('showCategoryModal', true)
        ->assertSee(__('catalog.errors.category_name_taken'));

    expect($category->fresh()->name)->toBe('Paket Nasi Ikan')
        ->and(Category::withTrashed()->count())->toBe(2)
        ->and(DB::table('domain_outbox')->count())->toBe(0);
})->with([[false, false], [false, true]]);

test('renaming to a deleted category restores it and merges products atomically', function () {
    $target = Category::factory()->create(['name' => 'Paket Nasi Ayam', 'slug' => 'paket-nasi-ayam', 'version' => 3]);
    $target->delete();
    $source = Category::factory()->create(['name' => 'Paket Nasi Ikan', 'slug' => 'paket-nasi-ikan']);
    $product = Product::factory()->create(['category_id' => $source->id, 'version' => 2]);
    $archivedProduct = Product::factory()->create(['category_id' => $source->id]);
    $archivedProduct->delete();

    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('editCategory', $source->id)
        ->set('categoryName', 'Paket Nasi Ayam')->call('saveCategory')->assertHasNoErrors()
        ->assertSet('showCategoryModal', false)->assertSee('Paket Nasi Ayam')->assertDontSee('Paket Nasi Ikan');

    expect($target->fresh()->trashed())->toBeFalse()
        ->and($target->fresh()->version)->toBe(4)
        ->and($source->fresh()->trashed())->toBeTrue()
        ->and($product->fresh()->category_id)->toBe($target->id)
        ->and($product->fresh()->version)->toBe(3)
        ->and($archivedProduct->fresh()->category_id)->toBe($target->id)
        ->and($archivedProduct->fresh()->trashed())->toBeTrue()
        ->and(Category::withTrashed()->count())->toBe(2)
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryRestored')->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductUpdated')->count())->toBe(2)
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryDeleted')->count())->toBe(1);
});

test('adding a deleted category restores its original record and emits a durable event', function () {
    $category = Category::factory()->create(['name' => 'Paket Nasi Ayam', 'slug' => 'paket-nasi-ayam', 'version' => 3]);
    $category->delete();

    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('createCategory')
        ->set('categoryName', 'Paket Nasi-Ayam')->call('saveCategory')->assertHasNoErrors()
        ->assertSet('showCategoryModal', false)->assertSee('Paket Nasi-Ayam');

    expect($category->fresh()->trashed())->toBeFalse()
        ->and($category->fresh()->version)->toBe(4)
        ->and(Category::withTrashed()->count())->toBe(1)
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryRestored')->where('aggregate_id', $category->id)->count())->toBe(1);
});

test('category may retain its own slug when saved', function () {
    $category = Category::factory()->create(['name' => 'Paket Nasi Ayam', 'slug' => 'paket-nasi-ayam']);

    Livewire::test('pages::menu.manage')->set('section', 'categories')
        ->call('editCategory', $category->id)->call('saveCategory')->assertHasNoErrors()
        ->assertSet('showCategoryModal', false);

    expect($category->fresh()->slug)->toBe('paket-nasi-ayam')
        ->and(DB::table('domain_outbox')->where('event_name', 'CategoryUpdated')->count())->toBe(1);
});

test('category usage filters combine with search and exclude deleted products and categories', function () {
    $used = Category::factory()->create(['name' => 'Menu aktif']);
    $empty = Category::factory()->create(['name' => 'Menu kosong']);
    $deleted = Category::factory()->create(['name' => 'Menu terhapus']);
    $deleted->delete();
    Product::factory()->count(2)->create(['category_id' => $used->id]);
    Product::factory()->create(['category_id' => $empty->id])->delete();
    Livewire::test('pages::menu.manage')->set('section', 'categories')
        ->set('categorySearch', 'Menu')->set('categoryUsageFilter', 'used')
        ->assertViewHas('categoryRows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $used->id && $rows->first()->products_count === 2)
        ->set('categoryUsageFilter', 'empty')
        ->assertViewHas('categoryRows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $empty->id && $rows->first()->products_count === 0)
        ->set('categorySearch', 'missing')->assertSee('Reset filter')->call('resetCategoryFilters')
        ->assertSet('categorySearch', '')->assertSet('categoryUsageFilter', '')
        ->assertViewHas('categoryRows', fn ($rows) => $rows->total() === 2);
});

test('category refresh preserves filters and resets pagination', function () {
    Category::factory()->count(11)->create(['name' => 'Menu']);
    Livewire::test('pages::menu.manage')->set('section', 'categories')->set('categorySearch', 'Menu')
        ->set('categoryUsageFilter', 'empty')->call('nextPage', 'categoriesPage')->call('refreshCategories')
        ->assertSet('paginators.categoriesPage', 1)->assertSet('categorySearch', 'Menu')->assertSet('categoryUsageFilter', 'empty')
        ->call('nextPage', 'categoriesPage')->set('categoryUsageFilter', 'used')->assertSet('paginators.categoriesPage', 1);
});

test('category discard confirmation blocks saves and allows continuing the draft', function () {
    $category = Category::factory()->create(['name' => 'Awal']);
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('editCategory', $category->id)
        ->set('categoryName', 'Baru')->call('requestCategoryClose')->assertSet('showCategoryDiscardConfirmation', true)
        ->assertDispatched('category-confirmation-requested')->call('saveCategory')->assertSet('showCategoryModal', true);
    expect($category->fresh()->name)->toBe('Awal');
    $page->call('continueCategoryEditing')->assertSet('categoryName', 'Baru')->assertSet('showCategoryDiscardConfirmation', false)
        ->call('saveCategory')->assertSet('showCategoryModal', false)->assertDispatched('category-dialog-closed');
    expect($category->fresh()->name)->toBe('Baru');
});

test('category deletion confirms by name and rechecks late products', function () {
    $category = Category::factory()->create(['name' => 'Kategori pilihan']);
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('requestCategoryDelete', $category->id)
        ->assertSet('deletingCategoryName', 'Kategori pilihan')->assertSet('showCategoryDeleteModal', true);
    expect($category->fresh()->trashed())->toBeFalse();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $page->call('deleteCategory')->assertHasErrors('categoryDelete')->assertSet('showCategoryDeleteModal', true)->assertSee('Kelola produk');
    expect($category->fresh()->trashed())->toBeFalse()->and(DB::table('domain_outbox')->count())->toBe(0);
    $product->delete();
    $page->call('deleteCategory')->assertHasNoErrors()->assertSet('showCategoryDeleteModal', false)->assertSee('Kategori dihapus.');
    expect($category->fresh()->trashed())->toBeTrue();
});

test('removed category retains editor draft and deletion dialog errors', function () {
    $category = Category::factory()->create();
    $editor = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('editCategory', $category->id)->set('categoryName', 'Draft baru');
    $deletion = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('requestCategoryDelete', $category->id);
    $category->delete();
    $editor->call('saveCategory')->assertHasErrors('categoryForm')->assertSet('categoryName', 'Draft baru')->assertSet('showCategoryModal', true);
    $deletion->call('deleteCategory')->assertHasErrors('categoryDelete')->assertSet('showCategoryDeleteModal', true);
});

test('category state is locked', function (string $property) {
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories')->call('createCategory');
    expect(fn () => $page->set($property, 999999))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['categoryId', 'deletingCategoryId', 'deletingCategoryName', 'originalCategoryDraft', 'showCategoryDiscardConfirmation']);

test('category mutations require initialized state', function (string $action) {
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call($action)->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['saveCategory', 'deleteCategory']);

test('category actions authorize managers', function (string $action) {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call($action)->assertForbidden();
})->with(['createCategory', 'saveCategory', 'deleteCategory', 'requestCategoryClose', 'continueCategoryEditing', 'discardCategoryChanges', 'closeCategoryDeleteModal', 'resetCategoryFilters', 'refreshCategories']);

test('category outbox failures roll back writes and allow retry', function (bool $deleting) {
    $category = Category::factory()->create(['name' => 'Awal']);
    $page = Livewire::test('pages::menu.manage')->set('section', 'categories');
    if ($deleting) {
        $page->call('requestCategoryDelete', $category->id);
    } else {
        $page->call('editCategory', $category->id)->set('categoryName', 'Baru');
    }
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Simulated category outbox failure');
        }
    });
    $action = $deleting ? 'deleteCategory' : 'saveCategory';
    $page->call($action)->assertHasErrors($deleting ? 'categoryDelete' : 'categoryForm');
    expect($category->fresh()->name)->toBe('Awal')->and($category->fresh()->trashed())->toBeFalse()->and(DB::table('domain_outbox')->count())->toBe(0);
    if (! $deleting) {
        $page->assertSet('categoryName', 'Baru');
    }
    $page->call($action)->assertHasNoErrors();
    expect(DB::table('domain_outbox')->count())->toBe(1);
})->with([false, true]);

test('category page renders long names mobile cards and protected dialogs', function () {
    $name = str_repeat('Kategori panjang ', 15);
    Category::factory()->create(['name' => $name]);
    $this->get(route('menu.category'))->assertOk()->assertSee($name)->assertSee('lg:hidden', false)
        ->assertSee('hidden lg:block', false)->assertSee('Jumlah produk')->assertDontSee('wire:click="refreshCategories"', false)
        ->assertDontSee('wire:confirm', false)->assertDontSee('wire:poll', false);
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('createCategory')->assertSee('Slug dibuat otomatis');
});

test('category actions with ids authorize managers', function (string $action) {
    $category = Category::factory()->create();
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call($action, $category->id)->assertForbidden();
})->with(['editCategory', 'requestCategoryDelete']);

test('unchanged category editor closes without discard confirmation', function () {
    $category = Category::factory()->create();
    Livewire::test('pages::menu.manage')->set('section', 'categories')->call('editCategory', $category->id)
        ->call('requestCategoryClose')->assertSet('showCategoryModal', false)->assertSet('showCategoryDiscardConfirmation', false);
    expect(DB::table('domain_outbox')->count())->toBe(0);
});
