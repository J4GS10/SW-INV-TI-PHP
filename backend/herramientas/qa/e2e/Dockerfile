# Imagen de las pruebas de punta a punta de la fase 7 (Playwright con Chromium,
# Firefox y WebKit) más las herramientas para verificar documentos: mupdf (PDF a
# imagen), zxing-wasm (decodificar los QR), exceljs y jszip (XLSX/DOCX) y axe.
# Las pruebas se montan en /e2e/pruebas al correr (herramientas/qa/e2e.sh).
# Red con inspección TLS: la CA corporativa entra como secreto de BuildKit y no
# queda en la imagen.
FROM mcr.microsoft.com/playwright:v1.64.1-noble
WORKDIR /e2e
COPY package.json ./
RUN --mount=type=secret,id=ca_corporativa,required=false \
    if [ -f /run/secrets/ca_corporativa ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/ca_corporativa; fi; \
    npm install --no-audit --no-fund && npm cache clean --force && chown pwuser /e2e
USER pwuser
