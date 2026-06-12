import uvicorn
from core.config import settings

if __name__ == "__main__":
    # In production, you would run this via Gunicorn + Uvicorn workers
    # Example: gunicorn api.server:app -w 4 -k uvicorn.workers.UvicornWorker
    uvicorn.run("api.server:app", host="0.0.0.0", port=8017, reload=True)