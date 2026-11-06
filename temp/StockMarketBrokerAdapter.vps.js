"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.StockMarketBrokerAdapter = void 0;
const ManagedFuturesRuntime_1 = require("../../exchange/ManagedFuturesRuntime");
const OrderNormalization_1 = require("../../exchange/OrderNormalization");
const CryptoOrderSizing_1 = require("../../exchange/CryptoOrderSizing");
const FundingCostUtils_1 = require("../../lib/FundingCostUtils");
class StockMarketBrokerAdapter {
    constructor(opts) {
        this.opts = opts;
        this.price = 0;
        this.price = Number(opts.currentPrice || 0);
        this.ensureStateCollections();
        this.opts.runtimeState.quantityRules = this.quantityRules();
    }
    setPreco(close) {
        this.price = Number(close || 0);
        this.updateRiskState();
    }
    positions() {
        const longs = this.longPositions().map((position) => this.toBrokerPosition(position, 'buy'));
        const shorts = this.shortPositions().map((position) => this.toBrokerPosition(position, 'sell'));
        return [...longs, ...shorts];
    }
    syncRealPositions(marginPositions) {
        if (!this.opts.isReal || !Array.isArray(marginPositions) || marginPositions.length === 0)
            return 0;
        const normalized = marginPositions.map((pos, index) => {
            const rawSide = String(pos?.side ?? pos?.positionSide ?? '').toLowerCase();
            const side = rawSide === 'short' || rawSide === 'sell' ? 'short' : 'long';
            const quantity = Number(pos?.quantity ?? pos?.size ?? pos?.positionAmt ?? 0);
            const entryPrice = Number(pos?.entryPrice ?? pos?.openPrice ?? pos?.avgPrice ?? 0);
            if (!(quantity > 0 && entryPrice > 0)) return null;
            const leverage = Math.max(1, Number(pos?.leverage ?? this.opts.config?.leverage ?? 1));
            const notional = Number(pos?.entryNotional ?? pos?.notional ?? (entryPrice * quantity));
            const initialMargin = Number(pos?.initialMargin ?? pos?.margin ?? (notional / leverage));
            const positionId = String(pos?.positionId ?? pos?.id ?? `API_${side}_${index}`);
            const existing = this.findStatePosition(positionId)?.position;
            return {
                ...(existing || {}), positionId, entryPrice, price: entryPrice, quantity,
                entryNotional: notional, initialMargin, leverage, side,
                openTime: Number(pos?.openTime ?? pos?.timestamp ?? existing?.openTime ?? Date.now()),
                feeOpen: Number(pos?.feeOpen ?? existing?.feeOpen ?? notional * this.feeRateForMode('maker')),
                slippageOpen: Number(pos?.slippageOpen ?? existing?.slippageOpen ?? 0),
                stockMarketTag: String(existing?.stockMarketTag ?? 'stockmarket'),
                openExecutionMode: String(existing?.openExecutionMode ?? this.openExecutionMode()),
                closeExecutionMode: 'taker', stockMarketOrderId: existing?.stockMarketOrderId ?? pos?.orderId,
                requestedQuantity: Number(existing?.requestedQuantity ?? quantity), quantityUnit: 'base_asset',
            };
        }).filter(Boolean);
        if (normalized.length === 0) return 0;
        this.opts.state.activePositions = normalized.filter((position) => position.side === 'long');
        this.opts.state.shortPositions = normalized.filter((position) => position.side === 'short');
        this.opts.state.status = 'holding';
        this.updateRiskState();
        this.opts.logger?.info?.(`[StockMarket] ${normalized.length} posicao(oes) real(is) sincronizada(s) antes do fechamento.`);
        return normalized.length;
    }
    history() {
        return Array.isArray(this.opts.runtimeState.history)
            ? [...this.opts.runtimeState.history]
            : [];
    }
    positionNet(id) {
        const located = this.findStatePosition(id);
        return located ? this.unrealizedNet(located.side, located.position) : null;
    }
    positionDiagnostics(engineState) {
        const now = Date.now();
        return this.rawStatePositions().map(({ side, position }) => {
            const tag = String(position.stockMarketTag || position.tag || 'StockMarket');
            const id = String(position.positionId || position.id || '');
            const entrySide = side === 'long' ? 'buy' : 'sell';
            const stopRaw = tag === 'turtle'
                ? engineState?.turtleSl
                : (tag === 'fimathe' ? engineState?.fimatheSl : undefined);
            const stop = Number(stopRaw);
            const stopPrice = Number.isFinite(stop) && stop > 0 ? stop : null;
            const profitLockRaw = tag === 'turtle'
                ? engineState?.turtleProfitLock
                : (tag === 'fimathe' ? engineState?.fimatheProfitLock : undefined);
            const profitLock = profitLockRaw && typeof profitLockRaw === 'object'
                && String(profitLockRaw.positionId || '') === id
                ? profitLockRaw
                : null;
            const peakNet = Number(profitLock?.peakNetUsd);
            const lockNet = Number(profitLock?.lockNetUsd);
            const peakAt = Number(profitLock?.peakAt);
            const openTime = Number(position.openTime || position.createdAt || now);
            return {
                id,
                tag,
                side,
                quantity: this.statePositionQuantity(position),
                entryPrice: Number(position.entryPrice ?? position.price ?? 0),
                currentExitPrice: this.exitPrice(entrySide),
                stopPrice,
                distanceToStop: stopPrice === null ? null : Math.abs(this.price - stopPrice),
                unrealizedNet: this.unrealizedNet(side, position),
                profitPeakUsd: Number.isFinite(peakNet) ? peakNet : null,
                profitLockUsd: Number.isFinite(lockNet) ? lockNet : null,
                profitLockStagnationMinutes: Number.isFinite(peakAt) && peakAt > 0
                    ? Math.max(0, (now - peakAt) / 60000)
                    : null,
                ageMinutes: Math.max(0, (now - openTime) / 60000),
            };
        });
    }
    getQuantityRules() {
        return this.quantityRules();
    }
    pendingOrders() {
        if (!Array.isArray(this.opts.runtimeState.pendingOrders)) {
            const persisted = Array.isArray(this.opts.state?._institutionalPendingOpenOrders)
                ? this.opts.state._institutionalPendingOpenOrders.filter((order) => String(order?.module || "").toLowerCase() === "stockmarket")
                : [];
            this.opts.runtimeState.pendingOrders = persisted;
        }
        return this.opts.runtimeState.pendingOrders;
    }
    syncPendingOrders() {
        this.opts.state._institutionalPendingOpenOrders = this.pendingOrders().map((order) => ({ ...order, module: "stockmarket" }));
    }
    async confirmOrderFill(orderId, requestedQuantity) {
        if (!this.opts.isReal || typeof this.opts.client?.Order?.orderInfo !== "function") return { filled: true, status: "FILLED" };
        try {
            const response = await this.opts.client.Order.orderInfo({ symbol: this.opts.config?.market, orderId });
            const data = response?.data?.data ?? response?.data ?? response;
            const status = String(data?.status ?? data?.orderStatus ?? data?.state ?? data?.orderState ?? "").toUpperCase();
            const filledQty = Number(data?.executedQty ?? data?.filledQty ?? data?.cumExecQty ?? data?.cumQty ?? data?.volume ?? 0);
            const requested = Number(requestedQuantity || 0);
            const filled = ["FILLED", "FULLY_FILLED", "TRADED", "SUCCESS", "COMPLETED"].includes(status) || (requested > 0 && filledQty >= requested * 0.999);
            const terminal = filled || ["CANCELED", "CANCELLED", "REJECTED", "EXPIRED", "FAILED"].includes(status);
            return { filled, terminal, status: status || "UNKNOWN", filledQty };
        } catch (error) {
            this.opts.logger?.warn?.("[StockMarket] Falha ao confirmar fill " + orderId + ": " + (error?.message || error));
            return { filled: false, terminal: false, status: "UNKNOWN" };
        }
    }
    async reconcilePendingOrders() {
        const pending = this.pendingOrders();
        const pendingTtlMs = Math.max(60000, Number(this.opts.config?.pendingOrderTtlMs ?? 10 * 60 * 1000));
        let exchangePendingIds = null;
        if (this.opts.isReal && typeof this.opts.client?.Order?.ordersPending === "function") {
            try {
                const response = await this.opts.client.Order.ordersPending({ symbol: this.opts.config?.market });
                const payload = response?.data ?? response;
                const rows = Array.isArray(payload) ? payload : (Array.isArray(payload?.list) ? payload.list : []);
                exchangePendingIds = new Set(rows.map((row) => String(row?.orderId ?? row?.id ?? row?.orderID ?? "")).filter(Boolean));
            } catch (error) {
                this.opts.logger?.warn?.("[StockMarket] Falha ao consultar ordens abertas para reconciliacao: " + (error?.message || error));
            }
        }
        for (let i = pending.length - 1; i >= 0; i--) {
            const order = pending[i];
            if (exchangePendingIds && !exchangePendingIds.has(String(order.orderId)) && Date.now() - Number(order.openTime || 0) >= pendingTtlMs) {
                pending.splice(i, 1);
                this.syncPendingOrders();
                this.recordOperation({ side: order.side === "long" ? "buy" : "sell", price: order.entry, quantity: order.quantity, orderId: order.orderId, profit: null, phase: "open", reason: order.tag, positionId: order.positionId, fee: 0, executionMode: order.executionMode, status: "canceled", context: { pending: true, finalOrderStatus: "NOT_IN_EXCHANGE_OPEN_ORDERS", ttlMs: pendingTtlMs } });
                this.opts.logger?.info?.("[StockMarket] Ordem pendente removida: nao consta nas ordens abertas da exchange " + order.orderId);
                continue;
            }
            const confirmation = await this.confirmOrderFill(order.orderId, order.quantity);
            if (confirmation.filled) {
                pending.splice(i, 1);
                this.syncPendingOrders();
                await this.registerOpenedPosition({ ...order, fillPrice: Number(confirmation.avgPrice || order.entry), fillQuantity: Number(confirmation.filledQty || order.quantity) });
            } else if (confirmation.terminal) {
                pending.splice(i, 1);
                this.syncPendingOrders();
                this.recordOperation({ side: order.side === "long" ? "buy" : "sell", price: order.entry, quantity: order.quantity, orderId: order.orderId, profit: null, phase: "open", reason: order.tag, positionId: order.positionId, fee: 0, executionMode: order.executionMode, status: confirmation.status.toLowerCase(), context: { pending: true, finalOrderStatus: confirmation.status } });
            } else if (Date.now() - Number(order.openTime || 0) >= pendingTtlMs) {
                try {
                    if (this.opts.client?.Order && typeof this.opts.client.Order.cancelOrder === "function") {
                        await this.opts.client.Order.cancelOrder({ symbol: this.opts.config?.market, orderId: order.orderId });
                    }
                    pending.splice(i, 1);
                    this.syncPendingOrders();
                    this.recordOperation({ side: order.side === "long" ? "buy" : "sell", price: order.entry, quantity: order.quantity, orderId: order.orderId, profit: null, phase: "open", reason: order.tag, positionId: order.positionId, fee: 0, executionMode: order.executionMode, status: "canceled", context: { pending: true, finalOrderStatus: "EXPIRED_LOCAL_TTL", ttlMs: pendingTtlMs } });
                    this.opts.logger?.warn?.("[StockMarket] Ordem pendente expirada e cancelada: " + order.orderId);
                } catch (error) {
                    this.opts.logger?.warn?.("[StockMarket] Falha ao expirar ordem pendente " + order.orderId + ": " + (error?.message || error));
                }
            }
        }
    }
    async registerOpenedPosition(order) {
        const entry = Number(order.fillPrice || order.entry);
        const quantity = Number(order.fillQuantity || order.quantity);
        const risk = order.risk || this.buildOpenRisk(entry, quantity);
        const positionId = String(order.positionId || (order.tag + "_" + order.orderId));
        const position = { positionId, entryPrice: entry, price: entry, quantity, entryNotional: risk.notional, initialMargin: risk.initialMargin, borrowedAmount: risk.borrowedAmount, leveragedExposureUsd: risk.leveragedExposureUsd, feeOpen: risk.feeOpen, slippageOpen: risk.slippageOpen, liquidationPrice: this.estimateLiquidationPrice(order.side, entry, quantity, risk.initialMargin, risk.notional), openTime: Number(order.openTime || Date.now()), side: order.positionSide, leverage: risk.leverage, openExecutionMode: order.executionMode || this.openExecutionMode(), closeExecutionMode: this.closeExecutionMode(), dailyInterestRate: (0, FundingCostUtils_1.resolveDailyInterestRateForExecution)(this.opts.config), fundingRatePer8h: this.fundingRatePer8h(), fundingIntervalHours: this.fundingIntervalHours(), fundingFeeMode: this.fundingFeeMode(), maintenanceMarginRate: this.maintenanceMarginRate(), stockMarketTag: order.tag, requestedQuantity: Number(order.requestedQuantity || quantity), quantityUnit: "base_asset", stockMarketOrderId: order.orderId };
        if (order.positionSide === "long") { this.longPositions().push(position); this.opts.state.lastBuyPrice = entry; } else { this.shortPositions().push(position); this.opts.state.lastSellPrice = entry; }
        this.opts.state.status = "holding";
        this.opts.runtimeState.lastAction = "open_" + order.tag + "_" + order.side;
        this.opts.runtimeState.lastOrderId = order.orderId;
        this.recordOperation({ side: order.side === "long" ? "buy" : "sell", price: entry, quantity, orderId: order.orderId, profit: null, phase: "open", reason: order.tag, positionId, fee: risk.feeOpen, executionMode: order.executionMode || this.openExecutionMode(), status: "success", context: { notional: risk.notional, initialMargin: risk.initialMargin, leveragedExposureUsd: risk.leveragedExposureUsd, pendingConfirmed: true, quantityUnit: "base_asset" } });
        this.updateRiskState();
        return { id: positionId, tag: order.tag, side: order.side === "long" ? "buy" : "sell", entry, openTime: position.openTime, quantity, entryNotional: risk.notional, initialMargin: risk.initialMargin, leverage: risk.leverage, feeOpen: risk.feeOpen, liquidationPrice: position.liquidationPrice, openExecutionMode: position.openExecutionMode, closeExecutionMode: position.closeExecutionMode };
    }
    async marketOrder(tag, side, requestedQuantity) {
        this.ensureStateCollections();
        await this.reconcilePendingOrders();
        if (this.pendingOrders().some((order) => String(order.tag) === String(tag))) {
            this.opts.runtimeState.lastAction = "skip_open_" + tag + "_pending_fill";
            return null;
        }
        if (!(this.price > 0)) {
            this.recordError('Preco atual indisponivel para abertura StockMarket');
            return null;
        }
        const maxOpen = Math.max(1, Number(this.opts.stockMarketConfig.maxOpenPositions || 2));
        if (this.positions().length >= maxOpen) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_max_open_positions`;
            return null;
        }
        if (this.positions().some((position) => position.tag === tag)) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_already_open`;
            return null;
        }
        if (!this.opts.stockMarketConfig.allowOppositeEnginePositions
            && this.positions().some((position) => position.side !== side)) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_opposite_engine_conflict`;
            return null;
        }
        const normalized = this.normalizeOpeningQuantity(requestedQuantity);
        const quantity = normalized.quantity;
        if (!normalized.accepted || !(quantity > 0)) {
            this.recordError(`Quantidade Crypto rejeitada para abertura StockMarket tag=${tag} ` +
                `requested=${formatSizingNumber(requestedQuantity)} reason=${normalized.reason || 'invalid_quantity'} ` +
                `notional=${formatSizingNumber(normalized.notionalUsd)} minNotional=${formatSizingNumber(normalized.effectiveMinNotionalUsd)}`);
            return null;
        }
        const entry = this.entryPrice(side);
        const risk = this.buildOpenRisk(entry, quantity);
        if (!this.canOpenWithMargin(risk.initialMargin, risk.feeOpen + risk.slippageOpen)) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_margin_cap`;
            return null;
        }
        const positionSide = side === 'buy' ? 'long' : 'short';
        const orderId = await this.placeExchangeOrder({
            phase: 'open',
            positionSide,
            quantity,
        });
        if (!orderId)
            return null;
        const positionId = `${tag}_${orderId}`;
        const confirmation = await this.confirmOrderFill(orderId, quantity);
        const orderRecord = { tag, side: positionSide, positionSide, quantity, requestedQuantity: normalized.requestedQuantity, entry, risk, orderId, positionId, executionMode: this.openExecutionMode(), openTime: Date.now() };
        if (!confirmation.filled) {
            this.pendingOrders().push(orderRecord);
            this.syncPendingOrders();
            this.opts.runtimeState.lastAction = "pending_" + tag + "_" + side;
            this.recordOperation({ side, price: entry, quantity, orderId, profit: null, phase: "open", reason: tag, positionId, fee: risk.feeOpen, executionMode: this.openExecutionMode(), status: "pending", context: { pending: true, requestedQuantity: normalized.requestedQuantity, normalizedQuantity: quantity, quantityUnit: "base_asset", pendingStatus: confirmation.status } });
            this.updateRiskState();
            return null;
        }
        return this.registerOpenedPosition({ ...orderRecord, fillPrice: Number(confirmation.avgPrice || entry), fillQuantity: Number(confirmation.filledQty || quantity) });
    }
    async closePosition(id, closeReason) {
        this.ensureStateCollections();
        const located = this.findStatePosition(id);
        if (!located)
            return null;
        if (!(this.price > 0)) {
            this.recordError('Preco atual indisponivel para fechamento StockMarket');
            return null;
        }
        const side = located.side === 'long' ? 'buy' : 'sell';
        const closeSide = located.side === 'long' ? 'sell' : 'buy';
        const storedQuantity = this.statePositionQuantity(located.position);
        const normalizedClose = (0, CryptoOrderSizing_1.normalizeCryptoOrderQuantity)({
            requestedQuantity: storedQuantity,
            price: this.price,
            rules: this.quantityRules(),
            reduceOnly: true,
            totalPositionQty: storedQuantity,
            orderType: this.closeExecutionMode() === 'maker' ? 'limit' : 'market',
        });
        const quantity = normalizedClose.quantity;
        if (!normalizedClose.accepted || !(quantity > 0)) {
            this.recordError(`Quantidade Crypto rejeitada para fechamento StockMarket id=${id} ` +
                `requested=${formatSizingNumber(storedQuantity)} reason=${normalizedClose.reason || 'invalid_quantity'}`);
            return null;
        }
        const orderId = await this.placeExchangeOrder({
            phase: 'close',
            positionSide: located.side,
            quantity,
        });
        if (!orderId)
            return null;
        const entry = Number(located.position.entryPrice ?? located.position.price ?? 0);
        const exit = this.exitPrice(side);
        const closeNet = this.computeCloseNet({
            side,
            entry,
            exit,
            quantity,
            position: located.position,
            closeReason: closeReason || String(located.position.closeReason || 'signal'),
        });
        const deal = {
            tag: String(located.position.stockMarketTag || 'StockMarket'),
            side,
            entry,
            exit,
            quantity,
            gross: closeNet.gross,
            net: closeNet.net,
            profit: closeNet.net,
            feeOpen: closeNet.feeOpen,
            feeClose: closeNet.feeClose,
            feeTotal: closeNet.feeTotal,
            slippageTotal: closeNet.slippageTotal,
            interest: closeNet.interest,
            funding: closeNet.funding,
            initialMargin: Number(located.position.initialMargin || 0),
            borrowedAmount: Number(located.position.borrowedAmount || 0),
            leveragedExposureUsd: Number(located.position.leveragedExposureUsd || 0),
            leverage: Number(located.position.leverage || this.opts.config?.leverage || 1),
            closeReason: closeNet.closeReason,
            liquidationPenalty: closeNet.liquidationPenalty,
            openExecutionMode: String(located.position.openExecutionMode || this.openExecutionMode()),
            closeExecutionMode: String(located.position.closeExecutionMode || this.closeExecutionMode()),
            openTime: Number(located.position.openTime || Date.now()),
            closeTime: Date.now(),
        };
        this.removeLocatedPosition(located);
        this.pushHistory(deal);
        this.opts.state.totalProfit = Number(this.opts.state.totalProfit || 0) + closeNet.net;
        if (this.positions().length === 0)
            this.opts.state.status = 'waiting';
        this.opts.runtimeState.lastAction = `close_${deal.tag}_${closeSide}`;
        this.opts.runtimeState.lastOrderId = orderId;
        this.recordOperation({
            side: closeSide,
            price: exit,
            quantity,
            orderId,
            profit: closeNet.net,
            phase: 'close',
            reason: closeNet.closeReason || deal.tag,
            positionId: id,
            fee: closeNet.feeTotal,
            executionMode: this.closeExecutionMode(),
            context: {
                gross: closeNet.gross,
                net: closeNet.net,
                feeOpen: closeNet.feeOpen,
                feeClose: closeNet.feeClose,
                slippageTotal: closeNet.slippageTotal,
                interest: closeNet.interest,
                funding: closeNet.funding,
                liquidationPenalty: closeNet.liquidationPenalty,
                initialMargin: deal.initialMargin,
                borrowedAmount: deal.borrowedAmount,
                openExecutionMode: deal.openExecutionMode,
                closeExecutionMode: deal.closeExecutionMode,
                strategyTag: deal.tag,
                closeReason: closeNet.closeReason,
                quantityUnit: 'base_asset',
                requestedQuantity: storedQuantity,
                normalizedQuantity: quantity,
                fullReduction: normalizedClose.isFullReduction,
            },
        });
        this.updateRiskState();
        return deal;
    }
    equity(initialEquity) {
        const realized = this.history().reduce((sum, deal) => sum + Number(deal.profit || 0), 0);
        const floating = this.rawStatePositions().reduce((sum, item) => sum + this.unrealizedNet(item.side, item.position), 0);
        return Number(initialEquity || 0) + realized + floating;
    }
    async closeLiquidationRiskPositions() {
        const candidates = this.rawStatePositions()
            .filter((item) => this.shouldLiquidate(item.side, item.position))
            .map((item) => String(item.position.positionId || item.position.id || ''))
            .filter(Boolean);
        let closed = 0;
        for (const id of candidates) {
            const deal = await this.closePosition(id, 'liquidation');
            if (deal)
                closed++;
        }
        if (closed > 0) {
            this.opts.runtimeState.liquidationCount = Number(this.opts.runtimeState.liquidationCount || 0) + closed;
            this.opts.runtimeState.lastAction = `liquidation_guard_closed_${closed}`;
        }
        return closed;
    }
    async placeExchangeOrder(params) {
        if (!this.opts.isReal) {
            return this.nextDemoOrderId(params.phase);
        }
        const client = this.opts.client;
        if (!client?.Order || typeof client.Order.placeOrder !== 'function') {
            this.recordError('Cliente da exchange nao suporta Order.placeOrder para StockMarket');
            return null;
        }
        try {
            const executionMode = params.phase === 'open' ? this.openExecutionMode() : this.closeExecutionMode();
            const useMakerExecution = executionMode === 'maker';
            const useMarketExecution = executionMode === 'market' || executionMode === 'taker';
            const orderPrice = useMakerExecution
                ? this.passiveLimitPrice(params.positionSide)
                : this.price;
            const payload = (0, ManagedFuturesRuntime_1.isManagedFuturesExchange)(this.opts.config?.exchange)
                ? (0, ManagedFuturesRuntime_1.buildManagedFutureOrderPayload)({
                    exchange: this.opts.config?.exchange,
                    config: this.opts.config,
                    action: params.positionSide === 'long' ? 'buy' : 'sell',
                    isOpeningLong: params.phase === 'open' && params.positionSide === 'long',
                    isOpeningShort: params.phase === 'open' && params.positionSide === 'short',
                    isClosingLong: params.phase === 'close' && params.positionSide === 'long',
                    isClosingShort: params.phase === 'close' && params.positionSide === 'short',
                    price: orderPrice,
                    quantity: params.quantity,
                    quantityPrecision: this.quantityPrecision(),
                    pricePrecision: this.pricePrecision(),
                    symbolInfo: this.opts.symbolInfo || {},
                    useMarketExecution,
                    useMakerExecution,
                    runtimeMakerPostOnly: this.runtimeMakerPostOnly(),
                    logger: { warn: (msg) => this.opts.logger?.warn?.(msg) },
                    resolveProductType: ManagedFuturesRuntime_1.resolveManagedFuturesProductType,
                    resolveMarginMode: ManagedFuturesRuntime_1.resolveManagedFuturesMarginMode,
                })
                : {
                    symbol: this.opts.config?.market,
                    side: params.positionSide === 'long' ? 'buy' : 'sell',
                    orderType: useMakerExecution ? 'limit' : 'market',
                    ...(useMakerExecution ? { price: orderPrice.toFixed(this.pricePrecision()), force: 'post_only' } : {}),
                    baseSize: params.quantity.toFixed(this.quantityPrecision()),
                    ...(!(0, FundingCostUtils_1.isFuturePerpetualExecution)(this.opts.config) ? { loanType: 'autoLoan' } : {}),
                };
            const response = await client.Order.placeOrder(payload);
            const data = await this.unwrapResponsePayload(response);
            const orderId = (0, OrderNormalization_1.extractOrderId)(data?.data ?? data);
            if (!orderId) {
                const auditOrderId = 'LOCAL_AUDIT_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
                this.recordError('Exchange aceitou/respondeu ordem StockMarket sem orderId; usando identificador local de auditoria: ' + auditOrderId);
                return auditOrderId;
            }
            return orderId;
        }
        catch (error) {
            const msg = error?.response?.data?.msg ?? error?.data?.msg ?? error?.message ?? String(error);
            this.recordError(`Falha ao enviar ordem StockMarket: ${msg}`);
            return null;
        }
    }
    recordOperation(input) {
        if (!this.opts.batchOps || typeof this.opts.batchOps.addOperation !== 'function')
            return;
        this.opts.batchOps.addOperation({
            bot_id: Number(this.opts.config?.botId || 0),
            symbol: String(this.opts.config?.market || ''),
            side: input.side,
            price: input.price,
            size: input.quantity,
            amount_usdt: input.price * input.quantity,
            amount_op: input.price * input.quantity,
            fee: input.fee ?? 0,
            profit: input.profit,
            order_id: input.orderId,
            trade_type: 'StockMarket',
            op_type: this.opts.isReal ? 'real' : 'demo',
            status: input.status || 'success',
            execution_type: this.opts.isReal ? 'real' : 'simulated',
            is_demo: !this.opts.isReal,
            leverage: Number(this.opts.config?.leverage || 1),
            margin_mode: this.opts.config?.marginMode ?? null,
            operation_phase: input.phase,
            operation_reason: input.reason,
            execution_mode: input.executionMode || 'market',
            position_id: input.positionId,
            operation_context: {
                module: 'StockMarket',
                source: 'stockmarket_tradfi',
                bankroll: this.bankroll(),
                sizingMode: this.opts.stockMarketConfig.sizingMode,
                turtleMarginUsagePct: this.opts.stockMarketConfig.turtleMarginUsagePct,
                fimatheMarginUsagePct: this.opts.stockMarketConfig.fimatheMarginUsagePct,
                turtleRiskPerTradePct: this.opts.stockMarketConfig.turtleRiskPerTradePct,
                fimatheRiskPerTradePct: this.opts.stockMarketConfig.fimatheRiskPerTradePct,
                allowOppositeEnginePositions: this.opts.stockMarketConfig.allowOppositeEnginePositions,
                leverage: Number(this.opts.config?.leverage || this.opts.stockMarketConfig.leverage || 1),
                openExecutionMode: this.openExecutionMode(),
                closeExecutionMode: this.closeExecutionMode(),
                runtimeMakerPostOnly: this.runtimeMakerPostOnly(),
                ...(input.context || {}),
            },
        });
    }
    toBrokerPosition(position, side) {
        const quantity = this.statePositionQuantity(position);
        return {
            id: String(position.positionId || position.id || ''),
            tag: String(position.stockMarketTag || position.tag || 'StockMarket'),
            side,
            entry: Number(position.entryPrice ?? position.price ?? 0),
            openTime: Number(position.openTime || position.createdAt || Date.now()),
            quantity,
            entryNotional: Number(position.entryNotional || 0),
            initialMargin: Number(position.initialMargin || 0),
            borrowedAmount: Number(position.borrowedAmount || 0),
            leverage: Number(position.leverage || this.opts.config?.leverage || 1),
            feeOpen: Number(position.feeOpen || 0),
            slippageOpen: Number(position.slippageOpen || 0),
            liquidationPrice: Number(position.liquidationPrice || 0) || null,
            openExecutionMode: String(position.openExecutionMode || this.openExecutionMode()),
            closeExecutionMode: String(position.closeExecutionMode || this.closeExecutionMode()),
        };
    }
    entryPrice(side) {
        return side === 'buy' ? this.bestAsk() : this.bestBid();
    }
    exitPrice(entrySide) {
        return entrySide === 'buy' ? this.bestBid() : this.bestAsk();
    }
    normalizeOpeningQuantity(requestedQuantity) {
        return (0, CryptoOrderSizing_1.normalizeCryptoOrderQuantity)({
            requestedQuantity,
            price: this.price,
            rules: this.quantityRules(),
            reduceOnly: false,
            orderType: this.openExecutionMode() === 'maker' ? 'limit' : 'market',
        });
    }
    quantityRules() {
        if (this.resolvedQuantityRules)
            return this.resolvedQuantityRules;
        const runtimeConfig = this.opts.config || {};
        const fallbackConfig = {
            quantityStep: firstPositiveNumber(runtimeConfig.quantityStep, this.opts.stockMarketConfig.quantityStep),
            minOrderQty: firstPositiveNumber(runtimeConfig.minOrderQty, this.opts.stockMarketConfig.minOrderQty),
            minNotional: firstPositiveNumber(runtimeConfig.minNotional, runtimeConfig.minNotionalUsd, this.opts.stockMarketConfig.minNotional) ?? 0,
            quantityPrecision: firstNonNegativeNumber(runtimeConfig.quantityPrecision),
            pricePrecision: firstNonNegativeNumber(runtimeConfig.pricePrecision),
            priceStep: firstPositiveNumber(runtimeConfig.priceStep),
            maxOrderQty: firstPositiveNumber(runtimeConfig.maxOrderQty),
            maxMarketOrderQty: firstPositiveNumber(runtimeConfig.maxMarketOrderQty),
        };
        this.resolvedQuantityRules = (0, CryptoOrderSizing_1.resolveCryptoQuantityRules)(this.opts.symbolInfo || {}, fallbackConfig);
        return this.resolvedQuantityRules;
    }
    statePositionQuantity(position) {
        const direct = Number(position?.quantity);
        if (Number.isFinite(direct) && direct > 0)
            return direct;
        // Read-only migration boundary for positions persisted by the former Forex-named model.
        const legacy = Number(position?.StockMarketLots ?? position?.lots);
        return Number.isFinite(legacy) && legacy > 0 ? legacy : 0;
    }
    quantityPrecision() {
        return this.quantityRules().quantityPrecision;
    }
    pricePrecision() {
        return this.quantityRules().pricePrecision;
    }
    passiveLimitPrice(positionSide) {
        const precision = this.pricePrecision();
        const tick = Math.pow(10, -precision);
        const offset = Math.max(tick, this.price * Math.max(0.00002, this.slippageForMode('taker') / 2));
        const price = positionSide === 'long'
            ? Math.max(tick, this.price - offset)
            : this.price + offset;
        return Number(price.toFixed(precision));
    }
    buildOpenRisk(entry, quantity) {
        const leverage = Math.max(1, Number(this.opts.config?.leverage || this.opts.stockMarketConfig.leverage || 1));
        const notional = this.notional(entry, quantity);
        const initialMargin = notional / leverage;
        const leveragedExposureUsd = Math.max(0, notional - initialMargin);
        return {
            leverage,
            notional,
            initialMargin,
            borrowedAmount: (0, FundingCostUtils_1.isFuturePerpetualExecution)(this.opts.config) ? 0 : leveragedExposureUsd,
            leveragedExposureUsd,
            feeOpen: notional * this.feeRateForMode(this.openExecutionMode()),
            slippageOpen: this.slippageModel() === 'two_sided'
                ? notional * this.slippageForMode(this.openExecutionMode())
                : 0,
        };
    }
    canOpenWithMargin(nextInitialMargin, openingCost) {
        const bankroll = this.bankroll();
        if (!(bankroll > 0))
            return true;
        const equity = this.equity(bankroll);
        if (equity < this.opts.stockMarketConfig.minEquityForNewEntries)
            return false;
        const used = this.rawStatePositions().reduce((sum, item) => {
            const fallback = this.notional(Number(item.position.entryPrice || item.position.price || 0), this.statePositionQuantity(item.position))
                / Math.max(1, Number(item.position.leverage || this.opts.config?.leverage || 1));
            return sum + Number(item.position.initialMargin || fallback || 0);
        }, 0);
        const projected = used + nextInitialMargin + openingCost;
        return projected <= bankroll * this.opts.stockMarketConfig.maxInitialMarginUsagePct + 1e-9;
    }
    computeCloseNet(params) {
        const closeNotional = this.notional(params.exit, params.quantity);
        const feeOpen = Number(params.position.feeOpen || 0);
        const closeMode = normalizeExecutionMode(params.position.closeExecutionMode, this.closeExecutionMode());
        const feeClose = closeNotional * this.feeRateForMode(closeMode);
        const slippageOpen = Number(params.position.slippageOpen || 0);
        const slippageClose = closeNotional * this.slippageForMode(closeMode);
        const gross = this.gross(params.side, params.entry, params.exit, params.quantity);
        const interest = this.interest(params.position);
        const funding = this.funding(params.position, params.quantity);
        const closeReason = params.closeReason || 'signal';
        const liquidationPenalty = closeReason === 'liquidation'
            ? closeNotional * this.opts.stockMarketConfig.liquidationPenaltyPct
            : 0;
        const feeTotal = feeOpen + feeClose;
        const slippageTotal = slippageOpen + slippageClose;
        const net = gross - feeTotal - slippageTotal - interest - funding - liquidationPenalty;
        return {
            gross,
            net,
            feeOpen,
            feeClose,
            feeTotal,
            slippageTotal,
            interest,
            funding,
            liquidationPenalty,
            closeReason,
        };
    }
    unrealizedNet(positionSide, position) {
        const side = positionSide === 'long' ? 'buy' : 'sell';
        const entry = Number(position.entryPrice ?? position.price ?? 0);
        const quantity = this.statePositionQuantity(position);
        if (!(entry > 0 && quantity > 0))
            return 0;
        const exit = this.exitPrice(side);
        const closeNotional = this.notional(exit, quantity);
        const closeMode = normalizeExecutionMode(position.closeExecutionMode, this.closeExecutionMode());
        const feeClose = closeNotional * this.feeRateForMode(closeMode);
        const slippageClose = closeNotional * this.slippageForMode(closeMode);
        return this.gross(side, entry, exit, quantity)
            - Number(position.feeOpen || 0)
            - feeClose
            - Number(position.slippageOpen || 0)
            - slippageClose
            - this.interest(position)
            - this.funding(position, quantity);
    }
    shouldLiquidate(positionSide, position) {
        const side = positionSide === 'long' ? 'buy' : 'sell';
        const entry = Number(position.entryPrice ?? position.price ?? 0);
        const quantity = this.statePositionQuantity(position);
        if (!(this.price > 0 && entry > 0 && quantity > 0))
            return false;
        const notional = Number(position.entryNotional || this.notional(entry, quantity));
        const leverage = Math.max(1, Number(position.leverage || this.opts.config?.leverage || 1));
        const initialMargin = Number(position.initialMargin || notional / leverage);
        const maintenance = notional * this.maintenanceMarginRate(position);
        const buffer = notional * this.opts.stockMarketConfig.liquidationBufferPct;
        const lossLimit = Math.max(0, initialMargin - maintenance - buffer);
        const gross = this.gross(side, entry, this.markPrice(), quantity);
        return gross <= -lossLimit;
    }
    updateRiskState() {
        const items = this.rawStatePositions();
        const marginUsed = items.reduce((sum, item) => {
            const position = item.position;
            const entry = Number(position.entryPrice ?? position.price ?? 0);
            const quantity = this.statePositionQuantity(position);
            const leverage = Math.max(1, Number(position.leverage || this.opts.config?.leverage || 1));
            const fallback = this.notional(entry, quantity) / leverage;
            return sum + Number(position.initialMargin || fallback || 0);
        }, 0);
        const borrowedAmount = items.reduce((sum, item) => sum + Number(item.position.borrowedAmount || 0), 0);
        const bankroll = this.bankroll();
        const equity = this.equity(bankroll);
        this.opts.state.borrowedAmount = borrowedAmount;
        this.opts.state.marginUsed = marginUsed;
        this.opts.state.marginUsedPct = bankroll > 0 ? (marginUsed / bankroll) * 100 : 0;
        this.opts.state.marginLevel = marginUsed > 0 ? equity / marginUsed : 0;
        this.opts.state.currentEquity = equity;
    }
    rawStatePositions() {
        return [
            ...this.longPositions().map((position) => ({ side: 'long', position })),
            ...this.shortPositions().map((position) => ({ side: 'short', position })),
        ];
    }
    gross(side, entry, exit, quantity) {
        const priceDelta = side === 'buy' ? exit - entry : entry - exit;
        return priceDelta * quantity;
    }
    notional(price, quantity) {
        return Math.max(0, Number(price || 0) * Number(quantity || 0));
    }
    interest(position) {
        const rate = (0, FundingCostUtils_1.resolveDailyInterestRateForExecution)(this.opts.config, Number(position.dailyInterestRate ?? this.opts.stockMarketConfig.defaultDailyInterestRate));
        const borrowed = Number(position.borrowedAmount || 0);
        if (!(rate > 0 && borrowed > 0))
            return 0;
        const now = Date.now();
        const holdingMs = Math.max(1, now - Number(position.openTime || now));
        const holdingDays = Math.max(holdingMs / (24 * 60 * 60 * 1000), 1 / 1440);
        return borrowed * rate * holdingDays;
    }
    funding(position, quantity) {
        const side = String(position?.side || '').toLowerCase() === 'short' ? 'short' : 'long';
        return (0, FundingCostUtils_1.estimatePerpFundingCost)({
            isPerp: (0, FundingCostUtils_1.isFuturePerpetualExecution)(this.opts.config),
            fundingFeeMode: this.fundingFeeMode(position),
            side,
            entryPrice: Number(position?.entryPrice ?? position?.price ?? 0),
            markPrice: this.markPrice(),
            closeQty: quantity,
            openTime: Number(position?.openTime ?? position?.createdAt ?? Date.now()),
            fundingRatePer8h: this.fundingRatePer8h(position),
            fundingIntervalHours: this.fundingIntervalHours(position),
            asOfTs: Date.now(),
        });
    }
    fundingRatePer8h(position) {
        const candidates = [
            this.opts.symbolInfo?.fundingRatePer8h,
            this.opts.config?.fundingRatePer8h,
            this.opts.config?.defaultFundingRatePer8h,
            position?.fundingRatePer8h,
        ];
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value))
                return value;
        }
        return 0;
    }
    fundingIntervalHours(position) {
        const candidates = [
            this.opts.symbolInfo?.fundingIntervalHours,
            this.opts.config?.fundingIntervalHours,
            position?.fundingIntervalHours,
        ];
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value) && value > 0)
                return Math.max(0.25, value);
        }
        return 8;
    }
    fundingFeeMode(position) {
        const raw = String(position?.fundingFeeMode
            ?? this.opts.config?.fundingFeeMode
            ?? ((0, FundingCostUtils_1.isFuturePerpetualExecution)(this.opts.config) ? 'signed' : 'none')).trim().toLowerCase();
        if (raw === 'none' || raw === 'absolute_cost')
            return raw;
        return 'signed';
    }
    maintenanceMarginRate(position) {
        const candidates = [
            position?.keepMarginRate,
            position?.maintenanceMarginRate,
            this.opts.symbolInfo?.keepMarginRate,
            this.opts.symbolInfo?.maintenanceMarginRate,
            this.opts.config?.maintenanceMarginRate,
            this.opts.stockMarketConfig.maintenanceMarginRate,
        ];
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value) && value > 0)
                return Math.min(0.5, value);
        }
        return 0.01;
    }
    bestBid() {
        return this.firstPositivePrice(this.opts.tickerInfo?.bestBid, this.opts.tickerInfo?.bidPr, this.opts.tickerInfo?.bidPrice, this.opts.tickerInfo?.bid, this.price);
    }
    bestAsk() {
        return this.firstPositivePrice(this.opts.tickerInfo?.bestAsk, this.opts.tickerInfo?.askPr, this.opts.tickerInfo?.askPrice, this.opts.tickerInfo?.ask, this.price + this.opts.stockMarketConfig.paperSpread);
    }
    markPrice() {
        return this.firstPositivePrice(this.opts.tickerInfo?.markPrice, this.opts.symbolInfo?.markPrice, this.price);
    }
    firstPositivePrice(...candidates) {
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value) && value > 0)
                return value;
        }
        return Math.max(0, this.price);
    }
    estimateLiquidationPrice(side, entry, quantity, initialMargin, notional) {
        if (!(entry > 0 && quantity > 0))
            return null;
        const maintenance = notional * this.maintenanceMarginRate();
        const buffer = notional * this.opts.stockMarketConfig.liquidationBufferPct;
        const lossLimit = Math.max(0, initialMargin - maintenance - buffer);
        const distance = lossLimit / quantity;
        return side === 'buy' ? Math.max(0, entry - distance) : entry + distance;
    }
    bankroll() {
        return Math.max(0, Number(this.opts.config?.bankroll
            ?? this.opts.stockMarketConfig.bankroll));
    }
    takerFeeRate() {
        return Math.max(0, Number(this.opts.symbolInfo?.takerFeeRate
            ?? this.opts.symbolInfo?.takerFee
            ?? this.opts.config?.defaultTakerFeeRate
            ?? this.opts.config?.takerFeeRate
            ?? this.opts.stockMarketConfig.defaultTakerFeeRate
            ?? 0));
    }
    makerFeeRate() {
        return Math.max(0, Number(this.opts.symbolInfo?.makerFeeRate
            ?? this.opts.symbolInfo?.makerFee
            ?? this.opts.config?.makerFeeRate
            ?? this.opts.config?.maker_fee_rate
            ?? this.opts.stockMarketConfig.makerFeeRate
            ?? 0));
    }
    feeRateForMode(mode) {
        return mode === 'maker' ? this.makerFeeRate() : this.takerFeeRate();
    }
    slippagePercent() {
        return Math.max(0, Number(this.opts.config?.slippagePercent
            ?? this.opts.config?.estimatedSlippagePercent
            ?? this.opts.stockMarketConfig.slippagePercent
            ?? 0));
    }
    makerSlippagePercent() {
        return Math.max(0, Number(this.opts.config?.makerSlippagePercent
            ?? this.opts.config?.maker_slippage_percent
            ?? this.opts.stockMarketConfig.makerSlippagePercent
            ?? 0));
    }
    slippageForMode(mode) {
        return mode === 'maker' ? this.makerSlippagePercent() : this.slippagePercent();
    }
    slippageModel() {
        return String(this.opts.config?.slippageModel || this.opts.stockMarketConfig.slippageModel || '').toLowerCase() === 'one_sided'
            ? 'one_sided'
            : 'two_sided';
    }
    openExecutionMode() {
        return normalizeExecutionMode(this.opts.config?.runtimeOpenExecutionMode
            ?? this.opts.config?.openExecutionMode
            ?? this.opts.stockMarketConfig.openExecutionMode, this.opts.stockMarketConfig.openExecutionMode);
    }
    closeExecutionMode() {
        return normalizeExecutionMode(this.opts.config?.runtimeCloseExecutionMode
            ?? this.opts.config?.closeExecutionMode
            ?? this.opts.stockMarketConfig.closeExecutionMode, this.opts.stockMarketConfig.closeExecutionMode);
    }
    runtimeMakerPostOnly() {
        const value = this.opts.config?.runtimeMakerPostOnly ?? this.opts.config?.makerPostOnly ?? this.opts.stockMarketConfig.runtimeMakerPostOnly;
        if (typeof value === 'boolean')
            return value;
        const normalized = String(value ?? '').trim().toLowerCase();
        if (['0', 'false', 'no', 'nao'].includes(normalized))
            return false;
        if (['1', 'true', 'yes', 'sim'].includes(normalized))
            return true;
        return true;
    }
    async unwrapResponsePayload(response) {
        if (response == null)
            return response;
        if (typeof response?.data === 'function') {
            try {
                return await response.data();
            }
            catch {
                return response;
            }
        }
        return response?.data ?? response;
    }
    ensureStateCollections() {
        if (!Array.isArray(this.opts.state.activePositions))
            this.opts.state.activePositions = [];
        if (!Array.isArray(this.opts.state.shortPositions))
            this.opts.state.shortPositions = [];
        if (!Array.isArray(this.opts.runtimeState.history))
            this.opts.runtimeState.history = [];
        if ((0, FundingCostUtils_1.isFuturePerpetualExecution)(this.opts.config)) {
            const positions = [...this.opts.state.activePositions, ...this.opts.state.shortPositions];
            for (const position of positions) {
                const entry = Number(position?.entryPrice ?? position?.price ?? 0);
                const quantity = this.statePositionQuantity(position);
                const notional = Number(position?.entryNotional || this.notional(entry, quantity));
                const initialMargin = Number(position?.initialMargin || 0);
                if (!(Number(position?.leveragedExposureUsd) >= 0)) {
                    position.leveragedExposureUsd = Math.max(0, notional - initialMargin);
                }
                position.borrowedAmount = 0;
                position.dailyInterestRate = 0;
                position.maintenanceMarginRate = this.maintenanceMarginRate(position);
            }
        }
    }
    longPositions() {
        this.ensureStateCollections();
        return this.opts.state.activePositions;
    }
    shortPositions() {
        this.ensureStateCollections();
        return this.opts.state.shortPositions;
    }
    findStatePosition(id) {
        const longIndex = this.longPositions().findIndex((position) => String(position.positionId || position.id) === String(id));
        if (longIndex >= 0) {
            return { side: 'long', index: longIndex, position: this.longPositions()[longIndex] };
        }
        const shortIndex = this.shortPositions().findIndex((position) => String(position.positionId || position.id) === String(id));
        if (shortIndex >= 0) {
            return { side: 'short', index: shortIndex, position: this.shortPositions()[shortIndex] };
        }
        return null;
    }
    removeLocatedPosition(located) {
        if (located.side === 'long')
            this.longPositions().splice(located.index, 1);
        else
            this.shortPositions().splice(located.index, 1);
    }
    pushHistory(deal) {
        const history = this.opts.runtimeState.history || [];
        history.push(deal);
        const maxHistory = 500;
        this.opts.runtimeState.history = history.slice(-maxHistory);
        this.opts.runtimeState.feeTotal = Number(this.opts.runtimeState.feeTotal || 0) + Number(deal.feeTotal || 0);
        this.opts.runtimeState.slippageTotal = Number(this.opts.runtimeState.slippageTotal || 0) + Number(deal.slippageTotal || 0);
        this.opts.runtimeState.interestTotal = Number(this.opts.runtimeState.interestTotal || 0) + Number(deal.interest || 0);
        this.opts.runtimeState.fundingTotal = Number(this.opts.runtimeState.fundingTotal || 0) + Number(deal.funding || 0);
        this.opts.runtimeState.liquidationPenaltyTotal = Number(this.opts.runtimeState.liquidationPenaltyTotal || 0) + Number(deal.liquidationPenalty || 0);
    }
    nextDemoOrderId(phase) {
        const next = Math.max(0, Number(this.opts.runtimeState.orderSeq || 0)) + 1;
        this.opts.runtimeState.orderSeq = next;
        return `GT_${phase}_${Date.now()}_${next}`;
    }
    recordError(message) {
        this.opts.runtimeState.lastError = message;
        this.opts.runtimeState.lastAction = 'error';
        this.opts.logger?.warn?.(`[StockMarket] ${message}`);
    }
}
exports.StockMarketBrokerAdapter = StockMarketBrokerAdapter;
function firstPositiveNumber(...values) {
    for (const value of values) {
        const parsed = Number(value);
        if (Number.isFinite(parsed) && parsed > 0)
            return parsed;
    }
    return undefined;
}
function firstNonNegativeNumber(...values) {
    for (const value of values) {
        const parsed = Number(value);
        if (Number.isFinite(parsed) && parsed >= 0)
            return parsed;
    }
    return undefined;
}
function formatSizingNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed.toFixed(8).replace(/0+$/, '').replace(/\.$/, '') : 'n/a';
}
function normalizeExecutionMode(value, fallback) {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'maker' || normalized === 'post_only' || normalized === 'post-only')
        return 'maker';
    if (normalized === 'taker')
        return 'taker';
    if (normalized === 'market')
        return 'market';
    return fallback;
}
