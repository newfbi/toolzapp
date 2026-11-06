import fs from 'fs';
import path from 'path';
import { BatchOperations } from '../../lib/BatchOperations';
import {
    applyManagedFutureRuntimeSettings,
    resolveManagedFuturesMarginMode,
    resolveManagedFuturesProductType,
} from '../../exchange/ManagedFuturesRuntime';
import { canal, GoldTeamEngine, GoldTeamEngineState } from './core/GoldTeamEngine';
import { CryptoBrokerAdapter, GoldTeamRuntimeState } from './core/CryptoBrokerAdapter';
import {
    CONFIG_PADRAO,
    GoldTeamCandle,
    GoldTeamConfig,
    GoldTeamConfigInput,
    normalizeGoldTeamConfig,
} from './config/GoldTeamConfig';

type LoggerLike = {
    info: (msg: string) => void;
    warn: (msg: string) => void;
    error: (msg: string) => void;
};

const H1_MS = 60 * 60 * 1000;
const H8_MS = 8 * H1_MS;

export class GoldTeamTradingService {
    private logger: LoggerLike;
    private h1Cache: { key: string; loadedAt: number; candles: GoldTeamCandle[] } | null = null;
    private lastDiagnosticLogAt = 0;
    private lastDiagnosticH1 = 0;
    private lastDiagnosticH8 = 0;

    constructor(
        private botName: string,
        private maxOperations: number,
        private botId?: number,
        private dbConfig?: any,
        private signalClient?: any,
        private batchOps?: BatchOperations
    ) {
        void this.dbConfig;
        void this.signalClient;
        this.logger = {
            info: (msg) => this.writeLog('INFO', msg),
            warn: (msg) => this.writeLog('WARNING', msg),
            error: (msg) => this.writeLog('ERROR', msg),
        };
    }

