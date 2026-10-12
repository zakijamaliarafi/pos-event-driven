<?php

namespace App\Domain\Orders;

use RuntimeException;

final class SubmissionConflict extends RuntimeException
{
    public function __construct(public readonly int $orderId)
    {
        parent::__construct(__('orders.errors.submission_conflict'));
    }
}
