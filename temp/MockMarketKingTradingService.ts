
import {
    computeNotionalAndMargin,
    quantityFromUsdtAmount as qtyFromNotionalUsdRaw,
} from './ManageFees.ts';
import { resolveInstitutionalPointTargetNetUsd } from '../../shared/OperationalPriceUnits.ts';
import {
    applyRuntimeContextToGlobal,
    createBacktestRuntimeContext,
    syncRuntimeContextFromGlobal,
    type BacktestRuntimeContext
} from './core/BacktestRuntimeContext.ts';
import { resolveInstitutionalMinNetUsd } from './services/InstitutionalMinNet.bt.ts';
import {
    resolveOperationalSafetyAndPlan,
    type OperationalMarketSnapshot,
    type OperationalStrategyPlan,
} from '../shared/OperationalEngine.bt.ts';
import {
    appendRecentSeriesValue,
    buildOperationalMarketSnapshotFromState,
} from '../shared/OperationalRecentState.bt.ts';
import { closeEntireCycle as btCloseEntireCycle } from './services/DilutionManager.bt.ts';
import {
    evaluateRsiExtremeOverlay,
    isRsiExtremeOverlayPosition,
    isRsiExtremeOverlayReason,
    resolveRsiExtremeEntryQuantity,
    resolveRsiExtremeOverlayConfig,
    RSI_EXTREME_LONG_EXIT_REASON,
    RSI_EXTREME_LONG_REASON,
    RSI_EXTREME_SHORT_EXIT_REASON,
    RSI_EXTREME_SHORT_REASON,
} from '../../lib/InstitutionalHybrid/RsiExtremeOverlay.ts';
import {
    isInstitutionalEmergencyCloseReason,
    resolveInstitutionalCloseExecutionMode,
    resolveInstitutionalOpenExecutionMode,
    resolveInstitutionalPlannedHedgeCloseMode,
} from '../../lib/InstitutionalHybrid/ExecutionModePolicy.ts';
import { tryCriticalMarginInjection, maybeCriticalMarginProactiveTopUp } from '../../lib/InstitutionalHybrid/CriticalMarginInjection.ts';
import { markLossCapClose, resolveLossCapCooldownStatus } from '../../lib/InstitutionalHybrid/LossCapCooldownPolicy.ts';
import {
    computeBasketCentralZone,
    IH_BASKET_CENTER_SCALP_LONG,
    IH_BASKET_CENTER_SCALP_SHORT,
    IH_BASKET_CENTER_SCALP_TP,
    isBasketCentralScalpReason,
    isPriceInsideBasketCentralZone,
    resolveBasketCentralScalpConfig,
    resolveBasketCentralScalpDirection,
    resolveBasketCentralScalpTargetPrice,
} from '../../lib/InstitutionalHybrid/BasketCentralScalp.ts';
import {
    IH_PAIRED_RANGE_BOOTSTRAP_REASON,
    resolvePairedRangeBootstrapConfig,
    shouldAttemptPairedRangeBootstrap,
} from '../../lib/InstitutionalHybrid/PairedRangeBootstrap.ts';

type Side = 'long' | 'short';
type QuoteSide = 'bid' | 'ask';
type Regime = 'RANGE' | 'TREND_UP' | 'TREND_DOWN';
type RobotState = 'MM_NORMAL' | 'MM_SKEW' | 'TREND_DEFENSIVE' | 'REBASE';
type RebaseMode = 'emergency' | 'non_emergency' | 'all';

interface QuoteOrder {
    id: string;
    side: QuoteSide;
    level: number;
    price: number;
    quantity: number;
    createdAt: number;
    createdIteration: number;
    referenceMid: number;
    reduceOnly: boolean;
    /** Quando reduceOnly: id da posição alvo. */
    positionId?: string;
    /** Motivo do quote (ex: pairing_take). */
    reason?: string;
    takeTargetNetPercentOfAmountOverride?: number | null;
}

interface PendingEntryIntent {
    id: string;
    side: Side;
    level: number;
    quantity: number;
    reason: string;
    sourceQuoteId: string;
    createdAt: number;
    createdIteration: number;
    takeTargetNetPercentOfAmountOverride?: number | null;
}

interface Position {
    positionId: string;
    side: Side;
    quantity: number;
    entryPrice: number;
    price?: number;
    takePrice: number;
    entryTime: number;
    initialMargin: number;
    borrowedAmount: number;
    leverage: number;
    makerFeeRate: number;
    takerFeeRate: number;
    makerSlippagePercent: number;
    takerSlippagePercent: number;
    openFeeRate: number;
    closeFeeRate: number;
    openSlippagePercent: number;
    closeSlippagePercent: number;
    openExecutionMode: 'maker' | 'taker' | 'market';
    closeExecutionMode: 'maker' | 'taker' | 'market';
    dailyInterestRate: number;
    fundingRatePer8h: number;
    fundingIntervalHours: number;
    fundingFeeMode: 'none' | 'signed' | 'absolute_cost';
    maintenanceMarginRate: number;
    qgHedge: boolean;
    qgParentSide: Side;
    qgOpenReason: string;
    sourceQuoteId: string;
    /** Longevity rebase: par (presa >24h + hedge 1.5x). Id do par = positionId da perna presa. */
    longevityRebasePairId?: string;
    /** true = perna original presa; false/undefined = perna rebase (hedge). */
    isLongevityRebaseStuck?: boolean;
    /** Nível do rebase (1, 2 ou 3); só nas pernas hedge, não na presa. */
    longevityRebaseLevel?: number;
    rebaseAttemptCount?: number;
    staleCycleRebaseAttemptCount?: number;
    inventoryTimeLimitAttemptCount?: number;
    riskRebaseAttemptCount?: number;
    rebaseBlockedCount?: number;
    rebasePartialCleanupCount?: number;
    rebaseAttemptReasons?: Record<string, number>;
    rebaseBlockedByReason?: Record<string, number>;
    lastRebaseAttemptTs?: number;
    lastRebaseAttemptCloseReason?: string;
    lastRebaseTriggerReasons?: string[];
    lastRebaseBlockedReason?: string | null;
    takeTargetNetPercentOfAmountOverride?: number | null;
}

export class MockMarketKingTradingService {
    botName: string;
    config: any;
    managerDB: any = null;
    logger: any = console;
    _cycleStarts = 0;
    _cycleEnds = 0;
    /** Em true, não sincroniza com globais (uso em produção/live). */
    _liveMode = false;
    _liveExecutionMode: 'real' | 'demo' = 'real';

    constructor(botName: string, config: any = {}) {
        this.botName = botName;
        this.config = (config && typeof config === 'object' && !Array.isArray(config)) ? config : {};
    }

    private ensureRuntimeContext(ctx: any): BacktestRuntimeContext {
        if (ctx && typeof ctx === 'object') {
            const runtimeCtx = ctx as BacktestRuntimeContext;
            if (!Array.isArray(runtimeCtx.openPositions)) runtimeCtx.openPositions = [];
            if (typeof runtimeCtx.verbose !== 'boolean') runtimeCtx.verbose = false;
            if (!isFinite(Number(runtimeCtx.estimatedLiquidityQty))) runtimeCtx.estimatedLiquidityQty = 0;
            return runtimeCtx;
        }
        return createBacktestRuntimeContext({
            verbose: false,
            openPositions: [],
            estimatedLiquidityQty: 0,
        });
    }

    private resolveLiquidationReasons(_config: any): { isolated: string; account: string } {
        return {
            isolated: 'liquidation_isolated',
            account: 'liquidation_account',
        };
    }

    private nowTsFromTicker(tickerInfo: any): number {
        const ts = Number(tickerInfo?.ts ?? tickerInfo?.time ?? tickerInfo?.timestamp ?? Date.now());
        return isFinite(ts) && ts > 0 ? ts : Date.now();
    }

    private readMidPrice(tickerInfo: any): number {
        const bestBid = Number(tickerInfo?.bestBid ?? tickerInfo?.bid ?? NaN);
        const bestAsk = Number(tickerInfo?.bestAsk ?? tickerInfo?.ask ?? NaN);
        if (isFinite(bestBid) && bestBid > 0 && isFinite(bestAsk) && bestAsk > 0) {
            const mid = (bestBid + bestAsk) / 2;
            if (isFinite(mid) && mid > 0) return mid;
        }
        const fallback = Number(tickerInfo?.mid ?? tickerInfo?.last ?? tickerInfo?.close ?? tickerInfo?.markPrice);
        return isFinite(fallback) && fallback > 0 ? fallback : NaN;
    }

    private readMarkPrice(tickerInfo: any): number {
        const mark = Number(tickerInfo?.markPrice ?? tickerInfo?.mark ?? NaN);
        if (isFinite(mark) && mark > 0) return mark;
        return this.readMidPrice(tickerInfo);
    }

    private readSpreadPct(tickerInfo: any, mid: number): number {
        const bestBid = Number(tickerInfo?.bestBid ?? tickerInfo?.bid ?? NaN);
        const bestAsk = Number(tickerInfo?.bestAsk ?? tickerInfo?.ask ?? NaN);
        if (!(isFinite(bestBid) && bestBid > 0 && isFinite(bestAsk) && bestAsk > 0 && mid > 0)) return 0;
        const spread = Math.max(0, bestAsk - bestBid);
        return spread > 0 ? (spread / mid) : 0;
    }

    private readCandleRange(tickerInfo: any, mid: number) {
        const highRaw = Number(tickerInfo?.high);
        const lowRaw = Number(tickerInfo?.low);
        const high = isFinite(highRaw) && highRaw > 0 ? highRaw : mid;
        const low = isFinite(lowRaw) && lowRaw > 0 ? lowRaw : mid;
        return {
            high: Math.max(high, low, mid),
            low: Math.min(high, low, mid),
        };
    }

    private getModeFeeRate(mode: string, makerFeeRate: number, takerFeeRate: number): number {
        return mode === 'maker' ? makerFeeRate : takerFeeRate;
    }

    private getModeSlippage(mode: string, makerSlip: number, takerSlip: number): number {
        return mode === 'maker' ? makerSlip : takerSlip;
    }

    private getPositionFeeRate(position: Position, mode: 'maker' | 'taker' | 'market', fallback: number): number {
        if (mode === 'maker') {
            const maker = Number(position.makerFeeRate);
            return isFinite(maker) ? maker : fallback;
        }
        const taker = Number(position.takerFeeRate);
        return isFinite(taker) ? taker : fallback;
    }

    private getPositionSlippage(position: Position, mode: 'maker' | 'taker' | 'market', fallback: number): number {
        if (mode === 'maker') {
            const maker = Number(position.makerSlippagePercent);
            return isFinite(maker) ? maker : fallback;
        }
        const taker = Number(position.takerSlippagePercent);
        return isFinite(taker) ? taker : fallback;
    }

    private normalizeExecutionMode(modeRaw: any, fallback: 'maker' | 'taker' | 'market' = 'maker'): 'maker' | 'taker' | 'market' {
        const mode = String(modeRaw || fallback).toLowerCase();
        if (mode === 'market' || mode === 'taker') return 'market';
        return 'maker';
    }

    private isEmergencyCloseReason(reasonRaw: any): boolean {
        return isInstitutionalEmergencyCloseReason(reasonRaw);
    }

    /**
     * Razões que justificam fechar com perda (net <= 0) no backtest.
     * pairing_take_fallback continua sujeito a minNetFloor/minHold.
     * forced_end_of_backtest_close: permitir fechar no negativo no cleanup final.
     */
    private isEmergencyForceCloseReason(reasonRaw: string | undefined): boolean {
        if (!reasonRaw || typeof reasonRaw !== 'string') return false;
        const r = reasonRaw.trim().toLowerCase();
        return (
            r.includes('red_total_settlement') ||
            r.includes('liquidation') ||
            r.includes('stopout') ||
            r.includes('margin_critical') ||
            r.includes('emergency_rebase') ||
            r.includes('drawdown_critical') ||
            r.includes('forced_end_of_backtest') ||
            r.includes('end_of_backtest_close') ||
            r.includes('max_unrealized_loss_cap') ||
            r.includes('longevity_rebase_basket_positive') ||
            r.includes('hard_stop')
        );
    }

    /**
     * Gatilho um passo antes: não solicitar fechamento fallback quando o net projetado é negativo
     * e a posição ainda não ficou aberta o tempo mínimo. Deixa a operação exposta para dar chance
     * de o mercado reverter e evitar fechar no negativo.
     * Retorna true = pular (não chamar closePosition); false = seguir e fechar.
     */
    private shouldSkipFallbackCloseBecauseNegativeAndTooEarly(
        _state: any,
        config: any,
        position: Position,
        closePrice: number,
        closeTs: number,
        closeQty: number,
        reason: string,
        forceLoss: boolean
    ): boolean {
        const allowNegative = forceLoss && this.isEmergencyForceCloseReason(reason);
        if (allowNegative) return false;
        const minHoldMin = Math.max(0, Number(config.minHoldMinutesBeforeNegativeClose ?? 0));
        if (minHoldMin <= 0) return false;
        const cloned: Position = { ...position, quantity: closeQty };
        const calc = this.estimatePositionNet(cloned, closePrice, closeTs, 'market');
        if (calc.net >= 0) return false;
        const refMargin = Number(config.baseInitialMarginPerOrder ?? config.amount ?? 0) || 1;
        const minNetFloorPct = config.minNetFloorPercentOfAmount != null && Number.isFinite(Number(config.minNetFloorPercentOfAmount))
            ? Number(config.minNetFloorPercentOfAmount)
            : null;
        const minNetFloor = minNetFloorPct != null
            ? refMargin * (minNetFloorPct / 100)
            : Number(config.minNetFloorUsd ?? 0);
        if (calc.net < minNetFloor) return false;
        const ageMs = Math.max(0, closeTs - Number(position.entryTime || closeTs));
        if (ageMs >= minHoldMin * 60 * 1000) return false;
        return true;
    }

    private resolveOpenExecutionMode(config: any, reason: string, isHedge: boolean): 'maker' | 'taker' | 'market' {
        const rsiOverlay = isRsiExtremeOverlayReason(reason)
            ? resolveRsiExtremeOverlayConfig(config).openExecutionMode
            : undefined;
        return resolveInstitutionalOpenExecutionMode(
            config,
            reason,
            isHedge,
            rsiOverlay,
            isRsiExtremeOverlayReason(reason),
        );
    }

    private resolveCloseExecutionMode(
        config: any,
        reason: string,
        forceLoss: boolean,
        position: Position,
        overrideMode?: string
    ): 'maker' | 'taker' | 'market' {
        const rsiOverlay = (isRsiExtremeOverlayReason(reason) || isRsiExtremeOverlayPosition(position))
            ? resolveRsiExtremeOverlayConfig(config).closeExecutionMode
            : undefined;
        return resolveInstitutionalCloseExecutionMode(
            config,
            reason,
            forceLoss,
            position,
            overrideMode,
            rsiOverlay,
            isRsiExtremeOverlayReason(reason) || isRsiExtremeOverlayPosition(position),
        );
    }

    private clamp(value: number, min: number, max: number): number {
        return Math.max(min, Math.min(max, value));
    }

    private normalizePrice(price: number, priceStep: number): number {
        if (!(isFinite(price) && price > 0)) return 0;
        if (!(isFinite(priceStep) && priceStep > 0)) return price;
        const normalized = Math.round(price / priceStep) * priceStep;
        return normalized > 0 ? normalized : price;
    }

    private normalizeQty(qty: number, config: any): number {
        const raw = Number(qty);
        if (!(isFinite(raw) && raw > 0)) return 0;
        const qtyStep = Number(config?.quantityStep || 0);
        const minOrderQty = Number(config?.minOrderQty || 0);
        let out = raw;
        if (qtyStep > 0) {
            out = Math.floor(out / qtyStep) * qtyStep;
        }
        if (minOrderQty > 0 && out < minOrderQty) return 0;
        return out > 0 ? out : 0;
    }

    private resolveAdversePointPriceStep(config: any, symbolInfo: any): number {
        const explicitTick = Number(config?.adversePointsPriceTick ?? 0);
        if (Number.isFinite(explicitTick) && explicitTick > 0) return explicitTick;
        const gridPointValue = Number(config?.gridPointValue ?? 0);
        if (Number.isFinite(gridPointValue) && gridPointValue > 0) return gridPointValue;
        const symbolStep = Number(symbolInfo?.priceStep ?? symbolInfo?.tickSize ?? 0);
        if (Number.isFinite(symbolStep) && symbolStep > 0) return symbolStep;
        return 0.001;
    }

    private computeAdversePointsFromBase(position: Position, price: number, config: any, symbolInfo: any): number {
        const pointStep = this.resolveAdversePointPriceStep(config, symbolInfo);
        if (!(pointStep > 0) || !(price > 0)) return 0;
        const entryPrice = Number(position?.entryPrice || 0);
        if (!(entryPrice > 0)) return 0;
        const rawDistance = position.side === 'long'
            ? (entryPrice - price)
            : (price - entryPrice);
        return Math.max(0, rawDistance / pointStep);
    }

    private computeManagedCycleAdversePointsFromBase(
        parentSide: Side,
        baseEntryPrice: number,
        price: number,
        config: any,
        symbolInfo: any
    ): number {
        const pointStep = this.resolveAdversePointPriceStep(config, symbolInfo);
        const base = Number(baseEntryPrice || 0);
        const current = Number(price || 0);
        if (!(pointStep > 0) || !(base > 0) || !(current > 0)) return 0;
        const rawDistance = parentSide === 'long'
            ? (base - current)
            : (current - base);
        return Math.max(0, rawDistance / pointStep);
    }

    private resolveManagedCycleAdverseAnchorMovePct(parentSide: Side, anchorPrice: number, price: number): number {
        if (!(anchorPrice > 0) || !(price > 0)) return 0;
        if (parentSide === 'long') {
            return Math.max(0, ((anchorPrice - price) / anchorPrice) * 100);
        }
        return Math.max(0, ((price - anchorPrice) / anchorPrice) * 100);
    }

    private shouldBlockLongevityRebaseByAdversePoints(
        state: any,
        config: any,
        symbolInfo: any,
        basePosition: Position,
        price: number,
        nowTs: number,
        nextLevel: number,
        pairId: string
    ): boolean {
        const adversePointsFromBase = this.computeAdversePointsFromBase(basePosition, price, config, symbolInfo);
        const entryPrice = Math.max(0, Number(basePosition?.entryPrice || 0));
        const adversePercentFromBase = entryPrice > 0 && price > 0
            ? (basePosition.side === 'long'
                ? Math.max(0, ((entryPrice - price) / entryPrice) * 100)
                : Math.max(0, ((price - entryPrice) / entryPrice) * 100))
            : 0;
        const leakMinAdversePercentFromBase = Math.max(0, Number(
            config?.leakMinAdversePercentFromBase ?? config?.leak_min_adverse_percent_from_base ?? 0
        ));
        const leakMinAdversePointsFromBase = Math.max(0, Number(config?.leakMinAdversePointsFromBase ?? 0));
        const maxAdversePercentFromBase = Math.max(0, Number(
            config?.maxAdversePercentFromBase ?? config?.max_adverse_percent_from_base ?? 0
        ));
        const maxAdversePointsFromBase = Math.max(0, Number(config?.maxAdversePointsFromBase ?? 0));
        const maxExceeded = maxAdversePercentFromBase > 0
            ? adversePercentFromBase > maxAdversePercentFromBase
            : maxAdversePointsFromBase > 0 && adversePointsFromBase > maxAdversePointsFromBase;
        if (maxExceeded) {
            this.recordSignal(state, {
                type: 'longevity_rebase_blocked',
                side: basePosition.side,
                reason: maxAdversePercentFromBase > 0 ? 'max_adverse_percent_from_base' : 'max_adverse_points_from_base',
                pairId,
                level: nextLevel,
                adversePercentFromBase,
                maxAdversePercentFromBase,
                adversePointsFromBase,
                maxAdversePointsFromBase,
                time: nowTs,
            });
            return true;
        }
        const leakReady = leakMinAdversePercentFromBase > 0
            ? adversePercentFromBase >= leakMinAdversePercentFromBase
            : !(leakMinAdversePointsFromBase > 0) || adversePointsFromBase >= leakMinAdversePointsFromBase;
        if (nextLevel === 1 && !leakReady) {
            this.recordSignal(state, {
                type: 'longevity_rebase_blocked',
                side: basePosition.side,
                reason: leakMinAdversePercentFromBase > 0 ? 'min_adverse_percent_from_base' : 'min_adverse_points_from_base',
                pairId,
                level: nextLevel,
                adversePercentFromBase,
                leakMinAdversePercentFromBase,
                adversePointsFromBase,
                leakMinAdversePointsFromBase,
                time: nowTs,
            });
            return true;
        }
        return false;
    }

    private isManagedCycleModeEnabled(config: any): boolean {
        return !!config?.managedCycleModeEnabled;
    }

    private ensureManagedCycleState(state: any): any {
        if (!state.managedCycleState || typeof state.managedCycleState !== 'object') {
            state.managedCycleState = {};
        }
        state.managedCycleState.globalReserveUsd = Number(state.managedCycleState.globalReserveUsd || 0);
        for (const side of ['long', 'short']) {
            if (!state.managedCycleState[side] || typeof state.managedCycleState[side] !== 'object') {
                state.managedCycleState[side] = {};
            }
            const sideState = state.managedCycleState[side];
            sideState.active = !!sideState.active;
            sideState.baseEntryTs = Number(sideState.baseEntryTs || 0);
            sideState.baseEntryPrice = Number(sideState.baseEntryPrice || 0);
            sideState.baseNotionalUsd = Number(sideState.baseNotionalUsd || 0);
            sideState.mgCount = Math.max(0, Number(sideState.mgCount || 0));
            sideState.lastMgOpenTs = Number(sideState.lastMgOpenTs || 0);
            sideState.maxMgReachedTs = Number(sideState.maxMgReachedTs || 0);
            sideState.lastMgNotionalUsd = Number(sideState.lastMgNotionalUsd || 0);
            sideState.lastMgReferencePrice = Number(sideState.lastMgReferencePrice || 0);
            sideState.reverseMgCount = Math.max(0, Number(sideState.reverseMgCount || 0));
            sideState.lastReverseMgOpenTs = Number(sideState.lastReverseMgOpenTs || 0);
            sideState.rebaseNotionalUsd = Number(sideState.rebaseNotionalUsd || 0);
            sideState.rebaseEntryPrice = Number(sideState.rebaseEntryPrice || 0);
            sideState.lastReverseMgNotionalUsd = Number(sideState.lastReverseMgNotionalUsd || 0);
            sideState.lastReverseReferencePrice = Number(sideState.lastReverseReferencePrice || 0);
            sideState.reserveUsd = Number(sideState.reserveUsd || 0);
            sideState.locked = !!sideState.locked;
            sideState.rebaseOpened = !!sideState.rebaseOpened;
            sideState.rebaseOpenedTs = Number(sideState.rebaseOpenedTs || 0);
            if (sideState.reserveUsd > 0) {
                state.managedCycleState.globalReserveUsd += sideState.reserveUsd;
                sideState.reserveUsd = 0;
            }
        }
        return state.managedCycleState;
    }

    private getManagedCycleSideState(state: any, parentSide: Side): any {
        const managedCycleState = this.ensureManagedCycleState(state);
        return managedCycleState[parentSide];
    }

    private getManagedCycleGlobalReserveUsd(state: any): number {
        const managedCycleState = this.ensureManagedCycleState(state);
        return Math.max(0, Number(managedCycleState.globalReserveUsd || 0));
    }

    private adjustManagedCycleGlobalReserveUsd(state: any, deltaUsd: number): number {
        const managedCycleState = this.ensureManagedCycleState(state);
        managedCycleState.globalReserveUsd = Math.max(
            0,
            Number(managedCycleState.globalReserveUsd || 0) + Number(deltaUsd || 0)
        );
        return Number(managedCycleState.globalReserveUsd || 0);
    }

    private clearManagedCycleGlobalReserveIfIdle(state: any): void {
        const managedCycleState = this.ensureManagedCycleState(state);
        const totalPositions = this.getManagedCyclePositions(state, 'long').length
            + this.getManagedCyclePositions(state, 'short').length;
        if (totalPositions <= 0) {
            managedCycleState.globalReserveUsd = 0;
        }
    }

    private resetManagedCycleSideState(state: any, parentSide: Side): void {
        const sideState = this.getManagedCycleSideState(state, parentSide);
        sideState.active = false;
        sideState.baseEntryTs = 0;
        sideState.baseEntryPrice = 0;
        sideState.baseNotionalUsd = 0;
        sideState.mgCount = 0;
        sideState.lastMgOpenTs = 0;
        sideState.maxMgReachedTs = 0;
        sideState.lastMgNotionalUsd = 0;
        sideState.lastMgReferencePrice = 0;
        sideState.reverseMgCount = 0;
        sideState.lastReverseMgOpenTs = 0;
        sideState.rebaseNotionalUsd = 0;
        sideState.rebaseEntryPrice = 0;
        sideState.lastReverseMgNotionalUsd = 0;
        sideState.lastReverseReferencePrice = 0;
        sideState.reserveUsd = 0;
        sideState.locked = false;
        sideState.rebaseOpened = false;
        sideState.rebaseOpenedTs = 0;
        this.clearManagedCycleGlobalReserveIfIdle(state);
    }

    private getManagedCyclePositions(state: any, parentSide: Side): Position[] {
        return this.flattenedPositions(state).filter((position) => {
            if (isRsiExtremeOverlayPosition(position)) return false;
            const resolvedParentSide = String((position as any)?.qgParentSide || position?.side || parentSide) === 'short'
                ? 'short'
                : 'long';
            return resolvedParentSide === parentSide;
        });
    }

    private getManagedCyclePrimaryPositions(state: any, parentSide: Side): Position[] {
        return this.getManagedCyclePositions(state, parentSide).filter((position) => !position.qgHedge);
    }

    private getManagedCycleHedgePositions(state: any, parentSide: Side): Position[] {
        return this.getManagedCyclePositions(state, parentSide).filter((position) => !!position.qgHedge);
    }

    private syncManagedCycleReverseStateFromPositions(state: any, parentSide: Side): void {
        const sideState = this.getManagedCycleSideState(state, parentSide);
        const hedgePositions = this.getManagedCycleHedgePositions(state, parentSide)
            .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
        if (!hedgePositions.length) {
            sideState.rebaseOpened = false;
            sideState.rebaseOpenedTs = 0;
            sideState.rebaseNotionalUsd = 0;
            sideState.rebaseEntryPrice = 0;
            sideState.lastReverseMgOpenTs = 0;
            sideState.lastReverseMgNotionalUsd = 0;
            sideState.lastReverseReferencePrice = 0;
            return;
        }

        const latestHedge = hedgePositions[hedgePositions.length - 1];
        const latestHedgeNotionalUsd = Math.max(0, Number(latestHedge.entryPrice || 0))
            * Math.max(0, Number(latestHedge.quantity || 0));
        sideState.rebaseOpened = true;
        sideState.rebaseOpenedTs = Number(latestHedge.entryTime || sideState.rebaseOpenedTs || 0);
        sideState.rebaseNotionalUsd = latestHedgeNotionalUsd;
        sideState.rebaseEntryPrice = Number(latestHedge.entryPrice || 0);
        sideState.lastReverseMgOpenTs = Number(latestHedge.entryTime || sideState.lastReverseMgOpenTs || 0);
        sideState.lastReverseMgNotionalUsd = latestHedgeNotionalUsd;
        sideState.lastReverseReferencePrice = Number(latestHedge.entryPrice || sideState.lastReverseReferencePrice || 0);
    }

    private syncManagedCycleAnchorFromPrimaryPositions(state: any, config: any, parentSide: Side): void {
        const sideState = this.getManagedCycleSideState(state, parentSide);
        const orderedPrimaries = this.getManagedCyclePrimaryPositions(state, parentSide)
            .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
        const nextBase = orderedPrimaries[0] || null;
        if (!nextBase) {
            const remainingCyclePositions = this.getManagedCyclePositions(state, parentSide);
            if (!remainingCyclePositions.length) {
                this.resetManagedCycleSideState(state, parentSide);
                return;
            }
            sideState.active = true;
            sideState.basePositionId = '';
            sideState.baseEntryTs = 0;
            sideState.baseEntryPrice = 0;
            sideState.baseNotionalUsd = 0;
            sideState.mgCount = 0;
            sideState.locked = true;
            this.syncManagedCycleReverseStateFromPositions(state, parentSide);
            return;
        }

        sideState.active = true;
        if (!(Number(sideState.cycleStartedTs || 0) > 0)) {
            sideState.cycleStartedTs = Number(nextBase.entryTime || 0);
        }
        sideState.basePositionId = String(nextBase.positionId || '');
        sideState.baseEntryTs = Number(nextBase.entryTime || 0);
        sideState.baseEntryPrice = Number(nextBase.entryPrice || 0);
        sideState.baseNotionalUsd = this.resolveManagedCycleBaseNotionalUsd(config, nextBase);
        sideState.mgCount = Math.max(0, orderedPrimaries.length - 1);
        sideState.locked = true;
        this.syncManagedCycleReverseStateFromPositions(state, parentSide);
    }

