<?php

namespace App\Enums;

enum DocumentTemplateType: string
{
    case Apa = 'apa';
    case Vancouver = 'vancouver';
    case Custom = 'custom';
}
