<?php

namespace App\Enums;

enum UserPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Trial = 'trial';
}