    async executeIteration(
        client: any,
        config: any,
        state: any,
        balances: any,
        symbolInfo: any,
        tickerInfo: any,
        currentIndicators: any
    ): Promise<any> {
        void balances;
        const now = Date.now();
        const runtimeState = ensureGoldTeamRuntimeState(state);
        runtimeState.lastAction = countOpenPositions(state) > 0 ? 'holding' : 'waiting';
        let goldTeamConfig: GoldTeamConfig;
        try {
            goldTeamConfig = resolveGoldTeamConfig(config);
        } catch (error: any) {
            runtimeState.lastAction = 'error';
            runtimeState.lastError = error?.message || String(error);
            this.logger.warn(`[GOLDTEAM] Configuracao Crypto rejeitada: ${runtimeState.lastError}`);
            return this.result('error', 0, state, CONFIG_PADRAO, runtimeState);
        }
        const currentPrice = resolveCurrentPrice(tickerInfo, currentIndicators);
        const isReal = String(config?.opType || 'demo').toLowerCase() === 'real';

        config.botId = config.botId ?? this.botId;
        config.maxOperations = Math.max(1, Number(config.maxOperations || this.maxOperations || goldTeamConfig.maxOpenPositions));
        config.marginMode = normalizeMarginMode(config.marginMode);
        // GoldTeam does not maintain pending maker orders. Canonicalize legacy maker
        // values before both Demo simulation and Real payload construction.
        config.openExecutionMode = goldTeamConfig.openExecutionMode;
        config.closeExecutionMode = goldTeamConfig.closeExecutionMode;
        config.runtimeOpenExecutionMode = goldTeamConfig.openExecutionMode;
        config.runtimeCloseExecutionMode = goldTeamConfig.closeExecutionMode;
        config.runtimeMakerPostOnly = false;

        if (!(currentPrice > 0)) {
            runtimeState.lastAction = 'waiting_price';
            runtimeState.lastError = 'Preco atual indisponivel';
            this.logOperationalSnapshot({
                now,
                action: 'loading',
                currentPrice: 0,
                state,
                config: goldTeamConfig,
                runtimeState,
                candlesH1: [],
                candlesH8: [],
            });
            return this.result('loading', 0, state, goldTeamConfig, runtimeState);
        }

        if (isReal) {
            runtimeState.managedFutureSettings = await applyManagedFutureRuntimeSettings({
                exchange: config.exchange,
                isReal,
                isFuturePerpetualTrade: true,
                client,
                config,
                state: runtimeState.managedFutureSettings,
                unwrapResponsePayload,
                logger: this.logger,
                resolveProductType: resolveManagedFuturesProductType,
                resolveMarginMode: resolveManagedFuturesMarginMode,
            });
        }

        const candlesH1 = await this.loadClosedH1Candles(client, config, goldTeamConfig, now);
        const requiredFimatheH1 = Math.max(
            goldTeamConfig.fPeriodoCR + 2,
            goldTeamConfig.fTrendFilterOn
                ? goldTeamConfig.fTrendPeriod + goldTeamConfig.fTrendSlopeLookback
                : 0
        );
        const requiredH1 = goldTeamConfig.fimatheOn
            ? requiredFimatheH1
            : (goldTeamConfig.tPeriodoN + goldTeamConfig.tAtrPer + 2) * 8;
        if (candlesH1.length < requiredH1) {
            runtimeState.lastAction = 'warming_up';
            runtimeState.lastError = `Historico H1 insuficiente (${candlesH1.length}/${requiredH1})`;
            runtimeState.lastCandleCountH1 = candlesH1.length;
            this.logOperationalSnapshot({
                now,
                action: 'loading',
                currentPrice,
                state,
                config: goldTeamConfig,
                runtimeState,
                candlesH1,
                candlesH8: [],
            });
            return this.result('loading', currentPrice, state, goldTeamConfig, runtimeState);
        }

        runtimeState.lastError = '';
        const candlesH8 = aggregateCandles(candlesH1, H8_MS, now);
        const requiredH8 = goldTeamConfig.tPeriodoN + goldTeamConfig.tAtrPer + 2;
        if (goldTeamConfig.turtleOn && candlesH8.length < requiredH8) {
            runtimeState.lastError = `Historico H8 insuficiente (${candlesH8.length}/${requiredH8})`;
        } else {
            runtimeState.lastError = '';
        }

        const broker = new CryptoBrokerAdapter({
            client,
            config,
            goldTeamConfig,
            state,
            runtimeState,
            currentPrice,
            symbolInfo,
            tickerInfo,
            isReal,
            batchOps: this.batchOps,
            logger: this.logger,
        });
        broker.setPreco(currentPrice);
        await broker.closeLiquidationRiskPositions();

        const engine = new GoldTeamEngine({
            config: goldTeamConfig,
            mode: isReal ? 'live' : 'backtest',
            broker,
            state: runtimeState.engineState as GoldTeamEngineState | undefined,
        });

        // Entradas e atualizacao do trailing usam candles fechados; o disparo do stop
        // usa o ticker atual em toda iteracao para nao esperar mais 1h/8h.
        await engine.tick(now, candlesH8, candlesH1, currentPrice);
        if (runtimeState.lastAction !== 'error') {
            runtimeState.lastError = '';
        }
        if (countOpenPositions(state) <= 0 && /^close_/.test(String(runtimeState.lastAction || ''))) {
            runtimeState.lastAction = 'waiting';
        }
        runtimeState.engineState = engine.snapshot();
        runtimeState.lastStatus = engine.status();
        runtimeState.lastPrice = currentPrice;
        runtimeState.lastCandleCountH1 = candlesH1.length;
        runtimeState.lastCandleCountH8 = candlesH8.length;
        runtimeState.updatedAt = now;
        runtimeState.positionDiagnostics = broker.positionDiagnostics(runtimeState.engineState);
        runtimeState.floatingNet = runtimeState.positionDiagnostics.reduce(
            (sum, position) => sum + Number(position.unrealizedNet || 0),
            0
        );
        runtimeState.entryGate = runtimeState.positionDiagnostics.length >= goldTeamConfig.maxOpenPositions
            ? 'capacity_full'
            : 'available';

        this.logOperationalSnapshot({
            now,
            action: runtimeState.lastAction || 'waiting',
            currentPrice,
            state,
            config: goldTeamConfig,
            runtimeState,
            candlesH1,
            candlesH8,
        });

        state.marginLevel = Number(state.marginLevel || 0);
        state.borrowedAmount = Number(state.borrowedAmount || 0);
        return this.result(runtimeState.lastAction || 'waiting', currentPrice, state, goldTeamConfig, runtimeState);
    }

