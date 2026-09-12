# Memory Module Rollout: Tenant-Global Knowledge Sources

## Architecture Shift
The Memory module has been updated from a strict **Agent-Owned** structure to a **Tenant-Global** architecture. 

### Why?
Previously, if a tenant had a "Refund Policy" document, they had to upload it directly to a specific Agent. If they wanted 5 Agents to know about the Refund Policy, they had to upload it 5 times, duplicating vectors and management overhead.

Now, all knowledge is uploaded to a global **Knowledge Hub** belonging to the Tenant. Agents then dynamically attach (Many-to-Many) to these global sources.

## Database Changes
- `tenant_knowledge_sources`: The new source of truth for all knowledge metadata.
- `agent_knowledge_source`: The pivot table defining which Agent has access to which knowledge source.
- `tenant_vector_memories`: Modified. `agent_id` dropped. `source_id` added. Vectors now belong to the document/source, not the agent directly.

## Laravel Workflow
1. User uploads a document via Knowledge Hub UI (`POST /api/v1/knowledge-sources`).
2. `KnowledgeHubController` creates a `KnowledgeSource` record (status: pending) and dispatches `ProcessKnowledgeSourceJob`.
3. `ProcessKnowledgeSourceJob` triggers the Python Sidecar `/api/v1/memory/ingest` endpoint.
4. Python Sidecar chunks, embeds, and stores vectors tagged with the `source_id`, then returns success.
5. In the Agent Builder, the User attaches the `KnowledgeSource` to an `AiAgent`.
6. When an incoming message triggers the Agent, `WorkflowPayload::fromAgent()` looks up all *active* knowledge sources attached to the agent, plucks their IDs, and passes them to the Python Sidecar inside `global_data.active_knowledge_source_ids`.

## Python Sidecar Updates
- **Ingestion (`semantic.py > ingest_document`)**: Now accepts `source_id` instead of `agent_id`.
- **Search (`knowledge_search.py`)**: Reads `active_knowledge_source_ids` from the context payload. If the array is empty, it bypasses the vector search entirely (O(1) fast exit).
- **Filtering (`semantic.py > search_memories`)**: Modified SQL query uses `AND source_id = ANY($3::int[])` to strictly scope semantic search to only the knowledge sources explicitly assigned to the running agent.

## Deployment / Rollout Steps
1. Run Laravel Migrations: `php artisan migrate`. (Note: This will drop legacy `agent_id` references in the vector DB. Data wipe of legacy test vectors is intended for this clean slate).
2. Build Vue SPA: `npm run build`.
3. Restart Python Sidecar to pick up updated Semantic Search code.
