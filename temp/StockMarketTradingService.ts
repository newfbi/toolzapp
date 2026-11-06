import fs from 'fs';
import path from 'path';
import { StockMarketBrokerAdapter, StockMarketRuntimeState } from './StockMarketBrokerAdapter';
import StockMarketStrategyEngine from './StockMarketStrategyEngine';

type Side = 'long' | 'short';

function currentPrice(ticker: any, indicators: any): number {
    return Number(ticker?.last ?? ticker?.price ?? ticker?.close ?? indicators?.lastClose ?? indicators?.close ?? 0);
}

function openCount(state: any): number {
    return (Array.isArray(state?.activePositions) ? state.activePositions.length : 0)
        + (Array.isArray(state?.shortPositions) ? state.shortPositions.length : 0);
}

function ensureRuntimeState(state: any): StockMarketRuntimeState {
    if (!state._stockMarketRuntimeState || typeof state._stockMarketRuntimeState !== 'object') {
        state._stockMarketRuntimeState = { history: [], lastAction: 'waiting' };
    }
    return state._stockMarketRuntimeState as StockMarketRuntimeState;
}

/**
 * Independent StockMarket service. It owns the strategy lifecycle and only
 * depends on generic exchange/order contracts through StockMarketBrokerAdapter.
 */
export default class StockMarketTradingService {
    private readonly strategy = new StockMarketStrategyEngine();
    private readonly botName: string;
    private readonly configuredMaxOperations: number;
    private readonly batchOps: any;
    private readonly logger: { info: (msg: string) => void; warn: (msg: string) => void; error: (msg: string) => void };

    constructor(botName: string, maxOperationsOrConfig: any = 1, _botId?: number, _dbConfig?: any, _signalClient?: any, batchOps?: any) {
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

    async executeIteration(client: any, config: any, state: any, balances: any, symbolInfo: any, tickerInfo: any, currentIndicators: any): Promise<any> {
        const runtimeState = ensureRuntimeState(state);
        const price = currentPrice(tickerInfo, currentIndicators);
        const isReal = String(config?.opType || config?.accountType || 'demo').toLowerCase() === 'real';
        const maxOpen = Math.max(1, Math.min(2, Number(config.stockMarketMaxOpenPositions ?? 2)));
        const stockConfig = {
            ...config,
            maxOpenPositions: maxOpen,
            maxInitialMarginUsagePct: Number(config.maxMarginUsedPct ?? 0.70),
            allowOppositeEnginePositions: true,
            leverage: Number(config.leverage ?? config.leverageDefault ?? 3),
            leverageDefault: Number(config.leverage ?? config.leverageDefault ?? 3),
            minEquityForNewEntries: Number(config.bankroll ?? 250) * 0.05,
            openExecutionMode: 'maker',
            closeExecutionMode: 'maker',
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

        const broker = new StockMarketBrokerAdapter({
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
            const side: Side | null = decision.allowLong ? 'long' : decision.allowShort ? 'short' : null;
            if (side) {
                await broker.marketOrder(
                    `stockmarket_${side}`,
                    side === 'long' ? 'buy' : 'sell',
                    qty,
                );
                runtimeState.lastAction = `open_${side}`;
            }
        } else if (!decision.enabled) {
            runtimeState.lastAction = `blocked_${decision.reason}`;
        } else {
            runtimeState.lastAction = 'holding_capacity';
        }

        runtimeState.updatedAt = Date.now();
        runtimeState.floatingNet = broker.positionDiagnostics(runtimeState.engineState)
            .reduce((sum, item) => sum + Number(item.unrealizedNet || 0), 0);
        runtimeState.positionDiagnostics = broker.positionDiagnostics(runtimeState.engineState);
        return this.result(runtimeState, state, price, runtimeState.lastAction || 'waiting');
    }

    async shutdown(_client: any, _config: any, state: any): Promise<{ canceledOrders: number; closedPositions: boolean; residualPositions: number }> {
        return { canceledOrders: 0, closedPositions: false, residualPositions: openCount(state) };
    }

    private result(runtimeState: StockMarketRuntimeState, state: any, price: number, action: string): any {
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

    private writeLog(type: 'INFO' | 'WARNING' | 'ERROR', message: string): void {
        const line = `[StockMarket:${this.botName}] ${message}`;
        if (type === 'ERROR') console.error(line);
        else if (type === 'WARNING') console.warn(line);
        else console.log(line);
        try {
            const dir = path.join(process.cwd(), 'storage', 'logs', 'trading');
            fs.mkdirSync(dir, { recursive: true });
            fs.appendFileSync(path.join(dir, `${this.botName}.log`), `[${new Date().toISOString()}] [${type}] ${message}\n`);
        } catch { /* observability cannot stop the trading loop */ }
    }
}