    async shutdown(
        client: any,
        config: any,
        state: any,
        options?: { finalCloseOpenPositions?: boolean }
    ): Promise<{ canceledOrders: number; closedPositions: boolean; residualPositions: number }> {
        void client;
        const forceClose = options?.finalCloseOpenPositions === true || config?.finalCloseOpenPositions === true;
        if (!forceClose) {
            return {
                canceledOrders: 0,
                closedPositions: false,
                residualPositions: countOpenPositions(state),
            };
        }
        this.logger.warn('[GOLDTEAM] Shutdown com fechamento forcado nao executado automaticamente; preserve/feche via fluxo operacional normal.');
        return {
            canceledOrders: 0,
            closedPositions: false,
            residualPositions: countOpenPositions(state),
        };
    }

    private result(
        action: string,
        lastPrice: number,
        state: any,
        cfg: GoldTeamConfig,
        runtimeState: GoldTeamRuntimeState
    ): any {
        return {
            action,
            lastPrice,
            totalProfit: Number(state?.totalProfit || 0),
            openPositions: countOpenPositions(state),
            status: runtimeState.lastStatus || '',
            risk: {
                currentEquity: Number(state?.currentEquity || 0),
                marginUsed: Number(state?.marginUsed || 0),
                marginUsedPct: Number(state?.marginUsedPct || 0),
                marginLevel: Number(state?.marginLevel || 0),
                borrowedAmount: Number(state?.borrowedAmount || 0),
                liquidationCount: Number(runtimeState.liquidationCount || 0),
                feeTotal: Number(runtimeState.feeTotal || 0),
                slippageTotal: Number(runtimeState.slippageTotal || 0),
                floatingNet: Number(runtimeState.floatingNet || 0),
                entryGate: runtimeState.entryGate || 'available',
                positions: runtimeState.positionDiagnostics || [],
            },
            config: {
                bankroll: cfg.bankroll,
                capTurtle: cfg.capTurtle,
                capFimathe: cfg.capFimathe,
                turtleOn: cfg.turtleOn,
                fimatheOn: cfg.fimatheOn,
                maxOpenPositions: cfg.maxOpenPositions,
                leverage: cfg.leverage,
                marginMode: cfg.marginMode,
                sizingMode: cfg.sizingMode,
                turtleMarginUsagePct: cfg.turtleMarginUsagePct,
                fimatheMarginUsagePct: cfg.fimatheMarginUsagePct,
                turtleRiskPerTradePct: cfg.turtleRiskPerTradePct,
                fimatheRiskPerTradePct: cfg.fimatheRiskPerTradePct,
                fTrendFilterOn: cfg.fTrendFilterOn,
                fTrendPeriod: cfg.fTrendPeriod,
                fTrendSlopeLookback: cfg.fTrendSlopeLookback,
                openExecutionMode: cfg.openExecutionMode,
                closeExecutionMode: cfg.closeExecutionMode,
                runtimeMakerPostOnly: cfg.runtimeMakerPostOnly,
                takerFeeRate: cfg.defaultTakerFeeRate,
                makerFeeRate: cfg.makerFeeRate,
                slippagePercent: cfg.slippagePercent,
                makerSlippagePercent: cfg.makerSlippagePercent,
                quantityUnit: 'base_asset',
                quantityStep: cfg.quantityStep,
                minOrderQty: cfg.minOrderQty,
                minNotional: cfg.minNotional,
                allowOppositeEnginePositions: cfg.allowOppositeEnginePositions,
                requireFreshBreakout: cfg.requireFreshBreakout,
                resolvedQuantityRules: runtimeState.quantityRules || null,
            },
        };
    }

