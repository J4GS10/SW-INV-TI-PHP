# =========================================================================
# Frontend de PRODUCCIÓN — Vite build + Nginx con security headers
# =========================================================================

# --------- Stage 1: build ---------
FROM node:20-alpine AS builder

WORKDIR /app

# Solo manifest primero para aprovechar caché de capa
COPY package*.json ./
RUN npm ci --no-audit --no-fund

# Resto del código
COPY . .

# Variables Vite (se inyectan al hacer build)
ARG VITE_API_URL=/api/v1
ARG VITE_SESSION_IDLE_TIMEOUT_MINUTES=30
ENV VITE_API_URL=${VITE_API_URL}
ENV VITE_SESSION_IDLE_TIMEOUT_MINUTES=${VITE_SESSION_IDLE_TIMEOUT_MINUTES}

RUN npm run build

# --------- Stage 2: runtime (Nginx) ---------
# >= 1.27.3: `server ... resolve` en upstream (re-resolución DNS del backend).
# Rama estable al día (Trivy, fase 7: la 1.27.5 traía OpenSSL, brotli, libexpat, libxml2 y musl
# con CVE altos y críticos ya corregidos). Actualizar la etiqueta con cada versión estable.
FROM nginx:1.30.6-alpine AS runtime
# Parches de seguridad de Alpine publicados después de la imagen base (paquetes firmados).
RUN apk upgrade --no-cache

# Headers de seguridad, gzip, fallback SPA, proxy al backend
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Static build
COPY --from=builder /app/dist /usr/share/nginx/html

# El proceso maestro de Nginx arranca como root (abre el puerto 80) y los workers corren como
# `nginx`; el despliegue le quita todas las capacidades salvo las que necesita (cap_drop).
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget --quiet --spider --tries=1 http://127.0.0.1/healthz || exit 1
