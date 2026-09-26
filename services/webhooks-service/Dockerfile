# syntax=docker/dockerfile:1

FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# Local development: source is bind-mounted over /app, node_modules stays in the image's volume.
FROM deps AS dev
ENV NODE_ENV=development
COPY . .
RUN chown -R node:node /app
USER node
EXPOSE 3000
CMD ["sh", "-c", "npx prisma generate && npm run start:dev"]

FROM deps AS build
COPY . .
RUN DATABASE_URL=postgresql://build@localhost/build npx prisma generate && npm run build

FROM node:24-alpine AS prod-deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

FROM node:24-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY --from=prod-deps --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/dist ./dist
COPY --chown=node:node package.json ./
USER node
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
  CMD node -e "fetch('http://localhost:3000/health').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"
CMD ["node", "dist/main.js"]
