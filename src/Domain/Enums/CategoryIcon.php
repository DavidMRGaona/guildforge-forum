<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * The icons a category may show: a closed list, so nothing a person types ever reaches the page
 * as markup. The value is the Heroicons name the pages draw.
 */
enum CategoryIcon: string
{
    case Chat = 'chat-bubble-left-right';
    case Megaphone = 'megaphone';
    case Dice = 'cube';
    case Puzzle = 'puzzle-piece';
    case Users = 'user-group';
    case Calendar = 'calendar-days';
    case Map = 'map';
    case Book = 'book-open';
    case Sparkles = 'sparkles';
    case Shield = 'shield-check';
    case Trophy = 'trophy';
    case Flag = 'flag';
    case Lock = 'lock-closed';
    case Question = 'question-mark-circle';
}
