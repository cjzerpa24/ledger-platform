-- CreateTable
CREATE TABLE "subscriptions" (
    "id" UUID NOT NULL,
    "url" TEXT NOT NULL,
    "event_types" TEXT[],
    "secret" TEXT NOT NULL,
    "status" TEXT NOT NULL,
    "description" TEXT,
    "created_at" TIMESTAMPTZ(3) NOT NULL,

    CONSTRAINT "subscriptions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "deliveries" (
    "id" UUID NOT NULL,
    "subscription_id" UUID NOT NULL,
    "event_id" UUID NOT NULL,
    "event_name" TEXT NOT NULL,
    "body" JSONB NOT NULL,
    "status" TEXT NOT NULL,
    "attempt_count" INTEGER NOT NULL,
    "next_attempt_at" TIMESTAMPTZ(3) NOT NULL,
    "last_error" TEXT,
    "created_at" TIMESTAMPTZ(3) NOT NULL,

    CONSTRAINT "deliveries_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "delivery_attempts" (
    "id" UUID NOT NULL,
    "delivery_id" UUID NOT NULL,
    "number" INTEGER NOT NULL,
    "at" TIMESTAMPTZ(3) NOT NULL,
    "status_code" INTEGER,
    "error" TEXT,
    "duration_ms" INTEGER NOT NULL,

    CONSTRAINT "delivery_attempts_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "deliveries_status_next_attempt_at_idx" ON "deliveries"("status", "next_attempt_at");

-- CreateIndex
CREATE INDEX "deliveries_subscription_id_id_idx" ON "deliveries"("subscription_id", "id" DESC);

-- CreateIndex
CREATE UNIQUE INDEX "deliveries_subscription_id_event_id_key" ON "deliveries"("subscription_id", "event_id");

-- CreateIndex
CREATE UNIQUE INDEX "delivery_attempts_delivery_id_number_key" ON "delivery_attempts"("delivery_id", "number");

-- AddForeignKey
ALTER TABLE "deliveries" ADD CONSTRAINT "deliveries_subscription_id_fkey" FOREIGN KEY ("subscription_id") REFERENCES "subscriptions"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "delivery_attempts" ADD CONSTRAINT "delivery_attempts_delivery_id_fkey" FOREIGN KEY ("delivery_id") REFERENCES "deliveries"("id") ON DELETE CASCADE ON UPDATE CASCADE;