    private getManagedCycleFrontierPositions(state: any, parentSide: Side): Position[] {
        const cyclePositions = this.getManagedCyclePositions(state, parentSide);
        if (!cyclePositions.length) return [];

        const hedgePositions = cyclePositions.filter((position) => !!position.qgHedge);
        if (hedgePositions.length > 0) {
            return hedgePositions;
        }

        const mgPositions = cyclePositions.filter((position) => !position.qgHedge && position.qgOpenReason === 'managed_cycle_mg');
        if (!mgPositions.length) {
            const orderedPrimaries = this.getManagedCyclePrimaryPositions(state, parentSide)
                .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
            return orderedPrimaries.length ? [orderedPrimaries[orderedPrimaries.length - 1]] : [];
        }

        const latestEntryTs = mgPositions.reduce((maxTs, position) => Math.max(maxTs, Number(position.entryTime || 0)), 0);
        return mgPositions.filter((position) => Number(position.entryTime || 0) === latestEntryTs);
    }

    private findManagedCycleGlobalOldestNegativePrimary(
        state: any,
        config: any,
        price: number,
        nowTs: number
    ): { parentSide: Side; position: Position; net: number; openTime: number } | null {
        let selected: { parentSide: Side; position: Position; net: number; openTime: number } | null = null;
        for (const parentSide of ['long', 'short'] as Side[]) {
            const primaryPositions = this.getManagedCyclePrimaryPositions(state, parentSide)
                .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
            for (const position of primaryPositions) {
                const estimate = this.estimateManagedCycleCloseNet(config, position, price, nowTs);
                const net = Number(estimate.net || 0);
                if (!(net < 0)) continue;
                const openTime = Number(position.entryTime || 0);
                if (!selected || openTime < Number(selected.openTime || 0)) {
                    selected = { parentSide, position, net, openTime };
                }
                break;
            }
        }
        return selected;
    }

    private findManagedCycleGlobalOldestNegativeHedge(
        state: any,
        config: any,
        price: number,
        nowTs: number
    ): { parentSide: Side; position: Position; net: number; openTime: number } | null {
        let selected: { parentSide: Side; position: Position; net: number; openTime: number } | null = null;
        for (const parentSide of ['long', 'short'] as Side[]) {
            const hedgePositions = this.getManagedCycleHedgePositions(state, parentSide)
                .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
            for (const position of hedgePositions) {
                const estimate = this.estimateManagedCycleCloseNet(config, position, price, nowTs);
                const net = Number(estimate.net || 0);
                if (!(net < 0)) continue;
                const openTime = Number(position.entryTime || 0);
                if (!selected || openTime < Number(selected.openTime || 0)) {
                    selected = { parentSide, position, net, openTime };
                }
                break;
            }
        }
        return selected;
    }

    private isManagedCycleActiveForParentSide(state: any, parentSide: Side): boolean {
        const sideState = this.getManagedCycleSideState(state, parentSide);
        return !!sideState.active && this.getManagedCyclePositions(state, parentSide).length > 0;
    }

    private resolveManagedCycleBaseNotionalUsd(config: any, fallbackPosition?: Position | null): number {
        const configuredBaseNotional = Number(config?.baseNotionalPerOrder || 0);
        if (Number.isFinite(configuredBaseNotional) && configuredBaseNotional > 0) {
            return configuredBaseNotional;
        }
        const fallbackMargin = Number(fallbackPosition?.initialMargin || config?.baseInitialMarginPerOrder || config?.amount || 0);
        const fallbackLeverage = Math.max(
            1,
            Number(fallbackPosition?.leverage || config?.leverageDefault || config?.leverage || 1)
        );
        return Math.max(0, fallbackMargin * fallbackLeverage);
    }

    private getManagedCycleStepPercent(config: any): number {
        return Math.max(0, Number(config?.cycleMartingaleStepPercent || 0));
    }

    private getManagedCycleActivationStepPercent(config: any): number {
        const explicit = Math.max(0, Number(config?.cycleActivationStepPercent || 0));
        return explicit > 0 ? explicit : this.getManagedCycleStepPercent(config);
    }

    private getManagedCycleLeakInternalStepPercent(config: any): number {
        const explicit = Math.max(0, Number(config?.cycleLeakInternalStepPercent || 0));
        if (explicit > 0) return explicit;
        return this.getManagedCycleStepPercent(config);
    }

    private shouldRequireManagedCycleActivationAge(config: any): boolean {
        if (config?.cycleActivationRequiresAge !== undefined) {
            return !!config.cycleActivationRequiresAge;
        }
        return config?.cycleActivationRequiresAge !== undefined ? !!config.cycleActivationRequiresAge : true;
    }

    private getManagedCycleStepDecayPercent(config: any): number {
        return Math.max(0, Number(config?.cycleMartingaleStepDecayPercent || 0));
    }

    private getManagedCycleLeakHarvestTargetFactor(config: any): number {
        return Math.max(0.05, Math.min(1, Number(config?.cycleLeakHarvestTargetFactor ?? 1)));
    }

    private isManagedCycleGlobalReserveEnabled(config: any): boolean {
        return config?.managedCycleGlobalReserveEnabled !== false
            && config?.cycleGlobalReserveEnabled !== false;
    }

    private getManagedCycleReserveUseFactor(config: any): number {
        const raw = Number(config?.cycleReserveUseFactor ?? config?.managedCycleReserveUseFactor ?? 1);
        if (!Number.isFinite(raw)) return 1;
        return Math.max(0.05, Math.min(1, raw));
    }

    private getManagedCycleReserveSettlementTargetUsd(config: any): number {
        const explicit = Number(config?.cycleReserveSettlementTargetUsd ?? config?.managedCycleReserveSettlementTargetUsd);
        if (Number.isFinite(explicit) && explicit >= 0) return explicit;
        return Math.max(0, Number(config?.cycleBasketCloseMinNetUsd || 0));
    }

    private getManagedCycleReserveMaxNegativeNetUsd(config: any): number {
        const raw = Number(config?.cycleReserveMaxNegativeNetUsd ?? config?.managedCycleReserveMaxNegativeNetUsd ?? 0);
        return Number.isFinite(raw) ? Math.max(0, raw) : 0;
    }

    private getManagedCycleReserveMinAgeMs(config: any): number {
        const minutes = Number(config?.cycleReserveMinAgeMinutes ?? config?.managedCycleReserveMinAgeMinutes ?? 0);
        if (!Number.isFinite(minutes) || minutes <= 0) return 0;
        return minutes * 60 * 1000;
    }

    private getManagedCycleTriggerStepPercent(config: any, stageIndex: number): number {
        const baseStepPercent = this.getManagedCycleStepPercent(config);
        const stepDecayPercent = this.getManagedCycleStepDecayPercent(config);
        return Math.max(0, baseStepPercent - (stepDecayPercent * Math.max(0, stageIndex)));
    }

    private isManagedCycleLeakFrontierPosition(position: Position | null | undefined): boolean {
        if (!position) return false;
        if (!!position.qgHedge) return false;
        return String(position.qgOpenReason || '') !== 'managed_cycle_mg';
    }

    private getManagedCycleEmergencyReverseHoldMinutes(config: any): number {
        return Math.max(0, Number(config?.cycleEmergencyReverseHoldMinutes || 0));
    }

    private hasManagedCycleAdverseStep(
        side: Side,
        referencePrice: number,
        currentPrice: number,
        stepPercent: number
    ): boolean {
        if (!(referencePrice > 0 && currentPrice > 0 && stepPercent > 0)) return false;
        const stepRatio = stepPercent / 100;
        if (!(stepRatio > 0)) return false;
        return side === 'long'
            ? currentPrice <= (referencePrice * (1 - stepRatio))
            : currentPrice >= (referencePrice * (1 + stepRatio));
    }

    private pruneManagedCycleOpenQuotes(state: any, parentSide: Side): void {
        const pendingQuotes: QuoteOrder[] = Array.isArray(state.pendingQuotes) ? state.pendingQuotes : [];
        const blockedQuoteSide = parentSide === 'long' ? 'bid' : 'ask';
        state.pendingQuotes = pendingQuotes.filter((quote) => {
            if (!!quote?.reduceOnly) return true;
            return String(quote?.side || '') !== blockedQuoteSide;
        });
    }

    private estimateManagedCycleCloseNet(
        config: any,
        position: Position,
        price: number,
        nowTs: number
    ): { net: number; qty: number } {
        const maxQty = Math.max(0, Number(position.quantity || 0));
        let closeQty = this.normalizeQty(maxQty, config);
        if (!(closeQty > 0)) closeQty = maxQty;
        if (!(closeQty > 0)) return { net: 0, qty: 0 };

        const cloned: Position = { ...position, quantity: closeQty };
        const calc = this.estimatePositionNet(cloned, price, nowTs, 'market');
        return { net: calc.net, qty: closeQty };
    }

    private estimateManagedCycleRequiredCloseNet(config: any, closeNotional: number): number {
        return resolveInstitutionalMinNetUsd(config, closeNotional, 'close');
    }

    private getManagedCycleMgMinNetCloseUsd(config: any): number {
        const raw = Number(config?.cycleMgMinNetCloseUsd ?? -0.1);
        return Number.isFinite(raw) && raw < 0 ? raw : 0;
    }

    private estimateManagedCyclePositionRequiredCloseNet(config: any, position: Position, closeNotional: number): number {
        const mgMinNetCloseUsd = this.getManagedCycleMgMinNetCloseUsd(config);
        if (mgMinNetCloseUsd < 0 && !position.qgHedge && String(position.qgOpenReason || '') === 'managed_cycle_mg') {
            return mgMinNetCloseUsd;
        }
        return this.estimateManagedCycleRequiredCloseNet(config, closeNotional);
    }

    private getManagedCyclePositionMinNetOverride(config: any, position: Position, closeNotional: number): number | undefined {
        const requiredNet = this.estimateManagedCyclePositionRequiredCloseNet(config, position, closeNotional);
        if (requiredNet < 0 && !position.qgHedge && String(position.qgOpenReason || '') === 'managed_cycle_mg') {
            return requiredNet;
        }
        return undefined;
    }

    private estimateManagedCycleReserveHarvestRequiredCloseNet(config: any, position: Position, closeNotional: number): number {
        const baseRequiredNet = this.estimateManagedCyclePositionRequiredCloseNet(config, position, closeNotional);
        if (!this.isManagedCycleLeakFrontierPosition(position)) {
            return baseRequiredNet;
        }
        return Math.max(0, baseRequiredNet * this.getManagedCycleLeakHarvestTargetFactor(config));
    }

    private estimateManagedCycleBasketCloseNet(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        parentSide: Side
    ): number {
        return this.getManagedCyclePositions(state, parentSide)
            .reduce((sum, position) => sum + this.estimateManagedCycleCloseNet(config, position, price, nowTs).net, 0);
    }

    private estimateManagedCycleFrontierCloseNet(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        parentSide: Side
    ): number {
        return this.getManagedCycleFrontierPositions(state, parentSide)
            .reduce((sum, position) => sum + this.estimateManagedCycleCloseNet(config, position, price, nowTs).net, 0);
    }

    private estimateManagedCycleFrontierRequiredCloseNet(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        parentSide: Side
    ): number {
        return this.getManagedCycleFrontierPositions(state, parentSide)
            .reduce((sum, position) => {
                const estimate = this.estimateManagedCycleCloseNet(config, position, price, nowTs);
                const closeNotional = Number(price || 0) * Number(estimate.qty || 0);
                return sum + this.estimateManagedCycleReserveHarvestRequiredCloseNet(config, position, closeNotional);
            }, 0);
    }

    private getManagedCycleReserveSettleReason(parentSide: Side): string {
        return parentSide === 'short'
            ? 'managed_cycle_reserve_settle_short'
            : 'managed_cycle_reserve_settle_long';
    }

    private trySettleManagedCycleReserve(
        state: any,
        config: any,
        price: number,
        nowTs: number
    ): boolean {
        if (!this.isManagedCycleGlobalReserveEnabled(config)) return false;
        const reserveUsd = this.getManagedCycleGlobalReserveUsd(state);
        const usableReserveUsd = reserveUsd * this.getManagedCycleReserveUseFactor(config);
        if (!(usableReserveUsd > 0)) return false;

        const oldestPrimary = this.findManagedCycleGlobalOldestNegativePrimary(state, config, price, nowTs);
        const oldestHedge = this.findManagedCycleGlobalOldestNegativeHedge(state, config, price, nowTs);
        const oldest = (() => {
            if (oldestPrimary && oldestHedge) {
                return Number(oldestPrimary.openTime || 0) <= Number(oldestHedge.openTime || 0)
                    ? oldestPrimary
                    : oldestHedge;
            }
            return oldestPrimary || oldestHedge;
        })();
        if (!oldest) return false;

        const oldestEstimate = this.estimateManagedCycleCloseNet(config, oldest.position, price, nowTs);
        const minAgeMs = this.getManagedCycleReserveMinAgeMs(config);
        const oldestAgeMs = Math.max(0, nowTs - Number(oldest.position.entryTime || nowTs));
        if (minAgeMs > 0 && oldestAgeMs < minAgeMs) return false;

        const maxNegativeNetUsd = this.getManagedCycleReserveMaxNegativeNetUsd(config);
        const oldestNet = Number(oldestEstimate.net || 0);
        if (maxNegativeNetUsd > 0 && Math.abs(Math.min(0, oldestNet)) > maxNegativeNetUsd) return false;

        const settlementTargetUsd = this.getManagedCycleReserveSettlementTargetUsd(config);
        const projectedNet = usableReserveUsd + oldestNet;
        if (!(projectedNet >= settlementTargetUsd)) return false;

        const closeReason = oldest.position.qgHedge
            ? 'managed_cycle_reserve_settle_hedge'
            : this.getManagedCycleReserveSettleReason(oldest.parentSide);
        const closed = this.closePosition(
            state,
            config,
            oldest.position,
            price,
            nowTs,
            closeReason,
            Number(oldest.position.quantity || 0),
            false,
            undefined,
            true
        );
        if (!closed.closed) return false;

        const reserveAfterUsd = this.adjustManagedCycleGlobalReserveUsd(state, Number(closed.net || 0));
        this.recordSignal(state, {
            type: 'managed_cycle_reserve_settle',
            side: oldest.parentSide,
            reason: closeReason,
            time: nowTs,
            closedPositionId: oldest.position.positionId,
            reserveBeforeUsd: reserveUsd,
            usableReserveUsd,
            reserveAfterUsd,
            oldestNet,
            oldestAgeMinutes: Math.round(oldestAgeMs / 60000),
            projectedNet,
            targetUsd: settlementTargetUsd,
        });
        return true;
    }

    private tryHarvestManagedCycleReserve(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        parentSide: Side
    ): boolean {
        if (!this.isManagedCycleGlobalReserveEnabled(config)) return false;
        const primaryPositions = this.getManagedCyclePrimaryPositions(state, parentSide)
            .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
        const oldest = primaryPositions[0];
        if (!oldest) return false;

        const frontierPositions = this.getManagedCycleFrontierPositions(state, parentSide)
            .filter((position) => String(position.positionId || '') !== String(oldest.positionId || ''));
        if (!frontierPositions.length) return false;
        const harvestHedgeEnabled = config.cycleReserveHarvestHedgeEnabled !== false;

        const candidates = frontierPositions
            .filter((position) => harvestHedgeEnabled || !position.qgHedge)
            .map((position) => {
                const estimate = this.estimateManagedCycleCloseNet(config, position, price, nowTs);
                const closeNotional = Math.max(0, Number(price || 0) * Number(estimate.qty || 0));
                const requiredNet = this.estimateManagedCycleReserveHarvestRequiredCloseNet(config, position, closeNotional);
                return {
                    position,
                    net: Number(estimate.net || 0),
                    qty: Number(estimate.qty || 0),
                    openTime: Number(position.entryTime || 0),
                    individuallyOk: Number(estimate.net || 0) >= requiredNet,
                };
            })
            .filter((candidate) => candidate.individuallyOk && candidate.qty > 0 && candidate.net > 0)
            .sort((left, right) => Number(right.openTime || 0) - Number(left.openTime || 0));

        for (const candidate of candidates) {
            const reserveBeforeUsd = this.getManagedCycleGlobalReserveUsd(state);
            const closed = this.closePosition(
                state,
                config,
                candidate.position,
                price,
                nowTs,
                'managed_cycle_reserve_harvest',
                Number(candidate.position.quantity || candidate.qty || 0),
                false
            );
            if (!closed.closed) continue;

            const reserveAfterUsd = this.adjustManagedCycleGlobalReserveUsd(state, Number(closed.net || 0));
            this.recordSignal(state, {
                type: 'managed_cycle_reserve_harvest',
                side: parentSide,
                reason: 'managed_cycle_reserve_harvest',
                time: nowTs,
                closedPositionId: candidate.position.positionId,
                harvestedNet: Number(closed.net || 0),
                reserveBeforeUsd,
                reserveAfterUsd,
                oldestPositionId: oldest.positionId,
            });

            if (this.trySettleManagedCycleReserve(state, config, price, nowTs)) {
                return true;
            }
            return true;
        }

        return false;
    }

    private closeManagedCycleBasket(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        parentSide: Side,
        reason: string
    ): number {
        const ordered = [...this.getManagedCyclePositions(state, parentSide)].sort((a, b) => {
            if (!!a.qgHedge !== !!b.qgHedge) return a.qgHedge ? -1 : 1;
            return Number(a.entryTime || 0) - Number(b.entryTime || 0);
        });
        const projectedNet = this.estimateManagedCycleBasketCloseNet(state, config, price, nowTs, parentSide);
        const frontierNet = this.estimateManagedCycleFrontierCloseNet(state, config, price, nowTs, parentSide);
        if (!ordered.length) {
            this.resetManagedCycleSideState(state, parentSide);
            return 0;
        }

        let closedCount = 0;
        for (const position of ordered) {
            const result = this.closePosition(
                state,
                config,
                position,
                price,
                nowTs,
                reason,
                Number(position.quantity || 0),
                false,
                undefined,
                undefined,
                true
            );
            if (result.closed) closedCount += 1;
        }

        this.recordSignal(state, {
            type: 'managed_cycle_close',
            side: parentSide,
            reason,
            time: nowTs,
            price,
            net: projectedNet,
            netPnL: projectedNet,
            frontierNet,
            closedCount,
        });
        this.resetManagedCycleSideState(state, parentSide);
        return closedCount;
    }

