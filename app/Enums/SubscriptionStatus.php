<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trial = 'trial';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case PastDue = 'past_due';
}
