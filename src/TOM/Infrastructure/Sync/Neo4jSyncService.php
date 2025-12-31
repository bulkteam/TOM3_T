<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Sync;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Neo4j\Neo4jService;

/**
 * Neo4jSyncService - Synchronisiert Daten von MySQL nach Neo4j
 * 
 * Verarbeitet Events aus der Outbox und erstellt/aktualisiert Nodes und Relationships in Neo4j
 */
class Neo4jSyncService
{
    private PDO $db;
    private Neo4jService $neo4j;
    
    public function __construct(?PDO $db = null, ?Neo4jService $neo4j = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->neo4j = $neo4j ?? new Neo4jService();
    }
    
    /**
     * Verarbeitet ein Event aus der Outbox
     */
    public function processEvent(array $event): bool
    {
        try {
            $aggregateType = $event['aggregate_type'];
            $eventType = $event['event_type'];
            $payload = json_decode($event['payload'], true);
            
            switch ($aggregateType) {
                case 'org':
                    return $this->processOrgEvent($eventType, $payload);
                    
                case 'person':
                    return $this->processPersonEvent($eventType, $payload);
                    
                default:
                    // Unbekannte Aggregate-Typen werden ignoriert
                    return true;
            }
        } catch (\Exception $e) {
            error_log("Neo4j Sync Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verarbeitet Org-Events
     */
    private function processOrgEvent(string $eventType, array $payload): bool
    {
        switch ($eventType) {
            case 'OrgCreated':
            case 'OrgUpdated':
                return $this->syncOrg($payload);
                
            case 'OrgRelationAdded':
                return $this->syncOrgRelation($payload);
                
            case 'OrgRelationUpdated':
                return $this->syncOrgRelation($payload);
                
            case 'OrgRelationDeleted':
                return $this->deleteOrgRelation($payload);
                
            default:
                return true;
        }
    }
    
    /**
     * Verarbeitet Person-Events
     */
    private function processPersonEvent(string $eventType, array $payload): bool
    {
        switch ($eventType) {
            case 'PersonCreated':
            case 'PersonUpdated':
                return $this->syncPerson($payload);
                
            case 'PersonAffiliationAdded':
            case 'PersonAffiliationUpdated':
                return $this->syncPersonAffiliation($payload);
                
            case 'PersonAffiliationDeleted':
                return $this->deletePersonAffiliation($payload);
                
            default:
                return true;
        }
    }
    
    /**
     * Synchronisiert eine Organisation nach Neo4j
     */
    private function syncOrg(array $org): bool
    {
        $query = "
            MERGE (o:Org {uuid: \$uuid})
            SET o.name = \$name,
                o.org_kind = \$org_kind,
                o.status = \$status,
                o.external_ref = \$external_ref,
                o.updated_at = datetime()
            RETURN o
        ";
        
        $this->neo4j->run($query, [
            'uuid' => $org['org_uuid'],
            'name' => $org['name'] ?? '',
            'org_kind' => $org['org_kind'] ?? 'other',
            'status' => $org['status'] ?? 'lead',
            'external_ref' => $org['external_ref'] ?? null
        ]);
        
        return true;
    }
    
    /**
     * Synchronisiert eine Firmenrelation nach Neo4j
     */
    private function syncOrgRelation(array $relation): bool
    {
        $relationType = $relation['relation_type'] ?? 'subsidiary_of';
        
        // Bestimme Relationship-Typ basierend auf relation_type
        $neo4jRelType = $this->mapRelationTypeToNeo4j($relationType);
        
        $query = "
            MATCH (parent:Org {uuid: \$parent_uuid})
            MATCH (child:Org {uuid: \$child_uuid})
            MERGE (parent)-[r:$neo4jRelType]->(child)
            SET r.relation_type = \$relation_type,
                r.ownership_percent = \$ownership_percent,
                r.since_date = \$since_date,
                r.until_date = \$until_date,
                r.is_direct = \$is_direct,
                r.has_voting_rights = \$has_voting_rights,
                r.is_current = \$is_current,
                r.updated_at = datetime()
            RETURN r
        ";
        
        $this->neo4j->run($query, [
            'parent_uuid' => $relation['parent_org_uuid'],
            'child_uuid' => $relation['child_org_uuid'],
            'relation_type' => $relationType,
            'ownership_percent' => $relation['ownership_percent'] ?? null,
            'since_date' => $relation['since_date'] ?? null,
            'until_date' => $relation['until_date'] ?? null,
            'is_direct' => $relation['is_direct'] ?? 1,
            'has_voting_rights' => $relation['has_voting_rights'] ?? 0,
            'is_current' => $relation['is_current'] ?? 1
        ]);
        
        return true;
    }
    
    /**
     * Löscht eine Firmenrelation aus Neo4j
     */
    private function deleteOrgRelation(array $payload): bool
    {
        $relationUuid = $payload['relation_uuid'] ?? null;
        
        if (!$relationUuid) {
            return false;
        }
        
        // Versuche Relation aus DB zu holen (falls noch vorhanden)
        $stmt = $this->db->prepare("
            SELECT parent_org_uuid, child_org_uuid, relation_type
            FROM org_relation
            WHERE relation_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $relationUuid]);
        $relation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Wenn Relation nicht mehr in DB existiert, versuche alle möglichen Relationship-Typen
        if (!$relation) {
            // Fallback: Lösche alle Relationships zwischen allen möglichen Org-Paaren
            // Dies ist notwendig, wenn die Relation bereits aus MySQL gelöscht wurde
            // aber noch in Neo4j existiert
            $query = "
                MATCH (parent:Org)-[r]->(child:Org)
                WHERE r.relation_uuid = \$relation_uuid
                DELETE r
            ";
            
            try {
                $this->neo4j->run($query, [
                    'relation_uuid' => $relationUuid
                ]);
                return true;
            } catch (\Exception $e) {
                // Wenn das auch fehlschlägt, versuche alle Relationship-Typen zu durchsuchen
                $relationTypes = ['PART_OF', 'OWNS', 'MERGED_WITH', 'ACQUIRED', 'SUPPLIES', 'CUSTOMER_OF', 'PARTNER_OF', 'RELATED_TO'];
                foreach ($relationTypes as $relType) {
                    try {
                        $query = "MATCH ()-[r:$relType]->() WHERE r.relation_uuid = \$relation_uuid DELETE r";
                        $this->neo4j->run($query, ['relation_uuid' => $relationUuid]);
                        return true;
                    } catch (\Exception $e2) {
                        // Weiter mit nächstem Typ
                        continue;
                    }
                }
                // Wenn nichts gefunden wurde, ist das ok (Relation existiert vielleicht nicht mehr)
                return true;
            }
        }
        
        $neo4jRelType = $this->mapRelationTypeToNeo4j($relation['relation_type']);
        
        $query = "
            MATCH (parent:Org {uuid: \$parent_uuid})-[r:$neo4jRelType]->(child:Org {uuid: \$child_uuid})
            DELETE r
        ";
        
        $this->neo4j->run($query, [
            'parent_uuid' => $relation['parent_org_uuid'],
            'child_uuid' => $relation['child_org_uuid']
        ]);
        
        return true;
    }
    
    /**
     * Synchronisiert eine Person nach Neo4j
     */
    private function syncPerson(array $person): bool
    {
        $query = "
            MERGE (p:Person {uuid: \$uuid})
            SET p.display_name = \$display_name,
                p.email = \$email,
                p.phone = \$phone,
                p.updated_at = datetime()
            RETURN p
        ";
        
        $this->neo4j->run($query, [
            'uuid' => $person['person_uuid'],
            'display_name' => $person['display_name'] ?? '',
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null
        ]);
        
        return true;
    }
    
    /**
     * Synchronisiert eine Person-Affiliation nach Neo4j
     */
    private function syncPersonAffiliation(array $affiliation): bool
    {
        $query = "
            MATCH (p:Person {uuid: \$person_uuid})
            MATCH (o:Org {uuid: \$org_uuid})
            MERGE (p)-[r:AFFILIATED_WITH]->(o)
            SET r.kind = \$kind,
                r.title = \$title,
                r.since_date = \$since_date,
                r.until_date = \$until_date,
                r.updated_at = datetime()
            RETURN r
        ";
        
        $this->neo4j->run($query, [
            'person_uuid' => $affiliation['person_uuid'],
            'org_uuid' => $affiliation['org_uuid'],
            'kind' => $affiliation['kind'] ?? 'employee',
            'title' => $affiliation['title'] ?? null,
            'since_date' => $affiliation['since_date'] ?? null,
            'until_date' => $affiliation['until_date'] ?? null
        ]);
        
        return true;
    }
    
    /**
     * Löscht eine Person-Affiliation aus Neo4j
     */
    private function deletePersonAffiliation(array $payload): bool
    {
        $query = "
            MATCH (p:Person {uuid: \$person_uuid})-[r:AFFILIATED_WITH]->(o:Org {uuid: \$org_uuid})
            DELETE r
        ";
        
        $this->neo4j->run($query, [
            'person_uuid' => $payload['person_uuid'],
            'org_uuid' => $payload['org_uuid']
        ]);
        
        return true;
    }
    
    /**
     * Mappt SQL relation_type zu Neo4j Relationship-Typ
     */
    private function mapRelationTypeToNeo4j(string $relationType): string
    {
        $mapping = [
            'subsidiary_of' => 'PART_OF',
            'parent_of' => 'PART_OF',
            'division_of' => 'PART_OF',
            'brand_of' => 'PART_OF',
            'owns_stake_in' => 'OWNS',
            'owns' => 'OWNS',
            'merged_with' => 'MERGED_WITH',
            'acquired' => 'ACQUIRED',
            'supplier_of' => 'SUPPLIES',
            'customer_of' => 'CUSTOMER_OF',
            'partner_of' => 'PARTNER_OF'
        ];
        
        return $mapping[$relationType] ?? 'RELATED_TO';
    }
    
    /**
     * Initial-Sync: Synchronisiert alle bestehenden Daten nach Neo4j
     * Nützlich für den ersten Start oder nach Problemen
     */
    public function initialSync(): array
    {
        $stats = [
            'orgs' => 0,
            'persons' => 0,
            'relations' => 0,
            'affiliations' => 0
        ];
        
        // Sync alle Organisationen
        $stmt = $this->db->query("SELECT * FROM org");
        $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orgs as $org) {
            if ($this->syncOrg($org)) {
                $stats['orgs']++;
            }
        }
        
        // Sync alle Personen
        $stmt = $this->db->query("SELECT * FROM person");
        $persons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($persons as $person) {
            if ($this->syncPerson($person)) {
                $stats['persons']++;
            }
        }
        
        // Sync alle Firmenrelationen
        $stmt = $this->db->query("
            SELECT r.*, 
                   parent.name as parent_name,
                   child.name as child_name
            FROM org_relation r
            LEFT JOIN org parent ON r.parent_org_uuid = parent.org_uuid
            LEFT JOIN org child ON r.child_org_uuid = child.org_uuid
        ");
        $relations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($relations as $relation) {
            if ($this->syncOrgRelation($relation)) {
                $stats['relations']++;
            }
        }
        
        // Sync alle Person-Affiliationen
        $stmt = $this->db->query("SELECT * FROM person_affiliation");
        $affiliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($affiliations as $affiliation) {
            if ($this->syncPersonAffiliation($affiliation)) {
                $stats['affiliations']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Verarbeitet alle unverarbeiteten Events aus der Outbox
     */
    public function processOutbox(int $limit = 100): int
    {
        $stmt = $this->db->prepare("
            SELECT 
                event_uuid,
                aggregate_type,
                aggregate_uuid,
                event_type,
                payload,
                created_at
            FROM outbox_event
            WHERE processed_at IS NULL
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $processed = 0;
        
        foreach ($events as $event) {
            if ($this->processEvent($event)) {
                // Markiere Event als verarbeitet
                $updateStmt = $this->db->prepare("
                    UPDATE outbox_event 
                    SET processed_at = NOW() 
                    WHERE event_uuid = :uuid
                ");
                $updateStmt->execute(['uuid' => $event['event_uuid']]);
                $processed++;
            }
        }
        
        return $processed;
    }
}

