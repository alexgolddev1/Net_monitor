<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class BytesExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
        ];
    }

    public function formatBytes(int|float|string|null $bytes): string
    {
        $value = (float) $bytes;

        if ($value >= 1073741824) {
            return sprintf('%.1f ГБ', $value / 1073741824);
        }

        if ($value >= 1048576) {
            return sprintf('%.1f МБ', $value / 1048576);
        }

        if ($value >= 1024) {
            return sprintf('%.1f КБ', $value / 1024);
        }

        return sprintf('%d Б', (int) $value);
    }
}
