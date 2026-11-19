# 1) Build du front Vue  2) nginx sert le front et relaie l'API (php-fpm) et Reverb.
FROM node:22-alpine AS front
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
ARG VITE_REVERB_APP_KEY=fleettrack-key
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY
RUN npm run build

FROM nginx:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=front /app/dist /usr/share/nginx/html
