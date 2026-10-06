@props(['context', 'rows', 'total', 'blocked', 'linkedTable'])

<form wire:submit="submit" class="customer-cart-form flex flex-col" data-customer-checkout="{{ $context }}" novalidate>
    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-pos-border p-5">
        <h2 id="{{ $context }}-cart-heading" tabindex="-1" @if($context === 'mobile') autofocus @endif class="text-xl font-bold">Keranjang Anda</h2>
        @if($context === 'mobile')
            <flux:modal.close><flux:button square variant="ghost" icon="x-mark" wire:loading.attr="disabled" wire:target="submit" class="min-h-11 min-w-11" aria-label="Tutup keranjang" /></flux:modal.close>
        @endif
    </div>
    <div class="min-h-0 min-w-0 flex-1 overflow-y-auto p-4 sm:p-5" data-customer-cart-scroll>
        <fieldset wire:loading.attr="disabled" wire:target="submit" class="min-w-0 space-y-5" data-customer-cart-fields>
            @error('cart')<p role="alert" tabindex="-1" data-customer-invalid class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $message }}</p>@enderror
            <div class="space-y-4">
                @forelse($rows as $row)
                    <div wire:key="cart-{{ $context }}-{{ $row['key'] }}" class="border-b border-pos-border pb-4">
                        <div class="flex items-start gap-3">
                            <x-customer-product-image :path="$row['product']?->image_url" :show-label="false" wire:key="customer-image-cart-{{ $context }}-{{ $row['key'] }}-{{ md5((string) ($row['product']?->image_url)) }}" class="size-14 rounded-xl" />
                            <div class="min-w-0 flex-1">
                                <h3 class="break-words text-sm font-semibold">{{ $row['name'] }}</h3>
                                <p class="mt-1 break-words text-xs text-pos-muted">{{ config('pos.currency') }} {{ number_format($row['price'] / 100, 0) }} / produk</p>
                            </div>
                        </div>
                        @if($row['invalid'])
                            <p class="mt-2 text-xs leading-relaxed text-pos-danger" role="status">{{ $row['limit'] === 0 ? 'Produk ini tidak tersedia. Hapus dari keranjang.' : 'Jumlah tidak valid atau melebihi ketersediaan. Tersedia: '.$row['limit'].'. Kurangi atau hapus pilihan ini.' }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            @if($row['product'] && $row['quantity'] > 0)
                                <x-customer-quantity :product-id="$row['product']->id" :name="$row['name']" :quantity="$row['quantity']" :limit="$row['limit']" :context="$context" />
                            @endif
                            <p class="break-words text-sm font-semibold text-pos-link">{{ config('pos.currency') }} {{ number_format($row['subtotal'] / 100, 0) }}</p>
                            <flux:button variant="ghost" size="sm" wire:click="remove(@js($row['key']))" wire:loading.attr="disabled" wire:target="submit" class="min-h-11 text-pos-danger!" aria-label="Hapus {{ $row['name'] }} dari keranjang">Hapus</flux:button>
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl bg-pos-soft p-4 text-sm leading-relaxed text-pos-muted">Keranjang masih kosong. Tambahkan menu favorit Anda.</p>
                @endforelse
            </div>
            <div class="space-y-4">
                @if($linkedTable !== null)
                    <div>
                        <p class="text-sm font-medium">Nomor meja</p>
                        <p class="mt-2 rounded-lg border border-pos-border bg-pos-soft px-3 py-3 text-sm font-semibold">Meja {{ $linkedTable }}</p>
                        <p class="mt-1 text-xs text-pos-muted">Meja mengikuti tautan yang Anda buka. Jika keliru, pindai kode meja yang benar.</p>
                    </div>
                @else
                    <flux:field>
                        <flux:label for="{{ $context }}-table-number">Nomor meja <span class="text-pos-danger">*</span></flux:label>
                        <flux:input id="{{ $context }}-table-number" type="number" min="1" max="2147483647" inputmode="numeric" wire:model.blur="tableNumber" :invalid="$errors->has('table_number')" aria-invalid="{{ $errors->has('table_number') ? 'true' : 'false' }}" aria-describedby="{{ $context }}-table-error" required class="min-h-11" />
                        <flux:error id="{{ $context }}-table-error" name="table_number" />
                    </flux:field>
                @endif
                <flux:field>
                    <flux:label for="{{ $context }}-customer-name">Nama Anda <span class="text-pos-danger">*</span></flux:label>
                    <flux:input id="{{ $context }}-customer-name" type="text" maxlength="100" autocomplete="name" wire:model="customerName" :invalid="$errors->has('customer_name')" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="{{ $context }}-name-error" required class="min-h-11" />
                    <flux:error id="{{ $context }}-name-error" name="customer_name" />
                </flux:field>
                <flux:field>
                    <flux:label for="{{ $context }}-customer-phone">Nomor telepon <span class="font-normal text-pos-muted">(opsional)</span></flux:label>
                    <flux:input id="{{ $context }}-customer-phone" type="tel" maxlength="20" autocomplete="tel" wire:model="customerPhone" :invalid="$errors->has('customer_phone')" aria-invalid="{{ $errors->has('customer_phone') ? 'true' : 'false' }}" aria-describedby="{{ $context }}-phone-error" class="min-h-11" />
                    <flux:error id="{{ $context }}-phone-error" name="customer_phone" />
                </flux:field>
            </div>
        </fieldset>
    </div>
    <div class="shrink-0 space-y-3 border-t border-pos-border bg-pos-soft p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2 text-lg font-bold">
            <span>Total</span>
            <span class="break-words text-pos-link" data-test="cart-total-{{ $context }}">{{ config('pos.currency') }} {{ number_format($total / 100, 0) }}</span>
        </div>
        <p class="text-xs leading-relaxed text-pos-muted">Pesanan diverifikasi pramusaji. Bayar tunai di kasir setelah makan.</p>
        <flux:button type="submit" variant="primary" class="min-h-12 w-full" :disabled="$blocked" wire:loading.attr="disabled" wire:target="submit" wire:key="customer-submit-{{ $context }}-{{ $blocked ? 'blocked' : 'ready' }}">
            <span wire:loading.remove wire:target="submit">Kirim pesanan</span>
            <span wire:loading wire:target="submit">Mengirim pesanan…</span>
        </flux:button>
    </div>
</form>
