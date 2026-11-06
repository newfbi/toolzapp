/**
 * BackTestMarketKing – ponto de entrada do backtest (Bitget/Binance, M5, institutional hybrid).
 * Fluxo: argv → merge preset → buildRuntimeConfig → adapter exchange → bootstrap candles →
 * loadStrategyModule + loadTradingServiceClass → loop por candle (indicators + executeIteration) →
 * finalClose opcional → CSV signals, Pine, summary JSON.
 */
import fs from 'fs';
import path from 'path';
import { pathToFileURL } from 'url';
import { buildRuntimeConfig, parseBoolArg } from './config/BacktestRuntimeConfig.ts';
import { createExchangeAdapter, normalizeExchangeName } from './exchange/ExchangeFactory.ts';
import { createFeePolicy } from './exchange/fees/FeePolicyFactory.ts';
import { parseCsvCandles } from './exchange/types.ts';
import { loadStrategyModule } from './factories/StrategyModuleFactory.ts';
import { loadTradingServiceClass } from './factories/TradingServiceFactory.ts';
import { computeIndicatorsSlice } from './services/IndicatorEngine.ts';
import { formatCsvSignals } from './services/SignalExport.ts';
import { createBacktestRuntimeContext } from './core/BacktestRuntimeContext.ts';
import { buildBacktestSummary } from './services/SummaryBuilder.ts';
import { persistJsonWithFallback, persistTextWithFallback } from './services/OutputPersistence.ts';
import { computeOperationalSizing } from './config/OperationalSizing.bt.ts';
import { mergeBacktestArgvPassthrough } from '../shared/backtestRuntimePassthrough.ts';
import { isBitgetCompatibleExchange, isKucoinExchange } from '../../exchange/ExchangeRegistry';
import {
    applyFutureFeeSanityFallback,
    isExplicitFeeOverride,
    resolveBacktestFeeDefaults,
    resolveEffectiveFeeRate,
} from '../../exchange/fees/backtestFeeDefaults';
import { applyRequestedSymbolToPreset } from '../shared/PresetSymbolUtils.ts';
import { mergeBacktestPreset } from '../../config/presets/backtestPresetMerge.ts';
import { pruneBacktestOutputDir } from '../tools/pruneBacktestOutputs.ts';

const BOT_OPERATIONS_ROOT = path.resolve(process.cwd(), 'resources', 'js', 'BotOperations', 'Crypto');
const BACKTEST_ROOT = path.join(BOT_OPERATIONS_ROOT, 'scripts', 'BackTestMarketKing');

let Strategies: any = null;
let marketKingRiskManagerBacktestClass: any = null;
const localCandleBootstrapCache = new Map<string, Promise<any>>();

async function loadMarketKingRiskManagerBacktest(): Promise<any> {
    if (marketKingRiskManagerBacktestClass) return marketKingRiskManagerBacktestClass;
    try {
        const href = pathToFileURL(path.join(BACKTEST_ROOT, 'MarketKingRiskManager.bt.ts')).href;
        const mod = await import(href);
        marketKingRiskManagerBacktestClass = mod.MarketKingRiskManagerBacktest || mod.default;
        return marketKingRiskManagerBacktestClass;
    } catch (_) {
        return null;
    }
}

/** Alertas de risco de liquidação no backtest (quando `MarketKingRiskManager.bt.ts` está disponível). */
async function logMarketKingLiquidationRiskWarnings(
    state: any,
    balances: any,
    markPrice: number,
    candleIndex: number,
    verbose: boolean
): Promise<void> {
    const RiskManager = await loadMarketKingRiskManagerBacktest();
    if (!RiskManager) return;
    const openCount = (state.activePositions?.length || 0) + (state.shortPositions?.length || 0);
    if (openCount === 0) return;

    const equity = Number(balances.equity ?? 0);
    const usedMargin = Number(balances.usedMargin ?? balances.marginUsed ?? 0);
    const marginLevel = usedMargin > 0 && isFinite(equity) ? equity / usedMargin : 0;

    const marginMetrics = {
        marginLevel: isFinite(marginLevel) ? marginLevel : 0,
        totalAssetValue: equity,
        borrowedValue: usedMargin,
    };

    const positions: Array<{ positionId?: string; leverage?: number; currentPrice?: number; entryPrice?: number; unrealizedPnL?: number; riskLevel?: string }> = [];
    const longs = Array.isArray(state.activePositions) ? state.activePositions : [];
    const shorts = Array.isArray(state.shortPositions) ? state.shortPositions : [];
    for (const p of longs) {
        const qty = Number(p.quantity ?? 0);
        const entry = Number(p.entryPrice ?? 0);
        const unrealized = (markPrice - entry) * qty;
        positions.push({
            positionId: p.positionId,
            leverage: Number(p.leverage ?? 1),
            currentPrice: markPrice,
            entryPrice: entry,
            unrealizedPnL: unrealized,
            riskLevel: undefined,
        });
    }
    for (const p of shorts) {
        const qty = Number(p.quantity ?? 0);
        const entry = Number(p.entryPrice ?? 0);
        const unrealized = (entry - markPrice) * qty;
        positions.push({
            positionId: p.positionId,
            leverage: Number(p.leverage ?? 1),
            currentPrice: markPrice,
            entryPrice: entry,
            unrealizedPnL: unrealized,
            riskLevel: undefined,
        });
    }

    try {
        const manager = new RiskManager({ criticalMarginLevel: 1.08, warningMarginLevel: 1.2 });
        const alerts = manager.monitorAccountHealth(marginMetrics, positions);
        if (alerts.length === 0) return;
        for (const a of alerts) {
            if (a.severity === 'critical') {
                console.warn(`[backtest][risk][CRITICAL] candle=${candleIndex} ${a.message}${a.suggestedAction ? ` → ${a.suggestedAction}` : ''}`);
            } else if (a.severity === 'warning') {
                console.warn(`[backtest][risk][WARNING] candle=${candleIndex} ${a.message}${a.suggestedAction ? ` → ${a.suggestedAction}` : ''}`);
            } else if (verbose) {
                console.warn(`[backtest][risk][INFO] candle=${candleIndex} ${a.message}`);
            }
        }
    } catch (_) {
        // ignore
    }
}

async function loadPineGenerator() {
    const relativeCandidates = ['./BackTestPineGenerate', './BackTestPineGenerate.ts'];
    const absoluteCandidates = [
        path.join(BACKTEST_ROOT, 'BackTestPineGenerate.ts'),
        path.join(BACKTEST_ROOT, 'BackTestPineGenerate.js'),
        path.join(BACKTEST_ROOT, 'BackTestPineGenerate.cjs'),
    ];

    try {
        const { pathToFileURL } = await import('url');
        for (const abs of absoluteCandidates) {
            try {
                const mod: any = await import(pathToFileURL(abs).href);
                return mod.buildPineScript || mod.default || mod;
            } catch (_) {
                // next candidate
            }
        }
    } catch (_) {
        // no-op
    }

    for (const candidate of relativeCandidates) {
        try {
            const mod: any = await import(candidate);
            return mod.buildPineScript || mod.default || mod;
        } catch (_) {
            // next candidate
        }
    }

    throw new Error('BackTestPineGenerate module not found');
}

export function parseArgs(argv: string[] = process.argv.slice(2)): Record<string, any> {
    const params: Record<string, any> = {};
    for (let i = 0; i < argv.length; i++) {
        const arg = String(argv[i] ?? '').trim();
        if (!arg) continue;

        if (arg.includes('=')) {
            const [rawKey, ...valueParts] = arg.split('=');
            const key = rawKey.replace(/^-+/, '');
            const value = valueParts.join('=');
            if (key && value !== undefined) params[key] = value;
            continue;
        }

        if (/^-{1,2}[a-zA-Z0-9_]/.test(arg)) {
            const key = arg.replace(/^-+/, '');
            const next = String(argv[i + 1] ?? '').trim();
            if (next && !/^-{1,2}[a-zA-Z0-9_]/.test(next) && !next.includes('=')) {
                params[key] = next;
                i++;
            } else {
                params[key] = 'true';
            }
        }
    }
    return params;
}

function restrictCandlesToRequestedWindow<T extends { ts?: any }>(candles: T[], days: number): T[] {
    if (!Array.isArray(candles) || candles.length === 0) return candles;
    const lastTs = Number(candles[candles.length - 1]?.ts || 0);
    const requestedWindowMs = Math.max(1, Number(days || 0)) * 24 * 60 * 60 * 1000;
    if (!(lastTs > 0) || !(requestedWindowMs > 0)) return candles;

    const firstEligibleTs = lastTs - requestedWindowMs + 1;
    const filtered = candles.filter((c) => Number(c?.ts || 0) >= firstEligibleTs);
    return filtered.length > 0 ? filtered : candles;
}

