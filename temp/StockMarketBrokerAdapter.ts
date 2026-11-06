import type { BrokerAdapter, ClosedDeal, Position, Side } from '../../exchange/types';
import {
    buildManagedFutureOrderPayload,
    isManagedFuturesExchange,
    resolveManagedFuturesMarginMode,
    resolveManagedFuturesProductType,
} from '../../exchange/ManagedFuturesRuntime';
import { extractOrderId } from '../../exchange/OrderNormalization';
import {
    CryptoQuantityNormalizationResult,
    CryptoQuantityRules,
    normalizeCryptoOrderQuantity,
    resolveCryptoQuantityRules,
} from '../../exchange/CryptoOrderSizing';
import {
    estimatePerpFundingCost,
    isFuturePerpetualExecution,
    resolveDailyInterestRateForExecution,
} from '../../lib/FundingCostUtils';

export interface StockMarketBrokerOptions {
    client?: any;
    config: any;
    stockMarketConfig: any;
    state: any;
    runtimeState: StockMarketRuntimeState;
    currentPrice: number;
    symbolInfo?: any;
    tickerInfo?: any;
    isReal?: boolean;
    batchOps?: any;
    logger?: { info?: (msg: string) => void; warn?: (msg: string) => void; error?: (msg: string) => void };
}

export interface StockMarketRuntimeState {
    history?: ClosedDeal[];
    lastAction?: string;
    lastOrderId?: string;
    lastError?: string;
    liquidationCount?: number;
    feeTotal?: number;
    slippageTotal?: number;
    interestTotal?: number;
    fundingTotal?: number;
    liquidationPenaltyTotal?: number;
    orderSeq?: number;
    engineState?: any;
    managedFutureSettings?: Record<string, unknown>;
    lastStatus?: string;
    lastPrice?: number;
    lastCandleCountH1?: number;
    lastCandleCountH8?: number;
    updatedAt?: number;
    quantityRules?: CryptoQuantityRules;
    entryGate?: 'available' | 'capacity_full';
    floatingNet?: number;
    positionDiagnostics?: StockMarketPositionDiagnostic[];
}

export interface StockMarketPositionDiagnostic {
    id: string;
    tag: string;
    side: PositionSide;
    quantity: number;
    entryPrice: number;
    currentExitPrice: number;
    stopPrice: number | null;
    distanceToStop: number | null;
    unrealizedNet: number;
    profitPeakUsd: number | null;
    profitLockUsd: number | null;
    profitLockStagnationMinutes: number | null;
    ageMinutes: number;
}

type PositionSide = 'long' | 'short';

export class StockMarketBrokerAdapter implements BrokerAdapter {
    private price = 0;
    private resolvedQuantityRules?: CryptoQuantityRules;

    constructor(private opts: StockMarketBrokerOptions) {
        this.price = Number(opts.currentPrice || 0);
        this.ensureStateCollections();
        this.opts.runtimeState.quantityRules = this.quantityRules();
    }

    setPreco(close: number): void {
        this.price = Number(close || 0);
        this.updateRiskState();
    }

    positions(): Position[] {
        const longs = this.longPositions().map((position: any) => this.toBrokerPosition(position, 'buy'));
        const shorts = this.shortPositions().map((position: any) => this.toBrokerPosition(position, 'sell'));
        return [...longs, ...shorts];
    }

    history(): ClosedDeal[] {
        return Array.isArray(this.opts.runtimeState.history)
            ? [...this.opts.runtimeState.history]
            : [];
    }

    positionNet(id: string): number | null {
        const located = this.findStatePosition(id);
        return located ? this.unrealizedNet(located.side, located.position) : null;
    }

