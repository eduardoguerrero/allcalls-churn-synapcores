<?php

declare(strict_types=1);

namespace App\Enums;

enum Tier: string
{
    case Bronze = 'Bronze';
    case Silver = 'Silver';
    case Gold = 'Gold';
    case Platinum = 'Platinum';
}
