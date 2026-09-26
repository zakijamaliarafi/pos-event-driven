<?php

use App\Domain\Orders\RecordCashPayment;
use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $customerName = '';
    public string $customerPhone = '';
    public string $orderType = 'dine_in';
    public ?int $tableNumber = null;
    public array $quantities = [];
    public string $message = '';

    #[On('order-view-changed')]
    public function refreshOrders(): void {}

    public function submit(SubmitOrder $orders): void
    {
        $cart = collect($this->quantities)
            ->filter(fn ($qty) => is_numeric($qty) && (int) $qty > 0)
            ->map(fn ($qty, $id): array => ['id' => (int) $id, 'qty' => (int) $qty])
            ->values()->all();

        if ($cart === []) {
            throw ValidationException::withMessages(['cart' => 'Choose at least one product.']);
        }

        $order = $orders->handle([
            'order_type' => $this->orderType,
            'table_number' => $this->tableNumber,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'cart' => $cart,
        ], cashierId: (int) Auth::id());

        $this->quantities = [];
        $this->message = "Order {$order->order_number} submitted. Confirm cash payment once stock is reserved.";
    }

    public function pay(int $orderId, RecordCashPayment $payments): void
    {
        $order = $payments->handle($orderId, (int) Auth::id(), "cash-order-{$orderId}");
        $this->message = "Cash recorded for {$order->order_number}. The kitchen will receive it shortly.";
    }

    public function with(): array
    {
        return [
            'products' => Product::query()->with('discounts')->where('is_available', true)->orderBy('name')->get(),
            'activeOrders' => Order::query()->whereDate('created_at', today())->whereIn('status', ['awaiting_inventory', 'waiting_payment', 'pending', 'preparing', 'ready'])->latest()->get(),
        ];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <?php if (isset($component)) { $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.pos-nav','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('pos-nav'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $attributes = $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $component = $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Cashier</p>
            <h1 class="mt-1 text-3xl font-bold">New sale</h1>
            <p class="mt-2 text-slate-600">Submit the order, then record cash payment when stock is reserved.</p>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message): ?><div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900"><?php echo e($message); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['cart'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['payment'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <form wire:submit="submit" class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold"><?php echo e($product->name); ?></h2>
                        <p class="mt-1 text-sm font-semibold text-blue-700"><?php echo e(config('pos.currency')); ?> <?php echo e(number_format($product->active_price, 0)); ?></p>
                        <p class="mt-1 text-xs text-slate-500">Available: <?php echo e(max(0, $product->current_stock - $product->reserved_stock)); ?></p>
                        <label class="mt-4 block text-sm text-slate-600">Quantity<input type="number" min="0" max="100" wire:model.number="quantities.<?php echo e($product->id); ?>" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                    </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </section>
            <aside class="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Sale details</h2>
                <label class="block text-sm font-medium">Order type<select wire:model.live="orderType" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"><option value="dine_in">Dine in</option><option value="takeaway">Takeaway</option></select></label>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($orderType === 'dine_in'): ?><label class="block text-sm font-medium">Table number<input type="number" min="1" wire:model="tableNumber" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['table_number'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="text-sm text-red-600"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <label class="block text-sm font-medium">Customer name<input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['customer_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="text-sm text-red-600"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <label class="block text-sm font-medium">Phone (optional)<input type="tel" wire:model="customerPhone" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800" wire:loading.attr="disabled">Submit sale</button>
            </aside>
        </form>

        <section wire:poll.2s class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Today's active orders</h2>
            <div class="space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $activeOrders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3 text-sm">
                        <div><strong><?php echo e($order->order_number); ?></strong><span class="ml-2 text-slate-500"><?php echo e($order->customer_name); ?> · <?php echo e($order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number); ?></span></div>
                        <span class="capitalize text-blue-700"><?php echo e(str_replace('_', ' ', $order->status)); ?></span>
                        <span class="font-medium"><?php echo e(config('pos.currency')); ?> <?php echo e(number_format($order->total_amount, 0)); ?></span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order->status === 'waiting_payment'): ?><button wire:click="pay(<?php echo e($order->id); ?>)" wire:confirm="Record cash payment for this order?" class="rounded-lg bg-blue-700 px-3 py-2 font-semibold text-white hover:bg-blue-800">Record cash</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    <p class="text-sm text-slate-500">No active orders today.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php /**PATH /var/www/html/resources/views/pages/order/create.blade.php ENDPATH**/ ?>