    positionDiagnostics(engineState?: Record<string, unknown> | null): StockMarketPositionDiagnostic[] {
        const now = Date.now();
        return this.rawStatePositions().map(({ side, position }) => {
            const tag = String(position.stockMarketTag || position.tag || 'StockMarket');
            const id = String(position.positionId || position.id || '');
            const entrySide: Side = side === 'long' ? 'buy' : 'sell';
            const stopRaw = tag === 'turtle'
                ? engineState?.turtleSl
                : (tag === 'fimathe' ? engineState?.fimatheSl : undefined);
            const stop = Number(stopRaw);
            const stopPrice = Number.isFinite(stop) && stop > 0 ? stop : null;
            const profitLockRaw = tag === 'turtle'
                ? engineState?.turtleProfitLock
                : (tag === 'fimathe' ? engineState?.fimatheProfitLock : undefined);
            const profitLock = profitLockRaw && typeof profitLockRaw === 'object'
                && String((profitLockRaw as any).positionId || '') === id
                ? profitLockRaw as Record<string, unknown>
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

    getQuantityRules(): CryptoQuantityRules {
        return this.quantityRules();
    }

    async marketOrder(tag: string, side: Side, requestedQuantity: number): Promise<Position | null> {
        this.ensureStateCollections();
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
        if (
            !this.opts.stockMarketConfig.allowOppositeEnginePositions
            && this.positions().some((position) => position.side !== side)
        ) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_opposite_engine_conflict`;
            return null;
        }

        const normalized = this.normalizeOpeningQuantity(requestedQuantity);
        const quantity = normalized.quantity;
        if (!normalized.accepted || !(quantity > 0)) {
            this.recordError(
                `Quantidade Crypto rejeitada para abertura StockMarket tag=${tag} ` +
                `requested=${formatSizingNumber(requestedQuantity)} reason=${normalized.reason || 'invalid_quantity'} ` +
                `notional=${formatSizingNumber(normalized.notionalUsd)} minNotional=${formatSizingNumber(normalized.effectiveMinNotionalUsd)}`
            );
            return null;
        }

        const entry = this.entryPrice(side);
        const risk = this.buildOpenRisk(entry, quantity);
        if (!this.canOpenWithMargin(risk.initialMargin, risk.feeOpen + risk.slippageOpen)) {
            this.opts.runtimeState.lastAction = `skip_open_${tag}_margin_cap`;
            return null;
        }

        const positionSide: PositionSide = side === 'buy' ? 'long' : 'short';
        const orderId = await this.placeExchangeOrder({
            phase: 'open',
            positionSide,
            quantity,
        });
        if (!orderId) return null;

        const positionId = `${tag}_${orderId}`;
        const openTime = Date.now();
        const statePosition = {
            positionId,
            entryPrice: entry,
            price: entry,
            quantity,
            entryNotional: risk.notional,
            initialMargin: risk.initialMargin,
            borrowedAmount: risk.borrowedAmount,
            leveragedExposureUsd: risk.leveragedExposureUsd,
            feeOpen: risk.feeOpen,
            slippageOpen: risk.slippageOpen,
            liquidationPrice: this.estimateLiquidationPrice(side, entry, quantity, risk.initialMargin, risk.notional),
            openTime,
            side: positionSide,
            leverage: risk.leverage,
            openExecutionMode: this.openExecutionMode(),
            closeExecutionMode: this.closeExecutionMode(),
            dailyInterestRate: resolveDailyInterestRateForExecution(this.opts.config),
            fundingRatePer8h: this.fundingRatePer8h(),
            fundingIntervalHours: this.fundingIntervalHours(),
            fundingFeeMode: this.fundingFeeMode(),
            maintenanceMarginRate: this.maintenanceMarginRate(),
            stockMarketTag: tag,
            requestedQuantity: Number(requestedQuantity || 0),
            quantityUnit: 'base_asset',
            stockMarketOrderId: orderId,
        };

        if (positionSide === 'long') {
            this.longPositions().push(statePosition);
            this.opts.state.lastBuyPrice = entry;
        } else {
            this.shortPositions().push(statePosition);
            this.opts.state.lastSellPrice = entry;
        }
        this.opts.state.status = 'holding';
        this.opts.runtimeState.lastAction = `open_${tag}_${side}`;
        this.opts.runtimeState.lastOrderId = orderId;
        this.recordOperation({
            side,
            price: entry,
            quantity,
            orderId,
            profit: null,
            phase: 'open',
            reason: tag,
            positionId,
            fee: risk.feeOpen,
            executionMode: this.openExecutionMode(),
            context: {
                notional: risk.notional,
                initialMargin: risk.initialMargin,
                borrowedAmount: risk.borrowedAmount,
                leveragedExposureUsd: risk.leveragedExposureUsd,
                slippageOpen: risk.slippageOpen,
                liquidationPrice: this.estimateLiquidationPrice(side, entry, quantity, risk.initialMargin, risk.notional),
                openExecutionMode: this.openExecutionMode(),
                closeExecutionMode: this.closeExecutionMode(),
                requestedQuantity: normalized.requestedQuantity,
                normalizedQuantity: quantity,
                quantityUnit: 'base_asset',
                quantityStep: this.quantityRules().quantityStep,
                minOrderQty: this.quantityRules().minOrderQty,
                minNotionalUsd: this.quantityRules().minNotionalUsd,
                quantityStepSource: this.quantityRules().quantityStepSource,
                minOrderQtySource: this.quantityRules().minOrderQtySource,
                minNotionalSource: this.quantityRules().minNotionalSource,
            },
        });
        this.updateRiskState();

        return {
            id: positionId,
            tag,
            side,
            entry,
            openTime,
            quantity,
            entryNotional: risk.notional,
            initialMargin: risk.initialMargin,
            borrowedAmount: risk.borrowedAmount,
            leverage: risk.leverage,
            feeOpen: risk.feeOpen,
            slippageOpen: risk.slippageOpen,
            liquidationPrice: this.estimateLiquidationPrice(side, entry, quantity, risk.initialMargin, risk.notional),
            openExecutionMode: this.openExecutionMode(),
            closeExecutionMode: this.closeExecutionMode(),
        } as Position;
    }

    async closePosition(id: string, closeReason?: string): Promise<ClosedDeal | null> {
        this.ensureStateCollections();
        const located = this.findStatePosition(id);
        if (!located) return null;
        if (!(this.price > 0)) {
            this.recordError('Preco atual indisponivel para fechamento StockMarket');
            return null;
        }

        const side: Side = located.side === 'long' ? 'buy' : 'sell';
        const closeSide: Side = located.side === 'long' ? 'sell' : 'buy';
        const storedQuantity = this.statePositionQuantity(located.position);
        const normalizedClose = normalizeCryptoOrderQuantity({
            requestedQuantity: storedQuantity,
            price: this.price,
            rules: this.quantityRules(),
            reduceOnly: true,
            totalPositionQty: storedQuantity,
            orderType: this.closeExecutionMode() === 'maker' ? 'limit' : 'market',
        });
        const quantity = normalizedClose.quantity;
        if (!normalizedClose.accepted || !(quantity > 0)) {
            this.recordError(
                `Quantidade Crypto rejeitada para fechamento StockMarket id=${id} ` +
                `requested=${formatSizingNumber(storedQuantity)} reason=${normalizedClose.reason || 'invalid_quantity'}`
            );
            return null;
        }

        const orderId = await this.placeExchangeOrder({
            phase: 'close',
            positionSide: located.side,
            quantity,
        });
        if (!orderId) return null;

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
        } as ClosedDeal;

        this.removeLocatedPosition(located);
        this.pushHistory(deal);
        this.opts.state.totalProfit = Number(this.opts.state.totalProfit || 0) + closeNet.net;
        if (this.positions().length === 0) this.opts.state.status = 'waiting';
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

    equity(initialEquity: number): number {
        const realized = this.history().reduce((sum, deal) => sum + Number(deal.profit || 0), 0);
        const floating = this.rawStatePositions().reduce((sum, item) => sum + this.unrealizedNet(item.side, item.position), 0);
        return Number(initialEquity || 0) + realized + floating;
    }

    async closeLiquidationRiskPositions(): Promise<number> {
        const candidates = this.rawStatePositions()
            .filter((item) => this.shouldLiquidate(item.side, item.position))
            .map((item) => String(item.position.positionId || item.position.id || ''))
            .filter(Boolean);

        let closed = 0;
        for (const id of candidates) {
            const deal = await this.closePosition(id, 'liquidation');
            if (deal) closed++;
        }
        if (closed > 0) {
            this.opts.runtimeState.liquidationCount = Number(this.opts.runtimeState.liquidationCount || 0) + closed;
            this.opts.runtimeState.lastAction = `liquidation_guard_closed_${closed}`;
        }
        return closed;
    }

    private async placeExchangeOrder(params: {
        phase: 'open' | 'close';
        positionSide: PositionSide;
        quantity: number;
    }): Promise<string | null> {
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
            const payload = isManagedFuturesExchange(this.opts.config?.exchange)
                ? buildManagedFutureOrderPayload({
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
                    resolveProductType: resolveManagedFuturesProductType,
                    resolveMarginMode: resolveManagedFuturesMarginMode,
                })
                : {
                    symbol: this.opts.config?.market,
                    side: params.positionSide === 'long' ? 'buy' : 'sell',
                    orderType: useMakerExecution ? 'limit' : 'market',
                    ...(useMakerExecution ? { price: orderPrice.toFixed(this.pricePrecision()), force: 'post_only' } : {}),
                    baseSize: params.quantity.toFixed(this.quantityPrecision()),
                    ...(!isFuturePerpetualExecution(this.opts.config) ? { loanType: 'autoLoan' } : {}),
                };

            const response = await client.Order.placeOrder(payload);
            const data = await this.unwrapResponsePayload(response);
            const orderId = extractOrderId(data?.data ?? data);
            if (!orderId) {
                const auditOrderId = 'LOCAL_AUDIT_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
                this.recordError('Exchange aceitou/respondeu ordem StockMarket sem orderId; usando identificador local de auditoria: ' + auditOrderId);
                return auditOrderId;
            }
            return orderId;
        } catch (error: any) {
            const msg = error?.response?.data?.msg ?? error?.data?.msg ?? error?.message ?? String(error);
            this.recordError(`Falha ao enviar ordem StockMarket: ${msg}`);
            return null;
        }
    }

    private recordOperation(input: {
        side: Side;
        price: number;
        quantity: number;
        orderId: string;
        profit: number | null;
        phase: 'open' | 'close';
        reason: string;
        positionId: string;
        fee?: number | null;
        executionMode?: string;
        context?: Record<string, any>;
    }): void {
        if (!this.opts.batchOps || typeof this.opts.batchOps.addOperation !== 'function') return;
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
            status: 'success',
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
                source: 'turtle_fimathe',
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

    private toBrokerPosition(position: any, side: Side): Position {
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
        } as Position;
    }

    private entryPrice(side: Side): number {
        return side === 'buy' ? this.bestAsk() : this.bestBid();
    }

    private exitPrice(entrySide: Side): number {
        return entrySide === 'buy' ? this.bestBid() : this.bestAsk();
    }

    private normalizeOpeningQuantity(requestedQuantity: number): CryptoQuantityNormalizationResult {
        return normalizeCryptoOrderQuantity({
            requestedQuantity,
            price: this.price,
            rules: this.quantityRules(),
            reduceOnly: false,
            orderType: this.openExecutionMode() === 'maker' ? 'limit' : 'market',
        });
    }

    private quantityRules(): CryptoQuantityRules {
        if (this.resolvedQuantityRules) return this.resolvedQuantityRules;
        const runtimeConfig = this.opts.config || {};
        const fallbackConfig = {
            quantityStep: firstPositiveNumber(runtimeConfig.quantityStep, this.opts.stockMarketConfig.quantityStep),
            minOrderQty: firstPositiveNumber(runtimeConfig.minOrderQty, this.opts.stockMarketConfig.minOrderQty),
            minNotional: firstPositiveNumber(
                runtimeConfig.minNotional,
                runtimeConfig.minNotionalUsd,
                this.opts.stockMarketConfig.minNotional
            ) ?? 0,
            quantityPrecision: firstNonNegativeNumber(runtimeConfig.quantityPrecision),
            pricePrecision: firstNonNegativeNumber(runtimeConfig.pricePrecision),
            priceStep: firstPositiveNumber(runtimeConfig.priceStep),
            maxOrderQty: firstPositiveNumber(runtimeConfig.maxOrderQty),
            maxMarketOrderQty: firstPositiveNumber(runtimeConfig.maxMarketOrderQty),
        };
        this.resolvedQuantityRules = resolveCryptoQuantityRules(this.opts.symbolInfo || {}, fallbackConfig);
        return this.resolvedQuantityRules;
    }

    private statePositionQuantity(position: any): number {
        const direct = Number(position?.quantity);
        if (Number.isFinite(direct) && direct > 0) return direct;
        // Read-only migration boundary for positions persisted by the former Forex-named model.
        const legacy = Number(position?.StockMarketLots ?? position?.lots);
        return Number.isFinite(legacy) && legacy > 0 ? legacy : 0;
    }

    private quantityPrecision(): number {
        return this.quantityRules().quantityPrecision;
    }

    private pricePrecision(): number {
        return this.quantityRules().pricePrecision;
    }

    private passiveLimitPrice(positionSide: PositionSide): number {
        const precision = this.pricePrecision();
        const tick = Math.pow(10, -precision);
        const offset = Math.max(tick, this.price * Math.max(0.00002, this.slippageForMode('taker') / 2));
        const price = positionSide === 'long'
            ? Math.max(tick, this.price - offset)
            : this.price + offset;
        return Number(price.toFixed(precision));
    }

    private buildOpenRisk(entry: number, quantity: number): {
        leverage: number;
        notional: number;
        initialMargin: number;
        borrowedAmount: number;
        leveragedExposureUsd: number;
        feeOpen: number;
        slippageOpen: number;
    } {
        const leverage = Math.max(1, Number(this.opts.config?.leverage || this.opts.stockMarketConfig.leverage || 1));
        const notional = this.notional(entry, quantity);
        const initialMargin = notional / leverage;
        const leveragedExposureUsd = Math.max(0, notional - initialMargin);
        return {
            leverage,
            notional,
            initialMargin,
            borrowedAmount: isFuturePerpetualExecution(this.opts.config) ? 0 : leveragedExposureUsd,
            leveragedExposureUsd,
            feeOpen: notional * this.feeRateForMode(this.openExecutionMode()),
            slippageOpen: this.slippageModel() === 'two_sided'
                ? notional * this.slippageForMode(this.openExecutionMode())
                : 0,
        };
    }

    private canOpenWithMargin(nextInitialMargin: number, openingCost: number): boolean {
        const bankroll = this.bankroll();
        if (!(bankroll > 0)) return true;
        const equity = this.equity(bankroll);
        if (equity < this.opts.stockMarketConfig.minEquityForNewEntries) return false;
        const used = this.rawStatePositions().reduce((sum, item) => {
            const fallback = this.notional(
                Number(item.position.entryPrice || item.position.price || 0),
                this.statePositionQuantity(item.position)
            )
                / Math.max(1, Number(item.position.leverage || this.opts.config?.leverage || 1));
            return sum + Number(item.position.initialMargin || fallback || 0);
        }, 0);
        const projected = used + nextInitialMargin + openingCost;
        return projected <= bankroll * this.opts.stockMarketConfig.maxInitialMarginUsagePct + 1e-9;
    }

    private computeCloseNet(params: {
        side: Side;
        entry: number;
        exit: number;
        quantity: number;
        position: any;
        closeReason: string;
    }): {
        gross: number;
        net: number;
        feeOpen: number;
        feeClose: number;
        feeTotal: number;
        slippageTotal: number;
        interest: number;
        funding: number;
        liquidationPenalty: number;
        closeReason: string;
    } {
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

    private unrealizedNet(positionSide: PositionSide, position: any): number {
        const side: Side = positionSide === 'long' ? 'buy' : 'sell';
        const entry = Number(position.entryPrice ?? position.price ?? 0);
        const quantity = this.statePositionQuantity(position);
        if (!(entry > 0 && quantity > 0)) return 0;
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

    private shouldLiquidate(positionSide: PositionSide, position: any): boolean {
        const side: Side = positionSide === 'long' ? 'buy' : 'sell';
        const entry = Number(position.entryPrice ?? position.price ?? 0);
        const quantity = this.statePositionQuantity(position);
        if (!(this.price > 0 && entry > 0 && quantity > 0)) return false;

        const notional = Number(position.entryNotional || this.notional(entry, quantity));
        const leverage = Math.max(1, Number(position.leverage || this.opts.config?.leverage || 1));
        const initialMargin = Number(position.initialMargin || notional / leverage);
        const maintenance = notional * this.maintenanceMarginRate(position);
        const buffer = notional * this.opts.stockMarketConfig.liquidationBufferPct;
        const lossLimit = Math.max(0, initialMargin - maintenance - buffer);
        const gross = this.gross(side, entry, this.markPrice(), quantity);
        return gross <= -lossLimit;
    }

    private updateRiskState(): void {
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

    private rawStatePositions(): Array<{ side: PositionSide; position: any }> {
        return [
            ...this.longPositions().map((position: any) => ({ side: 'long' as const, position })),
            ...this.shortPositions().map((position: any) => ({ side: 'short' as const, position })),
        ];
    }

    private gross(side: Side, entry: number, exit: number, quantity: number): number {
        const priceDelta = side === 'buy' ? exit - entry : entry - exit;
        return priceDelta * quantity;
    }

    private notional(price: number, quantity: number): number {
        return Math.max(0, Number(price || 0) * Number(quantity || 0));
    }

    private interest(position: any): number {
        const rate = resolveDailyInterestRateForExecution(
            this.opts.config,
            Number(position.dailyInterestRate ?? this.opts.stockMarketConfig.defaultDailyInterestRate)
        );
        const borrowed = Number(position.borrowedAmount || 0);
        if (!(rate > 0 && borrowed > 0)) return 0;
        const now = Date.now();
        const holdingMs = Math.max(1, now - Number(position.openTime || now));
        const holdingDays = Math.max(holdingMs / (24 * 60 * 60 * 1000), 1 / 1440);
        return borrowed * rate * holdingDays;
    }

    private funding(position: any, quantity: number): number {
        const side: PositionSide = String(position?.side || '').toLowerCase() === 'short' ? 'short' : 'long';
        return estimatePerpFundingCost({
            isPerp: isFuturePerpetualExecution(this.opts.config),
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

    private fundingRatePer8h(position?: any): number {
        const candidates = [
            this.opts.symbolInfo?.fundingRatePer8h,
            this.opts.config?.fundingRatePer8h,
            this.opts.config?.defaultFundingRatePer8h,
            position?.fundingRatePer8h,
        ];
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value)) return value;
        }
        return 0;
    }

    private fundingIntervalHours(position?: any): number {
        const candidates = [
            this.opts.symbolInfo?.fundingIntervalHours,
            this.opts.config?.fundingIntervalHours,
            position?.fundingIntervalHours,
        ];
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value) && value > 0) return Math.max(0.25, value);
        }
        return 8;
    }

    private fundingFeeMode(position?: any): 'none' | 'signed' | 'absolute_cost' {
        const raw = String(
            position?.fundingFeeMode
            ?? this.opts.config?.fundingFeeMode
            ?? (isFuturePerpetualExecution(this.opts.config) ? 'signed' : 'none')
        ).trim().toLowerCase();
        if (raw === 'none' || raw === 'absolute_cost') return raw;
        return 'signed';
    }

    private maintenanceMarginRate(position?: any): number {
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
            if (Number.isFinite(value) && value > 0) return Math.min(0.5, value);
        }
        return 0.01;
    }

    private bestBid(): number {
        return this.firstPositivePrice(
            this.opts.tickerInfo?.bestBid,
            this.opts.tickerInfo?.bidPr,
            this.opts.tickerInfo?.bidPrice,
            this.opts.tickerInfo?.bid,
            this.price
        );
    }

    private bestAsk(): number {
        return this.firstPositivePrice(
            this.opts.tickerInfo?.bestAsk,
            this.opts.tickerInfo?.askPr,
            this.opts.tickerInfo?.askPrice,
            this.opts.tickerInfo?.ask,
            this.price + this.opts.stockMarketConfig.paperSpread
        );
    }

    private markPrice(): number {
        return this.firstPositivePrice(
            this.opts.tickerInfo?.markPrice,
            this.opts.symbolInfo?.markPrice,
            this.price
        );
    }

    private firstPositivePrice(...candidates: any[]): number {
        for (const candidate of candidates) {
            const value = Number(candidate);
            if (Number.isFinite(value) && value > 0) return value;
        }
        return Math.max(0, this.price);
    }

    private estimateLiquidationPrice(side: Side, entry: number, quantity: number, initialMargin: number, notional: number): number | null {
        if (!(entry > 0 && quantity > 0)) return null;
        const maintenance = notional * this.maintenanceMarginRate();
        const buffer = notional * this.opts.stockMarketConfig.liquidationBufferPct;
        const lossLimit = Math.max(0, initialMargin - maintenance - buffer);
        const distance = lossLimit / quantity;
        return side === 'buy' ? Math.max(0, entry - distance) : entry + distance;
    }

    private bankroll(): number {
        return Math.max(
            0,
            Number(
                this.opts.config?.bankroll
                ?? this.opts.stockMarketConfig.bankroll
            )
        );
    }

    private takerFeeRate(): number {
        return Math.max(0, Number(
            this.opts.symbolInfo?.takerFeeRate
            ?? this.opts.symbolInfo?.takerFee
            ?? this.opts.config?.defaultTakerFeeRate
            ?? this.opts.config?.takerFeeRate
            ?? this.opts.stockMarketConfig.defaultTakerFeeRate
            ?? 0
        ));
    }

    private makerFeeRate(): number {
        return Math.max(0, Number(
            this.opts.symbolInfo?.makerFeeRate
            ?? this.opts.symbolInfo?.makerFee
            ?? this.opts.config?.makerFeeRate
            ?? this.opts.config?.maker_fee_rate
            ?? this.opts.stockMarketConfig.makerFeeRate
            ?? 0
        ));
    }

    private feeRateForMode(mode: 'maker' | 'taker' | 'market'): number {
        return mode === 'maker' ? this.makerFeeRate() : this.takerFeeRate();
    }

    private slippagePercent(): number {
        return Math.max(0, Number(
            this.opts.config?.slippagePercent
            ?? this.opts.config?.estimatedSlippagePercent
            ?? this.opts.stockMarketConfig.slippagePercent
            ?? 0
        ));
    }

    private makerSlippagePercent(): number {
        return Math.max(0, Number(
            this.opts.config?.makerSlippagePercent
            ?? this.opts.config?.maker_slippage_percent
            ?? this.opts.stockMarketConfig.makerSlippagePercent
            ?? 0
        ));
    }

    private slippageForMode(mode: 'maker' | 'taker' | 'market'): number {
        return mode === 'maker' ? this.makerSlippagePercent() : this.slippagePercent();
    }

    private slippageModel(): 'one_sided' | 'two_sided' {
        return String(this.opts.config?.slippageModel || this.opts.stockMarketConfig.slippageModel || '').toLowerCase() === 'one_sided'
            ? 'one_sided'
            : 'two_sided';
    }

    private openExecutionMode(): 'maker' | 'taker' | 'market' {
        return normalizeExecutionMode(
            this.opts.config?.runtimeOpenExecutionMode
            ?? this.opts.config?.openExecutionMode
            ?? this.opts.stockMarketConfig.openExecutionMode,
            this.opts.stockMarketConfig.openExecutionMode
        );
    }

    private closeExecutionMode(): 'maker' | 'taker' | 'market' {
        return normalizeExecutionMode(
            this.opts.config?.runtimeCloseExecutionMode
            ?? this.opts.config?.closeExecutionMode
            ?? this.opts.stockMarketConfig.closeExecutionMode,
            this.opts.stockMarketConfig.closeExecutionMode
        );
    }

    private runtimeMakerPostOnly(): boolean {
        const value = this.opts.config?.runtimeMakerPostOnly ?? this.opts.config?.makerPostOnly ?? this.opts.stockMarketConfig.runtimeMakerPostOnly;
        if (typeof value === 'boolean') return value;
        const normalized = String(value ?? '').trim().toLowerCase();
        if (['0', 'false', 'no', 'nao'].includes(normalized)) return false;
        if (['1', 'true', 'yes', 'sim'].includes(normalized)) return true;
        return true;
    }

    private async unwrapResponsePayload(response: any): Promise<any> {
        if (response == null) return response;
        if (typeof response?.data === 'function') {
            try {
                return await response.data();
            } catch {
                return response;
            }
        }
        return response?.data ?? response;
    }

    private ensureStateCollections(): void {
        if (!Array.isArray(this.opts.state.activePositions)) this.opts.state.activePositions = [];
        if (!Array.isArray(this.opts.state.shortPositions)) this.opts.state.shortPositions = [];
        if (!Array.isArray(this.opts.runtimeState.history)) this.opts.runtimeState.history = [];
        if (isFuturePerpetualExecution(this.opts.config)) {
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

    private longPositions(): any[] {
        this.ensureStateCollections();
        return this.opts.state.activePositions;
    }

    private shortPositions(): any[] {
        this.ensureStateCollections();
        return this.opts.state.shortPositions;
    }

    private findStatePosition(id: string): { side: PositionSide; index: number; position: any } | null {
        const longIndex = this.longPositions().findIndex((position: any) => String(position.positionId || position.id) === String(id));
        if (longIndex >= 0) {
            return { side: 'long', index: longIndex, position: this.longPositions()[longIndex] };
        }
        const shortIndex = this.shortPositions().findIndex((position: any) => String(position.positionId || position.id) === String(id));
        if (shortIndex >= 0) {
            return { side: 'short', index: shortIndex, position: this.shortPositions()[shortIndex] };
        }
        return null;
    }

    private removeLocatedPosition(located: { side: PositionSide; index: number }): void {
        if (located.side === 'long') this.longPositions().splice(located.index, 1);
        else this.shortPositions().splice(located.index, 1);
    }

    private pushHistory(deal: ClosedDeal): void {
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

    private nextDemoOrderId(phase: string): string {
        const next = Math.max(0, Number(this.opts.runtimeState.orderSeq || 0)) + 1;
        this.opts.runtimeState.orderSeq = next;
        return `GT_${phase}_${Date.now()}_${next}`;
    }

    private recordError(message: string): void {
        this.opts.runtimeState.lastError = message;
        this.opts.runtimeState.lastAction = 'error';
        this.opts.logger?.warn?.(`[StockMarket] ${message}`);
    }
}

function firstPositiveNumber(...values: unknown[]): number | undefined {
    for (const value of values) {
        const parsed = Number(value);
        if (Number.isFinite(parsed) && parsed > 0) return parsed;
    }
    return undefined;
}

function firstNonNegativeNumber(...values: unknown[]): number | undefined {
    for (const value of values) {
        const parsed = Number(value);
        if (Number.isFinite(parsed) && parsed >= 0) return parsed;
    }
    return undefined;
}

function formatSizingNumber(value: unknown): string {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed.toFixed(8).replace(/0+$/, '').replace(/\.$/, '') : 'n/a';
}

function normalizeExecutionMode(value: unknown, fallback: 'maker' | 'taker' | 'market'): 'maker' | 'taker' | 'market' {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'maker' || normalized === 'post_only' || normalized === 'post-only') return 'maker';
    if (normalized === 'taker') return 'taker';
    if (normalized === 'market') return 'market';
    return fallback;
}

