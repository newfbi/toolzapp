"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const StockMarketBrokerAdapter_1 = require("./StockMarketBrokerAdapter");
const StockMarketStrategyEngine_1 = __importDefault(require("./StockMarketStrategyEngine"));
function currentPrice(ticker, indicators) {
    // BingX returns the latest quote as `lastPr`; keep the generic aliases
    // for the other exchange adapters and strategy snapshots.
    return Number(ticker?.last ?? ticker?.lastPrice ?? ticker?.lastPr ?? ticker?.price ?? ticker?.close ?? indicators?.lastClose ?? indicators?.close ?? indicators?.price ?? 0);
}
function openCount(state) {
    return (Array.isArray(state?.activePositions) ? state.activePositions.length : 0)
        + (Array.isArray(state?.shortPositions) ? state.shortPositions.length : 0);
}
function ensureRuntimeState(state) {
    if (!state._stockMarketRuntimeState || typeof state._stockMarketRuntimeState !== 'object') {
        state._stockMarketRuntimeState = { history: [], lastAction: 'waiting' };
    }
    return state._stockMarketRuntimeState;
}
/**
 * Independent StockMarket service. It owns the strategy lifecycle and only
 * depends on generic exchange/order contracts through StockMarketBrokerAdapter.
 */
class StockMarketTradingService {
    constructor(botName, maxOperationsOrConfig = 1, _botId, _dbConfig, _signalClient, batchOps) {
        this.strategy = new StockMarketStrategyEngine_1.default();
        this.botName = botName;
        this.configuredMaxOperations = typeof maxOperationsOrConfig === 'object'
            ? Number(maxOperationsOrConfig.maxOperations || 1)
            : Number(maxOperationsOrConfig || 1);
        this.batchOps = batchOps || null;
        this.logger = {
            info: (msg) => this.writeLog('INFO', msg),
            warn: (msg) => this.writeLog('WARNING', msg),
            error: (msg) => this.writeLog('ERROR', msg),
        };
    }
    async executeIteration(client, config, state, balances, symbolInfo, tickerInfo, currentIndicators, marginAccountInfo, marginPositions) {
        const runtimeState = ensureRuntimeState(state);
        const price = currentPrice(tickerInfo, currentIndicators);
        const isReal = String(config?.opType || config?.accountType || 'demo').toLowerCase() === 'real';
        const maxOpen = Math.max(1, Math.min(2, Number(config.stockMarketMaxOpenPositions ?? 2)));
        const stockConfig = {
            ...config,
            maxOpenPositions: maxOpen,
            maxInitialMarginUsagePct: Number(config.maxMarginUsedPct ?? 0.70),
            allowOppositeEnginePositions: true,
            sizingMode: 'margin_base',
            leverage: Number(config.leverage ?? config.leverageDefault ?? 3),
            leverageDefault: Number(config.leverage ?? config.leverageDefault ?? 3),
            minEquityForNewEntries: Number(config.bankroll ?? 250) * 0.05,
            openExecutionMode: 'maker',
            closeExecutionMode: 'taker',
            riskEmergencyCloseMode: 'market',
            minNetUsdPerClose: Math.max(0.30, Number(config.minNetUsdPerClose ?? 0)),
            positiveNetCloseMinUsd: Math.max(0.30, Number(config.positiveNetCloseMinUsd ?? 0)),
            makerFeeRate: Number(config.makerFeeRate ?? 0.0002),
            defaultTakerFeeRate: Number(config.defaultTakerFeeRate ?? 0.0006),
            makerSlippagePercent: Number(config.makerSlippagePercent ?? 0),
            takerSlippagePercent: Number(config.takerSlippagePercent ?? 0.0003),
            stockMarketTag: 'stockmarket',
        };
        if (!(price > 0)) {
            runtimeState.lastAction = 'waiting_price';
            return this.result(runtimeState, state, 0, 'waiting');
        }
        const broker = new StockMarketBrokerAdapter_1.StockMarketBrokerAdapter({
            client,
            config: stockConfig,
            stockMarketConfig: stockConfig,
            state,
            runtimeState,
            currentPrice: price,
            symbolInfo,
            tickerInfo,
            isReal,
            batchOps: this.batchOps,
            logger: this.logger,
        });
        broker.setPreco(price);
        // A exchange e a fonte de verdade para fills antes do alvo de fechamento.
        broker.syncRealPositions(marginPositions);
        // Reconcile stale maker opens even when there is no new entry signal.
        // Otherwise an old pending order can silently keep the engine occupied.
        await broker.reconcilePendingOrders();
        await broker.closeLiquidationRiskPositions();
        const decision = this.strategy.evaluate({ ...currentIndicators, price }, stockConfig);
        runtimeState.lastStatus = decision.regime;
        runtimeState.lastPrice = price;
        runtimeState.entryGate = openCount(state) >= maxOpen ? 'capacity_full' : 'available';
        // Close only the profitable side/position. There is no basket, rebase,
        // grid, martingale or leak close in this engine.
        for (const position of broker.positions()) {
            const net = broker.positionNet(String(position.id));
            if (net !== null && net >= Math.max(0.30, Number(stockConfig.minNetUsdPerClose))) {
                await broker.closePosition(String(position.id), 'stockmarket_net_target');
            }
        }
        if (decision.enabled && openCount(state) < maxOpen) {
            const amountMargin = Math.max(0.01, Number(stockConfig.amount ?? 30));
            const qty = (amountMargin * stockConfig.leverage) / price;
            const side = decision.allowLong ? 'long' : decision.allowShort ? 'short' : null;
            if (side) {
                await broker.marketOrder(`stockmarket_${side}`, side === 'long' ? 'buy' : 'sell', qty);
                runtimeState.lastAction = `open_${side}`;
            }
        }
        else if (!decision.enabled) {
            runtimeState.lastAction = `blocked_${decision.reason}`;
        }
        else {
            runtimeState.lastAction = 'holding_capacity';
        }
        runtimeState.updatedAt = Date.now();
        runtimeState.floatingNet = broker.positionDiagnostics(runtimeState.engineState)
            .reduce((sum, item) => sum + Number(item.unrealizedNet || 0), 0);
        runtimeState.positionDiagnostics = broker.positionDiagnostics(runtimeState.engineState);
        return this.result(runtimeState, state, price, runtimeState.lastAction || 'waiting');
    }
    async shutdown(client, config, state, runtime = {}) {
        const runtimeState = ensureRuntimeState(state);
        const symbol = String(config?.market || config?.symbol || "");
        let canceledOrders = 0;
        let closedPositions = 0;
        let pendingOrders = [];
        if (client?.Order && typeof client.Order.ordersPending === "function") {
            try {
                const response = await client.Order.ordersPending({ symbol });
                const payload = response?.data ?? response;
                pendingOrders = Array.isArray(payload) ? payload : (Array.isArray(payload?.list) ? payload.list : []);
            } catch (error) {
                this.logger.warn("[SHUTDOWN] Nao foi possivel consultar ordens pendentes StockMarket: " + (error?.message || error));
            }
        }
        if (client?.Order && typeof client.Order.cancelOrder === "function") {
            for (const order of pendingOrders) {
                const orderId = order?.orderId ?? order?.id ?? order?.orderID;
                if (!orderId) continue;
                try {
                    await client.Order.cancelOrder({ symbol, orderId });
                    canceledOrders++;
                } catch (error) {
                    this.logger.warn("[SHUTDOWN] Falha ao cancelar ordem StockMarket " + orderId + ": " + (error?.message || error));
                }
            }
        }
        const forceClose = config?.finalCloseOpenPositions === true;
        if (forceClose && openCount(state) > 0) {
            let tickerInfo = runtime?.tickerInfo || {};
            let price = currentPrice(tickerInfo);
            if (!(price > 0) && client?.Market && typeof client.Market.ticker === "function") {
                try {
                    const response = await client.Market.ticker({ symbol });
                    tickerInfo = response?.data ?? response ?? {};
                    price = currentPrice(tickerInfo);
                } catch (error) {
                    this.logger.warn("[SHUTDOWN] Nao foi possivel consultar preco para fechamento StockMarket: " + (error?.message || error));
                }
            }
            if (price > 0) {
                const stockConfig = {
                    ...config,
                    maxOpenPositions: Math.max(1, Math.min(2, Number(config.stockMarketMaxOpenPositions ?? config.maxOperations ?? 2))),
                    maxInitialMarginUsagePct: Number(config.maxMarginUsedPct ?? 0.70),
                    allowOppositeEnginePositions: true,
                    sizingMode: "margin_base",
                    openExecutionMode: "market",
                    closeExecutionMode: "market",
                    riskEmergencyCloseMode: "market",
                    makerFeeRate: Number(config.makerFeeRate ?? 0.0002),
                    defaultTakerFeeRate: Number(config.defaultTakerFeeRate ?? 0.0006),
                    takerSlippagePercent: Number(config.takerSlippagePercent ?? config.slippagePercent ?? 0.0003),
                    stockMarketTag: "stockmarket",
                };
                const broker = new StockMarketBrokerAdapter_1.StockMarketBrokerAdapter({
                    client, config: stockConfig, stockMarketConfig: stockConfig, state, runtimeState,
                    currentPrice: price, symbolInfo: runtime?.symbolInfo || {}, tickerInfo,
                    isReal: String(config?.opType || "demo").toLowerCase() === "real",
                    batchOps: this.batchOps, logger: this.logger,
                });
                broker.setPreco(price);
                for (const position of broker.positions()) {
                    const deal = await broker.closePosition(String(position.id), "shutdown");
                    if (deal) closedPositions++;
                }
            } else {
                this.logger.warn("[SHUTDOWN] Posicoes StockMarket preservadas: preco indisponivel para fechamento.");
            }
        }
        const residualPositions = openCount(state);
        runtimeState.lastAction = forceClose && residualPositions === 0 ? "shutdown_closed" : "shutdown_canceled_orders";
        return {
            canceledOrders,
            closedPositions: closedPositions > 0 || (forceClose && residualPositions === 0),
            residualPositions,
            residualLiability: residualPositions > 0 ? Number.POSITIVE_INFINITY : 0,
            repaidAssets: 0,
        };
    }
    result(runtimeState, state, price, action) {
        return {
            action,
            lastPrice: price,
            totalProfit: Number(state?.totalProfit || 0),
            openPositions: openCount(state),
            status: runtimeState.lastStatus || 'waiting',
            risk: {
                currentEquity: Number(state?.currentEquity || state?.bankroll || 0),
                marginUsed: Number(state?.marginUsed || 0),
                marginUsedPct: Number(state?.marginUsedPct || 0),
                floatingNet: Number(runtimeState.floatingNet || 0),
                entryGate: runtimeState.entryGate || 'available',
                positions: runtimeState.positionDiagnostics || [],
            },
        };
    }
    writeLog(type, message) {
        const line = `[StockMarket:${this.botName}] ${message}`;
        if (type === 'ERROR')
            console.error(line);
        else if (type === 'WARNING')
            console.warn(line);
        else
            console.log(line);
        try {
            const dir = path_1.default.join(process.cwd(), 'storage', 'logs', 'trading');
            fs_1.default.mkdirSync(dir, { recursive: true });
            fs_1.default.appendFileSync(path_1.default.join(dir, `${this.botName}.log`), `[${new Date().toISOString()}] [${type}] ${message}\n`);
        }
        catch { /* observability cannot stop the trading loop */ }
    }
}
exports.default = StockMarketTradingService;
