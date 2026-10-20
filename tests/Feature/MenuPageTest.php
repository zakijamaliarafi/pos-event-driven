<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

test('menu renders a responsive table and cards with thumbnails and existing navigation', function () {
    $category = Category::factory()->create(['name' => 'Modern']);
    Product::factory()->create(['category_id' => $category->id, 'name' => 'Comfort Morning', 'image_url' => null]);
    $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'Balance Boost', 'price' => 15000]);
    $response = $this->get(route('menu.menu'))->assertOk()
        ->assertSee('Kelola Produk')->assertSee('Tambah produk')->assertSee('Kategori')->assertSee('Harga')->assertSee('Aksi')
        ->assertSeeInOrder(['Balance Boost', 'Comfort Morning'])->assertSee('Menampilkan 1 sampai 2 dari 2 produk')
        ->assertSee(config('pos.currency').' 15,000')->assertSee('Modern')
        ->assertSee(Storage::disk('public')->url($product->image_url))
        ->assertSee('aria-label="Tanpa gambar"', false)
        ->assertSee('aria-label="Ubah produk Balance Boost"', false)
        ->assertSee('aria-label="Hapus produk Balance Boost"', false)
        ->assertDontSee('wire:confirm="Hapus produk ini?"', false)
        ->assertSee('data-product-delete', false)->assertSee('Kelola resep')
        ->assertSee('Pilih gambar')->assertSee('Belum ada berkas dipilih')
        ->assertSee('data-test="theme-toggle"', false)
        ->assertSee('data-test="notifications-toggle"', false)
        ->assertSee('data-test="user-menu-toggle"', false)->assertDontSee('wire:poll', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//main//table/tbody/tr')->length)->toBe(2)
        ->and($xpath->query('//main//article')->length)->toBe(2)
        ->and($xpath->query('//main//h1')->length)->toBe(1)
        ->and($xpath->query('//main//form[not(ancestor::dialog)]')->length)->toBe(0)
        ->and($xpath->query('//header//nav/a[@aria-current="page"]')->item(0)->getAttribute('href'))->toBe(route('menu.menu'));
});

test('empty menu displays a count and empty state', function () {
    $this->get(route('menu.menu'))->assertOk()->assertSee('Belum ada produk.')->assertSee('Menampilkan 0 sampai 0 dari 0 produk');
});

test('product category selector has a selectable empty option and saves the first category', function () {
    $category = Category::factory()->create(['name' => 'Paket Ayam']);
    $page = Livewire::test('pages::dashboard.products')->call('createProduct')->assertSet('productCategoryId', null);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    $select = $xpath->query('//select[@name="productCategoryId"]')->item(0);
    $options = $select->getElementsByTagName('option');

    expect($select->getAttribute('wire:model.live'))->toBe('productCategoryId')
        ->and($options->item(0)->getAttribute('value'))->toBe('')
        ->and($options->item(0)->hasAttribute('disabled'))->toBeFalse()
        ->and(trim($options->item(0)->textContent))->toBe('Pilih kategori...')
        ->and($options->item(1)->getAttribute('value'))->toBe((string) $category->id);

    $page->set('productName', 'Nasi Ayam Bakar')->set('productPrice', '20000')->call('saveProduct')
        ->assertHasErrors('productCategoryId')->assertSee('Pilih kategori yang tersedia.')->assertSet('showProductModal', true)
        ->set('productCategoryId', (string) $category->id)->call('saveProduct')->assertHasNoErrors()
        ->assertSet('showProductModal', false);

    expect(Product::sole()->category_id)->toBe($category->id);
});

