<?php
/**
 * TOM3 - Customer Number Configuration
 * 
 * Konfiguration für die automatische Generierung von Kundennummern.
 * Die Kundennummer wird in der Spalte `external_ref` der `org` Tabelle gespeichert.
 */

return [
    /**
     * Startnummer für neue Kundennummern
     * Die erste automatisch generierte Kundennummer beginnt bei diesem Wert.
     */
    'start_number' => 100,
    
    /**
     * Format der Kundennummer
     * 'numeric' = nur Zahlen (z.B. 100, 101, 102)
     * 'padded' = mit führenden Nullen (z.B. 000100, 000101, 000102)
     * 'prefix' = mit Präfix (z.B. K-100, K-101, K-102)
     */
    'format' => 'numeric',
    
    /**
     * Präfix für Kundennummern (nur wenn format = 'prefix')
     */
    'prefix' => 'K-',
    
    /**
     * Anzahl der Stellen für gepaddete Nummern (nur wenn format = 'padded')
     */
    'padding_length' => 6
];


