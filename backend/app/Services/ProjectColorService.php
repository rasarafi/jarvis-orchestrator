<?php

namespace App\Services;

use App\Models\Project;

class ProjectColorService
{
    /**
     * Stable hex color derived from the slug. Returns a 6-char #RRGGBB string
     * so consumers can safely append hex-alpha suffixes (`${color}1f` etc.)
     * for translucent backgrounds and borders. Equivalent hue distribution to
     * the original HSL(h, 70%, 60%) formulation, just pre-rendered to RGB.
     */
    public function colorForSlug(string $slug): string
    {
        $hue = hexdec(substr(sha1($slug), 0, 8)) % 360;
        [$r, $g, $b] = $this->hslToRgb($hue, 70, 60);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Returns ['slug' => '#RRGGBB', ...] for every project in the DB.
     */
    public function allColors(): array
    {
        return Project::query()
            ->pluck('slug')
            ->mapWithKeys(fn ($slug) => [$slug => $this->colorForSlug($slug)])
            ->all();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hslToRgb(int $h, int $s, int $l): array
    {
        $sNorm = $s / 100;
        $lNorm = $l / 100;
        $c = (1 - abs(2 * $lNorm - 1)) * $sNorm;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $lNorm - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };
        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }
}