test('product modal resets fields uploads and validation when cancelled or reopened', function () {
    Storage::fake('public');
    $product = Product::factory()->create(['name' => 'Comfort Morning', 'price' => 20000]);
    $page = Livewire::test('pages::dashboard.products')->assertSet('showProductModal', false)
        ->call('editProduct', $product->id)->assertSet('showProductModal', true)
        ->assertSet('productId', $product->id)->assertSet('productName', $product->name)
        ->assertSet('productCategoryId', $product->category_id)->assertSet('productPrice', '20000.00')
        ->assertSet('productImageUrl', $product->image_url)->assertSee('Ubah produk')->assertSee('Gambar saat ini:')
        ->set('productImage', UploadedFile::fake()->image('replacement.png'))->assertSee('Pratinjau:')
        ->set('productName', '')->call('saveProduct')->assertHasErrors('productName')
        ->assertSet('showProductModal', true)->call('closeProductModal')
        ->assertSet('showProductDiscardConfirmation', true)->assertSet('showProductModal', true)
        ->call('discardProductChanges')->assertSet('showProductModal', false)->assertSet('productId', null)->assertSet('productName', '')
        ->assertSet('productCategoryId', null)->assertSet('productPrice', '')
        ->assertSet('productImageUrl', null)->assertSet('productImage', null)->assertHasNoErrors();
    $page->call('editProduct', $product->id)->call('createProduct')
        ->assertSet('showProductModal', true)->assertSet('productId', null)->assertSet('productName', '')
        ->assertSet('productImageUrl', null)->assertSet('productImage', null);
    expect($product->fresh()->name)->toBe('Comfort Morning')
        ->and(DB::table('domain_outbox')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([]);
});

test('invalid fields keep the modal open without storing images or events', function (string $field, mixed $value) {
    Storage::fake('public');
    $category = Category::factory()->create();
    Livewire::test('pages::dashboard.products')->call('createProduct')
        ->set('productName', 'Valid product')->set('productCategoryId', $category->id)->set('productPrice', '12000')
        ->set('productImage', UploadedFile::fake()->image('product.png'))
        ->set($field, $value)->call('saveProduct')->assertHasErrors($field)
        ->assertSet('showProductModal', true);
    expect(Product::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([]);
})->with([
    ['productName', ''],
    ['productName', str_repeat('a', 256)],
    ['productCategoryId', null],
    ['productCategoryId', 99999],
    ['productPrice', ''],
    ['productPrice', '-1'],
    ['productPrice', 'invalid'],
]);

test('invalid images show errors without attempting a preview', function (bool $oversized) {
    Storage::fake('public');
    $image = $oversized ? UploadedFile::fake()->image('large.png')->size(2049) : UploadedFile::fake()->create('document.pdf', 10, 'application/pdf');
    Livewire::test('pages::dashboard.products')->call('createProduct')->set('productImage', $image)
        ->assertHasErrors(['productImage' => $oversized ? 'max' : 'image'])
        ->assertSet('showProductModal', true)->assertDontSee('Pratinjau:');
    expect(Product::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([]);
})->with([false, true]);

test('product modal creates products with optional images and durable events', function (bool $withImage) {
    Storage::fake('public');
    $category = Category::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('createProduct')
        ->set('productName', 'New product')->set('productCategoryId', $category->id)->set('productPrice', '12000');
    if ($withImage) {
        $page->set('productImage', UploadedFile::fake()->image('product.png'))->assertSee('Pratinjau:');
    }
    $page->call('saveProduct')->assertHasNoErrors()->assertSet('showProductModal', false)
        ->assertSet('productId', null)->assertSet('productName', '')->assertSet('productImage', null)
        ->assertSee(__('catalog.messages.product_saved'))->assertSee('Menampilkan 1 sampai 1 dari 1 produk');
    $product = Product::sole();
    expect($product->name)->toBe('New product')->and($product->price)->toBe('12000.00')
        ->and($product->category_id)->toBe($category->id)
        ->and($product->current_stock)->toBe(0)
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductCreated')->count())->toBe(1);
    if ($withImage) {
        Storage::disk('public')->assertExists($product->image_url);
    } else {
        expect($product->image_url)->toBeNull();
    }
})->with([false, true]);

test('editing preserves or replaces images through catalog commands', function (bool $replaceImage) {
    Storage::fake('public');
    Storage::disk('public')->put('products/original.png', 'original image');
    $product = Product::factory()->create(['image_url' => 'products/original.png', 'version' => 2]);
    $page = Livewire::test('pages::dashboard.products')->call('editProduct', $product->id)->set('productName', 'Updated product');
    if ($replaceImage) {
        $page->set('productImage', UploadedFile::fake()->image('replacement.png'));
    }
    $page->call('saveProduct')->assertHasNoErrors()->assertSet('showProductModal', false)->assertSet('productId', null);
    $updated = $product->fresh();
    expect($updated->name)->toBe('Updated product')->and($updated->version)->toBe(3)
        ->and($updated->current_stock)->toBe($product->current_stock)
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductUpdated')->count())->toBe(1);
    Storage::disk('public')->assertExists($updated->image_url);
    if ($replaceImage) {
        expect($updated->image_url)->not->toBe($product->image_url);
    } else {
        expect($updated->image_url)->toBe($product->image_url);
    }
})->with([false, true]);

test('command validation errors are mapped to modal fields', function () {
    $category = Category::factory()->create();
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw ValidationException::withMessages(['image_url' => 'Gambar tidak dapat disimpan.']);
        }
    });
    Livewire::test('pages::dashboard.products')->call('createProduct')
        ->set('productName', 'Product')->set('productCategoryId', $category->id)->set('productPrice', '12000')
        ->call('saveProduct')
        ->assertHasErrors('productImage')->assertSet('showProductModal', true);
    expect(Product::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('deleting refreshes the count and emits a durable event', function () {
    $product = Product::factory()->create();
    Livewire::test('pages::dashboard.products')->call('requestProductDelete', $product->id)->call('deleteProduct')
        ->assertSee('Belum ada produk.')->assertSee('Menampilkan 0 sampai 0 dari 0 produk');
    expect($product->fresh()->trashed())->toBeTrue()
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductDeleted')->count())->toBe(1);
});

test('product search category pagination and refresh work together', function () {
    $category = Category::factory()->create();
    Product::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Produk %02d', $sequence->index), 'category_id' => $category->id])->create();
    $other = Product::factory()->create(['name' => 'Produk lain']);
    Product::factory()->create(['name' => 'Dihapus'])->delete();
    Livewire::test('pages::dashboard.products')
        ->assertViewHas('productRows', fn ($rows) => $rows->count() === 10 && $rows->total() === 12)
        ->assertDontSee('Dihapus')->call('nextPage', 'productsPage')->assertSee('Menampilkan 11 sampai 12 dari 12 produk')
        ->set('productSearch', ' Produk 03 ')->assertSet('paginators.productsPage', 1)
        ->assertViewHas('productRows', fn ($rows) => $rows->pluck('name')->all() === ['Produk 03'])
        ->set('productCategoryFilter', (string) $other->category_id)->assertSee('Tidak ada produk yang cocok dengan filter.')
        ->set('productSearch', '')->assertViewHas('productRows', fn ($rows) => $rows->modelKeys() === [$other->id])
        ->call('resetProductFilters')->assertSet('productSearch', '')->assertSet('productCategoryFilter', '')
        ->call('nextPage', 'productsPage')->set('productCategoryFilter', (string) $category->id)
        ->assertSet('paginators.productsPage', 1)->assertViewHas('productRows', fn ($rows) => $rows->total() === 11)
        ->call('nextPage', 'productsPage')->call('refreshProducts')->assertSet('paginators.productsPage', 1)
        ->assertSet('productCategoryFilter', (string) $category->id);
});

test('menu keeps missing invalid and depleted recipes visible and derives portions from ingredients', function () {
    $missing = Product::factory()->create();
    $invalid = Product::factory()->withRecipe()->create();
    $invalid->recipes->first()->inventoryItem->delete();
    $empty = Product::factory()->withRecipe(0)->create();
    $available = Product::factory()->withRecipe(2.55, 0.20)->create(['current_stock' => 999, 'is_available' => false]);
    $ingredient = $available->recipes->first()->inventoryItem;
    $ingredient->forceFill(['reserved_stock' => '0.35'])->save();
    $page = Livewire::test('pages::dashboard.products')
        ->assertViewHas('productRows', fn ($rows) => $rows->total() === 4 && $rows->every(fn ($product) => $product->relationLoaded('category') && $product->relationLoaded('recipes') && $product->recipes->every(fn ($recipe) => $recipe->relationLoaded('inventoryItem'))))
        ->assertViewHas('productOverview', function ($rows) use ($missing, $invalid, $empty, $available): bool {
            expect($rows[$missing->id])->toBe(['status' => 'Belum ada resep', 'portions' => 0]);
            expect($rows[$invalid->id])->toBe(['status' => 'Resep tidak valid', 'portions' => 0]);
            expect($rows[$empty->id])->toBe(['status' => 'Resep tersedia', 'portions' => 0]);
            expect($rows[$available->id])->toBe(['status' => 'Resep tersedia', 'portions' => 11]);

            return true;
        });
    $ingredient->forceFill(['reserved_stock' => '2.55'])->save();
    $page->call('refreshProducts')->assertViewHas('productOverview', fn ($rows) => $rows[$available->id]['portions'] === 0);
    expect($available->fresh()->current_stock)->toBe(999)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('menu queries only its own catalog collections and discounts retain complete product choices', function () {
    Product::factory()->count(12)->create();
    Livewire::test('pages::dashboard.products')->assertViewMissing('products')
        ->assertViewMissing('ingredients')->assertViewMissing('discounts');
    Livewire::test('pages::dashboard.discounts')->assertViewHas('products', fn ($rows) => $rows->count() === 12);
});

test('product editor explains missing categories and rejects deleted category selections', function () {
    $page = Livewire::test('pages::dashboard.products')->call('createProduct')
        ->assertSee('Belum ada kategori.')->assertSee('Kelola kategori')
        ->set('productName', 'Produk')->set('productPrice', '10000')->call('saveProduct')->assertHasErrors('productCategoryId');
    $category = Category::factory()->create();
    $category->delete();
    $page->set('productCategoryId', $category->id)->call('saveProduct')->assertHasErrors('productCategoryId');
    expect(Product::count())->toBe(0)->and(DB::table('domain_outbox')->count())->toBe(0);
});

test('closing unchanged product drafts skips confirmation and edited drafts can be resumed', function () {
    $product = Product::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('editProduct', $product->id)
        ->call('requestProductClose')->assertSet('showProductModal', false)->assertDispatched('product-dialog-closed')
        ->call('editProduct', $product->id)->set('productName', 'Baru')->call('requestProductClose')
        ->assertSet('showProductDiscardConfirmation', true)->assertSet('showProductModal', true)
        ->assertDispatched('product-confirmation-requested')->call('saveProduct')->assertSet('productName', 'Baru')
        ->call('continueProductEditing')->assertSet('showProductDiscardConfirmation', false)
        ->assertSet('productName', 'Baru')->assertDispatched('product-editing-resumed');
    expect($product->fresh()->name)->toBe($product->name)->and(DB::table('domain_outbox')->count())->toBe(0);
    $page->call('saveProduct')->assertHasNoErrors()->assertSet('showProductModal', false);
});

test('image only changes require discard confirmation', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    Livewire::test('pages::dashboard.products')->call('editProduct', $product->id)
        ->set('productImage', UploadedFile::fake()->image('replacement.png'))->call('requestProductClose')
        ->assertSet('showProductDiscardConfirmation', true)->call('discardProductChanges')
        ->assertSet('showProductModal', false)->assertSet('productImage', null);
    expect(DB::table('domain_outbox')->count())->toBe(0)->and(Storage::disk('public')->allFiles('products'))->toBe([]);
});

test('product save failure rolls back image and event writes and can be retried', function () {
    Storage::fake('public');
    $product = Product::factory()->withRecipe()->create(['name' => 'Awal', 'current_stock' => 10, 'reserved_stock' => 2]);
    $ingredient = $product->recipes->first()->inventoryItem;
    $stock = $ingredient->current_stock;
    $reservations = $ingredient->reserved_stock;
    $page = Livewire::test('pages::dashboard.products')->call('editProduct', $product->id)
        ->set('productName', 'Baru')->set('productImage', UploadedFile::fake()->image('replacement.png'));
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Simulated product outbox failure');
        }
    });
    $page->call('saveProduct')->assertHasErrors('productForm')->assertSee(__('catalog.errors.product_save_failed'))
        ->assertSet('showProductModal', true)->assertSet('productName', 'Baru');
    expect($product->fresh()->name)->toBe('Awal')->and(DB::table('domain_outbox')->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('products'))->toBe([]);
    $page->call('saveProduct')->assertHasNoErrors()->assertSet('showProductModal', false);
    expect($product->fresh()->name)->toBe('Baru')->and($product->fresh()->current_stock)->toBe(10)
        ->and($product->fresh()->reserved_stock)->toBe(2)->and($ingredient->fresh()->current_stock)->toBe($stock)
        ->and($ingredient->fresh()->reserved_stock)->toBe($reservations)
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductUpdated')->count())->toBe(1);
    Storage::disk('public')->assertExists($product->fresh()->image_url);
});

test('deleted product preserves its draft and cannot be saved', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('editProduct', $product->id)
        ->set('productName', 'Baru')->set('productImage', UploadedFile::fake()->image('replacement.png'));
    $product->delete();
    $page->call('saveProduct')->assertHasErrors('productForm')->assertSee(__('catalog.errors.product_missing'))
        ->assertSet('showProductModal', true)->assertSet('productName', 'Baru');
    expect(DB::table('domain_outbox')->count())->toBe(0)->and(Storage::disk('public')->allFiles('products'))->toBe([]);
});