    private manageStuckCycleForSide(
        state: any,
        config: any,
        symbolInfo: any,
        price: number,
        nowTs: number,
        parentSide: Side
    ): void {
        const cyclePositions = this.getManagedCyclePositions(state, parentSide);
        if (!cyclePositions.length) {
            this.resetManagedCycleSideState(state, parentSide);
            return;
        }
        const primaryPositions = this.getManagedCyclePrimaryPositions(state, parentSide);
        const hedgePositions = this.getManagedCycleHedgePositions(state, parentSide);
        if (!primaryPositions.length && !hedgePositions.length) {
            this.resetManagedCycleSideState(state, parentSide);
            return;
        }

        const sideState = this.getManagedCycleSideState(state, parentSide);
        const shouldSyncObservedCycleState =
            !!sideState.active
            || !!sideState.locked
            || !!sideState.rebaseOpened
            || Number(sideState.mgCount || 0) > 0;
        if (shouldSyncObservedCycleState) {
            this.syncManagedCycleAnchorFromPrimaryPositions(state, config, parentSide);
        }
        const mgTriggerMs = Math.max(1, Number(config.cycleMartingaleTriggerMinutes || 240)) * 60 * 1000;
        const mgMultiplierRaw = Number(config.cycleMartingaleMultiplier || 1.5);
        const mgMultiplier = Math.max(1, Number.isFinite(mgMultiplierRaw) && mgMultiplierRaw > 0
            ? (mgMultiplierRaw < 1 ? 1 + mgMultiplierRaw : mgMultiplierRaw)
            : 1.5);
        const maxMgLevels = Math.max(1, Math.min(10, Number(config.cycleMartingaleMaxLevels || 3)));
        const maxReverseMgLevels = Math.max(0, Math.min(10, Number(config.cycleReverseMgMaxLevels ?? maxMgLevels)));
        const rebaseTriggerMs = Math.max(1, Number(config.cycleRebaseTriggerMinutes || 720)) * 60 * 1000;
        const rebaseMultiplierRaw = Number(config.cycleRebaseMultiplier || mgMultiplier);
        const rebaseMultiplier = Math.max(1, Number.isFinite(rebaseMultiplierRaw) && rebaseMultiplierRaw > 0
            ? (rebaseMultiplierRaw < 1 ? 1 + rebaseMultiplierRaw : rebaseMultiplierRaw)
            : mgMultiplier);
        const baseStepPercent = this.getManagedCycleStepPercent(config);
        const useStepTrigger = baseStepPercent > 0;
        const basketCloseMinNetUsd = Math.max(0, Number(config.cycleBasketCloseMinNetUsd || 0));
        const emergencyReverseHoldMs = this.getManagedCycleEmergencyReverseHoldMinutes(config) * 60 * 1000;
        const orderedPrimaries = [...primaryPositions].sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));
        const anchor = orderedPrimaries[0] || [...cyclePositions].sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0))[0];
        if (!anchor) {
            this.resetManagedCycleSideState(state, parentSide);
            return;
        }
        const anchorEntryTs = Number(anchor.entryTime || nowTs);
        const anchorEntryPrice = Number(anchor.entryPrice || anchor.price || 0);
        const adversePointsFromBase = this.computeManagedCycleAdversePointsFromBase(
            parentSide,
            anchorEntryPrice,
            price,
            config,
            symbolInfo
        );
        const anchorAdverseMovePct = this.resolveManagedCycleAdverseAnchorMovePct(parentSide, anchorEntryPrice, price);
        const leakMinAdversePercentFromBase = Math.max(0, Number(
            config?.leakMinAdversePercentFromBase ?? config?.leak_min_adverse_percent_from_base ?? 0
        ));
        const leakMinAdversePointsFromBase = Math.max(0, Number(config?.leakMinAdversePointsFromBase ?? 0));
        const maxAdversePercentFromBase = Math.max(0, Number(
            config?.maxAdversePercentFromBase ?? config?.max_adverse_percent_from_base ?? 0
        ));
        const maxAdversePointsFromBase = Math.max(0, Number(config?.maxAdversePointsFromBase ?? 0));
        const rebaseMinAdversePercentFromBase = Math.max(0, Number(
            config?.cycleRebaseMinAdversePercentFromBase ?? config?.cycle_rebase_min_adverse_percent_from_base ?? 0
        ));
        const rebaseMinAdversePointsFromBase = Math.max(0, Number(
            config?.cycleRebaseMinAdversePointsFromBase ?? config?.cycle_rebase_min_adverse_points_from_base ?? 0
        ));

        if (!sideState.active) {
            const anchorAgeMs = Math.max(0, nowTs - anchorEntryTs);
            const emergencyHoldActivation = emergencyReverseHoldMs > 0 && anchorAgeMs >= emergencyReverseHoldMs;
            const activationStepPercent = this.getManagedCycleActivationStepPercent(config);
            const activationAgeReady = anchorAgeMs >= mgTriggerMs;
            const activationStepReady = !useStepTrigger
                || this.hasManagedCycleAdverseStep(parentSide, anchorEntryPrice, price, activationStepPercent);
            const normalActivationReady = this.shouldRequireManagedCycleActivationAge(config)
                ? (activationAgeReady && activationStepReady)
                : activationStepReady;
            const activationReady = emergencyHoldActivation || normalActivationReady;
            if (!activationReady) return;
            sideState.active = true;
            sideState.baseEntryTs = anchorEntryTs;
            sideState.baseEntryPrice = anchorEntryPrice;
            sideState.baseNotionalUsd = this.resolveManagedCycleBaseNotionalUsd(config, anchor);
            sideState.mgCount = 0;
            sideState.lastMgOpenTs = anchorEntryTs;
            sideState.maxMgReachedTs = 0;
            sideState.lastMgNotionalUsd = 0;
            sideState.lastMgReferencePrice = anchorEntryPrice;
            sideState.reverseMgCount = 0;
            sideState.lastReverseMgOpenTs = 0;
            sideState.rebaseNotionalUsd = 0;
            sideState.rebaseEntryPrice = 0;
            sideState.lastReverseMgNotionalUsd = 0;
            sideState.lastReverseReferencePrice = 0;
            sideState.locked = true;
            sideState.rebaseOpened = false;
            sideState.rebaseOpenedTs = 0;
            this.recordSignal(state, {
                type: 'managed_cycle_activate',
                side: parentSide,
                reason: emergencyHoldActivation
                    ? 'stuck_over_24h_reverse_override'
                    : useStepTrigger
                        ? 'adverse_step_trigger'
                        : 'stuck_over_4h',
                time: nowTs,
                baseEntryTs: sideState.baseEntryTs,
                baseEntryPrice: sideState.baseEntryPrice,
                baseNotionalUsd: sideState.baseNotionalUsd,
                stepPercent: useStepTrigger ? activationStepPercent : null,
            });
        }

        sideState.locked = true;
        this.pruneManagedCycleOpenQuotes(state, parentSide);

        const cycleNet = this.estimateManagedCycleBasketCloseNet(state, config, price, nowTs, parentSide);
        const frontierNet = this.estimateManagedCycleFrontierCloseNet(state, config, price, nowTs, parentSide);
        const frontierRequiredNet = this.estimateManagedCycleFrontierRequiredCloseNet(state, config, price, nowTs, parentSide);
        if (this.trySettleManagedCycleReserve(state, config, price, nowTs)) {
            return;
        }
        if (cycleNet >= basketCloseMinNetUsd && frontierNet >= frontierRequiredNet) {
            this.closeManagedCycleBasket(state, config, price, nowTs, parentSide, 'managed_cycle_basket_positive');
            return;
        }
        if (this.tryHarvestManagedCycleReserve(state, config, price, nowTs, parentSide)) {
            return;
        }

        const cycleAgeMs = Math.max(0, nowTs - Number(sideState.baseEntryTs || anchorEntryTs || nowTs));
        const forceRebaseByHold = !sideState.rebaseOpened
            && emergencyReverseHoldMs > 0
            && cycleAgeMs >= emergencyReverseHoldMs;

        if (sideState.rebaseOpened) {
            if (sideState.reverseMgCount >= maxReverseMgLevels) return;
            const reverseReferenceTs = Number(sideState.lastReverseMgOpenTs || sideState.rebaseOpenedTs || nowTs);
            const reverseSide: Side = parentSide === 'long' ? 'short' : 'long';
            const reverseReferencePrice = Number(sideState.lastReverseReferencePrice || sideState.rebaseEntryPrice || 0);
            const reverseMgStepPercent = this.getManagedCycleTriggerStepPercent(
                config,
                maxMgLevels + 1 + Number(sideState.reverseMgCount || 0)
            );
            const reverseReady = useStepTrigger
                ? this.hasManagedCycleAdverseStep(reverseSide, reverseReferencePrice, price, reverseMgStepPercent)
                : ((nowTs - reverseReferenceTs) >= mgTriggerMs);
            if (!reverseReady) return;

            const reverseAnchorNotionalUsd = Math.max(
                0,
                Number(sideState.lastReverseMgNotionalUsd || sideState.rebaseNotionalUsd || 0)
            );
            if (!(reverseAnchorNotionalUsd > 0)) return;
            const reverseNotionalUsd = Math.max(0, reverseAnchorNotionalUsd * mgMultiplier);
            const reverseQty = price > 0 ? (reverseNotionalUsd / price) : 0;
            const openedReverseMg = this.openPosition(state, config, symbolInfo, {
                side: reverseSide,
                qty: reverseQty,
                price,
                takePrice: price,
                nowTs,
                reason: 'managed_cycle_rebase_mg',
                sourceQuoteId: `MC_${parentSide.toUpperCase()}_REBASE_MG${sideState.reverseMgCount + 1}_${nowTs}`,
                qgHedge: true,
                qgParentSide: parentSide,
            });
            if (openedReverseMg) {
                sideState.reverseMgCount = Number(sideState.reverseMgCount || 0) + 1;
                sideState.lastReverseMgOpenTs = nowTs;
                sideState.lastReverseMgNotionalUsd = reverseNotionalUsd;
                sideState.lastReverseReferencePrice = price;
                this.recordSignal(state, {
                    type: 'managed_cycle_rebase_mg_open',
                    side: reverseSide,
                    reason: 'managed_cycle_rebase_mg',
                    parentSide,
                    time: nowTs,
                    mgLevel: sideState.reverseMgCount,
                    quantity: reverseQty,
                    notionalUsd: reverseNotionalUsd,
                    stepPercent: useStepTrigger ? reverseMgStepPercent : null,
                });
            }
            return;
        }

        if (sideState.mgCount < maxMgLevels && !forceRebaseByHold) {
            const referenceTs = Number(sideState.lastMgOpenTs || sideState.baseEntryTs || nowTs);
            const mgReferencePrice = Number(sideState.lastMgReferencePrice || sideState.baseEntryPrice || anchorEntryPrice || 0);
            const nextMgLevel = Number(sideState.mgCount || 0) + 1;
            const mgStageIndex = Number(sideState.mgCount || 0);
            const mgStepPercent = mgStageIndex === 0
                ? this.getManagedCycleLeakInternalStepPercent(config)
                : this.getManagedCycleTriggerStepPercent(config, mgStageIndex);
            const mgReady = useStepTrigger
                ? this.hasManagedCycleAdverseStep(parentSide, mgReferencePrice, price, mgStepPercent)
                : ((nowTs - referenceTs) >= mgTriggerMs);
            if (!mgReady) return;
            const maxAdverseExceeded = maxAdversePercentFromBase > 0
                ? anchorAdverseMovePct > maxAdversePercentFromBase
                : maxAdversePointsFromBase > 0 && adversePointsFromBase > maxAdversePointsFromBase;
            if (maxAdverseExceeded) {
                this.recordSignal(state, {
                    type: 'managed_cycle_mg_blocked',
                    side: parentSide,
                    reason: maxAdversePercentFromBase > 0 ? 'max_adverse_percent_from_base' : 'max_adverse_points_from_base',
                    time: nowTs,
                    mgLevel: nextMgLevel,
                    adversePercentFromBase: anchorAdverseMovePct,
                    maxAdversePercentFromBase,
                    adversePointsFromBase,
                    maxAdversePointsFromBase,
                    baseEntryPrice: anchorEntryPrice,
                    price,
                });
                return;
            }
            const leakMinAdverseReady = leakMinAdversePercentFromBase > 0
                ? anchorAdverseMovePct >= leakMinAdversePercentFromBase
                : !(leakMinAdversePointsFromBase > 0) || adversePointsFromBase >= leakMinAdversePointsFromBase;
            if (nextMgLevel === 1 && !leakMinAdverseReady) {
                this.recordSignal(state, {
                    type: 'managed_cycle_mg_blocked',
                    side: parentSide,
                    reason: leakMinAdversePercentFromBase > 0 ? 'min_adverse_percent_from_base' : 'min_adverse_points_from_base',
                    time: nowTs,
                    mgLevel: nextMgLevel,
                    adversePercentFromBase: anchorAdverseMovePct,
                    leakMinAdversePercentFromBase,
                    adversePointsFromBase,
                    leakMinAdversePointsFromBase,
                    baseEntryPrice: anchorEntryPrice,
                    price,
                });
                return;
            }

            const baseNotionalUsd = Math.max(0, Number(sideState.baseNotionalUsd || 0));
            const mgAnchorNotionalUsd = Math.max(
                0,
                Number(sideState.lastMgNotionalUsd || baseNotionalUsd || 0)
            );
            if (!(mgAnchorNotionalUsd > 0)) return;
            const mgNotionalUsd = (!(sideState.mgCount > 0) && baseNotionalUsd > 0)
                ? Math.max(0, baseNotionalUsd * mgMultiplier)
                : Math.max(0, mgAnchorNotionalUsd * mgMultiplier);
            const mgQty = price > 0 ? (mgNotionalUsd / price) : 0;
            const opened = this.openPosition(state, config, symbolInfo, {
                side: parentSide,
                qty: mgQty,
                price,
                takePrice: price,
                nowTs,
                reason: 'managed_cycle_mg',
                sourceQuoteId: `MC_${parentSide.toUpperCase()}_MG${sideState.mgCount + 1}_${nowTs}`,
                qgHedge: false,
                qgParentSide: parentSide,
            });
            if (opened) {
                sideState.mgCount = Number(sideState.mgCount || 0) + 1;
                sideState.lastMgOpenTs = nowTs;
                sideState.lastMgNotionalUsd = mgNotionalUsd;
                sideState.lastMgReferencePrice = price;
                if (sideState.mgCount >= maxMgLevels) {
                    sideState.maxMgReachedTs = nowTs;
                }
                this.recordSignal(state, {
                    type: 'managed_cycle_mg_open',
                    side: parentSide,
                    reason: useStepTrigger ? 'managed_cycle_step_mg' : 'stuck_cycle_mg',
                    time: nowTs,
                    mgLevel: nextMgLevel,
                    quantity: mgQty,
                    notionalUsd: mgNotionalUsd,
                    stepPercent: useStepTrigger ? mgStepPercent : null,
                });
            }
            return;
        }

        const rebaseReferenceTs = Number(sideState.baseEntryTs || anchorEntryTs || nowTs);
        const rebaseReferencePrice = Number(sideState.lastMgReferencePrice || sideState.baseEntryPrice || anchorEntryPrice || 0);
        const rebaseStepPercent = this.getManagedCycleTriggerStepPercent(config, maxMgLevels);
        const rebaseReady = forceRebaseByHold || (useStepTrigger
            ? this.hasManagedCycleAdverseStep(parentSide, rebaseReferencePrice, price, rebaseStepPercent)
            : ((rebaseReferenceTs > 0) && ((nowTs - rebaseReferenceTs) >= rebaseTriggerMs)));
        if (config.cycleRebaseEnabled === false) return;
        if (!rebaseReady) return;
        const maxAdverseExceededForRebase = maxAdversePercentFromBase > 0
            ? anchorAdverseMovePct > maxAdversePercentFromBase
            : maxAdversePointsFromBase > 0 && adversePointsFromBase > maxAdversePointsFromBase;
        if (maxAdverseExceededForRebase) {
            this.recordSignal(state, {
                type: 'managed_cycle_rebase_blocked',
                side: parentSide === 'long' ? 'short' : 'long',
                parentSide,
                reason: maxAdversePercentFromBase > 0 ? 'max_adverse_percent_from_base' : 'max_adverse_points_from_base',
                time: nowTs,
                adversePercentFromBase: anchorAdverseMovePct,
                maxAdversePercentFromBase,
                adversePointsFromBase,
                maxAdversePointsFromBase,
                baseEntryPrice: anchorEntryPrice,
                price,
            });
            return;
        }
        const rebaseMinAdverseReady = rebaseMinAdversePercentFromBase > 0
            ? anchorAdverseMovePct >= rebaseMinAdversePercentFromBase
            : !(rebaseMinAdversePointsFromBase > 0) || adversePointsFromBase >= rebaseMinAdversePointsFromBase;
        if (!forceRebaseByHold && !rebaseMinAdverseReady) {
            this.recordSignal(state, {
                type: 'managed_cycle_rebase_blocked',
                side: parentSide === 'long' ? 'short' : 'long',
                parentSide,
                reason: rebaseMinAdversePercentFromBase > 0 ? 'min_rebase_adverse_percent_from_base' : 'min_rebase_adverse_points_from_base',
                time: nowTs,
                adversePercentFromBase: anchorAdverseMovePct,
                rebaseMinAdversePercentFromBase,
                adversePointsFromBase,
                rebaseMinAdversePointsFromBase,
                baseEntryPrice: anchorEntryPrice,
                price,
            });
            return;
        }

        const rebaseAnchorNotionalUsd = Math.max(
            0,
            Number(sideState.lastMgNotionalUsd || sideState.baseNotionalUsd || 0)
        );
        if (!(rebaseAnchorNotionalUsd > 0)) return;
        const rebaseNotionalUsd = Math.max(0, rebaseAnchorNotionalUsd * rebaseMultiplier);
        const rebaseQty = price > 0 ? (rebaseNotionalUsd / price) : 0;
        const rebaseSide: Side = parentSide === 'long' ? 'short' : 'long';
        const opened = this.openPosition(state, config, symbolInfo, {
            side: rebaseSide,
            qty: rebaseQty,
            price,
            takePrice: price,
            nowTs,
            reason: 'managed_cycle_rebase',
            sourceQuoteId: `MC_${parentSide.toUpperCase()}_REBASE_${nowTs}`,
            qgHedge: true,
            qgParentSide: parentSide,
        });
        if (opened) {
            sideState.rebaseOpened = true;
            sideState.rebaseOpenedTs = nowTs;
            sideState.rebaseNotionalUsd = rebaseNotionalUsd;
            sideState.rebaseEntryPrice = price;
            sideState.lastReverseMgNotionalUsd = 0;
            sideState.lastReverseReferencePrice = price;
            this.recordSignal(state, {
                type: 'managed_cycle_rebase_open',
                side: rebaseSide,
                reason: 'managed_cycle_rebase',
                parentSide,
                time: nowTs,
                quantity: rebaseQty,
                notionalUsd: rebaseNotionalUsd,
                stepPercent: forceRebaseByHold
                    ? null
                    : useStepTrigger
                        ? rebaseStepPercent
                        : null,
                triggerReason: forceRebaseByHold ? 'hold_24h_override' : 'managed_cycle_rebase',
            });
        }
    }

    private runManagedStuckCycles(
        state: any,
        config: any,
        symbolInfo: any,
        price: number,
        nowTs: number
    ): void {
        if (!this.isManagedCycleModeEnabled(config)) return;
        this.manageStuckCycleForSide(state, config, symbolInfo, price, nowTs, 'long');
        this.manageStuckCycleForSide(state, config, symbolInfo, price, nowTs, 'short');
    }

    private ensureState(state: any, config: any, price: number, nowTs: number): any {
        const s = state || {};
        if (!Array.isArray(s.activePositions)) s.activePositions = [];
        if (!Array.isArray(s.shortPositions)) s.shortPositions = [];
        if (!Array.isArray(s.pendingQuotes)) s.pendingQuotes = [];
        if (!Array.isArray(s.signals)) s.signals = [];
        if (!Array.isArray(s.equityTimeline)) s.equityTimeline = [];
        if (!Array.isArray(s.recentCloses)) s.recentCloses = [];
        if (!Array.isArray(s.recentSpreadPcts)) s.recentSpreadPcts = [];
        if (!Array.isArray(s.recentCandleRangePcts)) s.recentCandleRangePcts = [];

        s.robotState = (s.robotState || 'MM_NORMAL') as RobotState;
        s.regime = (s.regime || 'RANGE') as Regime;
        s.lastRegime = (s.lastRegime || s.regime) as Regime;
        s.operationalRegime = String(s.operationalRegime || 'RANGE');
        s.operationalMotor = String(s.operationalMotor || 'pause');
        s.operationalPauseReason = s.operationalPauseReason != null ? String(s.operationalPauseReason) : null;
        s.operationalPlanKey = String(s.operationalPlanKey || '');
        s.referenceMid = Number(s.referenceMid || price || 0);
        s.lastRequoteTs = Number(s.lastRequoteTs || nowTs || 0);
        s.requoteWindowStartTs = Number(s.requoteWindowStartTs || nowTs || 0);
        s.requoteWindowCount = Number(s.requoteWindowCount || 0);
        s.lastRebaseTs = Number(s.lastRebaseTs || 0);
        s.rebaseWindowStartTs = Number(s.rebaseWindowStartTs || nowTs || 0);
        s.regimeAgeTicks = Number(s.regimeAgeTicks || 0);
        s.pendingRegime = (s.pendingRegime || '') as Regime | '';
        s.pendingRegimeTicks = Number(s.pendingRegimeTicks || 0);
        s.lastInventoryReductionTs = Number(s.lastInventoryReductionTs || nowTs || 0);
        s.lastInventoryReductionPrice = Number(s.lastInventoryReductionPrice || price || 0);
        s.rebaseCount = Number(s.rebaseCount || 0);
        s.volAdaptiveAtrSlowEma = Number(s.volAdaptiveAtrSlowEma || 0);
        s.volAdaptiveVr = Number(s.volAdaptiveVr || 1);
        s.volAdaptiveKVol = Number(s.volAdaptiveKVol || 1);

        s.totalRealizedNet = Number(s.totalRealizedNet || 0);
        if (!isFinite(Number(s.initialBankroll)) || Number(s.initialBankroll) <= 0) {
            s.initialBankroll = Math.max(1e-8, Number(config?.bankroll || 0));
        }
        if (!isFinite(Number(s.sessionBankrollDrawdownHaltPct)) || Number(s.sessionBankrollDrawdownHaltPct) <= 0) {
            const fromConfig = Number(
                (config as any)?.sessionBankrollDrawdownHaltPct
                ?? (this.config as any)?.sessionBankrollDrawdownHaltPct
                ?? 0
            );
            if (fromConfig > 0) {
                s.sessionBankrollDrawdownHaltPct = fromConfig;
            }
        }
        if (!isFinite(Number(s.equityDrawdownHaltPct))) {
            s.equityDrawdownHaltPct = Number(
                (config as any)?.equityDrawdownHaltPct
                ?? (config as any)?.quantumEquityDrawdownHaltPct
                ?? (this.config as any)?.equityDrawdownHaltPct
                ?? 0
            );
        }
        s.lastMarkPrice = Number(price || s.lastMarkPrice || 0);
        s.markPrice = Number(price || s.markPrice || 0);
        s.realizedSinceRebase = Number(s.realizedSinceRebase || 0);
        s.totalFees = Number(s.totalFees || 0);
        s.totalInterest = Number(s.totalInterest || 0);
        s.totalFunding = Number(s.totalFunding || 0);
        s.totalSlippage = Number(s.totalSlippage || 0);
        s.totalGross = Number(s.totalGross || 0);

        s.bankroll = Number(s.bankroll || config?.bankroll || 0);
        s.peakEquity = Number(s.peakEquity || s.bankroll || 0);
        s.drawdownPct = Number(s.drawdownPct || 0);
        s.riskStopReason = '';
        s.inventoryLockActive = !!s.inventoryLockActive;
        s.marginUsed = Number(s.marginUsed || 0);
        s.marginUsedPct = Number(s.marginUsedPct || 0);
        s.availableBalance = Number(s.availableBalance || 0);
        s.availableOperationalBalance = Number(s.availableOperationalBalance || 0);
        s.realizedCollateral = Number(s.realizedCollateral || s.bankroll || 0);
        s.collateralOperational = Number(s.collateralOperational || s.bankroll || 0);
        s.currentGrossOpenNotional = Number(s.currentGrossOpenNotional || 0);
        s.peakGrossOpenNotional = Number(s.peakGrossOpenNotional || 0);
        s.accountOperationalNotionalLimit = Number(s.accountOperationalNotionalLimit || 0);
        s.hardAccountOperationalNotionalLimit = Number(s.hardAccountOperationalNotionalLimit || 0);
        s.strategyOperationalNotionalLimit = Number(s.strategyOperationalNotionalLimit || 0);
        s.capitalOperationalNotionalLimit = Number(s.capitalOperationalNotionalLimit || 0);
        s.configuredAccountOperationalMaxGrossNotional =
            isFinite(Number(s.configuredAccountOperationalMaxGrossNotional)) && Number(s.configuredAccountOperationalMaxGrossNotional) > 0
                ? Number(s.configuredAccountOperationalMaxGrossNotional)
                : null;
        s.minMarginLevel = isFinite(Number(s.minMarginLevel)) ? Number(s.minMarginLevel) : Number.POSITIVE_INFINITY;
        s.liquidationRiskCriticalCount = Number(s.liquidationRiskCriticalCount || 0);
        s.liquidationMarginLevelThresholdUsed = isFinite(Number(s.liquidationMarginLevelThresholdUsed))
            ? Number(s.liquidationMarginLevelThresholdUsed)
            : 0;
        s.isolatedLiquidationCount = Number(s.isolatedLiquidationCount || 0);
        s.accountLiquidationCount = Number(s.accountLiquidationCount || 0);
        s.accountLiquidated = !!s.accountLiquidated;
        s.accountLiquidatedTs = Number(s.accountLiquidatedTs || 0);
        s.openRejectedTotalCount = Number(s.openRejectedTotalCount || 0);
        s.openRejectedOperationalCapitalCount = Number(s.openRejectedOperationalCapitalCount || 0);
        s.openRejectedProjectedMarginCount = Number(s.openRejectedProjectedMarginCount || 0);
        s.openRejectedInsufficientCapitalCount = Number(
            s.openRejectedInsufficientCapitalCount || s.openRejectedOperationalCapitalCount || 0
        );
        s.openRejectedAccountLimitCount = Number(s.openRejectedAccountLimitCount || 0);

        s.hedgeActive = false;
        s.hedgeParentSide = null;
        s.hedgeActivatedTs = 0;
        s.hedgeWatchStartTs = 0;
        s.hedgeWatchInventoryAbs = 0;
        s.hedgeMinInventoryAbsSeen = 0;

        s.skippedClosesCount = Number(s.skippedClosesCount || 0);
        s.skippedClosesByReason = s.skippedClosesByReason || {};
        s.defensiveLossCloseCount = Number(s.defensiveLossCloseCount || 0);
        this.ensureManagedCycleState(s);

        s.cycleStarts = Number(s.cycleStarts || 0);
        s.cycleEnds = Number(s.cycleEnds || 0);
        s.iterationSeq = Number(s.iterationSeq || 0);
        this.ensureExecutionStats(s);

        return s;
    }

    private updateRecentCloses(state: any, close: number, maxLen = 120): void {
        state.recentCloses.push(Number(close));
        if (state.recentCloses.length > maxLen) {
            state.recentCloses.splice(0, state.recentCloses.length - maxLen);
        }
    }

    private flattenedPositions(state: any): Position[] {
        const longs: Position[] = Array.isArray(state.activePositions) ? state.activePositions : [];
        const shorts: Position[] = Array.isArray(state.shortPositions) ? state.shortPositions : [];
        return [...longs, ...shorts];
    }

    private findPositionById(state: any, positionId: any): Position | null {
        const targetId = String(positionId || '').trim();
        if (!targetId) return null;
        return this.flattenedPositions(state).find((position) => String(position.positionId || '').trim() === targetId) || null;
    }

    private isLiveRealMode(): boolean {
        return !!this._liveMode && this._liveExecutionMode === 'real';
    }

    private syncGlobalOpenPositions(state: any): void {
        if (this._liveMode) return;
        const globalRef: any = global as any;
        globalRef._bt_openPositions = this.flattenedPositions(state).map((p) => ({
            positionId: p.positionId,
            entryPrice: p.entryPrice,
            quantity: p.quantity,
            borrowedAmount: p.borrowedAmount,
            initialMargin: p.initialMargin,
            leverage: p.leverage,
            entryTime: p.entryTime,
            side: p.side,
            qgHedge: p.qgHedge,
            qgParentSide: p.qgParentSide,
            qgOpenReason: p.qgOpenReason,
            takePrice: p.takePrice,
        }));
    }

    private recordSignal(state: any, signal: any): void {
        if (!state) return;
        if (!Array.isArray(state.signals)) state.signals = [];
        const tsCandidate = signal?.time ?? signal?.closeTime ?? signal?.entryTime ?? state?._lastTickerTs ?? Date.now();
        const ts = Number(tsCandidate);
        const entry: any = {
            time: isFinite(ts) ? ts : Date.now(),
            ...signal,
        };
state.signals.push(entry);
    }

    private ensureExecutionStats(state: any): any {
        if (!state.executionStats || typeof state.executionStats !== 'object') state.executionStats = {};
        const stats = state.executionStats;
        const numericKeys = [
            'quotesPosted',
            'quoteTouchObserved',
            'quoteEligible',
            'quoteFills',
            'quoteBlockedByRest',
            'quoteBlockedByPenetration',
            'quoteBlockedByProb',
            'totalFillWaitCandles',
            'makerCloseCount',
            'marketCloseCount',
            'operationalPlanActiveCount',
            'operationalPlanPausedCount',
            'requoteChecks',
            'requoteTriggered',
            'requoteBlockedRateLimit',
            'buildQuotesCalls',
            'buildQuotesReturnedQuotes',
            'buildQuotesReturnedZeroCount',
            'quotesPruned',
        ];
        const mapKeys = [
            'quotesPostedByLevel',
            'quotesPostedByRegime',
            'operationalPauseReasons',
            'quoteTouchesByLevel',
            'quoteTouchesByRegime',
            'quoteEligibleByLevel',
            'quoteEligibleByRegime',
            'quoteFillsByLevel',
            'quoteFillsByRegime',
            'quoteBlockedByRestByLevel',
            'quoteBlockedByPenetrationByLevel',
            'quoteBlockedByProbByLevel',
            'fillWaitCandlesBuckets',
            'closeExecutionModeCounts',
        ];
        for (const key of numericKeys) stats[key] = Number(stats[key] || 0);
        for (const key of mapKeys) {
            if (!stats[key] || typeof stats[key] !== 'object') stats[key] = {};
        }
        stats.lastFillModel = String(stats.lastFillModel || '');
        return stats;
    }

    private incrementMapCounter(map: Record<string, number>, key: string, delta = 1): void {
        const normalized = String(key || 'unknown');
        map[normalized] = Number(map[normalized] || 0) + delta;
    }

    private fillWaitBucket(waitCandles: number): string {
        if (waitCandles <= 0) return '0';
        if (waitCandles === 1) return '1';
        if (waitCandles <= 3) return '2_3';
        if (waitCandles <= 5) return '4_5';
        return '6_plus';
    }

    private resolveFillModel(config: any) {
        const raw = String(config?.fillModel || 'realistic').toLowerCase();
        const model = raw === 'permissive' ? 'permissive' : (raw === 'pessimistic' ? 'pessimistic' : 'realistic');
        const configuredMinRestCandles = Math.max(
            0,
            Math.round(Number(config?.makerFillMinRestCandles ?? (model === 'permissive' ? 0 : (model === 'pessimistic' ? 2 : 1))))
        );
        const minRestCandles = this._liveMode && this._liveExecutionMode === 'demo'
            ? Math.max(1, configuredMinRestCandles)
            : configuredMinRestCandles;
        let penetrationTicks = Math.max(
            0,
            Math.round(Number(config?.makerFillPenetrationTicks ?? (model === 'permissive' ? 0 : (model === 'pessimistic' ? 2 : 1))))
        );
        if (this._liveMode && this._liveExecutionMode === 'demo') {
            penetrationTicks = Math.max(
                0,
                penetrationTicks + Math.round(Number(config?.demoMakerFillPenetrationTicksDelta ?? 0))
            );
        }
        return {
            model,
            minRestCandles,
            penetrationTicks,
            penetrationTicksPerLevel: Math.max(0, Math.round(Number(config?.makerFillPenetrationTicksPerLevel ?? 0))),
            levelProbStep: Number(config?.makerFillLevelProbStep ?? (model === 'permissive' ? 0 : (model === 'pessimistic' ? 0.08 : 0.05))),
            trendProbPenaltyPct: Math.max(0, Number(config?.makerFillTrendProbPenaltyPct ?? (model === 'permissive' ? 0 : (model === 'pessimistic' ? 0.15 : 0.08)))),
            highVolPenaltyPct: Math.max(0, Number(config?.makerFillHighVolPenaltyPct ?? (model === 'permissive' ? 0 : (model === 'pessimistic' ? 0.12 : 0.05)))),
            highVolAtrRatio: Math.max(1, Number(config?.makerFillHighVolAtrRatio ?? 1.25)),
        };
    }

    private inventoryQty(state: any): number {
        const longQty = (Array.isArray(state.activePositions) ? state.activePositions : [])
            .reduce((sum: number, p: Position) => sum + Number(p.quantity || 0), 0);
        const shortQty = (Array.isArray(state.shortPositions) ? state.shortPositions : [])
            .reduce((sum: number, p: Position) => sum + Number(p.quantity || 0), 0);
        return longQty - shortQty;
    }

    private flattenedNormalPositions(state: any): Position[] {
        return this.flattenedPositions(state).filter((position) => this.isEligibleForNormalLegManagement(position));
    }

    private flattenedTechnicalStopPositions(state: any, config: any): Position[] {
        const includeManagedFamilies = config?.technicalStopsIncludeManagedFamilies === true;
        if (includeManagedFamilies) {
            const includeHedges = config?.technicalStopsIncludeHedges === true
                || config?.managedCycleTechnicalStopsIncludeHedges === true;
            return this.flattenedPositions(state).filter((position) => !!position && (includeHedges || !position.qgHedge));
        }
        if (this.isManagedCycleModeEnabled(config) && config?.managedCycleTechnicalStopsIncludeHedges === true) {
            return this.flattenedPositions(state).filter((position) =>
                !!position
                && !this.isLongevityCycleManagedPosition(position)
                && !isRsiExtremeOverlayPosition(position)
            );
        }
        return this.flattenedNormalPositions(state);
    }

    private normalInventoryQty(state: any): number {
        return this.flattenedNormalPositions(state).reduce(
            (sum: number, position: Position) => sum + (position.side === 'long' ? 1 : -1) * Number(position.quantity || 0),
            0
        );
    }

    private normalInventoryNotional(state: any, price: number): number {
        return this.normalInventoryQty(state) * Math.max(0, Number(price || 0));
    }

    private countNormalPositions(state: any): number {
        return this.flattenedNormalPositions(state).length;
    }

    private inventoryNotional(state: any, price: number): number {
        return this.inventoryQty(state) * Math.max(0, Number(price || 0));
    }

    private grossOpenNotional(state: any, price: number): number {
        const effectivePrice = Math.max(0, Number(price || 0));
        return this.flattenedPositions(state)
            .reduce((sum: number, position: Position) => sum + (Math.abs(Number(position.quantity || 0)) * effectivePrice), 0);
    }

    private signedInventoryNotional(side: Side, notional: number): number {
        const value = Math.max(0, Number(notional || 0));
        return side === 'long' ? value : -value;
    }

    private reducesInventoryExposure(currentInventoryNotional: number, side: Side, notional: number): boolean {
        const current = Number(currentInventoryNotional || 0);
        const projected = current + this.signedInventoryNotional(side, notional);
        return Math.abs(projected) + 1e-8 < Math.abs(current);
    }

    private maxInventoryNotional(config: any, price: number): number {
        const levels = Math.max(1, Math.round(Number(config.levelsPerSide || 1)));
        const levelMultiplier = Math.max(1, Number(config.levelSizeMultiplier || 1));
        let ladderFactor = 0;
        for (let i = 0; i < levels; i++) {
            ladderFactor += Math.pow(levelMultiplier, i);
        }

        const base = Math.max(0, Number(config.baseNotionalPerOrder || 0)) * ladderFactor;
        const fallback = Math.max(
            0,
            qtyFromNotionalUsdRaw(
                Number(config.baseInitialMarginPerOrder || 0) * Number(config.leverageDefault || 1),
                Math.max(1e-8, price)
            ) * price * ladderFactor
        );
        return Math.max(1e-8, Math.max(base, fallback) * Math.max(1, Number(config.maxInventoryMultiple || 1)));
    }

    private deterministicGate(id: string, ts: number, threshold: number): boolean {
        const key = `${id}|${ts}`;
        let hash = 2166136261;
        for (let i = 0; i < key.length; i++) {
            hash ^= key.charCodeAt(i);
            hash = Math.imul(hash, 16777619);
        }
        const normalized = (hash >>> 0) / 4294967295;
        return normalized <= threshold;
    }

    private calcFundingCost(position: Position, markPrice: number, closeTs: number): number {
        const fundingMode = position.fundingFeeMode || 'none';
        if (fundingMode === 'none') return 0;

        const entryTs = Number(position.entryTime || closeTs);
        const elapsedMs = Math.max(0, closeTs - entryTs);
        const hours = elapsedMs / (60 * 60 * 1000);
        const interval = Math.max(0.25, Number(position.fundingIntervalHours || 8));
        const periods = hours / interval;
        if (!(periods > 0)) return 0;

        const notional = Math.max(0, Number(markPrice || 0) * Number(position.quantity || 0));
        const fundingRate = Number(position.fundingRatePer8h || 0);

        const ratePerInterval = fundingRate * (interval / 8);
        let funding = notional * ratePerInterval * periods;
        if (fundingMode === 'signed') funding *= (position.side === 'long' ? 1 : -1);
        if (fundingMode === 'absolute_cost') funding = Math.abs(funding);
        return isFinite(funding) ? funding : 0;
    }

    private estimatePositionNet(
        position: Position,
        markPrice: number,
        nowTs: number,
        closeModeOverride?: 'maker' | 'taker' | 'market'
    ): { net: number; gross: number; feeTotal: number; slippageTotal: number; interest: number; funding: number; feeOpen: number; feeClose: number; closeMode: 'maker' | 'taker' | 'market' } {
        const entryPrice = Number(position.entryPrice || 0);
        const exitPrice = Number(markPrice || 0);
        const quantity = Math.max(0, Number(position.quantity || 0));
        const borrowedAmount = Math.max(0, Number(position.borrowedAmount || 0));

        const openMode = this.normalizeExecutionMode(position.openExecutionMode || 'maker', 'maker');
        const closeMode = this.normalizeExecutionMode(closeModeOverride || position.closeExecutionMode || 'maker', 'maker');

        const openFeeRate = isFinite(Number(position.openFeeRate))
            ? Number(position.openFeeRate)
            : this.getPositionFeeRate(position, openMode, 0);
        const closeFeeRate = this.getPositionFeeRate(
            position,
            closeMode,
            isFinite(Number(position.closeFeeRate)) ? Number(position.closeFeeRate) : 0
        );

        const openSlip = isFinite(Number(position.openSlippagePercent))
            ? Number(position.openSlippagePercent)
            : this.getPositionSlippage(position, openMode, 0);
        const closeSlip = this.getPositionSlippage(
            position,
            closeMode,
            isFinite(Number(position.closeSlippagePercent)) ? Number(position.closeSlippagePercent) : 0
        );

        const entryNotional = Math.max(0, entryPrice * quantity);
        const closeNotional = Math.max(0, exitPrice * quantity);
        const gross = position.side === 'long'
            ? (exitPrice - entryPrice) * quantity
            : (entryPrice - exitPrice) * quantity;

        const feeOpen = entryNotional * openFeeRate;
        const feeClose = closeNotional * closeFeeRate;
        const feeTotal = feeOpen + feeClose;

        const slippageTotal = (entryNotional * openSlip) + (closeNotional * closeSlip);

        const safeCloseTime = Number(nowTs || Date.now());
        const safeEntryTime = Number(position.entryTime || safeCloseTime);
        const holdingMs = Math.max(1, safeCloseTime - safeEntryTime);
        const holdingDays = Math.max(holdingMs / (24 * 60 * 60 * 1000), 1 / 1440);
        const interestBase = borrowedAmount * Number(position.dailyInterestRate || 0) * holdingDays;

        const funding = this.calcFundingCost(position, markPrice, nowTs);
        const interest = interestBase;
        const net = gross - feeTotal - slippageTotal - interest - funding;

        return {
            net,
            gross,
            feeTotal,
            slippageTotal,
            interest,
            funding,
            feeOpen,
            feeClose,
            closeMode,
        };
    }

    private totalUnrealizedNet(state: any, markPrice: number, nowTs: number): number {
        return this.flattenedPositions(state)
            .reduce((sum: number, p: Position) => sum + this.estimatePositionNet(p, markPrice, nowTs).net, 0);
    }

    private estimateLiquidationDistancePct(position: Position, markPrice: number): number {
        const entry = Number(position.entryPrice || 0);
        const lev = Math.max(1, Number(position.leverage || 1));
        const mmrRaw = Number(position.maintenanceMarginRate ?? this.config?.maintenanceMarginRate ?? 0.01);
        const mmr = Number.isFinite(mmrRaw) ? Math.max(0, Math.min(0.5, mmrRaw)) : 0.01;
        if (!(entry > 0) || !(markPrice > 0)) return Number.POSITIVE_INFINITY;

        if (position.side === 'long') {
            const liq = entry * Math.max(0.01, (1 - (1 / lev) + mmr));
            return ((markPrice - liq) / markPrice) * 100;
        }

        const liq = entry * (1 + (1 / lev) - mmr);
        return ((liq - markPrice) / markPrice) * 100;
    }

    private minLiquidationDistancePct(state: any, markPrice: number): number {
        const open = this.flattenedPositions(state);
        if (!open.length) return Number.POSITIVE_INFINITY;
        let minDist = Number.POSITIVE_INFINITY;
        for (const p of open) {
            const dist = this.estimateLiquidationDistancePct(p, markPrice);
            if (isFinite(dist)) minDist = Math.min(minDist, dist);
        }
        return minDist;
    }

    private classifyRegime(state: any, indicators: any, config: any): Regime {
        const ema = Number(indicators?.ema ?? NaN);
        const emaPrev = Number(indicators?.emaPrev ?? NaN);
        const atr = Number(indicators?.atr ?? NaN);
        const atrAverage = Number(indicators?.atrAverage30 ?? indicators?.atrAverage ?? NaN);
        const closes: number[] = Array.isArray(state.recentCloses) ? state.recentCloses : [];
        const lookback = Math.max(3, Number(config.regimeDirectionalLookback || 6));
        const window = closes.slice(Math.max(0, closes.length - lookback));

        let up = 0;
        let down = 0;
        for (let i = 1; i < window.length; i++) {
            const diff = Number(window[i]) - Number(window[i - 1]);
            if (diff > 0) up += 1;
            else if (diff < 0) down += 1;
        }

        const directionalBias = window.length > 1 ? ((up - down) / (window.length - 1)) : 0;
        const price = Number(closes.length ? closes[closes.length - 1] : state.referenceMid || 0);
        const emaSlopePct = (isFinite(ema) && isFinite(emaPrev) && Math.abs(emaPrev) > 1e-8)
            ? (((ema - emaPrev) / emaPrev) * 100)
            : 0;
        const atrNowPct = (isFinite(atr) && price > 0) ? Math.abs(atr / price) * 100 : 0;
        const atrAvgPct = (isFinite(atrAverage) && price > 0) ? Math.abs(atrAverage / price) * 100 : atrNowPct;
        const aggressiveVol = atrAvgPct > 0
            ? atrNowPct >= (atrAvgPct * Number(config.regimeAtrRelativeThreshold || 1.25))
            : false;

        const slopeThreshold = Number(config.regimeEmaSlopeThresholdPct || 0.04);
        const slopeStrong = Number(config.regimeEmaSlopeStrongPct || 0.08);
        const biasThreshold = Number(config.regimeDirectionalBiasThreshold || 0.55);

        const upSignal = emaSlopePct >= slopeThreshold
            && directionalBias >= biasThreshold
            && (aggressiveVol || emaSlopePct >= slopeStrong);

        const downSignal = emaSlopePct <= -slopeThreshold
            && directionalBias <= -biasThreshold
            && (aggressiveVol || emaSlopePct <= -slopeStrong);

        if (upSignal) return 'TREND_UP';
        if (downSignal) return 'TREND_DOWN';
        return 'RANGE';
    }

    private resolveStableRegime(state: any, config: any, rawRegime: Regime): Regime {
        const current = (state.regime || 'RANGE') as Regime;
        const confirmTicks = Math.max(1, Number(config.regimeFlipConfirmTicks || 2));
        const minHoldTicks = Math.max(0, Number(config.regimeMinHoldTicks || 2));

        state.regimeAgeTicks = Number(state.regimeAgeTicks || 0) + 1;
        if (rawRegime === current) {
            state.pendingRegime = '';
            state.pendingRegimeTicks = 0;
            return current;
        }

        if (String(state.pendingRegime || '') === rawRegime) {
            state.pendingRegimeTicks = Number(state.pendingRegimeTicks || 0) + 1;
        } else {
            state.pendingRegime = rawRegime;
            state.pendingRegimeTicks = 1;
        }

        if (state.regimeAgeTicks < minHoldTicks) return current;
        if (Number(state.pendingRegimeTicks || 0) < confirmTicks) return current;

        state.pendingRegime = '';
        state.pendingRegimeTicks = 0;
        state.regimeAgeTicks = 0;
        return rawRegime;
    }

    private evaluateRisk(state: any, config: any, price: number, nowTs: number, regime: Regime) {
        const marginUsed = this.flattenedPositions(state)
            .reduce((sum: number, p: Position) => sum + Math.max(0, Number(p.initialMargin || 0)), 0);

        const unrealized = this.totalUnrealizedNet(state, price, nowTs);
        const realized = Number(state.totalRealizedNet || 0);
        const bankroll = Math.max(1e-8, Number(state.bankroll || config.bankroll || 1));
        const equity = bankroll + realized + unrealized;
        const realizedCollateral = bankroll + realized;
        const collateralOperational = Math.min(realizedCollateral, equity);
        const available = equity - marginUsed;
        const availableOperational = collateralOperational - marginUsed;
        const marginLevel = marginUsed > 1e-8 ? (equity / marginUsed) : Number.POSITIVE_INFINITY;
        const leverageDefault = Math.max(1, Number(config.leverageDefault || 1));
        const strategyOperationalNotionalLimit = this.maxInventoryNotional(config, price);
        const configuredAccountOperationalMaxGrossNotional =
            isFinite(Number(config.accountOperationalMaxGrossNotional)) && Number(config.accountOperationalMaxGrossNotional) > 0
                ? Number(config.accountOperationalMaxGrossNotional)
                : null;
        const capitalOperationalNotionalLimit = Math.max(0, collateralOperational) * leverageDefault * Number(config.maxMarginUsedPct || 0.9);
        const hardAccountOperationalNotionalLimitRaw = Math.min(
            configuredAccountOperationalMaxGrossNotional != null
                ? configuredAccountOperationalMaxGrossNotional
                : Number.POSITIVE_INFINITY,
            capitalOperationalNotionalLimit > 0
                ? capitalOperationalNotionalLimit
                : Number.POSITIVE_INFINITY
        );
        const hardAccountOperationalNotionalLimit = Number.isFinite(hardAccountOperationalNotionalLimitRaw)
            ? Math.max(0, hardAccountOperationalNotionalLimitRaw)
            : 0;
        const accountOperationalNotionalLimit = Math.max(
            0,
            Math.min(
                configuredAccountOperationalMaxGrossNotional != null ? configuredAccountOperationalMaxGrossNotional : Number.POSITIVE_INFINITY,
                strategyOperationalNotionalLimit > 0 ? strategyOperationalNotionalLimit : Number.POSITIVE_INFINITY,
                capitalOperationalNotionalLimit > 0 ? capitalOperationalNotionalLimit : Number.POSITIVE_INFINITY
            )
        );
        const grossOpenNotional = this.grossOpenNotional(state, price);

        let peakEquity = Math.max(Number(state.peakEquity || bankroll), equity);
        state.peakGrossOpenNotional = Math.max(Number(state.peakGrossOpenNotional || 0), grossOpenNotional);
        let drawdownPct = peakEquity > 0
            ? Math.max(0, (peakEquity - equity) / peakEquity)
            : 0;

        // Reancora o pico operacional quando o inventario zera. Sem isso, o backtest
        // fica preso em modo defensivo apos um airbag realizar prejuizo e nao mede a retomada.
        if (this.flattenedPositions(state).length <= 0) {
            peakEquity = Math.max(bankroll, equity);
            drawdownPct = 0;
        }

        state.peakEquity = peakEquity;

        const marginUsedPct = marginUsed / bankroll;
        const inventory = this.inventoryNotional(state, price);
        const maxInventory = this.maxInventoryNotional(config, price);
        const inventoryRatio = maxInventory > 0 ? inventory / maxInventory : 0;

        const wrongTrendExposure =
            (regime === 'TREND_UP' && inventoryRatio < -Number(config.directionalExposureLimitPct || 0.65)) ||
            (regime === 'TREND_DOWN' && inventoryRatio > Number(config.directionalExposureLimitPct || 0.65));

        const minLiqDistancePct = this.minLiquidationDistancePct(state, price);

        const criticalPause = marginUsedPct >= Number(config.maxMarginUsedPct || 0.9)
            || drawdownPct >= Number(config.maxDrawdownPct || 0.25)
            || minLiqDistancePct <= Number(config.liquidationBufferPctCritical || 2.5);

        const requireRebase = marginUsedPct >= Number(config.marginRebaseThresholdPct || 0.75)
            || drawdownPct >= Number(config.drawdownRebaseThresholdPct || 0.2);

        const defensive = marginUsedPct >= Number(config.marginDefensiveThresholdPct || 0.6)
            || drawdownPct >= Number(config.drawdownDefensiveThresholdPct || 0.14)
            || wrongTrendExposure;

        const reduced = marginUsedPct >= Number(config.marginReduceThresholdPct || 0.45)
            || drawdownPct >= Number(config.drawdownReduceThresholdPct || 0.08);

        state.marginUsed = marginUsed;
        state.marginUsedPct = marginUsedPct;
        state.drawdownPct = drawdownPct;
        state.availableBalance = available;
        state.availableOperationalBalance = availableOperational;
        state.realizedCollateral = realizedCollateral;
        state.collateralOperational = collateralOperational;
        state.currentGrossOpenNotional = grossOpenNotional;
        state.accountOperationalNotionalLimit = accountOperationalNotionalLimit;
        state.hardAccountOperationalNotionalLimit = hardAccountOperationalNotionalLimit;
        state.strategyOperationalNotionalLimit = strategyOperationalNotionalLimit;
        state.capitalOperationalNotionalLimit = capitalOperationalNotionalLimit;
        state.configuredAccountOperationalMaxGrossNotional = configuredAccountOperationalMaxGrossNotional;
        if (isFinite(marginLevel)) {
            const prevMinMarginLevel = Number(state.minMarginLevel);
            state.minMarginLevel = isFinite(prevMinMarginLevel)
                ? Math.min(prevMinMarginLevel, marginLevel)
                : marginLevel;
        }

        return {
            marginUsed,
            marginUsedPct,
            equity,
            unrealized,
            realized,
            available,
            availableOperational,
            realizedCollateral,
            collateralOperational,
            marginLevel,
            grossOpenNotional,
            accountOperationalNotionalLimit,
            hardAccountOperationalNotionalLimit,
            strategyOperationalNotionalLimit,
            capitalOperationalNotionalLimit,
            drawdownPct,
            minLiqDistancePct,
            criticalPause,
            requireRebase,
            defensive,
            reduced,
        };
    }

    private countPositions(state: any): number {
        return (Array.isArray(state.activePositions) ? state.activePositions.length : 0)
            + (Array.isArray(state.shortPositions) ? state.shortPositions.length : 0);
    }

    private capitalRequiredToOpen(initialMargin: number, notional: number, openFeeRate: number, openSlippagePercent: number): number {
        const feeReserve = Math.max(0, notional * Math.max(0, openFeeRate));
        const slippageReserve = Math.max(0, notional * Math.max(0, openSlippagePercent));
        return Math.max(0, initialMargin) + feeReserve + slippageReserve;
    }

    private usesIsolatedLiquidation(config: any): boolean {
        return String(config?.marginMode || 'isolated').toLowerCase() !== 'cross';
    }

    private shouldLiquidateIsolatedPosition(position: Position, config: any, markPrice: number, nowTs: number): boolean {
        const liqDistancePct = this.estimateLiquidationDistancePct(position, markPrice);
        if (isFinite(liqDistancePct) && liqDistancePct <= 0) return true;

        const calc = this.estimatePositionNet(position, markPrice, nowTs, 'market');
        const maintenanceMarginPct = Math.max(0, Number(config.maintenanceMarginRate ?? 0.01));
        const liquidationLossLimit = -Math.max(0, Number(position.initialMargin || 0)) * (1 - maintenanceMarginPct);
        return calc.net <= liquidationLossLimit;
    }

    private runIsolatedLiquidations(state: any, config: any, markPrice: number, nowTs: number): number {
        if (!this.usesIsolatedLiquidation(config)) return 0;
        const liquidationReasons = this.resolveLiquidationReasons(config);
        let liquidated = 0;
        for (const position of [...this.flattenedPositions(state)]) {
            if (!this.shouldLiquidateIsolatedPosition(position, config, markPrice, nowTs)) continue;
            const result = this.closePosition(
                state,
                config,
                position,
                markPrice,
                nowTs,
                liquidationReasons.isolated,
                position.quantity,
                true,
                'market'
            );
            if (!result.closed) continue;
            liquidated += 1;
            state.isolatedLiquidationCount = Number(state.isolatedLiquidationCount || 0) + 1;
        }
        if (liquidated > 0) {
            this.recordSignal(state, {
                type: 'liquidation',
                side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
                reason: liquidationReasons.isolated,
                time: nowTs,
                closedCount: liquidated,
                markPrice,
            });
        }
        return liquidated;
    }

    private maybeLiquidateAccount(state: any, config: any, markPrice: number, nowTs: number, risk: any): { liquidated: boolean; risk: any } {
        const liquidationReasons = this.resolveLiquidationReasons(config);
        state.liquidationMarginLevelThresholdUsed = 0;
        if (risk.criticalPause) {
            state.liquidationRiskCriticalCount = Number(state.liquidationRiskCriticalCount || 0) + 1;
        }

if (state.accountLiquidated) {
            return { liquidated: true, risk };
        }

        if (!(this.countPositions(state) > 0 && Number(risk.equity || 0) <= 0)) {
            return { liquidated: false, risk };
        }

        state.pendingQuotes = [];
        const closedCount = this.closeAllPositions(
            state,
            config,
            markPrice,
            nowTs,
            liquidationReasons.account,
            true,
            'market'
        );
        state.accountLiquidated = true;
        state.accountLiquidatedTs = nowTs;
        state.accountLiquidationCount = Number(state.accountLiquidationCount || 0) + 1;
        this.recordSignal(state, {
            type: 'liquidation',
            side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
            reason: liquidationReasons.account,
            time: nowTs,
            closedCount,
            equityBefore: Number(risk.equity || 0),
            markPrice,
        });

        const nextRisk = this.evaluateRisk(state, config, markPrice, nowTs, (state.regime || 'RANGE') as Regime);
        return { liquidated: true, risk: nextRisk };
    }

    private nextPositionId(state: any): string {
        state._positionSeq = Number(state._positionSeq || 0) + 1;
        return `MK_${state._positionSeq}`;
    }

    private sideArray(state: any, side: Side): Position[] {
        return side === 'long' ? state.activePositions : state.shortPositions;
    }

    private bumpCounterMap(target: Record<string, number> | undefined, key: string): Record<string, number> {
        const out = target && typeof target === 'object' ? target : Object.create(null);
        out[key] = Number(out[key] || 0) + 1;
        return out;
    }

    private annotateRebaseAttempt(position: Position, closeReason: string, triggerReasons: string[], nowTs: number): void {
        position.rebaseAttemptCount = Number(position.rebaseAttemptCount || 0) + 1;
        position.rebaseAttemptReasons = this.bumpCounterMap(position.rebaseAttemptReasons, closeReason);
        if (triggerReasons.includes('stale_cycle_rebase')) {
            position.staleCycleRebaseAttemptCount = Number(position.staleCycleRebaseAttemptCount || 0) + 1;
        }
        if (triggerReasons.includes('inventory_time_limit')) {
            position.inventoryTimeLimitAttemptCount = Number(position.inventoryTimeLimitAttemptCount || 0) + 1;
        }
        if (triggerReasons.includes('risk_rebase')) {
            position.riskRebaseAttemptCount = Number(position.riskRebaseAttemptCount || 0) + 1;
        }
        position.lastRebaseAttemptTs = nowTs;
        position.lastRebaseAttemptCloseReason = closeReason;
        position.lastRebaseTriggerReasons = [...triggerReasons];
        position.lastRebaseBlockedReason = null;
    }

    private annotateRebaseBlocked(position: Position, blockReason: string): void {
        position.rebaseBlockedCount = Number(position.rebaseBlockedCount || 0) + 1;
        position.rebaseBlockedByReason = this.bumpCounterMap(position.rebaseBlockedByReason, blockReason);
        position.lastRebaseBlockedReason = blockReason;
    }

    private isLongevityCycleManagedPosition(position: Position | undefined | null): boolean {
        return !!String((position as any)?.longevityRebasePairId || '').trim();
    }

    private isEligibleForNormalLegManagement(position: Position | undefined | null): boolean {
        return !!position && !position.qgHedge && !this.isLongevityCycleManagedPosition(position) && !isRsiExtremeOverlayPosition(position);
    }

    private hasActiveLongevityCycle(state: any): boolean {
        return this.flattenedPositions(state).some((p) => this.isLongevityCycleManagedPosition(p));
    }

    private openPosition(
        state: any,
        config: any,
        symbolInfo: any,
        params: {
            side: Side;
            qty: number;
            price: number;
            takePrice: number;
            nowTs: number;
            reason: string;
            sourceQuoteId: string;
            qgHedge?: boolean;
            qgParentSide?: Side;
            longevityRebasePairId?: string;
            isLongevityRebaseStuck?: boolean;
            longevityRebaseLevel?: number;
        }
    ): Position | null {
        const qty = this.normalizeQty(params.qty, config);
        if (!(qty > 0)) return null;

        if (String(config.tradeType || '').toLowerCase() === 'stockmarket') {
            const maxOpen = Math.max(1, Number(config.stockMarketMaxOpenPositions ?? 2));
            const openNow = this.countPositions(state);
            if (openNow >= maxOpen) {
                state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
                state.openRejectedByReason = state.openRejectedByReason && typeof state.openRejectedByReason === 'object'
                    ? state.openRejectedByReason : {};
                state.openRejectedByReason.stockmarket_max_open_positions = Number(state.openRejectedByReason.stockmarket_max_open_positions || 0) + 1;
                return null;
            }
            const maxPerSide = Math.max(1, Number(config.stockMarketMaxOpenPositionsPerSide ?? 1));
            const openOnSide = this.flattenedPositions(state).filter((position) => position.side === params.side).length;
            if (!params.qgHedge && openOnSide >= maxPerSide) {
                state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
                state.openRejectedByReason = state.openRejectedByReason && typeof state.openRejectedByReason === 'object'
                    ? state.openRejectedByReason : {};
                state.openRejectedByReason.stockmarket_max_open_positions_per_side = Number(
                    state.openRejectedByReason.stockmarket_max_open_positions_per_side || 0
                ) + 1;
                return null;
            }
        }

        const lossCapReentryCooldown = resolveLossCapCooldownStatus(
            state,
            config,
            params.nowTs,
            { side: params.side, forReentry: true }
        );
        if (!params.qgHedge && lossCapReentryCooldown.active) {
            const rejectReason = 'max_unrealized_loss_cap_reentry_cooldown';
            state.lastOpenRejectReason = rejectReason;
            state.lastOpenRejectDetails = {
                context: 'open_position',
                side: params.side,
                requestedQty: qty,
                price: Number(params.price || 0),
                sourceQuoteId: String(params.sourceQuoteId || ''),
                openReason: String(params.reason || ''),
                lastCloseTs: lossCapReentryCooldown.lastCloseTs,
                lastCloseSide: lossCapReentryCooldown.lastCloseSide,
                remainingMs: lossCapReentryCooldown.remainingMs,
            };
            state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
            state.openRejectedByReason = state.openRejectedByReason && typeof state.openRejectedByReason === 'object'
                ? state.openRejectedByReason
                : {};
            state.openRejectedByReason[rejectReason] = Number(state.openRejectedByReason[rejectReason] || 0) + 1;
            return null;
        }

        const minNotional = Number(config.minNotional || 0);
        const notional = qty * Math.max(0, Number(params.price || 0));
        if (minNotional > 0 && notional < minNotional) return null;

        // Kill-switch de session drawdown: bloqueia aberturas primarias quando equity MTM
        // cai abaixo do limiar (paridade mk_validate_final_v4). Hedges continuam permitidos.
        const isPairedRangeBootstrap = String(params.reason || '').trim() === IH_PAIRED_RANGE_BOOTSTRAP_REASON;
        if (!params.qgHedge && !isPairedRangeBootstrap) {
            this.evaluateRisk(
                state,
                config,
                Number(params.price || 0),
                params.nowTs,
                (state.regime || 'RANGE') as Regime
            );
            const sessionHalt = this.resolveSessionBankrollHaltDecision(
                state,
                config,
                Number(params.price || 0),
                params.nowTs
            );
            if (sessionHalt.blocked) {
                state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
                state.openRejectedSessionBankrollHaltCount = Number(state.openRejectedSessionBankrollHaltCount || 0) + 1;
                this.recordSignal(state, {
                    type: 'open_rejected',
                    side: params.side,
                    reason: 'session_bankroll_drawdown_halt',
                    time: params.nowTs,
                    requestedQty: qty,
                    requestedPrice: params.price,
                    realizedNet: sessionHalt.realizedNow,
                    unrealizedNet: sessionHalt.unrealizedNow,
                    equityNow: sessionHalt.equityNow,
                    peakEquity: sessionHalt.peakEquity,
                    initialBankroll: sessionHalt.initialBankroll,
                    sessionDrawdownPct: sessionHalt.sessionDrawdownPct,
                    sessionHaltPct: sessionHalt.sessionHaltPct,
                });
                return null;
            }
        }

        const equityHaltPct = Math.max(
            0,
            Number(
                (config as any)?.equityDrawdownHaltPct
                ?? (config as any)?.quantumEquityDrawdownHaltPct
                ?? state?.equityDrawdownHaltPct
                ?? (this.config as any)?.equityDrawdownHaltPct
                ?? 0
            )
        );
        if (equityHaltPct > 0 && !params.qgHedge && !isPairedRangeBootstrap) {
            const initialBankroll = Math.max(
                1e-8,
                Number(state?.initialBankroll ?? config?.bankroll ?? (this.config as any)?.bankroll ?? 0)
            );
            const markPrice = Number(params.price || state?.lastMarkPrice || state?.markPrice || 0);
            const unrealizedNet = markPrice > 0
                ? this.totalUnrealizedNet(state, markPrice, params.nowTs)
                : 0;
            const bankrollNow = Math.max(1e-8, Number(state?.bankroll ?? config?.bankroll ?? initialBankroll));
            const equity = bankrollNow + Number(state?.totalRealizedNet || 0) + unrealizedNet;
            const drawdownPct = Math.max(0, (initialBankroll - equity) / initialBankroll);
            if (drawdownPct >= equityHaltPct) {
                state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
                state.openRejectedEquityDrawdownHaltCount = Number(state.openRejectedEquityDrawdownHaltCount || 0) + 1;
                this.recordSignal(state, {
                    type: 'open_rejected',
                    side: params.side,
                    reason: 'equity_drawdown_halt',
                    time: params.nowTs,
                    requestedQty: qty,
                    requestedPrice: params.price,
                    equity,
                    equityDrawdownPct: drawdownPct,
                    equityHaltPct,
                    unrealizedNet,
                });
                return null;
            }
        }

        const leverage = Math.max(1, Number(config.leverageDefault || symbolInfo?.leverage || 1));
        const makerFeeRate = Number(symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? 0);
        const takerFeeRate = Number(symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0);

        const openMode = this.resolveOpenExecutionMode(config, params.reason, !!params.qgHedge);
        const closeMode = !!params.qgHedge
            ? resolveInstitutionalPlannedHedgeCloseMode(config)
            : this.normalizeExecutionMode(config?.closeExecutionMode || 'maker', 'maker');

        const makerSlippagePercent = Number(config.makerSlippagePercent ?? 0);
        const takerSlippagePercent = Number(config.takerSlippagePercent ?? 0.00015);

        const openFeeRate = this.getModeFeeRate(openMode, makerFeeRate, takerFeeRate);
        const closeFeeRate = this.getModeFeeRate(closeMode, makerFeeRate, takerFeeRate);
        const openSlippagePercent = this.getModeSlippage(
            openMode,
            makerSlippagePercent,
            takerSlippagePercent
        );
        const closeSlippagePercent = this.getModeSlippage(
            closeMode,
            makerSlippagePercent,
            takerSlippagePercent
        );

        const { initialMargin, borrowedAmount } = computeNotionalAndMargin(params.price, qty, leverage);
        const entryRisk = this.evaluateRisk(state, config, Math.max(0, Number(params.price || 0)), params.nowTs, (state.regime || 'RANGE') as Regime);
        const cmiSessionGate = (!params.qgHedge && !isPairedRangeBootstrap)
            ? this.resolveSessionBankrollHaltDecision(state, config, Number(params.price || 0), params.nowTs)
            : { blocked: false };
        if (!cmiSessionGate.blocked && tryCriticalMarginInjection({
            state,
            config,
            risk: entryRisk,
            markPrice: Math.max(0, Number(params.price || 0)),
            intendedSide: params.side,
            initialMargin,
            notional,
            openFeeRate,
            openSlippagePercent,
            nowTs: params.nowTs,
            reason: params.reason,
            sourceQuoteId: params.sourceQuoteId,
            isHedge: !!params.qgHedge,
            normalPositions: this.flattenedNormalPositions(state),
            allowMarginHeadroomFallback: true,
            recordSignal: (signalState, signal) => this.recordSignal(signalState, signal),
        })) {
            return null;
        }
        const requiredCapital = this.capitalRequiredToOpen(initialMargin, notional, openFeeRate, openSlippagePercent);
        const availableOperational = Number(entryRisk.availableOperational || 0);
        const collateralOperational = Math.max(1e-8, Number(entryRisk.collateralOperational || 0));
        const projectedMarginUsed = Number(entryRisk.marginUsed || 0) + initialMargin;
        const projectedMarginUsedPctOperational = projectedMarginUsed / collateralOperational;
        const maxMarginUsedPct = Number(config.maxMarginUsedPct || 0.9);
        const currentGrossOpenNotional = Number(entryRisk.grossOpenNotional || 0);
        const projectedGrossOpenNotional = currentGrossOpenNotional + notional;
        const strategyOperationalNotionalLimit = Math.max(0, Number(entryRisk.strategyOperationalNotionalLimit || 0));
        const hardAccountOperationalNotionalLimit = Math.max(0, Number(entryRisk.hardAccountOperationalNotionalLimit || 0));
        const currentInventoryNotional = this.inventoryNotional(state, Math.max(0, Number(params.price || 0)));
        const inventoryReductionOpen = this.reducesInventoryExposure(currentInventoryNotional, params.side, notional);
        const isManagedCycleProtectionOpen = String(params.reason || '').trim().toLowerCase().startsWith('managed_cycle_');
        const rawProtectionMaxMarginUsedPct = Number(
            config?.managedCycleProtectionMaxMarginUsedPct ?? config?.cycleProtectionMaxMarginUsedPct ?? maxMarginUsedPct
        );
        const managedCycleProtectionMaxMarginUsedPct = isManagedCycleProtectionOpen
            ? Math.max(
                maxMarginUsedPct,
                Number.isFinite(rawProtectionMaxMarginUsedPct) ? rawProtectionMaxMarginUsedPct : maxMarginUsedPct
            )
            : maxMarginUsedPct;
        const rawProtectionCapitalCoverage = Number(
            config?.managedCycleProtectionCapitalCoverage ?? config?.cycleProtectionCapitalCoverage ?? 1
        );
        const managedCycleProtectionCapitalCoverage = isManagedCycleProtectionOpen
            ? Math.max(0, Math.min(1, Number.isFinite(rawProtectionCapitalCoverage) ? rawProtectionCapitalCoverage : 1))
            : 1;
        const requiredCapitalForGate = requiredCapital * managedCycleProtectionCapitalCoverage;
        const effectiveStrategyOperationalNotionalLimit = strategyOperationalNotionalLimit > 0
            ? strategyOperationalNotionalLimit + (isManagedCycleProtectionOpen ? notional : 0)
            : 0;
        const exceedsStrategyOperationalLimit =
            effectiveStrategyOperationalNotionalLimit > 0 &&
            projectedGrossOpenNotional > (effectiveStrategyOperationalNotionalLimit + 1e-8) &&
            !inventoryReductionOpen;
        const exceedsHardAccountOperationalLimit =
            hardAccountOperationalNotionalLimit > 0 &&
            projectedGrossOpenNotional > (hardAccountOperationalNotionalLimit + 1e-8);
        const insufficientOperationalCapital = availableOperational < requiredCapitalForGate;
        const exceedsProjectedMarginLimit = projectedMarginUsedPctOperational > managedCycleProtectionMaxMarginUsedPct;
        if (
            insufficientOperationalCapital
            || exceedsProjectedMarginLimit
            || exceedsStrategyOperationalLimit
            || exceedsHardAccountOperationalLimit
        ) {
            state.openRejectedTotalCount = Number(state.openRejectedTotalCount || 0) + 1;
            if (insufficientOperationalCapital) {
                state.openRejectedOperationalCapitalCount = Number(state.openRejectedOperationalCapitalCount || 0) + 1;
                // Legacy alias kept in state for backward compatibility with old readers.
                state.openRejectedInsufficientCapitalCount = Number(state.openRejectedInsufficientCapitalCount || 0) + 1;
            }
            if (exceedsProjectedMarginLimit) {
                state.openRejectedProjectedMarginCount = Number(state.openRejectedProjectedMarginCount || 0) + 1;
            }
            if (exceedsStrategyOperationalLimit || exceedsHardAccountOperationalLimit) {
                state.openRejectedAccountLimitCount = Number(state.openRejectedAccountLimitCount || 0) + 1;
            }
            this.recordSignal(state, {
                type: 'open_rejected',
                side: params.side,
                reason: exceedsHardAccountOperationalLimit
                    ? 'account_operational_hard_notional_limit'
                    : exceedsStrategyOperationalLimit
                        ? 'account_operational_notional_limit'
                    : insufficientOperationalCapital
                    ? 'insufficient_operational_capital'
                    : 'projected_margin_limit',
                time: params.nowTs,
                requestedQty: qty,
                requestedPrice: params.price,
                initialMarginRequired: initialMargin,
                requiredCapital,
                requiredCapitalForGate,
                managedCycleProtectionOpen: isManagedCycleProtectionOpen,
                managedCycleProtectionCapitalCoverage,
                availableOperational,
                projectedMarginUsed,
                projectedMarginUsedPctOperational,
                maxMarginUsedPct,
                managedCycleProtectionMaxMarginUsedPct,
                projectedGrossOpenNotional,
                currentInventoryNotional,
                inventoryReductionOpen,
                strategyOperationalNotionalLimit,
                strategyOperationalNotionalLimitApplied: effectiveStrategyOperationalNotionalLimit,
                hardAccountOperationalNotionalLimit,
                managedCycleProtectionAllowanceNotional: isManagedCycleProtectionOpen ? notional : 0,
            });
            return null;
        }

        const position: Position = {
            positionId: this.nextPositionId(state),
            side: params.side,
            quantity: qty,
            entryPrice: params.price,
            takePrice: params.takePrice,
            entryTime: params.nowTs,
            initialMargin,
            borrowedAmount,
            leverage,
            makerFeeRate,
            takerFeeRate,
            makerSlippagePercent,
            takerSlippagePercent,
            openFeeRate,
            closeFeeRate,
            openSlippagePercent,
            closeSlippagePercent,
            openExecutionMode: openMode,
            closeExecutionMode: closeMode,
            dailyInterestRate: Number(config.defaultDailyInterestRate || 0),
            fundingRatePer8h: Number(config.defaultFundingRatePer8h || 0),
            fundingIntervalHours: Number(config.fundingIntervalHours || 8),
            fundingFeeMode: config.fundingFeeMode || 'signed',
            maintenanceMarginRate: Number(config.maintenanceMarginRate ?? 0.01),
            qgHedge: !!params.qgHedge,
            qgParentSide: (params.qgParentSide || params.side) as Side,
            qgOpenReason: params.reason,
            sourceQuoteId: params.sourceQuoteId,
            longevityRebasePairId: params.longevityRebasePairId,
            isLongevityRebaseStuck: !!params.isLongevityRebaseStuck,
            longevityRebaseLevel: params.longevityRebaseLevel,
            rebaseAttemptCount: 0,
            staleCycleRebaseAttemptCount: 0,
            inventoryTimeLimitAttemptCount: 0,
            riskRebaseAttemptCount: 0,
            rebaseBlockedCount: 0,
            rebasePartialCleanupCount: 0,
            rebaseAttemptReasons: Object.create(null),
            rebaseBlockedByReason: Object.create(null),
            lastRebaseAttemptTs: 0,
            lastRebaseAttemptCloseReason: '',
            lastRebaseTriggerReasons: [],
            lastRebaseBlockedReason: null,
        };

        const overlayPosition = isRsiExtremeOverlayPosition(position);
        const before = overlayPosition ? this.countNormalPositions(state) : this.countPositions(state);
        this.sideArray(state, params.side).push(position);
        const after = overlayPosition ? this.countNormalPositions(state) : this.countPositions(state);

        if (!overlayPosition && before === 0 && after > 0) {
            state.cycleStarts = Number(state.cycleStarts || 0) + 1;
            this._cycleStarts += 1;
        }

        this.recordSignal(state, {
            type: 'open',
            side: params.side,
            price: position.entryPrice,
            entryPrice: position.entryPrice,
            quantity: position.quantity,
            entryTime: params.nowTs,
            reason: params.reason,
            qgOpenReason: params.reason,
            qgHedge: position.qgHedge,
            qgParentSide: position.qgParentSide,
            qgId: position.positionId,
            longevityRebasePairId: position.longevityRebasePairId,
            longevityRebaseLevel: position.longevityRebaseLevel,
            initialMargin: position.initialMargin,
            borrowedAmount: position.borrowedAmount,
            leverage: position.leverage,
            sourceQuoteId: params.sourceQuoteId,
            rebaseAttemptCount: position.rebaseAttemptCount,
            staleCycleRebaseAttemptCount: position.staleCycleRebaseAttemptCount,
            inventoryTimeLimitAttemptCount: position.inventoryTimeLimitAttemptCount,
            riskRebaseAttemptCount: position.riskRebaseAttemptCount,
            rebaseBlockedCount: position.rebaseBlockedCount,
            rebasePartialCleanupCount: position.rebasePartialCleanupCount,
            lastRebaseAttemptTs: position.lastRebaseAttemptTs,
            lastRebaseAttemptCloseReason: position.lastRebaseAttemptCloseReason,
            lastRebaseTriggerReasons: Array.isArray(position.lastRebaseTriggerReasons) ? [...position.lastRebaseTriggerReasons] : [],
            lastRebaseBlockedReason: position.lastRebaseBlockedReason,
            executionMode: position.openExecutionMode,
            openExecutionMode: position.openExecutionMode,
            plannedCloseExecutionMode: position.closeExecutionMode,
            executionPair: `${position.openExecutionMode}->${position.closeExecutionMode}`,
            regime: String(state?.regime || 'RANGE'),
        });

        return position;
    }

    private closePosition(
        state: any,
        config: any,
        position: Position,
        closePrice: number,
        closeTs: number,
        reason: string,
        closeQtyInput?: number,
        forceLoss = false,
        closeModeOverride?: 'maker' | 'taker' | 'market',
        minNetOverride?: number,
        cycleNetApproved = false
    ): { closed: boolean; qty: number; net: number; blockReason?: string } {
        const side = position.side;
        const arr = this.sideArray(state, side);
        const idx = arr.findIndex((p) => p.positionId === position.positionId);
        if (idx < 0) return { closed: false, qty: 0, net: 0, blockReason: 'position_not_found' };

        const maxQty = Number(position.quantity || 0);
        let closeQty = Number(closeQtyInput ?? maxQty);
        closeQty = Math.min(maxQty, Math.max(0, closeQty));
        closeQty = this.normalizeQty(closeQty, config);
        if (!(closeQty > 0)) {
            if (forceLoss) {
                closeQty = maxQty;
            } else {
                return { closed: false, qty: 0, net: 0, blockReason: 'invalid_close_qty' };
            }
        }

        const isStockMarket = ['tradeType', 'tradeMode', 'strategyMode', 'strategy'].some((key) => {
            const value = String(config?.[key] ?? '').trim().toLowerCase();
            return value.includes('stockmarket') || value.includes('stock_market') || value.includes('tradfi');
        });
        if (isStockMarket && closeQty < maxQty) {
            closeQty = this.normalizeQty(maxQty, config);
            if (!(closeQty > 0)) closeQty = maxQty;
        }

        const partial = closeQty < maxQty;
        const cloned: Position = { ...position, quantity: closeQty };
        const closeMode = this.resolveCloseExecutionMode(config, reason, forceLoss, cloned, closeModeOverride);
        const calc = this.estimatePositionNet(cloned, closePrice, closeTs, closeMode);
        const closeRatio = maxQty > 0 ? (closeQty / maxQty) : 1;
        const closeInitialMargin = Number(position.initialMargin || 0) * closeRatio;
        const closeBorrowedAmount = Number(position.borrowedAmount || 0) * closeRatio;
        const closeEntryNotional = Number(position.entryPrice || 0) * closeQty;
        const closeNotional = Number(closePrice || 0) * closeQty;

        const configuredMinNet = this.resolveMinNetUsdForClose(config, position, closeNotional, closeEntryNotional, reason, closeQty);
        const overrideMinNet = Number(minNetOverride);
        const minNet = Number.isFinite(overrideMinNet)
            ? (this.isStandardPairingTake(reason) || this.isProfitManagementClose(reason)
                ? Math.max(overrideMinNet, configuredMinNet)
                : overrideMinNet)
            : configuredMinNet;
        const enforcedMinNet = isStockMarket
            ? Math.max(0.30, Number(config.positiveNetCloseMinUsd ?? 0), minNet)
            : minNet;
        const refMargin = Number(config.baseInitialMarginPerOrder ?? config.amount ?? 0) || 1;
        const minNetFloorPct = config.minNetFloorPercentOfAmount != null && Number.isFinite(Number(config.minNetFloorPercentOfAmount))
            ? Number(config.minNetFloorPercentOfAmount)
            : null;
        const minNetFloor = minNetFloorPct != null
            ? refMargin * (minNetFloorPct / 100)
            : Number(config.minNetFloorUsd ?? 0);
        const allowNegativeCloseForThisReason = forceLoss && this.isEmergencyForceCloseReason(reason);
        const cycleNetApprovedForClose = cycleNetApproved
            && !String(reason || '').toLowerCase().includes('reserve_settle');
        const cycleNetApprovedMaxLegLossUsd = Math.max(0, Number(config.cycleNetApprovedMaxLegLossUsd ?? 0.25));
        const requiredNet = allowNegativeCloseForThisReason || forceLoss
            ? Number.NEGATIVE_INFINITY
            : (cycleNetApprovedForClose && !isStockMarket
                ? -cycleNetApprovedMaxLegLossUsd
                : Math.max(minNetFloor, enforcedMinNet));
        if (calc.net < requiredNet) {
            state.skippedClosesCount = Number(state.skippedClosesCount || 0) + 1;
            state.skippedClosesByReason[reason] = Number(state.skippedClosesByReason[reason] || 0) + 1;
            return { closed: false, qty: 0, net: calc.net, blockReason: 'min_net_not_reached' };
        }
        const minHoldMinutesBeforeNegative = Math.max(0, Number(config.minHoldMinutesBeforeNegativeClose ?? 0));
        const ageMs = Math.max(0, closeTs - Number(position.entryTime || closeTs));
        if (
            minHoldMinutesBeforeNegative > 0 &&
            calc.net < 0 &&
            !cycleNetApprovedForClose &&
            !allowNegativeCloseForThisReason &&
            ageMs < minHoldMinutesBeforeNegative * 60 * 1000
        ) {
            state.skippedClosesCount = Number(state.skippedClosesCount || 0) + 1;
            state.skippedClosesByReason[reason] = Number(state.skippedClosesByReason[reason] || 0) + 1;
            return { closed: false, qty: 0, net: calc.net, blockReason: 'negative_close_hold_window' };
        }

        const eventType = partial ? 'partial_close' : 'close';
        const executionStats = this.ensureExecutionStats(state);
        const closeExecutionLabel = closeMode === 'maker' ? 'maker' : 'market';
        if (closeMode === 'maker') {
            executionStats.makerCloseCount = Number(executionStats.makerCloseCount || 0) + 1;
        } else {
            executionStats.marketCloseCount = Number(executionStats.marketCloseCount || 0) + 1;
        }
        this.incrementMapCounter(executionStats.closeExecutionModeCounts, closeExecutionLabel);

        this.recordSignal(state, {
            type: eventType,
            side,
            price: closePrice,
            closePrice,
            entryPrice: position.entryPrice,
            quantity: closeQty,
            closeQty,
            entryTime: position.entryTime,
            closeTime: closeTs,
            reason,
            qgOpenReason: position.qgOpenReason,
            qgHedge: position.qgHedge,
            qgParentSide: position.qgParentSide,
            qgId: position.positionId,
            longevityRebasePairId: position.longevityRebasePairId,
            longevityRebaseLevel: position.longevityRebaseLevel,
            entryNotional: closeEntryNotional,
            closeNotional,
            initialMargin: closeInitialMargin,
            borrowedAmount: closeBorrowedAmount,
            feeOpen: calc.feeOpen,
            feeClose: calc.feeClose,
            feeTotal: calc.feeTotal,
            slippageTotal: calc.slippageTotal,
            interest: calc.interest,
            funding: calc.funding,
            gross: calc.gross,
            net: calc.net,
            netPnL: calc.net,
            profit: calc.net,
            leverage: position.leverage,
            rebaseAttemptCount: position.rebaseAttemptCount,
            staleCycleRebaseAttemptCount: position.staleCycleRebaseAttemptCount,
            inventoryTimeLimitAttemptCount: position.inventoryTimeLimitAttemptCount,
            riskRebaseAttemptCount: position.riskRebaseAttemptCount,
            rebaseBlockedCount: position.rebaseBlockedCount,
            rebasePartialCleanupCount: position.rebasePartialCleanupCount,
            lastRebaseAttemptTs: position.lastRebaseAttemptTs,
            lastRebaseAttemptCloseReason: position.lastRebaseAttemptCloseReason,
            lastRebaseTriggerReasons: Array.isArray(position.lastRebaseTriggerReasons) ? [...position.lastRebaseTriggerReasons] : [],
            lastRebaseBlockedReason: position.lastRebaseBlockedReason,
            executionMode: closeMode,
            openExecutionMode: position.openExecutionMode,
            closeExecutionMode: closeMode,
            executionPair: `${position.openExecutionMode}->${closeMode}`,
            regime: String(state?.regime || 'RANGE'),
        });

        const overlayPosition = isRsiExtremeOverlayPosition(position);
        state.totalRealizedNet = Number(state.totalRealizedNet || 0) + calc.net;
        if (!overlayPosition) {
            state.realizedSinceRebase = Number(state.realizedSinceRebase || 0) + calc.net;
        }
        state.totalFees = Number(state.totalFees || 0) + calc.feeTotal;
        state.totalInterest = Number(state.totalInterest || 0) + calc.interest;
        state.totalSlippage = Number(state.totalSlippage || 0) + calc.slippageTotal;
        state.totalGross = Number(state.totalGross || 0) + calc.gross;
        state.totalFunding = Number(state.totalFunding || 0) + calc.funding;
        if (forceLoss) {
            state.forcedCloseReasonCounts = state.forcedCloseReasonCounts || {};
            state.forcedCloseReasonCounts[reason] = Number(state.forcedCloseReasonCounts[reason] || 0) + 1;
        }

        const beforeAbsInventory = Math.abs(this.normalInventoryQty(state));
        const beforeCount = overlayPosition ? this.countNormalPositions(state) : this.countPositions(state);

        if (partial) {
            arr[idx].quantity = Math.max(0, maxQty - closeQty);
            arr[idx].initialMargin = Math.max(0, Number(arr[idx].initialMargin || 0) - closeInitialMargin);
            arr[idx].borrowedAmount = Math.max(0, Number(arr[idx].borrowedAmount || 0) - closeBorrowedAmount);
        } else {
            arr.splice(idx, 1);
        }

        const afterAbsInventory = Math.abs(this.normalInventoryQty(state));
        if (afterAbsInventory < beforeAbsInventory) {
            state.lastInventoryReductionTs = closeTs;
            state.lastInventoryReductionPrice = closePrice;
        }

        const afterCount = overlayPosition ? this.countNormalPositions(state) : this.countPositions(state);
        if (!overlayPosition && beforeCount > 0 && afterCount === 0) {
            state.cycleEnds = Number(state.cycleEnds || 0) + 1;
            this._cycleEnds += 1;
        }

        return { closed: true, qty: closeQty, net: calc.net };
    }

    private closeAllPositions(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        reason: string,
        forceLoss = false,
        closeModeOverride?: 'maker' | 'taker' | 'market'
    ): number {
        let closedCount = 0;
        for (const position of [...this.flattenedPositions(state)]) {
            const res = this.closePosition(state, config, position, price, nowTs, reason, position.quantity, forceLoss, closeModeOverride);
            if (res.closed) closedCount += 1;
        }
        return closedCount;
    }

    private shouldRequote(state: any, config: any, mid: number, atr: number, nowTs: number): boolean {
        const pending: QuoteOrder[] = Array.isArray(state.pendingQuotes) ? state.pendingQuotes : [];
        if (!pending.length) return true;

        const cooldownMs = Math.max(0, Number(config.requoteCooldownMs || 0));
        if (cooldownMs > 0 && (nowTs - Number(state.lastRequoteTs || 0)) < cooldownMs) {
            return false;
        }

        const ref = Number(state.referenceMid || mid || 0);
        const movePct = ref > 0 ? Math.abs(mid - ref) / ref : 0;
        const atrThreshold = (isFinite(atr) && mid > 0)
            ? Math.abs(atr / mid) * Number(config.requoteThresholdAtrFraction || 0.35)
            : 0;
        const pctThreshold = Number(config.requoteThresholdPercent || 0.0009);

        if (movePct >= Math.max(pctThreshold, atrThreshold)) return true;

        const staleMs = Math.max(1000, Number(config.staleOrderMs || (20 * 60 * 1000)));
        const age = nowTs - Number(state.lastRequoteTs || 0);
        return age >= staleMs;
    }

    private resolveOperationalRefreshMinMs(config: any): number {
        const candidate = Number(
            config?.operationalRefreshMinMs
            ?? config?.operational_refresh_min_ms
            ?? config?.operationalRefreshFloorMs
            ?? config?.operational_refresh_floor_ms
            ?? NaN
        );
        if (Number.isFinite(candidate) && candidate >= 0) {
            return Math.max(0, candidate);
        }
        return 60 * 1000;
    }

    private shouldDeferEntriesToExecution(config: any): boolean {
        return !!config?.deferEntryToNextExecution
            && this.normalizeExecutionMode(config?.openExecutionMode || 'maker', 'maker') !== 'maker';
    }

    private stageDeferredEntryIntents(state: any, quotes: QuoteOrder[]): void {
        const intents: PendingEntryIntent[] = [];
        for (const quote of Array.isArray(quotes) ? quotes : []) {
            if (quote?.reduceOnly) continue;
            const side: Side = quote.side === 'bid' ? 'long' : 'short';
            intents.push({
                id: `DEFER_${quote.id}`,
                side,
                level: Math.max(1, Number(quote.level || 1)),
                quantity: Math.max(0, Number(quote.quantity || 0)),
                reason: String(quote.reason || `deferred_${side}_entry`),
                sourceQuoteId: String(quote.id || ''),
                createdAt: Number(quote.createdAt || 0),
                createdIteration: Number(quote.createdIteration || 0),
                takeTargetNetPercentOfAmountOverride: quote.takeTargetNetPercentOfAmountOverride ?? null,
            });
        }
        state.pendingEntryIntents = intents;
    }

    private consumeDeferredEntryIntents(
        state: any,
        config: any,
        symbolInfo: any,
        params: {
            nowTs: number;
            mid: number;
            atr: number;
            regime: Regime;
            bestBid: number;
            bestAsk: number;
        }
    ): void {
        const pending: PendingEntryIntent[] = Array.isArray(state.pendingEntryIntents) ? state.pendingEntryIntents : [];
        if (!pending.length) return;
        if (this.isPostManagedCycleFlattenCooldownActive(state, params.nowTs)) return;
        state.pendingEntryIntents = [];

        const spreadBasePct = this.quoteSpreadBasePct(
            state,
            config,
            params.mid,
            params.atr,
            this.resolveRangeMinSpreadPercent(config, params.regime)
        );

        for (const entry of pending) {
            const qty = this.normalizeQty(Number(entry.quantity || 0), config);
            if (!(qty > 0)) continue;

            const fallbackPrice = Math.max(0, Number(params.mid || 0));
            const entryPrice = entry.side === 'long'
                ? Math.max(0, Number(params.bestAsk || fallbackPrice))
                : Math.max(0, Number(params.bestBid || fallbackPrice));
            if (!(entryPrice > 0)) continue;

            const takePrice = this.quoteTakePrice(
                entry.side,
                entryPrice,
                qty,
                Math.max(1, Number(entry.level || 1)),
                spreadBasePct,
                config,
                symbolInfo,
                entry.takeTargetNetPercentOfAmountOverride
            );

            this.openPosition(state, config, symbolInfo, {
                side: entry.side,
                qty,
                price: entryPrice,
                takePrice,
                nowTs: params.nowTs,
                reason: entry.reason,
                sourceQuoteId: entry.sourceQuoteId || entry.id,
                qgHedge: false,
                qgParentSide: entry.side,
            });
        }
    }

    private canRequoteByRateLimit(state: any, config: any, nowTs: number): boolean {
        const windowMs = 60 * 1000;
        const startTs = Number(state.requoteWindowStartTs || 0);
        if (!(startTs > 0) || (nowTs - startTs) >= windowMs) {
            state.requoteWindowStartTs = nowTs;
            state.requoteWindowCount = 0;
        }
        const limit = Math.max(1, Number(config.maxRequotesPerMinute || 12));
        return Number(state.requoteWindowCount || 0) < limit;
    }

    private markRequote(state: any): void {
        state.requoteWindowCount = Number(state.requoteWindowCount || 0) + 1;
    }

    private pruneStaleQuotes(state: any, config: any, mid: number, nowTs: number): void {
        const staleMs = Math.max(1000, Number(config.staleOrderMs || (20 * 60 * 1000)));
        const outerBandPct = Math.max(0.0001, Number(config.outerBandPct || 0.03));
        const quotes: QuoteOrder[] = Array.isArray(state.pendingQuotes) ? state.pendingQuotes : [];
        if (!quotes.length) return;
        const reduceOnlyQuotes = quotes.filter((q) => !!q.reduceOnly);
        const openQuotes = quotes.filter((q) => !q.reduceOnly);
        const beforeCount = openQuotes.length;
        const rawTotal = openQuotes.length;
        let filtered = openQuotes.filter((q) => {
            const ageOk = (nowTs - Number(q.createdAt || nowTs)) <= staleMs;
            const distPct = mid > 0 ? Math.abs(Number(q.price || 0) - mid) / mid : 0;
            const bandOk = distPct <= outerBandPct;
            return ageOk && bandOk;
        });
        const maxPerSide = Math.max(1, Number(config.maxOpenOrdersPerSide || 2));
        const maxTotal = Math.max(2, Number(config.maxTotalOpenOrders || (maxPerSide * 2)));
        const overflowLimited = rawTotal > maxTotal;
        const perSideLimit = overflowLimited ? 1 : maxPerSide;
        const totalLimit = overflowLimited ? 2 : maxTotal;
        const bids = filtered.filter((q) => q.side === 'bid').sort((a, b) => a.level - b.level).slice(0, perSideLimit);
        const asks = filtered.filter((q) => q.side === 'ask').sort((a, b) => a.level - b.level).slice(0, perSideLimit);
        filtered = [...bids, ...asks];
        if (filtered.length > totalLimit) {
            filtered = filtered.sort((a, b) => a.level - b.level).slice(0, totalLimit);
        }
        state.pendingQuotes = [...filtered, ...reduceOnlyQuotes];
        const removed = Math.max(0, beforeCount - filtered.length);
        if (removed > 0) {
            const executionStats = this.ensureExecutionStats(state);
            executionStats.quotesPruned = Number(executionStats.quotesPruned || 0) + removed;
        }
    }

    private nextVolAdaptiveFactor(state: any, config: any, atr: number): number {
        if (!config?.volAdaptiveEnabled) {
            state.volAdaptiveVr = 1;
            state.volAdaptiveKVol = 1;
            return 1;
        }
        const atrNow = Number(atr || 0);
        if (!(isFinite(atrNow) && atrNow > 0)) {
            return isFinite(Number(state.volAdaptiveKVol)) && Number(state.volAdaptiveKVol) > 0
                ? Number(state.volAdaptiveKVol)
                : 1;
        }

        const period = Math.max(2, Math.round(Number(config.volAdaptiveAtrSlowPeriod || 200)));
        const alpha = 2 / (period + 1);
        const prevSlow = Number(state.volAdaptiveAtrSlowEma || 0);
        const nextSlow = (isFinite(prevSlow) && prevSlow > 0)
            ? (prevSlow + (alpha * (atrNow - prevSlow)))
            : atrNow;
        state.volAdaptiveAtrSlowEma = nextSlow;

        const vr = nextSlow > 0 ? (atrNow / nextSlow) : 1;
        const power = Math.max(0.1, Number(config.volAdaptivePower || 0.8));
        const kMin = Math.max(0.1, Number(config.volAdaptiveKMin || 0.8));
        const kMax = Math.max(kMin, Number(config.volAdaptiveKMax || 2.0));
        const kVolRaw = Math.pow(Math.max(1e-6, vr), power);
        const kVol = this.clamp(kVolRaw, kMin, kMax);

        state.volAdaptiveVr = vr;
        state.volAdaptiveKVol = kVol;
        return kVol;
    }

    private quoteSpreadBasePct(state: any, config: any, mid: number, atr: number, minSpreadOverride?: number | null): number {
        const atrPct = (isFinite(atr) && mid > 0) ? Math.abs(atr / mid) : 0;
        const byAtr = atrPct * Number(config.baseSpreadAtrMultiplier || 0.35);
        const kVol = this.nextVolAdaptiveFactor(state, config, atr);
        const adapted = byAtr * kVol;
        const min = Number.isFinite(Number(minSpreadOverride)) && Number(minSpreadOverride) > 0
            ? Number(minSpreadOverride)
            : Number(config.minSpreadPercent || 0.0005);
        const max = Number(config.maxSpreadPercent || 0.009);
        return this.clamp(adapted, min, max);
    }

    private resolveRangeMinSpreadPercent(config: any, regime: Regime): number | null {
        if (regime !== 'RANGE') return null;
        const candidate = Number(config.rangeMinSpreadPercent ?? NaN);
        if (!Number.isFinite(candidate) || !(candidate > 0)) return null;
        return candidate;
    }

    private resolvePairTakeTargetNetUsd(config: any, notional: number, pctOfAmountOverride?: number | null): number {
        const fixedUsd = Number(config.pairTakeTargetNetUsd ?? NaN);
        const fixed = Number.isFinite(fixedUsd) && fixedUsd > 0 ? fixedUsd : 0;
        const pctOfAmount = Number.isFinite(Number(pctOfAmountOverride))
            ? Number(pctOfAmountOverride)
            : Number(config.pairTakeTargetNetPercentOfAmount ?? NaN);
        const leverage = Math.max(1, Number(config.leverageDefault ?? config.leverage ?? 1));
        const initialMargin = leverage > 0 ? (Math.max(0, Number(notional || 0)) / leverage) : 0;
        const pctTarget = Number.isFinite(pctOfAmount) && pctOfAmount > 0 && initialMargin > 0
            ? (initialMargin * (pctOfAmount / 100))
            : 0;
        return Math.max(fixed, pctTarget);
    }

    private isStandardPairingTake(reason: string): boolean {
        return String(reason || '').trim().toLowerCase() === 'pairing_take';
    }

    private isPairingTakeFamily(reason: string): boolean {
        return String(reason || '').trim().toLowerCase().startsWith('pairing_take');
    }

    private isProfitManagementClose(reason: string): boolean {
        const normalized = String(reason || '').trim().toLowerCase();
        return normalized === 'rebase'
            || normalized === 'defensive_inventory_reduction'
            || normalized === 'inventory_reduce'
            || normalized === 'inventory_reduction';
    }

    private resolvePositiveManagementNetUsd(config: any): number {
        const configured = Number(config?.positiveNetCloseMinUsd ?? 0);
        return Number.isFinite(configured) && configured > 0 ? configured : 0;
    }

    private resolveMinNetUsdForClose(
        config: any,
        position: Position,
        closeNotional: number,
        closeEntryNotional: number,
        reason: string,
        closeQty: number
    ): number {
        const base = resolveInstitutionalMinNetUsd(config, closeNotional, 'close');
        if (!this.isPairingTakeFamily(reason)) {
            return this.isProfitManagementClose(reason)
                ? Math.max(base, this.resolvePositiveManagementNetUsd(config))
                : base;
        }
        const entryNotional = Math.max(
            0,
            Number(closeEntryNotional || 0),
            Number(position?.entryPrice || 0) * Math.max(0, Number(closeQty || 0))
        );
        return Math.max(
            base,
            this.resolvePairTakeTargetNetUsd(
                config,
                entryNotional > 0 ? entryNotional : closeNotional,
                position?.takeTargetNetPercentOfAmountOverride ?? null
            )
        );
    }

    private resolveNormalizedPairTakePriceForPosition(
        config: any,
        symbolInfo: any,
        position: Position,
        quantity: number
    ): number {
        const entryPrice = Math.max(0, Number(position?.entryPrice || 0));
        const closeQty = Math.max(0, Number(quantity || position?.quantity || 0));
        if (!(entryPrice > 0) || !(closeQty > 0)) {
            return Math.max(0, Number(position?.takePrice || 0));
        }
        const requiredTakePrice = this.quoteTakePrice(
            position.side,
            entryPrice,
            closeQty,
            1,
            0.00001,
            config,
            symbolInfo,
            position?.takeTargetNetPercentOfAmountOverride ?? null
        );
        const currentTakePrice = Math.max(0, Number(position?.takePrice || 0));
        if (!(requiredTakePrice > 0)) return currentTakePrice;
        if (!(currentTakePrice > 0)) return requiredTakePrice;
        return position.side === 'long'
            ? Math.max(currentTakePrice, requiredTakePrice)
            : Math.min(currentTakePrice, requiredTakePrice);
    }

    private riskSpreadFactor(risk: any): number {
        if (risk?.defensive) return 1.45;
        if (risk?.reduced) return 1.2;
        return 1;
    }

    private riskSizeFactor(risk: any): number {
        if (risk?.defensive) return 0.45;
        if (risk?.reduced) return 0.72;
        return 1;
    }

    private buildOperationalMarketSnapshot(
        config: any,
        state: any,
        indicators: any,
        mid: number,
        spreadPct: number,
        candleRangePct: number,
        nowTs: number
    ): OperationalMarketSnapshot {
        return buildOperationalMarketSnapshotFromState({
            config,
            state,
            indicators,
            mid,
            spreadPct,
            candleRangePct,
            nowTs,
        });
    }

    /**
     * Halt de sessão MK: paridade IH / Margin mock / mk_validate_final_v4 —
     * drawdown da sessão só sobre PnL realizado (não MTM vs pico).
     */
    private resolveSessionBankrollHaltDecision(
        state: any,
        config: any,
        markPrice?: number,
        nowTs?: number
    ): { blocked: boolean; sessionHaltPct: number; sessionDrawdownPct: number; initialBankroll: number; peakEquity: number; realizedNow: number; unrealizedNow: number; equityNow: number } {
        let sessionHaltPct = Number(
            (config as any)?.sessionBankrollDrawdownHaltPct
            ?? state?.sessionBankrollDrawdownHaltPct
            ?? (this.config as any)?.sessionBankrollDrawdownHaltPct
            ?? 0
        );
        if (this.botName === 'BackTestMarketKing' && !(sessionHaltPct > 0)) {
            sessionHaltPct = 0.28;
        }
        const initialBankroll = Math.max(
            1e-8,
            Number(state?.initialBankroll ?? config?.bankroll ?? (this.config as any)?.bankroll ?? 0)
        );
        const realizedNow = Number(state?.totalRealizedNet || 0);
        const mark = Number(markPrice ?? state?.lastMarkPrice ?? state?.markPrice ?? 0);
        const ts = Number(nowTs ?? state?._lastTickerTs ?? Date.now());
        const unrealizedNow = mark > 0
            ? this.totalUnrealizedNet(state, mark, ts)
            : 0;
        const bankrollNow = Math.max(1e-8, Number(state?.bankroll ?? config?.bankroll ?? initialBankroll));
        const equityNow = bankrollNow + realizedNow + unrealizedNow;
        const sessionDrawdownRealized = Math.max(0, -realizedNow / initialBankroll);
        const sessionDrawdownEquity = Math.max(0, (initialBankroll - equityNow) / initialBankroll);
        const sessionDrawdownPct = Math.max(sessionDrawdownRealized, sessionDrawdownEquity);
        const peakEquity = Math.max(initialBankroll, Number(state?.peakEquity || 0), equityNow);
        return {
            blocked: sessionHaltPct > 0 && sessionDrawdownPct >= sessionHaltPct,
            sessionHaltPct,
            sessionDrawdownPct,
            initialBankroll,
            peakEquity,
            realizedNow,
            unrealizedNow,
            equityNow,
        };
    }

    private recordOperationalPlanTransition(state: any, plan: OperationalStrategyPlan, nowTs: number): void {
        const planKey = [
            plan.operationalRegime,
            plan.motor,
            plan.disableNewEntries ? 'paused' : 'active',
            plan.allowBid ? 'bid' : '',
            plan.allowAsk ? 'ask' : '',
            plan.pauseReason || '',
        ].join('|');
        if (String(state.operationalPlanKey || '') === planKey) return;
        this.recordSignal(state, {
            type: 'operational_transition',
            side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
            regime: plan.operationalRegime,
            motor: plan.motor,
            reason: plan.pauseReason || 'operational_plan_update',
            allowBid: plan.allowBid,
            allowAsk: plan.allowAsk,
            time: nowTs,
        });
        state.operationalPlanKey = planKey;
        state.operationalRegime = plan.operationalRegime;
        state.operationalMotor = plan.motor;
        state.operationalPauseReason = plan.pauseReason;
    }

    private buildQuotes(state: any, config: any, risk: any, params: {
        nowTs: number;
        mid: number;
        atr: number;
        regime: Regime;
        disableNewEntries: boolean;
        operationalPlan?: OperationalStrategyPlan | null;
    }): QuoteOrder[] {
        const executionStats = this.ensureExecutionStats(state);
        executionStats.buildQuotesCalls = Number(executionStats.buildQuotesCalls || 0) + 1;
        if (params.disableNewEntries) return [];
        this.evaluateRisk(state, config, params.mid, params.nowTs, params.regime);
        const sessionHalt = this.resolveSessionBankrollHaltDecision(state, config, params.mid, params.nowTs);
        if (sessionHalt.blocked) {
            executionStats.sessionHaltBlockedQuoteBuilds = Number(executionStats.sessionHaltBlockedQuoteBuilds || 0) + 1;
            state.pendingQuotes = [];
            return [];
        }
        const operationalPlan = params.operationalPlan || null;
        if (operationalPlan?.disableNewEntries) return [];
        if (this.isPostManagedCycleFlattenCooldownActive(state, params.nowTs)) return [];

        const baseLevels = Math.max(1, Number(config.levelsPerSide || 2));
        const levels = operationalPlan?.levelsOverride && operationalPlan.levelsOverride > 0
            ? Math.min(baseLevels, Math.round(operationalPlan.levelsOverride))
            : (params.regime === 'RANGE' ? baseLevels : Math.min(baseLevels, 2));
        const spreadBasePct = this.quoteSpreadBasePct(
            state,
            config,
            params.mid,
            params.atr,
            this.resolveRangeMinSpreadPercent(config, params.regime)
        ) * Math.max(0.1, Number(operationalPlan?.spreadMultiplier || 1));
        const outerMult = Math.max(1, Number(config.outerSpreadMultiplier || 1.85));
        const riskSpread = this.riskSpreadFactor(risk);
        const regimeSizeFactor = params.regime === 'RANGE' ? 1 : Number(config.trendDefensiveSizeMultiplier || 0.75);
        const riskSize = this.riskSizeFactor(risk)
            * Math.max(0.05, regimeSizeFactor)
            * Math.max(0.05, Number(operationalPlan?.sizeMultiplier || 1));

        const baseNotional = Math.max(0, Number(config.baseNotionalPerOrder || 0));
        const baseQtyRaw = params.mid > 0 ? (baseNotional / params.mid) : 0;
        const baseQty = this.normalizeQty(baseQtyRaw, config);
        if (!(baseQty > 0)) return [];

        const inventoryNotional = this.inventoryNotional(state, params.mid);
        const maxInventory = this.maxInventoryNotional(config, params.mid);
        const invRatio = maxInventory > 0 ? this.clamp(inventoryNotional / maxInventory, -1, 1) : 0;
        const invAbs = Math.abs(invRatio);
        const managedCycleLockLong = this.isManagedCycleModeEnabled(config) && this.isManagedCycleActiveForParentSide(state, 'long');
        const managedCycleLockShort = this.isManagedCycleModeEnabled(config) && this.isManagedCycleActiveForParentSide(state, 'short');
        const managedCycleGlobalLock = managedCycleLockLong || managedCycleLockShort;

        const freezeThreshold = Number(config.inventoryFreezeThreshold || 0.8);
        const freezeLong = invRatio >= freezeThreshold;
        const freezeShort = invRatio <= -freezeThreshold;
        const inventoryLockActive = freezeLong || freezeShort;
        if (state.inventoryLockActive !== inventoryLockActive) {
            state.inventoryLockActive = inventoryLockActive;
            this.recordSignal(state, {
                type: 'inventory_lock',
                side: inventoryNotional >= 0 ? 'long' : 'short',
                active: inventoryLockActive,
                invRatio,
                threshold: freezeThreshold,
                reason: inventoryLockActive ? 'inv_hard_threshold' : 'inv_back_to_safe',
                time: params.nowTs,
            });
        }

        const skewStrong = Number(config.skewStrongThreshold || 0.65);
        const skewBoost = invAbs >= skewStrong ? 0.55 : 0.25;

        const quotes: QuoteOrder[] = [];
        for (let level = 1; level <= levels; level++) {
            const levelScale = Math.pow(Math.max(1, Number(config.levelSizeMultiplier || 1.2)), level - 1);
            const qty = this.normalizeQty(baseQty * levelScale * riskSize, config);
            if (!(qty > 0)) continue;

            const levelBase = spreadBasePct * (1 + (level - 1) * Math.max(0, outerMult - 1));
            let bidDist = levelBase;
            let askDist = levelBase;

            if (params.regime === 'TREND_UP') {
                askDist *= Number(config.trendDefensiveSpreadMultiplier || 1.25);
                bidDist *= 0.85;
            } else if (params.regime === 'TREND_DOWN') {
                bidDist *= Number(config.trendDefensiveSpreadMultiplier || 1.25);
                askDist *= 0.85;
            }

            if (invRatio > 0) {
                bidDist *= (1 + invAbs * (0.8 + skewBoost));
                askDist *= Math.max(0.2, 1 - invAbs * 0.45);
            } else if (invRatio < 0) {
                askDist *= (1 + invAbs * (0.8 + skewBoost));
                bidDist *= Math.max(0.2, 1 - invAbs * 0.45);
            }

            bidDist *= riskSpread;
            askDist *= riskSpread;

            const bidPrice = this.normalizePrice(params.mid * (1 - bidDist), Number(config.priceStep || 0));
            const askPrice = this.normalizePrice(params.mid * (1 + askDist), Number(config.priceStep || 0));

            const onlyReduceLong = risk.defensive && inventoryNotional > 0;
            const onlyReduceShort = risk.defensive && inventoryNotional < 0;

            // U-MK1: honrar allowBid/allowAsk do plano operacional para reduzir
            // aberturas contra-tendencia que acabam drenando o basket.
            const planAllowBid = operationalPlan ? (operationalPlan.allowBid !== false) : true;
            const planAllowAsk = operationalPlan ? (operationalPlan.allowAsk !== false) : true;

            const allowBid = !freezeLong
                && !managedCycleGlobalLock
                && !managedCycleLockLong
                && !onlyReduceLong
                && bidPrice > 0
                && planAllowBid;
            const allowAsk = !freezeShort
                && !managedCycleGlobalLock
                && !managedCycleLockShort
                && !onlyReduceShort
                && askPrice > 0
                && planAllowAsk;

            if (allowBid) {
                quotes.push({
                    id: `Q_B_${params.nowTs}_${level}`,
                    side: 'bid',
                    level,
                    price: bidPrice,
                    quantity: qty,
                    createdAt: params.nowTs,
                    createdIteration: Number(state.iterationSeq || 0),
                    referenceMid: params.mid,
                    reduceOnly: false,
                    reason: operationalPlan?.bidReason || `mm_quote_l${level}`,
                    takeTargetNetPercentOfAmountOverride: operationalPlan?.takeTargetNetPercentOfAmountOverride ?? null,
                });
            }

            if (allowAsk) {
                quotes.push({
                    id: `Q_A_${params.nowTs}_${level}`,
                    side: 'ask',
                    level,
                    price: askPrice,
                    quantity: qty,
                    createdAt: params.nowTs,
                    createdIteration: Number(state.iterationSeq || 0),
                    referenceMid: params.mid,
                    reduceOnly: false,
                    reason: operationalPlan?.askReason || `mm_quote_l${level}`,
                    takeTargetNetPercentOfAmountOverride: operationalPlan?.takeTargetNetPercentOfAmountOverride ?? null,
                });
            }
        }

        let filtered = quotes;
        if (freezeLong || (risk.defensive && inventoryNotional > 0)) {
            filtered = filtered.filter((q) => q.side === 'ask');
        } else if (freezeShort || (risk.defensive && inventoryNotional < 0)) {
            filtered = filtered.filter((q) => q.side === 'bid');
        }

        const maxPerSide = Math.max(1, Number(config.maxOpenOrdersPerSide || levels));
        const maxTotal = Math.max(2, Number(config.maxTotalOpenOrders || (maxPerSide * 2)));
        const bids = filtered.filter((q) => q.side === 'bid').sort((a, b) => a.level - b.level).slice(0, maxPerSide);
        const asks = filtered.filter((q) => q.side === 'ask').sort((a, b) => a.level - b.level).slice(0, maxPerSide);
        filtered = [...bids, ...asks];
        if (filtered.length > maxTotal) {
            filtered = filtered.sort((a, b) => a.level - b.level).slice(0, maxTotal);
        }
        executionStats.lastFillModel = 'price_touch';
        for (const quote of filtered) {
            executionStats.quotesPosted = Number(executionStats.quotesPosted || 0) + 1;
            this.incrementMapCounter(executionStats.quotesPostedByLevel, `L${Number(quote.level || 0)}`);
            this.incrementMapCounter(executionStats.quotesPostedByRegime, String(params.regime || 'RANGE'));
        }
        executionStats.buildQuotesReturnedQuotes = Number(executionStats.buildQuotesReturnedQuotes || 0) + filtered.length;
        if (filtered.length <= 0) {
            executionStats.buildQuotesReturnedZeroCount = Number(executionStats.buildQuotesReturnedZeroCount || 0) + 1;
        }
        return filtered;
    }

    private quoteTakePrice(
        side: Side,
        entryPrice: number,
        quantity: number,
        level: number,
        spreadBasePct: number,
        config: any,
        symbolInfo: any,
        targetNetPercentOfAmountOverride?: number | null
    ): number {
        const mult = Number(config.pairTakeProfitSpreadMultiplier || 1.05);
        const levelBoost = 1 + ((Math.max(1, level) - 1) * 0.2);
        const spreadTargetPct = Math.max(0.00001, spreadBasePct * mult * levelBoost);

        const makerFeeRate = Number(symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? 0);
        const takerFeeRate = Number(symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0);
        const openMode = String(config.openExecutionMode || 'maker').toLowerCase();
        const closeMode = String(config.closeExecutionMode || 'maker').toLowerCase();
        const openFeeRate = this.getModeFeeRate(openMode, makerFeeRate, takerFeeRate);
        const closeFeeRate = this.getModeFeeRate(closeMode, makerFeeRate, takerFeeRate);
        const openSlip = this.getModeSlippage(
            openMode,
            Number(config.makerSlippagePercent ?? 0),
            Number(config.takerSlippagePercent ?? 0.00015)
        );
        const closeSlip = this.getModeSlippage(
            closeMode,
            Number(config.makerSlippagePercent ?? 0),
            Number(config.takerSlippagePercent ?? 0.00015)
        );
        const notional = Math.max(0, Number(entryPrice || 0) * Math.max(0, Number(quantity || 0)));
        const minNetUsd = Math.max(
            resolveInstitutionalMinNetUsd(config, notional, 'close'),
            resolveInstitutionalPointTargetNetUsd({
                config,
                symbolInfo,
                entryPrice,
                quantity,
                targetNetPercentOfAmountOverride,
            }) ?? 0,
            this.resolvePairTakeTargetNetUsd(config, notional, targetNetPercentOfAmountOverride)
        );
        const minGrossUsd = minNetUsd + (notional * (openFeeRate + closeFeeRate + openSlip + closeSlip));
        const minDeltaPct = notional > 0 ? (minGrossUsd / notional) : spreadTargetPct;
        const tpPct = Math.max(spreadTargetPct, minDeltaPct);
        return side === 'long'
            ? entryPrice * (1 + tpPct)
            : entryPrice * (1 - tpPct);
    }

    private consumeQuoteFills(
        state: any,
        config: any,
        symbolInfo: any,
        params: {
            nowTs: number;
            mid: number;
            high: number;
            low: number;
            atr: number;
            regime: Regime;
        }
    ): void {
        const pending: QuoteOrder[] = Array.isArray(state.pendingQuotes) ? state.pendingQuotes : [];
        if (!pending.length) return;

        this.evaluateRisk(state, config, params.mid, params.nowTs, params.regime);
        const sessionHalt = this.resolveSessionBankrollHaltDecision(state, config, params.mid, params.nowTs);
        if (sessionHalt.blocked) {
            state.pendingQuotes = pending.filter((quote) => !!quote.reduceOnly);
            if (!state.pendingQuotes.length) return;
        }

        const spreadBasePct = this.quoteSpreadBasePct(
            state,
            config,
            params.mid,
            params.atr,
            this.resolveRangeMinSpreadPercent(config, params.regime)
        );
        const effectiveRegime = (state?.operationalRegime || params.regime || 'RANGE') as Regime;
        const makerProbBase = this.clamp(Number(config.makerFillProb || 0.75), 0, 1);
        const makerProb = (this._liveMode && this._liveExecutionMode === 'demo')
            ? this.clamp(makerProbBase + Math.max(0, Number(config.demoMakerFillProbBonusPct ?? 0)), 0, 1)
            : makerProbBase;
        const fillModel = this.resolveFillModel(config);
        const volRatio = Math.max(0, Number(state.volAdaptiveVr || 1));
        const executionStats = this.ensureExecutionStats(state);
        executionStats.lastFillModel = fillModel.model;

        const keep: QuoteOrder[] = [];
        for (let quote of pending) {
            const reduceOnlyTargetPosition = quote.reduceOnly ? this.findPositionById(state, quote.positionId) : null;
            if (quote.reduceOnly && (!reduceOnlyTargetPosition || reduceOnlyTargetPosition.qgHedge)) {
                continue;
            }

            if (
                quote.reduceOnly
                && reduceOnlyTargetPosition
                && this.isStandardPairingTake(String(quote.reason || 'pairing_take'))
            ) {
                const requestedQty = Math.max(0, Number(quote.quantity || reduceOnlyTargetPosition.quantity || 0));
                const normalizedRequestedQty = this.normalizeQty(
                    Math.min(Number(reduceOnlyTargetPosition.quantity || 0), requestedQty),
                    config
                );
                const closeQty = normalizedRequestedQty > 0
                    ? normalizedRequestedQty
                    : Math.max(0, Number(reduceOnlyTargetPosition.quantity || 0));
                const normalizedTakePrice = this.resolveNormalizedPairTakePriceForPosition(
                    config,
                    symbolInfo,
                    reduceOnlyTargetPosition,
                    closeQty
                );
                if (normalizedTakePrice > 0 && Math.abs(normalizedTakePrice - Number(quote.price || 0)) > 1e-12) {
                    reduceOnlyTargetPosition.takePrice = normalizedTakePrice;
                    quote = {
                        ...quote,
                        price: normalizedTakePrice,
                        referenceMid: normalizedTakePrice,
                        quantity: closeQty,
                    };
                }
            }

            const triggerSide: QuoteSide = quote.reduceOnly
                ? (reduceOnlyTargetPosition!.side === 'long' ? 'ask' : 'bid')
                : quote.side;
            const touched = triggerSide === 'bid' ? params.low <= quote.price : params.high >= quote.price;
            if (!touched) {
                keep.push(quote);
                continue;
            }

            // U-M2: gate anti-wick. Wick que apenas toca sem penetrar fracao
            // minima do range e rejeitado (quote segue ativo para candles futuros).
            const wickGateEnabledRaw = config.fillWickGateEnabled;
            const wickGateEnabled = wickGateEnabledRaw === true
                || (typeof wickGateEnabledRaw === 'string' && ['true','1','yes','on'].includes(String(wickGateEnabledRaw).toLowerCase()));
            const wickMinPenetrationRatio = Math.max(0, Math.min(1, Number(config.fillWickMinPenetrationRatio ?? 0)));
            if (wickGateEnabled && wickMinPenetrationRatio > 0) {
                const candleRange = Math.max(0, Number(params.high) - Number(params.low));
                if (candleRange > 0) {
                    const penetration = triggerSide === 'bid'
                        ? (Number(quote.price) - Number(params.low)) / candleRange
                        : (Number(params.high) - Number(quote.price)) / candleRange;
                    if (penetration < wickMinPenetrationRatio) {
                        executionStats.quoteTouchWickRejected = Number(executionStats.quoteTouchWickRejected || 0) + 1;
                        keep.push(quote);
                        continue;
                    }
                }
            }

            executionStats.quoteTouchObserved = Number(executionStats.quoteTouchObserved || 0) + 1;
            this.incrementMapCounter(executionStats.quoteTouchesByLevel, `L${Number(quote.level || 0)}`);
            this.incrementMapCounter(executionStats.quoteTouchesByRegime, String(effectiveRegime));

            const waitCandles = Math.max(0, Number(state.iterationSeq || 0) - Number(quote.createdIteration || 0));
            if (waitCandles < fillModel.minRestCandles) {
                executionStats.quoteBlockedByRest = Number(executionStats.quoteBlockedByRest || 0) + 1;
                this.incrementMapCounter(executionStats.quoteBlockedByRestByLevel, `L${Number(quote.level || 0)}`);
                keep.push(quote);
                continue;
            }

            const quoteLevel = Math.max(1, Number(quote.level || 1));
            const isRangeRegime = effectiveRegime === 'RANGE';
            const isLowVol = volRatio <= Math.max(0.1, Number(config.makerFillLowVolAtrRatio ?? 1.05));
            const rangeL1RelaxTicks = isRangeRegime && quoteLevel === 1
                ? Math.max(0, Math.round(Number(config.makerFillRangeL1PenetrationRelaxTicks ?? 0)))
                    + (this._liveMode && this._liveExecutionMode === 'demo'
                        ? Math.max(0, Math.round(Number(config.demoMakerFillRangeL1RelaxExtraTicks ?? 0)))
                        : 0)
                : 0;
            const penetrationTicks = Math.max(
                0,
                fillModel.penetrationTicks
                    + ((quoteLevel - 1) * fillModel.penetrationTicksPerLevel)
                    - rangeL1RelaxTicks
            );
            const explicitPriceStep = Math.max(0, Number(config.priceStep || 0));
            const syntheticTickAbs = explicitPriceStep > 0
                ? explicitPriceStep
                : Math.max(
                    Math.abs(params.mid || 0) * Math.max(0.00001, Number(config.minSpreadPercent || 0.0005) * 0.1),
                    Math.abs(params.atr || 0) * 0.05
                );
            const penetrationAbs = penetrationTicks * syntheticTickAbs;
            const penetrated = penetrationAbs <= 0
                ? touched
                : (triggerSide === 'bid'
                    ? params.low <= (quote.price - penetrationAbs)
                    : params.high >= (quote.price + penetrationAbs));
            if (!penetrated) {
                executionStats.quoteBlockedByPenetration = Number(executionStats.quoteBlockedByPenetration || 0) + 1;
                this.incrementMapCounter(executionStats.quoteBlockedByPenetrationByLevel, `L${Number(quote.level || 0)}`);
                keep.push(quote);
                continue;
            }

            executionStats.quoteEligible = Number(executionStats.quoteEligible || 0) + 1;
            this.incrementMapCounter(executionStats.quoteEligibleByLevel, `L${Number(quote.level || 0)}`);
            this.incrementMapCounter(executionStats.quoteEligibleByRegime, String(effectiveRegime));

            const levelProb = makerProb + ((quoteLevel - 1) * fillModel.levelProbStep);
            const regimePenalty = isRangeRegime ? 0 : fillModel.trendProbPenaltyPct;
            const highVolPenalty = volRatio >= fillModel.highVolAtrRatio ? fillModel.highVolPenaltyPct : 0;
            const rangeBonus = isRangeRegime ? Math.max(0, Number(config.makerFillRangeProbBonusPct ?? 0)) : 0;
            const lowVolBonus = isLowVol ? Math.max(0, Number(config.makerFillLowVolProbBonusPct ?? 0)) : 0;
            const effectiveProb = this.clamp(
                levelProb - regimePenalty - highVolPenalty + rangeBonus + lowVolBonus,
                0,
                1
            );
            if (!this.deterministicGate(quote.id, params.nowTs, effectiveProb)) {
                executionStats.quoteBlockedByProb = Number(executionStats.quoteBlockedByProb || 0) + 1;
                this.incrementMapCounter(executionStats.quoteBlockedByProbByLevel, `L${Number(quote.level || 0)}`);
                keep.push(quote);
                continue;
            }

            executionStats.quoteFills = Number(executionStats.quoteFills || 0) + 1;
            executionStats.totalFillWaitCandles = Number(executionStats.totalFillWaitCandles || 0) + waitCandles;
            this.incrementMapCounter(executionStats.quoteFillsByLevel, `L${Number(quote.level || 0)}`);
            this.incrementMapCounter(executionStats.quoteFillsByRegime, String(effectiveRegime));
            this.incrementMapCounter(executionStats.fillWaitCandlesBuckets, this.fillWaitBucket(waitCandles));

            if (quote.reduceOnly) {
                const requestedQty = Math.max(0, Number(quote.quantity || reduceOnlyTargetPosition!.quantity || 0));
                const normalizedRequestedQty = this.normalizeQty(
                    Math.min(Number(reduceOnlyTargetPosition!.quantity || 0), requestedQty),
                    config
                );
                const closeQty = normalizedRequestedQty > 0
                    ? normalizedRequestedQty
                    : Math.max(0, Number(reduceOnlyTargetPosition!.quantity || 0));
                const closeResult = this.closePosition(
                    state,
                    config,
                    reduceOnlyTargetPosition!,
                    quote.price,
                    params.nowTs,
                    String(quote.reason || 'pairing_take'),
                    closeQty,
                    false,
                    'maker'
                );
                if (!closeResult.closed) {
                    keep.push(quote);
                }
                continue;
            }

            const openSide: Side = quote.side === 'bid' ? 'long' : 'short';
            const takePrice = this.quoteTakePrice(
                openSide,
                quote.price,
                quote.quantity,
                quote.level,
                spreadBasePct,
                config,
                symbolInfo,
                quote.takeTargetNetPercentOfAmountOverride
            );
            const openReason = String(quote.reason || `mm_quote_l${quote.level}`);
            this.openPosition(state, config, symbolInfo, {
                side: openSide,
                qty: quote.quantity,
                price: quote.price,
                takePrice,
                nowTs: params.nowTs,
                reason: openReason,
                sourceQuoteId: quote.id,
                qgHedge: false,
                qgParentSide: openSide,
            });
        }

        state.pendingQuotes = keep;
    }

    private syncLiveRealReduceOnlyPairQuotes(state: any, config: any, nowTs: number): void {
        const existingQuotes: QuoteOrder[] = Array.isArray(state.pendingQuotes) ? state.pendingQuotes : [];
        const openQuotes: QuoteOrder[] = existingQuotes
            .filter((quote: QuoteOrder) => !quote.reduceOnly);
        const existingReduceOnlyQuotes = existingQuotes.filter((quote: QuoteOrder) => !!quote.reduceOnly);
        const reduceOnlyQuotes: QuoteOrder[] = [];

        for (const position of this.flattenedPositions(state)) {
            if (!this.isEligibleForNormalLegManagement(position)) continue;
            const closeMode = this.resolveCloseExecutionMode(config, 'pairing_take', false, position);
            const quantity = this.normalizeQty(Number(position.quantity || 0), config);
            const normalizedTakePrice = this.resolveNormalizedPairTakePriceForPosition(config, null, position, quantity);
            if (normalizedTakePrice > 0 && Math.abs(normalizedTakePrice - Number(position.takePrice || 0)) > 1e-12) {
                position.takePrice = normalizedTakePrice;
            }
            const takePrice = Math.max(0, Number(position.takePrice || normalizedTakePrice || 0));
            const positionId = String(position.positionId || '').trim();
            if (closeMode !== 'maker' || !(takePrice > 0) || !(quantity > 0) || !positionId) continue;
            const quoteSide: QuoteSide = position.side === 'long' ? 'ask' : 'bid';
            const existingQuote = existingReduceOnlyQuotes.find((quote) => (
                String(quote.positionId || '').trim() === positionId
                && quote.side === quoteSide
            ));
            const existingCreatedAt = Number(existingQuote?.createdAt || 0);
            const existingCreatedIteration = Number(existingQuote?.createdIteration);

            reduceOnlyQuotes.push({
                id: `RO_${position.side}_${positionId}`,
                side: quoteSide,
                level: 0,
                price: takePrice,
                quantity,
                createdAt: existingCreatedAt > 0 ? existingCreatedAt : nowTs,
                createdIteration: Number.isFinite(existingCreatedIteration)
                    ? existingCreatedIteration
                    : Number(state.iterationSeq || 0),
                referenceMid: takePrice,
                reduceOnly: true,
                positionId,
                reason: 'pairing_take',
                takeTargetNetPercentOfAmountOverride: position.takeTargetNetPercentOfAmountOverride ?? null,
            });
        }

        state.pendingQuotes = [...openQuotes, ...reduceOnlyQuotes];
    }

    private processLiveRealPairingManagement(state: any, config: any, high: number, low: number, markPrice: number, nowTs: number): void {
        const pairMaxAgeMs = Math.max(0, Number(config.pairMaxAgeMinutes || 0)) * 60 * 1000;
        const pairAdverseMovePct = Math.max(0, Number(config.pairAdverseMovePct || 0));
        const pairFallbackAgeReducePct = this.clamp(Number(config.pairFallbackAgeReducePct ?? 1), 0.05, 1);
        const pairFallbackAdverseReducePct = this.clamp(Number(config.pairFallbackAdverseReducePct ?? 1), 0.05, 1);
        const pairFallbackMinNetUsd = Number(config.pairFallbackMinNetUsd ?? 0.01);
        const pairAgeReduceMinNetUsd = Number(config.pairAgeReduceMinNetUsd ?? 0.01);

        for (const position of this.flattenedPositions(state)) {
            if (!this.isEligibleForNormalLegManagement(position)) continue;
            const parentSide = String((position as any)?.qgParentSide || position.side || 'long') === 'short' ? 'short' : 'long';

            const normalizedTakePrice = this.resolveNormalizedPairTakePriceForPosition(config, null, position, position.quantity);
            if (normalizedTakePrice > 0 && Math.abs(normalizedTakePrice - Number(position.takePrice || 0)) > 1e-12) {
                position.takePrice = normalizedTakePrice;
            }
            const takePrice = Math.max(0, Number(position.takePrice || normalizedTakePrice || 0));
            const shouldClose = position.side === 'long'
                ? (high >= takePrice)
                : (low <= takePrice);
            if (shouldClose) {
                const closeMode = this.resolveCloseExecutionMode(config, 'pairing_take', false, position);
                if (closeMode !== 'maker') {
                    this.closePosition(state, config, position, takePrice, nowTs, 'pairing_take', position.quantity, false, closeMode);
                }
                continue;
            }

            if (this.isManagedCycleModeEnabled(config) && this.isManagedCycleActiveForParentSide(state, parentSide)) {
                continue;
            }

            const entryPrice = Number(position.entryPrice || 0);
            const ageMs = Math.max(0, nowTs - Number(position.entryTime || nowTs));
            const ageExceeded = pairMaxAgeMs > 0 && ageMs >= pairMaxAgeMs;
            let adverseMovePct = 0;
            if (entryPrice > 0) {
                if (position.side === 'long') {
                    const worstPx = Math.min(entryPrice, low, markPrice);
                    adverseMovePct = Math.max(0, (entryPrice - worstPx) / entryPrice);
                } else {
                    const worstPx = Math.max(entryPrice, high, markPrice);
                    adverseMovePct = Math.max(0, (worstPx - entryPrice) / entryPrice);
                }
            }
            const adverseExceeded = pairAdverseMovePct > 0 && adverseMovePct >= pairAdverseMovePct;
            if (!ageExceeded && !adverseExceeded) continue;

            const fallbackPrice = Math.max(0, Number(markPrice || position.takePrice || position.entryPrice || 0));
            const fullQty = Math.max(0, Number(position.quantity || 0));
            const fallbackReducePct = adverseExceeded ? pairFallbackAdverseReducePct : pairFallbackAgeReducePct;
            let fallbackQty = this.normalizeQty(fullQty * fallbackReducePct, config);
            if (!(fallbackQty > 0) || fallbackQty > fullQty) fallbackQty = fullQty;
            const fallbackReason = adverseExceeded ? 'pairing_take_fallback' : 'pairing_take_age_reduce';
            const fallbackMinNet = adverseExceeded ? pairFallbackMinNetUsd : pairAgeReduceMinNetUsd;
            if (this.shouldSkipFallbackCloseBecauseNegativeAndTooEarly(state, config, position, fallbackPrice, nowTs, fallbackQty, fallbackReason, false)) {
                continue;
            }
            this.closePosition(
                state,
                config,
                position,
                fallbackPrice,
                nowTs,
                fallbackReason,
                fallbackQty,
                false,
                undefined,
                fallbackMinNet
            );
        }

        this.syncLiveRealReduceOnlyPairQuotes(state, config, nowTs);
    }

    private oldestNonHedgeLegAgeMs(state: any, nowTs: number): number {
        let oldestEntryTs = Number.POSITIVE_INFINITY;
        for (const position of this.flattenedPositions(state)) {
            if (!this.isEligibleForNormalLegManagement(position)) continue;
            const entryTs = Number(position.entryTime || 0);
            if (entryTs > 0 && entryTs < oldestEntryTs) oldestEntryTs = entryTs;
        }
        if (!Number.isFinite(oldestEntryTs)) return 0;
        return Math.max(0, nowTs - oldestEntryTs);
    }

    private shouldTriggerStaleCycleRebase(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        inventoryRatioAbsOverride?: number
    ): boolean {
        const staleMinutes = Math.max(0, Number(config.staleCycleRebaseMinutes || 0));
        if (staleMinutes <= 0) return false;

        const nonHedgePositions = this.flattenedPositions(state).filter((position) => this.isEligibleForNormalLegManagement(position));
        const minPositions = Math.max(1, Number(config.staleCycleRebaseMinPositions || 1));
        if (nonHedgePositions.length < minPositions) return false;

        const oldestLegAgeMs = this.oldestNonHedgeLegAgeMs(state, nowTs);
        if (oldestLegAgeMs < staleMinutes * 60 * 1000) return false;

        const maxInv = this.maxInventoryNotional(config, price);
        const inventoryRatioAbs = Number.isFinite(Number(inventoryRatioAbsOverride))
            ? Number(inventoryRatioAbsOverride)
            : (maxInv > 0 ? Math.abs(this.normalInventoryNotional(state, price) / maxInv) : 0);
        if (inventoryRatioAbs <= Number(config.staleCycleRebaseInventoryThreshold || 0)) return false;

        const ageHours = oldestLegAgeMs / (60 * 60 * 1000);
        const basketNet = this.basketNet(state, price, nowTs);
        const basketPnlPerHour = ageHours > 0 ? (basketNet / ageHours) : basketNet;
        return basketPnlPerHour < Number(config.staleCycleRebasePnlPerHourFloorUsd || 0);
    }

    private resolveRebaseCloseReason(reasons: string[]): string {
        if (reasons.includes('risk_rebase')) return 'risk_rebase';
        if (reasons.includes('stale_cycle_rebase')) return 'stale_cycle_rebase';
        return 'rebase';
    }

    private runTechnicalStops(
        state: any,
        config: any,
        high: number,
        low: number,
        markPrice: number,
        nowTs: number
    ): void {
        const maxHoldMs = Math.max(0, Number(config.maxHoldMinutesPerLeg || 0)) * 60 * 1000;
        const maxAdverseMovePct = Math.max(0, Number(config.maxAdverseMovePctPerLeg || 0));
        const maxAdverseMovePoints = Math.max(
            0,
            Number(config.maxAdverseMovePointsPerLeg ?? config.max_adverse_move_points_per_leg ?? 0)
        );
        const adversePointStep = this.resolveAdversePointPriceStep(config, null);
        const maxHoldStopReducePct = this.clamp(Number(config.maxHoldStopReducePct ?? 0.5), 0.05, 1);
        const maxHoldStopMinNetUsd = Number(config.maxHoldStopMinNetUsd ?? 0.01);
        const maxAdverseStopReducePct = this.clamp(Number(config.maxAdverseStopReducePct ?? 0.5), 0.05, 1);
        if (!(maxHoldMs > 0 || maxAdverseMovePct > 0 || maxAdverseMovePoints > 0)) return;

        const maxStopsPerTick = 3;
        let stopsTriggered = 0;
        const candidates = this.flattenedTechnicalStopPositions(state, config)
            .sort((a, b) => Number(a.entryTime || 0) - Number(b.entryTime || 0));

        for (const p of candidates) {
            if (stopsTriggered >= maxStopsPerTick) break;
            const entryPrice = Number(p.entryPrice || 0);
            if (!(entryPrice > 0)) continue;

            const ageMs = Math.max(0, nowTs - Number(p.entryTime || nowTs));
            const holdExceeded = maxHoldMs > 0 && ageMs >= maxHoldMs;

            let adverseMovePct = 0;
            let adverseMovePoints = 0;
            if (p.side === 'long') {
                const worstPx = Math.min(entryPrice, low, markPrice);
                adverseMovePct = Math.max(0, (entryPrice - worstPx) / entryPrice);
                adverseMovePoints = adversePointStep > 0
                    ? Math.max(0, (entryPrice - worstPx) / adversePointStep)
                    : 0;
            } else {
                const worstPx = Math.max(entryPrice, high, markPrice);
                adverseMovePct = Math.max(0, (worstPx - entryPrice) / entryPrice);
                adverseMovePoints = adversePointStep > 0
                    ? Math.max(0, (worstPx - entryPrice) / adversePointStep)
                    : 0;
            }
            const adverseExceeded = maxAdverseMovePct > 0
                ? adverseMovePct >= maxAdverseMovePct
                : maxAdverseMovePoints > 0 && adverseMovePoints >= maxAdverseMovePoints;
            if (!holdExceeded && !adverseExceeded) continue;

            const reason = adverseExceeded ? 'max_adverse_move_stop' : 'max_hold_time_stop';
            const fullQty = Math.max(0, Number(p.quantity || 0));
            const reducePct = adverseExceeded ? maxAdverseStopReducePct : maxHoldStopReducePct;
            const forceLoss = adverseExceeded;
            const minNetOverride = adverseExceeded ? undefined : maxHoldStopMinNetUsd;
            let reduceQty = this.normalizeQty(fullQty * reducePct, config);
            if (!(reduceQty > 0) || reduceQty >= fullQty) reduceQty = fullQty;

            const res = this.closePosition(
                state,
                config,
                p,
                Math.max(0, Number(markPrice || entryPrice)),
                nowTs,
                reason,
                reduceQty,
                forceLoss,
                'market',
                minNetOverride
            );
            if (res.closed) stopsTriggered += 1;
        }
    }

    /**
     * Backtest-only: teto de perda unrealized por posição (só pernas, não hedge).
     * Usa markPrice (close do candle) para a checagem — fluxo realista, sem assumir fechamento no pior tick.
     * Ordena por pior net e corta uma por tick.
     */
    private runMaxUnrealizedLossCap(
        state: any,
        config: any,
        markPrice: number,
        nowTs: number,
        _range?: { high: number; low: number }
    ): void {
        const cap = config.maxUnrealizedLossPerPositionUsd;
        if (cap == null || !Number.isFinite(Number(cap)) || Number(cap) >= 0) return;
        if (resolveLossCapCooldownStatus(state, config, nowTs).active) return;
        const capNum = Number(cap);
        const candidates = this.flattenedTechnicalStopPositions(state, config)
            .map((p) => {
                const calc = this.estimatePositionNet(p, markPrice, nowTs);
                return { p, net: calc.net };
            })
            .filter((x) => Number.isFinite(x.net) && x.net < capNum)
            .sort((a, b) => a.net - b.net);
        for (const { p } of candidates) {
            const closeResult = this.closePosition(
                state,
                config,
                p,
                markPrice,
                nowTs,
                'max_unrealized_loss_cap',
                p.quantity,
                true
            );
            if (closeResult.closed) markLossCapClose(state, nowTs, p.side);
            break;
        }
    }

    private shouldRunManagedCycleRiskStops(config: any, risk: any, operationalPlan?: OperationalStrategyPlan | null, state?: any): boolean {
        if (!config?.managedCycleRiskStopsEnabled) return false;
        const minDrawdownPct = Math.max(0, Number(config.managedCycleRiskStopsMinDrawdownPct ?? 0.55));
        const maxMarginLevel = Math.max(0, Number(config.managedCycleRiskStopsMaxMarginLevel ?? 1.1));
        const drawdownPct = Math.max(0, Number(risk?.drawdownPct || 0));
        const accountMarginLevel = Number(risk?.accountMarginLevel || risk?.marginLevel || 0);
        if (minDrawdownPct > 0 && drawdownPct >= minDrawdownPct) return true;
        if (maxMarginLevel > 0 && accountMarginLevel > 0 && accountMarginLevel <= maxMarginLevel) return true;

        const useOperationalShock = config.managedCycleRiskStopsUseOperationalShock !== false;
        if (!useOperationalShock) return false;
        const reason = String(operationalPlan?.pauseReason || state?.operationalPauseReason || '').toLowerCase();
        if (!reason) return false;
        if (
            reason.includes('shock')
            || reason.includes('fresh_impulse')
            || reason.includes('volatility')
            || reason.includes('atr_')
            || reason.includes('candle_')
            || reason.includes('spread_')
        ) {
            return true;
        }
        return false;
    }

    private shouldRunManagedCycleTechnicalStops(config: any, risk: any, operationalPlan?: OperationalStrategyPlan | null, state?: any): boolean {
        if (!this.isManagedCycleModeEnabled(config)) return true;
        if (config?.managedCycleTechnicalStopsEnabled === true) return true;
        return this.shouldRunManagedCycleRiskStops(config, risk, operationalPlan, state);
    }

    private maybeManagedCycleEmergencyFlatten(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        regime: Regime,
        risk: any
    ): { rebased: boolean; reason: string } {
        if (!this.isManagedCycleModeEnabled(config) || !config.rebaseOnRiskCritical) {
            return { rebased: false, reason: '' };
        }
        const flattenOnRebase = config.managedCycleFlattenOnRiskRebase !== false;
        const needFlatten = risk.criticalPause || (flattenOnRebase && risk.requireRebase);
        if (!needFlatten) return { rebased: false, reason: '' };
        if (this.countPositions(state) <= 0) return { rebased: false, reason: '' };

        const closed = this.closeAllPositions(
            state,
            config,
            price,
            nowTs,
            'managed_cycle_risk_flatten',
            !!risk.criticalPause,
            'market'
        );
        if (closed <= 0) return { rebased: false, reason: '' };

        for (const side of ['long', 'short'] as Side[]) {
            this.resetManagedCycleSideState(state, side);
        }
        state.pendingQuotes = [];
        state.hedgeActive = false;
        state.hedgeParentSide = null;
        state.hedgeWatchStartTs = 0;
        state.hedgeWatchInventoryAbs = 0;
        state.hedgeMinInventoryAbsSeen = 0;
        state.forceRebaseReason = '';
        state.referenceMid = price;
        state.lastRequoteTs = nowTs;
        state.lastRebaseTs = nowTs;
        state.rebaseWindowStartTs = nowTs;
        state.realizedSinceRebase = 0;
        state.lastInventoryReductionTs = nowTs;
        state.lastInventoryReductionPrice = price;
        state.rebaseCount = Number(state.rebaseCount || 0) + 1;
        const coolMin = Math.max(0, Number(config.managedCyclePostFlattenEntryCooldownMinutes ?? 0));
        state.managedCycleFlattenCooldownUntilTs = coolMin > 0 ? nowTs + coolMin * 60 * 1000 : 0;

        this.recordSignal(state, {
            type: 'managed_cycle_emergency_flatten',
            side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
            reason: risk.criticalPause ? 'critical_pause' : 'require_rebase',
            closedCount: closed,
            regime,
            marginUsedPct: Number(risk.marginUsedPct || 0),
            drawdownPct: Number(risk.drawdownPct || 0),
            minLiqDistancePct: Number(risk.minLiqDistancePct || 0),
            time: nowTs,
        });

        return { rebased: true, reason: 'managed_cycle_risk_flatten' };
    }

    private isPostManagedCycleFlattenCooldownActive(state: any, nowTs: number): boolean {
        const until = Number(state.managedCycleFlattenCooldownUntilTs || 0);
        return until > 0 && nowTs < until;
    }

    private runDefensiveInventoryReduction(state: any, config: any, price: number, nowTs: number, risk: any): void {
        if (!risk.defensive) return;
        const inv = this.normalInventoryQty(state);
        if (Math.abs(inv) <= 1e-12) return;

        const riskySide: Side = inv > 0 ? 'long' : 'short';
        const list = [...this.sideArray(state, riskySide)]
            .filter((p) => !this.isLongevityCycleManagedPosition(p))
            .filter((p) => this.isEligibleForNormalLegManagement(p))
            .sort((a, b) => a.entryTime - b.entryTime);
        for (const p of list) {
            const netNow = this.estimatePositionNet(p, price, nowTs).net;
            const allowLoss = false;
            const positionNotional = Math.max(0, Number(price || 0) * Math.max(0, Number(p.quantity || 0)));
            const basketMinNetUsd = resolveInstitutionalMinNetUsd(config, positionNotional, 'basket');
            if (netNow >= basketMinNetUsd || allowLoss) {
                const res = this.closePosition(state, config, p, price, nowTs, 'defensive_inventory_reduction', p.quantity, allowLoss);
                if (res.closed && res.net < 0) {
                    state.defensiveLossCloseCount = Number(state.defensiveLossCloseCount || 0) + 1;
                }
                break;
            }
        }
    }

    private basketNet(state: any, price: number, nowTs: number): number {
        return Number(state.realizedSinceRebase || 0) + this.flattenedNormalPositions(state)
            .reduce((sum, position) => sum + this.estimatePositionNet(position, price, nowTs).net, 0);
    }

    private maybePairedRangeBootstrap(
        state: any,
        config: any,
        symbolInfo: any,
        markPrice: number,
        nowTs: number,
        operationalPlan: OperationalStrategyPlan | null | undefined,
        atr: number
    ): void {
        const bootCfg = resolvePairedRangeBootstrapConfig(config);
        if (!bootCfg.enabled || !(markPrice > 0)) return;
        const bootstrapsDone = Number(state.pairedRangeBootstrapCount || 0);
        if (!shouldAttemptPairedRangeBootstrap({
            config,
            operationalRegime: operationalPlan?.operationalRegime,
            disableNewEntries: !!config.disableNewEntries,
            normalPositionCount: this.countNormalPositions(state),
            bootstrapsDone,
        })) {
            return;
        }
        const qty = this.normalizeQty(qtyFromNotionalUsdRaw(bootCfg.notionalUsdPerSide, markPrice), config);
        if (!(qty > 0)) return;
        const spreadBasePct = Math.max(0.00001, Number(config.baseSpreadAtrMultiplier || 0.3) * (atr / Math.max(markPrice, 1e-8)));
        const longTake = this.quoteTakePrice('long', markPrice, qty, 1, spreadBasePct, config, symbolInfo, operationalPlan?.takeTargetNetPercentOfAmountOverride);
        const shortTake = this.quoteTakePrice('short', markPrice, qty, 1, spreadBasePct, config, symbolInfo, operationalPlan?.takeTargetNetPercentOfAmountOverride);
        const longOpened = this.openPosition(state, config, symbolInfo, {
            side: 'long', qty, price: markPrice, takePrice: longTake, nowTs,
            reason: IH_PAIRED_RANGE_BOOTSTRAP_REASON, sourceQuoteId: `paired_bootstrap_long_${nowTs}`,
        });
        const shortOpened = this.openPosition(state, config, symbolInfo, {
            side: 'short', qty, price: markPrice, takePrice: shortTake, nowTs,
            reason: IH_PAIRED_RANGE_BOOTSTRAP_REASON, sourceQuoteId: `paired_bootstrap_short_${nowTs}`,
        });
        if (longOpened || shortOpened) {
            state.pairedRangeBootstrapCount = bootstrapsDone + 1;
        }
    }

    private processBasketCentralScalp(
        state: any,
        config: any,
        symbolInfo: any,
        markPrice: number,
        nowTs: number
    ): void {
        const scalpCfg = resolveBasketCentralScalpConfig(config);
        if (!scalpCfg.enabled || !(markPrice > 0)) return;

        const pointSize = Math.max(
            1e-8,
            Number((config as any)?.adversePointsPriceTick ?? (config as any)?.priceStep ?? symbolInfo?.priceStep ?? 0.001)
        );
        const normals = this.flattenedNormalPositions(state);
        const longPositions = normals.filter((p) => p.side === 'long');
        const shortPositions = normals.filter((p) => p.side === 'short');
        const scalpPositions = normals.filter((p) => isBasketCentralScalpReason(p.qgOpenReason || (p as any).reason));

        for (const position of scalpPositions) {
            const targetPrice = Number((position as any).takePrice || (position as any).qgTargetPrice || 0);
            if (!(targetPrice > 0)) continue;
            const hit = position.side === 'long' ? markPrice >= targetPrice : markPrice <= targetPrice;
            if (!hit) continue;
            const res = this.closePosition(
                state,
                config,
                position,
                markPrice,
                nowTs,
                IH_BASKET_CENTER_SCALP_TP,
                Number(position.quantity || 0),
                false,
                undefined,
                scalpCfg.scalpMinNetUsd
            );
            if (res.closed) return;
        }

        const zone = computeBasketCentralZone({
            longPositions,
            shortPositions,
            pointSize,
            bufferPips: scalpCfg.bufferPips,
            minZoneWidthPips: scalpCfg.minZoneWidthPips,
        });
        if (!zone || !isPriceInsideBasketCentralZone(markPrice, zone)) return;

        const direction = resolveBasketCentralScalpDirection(markPrice, zone);
        if (!direction) return;

        if (scalpPositions.filter((p) => p.side === direction).length >= scalpCfg.maxOpenPerSide) return;

        const notional = scalpCfg.scalpNotionalUsd;
        if (!(notional > 0)) return;
        const qty = this.normalizeQty(qtyFromNotionalUsdRaw(notional, markPrice), config);
        if (!(qty > 0)) return;

        const targetPrice = resolveBasketCentralScalpTargetPrice(direction, markPrice, zone, scalpCfg.scalpTpPips);
        if (!(targetPrice && targetPrice > 0)) return;

        const reason = direction === 'long' ? IH_BASKET_CENTER_SCALP_LONG : IH_BASKET_CENTER_SCALP_SHORT;
        this.openPosition(state, config, symbolInfo, {
            side: direction,
            qty,
            price: markPrice,
            takePrice: targetPrice,
            nowTs,
            reason,
            sourceQuoteId: `basket_central_${direction}_${nowTs}`,
        });
    }

    private processRsiExtremeOverlay(
        state: any,
        config: any,
        symbolInfo: any,
        price: number,
        nowTs: number,
        currentIndicators: any,
        operationalPlan?: OperationalStrategyPlan | null
    ): void {
        const overlay = evaluateRsiExtremeOverlay<Position>({
            config,
            positions: this.flattenedPositions(state),
            currentIndicators,
            price,
        });
        if (!overlay.enabled || !Number.isFinite(Number(overlay.rsi))) return;

        for (const position of overlay.closeLongPositions) {
            this.closePosition(
                state,
                config,
                position,
                price,
                nowTs,
                RSI_EXTREME_LONG_EXIT_REASON,
                Number(position.quantity || 0),
                false,
                overlay.config.closeExecutionMode,
                overlay.config.minNetCloseUsd
            );
        }

        for (const position of overlay.closeShortPositions) {
            this.closePosition(
                state,
                config,
                position,
                price,
                nowTs,
                RSI_EXTREME_SHORT_EXIT_REASON,
                Number(position.quantity || 0),
                false,
                overlay.config.closeExecutionMode,
                overlay.config.minNetCloseUsd
            );
        }

        const qty = resolveRsiExtremeEntryQuantity(config, price, overlay.config.sizeMultiplier);
        if (!(qty > 0)) return;

        const allowNewOverlayEntries = !state.pauseActive
            && !config.disableNewEntries
            && !operationalPlan?.disableNewEntries;
        if (!allowNewOverlayEntries) return;

        if (overlay.shouldOpenLong) {
            this.openPosition(state, config, symbolInfo, {
                side: 'long',
                qty,
                price,
                takePrice: price,
                nowTs,
                reason: RSI_EXTREME_LONG_REASON,
                sourceQuoteId: `RSI_EXTREME_LONG_${nowTs}`,
                qgHedge: false,
                qgParentSide: 'long',
            });
        }

        if (overlay.shouldOpenShort) {
            this.openPosition(state, config, symbolInfo, {
                side: 'short',
                qty,
                price,
                takePrice: price,
                nowTs,
                reason: RSI_EXTREME_SHORT_REASON,
                sourceQuoteId: `RSI_EXTREME_SHORT_${nowTs}`,
                qgHedge: false,
                qgParentSide: 'short',
            });
        }
    }

    /** Longevity rebase: se cesto (par rebase) valor + taxas + empréstimos net > 0, fecha ambas as ordens. */
    private async runLongevityRebaseCloseWhenPositive(state: any, config: any, price: number, nowTs: number): Promise<void> {
        if (!config.longevityRebaseEnabled) return;
        const positions = this.flattenedPositions(state);
        const byPair = new Map<string, Position[]>();
        for (const p of positions) {
            const pairId = (p as any).longevityRebasePairId;
            if (!pairId) continue;
            if (!byPair.has(pairId)) byPair.set(pairId, []);
            byPair.get(pairId)!.push(p);
        }
        for (const [, group] of byPair) {
            if (group.length === 0) continue;
            const basketNet = group.reduce((sum, p) => sum + this.estimatePositionNet(p, price, nowTs).net, 0);
            if (basketNet <= 0) continue;
            const parentSide: Side = String((group[0] as any)?.qgParentSide || group[0]?.side || 'long') === 'short' ? 'short' : 'long';
            await btCloseEntireCycle(parentSide, {
                getCyclePositions: () => group.map((p) => ({ side: p.side, pos: p })),
                closePosition: async (_side, pos, closeReason, _fullClose, forceLossClose, _minNetOverride, ignorePerPositionPositiveCheck) => {
                    const res = this.closePosition(
                        state,
                        config,
                        pos,
                        price,
                        nowTs,
                        closeReason,
                        Number(pos?.quantity || 0),
                        !!forceLossClose,
                        'maker',
                        undefined,
                        !!ignorePerPositionPositiveCheck
                    );
                    return !!res.closed;
                },
                closeReason: 'longevity_rebase_basket_positive',
                forceLossClose: true,
                ignorePerPositionPositiveCheck: true,
            });
            this.recordSignal(state, {
                type: 'longevity_rebase_close',
                side: 'long',
                reason: 'basket_net_positive',
                pairId: String((group[0] as any)?.longevityRebasePairId || ''),
                maxLevel: group.reduce((max, p) => Math.max(max, Number((p as any)?.longevityRebaseLevel || 0)), 0),
                basketNet,
                closedCount: group.length,
                time: nowTs,
            });
        }
    }

    /** Longevity rebase: nível 1 = posição presa >hold sem par; níveis 2–3 = rebase de rebase 1.5× do exposure do par, até longevityRebaseMaxLevel. */
    private runLongevityRebaseOpenForStuck(state: any, config: any, symbolInfo: any, price: number, nowTs: number): void {
        if (!config.longevityRebaseEnabled) return;
        const holdMs = Math.max(1, Number(config.longevityRebaseHoldMinutes || 1440)) * 60 * 1000;
        const mult = Math.max(1, Number(config.longevityRebaseMultiplier || 1.5));
        const maxLevel = Math.max(1, Math.min(5, Number(config.longevityRebaseMaxLevel ?? 3)));
        if (!state.longevityRebaseLastOpenTs) state.longevityRebaseLastOpenTs = Object.create(null);
        const positions = this.flattenedPositions(state);

        // Nível 1: posição presa sem par rebase
        for (const p of positions) {
            if (p.qgHedge) continue;
            if ((p as any).longevityRebasePairId) continue;
            const ageMs = nowTs - Number(p.entryTime || nowTs);
            if (ageMs < holdMs) continue;
            const pairId = p.positionId;
            const notionalStuck = Math.abs(Number(p.entryPrice || 0) * Number(p.quantity || 0));
            if (!(notionalStuck >= 1e-6)) continue;
            const rebaseNotional = notionalStuck * mult;
            const rebaseSide: Side = p.side === 'long' ? 'short' : 'long';
            const rebaseQty = price > 0 ? rebaseNotional / price : 0;
            if (!(rebaseQty > 0)) continue;
            if (this.shouldBlockLongevityRebaseByAdversePoints(state, config, symbolInfo, p, price, nowTs, 1, pairId)) {
                continue;
            }
            (p as any).longevityRebasePairId = pairId;
            (p as any).isLongevityRebaseStuck = true;
            const opened = this.openPosition(state, config, symbolInfo, {
                side: rebaseSide,
                qty: rebaseQty,
                price,
                takePrice: price,
                nowTs,
                reason: 'longevity_rebase_hedge',
                sourceQuoteId: `LONGREBASE_${pairId}_L1_${nowTs}`,
                qgHedge: true,
                longevityRebasePairId: pairId,
                isLongevityRebaseStuck: false,
                longevityRebaseLevel: 1,
            });
            if (opened) {
                state.longevityRebaseLastOpenTs[pairId] = nowTs;
                this.recordSignal(state, {
                    type: 'longevity_rebase_open',
                    side: rebaseSide,
                    reason: 'stuck_over_24h',
                    pairId,
                    stuckPositionId: pairId,
                    rebaseNotional,
                    holdMinutes: ageMs / (60 * 1000),
                    level: 1,
                    time: nowTs,
                });
            } else {
                (p as any).longevityRebasePairId = undefined;
                (p as any).isLongevityRebaseStuck = undefined;
            }
            break;
        }

        // Rebase de rebase: níveis 2..maxLevel para pares com cesto ainda net <= 0 (usa state atualizado após nível 1)
        const currentPositions = this.flattenedPositions(state);
        const byPair = new Map<string, Position[]>();
        for (const p of currentPositions) {
            const pairId = (p as any).longevityRebasePairId;
            if (!pairId) continue;
            if (!byPair.has(pairId)) byPair.set(pairId, []);
            byPair.get(pairId)!.push(p);
        }
        for (const [pairId, group] of byPair) {
            if (group.length === 0) continue;
            const basketNet = group.reduce((sum, p) => sum + this.estimatePositionNet(p, price, nowTs).net, 0);
            if (basketNet > 0) continue;
            const rebaseLegs = group.filter((p) => !(p as any).isLongevityRebaseStuck);
            const currentLevel = rebaseLegs.length;
            if (currentLevel >= maxLevel) continue;
            const lastOpenTs = state.longevityRebaseLastOpenTs[pairId] ?? Math.min(...group.map((p) => Number(p.entryTime || 0)));
            if (nowTs - lastOpenTs < holdMs) continue;
            // Martingale-style: cada nível adiciona 1.5× o amount da base (perna presa), não 1.5× do net atual
            const stuckLeg = group.find((p) => (p as any).isLongevityRebaseStuck);
            const baseNotional = stuckLeg
                ? Math.abs(Number(stuckLeg.entryPrice || 0) * Number(stuckLeg.quantity || 0))
                : 0;
            if (!(baseNotional >= 1e-6) || !(price > 0)) continue;
            // N1 = 1.5× base; N2 = N1 + 50% base = 2× base; N3 = N2 + 50% base = 2.5× base
            const nextLevel = currentLevel + 1;
            const rebaseNotional = baseNotional * (1.5 + (nextLevel - 1) * 0.5);
            const rebaseQty = rebaseNotional / price;
            if (this.shouldBlockLongevityRebaseByAdversePoints(state, config, symbolInfo, stuckLeg, price, nowTs, nextLevel, pairId)) {
                continue;
            }
            const netNotional = group.reduce(
                (sum, p) => sum + (p.side === 'long' ? 1 : -1) * Number(p.quantity || 0) * price,
                0
            );
            const rebaseSide: Side = netNotional >= 0 ? 'short' : 'long';
            const opened = this.openPosition(state, config, symbolInfo, {
                side: rebaseSide,
                qty: rebaseQty,
                price,
                takePrice: price,
                nowTs,
                reason: 'longevity_rebase_hedge',
                sourceQuoteId: `LONGREBASE_${pairId}_L${nextLevel}_${nowTs}`,
                qgHedge: true,
                longevityRebasePairId: pairId,
                isLongevityRebaseStuck: false,
                longevityRebaseLevel: nextLevel,
            });
            if (opened) {
                state.longevityRebaseLastOpenTs[pairId] = nowTs;
                this.recordSignal(state, {
                    type: 'longevity_rebase_open',
                    side: rebaseSide,
                    reason: 'rebase_of_rebase',
                    pairId,
                    stuckPositionId: pairId,
                    rebaseNotional,
                    level: nextLevel,
                    time: nowTs,
                });
            }
            break;
        }
    }

    private resolveRobotState(state: any, config: any, regime: Regime, risk: any): RobotState {
        const inv = this.normalInventoryNotional(state, Number(state._lastPrice || state.referenceMid || 0));
        const maxInv = this.maxInventoryNotional(config, Number(state._lastPrice || state.referenceMid || 0));
        const invRatioAbs = maxInv > 0 ? Math.abs(inv / maxInv) : 0;
        const skewLight = Number(config.skewLightThreshold || 0.35);
        const skewExit = Number(config.skewSoftExitThreshold || skewLight);

        if (regime !== 'RANGE') return 'TREND_DEFENSIVE';
        if (risk.defensive) return 'MM_SKEW';
        if (String(state.robotState || '') === 'MM_SKEW') {
            return invRatioAbs > skewExit ? 'MM_SKEW' : 'MM_NORMAL';
        }
        if (invRatioAbs >= skewLight) return 'MM_SKEW';
        return 'MM_NORMAL';
    }

    private maybeRebase(
        state: any,
        config: any,
        price: number,
        nowTs: number,
        regime: Regime,
        risk: any,
        mode: RebaseMode = 'all'
    ): { rebased: boolean; reason: string } {
        const cooldownMs = Math.max(0, Number(config.rebaseCooldownMinutes || 0)) * 60 * 1000;
        if (mode !== 'emergency' && Number(state.lastRebaseTs || 0) > 0 && (nowTs - Number(state.lastRebaseTs || 0)) < cooldownMs) {
            return { rebased: false, reason: '' };
        }

        const allowEmergency = mode === 'all' || mode === 'emergency';
        const allowNonEmergency = mode === 'all' || mode === 'non_emergency';

        const reasons: string[] = [];
        const basketNet = this.basketNet(state, price, nowTs);
        const maxInv = this.maxInventoryNotional(config, price);
        const inventoryRatioAbs = maxInv > 0 ? Math.abs(this.normalInventoryNotional(state, price) / maxInv) : 0;
        const residualCount = this.countNormalPositions(state) + (Array.isArray(state.pendingQuotes) ? state.pendingQuotes.length : 0);

        if (allowNonEmergency) {
            if (
                this.countNormalPositions(state) > 0 &&
                basketNet >= Number(config.rebaseProfitUsd || 0) &&
                inventoryRatioAbs <= Number(config.basketInventorySafeThreshold || 0.35)
            ) {
                reasons.push('basket_profit_target');
            }

            if (
                Number(state.realizedSinceRebase || 0) >= Number(config.rebaseProfitUsd || 0) &&
                residualCount > Math.max(0, Number(config.residualLimit || 0))
            ) {
                reasons.push('residual_cleanup');
            }

            if (this.shouldTriggerStaleCycleRebase(state, config, price, nowTs, inventoryRatioAbs)) {
                reasons.push('stale_cycle_rebase');
            }

            if (Math.abs(this.normalInventoryQty(state)) > 0) {
                const ageMs = nowTs - Number(state.lastInventoryReductionTs || nowTs);
                const maxAgeMs = Math.max(1, Number(config.rebaseMaxInventoryMinutes || 360)) * 60 * 1000;
                if (ageMs >= maxAgeMs) reasons.push('inventory_time_limit');
            }

            const ageMs = Number(state.lastRebaseTs || 0) > 0
                ? Math.max(0, nowTs - Number(state.lastRebaseTs || 0))
                : Math.max(0, nowTs - Number(state.rebaseWindowStartTs || nowTs));
            const ageThresholdMs = Math.max(1, Number(config.rebaseMaxInventoryMinutes || 360)) * 60 * 1000;
            const ageHours = ageMs / (60 * 60 * 1000);
            const realizedWindow = Number(state.realizedSinceRebase || 0);
            const pnlPerHour = ageHours > 0 ? (realizedWindow / ageHours) : realizedWindow;
            if (
                ageMs >= ageThresholdMs &&
                pnlPerHour < Number(config.rebasePnlPerHourFloorUsd || 0) &&
                inventoryRatioAbs > Number(config.skewLightThreshold || 0.35)
            ) {
                reasons.push('stagnation_tunnel');
            }

            if (config.rebaseOnRegimeFlip) {
                const prevRegime = (state.lastRegime || regime) as Regime;
                const regimeFlipped = prevRegime !== regime && prevRegime !== 'RANGE' && regime !== 'RANGE';
                if (regimeFlipped) reasons.push('regime_flip');
            }
        }

        if (allowEmergency) {
            if (config.rebaseOnRiskCritical && risk.requireRebase) reasons.push('risk_rebase');
            if (String(state.forceRebaseReason || '').trim()) {
                reasons.push(String(state.forceRebaseReason));
            }
        }

        if (!reasons.length) return { rebased: false, reason: '' };

        const ordered = [...this.flattenedPositions(state)]
            .filter((p) => this.isEligibleForNormalLegManagement(p))
            .sort((a, b) => {
                if (!!a.qgHedge !== !!b.qgHedge) return a.qgHedge ? -1 : 1;
                const na = Math.abs(Number(a.quantity || 0) * Number(a.entryPrice || 0));
                const nb = Math.abs(Number(b.quantity || 0) * Number(b.entryPrice || 0));
                return na - nb;
            });
        if (!ordered.length) return { rebased: false, reason: '' };

        this.recordSignal(state, {
            type: 'rebase_trigger',
            side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
            reason: reasons.join('|'),
            basketNet,
            regime,
            marginUsedPct: Number(state.marginUsedPct || 0),
            drawdownPct: Number(state.drawdownPct || 0),
            mode,
            time: nowTs,
        });

        state.pendingQuotes = [];
        const closeReason = this.resolveRebaseCloseReason(reasons);
        let closedCount = 0;
        for (const p of ordered) {
            this.annotateRebaseAttempt(p, closeReason, reasons, nowTs);
            const res = this.closePosition(state, config, p, price, nowTs, closeReason, p.quantity, false);
            if (res.closed) {
                closedCount += 1;
            } else if (res.blockReason) {
                this.annotateRebaseBlocked(p, res.blockReason);
            }
        }

        state.referenceMid = price;
        state.lastRequoteTs = nowTs;
        state.lastRebaseTs = nowTs;
        state.rebaseWindowStartTs = nowTs;
        state.realizedSinceRebase = 0;
        state.rebaseCount = Number(state.rebaseCount || 0) + 1;
        state.lastInventoryReductionTs = nowTs;
        state.lastInventoryReductionPrice = price;
        state.forceRebaseReason = '';
        state.hedgeActive = false;
        state.hedgeParentSide = null;
        state.hedgeWatchStartTs = 0;
        state.hedgeWatchInventoryAbs = 0;
        state.hedgeMinInventoryAbsSeen = 0;
        const remaining = this.countNormalPositions(state);
        if (remaining > 0) {
            for (const p of this.flattenedPositions(state)) {
                p.rebasePartialCleanupCount = Number(p.rebasePartialCleanupCount || 0) + 1;
            }
            this.recordSignal(state, {
                type: 'rebase_partial_cleanup',
                side: this.inventoryQty(state) >= 0 ? 'long' : 'short',
                reason: 'min_net_not_reached',
                remainingPositions: remaining,
                time: nowTs,
            });
        }

        this.recordSignal(state, {
            type: 'rebase',
            side: 'long',
            reason: reasons.join('|'),
            time: nowTs,
            price,
            rebaseCount: state.rebaseCount,
            closedCount,
            remainingPositions: remaining,
        });

        return { rebased: true, reason: reasons.join('|') };
    }

    private updateBalances(state: any, balances: any, risk: any): void {
        if (!balances || typeof balances !== 'object') return;
        balances.usedMargin = Number(risk.marginUsed || 0);
        balances.marginUsed = Number(risk.marginUsed || 0);
        balances.marginUsedPct = Number(risk.marginUsedPct || 0);
        balances.equity = Number(risk.equity || 0);
        balances.available = Number(risk.equity || 0) - Number(risk.marginUsed || 0);
        balances.availableOperational = Number(risk.availableOperational || 0);
        balances.realizedCollateral = Number(risk.realizedCollateral || 0);
        balances.collateralOperational = Number(risk.collateralOperational || 0);
        balances.marginLevel = isFinite(Number(risk.marginLevel)) ? Number(risk.marginLevel) : null;
        balances.grossOpenNotional = Number(risk.grossOpenNotional || 0);
        balances.accountOperationalNotionalLimit = Number(risk.accountOperationalNotionalLimit || 0);
        balances.hardAccountOperationalNotionalLimit = Number(risk.hardAccountOperationalNotionalLimit || 0);
        balances.strategyOperationalNotionalLimit = Number(risk.strategyOperationalNotionalLimit || 0);
        balances.capitalOperationalNotionalLimit = Number(risk.capitalOperationalNotionalLimit || 0);
        balances.configuredAccountOperationalMaxGrossNotional =
            isFinite(Number(state.configuredAccountOperationalMaxGrossNotional)) && Number(state.configuredAccountOperationalMaxGrossNotional) > 0
                ? Number(state.configuredAccountOperationalMaxGrossNotional)
                : null;
        balances.realizedPnL = Number(state.totalRealizedNet || 0);
        balances.unrealizedPnL = Number(risk.unrealized || 0);
    }

    async executeIteration(_ctx: any, iterationConfig: any, state: any, balances: any, symbolInfo: any, tickerInfo: any, currentIndicators: any) {
        const runtimeContext = this.ensureRuntimeContext(_ctx);
        this._liveMode = !!(runtimeContext as any).liveMode;
        this._liveExecutionMode = (runtimeContext as any).executionMode === 'demo' ? 'demo' : 'real';
        if (!this._liveMode) applyRuntimeContextToGlobal(runtimeContext);

        try {
            const config = { ...this.config, ...(iterationConfig || {}) };
const nowTs = this.nowTsFromTicker(tickerInfo);
            const quoteMid = this.readMidPrice(tickerInfo);
            const markPrice = this.readMarkPrice(tickerInfo);
            if (!(isFinite(quoteMid) && quoteMid > 0 && isFinite(markPrice) && markPrice > 0)) return;

            const range = this.readCandleRange(tickerInfo, quoteMid);
            const atr = Number(currentIndicators?.atr ?? 0);
            const spreadPct = this.readSpreadPct(tickerInfo, quoteMid);
            const candleRangePct = quoteMid > 0 ? (Math.max(0, range.high - range.low) / quoteMid) : 0;

            const s = this.ensureState(state, config, quoteMid, nowTs);
            s.iterationSeq = Number(s.iterationSeq || 0) + 1;
            s._lastTickerTs = nowTs;
            s._lastPrice = markPrice;
            if (!config.executionOnly) {
                this.updateRecentCloses(s, markPrice, Math.max(120, Number(config.regimeDirectionalLookback || 6) * 6));
                appendRecentSeriesValue(s, 'recentSpreadPcts', spreadPct, 180);
                appendRecentSeriesValue(s, 'recentCandleRangePcts', candleRangePct, 180);
            }
            const preLiquidationRegime = (s.regime || 'RANGE') as Regime;
            if (s.accountLiquidated) {
                s.pendingQuotes = [];
                const haltedRisk = this.evaluateRisk(s, config, markPrice, nowTs, preLiquidationRegime);
                this.updateBalances(s, balances, haltedRisk);
                s.equityTimeline.push({ time: nowTs, equity: Number(haltedRisk.equity || 0) });
                if (s.equityTimeline.length > 20000) {
                    s.equityTimeline.splice(0, s.equityTimeline.length - 20000);
                }
                this.syncGlobalOpenPositions(s);
                return;
            }
            const preLiquidationRisk = this.evaluateRisk(s, config, markPrice, nowTs, preLiquidationRegime);
            if (this.shouldRunManagedCycleTechnicalStops(config, preLiquidationRisk, null, s)) {
                this.runTechnicalStops(s, config, range.high, range.low, markPrice, nowTs);
                this.runMaxUnrealizedLossCap(s, config, markPrice, nowTs, range);
            }
            this.runIsolatedLiquidations(s, config, markPrice, nowTs);
let liquidationRisk = this.evaluateRisk(s, config, markPrice, nowTs, preLiquidationRegime);
            const accountLiquidation = this.maybeLiquidateAccount(s, config, markPrice, nowTs, liquidationRisk);
            liquidationRisk = accountLiquidation.risk;
            if (accountLiquidation.liquidated) {
                this.updateBalances(s, balances, liquidationRisk);
                s.equityTimeline.push({ time: nowTs, equity: Number(liquidationRisk.equity || 0) });
                if (s.equityTimeline.length > 20000) {
                    s.equityTimeline.splice(0, s.equityTimeline.length - 20000);
                }
                this.syncGlobalOpenPositions(s);
                return;
            }

            // Modo "realistic": só execução (fill, SL/TP, max_unrealized) — sem novas decisões (regime, requote, entradas).
            if (config.executionOnly) {
                const regime = (s.regime || 'RANGE') as Regime;
                const managedCycleMode = this.isManagedCycleModeEnabled(config);
                if (this.shouldDeferEntriesToExecution(config)) {
                    this.consumeDeferredEntryIntents(s, config, symbolInfo, {
                        nowTs,
                        mid: quoteMid,
                        atr: Number(currentIndicators?.atr ?? 0),
                        regime,
                        bestBid: Number(tickerInfo?.bestBid ?? quoteMid),
                        bestAsk: Number(tickerInfo?.bestAsk ?? quoteMid),
                    });
                }
                const executionRisk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
                if (this.shouldRunManagedCycleTechnicalStops(config, executionRisk, null, s)) {
                    this.runTechnicalStops(s, config, range.high, range.low, markPrice, nowTs);
                    this.runMaxUnrealizedLossCap(s, config, markPrice, nowTs, range);
                }
                if (!this.isLiveRealMode()) {
                    this.consumeQuoteFills(s, config, symbolInfo, {
                        nowTs,
                        mid: quoteMid,
                        high: range.high,
                        low: range.low,
                        atr: Number(currentIndicators?.atr ?? 0),
                        regime,
                    });
                    this.processLiveRealPairingManagement(s, config, range.high, range.low, markPrice, nowTs);
                }
                this.runManagedStuckCycles(s, config, symbolInfo, markPrice, nowTs);
                this.syncLiveRealReduceOnlyPairQuotes(s, config, nowTs);
                this.pruneStaleQuotes(s, config, quoteMid, nowTs);
                const newRisk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
                this.updateBalances(s, balances, newRisk);
                s.equityTimeline.push({ time: nowTs, equity: Number(newRisk.equity || 0) });
                if (s.equityTimeline.length > 20000) {
                    s.equityTimeline.splice(0, s.equityTimeline.length - 20000);
                }
                this.syncGlobalOpenPositions(s);
                return;
            }

            const forceCloseReason = String(config.forceCloseAllReason || '').trim();
            if (forceCloseReason) {
                s.pendingQuotes = [];
                const forceMode = this.normalizeExecutionMode(config.forceCloseAllMode || 'market', 'market');
                const forcedCount = this.closeAllPositions(
                    s,
                    config,
                    markPrice,
                    nowTs,
                    forceCloseReason,
                    true,
                    forceMode
                );
                if (forcedCount > 0) {
                    this.recordSignal(s, {
                        type: 'forced_close_batch',
                        side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                        reason: forceCloseReason,
                        time: nowTs,
                        closeMode: forceMode,
                        closedCount: forcedCount,
                    });
                }
                const forcedRisk = this.evaluateRisk(s, config, markPrice, nowTs, (s.regime || 'RANGE') as Regime);
                this.updateBalances(s, balances, forcedRisk);
                s.equityTimeline.push({ time: nowTs, equity: Number(forcedRisk.equity || 0) });
                if (s.equityTimeline.length > 20000) {
                    s.equityTimeline.splice(0, s.equityTimeline.length - 20000);
                }
                this.syncGlobalOpenPositions(s);
                return;
            }

            const rawRegime = this.classifyRegime(s, currentIndicators, config);
            const regime = this.resolveStableRegime(s, config, rawRegime);
            if (s.regime !== regime) {
                this.recordSignal(s, {
                    type: 'regime_transition',
                    side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                    from: s.regime,
                    to: regime,
                    rawRegime,
                    time: nowTs,
                });
                s.lastRegime = s.regime;
                s.regime = regime;
            }

            // Macro-trend tracker for the CMI macro gate. A gentle multi-day grind keeps
            // price within fractions of a percent of any fast EMA, so we detect drift via a
            // cumulative return over a long lookback sampled on an hourly ring buffer.
            {
                const sampleEveryMs = 60 * 60 * 1000; // hourly sample
                const lookbackHours = Math.max(6, Number(config.criticalMarginInjectionMacroLookbackHours ?? 168));
                const minMovePct = Math.max(0, Number(config.criticalMarginInjectionMacroMinMovePct ?? 0.012));
                const px = Math.max(0, Number(markPrice || 0));
                if (px > 0) {
                    if (!Array.isArray(s._macroPxBuf)) { s._macroPxBuf = []; s._macroPxLastTs = 0; }
                    if (!(Number(s._macroPxLastTs) > 0) || (nowTs - Number(s._macroPxLastTs)) >= sampleEveryMs) {
                        s._macroPxBuf.push(px);
                        s._macroPxLastTs = nowTs;
                        const cap = lookbackHours + 2;
                        if (s._macroPxBuf.length > cap) s._macroPxBuf.splice(0, s._macroPxBuf.length - cap);
                    }
                    const buf = s._macroPxBuf as number[];
                    if (buf.length > lookbackHours) {
                        const ref = buf[buf.length - 1 - lookbackHours];
                        const ret = ref > 0 ? (px - ref) / ref : 0;
                        s.macroTrendDirection = ret <= -minMovePct ? -1 : (ret >= minMovePct ? 1 : 0);
                    } else {
                        s.macroTrendDirection = 0;
                    }
                }
            }

            const managedCycleMode = this.isManagedCycleModeEnabled(config);
            if (config.longevityRebaseEnabled && !managedCycleMode) {
                // A positive longevity basket should exit before unrelated emergency cleanup.
                await this.runLongevityRebaseCloseWhenPositive(s, config, markPrice, nowTs);
            }

            let risk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
            if (this.flattenedNormalPositions(s).length > 0) {
                maybeCriticalMarginProactiveTopUp({
                    state: s,
                    config,
                    symbolInfo,
                    risk,
                    markPrice,
                    nowTs,
                    preferredSide: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                    normalPositions: this.flattenedNormalPositions(s),
                    recordSignal: (signalState, signal) => this.recordSignal(signalState, signal),
                });
                risk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
            }
            const operationalMarket = this.buildOperationalMarketSnapshot(
                config,
                s,
                currentIndicators,
                markPrice,
                spreadPct,
                candleRangePct,
                nowTs
            );
            const { plan: operationalPlan } = resolveOperationalSafetyAndPlan({
                config,
                indicators: currentIndicators,
                market: operationalMarket,
                risk,
                robotState: s.robotState,
                // Paridade com MarketKingTrade/InstitutionalHybridAdapter (nao usar hedgeActive do estado do motor).
                hedgeActive: false,
            });
            const executionStats = this.ensureExecutionStats(s);
            if (operationalPlan) {
                if (operationalPlan.disableNewEntries) {
                    executionStats.operationalPlanPausedCount = Number(executionStats.operationalPlanPausedCount || 0) + 1;
                    this.incrementMapCounter(executionStats.operationalPauseReasons, String(operationalPlan.pauseReason || 'paused'));
                } else {
                    executionStats.operationalPlanActiveCount = Number(executionStats.operationalPlanActiveCount || 0) + 1;
                }
                this.recordOperationalPlanTransition(s, operationalPlan, nowTs);
            }

            const emergencyRebase = managedCycleMode
                ? this.maybeManagedCycleEmergencyFlatten(s, config, markPrice, nowTs, regime, risk)
                : this.maybeRebase(s, config, markPrice, nowTs, regime, risk, 'emergency');

            if (!emergencyRebase.rebased) {
                if (this.shouldRunManagedCycleTechnicalStops(config, risk, operationalPlan, s)) {
                    this.runTechnicalStops(s, config, range.high, range.low, markPrice, nowTs);
                    this.runMaxUnrealizedLossCap(s, config, markPrice, nowTs, range);
                }
                risk = this.evaluateRisk(s, config, markPrice, nowTs, regime);

                // 2) Robot state from risk/regime (institutional inventory hedge removed; parity with MarketKing adapter).
                const resolvedRobotState = this.resolveRobotState(s, config, regime, risk);
                if (s.robotState !== resolvedRobotState) {
                    this.recordSignal(s, {
                        type: 'state_transition',
                        side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                        from: s.robotState,
                        to: resolvedRobotState,
                        regime,
                        time: nowTs,
                    });
                    s.robotState = resolvedRobotState;
                }

                if (config.longevityRebaseEnabled && !managedCycleMode) {
                    this.runLongevityRebaseOpenForStuck(s, config, symbolInfo, markPrice, nowTs);
                }

                risk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
                const resolvedAfterLongevity = this.resolveRobotState(s, config, regime, risk);
                if (s.robotState !== resolvedAfterLongevity) {
                    this.recordSignal(s, {
                        type: 'state_transition',
                        side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                        from: s.robotState,
                        to: resolvedAfterLongevity,
                        regime,
                        time: nowTs,
                    });
                    s.robotState = resolvedAfterLongevity;
                }

                // 3) Quotes, natural exits and basket closes before non-emergency rebase.
                this.maybePairedRangeBootstrap(s, config, symbolInfo, markPrice, nowTs, operationalPlan, atr);

                const disableNewEntries = !!config.disableNewEntries || !!operationalPlan?.disableNewEntries;
                executionStats.requoteChecks = Number(executionStats.requoteChecks || 0) + 1;
                const operationalRefreshMinMs = Math.max(
                    this.resolveOperationalRefreshMinMs(config),
                    Number(config.requoteCooldownMs || 0)
                );
                const operationalRefreshDue = !!operationalPlan
                    && !operationalPlan.disableNewEntries
                    && ((nowTs - Number(s.lastRequoteTs || 0)) >= operationalRefreshMinMs);
                if (operationalRefreshDue || this.shouldRequote(s, config, quoteMid, atr, nowTs)) {
                    executionStats.requoteTriggered = Number(executionStats.requoteTriggered || 0) + 1;
                    if (this.canRequoteByRateLimit(s, config, nowTs)) {
                        s.referenceMid = quoteMid;
                        s.lastRequoteTs = nowTs;
                        this.markRequote(s);
                        const builtQuotes = this.buildQuotes(s, config, risk, {
                            nowTs,
                            mid: quoteMid,
                            atr,
                            regime,
                            disableNewEntries,
                            operationalPlan,
                        });
                        const reduceOnlyQuotes = (Array.isArray(s.pendingQuotes) ? s.pendingQuotes : [])
                            .filter((quote: QuoteOrder) => !!quote.reduceOnly);
                        if (this.shouldDeferEntriesToExecution(config)) {
                            this.stageDeferredEntryIntents(s, builtQuotes);
                            s.pendingQuotes = reduceOnlyQuotes;
                        } else {
                            s.pendingQuotes = [...builtQuotes, ...reduceOnlyQuotes];
                        }
                    } else {
                        executionStats.requoteBlockedRateLimit = Number(executionStats.requoteBlockedRateLimit || 0) + 1;
                        this.recordSignal(s, {
                            type: 'requote_blocked',
                            side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                            reason: 'max_requotes_per_minute',
                            time: nowTs,
                            requotesInWindow: Number(s.requoteWindowCount || 0),
                        });
                    }
                }

                // Em live/real o motor nao pode assumir fill por candle nem take maker por toque.
                if (!this.isLiveRealMode()) {
                    this.consumeQuoteFills(s, config, symbolInfo, {
                        nowTs,
                        mid: quoteMid,
                        high: range.high,
                        low: range.low,
                        atr,
                        regime,
                    });
                    this.processBasketCentralScalp(s, config, symbolInfo, markPrice, nowTs);
                    this.processLiveRealPairingManagement(s, config, range.high, range.low, markPrice, nowTs);
                }
                this.runManagedStuckCycles(s, config, symbolInfo, markPrice, nowTs);

                if (!managedCycleMode) {
                    let rebasedByBasket = false;
                    const basketNetNow = this.basketNet(s, markPrice, nowTs);
                    const basketInvRatioAbs = (() => {
                        const maxInv = this.maxInventoryNotional(config, markPrice);
                        return maxInv > 0 ? Math.abs(this.normalInventoryNotional(s, markPrice) / maxInv) : 0;
                    })();
                    if (
                        this.countNormalPositions(s) > 0 &&
                        basketNetNow >= Number(config.basketTakeProfitUsd || 0) &&
                        basketInvRatioAbs <= Number(config.basketInventorySafeThreshold || 0.35)
                    ) {
                        this.recordSignal(s, {
                            type: 'basket_take_profit',
                            side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                            time: nowTs,
                            basketNet: basketNetNow,
                            reason: 'basket_net_target',
                        });
                        rebasedByBasket = this.maybeRebase(
                            s,
                            { ...config, rebaseProfitUsd: 0 },
                            markPrice,
                            nowTs,
                            regime,
                            risk,
                            'non_emergency'
                        ).rebased;
                    }

                    this.runDefensiveInventoryReduction(s, config, markPrice, nowTs, risk);

                    if (!rebasedByBasket) {
                        const postExecutionRisk = this.evaluateRisk(s, config, markPrice, nowTs, regime);
                        this.maybeRebase(s, config, markPrice, nowTs, regime, postExecutionRisk, 'non_emergency');
                    }
                }

                this.processRsiExtremeOverlay(s, config, symbolInfo, markPrice, nowTs, currentIndicators, operationalPlan);
            }

            this.syncLiveRealReduceOnlyPairQuotes(s, config, nowTs);

            // 6) Maintenance cleanup.
            this.pruneStaleQuotes(s, config, quoteMid, nowTs);

            const newRisk = this.evaluateRisk(s, config, markPrice, nowTs, regime);

            const nextState = this.resolveRobotState(s, config, regime, newRisk);
            if (s.robotState !== nextState) {
                this.recordSignal(s, {
                    type: 'state_transition',
                    side: this.inventoryQty(s) >= 0 ? 'long' : 'short',
                    from: s.robotState,
                    to: nextState,
                    regime,
                    time: nowTs,
                });
                s.robotState = nextState;
            }

            this.updateBalances(s, balances, newRisk);

            s.equityTimeline.push({ time: nowTs, equity: Number(newRisk.equity || 0) });
            if (s.equityTimeline.length > 20000) {
                s.equityTimeline.splice(0, s.equityTimeline.length - 20000);
            }

            this.syncGlobalOpenPositions(s);
        } catch (err: any) {
            this.logger.error(
                '[MockMarketKingTradingService] executeIteration failed',
                err?.stack || err?.message || err
            );
            throw err;
        } finally {
            syncRuntimeContextFromGlobal(runtimeContext);
        }
    }
}

export default MockMarketKingTradingService;
