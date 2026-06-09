<?php

namespace App\Enums;

enum PaymentFlow: string
{
    case DocumentCheckout = 'document_checkout';
    case ProSubscription = 'pro_subscription';
    case RegistrationCheckout = 'registration_checkout';
}