test('product delete cancellation and confirmed deletion reset pagination', function () {
    $products = Product::factory()->count(11)->sequence(fn ($sequence) => ['name' => sprintf('Produk %02d', $sequence->index)])->create();
    $last = $products->last();
    $page = Livewire::test('pages::dashboard.products')->call('nextPage', 'productsPage')->call('requestProductDelete', $last->id)
        ->assertSet('showProductDeleteModal', true)->assertSet('deletingProductId', $last->id)
        ->call('closeProductDeleteModal')->assertSet('deletingProductId', null);
    expect($last->fresh()->trashed())->toBeFalse()->and(DB::table('domain_outbox')->count())->toBe(0);
    $page->call('requestProductDelete', $last->id)->call('deleteProduct')->assertHasNoErrors()
        ->assertSet('showProductDeleteModal', false)->assertSet('paginators.productsPage', 1)
        ->assertSee(__('catalog.messages.product_deleted'));
    expect($last->fresh()->trashed())->toBeTrue()->and(DB::table('domain_outbox')->count())->toBe(1);
});

test('deleted product is rechecked on deletion confirmation', function () {
    $product = Product::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('requestProductDelete', $product->id);
    $product->delete();
    $page->call('deleteProduct')->assertHasErrors('productDelete')->assertSee(__('catalog.errors.product_missing'))
        ->assertSet('showProductDeleteModal', true);
    expect(DB::table('domain_outbox')->count())->toBe(0);
});

