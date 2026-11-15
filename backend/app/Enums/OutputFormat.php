<?php

declare(strict_types=1);

namespace App\Enums;

enum OutputFormat: string
{
    case Outline = 'outline';
    case Draft = 'draft';
    case Checklist = 'checklist';
    case Schedule = 'schedule';
    case Message = 'message';
    case Notes = 'notes';
    case Slides = 'slides';
}