    private async loadClosedH1Candles(
        client: any,
        config: any,
        cfg: GoldTeamConfig,
        now: number
    ): Promise<GoldTeamCandle[]> {
        const inline = normalizeInlineCandles(config?.goldTeamCandlesH1 ?? config?.candlesH1);
        if (inline.length > 0) return inline;

        if (!client?.Market || typeof client.Market.candles !== 'function') {
            return [];
        }

        const limit = Math.min(1000, Math.max(120, Number(cfg.historyLimitH1 || 900)));
        const cacheKey = `${String(config.exchange || '')}:${String(config.market || '')}:${limit}`;
        const currentH1Bucket = Math.floor(now / H1_MS);
        const cacheH1Bucket = this.h1Cache ? Math.floor(this.h1Cache.loadedAt / H1_MS) : -1;
        if (
            this.h1Cache
            && this.h1Cache.key === cacheKey
            && (now - this.h1Cache.loadedAt) < 5 * 60 * 1000
            && this.h1Cache.candles.length > 0
            && cacheH1Bucket === currentH1Bucket
        ) {
            return this.h1Cache.candles;
        }
        const startTime = now - (limit + 4) * H1_MS;
        const payload: Record<string, any> = {
            symbol: config.market,
            granularity: toExchangeH1Granularity(config.exchange),
            startTime,
            endTime: now,
            limit,
        };
        if (isFutureConfig(config)) {
            payload.productType = resolveManagedFuturesProductType(config);
        }

        try {
            const response = await client.Market.candles(payload);
            const data = await unwrapResponsePayload(response);
            const candles = normalizeExchangeCandles(data)
                .filter((candle) => candle.time + H1_MS <= now + 1000)
                .slice(-limit);
            this.h1Cache = { key: cacheKey, loadedAt: now, candles };
            return candles;
        } catch (error: any) {
            this.logger.warn(`[GOLDTEAM] Falha ao carregar candles H1: ${error?.message || error}`);
            if (this.h1Cache?.candles?.length) {
                return this.h1Cache.candles;
            }
            return [];
        }
    }

    private writeLog(type: 'INFO' | 'WARNING' | 'ERROR', message: string): void {
        const prefix = `[GoldTeam:${this.botName}] ${message}`;
        if (type === 'ERROR') console.error(prefix);
        else if (type === 'WARNING') console.warn(prefix);
        else console.log(prefix);

        try {
            const logDir = path.join(process.cwd(), 'storage', 'logs', 'trading');
            fs.mkdirSync(logDir, { recursive: true });
            const logFile = path.join(logDir, `${this.botName}.log`);
            fs.appendFileSync(logFile, `[${new Date().toISOString()}] [${type}] ${message}\n`);
        } catch {
            // Best-effort observability only; trading must not stop because log persistence failed.
        }
    }

    private logOperationalSnapshot(params: {
        now: number;
        action: string;
        currentPrice: number;
        state: any;
        config: GoldTeamConfig;
        runtimeState: GoldTeamRuntimeState;
        candlesH1: GoldTeamCandle[];
        candlesH8: GoldTeamCandle[];
    }): void {
        const lastH1 = params.candlesH1.length ? Number(params.candlesH1[params.candlesH1.length - 1].time || 0) : 0;
        const lastH8 = params.candlesH8.length ? Number(params.candlesH8[params.candlesH8.length - 1].time || 0) : 0;
        const shouldLog =
            params.action !== 'waiting'
            || (params.now - this.lastDiagnosticLogAt) >= 5 * 60 * 1000
            || lastH1 !== this.lastDiagnosticH1
            || lastH8 !== this.lastDiagnosticH8;
        if (!shouldLog) return;

        this.lastDiagnosticLogAt = params.now;
        this.lastDiagnosticH1 = lastH1;
        this.lastDiagnosticH8 = lastH8;

        this.logger.info(
            `[GOLDTEAM STATUS] action=${params.action} price=${formatNumber(params.currentPrice)} ` +
            `open=${countOpenPositions(params.state)} ` +
            `entryGate=${params.runtimeState.entryGate || 'available'} ` +
            `floatingNet=${formatSignedNumber(params.runtimeState.floatingNet)} ` +
            `positions=${formatPositionDiagnostics(params.runtimeState.positionDiagnostics)} ` +
            `H1=${params.candlesH1.length} lastH1=${formatTimestamp(lastH1)} ` +
            `${describeFimatheSignal(params.candlesH1, params.config)} ` +
            `H8=${params.candlesH8.length} lastH8=${formatTimestamp(lastH8)} ` +
            `${describeTurtleSignal(params.candlesH8, params.config)} ` +
            `qtyRules=${formatQuantityRules(params.runtimeState.quantityRules)} ` +
            `status=${compactLogText(params.runtimeState.lastStatus || 'none')} ` +
            `error=${compactLogText(params.runtimeState.lastError || 'none')}`
        );
    }
}

