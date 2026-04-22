<?php

declare(strict_types=1);

namespace App\Application\Barbershop\Exception;

final class SlotAlreadyBookedException extends \DomainException
{
    public function __construct() {
        parent::__construct('This slot is no longer available. Please choose another time.');
    }

}
