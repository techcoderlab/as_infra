###############################################################################
# gateway image — builds the standalone Vue 3 SPA (../spa) and packages it
# with Nginx, which then routes:
#   /       -> the built SPA (static files, this image)
#   /api/   -> business-agency-saas-api via FastCGI
#   /tools/ -> business-tools-service via HTTP (SSE-friendly, for AI chat)
#
# Build context is the REPO ROOT (see infra/docker-compose.yml:
# gateway.build.context: ..), so this Dockerfile can COPY both ./spa and
# ./gateway/* in the same build.
#
# ASSUMPTION (please check this against spa/package.json — this could not
# be verified against your actual repo): a standard Vite-based Vue 3 app
# with `npm run build` emitting to `dist/`. If your build tool or output
# directory differs, only the two lines marked below need to change.
###############################################################################

# ---- Stage 1: build the standalone Vue 3 SPA -------------------------------
FROM node:20-alpine AS spa-build
WORKDIR /app
COPY spa/package.json spa/package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY spa/ ./
RUN npm run build   # <- adjust if spa/package.json's build script differs

# ---- Stage 2: Nginx runtime --------------------------------------------------
FROM nginx:1.27-alpine

RUN rm -f /etc/nginx/conf.d/default.conf

COPY gateway/nginx.conf /etc/nginx/nginx.conf
COPY gateway/nginx.conf.template /etc/nginx/templates/app.conf.template

# ADD THIS LINE: Copy your custom FastCGI config into the container
COPY gateway/fastcgi-laravel.conf /etc/nginx/fastcgi-laravel.conf

# <- adjust this path if your Vite/Vue-CLI config emits somewhere other than dist/
COPY --from=spa-build /app/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
  CMD wget -qO- http://127.0.0.1/healthz || exit 1

# No custom ENTRYPOINT/CMD needed: the official nginx image already ships an
# entrypoint script that auto-renders every *.template file in
# /etc/nginx/templates/ through envsubst (substituting only variables that
# actually exist in the container's environment — nginx's own runtime
# variables like $host/$remote_addr are untouched because they aren't env
# vars) into /etc/nginx/conf.d/ before starting nginx. DOMAIN is set via
# `environment:` in docker-compose.yml.
