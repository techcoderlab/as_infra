Interactive API Documentation

We have generated a standard OpenAPI 3.0 specification and a Swagger UI viewer to provide a "FastAPI-like" experience for the Agency SaaS API.

1. Files

Spec: public/docs/openapi.json

Viewer: public/docs/index.html

2. Accessing the Docs

If you are running the Laravel development server (php artisan serve), you can access the interactive documentation at:

http://localhost:8000/docs/index.html

3. Usage

Open the URL above.

To test protected endpoints (like /leads), you must first Authorize.

Use the Authorize button at the top right and enter your Bearer token (obtained from the /login endpoint).

Format: Bearer <your-token> (depending on how Swagger UI handles it, usually just the token string if type is HTTP Bearer).

This setup allows you to test endpoints directly from the browser.