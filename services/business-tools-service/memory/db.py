# ─────────────────────────────────────────────────────
# Module   : Memory DB Pool
# Layer    : Infrastructure
# Pillar   : P3 Concurrency, P4 Performance, P6 Resilience
# ─────────────────────────────────────────────────────

import asyncpg
from core.config import settings
from core.logger import mcp_logger

# Process-level singleton — shared across all Gunicorn worker coroutines
_pool: asyncpg.Pool | None = None


async def get_pool() -> asyncpg.Pool:
    """
    Returns the process-level asyncpg connection pool, creating it lazily on first call.

    Returns:
        asyncpg.Pool: A bounded connection pool to the shared Postgres instance.

    Raises:
        asyncpg.PostgresError: If the database is unreachable.
    """
    global _pool
    if _pool is None:
        # P4 Performance: bounded pool prevents connection exhaustion
        # min_size=2 keeps warm connections ready; max_size=5 limits the sidecar's
        # footprint since the primary Postgres consumer is the Laravel API.
        _pool = await asyncpg.create_pool(
            host=settings.DB_HOST,
            port=settings.DB_PORT,
            database=settings.DB_DATABASE,
            user=settings.DB_USERNAME,
            password=settings.DB_PASSWORD,
            min_size=2,
            max_size=5,
            command_timeout=10,  # P6 Resilience: per-query timeout budget
        )
        # Register pgvector codec so asyncpg can serialize/deserialize vector columns
        # TRADE-OFF: P1 Architecture — registering type codecs globally on the pool
        # is a pgvector requirement. This runs once at pool creation, not per-query.
        async with _pool.acquire() as conn:
            await conn.execute("CREATE EXTENSION IF NOT EXISTS vector")
            # Register the vector type for all connections in this pool
            await _register_vector_type(conn)
        mcp_logger.info("[MemoryDB] Connection pool initialized")
    return _pool


async def _register_vector_type(conn: asyncpg.Connection) -> None:
    """
    Registers the pgvector 'vector' type codec with asyncpg so that vector
    columns are returned as Python lists of floats.

    Parameters:
        conn: An active asyncpg connection.
    """
    # Fetch the OID for the 'vector' type from pg_type
    vector_oid = await conn.fetchval(
        "SELECT oid FROM pg_type WHERE typname = 'vector'"
    )
    if vector_oid is None:
        mcp_logger.warning("[MemoryDB] pgvector type 'vector' not found in pg_type — extension may not be installed")
        return

    # Define encoder/decoder for the vector type
    # pgvector text format: '[0.1,0.2,0.3]'
    def _vector_encoder(value):
        """Encode a Python list of floats to pgvector text format."""
        if isinstance(value, str):
            return value
        return "[" + ",".join(str(float(v)) for v in value) + "]"

    def _vector_decoder(value):
        """Decode pgvector text format to a Python list of floats."""
        if isinstance(value, (list, tuple)):
            return list(value)
        # Strip brackets and parse
        return [float(x) for x in value.strip("[]").split(",")]

    await conn.set_type_codec(
        "vector",
        encoder=_vector_encoder,
        decoder=_vector_decoder,
        schema="public",
        format="text",
    )


async def close_pool() -> None:
    """
    Gracefully closes the connection pool during app shutdown.
    Safe to call even if pool was never initialized.
    """
    global _pool
    if _pool is not None:
        await _pool.close()
        _pool = None
        mcp_logger.info("[MemoryDB] Connection pool closed")