async function loadLocalCandleCsvBootstrap(params: {
    exchange: string;
    symbol: string;
    days: number;
    gran: string;
    executionTradeType: string;
    productType?: string;
    candlesPath: string;
    takerFeeRateOverrideRaw?: any;
    makerFeeRateOverrideRaw?: any;
    slippagePercentOverrideRaw?: any;
}): Promise<{
    exchange: string;
    candles: any[];
    candlesPath: string;
    takerFeeRateDefault: number;
    makerFeeRateDefault: number;
    estimatedSlippagePercent: number;
    feesFromApi: boolean;
    feeSource: string;
    feeApiError: string | null;
    slippageSource: string;
    slippageApiError: string | null;
    estimatedLiquidityQty: number;
    dataProvenance: Record<string, any>;
}> {
    const fullPath = path.isAbsolute(params.candlesPath)
        ? params.candlesPath
        : path.resolve(process.cwd(), params.candlesPath);
    if (!fs.existsSync(fullPath)) {
        throw new Error(`candlesPath not found: ${fullPath}`);
    }
    const stat = fs.statSync(fullPath);
    const cacheKey = JSON.stringify({
        fullPath,
        mtimeMs: Number(stat.mtimeMs || 0),
        exchange: params.exchange,
        symbol: params.symbol,
        days: params.days,
        gran: params.gran,
        executionTradeType: params.executionTradeType,
        productType: params.productType || '',
        takerFeeRateOverrideRaw: params.takerFeeRateOverrideRaw ?? null,
        makerFeeRateOverrideRaw: params.makerFeeRateOverrideRaw ?? null,
        slippagePercentOverrideRaw: params.slippagePercentOverrideRaw ?? null,
    });
    const cached = localCandleBootstrapCache.get(cacheKey);
    if (cached) return await cached;

    const loadPromise = (async () => {
        const csv = await fs.promises.readFile(fullPath, 'utf8');
        const parsedCandles = parseCsvCandles(csv).sort((a, b) => Number(a.ts || 0) - Number(b.ts || 0));
        if (!parsedCandles.length) {
            throw new Error(`candlesPath has no readable candles: ${fullPath}`);
        }
        const lastTs = Number(parsedCandles[parsedCandles.length - 1]?.ts || 0);
        const requestedWindowMs = Math.max(1, Number(params.days || 0)) * 24 * 60 * 60 * 1000;
        const firstEligibleTs = lastTs > 0 ? (lastTs - requestedWindowMs + 1) : 0;
        const candles = parsedCandles.filter((c) => Number(c.ts || 0) >= firstEligibleTs);
        if (!candles.length) {
            throw new Error(`candlesPath does not cover requested window: ${fullPath}`);
        }

        const feePolicy = createFeePolicy(params.exchange);
        const feeDefaults = resolveBacktestFeeDefaults(params.exchange, params.executionTradeType);
        const normalizedFeeExchange = String(params.exchange || '').trim().toLowerCase();
        const feeBaseUrl = isKucoinExchange(params.exchange)
            ? (process.env.KUCOIN_SPOT_BASE_URL || 'https://api.kucoin.com')
            : normalizedFeeExchange.includes('bingx')
                ? (process.env.BINGX_MARKET_BASE_URL || 'https://open-api.bingx.com')
                : (process.env.BITGET_BASE_URL || 'https://api.bitget.com');
        /** Defaults alinhados com live; override so quando CLI/preset > 0. */
        const hasTakerOverride = isExplicitFeeOverride(params.takerFeeRateOverrideRaw);
        const hasMakerOverride = isExplicitFeeOverride(params.makerFeeRateOverrideRaw);
        // Explicit fee overrides make a CSV backtest deterministic and avoid
        // a needless exchange request (important for offline/reproducible runs).
        const feeResolution = hasTakerOverride && hasMakerOverride
            ? {
                takerFeeRateDefault: Number(params.takerFeeRateOverrideRaw),
                makerFeeRateDefault: Number(params.makerFeeRateOverrideRaw),
                feesFromApi: false,
                feeSource: 'explicit_cli_override',
                feeApiError: null,
            }
            : await feePolicy.resolve({
                symbol: params.symbol,
                executionTradeType: params.executionTradeType,
                productType: params.productType,
                takerFeeRateOverrideRaw: hasTakerOverride ? params.takerFeeRateOverrideRaw : undefined,
                makerFeeRateOverrideRaw: hasMakerOverride ? params.makerFeeRateOverrideRaw : undefined,
                defaultTakerFeeRate: feeDefaults.taker,
                defaultMakerFeeRate: feeDefaults.maker,
                baseUrl: feeBaseUrl,
            });

        let estimatedSlippagePercent = (isBitgetCompatibleExchange(params.exchange) || isKucoinExchange(params.exchange)) && String(params.executionTradeType || '').toLowerCase().includes('future')
            ? 0.00015
            : 0.0008;
        let slippageSource = 'fallback';
        if (params.slippagePercentOverrideRaw !== undefined) {
            const override = Number(params.slippagePercentOverrideRaw);
            if (Number.isFinite(override) && override >= 0) {
                estimatedSlippagePercent = override;
                slippageSource = 'cli_override';
            }
        }

        return {
            exchange: params.exchange,
            candles,
            candlesPath: fullPath,
            takerFeeRateDefault: feeResolution.takerFeeRateDefault,
            makerFeeRateDefault: feeResolution.makerFeeRateDefault,
            estimatedSlippagePercent,
            feesFromApi: feeResolution.feesFromApi,
            feeSource: feeResolution.feeSource,
            feeApiError: feeResolution.feeApiError,
            slippageSource,
            slippageApiError: null,
            estimatedLiquidityQty: 0,
            dataProvenance: {
                sourceType: 'local_candle_csv',
                candlesPath: fullPath,
                granularity: params.gran,
                firstTs: Number(candles[0]?.ts || 0),
                lastTs: Number(candles[candles.length - 1]?.ts || 0),
                candlesCount: candles.length,
                cacheKey,
            },
        };
    })().catch((error) => {
        localCandleBootstrapCache.delete(cacheKey);
        throw error;
    });

    localCandleBootstrapCache.set(cacheKey, loadPromise);
    return await loadPromise;
}

function mergePreset(argv: Record<string, any>) {
    const requestedMode = String(argv.tradeMode ?? argv.tradeType ?? argv.strategyMode ?? '').trim().toLowerCase();
    const module = ['stockmarket', 'stock_market', 'tradfi', 'stock', 'stocks'].includes(requestedMode)
        ? 'stockmarket'
        : 'marketking';
    return mergeBacktestPreset(argv, {
        module,
        applySymbolToPreset: applyRequestedSymbolToPreset,
        parseBool: parseBoolArg,
        strictUnknownAlias: false,
    });
}

function normalizeAmountMode(argv: Record<string, any>) {
    const modeRaw = String(argv.amountMode || '').trim().toLowerCase();
    const amountIsMargin = argv.amountIsMargin !== undefined
        ? ['1', 'true', 'yes'].includes(String(argv.amountIsMargin).trim().toLowerCase())
        : (modeRaw ? modeRaw !== 'notional' : true);
    return amountIsMargin;
}

function readPositiveNumberFromArgs(argv: Record<string, any>, keys: string[]): number | null {
    for (const key of keys) {
        const value = Number(argv?.[key]);
        if (Number.isFinite(value) && value > 0) return value;
    }
    return null;
}

