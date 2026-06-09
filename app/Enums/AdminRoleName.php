<?php

namespace App\Enums;

enum AdminRoleName: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Editor = 'editor';
}
