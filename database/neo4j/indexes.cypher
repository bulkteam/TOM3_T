// TOM3 - Neo4j Indexes
// Erstellt Indexes für bessere Query-Performance

// Org Indexes
CREATE INDEX org_name IF NOT EXISTS
FOR (o:Org) ON (o.name);

CREATE INDEX org_kind IF NOT EXISTS
FOR (o:Org) ON (o.org_kind);

// Person Indexes
CREATE INDEX person_email IF NOT EXISTS
FOR (p:Person) ON (p.email);

CREATE INDEX person_display_name IF NOT EXISTS
FOR (p:Person) ON (p.display_name);

// Project Indexes
CREATE INDEX project_status IF NOT EXISTS
FOR (pr:Project) ON (pr.status);

CREATE INDEX project_priority IF NOT EXISTS
FOR (pr:Project) ON (pr.priority);

// Case Indexes (optional, für v2)
CREATE INDEX case_status IF NOT EXISTS
FOR (c:Case) ON (c.status);

CREATE INDEX case_engine IF NOT EXISTS
FOR (c:Case) ON (c.engine);


