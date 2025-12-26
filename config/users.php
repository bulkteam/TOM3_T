<?php
/**
 * TOM3 - User Configuration
 * 
 * Einfache Liste von verfügbaren Usern für Account Owner Zuordnung und Rollen-Management.
 * Später kann dies durch eine echte User-Verwaltung ersetzt werden.
 * 
 * Rollen-Typen:
 * - workflow_roles: Rollen für Vorgangs-Workflows (customer_inbound, ops, inside_sales, outside_sales, order_admin)
 * - account_team_roles: Rollen im Account-Team (co_owner, support, backup, technical)
 * - permissions: System-Berechtigungen (admin, sales, viewer)
 */

return [
    // Verfügbare Workflow-Rollen (Engines)
    'workflow_roles' => [
        'customer_inbound' => [
            'name' => 'Customer Inbound',
            'description' => 'Eingehende Kundenanfragen - Annahme, Klassifikation, Routing'
        ],
        'ops' => [
            'name' => 'OPS',
            'description' => 'Operations - Strukturierung, Bearbeitung, Entscheidungsreife'
        ],
        'inside_sales' => [
            'name' => 'Inside Sales',
            'description' => 'Innendienst-Vertrieb - Wachstum, Akquise, Qualifizierung'
        ],
        'outside_sales' => [
            'name' => 'Outside Sales',
            'description' => 'Außendienst-Vertrieb - Entscheidungsführung, Verhandlung, Abschluss'
        ],
        'order_admin' => [
            'name' => 'Order Admin',
            'description' => 'Auftragsverwaltung - Formalität, Dokumente, ERP-Korrektheit'
        ]
    ],
    
    // Verfügbare Account-Team-Rollen
    'account_team_roles' => [
        'co_owner' => [
            'name' => 'Co-Owner',
            'description' => 'Mitverantwortlicher Account Owner'
        ],
        'support' => [
            'name' => 'Support',
            'description' => 'Technischer Support für den Account'
        ],
        'backup' => [
            'name' => 'Backup',
            'description' => 'Vertretung für den Account Owner'
        ],
        'technical' => [
            'name' => 'Technical',
            'description' => 'Technischer Ansprechpartner'
        ]
    ],
    
    // Verfügbare Berechtigungs-Rollen
    'permission_roles' => [
        'admin' => [
            'name' => 'Administrator',
            'description' => 'Vollzugriff auf alle Funktionen'
        ],
        'sales' => [
            'name' => 'Sales',
            'description' => 'Vollzugriff auf Sales-Funktionen'
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Nur Lesezugriff'
        ]
    ],
    
    // User-Definitionen
    'users' => [
        [
            'user_id' => 'max.mustermann',
            'display_name' => 'Max Mustermann',
            'email' => 'max.mustermann@example.com',
            // Berechtigungs-Rolle (für System-Zugriff)
            'permission_role' => 'sales',
            // Workflow-Rollen (welche Engines kann dieser User bedienen?)
            'workflow_roles' => ['ops', 'inside_sales'],
            // Kann als Account Owner fungieren?
            'can_be_account_owner' => true
        ],
        [
            'user_id' => 'anna.schmidt',
            'display_name' => 'Anna Schmidt',
            'email' => 'anna.schmidt@example.com',
            'permission_role' => 'sales',
            'workflow_roles' => ['customer_inbound', 'ops'],
            'can_be_account_owner' => true
        ],
        [
            'user_id' => 'peter.mueller',
            'display_name' => 'Peter Müller',
            'email' => 'peter.mueller@example.com',
            'permission_role' => 'sales',
            'workflow_roles' => ['outside_sales', 'order_admin'],
            'can_be_account_owner' => true
        ],
        [
            'user_id' => 'default_user',
            'display_name' => 'Standard Benutzer',
            'email' => 'default@example.com',
            'permission_role' => 'admin',
            'workflow_roles' => ['customer_inbound', 'ops', 'inside_sales', 'outside_sales', 'order_admin'],
            'can_be_account_owner' => true
        ]
    ]
];