function buildBacktestOperationalSizingInput(
    argv: Record<string, any>,
    bankroll: number,
    leverageInput: number,
    requestedAmountInput: number,
    enabled: boolean
) {
    const capitalPerAmountUsd = readPositiveNumberFromArgs(argv, [
        'balanceSizingCapitalPerAmountUsd',
    ]);
    const maxEnvelopeUsagePct = readPositiveNumberFromArgs(argv, [
        'balanceSizingMaxEnvelopeUsagePct',
    ]);
    const referenceEquityUsd = readPositiveNumberFromArgs(argv, [
        'balanceSizingReferenceEquityUsd',
    ]) ?? bankroll;
    const referenceAmountUsd = readPositiveNumberFromArgs(argv, [
        'balanceSizingReferenceAmount',
    ]) ?? requestedAmountInput;

    return {
        enabled,
        configuredAmountUsd: requestedAmountInput,
        currentEquityUsd: bankroll,
        availableQuoteUsd: bankroll,
        leverage: leverageInput,
        gridTakeProfitPercent: 0,
        redExitNetPercent: 0,
        capitalSource: String(
            argv.balanceSizingCapitalSource
            ?? 'equity'
        ).trim().toLowerCase() as any,
        capitalPerAmountUsd: capitalPerAmountUsd ?? 0,
        maxEnvelopeUsagePct: maxEnvelopeUsagePct ?? 0,
        referenceEquityUsd,
        referenceAmountUsd,
        reserveFactor: Number(
            argv.balanceSizingReserveFactor
            ?? 1
        ),
        amountStepUsd: Number(
            argv.balanceSizingAmountStep
            ?? 0
        ),
        minAmountUsd: Number(
            argv.balanceSizingSoftMinAmount
            ?? argv.balanceSizingSoftMinAmountUsd
            ?? argv.balanceSizingMinAmount
            ?? 0
        ),
        softMinAmountUsd: Number(
            argv.balanceSizingSoftMinAmount
            ?? argv.balanceSizingSoftMinAmountUsd
            ?? argv.balanceSizingMinAmount
            ?? 0
        ),
        hardMinAmountUsd: Number(
            argv.balanceSizingHardMinAmount
            ?? argv.balanceSizingHardMinAmountUsd
            ?? 0
        ),
        adaptiveMinRatio: Number(
            argv.balanceSizingAdaptiveMinRatio
            ?? argv.balanceSizingAdaptiveFloorRatio
            ?? 0
        ),
        adaptiveStepEnabled: argv.balanceSizingAdaptiveStepEnabled
            ?? argv.balanceSizingAdaptiveAmountStep
            ?? argv.balanceSizingDynamicStepEnabled
            ?? null,
        maxAmountUsd: Number(
            argv.balanceSizingMaxAmount
            ?? 0
        ),
        envelopeMode: String(
            argv.balanceSizingEnvelopeMode
            ?? 'single_red_stress'
        ).trim().toLowerCase() as any,
        martingaleMaxLevels: Number(argv.levelsPerSide ?? 2),
        martingaleFactor: Math.max(0, Number(argv.levelSizeMultiplier ?? 1.2) - 1),
        martingaleFactorLevels: [],
        redHedgeMultiplier: 1,
        managedCycleEnabled: !!argv.managedCycleModeEnabled,
        managedCycleMaxLevels: Number(argv.cycleMartingaleMaxLevels ?? 0),
        managedCycleMultiplier: Number(argv.cycleMartingaleMultiplier ?? 1.5),
        managedCycleAdditiveSteps: argv.cycleMartingaleAdditiveSteps ?? argv.cycle_martingale_additive_steps,
        managedCycleRebaseEnabled: argv.cycleRebaseEnabled !== false,
        managedCycleRebaseMultiplier: Number(
            argv.cycleRebaseMultiplier
            ?? argv.cycleMartingaleMultiplier
            ?? 1.5
        ),
    };
}

function candleIntervalMs(gran: string): number {
    const m = /^\s*(\d+)\s*(m|min|h|d)?\s*$/i.exec(String(gran || '5m').replace('min', 'm'));
    if (!m) return 5 * 60 * 1000;
    const value = Math.max(1, Number(m[1] || 5));
    const unit = String(m[2] || 'm').toLowerCase();
    if (unit === 'h') return value * 60 * 60 * 1000;
    if (unit === 'd') return value * 24 * 60 * 60 * 1000;
    return value * 60 * 1000;
}

/** Agrupa ticks de uma barra em micro-barras OHLC por janela de tempo (simula gráfico mais real). */
type ReplayTick = { ts: number; price: number; volume?: number; bid?: number; ask?: number };
type MicroBar = {
    ts: number;
    open: number;
    high: number;
    low: number;
    close: number;
    bestBid: number;
    bestAsk: number;
    volume: number;
    tickCount: number;
};

function aggregateTicksToMicroBars(
    ticksInBar: ReplayTick[],
    barStartTs: number,
    groupMs: number
): MicroBar[] {
    if (ticksInBar.length === 0) return [];
    const sorted = [...ticksInBar].sort((a, b) => a.ts - b.ts);
    const buckets = new Map<number, {
        open: number;
        high: number;
        low: number;
        close: number;
        bestBid: number | null;
        bestAsk: number | null;
        volume: number;
        tickCount: number;
    }>();
    for (const t of sorted) {
        const bucketStart = barStartTs + Math.floor((t.ts - barStartTs) / groupMs) * groupMs;
        const price = Number(t.price);
        const bid = Number(t.bid);
        const ask = Number(t.ask);
        const volume = Math.max(0, Number(t.volume || 0));
        if (!buckets.has(bucketStart)) {
            buckets.set(bucketStart, {
                open: price,
                high: price,
                low: price,
                close: price,
                bestBid: isFinite(bid) && bid > 0 ? bid : null,
                bestAsk: isFinite(ask) && ask > 0 ? ask : null,
                volume,
                tickCount: 1,
            });
            continue;
        }
        const bucket = buckets.get(bucketStart)!;
        bucket.high = Math.max(bucket.high, price);
        bucket.low = Math.min(bucket.low, price);
        bucket.close = price;
        if (isFinite(bid) && bid > 0) bucket.bestBid = bid;
        if (isFinite(ask) && ask > 0) bucket.bestAsk = ask;
        bucket.volume += volume;
        bucket.tickCount += 1;
    }
    const result: MicroBar[] = [];
    for (const bucketStart of [...buckets.keys()].sort((a, b) => a - b)) {
        const bucket = buckets.get(bucketStart)!;
        result.push({
            ts: bucketStart,
            open: bucket.open,
            high: bucket.high,
            low: bucket.low,
            close: bucket.close,
            bestBid: bucket.bestBid != null ? bucket.bestBid : bucket.close,
            bestAsk: bucket.bestAsk != null ? bucket.bestAsk : bucket.close,
            volume: bucket.volume,
            tickCount: bucket.tickCount,
        });
    }
    return result;
}

function groupTicksByBar(ticks: ReplayTick[], intervalMs: number): Map<number, ReplayTick[]> {
    const grouped = new Map<number, ReplayTick[]>();
    for (const tick of ticks) {
        const bucketStart = Math.floor(Number(tick.ts) / intervalMs) * intervalMs;
        if (!grouped.has(bucketStart)) grouped.set(bucketStart, []);
        grouped.get(bucketStart)!.push(tick);
    }
    return grouped;
}

function percentile(sortedValues: number[], pct: number): number {
    if (!Array.isArray(sortedValues) || sortedValues.length === 0) return 0;
    if (sortedValues.length === 1) return Number(sortedValues[0] || 0);
    const clamped = Math.max(0, Math.min(1, pct));
    const index = Math.min(sortedValues.length - 1, Math.max(0, Math.round((sortedValues.length - 1) * clamped)));
    return Number(sortedValues[index] || 0);
}

function resolveBidAsk(price: number, spreadPct: number, bid?: number, ask?: number) {
    const safePrice = Number(price);
    const safeBid = Number(bid);
    const safeAsk = Number(ask);
    if (isFinite(safeBid) && safeBid > 0 && isFinite(safeAsk) && safeAsk > safeBid) {
        return { bestBid: safeBid, bestAsk: safeAsk };
    }
    return {
        bestBid: safePrice * (1 - spreadPct),
        bestAsk: safePrice * (1 + spreadPct),
    };
}

function buildOhlcSubCandleReplayTicks(candle: any, spreadPct: number) {
    const ts = Number(candle?.ts || 0);
    const close = Number(candle?.close || 0);
    const open = Number(candle?.open || close);
    const high = Number(candle?.high || Math.max(open, close));
    const low = Number(candle?.low || Math.min(open, close));
    const green = close >= open;
    const path = green
        ? [
            { price: open, offsetMs: 0 },
            { price: low, offsetMs: 15_000 },
            { price: high, offsetMs: 35_000 },
            { price: close, offsetMs: 55_000 },
        ]
        : [
            { price: open, offsetMs: 0 },
            { price: high, offsetMs: 15_000 },
            { price: low, offsetMs: 35_000 },
            { price: close, offsetMs: 55_000 },
        ];

    // Cada sub-tick carrega o range do segmento desde o ponto anterior do caminho,
    // para que o modelo de fill maker (price_touch via high/low) veja toques intrabar.
    return path.map((step, index) => {
        const price = Number(step.price);
        const safePrice = isFinite(price) && price > 0 ? price : close;
        const prevRaw = index > 0 ? Number(path[index - 1].price) : safePrice;
        const prevPrice = isFinite(prevRaw) && prevRaw > 0 ? prevRaw : safePrice;
        const segHigh = Math.max(prevPrice, safePrice);
        const segLow = Math.min(prevPrice, safePrice);
        const bidAsk = resolveBidAsk(safePrice, spreadPct);
        return {
            bestBid: bidAsk.bestBid,
            bestAsk: bidAsk.bestAsk,
            ts: ts + step.offsetMs,
            open: prevPrice,
            high: segHigh,
            low: segLow,
            close: safePrice,
            last: safePrice,
            markPrice: safePrice,
            mid: safePrice,
        };
    });
}

