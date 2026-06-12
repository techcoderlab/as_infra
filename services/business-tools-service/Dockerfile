# business-tools-service/Dockerfile

# --- Stage 1: Builder ---
FROM python:3.12-slim as builder

WORKDIR /build

# DEBIAN FIX: Use apt-get instead of apk
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    libvips-dev \
    libtesseract-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir --prefix=/install -r requirements.txt

# --- Stage 2: Runtime ---
FROM python:3.12-slim

WORKDIR /app

# RUNTIME FIX: You MUST install the runtime library for vips in Stage 2 as well
# Otherwise, pyvips won't find the .so files
RUN apt-get update && apt-get install -y --no-install-recommends \
    # libvips42 \
    # tesseract-ocr \
    # tesseract-ocr-eng \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 1. Copy dependencies
COPY --from=builder /install /usr/local

# 2. Copy code
COPY . .

# 3. Create user
RUN useradd -m appuser && chown -R appuser /app
USER appuser

# 4. Set Python Path so imports like "from core.config" work
ENV PYTHONPATH=/app \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

EXPOSE 8017

# 5. THE FIX: Point Gunicorn to "api.server" module, "app" object
CMD ["gunicorn","api.server:app","--workers", "2","--worker-class", "uvicorn.workers.UvicornWorker","--bind", "0.0.0.0:8017","--timeout", "300","--graceful-timeout", "60","--keep-alive", "5","--forwarded-allow-ips", "*"]