# gateway dockerfile

FROM nginx:alpine

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

# Copy our template to a temp location or directly to where we will read it
COPY nginx.conf.template /etc/nginx/nginx.conf.template

EXPOSE 80

# The CMD is handled in docker-compose to run envsubst