FROM python:3.12-slim AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PYTHONPATH=/app

WORKDIR /app

RUN groupadd -r toolsvc && useradd -r -g toolsvc -d /app toolsvc

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . /app

# Ensure directory exists and non-root user owns it before USER switch
RUN mkdir -p /app/logs && chown -R toolsvc:toolsvc /app

USER toolsvc

EXPOSE 8017

HEALTHCHECK --interval=10s --timeout=5s --retries=3 --start-period=15s \
  CMD python -c "import urllib.request, sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8017/health').getcode() == 200 else 1)"

ENV WEB_CONCURRENCY=2

CMD ["sh", "-c", "gunicorn api.server:app -k uvicorn.workers.UvicornWorker -b 0.0.0.0:${PORT:-8017} -w ${WEB_CONCURRENCY} --timeout 120 --access-logfile - --error-logfile -"]