function formatSignedNumber(value: unknown): string {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return 'n/a';
    return `${parsed >= 0 ? '+' : ''}${parsed.toFixed(4)}`;
}

function formatPositionDiagnostics(positions: GoldTeamRuntimeState['positionDiagnostics']): string {
    if (!Array.isArray(positions) || positions.length === 0) return 'none';
    return positions.map((position) => {
        const stop = position.stopPrice == null ? 'none' : formatNumber(position.stopPrice);
        const distance = position.distanceToStop == null ? 'none' : formatNumber(position.distanceToStop);
        return [
            `${position.tag}:${position.side}`,
            `id=${compactLogText(position.id)}`,
            `qty=${formatNumber(position.quantity)}`,
            `entry=${formatNumber(position.entryPrice)}`,
            `stop=${stop}`,
            `stopDist=${distance}`,
            `net=${formatSignedNumber(position.unrealizedNet)}`,
            `profitPeak=${position.profitPeakUsd == null ? 'none' : formatSignedNumber(position.profitPeakUsd)}`,
            `profitLock=${position.profitLockUsd == null ? 'none' : formatSignedNumber(position.profitLockUsd)}`,
            `profitIdleMin=${position.profitLockStagnationMinutes == null ? 'none' : Math.round(position.profitLockStagnationMinutes)}`,
            `ageMin=${Math.round(position.ageMinutes)}`,
        ].join(',');
    }).join('|');
}

function formatQuantityRules(rules: any): string {
    if (!rules || typeof rules !== 'object') return 'unresolved';
    return [
        `unit=${String(rules.quantityUnit || 'base_asset')}`,
        `step=${formatNumber(Number(rules.quantityStep || 0))}(${String(rules.quantityStepSource || 'missing')})`,
        `minQty=${formatNumber(Number(rules.minOrderQty || 0))}(${String(rules.minOrderQtySource || 'missing')})`,
        `minNotional=${formatNumber(Number(rules.minNotionalUsd || 0))}(${String(rules.minNotionalSource || 'missing')})`,
    ].join(',');
}

