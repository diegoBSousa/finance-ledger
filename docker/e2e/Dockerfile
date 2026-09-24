FROM node:24-bookworm-slim@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgtk-3-0 libgbm1 libnotify4 libnss3 libxss1 libasound2 libxtst6 xauth xvfb unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /e2e
ENV CYPRESS_CACHE_FOLDER=/opt/cypress
COPY frontend/package.json frontend/package-lock.json ./
RUN CYPRESS_INSTALL_BINARY=0 npm ci && npx cypress install && npx cypress verify
COPY frontend/ ./
COPY backend/tests/Fixtures/financial_transactions.csv /backend/tests/Fixtures/financial_transactions.csv
CMD ["npm", "run", "test:e2e"]