function computeReplayIntegrity(
    candles: any[],
    ticksByBar: Map<number, ReplayTick[]>,
    tickGroupMs: number,
    enabled: boolean
) {
    if (!enabled) return null;

    const tickCounts = (Array.isArray(candles) ? candles : []).map((c: any) => (ticksByBar.get(Number(c?.ts || 0)) || []).length);
    const barsWithTicks = tickCounts.filter((count) => count > 0);
    const spreadValues = Array.from(ticksByBar.values())
        .flat()
        .map((tick) => {
            const bid = Number(tick.bid);
            const ask = Number(tick.ask);
            return isFinite(bid) && bid > 0 && isFinite(ask) && ask > bid ? (ask - bid) : NaN;
        })
        .filter((value) => isFinite(value) && value >= 0)
        .sort((a, b) => a - b);

    const microBarCounts = tickGroupMs > 0
        ? (Array.isArray(candles) ? candles : []).map((c: any) => aggregateTicksToMicroBars(ticksByBar.get(Number(c?.ts || 0)) || [], Number(c?.ts || 0), tickGroupMs).length)
        : [];
    const microBarsWithData = microBarCounts.filter((count) => count > 0);
    const avg = (values: number[]) => values.length > 0 ? (values.reduce((sum, value) => sum + value, 0) / values.length) : 0;
    const sortedTickCounts = [...barsWithTicks].sort((a, b) => a - b);
    const sortedMicroBarCounts = [...microBarsWithData].sort((a, b) => a - b);
    const avgMicroBarsPerBar = avg(microBarsWithData);

    return {
        tickBarsTotal: tickCounts.length,
        barsWithTicks: barsWithTicks.length,
        totalTicks: Array.from(ticksByBar.values()).reduce((sum, bucket) => sum + bucket.length, 0),
        avgTicksPerBar: Number(avg(barsWithTicks).toFixed(4)),
        medianTicksPerBar: Number(percentile(sortedTickCounts, 0.5).toFixed(4)),
        minTicksPerBar: sortedTickCounts.length > 0 ? sortedTickCounts[0] : 0,
        maxTicksPerBar: sortedTickCounts.length > 0 ? sortedTickCounts[sortedTickCounts.length - 1] : 0,
        tickGroupMs: tickGroupMs > 0 ? tickGroupMs : null,
        avgMicroBarsPerBar: Number(avgMicroBarsPerBar.toFixed(4)),
        medianMicroBarsPerBar: Number(percentile(sortedMicroBarCounts, 0.5).toFixed(4)),
        minMicroBarsPerBar: sortedMicroBarCounts.length > 0 ? sortedMicroBarCounts[0] : 0,
        maxMicroBarsPerBar: sortedMicroBarCounts.length > 0 ? sortedMicroBarCounts[sortedMicroBarCounts.length - 1] : 0,
        degradedReplay: tickGroupMs > 0 && avgMicroBarsPerBar > 0 && avgMicroBarsPerBar < 3,
        observedSpreadAvg: Number(avg(spreadValues).toFixed(8)),
        observedSpreadMedian: Number(percentile(spreadValues, 0.5).toFixed(8)),
        observedSpreadP95: Number(percentile(spreadValues, 0.95).toFixed(8)),
    };
}

function applyBacktestFinalFlatGraceConfig(config: any): any {
    const minNetClose = Math.max(0.01, Number(config?.minNetUsdPerClose || 0));
    const basketMinNet = Math.max(0.01, Number(config?.basketMinNetUsd || 0));
    const unwindFloor = Math.max(0.01, Math.min(minNetClose, basketMinNet));
    const cycleCloseCurrent = Math.max(0, Number(config?.cycleBasketCloseMinNetUsd || 0));
    return {
        ...config,
        managedCycleModeEnabled: true,
        disableNewEntries: true,
        cycleMartingaleStepPercent: 0,
        cycleMartingaleTriggerMinutes: 1,
        cycleRebaseTriggerMinutes: 1,
        cycleBasketCloseMinNetUsd: cycleCloseCurrent > 0 ? Math.min(cycleCloseCurrent, unwindFloor) : unwindFloor,
    };
}

function normalizeRuntimeConfigForPresetShape(config: any): any {
    return { ...(config || {}) };
}