function resolveGoldTeamConfig(config: any): GoldTeamConfig {
    const read = (camel: string, snake?: string) => config?.[camel] ?? (snake ? config?.[snake] : undefined);
    return normalizeGoldTeamConfig({
        capTurtle: Number(read('capTurtle', 'cap_turtle') ?? undefined),
        capFimathe: Number(read('capFimathe', 'cap_fimathe') ?? undefined),
        turtleOn: readBool(read('turtleOn', 'turtle_on'), true),
        tPeriodoN: Number(read('tPeriodoN', 't_periodo_n') ?? undefined),
        tAtrPer: Number(read('tAtrPer', 't_atr_per') ?? undefined),
        tTrailMult: Number(read('tTrailMult', 't_trail_mult') ?? undefined),
        turtleRiskPerTradePct: Number(read('turtleRiskPerTradePct', 'turtle_risk_per_trade_pct') ?? undefined),
        tBracket: Array.isArray(config?.tBracket) ? config.tBracket : config?.t_bracket,
        fimatheOn: readBool(read('fimatheOn', 'fimathe_on'), true),
        fPeriodoCR: Number(read('fPeriodoCR', 'f_periodo_cr') ?? undefined),
        fEsticadoMaxCR: Number(read('fEsticadoMaxCR', 'f_esticado_max_cr') ?? undefined),
        fStopCR: Number(read('fStopCR', 'f_stop_cr') ?? undefined),
        fFolgaCR: Number(read('fFolgaCR', 'f_folga_cr') ?? undefined),
        fimatheRiskPerTradePct: Number(read('fimatheRiskPerTradePct', 'fimathe_risk_per_trade_pct') ?? undefined),
        fTrendFilterOn: readBool(read('fTrendFilterOn', 'f_trend_filter_on'), true),
        fTrendPeriod: Number(read('fTrendPeriod', 'f_trend_period') ?? undefined),
        fTrendSlopeLookback: Number(read('fTrendSlopeLookback', 'f_trend_slope_lookback') ?? undefined),
        fRegua: Number(read('fRegua', 'f_regua') ?? undefined),
        toxicOn: readBool(read('toxicOn', 'toxic_on'), true),
        toxic1: read('toxic1', 'toxic_1'),
        toxic2SextaOnly: read('toxic2SextaOnly', 'toxic_2_sexta_only'),
        quantityStep: Number(read('quantityStep', 'quantity_step') ?? undefined),
        minOrderQty: Number(read('minOrderQty', 'min_order_qty') ?? undefined),
        minNotional: Number(
            read('minNotional', 'min_notional')
            ?? read('minNotionalUsd', 'min_notional_usd')
            ?? undefined
        ),
        paperSpread: Number(read('paperSpread', 'paper_spread') ?? undefined),
        bankroll: Number(read('bankroll') ?? undefined),
        leverage: Number(read('leverage') ?? read('leverageDefault', 'leverage_default') ?? undefined),
        marginMode: read('marginMode', 'margin_mode'),
        sizingMode: read('sizingMode', 'sizing_mode'),
        turtleMarginUsagePct: Number(read('turtleMarginUsagePct', 'turtle_margin_usage_pct') ?? undefined),
        fimatheMarginUsagePct: Number(read('fimatheMarginUsagePct', 'fimathe_margin_usage_pct') ?? undefined),
        openExecutionMode: read('openExecutionMode', 'open_execution_mode') ?? read('runtimeOpenExecutionMode', 'runtime_open_execution_mode'),
        closeExecutionMode: read('closeExecutionMode', 'close_execution_mode') ?? read('runtimeCloseExecutionMode', 'runtime_close_execution_mode'),
        runtimeMakerPostOnly: read('runtimeMakerPostOnly', 'runtime_maker_post_only') ?? read('makerPostOnly', 'maker_post_only'),
        defaultTakerFeeRate: Number(read('defaultTakerFeeRate', 'default_taker_fee_rate') ?? read('takerFeeRate', 'taker_fee_rate') ?? undefined),
        makerFeeRate: Number(read('makerFeeRate', 'maker_fee_rate') ?? undefined),
        slippagePercent: Number(read('slippagePercent', 'slippage_percent') ?? read('estimatedSlippagePercent', 'estimated_slippage_percent') ?? undefined),
        makerSlippagePercent: Number(read('makerSlippagePercent', 'maker_slippage_percent') ?? undefined),
        slippageModel: read('slippageModel', 'slippage_model'),
        defaultDailyInterestRate: Number(read('defaultDailyInterestRate', 'default_daily_interest_rate') ?? undefined),
        maintenanceMarginRate: Number(read('maintenanceMarginRate', 'maintenance_margin_rate') ?? undefined),
        liquidationPenaltyPct: Number(read('liquidationPenaltyPct', 'liquidation_penalty_pct') ?? undefined),
        liquidationBufferPct: Number(read('liquidationBufferPct', 'liquidation_buffer_pct') ?? undefined),
        maxInitialMarginUsagePct: Number(read('maxInitialMarginUsagePct', 'max_initial_margin_usage_pct') ?? read('maxMarginUsedPct', 'max_margin_used_pct') ?? undefined),
        minEquityForNewEntries: Number(read('minEquityForNewEntries', 'min_equity_for_new_entries') ?? undefined),
        maxOpenPositions: Number(read('maxOpenPositions', 'max_open_positions') ?? config?.maxOperations ?? undefined),
        allowOppositeEnginePositions: readBool(
            read('allowOppositeEnginePositions', 'allow_opposite_engine_positions'),
            false
        ),
        profitLockEnabled: readBool(read('profitLockEnabled', 'profit_lock_enabled'), true),
        profitLockActivationUsd: Number(read('profitLockActivationUsd', 'profit_lock_activation_usd') ?? undefined),
        profitLockFloorUsd: Number(read('profitLockFloorUsd', 'profit_lock_floor_usd') ?? undefined),
        profitLockTrailStartUsd: Number(read('profitLockTrailStartUsd', 'profit_lock_trail_start_usd') ?? undefined),
        profitLockGivebackUsd: Number(read('profitLockGivebackUsd', 'profit_lock_giveback_usd') ?? undefined),
        profitLockProgressStepUsd: Number(read('profitLockProgressStepUsd', 'profit_lock_progress_step_usd') ?? undefined),
        profitLockStagnationMinutes: Number(
            read('profitLockStagnationMinutes', 'profit_lock_stagnation_minutes') ?? undefined
        ),
        requireFreshBreakout: readBool(read('requireFreshBreakout', 'require_fresh_breakout'), true),
        historyLimitH1: Number(read('historyLimitH1', 'history_limit_h1') ?? undefined),
    } as GoldTeamConfigInput);
}

