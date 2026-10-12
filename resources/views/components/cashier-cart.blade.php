@props(['context', 'rows', 'total', 'count', 'blocked', 'recoveryOrder', 'orderType'])
<form wire:submit="submit" novalidate class="cashier-cart-form flex flex-col" data-cashier-checkout="{{ $context }}">
    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-pos-border p-5">
        <div><h2 id="cashier-{{ $context }}-cart-heading" tabindex="-1" @if($context === 'mobile') autofocus @endif class="text-xl font-bold">Keranjang penjualan</h2><p class="mt-1 text-xs text-pos-muted">{{ $rows->count() }} menu · {{ $count }} produk</p></div>
        @if($context === 'mobile')<flux:button type="button" square variant="ghost" icon="x-mark" wire:click="$set('showCart', false)" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" class="min-h-11 min-w-11" aria-label="Tutup keranjang" />@endif
    </div>
    <div class="min-h-0 min-w-0 flex-1 overflow-y-auto p-4 sm:p-5">
        <x-order-submission-summary :order="$recoveryOrder" :actions="true" />
        @if($this->submissionNotice)
            <p role="status" class="mb-4 rounded-xl bg-pos-soft p-3 text-sm">{{ $this->submissionNotice }}</p>
        @endif
        <fieldset class="min-w-0 space-y-5" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" data-cashier-cart-fields>
            @error('cart')<p role="alert" tabindex="-1" data-cashier-invalid class="rounded-xl border border-pos-danger-border bg-pos-danger-soft p-3 text-sm text-pos-danger">{{ $message }}</p>@enderror
            <div class="space-y-4">
                @forelse($rows as $row)
                    <div wire:key="cashier-cart-{{ $context }}-{{ $row['key'] }}" class="space-y-3 border-b border-pos-border pb-4">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words text-sm font-semibold">{{ $row['name'] }}</h3><p class="mt-1 text-xs text-pos-muted"><x-cashier-money :hundredths="$row['price']" /> / produk</p></div><flux:button type="button" variant="ghost" wire:click="remove(@js($row['key']))" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" class="min-h-11 shrink-0 text-pos-danger!" aria-label="Hapus {{ $row['name'] }} dari keranjang">Hapus</flux:button></div>
                        <p id="cashier-{{ $context }}-warning-{{ $row['key'] }}" @class(['text-xs leading-relaxed text-pos-danger', 'sr-only' => ! $row['invalid']]) @if($row['invalid']) role="status" @endif>{{ $row['invalid'] ? ($row['limit'] === 0 ? 'Produk ini tidak tersedia. Hapus dari keranjang.' : 'Jumlah harus bulat dari 1 sampai '.$row['limit'].'. Kurangi atau hapus pilihan ini.') : 'Tersedia: '.$row['limit'] }}</p>
                        @if($row['product'] && ! $row['product']->trashed())<x-cashier-quantity :product-id="$row['product']->id" :name="$row['name']" :quantity="$row['quantity'] ?? ''" :limit="$row['limit']" :context="$context" :invalid="$row['invalid']" />@endif
                        <p class="text-right text-sm font-semibold text-pos-link">Subtotal @if($row['subtotal'] !== null)<x-cashier-money :hundredths="$row['subtotal']" />@else<span>—</span>@endif</p>
                    </div>
                @empty<p class="rounded-xl bg-pos-soft p-4 text-sm text-pos-muted">Keranjang masih kosong. Tambahkan menu untuk memulai penjualan.</p>@endforelse
            </div>
            <div class="space-y-4">
                <flux:field><flux:label for="cashier-{{ $context }}-order-type">Jenis pesanan <span class="text-pos-danger">*</span></flux:label><flux:select id="cashier-{{ $context }}-order-type" wire:model.live="orderType" aria-invalid="{{ $errors->has('order_type') ? 'true' : 'false' }}" aria-describedby="cashier-{{ $context }}-type-error" class="min-h-11"><option value="dine_in">Makan di tempat</option><option value="takeaway">Bawa pulang</option></flux:select><flux:error name="order_type" id="cashier-{{ $context }}-type-error" /></flux:field>
                @if($orderType === 'dine_in')<flux:field><flux:label for="cashier-{{ $context }}-table">Nomor meja <span class="text-pos-danger">*</span></flux:label><flux:input id="cashier-{{ $context }}-table" type="number" min="1" max="2147483647" step="1" inputmode="numeric" wire:model="tableNumber" :invalid="$errors->has('table_number')" aria-invalid="{{ $errors->has('table_number') ? 'true' : 'false' }}" aria-describedby="cashier-{{ $context }}-table-error" required class="min-h-11" /><flux:error id="cashier-{{ $context }}-table-error" name="table_number" /></flux:field>@endif
                <flux:field><flux:label for="cashier-{{ $context }}-name">Nama pelanggan <span class="text-pos-danger">*</span></flux:label><flux:input id="cashier-{{ $context }}-name" wire:model="customerName" maxlength="100" autocomplete="off" :invalid="$errors->has('customer_name')" aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" aria-describedby="cashier-{{ $context }}-name-error" required class="min-h-11" /><flux:error id="cashier-{{ $context }}-name-error" name="customer_name" /></flux:field>
                <flux:field><flux:label for="cashier-{{ $context }}-phone">Nomor telepon <span class="font-normal text-pos-muted">(opsional)</span></flux:label><flux:input id="cashier-{{ $context }}-phone" type="tel" wire:model="customerPhone" maxlength="20" autocomplete="off" :invalid="$errors->has('customer_phone')" aria-invalid="{{ $errors->has('customer_phone') ? 'true' : 'false' }}" aria-describedby="cashier-{{ $context }}-phone-error" class="min-h-11" /><flux:error id="cashier-{{ $context }}-phone-error" name="customer_phone" /></flux:field>
            </div>
        </fieldset>
    </div>
    <div class="shrink-0 space-y-3 border-t border-pos-border bg-pos-soft p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:p-5">
        <div class="flex flex-wrap justify-between gap-2 text-lg font-bold"><span>Total</span><span class="break-words text-pos-link" data-test="cashier-cart-total-{{ $context }}"><x-cashier-money :hundredths="$total" /></span></div>
        @if($rows->contains('invalid', true))<p role="status" class="text-xs text-pos-danger">Perbaiki pilihan yang tidak valid sebelum mengirim penjualan.</p>@endif
        <p class="text-xs leading-relaxed text-pos-muted">Kirim penjualan → stok dicadangkan → pembayaran tunai di muka → dapur.</p>
        <flux:button type="submit" variant="primary" :disabled="$blocked" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission,increment,decrement,remove,quantities" wire:key="cashier-submit-{{ $context }}-{{ $blocked ? 'blocked' : 'ready' }}" class="min-h-12 w-full"><span wire:loading.remove wire:target="submit">Kirim penjualan</span><span wire:loading wire:target="submit">Mengirim penjualan…</span></flux:button>
        <flux:button type="button" wire:click="checkSubmission" wire:loading.attr="disabled" wire:target="submit,checkSubmission,viewPreviousSubmission,startNewSubmission" class="min-h-11 w-full">
            <span wire:loading.remove wire:target="checkSubmission">Periksa pengiriman</span>
            <span wire:loading wire:target="checkSubmission">Memeriksa pengiriman…</span>
        </flux:button>
    </div>
</form>
