<?php

namespace App\Livewire\Concerns;

use App\Domain\Orders\SubmitOrder;
use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait RecoversOrderSubmissions
{
    #[Locked]
    public string $submissionKey = '';

    #[Locked]
    public ?int $recoveryOrderId = null;

    #[Locked]
    public string $submissionNotice = '';

    /** @return array{cashierId: ?int, deviceId: ?string} */
    abstract protected function submissionActor(): array;

    abstract protected function showPreviousSubmission(Order $order): void;

    abstract protected function submissionInvalidEvent(): string;

    public function checkSubmission(SubmitOrder $orders): void
    {
        $order = $this->lookupSubmission($orders);
        if ($order !== null) {
            $this->recoveryOrderId = $order->id;
            $this->submissionNotice = '';
            $this->resetValidation('cart');
        }
    }

    public function viewPreviousSubmission(SubmitOrder $orders): void
    {
        $order = $this->lookupSubmission($orders);
        if ($order !== null) {
            $this->recoveryOrderId = $order->id;
            $this->showPreviousSubmission($order);
        }
    }

    public function startNewSubmission(SubmitOrder $orders): void
    {
        if ($this->lookupSubmission($orders) !== null) {
            $this->resetSubmission();
            $this->resetValidation('cart');
        }
    }

    protected function resetSubmission(): void
    {
        $this->submissionKey = (string) Str::uuid();
        $this->recoveryOrderId = null;
        $this->submissionNotice = '';
    }

    protected function submissionRecoveryOrder(SubmitOrder $orders): ?Order
    {
        if ($this->recoveryOrderId === null) {
            return null;
        }

        try {
            $order = $orders->findSubmission($this->submissionKey, ...$this->submissionActor());

            return $order?->id === $this->recoveryOrderId ? $order : null;
        } catch (ValidationException) {
            return null;
        }
    }

    private function lookupSubmission(SubmitOrder $orders): ?Order
    {
        // Resolve the actor on every action, including cashier role authorization.
        $actor = $this->submissionActor();
        $this->submissionNotice = '';
        try {
            $order = $orders->findSubmission($this->submissionKey, ...$actor);
            $this->recoveryOrderId = $order?->id;
            if ($order === null) {
                $this->submissionNotice = __('orders.messages.submission_not_found');
            }

            return $order;
        } catch (ValidationException $exception) {
            $this->recoveryOrderId = null;
            $this->addError('cart', $exception->errors()['cart'][0]);
            $this->dispatch($this->submissionInvalidEvent());

            return null;
        }
    }
}