function ensureGoldTeamRuntimeState(state: any): GoldTeamRuntimeState {
    if (!state || typeof state !== 'object') return {};
    state._institutionalHybridState = (
        state._institutionalHybridState
        && typeof state._institutionalHybridState === 'object'
        && !Array.isArray(state._institutionalHybridState)
    ) ? state._institutionalHybridState : {};
    const parent = state._institutionalHybridState as Record<string, any>;
    parent.goldTeam = (
        parent.goldTeam
        && typeof parent.goldTeam === 'object'
        && !Array.isArray(parent.goldTeam)
    ) ? parent.goldTeam : {};
    if (!Array.isArray(parent.goldTeam.history)) parent.goldTeam.history = [];
    return parent.goldTeam as GoldTeamRuntimeState;
}

function countOpenPositions(state: any): number {
    return (Array.isArray(state?.activePositions) ? state.activePositions.length : 0)
        + (Array.isArray(state?.shortPositions) ? state.shortPositions.length : 0);
}

function resolveCurrentPrice(tickerInfo: any, indicators: any): number {
    const candidates = [
        tickerInfo?.lastPr,
        tickerInfo?.last,
        tickerInfo?.markPrice,
        tickerInfo?.indexPrice,
        tickerInfo?.close,
        indicators?.lastClose,
        indicators?.latestCandle?.close,
    ];
    for (const candidate of candidates) {
        const value = Number(candidate);
        if (Number.isFinite(value) && value > 0) return value;
    }
    return 0;
}

function aggregateCandles(candles: GoldTeamCandle[], bucketMs: number, now: number): GoldTeamCandle[] {
    const buckets = new Map<number, GoldTeamCandle[]>();
    for (const candle of candles) {
        const bucket = Math.floor(Number(candle.time || 0) / bucketMs) * bucketMs;
        if (!(bucket > 0)) continue;
        const list = buckets.get(bucket) || [];
        list.push(candle);
        buckets.set(bucket, list);
    }

    const out: GoldTeamCandle[] = [];
    for (const [bucket, list] of Array.from(buckets.entries()).sort((a, b) => a[0] - b[0])) {
        if (bucket + bucketMs > now + 1000) continue;
        if (list.length < Math.round(bucketMs / H1_MS)) continue;
        const sorted = list.sort((a, b) => a.time - b.time);
        out.push({
            time: bucket + bucketMs,
            open: sorted[0].open,
            high: Math.max(...sorted.map((candle) => candle.high)),
            low: Math.min(...sorted.map((candle) => candle.low)),
            close: sorted[sorted.length - 1].close,
            volume: sorted.reduce((sum, candle) => sum + Number(candle.volume || 0), 0),
        });
    }
    return out;
}

function normalizeExchangeCandles(payload: any): GoldTeamCandle[] {
    const rows = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
            ? payload.data
            : Array.isArray(payload?.data?.list)
                ? payload.data.list
                : Array.isArray(payload?.list)
                    ? payload.list
                    : [];

    return rows
        .map(normalizeCandleRow)
        .filter((candle): candle is GoldTeamCandle => !!candle)
        .sort((a, b) => a.time - b.time);
}

function normalizeInlineCandles(value: any): GoldTeamCandle[] {
    if (!Array.isArray(value)) return [];
    return value
        .map(normalizeCandleRow)
        .filter((candle): candle is GoldTeamCandle => !!candle)
        .sort((a, b) => a.time - b.time);
}

