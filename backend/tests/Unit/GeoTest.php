<?php

use App\Support\Geo;

it('calcule une distance réaliste (Casablanca → Mohammedia ≈ 23 km)', function () {
    expect(Geo::distanceM(33.5731, -7.5898, 33.6866, -7.3830) / 1000)->toBeGreaterThan(21)->toBeLessThan(25);
});

it('calcule le cap', function () {
    expect(Geo::bearing(33.0, -7.0, 34.0, -7.0))->toBe(0)
        ->and(Geo::bearing(33.0, -7.0, 33.0, -6.0))->toBeGreaterThanOrEqual(89)->toBeLessThanOrEqual(91);
});

it('détecte un point dans un cercle', function () {
    expect(Geo::inCircle(33.5318 + 0.0018, -7.6425, 33.5318, -7.6425, 250))->toBeTrue()   // ~200 m
        ->and(Geo::inCircle(33.5318 + 0.0027, -7.6425, 33.5318, -7.6425, 250))->toBeFalse(); // ~300 m
});

it('détecte un point dans un polygone', function () {
    $square = [[33.60, -7.55], [33.60, -7.53], [33.62, -7.53], [33.62, -7.55]];
    expect(Geo::inPolygon(33.61, -7.54, $square))->toBeTrue()
        ->and(Geo::inPolygon(33.63, -7.54, $square))->toBeFalse()
        ->and(Geo::inPolygon(33.61, -7.56, $square))->toBeFalse();
});
