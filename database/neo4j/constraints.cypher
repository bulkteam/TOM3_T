// TOM3 - Neo4j Constraints
// Erstellt Unique Constraints für UUIDs

// Org Constraints
CREATE CONSTRAINT org_uuid IF NOT EXISTS
FOR (o:Org) REQUIRE o.uuid IS UNIQUE;

// Person Constraints
CREATE CONSTRAINT person_uuid IF NOT EXISTS
FOR (p:Person) REQUIRE p.uuid IS UNIQUE;

// Project Constraints
CREATE CONSTRAINT project_uuid IF NOT EXISTS
FOR (pr:Project) REQUIRE pr.uuid IS UNIQUE;

// Case Constraints (optional, für v2)
CREATE CONSTRAINT case_uuid IF NOT EXISTS
FOR (c:Case) REQUIRE c.uuid IS UNIQUE;