function normalizeCandleRow(row: any): GoldTeamCandle | null {
    const raw = Array.isArray(row)
        ? {
            time: row[0],
            open: row[1],
            high: row[2],
            low: row[3],
            close: row[4],
            volume: row[5],
        }
        : row;
    const time = Number(raw?.time ?? raw?.ts ?? raw?.timestamp ?? raw?.openTime ?? 0);
    const open = Number(raw?.open);
    const high = Number(raw?.high);
    const low = Number(raw?.low);
    const close = Number(raw?.close);
    if (!(time > 0) || !(open > 0) || !(high > 0) || !(low > 0) || !(close > 0)) return null;
    return {
        time,
        open,
        high,
        low,
        close,
        volume: Number(raw?.volume ?? raw?.vol ?? 0),
    };
}

function toExchangeH1Granularity(exchange: any): string {
    const normalized = String(exchange || '').toLowerCase();
    if (normalized.includes('bitget')) return '1H';
    return '1h';
}

function isFutureConfig(config: any): boolean {
    const normalized = String(config?.executionTradeType || config?.tradeType || '').toLowerCase();
    return normalized === 'future'
        || normalized.includes('future')
        || normalized.includes('perp')
        || normalized === 'quantum'
        || normalized === 'marketking'
        || normalized === 'margintrade'
        || normalized === 'goldteam';
}

function normalizeMarginMode(value: any): 'cross' | 'isolated' {
    const normalized = String(value || '').toLowerCase();
    return normalized === 'cross' || normalized === 'crossed' ? 'cross' : 'isolated';
}

function describeFimatheSignal(candles: GoldTeamCandle[], cfg: GoldTeamConfig): string {
    if (!cfg.fimatheOn) return 'fimathe=off';
    const required = cfg.fPeriodoCR + 2;
    if (candles.length < required) return `fimathe=warmup(${candles.length}/${required})`;
    const test = candles[candles.length - 1];
    const { hh, ll } = canal(candles, cfg.fPeriodoCR);
    const cr = hh - ll;
    if (!(cr > 0)) return `fimathe=flat close=${formatNumber(test.close)}`;
    const maxStretch = cfg.fEsticadoMaxCR * cr;
    let signal = 'inside';
    if (test.close > hh) {
        signal = (test.close - hh) <= maxStretch ? 'buy_ready' : 'buy_stretched';
    } else if (test.close < ll) {
        signal = (ll - test.close) <= maxStretch ? 'sell_ready' : 'sell_stretched';
    }
    return `fimathe=${signal} close=${formatNumber(test.close)} hh=${formatNumber(hh)} ll=${formatNumber(ll)} cr=${formatNumber(cr)}`;
}

function describeTurtleSignal(candles: GoldTeamCandle[], cfg: GoldTeamConfig): string {
    if (!cfg.turtleOn) return 'turtle=off';
    const required = cfg.tPeriodoN + cfg.tAtrPer + 2;
    if (candles.length < required) return `turtle=warmup(${candles.length}/${required})`;
    const test = candles[candles.length - 1];
    const { hh, ll } = canal(candles, cfg.tPeriodoN);
    let signal = 'inside';
    if (test.close > hh) signal = 'buy_ready';
    else if (test.close < ll) signal = 'sell_ready';
    return `turtle=${signal} close=${formatNumber(test.close)} hh=${formatNumber(hh)} ll=${formatNumber(ll)}`;
}

function formatNumber(value: unknown): string {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'n/a';
    return numeric.toFixed(4);
}

function formatTimestamp(value: unknown): string {
    const numeric = Number(value);
    if (!(numeric > 0)) return 'n/a';
    return new Date(numeric).toISOString();
}

function compactLogText(value: unknown): string {
    return String(value ?? '')
        .replace(/\s+/g, '_')
        .replace(/[^\w.:/=$%|+-]+/g, '_')
        .slice(0, 180) || 'none';
}

function readBool(value: any, fallback: boolean): boolean {
    if (value === undefined || value === null || value === '') return fallback;
    if (typeof value === 'boolean') return value;
    const normalized = String(value).trim().toLowerCase();
    if (['1', 'true', 'yes', 'sim'].includes(normalized)) return true;
    if (['0', 'false', 'no', 'nao'].includes(normalized)) return false;
    return fallback;
}

async function unwrapResponsePayload(response: any): Promise<any> {
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

export default GoldTeamTradingService;