test('product actions require an active editor or deletion confirmation', function (string $action) {
    Livewire::test('pages::dashboard.products')->call($action)->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['saveProduct', 'deleteProduct']);

test('product ids image path original draft and discard state are locked', function (string $property) {
    $product = Product::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('editProduct', $product->id);
    expect(fn () => $page->set($property, 999999))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['productId', 'productImageUrl', 'originalProductDraft', 'deletingProductId', 'showProductDiscardConfirmation']);

test('product actions require a manager', function (string $method) {
    $this->actingAs(User::factory()->create());
    $this->get(route('menu.menu'))->assertForbidden();
    $product = Product::factory()->create();
    $parameters = in_array($method, ['editProduct', 'requestProductDelete']) ? [$product->id] : [];
    Livewire::test('pages::dashboard.products')->call($method, ...$parameters)->assertForbidden();
    expect(DB::table('domain_outbox')->count())->toBe(0);
})->with(['createProduct', 'editProduct', 'saveProduct', 'requestProductClose', 'closeProductModal', 'continueProductEditing',
    'discardProductChanges', 'refreshProducts', 'requestProductDelete', 'closeProductDeleteModal', 'deleteProduct']);

test('product deletion failure rolls back and can be retried without duplicate events', function () {
    $product = Product::factory()->create();
    $page = Livewire::test('pages::dashboard.products')->call('requestProductDelete', $product->id);
    $failNextEvent = true;
    DB::listen(function (QueryExecuted $query) use (&$failNextEvent): void {
        if ($failNextEvent && str_starts_with($query->sql, 'insert into `domain_outbox`')) {
            $failNextEvent = false;
            throw new RuntimeException('Simulated product deletion outbox failure');
        }
    });
    $page->call('deleteProduct')->assertHasErrors('productDelete')->assertSet('showProductDeleteModal', true)
        ->assertSee(__('catalog.errors.product_delete_failed'));
    expect($product->fresh()->trashed())->toBeFalse()->and(DB::table('domain_outbox')->count())->toBe(0);
    $page->call('deleteProduct')->assertHasNoErrors()->assertSet('showProductDeleteModal', false);
    expect($product->fresh()->trashed())->toBeTrue()
        ->and(DB::table('domain_outbox')->where('event_name', 'ProductDeleted')->count())->toBe(1);
});

test('product list preserves long names in desktop rows and mobile cards', function () {
    $name = str_repeat('Produk', 42).'XYZ';
    $categoryName = str_repeat('Kategori ', 28);
    $category = Category::factory()->create(['name' => $categoryName]);
    Product::factory()->create(['name' => $name, 'category_id' => $category->id, 'image_url' => null]);
    $response = $this->get(route('menu.menu'))->assertOk()->assertSee($name)->assertSee($categoryName);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//table/tbody/tr/td[1]')->item(0)->textContent)->toContain($name)
        ->and($xpath->query('//article/h2|//article//h2')->item(0)->textContent)->toBe($name);
});
