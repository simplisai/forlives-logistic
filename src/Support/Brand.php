<?php

namespace Numok\Support;

/**
 * Resolves the active brand from the request host.
 *
 * One deployment serves multiple branded partner portals. The brand
 * (name, logo, favicon and the program scope) is chosen by inspecting
 * $_SERVER['HTTP_HOST']. Adding a new brand is a single entry here.
 */
class Brand {
    /**
     * Brand definitions, keyed by a substring matched against the host.
     * The first matching needle wins; otherwise the default brand is used.
     */
    private const BRANDS = [
        'solargrove' => [
            'key'           => 'solargrove',
            'name'          => 'Solar Grove',
            'logo'          => '/assets/images/solargrove-logo.svg',
            'favicon'       => '/assets/images/solargrove-favicon.svg',
            'favicon_type'  => 'image/svg+xml',
            'program_ref'   => 'solar-grove',
        ],
    ];

    private const DEFAULT_BRAND = [
        'key'           => 'forlives',
        'name'          => 'Forlives Logistic',
        'logo'          => '/assets/images/forlives-logo.svg',
        'favicon'       => '/assets/images/favicon.png',
        'favicon_type'  => 'image/png',
        'program_ref'   => null,
    ];

    public static function current(): array {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');

        foreach (self::BRANDS as $needle => $brand) {
            if (strpos($host, $needle) !== false) {
                return $brand;
            }
        }

        return self::DEFAULT_BRAND;
    }

    /**
     * The programs.external_ref this host is scoped to, or null for no scope
     * (shows every program).
     */
    public static function programRef(): ?string {
        return self::current()['program_ref'];
    }
}
