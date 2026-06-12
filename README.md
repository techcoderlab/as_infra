<!--
# ─────────────────────────────────────────────────────
# Module   : README Documentation
# Layer    : Documentation
# Pillar   : P8 Code Quality (Documentation as Code)
# Complexity: N/A
# ─────────────────────────────────────────────────────
-->

# Agency SaaS Microservices System

Welcome to the Agency SaaS Microservices repository. This robust, modern backend architecture is designed to handle multi-tenant business administration, workflow automation, and AI agent offloading. 

## High-Level Architecture

At its core, this system consists of multiple microservices interacting seamlessly to ensure speed, resilience, and horizontal scalability.

![System Architecture Flow Diagram](<!-- TODO: Insert High-Level Architecture Flow Diagram Here -->)

### Core Components

1. **Business Agency SaaS API (Laravel PHP)**  
   The primary entry point for all business logic, tenant management, data persistence, and API routing. It securely orchestrates data flows and triggers AI workflows.
   
2. **Business Tools Service (Python FastAPI Sidecar)**  
   A dedicated Python-based sidecar optimized for heavy computational tasks, AI logic, and agentic workflows. By offloading these tasks to the sidecar, the primary Laravel API remains ultra-fast and responsive.

3. **Frontend SPA (React/Vue/Etc.)**  
   The client-facing application interacting with our API. It provides the user interface for lead management, agent configuration, and analytics while remaining completely decoupled from the backend logic.

---

## Backend & Sidecar Interaction

The **Business Agency SaaS API** acts as the central brain of the application, handling synchronous web traffic and data storage. However, intensive operations (like AI prompt execution, embeddings, and complex data parsing) are safely decoupled and forwarded to the **Business Tools Service** (Python Sidecar).

### Asynchronous Processing: Jobs, Queues & Redis Workers

To prevent slow external or heavy computational API calls from blocking the main web threads, we utilize an advanced queueing system.

**Push / Pull Architecture:**
- **Push:** When the Laravel API encounters a heavy task (e.g., parsing a document or sending a prompt to an LLM), it **pushes** a serialized Job onto a Redis Queue. The web request is then immediately completed and returned to the user.
- **Pull:** Dedicated CLI processes (Laravel Horizon/Queue Workers) constantly monitor the Redis queue. When a job arrives, an available worker **pulls** the job from the queue and executes it in the background.

We use segregated worker queues to ensure critical business logic isn't delayed by slow AI tasks:
- **`agency-saas-worker-default`**: Handles fast, priority business logic (e.g., sending emails, database updates).
- **`agency-saas-worker-ai`**: Specifically tuned with higher memory, longer timeouts, and fewer concurrent threads to handle massive, slow LLM operations without crashing the system.

---

## Modules Deep-Dive

### Leads Module: Flexible & Industry-Agnostic

The Leads Module is designed with absolute flexibility in mind. Rather than hardcoding fields tailored to a specific niche (like Real Estate or Medical), it leverages dynamic schemas and attributes. 

- **Modular Schemas:** Custom metadata fields allow the system to adapt to *any* service-based industry. Whether capturing a patient's medical history or a home buyer's budget, the Leads database scales horizontally to store dynamic payloads.
- **Pipeline Agnostic:** Statuses and workflows are configurable per tenant, enabling diverse operational pipelines.

![Leads Module UI Screen](<!-- TODO: Insert Leads Module Dashboard / UI Screen Here -->)

### Agent Triggers & Modularity

Agent Triggers are defined as standalone, modular components. Instead of tightly coupled monolithic classes, each trigger is its own discrete strategy.

- **Event-Driven:** Triggers listen to internal domain events (e.g., `LeadCreated`, `StatusUpdated`).
- **Conditionals & Context:** Triggers dynamically compile necessary context and evaluate rules before deciding whether to invoke the Python Sidecar for AI processing.
- **Modularity:** Developers can quickly scaffold new triggers without touching existing core logic. Each trigger is plug-and-play.

![Agent Triggers Flow Diagram](<!-- TODO: Insert Agent Triggers Flow Diagram Here -->)

---

## Decoupled Webhook Integrations

Our system embraces event-driven decoupling. When external events occur or internal tasks complete, the system securely emits and ingests webhooks.
- **Incoming Webhooks:** External services (like n8n or Zapier) can inject data securely into our pipelines.
- **Outgoing Webhooks:** Once background jobs (handled by the Sidecar or Redis Workers) complete, the system fires asynchronous webhooks back to registered subscribers or frontend websockets to update the UI gracefully.

---

## Secure External API Access (Sanctum Tokens)

For external integrations and third-party API access, we issue **Laravel Sanctum-based API Tokens**. 
- Tokens are scoped (with granular abilities/permissions) ensuring the principle of least privilege.
- These stateless tokens allow external services to authenticate securely against our endpoints without relying on cookie-based sessions.
- Rate limiting and usage tracking are natively attached to these tokens, preventing abuse.

---

## Frontend Overview

The frontend Single Page Application (SPA) consumes our robust backend APIs. Its main role is presenting the dynamic data (like the Leads Module and Agent Configurations) seamlessly. Because of our webhook and queue architecture, the frontend frequently leverages optimistic UI updates and polling/websockets to reflect background task completion. It strictly handles presentation and UX, relying on the API for all validations, authorization, and business rules.

---

*This repository is continuously evolving. Further module-specific documentation will be added to the `/docs` folder as microservices scale.*
