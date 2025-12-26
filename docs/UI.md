# TOM3 - Basis UI

## Überblick

Die Basis-UI für TOM3 ist eine Single-Page-Application (SPA) mit modernem Design, die alle Kernfunktionen von TOM3 abdeckt.

## Features

### Dashboard
- Übersicht über Vorgänge, Projekte und Statistiken
- Aktuelle Vorgänge
- Status-Karten für schnelle Übersicht

### Vorgänge (Cases)
- Liste aller Vorgänge mit Filtern
- Status-Filter (neu, in_bearbeitung, wartend_intern, etc.)
- Engine-Filter (customer_inbound, ops, inside_sales, etc.)
- Suchfunktion
- Vorgangs-Details mit Blocker-Anzeige
- Workflow-Operationen (Übergabe, Rückläufer)

### Projekte
- Liste aller Projekte
- Projekt-Details
- Verknüpfung von Vorgängen mit Projekten

### Organisationen
- Liste aller Organisationen
- Erstellen neuer Organisationen
- Filter nach Org-Kind (customer, supplier, consultant, etc.)

### Personen
- Liste aller Personen
- Erstellen neuer Personen
- Kontaktinformationen

## Technologie

- **HTML5**: Struktur
- **CSS3**: Modernes, responsives Design mit CSS Variables
- **Vanilla JavaScript**: Keine Framework-Abhängigkeiten
- **Fetch API**: API-Kommunikation

## Struktur

```
public/
├── index.html          # Haupt-HTML
├── css/
│   └── style.css       # Styles
├── js/
│   ├── api.js          # API-Client
│   └── app.js          # Haupt-App-Logik
├── api/
│   ├── index.php       # API-Router
│   ├── cases.php       # Cases-Endpunkte
│   ├── workflow.php    # Workflow-Endpunkte
│   ├── projects.php    # Projects-Endpunkte
│   ├── orgs.php        # Orgs-Endpunkte
│   ├── persons.php     # Persons-Endpunkte
│   └── tasks.php       # Tasks-Endpunkte
└── .htaccess           # Apache Rewrite Rules
```

## Verwendung

### Setup

1. Stelle sicher, dass Apache mod_rewrite aktiviert ist
2. Konfiguriere den DocumentRoot auf `public/`
3. Oder verwende die URL: `http://localhost/TOM3/public/`

### API-Integration

Die UI kommuniziert mit der TOM3-API über `/api/*` Endpunkte.

**Beispiel:**
```javascript
// Vorgänge laden
const cases = await window.API.getCases({ status: 'in_bearbeitung' });

// Vorgang erstellen
const newCase = await window.API.createCase({
    title: 'Neuer Vorgang',
    description: 'Beschreibung',
    engine: 'ops'
});
```

## Design-System

### Farben

- **Primary**: `#2563eb` (Blau)
- **Success**: `#10b981` (Grün)
- **Warning**: `#f59e0b` (Orange)
- **Danger**: `#ef4444` (Rot)
- **Background**: `#f8fafc` (Hellgrau)
- **Sidebar**: `#1e293b` (Dunkelgrau)

### Status-Badges

- **Neu**: Blau
- **In Bearbeitung**: Grün
- **Wartend**: Gelb
- **Blockiert**: Rot
- **Eskaliert**: Dunkelrot
- **Abgeschlossen**: Grün

## Responsive Design

Die UI ist responsive und passt sich an verschiedene Bildschirmgrößen an:
- Desktop: Vollständige Sidebar
- Tablet: Kompakte Sidebar
- Mobile: Minimale Sidebar

## Erweiterungen

### Geplante Features

- [ ] Create-Modals für alle Entitäten
- [ ] Edit-Funktionalität
- [ ] Erweiterte Filter
- [ ] Export-Funktionen
- [ ] Notifications/Toast-Messages
- [ ] Dark Mode
- [ ] Graph-Visualisierung (Neo4j)

## Browser-Support

- Chrome/Edge (neueste Version)
- Firefox (neueste Version)
- Safari (neueste Version)

---

*Basis-UI für TOM3 - Vorgangssteuerungssystem*


