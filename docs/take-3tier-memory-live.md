# 🚀 Taking 3-Tier Memory & Knowledge Hub Live: Step-by-Step Production Runbook

This document provides a comprehensive, end-to-end runbook for deploying the **3-Tier Memory & Knowledge Hub** system into production. It details every step from local git commits and merging into `main`, through the GitHub Actions CI/CD pipeline, to exact manual interventions and verification steps on the production server (Dell OptiPlex host).

---

## 📋 Table of Contents
1. [Architecture & Pre-Flight Checklist](#1-architecture--pre-flight-checklist)
2. [Step 1: Committing and Merging to Main](#2-step-1-committing-and-merging-to-main)
3. [Step 2: Automated CI/CD Pipeline Execution](#3-step-2-automated-cicd-pipeline-execution)
4. [Step 3: What Needs Manual Action on the Server](#4-step-3-what-needs-manual-action-on-the-server)
5. [Step 4: Post-Deployment Verification & Smoke Tests](#5-step-4-post-deployment-verification--smoke-tests)
6. [Step 5: Rollback Plan (If Needed)](#6-step-5-rollback-plan-if-needed)

---

## 1. Architecture & Pre-Flight Checklist

The 3-Tier Memory upgrade introduces:
- **Tenant-Global Knowledge Hub**: Knowledge sources (`tenant_knowledge_sources`) owned by tenants, dynamically bound via `agent_knowledge_source` pivot table.
- **Python Sidecar Enhancements**: LangGraph state machine (`memory_graph.py`), vector similarity search with `pgvector`, and in-memory document extractors (`pypdf`, `python-docx`).
- **Storage Subsystem**: Laravel storage endpoint for internal container file access (`/api/internal/knowledge-sources/{id}/download`).
- **Async Processing**: Background extraction (`ProcessKnowledgeSourceJob` on `default` queue) and episodic fact summarization (`ExtractEpisodicFactsJob` on `ai-heavy` queue).

### Pre-Flight Verification:
- [x] Python dependencies updated in `services/business-tools-service/requirements.txt` (`langgraph`, `pypdf`, `python-docx`).
- [x] Laravel migrations generated for `tenant_knowledge_sources`, `agent_knowledge_source`, and vector schema update.
- [x] Vue 3 SPA views (`KnowledgeHub.vue`, `AiAgentBuilder.vue`) built and tested.
- [x] `.github/workflows/deploy.yml` updated with `USE_MEMORY_GRAPH=${{ vars.USE_MEMORY_GRAPH || 'true' }}`.

---

## 2. Step 1: Committing and Merging to Main

Follow these git commands from your local workspace to prepare and merge your feature branch into `main`.

### A. Stage and Commit All Changes
```bash
# Check status to ensure all modified and new files are tracked
git status

# Add all changes across api, sidecar, spa, infra, and docs
git add .

# Commit with a descriptive semantic message
git commit -m "feat(memory): 3-tier memory graph, tenant-global knowledge hub, and document ingest pipeline"
```

### B. Push Branch and Merge to Main
```bash
# If working on a feature branch (e.g. feature/3tier-memory):
git push origin feature/3tier-memory

# Switch to main branch and merge
git checkout main
git pull origin main
git merge feature/3tier-memory

# Push to origin main to trigger CI/CD pipeline
git push origin main
```

---

## 3. Step 2: Automated CI/CD Pipeline Execution

Once pushed to `main`, GitHub Actions automatically runs `.github/workflows/deploy.yml`.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ GitHub Actions Cloud Runner (ubuntu-latest)                                 │
│ ├── 1. Build & Push gateway image (contains built Vue SPA + Nginx)          │
│ ├── 2. Build & Push business-agency-saas-api image (PHP 8.2 FPM + Composer) │
│ └── 3. Build & Push business-tools-service image (Python 3.12 + LangGraph)   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Pushes to ghcr.io
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ Self-Hosted Runner (Dell OptiPlex 3040 Micro - serveradmin)                 │
│ ├── 1. Git fetch & hard reset /opt/docker-data/source-code/as_infra to main │
│ ├── 2. Generate infra/.env from GitHub Secrets & Variables                  │
│ ├── 3. Ensure host bind-mount directories exist                             │
│ ├── 4. Pull new GHCR images (`docker compose pull`)                         │
│ ├── 5. Run Database Migrations (`php artisan migrate --force`)              │
│ ├── 6. Optimize Caches (`config:cache`, `route:cache`)                      │
│ ├── 7. Recreate containers (`docker compose up -d --remove-orphans`)        │
│ ├── 8. Restart queue workers (`php artisan queue:restart`)                  │
│ └── 9. Healthcheck verification loop (waits for all containers healthy)     │
└─────────────────────────────────────────────────────────────────────────────┘
```

You can monitor real-time build & deployment progress on GitHub under:
`https://github.com/techcoderlab/as_infra/actions`

---

## 4. Step 3: What Needs Manual Action on the Server

While the CI/CD pipeline handles container deployment and migrations automatically, the following manual checks/actions **MUST** be verified on the OptiPlex server by hand:

### Action 1: Verify / Enable `pgvector` Extension in PostgreSQL
The 3-tier memory uses `pgvector` for vector embeddings. Ensure the extension is enabled in PostgreSQL:

```bash
# SSH into the OptiPlex server
ssh serveradmin@192.168.100.100

# Run SQL command inside the postgres container
docker exec -it as_infra_postgres psql -U ${POSTGRES_AS_USER:-agency_user} -d ${POSTGRES_AS_DB:-agency_saas_db} -c "CREATE EXTENSION IF NOT EXISTS vector;"
```
*(If already enabled, it will safely output `NOTICE: extension "vector" already exists, skipping`)*.

---

### Action 2: Check GitHub Repository Variables & Secrets
Verify in GitHub repository settings (**Settings > Secrets and variables > Actions**) that the following are set:
1. `USE_MEMORY_GRAPH`: Set variable to `true` (or defaults to `true` via workflow).
2. `EMBEDDING_MODEL`: Verify that the model (`BAAI/bge-small-en-v1.5`) is present in the server's environment or sidecar config.
3. `AS_APP_URL` & `AS_FRONTEND_URL`: Correctly pointing to production domains (e.g., `https://api.yourdomain.com`).

---

### Action 3: Verify Host Storage Directory Permissions
The Knowledge Hub allows uploading files (`.pdf`, `.docx`, `.txt`, `.md`). Ensure the host storage path has correct permissions so the PHP-FPM container can write uploads:

```bash
# Verify directory ownership on the host:
sudo mkdir -p /opt/docker-data/app-data/as_infra/laravel/storage/app/public/knowledge-sources
sudo chown -R 1000:1000 /opt/docker-data/app-data/as_infra/laravel/storage
sudo chmod -R 775 /opt/docker-data/app-data/as_infra/laravel/storage
```

---

### Action 4: Verify Queue Workers & Sidecar are Active
Check that the queue worker containers and sidecar are healthy and listening:

```bash
cd /opt/docker-data/source-code/as_infra/infra

# Check status of all containers
docker compose ps

# Inspect logs of the AI queue worker
docker compose logs -f agency-saas-queue-worker-ai

# Inspect logs of the default queue worker
docker compose logs -f agency-saas-queue-worker-default

# Inspect logs of the Python Tools sidecar
docker compose logs -f business-tools-service
```

---

## 5. Step 4: Post-Deployment Verification & Smoke Tests

Perform these 4 smoke tests immediately after deployment to guarantee 100% functionality:

### 🧪 Test 1: Knowledge Hub Document Upload
1. Log in to the SaaS Admin Dashboard (`https://app.yourdomain.com`).
2. Navigate to **Knowledge Hub** from the sidebar.
3. Click **Upload Document** and drop a sample `.pdf` or `.docx` (e.g., a FAQ or pricing sheet).
4. **Expected Result**: Document status changes from `pending` ➔ `processing` ➔ `indexed` within a few seconds.

### 🧪 Test 2: Ingest Job Log Inspection
Check the Laravel queue and Sidecar logs to verify chunking and embedding:
```bash
docker compose logs --tail=50 business-tools-service | grep -E "ingest|embedding|vector"
```
**Expected Output**:
`INFO: Ingesting document ID=X for tenant=Y. Generated Z chunks and vector embeddings.`

### 🧪 Test 3: Agent Dynamic Binding
1. Navigate to **AI Agents > Edit Agent**.
2. Go to the **Knowledge & Memory** section.
3. Check the checkbox for the newly indexed Knowledge Source and click **Save Changes**.
4. **Expected Result**: The source is linked in the `agent_knowledge_source` pivot table.

### 🧪 Test 4: Live Chat with 3-Tier Memory Graph
1. Open the Chat Interface for the configured agent.
2. Ask a specific question present only inside the uploaded document.
3. **Expected Result**:
   - The agent answers accurately using the indexed document (Tier 3: Semantic Memory).
   - In subsequent chat messages, the agent remembers the user's name/preferences (Tier 1 & 2: Working & Episodic Memory).

---

## 6. Step 5: Rollback Plan (If Needed)

If an unexpected issue occurs in production, execute this rollback procedure:

### Option A: Disable Memory Graph Feature Flag (Instant Zero-Downtime Fallback)
If the sidecar or graph encounters issues, you can instantly fall back to standard LLM chat without rolling back code:
```bash
# On OptiPlex server:
cd /opt/docker-data/source-code/as_infra/infra

# Update .env
sed -i 's/USE_MEMORY_GRAPH=true/USE_MEMORY_GRAPH=false/g' .env

# Restart Laravel and Worker containers
docker compose up -d business-agency-saas-api agency-saas-queue-worker-default agency-saas-queue-worker-ai
docker compose exec -T business-agency-saas-api php artisan config:cache
docker compose exec -T business-agency-saas-api php artisan queue:restart
```

### Option B: Rollback to Previous Docker Image Tag
```bash
# Rollback git commit
git checkout HEAD~1

# Pull previous image tag or re-trigger previous CI run in GitHub Actions
```
