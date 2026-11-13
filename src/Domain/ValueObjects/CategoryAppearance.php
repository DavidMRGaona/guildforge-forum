<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;

/**
 * How a category looks: a colour (#rrggbb) and an icon from a closed list, both optional.
 */
final readonly class CategoryAppearance
{
    public ?string $color;

    /**
     * @throws InvalidCategoryException for a colour that is not #rrggbb
     */
    public function __construct(?string $color, public ?CategoryIcon $icon)
    {
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}\z/', $color) !== 1) {
            throw InvalidCategoryException::invalidColor($color);
        }

        $this->color = $color === null ? null : strtolower($color);
    }

    public static function plain(): self
    {
        return new self(null, null);
    }
}