export async function run(rawCliArgsOverride?: Record<string, any> | string[]): Promise<any> {
    try {
        const dotenv = await import('dotenv').catch(() => null);
        if (dotenv && typeof dotenv.config === 'function') dotenv.config();
    } catch (_) {
        // no-op
    }

    const rawCliArgs = Array.isArray(rawCliArgsOverride)
        ? parseArgs(rawCliArgsOverride)
        : (rawCliArgsOverride && typeof rawCliArgsOverride === 'object'
            ? { ...rawCliArgsOverride }
            : parseArgs());
    const presetResolution = mergePreset(rawCliArgs);
    const argv = presetResolution.argv;

    // Padrão bitget (preset e uso atual); binance suportado via exchange=binance.
    const requestedExchange = argv.exchange || argv.platform || 'bitget';
    const exchange = normalizeExchangeName(requestedExchange);
    const symbol = String(argv.symbol || 'XAUTUSDT').toUpperCase();
    const days = Math.max(1, Number(argv.days || 60));
    const gran = String(argv.gran || argv.granularity || '1m').replace('min', 'm');
    const intervalMs = candleIntervalMs(gran);

    const outDir = argv.outputDir || argv.outDir || argv.outdir || path.join(BACKTEST_ROOT, 'output', exchange);
    const bankroll = Math.max(1, Number(argv.bankroll || 250));
    const amountIsMargin = normalizeAmountMode(argv);
    const leverageInput = Math.max(1, Number(argv.leverage || 25));
    const quantOp = Math.max(1, Math.floor(Number(argv.quant_op ?? argv.maxOperations ?? 1)));
    const fromBankrollPerSlot = bankroll / quantOp;
    const requestedAmountInputRaw = Math.max(
        0.01,
        Number(argv.amount || argv.amountPerOp || (Number.isFinite(fromBankrollPerSlot) ? fromBankrollPerSlot : 0) || 45)
    );
    const requestedAmountInput = requestedAmountInputRaw;
    const requestedAmountForSizing = requestedAmountInput;
    argv.leverage = leverageInput;
    const sizingAutoRequested = parseBoolArg(
        argv.autoBalanceSizingEnabled
        ?? argv.balanceSizingEnabled
        ?? argv.autoSizeAmountByBalance
        ?? argv.autoAmountByBalance,
        true
    );
    if (!sizingAutoRequested) {
        console.warn(
            `[backtest][${exchange}] autoBalanceSizingEnabled=false foi solicitado, mas o MarketKingTrade em live opera sempre com sizing por bankroll; forçando auto sizing para manter paridade.`
        );
    }
    const sizingAutoFlag = true;

    const hadLegacyBaseSizingInPreset =
        Object.prototype.hasOwnProperty.call(argv, 'baseNotionalPerOrder')
        || Object.prototype.hasOwnProperty.call(argv, 'baseInitialMarginPerOrder');
    if (hadLegacyBaseSizingInPreset) {
        console.warn(
            `[backtest][${exchange}] ignoring deprecated baseNotionalPerOrder/baseInitialMarginPerOrder from preset; notional and margin will be derived from bankroll-based sizing.`
        );
    }
    delete argv.baseNotionalPerOrder;
    delete argv.baseInitialMarginPerOrder;
    argv.autoBalanceSizingEnabled = sizingAutoFlag;
    argv.balanceSizingEnabled = sizingAutoFlag;
    argv.autoSizeAmountByBalance = sizingAutoFlag;
    argv.autoAmountByBalance = sizingAutoFlag;

    const sizingInputBase = buildBacktestOperationalSizingInput(
        argv,
        bankroll,
        leverageInput,
        requestedAmountForSizing,
        sizingAutoFlag
    );
    const operationalSizing = computeOperationalSizing(sizingInputBase);
    const envelopeRejectUsagePct = readPositiveNumberFromArgs(argv, [
        'balanceSizingRejectAboveEnvelopeUsagePct',
    ]) ?? 100;
    const envelopeUsageExceeded = operationalSizing.projectedEnvelopeInitialMarginPctOfEquity > (envelopeRejectUsagePct + 1e-8);
    if (sizingAutoFlag && envelopeUsageExceeded) {
        throw new Error(
            `[backtest][${exchange}] rejected preset/config: projected envelope initial margin ` +
            `${operationalSizing.projectedEnvelopeInitialMarginPctOfEquity}% exceeds ` +
            `balanceSizingRejectAboveEnvelopeUsagePct=${envelopeRejectUsagePct}%`
        );
    }
    if (!sizingAutoFlag && envelopeUsageExceeded) {
        console.warn(
            `[backtest][${exchange}] auto sizing disabled: projected envelope initial margin ` +
            `${operationalSizing.projectedEnvelopeInitialMarginPctOfEquity}% exceeds ` +
            `balanceSizingRejectAboveEnvelopeUsagePct=${envelopeRejectUsagePct}%, but configured amount will be preserved for live parity.`
        );
    }
    const amountPerOp = operationalSizing.effectiveAmountUsd;

    const amountPerOpNotional = amountIsMargin ? (amountPerOp * leverageInput) : amountPerOp;
    const amountPerOpInitialMargin = amountIsMargin ? amountPerOp : (amountPerOp / leverageInput);
    argv.amount = amountPerOp;
    argv.baseNotionalPerOrder = amountPerOpNotional;
    argv.baseInitialMarginPerOrder = amountPerOpInitialMargin;
    argv.balanceSizingReferenceEquityUsd = Number(
        argv.balanceSizingReferenceEquityUsd
        ?? bankroll
    );
    argv.balanceSizingReferenceAmount = Number(
        argv.balanceSizingReferenceAmount
        ?? requestedAmountForSizing
    );
    const derivedEnvelopeNotionalCap = Math.max(
        0,
        Number(operationalSizing.projectedEnvelopeNotionalUsd || 0)
    );
    const configuredOperationalCap = Number(argv.accountOperationalMaxGrossNotional);
    if (derivedEnvelopeNotionalCap > 0) {
        argv.accountOperationalMaxGrossNotional = Number.isFinite(configuredOperationalCap) && configuredOperationalCap > 0
            ? Math.min(configuredOperationalCap, derivedEnvelopeNotionalCap)
            : derivedEnvelopeNotionalCap;
    }
    argv.balanceSizingRejectAboveEnvelopeUsagePct = envelopeRejectUsagePct;

    const verbose = parseBoolArg(argv.verbose, false);
    const compoundBankroll = parseBoolArg(
        argv.compoundBankroll ?? argv.compoundingEnabled ?? argv.compound_bankroll,
        false
    );
    const runtimeContext = createBacktestRuntimeContext({ verbose });
    const riskAuditEveryCandles = Math.max(1, Math.round(Number(argv.riskAuditEveryCandles ?? argv.riskAuditEvery ?? 100)));
    const progressEveryCandles = Math.max(1, Math.round(Number(argv.progressEveryCandles ?? argv.progressEvery ?? 1000)));

    if (presetResolution.presetName) {
        const presetPath = presetResolution.presetPath ? ` path=${presetResolution.presetPath}` : '';
        const strictInfo = presetResolution.presetStrict
            ? ` strict=true lockedOverrides=${presetResolution.presetLockedKeys.length}`
            : '';
        console.log(`[backtest][${exchange}] preset=${presetResolution.presetName}${presetPath}${strictInfo}`);
    }

    console.log(
        `[backtest][${exchange}] operational sizing: requested=${operationalSizing.configuredAmountUsd} ` +
        `recommended=${operationalSizing.recommendedAmountUsd} effective=${operationalSizing.effectiveAmountUsd} ` +
        `source=${operationalSizing.ruleSource} auto=${operationalSizing.enabled ? 'on' : 'off'} ` +
        `envelope=${operationalSizing.envelope.envelopeMode}:${operationalSizing.envelope.selectedEnvelopeMultiple}x ` +
        `envMargin=${operationalSizing.projectedEnvelopeInitialMarginUsd} ` +
        `envMarginPct=${operationalSizing.projectedEnvelopeInitialMarginPctOfEquity}% ` +
        `rejectAbove=${envelopeRejectUsagePct}% envNotionalCap=${Number(argv.accountOperationalMaxGrossNotional || 0)}`
    );
    if (compoundBankroll) {
        console.log(`[backtest][${exchange}] compoundBankroll=on: amount/baseNotional/baseInitialMargin will be recomputed each iteration from equity = bankroll + totalRealizedNet`);
    }

    const runtimeConfig = normalizeRuntimeConfigForPresetShape(
        mergeBacktestArgvPassthrough(
            buildRuntimeConfig(argv, gran, amountPerOpNotional, leverageInput, amountPerOpInitialMargin),
            argv
        )
    );
    if (parseBoolArg(argv.debugSessionHalt ?? argv.debug_session_halt, false)) {
        console.log(
            `[backtest][${exchange}] debugSessionHalt runtimeConfig.sessionBankrollDrawdownHaltPct=` +
            `${Number(runtimeConfig.sessionBankrollDrawdownHaltPct || 0)} argv=` +
            `${Number(argv.sessionBankrollDrawdownHaltPct || 0)}`
        );
    }
    const runtimeBacktestVenue = String(requestedExchange || exchange || '').trim().toLowerCase();
    (runtimeConfig as any).backtestVenue = runtimeBacktestVenue;
    const forceRefreshCandles = parseBoolArg(argv.forceRefreshCandles, false);
    const preferCachedCandles = forceRefreshCandles
        ? false
        : parseBoolArg(argv.preferCachedCandles ?? argv.useCachedCandles, true);

    Strategies = await loadStrategyModule(exchange);

    const dataSource = String(argv.dataSource ?? argv.data_source ?? '').trim().toLowerCase();
    if (dataSource === 'mql5') {
        throw new Error('[backtest] dataSource=mql5 nao e suportado no modulo Crypto; use exchange API ou CSV de candles.');
    }
    const candlesPath = String(argv.candlesPath ?? argv.candles_path ?? '').trim();

    console.log(
        `[backtest][${exchange}] ${symbol} ${days}d ${gran} - bootstrapping candles/fees...` +
        (candlesPath ? ' (data from central candle CSV)' : '')
    );

    const marketBootstrap = candlesPath
        ? await loadLocalCandleCsvBootstrap({
            exchange,
            symbol,
            days,
            gran,
            executionTradeType: runtimeConfig.executionTradeType,
            tradeType: runtimeConfig.tradeType || runtimeConfig.tradeMode || argv.tradeType || argv.tradeMode,
            strategyMode: runtimeConfig.strategyMode || runtimeConfig.tradeMode || argv.strategyMode || argv.tradeMode,
            productType: runtimeConfig.productType,
            candlesPath,
            takerFeeRateOverrideRaw: runtimeConfig.takerFeeRateOverrideRaw,
            makerFeeRateOverrideRaw: runtimeConfig.makerFeeRateOverrideRaw,
            slippagePercentOverrideRaw: runtimeConfig.slippagePercentOverrideRaw,
        })
        : await createExchangeAdapter(exchange).bootstrapBacktestData({
            symbol,
            days,
            gran,
            outDir,
            executionTradeType: runtimeConfig.executionTradeType,
            tradeType: runtimeConfig.tradeType || runtimeConfig.tradeMode || argv.tradeType || argv.tradeMode,
            strategyMode: runtimeConfig.strategyMode || runtimeConfig.tradeMode || argv.strategyMode || argv.tradeMode,
            productType: runtimeConfig.productType,
            preferCachedCandles,
            expectedOrderValueUsd: amountPerOpNotional,
            verbose,
            takerFeeRateOverrideRaw: runtimeConfig.takerFeeRateOverrideRaw,
            makerFeeRateOverrideRaw: runtimeConfig.makerFeeRateOverrideRaw,
            slippagePercentOverrideRaw: runtimeConfig.slippagePercentOverrideRaw,
        });

    const candles = restrictCandlesToRequestedWindow(marketBootstrap.candles, days);
    const csvPath = marketBootstrap.candlesPath;
    if (!Array.isArray(candles) || candles.length === 0) {
        throw new Error('No candles loaded for backtest run');
    }

    const tickGroupMs = 0;
    let useTickReplay = false;
    const ticks: ReplayTick[] = [];
    let useRealisticReplay = false;
    const ticksByBar = (useTickReplay || useRealisticReplay)
        ? groupTicksByBar(ticks, intervalMs)
        : new Map<number, ReplayTick[]>();
    const replayIntegrity = computeReplayIntegrity(candles, ticksByBar, tickGroupMs, false);
    const degradedReplayAction = String(
        argv.degradedReplayAction
        ?? argv.degraded_replay_action
        ?? (parseBoolArg(argv.replayStrict ?? argv.replay_strict, false) ? 'error' : 'warn')
    ).trim().toLowerCase();
    if (useTickReplay) {
        console.log(`[backtest][${exchange}] tick-by-tick replay: ON (${ticks.length} ticks)`);
    } else if (useRealisticReplay) {
        const execResolution = tickGroupMs > 0 ? `micro-bars ${tickGroupMs}ms` : 'ticks';
        console.log(`[backtest][${exchange}] realistic replay: ON (decisions at bar close, execution on ${execResolution}, ${ticks.length} ticks)`);
    }

    const requestedOhlcSubReplay = parseBoolArg(
        argv.ohlcSubReplay
        ?? argv.ohlc_sub_replay
        ?? argv.useOhlcSubReplay
        ?? argv.use_ohlc_sub_replay
        ?? argv['ohlc-sub-replay']
        ?? argv['use-ohlc-sub-replay'],
        false
    );
    const barCloseNextOpenReplayDefault = !requestedOhlcSubReplay && !useTickReplay && !useRealisticReplay && String(gran).toLowerCase() === '1m';
    const useBarCloseNextOpenReplay = !useTickReplay
        && !useRealisticReplay
        && !requestedOhlcSubReplay
        && parseBoolArg(
            argv.barCloseNextOpenReplay
            ?? argv.bar_close_next_open_replay
            ?? argv.m1CloseNextOpenReplay
            ?? argv.m1_close_next_open_replay,
            barCloseNextOpenReplayDefault
        );
    const useOhlcSubReplay = !useTickReplay
        && !useRealisticReplay
        && !useBarCloseNextOpenReplay
        && requestedOhlcSubReplay;
    if (useBarCloseNextOpenReplay) {
        console.log('[backtest][' + exchange + '] bar-close-next-open replay: ON (decision on current 1m close, execution on next 1m open)');
    } else if (useOhlcSubReplay) {
        console.log('[backtest][' + exchange + '] OHLC sub-candle replay: ON (Open -> Low/High -> High/Low -> Close)');
    }

    if (replayIntegrity?.degradedReplay) {
        console.warn(
            `[backtest][${exchange}] degraded replay detected: avgMicroBarsPerBar=${Number(replayIntegrity.avgMicroBarsPerBar || 0).toFixed(2)} ` +
            `with tickGroupMs=${tickGroupMs}. This dataset is too sparse for compressed realistic replay.`
        );
        if (degradedReplayAction === 'error' || degradedReplayAction === 'abort' || degradedReplayAction === 'fail') {
            throw new Error(
                `Degraded tick replay: avgMicroBarsPerBar=${Number(replayIntegrity.avgMicroBarsPerBar || 0).toFixed(2)} with tickGroupMs=${tickGroupMs}. ` +
                `Use replayStrict=false, degradedReplayAction=fallback_to_candle, or provide denser ticks.`
            );
        }
        if (degradedReplayAction === 'fallback_to_candle' || degradedReplayAction === 'candle') {
            useTickReplay = false;
            useRealisticReplay = false;
            console.warn(`[backtest][${exchange}] falling back to candle replay because degradedReplayAction=${degradedReplayAction}`);
        }
    }

    const expectedCount = Math.floor((days * 24 * 60 * 60 * 1000) / intervalMs);
    console.log(`[backtest][${exchange}] candles loaded=${candles.length} expected~${expectedCount} path=${csvPath}`);

    const firstTs = Number(candles[0]?.ts || 0);
    const lastTs = Number(candles[candles.length - 1]?.ts || 0);
    const effectiveDays = firstTs > 0 && lastTs > firstTs
        ? ((lastTs - firstTs) / (24 * 60 * 60 * 1000))
        : ((candles.length * intervalMs) / (24 * 60 * 60 * 1000));

    console.log(
        `[backtest][${exchange}] coverage~${effectiveDays.toFixed(1)}d ` +
        `first=${firstTs ? new Date(firstTs).toISOString() : 'n/a'} ` +
        `last=${lastTs ? new Date(lastTs).toISOString() : 'n/a'}`
    );
    const backtestEntryCutoffMinutes = Math.max(0, Number(runtimeConfig.backtestEntryCutoffMinutes || 0));
    const backtestEntryCutoffTs = backtestEntryCutoffMinutes > 0 && lastTs > 0
        ? (lastTs - (backtestEntryCutoffMinutes * 60 * 1000))
        : null;
    if (backtestEntryCutoffTs != null) {
        console.log(
            `[backtest][${exchange}] no-new-entries tail: last ${backtestEntryCutoffMinutes} min ` +
            `(cutoff=${new Date(backtestEntryCutoffTs).toISOString()})`
        );
    }
    const backtestFinalFlatGraceMinutes = Math.max(0, Number(runtimeConfig.backtestFinalFlatGraceMinutes || 0));
    const backtestFinalFlatGraceTs = backtestFinalFlatGraceMinutes > 0 && lastTs > 0
        ? (lastTs - (backtestFinalFlatGraceMinutes * 60 * 1000))
        : null;
    if (backtestFinalFlatGraceTs != null) {
        console.log(
            `[backtest][${exchange}] final-flat-grace: last ${backtestFinalFlatGraceMinutes} min ` +
            `(cutoff=${new Date(backtestFinalFlatGraceTs).toISOString()})`
        );
    }
    let takerFeeRateDefault = resolveEffectiveFeeRate(marketBootstrap.takerFeeRateDefault, 0.0006);
    let makerFeeRateDefault = resolveEffectiveFeeRate(marketBootstrap.makerFeeRateDefault, 0.0002);
    let feeSource = String(marketBootstrap.feeSource || 'fallback_default');
    if (verbose) {
        console.log(
            `[backtest][${exchange}] fee bootstrap source=${feeSource} api=${String(marketBootstrap.feesFromApi)} ` +
            `maker=${makerFeeRateDefault} taker=${takerFeeRateDefault} error=${marketBootstrap.feeApiError || 'none'}`
        );
    }
    const futureFeeFallback = applyFutureFeeSanityFallback({
        executionTradeType: runtimeConfig.executionTradeType,
        takerFeeRateDefault,
        makerFeeRateDefault,
        feeSource,
        hasTakerOverride: isExplicitFeeOverride(runtimeConfig.takerFeeRateOverrideRaw),
        hasMakerOverride: isExplicitFeeOverride(runtimeConfig.makerFeeRateOverrideRaw),
    });
    takerFeeRateDefault = futureFeeFallback.takerFeeRateDefault;
    makerFeeRateDefault = futureFeeFallback.makerFeeRateDefault;
    feeSource = futureFeeFallback.feeSource;
    const estimatedSlippagePercent = Number(
        marketBootstrap.estimatedSlippagePercent
        || runtimeConfig.takerSlippagePercent
        || 0
    );

    runtimeContext.estimatedLiquidityQty = Number(marketBootstrap.estimatedLiquidityQty || 0);

    const isStockMarketBacktest = [
        runtimeConfig.tradeType,
        runtimeConfig.tradeMode,
        runtimeConfig.strategyMode,
        argv.tradeType,
        argv.tradeMode,
        argv.strategyMode,
    ].some((value) => {
        const mode = String(value ?? '').trim().toLowerCase();
        return mode.includes('stockmarket') || mode.includes('stock_market') || mode.includes('tradfi');
    });
    const serviceConfig = {
        ...runtimeConfig,
        tradeType: isStockMarketBacktest
            ? 'stockmarket'
            : (runtimeConfig.tradeType || runtimeConfig.tradeMode || argv.tradeType || argv.tradeMode),
        strategyMode: runtimeConfig.strategyMode || runtimeConfig.tradeMode || argv.strategyMode || argv.tradeMode,
        ...(isStockMarketBacktest ? {
            strategyMode: 'institutional_hybrid',
            // Keep StockMarket backtests identical to the live facade: maker
            // entries and normal exits, market emergency exits, full positive-net close floor, and the
            // same bounded inventory envelope.
            openExecutionMode: 'maker',
            closeExecutionMode: 'maker',
            hedgeOpenExecutionMode: 'maker',
            hedgeCloseExecutionMode: 'maker',
            runtimeOpenExecutionMode: 'maker',
            runtimeCloseExecutionMode: 'maker',
            riskEmergencyCloseMode: 'market',
            minNetUsdPerClose: Math.max(0.30, Number(argv.minNetUsdPerClose ?? runtimeConfig.minNetUsdPerClose ?? 0)),
            positiveNetCloseMinUsd: Math.max(0.30, Number(argv.positiveNetCloseMinUsd ?? runtimeConfig.positiveNetCloseMinUsd ?? 0)),
            maxMarginUsedPct: Number(argv.maxMarginUsedPct ?? runtimeConfig.maxMarginUsedPct ?? 0.70),
            maPeriod: Number(argv.stockMarketTrendPeriod ?? runtimeConfig.stockMarketTrendPeriod ?? runtimeConfig.maPeriod ?? 50),
            atrDilutionMultiplier: Number(argv.stockMarketAtrStopMultiplier ?? runtimeConfig.stockMarketAtrStopMultiplier ?? runtimeConfig.atrDilutionMultiplier ?? 2.2),
            trendTargetAtrMultiplier: Number(argv.stockMarketAtrTargetMultiplier ?? runtimeConfig.stockMarketAtrTargetMultiplier ?? runtimeConfig.trendTargetAtrMultiplier ?? 1.6),
            stockMarketMaxOpenPositions: Number(runtimeConfig.stockMarketMaxOpenPositions ?? 2),
            stockMarketMaxOpenPositionsPerSide: Number(runtimeConfig.stockMarketMaxOpenPositionsPerSide ?? 1),
            stockMarketDisablePartialClose: true,
            maxHoldMinutesPerLeg: Number(argv.maxHoldMinutesPerLeg ?? runtimeConfig.maxHoldMinutesPerLeg ?? Number(runtimeConfig.stockMarketMaxHoldingHours ?? 48) * 60),
            maxAdverseMovePctPerLeg: Number(argv.maxAdverseMovePctPerLeg ?? runtimeConfig.maxAdverseMovePctPerLeg ?? 0.018),
            martingaleMaxLevels: 1,
            allowMartingale: false,
            allowLeak: false,
        } : {}),
    };
    const TradingServiceClass = await loadTradingServiceClass(
        exchange,
        isStockMarketBacktest ? 'stockmarket' : String(serviceConfig.tradeType || serviceConfig.tradeMode || '')
    );
    const service = new TradingServiceClass('BackTestMarketKing', {
        ...serviceConfig,
        bankroll,
        amount: amountPerOp,
        leverageDefault: runtimeConfig.leverageDefault,
        baseNotionalPerOrder: runtimeConfig.baseNotionalPerOrder || amountPerOpNotional,
        baseInitialMarginPerOrder: runtimeConfig.baseInitialMarginPerOrder || amountPerOpInitialMargin,
    });

    const state: any = {
        signals: [],
        activePositions: [],
        shortPositions: [],
        pendingQuotes: [],
        equityTimeline: [],
        recentCloses: [],
        bankroll,
        initialBankroll: bankroll,
        sessionBankrollDrawdownHaltPct: Number(runtimeConfig.sessionBankrollDrawdownHaltPct || 0),
        equityDrawdownHaltPct: Number(
            runtimeConfig.equityDrawdownHaltPct ?? runtimeConfig.quantumEquityDrawdownHaltPct ?? 0
        ),
        referenceMid: Number(candles[0]?.close || 0),
        regime: 'RANGE',
        robotState: 'MM_NORMAL',
    };

    const balances: any = {
        initialBankroll: bankroll,
        equity: bankroll,
        available: bankroll,
        usedMargin: 0,
        marginUsedPct: 0,
        realizedPnL: 0,
        unrealizedPnL: 0,
    };

    const indicatorInputBase = {
        candles,
        strategies: Strategies,
        rsiPeriod: 14,
        mgQuinaMomentumPeriod: 14,
        mgQuinaMomentumRisePoints: 18,
        mgQuinaMomentumDropPoints: 12,
        mgQuinaMomentumRiseLookbackCandles: 5,
        mgQuinaMomentumDropLookaheadCandles: 4,
        mgQuinaRsiPeriod: 14,
        mgQuinaRsiSellThreshold: 67,
        mgQuinaRsiBuyThreshold: 33,
        mgQuinaRsiReversalPoints: 6,
        mgQuinaSyncToleranceCandles: 3,
        emaPeriod: 20,
        granularity: gran,
    };

    for (let i = 0; i < candles.length; i++) {
        const c = candles[i];
        const indicators = await computeIndicatorsSlice({
            ...indicatorInputBase,
            index: i,
        });

        if (compoundBankroll && operationalSizing.enabled) {
            const effectiveEquity = Math.max(bankroll, bankroll + Number(state.totalRealizedNet || 0));
            const compoundSizing = computeOperationalSizing({
                ...sizingInputBase,
                currentEquityUsd: effectiveEquity,
                availableQuoteUsd: effectiveEquity,
            });
            if (compoundSizing.projectedEnvelopeInitialMarginPctOfEquity > (envelopeRejectUsagePct + 1e-8)) {
                throw new Error(
                    `[backtest][${exchange}] rejected compound sizing: projected envelope initial margin ` +
                    `${compoundSizing.projectedEnvelopeInitialMarginPctOfEquity}% exceeds ` +
                    `balanceSizingRejectAboveEnvelopeUsagePct=${envelopeRejectUsagePct}%`
                );
            }
            let compoundAmount = compoundSizing.effectiveAmountUsd;
            const compoundNotional = amountIsMargin ? (compoundAmount * leverageInput) : compoundAmount;
            const compoundInitialMargin = amountIsMargin ? compoundAmount : (compoundAmount / leverageInput);
            runtimeConfig.amount = compoundAmount;
            runtimeConfig.baseNotionalPerOrder = compoundNotional;
            runtimeConfig.baseInitialMarginPerOrder = compoundInitialMargin;
            runtimeConfig.accountOperationalMaxGrossNotional = Math.max(
                0,
                Number(compoundSizing.projectedEnvelopeNotionalUsd || runtimeConfig.accountOperationalMaxGrossNotional || 0)
            );
        }

        const spreadPct = Math.max(0.00001, Number(runtimeConfig.minSpreadPercent || 0.0005)) * 0.5;
        const disableNewEntriesForTail = backtestEntryCutoffTs != null && Number(c.ts) >= backtestEntryCutoffTs;
        const inFinalFlatGrace = backtestFinalFlatGraceTs != null && Number(c.ts) >= backtestFinalFlatGraceTs;
        let iterationConfigBase = {
            ...runtimeConfig,
            bankroll,
            disableNewEntries: !!runtimeConfig.disableNewEntries || disableNewEntriesForTail,
        };
        if (inFinalFlatGrace) {
            iterationConfigBase = applyBacktestFinalFlatGraceConfig(iterationConfigBase);
        }
        const symbolInfo = {
            takerFeeRate: takerFeeRateDefault,
            makerFeeRate: makerFeeRateDefault,
            estimatedSlippagePercent,
            leverage: runtimeConfig.leverageDefault,
            isolated: runtimeConfig.marginMode === 'isolated',
        };

        const ticksInBar = (useTickReplay || useRealisticReplay)
            ? (ticksByBar.get(Number(c.ts)) || [])
            : [];

        if (useRealisticReplay) {
            // Decisão só no fechamento da barra (OHLC do candle atual).
            const lastDecisionTick = ticksInBar.length > 0 ? ticksInBar[ticksInBar.length - 1] : null;
            const decisionBidAsk = resolveBidAsk(Number(c.close), spreadPct, lastDecisionTick?.bid, lastDecisionTick?.ask);
            const tickerInfoBar = {
                bestBid: decisionBidAsk.bestBid,
                bestAsk: decisionBidAsk.bestAsk,
                ts: Number(c.ts),
                open: Number(c.open),
                high: Number(c.high),
                low: Number(c.low),
                close: Number(c.close),
                last: Number(c.close),
                markPrice: Number(c.close),
                mid: Number(c.close),
            };
            await service.executeIteration(runtimeContext, iterationConfigBase, state, balances, symbolInfo, tickerInfoBar, indicators);
            // Execução intra-bar na próxima barra (ticks ou micro-barras): só fill, SL/TP, max_unrealized.
            const nextCandle = candles[i + 1];
            if (nextCandle) {
                const nextTs = Number(nextCandle.ts);
                const ticksInNextBar = ticksByBar.get(nextTs) || [];
                if (tickGroupMs > 0 && ticksInNextBar.length > 0) {
                    const microBars = aggregateTicksToMicroBars(ticksInNextBar, nextTs, tickGroupMs);
                    for (const mb of microBars) {
                        const tickerInfo = {
                            bestBid: mb.bestBid,
                            bestAsk: mb.bestAsk,
                            ts: mb.ts,
                            open: mb.open,
                            high: mb.high,
                            low: mb.low,
                            close: mb.close,
                            last: mb.close,
                            markPrice: mb.close,
                            mid: mb.close,
                        };
                        await service.executeIteration(runtimeContext, {
                            ...iterationConfigBase,
                            executionOnly: true,
                        }, state, balances, symbolInfo, tickerInfo, indicators);
                    }
                } else if (ticksInNextBar.length > 0) {
                    for (const tick of ticksInNextBar) {
                        const p = Number(tick.price);
                        const tickBidAsk = resolveBidAsk(p, spreadPct, tick.bid, tick.ask);
                        const tickerInfo = {
                            bestBid: tickBidAsk.bestBid,
                            bestAsk: tickBidAsk.bestAsk,
                            ts: tick.ts,
                            open: p,
                            high: p,
                            low: p,
                            close: p,
                            last: p,
                            markPrice: p,
                            mid: p,
                        };
                        await service.executeIteration(runtimeContext, {
                            ...iterationConfigBase,
                            executionOnly: true,
                        }, state, balances, symbolInfo, tickerInfo, indicators);
                    }
                } else {
                    const nextBidAsk = resolveBidAsk(Number(nextCandle.close), spreadPct);
                    const tickerInfoNext = {
                        bestBid: nextBidAsk.bestBid,
                        bestAsk: nextBidAsk.bestAsk,
                        ts: nextTs,
                        open: Number(nextCandle.open),
                        high: Number(nextCandle.high),
                        low: Number(nextCandle.low),
                        close: Number(nextCandle.close),
                        last: Number(nextCandle.close),
                        markPrice: Number(nextCandle.close),
                        mid: Number(nextCandle.close),
                    };
                    await service.executeIteration(runtimeContext, {
                        ...iterationConfigBase,
                        executionOnly: true,
                    }, state, balances, symbolInfo, tickerInfoNext, indicators);
                }
            }
        } else if (useTickReplay && ticksInBar.length > 0) {
            for (const tick of ticksInBar) {
                const p = Number(tick.price);
                const tickBidAsk = resolveBidAsk(p, spreadPct, tick.bid, tick.ask);
                const tickerInfo = {
                    bestBid: tickBidAsk.bestBid,
                    bestAsk: tickBidAsk.bestAsk,
                    ts: tick.ts,
                    open: p,
                    high: p,
                    low: p,
                    close: p,
                    last: p,
                    markPrice: p,
                    mid: p,
                };
                await service.executeIteration(runtimeContext, iterationConfigBase, state, balances, symbolInfo, tickerInfo, indicators);
            }
        } else if (useOhlcSubReplay) {
            for (const tickerInfo of buildOhlcSubCandleReplayTicks(c, spreadPct)) {
                await service.executeIteration(runtimeContext, iterationConfigBase, state, balances, symbolInfo, tickerInfo, indicators);
            }
        } else if (useBarCloseNextOpenReplay) {
            const candleBidAsk = resolveBidAsk(Number(c.close), spreadPct);
            const tickerInfoDecision = {
                bestBid: candleBidAsk.bestBid,
                bestAsk: candleBidAsk.bestAsk,
                ts: Number(c.ts),
                open: Number(c.open),
                high: Number(c.high),
                low: Number(c.low),
                close: Number(c.close),
                last: Number(c.close),
                markPrice: Number(c.close),
                mid: Number(c.close),
            };
            await service.executeIteration(runtimeContext, {
                ...iterationConfigBase,
                deferEntryToNextExecution: true,
            }, state, balances, symbolInfo, tickerInfoDecision, indicators);

            const nextCandle = candles[i + 1];
            if (nextCandle) {
                const nextOpen = Number(nextCandle.open);
                const nextBidAsk = resolveBidAsk(nextOpen, spreadPct);
                const tickerInfoNextOpen = {
                    bestBid: nextBidAsk.bestBid,
                    bestAsk: nextBidAsk.bestAsk,
                    ts: Number(nextCandle.ts),
                    open: nextOpen,
                    high: nextOpen,
                    low: nextOpen,
                    close: nextOpen,
                    last: nextOpen,
                    markPrice: nextOpen,
                    mid: nextOpen,
                };
                await service.executeIteration(runtimeContext, {
                    ...iterationConfigBase,
                    executionOnly: true,
                    deferEntryToNextExecution: true,
                }, state, balances, symbolInfo, tickerInfoNextOpen, indicators);
            }
        } else {
            const candleBidAsk = resolveBidAsk(Number(c.close), spreadPct);
            const tickerInfo = {
                bestBid: candleBidAsk.bestBid,
                bestAsk: candleBidAsk.bestAsk,
                ts: Number(c.ts),
                open: Number(c.open),
                high: Number(c.high),
                low: Number(c.low),
                close: Number(c.close),
                last: Number(c.close),
                markPrice: Number(c.close),
                mid: Number(c.close),
            };
            await service.executeIteration(runtimeContext, iterationConfigBase, state, balances, symbolInfo, tickerInfo, indicators);
        }

        if ((i + 1) % riskAuditEveryCandles === 0 || i === candles.length - 1) {
            await logMarketKingLiquidationRiskWarnings(state, balances, Number(c.close), i, !!argv.verbose);
        }
        if ((i + 1) % progressEveryCandles === 0 || i === candles.length - 1) {
            console.log(
                `[backtest][${exchange}] progress ${(i + 1)}/${candles.length} ` +
                `equity=${Number(balances.equity || 0).toFixed(2)} ` +
                `open=${(state.activePositions?.length || 0) + (state.shortPositions?.length || 0)} ` +
                `state=${String(state.robotState || 'MM_NORMAL')} regime=${String(state.regime || 'RANGE')}`
            );
        }
    }

    const finalCleanupStats = {
        enabled: false,
        mode: 'mark_to_market_only',
        openBeforeCleanup: (state.activePositions?.length || 0) + (state.shortPositions?.length || 0),
        openAfterCleanup: 0,
        attempts: 0,
        forcedCloseReason: null,
        backtestEntryCutoffMinutes,
        backtestFinalFlatGraceMinutes,
        note: 'No synthetic final close is executed. Open positions remain in state and are reported as unrealized mark-to-market.',
    };
    finalCleanupStats.openAfterCleanup = (state.activePositions?.length || 0) + (state.shortPositions?.length || 0);

    const outputBaseDir = outDir || path.join(BACKTEST_ROOT, 'output', exchange);
    const baseName = `${symbol.replace('/', '')}_${days}d_${String(gran).replace('min', 'm')}`;
    const skipSignals = parseBoolArg(argv.skipSignals ?? argv.noSignals, false);
    const skipPine = parseBoolArg(argv.skipPine ?? argv.noPine, false);

    const signalsArray = Array.isArray(state.signals) ? state.signals.map((s: any) => ({ ...s })) : [];
    const needsSignalsCsv = !skipSignals || !skipPine;
    const signalsCsv = needsSignalsCsv ? formatCsvSignals(signalsArray, candles as any) : '';

    let signalsPath: string | null = null;
    if (!skipSignals) {
        const signalsPrimaryPath = path.join(outputBaseDir, `signals_${baseName}.csv`);
        const signalsPersisted = persistTextWithFallback(signalsPrimaryPath, signalsCsv, path.basename(signalsPrimaryPath));
        signalsPath = signalsPersisted.finalPath;
        console.log(`[backtest][${exchange}] signals csv: ${signalsPath}`);
    } else {
        console.log(`[backtest][${exchange}] signals csv skipped (skipSignals=true)`);
    }

    let pinePath: string | null = null;
    if (!skipPine) {
        try {
            const buildPine = await loadPineGenerator();
            const pine = buildPine(symbol, days, String(gran), signalsCsv);
            const pinePrimaryPath = path.join(outputBaseDir, `tradingview_signals_backtest_${baseName}.pine`);
            const pinePersisted = persistTextWithFallback(pinePrimaryPath, pine, path.basename(pinePrimaryPath));
            pinePath = pinePersisted.finalPath;
            console.log(`[backtest][${exchange}] pine: ${pinePath}`);
        } catch (error: any) {
            console.warn('[backtest] pine generation skipped:', error?.message || error);
        }
    } else {
        console.log(`[backtest][${exchange}] pine skipped (skipPine=true)`);
    }

    const summary = buildBacktestSummary({
        exchange,
        symbol,
        days,
        gran,
        bankroll,
        amountPerOp,
        amountPerOpNotional,
        amountPerOpInitialMargin,
        amountMode: amountIsMargin ? 'margin' : 'notional',
        operationalSizing,
        leverageDefault: runtimeConfig.leverageDefault,
        candles,
        csvPath,
        state,
        balances,
        service,
        feesFromApi: marketBootstrap.feesFromApi,
        feeSource,
        feeApiError: marketBootstrap.feeApiError,
        slippageSource: marketBootstrap.slippageSource,
        slippageApiError: marketBootstrap.slippageApiError,
        signalsPath,
        pinePath,
        verbose,
        strategyMode: runtimeConfig.strategyMode,
        executionTradeType: runtimeConfig.executionTradeType,
        defaultTakerFeeRate: takerFeeRateDefault,
        defaultMakerFeeRate: makerFeeRateDefault,
        slippagePercent: estimatedSlippagePercent,
        defaultDailyInterestRate: runtimeConfig.defaultDailyInterestRate,
        openExecutionMode: runtimeConfig.openExecutionMode,
        closeExecutionMode: runtimeConfig.closeExecutionMode,
        makerSlippagePercent: runtimeConfig.makerSlippagePercent,
        takerSlippagePercent: runtimeConfig.takerSlippagePercent,
        runtimeConfig,
        backtestVenue: runtimeBacktestVenue,
        preset: {
            name: presetResolution.presetName,
            path: presetResolution.presetPath,
            strict: presetResolution.presetStrict,
            lockedOverrideCount: presetResolution.presetLockedKeys.length,
            lockedOverrideKeys: presetResolution.presetLockedKeys,
        },
        replayMode: useRealisticReplay ? 'realistic' : useTickReplay ? 'tick' : useOhlcSubReplay ? 'ohlc_sub_candle' : useBarCloseNextOpenReplay ? 'bar_close_next_open' : 'candle',
        tickGroupMs: useRealisticReplay ? tickGroupMs : undefined,
        dataProvenance: marketBootstrap.dataProvenance || null,
        replayIntegrity,
        finalCleanupStats,
        gridPointValue: Number(runtimeConfig.gridPointValue ?? 0.01),
    });

    const summaryPath = path.join(outputBaseDir, `summary_${baseName}_${Date.now()}.json`);
    const summaryPersisted = persistJsonWithFallback(summaryPath, summary, path.basename(summaryPath));
    console.log(`[backtest][${exchange}] summary: ${summaryPersisted.finalPath}`);

    const pruned = pruneBacktestOutputDir(outputBaseDir, {
        keepLatestPerGroup: 1,
        removeOutputCandleCopies: !parseBoolArg(argv.preserveCandles ?? argv.preserve_candles, false),
    });
    if (pruned.removed.length > 0) {
        console.log(`[backtest][${exchange}] pruned ${pruned.removed.length} stale output file(s)`);
    }

    console.log('Backtest finished.');
    return {
        exchange,
        symbol,
        days,
        gran,
        summary,
        summaryPath: summaryPersisted.finalPath,
        signalsPath,
        pinePath,
        outputDir: outputBaseDir,
        backtestVenue: runtimeBacktestVenue,
        preset: {
            name: presetResolution.presetName,
            path: presetResolution.presetPath,
            strict: presetResolution.presetStrict,
            lockedOverrideCount: presetResolution.presetLockedKeys.length,
            lockedOverrideKeys: presetResolution.presetLockedKeys,
        },
    };
}

export async function runMarketKingBacktest(rawCliArgsOverride?: Record<string, any> | string[]) {
    return run(rawCliArgsOverride);
}
