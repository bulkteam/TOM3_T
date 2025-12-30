<?php
/**
 * TOM3 - Address Types Configuration
 * 
 * Verfügbare Adresstypen für Organisationen
 */

return [
    'headquarters' => [
        'code' => 'headquarters',
        'name' => 'Hauptsitz',
        'description' => 'Hauptsitz / Zentrale der Organisation'
    ],
    'delivery' => [
        'code' => 'delivery',
        'name' => 'Lieferanschrift',
        'description' => 'Adresse für Warenlieferungen'
    ],
    'billing' => [
        'code' => 'billing',
        'name' => 'Rechnungsadresse',
        'description' => 'Adresse für Rechnungen und Zahlungen'
    ],
    'branch' => [
        'code' => 'branch',
        'name' => 'Niederlassung',
        'description' => 'Zweigstelle / Niederlassung'
    ],
    'warehouse' => [
        'code' => 'warehouse',
        'name' => 'Lager',
        'description' => 'Lagerstandort'
    ],
    'production' => [
        'code' => 'production',
        'name' => 'Produktionsstandort',
        'description' => 'Produktionsstätte / Werk'
    ],
    'other' => [
        'code' => 'other',
        'name' => 'Sonstige',
        'description' => 'Sonstige Adresse'
    ]
];



