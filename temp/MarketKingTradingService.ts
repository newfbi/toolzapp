import { MarketKingCalculations, MarketKingAccountMetrics, PositionRisk } from './MarketKingCalculations';
import { MarketKingRiskManager, RiskAlert } from './MarketKingRiskManager';
import createLogger from '../../lib/logger';
import { calculateEMA } from '../Services/getEMA';
import { OperationManagerDBOptimistic } from '../../lib/OperationManagerDBOptimistic';
import axios from 'axios';
import type { ExchangeSignalClient } from '../../lib/Api/ExchangeWsClient';
import { BatchOperations } from '../../lib/BatchOperations';
import { initMode, getMode } from '../OperationModeHelper';
import { PineScriptService, PineScriptServiceConfig } from './pineGenerate/PineScriptService';
import {
    getGridEntryPrice,
    getGridOpenTime,
    getGridParentSide,
    normalizeOrderQty
} from './GridHelpers';
import { cs_computeCycleSnapshot, cs_computeCycleDrawdown } from './CycleSnapshotAndDrawdown';
import {
    closeEntireCycle as dm_closeEntireCycle
} from './DilutionManager';
import { calcAccumulatedInterest, calcNetProfitWithLoan, calcPositionNet } from './PositionNetUtils';
import { getCloseRatio, getProportionalValue, getBorrowedAmountForClose } from './FinancialUtils';
import { HedgeManager } from './HedgeManagement';
import { runInstitutionalHybridIteration } from './InstitutionalHybridAdapter';
import {
    applyPositionMarginUpdatesToState,
    type InstitutionalPositionMarginUpdate,
} from '../shared/InstitutionalCriticalMarginLiveSync';
import {
    alignInstitutionalPersistedStateToRuntimeLedger,
    collectRuntimePositionIds,
    reconcileInstitutionalPersistedStateAfterLive,
} from '../shared/InstitutionalHybridLiveLedgerReconcile';
import {
    buildInstitutionalNotionalDiagSuffix,
    resolveInstitutionalOpenRejectLabel,
} from '../shared/InstitutionalHybridIterationDiag';
import { MarketKingSafetyGates } from './security/MarketKingSafetyGates';
import { MarketKingBrokerOperations } from './operations/MarketKingBrokerOperations';
import { MarketKingOperations } from './operations/MarketKingOperations';
import {
    applyManagedFutureRuntimeSettings,
    buildManagedFutureOrderPayload,
    getManagedFuturesVenueLabel,
    isManagedFuturesExchange,
    resolveManagedFuturesMarginMode,
    resolveManagedFuturesProductType,
} from '../../exchange/ManagedFuturesRuntime';
import { applyRuntimeCreditUsageEstimate } from '../shared/CreditRuntimeGuard';
import { emitRuntimeRobotOperation, emitRuntimeRobotSignal } from '../../GlobalRuntime/GlobalRobotEventBus';
import { isRsiExtremeOverlayPosition } from '../../lib/InstitutionalHybrid/RsiExtremeOverlay';
import { LEGACY_GRID_PROFILE_PREFIX } from './runtime/legacyGridProfilePrefix';
import { estimatePerpFundingCost, resolveIterationTimestamp } from '../../lib/FundingCostUtils';
import {
    resolveIndicatorLogGranularity,
    resolveIndicatorLogRsiThresholds,
} from '../shared/IndicatorLogFormat';
import { resolveCryptoQuantityRules } from '../../exchange/CryptoOrderSizing';
import { markLossCapClose, resolveLossCapCooldownStatus } from '../../lib/InstitutionalHybrid/LossCapCooldownPolicy';

export interface MarketKingTradingConfig {
    botId: number;
    userId?: number; // ID do usuÃ¡rio para Pine Script Service
    botName: string;
    tradeType: string;
    executionTradeType?: string;
    /** 'notional' => amount eh notional em quote; 'margin_base' => amount eh margem base e notional = amount * leverage */
    amountSizingMode?: 'notional' | 'margin_base';
    exchange?: string;
    pair1: string;
    pair2: string;
    market: string;
    takeLoss: number;
    amount: number;
    /** Capital USDT do utilizador; em runtime costuma ser igual a `amount` (amount = bankroll no front). */
    bankroll?: number;
    /** Margem estimada × buffer vs saldo ao limitar quotes em futures gerenciado. Default ~1.34 */
    institutionalOpenMarginBuffer?: number;
    /** Retries ao receber 40762: divide qty a cada tentativa (aberturas maker). Default 8 */
    institutional40762MaxRetries?: number;
    /** Intervalo mínimo entre logs de getBalance futures API (ms). Default 60000 */
    futuresBalanceLogIntervalMs?: number;
    granularity?: string;
    /** Limite POR contador: ate N compras/long (buy_count) e ate N vendas/short (sell_count). Nao e a soma. */
    maxOperations: number;
    rsiPeriod: number;
    emaPeriod: number;
    rsiBuy: number;
    rsiSell: number;
    opType: string;
    leverage: number;
    maintenanceMarginRate?: number;
    marginMode: 'cross' | 'isolated';
    stopLossRatio?: number;
    stopLossPct?: number;
    takeProfitRatio: number;
    maxHoldingMs?: number;
    prolongedExposureDays?: number;
    prolongedExposureCycleReset?: boolean;
    prolongedExposureMinCycleNetUsd?: number;
    /** Perda maxima de uma perna quando o ciclo agregado foi aprovado. */
    cycleNetApprovedMaxLegLossUsd?: number;
    /** Limiar minimo de net para fechamento por holding/prolonged exposure */
    holdingCloseMinNetUsd?: number;
    /** Perda maxima tolerada para fechamento por holding/prolonged exposure */
    holdingMaxAllowedNegativeUsd?: number;
    liquidationMarginLevelThreshold?: number;
    defensiveCloseMarginLevelThreshold?: number;
    minEntryMarginLevel?: number;
    liquidationPenaltyPct?: number;
    minEquityForNewEntries?: number;
    minAvailableUsdtForNewEntries?: number;
    liquidationRiskBufferPct?: number;
    liquidationBufferPctCritical?: number;
    liquidationMarginRatioThreshold?: number;
    conservativeMode?: boolean;
    maxSkipCloseAttempts?: number;
    maxLossOnMarginPct?: number;
    maxHoldingForceFactor?: number;
    equilibriumFloorUsd?: number;
    enforceEquilibriumFloor?: boolean;
    forceCloseLossPctOfBankroll?: number;
    forceCloseInterestPctOfMargin?: number;
    verboseLossContext?: boolean;
    /** Parâmetros de estratégia (valores padrão se não fornecidos) */
    /** Threshold ADX para tendencia forte (padrao: 25) */
    adxStrongTrendThreshold?: number;
    /** RSI overbought threshold (padrao: 70) */
    rsiOverbought?: number;
    /** RSI oversold threshold (padrao: 30) */
    rsiOversold?: number;
    /** RSI para divergencia bullish (padrao: 30) */
    rsiBullishDivergence?: number;
    /** RSI para divergencia bearish (padrao: 70) */
    rsiBearishDivergence?: number;
    rsiCloseHigh?: number;
    rsiCloseLow?: number;
    /** Offset das bandas de Bollinger (padrao: 0.25 = 25%) */
    bollingerBandOffset?: number;
    /** Multiplicador ATR para spike (padrao: 1.5) */
    atrSpikeMultiplier?: number;
    /** Valor fallback ATR se nao houver media (padrao: 0.01) */
    atrFallback?: number;
    /** Histerese RSI para venda (padrao: 2) */
    rsiHysteresis?: number;
    /** Multiplicador EMA para pullback em uptrend (padrao: 1.02 = 2%) */
    emaPullbackMultiplier?: number;
    /** Multiplicador EMA para rally em downtrend (padrao: 0.98 = 2%) */
    emaRallyMultiplier?: number;
    /** Taxa de juros diaria padrao (padrao: 0.0001 = 0.01%) */
    defaultDailyInterestRate?: number;
    /** Fee taker padrao quando a exchange nao retornar taxa do simbolo */
    defaultTakerFeeRate?: number;
    /** Funding rate estimado por janela (padrao: 8h) para Future Perp. Ex.: 0.0001 = 0.01% */
    defaultFundingRatePer8h?: number;
    /** Override explicito do funding rate por 8h */
    fundingRatePer8h?: number;
    /** Janela de funding em horas (BitGet perp geralmente 8h) */
    fundingIntervalHours?: number;
    /** Modelo de funding no net de derivativos (realista = signed; conservador = absolute_cost) */
    fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
    /** Nivel de margem critico (padrao: 1.5) */
    criticalMarginLevel?: number;
    /** Leverage maximo permitido (padrao: 10) */
    maxLeverage?: number;
    /** Tolerancia para comparacao de precos (padrao: 0.0001) */
    priceTolerance?: number;
    /** Distancia minima em pontos adversos desde a base para liberar o primeiro leak/martingale. */
    leakMinAdversePointsFromBase?: number;
    /** Teto de pontos adversos desde a base; acima disso bloqueia novos martingales. */
    maxAdversePointsFromBase?: number;
    /** Tamanho de 1 ponto em preco para calculo do distanciamento adverso. */
    adversePointsPriceTick?: number;
    /** Limite maximo de valor total operado (ex: 1000 = maximo 1000 USDT em posicoes). Se nao definido, nao ha limite. */
    maxTotalAmount?: number;
    /** Limite minimo de lucro para fechar uma posicao (padrao: 0.01) */
    minCloseProfitUsd?: number;
    /** Limite minimo de lucro liquido para fechamento (apos custos) */
    minNetCloseUsd?: number;
    /** Piso de net (USDT) para permitir fechar: bloqueia se net < minNetFloorUsd. Ex: -2 permite fechar ate -2. Definido no preset institucional. */
    minNetFloorUsd?: number;
    /** Piso como % da margin por operacao: floor = baseInitialMarginPerOrder * (pct/100). Ex: -1.9 com margin 105 -> -2 USDT. Escala com bankroll/amount. */
    minNetFloorPercentOfAmount?: number | null;
    /** Só solicitar fechamento fallback (net negativo) após posição aberta este tempo (min). 0 = desligado. Gatilho um passo antes: aguardar movimento. */
    minHoldMinutesBeforeNegativeClose?: number;
    /** Lucro liquido minimo esperado para validar entradas */
    minExpectedNetUsd?: number;
    /** Threshold ADX usado no fluxo do motor institucional. */
    adxThreshold?: number;
    /** ATR diluiÃ§Ã£o: multiplicador do ATR para gatilho (ex: 0.9) */
    atrDilutionMultiplier?: number;
    /** Limite de ADX para entradas counter-trend */
    counterTrendMaxAdx?: number;
    /** Escala de tamanho para entradas counter-trend */
    counterTrendSizeScale?: number;
    /** Cobertura minima de custos para entrada */
    minEntryCostCoverage?: number;
    /** Multiplicador ATR para alvo em tendencia */
    trendTargetAtrMultiplier?: number;
    /** Multiplicador ATR para alvo em counter-trend */
    counterTargetAtrMultiplier?: number;
    /** Holding estimado para modelagem de custo de entrada */
    entryCostEstHoldMs?: number;
    /** Multiplicador de cobertura de custo para LONG */
    longEntryCostCoverageMultiplier?: number;
    /** Multiplicador minimo de lucro em saida confirmada */
    confirmedExitMinProfitMultiplier?: number;
    /** Multiplicador minimo de lucro em reversao de tendencia */
    trendReversalExitProfitMultiplier?: number;
    /** Ativacao do break-even */
    breakEvenActivationPct?: number;
    /** Trava de lucro apos break-even */
    breakEvenLockPct?: number;
    /** Limiar para saida precoce de perda */
    earlyLossExitPct?: number;
    /** Exigir confirmacao de tendencia para saida precoce de perda */
    earlyLossRequireTrend?: boolean;
    /** Relacao risco-retorno minima para entrada */
    minRiskRewardRatio?: number;
    /** Teto de RSI para entradas long de momentum */
    momentumRsiCeilLong?: number;
    /** Piso de RSI para entradas short de momentum */
    momentumRsiFloorShort?: number;
    /** Multiplicador ATR para stop no calculo de risco-retorno */
    stopAtrMultiplierForRr?: number;
    pairAgeReduceMinNetUsd?: number;
    maxHoldStopMinNetUsd?: number;
    minNetClosePercent?: number;
    basketMinNetPercent?: number;
    autoScaleMinNetWithNotional?: boolean;
    minNetCloseReferenceNotionalUsd?: number;
    /** Usa estimativa de fee maker/mista em vez de taker puro */
    useExpectedMakerFee?: boolean;
    /** Taxa maker fixa para modelagem de custos de entrada */
    makerFeeRate?: number;
    /** Percentual de slippage estimado */
    slippagePercent?: number;
    /** Modelo de slippage: fechamento apenas ou ida+volta */
    slippageModel?: 'one_sided' | 'two_sided';
    /** Modo de execucao/custo da perna de abertura (maker/taker/market). */
    runtimeOpenExecutionMode?: 'maker' | 'taker' | 'market';
    /** Modo de execucao/custo da perna de fechamento (maker/taker/market). */
    runtimeCloseExecutionMode?: 'maker' | 'taker' | 'market';
    /** Slippage por perna maker (padrao 0; usar residual se quiser modelagem conservadora). */
    runtimeMakerSlippagePercent?: number;
    /** Slippage por perna taker/market (fallback usa slippagePercent). */
    runtimeTakerSlippagePercent?: number;
    /** Forca post_only em ordens maker no Future/Mix. */
    runtimeMakerPostOnly?: boolean;
    /** Mantem ordem maker pendente apos timeout de monitoramento (risco de sync se fill atrasado). */
    runtimeMakerKeepPendingOnTimeout?: boolean;
    /** Delay em ms entre ordens consecutivas (ex.: compra e venda no bootstrap/RED). Evita rejeicao da 2a ordem. Default 1500. */
    orderDelayMs?: number;
    /** Cooldown em ms apos enviar ordem de FECHAMENTO pendente (nao preenchida). Evita sequencia de dezenas de tentativas. Default 60000. */
    closeCooldownMs?: number;
    /** Exigir tendencia confirmada para entradas LONG */
    requireTrendForLongEntries?: boolean;
    /** Exigir tendencia confirmada para entradas SHORT */
    requireTrendForShortEntries?: boolean;
    lockNewEntriesWhileCycleActive?: boolean;
    /** Evita LONG quando downtrend forte ja esta estendido */
    avoidLongInStrongDowntrend?: boolean;
    /** Evita SHORT quando uptrend forte ja esta estendido */
    avoidShortInStrongUptrend?: boolean;
    /** ADX para classificar tendencia forte (evitar contra fluxo) */
    strongTrendAdxThreshold?: number;
    /** Distancia percentual minima da EMA para tendencia estendida */
    strongTrendEmaDistancePct?: number;
    /** RSI para gatilho de contra tendencia no LONG */
    counterRsiBuy?: number;
    /** RSI para gatilho de contra tendencia no SHORT */
    counterRsiSell?: number;
    /** RSI de compra no modo lateral (estilo spot) */
    lateralRsiBuy?: number;
    /** RSI de venda no modo lateral (estilo spot) */
    lateralRsiSell?: number;
    /** ADX maximo para classificar mercado lateral */
    lateralMaxAdx?: number;
    /** RSI para entrada LONG por reversao extrema */
    reversalRsiLong?: number;
    /** RSI para entrada SHORT por reversao extrema */
    reversalRsiShort?: number;
    /** Overlay paralelo de extremos RSI/RSL */
    rsiExtremeOverlayEnabled?: boolean;
    rsiExtremeLongEntry?: number;
    rsiExtremeLongExit?: number;
    rsiExtremeShortEntry?: number;
    rsiExtremeShortExit?: number;
    rsiExtremeAllowLong?: boolean;
    rsiExtremeAllowShort?: boolean;
    rsiExtremeBypassOperationalPause?: boolean;
    rsiExtremeOperationalPauseOverrideReasons?: string;
    rsiExtremeAllowTrendRegimeOverlay?: boolean;
    rsiExtremeSizeMultiplier?: number;
    rsiExtremeMaxOpenPerSide?: number;
    rsiExtremeOpenExecutionMode?: 'maker' | 'taker' | 'market';
    rsiExtremeCloseExecutionMode?: 'maker' | 'taker' | 'market';
    rsiExtremeMinNetCloseUsd?: number;
    rsiExtremeEntryFilterMode?: 'none' | 'ma' | 'bollinger' | 'ma_or_bollinger' | 'ma_and_bollinger';
    rsiExtremeBollingerBandOffset?: number;
    rsiExtremeMaAtrTolerance?: number;
    /** Forca fechamento final de posicoes abertas no ciclo */
    finalCloseOpenPositions?: boolean;
    /** Rastrear neutros pulados para analise de fechamento */
    trackNeutralSkippedCloses?: boolean;
    partialCloseTargets?: number[];
    partialCloseTargetsDefault?: number[];
    partialCloseFractions?: number[];
    partialCloseFractionsDefault?: number[];
    trailingAtrMultiplier?: number;
    /** Exige lucro positivo para fechamento de posicoes */
    requirePositiveClose?: boolean;
    /** Saldo base para simulacao de capital em modo DEMO. */
    demoInitialBalance?: number;
    /** Em demo, aplica os mesmos gates de risco/entrada do modo real (recomendado para paridade). */
    demoMirrorRealRiskChecksInDemo?: boolean;
    /** Cooldown em ms para risk-off local em DEMO ao detectar alerta critico. */
    demoCriticalRiskCooldownMs?: number;
    /** Intervalo minimo (ms) entre logs de diagnostico de gates de entrada. */
    decisionVerboseIntervalMs?: number;
    /** Intervalo de logs de auditoria (TP/RED), separado do log de indicadores. */
    runtimeAuditLogIntervalMs?: number;
    /** Intervalo do resumo de status/performance. */
    runtimeStatusLogIntervalMs?: number;
    /** Intervalo do log de indicadores simplificado (IND). */
    runtimeIndicatorLogIntervalMs?: number;
    managedCycleModeEnabled?: boolean;
    maxUnrealizedLossPerPositionUsd?: number | null;
    maxUnrealizedLossCapCooldownMs?: number;
    maxUnrealizedLossCapReentryCooldownMs?: number;
    strategy?: string;
    strategyMode?: string;
    /** Alias do preset institucional (open execution mode). */
    openExecutionMode?: 'maker' | 'taker' | 'market';
    /** Alias do preset institucional (close execution mode). */
    closeExecutionMode?: 'maker' | 'taker' | 'market';
    /** Modo de execucao para aberturas de hedge/ciclo. */
    hedgeOpenExecutionMode?: 'maker' | 'taker' | 'market';
    /** Modo de execucao para fechamentos de hedge/ciclo. */
    hedgeCloseExecutionMode?: 'maker' | 'taker' | 'market';
    /** Modo de execucao para stops/saidas defensivas configuraveis. */
    riskEmergencyCloseMode?: 'maker' | 'taker' | 'market';
    /** Modo de execucao para fechamento forcado global. */
    forceCloseAllMode?: 'maker' | 'taker' | 'market';
    /** Alias do preset institucional (post-only para maker). */
    makerPostOnly?: boolean;
    /** Alias do preset institucional (manter maker pendente apos timeout). */
    makerKeepPendingOnTimeout?: boolean;
    operationalRangeBalancedQuoteModeEnabled?: boolean;
    operationalMicroEnabled?: boolean;
    /** Alias do preset institucional para limiar minimo liquido por fechamento. */
    minNetUsdPerClose?: number;
    /** Limiar minimo liquido do basket/close. */
    basketMinNetUsd?: number;
    runtimeStrictMode?: boolean;
    maPeriod?: number;
    /** Distancia percentual entre niveis do grid (0.5 = 0.5%) */
    martingaleStepPercent?: number;
    martingaleMaxLevels?: number;
    /** Lista de incremento por nivel (ex.: [50,35,25] ou [0.5,0.35,0.25]) */
    martingaleFactorLevels?: number[];
    /** Percentual de net alvo por ciclo (ex.: 0.08 = 0.08%). */
    gridTakeProfitUsd?: number;
    redHedgeMultiplier?: number;
    redDilutionTargetMinNetUsd?: number;
    redCreditDilutionEnabled?: boolean;
    redCreditReserveFactor?: number;
    redCreditMinSignificantUsd?: number;
    redDynamicStepEnabled?: boolean;
    redDynamicStepAtrMultiplier?: number;
    redDynamicStepCostFloorPct?: number;
    redTimeoutEscalationEnabled?: boolean;
    redTimeoutEscalationHours?: number;
    redTimeoutEscalationMinCutPct?: number;
    redTimeoutEscalationLossBudgetPct?: number;
    redReversalConfirmEnabled?: boolean;
    redReversalConfirmCandles?: number;
    minCompensationNetUsd?: number;
    dilutionRecoveryUsd?: number;
    redDilutionNetBufferUsd?: number;
    dilutionMinProjectedNetUsd?: number;
    dilutionMaxReductionPct?: number;
    redDilutionStepPercent?: number;
    redReverseDilutionTriggerEnabled?: boolean;
    redReverseDilutionTriggerPercent?: number;
    redReverseDilutionMaxRoundsPerTick?: number;
    redTotalSettlementEnabled?: boolean;
    redTotalSettlementStaleMinutes?: number;
    redTotalSettlementMinCycleNetUsd?: number;
    redTotalSettlementMinHedgeNetUsd?: number;
    redTotalSettlementAllowForceLossClose?: boolean;
    redLateralFlowEnabled?: boolean;
    redLateralFlowStaleMinutes?: number;
    redLateralFlowBandPercent?: number;
    redLateralFlowMaxRoundsPerTick?: number;
    redLateralFlowIntensityFactor?: number;
    redLateralFlowMinProjectedNetUsd?: number;
    redLateralFlowProfitUseFactor?: number;
    redHedgeProfitReinforceUseFactor?: number;
    redSingleHedgeMode?: boolean;
    redRebaseAfterLowLevelsCleared?: boolean;
    dilutionExecutionMode?: 'fifo' | 'pro_rata';
    redRebuildHedgeUntilMg34Only?: boolean;
    redRebuildMaxPrimaryLevel?: number;
    redRebuildOnlyHedgeSource?: boolean;
    redHedgeOnlyModeEnabled?: boolean;
    redHedgeOnlyPocketStepPercent?: number;
    redHedgeOnlyPocketReductionPct?: number;
    redHedgeOnlyBreakevenPercent?: number;
    redHedgeOnlyTrailingStepPercent?: number;
    redMg2SettlementDelayHours?: number;
    redTimedStallExitEnabled?: boolean;
    redTimedStallExitHours?: number;
    redTimedStallExitMaxLossUsd?: number;
    maxDilutionRoundsPerTick?: number;
    redMaxDrawdownPct?: number;
    /** Percentual de net alvo para sair do RED (ex.: 0.08 = 0.08%). */
    redExitNetUsd?: number;
    minOrderQty?: number;
    quantityStep?: number;
    /** Valor mínimo de ordem em USDT (notional) informado pelo preset; em perp, margem mínima ≈ minNotional/leverage. */
    minNotional?: number;
}

export interface MarketKingLongPosition {
    positionId: string;
    entryPrice: number;
    quantity: number;
    borrowedAmount: number; // Valor emprestado em USDT (para LONG) ou quantidade emprestada (para SHORT)
    openTime: number; // Timestamp de abertura
    side: 'long' | 'short';
    dailyInterestRate?: number; // Taxa de juros diaria
    initialMargin?: number;
    leverage?: number;
    counterTrend?: boolean;
    _hardStopSkipCount?: number;
    _maxHoldingSkipCount?: number;
    peakPrice?: number;
    _breakEvenArmed?: boolean;
    partialTargetsHit?: number[];
    takePrice?: number;
    makerFeeRate?: number;
    takerFeeRate?: number;
    makerSlippagePercent?: number;
    takerSlippagePercent?: number;
    openFeeRate?: number;
    closeFeeRate?: number;
    openSlippagePercent?: number;
    closeSlippagePercent?: number;
    openExecutionMode?: 'maker' | 'taker' | 'market';
    closeExecutionMode?: 'maker' | 'taker' | 'market';
    fundingRatePer8h?: number;
    fundingIntervalHours?: number;
    fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
    markPrice?: number;
    liquidationPrice?: number;
    keepMarginRate?: number;
    maintenanceMarginRate?: number;
    sourceQuoteId?: string;
    qgId?: string;
    qgParentSide?: 'long' | 'short';
    qgHedge?: boolean;
    qgReason?: string;
    gridParentSide?: 'long' | 'short';
    gridIsHedge?: boolean;
    gridOpenReason?: string;
}

export interface MarketKingShortPosition {
    positionId?: string;
    price: number;
    quantity: number;
    borrowedQuantity?: number; // Quantidade emprestada para SHORT
    borrowedAmount?: number; // Valor emprestado efetivo em USDT (notional - margem inicial)
    initialMargin?: number; // Margem inicial da posicao SHORT
    leverage?: number;
    openTime?: number; // Timestamp de abertura
    dailyInterestRate?: number; // Taxa de juros diaria
    counterTrend?: boolean;
    _hardStopSkipCount?: number;
    _maxHoldingSkipCount?: number;
    peakPrice?: number;
    _breakEvenArmed?: boolean;
    partialTargetsHit?: number[];
    takePrice?: number;
    makerFeeRate?: number;
    takerFeeRate?: number;
    makerSlippagePercent?: number;
    takerSlippagePercent?: number;
    openFeeRate?: number;
    closeFeeRate?: number;
    openSlippagePercent?: number;
    closeSlippagePercent?: number;
    openExecutionMode?: 'maker' | 'taker' | 'market';
    closeExecutionMode?: 'maker' | 'taker' | 'market';
    fundingRatePer8h?: number;
    fundingIntervalHours?: number;
    fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
    markPrice?: number;
    liquidationPrice?: number;
    keepMarginRate?: number;
    maintenanceMarginRate?: number;
    sourceQuoteId?: string;
    qgId?: string;
    qgParentSide?: 'long' | 'short';
    qgHedge?: boolean;
    qgReason?: string;
    gridParentSide?: 'long' | 'short';
    gridIsHedge?: boolean;
    gridOpenReason?: string;
}

interface InstitutionalPositionExecutionMeta {
    takePrice?: number;
    makerFeeRate?: number;
    takerFeeRate?: number;
    makerSlippagePercent?: number;
    takerSlippagePercent?: number;
    openFeeRate?: number;
    closeFeeRate?: number;
    openSlippagePercent?: number;
    closeSlippagePercent?: number;
    openExecutionMode?: 'maker' | 'taker' | 'market';
    closeExecutionMode?: 'maker' | 'taker' | 'market';
    dailyInterestRate?: number;
    fundingRatePer8h?: number;
    fundingIntervalHours?: number;
    fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
    sourceQuoteId?: string;
}

interface InstitutionalPendingCloseOrder {
    orderId: string;
    positionId: string;
    side: 'long' | 'short';
    action: 'buy' | 'sell';
    price: number;
    quantity: number;
    reason?: string;
}

interface InstitutionalPendingOpenOrder extends InstitutionalPositionExecutionMeta {
    orderId: string;
    side: 'long' | 'short';
    action: 'buy' | 'sell';
    price: number;
    quantity: number;
    reason?: string;
    parentSide?: 'long' | 'short';
    isHedge?: boolean;
    counted?: boolean;
    createdAt?: number;
}

interface MarginOperationRecordMetadata {
    operationPhase?: 'open' | 'close';
    operationReason?: string | null;
    executionMode?: 'maker' | 'taker' | 'market' | null;
    positionId?: string | null;
    operationContext?: Record<string, unknown> | null;
}

interface GridSideState {
    active: boolean;
    martingaleCount: number;
    lastGridPrice: number;
    lastQuantity: number;
    cycleStartBalance: number;
    cycleStartTime: number;
    redActive: boolean;
    redActivatedTime: number;
    redInitialDrawdown: number;
    redWorstDrawdown: number;
    redRecoveryConsumed: number;
    redLastDrawdown: number;
    redHedgeSide: 'long' | 'short' | null;
    redLastReductionTime?: number;
    redDilutionRefPrice?: number;
    redHedgeBaseQty?: number;
    redDilutionCreditUsd?: number;
    redHedgeProfitBufferUsd?: number;
    redLateralReinforceCount?: number;
    redLateralReinforceQty?: number;
    redLateralReinforceBudgetUsd?: number;
    redRebuildPhaseActive?: boolean;
    redRebuildCount?: number;
    redTotalSettlementLastSkipTs?: number;
    redTotalSettlementLastSkipReason?: string;
    redMg2CloseAnchorTs?: number;
    redReversalConfirmCount?: number;
    redHedgeOnlyRefPrice?: number;
    redHedgeOnlyPocketSteps?: number;
    redHedgeOnlyBreakevenArmed?: boolean;
    redHedgeOnlyPeakPrice?: number;
    redProfitReinforceCount?: number;
    redProfitReinforceQty?: number;
    redProfitReinforceBudgetUsd?: number;
    // ATR-based dilution bookkeeping
    atrLastReferencePrice?: number;
    atrLastDilutionTime?: number;
    // Manual dilution bookkeeping
    pendingReductionRemainder?: number;
    redBalanceSnapshot?: number;
    // Flag indicating TPs were removed for this RED cycle
    redRemoveTP?: boolean;
}

interface GridState {
    long: GridSideState;
    short: GridSideState;
    globalReserveUsd?: number;
}

export interface MarketKingTradingState {
    lastEMA: number | null;
    buyPrices: number[];
    /** Pontos de saida em memoria para fechamento de LONG; nao representam short_positions persistidas. */
    sellPrices?: number[];
    totalProfit: number;
    lastBuyPrice: number;
    lastSellPrice: number;
    forceStopAction: boolean;
    /** Quando true, permite venda (fechar long) com lucro negativo (stop loss). */
    forceStopLossSell?: boolean;
    activePositions: MarketKingLongPosition[];
    borrowedAmount: number; // Total emprestado (soma de todas as posicoes)
    marginLevel: number;
    /** Menor margin level observado (risco de liquidacao; limiares do preset). */
    minMarginLevel?: number;
    /** Limiar de margin level para liquidacao usado neste ciclo (persistido para consistencia) */
    liquidationMarginLevelThresholdUsed?: number;
    shortPositions?: MarketKingShortPosition[]; // Para vendido (short)
    /** Override do valor (USDT) da proxima ordem de abertura */
    _nextOrderAmountUsdt?: number;
    /** Marca entrada contra tendencia para observabilidade */
    _nextOrderCounterTrend?: boolean;
    _nextCloseQuantity?: number;
    _nextCloseReason?: string;
    _nextCloseIgnorePositiveCheck?: boolean;
    _nextCloseExecutionModeOverride?: 'maker' | 'taker' | 'market';
    /** Marca fechamento forcado do ciclo antes do reset dos contadores */
    _cyclePendingReset?: boolean;
    /** Sinaliza ao worker/state manager que ocorreu reset de ciclo */
    _cycleReset?: boolean;
    skippedClosesCount?: number;
    skippedClosesByReason?: Record<string, number>;
    neutralSkippedClosesCount?: number;
    neutralSkippedClosesByReason?: Record<string, number>;
    defensiveLossCloseCount?: number;
    forcedCloseReasonCounts?: Record<string, number>;
    lastMaxUnrealizedLossCapCloseTs?: number;
    lastMaxUnrealizedLossCapCloseSide?: 'long' | 'short';
    _lastEntryGateLogAt?: number;
    _lastMarginGateLogAt?: number;
    _lastGridQtyZeroLogAt?: number;
    _lastGridMarginGateLogAt?: number;
    _lastMgLimitLogAt?: number;
    _lastPositionSyncGuardLogAt?: number;
    _lastGridTpAuditLongLogAt?: number;
    _lastGridTpAuditShortLogAt?: number;
    _lastGridRedAuditLongLogAt?: number;
    _lastGridRedAuditShortLogAt?: number;
    _lastGridStatusLogAt?: number;
    _lastGridIndicatorLogAt?: number;
    /** Cooldown: timestamp da ultima ordem de FECHAMENTO aceita mas pendente (evita sequencia de 40+ tentativas) */
    _lastPendingCloseSentAt?: number;
    _lastPendingCloseSide?: 'long' | 'short';
    /** Preenchido quando ordem de fechamento maker fica no book: usado para persistir 1x success quando sync detectar posição fechada (evita várias ordens mock/pending). */
    _lastPendingCloseOrderId?: string;
    _lastPendingClosePrice?: number;
    _lastPendingCloseQty?: number;
    _lastPendingCloseAction?: 'sell' | 'buy';
    _lastPendingCloseReason?: string;
    _lastPendingCloseExecutionMode?: 'maker' | 'taker' | 'market';
    _lastPendingClosePositionId?: string;
    _lastPendingCloseSourceQuoteId?: string;
    _lastPendingCloseCycleId?: string;
    _lastPendingCloseCycleNetApproved?: boolean;
    /** Ordem de abertura (open) aceita mas ainda no book: persistir success quando corretora confirmar fill (upsert por order_id). */
    _lastPendingOpenOrderId?: string;
    _lastPendingOpenPrice?: number;
    _lastPendingOpenQty?: number;
    _lastPendingOpenAction?: 'sell' | 'buy';
    _lastPendingOpenCounted?: boolean;
    _lastPendingOpenReason?: string;
    _lastPendingOpenExecutionMode?: 'maker' | 'taker' | 'market';
    _lastPendingOpenPositionId?: string;
    _lastPendingOpenSourceQuoteId?: string;
    _lastDemoRiskOffAt?: number;
    _forceOpenSide?: 'long' | 'short';
    _nextOrderForceExecutionMode?: 'maker' | 'taker' | 'market';
    _nextOrderPositionIdOverride?: string;
    _nextOrderInstitutionalMeta?: InstitutionalPositionExecutionMeta;
    _nextOrderParentSide?: 'long' | 'short';
    _nextOrderIsHedge?: boolean;
    _nextOrderReason?: string;
    _institutionalHybridState?: Record<string, unknown>;
    _institutionalPendingOpenOrders?: InstitutionalPendingOpenOrder[];
    _institutionalPendingCloseOrders?: InstitutionalPendingCloseOrder[];
    gridState?: GridState;
}

export interface MarketKingShutdownContext {
    balances?: any;
    symbolInfo?: any;
    tickerInfo?: any;
    marginAccountInfo?: any;
    interestRate?: number | null;
    /**
    * Permite refresh do snapshot de conta apos cancel/fechamento para validar passivos residuais.
    */
    refreshUserConfig?: () => Promise<any>;
}

export interface MarketKingShutdownResult {
    canceledOrders: number;
    closedPositions: boolean;
    residualLiability: number;
    repaidAssets: number;
}

export interface InstitutionalRuntimeExecutionPolicy {
    engineExecutionMode: 'real' | 'demo';
    allowPositionMutations: boolean;
    submitRealOrders: boolean;
    syncRealQuotes: boolean;
    guardActive: boolean;
}

/**
 * Keeps strategy semantics separate from order authorization. A REAL iteration must
 * never become a DEMO iteration merely because the exchange snapshot is uncertain.
 */
export function resolveInstitutionalRuntimeExecutionPolicy(params: {
    isReal: boolean;
    hasUncertainRealExposure: boolean;
}): InstitutionalRuntimeExecutionPolicy {
    const isReal = params?.isReal === true;
    const guardActive = isReal && params?.hasUncertainRealExposure === true;
    return {
        engineExecutionMode: isReal ? 'real' : 'demo',
        allowPositionMutations: !guardActive,
        submitRealOrders: isReal && !guardActive,
        syncRealQuotes: isReal && !guardActive,
        guardActive,
    };
}

/**
 * The institutional engine returns the complete margin snapshot for positions
 * that already existed before the iteration. Only propagate rows that actually
 * changed; otherwise every market poll rewrites all positions and emits a false
 * `critical_margin_injection` diagnostic even when the feature is disabled.
 */
export function filterChangedInstitutionalMarginUpdates(
    state: any,
    updates: InstitutionalPositionMarginUpdate[],
    epsilon = 1e-8
): InstitutionalPositionMarginUpdate[] {
    const tolerance = Number.isFinite(Number(epsilon)) && Number(epsilon) >= 0
        ? Number(epsilon)
        : 1e-8;
    const nearlyEqual = (leftValue: unknown, rightValue: unknown): boolean => {
        const left = Number(leftValue);
        const right = Number(rightValue);
        if (!Number.isFinite(left) || !Number.isFinite(right)) return false;
        return Math.abs(left - right) <= tolerance * Math.max(1, Math.abs(left), Math.abs(right));
    };

    return (Array.isArray(updates) ? updates : []).filter((update) => {
        const positions = update.side === 'short'
            ? (Array.isArray(state?.shortPositions) ? state.shortPositions : [])
            : (Array.isArray(state?.activePositions) ? state.activePositions : []);
        const position = positions.find(
            (row: any) => String(row?.positionId ?? '') === String(update.positionId ?? '')
        );
        if (!position) return false;

        const numericChanged = !nearlyEqual(position.initialMargin, update.initialMargin)
            || !nearlyEqual(position.borrowedAmount, update.borrowedAmount)
            || !nearlyEqual(position.leverage, update.leverage);
        const injectionCountChanged = update.criticalMarginInjectionCount != null
            && Number(position.criticalMarginInjectionCount ?? 0) !== Number(update.criticalMarginInjectionCount);
        return numericChanged || injectionCountChanged;
    });
}

export class MarketKingTradingService {
    private botName: string;
    private maxOperations: number;
    private calculations: MarketKingCalculations;
    private riskManager: MarketKingRiskManager;
    private accountMetrics: MarketKingAccountMetrics | null = null;
    private positionRisks: PositionRisk[] = [];
    private logger: any;
    private managerDB: OperationManagerDBOptimistic | null = null;
    private isDemo: boolean = false;
    private batchOps: BatchOperations | null = null;
    private managedFuturesRuntimeSettingsAppliedKey: string | null = null;
    private safetyGates: MarketKingSafetyGates;
    private brokerOperations: MarketKingBrokerOperations;
    private marginOperations: MarketKingOperations;
    private managedFuturesRuntimeSettingsLastFailureKey: string | null = null;
    private managedFuturesRuntimeSettingsLastFailureAt: number = 0;
    private pineScriptService: PineScriptService | null = null; // ServiÃ§o de geraÃ§Ã£o de Pine Script
    /** Timestamp da ultima ordem REAL enviada (para delay entre compra/venda consecutivas). */
    private lastOrderSentAt: number = 0;
    constructor(
        botName: string,
        maxOperations: number,
        botId?: number,
        dbConfig?: any,
        _signalClient?: ExchangeSignalClient,
        batchOps?: BatchOperations
    ) {
        this.botName = botName;
        this.maxOperations = maxOperations;
        this.calculations = new MarketKingCalculations();
        // Risk manager deve compartilhar as mesmas taxas/métodos de cálculo (evita divergência após updateMarginRates via API).
        this.riskManager = new MarketKingRiskManager({ calculations: this.calculations });
        this.logger = createLogger(botName);
        this.batchOps = batchOps || null;
        this.safetyGates = new MarketKingSafetyGates({
            managerDB: () => this.managerDB,
            logger: this.logger,
            getMode,
        });
        this.brokerOperations = new MarketKingBrokerOperations({ logger: this.logger });
        this.marginOperations = new MarketKingOperations({
            logger: this.logger,
            brokerOperations: this.brokerOperations,
            isFuturePerpetualTrade: (config) => this.isFuturePerpetualTrade(config),
            resolveManagedFuturesProductType: (config) => this.resolveManagedFuturesProductType(config),
            resolveManagedFuturesMarginMode: (config) => this.resolveManagedFuturesMarginMode(config),
            sleep: (ms) => this.sleep(ms),
            getLastOrderSentAt: () => this.lastOrderSentAt,
            setLastOrderSentAt: (value) => {
                this.lastOrderSentAt = value;
            },
            upsertInstitutionalPendingOpenOrder: (state, payload) => this.upsertInstitutionalPendingOpenOrder(state, payload),
            removeInstitutionalPendingOpenOrder: (state, orderId) => this.removeInstitutionalPendingOpenOrder(state, orderId),
            upsertInstitutionalPendingCloseOrder: (state, payload) => this.upsertInstitutionalPendingCloseOrder(state, payload),
            removeInstitutionalPendingCloseOrder: (state, orderId) => this.removeInstitutionalPendingCloseOrder(state, orderId),
            removeInstitutionalPendingCloseOrdersByPositionId: (state, positionId) =>
                this.removeInstitutionalPendingCloseOrdersByPositionId(state, positionId),
            findRuntimePositionById: (state, positionId) => this.findRuntimePositionById(state, positionId),
            isInstitutionalHybridStrategy: (config) => this.isInstitutionalHybridStrategy(config),
            resolveMinNetFloor: (config) => this.resolveMinNetFloor(config),
            resolveRuntimeCloseMinNetForReason: (config, reason, forceLoss, closeNotional) =>
                this.resolveRuntimeCloseMinNetForReason(config, reason, forceLoss, closeNotional),
            estimateRuntimeCloseNet: (config, symbolInfo, position, side, price, quantity, dailyInterestRate, executionMode) =>
                this.estimateRuntimeCloseNet(config, symbolInfo, position, side, price, quantity, dailyInterestRate, executionMode),
            cancelMarginOrderBestEffort: (client, config, orderId) => this.cancelMarginOrderBestEffort(client, config, orderId),
            decrementBuySafe: () => this.decrementBuySafe(),
            decrementSellSafe: () => this.decrementSellSafe(),
            saveOperation: (...args: any[]) => (this.saveOperation as any)(...args),
            getAvailableQuoteBalance: (balances, cfg) => this.getAvailableBalanceByCoin(balances, cfg.pair2),
        });

        if (botId && dbConfig) {
            this.managerDB = new OperationManagerDBOptimistic(botId, botName, maxOperations, dbConfig);
            this.managerDB.initialize().catch(err => {
                this.logger.error(`Erro ao inicializar OperationManagerDBOptimistic: ${err.message}`);
            });
        }
    }

    /**
     * Inicializa o serviÃ§o de Pine Script se necessÃ¡rio
     */
    private initializePineScriptService(config: MarketKingTradingConfig, symbol: string, granularity: string): void {
        if (this.pineScriptService) return; // JÃ¡ inicializado

        try {
            const userId = config.userId || config.botId || 0; // Usa userId se disponÃ­vel, senÃ£o botId como fallback
            const botId = config.botId || 0;

            if (userId > 0 && botId > 0) {
                const pineConfig: PineScriptServiceConfig = {
                    userId,
                    botId,
                    symbol,
                    granularity: granularity || config.granularity || '1m',
                    autoSaveIntervalMs: 60000 // Salvar a cada 1 minuto
                };
                this.pineScriptService = new PineScriptService(pineConfig);
                this.logger.info(`[PINE] PineScriptService inicializado para Bot ${botId}, User ${userId}`);
            }
        } catch (err: any) {
            this.logger.warn(`[PINE] Falha ao inicializar PineScriptService: ${err?.message || err}`);
        }
    }

    /**
     * Registra transaÃ§Ãµes no Pine Script Service
     */
    private async recordPineSignals(
        state: MarketKingTradingState,
        currentPrice: number,
        currentTime: number
    ): Promise<void> {
        if (!this.pineScriptService) return;

        try {
            const refresh = (this.pineScriptService as any).refreshPineFromAccumulatedOrState;
            if (typeof refresh !== 'function') return;
            // Em tempo real: atualizar o .pine a partir do CSV acumulado (concatenação por operação).
            // Se não houver CSV acumulado ainda, faz snapshot do state e salva.
            await refresh.call(this.pineScriptService, state, currentPrice, currentTime, 30);
        } catch (err: any) {
            this.logger.warn(`[PINE] Erro ao registrar sinais Pine: ${err?.message || err}`);
        }
    }

    /** Helpers de operações atômicas (contadores DB) – delegados a MarketKingSafetyGates. */
    private async canBuySafe(): Promise<boolean> {
        return this.safetyGates.canBuySafe();
    }

    private async canSellSafe(): Promise<boolean> {
        return this.safetyGates.canSellSafe();
    }

    private async incrementBuySafe(): Promise<boolean> {
        return this.safetyGates.incrementBuySafe();
    }

    private async incrementSellSafe(): Promise<boolean> {
        return this.safetyGates.incrementSellSafe();
    }

    private async incrementBuyTrackOnlySafe(): Promise<boolean> {
        return this.safetyGates.incrementBuyTrackOnlySafe();
    }

    private async incrementSellTrackOnlySafe(): Promise<boolean> {
        return this.safetyGates.incrementSellTrackOnlySafe();
    }

    private async decrementBuySafe(): Promise<void> {
        return this.safetyGates.decrementBuySafe();
    }

    private async decrementSellSafe(): Promise<void> {
        return this.safetyGates.decrementSellSafe();
    }

    private getPrimaryOperationCounts(state: MarketKingTradingState): { buyCount: number; sellCount: number } {
        const buyCount = (Array.isArray(state.activePositions) ? state.activePositions : [])
            .filter((position: any) => !this.isGridHedgePosition(position))
            .length;
        const sellCount = (Array.isArray(state.shortPositions) ? state.shortPositions : [])
            .filter((position: any) => !this.isGridHedgePosition(position))
            .length;
        return { buyCount, sellCount };
    }

    private async resetCountsSafe(): Promise<void> {
        return this.safetyGates.resetCountsSafe();
    }

    private isCloseValue(a: number, b: number, tolerance: number): boolean {
        if (!Number.isFinite(a) || !Number.isFinite(b)) return false;
        const tol = Math.max(Math.abs(tolerance || 0), 1e-8);
        const base = Math.max(Math.abs(a), Math.abs(b), 1);
        return Math.abs(a - b) <= base * tol;
    }

    private findLongRuntimeMatch(
        currentPositions: MarketKingLongPosition[],
        apiPosition: MarketKingLongPosition,
        usedIndexes: Set<number>,
        tolerance: number
    ): MarketKingLongPosition | null {
        if (currentPositions.length === 0) return null;

        if (apiPosition.positionId) {
            for (let i = 0; i < currentPositions.length; i++) {
                if (usedIndexes.has(i)) continue;
                if (currentPositions[i].positionId === apiPosition.positionId) {
                    usedIndexes.add(i);
                    return currentPositions[i];
                }
            }
        }

        const apiOpenTime = Number(apiPosition.openTime || 0);
        for (let i = 0; i < currentPositions.length; i++) {
            if (usedIndexes.has(i)) continue;
            const current = currentPositions[i];
            const currentOpenTime = Number(current.openTime || 0);
            const closeTime = apiOpenTime > 0 && currentOpenTime > 0 && Math.abs(apiOpenTime - currentOpenTime) <= (2 * 60 * 1000);
            if (closeTime && this.isCloseValue(current.entryPrice, apiPosition.entryPrice, tolerance)) {
                usedIndexes.add(i);
                return current;
            }
        }

        for (let i = 0; i < currentPositions.length; i++) {
            if (usedIndexes.has(i)) continue;
            const current = currentPositions[i];
            if (this.isCloseValue(current.entryPrice, apiPosition.entryPrice, tolerance)
                && this.isCloseValue(current.quantity, apiPosition.quantity, tolerance)) {
                usedIndexes.add(i);
                return current;
            }
        }

        return null;
    }

    private findShortRuntimeMatch(
        currentPositions: MarketKingShortPosition[],
        apiPosition: MarketKingShortPosition,
        usedIndexes: Set<number>,
        tolerance: number
    ): MarketKingShortPosition | null {
        if (currentPositions.length === 0) return null;

        if (apiPosition.positionId) {
            for (let i = 0; i < currentPositions.length; i++) {
                if (usedIndexes.has(i)) continue;
                if (currentPositions[i].positionId === apiPosition.positionId) {
                    usedIndexes.add(i);
                    return currentPositions[i];
                }
            }
        }

        const apiOpenTime = Number(apiPosition.openTime || 0);
        for (let i = 0; i < currentPositions.length; i++) {
            if (usedIndexes.has(i)) continue;
            const current = currentPositions[i];
            const currentOpenTime = Number(current.openTime || 0);
            const closeTime = apiOpenTime > 0 && currentOpenTime > 0 && Math.abs(apiOpenTime - currentOpenTime) <= (2 * 60 * 1000);
            if (closeTime && this.isCloseValue(Number(current.price || 0), Number(apiPosition.price || 0), tolerance)) {
                usedIndexes.add(i);
                return current;
            }
        }

        for (let i = 0; i < currentPositions.length; i++) {
            if (usedIndexes.has(i)) continue;
            const current = currentPositions[i];
            if (this.isCloseValue(Number(current.price || 0), Number(apiPosition.price || 0), tolerance)
                && this.isCloseValue(Number(current.quantity || 0), Number(apiPosition.quantity || 0), tolerance)) {
                usedIndexes.add(i);
                return current;
            }
        }

        return null;
    }

    private findInstitutionalPendingOpenMatch(
        state: MarketKingTradingState,
        side: 'long' | 'short',
        apiPosition: { price?: number; entryPrice?: number; quantity?: number; openTime?: number },
        usedOrderIds: Set<string>,
        tolerance: number
    ): InstitutionalPendingOpenOrder | null {
        const pendingOrders = Array.isArray(state._institutionalPendingOpenOrders)
            ? state._institutionalPendingOpenOrders
            : [];
        if (pendingOrders.length === 0) return null;

        const apiPrice = side === 'long'
            ? Number(apiPosition.entryPrice || 0)
            : Number(apiPosition.price || 0);
        const apiQty = Number(apiPosition.quantity || 0);
        const apiOpenTime = Number(apiPosition.openTime || 0);

        let bestMatch: InstitutionalPendingOpenOrder | null = null;
        let bestScore = Number.POSITIVE_INFINITY;

        for (const pending of pendingOrders) {
            if (!pending || pending.side !== side) continue;
            const orderId = String(pending.orderId || '');
            if (!orderId || usedOrderIds.has(orderId)) continue;
            if (!this.isCloseValue(Number(pending.price || 0), apiPrice, tolerance)) continue;
            if (!this.isCloseValue(Number(pending.quantity || 0), apiQty, tolerance)) continue;

            const createdAt = Number(pending.createdAt || 0);
            const score =
                Math.abs(Number(pending.price || 0) - apiPrice) +
                Math.abs(Number(pending.quantity || 0) - apiQty) +
                (apiOpenTime > 0 && createdAt > 0 ? (Math.abs(apiOpenTime - createdAt) / 1000) : 0);
            if (score < bestScore) {
                bestScore = score;
                bestMatch = pending;
            }
        }

        if (bestMatch?.orderId) {
            usedOrderIds.add(String(bestMatch.orderId));
        }
        return bestMatch;
    }
    /** Define modo Demo/Real a partir do config. */
    private validateAndSetDemoMode(config: MarketKingTradingConfig, state: MarketKingTradingState): void {
        // Centraliza inicializacao do modo operacional (DEMO/REAL)
        initMode(this, config, state);
        // Producao: sempre refletir opType atual em toda iteracao (evita cache de DEMO apos troca para REAL)
        const op = String(config?.opType ?? 'demo').toLowerCase().trim();
        this.isDemo = (op !== 'real');
    }

    private enforceFutureRuntimeDefaults(config: MarketKingTradingConfig): void {
        // Runtime de perp/future por definicao: forca venue, margin mode e sizing.
        (config as any).executionTradeType = 'future';
        config.marginMode = 'isolated';
        (config as any).amountSizingMode = 'margin_base';
    }

    private isInstitutionalHybridStrategy(_config: MarketKingTradingConfig): boolean {
        return true; // Runtime canônico do MarketKingTrade: motor institucional sempre ativo.
    }

    private isEmergencyForceCloseReason(reason: string | undefined): boolean {
        return this.safetyGates.isEmergencyForceCloseReason(reason);
    }

    private resolveMinNetCloseUsd(config: MarketKingTradingConfig, notionalUsd?: number): number {
        return this.safetyGates.resolveMinNetCloseUsd(config, notionalUsd);
    }

    private resolveMinNetFloor(config: MarketKingTradingConfig): number {
        return this.safetyGates.resolveMinNetFloor(config);
    }

    private shouldSkipFallbackCloseBecauseNegativeAndTooEarly(
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        positionId: string,
        price: number,
        qty: number,
        reason: string,
        forceLoss: boolean,
        symbolInfo: any,
        dailyInterestRate: number
    ): boolean {
        const normalizedReason = String(reason || '').trim().toLowerCase();
        if (normalizedReason.includes('managed_cycle_basket_positive') || normalizedReason.includes('managed_cycle_reserve_')) return false;
        const runtime = this.findRuntimePositionById(state, positionId);
        if (!runtime) return false;
        const { position, side } = runtime;
        const openTime = Number((position as any).openTime ?? 0);
        const nowTs = Date.now();
        const runtimeNet = this.estimateRuntimeCloseNet(
            config,
            symbolInfo,
            position,
            side,
            price,
            qty,
            dailyInterestRate,
            'market'
        );
        const minNetFloor = this.resolveMinNetFloor(config);
        const minHoldMin = Math.max(0, Number(config.minHoldMinutesBeforeNegativeClose ?? 0));
        return this.safetyGates.shouldSkipNegativeCloseByHoldTime(
            minHoldMin,
            openTime,
            nowTs,
            runtimeNet,
            minNetFloor,
            forceLoss,
            this.isEmergencyForceCloseReason(reason)
        );
    }

    private alignRuntimeConfigWithPreset(config: MarketKingTradingConfig): void {
        // Estratégia principal: usar strategyMode quando strategy não vier preenchida.
        if (!String(config.strategy || '').trim() && String(config.strategyMode || '').trim()) {
            config.strategy = String(config.strategyMode).trim();
        }

        // Aliases do preset para chaves de runtime (open/close mode, post-only, slippage).
        if (!config.runtimeOpenExecutionMode && config.openExecutionMode) {
            config.runtimeOpenExecutionMode = config.openExecutionMode;
        }
        if (!config.runtimeCloseExecutionMode && config.closeExecutionMode) {
            config.runtimeCloseExecutionMode = config.closeExecutionMode;
        }
        if (config.runtimeMakerPostOnly === undefined && config.makerPostOnly !== undefined) {
            config.runtimeMakerPostOnly = !!config.makerPostOnly;
        }
        if (config.runtimeMakerKeepPendingOnTimeout === undefined && config.makerKeepPendingOnTimeout !== undefined) {
            config.runtimeMakerKeepPendingOnTimeout = !!config.makerKeepPendingOnTimeout;
        }

        const makerSlipAlias = Number((config as any).makerSlippagePercent);
        if (config.runtimeMakerSlippagePercent === undefined && Number.isFinite(makerSlipAlias)) {
            config.runtimeMakerSlippagePercent = Math.max(0, makerSlipAlias);
        }
        const takerSlipAlias = Number((config as any).takerSlippagePercent);
        if (config.runtimeTakerSlippagePercent === undefined && Number.isFinite(takerSlipAlias)) {
            config.runtimeTakerSlippagePercent = Math.max(0, takerSlipAlias);
        }

        if (!Number.isFinite(Number(config.minNetCloseUsd))) {
            config.minNetCloseUsd = this.resolveMinNetCloseUsd(config);
        }

        // No modo institucional: maker/maker por padrão e validação por net projetado.
        if (this.isInstitutionalHybridStrategy(config)) {
            if (!Number.isFinite(Number(config.minCloseProfitUsd))) {
                config.minCloseProfitUsd = this.resolveMinNetCloseUsd(config);
            }
            if (!config.runtimeOpenExecutionMode && !config.openExecutionMode) {
                config.runtimeOpenExecutionMode = 'maker';
            }
            if (!config.runtimeCloseExecutionMode && !config.closeExecutionMode) {
                config.runtimeCloseExecutionMode = 'maker';
            }
            if (config.requirePositiveClose === undefined) {
                config.requirePositiveClose = false;
            }
            if (config.runtimeStrictMode === undefined) {
                config.runtimeStrictMode = true;
            }
        }
    }

    private isFuturePerpetualTrade(configOrTradeType?: MarketKingTradingConfig | string | null): boolean {
        const tradeType = typeof configOrTradeType === 'string'
            ? configOrTradeType
            : ((configOrTradeType as any)?.executionTradeType || (configOrTradeType as any)?.tradeType);
        const normalized = String(tradeType || '').trim().toLowerCase();
        if (normalized === LEGACY_GRID_PROFILE_PREFIX || normalized.includes(LEGACY_GRID_PROFILE_PREFIX)) return true;
        return normalized === 'future'
            || normalized === 'futures'
            || normalized === 'mix'
            || normalized === 'perp'
            || normalized === 'perpetual'
            || normalized.includes('future')
            || normalized.includes('perp');
    }

    private resolveEffectiveCarryRate(config: MarketKingTradingConfig, rawRate?: number | null): number {
        // Futures perp nao usa juros de emprestimo de margin; funding e tratado separadamente no net.
        if (this.isFuturePerpetualTrade(config)) return 0;
        const parsedRaw = Number(rawRate);
        if (Number.isFinite(parsedRaw) && parsedRaw >= 0) return parsedRaw;
        const fallback = Number(config.defaultDailyInterestRate ?? 0.0001);
        return Number.isFinite(fallback) && fallback >= 0 ? fallback : 0.0001;
    }

    private resolveFundingFeeMode(config: MarketKingTradingConfig): 'none' | 'signed' | 'absolute_cost' {
        if (!this.isFuturePerpetualTrade(config)) return 'none';
        const raw = String(config.fundingFeeMode ?? '').trim().toLowerCase();
        if (raw === 'none' || raw === 'off' || raw === 'disabled') return 'none';
        if (raw === 'absolute_cost' || raw === 'absolute' || raw === 'conservative') return 'absolute_cost';
        if (raw === 'signed' || raw === 'realistic') return 'signed';
        return 'signed';
    }

    private resolveFundingIntervalHours(config: MarketKingTradingConfig, symbolInfo?: any): number {
        const candidates = [
            symbolInfo?.fundingIntervalHours,
            symbolInfo?.fundIntervalHours,
            symbolInfo?.fundingIntervalHour,
            symbolInfo?.fundingInterval,
            config.fundingIntervalHours,
            8
        ];
        for (const candidate of candidates) {
            const parsed = Number(candidate);
            if (Number.isFinite(parsed) && parsed > 0) return parsed;
        }
        return 8;
    }

    private resolveFundingRatePer8h(config: MarketKingTradingConfig, symbolInfo?: any): number {
        if (!this.isFuturePerpetualTrade(config)) return 0;
        const candidates = [
            symbolInfo?.fundingRatePer8h,
            symbolInfo?.fundingRate,
            symbolInfo?.capitalRate,
            symbolInfo?.fundRate,
            config.fundingRatePer8h,
            config.defaultFundingRatePer8h,
            0
        ];
        for (const candidate of candidates) {
            const parsed = Number(candidate);
            if (Number.isFinite(parsed)) return parsed;
        }
        return 0;
    }

    private estimateFundingCost(
        config: MarketKingTradingConfig,
        side: 'long' | 'short',
        pos: MarketKingLongPosition | MarketKingShortPosition,
        currentPrice: number,
        closeQty: number,
        symbolInfo?: any,
        asOfTs?: number
    ): number {
        const mode = this.resolveFundingFeeMode(config);
        return estimatePerpFundingCost({
            isPerp: this.isFuturePerpetualTrade(config),
            fundingFeeMode: mode,
            side,
            entryPrice: Math.max(0, Number(this.getEntryPrice(side, pos))),
            markPrice: Math.max(0, Number(currentPrice || 0)),
            closeQty,
            openTime: Number(this.getOpenTime(pos) || 0),
            fundingRatePer8h: this.resolveFundingRatePer8h(config, symbolInfo),
            fundingIntervalHours: this.resolveFundingIntervalHours(config, symbolInfo),
            asOfTs: resolveIterationTimestamp(config, symbolInfo, undefined, asOfTs ?? Date.now()),
        });
    }

    private resolveManagedFuturesProductType(config: MarketKingTradingConfig): 'USDT-FUTURES' | 'USDC-FUTURES' | 'COIN-FUTURES' {
        return resolveManagedFuturesProductType(config);
    }

    private resolveManagedFuturesMarginMode(config: MarketKingTradingConfig): 'isolated' | 'crossed' {
        return resolveManagedFuturesMarginMode(config);
    }

    private resolveAmountSizingMode(): 'notional' | 'margin_base' {
        // Future/perp: amount e sempre margem base.
        return 'margin_base';
    }

    /**
     * Margem mínima por ordem (USDT) a partir de regras da corretora: minNotional e/ou minQty×preço.
     */
    private resolveExchangeMinMarginPerOrderUsd(symbolInfo: any, price: number, leverage: number): number {
        const lev = Math.max(1, Number(leverage || 1));
        const p = Number(price);
        const configuredMinNotional = Math.max(0, Number(
            symbolInfo?.minNotionalUsd
            ?? symbolInfo?.minNotional
            ?? symbolInfo?.min_notional
            ?? symbolInfo?.minTradeUSDT
            ?? symbolInfo?.costMin
            ?? symbolInfo?.openCostMin
            ?? 0
        ));
        const minQty = Math.max(0, Number(
            symbolInfo?.minOrderQty ?? symbolInfo?.minQty ?? symbolInfo?.minTradeNum ?? 0
        ));
        const minNotional = Math.max(
            configuredMinNotional,
            minQty > 0 && Number.isFinite(p) && p > 0 ? minQty * p : 0
        );
        if (minNotional <= 0) return 0;
        return minNotional / lev;
    }

    /**
     * Piso de margem por operação derivado do preset (minNotional em USDT = valor mínimo da ordem).
     */
    private resolvePresetMinMarginPerOrderUsd(config: MarketKingTradingConfig): number {
        const mn = Math.max(0, Number(config.minNotional ?? 0));
        if (mn <= 0) return 0;
        const lev = Math.max(1, Number(config.leverage || 1));
        return this.resolveAmountSizingMode() === 'margin_base' ? mn / lev : mn;
    }

    private resolveConfiguredOpenNotionalUsdt(
        config: MarketKingTradingConfig,
        requestedAmountUsdt: number,
        explicitAmountOverride: boolean = false
    ): number {
        const safeRequested = Math.max(0, Number(requestedAmountUsdt || 0));
        if (safeRequested <= 0) return 0;
        if (explicitAmountOverride) return safeRequested;
        if (this.resolveAmountSizingMode() !== 'margin_base') return safeRequested;
        const leverage = Math.max(1, Number(config.leverage || 1));
        return safeRequested * leverage;
    }

    private quantityFromConfiguredOpenAmount(
        config: MarketKingTradingConfig,
        requestedAmountUsdt: number,
        price: number,
        explicitAmountOverride: boolean = false
    ): number {
        const notionalUsdt = this.resolveConfiguredOpenNotionalUsdt(
            config,
            requestedAmountUsdt,
            explicitAmountOverride
        );
        return this.quantityFromUsdtAmount(notionalUsdt, price);
    }

    private normalizeExecutionMode(rawMode: any, fallback: 'maker' | 'taker' | 'market'): 'maker' | 'taker' | 'market' {
        const normalized = String(rawMode || '').trim().toLowerCase();
        if (normalized === 'maker' || normalized === 'post_only' || normalized === 'postonly') return 'maker';
        if (normalized === 'market') return 'market';
        if (normalized === 'taker' || normalized === 'ioc' || normalized === 'aggressive') return 'taker';
        return fallback;
    }

    private buildInstitutionalOpenMetaDedupKey(plan: {
        side?: 'long' | 'short';
        parentSide?: 'long' | 'short';
        isHedge?: boolean;
        quantity?: number;
        takePrice?: number;
        executionMode?: 'maker' | 'taker' | 'market';
    }): string | null {
        const executionMode = this.normalizeExecutionMode(plan?.executionMode, 'maker');
        if (executionMode === 'maker') return null;

        const quantity = Number(plan?.quantity ?? 0);
        const takePrice = Number(plan?.takePrice ?? 0);
        return [
            plan?.side === 'short' ? 'short' : 'long',
            plan?.parentSide === 'short' ? 'short' : 'long',
            plan?.isHedge ? '1' : '0',
            Number.isFinite(quantity) ? quantity.toFixed(8) : '',
            Number.isFinite(takePrice) ? takePrice.toFixed(6) : '',
            executionMode,
        ].join('|');
    }

    private resolveOpenExecutionMode(config: MarketKingTradingConfig): 'maker' | 'taker' | 'market' {
        const fallback: 'maker' | 'taker' | 'market' = this.isInstitutionalHybridStrategy(config) ? 'maker' : 'market';
        const source = config.runtimeOpenExecutionMode ?? config.openExecutionMode;
        return this.normalizeExecutionMode(source, fallback);
    }

    private resolveCloseExecutionMode(config: MarketKingTradingConfig): 'maker' | 'taker' | 'market' {
        const fallback: 'maker' | 'taker' | 'market' = 'maker';
        const source = config.runtimeCloseExecutionMode ?? config.closeExecutionMode;
        return this.normalizeExecutionMode(source, fallback);
    }

    private resolveFeeRateForMode(
        mode: 'maker' | 'taker' | 'market',
        takerFeeRate: number,
        makerFeeRate: number
    ): number {
        if (mode === 'maker') return Math.max(0, Number(makerFeeRate || 0));
        return Math.max(0, Number(takerFeeRate || 0));
    }

    private resolveSlippageForMode(
        config: MarketKingTradingConfig,
        mode: 'maker' | 'taker' | 'market',
        fallbackTakerSlippagePercent: number
    ): number {
        if (mode === 'maker') {
            return Math.max(0, Number(config.runtimeMakerSlippagePercent ?? 0));
        }
        return Math.max(0, Number(
            config.runtimeTakerSlippagePercent
                ?? fallbackTakerSlippagePercent
                ?? config.slippagePercent
                ?? 0
        ));
    }

    private buildPositionExecutionCostProfile(
        config: MarketKingTradingConfig,
        symbolInfo: any,
        positionRef?: (MarketKingLongPosition | MarketKingShortPosition | InstitutionalPositionExecutionMeta | null),
        closeModeOverride?: 'maker' | 'taker' | 'market',
        fallbackTakerSlippagePercent: number = 0
    ): {
        openExecutionMode: 'maker' | 'taker' | 'market';
        closeExecutionMode: 'maker' | 'taker' | 'market';
        openFeeRate: number;
        closeFeeRate: number;
        openSlippagePercent: number;
        closeSlippagePercent: number;
        takerFeeRate: number;
        makerFeeRate: number;
    } {
        const takerFeeRate = Math.max(
            0,
            Number((positionRef as any)?.takerFeeRate ?? symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0)
        );
        const makerFeeRate = Math.max(
            0,
            Number((positionRef as any)?.makerFeeRate ?? symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? 0)
        );
        const openExecutionMode = this.normalizeExecutionMode(
            (positionRef as any)?.openExecutionMode,
            this.resolveOpenExecutionMode(config)
        );
        const persistedCloseMode = this.normalizeExecutionMode(
            (positionRef as any)?.closeExecutionMode,
            this.resolveCloseExecutionMode(config)
        );
        const closeExecutionMode = closeModeOverride
            ? this.normalizeExecutionMode(closeModeOverride, persistedCloseMode)
            : persistedCloseMode;
        const openFeeRate = Number.isFinite(Number((positionRef as any)?.openFeeRate))
            ? Math.max(0, Number((positionRef as any)?.openFeeRate))
            : this.resolveFeeRateForMode(openExecutionMode, takerFeeRate, makerFeeRate);
        const closeFeeRate = Number.isFinite(Number((positionRef as any)?.closeFeeRate))
            ? Math.max(0, Number((positionRef as any)?.closeFeeRate))
            : this.resolveFeeRateForMode(closeExecutionMode, takerFeeRate, makerFeeRate);
        const openSlippagePercent = Number.isFinite(Number((positionRef as any)?.openSlippagePercent))
            ? Math.max(0, Number((positionRef as any)?.openSlippagePercent))
            : this.resolveSlippageForMode(config, openExecutionMode, fallbackTakerSlippagePercent);
        const closeSlippagePercent = Number.isFinite(Number((positionRef as any)?.closeSlippagePercent))
            ? Math.max(0, Number((positionRef as any)?.closeSlippagePercent))
            : this.resolveSlippageForMode(config, closeExecutionMode, fallbackTakerSlippagePercent);

        return {
            openExecutionMode,
            closeExecutionMode,
            openFeeRate,
            closeFeeRate,
            openSlippagePercent,
            closeSlippagePercent,
            takerFeeRate,
            makerFeeRate,
        };
    }

    private findRuntimePositionById(
        state: MarketKingTradingState,
        positionId: string
    ): { side: 'long' | 'short'; position: MarketKingLongPosition | MarketKingShortPosition } | null {
        const targetId = String(positionId || '').trim();
        if (!targetId) return null;

        const longPosition = (state.activePositions || []).find((p) => String(p.positionId || '').trim() === targetId);
        if (longPosition) {
            return { side: 'long', position: longPosition };
        }

        const shortPosition = (state.shortPositions || []).find((p) => String(p.positionId || '').trim() === targetId);
        if (shortPosition) {
            return { side: 'short', position: shortPosition };
        }

        return null;
    }

    private isEligibleForInstitutionalLossCap(position: MarketKingLongPosition | MarketKingShortPosition | null | undefined): boolean {
        if (!position) return false;
        const isHedge = !!(((position as any).gridIsHedge) ?? (position as any).qgHedge);
        if (isHedge) return false;
        if (String((position as any).longevityRebasePairId || '').trim().length > 0) return false;
        if (isRsiExtremeOverlayPosition(position)) return false;
        return true;
    }

    private buildInstitutionalLossCapClosePlan(
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        symbolInfo: any,
        closePrice: number,
        dailyInterestRate: number,
        nowTs: number = Date.now()
    ): {
        positionId: string;
        side: 'long' | 'short';
        quantity: number;
        price: number;
        reason: string;
        forceLoss: boolean;
        executionMode: 'maker' | 'taker' | 'market';
    } | null {
        const cap = Number(config.maxUnrealizedLossPerPositionUsd);
        if (!Number.isFinite(cap) || cap >= 0) return null;

        const safePrice = Number(closePrice || 0);
        if (!(safePrice > 0)) return null;

        const safeNowTs = Number.isFinite(Number(nowTs)) && Number(nowTs) > 0 ? Number(nowTs) : Date.now();
        if (resolveLossCapCooldownStatus(state, config, safeNowTs).active) return null;

        const safeDailyInterestRate = Math.max(
            0,
            this.resolveEffectiveCarryRate(config, dailyInterestRate)
        );
        const lossCapCloseMode = this.normalizeExecutionMode(
            config.riskEmergencyCloseMode || config.closeExecutionMode || 'maker',
            'maker'
        );
        let worst: {
            positionId: string;
            side: 'long' | 'short';
            quantity: number;
            net: number;
        } | null = null;

        const candidates: Array<{ side: 'long' | 'short'; position: MarketKingLongPosition | MarketKingShortPosition }> = [
            ...(state.activePositions || []).map((position) => ({ side: 'long' as const, position })),
            ...((state.shortPositions || []).map((position) => ({ side: 'short' as const, position }))),
        ];

        for (const candidate of candidates) {
            if (!this.isEligibleForInstitutionalLossCap(candidate.position)) continue;
            const positionId = String((candidate.position as any)?.positionId || '').trim();
            const quantity = Math.max(0, Number((candidate.position as any)?.quantity || 0));
            if (!positionId || !(quantity > 0)) continue;

            const net = this.estimateRuntimeCloseNet(
                config,
                symbolInfo,
                candidate.position,
                candidate.side,
                safePrice,
                quantity,
                safeDailyInterestRate,
                lossCapCloseMode
            );
            if (!Number.isFinite(net) || net >= cap) continue;

            if (!worst || net < worst.net) {
                worst = {
                    positionId,
                    side: candidate.side,
                    quantity,
                    net,
                };
            }
        }

        if (!worst) return null;

        return {
            positionId: worst.positionId,
            side: worst.side,
            quantity: worst.quantity,
            price: safePrice,
            reason: 'max_unrealized_loss_cap',
            forceLoss: true,
            executionMode: lossCapCloseMode,
        };
    }

    private estimateRuntimeCloseNet(
        config: MarketKingTradingConfig,
        symbolInfo: any,
        positionRef: MarketKingLongPosition | MarketKingShortPosition | null | undefined,
        side: 'long' | 'short',
        closePrice: number,
        closeQty: number,
        dailyInterestRate: number,
        closeModeOverride?: 'maker' | 'taker' | 'market'
    ): number {
        if (!positionRef) return Number.NaN;

        const safePrice = Number(closePrice || 0);
        const safeQty = Math.max(0, Number(closeQty || 0));
        if (!(safePrice > 0) || !(safeQty > 0)) return Number.NaN;

        const executionSlippagePercent = Number(config.slippagePercent ?? 0.00015);
        const executionSlippageModel: 'one_sided' | 'two_sided' =
            (String(config.slippageModel ?? 'two_sided').toLowerCase() === 'one_sided' ? 'one_sided' : 'two_sided');
        const executionProfile = this.buildPositionExecutionCostProfile(
            config,
            symbolInfo,
            positionRef,
            closeModeOverride,
            executionSlippagePercent
        );
        const modeled = this.calculatePositionNet(
            side,
            positionRef,
            safePrice,
            executionProfile.takerFeeRate,
            executionSlippagePercent,
            executionSlippageModel,
            dailyInterestRate,
            safeQty,
            config,
            symbolInfo,
            executionProfile
        );

        return Number(modeled?.net ?? Number.NaN);
    }
    private resolveRuntimeCloseMinNetForReason(
        config: MarketKingTradingConfig,
        reasonRaw?: string,
        forceLoss: boolean = false,
        notionalUsd?: number
    ): number | undefined {
        if (forceLoss) return undefined;

        const defaultMinNet = this.resolveMinNetCloseUsd(config, notionalUsd);
        const reason = String(reasonRaw || '').trim().toLowerCase();
        if (!reason) return defaultMinNet;

        if (reason.includes('pairing_take_age_reduce')) {
            const pairAgeReduceMinNetUsd = Number(config.pairAgeReduceMinNetUsd ?? 0.01);
            return Number.isFinite(pairAgeReduceMinNetUsd) ? pairAgeReduceMinNetUsd : defaultMinNet;
        }

        if (reason.includes('max_hold_time_stop')) {
            const maxHoldStopMinNetUsd = Number(config.maxHoldStopMinNetUsd ?? 0.01);
            return Number.isFinite(maxHoldStopMinNetUsd) ? maxHoldStopMinNetUsd : defaultMinNet;
        }

        return defaultMinNet;
    }

    private upsertInstitutionalPendingCloseOrder(
        state: MarketKingTradingState,
        order: InstitutionalPendingCloseOrder
    ): void {
        if (!Array.isArray(state._institutionalPendingCloseOrders)) {
            state._institutionalPendingCloseOrders = [];
        }
        const orders = state._institutionalPendingCloseOrders;
        const idx = orders.findIndex((item) => String(item.orderId) === String(order.orderId));
        if (idx >= 0) {
            orders[idx] = { ...orders[idx], ...order };
        } else {
            orders.push(order);
        }
    }

    private upsertInstitutionalPendingOpenOrder(
        state: MarketKingTradingState,
        order: InstitutionalPendingOpenOrder
    ): void {
        if (!Array.isArray(state._institutionalPendingOpenOrders)) {
            state._institutionalPendingOpenOrders = [];
        }
        const orders = state._institutionalPendingOpenOrders;
        const idx = orders.findIndex((item) => String(item.orderId) === String(order.orderId));
        if (idx >= 0) {
            orders[idx] = { ...orders[idx], ...order };
        } else {
            orders.push(order);
        }
    }

    private removeInstitutionalPendingOpenOrder(
        state: MarketKingTradingState,
        orderId: string
    ): void {
        if (!Array.isArray(state._institutionalPendingOpenOrders) || !orderId) return;
        state._institutionalPendingOpenOrders = state._institutionalPendingOpenOrders
            .filter((item) => String(item.orderId) !== String(orderId));
    }

    private removeInstitutionalPendingCloseOrder(
        state: MarketKingTradingState,
        orderId: string
    ): void {
        if (!Array.isArray(state._institutionalPendingCloseOrders) || !orderId) return;
        state._institutionalPendingCloseOrders = state._institutionalPendingCloseOrders
            .filter((item) => String(item.orderId) !== String(orderId));
    }

    private removeInstitutionalPendingCloseOrdersByPositionId(
        state: MarketKingTradingState,
        positionId: string
    ): void {
        const pid = String(positionId || '').trim();
        if (!Array.isArray(state._institutionalPendingCloseOrders) || !pid) return;
        state._institutionalPendingCloseOrders = state._institutionalPendingCloseOrders
            .filter((item) => String(item.positionId || '').trim() !== pid);
    }

    private async ensureManagedFuturesRuntimeSettings(client: any, config: MarketKingTradingConfig, isReal: boolean): Promise<void> {
        const nextState = await applyManagedFutureRuntimeSettings({
            exchange: (config as any)?.exchange,
            isReal,
            isFuturePerpetualTrade: this.isFuturePerpetualTrade(config),
            client,
            config,
            state: {
                appliedKey: this.managedFuturesRuntimeSettingsAppliedKey,
                lastFailureKey: this.managedFuturesRuntimeSettingsLastFailureKey,
                lastFailureAt: this.managedFuturesRuntimeSettingsLastFailureAt,
            },
            unwrapResponsePayload: (response) => this.unwrapResponsePayload(response),
            logger: this.logger,
            resolveProductType: (runtimeConfig) => this.resolveManagedFuturesProductType(runtimeConfig),
            resolveMarginMode: (runtimeConfig) => this.resolveManagedFuturesMarginMode(runtimeConfig),
        });
        this.managedFuturesRuntimeSettingsAppliedKey = nextState.appliedKey ?? null;
        this.managedFuturesRuntimeSettingsLastFailureKey = nextState.lastFailureKey ?? null;
        this.managedFuturesRuntimeSettingsLastFailureAt = Number(nextState.lastFailureAt ?? 0);
    }

    async executeIteration(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        balances: any,
        symbolInfo: any,
        tickerInfo: any,
        currentIndicators: any,
        marginAccountInfo?: any,
        marginPositions?: any[],
        interestRate?: number | null
    ): Promise<void> {
        try {
            if (!Array.isArray(state.activePositions)) state.activePositions = [];
            if (!Array.isArray(state.shortPositions)) state.shortPositions = [];
            if (!Array.isArray(state.buyPrices)) state.buyPrices = [];

            this.enforceFutureRuntimeDefaults(config);
            this.alignRuntimeConfigWithPreset(config);
            // o. Validar modo Demo/Real na primeira iteracao
            this.validateAndSetDemoMode(config, state);

            // Crítico: Determinar modo (DEMO ou REAL) no inicio
            const isDemo = this.isDemo; // o. Usar propriedade da classe
            const isReal = !this.isDemo;
            const demoMirrorRealRiskChecksInDemo = config.demoMirrorRealRiskChecksInDemo !== undefined
                ? !!config.demoMirrorRealRiskChecksInDemo
                : true;

            await this.ensureManagedFuturesRuntimeSettings(client, config, isReal);

            // o. Sincronizar max_operations do job com o DB (evita servico cacheado com quant_op antigo)
            if (this.managerDB && config.maxOperations != null) {
                await this.managerDB.syncMaxOperations(config.maxOperations);
            }

            const normalizedSymbolInfo = this.normalizeSymbolInfo(config, symbolInfo);
            const currentPrice = this.extractTickerPrice(tickerInfo);
            symbolInfo = normalizedSymbolInfo;
            const runtimeQuantityRules = resolveCryptoQuantityRules(normalizedSymbolInfo, config);
            const runtimeMaintenanceMarginRate = Number(
                normalizedSymbolInfo?.keepMarginRate
                ?? normalizedSymbolInfo?.maintenanceMarginRate
                ?? config.maintenanceMarginRate
                ?? 0.01
            );
            config = {
                ...config,
                quantityStep: runtimeQuantityRules.quantityStep,
                minOrderQty: runtimeQuantityRules.minOrderQty,
                minNotional: runtimeQuantityRules.minNotionalUsd,
                quantityPrecision: runtimeQuantityRules.quantityPrecision,
                pricePrecision: runtimeQuantityRules.pricePrecision,
                priceStep: runtimeQuantityRules.priceStep,
                cryptoQuantityRules: runtimeQuantityRules,
                maintenanceMarginRate: Number.isFinite(runtimeMaintenanceMarginRate) && runtimeMaintenanceMarginRate > 0
                    ? runtimeMaintenanceMarginRate
                    : 0.01,
            } as MarketKingTradingConfig;
            this.calculations.updateMarginRates(Number(config.maintenanceMarginRate));

            if (!Number.isFinite(currentPrice) || currentPrice <= 0) {
                this.logger.warn(`s Preco invalido recebido para ${config.market}: ${JSON.stringify(tickerInfo)}`);
                return;
            }

            // Inicializar Pine Script Service se necessÃ¡rio
            const currentTime = resolveIterationTimestamp(config, normalizedSymbolInfo, tickerInfo, Date.now());
            (config as any).iterationTs = currentTime;
            const symbol = config.market || config.pair1 + config.pair2;
            const granularity = config.granularity || '1m';
            this.initializePineScriptService(config, symbol, granularity);

            // Crítico: Em modo DEMO, nao usar dados da API (evitar falhas silenciosas)
            // Em modo REAL, usar dados reais da API quando disponiveis
            let realBorrowedAmount = state.borrowedAmount;
            let realTotalLiabilitiesAmount: number | undefined = undefined;
            let realMarginLevel = state.marginLevel;
            // Risco de liquidação por extouro de margem: liquidationMarginLevelThreshold (1.1) do preset.
            const CRITICAL_MARGIN_LEVEL = this.riskManager.resolveCriticalMarginLevel({
                criticalMarginLevel: (config as any).criticalMarginLevel,
                liquidationMarginLevelThreshold: (config as any).liquidationMarginLevelThreshold,
                fallback: 1.5,
            });
            let realInterestRate = this.resolveEffectiveCarryRate(config, interestRate); // Fallback para padrao se nao disponivel

            // Crítico: So sincronizar com API em modo REAL
            if (isReal && marginAccountInfo) {
                const apiMarginLevelRaw = Number(marginAccountInfo.marginLevel);
                realMarginLevel = Number.isFinite(apiMarginLevelRaw) && apiMarginLevelRaw > 0
                    ? apiMarginLevelRaw
                    : state.marginLevel;

                if (this.isFuturePerpetualTrade(config)) {
                    realBorrowedAmount = 0;
                    realTotalLiabilitiesAmount = 0;
                    realInterestRate = 0;
                    this.logger.info(
                        `Y"S [FUTURE REAL] Margin Level(API): ${realMarginLevel.toFixed(2)}x | Funding: ${(this.resolveFundingRatePer8h(config, symbolInfo) * 100).toFixed(4)}%/8h`
                    );
                } else {
                    // Preferir passivos convertidos para moeda de cotacao (quote). Somatorios "raw" por ativo
                    // (ex.: XAUT + USDT) nao podem ser tratados como USD/USDT.
                    const apiLiabilitiesQuote = this.extractApiMarginLiabilitiesInQuote(
                        marginAccountInfo,
                        config,
                        currentPrice
                    );
                    const apiBorrowedQuote = Number(marginAccountInfo.totalBorrowedQuote);
                    const apiTotalLiabilityQuote = Number(marginAccountInfo.totalLiabilityQuote);
                    realBorrowedAmount = Number.isFinite(apiLiabilitiesQuote.borrowedPrincipalQuote)
                        ? Number(apiLiabilitiesQuote.borrowedPrincipalQuote)
                        : (Number.isFinite(apiBorrowedQuote) && apiBorrowedQuote >= 0 ? apiBorrowedQuote : state.borrowedAmount);
                    realTotalLiabilitiesAmount = Number.isFinite(apiLiabilitiesQuote.totalLiabilitiesQuote)
                        ? Number(apiLiabilitiesQuote.totalLiabilitiesQuote)
                        : (Number.isFinite(apiTotalLiabilityQuote) && apiTotalLiabilityQuote >= 0 ? apiTotalLiabilityQuote : undefined);

                    const maintenanceRate = Number(
                        symbolInfo?.keepMarginRate
                        ?? symbolInfo?.maintenanceMarginRate
                        ?? marginAccountInfo.maintenanceMarginRate
                        ?? marginAccountInfo.maintenanceRate
                        ?? config.maintenanceMarginRate
                        ?? 0
                    );
                    const initialRate = parseFloat(marginAccountInfo.initialMarginRate || marginAccountInfo.initialRate || '0');
                    if (maintenanceRate > 0) {
                        this.calculations.updateMarginRates(maintenanceRate, initialRate);
                        this.logger.info(
                            `Y"S [TAXAS REAIS] Maintenance Rate: ${(maintenanceRate * 100).toFixed(2)}% | Initial Rate: ${(initialRate * 100).toFixed(2)}%`
                        );
                    }

                    if (apiLiabilitiesQuote.unresolvedAssets.length > 0) {
                        this.logger.warn(
                            `[MARGEM REAL] Passivos com ativo sem conversao para ${config.pair2}: ${apiLiabilitiesQuote.unresolvedAssets.join(', ')}`
                        );
                    }

                    this.logger.info(
                        `Y"S [MARGEM REAL] Borrowed(quote): $${realBorrowedAmount.toFixed(2)} | ` +
                        `Liabilities(quote): $${Number(realTotalLiabilitiesAmount ?? realBorrowedAmount).toFixed(2)} | ` +
                        `Margin Level(API): ${realMarginLevel.toFixed(2)}x | Interest Rate: ${(realInterestRate * 100).toFixed(4)}%/dia`
                    );
                }
            }

            const hasReliableRealPositionsSnapshot = marginAccountInfo?.positionsSnapshotReliable !== false;
            if (isReal && !hasReliableRealPositionsSnapshot) {
                const now = Date.now();
                const lastTs = Number(state._lastPositionSyncGuardLogAt || 0);
                if (!Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= 30000) {
                    this.logger.warn('[SYNC API] Snapshot de posicoes da exchange marcado como nao confiavel (open_orders). Sync de posicoes reais sera ignorado nesta iteracao.');
                    state._lastPositionSyncGuardLogAt = now;
                }
            }

            // Crítico: Sincronizar posicoes reais da API com state (LONG e SHORT) - APENAS em modo REAL
            if (isReal && hasReliableRealPositionsSnapshot && Array.isArray(marginPositions)) {
                const priceTolerance = Math.max(1e-8, Number(config.priceTolerance ?? 0.0001));
                const currentLongPositions = Array.isArray(state.activePositions) ? [...state.activePositions] : [];
                const currentShortPositions = Array.isArray(state.shortPositions) ? [...state.shortPositions] : [];
                const matchedPendingOpenOrders: InstitutionalPendingOpenOrder[] = [];

                if (marginPositions.length === 0) {
                    const hadLocalPositions = currentLongPositions.length > 0 || currentShortPositions.length > 0;
                    const borrowedSignal = Number.isFinite(Number(realBorrowedAmount)) && Number(realBorrowedAmount) > 0;

                    if (hadLocalPositions && borrowedSignal) {
                        this.logger.warn(
                            `[SYNC API] Empty marginPositions snapshot with borrowedAmount=${Number(realBorrowedAmount).toFixed(4)}. Keeping local runtime positions to avoid false reset.`
                        );
                    } else {
                        if (hadLocalPositions) {
                            this.logger.warn('[SYNC API] No open positions returned by exchange. Clearing local LONG/SHORT runtime state.');
                        }
                        state.activePositions = [];
                        state.shortPositions = [];
                        state.buyPrices = [];
                        state.borrowedAmount = 0;
                    }
                } else {
                    const longPositions = marginPositions.filter((pos: any) =>
                        (pos.side || 'long').toLowerCase() === 'long'
                    );
                    const shortPositions = marginPositions.filter((pos: any) =>
                        (pos.side || '').toLowerCase() === 'short'
                    );

                    const usedLongIndexes = new Set<number>();
                    const usedPendingOpenLongOrderIds = new Set<string>();
                    state.activePositions = longPositions
                        .map((pos: any, index: number) => {
                            const entryPriceRaw = parseFloat(pos.entryPrice || pos.openPrice || '0');
                            const quantityRaw = parseFloat(pos.quantity || pos.size || '0');
                            const borrowedRaw = parseFloat(pos.borrowedAmount || pos.borrow || '0');
                            const openTimeRaw = Number(pos.openTime || pos.timestamp || Date.now());

                            const entryPrice = Number.isFinite(entryPriceRaw) ? entryPriceRaw : 0;
                            const quantity = Number.isFinite(quantityRaw) ? quantityRaw : 0;
                            const borrowedAmount = Number.isFinite(borrowedRaw) ? borrowedRaw : 0;
                            const openTime = Number.isFinite(openTimeRaw) && openTimeRaw > 0 ? openTimeRaw : Date.now();

                            const apiPosition: MarketKingLongPosition = {
                                positionId: String(pos.positionId || pos.id || `LONG_${openTime}_${index}`),
                                entryPrice,
                                quantity,
                                borrowedAmount,
                                openTime,
                                side: 'long',
                                dailyInterestRate: String(config.executionTradeType || config.tradeType || '').toLowerCase().includes('future')
                                    ? 0
                                    : realInterestRate,
                                initialMargin: Number(pos.initialMargin || pos.margin || 0) || ((entryPrice * quantity) / Math.max(1, Number(pos.leverage || config.leverage || 1))),
                                leverage: Number(pos.leverage || config.leverage || 1),
                                markPrice: Number(pos.markPrice) > 0 ? Number(pos.markPrice) : undefined,
                                liquidationPrice: Number(pos.liquidationPrice) > 0 ? Number(pos.liquidationPrice) : undefined,
                                keepMarginRate: Number(pos.keepMarginRate) > 0 ? Number(pos.keepMarginRate) : undefined,
                                maintenanceMarginRate: Number(pos.maintenanceMarginRate || pos.keepMarginRate) > 0
                                    ? Number(pos.maintenanceMarginRate || pos.keepMarginRate)
                                    : undefined,
                            };

                            const runtimeMatch = this.findLongRuntimeMatch(
                                currentLongPositions,
                                apiPosition,
                                usedLongIndexes,
                                priceTolerance
                            );
                            const pendingOpenMatch = runtimeMatch
                                ? null
                                : this.findInstitutionalPendingOpenMatch(
                                    state,
                                    'long',
                                    apiPosition,
                                    usedPendingOpenLongOrderIds,
                                    priceTolerance
                                );
                            if (pendingOpenMatch) {
                                matchedPendingOpenOrders.push(pendingOpenMatch);
                            }

                            return {
                                ...apiPosition,
                                initialMargin: runtimeMatch?.initialMargin ?? apiPosition.initialMargin,
                                leverage: runtimeMatch?.leverage ?? apiPosition.leverage,
                                counterTrend: runtimeMatch?.counterTrend ?? false,
                                _hardStopSkipCount: runtimeMatch?._hardStopSkipCount ?? 0,
                                _maxHoldingSkipCount: runtimeMatch?._maxHoldingSkipCount ?? 0,
                                peakPrice: runtimeMatch?.peakPrice ?? entryPrice,
                                _breakEvenArmed: runtimeMatch?._breakEvenArmed ?? false,
                                takePrice: runtimeMatch?.takePrice ?? pendingOpenMatch?.takePrice,
                                makerFeeRate: runtimeMatch?.makerFeeRate ?? pendingOpenMatch?.makerFeeRate,
                                takerFeeRate: runtimeMatch?.takerFeeRate ?? pendingOpenMatch?.takerFeeRate,
                                makerSlippagePercent: runtimeMatch?.makerSlippagePercent ?? pendingOpenMatch?.makerSlippagePercent,
                                takerSlippagePercent: runtimeMatch?.takerSlippagePercent ?? pendingOpenMatch?.takerSlippagePercent,
                                openFeeRate: runtimeMatch?.openFeeRate ?? pendingOpenMatch?.openFeeRate,
                                closeFeeRate: runtimeMatch?.closeFeeRate ?? pendingOpenMatch?.closeFeeRate,
                                openSlippagePercent: runtimeMatch?.openSlippagePercent ?? pendingOpenMatch?.openSlippagePercent,
                                closeSlippagePercent: runtimeMatch?.closeSlippagePercent ?? pendingOpenMatch?.closeSlippagePercent,
                                openExecutionMode: runtimeMatch?.openExecutionMode ?? pendingOpenMatch?.openExecutionMode,
                                closeExecutionMode: runtimeMatch?.closeExecutionMode ?? pendingOpenMatch?.closeExecutionMode,
                                fundingRatePer8h: runtimeMatch?.fundingRatePer8h ?? pendingOpenMatch?.fundingRatePer8h,
                                fundingIntervalHours: runtimeMatch?.fundingIntervalHours ?? pendingOpenMatch?.fundingIntervalHours,
                                fundingFeeMode: runtimeMatch?.fundingFeeMode ?? pendingOpenMatch?.fundingFeeMode,
                                sourceQuoteId: runtimeMatch?.sourceQuoteId ?? pendingOpenMatch?.sourceQuoteId,
                                qgHedge: (runtimeMatch as any)?.qgHedge ?? pendingOpenMatch?.isHedge ?? false,
                                qgParentSide: (runtimeMatch as any)?.qgParentSide ?? pendingOpenMatch?.parentSide,
                                qgReason: (runtimeMatch as any)?.qgReason ?? (runtimeMatch as any)?.qgOpenReason ?? pendingOpenMatch?.reason,
                                gridParentSide: (runtimeMatch as any)?.gridParentSide ?? pendingOpenMatch?.parentSide,
                                gridIsHedge: (runtimeMatch as any)?.gridIsHedge ?? pendingOpenMatch?.isHedge ?? false,
                                gridOpenReason: (runtimeMatch as any)?.gridOpenReason ?? (runtimeMatch as any)?.qgReason ?? pendingOpenMatch?.reason
                            };
                        })
                        .sort((a, b) => Number(a.openTime || 0) - Number(b.openTime || 0));

                    if (state.activePositions.length > 0) {
                        const syncedBuyPrices = state.activePositions.map(p => p.entryPrice);
                        if (state.buyPrices.length === 0 ||
                            state.buyPrices.length !== state.activePositions.length ||
                            !state.buyPrices.every((bp, idx) => Math.abs(bp - syncedBuyPrices[idx]) < 0.0001)) {
                            this.logger.warn(
                                `[SYNC API] buyPrices out of sync (${state.buyPrices.length} vs ${state.activePositions.length}). Rebuilding from activePositions.`,
                                { channel: 'trading' }
                            );
                            state.buyPrices = syncedBuyPrices;
                        }
                    } else if (state.buyPrices.length > 0) {
                        state.buyPrices = [];
                    }

                    if (shortPositions.length > 0) {
                        const usedShortIndexes = new Set<number>();
                        const usedPendingOpenShortOrderIds = new Set<string>();
                        state.shortPositions = shortPositions
                            .map((pos: any, index: number) => {
                                const shortPriceRaw = parseFloat(pos.entryPrice || pos.openPrice || '0');
                                const shortQtyRaw = parseFloat(pos.quantity || pos.size || '0');
                                const borrowedQtyRaw = parseFloat(pos.borrowedQuantity || pos.quantity || pos.size || '0');
                                const borrowedAmountRaw = parseFloat(pos.borrowedAmount || pos.borrow || pos.borrowed || '0');
                                const initialMarginRaw = parseFloat(pos.initialMargin || pos.margin || '0');
                                const openTimeRaw = Number(pos.openTime || pos.timestamp || Date.now());

                                const shortPrice = Number.isFinite(shortPriceRaw) ? shortPriceRaw : 0;
                                const shortQty = Number.isFinite(shortQtyRaw) ? shortQtyRaw : 0;
                                const borrowedQty = Number.isFinite(borrowedQtyRaw) ? borrowedQtyRaw : 0;
                                const borrowedAmount = Number.isFinite(borrowedAmountRaw) ? borrowedAmountRaw : 0;
                                const initialMargin = Number.isFinite(initialMarginRaw) ? initialMarginRaw : 0;
                                const openTime = Number.isFinite(openTimeRaw) && openTimeRaw > 0 ? openTimeRaw : Date.now();

                                const apiShortPosition: MarketKingShortPosition = {
                                    positionId: String(pos.positionId || pos.id || `SHORT_${openTime}_${index}`),
                                    price: shortPrice,
                                    quantity: shortQty,
                                    borrowedQuantity: borrowedQty,
                                    borrowedAmount,
                                    initialMargin,
                                    leverage: Number(pos.leverage || config.leverage || 1),
                                    openTime,
                                    dailyInterestRate: String(config.executionTradeType || config.tradeType || '').toLowerCase().includes('future')
                                        ? 0
                                        : realInterestRate,
                                    markPrice: Number(pos.markPrice) > 0 ? Number(pos.markPrice) : undefined,
                                    liquidationPrice: Number(pos.liquidationPrice) > 0 ? Number(pos.liquidationPrice) : undefined,
                                    keepMarginRate: Number(pos.keepMarginRate) > 0 ? Number(pos.keepMarginRate) : undefined,
                                    maintenanceMarginRate: Number(pos.maintenanceMarginRate || pos.keepMarginRate) > 0
                                        ? Number(pos.maintenanceMarginRate || pos.keepMarginRate)
                                        : undefined,
                                };

                                const runtimeMatch = this.findShortRuntimeMatch(
                                    currentShortPositions,
                                    apiShortPosition,
                                    usedShortIndexes,
                                    priceTolerance
                                );
                                const pendingOpenMatch = runtimeMatch
                                    ? null
                                    : this.findInstitutionalPendingOpenMatch(
                                        state,
                                        'short',
                                        apiShortPosition,
                                        usedPendingOpenShortOrderIds,
                                        priceTolerance
                                    );
                                if (pendingOpenMatch) {
                                    matchedPendingOpenOrders.push(pendingOpenMatch);
                                }

                                return {
                                    ...apiShortPosition,
                                    initialMargin: runtimeMatch?.initialMargin ?? apiShortPosition.initialMargin,
                                    leverage: runtimeMatch?.leverage ?? apiShortPosition.leverage,
                                    counterTrend: runtimeMatch?.counterTrend ?? false,
                                    _hardStopSkipCount: runtimeMatch?._hardStopSkipCount ?? 0,
                                    _maxHoldingSkipCount: runtimeMatch?._maxHoldingSkipCount ?? 0,
                                    peakPrice: runtimeMatch?.peakPrice ?? shortPrice,
                                    _breakEvenArmed: runtimeMatch?._breakEvenArmed ?? false,
                                    takePrice: runtimeMatch?.takePrice ?? pendingOpenMatch?.takePrice,
                                    makerFeeRate: runtimeMatch?.makerFeeRate ?? pendingOpenMatch?.makerFeeRate,
                                    takerFeeRate: runtimeMatch?.takerFeeRate ?? pendingOpenMatch?.takerFeeRate,
                                    makerSlippagePercent: runtimeMatch?.makerSlippagePercent ?? pendingOpenMatch?.makerSlippagePercent,
                                    takerSlippagePercent: runtimeMatch?.takerSlippagePercent ?? pendingOpenMatch?.takerSlippagePercent,
                                    openFeeRate: runtimeMatch?.openFeeRate ?? pendingOpenMatch?.openFeeRate,
                                    closeFeeRate: runtimeMatch?.closeFeeRate ?? pendingOpenMatch?.closeFeeRate,
                                    openSlippagePercent: runtimeMatch?.openSlippagePercent ?? pendingOpenMatch?.openSlippagePercent,
                                    closeSlippagePercent: runtimeMatch?.closeSlippagePercent ?? pendingOpenMatch?.closeSlippagePercent,
                                    openExecutionMode: runtimeMatch?.openExecutionMode ?? pendingOpenMatch?.openExecutionMode,
                                    closeExecutionMode: runtimeMatch?.closeExecutionMode ?? pendingOpenMatch?.closeExecutionMode,
                                    fundingRatePer8h: runtimeMatch?.fundingRatePer8h ?? pendingOpenMatch?.fundingRatePer8h,
                                    fundingIntervalHours: runtimeMatch?.fundingIntervalHours ?? pendingOpenMatch?.fundingIntervalHours,
                                    fundingFeeMode: runtimeMatch?.fundingFeeMode ?? pendingOpenMatch?.fundingFeeMode,
                                    sourceQuoteId: runtimeMatch?.sourceQuoteId ?? pendingOpenMatch?.sourceQuoteId,
                                    qgHedge: (runtimeMatch as any)?.qgHedge ?? pendingOpenMatch?.isHedge ?? false,
                                    qgParentSide: (runtimeMatch as any)?.qgParentSide ?? pendingOpenMatch?.parentSide,
                                    qgReason: (runtimeMatch as any)?.qgReason ?? (runtimeMatch as any)?.qgOpenReason ?? pendingOpenMatch?.reason,
                                    gridParentSide: (runtimeMatch as any)?.gridParentSide ?? pendingOpenMatch?.parentSide,
                                    gridIsHedge: (runtimeMatch as any)?.gridIsHedge ?? pendingOpenMatch?.isHedge ?? false,
                                    gridOpenReason: (runtimeMatch as any)?.gridOpenReason ?? (runtimeMatch as any)?.qgReason ?? pendingOpenMatch?.reason
                                };
                            })
                            .sort((a, b) => Number(a.openTime || 0) - Number(b.openTime || 0));

                        this.logger.info(
                            `[SYNC] ${shortPositions.length} SHORT position(s) synchronized from API`
                        );
                    } else {
                        state.shortPositions = [];
                    }

                    const totalBorrowedLong = state.activePositions.reduce((sum, p) => sum + Number(p.borrowedAmount || 0), 0);
                    const totalBorrowedShort = state.shortPositions
                        ? state.shortPositions.reduce((sum, sp) => {
                            const borrowedValue = Number.isFinite(Number(sp.borrowedAmount))
                                ? Number(sp.borrowedAmount)
                                : (Number(sp.price || 0) * Number(sp.borrowedQuantity || sp.quantity || 0));
                            return sum + borrowedValue;
                        }, 0)
                        : 0;
                    const totalBorrowed = totalBorrowedLong + totalBorrowedShort;

                    state.borrowedAmount = totalBorrowed;
                    if (totalBorrowed > 0 || currentLongPositions.length > 0 || currentShortPositions.length > 0) {
                        this.logger.info(
                            `[SYNC] Total borrowed updated: $${totalBorrowed.toFixed(2)} (${state.activePositions.length} LONG + ${state.shortPositions?.length || 0} SHORT)`
                        );
                    }
                }
                // o. [REAL] Fechamento maker confirmado pelo book: sync viu menos posições → persistir 1x success (evita várias ordens mock/pending).
                if (isReal && state._lastPendingOpenOrderId && state._lastPendingOpenPrice != null && state._lastPendingOpenQty != null && state._lastPendingOpenAction) {
                    const longQtyBefore = currentLongPositions.reduce((sum, position) => sum + Math.max(0, Number(position.quantity || 0)), 0);
                    const longQtyAfter = (state.activePositions || []).reduce((sum, position) => sum + Math.max(0, Number(position.quantity || 0)), 0);
                    const shortQtyBefore = currentShortPositions.reduce((sum, position) => sum + Math.max(0, Number(position.quantity || 0)), 0);
                    const shortQtyAfter = (state.shortPositions || []).reduce((sum, position) => sum + Math.max(0, Number(position.quantity || 0)), 0);
                    const qtyTolerance = 1e-10;
                    const pendingOpenIsLong = state._lastPendingOpenAction === 'buy';
                    const openSideAdvanced = pendingOpenIsLong
                        ? (longQtyAfter > (longQtyBefore + qtyTolerance))
                        : (shortQtyAfter > (shortQtyBefore + qtyTolerance));

                    if (openSideAdvanced) {
                        try {
                            await this.saveOperation(
                                config,
                                state._lastPendingOpenAction,
                                state._lastPendingOpenPrice,
                                state._lastPendingOpenQty,
                                state._lastPendingOpenOrderId,
                                0,
                                'real',
                                symbolInfo,
                                'success',
                                {
                                    operationPhase: 'open',
                                    operationReason: state._lastPendingOpenReason,
                                    executionMode: state._lastPendingOpenExecutionMode,
                                    positionId: state._lastPendingOpenPositionId,
                                    operationContext: {
                                        sync_source: 'position_sync_open',
                                        ...(state._lastPendingOpenSourceQuoteId ? { source_quote_id: state._lastPendingOpenSourceQuoteId } : {}),
                                    },
                                }
                            );
                            this.logger.info(`[SYNC API] Abertura ${pendingOpenIsLong ? 'LONG' : 'SHORT'} (book) persistida: orderId=${state._lastPendingOpenOrderId}`);
                        } catch (err: any) {
                            this.logger.warn(`[SYNC API] Falha ao persistir abertura ${pendingOpenIsLong ? 'LONG' : 'SHORT'}: ${err?.message || err}`);
                        }
                        state._lastPendingOpenOrderId = undefined;
                        state._lastPendingOpenPrice = undefined;
                        state._lastPendingOpenQty = undefined;
                        state._lastPendingOpenAction = undefined;
                        state._lastPendingOpenReason = undefined;
                        state._lastPendingOpenExecutionMode = undefined;
                        state._lastPendingOpenPositionId = undefined;
                        state._lastPendingOpenSourceQuoteId = undefined;
                    }
                }
                if (isReal && state._lastPendingCloseOrderId && state._lastPendingClosePrice != null && state._lastPendingCloseQty != null && state._lastPendingCloseAction) {
                    const longCountBefore = currentLongPositions.length;
                    const longCountAfter = (state.activePositions || []).length;
                    const shortCountBefore = currentShortPositions.length;
                    const shortCountAfter = (state.shortPositions || []).length;
                    const isPerp = this.isFuturePerpetualTrade(config);
                    const closeFeeRate = Math.max(0, Number(symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? (isPerp ? 0.0002 : 0)));
                    if (state._lastPendingCloseSide === 'long' && longCountAfter < longCountBefore && currentLongPositions.length > 0) {
                        const closedPos = currentLongPositions[currentLongPositions.length - 1];
                        const closeBorrowed = isPerp ? 0 : getBorrowedAmountForClose(
                            Number(closedPos.borrowedAmount || 0),
                            Number(closedPos.quantity || 0),
                            state._lastPendingCloseQty
                        );
                        const accumulatedInterest = isPerp ? 0 : this.calculateAccumulatedInterest(
                            closeBorrowed,
                            closedPos.openTime,
                            closedPos.dailyInterestRate ?? realInterestRate,
                            config
                        );
                        const legacyProfit = this.calculateNetProfitWithLoan(
                            closedPos.entryPrice,
                            state._lastPendingClosePrice,
                            state._lastPendingCloseQty,
                            closeFeeRate,
                            accumulatedInterest,
                            'long',
                            0,
                            'one_sided'
                        );
                        const canonicalProfit = this.estimateRuntimeCloseNet(
                            config,
                            symbolInfo,
                            closedPos,
                            'long',
                            state._lastPendingClosePrice,
                            state._lastPendingCloseQty,
                            this.resolveEffectiveCarryRate(config, realInterestRate),
                            state._lastPendingCloseExecutionMode ?? 'maker'
                        );
                        const profit = Number.isFinite(canonicalProfit) ? canonicalProfit : legacyProfit;
                        try {
                            await this.saveOperation(
                                config,
                                state._lastPendingCloseAction,
                                state._lastPendingClosePrice,
                                state._lastPendingCloseQty,
                                state._lastPendingCloseOrderId,
                                profit,
                                'real',
                                symbolInfo,
                                'success',
                                {
                                    operationPhase: 'close',
                                    operationReason: state._lastPendingCloseReason,
                                    executionMode: state._lastPendingCloseExecutionMode,
                                    positionId: state._lastPendingClosePositionId,
                                    operationContext: {
                                        sync_source: 'position_sync_close',
                                        ...(state._lastPendingCloseSourceQuoteId ? { source_quote_id: state._lastPendingCloseSourceQuoteId } : {}),
                                        ...(state._lastPendingCloseCycleId ? { cycle_close_id: state._lastPendingCloseCycleId } : {}),
                                        ...(state._lastPendingCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                                    },
                                }
                            );
                            this.logger.info(`[SYNC API] Fechamento LONG (book) persistido: orderId=${state._lastPendingCloseOrderId} profit=${profit.toFixed(4)}`);
                        } catch (err: any) {
                            this.logger.warn(`[SYNC API] Falha ao persistir fechamento LONG: ${err?.message || err}`);
                        }
                        state._lastPendingCloseOrderId = undefined;
                        state._lastPendingClosePrice = undefined;
                        state._lastPendingCloseQty = undefined;
                        state._lastPendingCloseAction = undefined;
                        state._lastPendingCloseSentAt = undefined;
                        state._lastPendingCloseSide = undefined;
                        state._lastPendingCloseReason = undefined;
                        state._lastPendingCloseExecutionMode = undefined;
                        state._lastPendingClosePositionId = undefined;
                        state._lastPendingCloseSourceQuoteId = undefined;
                        state._lastPendingCloseCycleId = undefined;
                        state._lastPendingCloseCycleNetApproved = undefined;
                    } else if (state._lastPendingCloseSide === 'short' && shortCountAfter < shortCountBefore && currentShortPositions.length > 0) {
                        const closedShort = currentShortPositions[currentShortPositions.length - 1];
                        const borrowedValueTotal = isPerp ? 0 : (Number.isFinite(Number(closedShort.borrowedAmount)) ? Number(closedShort.borrowedAmount) : (Number(closedShort.price || 0) * Number(closedShort.borrowedQuantity || closedShort.quantity || 0)));
                        const closeBorrowed = isPerp ? 0 : getBorrowedAmountForClose(
                            borrowedValueTotal,
                            Number(closedShort.quantity || 0),
                            state._lastPendingCloseQty
                        );
                        const accumulatedInterest = isPerp ? 0 : this.calculateAccumulatedInterest(
                            closeBorrowed,
                            closedShort.openTime,
                            closedShort.dailyInterestRate ?? realInterestRate,
                            config
                        );
                        const legacyProfit = this.calculateNetProfitWithLoan(
                            closedShort.price,
                            state._lastPendingClosePrice,
                            state._lastPendingCloseQty,
                            closeFeeRate,
                            accumulatedInterest,
                            'short',
                            0,
                            'one_sided'
                        );
                        const canonicalProfit = this.estimateRuntimeCloseNet(
                            config,
                            symbolInfo,
                            closedShort,
                            'short',
                            state._lastPendingClosePrice,
                            state._lastPendingCloseQty,
                            this.resolveEffectiveCarryRate(config, realInterestRate),
                            state._lastPendingCloseExecutionMode ?? 'maker'
                        );
                        const profit = Number.isFinite(canonicalProfit) ? canonicalProfit : legacyProfit;
                        try {
                            await this.saveOperation(
                                config,
                                state._lastPendingCloseAction,
                                state._lastPendingClosePrice,
                                state._lastPendingCloseQty,
                                state._lastPendingCloseOrderId,
                                profit,
                                'real',
                                symbolInfo,
                                'success',
                                {
                                    operationPhase: 'close',
                                    operationReason: state._lastPendingCloseReason,
                                    executionMode: state._lastPendingCloseExecutionMode,
                                    positionId: state._lastPendingClosePositionId,
                                    operationContext: {
                                        sync_source: 'position_sync_close',
                                        ...(state._lastPendingCloseSourceQuoteId ? { source_quote_id: state._lastPendingCloseSourceQuoteId } : {}),
                                        ...(state._lastPendingCloseCycleId ? { cycle_close_id: state._lastPendingCloseCycleId } : {}),
                                        ...(state._lastPendingCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                                    },
                                }
                            );
                            this.logger.info(`[SYNC API] Fechamento SHORT (book) persistido: orderId=${state._lastPendingCloseOrderId} profit=${profit.toFixed(4)}`);
                        } catch (err: any) {
                            this.logger.warn(`[SYNC API] Falha ao persistir fechamento SHORT: ${err?.message || err}`);
                        }
                        state._lastPendingCloseOrderId = undefined;
                        state._lastPendingClosePrice = undefined;
                        state._lastPendingCloseQty = undefined;
                        state._lastPendingCloseAction = undefined;
                        state._lastPendingCloseSentAt = undefined;
                        state._lastPendingCloseSide = undefined;
                        state._lastPendingCloseReason = undefined;
                        state._lastPendingCloseExecutionMode = undefined;
                        state._lastPendingClosePositionId = undefined;
                        state._lastPendingCloseSourceQuoteId = undefined;
                        state._lastPendingCloseCycleId = undefined;
                        state._lastPendingCloseCycleNetApproved = undefined;
                    }
                }
                if (isReal && Array.isArray(state._institutionalPendingCloseOrders) && state._institutionalPendingCloseOrders.length > 0) {
                    const executionSlippagePercent = Number(config.slippagePercent ?? 0.00015);
                    const executionSlippageModel: 'one_sided' | 'two_sided' =
                        (String(config.slippageModel ?? 'two_sided').toLowerCase() === 'one_sided' ? 'one_sided' : 'two_sided');
                    const pendingOrdersNext: InstitutionalPendingCloseOrder[] = [];
                    for (const pendingClose of state._institutionalPendingCloseOrders) {
                        const side = pendingClose.side === 'short' ? 'short' : 'long';
                        const beforePosition = side === 'long'
                            ? currentLongPositions.find((position) => String(position.positionId || '') === String(pendingClose.positionId || ''))
                            : currentShortPositions.find((position) => String(position.positionId || '') === String(pendingClose.positionId || ''));
                        if (!beforePosition) {
                            continue;
                        }

                        const afterPosition = side === 'long'
                            ? (state.activePositions || []).find((position) => String(position.positionId || '') === String(pendingClose.positionId || ''))
                            : (state.shortPositions || []).find((position) => String(position.positionId || '') === String(pendingClose.positionId || ''));
                        const beforeQty = Math.max(0, Number((beforePosition as any).quantity || 0));
                        const afterQty = Math.max(0, Number((afterPosition as any)?.quantity || 0));
                        const filledQty = Math.max(0, Math.min(Number(pendingClose.quantity || 0), beforeQty - afterQty));
                        if (!(filledQty > 1e-10)) {
                            pendingOrdersNext.push(pendingClose);
                            continue;
                        }

                        try {
                            const executionProfile = this.buildPositionExecutionCostProfile(
                                config,
                                symbolInfo,
                                beforePosition as any,
                                (beforePosition as any)?.closeExecutionMode,
                                executionSlippagePercent
                            );
                            const modeled = this.calculatePositionNet(
                                side,
                                beforePosition as any,
                                Number(pendingClose.price || 0),
                                executionProfile.takerFeeRate,
                                executionSlippagePercent,
                                executionSlippageModel,
                                realInterestRate,
                                filledQty,
                                config,
                                symbolInfo,
                                executionProfile
                            );
                            await this.saveOperation(
                                config,
                                pendingClose.action,
                                Number(pendingClose.price || 0),
                                filledQty,
                                pendingClose.orderId,
                                Number(modeled?.net || 0),
                                'real',
                                symbolInfo,
                                'success'
                            );
                            this.logger.info(
                                `[SYNC API] Fechamento institucional (${side.toUpperCase()}) persistido: ` +
                                `orderId=${pendingClose.orderId} positionId=${pendingClose.positionId} qty=${filledQty.toFixed(8)} profit=${Number(modeled?.net || 0).toFixed(4)}`
                            );
                        } catch (err: any) {
                            this.logger.warn(
                                `[SYNC API] Falha ao persistir fechamento institucional ${side.toUpperCase()}: ${err?.message || err}`
                            );
                        }

                        const remainingQty = Math.max(0, Number(pendingClose.quantity || 0) - filledQty);
                        if (remainingQty > 1e-10 && afterPosition) {
                            pendingOrdersNext.push({
                                ...pendingClose,
                                quantity: remainingQty,
                            });
                        }
                    }
                    state._institutionalPendingCloseOrders = pendingOrdersNext;
                }
                if (isReal && matchedPendingOpenOrders.length > 0) {
                    const processedOpenOrderIds = new Set<string>();
                    for (const pendingOpen of matchedPendingOpenOrders) {
                        const orderId = String(pendingOpen.orderId || '');
                        if (!orderId || processedOpenOrderIds.has(orderId)) continue;
                        processedOpenOrderIds.add(orderId);
                        try {
                            await this.saveOperation(
                                config,
                                pendingOpen.action,
                                Number(pendingOpen.price || 0),
                                Number(pendingOpen.quantity || 0),
                                orderId,
                                0,
                                'real',
                                symbolInfo,
                                'success'
                            );
                            this.logger.info(
                                `[SYNC API] Abertura institucional (${pendingOpen.side.toUpperCase()}) persistida: ` +
                                `orderId=${orderId} qty=${Number(pendingOpen.quantity || 0).toFixed(8)} slot=${String(pendingOpen.sourceQuoteId || 'n/a')}`
                            );
                        } catch (err: any) {
                            this.logger.warn(
                                `[SYNC API] Falha ao persistir abertura institucional ${pendingOpen.side.toUpperCase()}: ${err?.message || err}`
                            );
                        }
                        this.removeInstitutionalPendingOpenOrder(state, orderId);
                    }
                }
                // o. [REAL] Reconciliar contadores com posições primárias da API (evita contador em 1 sem compra ao iniciar).
                if (this.managerDB) {
                    const { buyCount: primaryLongCount, sellCount: primaryShortCount } = this.getPrimaryOperationCounts(state);
                    try {
                        await this.managerDB.syncCountsToMatchPrimaries(primaryLongCount, primaryShortCount);
                        if (isReal && primaryLongCount === 0 && primaryShortCount === 0) {
                            this.logger.info('[SYNC API] Contadores reconciliados com 0 posições primárias (buy_count=0, sell_count=0).');
                        }
                    } catch (err: any) {
                        this.logger.warn(`[SYNC API] Falha ao reconciliar contadores com primárias: ${err?.message || err}`);
                    }
                }
            }
            if ((isDemo || (isReal && !hasReliableRealPositionsSnapshot)) && this.managerDB) {
                const { buyCount: primaryLongCount, sellCount: primaryShortCount } = this.getPrimaryOperationCounts(state);
                try {
                    await this.managerDB.syncCountsToMatchPrimaries(primaryLongCount, primaryShortCount);
                } catch (err: any) {
                    this.logger.warn(`[LOCAL SYNC] Falha ao reconciliar contadores com primárias locais: ${err?.message || err}`);
                }
            }
            this.accountMetrics = this.calculateAccountMargin(
                balances,
                state,
                config,
                tickerInfo,
                realBorrowedAmount,
                realTotalLiabilitiesAmount,
                realInterestRate,
                isDemo
            );

            // Em REAL, usar marginLevel da exchange como fonte de verdade para gates/alertas.
            if (isReal && Number.isFinite(realMarginLevel) && realMarginLevel > 0) {
                this.accountMetrics.marginLevel = realMarginLevel;
            }

            // Calcular risco de cada posicao
            this.positionRisks = this.calculatePositionsRisk(
                state,
                config,
                tickerInfo
            );

            // Validação: So monitorar saude se ha dados validos
            // Em modo DEMO sem posicoes, nao gerar alertas falsos
            const hasValidData = this.accountMetrics.totalAssetValue > 0 ||
                this.accountMetrics.borrowedValue > 0 ||
                state.activePositions.length > 0 ||
                (state.shortPositions && state.shortPositions.length > 0);

            let alerts: RiskAlert[] = [];
            if (hasValidData) {
                // Limiares de liquidação por margem (extouro) conforme preset.
                if (typeof (this.riskManager as any).setThresholds === 'function') {
                    (this.riskManager as any).setThresholds(
                        config.liquidationMarginLevelThreshold ?? 1.1,
                        config.defensiveCloseMarginLevelThreshold ?? 1.15
                    );
                }
                alerts = this.riskManager.monitorAccountHealth(
                    this.accountMetrics,
                    this.positionRisks
                );
            }

            if (alerts.length > 0) {
                await this.handleRiskAlerts(alerts, config, isDemo);
                const dailyInterestRateForRiskOff = this.resolveEffectiveCarryRate(config, realInterestRate);
                const demoRiskOffExecuted = await this.maybeExecuteDemoCriticalRiskOff(
                    alerts,
                    client,
                    config,
                    state,
                    currentPrice,
                    balances,
                    symbolInfo,
                    dailyInterestRateForRiskOff
                );
                if (demoRiskOffExecuted) {
                    return;
                }
            }

            // Hedge/RED: nao forcar fechamento total por margem critica aqui.
            // O encerramento segue o fluxo da estrategia (net target / RED / diluicoes).
            if (isFinite(this.accountMetrics.marginLevel) && this.accountMetrics.marginLevel > 0) {
                const enforceCriticalMarginGuard = isReal || (isDemo && demoMirrorRealRiskChecksInDemo);
                const decision = this.riskManager.evaluateCriticalMarginGuard({
                    marginLevel: this.accountMetrics.marginLevel,
                    criticalMarginLevel: CRITICAL_MARGIN_LEVEL,
                    enforce: enforceCriticalMarginGuard,
                    hasValidData,
                    isDemo,
                });
                if (decision.shouldLog) {
                    this.logger.warn(
                        `[${this.botName}] ${decision.message}, mas sem fechamento forçado automático no motor institucional.`
                    );
                }
            }

            // Crítico: Atualizar margin level no state e rastrear mínimo (risco de liquidação por margem).
            state.marginLevel = this.accountMetrics.marginLevel;
            if (Number.isFinite(this.accountMetrics.marginLevel) && this.accountMetrics.marginLevel > 0) {
                const ml = this.accountMetrics.marginLevel;
                state.minMarginLevel = Math.min(state.minMarginLevel ?? Number.POSITIVE_INFINITY, ml);
                if (state.liquidationMarginLevelThresholdUsed === undefined) {
                    state.liquidationMarginLevelThresholdUsed = Number(config.liquidationMarginLevelThreshold ?? 1.1);
                }
            }

            // Calcular EMA
            state.lastEMA = calculateEMA(currentPrice, state.lastEMA, config.emaPeriod);

            // Determinar acao (mesma logica que Spot)
            // Correção: Aguarda operacao assincrona e passa taxa de juros real
            const apiAvailableQuoteBalance = this.getAvailableBalanceByCoin(balances, config.pair2);
            const apiAccountEquity = this.accountMetrics
                ? (
                    Number.isFinite(Number(this.accountMetrics.marginRatio))
                        ? Math.max(0, Number(this.accountMetrics.totalAssetValue || 0) * (Number(this.accountMetrics.marginRatio || 0) / 100))
                        : Math.max(0, Number(this.accountMetrics.totalAssetValue || 0) - Number(this.accountMetrics.borrowedValue || 0))
                )
                : 0;
            const demoCapital = isDemo
                ? this.computeDemoCapitalSnapshot(
                    state,
                    config,
                    currentPrice,
                    Number(symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0.0006),
                    this.resolveEffectiveCarryRate(config, realInterestRate)
                )
                : null;
            const demoFallbackCapital = Math.max(
                0,
                Number(config.demoInitialBalance ?? config.bankroll ?? 1000) + Number(state.totalProfit || 0)
            );
            const availableQuoteBalance = isDemo
                ? (
                    Number.isFinite(Number(demoCapital?.availableQuote))
                        ? Math.max(0, Number(demoCapital?.availableQuote))
                        : demoFallbackCapital
                )
                : apiAvailableQuoteBalance;
            const accountEquity = isDemo
                ? (
                    Number.isFinite(Number(demoCapital?.equity))
                        ? Math.max(0, Number(demoCapital?.equity))
                        : demoFallbackCapital
                )
                : apiAccountEquity;

            const hasLocalRuntimePositions = state.activePositions.length > 0 || !!(state.shortPositions && state.shortPositions.length > 0);
            const liabilitySignalForGuard = Number(realTotalLiabilitiesAmount ?? realBorrowedAmount ?? 0);
            const hasUncertainRealExposure = isReal
                && (marginAccountInfo?.positionsSnapshotReliable === false)
                && !hasLocalRuntimePositions
                && Number.isFinite(liabilitySignalForGuard)
                && liabilitySignalForGuard > 1e-6;

            // O motor continua observando o mercado, mas autorização de execução é
            // independente do modo. Em especial, REAL incerto nunca cai em DEMO.
            const institutionalExecutionPolicy = resolveInstitutionalRuntimeExecutionPolicy({
                isReal,
                hasUncertainRealExposure,
            });
            const effectiveExecutionReal = institutionalExecutionPolicy.submitRealOrders;

            if (hasUncertainRealExposure) {
                const now = Date.now();
                const lastTs = Number(state._lastPositionSyncGuardLogAt || 0);
                if (!Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= 30000) {
                    this.logger.warn(
                        `[REAL][SYNC GUARD] Passivo detectado na conta (${liabilitySignalForGuard.toFixed(6)} ${config.pair2}) ` +
                        `sem snapshot confiavel de posicoes. Iteracao institucional suspensa sem ordens nem alteracoes locais de posicao/ledger.`
                    );
                    state._lastPositionSyncGuardLogAt = now;
                }
            }

            if (institutionalExecutionPolicy.guardActive) {
                this.logIndicators(config, state, currentIndicators, currentPrice, 'sync_guard');
                await this.recordPineSignals(state, currentPrice, currentTime);
                return;
            }

            // Unified decision path for DEMO and REAL. Position mutations and exchange
            // orders are suppressed while the REAL snapshot guard is active.
            const modeLabel = isReal ? 'REAL' : 'DEMO';
            this.logger.info(
                `[INSTITUTIONAL_HYBRID] Modo ${modeLabel} | trade_mode alinhado para ${isReal ? 'operacoes reais na corretora' : 'simulacao local (sem envio de ordens a API, fills causais entre polls; OHLC reservado a risco)'}`
            );
            try {
                let persistedState = (state as any)._institutionalHybridState as Record<string, unknown> | null | undefined;
                persistedState = alignInstitutionalPersistedStateToRuntimeLedger({
                    persistedState,
                    authoritativeTotalRealizedNet: Number(state.totalProfit || 0),
                });
                const institutionalBalances = isDemo
                    ? {
                        ...balances,
                        equity: accountEquity,
                        balance: accountEquity,
                        availableQuote: availableQuoteBalance,
                        available: availableQuoteBalance,
                        free: availableQuoteBalance,
                        freeMargin: availableQuoteBalance,
                        usedMargin: Math.max(0, Number(demoCapital?.lockedMargin ?? 0)),
                        margin: Math.max(0, Number(demoCapital?.lockedMargin ?? 0)),
                        marginLevel: Number(state.marginLevel || this.accountMetrics?.marginLevel || 100),
                    }
                    : balances;
                const priorInstitutionalState = persistedState && typeof persistedState === 'object'
                    ? { ...persistedState }
                    : {};
                const decisions = await runInstitutionalHybridIteration(
                    config,
                    { activePositions: state.activePositions, shortPositions: state.shortPositions ?? [] },
                    institutionalBalances,
                    symbolInfo,
                    tickerInfo,
                    currentIndicators,
                    persistedState ?? null,
                    { executionMode: institutionalExecutionPolicy.engineExecutionMode }
                );
                const operationalSizingSnapshot: any = decisions.updatedPersistedState?.operationalSizingSnapshot;
                if (operationalSizingSnapshot && typeof operationalSizingSnapshot === 'object') {
                    const sizingSignature = [
                        Number(operationalSizingSnapshot.amountUsd ?? 0).toFixed(8),
                        Number(operationalSizingSnapshot.baseNotionalPerOrder ?? 0).toFixed(8),
                        Number(operationalSizingSnapshot.lockedAtTs ?? 0),
                    ].join('|');
                    if ((state as any)._lastInstitutionalSizingSignature !== sizingSignature) {
                        (state as any)._lastInstitutionalSizingSignature = sizingSignature;
                        this.logger.info(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] sizing amount=${Number(operationalSizingSnapshot.amountUsd ?? 0)} ` +
                            `baseNotional=${Number(operationalSizingSnapshot.baseNotionalPerOrder ?? 0)} ` +
                            `rule=${String(operationalSizingSnapshot.ruleSource ?? 'configured_amount_only')} ` +
                            `equity=${Number(operationalSizingSnapshot.equityUsd ?? 0).toFixed(2)} ` +
                            `available=${Number(operationalSizingSnapshot.availableQuoteUsd ?? 0).toFixed(2)}`
                        );
                    }
                }

                const closePlans: Array<{
                    positionId: string;
                    side: 'long' | 'short';
                    quantity: number;
                    price: number;
                    reason: string;
                    forceLoss: boolean;
                    cycleNetApproved?: boolean;
                    cycleCloseId?: string;
                    executionMode?: 'maker' | 'taker' | 'market';
                }> = Array.isArray(decisions.closePlans) && decisions.closePlans.length > 0
                    ? decisions.closePlans
                    : (decisions.positionIdsToClose || []).map((positionId) => ({
                        positionId,
                        side: 'long' as const,
                        quantity: Number.NaN,
                        price: currentPrice,
                        reason: 'institutional_hybrid_signal',
                        forceLoss: false,
                        cycleNetApproved: false,
                        executionMode: undefined,
                    }));
                const lossCapClosePlan = this.buildInstitutionalLossCapClosePlan(
                    config,
                    state,
                    symbolInfo,
                    currentPrice,
                    this.resolveEffectiveCarryRate(config, realInterestRate),
                    currentTime
                );
                if (
                    lossCapClosePlan
                    && !closePlans.some((plan) => String(plan?.positionId || '').trim() === lossCapClosePlan.positionId)
                ) {
                    closePlans.unshift(lossCapClosePlan);
                }

                const lossCapSideForTick = closePlans.find((plan) => (
                    String(plan?.reason || '').trim().toLowerCase() === 'max_unrealized_loss_cap'
                ))?.side;
                if (lossCapSideForTick) {
                    decisions.positionsToOpen = decisions.positionsToOpen.filter((positionPlan) => (
                        positionPlan.isHedge || positionPlan.side !== lossCapSideForTick
                    ));
                    decisions.desiredQuotes = decisions.desiredQuotes.filter((quote) => {
                        if (quote.reduceOnly || quote.isHedge) return true;
                        const quoteSide = quote.side === 'ask' ? 'short' : 'long';
                        return quoteSide !== lossCapSideForTick;
                    });
                }

                const confirmedClosePositionIds = new Set<string>();
                for (const closePlan of institutionalExecutionPolicy.allowPositionMutations ? closePlans : []) {
                    const closePrice = Number(closePlan.price || currentPrice);
                    const runtimePos = this.findRuntimePositionById(state, closePlan.positionId);
                    const closeQty = Number.isFinite(Number(closePlan.quantity))
                        ? Number(closePlan.quantity)
                        : (runtimePos ? Number((runtimePos.position as any).quantity ?? 0) : 0);
                    const cycleNetApprovedForClose = !!closePlan.cycleNetApproved
                        && !String(closePlan.reason || '').toLowerCase().includes('reserve_settle');
                    if (!cycleNetApprovedForClose && this.shouldSkipFallbackCloseBecauseNegativeAndTooEarly(
                        config,
                        state,
                        closePlan.positionId,
                        closePrice,
                        closeQty,
                        String(closePlan.reason || 'institutional_hybrid_signal'),
                        !!closePlan.forceLoss,
                        symbolInfo,
                        realInterestRate
                    )) {
                        this.logger.info(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Fechamento nao solicitado (gatilho um passo antes): ` +
                            `positionId=${closePlan.positionId} reason=${String(closePlan.reason)} ` +
                            `net projetado negativo e posicao com menos de ${config.minHoldMinutesBeforeNegativeClose ?? 0} min; mantendo exposta para tentar reversao.`
                        );
                        continue;
                    }
                    const closeResult = await this.closeSinglePositionById(
                        client,
                        config,
                        state,
                        balances,
                        symbolInfo,
                        closePrice,
                        realInterestRate,
                        !effectiveExecutionReal,
                        effectiveExecutionReal,
                        closePlan.positionId,
                        String(closePlan.reason || 'institutional_hybrid_signal'),
                        !!closePlan.forceLoss,
                        Number.isFinite(Number(closePlan.quantity)) ? Number(closePlan.quantity) : undefined,
                        closePlan.executionMode,
                        cycleNetApprovedForClose,
                        closePlan.cycleCloseId
                    );
                    if (closeResult.status === 'closed' || closeResult.status === 'pending') {
                        confirmedClosePositionIds.add(closePlan.positionId);
                        if (String(closePlan.reason || '').toLowerCase() === 'max_unrealized_loss_cap') {
                            markLossCapClose(state, currentTime, closePlan.side);
                        }
                    }
                    if (closeResult.status === 'closed') {
                        this.logger.info(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Posicao ${closePlan.positionId} fechada e confirmada por sinal do motor ` +
                            `reason=${String(closePlan.reason || 'institutional_hybrid_signal')} ` +
                            `exec=${String(closePlan.executionMode || 'runtime')}`
                        );
                    } else if (closeResult.status === 'pending') {
                        this.logger.info(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Fechamento solicitado e pendente para posicao ${closePlan.positionId} ` +
                            `reason=${String(closePlan.reason || 'institutional_hybrid_signal')} ` +
                            `exec=${String(closePlan.executionMode || 'runtime')} orderId=${closeResult.orderId || 'n/a'}`
                        );
                    } else {
                        this.logger.warn(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Fechamento bloqueado/ignorado para posicao ${closePlan.positionId} ` +
                            `reason=${String(closePlan.reason || 'institutional_hybrid_signal')} ` +
                            `exec=${String(closePlan.executionMode || 'runtime')}`
                        );
                    }
                }

                const changedMarginUpdates = institutionalExecutionPolicy.allowPositionMutations
                    ? filterChangedInstitutionalMarginUpdates(
                        state,
                        decisions.positionMarginUpdates ?? []
                    )
                    : [];
                const marginUpdatesApplied = applyPositionMarginUpdatesToState(state, changedMarginUpdates);
                const criticalMarginInjectionCount = decisions.criticalMarginInjections?.length ?? 0;
                if (marginUpdatesApplied > 0 || criticalMarginInjectionCount > 0) {
                    const marginEventLabel = criticalMarginInjectionCount > 0
                        ? 'critical_margin_injection'
                        : 'position_margin_sync';
                    this.logger.info(
                        `[INSTITUTIONAL_HYBRID] [${modeLabel}] ${marginEventLabel} ` +
                        `updatesApplied=${marginUpdatesApplied} ` +
                        `injectEvents=${criticalMarginInjectionCount} ` +
                        `injectUsd=${(decisions.criticalMarginInjections ?? []).reduce((sum, row) => sum + Number(row.injectMarginUsd || 0), 0).toFixed(4)}`
                    );
                }

                const openedPlanMetaKeys = new Set<string>();
                for (const positionPlan of institutionalExecutionPolicy.allowPositionMutations ? decisions.positionsToOpen : []) {
                    const planMetaKey = this.buildInstitutionalOpenMetaDedupKey(positionPlan);
                    if (planMetaKey && openedPlanMetaKeys.has(planMetaKey)) {
                        this.logger.warn(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Duplicata de abertura agressiva ignorada ` +
                            `side=${positionPlan.side} qty=${Number(positionPlan.quantity).toFixed(8)} ` +
                            `take=${Number(positionPlan.takePrice || 0).toFixed(4)} reason=${positionPlan.reason}`
                        );
                        continue;
                    }
                    if (planMetaKey) openedPlanMetaKeys.add(planMetaKey);

                    const opened = await this.openSingleInstitutionalPosition(
                        client,
                        config,
                        state,
                        balances,
                        symbolInfo,
                        currentPrice,
                        realInterestRate,
                        !effectiveExecutionReal,
                        effectiveExecutionReal,
                        positionPlan
                    );
                    if (opened) {
                        this.logger.info(
                            `[INSTITUTIONAL_HYBRID] [${modeLabel}] Posicao ${positionPlan.side.toUpperCase()} aberta por sinal do motor ` +
                            `price=${Number(positionPlan.price).toFixed(4)} qty=${Number(positionPlan.quantity).toFixed(8)} ` +
                            `reason=${positionPlan.reason} exec=${effectiveExecutionReal ? 'real' : 'simulada'}`
                        );
                    }
                }

                const runtimePositionIdsAfter = collectRuntimePositionIds(state);
                const reconciledInstitutionalState = alignInstitutionalPersistedStateToRuntimeLedger({
                    persistedState: institutionalExecutionPolicy.guardActive
                        ? priorInstitutionalState
                        : reconcileInstitutionalPersistedStateAfterLive({
                            priorState: priorInstitutionalState,
                            proposedState: decisions.updatedPersistedState,
                            closeAccounting: decisions.ledgerCloseAccounting ?? [],
                            confirmedClosePositionIds,
                            runtimePositionIdsAfter,
                        }),
                    authoritativeTotalRealizedNet: Number(state.totalProfit || 0),
                });
                if (Number(state.lastMaxUnrealizedLossCapCloseTs || 0) > 0) {
                    (reconciledInstitutionalState as any).lastMaxUnrealizedLossCapCloseTs = Number(
                        state.lastMaxUnrealizedLossCapCloseTs
                    );
                    (reconciledInstitutionalState as any).lastMaxUnrealizedLossCapCloseSide = (
                        state.lastMaxUnrealizedLossCapCloseSide
                    );
                }
                (state as any)._institutionalHybridState = {
                    ...reconciledInstitutionalState,
                };

                const institutionalDiagState: any = institutionalExecutionPolicy.guardActive
                    ? decisions.updatedPersistedState
                    : (reconciledInstitutionalState ?? (state as any)._institutionalHybridState ?? {});
                const quoteZeroReason = String(institutionalDiagState?.lastBuildQuotesZeroReason ?? '');
                const quoteZeroDetails = quoteZeroReason && institutionalDiagState?.lastBuildQuotesZeroDetails
                    ? ` quoteZeroDetails=${JSON.stringify(institutionalDiagState.lastBuildQuotesZeroDetails).slice(0, 420)}`
                    : '';
                const positionsToOpenRawCount = Number(reconciledInstitutionalState?._adapterRawPositionsToOpenCount ?? decisions.positionsToOpen.length);
                const desiredQuotesRawCount = Number(reconciledInstitutionalState?._adapterRawDesiredQuotesCount ?? decisions.desiredQuotes.length);
                const openRejectReason = resolveInstitutionalOpenRejectLabel(institutionalDiagState);
                const notionalDiag = buildInstitutionalNotionalDiagSuffix(
                    institutionalDiagState,
                    positionsToOpenRawCount,
                    desiredQuotesRawCount
                );
                this.logger.info(
                    `[INSTITUTIONAL_HYBRID] [${modeLabel}] regime=${reconciledInstitutionalState?.regime ?? '?'} ` +
                    `robotState=${reconciledInstitutionalState?.robotState ?? '?'} ` +
                    `operationalRegime=${reconciledInstitutionalState?.operationalRegime ?? '?'} ` +
                    `operationalMotor=${reconciledInstitutionalState?.operationalMotor ?? '?'} ` +
                    `operationalPause=${reconciledInstitutionalState?.operationalPauseReason ?? '?'} ` +
                    `desiredQuotes=${decisions.desiredQuotes.length} ` +
                    `desiredQuotesRaw=${desiredQuotesRawCount} ` +
                    `desiredQuotesFiltered=${Number(reconciledInstitutionalState?._adapterFilteredDesiredQuotesCount ?? decisions.desiredQuotes.length)} ` +
                    `positionsToOpen=${decisions.positionsToOpen.length} ` +
                    `positionsToOpenRaw=${positionsToOpenRawCount} ` +
                    `positionsToOpenFiltered=${Number(reconciledInstitutionalState?._adapterFilteredPositionsToOpenCount ?? decisions.positionsToOpen.length)} ` +
                    `quoteZeroReason=${quoteZeroReason} ` +
                    `openRejectReason=${openRejectReason} ` +
                    `exchangeRejectReason=${String(institutionalDiagState?.lastInstitutionalQuoteRejectReason ?? '')}` +
                    notionalDiag +
                    quoteZeroDetails
                );
                if (institutionalExecutionPolicy.syncRealQuotes) {
                    await this.syncDesiredQuotes(
                        client,
                        config,
                        state,
                        symbolInfo,
                        decisions.desiredQuotes,
                        true,
                        balances
                    );
                }
            } catch (err: any) {
                this.logger.error(`[INSTITUTIONAL_HYBRID] Erro no motor: ${err?.message ?? err}`);
                throw err;
            }

            this.logIndicators(config, state, currentIndicators, currentPrice, 'loading');
            await this.recordPineSignals(state, currentPrice, currentTime);
            return;

        } catch (error) {
            this.logger.error(`[${this.botName}] Erro na execucao:`, error);
            throw error;
        }
    }

    private createGridSideState(): GridSideState {
        return {
            active: false,
            martingaleCount: 0,
            lastGridPrice: 0,
            lastQuantity: 0,
            cycleStartBalance: 0,
            cycleStartTime: 0,
            redActive: false,
            redActivatedTime: 0,
            redInitialDrawdown: 0,
            redWorstDrawdown: 0,
            redRecoveryConsumed: 0,
            redLastDrawdown: 0,
            redHedgeSide: null,
            redLastReductionTime: 0,
            redDilutionRefPrice: 0,
            redHedgeBaseQty: 0,
            redDilutionCreditUsd: 0,
            redHedgeProfitBufferUsd: 0,
            redLateralReinforceCount: 0,
            redLateralReinforceQty: 0,
            redLateralReinforceBudgetUsd: 0,
            redRebuildPhaseActive: false,
            redRebuildCount: 0,
            redTotalSettlementLastSkipTs: 0,
            redTotalSettlementLastSkipReason: '',
            redMg2CloseAnchorTs: 0,
            redReversalConfirmCount: 0,
            redHedgeOnlyRefPrice: 0,
            redHedgeOnlyPocketSteps: 0,
            redHedgeOnlyBreakevenArmed: false,
            redHedgeOnlyPeakPrice: 0,
            redProfitReinforceCount: 0,
            redProfitReinforceQty: 0,
            redProfitReinforceBudgetUsd: 0,
            // ATR-based dilution bookkeeping
            atrLastReferencePrice: 0,
            atrLastDilutionTime: 0,
            // Manual dilution bookkeeping
            pendingReductionRemainder: 0,
            redBalanceSnapshot: 0
        };
    }

    private ensureGridState(state: MarketKingTradingState): GridState {
        if (!state.gridState) {
            state.gridState = {
                long: this.createGridSideState(),
                short: this.createGridSideState(),
                globalReserveUsd: 0,
            };
        }
        if (!state.gridState.long) state.gridState.long = this.createGridSideState();
        if (!state.gridState.short) state.gridState.short = this.createGridSideState();
        state.gridState.globalReserveUsd = Math.max(0, Number(state.gridState.globalReserveUsd || 0));
        return state.gridState;
    }

    private getEntryPrice(side: 'long' | 'short', pos: MarketKingLongPosition | MarketKingShortPosition): number {
        return getGridEntryPrice(side, pos);
    }

    private getOpenTime(pos: MarketKingLongPosition | MarketKingShortPosition): number {
        return getGridOpenTime(pos);
    }

    private getParentSide(
        pos: MarketKingLongPosition | MarketKingShortPosition,
        fallback: 'long' | 'short'
    ): 'long' | 'short' {
        return getGridParentSide(pos, fallback);
    }

    private isGridHedgePosition(pos: any): boolean {
        return !!(pos && ((pos as any).gridIsHedge ?? (pos as any).qgHedge));
    }

    private getGridOpenReason(pos: any): string {
        return String((pos && ((pos as any).gridOpenReason ?? (pos as any).qgOpenReason ?? (pos as any).qgReason ?? (pos as any).reason)) || '');
    }

    private calculatePositionNet(
        side: 'long' | 'short',
        pos: MarketKingLongPosition | MarketKingShortPosition,
        currentPrice: number,
        takerFeeRate: number,
        slippagePercent: number,
        slippageModel: 'one_sided' | 'two_sided',
        dailyInterestRate: number,
        quantityOverride?: number,
        config?: MarketKingTradingConfig,
        symbolInfo?: any,
        executionCostProfile?: {
            openExecutionMode?: 'maker' | 'taker' | 'market';
            closeExecutionMode?: 'maker' | 'taker' | 'market';
            openFeeRate?: number;
            closeFeeRate?: number;
            openSlippagePercent?: number;
            closeSlippagePercent?: number;
        }
    ): { net: number; quantity: number } {
        const base = calcPositionNet(
            side,
            pos,
            currentPrice,
            takerFeeRate,
            slippagePercent,
            slippageModel,
            dailyInterestRate,
            quantityOverride,
            (s: 'long' | 'short', p: any) => this.getEntryPrice(s, p),
            (p: any) => this.getOpenTime(p)
        );
        if (!(base.quantity > 0)) return base;
        let netAdjusted = Number(base.net || 0);
        if (executionCostProfile) {
            const entry = Math.max(0, Number(this.getEntryPrice(side, pos)));
            const exit = Math.max(0, Number(currentPrice || 0));
            const qty = Math.max(0, Number(base.quantity || 0));
            if (entry > 0 && exit > 0 && qty > 0) {
                const assumedOpenFee = entry * qty * Math.max(0, Number(takerFeeRate || 0));
                const assumedCloseFee = exit * qty * Math.max(0, Number(takerFeeRate || 0));
                const assumedSlipOpen = Math.max(0, entry * qty * Math.max(0, Number(slippagePercent || 0)));
                const assumedSlipClose = Math.max(0, exit * qty * Math.max(0, Number(slippagePercent || 0)));
                const assumedSlippage = slippageModel === 'two_sided' ? (assumedSlipOpen + assumedSlipClose) : assumedSlipClose;

                const desiredOpenFeeRate = Math.max(0, Number(executionCostProfile.openFeeRate ?? takerFeeRate ?? 0));
                const desiredCloseFeeRate = Math.max(0, Number(executionCostProfile.closeFeeRate ?? takerFeeRate ?? 0));
                const desiredOpenSlipPct = Math.max(0, Number(
                    executionCostProfile.openSlippagePercent ??
                    (slippageModel === 'two_sided' ? slippagePercent : 0)
                ));
                const desiredCloseSlipPct = Math.max(0, Number(executionCostProfile.closeSlippagePercent ?? slippagePercent ?? 0));
                const desiredOpenFee = entry * qty * desiredOpenFeeRate;
                const desiredCloseFee = exit * qty * desiredCloseFeeRate;
                const desiredSlippage = (entry * qty * desiredOpenSlipPct) + (exit * qty * desiredCloseSlipPct);

                netAdjusted += (assumedOpenFee + assumedCloseFee + assumedSlippage) - (desiredOpenFee + desiredCloseFee + desiredSlippage);
            }
        }
        if (!config) return { ...base, net: netAdjusted };
        const fundingCost = this.estimateFundingCost(
            config,
            side,
            pos,
            currentPrice,
            base.quantity,
            symbolInfo
        );
        if (!Number.isFinite(fundingCost) || fundingCost === 0) {
            return {
                ...base,
                net: netAdjusted
            };
        }
        return {
            ...base,
            net: netAdjusted - fundingCost
        };
    }

    private async executeGridIteration(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        balances: any,
        symbolInfo: any,
        currentIndicators: any,
        currentPrice: number,
        dailyInterestRate: number,
        _availableQuoteBalance: number,
        accountEquity: number,
        isDemo: boolean,
        isReal: boolean
    ): Promise<void> {
        const price = Number(currentPrice);
        if (!Number.isFinite(price) || price <= 0) return;

        const maPeriod = Math.max(1, Number(config.maPeriod ?? config.emaPeriod ?? 20));
        state.lastEMA = calculateEMA(price, state.lastEMA, maPeriod);
        const ema = Number(currentIndicators?.ema ?? state.lastEMA);
        if (!Number.isFinite(ema) || ema <= 0) return;

        state.activePositions = Array.isArray(state.activePositions) ? state.activePositions : [];
        state.shortPositions = Array.isArray(state.shortPositions) ? state.shortPositions : [];
        state.buyPrices = Array.isArray(state.buyPrices) ? state.buyPrices : [];

        // Auto-recovery: if counters are saturated/desynced with no open positions,
        // reset them here as o fluxo de grid bypassa directOrder's recovery path.
        if (this.managerDB) {
            try {
                const hasOpenLongPositions = state.buyPrices.length > 0 || state.activePositions.length > 0;
                const hasOpenShortPositions = !!(state.shortPositions && state.shortPositions.length > 0);
                if (!hasOpenLongPositions && !hasOpenShortPositions) {
                    const snapshot = await this.managerDB.getCountsSnapshot();
                    const buyCount = Number(snapshot.buyCount || 0);
                    const sellCount = Number(snapshot.sellCount || 0);
                    if (buyCount !== 0 || sellCount !== 0) {
                        this.logger.warn(
                            `[MARKETKING] Contadores desincronizados sem posicoes abertas. ` +
                            `Buy: ${buyCount} | Sell: ${sellCount}. Reconciliando para 0/0.`
                        );
                        await this.managerDB.syncCountsToMatchPrimaries(0, 0);
                        const canBuyAfterReset = await this.canBuySafe();
                        const canSellAfterReset = await this.canSellSafe();
                        this.logger.info(
                            `[MARKETKING] Contadores reconciliados | canBuy: ${canBuyAfterReset} | canSell: ${canSellAfterReset}`
                        );
                        state._cycleReset = true;
                    }
                }
            } catch (error: any) {
                this.logger.warn(
                    `[MARKETKING] Falha no auto-recovery dos contadores: ${error?.message || error}`
                );
            }
        }

        const gridState = this.ensureGridState(state);
        const nowTs = Date.now();
        const takerFeeRate = Math.max(0, Number(symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0.0006));
        const makerFeeRate = Math.max(0, Number(symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? (this.isFuturePerpetualTrade(config) ? 0.0002 : 0)));
        const slippagePercent = Math.max(0, Number(config.slippagePercent ?? 0.00015));
        const slippageModel: 'one_sided' | 'two_sided' =
            String(config.slippageModel ?? 'two_sided').toLowerCase() === 'one_sided' ? 'one_sided' : 'two_sided';
        const effectiveDailyRate = this.resolveEffectiveCarryRate(config, dailyInterestRate);
        const effectiveFundingRatePer8h = this.resolveFundingRatePer8h(config, symbolInfo);
        const effectiveFundingMode = this.resolveFundingFeeMode(config);
        const effectiveFundingIntervalHours = this.resolveFundingIntervalHours(config, symbolInfo);
        const runtimeOpenExecutionMode = this.resolveOpenExecutionMode(config);
        const runtimeCloseExecutionMode = this.resolveCloseExecutionMode(config);
        const runtimeOpenFeeRate = this.resolveFeeRateForMode(runtimeOpenExecutionMode, takerFeeRate, makerFeeRate);
        const runtimeCloseFeeRate = this.resolveFeeRateForMode(runtimeCloseExecutionMode, takerFeeRate, makerFeeRate);
        const runtimeOpenSlippagePercent = this.resolveSlippageForMode(config, runtimeOpenExecutionMode, slippagePercent);
        const runtimeCloseSlippagePercent = this.resolveSlippageForMode(config, runtimeCloseExecutionMode, slippagePercent);
        const gridExecutionCostProfile = {
            openExecutionMode: runtimeOpenExecutionMode,
            closeExecutionMode: runtimeCloseExecutionMode,
            openFeeRate: runtimeOpenFeeRate,
            closeFeeRate: runtimeCloseFeeRate,
            openSlippagePercent: runtimeOpenSlippagePercent,
            closeSlippagePercent: runtimeCloseSlippagePercent
        };

        const configuredStepPercentRaw = Number(
            (config as any).martingaleStepPercent
            ?? (config as any).martingaleStepPct
            ?? 0.5
        );
        const configuredStepPercent = Number.isFinite(configuredStepPercentRaw) && configuredStepPercentRaw > 0
            ? configuredStepPercentRaw
            : 0.5;
        const leakMinAdversePercentFromBase = Math.max(0, Number(
            (config as any).leakMinAdversePercentFromBase
            ?? (config as any).leak_min_adverse_percent_from_base
            ?? 0
        ));
        const leakMinAdversePointsFromBase = Math.max(0, Number((config as any).leakMinAdversePointsFromBase ?? 0));
        const maxAdversePercentFromBase = Math.max(0, Number(
            (config as any).maxAdversePercentFromBase
            ?? (config as any).max_adverse_percent_from_base
            ?? 0
        ));
        const maxAdversePointsFromBase = Math.max(0, Number((config as any).maxAdversePointsFromBase ?? 0));
        const resolveAdversePointPriceStep = (): number => {
            const fromConfig = Number((config as any).adversePointsPriceTick ?? 0);
            if (fromConfig > 0) return fromConfig;
            const direct = Math.max(0, Number((symbolInfo as any)?.point ?? (symbolInfo as any)?.tickSize ?? (config as any)?.priceStep ?? 0));
            if (direct > 0) return direct;
            const pp = Number((symbolInfo as any)?.pricePrecision ?? (config as any)?.pricePrecision ?? NaN);
            if (Number.isFinite(pp) && pp >= 0 && pp <= 12) return Math.pow(10, -pp);
            return 0.01;
        };
        // Interpreta martingaleMaxLevels como niveis totais incluindo a ordem base.
        // Ex.: 5 => base + 4 martingales; a proxima oportunidade adversa ativa RED.
        // No modo institucional_hybrid o núcleo não usa martingale.
        const martingaleDefaultLevels = this.isInstitutionalHybridStrategy(config) ? 1 : 5;
        const martingaleMaxLevelsTotal = Math.max(1, Number(config.martingaleMaxLevels ?? martingaleDefaultLevels));
        const martingaleFactorLevelsRawInput = Array.isArray((config as any).martingaleFactorLevels)
            ? (config as any).martingaleFactorLevels
                .map((v: any) => Number(v))
                .filter((v: number) => Number.isFinite(v) && v > 0)
            : [];
        const martingaleFactorLevelsRaw = martingaleFactorLevelsRawInput.length > 0
            ? martingaleFactorLevelsRawInput
            : [50, 35, 25, 20];
        const maxMartingaleAddsBeforeRed = Math.max(0, martingaleMaxLevelsTotal - 1);
        const resolveMartingaleFactorForLevel = (levelIndex: number): number => {
            const idx = Math.max(0, Math.min(levelIndex, martingaleFactorLevelsRaw.length - 1));
            const raw = Number(martingaleFactorLevelsRaw[idx]);
            // Accept either percentage points (50 = +50%) or decimal (+0.5 = +50%).
            const increment = raw > 2 ? (raw / 100) : raw;
            return Math.max(1, 1 + increment);
        };
        const gridTakeProfitPercentRaw = Number(config.gridTakeProfitUsd);
        const gridTakeProfitPercent = Number.isFinite(gridTakeProfitPercentRaw) && gridTakeProfitPercentRaw > 0
            ? gridTakeProfitPercentRaw
            : 0.08;
        // Calculate minCompensationNetUsd (motor institucional).
        const amountCfgUsdt = Number(config.amount || 0);
        const leverage = Math.max(1, Number(config.leverage || 1));
        const notionalBase = config.amountSizingMode === 'margin_base'
            ? (amountCfgUsdt * leverage)
            : amountCfgUsdt;
        const minCompensationNetUsd = Math.max(0, Number(
            config.minCompensationNetUsd ??
            Math.max(0.5, notionalBase * 0.003)
        ));
        // Determine runtimeStrictMode: respect config if provided, otherwise default to true for grid/institutional_hybrid.
        const legacyGridStrategyToken = `${LEGACY_GRID_PROFILE_PREFIX}_grid`;
        const strategyMode = String(config.strategyMode || config.strategy || 'marketking_grid').trim().toLowerCase();
        const isInstitutionalHybrid = strategyMode === 'institutional_hybrid' || strategyMode === 'institutional' || strategyMode === 'hybrid';
        const isMarketKingGrid = !isInstitutionalHybrid && (strategyMode === 'marketking_grid' || strategyMode === legacyGridStrategyToken);
        const runtimeStrictMode = (config.runtimeStrictMode !== undefined)
            ? !!config.runtimeStrictMode
            : (isMarketKingGrid || isInstitutionalHybrid);
        const redHedgeProfitReinforceUseFactorRaw = Number(
            (config as any).redHedgeProfitReinforceUseFactor
            ?? (config as any).redHedgeProfitUseFactor
            ?? (config as any).redReinforceProfitUseFactor
            ?? 1
        );
        const redHedgeProfitReinforceUseFactor = Math.max(
            0,
            Math.min(
                1,
                Number.isFinite(redHedgeProfitReinforceUseFactorRaw) ? redHedgeProfitReinforceUseFactorRaw : 1
            )
        );
        const maxHoldingMs = 0;
        const prolongedExposureMs = 0;
        const prolongedExposureCycleReset = false;
        const prolongedExposureMinCycleNetUsd = 0;
        const holdingCloseMinNetUsd = 0;
        const minOrderQty = Math.max(0, Number(config.minOrderQty ?? (symbolInfo?.minOrderQty ?? symbolInfo?.minQty ?? 0)));
        const quantityStep = Math.max(0, Number(config.quantityStep ?? (symbolInfo?.quantityStep ?? symbolInfo?.qtyStep ?? symbolInfo?.sizeMultiplier ?? 0)));
        const demoMirrorRealRiskChecksInDemo = config.demoMirrorRealRiskChecksInDemo !== undefined
            ? !!config.demoMirrorRealRiskChecksInDemo
            : true;
        const minEquityForNewEntries = Math.max(0, Number(config.minEquityForNewEntries ?? 1));
        const minAvailableUsdtForNewEntries = Math.max(0, Number(config.minAvailableUsdtForNewEntries ?? 1));
        const normalizedGridAvailableQuote = Number.isFinite(Number(_availableQuoteBalance))
            ? Math.max(0, Number(_availableQuoteBalance))
            : 0;
        const normalizedGridEquity = Number.isFinite(Number(accountEquity))
            ? Math.max(0, Number(accountEquity))
            : 0;
        const marginLevelNowGrid = Number(state.marginLevel || this.accountMetrics?.marginLevel || 0);
        const hasValidMarginLevelGrid = Number.isFinite(marginLevelNowGrid) && marginLevelNowGrid > 0;
        const hasCapitalForNewEntriesGrid = (normalizedGridEquity > minEquityForNewEntries)
            && (normalizedGridAvailableQuote >= minAvailableUsdtForNewEntries);
        const enforceEntryRiskGatesGrid = isReal || (isDemo && demoMirrorRealRiskChecksInDemo);
        const entryGate = this.riskManager.evaluateEntryMarginGate({
            marginLevel: marginLevelNowGrid,
            minEntryMarginLevel: (config as any).minEntryMarginLevel,
            hasValidMarginLevel: hasValidMarginLevelGrid,
            normalizedEquityUsd: normalizedGridEquity,
            normalizedAvailableQuoteUsd: normalizedGridAvailableQuote,
            minEquityForNewEntries,
            minAvailableUsdtForNewEntries,
            enforceEntryRiskGates: enforceEntryRiskGatesGrid,
        });
        const allowByMarginLevelGrid = entryGate.allowByMarginLevel;
        const allowNewEntriesByMarginGrid = !enforceEntryRiskGatesGrid
            || (allowByMarginLevelGrid && hasCapitalForNewEntriesGrid);

        const normalizeOrderQtySafe = (qtyRaw: number, totalQty?: number): number => {
            return normalizeOrderQty(qtyRaw, totalQty, quantityStep, minOrderQty);
        };
        const runtimeAuditLogIntervalMs = Math.max(15000, Number(config.runtimeAuditLogIntervalMs ?? config.decisionVerboseIntervalMs ?? 30000));
        const runtimeStatusLogIntervalMs = Math.max(10000, Number(config.runtimeStatusLogIntervalMs ?? config.decisionVerboseIntervalMs ?? 30000));
        const shouldLogGridAudit = (
            field:
                | '_lastGridTpAuditLongLogAt'
                | '_lastGridTpAuditShortLogAt'
                | '_lastGridRedAuditLongLogAt'
                | '_lastGridRedAuditShortLogAt'
        ): boolean => {
            const now = Date.now();
            const lastTs = Number((state as any)[field] || 0);
            const normalizedLastTs = Number.isFinite(lastTs) && lastTs > 0 && lastTs <= (now + 60000)
                ? lastTs
                : 0;
            if (normalizedLastTs > 0 && (now - normalizedLastTs) < runtimeAuditLogIntervalMs) {
                return false;
            }
            (state as any)[field] = now;
            return true;
        };
        const gridQtyBlockLogIntervalMs = Math.max(5000, Number(config.decisionVerboseIntervalMs ?? 30000));
        const shouldLogGridQtyBlocked = (): boolean => {
            const now = Date.now();
            const lastTs = Number(state._lastGridQtyZeroLogAt || 0);
            const normalizedLastTs = Number.isFinite(lastTs) && lastTs > 0 && lastTs <= (now + 60000)
                ? lastTs
                : 0;
            if (normalizedLastTs > 0 && (now - normalizedLastTs) < gridQtyBlockLogIntervalMs) {
                return false;
            }
            state._lastGridQtyZeroLogAt = now;
            return true;
        };
        const logGridQtyBlocked = (
            side: 'long' | 'short',
            reason: string,
            qtyRaw: number,
            qtyNormalized: number
        ): void => {
            if (!shouldLogGridQtyBlocked()) return;
            const amountCfgUsdt = Number(config.amount || 0);
            const impliedNotionalRaw = Math.max(0, Number(qtyRaw || 0) * price);
            this.logger.warn(
                `[MARKETKING] ${side.toUpperCase()} bloqueado (${reason}): ` +
                `qtyRaw=${Number(qtyRaw || 0).toFixed(8)} qtyNorm=${Number(qtyNormalized || 0).toFixed(8)} ` +
                `amountCfg=${amountCfgUsdt.toFixed(8)} price=${price.toFixed(6)} ` +
                `minOrderQty=${minOrderQty.toFixed(8)} quantityStep=${quantityStep.toFixed(8)} ` +
                `notionalRaw=${impliedNotionalRaw.toFixed(6)}`
            );
        };

        const cycleSideState = (side: 'long' | 'short'): GridSideState => gridState[side];
        const getCyclePositions = (parentSide: 'long' | 'short'): Array<{ side: 'long' | 'short'; pos: MarketKingLongPosition | MarketKingShortPosition }> => {
            const out: Array<{ side: 'long' | 'short'; pos: MarketKingLongPosition | MarketKingShortPosition }> = [];
            for (const p of state.activePositions) {
                const mapped = this.getParentSide(p, 'long');
                if (mapped === parentSide) out.push({ side: 'long', pos: p });
            }
            for (const p of state.shortPositions || []) {
                const mapped = this.getParentSide(p, 'short');
                if (mapped === parentSide) out.push({ side: 'short', pos: p });
            }
            return out;
        };
        const computeCycleSnapshot = (parentSide: 'long' | 'short') => {
            return cs_computeCycleSnapshot(
                parentSide,
                getCyclePositions,
                (side: 'long' | 'short', pos: any, currentPrice: number, takerFeeRate: number, slippagePercent: number, slippageModel: 'one_sided' | 'two_sided', dailyInterestRate: number, quantityOverride?: number) =>
                    this.calculatePositionNet(side, pos, currentPrice, takerFeeRate, slippagePercent, slippageModel, dailyInterestRate, quantityOverride, config, symbolInfo, gridExecutionCostProfile),
                price,
                takerFeeRate,
                slippagePercent,
                slippageModel,
                effectiveDailyRate,
                nowTs,
                (pos: any) => this.getOpenTime(pos)
            );
        };

        const computePrimaryCycleNotionalUsd = (parentSide: 'long' | 'short'): number => {
            const primaryPositions = getCyclePositions(parentSide)
                .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos));
            let totalNotional = 0;
            for (const item of primaryPositions) {
                const qty = Math.max(0, Number((item.pos as any).quantity || 0));
                if (!(qty > 0)) continue;
                const entry = Math.max(0, Number(this.getEntryPrice(parentSide, item.pos)));
                if (!(entry > 0)) continue;
                totalNotional += entry * qty;
            }
            if (totalNotional > 0) return totalNotional;
            const snapshot = computeCycleSnapshot(parentSide);
            const fallbackQty = parentSide === 'long' ? snapshot.longQty : snapshot.shortQty;
            return Math.max(0, fallbackQty * Math.max(0, price));
        };

        const getPrimaryPositionsSorted = (parentSide: 'long' | 'short') => {
            return getCyclePositions(parentSide)
                .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos))
                .sort((a, b) => this.getOpenTime(b.pos) - this.getOpenTime(a.pos));
        };

        const computeSinglePrimaryTakeProfitTargetNetUsd = (
            parentSide: 'long' | 'short',
            pos: MarketKingLongPosition | MarketKingShortPosition
        ): number => {
            const targetPct = Math.max(0, gridTakeProfitPercent);
            const qty = Math.max(0, Number((pos as any).quantity || 0));
            const entry = Math.max(0, Number(this.getEntryPrice(parentSide, pos)));
            return Math.max(0, entry * qty * (targetPct / 100));
        };
        const computePrimaryBasketTakeProfitTargetNetUsd = (parentSide: 'long' | 'short'): number => {
            const targetPct = Math.max(0, gridTakeProfitPercent);
            const basketNotionalUsd = computePrimaryCycleNotionalUsd(parentSide);
            return Math.max(0, basketNotionalUsd * (targetPct / 100));
        };
        const computePrimaryBasketNet = (parentSide: 'long' | 'short'): { net: number; qty: number; count: number } => {
            const primaryPositions = getPrimaryPositionsSorted(parentSide);
            let net = 0;
            let qty = 0;
            let count = 0;
            for (const item of primaryPositions) {
                const calc = this.calculatePositionNet(
                    parentSide,
                    item.pos,
                    price,
                    takerFeeRate,
                    slippagePercent,
                    slippageModel,
                    effectiveDailyRate,
                    undefined,
                    config,
                    symbolInfo,
                    gridExecutionCostProfile
                );
                net += Number(calc.net || 0);
                qty += Math.max(0, Number((item.pos as any).quantity || 0));
                count++;
            }
            return { net, qty, count };
        };
        const computeNetForPosition = (
            side: 'long' | 'short',
            pos: MarketKingLongPosition | MarketKingShortPosition,
            quantityOverride?: number
        ) => this.calculatePositionNet(
            side,
            pos,
            price,
            takerFeeRate,
            slippagePercent,
            slippageModel,
            effectiveDailyRate,
            quantityOverride,
            config,
            symbolInfo,
            gridExecutionCostProfile
        );
        const getPrimaryLevelFromReason = (reasonRaw: any): number => {
            const reason = String(reasonRaw || '').trim().toLowerCase();
            const mgMatch = reason.match(/martingale_level_(\d+)/);
            if (!mgMatch) return 0;
            const level = Number(mgMatch[1]);
            return Number.isFinite(level) && level >= 0 ? level : 0;
        };
        const getPrimaryPositionsWithLevel = (parentSide: 'long' | 'short') => {
            return getCyclePositions(parentSide)
                .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos))
                .map((item) => ({
                    item,
                    level: getPrimaryLevelFromReason(this.getGridOpenReason(item.pos)),
                    openTime: this.getOpenTime(item.pos)
                }))
                .sort((a, b) => Number(a.openTime || 0) - Number(b.openTime || 0));
        };
        const hasPrimaryAtOrBelowLevel = (parentSide: 'long' | 'short', maxLevel: number): boolean => {
            return getPrimaryPositionsWithLevel(parentSide).some((entry) => Number(entry.level || 0) <= maxLevel);
        };
        const getHedgeTotalQty = (parentSide: 'long' | 'short', hedgeSide: 'long' | 'short'): number => {
            return getCyclePositions(parentSide)
                .filter((item) => item.side === hedgeSide && this.isGridHedgePosition(item.pos))
                .reduce((sum, item) => sum + Math.max(0, Number((item.pos as any).quantity || 0)), 0);
        };
        const computeCycleHedgeNet = (parentSide: 'long' | 'short'): { net: number; count: number } => {
            const hedgePositions = getCyclePositions(parentSide).filter((item) => this.isGridHedgePosition(item.pos));
            let net = 0;
            let count = 0;
            for (const item of hedgePositions) {
                const calc = computeNetForPosition(item.side, item.pos);
                net += Number(calc.net || 0);
                count++;
            }
            return { net, count };
        };
        const tryReinforceRedFromMartingaleProfit = async (
            closedPrimarySide: 'long' | 'short',
            martingaleProfitUsd: number
        ): Promise<boolean> => {
            if (!(martingaleProfitUsd > 0) || !(price > 0)) return false;

            for (const redParentSide of ['long', 'short'] as Array<'long' | 'short'>) {
                const redState = cycleSideState(redParentSide);
                if (!redState || !redState.redActive) continue;

                const redHedgeSide: 'long' | 'short' = redState.redHedgeSide
                    ? (redState.redHedgeSide as 'long' | 'short')
                    : (redParentSide === 'long' ? 'short' : 'long');
                if (redHedgeSide !== closedPrimarySide) continue;

                const marginBudgetUsd = martingaleProfitUsd * redHedgeProfitReinforceUseFactor;
                if (!(marginBudgetUsd > 0)) return false;

                const reinforceQtyRaw = (marginBudgetUsd * leverage) / price;
                let reinforceQty = normalizeOrderQtySafe(reinforceQtyRaw);
                if (!(reinforceQty > 0)) reinforceQty = normalizeOrderQtySafe(reinforceQtyRaw, reinforceQtyRaw);
                if (!(reinforceQty > 0)) {
                    this.logger.debug(
                        `[MARKETKING][RED REINFORCE SKIP] ${redParentSide.toUpperCase()} qty_below_min ` +
                        `mgProfit=${martingaleProfitUsd.toFixed(6)} budget=${marginBudgetUsd.toFixed(6)}`
                    );
                    return false;
                }

                const opened = await openPosition(
                    redHedgeSide,
                    reinforceQty,
                    'red_profit_reinforce',
                    redParentSide,
                    { isHedge: true, forceOpen: true }
                );
                if (!opened) {
                    this.logger.debug(
                        `[MARKETKING][RED REINFORCE SKIP] ${redParentSide.toUpperCase()} open_rejected ` +
                        `qty=${reinforceQty.toFixed(8)}`
                    );
                    return false;
                }

                redState.redHedgeBaseQty = Math.max(0, Number(redState.redHedgeBaseQty || 0)) + reinforceQty;
                redState.redProfitReinforceCount = Math.max(0, Number(redState.redProfitReinforceCount || 0)) + 1;
                redState.redProfitReinforceQty = Math.max(0, Number(redState.redProfitReinforceQty || 0)) + reinforceQty;
                redState.redProfitReinforceBudgetUsd = Math.max(0, Number(redState.redProfitReinforceBudgetUsd || 0)) + marginBudgetUsd;

                this.logger.info(
                    `[MARKETKING][RED REINFORCE] ${redParentSide.toUpperCase()} hedgeSide=${redHedgeSide} ` +
                    `qty=${reinforceQty.toFixed(8)} mgProfit=${martingaleProfitUsd.toFixed(6)} ` +
                    `budget=${marginBudgetUsd.toFixed(6)}`
                );
                return true;
            }

            return false;
        };

        const computeCycleDrawdown = (parentSide: 'long' | 'short') => {
            const sideState = cycleSideState(parentSide);
            const snapshot = computeCycleSnapshot(parentSide);
            return cs_computeCycleDrawdown(sideState, snapshot, accountEquity);
        };

        const syncCycleStates = () => {
            (['long', 'short'] as Array<'long' | 'short'>).forEach((parentSide) => {
                const sideState = cycleSideState(parentSide);
                const snapshot = computeCycleSnapshot(parentSide);
                if (snapshot.count > 0) {
                    sideState.active = true;
                    if (!(sideState.cycleStartBalance > 0)) {
                        sideState.cycleStartBalance = Math.max(1, Number(accountEquity || 0));
                    }
                    if (!(sideState.cycleStartTime > 0)) {
                        sideState.cycleStartTime = snapshot.oldestOpen;
                    }
                    const cyclePositions = getCyclePositions(parentSide);
                    const hasHedgeInCycle = cyclePositions.some((d) => this.isGridHedgePosition(d?.pos));

                    const primaryPositions = cyclePositions
                        .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos))
                        .sort((a, b) => this.getOpenTime(b.pos) - this.getOpenTime(a.pos));
                    const hasPrimaries = primaryPositions.length > 0;

                    // CORRIGIDO: RED sÃ³ Ã© desativado quando nÃ£o hÃ¡ hedge E nÃ£o hÃ¡ posiÃ§Ãµes primÃ¡rias
                    // IMPORTANTE: Verificar TODAS as posiÃ§Ãµes do sistema, nÃ£o apenas cyclePositions
                    // porque pode haver posiÃ§Ãµes que nÃ£o estÃ£o sendo encontradas por getCyclePositions
                    if (sideState.redActive && !hasHedgeInCycle && !hasPrimaries) {
                        // VerificaÃ§Ã£o adicional: verificar TODAS as posiÃ§Ãµes do sistema antes de desativar RED
                        const allPositions = [...(state.activePositions || []), ...(state.shortPositions || [])];
                        const hasHedgeRelated = allPositions.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && this.isGridHedgePosition(p);
                        });
                        const hasPrimariesRelated = allPositions.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && !this.isGridHedgePosition(p);
                        });

                        // SÃ³ desativar RED se realmente nÃ£o hÃ¡ hedge nem primÃ¡rias em TODAS as posiÃ§Ãµes
                        if (!hasHedgeRelated && !hasPrimariesRelated) {
                            sideState.redActive = false;
                            sideState.redHedgeSide = null;
                            sideState.redDilutionRefPrice = 0;
                            sideState.redHedgeBaseQty = 0;
                            sideState.redProfitReinforceCount = 0;
                            sideState.redProfitReinforceQty = 0;
                            sideState.redProfitReinforceBudgetUsd = 0;
                            sideState.redRebuildPhaseActive = false;
                            sideState.redLastReductionTime = nowTs;
                            this.logger.info(
                                `[MARKETKING] RED ${parentSide.toUpperCase()} desativado: hedge e posiÃ§Ãµes primÃ¡rias completamente fechadas apÃ³s diluiÃ§Ãµes`
                            );
                        } else {
                            // Se ainda hÃ¡ hedge ou primÃ¡rias relacionadas, manter RED ativo
                            // Isso evita falso positivo quando Base fecha mas ainda hÃ¡ MG abertos
                            sideState.active = true; // Garantir que ciclo permanece ativo
                        }
                    }
                    if (primaryPositions.length > 0) {
                        const latestPrimary = primaryPositions[0];
                        sideState.lastQuantity = Math.max(0, Number((latestPrimary.pos as any).quantity || 0));
                        sideState.lastGridPrice = this.getEntryPrice(parentSide, latestPrimary.pos);
                        // Base + N martingales => N = quantidade de posicoes primarias - 1.
                        sideState.martingaleCount = Math.max(0, primaryPositions.length - 1);
                    } else {
                        sideState.lastQuantity = 0;
                        sideState.martingaleCount = 0;
                    }
                } else {
                    // CORRIGIDO: Antes de resetar o estado, verificar se hÃ¡ RED ativo
                    // Se hÃ¡ RED ativo mas nÃ£o hÃ¡ posiÃ§Ãµes no ciclo, verificar TODAS as posiÃ§Ãµes do sistema
                    // para garantir que nÃ£o hÃ¡ hedge ou primÃ¡rias relacionadas ao RED
                    if (sideState.redActive) {
                        // Verificar TODAS as posiÃ§Ãµes, nÃ£o apenas as do ciclo atual
                        // porque o hedge pode estar em outro array (state.activePositions vs state.shortPositions)
                        const allPositions = [...(state.activePositions || []), ...(state.shortPositions || [])];
                        const hasHedgeRelated = allPositions.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && this.isGridHedgePosition(p);
                        });
                        const hasPrimariesRelated = allPositions.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && !this.isGridHedgePosition(p);
                        });

                        // SÃ³ desativar RED se realmente nÃ£o hÃ¡ hedge nem primÃ¡rias relacionadas
                        if (!hasHedgeRelated && !hasPrimariesRelated) {
                            this.logger.info(
                                `[MARKETKING] RED ${parentSide.toUpperCase()} desativado: todas as posiÃ§Ãµes relacionadas fechadas`
                            );
                            sideState.redActive = false;
                            sideState.redHedgeSide = null;
                            sideState.redDilutionRefPrice = 0;
                            sideState.redHedgeBaseQty = 0;
                            sideState.redProfitReinforceCount = 0;
                            sideState.redProfitReinforceQty = 0;
                            sideState.redProfitReinforceBudgetUsd = 0;
                            sideState.redRebuildPhaseActive = false;
                            sideState.redLastReductionTime = nowTs;
                        } else {
                            // Se ainda hÃ¡ hedge ou primÃ¡rias relacionadas, NÃƒO resetar o estado
                            // Manter RED ativo e manter sideState.active = true para evitar reentry
                            sideState.active = true; // Manter ciclo ativo enquanto hÃ¡ RED
                            return; // NÃ£o resetar estado se ainda hÃ¡ posiÃ§Ãµes relacionadas ao RED
                        }
                    }
                    // CORRIGIDO: Antes de resetar o estado completamente, verificar novamente se hÃ¡ RED ativo
                    // Se hÃ¡ RED ativo, NÃƒO resetar o estado - manter RED ativo atÃ© que todas as posiÃ§Ãµes sejam fechadas
                    if (sideState.redActive) {
                        // VerificaÃ§Ã£o final: verificar TODAS as posiÃ§Ãµes antes de resetar estado
                        const allPositionsFinal = [...(state.activePositions || []), ...(state.shortPositions || [])];
                        const hasHedgeFinal = allPositionsFinal.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && this.isGridHedgePosition(p);
                        });
                        const hasPrimariesFinal = allPositionsFinal.some((p: any) => {
                            const pParentSide = this.getParentSide(p, parentSide);
                            return pParentSide === parentSide && !this.isGridHedgePosition(p);
                        });

                        // Se ainda hÃ¡ posiÃ§Ãµes relacionadas ao RED, nÃ£o resetar estado
                        if (hasHedgeFinal || hasPrimariesFinal) {
                            sideState.active = true; // Manter ciclo ativo
                            return; // NÃ£o resetar estado se ainda hÃ¡ posiÃ§Ãµes relacionadas ao RED
                        }
                    }
                    gridState[parentSide] = this.createGridSideState();
                }
            });
        };
        // CORRIGIDO: Permitir bootstrap quando apenas hedges estÃ£o abertos (sem primÃ¡rias)
        const anyActiveCycle = () => {
            const longPositions = getCyclePositions('long');
            const shortPositions = getCyclePositions('short');
            const longHasPrimaries = longPositions.some((d) => !this.isGridHedgePosition(d?.pos));
            const shortHasPrimaries = shortPositions.some((d) => !this.isGridHedgePosition(d?.pos));
            const longState = cycleSideState('long');
            const shortState = cycleSideState('short');

            // CORRIGIDO: RED ativo sÃ³ mantÃ©m ciclo ativo se hÃ¡ posiÃ§Ãµes primÃ¡rias OU hedge naquele lado
            // Se hÃ¡ RED mas nÃ£o hÃ¡ posiÃ§Ãµes primÃ¡rias nem hedge, nÃ£o considerar ciclo ativo
            // Isso permite que o lado oposto opere normalmente
            const longHasRed = longState && longState.redActive;
            const shortHasRed = shortState && shortState.redActive;
            const longHasHedge = longPositions.some((d) => this.isGridHedgePosition(d?.pos));
            const shortHasHedge = shortPositions.some((d) => this.isGridHedgePosition(d?.pos));

            // Considerar ciclo ativo se hÃ¡ posiÃ§Ãµes primÃ¡rias OU (RED ativo E hÃ¡ hedge)
            const longCycleActive = (gridState.long.active && longHasPrimaries) ||
                                   (longHasRed && (longHasPrimaries || longHasHedge));
            const shortCycleActive = (gridState.short.active && shortHasPrimaries) ||
                                    (shortHasRed && (shortHasPrimaries || shortHasHedge));

            return longCycleActive || shortCycleActive ||
                (longPositions.length > 0 && longHasPrimaries) ||
                (shortPositions.length > 0 && shortHasPrimaries);
        };
        let hedgeManager: HedgeManager | null = null;

        /**
         * Verifica se o bloqueio de entrada RED estÃ¡ ativo para um lado especÃ­fico.
         * REGRA: Quando RED estÃ¡ ativo, TODAS as operaÃ§Ãµes primÃ¡rias do lado com RED sÃ£o totalmente bloqueadas
         * atÃ© que o RED liquide essas posiÃ§Ãµes abertas e reset o ciclo liberando as operaÃ§Ãµes novamente.
         * O lado oposto pode continuar operando normalmente.
         */
        const isRedEntryLockActive = (parentSide: 'long' | 'short'): boolean => {
            if (!hedgeManager) return false;
            return hedgeManager.isRedEntryLockActive(parentSide);
        };

        const openPosition = async (
            side: 'long' | 'short',
            quantityRaw: number,
            reason: string,
            parentSide: 'long' | 'short',
            options?: { isHedge?: boolean; forceOpen?: boolean }
        ): Promise<boolean> => {
            const qtyRaw = Number(quantityRaw || 0);
            const quantity = normalizeOrderQtySafe(qtyRaw);
            if (!(quantity > 0)) {
                logGridQtyBlocked(side, reason, qtyRaw, quantity);
                return false;
            }
            const amountUsdt = quantity * price;
            if (!(amountUsdt > 0)) {
                if (shouldLogGridQtyBlocked()) {
                    this.logger.warn(
                        `[MARKETKING] ${side.toUpperCase()} bloqueado (${reason}): amountUsdt invalido ` +
                        `qty=${quantity.toFixed(8)} price=${price.toFixed(6)}`
                    );
                }
                return false;
            }

            // Bloquear entrada apenas do lado com RED ativo (nÃ£o bloquear o lado oposto)
            const isHedgeOpen = !!options?.isHedge;
            if (String(config.tradeType || '').toLowerCase() === 'stockmarket') {
                const maxOpen = Math.max(1, Number((config as any).stockMarketMaxOpenPositions ?? 2));
                const openNow = (Array.isArray(state.activePositions) ? state.activePositions.length : 0)
                    + (Array.isArray(state.shortPositions) ? state.shortPositions.length : 0);
                if (openNow >= maxOpen) {
                    this.logger.warn(`[STOCKMARKET] abertura bloqueada: maxOpenPositions=${maxOpen} open=${openNow}`);
                    return false;
                }
            }
            if (!isHedgeOpen && !options?.forceOpen && isRedEntryLockActive(parentSide)) {
                const now = Date.now();
                const lastTs = Number(state._lastEntryGateLogAt || 0);
                const shouldLog = !Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= Math.max(5000, Number(config.decisionVerboseIntervalMs ?? 5000));
                if (shouldLog) {
                    this.logger.warn(
                        `[MARKETKING][RED ENTRY LOCK] ${side.toUpperCase()} bloqueado para parentSide=${parentSide}: RED ativo neste lado. ` +
                        `Lado oposto pode continuar operando normalmente.`
                    );
                    state._lastEntryGateLogAt = now;
                }
                return false;
            }

            // Verificar limite de MG antes de abrir nova posiÃ§Ã£o primÃ¡ria
            if (!isHedgeOpen && !options?.forceOpen) {
                const sideState = cycleSideState(parentSide);
                const currentMgCount = Number(sideState.martingaleCount || 0);
                const maxMgLevels = Math.max(1, Number(config.martingaleMaxLevels ?? 5));
                const maxMgAdds = Math.max(0, maxMgLevels - 1); // Base nÃ£o conta como MG, entÃ£o MG1-MG4 = 4 nÃ­veis

                // Verificar se jÃ¡ atingiu o mÃ¡ximo de nÃ­veis de MG permitidos
                if (currentMgCount >= maxMgAdds) {
                    const now = Date.now();
                    const lastTs = Number(state._lastMgLimitLogAt || 0);
                    const shouldLog = !Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= Math.max(5000, Number(config.decisionVerboseIntervalMs ?? 5000));
                    if (shouldLog) {
                        this.logger.warn(
                            `[MARKETKING][MG LIMIT] ${side.toUpperCase()} bloqueado para parentSide=${parentSide}: ` +
                            `Limite de MG atingido (${currentMgCount}/${maxMgAdds}). ` +
                            `Base + MG1-MG${currentMgCount} jÃ¡ estÃ£o ativas. RED deve ser ativado antes de novas entradas.`
                        );
                        state._lastMgLimitLogAt = now;
                    }
                    return false;
                }
            }

            if (!options?.forceOpen && !allowNewEntriesByMarginGrid) {
                const now = Date.now();
                const lastTs = Number(state._lastGridMarginGateLogAt || 0);
                const shouldLog = !Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= Math.max(5000, Number(config.decisionVerboseIntervalMs ?? 5000));
                if (shouldLog) {
                    const reason = entryGate.reason || 'blocked';
                    this.logger.warn(`[MARKETKING][MARGIN GATE] ${side.toUpperCase()} bloqueado (${reason})`);
                    state._lastGridMarginGateLogAt = now;
                }
                return false;
            }

            const beforeCount = side === 'long' ? state.activePositions.length : (state.shortPositions || []).length;
            state._nextOrderAmountUsdt = amountUsdt;
            state._nextOrderParentSide = parentSide;
            state._nextOrderIsHedge = !!options?.isHedge;
            state._nextOrderReason = String(reason || '');

            const hasOppositeOpen = side === 'long'
                ? ((state.shortPositions || []).length > 0)
                : (state.activePositions.length > 0);
            if (hasOppositeOpen || options?.forceOpen) {
                state._forceOpenSide = side;
            }

            const action = side === 'long' ? 'buy' : 'sell';
            await this.executeMarginOrder(
                action,
                price,
                balances,
                symbolInfo,
                client,
                config,
                state,
                effectiveDailyRate,
                isDemo,
                isReal
            );

            const afterCount = side === 'long' ? state.activePositions.length : (state.shortPositions || []).length;
            const opened = afterCount > beforeCount;
            if (opened) {
                this.logger.info(
                    `[MARKETKING] ${side.toUpperCase()} aberto (${reason}) qty=${quantity.toFixed(8)} parent=${parentSide} hedge=${!!options?.isHedge}`
                );
            }
            return opened;
        };

        const closePosition = async (
            side: 'long' | 'short',
            pos: MarketKingLongPosition | MarketKingShortPosition,
            reason: string,
            quantityOverride?: number,
            forceLossClose: boolean = false,
            minNetOverride?: number,
            ignorePerPositionPositiveCheck: boolean = false
        ): Promise<boolean> => {
            const strictExceptionOnlyNegativeClose = !!config.requirePositiveClose;
            const closeReason = String(reason || '').trim().toLowerCase();
            const allowExceptionIgnore =
                !!ignorePerPositionPositiveCheck &&
                (
                    !strictExceptionOnlyNegativeClose
                    || closeReason.includes('red_total_settlement')
                    || closeReason.includes('red_dilution')
                    || closeReason.includes('managed_cycle_basket_positive')
                    || closeReason.includes('managed_cycle_reserve_')
                );
            const sideArray: Array<MarketKingLongPosition | MarketKingShortPosition> = side === 'long'
                ? (state.activePositions as Array<MarketKingLongPosition | MarketKingShortPosition>)
                : ((state.shortPositions || []) as Array<MarketKingLongPosition | MarketKingShortPosition>);
            const idx = sideArray.findIndex((p) => {
                const pid = String((p as any).positionId || '');
                const targetId = String((pos as any).positionId || '');
                return targetId ? pid === targetId : p === pos;
            });
            if (idx < 0) return false;

            if (idx !== sideArray.length - 1) {
                const [target] = sideArray.splice(idx, 1);
                sideArray.push(target);
                if (side === 'long') {
                    state.buyPrices = state.activePositions.map((p) => Number(p.entryPrice || 0));
                }
            }

            const beforeArray = side === 'long' ? state.activePositions : (state.shortPositions || []);
            const beforeLen = beforeArray.length;
            const beforeLast = beforeArray[beforeArray.length - 1];
            const beforeQty = Number((beforeLast as any)?.quantity || 0);
            let closeQty = Number.isFinite(Number(quantityOverride))
                ? Math.min(beforeQty, Math.max(0, Number(quantityOverride)))
                : beforeQty;
            closeQty = normalizeOrderQtySafe(closeQty, beforeQty);
            if (!(closeQty > 0)) return false;

            const closeNotional = Math.max(0, Number(price || 0) * closeQty);
            const configuredMinNet = this.resolveRuntimeCloseMinNetForReason(
                config,
                reason,
                forceLossClose,
                closeNotional
            );
            const effectiveMinNet = Number.isFinite(Number(minNetOverride))
                ? Number(minNetOverride)
                : configuredMinNet;
            if (!forceLossClose && !allowExceptionIgnore && Number.isFinite(effectiveMinNet)) {
                const projectedClose = computeNetForPosition(side, beforeLast as any, closeQty);
                const projectedNet = Number(projectedClose?.net ?? NaN);
                if (!Number.isFinite(projectedNet) || projectedNet < Number(effectiveMinNet)) {
                    return false;
                }
            }

            const isPartial = closeQty < (beforeQty - 1e-10);
            if (isPartial) {
                state._nextCloseQuantity = closeQty;
            }
            state._nextCloseReason = reason;
            state._nextCloseIgnorePositiveCheck = !!allowExceptionIgnore;
            state.forceStopLossSell = forceLossClose;

            const action = side === 'long' ? 'sell' : 'buy';
            await this.executeMarginOrder(
                action,
                price,
                balances,
                symbolInfo,
                client,
                config,
                state,
                effectiveDailyRate,
                isDemo,
                isReal
            );
            state.forceStopLossSell = false;

            const afterArray = side === 'long' ? state.activePositions : (state.shortPositions || []);
            const afterLast = afterArray.length > 0 ? afterArray[afterArray.length - 1] : null;
            const afterQty = Number((afterLast as any)?.quantity || 0);
            const progressed = afterArray.length < beforeLen || afterQty < beforeQty;
            if (progressed) {
                this.logger.info(
                    `[MARKETKING] ${side.toUpperCase()} fechado (${reason}) qty=${closeQty.toFixed(8)} force=${forceLossClose}`
                );
            }
            return progressed;
        };

        const tryCloseLatestPrimaryTakeProfit = async (
            parentSide: 'long' | 'short',
            reason: string = 'grid_take_profit'
        ): Promise<boolean> => {
            const primaryPositions = getPrimaryPositionsSorted(parentSide);
            if (primaryPositions.length === 0) return false;

            // MG acts as an increment of base order: when MG is active, TP is evaluated on
            // the whole primary basket (base + martingales), and closes the whole basket.
            if (primaryPositions.length > 1) {
                const basketNet = computePrimaryBasketNet(parentSide).net;
                const basketTargetNet = Math.max(1e-8, computePrimaryBasketTakeProfitTargetNetUsd(parentSide));
                if (!(basketNet > 0 && basketNet >= basketTargetNet)) return false;

                // IMPORTANTE: Fechar cada posiÃ§Ã£o primÃ¡ria individualmente para garantir
                // que cada uma fechada completamente decremente o contador corretamente
                let closedCount = 0;
                let fullyClosedCount = 0;
                let martingaleProfitClosedUsd = 0;

                for (const item of primaryPositions) {
                    const openReason = String(
                        this.getGridOpenReason(item.pos)
                        ?? ''
                    ).trim().toLowerCase();
                    const isMartingalePrimary = openReason.startsWith('martingale_level_');
                    const netBeforeClose = isMartingalePrimary
                        ? Number(computeNetForPosition(parentSide, item.pos).net || 0)
                        : 0;
                    const beforeCount = parentSide === 'long' ? state.activePositions.length : (state.shortPositions || []).length;
                    const closed = await closePosition(parentSide, item.pos, reason, undefined, false);
                    if (closed) {
                        closedCount++;
                        if (isMartingalePrimary && Number.isFinite(netBeforeClose) && netBeforeClose > 0) {
                            martingaleProfitClosedUsd += netBeforeClose;
                        }
                        const afterCount = parentSide === 'long' ? state.activePositions.length : (state.shortPositions || []).length;
                        // Se a posiÃ§Ã£o foi completamente removida do array, foi fechada totalmente
                        if (afterCount < beforeCount) {
                            fullyClosedCount++;
                        }
                    }
                }

                if (closedCount > 0) {
                    this.logger.info(
                        `[MARKETKING] ${parentSide.toUpperCase()} TP cesta MG: net=${basketNet.toFixed(4)} alvo=${basketTargetNet.toFixed(4)} ` +
                        `closed=${closedCount}/${primaryPositions.length} fullyClosed=${fullyClosedCount}`
                    );
                    await tryReinforceRedFromMartingaleProfit(parentSide, martingaleProfitClosedUsd);
                }
                return closedCount > 0;
            }

            const latestPrimary = primaryPositions[0].pos;
            const latestOpenReason = String(
                this.getGridOpenReason(latestPrimary)
                ?? ''
            ).trim().toLowerCase();
            const latestIsMartingale = latestOpenReason.startsWith('martingale_level_');
            const targetNet = Math.max(1e-8, computeSinglePrimaryTakeProfitTargetNetUsd(parentSide, latestPrimary));
            const latestNet = this.calculatePositionNet(
                parentSide,
                latestPrimary,
                price,
                takerFeeRate,
                slippagePercent,
                slippageModel,
                effectiveDailyRate,
                undefined,
                config,
                symbolInfo,
                gridExecutionCostProfile
            ).net;

            if (!(latestNet > 0 && latestNet >= targetNet)) return false;

            const closed = await closePosition(parentSide, latestPrimary, reason, undefined, false);
            if (closed) {
                this.logger.info(
                    `[MARKETKING] ${parentSide.toUpperCase()} TP parcial (LIFO): net=${latestNet.toFixed(4)} alvo=${targetNet.toFixed(4)}`
                );
                if (latestIsMartingale && latestNet > 0) {
                    await tryReinforceRedFromMartingaleProfit(parentSide, latestNet);
                }
            }
            return closed;
        };
        const auditLatestPrimaryTakeProfit = (parentSide: 'long' | 'short', tag: string): void => {
            if (!shouldLogGridAudit(parentSide === 'long' ? '_lastGridTpAuditLongLogAt' : '_lastGridTpAuditShortLogAt')) return;
            const primaryPositions = getPrimaryPositionsSorted(parentSide);
            if (primaryPositions.length === 0) return;

            if (primaryPositions.length > 1) {
                const basket = computePrimaryBasketNet(parentSide);
                const targetNet = Math.max(1e-8, computePrimaryBasketTakeProfitTargetNetUsd(parentSide));
                const deficit = Math.max(0, targetNet - Number(basket.net || 0));
                this.logger.decision(
                    `[MARKETKING][TP AUDIT] ${parentSide.toUpperCase()} ${tag} | ` +
                    `mode=basket_mg primaries=${primaryPositions.length} mg=${Math.max(0, primaryPositions.length - 1)} ` +
                    `qty=${Number(basket.qty || 0).toFixed(8)} net=${Number(basket.net || 0).toFixed(4)} ` +
                    `target=${targetNet.toFixed(4)} deficit=${deficit.toFixed(4)} ` +
                    `exec=${runtimeOpenExecutionMode}/${runtimeCloseExecutionMode} ` +
                    `feePct=${(runtimeOpenFeeRate * 100).toFixed(4)}%/${(runtimeCloseFeeRate * 100).toFixed(4)}% ` +
                    `slipPct=${(runtimeOpenSlippagePercent * 100).toFixed(4)}%/${(runtimeCloseSlippagePercent * 100).toFixed(4)}% ` +
                    `fundRate8h=${(effectiveFundingRatePer8h * 100).toFixed(4)}% fundMode=${effectiveFundingMode} fundInt=${effectiveFundingIntervalHours}h ` +
                    `redActive=${!!cycleSideState(parentSide).redActive} mg=${Number(cycleSideState(parentSide).martingaleCount || 0)}`
                );
                return;
            }

            const latestPrimary = primaryPositions[0].pos;
            const targetNet = Math.max(1e-8, computeSinglePrimaryTakeProfitTargetNetUsd(parentSide, latestPrimary));
            const calc = this.calculatePositionNet(
                parentSide,
                latestPrimary,
                price,
                takerFeeRate,
                slippagePercent,
                slippageModel,
                effectiveDailyRate,
                undefined,
                config,
                symbolInfo,
                gridExecutionCostProfile
            );
            const entry = Math.max(0, Number(this.getEntryPrice(parentSide, latestPrimary)));
            const qty = Math.max(0, Number((latestPrimary as any).quantity || 0));
            const net = Number(calc.net || 0);
            const totalQty = Math.max(0, Number((latestPrimary as any).quantity || 0));
            const closeQty = Math.max(0, Number(calc.quantity || totalQty));
            const ratio = totalQty > 0 ? Math.min(1, closeQty / totalQty) : 1;
            const borrowedForClose = getBorrowedAmountForClose(
                Number((latestPrimary as any).borrowedAmount || 0),
                totalQty,
                closeQty
            );
            const openTime = Number(this.getOpenTime(latestPrimary) || Date.now());
            const carryRateUsed = Math.max(0, Number((latestPrimary as any).dailyInterestRate ?? effectiveDailyRate ?? 0));
            const carryCost = calcAccumulatedInterest(borrowedForClose, openTime, carryRateUsed);
            const fundingCost = this.estimateFundingCost(
                config,
                parentSide,
                latestPrimary,
                price,
                closeQty,
                symbolInfo
            );
            const grossMovePnl = parentSide === 'long'
                ? ((price - entry) * closeQty)
                : ((entry - price) * closeQty);
            const modeledFeeCost = Math.max(0, (entry * closeQty * runtimeOpenFeeRate) + (price * closeQty * runtimeCloseFeeRate));
            const modeledSlippageCost = Math.max(0, (entry * closeQty * runtimeOpenSlippagePercent) + (price * closeQty * runtimeCloseSlippagePercent));
            const deficit = Math.max(0, targetNet - net);
            this.logger.decision(
                `[MARKETKING][TP AUDIT] ${parentSide.toUpperCase()} ${tag} | ` +
                `entry=${entry.toFixed(4)} price=${price.toFixed(4)} qty=${qty.toFixed(8)} ` +
                `gross=${grossMovePnl.toFixed(4)} fee=${modeledFeeCost.toFixed(4)} slip=${modeledSlippageCost.toFixed(4)} carry=${carryCost.toFixed(4)} funding=${Number(fundingCost || 0).toFixed(4)} ` +
                `net=${net.toFixed(4)} target=${targetNet.toFixed(4)} deficit=${deficit.toFixed(4)} ` +
                `exec=${runtimeOpenExecutionMode}/${runtimeCloseExecutionMode} ` +
                `feePct=${(runtimeOpenFeeRate * 100).toFixed(4)}%/${(runtimeCloseFeeRate * 100).toFixed(4)}% ` +
                `slipPct=${(runtimeOpenSlippagePercent * 100).toFixed(4)}%/${(runtimeCloseSlippagePercent * 100).toFixed(4)}% ratio=${ratio.toFixed(4)} ` +
                `fundRate8h=${(effectiveFundingRatePer8h * 100).toFixed(4)}% fundMode=${effectiveFundingMode} fundInt=${effectiveFundingIntervalHours}h ` +
                `redActive=${!!cycleSideState(parentSide).redActive} mg=${Number(cycleSideState(parentSide).martingaleCount || 0)}`
            );
        };

        const shouldLogGridStatusSummary = (): boolean => {
            const now = Date.now();
            const lastTs = Number(state._lastGridStatusLogAt || 0);
            const normalizedLastTs = Number.isFinite(lastTs) && lastTs > 0 && lastTs <= (now + 60000) ? lastTs : 0;
            if (normalizedLastTs > 0 && (now - normalizedLastTs) < runtimeStatusLogIntervalMs) {
                return false;
            }
            state._lastGridStatusLogAt = now;
            return true;
        };

        const formatGridSideStatus = (parentSide: 'long' | 'short'): string => {
            const sideState = cycleSideState(parentSide);
            const primaryPositions = getPrimaryPositionsSorted(parentSide);
            const cyclePositions = getCyclePositions(parentSide);
            const primaryCount = primaryPositions.length;
            const hedgeCount = Math.max(0, cyclePositions.length - primaryCount);
            if (primaryCount <= 0) {
                return `${parentSide.toUpperCase()}{off h=${hedgeCount} red=${sideState.redActive ? 1 : 0}}`;
            }

            const latestPrimary = primaryPositions[0].pos;
            const calc = this.calculatePositionNet(
                parentSide,
                latestPrimary,
                price,
                takerFeeRate,
                slippagePercent,
                slippageModel,
                effectiveDailyRate,
                undefined,
                config,
                symbolInfo,
                gridExecutionCostProfile
            );
            const entry = Math.max(0, Number(this.getEntryPrice(parentSide, latestPrimary)));
            const target = Math.max(1e-8, computeSinglePrimaryTakeProfitTargetNetUsd(parentSide, latestPrimary));
            const net = Number(calc.net || 0);
            const deficit = Math.max(0, target - net);
            const movePct = entry > 0 ? (((price - entry) / entry) * 100) : 0;
            return `${parentSide.toUpperCase()}{p=${primaryCount} h=${hedgeCount} mg=${Number(sideState.martingaleCount || 0)} red=${sideState.redActive ? 1 : 0} ` +
                `net=${net.toFixed(4)}/${target.toFixed(4)} df=${deficit.toFixed(4)} mv=${movePct.toFixed(3)}%}`;
        };

        const logGridStatusSummary = (tag: string): void => {
            if (!shouldLogGridStatusSummary()) return;
            const emaDistPct = ema > 0 ? (((price - ema) / ema) * 100) : 0;
            const marginLv = Number(state.marginLevel || this.accountMetrics?.marginLevel || 0);
            this.logger.decision(
                `[MARKETKING][STATUS] ${tag} | ` +
                `p=${price.toFixed(4)} ema=${ema.toFixed(4)} dEma=${emaDistPct.toFixed(3)}% ` +
                `exec=${runtimeOpenExecutionMode}/${runtimeCloseExecutionMode} ` +
                `fee=${(runtimeOpenFeeRate * 100).toFixed(4)}%/${(runtimeCloseFeeRate * 100).toFixed(4)}% ` +
                `slip=${(runtimeOpenSlippagePercent * 100).toFixed(4)}%/${(runtimeCloseSlippagePercent * 100).toFixed(4)}% ` +
                `fund=${(effectiveFundingRatePer8h * 100).toFixed(4)}%/8h(${effectiveFundingMode}) ` +
                `eq=${Number(accountEquity || 0).toFixed(2)} ml=${Number.isFinite(marginLv) ? marginLv.toFixed(3) : '0.000'}x ` +
                `realized=${Number(state.totalProfit || 0).toFixed(4)} | ` +
                `${formatGridSideStatus('long')} | ${formatGridSideStatus('short')}`
            );
        };

        const closeEntireCycle = async (
            parentSide: 'long' | 'short',
            reason: string,
            requiredNetUsd: number,
            forceLossClose: boolean,
            options?: { ignorePerPositionPositiveCheck?: boolean }
        ): Promise<boolean> => {
            const firstSnapshot = computeCycleSnapshot(parentSide);
            if (firstSnapshot.count <= 0) return false;
            if (Number.isFinite(requiredNetUsd) && firstSnapshot.net < requiredNetUsd) return false;
            return await dm_closeEntireCycle(parentSide, {
                getCyclePositions,
                closePosition,
                closeReason: reason,
                forceLossClose,
                ignorePerPositionPositiveCheck: !!options?.ignorePerPositionPositiveCheck
            });
        };

        const normalizeOrderQtyForHedge = (qty: number, options?: any): number => {
            if (options && typeof options === 'object') {
                const totalQty = Number(options.totalQty);
                return normalizeOrderQtySafe(qty, Number.isFinite(totalQty) && totalQty > 0 ? totalQty : undefined);
            }
            return normalizeOrderQtySafe(qty);
        };

        const computeNetForPositionForHedge = (
            side: 'long' | 'short',
            pos: MarketKingLongPosition | MarketKingShortPosition,
            quantityOverride?: number
        ) => {
            const calc = computeNetForPosition(side, pos, quantityOverride) as any;
            const net = Number(calc?.net ?? calc?.res?.net ?? 0);
            return {
                ...calc,
                res: {
                    ...(calc?.res || {}),
                    net
                }
            };
        };

        const openPositionForHedge = async (
            side: 'long' | 'short',
            qty: number,
            reason: string,
            parentSide: 'long' | 'short',
            options?: any
        ): Promise<boolean> => {
            return openPosition(side, qty, reason, parentSide, {
                isHedge: !!options?.isHedge,
                forceOpen: !!options?.forceOpen
            });
        };

        const closePositionForHedge = async (
            side: 'long' | 'short',
            pos: MarketKingLongPosition | MarketKingShortPosition,
            reason: string,
            fullClose: boolean,
            qty?: number,
            minNet?: number,
            ignorePositiveCheck?: boolean
        ): Promise<{ closed: boolean; net: number; closeQty: number; qty: number }> => {
            const totalQty = Math.max(0, Number((pos as any)?.quantity || 0));
            let closeQtyRaw = fullClose ? totalQty : Number(qty);
            if (!Number.isFinite(closeQtyRaw) || closeQtyRaw <= 0) {
                closeQtyRaw = totalQty;
            }
            const closeQty = normalizeOrderQtySafe(closeQtyRaw, totalQty);
            if (!(closeQty > 0)) {
                return { closed: false, net: 0, closeQty: 0, qty: 0 };
            }

            const projected = computeNetForPositionForHedge(side, pos, closeQty);
            const projectedNet = Number(projected?.res?.net ?? 0);
            const closed = await closePosition(
                side,
                pos,
                reason,
                closeQty,
                false,
                minNet,
                !!ignorePositiveCheck
            );
            return {
                closed,
                net: projectedNet,
                closeQty,
                qty: closeQty
            };
        };

        const closeEntireCycleForHedge = async (
            parentSide: 'long' | 'short',
            reason: string,
            forceLossClose: boolean,
            minNetOverride?: number,
            options?: { ignorePerPositionPositiveCheck?: boolean }
        ): Promise<number> => {
            const positions = getCyclePositions(parentSide)
                .sort((a, b) => this.getOpenTime(a.pos) - this.getOpenTime(b.pos));
            if (!positions.length) return 0;

            let closedCount = 0;
            for (const item of positions) {
                const totalQty = Math.max(0, Number((item.pos as any)?.quantity || 0));
                const fullQty = normalizeOrderQtySafe(totalQty, totalQty);
                if (!(fullQty > 0)) continue;
                const closed = await closePosition(
                    item.side,
                    item.pos,
                    reason,
                    fullQty,
                    forceLossClose,
                    minNetOverride,
                    !!options?.ignorePerPositionPositiveCheck
                );
                if (closed) {
                    closedCount += 1;
                }
            }

            return closedCount;
        };

        const recordGridSignal = (st: any, signal: any): void => {
            if (!st) return;
            if (!Array.isArray((st as any)._runtimeSignalLog)) (st as any)._runtimeSignalLog = [];
            (st as any)._runtimeSignalLog.push(signal);
            if ((st as any)._runtimeSignalLog.length > 2000) (st as any)._runtimeSignalLog.shift();
            void emitRuntimeRobotSignal({
                robot: 'marketking',
                config,
                state: st,
                signal,
                source: 'marketking.runtimeSignal',
            }).catch(() => undefined);
        };

        (state as any)._lastAtr = Number((currentIndicators as any)?.atr ?? 0);
        (state as any)._lastEma = Number(state.lastEMA ?? 0);

        hedgeManager = new HedgeManager({
            state,
            config,
            price,
            nowTs,
            cycleSideState,
            getCyclePositions,
            getParentSide: (pos: any, fallback: 'long' | 'short') => this.getParentSide(pos, fallback),
            getEntryPrice: (side: 'long' | 'short', pos: any) => this.getEntryPrice(side, pos),
            normalizeOrderQty: normalizeOrderQtyForHedge,
            computeNetForPosition: computeNetForPositionForHedge,
            computeCycleDrawdown,
            computeCycleHedgeNet,
            computePrimaryCycleNotionalUsd,
            getPrimaryPositionsLifo: (parentSide: 'long' | 'short') => {
                return getCyclePositions(parentSide)
                    .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos))
                    .sort((a, b) => this.getOpenTime(b.pos) - this.getOpenTime(a.pos));
            },
            getPrimaryLevelFromReason,
            hasPrimaryAtOrBelowLevel,
            getHedgeTotalQty,
            getRealizedEquity: () => Math.max(0, Number((state as any).accountEquity || accountEquity || (state as any).equity || 0)),
            openPosition: openPositionForHedge,
            closePosition: closePositionForHedge,
            closeEntireCycle: closeEntireCycleForHedge,
            recordSignal: recordGridSignal,
            syncCycleActivityFromPositions: syncCycleStates
        });

        const activateRed = async (parentSide: 'long' | 'short'): Promise<boolean> => {
            if (!hedgeManager) return false;
            return hedgeManager.activateRed(parentSide);
        };

        syncCycleStates();
        const primaryTakeProfitClosedThisTick = new Set<'long' | 'short'>();

        for (const parentSide of ['long', 'short'] as Array<'long' | 'short'>) {
            const sideState = cycleSideState(parentSide);
            const snapshot = computeCycleSnapshot(parentSide);
            // CORRIGIDO: Permitir take profit quando hÃ¡ primÃ¡rias, mesmo com RED ativo
            const hasPrimaries = getCyclePositions(parentSide).some((d) => !this.isGridHedgePosition(d?.pos));
            if (snapshot.count <= 0 || (sideState.redActive && !hasPrimaries)) continue;

            const closedLatest = await tryCloseLatestPrimaryTakeProfit(parentSide, 'grid_take_profit');
            if (closedLatest) {
                primaryTakeProfitClosedThisTick.add(parentSide);
                syncCycleStates();
            } else {
                auditLatestPrimaryTakeProfit(parentSide, 'no_close');
            }
        }

        for (const parentSide of ['long', 'short'] as Array<'long' | 'short'>) {
            if (!hedgeManager) break;
            const redResult = await hedgeManager.processRedSide(parentSide);
            if (redResult.shouldReturn) {
                return;
            }
        }

        for (const parentSide of ['long', 'short'] as Array<'long' | 'short'>) {
            const sideState = cycleSideState(parentSide);
            const snapshot = computeCycleSnapshot(parentSide);
            // CORRIGIDO: Bloquear Martingale quando RED estÃ¡ ativo - todas as operaÃ§Ãµes primÃ¡rias devem ser bloqueadas
            if (snapshot.count <= 0 || sideState.redActive) continue;
            if (primaryTakeProfitClosedThisTick.has(parentSide)) continue;

            const primaryPositions = getCyclePositions(parentSide)
                .filter((item) => item.side === parentSide && !this.isGridHedgePosition(item.pos))
                .sort((a, b) => this.getOpenTime(b.pos) - this.getOpenTime(a.pos));
            if (primaryPositions.length === 0) continue;

            const latestPrimary = primaryPositions[0].pos;
            const lastPriceRef = Number(sideState.lastGridPrice || this.getEntryPrice(parentSide, latestPrimary));
            const lastQtyRef = Number(sideState.lastQuantity || Number((latestPrimary as any).quantity || 0));
            if (!(lastQtyRef > 0)) continue;
            const basePrimary = primaryPositions[primaryPositions.length - 1]?.pos;
            const baseQtyRef = Math.max(0, Number((basePrimary as any)?.quantity || 0));
            if (!(baseQtyRef > 0)) continue;

            const nextLevelIndex = Math.max(0, Number(sideState.martingaleCount || 0));
            const priceTick = resolveAdversePointPriceStep();
            const baseEntryPrice = Math.max(0, Number(this.getEntryPrice(parentSide, basePrimary)));
            let adversePointsFromBase = 0;
            if (baseEntryPrice > 0 && priceTick > 0 && Number.isFinite(price)) {
                const raw = parentSide === 'long' ? (baseEntryPrice - price) : (price - baseEntryPrice);
                adversePointsFromBase = raw > 0 ? raw / priceTick : 0;
            }
            const adversePercentFromBase = baseEntryPrice > 0 && Number.isFinite(price)
                ? (parentSide === 'long'
                    ? Math.max(0, ((baseEntryPrice - price) / baseEntryPrice) * 100)
                    : Math.max(0, ((price - baseEntryPrice) / baseEntryPrice) * 100))
                : 0;
            const maxAdverseExceeded = maxAdversePercentFromBase > 0
                ? adversePercentFromBase > maxAdversePercentFromBase
                : maxAdversePointsFromBase > 0 && adversePointsFromBase > maxAdversePointsFromBase;
            if (maxAdverseExceeded) {
                this.logger.warn(
                    `[MARKETKING][MG BLOCK] ${parentSide.toUpperCase()} bloqueado por distanciamento maximo: ` +
                    `adversePct=${adversePercentFromBase.toFixed(4)} maxPct=${maxAdversePercentFromBase.toFixed(4)} ` +
                    `adversePoints=${adversePointsFromBase.toFixed(2)} maxPoints=${maxAdversePointsFromBase.toFixed(2)} ` +
                    `baseEntry=${baseEntryPrice.toFixed(3)} price=${Number(price || 0).toFixed(3)} tick=${priceTick.toFixed(6)}`
                );
                continue;
            }
            const effectiveStepPrice = Math.max(1e-8, lastPriceRef * (configuredStepPercent / 100));
            let adverseMove = parentSide === 'long'
                ? price <= (lastPriceRef - effectiveStepPrice)
                : price >= (lastPriceRef + effectiveStepPrice);
            if (nextLevelIndex === 0) {
                const leakMinAdverseReady = leakMinAdversePercentFromBase > 0
                    ? adversePercentFromBase >= leakMinAdversePercentFromBase
                    : !(leakMinAdversePointsFromBase > 0) || adversePointsFromBase >= leakMinAdversePointsFromBase;
                adverseMove = adverseMove && leakMinAdverseReady;
            }
            if (!adverseMove) continue;

            if (Number(sideState.martingaleCount || 0) >= maxMartingaleAddsBeforeRed) {
                const closedLatest = await tryCloseLatestPrimaryTakeProfit(parentSide, 'grid_take_profit');
                if (closedLatest) {
                    syncCycleStates();
                    return;
                }
                const activated = await activateRed(parentSide);
                if (activated) {
                    syncCycleStates();
                    return;
                }
                continue;
            }

            const levelFactor = resolveMartingaleFactorForLevel(nextLevelIndex);
            // Martingale por nivel baseado no tamanho da ordem base (nao composto sobre a ultima).
            const nextQty = normalizeOrderQtySafe(baseQtyRef * levelFactor);
            const opened = await openPosition(
                parentSide,
                nextQty,
                `martingale_level_${Number(sideState.martingaleCount || 0) + 1}`,
                parentSide,
                { isHedge: false }
            );
            if (!opened) continue;

            sideState.martingaleCount = Number(sideState.martingaleCount || 0) + 1;
            sideState.lastGridPrice = price;
            sideState.lastQuantity = nextQty;
            // Ao atingir o ultimo nivel de martingale, aguarda a proxima oportunidade adversa para ativar o RED.
            syncCycleStates();
            return;
        }

        if (!runtimeStrictMode) {
            for (const parentSide of ['long', 'short'] as Array<'long' | 'short'>) {
                const snapshot = computeCycleSnapshot(parentSide);
                if (snapshot.count <= 0) continue;
                const holdingMs = nowTs - snapshot.oldestOpen;
                const minRequiredNet = Math.max(minCompensationNetUsd, holdingCloseMinNetUsd);

                if (maxHoldingMs > 0 && holdingMs >= maxHoldingMs) {
                    const closed = await closeEntireCycle(parentSide, 'max_holding_cycle_close', minRequiredNet, false);
                    if (closed && prolongedExposureCycleReset) state._cycleReset = true;
                    syncCycleStates();
                    continue;
                }

                if (prolongedExposureMs > 0 && holdingMs >= prolongedExposureMs) {
                    const prolongedNet = Math.max(minRequiredNet, prolongedExposureMinCycleNetUsd);
                    const closed = await closeEntireCycle(parentSide, 'prolonged_exposure_cycle_close', prolongedNet, false);
                    if (closed && prolongedExposureCycleReset) state._cycleReset = true;
                    syncCycleStates();
                }
            }
        }

        const openBaseCycleSide = async (
            sideToOpen: 'long' | 'short',
            reason: string
        ): Promise<boolean> => {
            const isBaseOpenReason = (reasonRaw: any, sideRef: 'long' | 'short'): boolean => {
                const r = String(reasonRaw || '').trim().toLowerCase();
                return r === 'bootstrap_primary' || r === 'bootstrap_paired' || r === `cycle_reentry_${sideRef}`;
            };
            const sideState = cycleSideState(sideToOpen);
            const cyclePositionsForSide = getCyclePositions(sideToOpen);
            const hasHedgeInCycle = cyclePositionsForSide.some((item) => this.isGridHedgePosition(item?.pos));
            const hasMartingaleExposure = Number(sideState.martingaleCount || 0) > 0;
            const hasProtectedCycleExposure = !!sideState.redActive
                || !!sideState.redRebuildPhaseActive
                || hasHedgeInCycle
                || hasMartingaleExposure;
            if (isBaseOpenReason(reason, sideToOpen) && hasProtectedCycleExposure) {
                this.logger.warn(
                    `[MARKETKING][BASE LOCK] ${sideToOpen.toUpperCase()} bloqueado: ` +
                    `lado ainda possui MG/hedge/red ativos ` +
                    `(mg=${Number(sideState.martingaleCount || 0)} hedge=${hasHedgeInCycle ? 1 : 0} red=${sideState.redActive ? 1 : 0}).`
                );
                return false;
            }
            // CORRIGIDO: Verificar se hÃ¡ RED ativo antes de permitir novo ciclo
            // NÃ£o permitir iniciar novo ciclo quando hÃ¡ RED ativo no mesmo lado
            if (isRedEntryLockActive(sideToOpen)) {
                this.logger.warn(
                    `[MARKETKING][RED] Tentativa de iniciar ciclo ${sideToOpen.toUpperCase()} bloqueada: ` +
                    `RED ativo neste lado. Deve aguardar liquidaÃ§Ã£o completa do RED antes de iniciar novo ciclo.`
                );
                return false;
            }
            const hasAnyPrimaryForSide = getCyclePositions(sideToOpen).some((item) => {
                if (item.side !== sideToOpen) return false;
                const pos = item.pos as any;
                return !!(pos && !this.isGridHedgePosition(pos));
            });
            if (hasAnyPrimaryForSide) {
                this.logger.warn(
                    `[MARKETKING][BASE LOCK] ${sideToOpen.toUpperCase()} bloqueado: ja existe primaria aberta neste lado.`
                );
                return false;
            }
            const hasExistingBase = getCyclePositions(sideToOpen).some((item) => {
                if (item.side !== sideToOpen) return false;
                const pos = item.pos as any;
                if (!pos || this.isGridHedgePosition(pos)) return false;
                const openReason = this.getGridOpenReason(pos);
                return isBaseOpenReason(openReason, sideToOpen);
            });
            if (hasExistingBase) {
                this.logger.warn(
                    `[MARKETKING][BASE LOCK] ${sideToOpen.toUpperCase()} bloqueado: base ja existente neste lado.`
                );
                return false;
            }

            const baseQtyRaw = this.quantityFromConfiguredOpenAmount(
                config,
                Number(config.amount || 0),
                price,
                false
            );
            const baseQty = normalizeOrderQtySafe(baseQtyRaw);
            if (!(baseQty > 0)) {
                logGridQtyBlocked(sideToOpen, reason, baseQtyRaw, baseQty);
                return false;
            }

            const opened = await openPosition(sideToOpen, baseQty, reason, sideToOpen, { isHedge: false });
            if (!opened) return false;

            sideState.active = true;
            sideState.lastGridPrice = price;
            sideState.atrLastReferencePrice = price;
            sideState.lastQuantity = baseQty;
            sideState.cycleStartTime = nowTs;
            sideState.cycleStartBalance = Math.max(1, Number(accountEquity || 0));
            sideState.martingaleCount = 0;
            // CORRIGIDO: NÃ£o resetar RED ao abrir nova posiÃ§Ã£o base
            // RED sÃ³ deve ser resetado quando todas as posiÃ§Ãµes primÃ¡rias sÃ£o fechadas
            // Se RED estava ativo, mantém ativo (será desativado em syncCycleStates quando apropriado)
            sideState.redRebuildCount = 0;
            {this.logger.info(
                    `[MARKETKING] Ciclo ${sideToOpen.toUpperCase()} iniciado (${reason}) (price=${price.toFixed(6)} ema=${ema.toFixed(6)}).`
                );
            }
            return true;
        };

        syncCycleStates();

        // Bootstrap institucional legado: quando nao ha ciclo ativo, abrir os dois lados no mesmo tick.
        // CORRIGIDO: Permitir bootstrap/reentry do lado oposto mesmo quando hÃ¡ RED ativo em um lado
        const longState = cycleSideState('long');
        const shortState = cycleSideState('short');
        const longHasPrimaries = getCyclePositions('long').some((d) => !this.isGridHedgePosition(d?.pos));
        const shortHasPrimaries = getCyclePositions('short').some((d) => !this.isGridHedgePosition(d?.pos));
        const hasAnyPrimaries = longHasPrimaries || shortHasPrimaries;
        const longHasRed = longState && longState.redActive;
        const shortHasRed = shortState && shortState.redActive;

        // Se nÃ£o hÃ¡ posiÃ§Ãµes primÃ¡rias, verificar se pode fazer bootstrap
        if (!hasAnyPrimaries) {
            // Se nÃ£o hÃ¡ RED ativo em nenhum lado, fazer bootstrap normal (ambos os lados)
            if (!longHasRed && !shortHasRed) {
                // Verificar se realmente nÃ£o hÃ¡ ciclo ativo antes de fazer bootstrap completo
                if (!anyActiveCycle()) {
                    let preferredSide: 'long' | 'short' = price >= ema ? 'long' : 'short';
                    if (price === ema) {
                        this.logger.info(
                            `[MARKETKING] Bootstrap neutro (price == ema). Usando LONG como lado primario por fallback.`
                        );
                        preferredSide = 'long';
                    }
                    const pairedSide: 'long' | 'short' = preferredSide === 'long' ? 'short' : 'long';

                    const openedPreferred = await openBaseCycleSide(preferredSide, 'bootstrap_primary');
                    const delayBootstrapMs = Math.max(0, Number(config.orderDelayMs ?? 1500));
                    if (delayBootstrapMs > 0) {
                        this.logger.execution(`Y" [MARKETKING] Aguardando ${delayBootstrapMs}ms entre abertura ${preferredSide.toUpperCase()} e ${pairedSide.toUpperCase()} (bootstrap).`);
                        await this.sleep(delayBootstrapMs);
                    }
                    const openedPaired = await openBaseCycleSide(pairedSide, 'bootstrap_paired');

                    if (!openedPreferred || !openedPaired) {
                        this.logger.warn(
                            `[MARKETKING] Bootstrap parcial: preferred=${preferredSide} opened=${openedPreferred} | paired=${pairedSide} opened=${openedPaired}`
                        );
                    }
                    syncCycleStates();
                    logGridStatusSummary('bootstrap');
                    return;
                }
            }
            // Se hÃ¡ RED ativo em um lado, fazer bootstrap apenas do lado oposto (mesmo se anyActiveCycle retorna true)
            if (longHasRed && !shortHasRed) {
                // RED LONG ativo - fazer bootstrap apenas SHORT
                const openedShort = await openBaseCycleSide('short', 'bootstrap_primary');
                if (openedShort) {
                    syncCycleStates();
                    logGridStatusSummary('bootstrap_opposite_red');
                }
                return;
            }
            if (shortHasRed && !longHasRed) {
                // RED SHORT ativo - fazer bootstrap apenas LONG
                const openedLong = await openBaseCycleSide('long', 'bootstrap_primary');
                if (openedLong) {
                    syncCycleStates();
                    logGridStatusSummary('bootstrap_opposite_red');
                }
                return;
            }
            // Se hÃ¡ RED em ambos os lados (raro), aguardar que seja desativado
            return;
        }
        // Reentrada imediata por lado: se um lado zerou, tenta reabrir no mesmo tick.
        // CORRIGIDO: NÃ£o permitir reentry quando hÃ¡ RED ativo - RED deve ser totalmente liquidado primeiro
        let reopenedAny = false;
        const longStateForReentry = cycleSideState('long');
        const shortStateForReentry = cycleSideState('short');
        const longCanReenter = !longStateForReentry.active && !isRedEntryLockActive('long');
        const shortCanReenter = !shortStateForReentry.active && !isRedEntryLockActive('short');
        if (longCanReenter) {
            const reopenedLong = await openBaseCycleSide('long', 'cycle_reentry_long');
            reopenedAny = reopenedAny || reopenedLong;
        }
        if (shortCanReenter) {
            const delayReentryMs = Math.max(0, Number(config.orderDelayMs ?? 1500));
            if (delayReentryMs > 0 && longCanReenter) {
                this.logger.execution(`Y" [MARKETKING] Aguardando ${delayReentryMs}ms entre reentry LONG e SHORT.`);
                await this.sleep(delayReentryMs);
            }
            const reopenedShort = await openBaseCycleSide('short', 'cycle_reentry_short');
            reopenedAny = reopenedAny || reopenedShort;
        }
        if (reopenedAny) {
            syncCycleStates();
        }
        logGridStatusSummary(reopenedAny ? 'reentry' : 'tick');
    }

    private quantityFromUsdtAmount(amountUsdt: number, price: number): number {
        const safeAmount = Number(amountUsdt || 0);
        const safePrice = Number(price || 0);
        if (!Number.isFinite(safeAmount) || !Number.isFinite(safePrice) || safePrice <= 0) return 0;
        return safeAmount / safePrice;
    }

    private computeDemoCapitalSnapshot(
        state: MarketKingTradingState,
        config: MarketKingTradingConfig,
        currentPrice: number,
        takerFeeRate: number,
        dailyInterestRate: number
    ): { equity: number; availableQuote: number; lockedMargin: number; unrealizedNet: number } {
        const demoInitialBalance = Math.max(0, Number(config.demoInitialBalance ?? config.bankroll ?? 1000));
        const safePrice = Number.isFinite(currentPrice) && currentPrice > 0 ? currentPrice : 0;
        const safeFeeRate = Math.max(0, Number(takerFeeRate ?? config.defaultTakerFeeRate ?? 0.0006));
        const safeDailyRate = this.resolveEffectiveCarryRate(config, dailyInterestRate);
        const slippagePercent = Math.max(0, Number(config.slippagePercent ?? 0.00015));
        const slippageModel = String(config.slippageModel ?? 'two_sided').toLowerCase() === 'one_sided'
            ? 'one_sided'
            : 'two_sided';
        const leverageDefault = Math.max(1, Number(config.leverage || 1));

        let lockedMargin = 0;
        let unrealizedNet = 0;

        for (const pos of Array.isArray(state.activePositions) ? state.activePositions : []) {
            const qty = Math.max(0, Number(pos.quantity || 0));
            const entry = Math.max(0, Number(pos.entryPrice || 0));
            const lev = Math.max(1, Number(pos.leverage || leverageDefault));
            const initialMargin = Number.isFinite(Number(pos.initialMargin))
                ? Math.max(0, Number(pos.initialMargin))
                : ((entry * qty) / lev);
            lockedMargin += initialMargin;

            if (safePrice > 0 && qty > 0 && entry > 0) {
                const borrowedAmount = Math.max(0, Number(pos.borrowedAmount || 0));
                const interest = this.calculateAccumulatedInterest(
                    borrowedAmount,
                    Number(pos.openTime || Date.now()),
                    Number(pos.dailyInterestRate || safeDailyRate),
                    config
                );
                unrealizedNet += this.calculateNetProfitWithLoan(
                    entry,
                    safePrice,
                    qty,
                    safeFeeRate,
                    interest,
                    'long',
                    slippagePercent,
                    slippageModel
                );
            }
        }

        for (const sp of Array.isArray(state.shortPositions) ? state.shortPositions : []) {
            const qty = Math.max(0, Number(sp.quantity || 0));
            const entry = Math.max(0, Number(sp.price || 0));
            const lev = Math.max(1, Number(sp.leverage || leverageDefault));
            const fallbackInitial = (entry * qty) / lev;
            const initialMargin = Number.isFinite(Number(sp.initialMargin))
                ? Math.max(0, Number(sp.initialMargin))
                : fallbackInitial;
            lockedMargin += initialMargin;

            if (safePrice > 0 && qty > 0 && entry > 0) {
                const fallbackBorrowed = Math.max(0, Number(sp.price || 0) * Number(sp.borrowedQuantity || sp.quantity || 0));
                const borrowedAmount = Number.isFinite(Number(sp.borrowedAmount))
                    ? Math.max(0, Number(sp.borrowedAmount))
                    : fallbackBorrowed;
                const interest = this.calculateAccumulatedInterest(
                    borrowedAmount,
                    Number(sp.openTime || Date.now()),
                    Number(sp.dailyInterestRate || safeDailyRate),
                    config
                );
                unrealizedNet += this.calculateNetProfitWithLoan(
                    entry,
                    safePrice,
                    qty,
                    safeFeeRate,
                    interest,
                    'short',
                    slippagePercent,
                    slippageModel
                );
            }
        }

        const safeLockedMargin = Number.isFinite(lockedMargin) ? Math.max(0, lockedMargin) : 0;
        const safeUnrealizedNet = Number.isFinite(unrealizedNet) ? unrealizedNet : 0;
        const realizedProfit = Number.isFinite(Number(state.totalProfit)) ? Number(state.totalProfit) : 0;
        const baseWallet = demoInitialBalance + realizedProfit;
        const availableQuote = Math.max(0, baseWallet - safeLockedMargin);
        const equity = Math.max(0, baseWallet + safeUnrealizedNet);
        return { equity, availableQuote, lockedMargin: safeLockedMargin, unrealizedNet: safeUnrealizedNet };
    }

    private computeNotionalAndMargin(price: number, quantity: number, leverage: number): {
        notional: number;
        initialMargin: number;
        borrowedAmount: number;
    } {
        const safePrice = Number(price || 0);
        const safeQty = Number(quantity || 0);
        const safeLeverage = Math.max(1, Number(leverage || 1));
        const notional = safePrice * safeQty;
        const initialMargin = notional / safeLeverage;
        const borrowedAmount = Math.max(0, notional - initialMargin);
        return { notional, initialMargin, borrowedAmount };
    }

    private calculateBorrowedAmountForLong(
        positionValue: number,
        leverage: number
    ): number {
        // Se leverage = 2x, precisa emprestar 50% do valor da posicao
        // Se leverage = 10x, precisa emprestar 90% do valor da posicao
        const ownCapital = positionValue / leverage;
        return positionValue - ownCapital;
    }

    /**
    * Novo: Calcula quantidade emprestada ao abrir posicao SHORT
    * Para SHORT: Empresta tokens para vender
    * Borrowed Quantity = Quantity (vende tokens emprestados)
    */
    private calculateBorrowedQuantityForShort(
        quantity: number
    ): number {
        return quantity; // Em SHORT, empresta a quantidade de tokens vendidos
    }

    /**
    * Novo: Calcula juros acumulados desde a abertura da posicao
    */
    private calculateAccumulatedInterest(
        borrowedAmount: number,
        openTime: number,
        dailyInterestRate: number = 0,
        config?: MarketKingTradingConfig // o. Opcional: para usar taxa padrao do config
    ): number {
        // Delegate to shared util
        if (config && this.isFuturePerpetualTrade(config)) return 0;
        const parsedRate = Number(dailyInterestRate);
        const effectiveRate = (Number.isFinite(parsedRate) && parsedRate >= 0)
            ? parsedRate
            : Number(config?.defaultDailyInterestRate ?? 0.0001);
        return calcAccumulatedInterest(borrowedAmount, openTime, effectiveRate);
    }

    /**
    * Novo: Calcula profit liquido considerando juros do emprestimo
    */
    private calculateNetProfitWithLoan(
        entryPrice: number,
        exitPrice: number,
        quantity: number,
        takerFeeRate: number,
        accumulatedInterest: number,
        side: 'long' | 'short',
        slippagePercent: number = 0,
        slippageModel: 'one_sided' | 'two_sided' = 'one_sided'
    ): number {
        return calcNetProfitWithLoan(
            entryPrice,
            exitPrice,
            quantity,
            takerFeeRate,
            accumulatedInterest,
            side,
            slippagePercent,
            slippageModel
        );
    }

    /**
     * Fecha uma única posição por ID (usado pelo fluxo institutional_hybrid).
     */
    private async closeSinglePositionById(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        balances: any,
        symbolInfo: any,
        price: number,
        dailyInterestRate: number,
        isDemo: boolean,
        isReal: boolean,
        positionId: string,
        reason: string,
        forceLoss: boolean,
        quantityOverride?: number,
        executionModeOverride?: 'maker' | 'taker' | 'market',
        cycleNetApproved: boolean = false,
        cycleCloseId?: string
    ): Promise<{ status: 'closed' | 'pending' | 'blocked'; orderId?: string }> {
        const idStr = String(positionId || '').trim();
        if (!idStr) return { status: 'blocked' };

        const longArr = state.activePositions ?? [];
        const shortArr = state.shortPositions ?? [];
        let side: 'long' | 'short';
        let idx = longArr.findIndex((p: any) => String(p.positionId ?? '').trim() === idStr);
        if (idx >= 0) {
            side = 'long';
        } else {
            idx = shortArr.findIndex((p: any) => String((p as any).positionId ?? '').trim() === idStr);
            if (idx < 0) return { status: 'blocked' };
            side = 'short';
        }

        const sideArray: any[] = side === 'long' ? (longArr as any[]) : (shortArr as any[]);
        const pos: any = sideArray[idx];
        const maxQty = Math.max(0, Number((pos as any).quantity ?? 0));
        const qty = Number.isFinite(Number(quantityOverride))
            ? Math.min(maxQty, Math.max(0, Number(quantityOverride)))
            : maxQty;
        if (!(qty > 0)) return { status: 'blocked' };

        const normalizedExecutionModeOverride = executionModeOverride
            ? this.normalizeExecutionMode(executionModeOverride, 'maker')
            : undefined;
        const closeNotional = Math.max(0, Number(price || 0) * qty);
        const minNetThreshold = this.isInstitutionalHybridStrategy(config)
            ? this.resolveMinNetFloor(config)
            : this.resolveRuntimeCloseMinNetForReason(config, reason, forceLoss, closeNotional);
        const cycleNetApprovedMaxLegLossUsd = Math.max(0, Number(config.cycleNetApprovedMaxLegLossUsd ?? 0.25));
        const cycleNetApprovedForClose = cycleNetApproved
            && !String(reason || '').toLowerCase().includes('reserve_settle');
        if (!forceLoss && Number.isFinite(Number(minNetThreshold))) {
            const runtimeNet = this.estimateRuntimeCloseNet(
                config,
                symbolInfo,
                pos,
                side,
                price,
                qty,
                Number((pos as any)?.dailyInterestRate ?? dailyInterestRate ?? config.defaultDailyInterestRate ?? 0),
                normalizedExecutionModeOverride
            );
            const runtimeMinNetThreshold = cycleNetApprovedForClose
                ? -cycleNetApprovedMaxLegLossUsd
                : Number(minNetThreshold);
            if (!Number.isFinite(runtimeNet) || runtimeNet < runtimeMinNetThreshold) {
                this.logger.warn(
                    `[INSTITUTIONAL_HYBRID] Close ${side.toUpperCase()} bloqueado por piso net runtime: ` +
                    `positionId=${idStr} reason=${String(reason || 'institutional_hybrid_signal')} ` +
                    `net=${Number.isFinite(runtimeNet) ? runtimeNet.toFixed(4) : 'NaN'} ` +
                    `piso=${runtimeMinNetThreshold.toFixed(4)} ` +
                    `limitePerna=${cycleNetApprovedForClose ? cycleNetApprovedMaxLegLossUsd.toFixed(4) : 'n/a'} ` +
                    `exec=${normalizedExecutionModeOverride ?? 'auto'}`
                );
                return { status: 'blocked' };
            }
            const minHoldMin = Math.max(0, Number(config.minHoldMinutesBeforeNegativeClose ?? 0));
            if (!cycleNetApprovedForClose && minHoldMin > 0 && runtimeNet < 0) {
                const openTime = Number((pos as any).openTime ?? 0);
                const ageMs = openTime > 0 ? Math.max(0, Date.now() - openTime) : 0;
                if (ageMs < minHoldMin * 60 * 1000) {
                    this.logger.warn(
                        `[INSTITUTIONAL_HYBRID] Close ${side.toUpperCase()} bloqueado por gatilho (tempo minimo antes de negativo): ` +
                        `positionId=${idStr} reason=${String(reason)} net=${runtimeNet.toFixed(4)} ` +
                        `idade=${(ageMs / 60000).toFixed(1)}min < ${minHoldMin}min`
                    );
                    return { status: 'blocked' };
                }
            }
        }

        if (idx !== sideArray.length - 1) {
            sideArray.splice(idx, 1);
            sideArray.push(pos);
            if (side === 'long') {
                state.buyPrices = state.activePositions.map((p) => Number(p.entryPrice || 0));
            }
        }

        state._nextCloseQuantity = qty;
        state._nextCloseReason = reason;
        // Fechamentos institucionais normais devem respeitar minNet.
        // Only force-loss/emergency flows are allowed to bypass the local positive/minNet gate.
        state._nextCloseIgnorePositiveCheck = !!forceLoss;
        state._nextCloseExecutionModeOverride = normalizedExecutionModeOverride;
        state._nextOrderPositionIdOverride = idStr;
        (state as any)._nextCloseCycleNetApproved = cycleNetApprovedForClose;
        (state as any)._nextCloseCycleId = String(cycleCloseId || '').trim() || undefined;
        state.forceStopLossSell = forceLoss;

        const beforeQty = maxQty;
        const beforePendingOrderId = String(state._lastPendingCloseOrderId || '');
        const beforePendingSentAt = Number(state._lastPendingCloseSentAt || 0);

        try {
            const action = side === 'long' ? 'sell' : 'buy';
            await this.executeMarginOrder(
                action,
                price,
                balances,
                symbolInfo,
                client,
                config,
                state,
                dailyInterestRate,
                isDemo,
                isReal
            );

            const updatedSideArray: any[] = side === 'long'
                ? (state.activePositions ?? [])
                : (state.shortPositions ?? []);
            const updatedPosition = updatedSideArray.find((candidate: any) => String(candidate?.positionId ?? '').trim() === idStr);
            const updatedQty = Math.max(0, Number(updatedPosition?.quantity ?? 0));
            const closeQtyTolerance = 1e-10;

            if (!updatedPosition || updatedQty < (beforeQty - closeQtyTolerance)) {
                return { status: 'closed' };
            }

            const afterPendingOrderId = String(state._lastPendingCloseOrderId || '');
            const afterPendingSentAt = Number(state._lastPendingCloseSentAt || 0);
            const pendingCloseRegistered =
                isReal &&
                state._lastPendingCloseSide === side &&
                (
                    (afterPendingOrderId !== '' && afterPendingOrderId !== beforePendingOrderId) ||
                    (afterPendingSentAt > beforePendingSentAt)
                );

            if (pendingCloseRegistered) {
                return {
                    status: 'pending',
                    orderId: afterPendingOrderId || undefined
                };
            }

            return { status: 'blocked' };
        } finally {
            state.forceStopLossSell = false;
            if (state._nextCloseQuantity !== undefined) delete state._nextCloseQuantity;
            if (state._nextCloseReason !== undefined) delete state._nextCloseReason;
            if (state._nextCloseIgnorePositiveCheck !== undefined) delete state._nextCloseIgnorePositiveCheck;
            if (state._nextCloseExecutionModeOverride !== undefined) delete state._nextCloseExecutionModeOverride;
            if (state._nextOrderPositionIdOverride !== undefined) delete state._nextOrderPositionIdOverride;
            delete (state as any)._nextCloseCycleNetApproved;
            delete (state as any)._nextCloseCycleId;
        }
    }

    /**
     * Abre uma única posição explicitamente validada pelo motor institutional_hybrid
     * usando o mesmo executeMarginOrder do runtime.
     */
    private async openSingleInstitutionalPosition(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        balances: any,
        symbolInfo: any,
        currentPrice: number,
        dailyInterestRate: number,
        isDemo: boolean,
        isReal: boolean,
        plan: {
            positionId: string;
            side: 'long' | 'short';
            quantity: number;
            price: number;
            takePrice: number;
            parentSide: 'long' | 'short';
            reason: string;
            isHedge: boolean;
            executionMode?: 'maker' | 'taker' | 'market';
            closeExecutionMode?: 'maker' | 'taker' | 'market';
            sourceQuoteId?: string;
            makerFeeRate?: number;
            takerFeeRate?: number;
            makerSlippagePercent?: number;
            takerSlippagePercent?: number;
            openFeeRate?: number;
            closeFeeRate?: number;
            openSlippagePercent?: number;
            closeSlippagePercent?: number;
            fundingRatePer8h?: number;
            fundingIntervalHours?: number;
            fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
        }
    ): Promise<boolean> {
        const side = plan?.side === 'short' ? 'short' : 'long';
        const quantity = Math.max(0, Number(plan?.quantity ?? 0));
        const executionPrice = Math.max(0, Number(plan?.price ?? currentPrice ?? 0));
        if (!(quantity > 0) || !(executionPrice > 0)) return false;

        const beforeCount = side === 'long'
            ? Number(state.activePositions?.length || 0)
            : Number(state.shortPositions?.length || 0);

        state._nextOrderAmountUsdt = quantity * executionPrice;
        state._forceOpenSide = side;
        state._nextOrderPositionIdOverride = String(plan?.positionId || '').trim() || undefined;
        state._nextOrderForceExecutionMode = plan?.executionMode
            ? this.normalizeExecutionMode(plan.executionMode, 'market')
            : undefined;
        state._nextOrderInstitutionalMeta = {
            takePrice: Number.isFinite(Number(plan?.takePrice)) ? Number(plan.takePrice) : undefined,
            makerFeeRate: Number.isFinite(Number(plan?.makerFeeRate)) ? Number(plan.makerFeeRate) : undefined,
            takerFeeRate: Number.isFinite(Number(plan?.takerFeeRate)) ? Number(plan.takerFeeRate) : undefined,
            makerSlippagePercent: Number.isFinite(Number(plan?.makerSlippagePercent)) ? Number(plan.makerSlippagePercent) : undefined,
            takerSlippagePercent: Number.isFinite(Number(plan?.takerSlippagePercent)) ? Number(plan.takerSlippagePercent) : undefined,
            openFeeRate: Number.isFinite(Number(plan?.openFeeRate)) ? Number(plan.openFeeRate) : undefined,
            closeFeeRate: Number.isFinite(Number(plan?.closeFeeRate)) ? Number(plan.closeFeeRate) : undefined,
            openSlippagePercent: Number.isFinite(Number(plan?.openSlippagePercent)) ? Number(plan.openSlippagePercent) : undefined,
            closeSlippagePercent: Number.isFinite(Number(plan?.closeSlippagePercent)) ? Number(plan.closeSlippagePercent) : undefined,
            openExecutionMode: plan?.executionMode
                ? this.normalizeExecutionMode(plan.executionMode, 'maker')
                : undefined,
            closeExecutionMode: plan?.closeExecutionMode
                ? this.normalizeExecutionMode(plan.closeExecutionMode, 'maker')
                : undefined,
            dailyInterestRate: Number.isFinite(Number(dailyInterestRate)) ? Number(dailyInterestRate) : undefined,
            fundingRatePer8h: Number.isFinite(Number(plan?.fundingRatePer8h)) ? Number(plan.fundingRatePer8h) : undefined,
            fundingIntervalHours: Number.isFinite(Number(plan?.fundingIntervalHours)) ? Number(plan.fundingIntervalHours) : undefined,
            fundingFeeMode: plan?.fundingFeeMode,
            sourceQuoteId: String(plan?.sourceQuoteId || '').trim() || undefined,
        };
        state._nextOrderParentSide = plan?.parentSide === 'short' ? 'short' : 'long';
        state._nextOrderIsHedge = !!plan?.isHedge;
        state._nextOrderReason = String(plan?.reason || 'institutional_hybrid_quote_fill');

        await this.executeMarginOrder(
            side === 'long' ? 'buy' : 'sell',
            executionPrice,
            balances,
            symbolInfo,
            client,
            config,
            state,
            dailyInterestRate,
            isDemo,
            isReal
        );

        const afterCount = side === 'long'
            ? Number(state.activePositions?.length || 0)
            : Number(state.shortPositions?.length || 0);

        return afterCount > beforeCount;
    }

    /**
     * Sincroniza o book de ordens limit com as desiredQuotes do motor institutional_hybrid.
     * Em REAL: cancela ordens que não estão em desiredQuotes e coloca as que faltam. Em DEMO: no-op.
     */
    private async syncDesiredQuotes(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        symbolInfo: any,
        desiredQuotes: Array<{
            id: string;
            side: 'bid' | 'ask';
            level: number;
            price: number;
            quantity: number;
            reduceOnly: boolean;
            positionId?: string;
            reason?: string;
            takePrice?: number;
            parentSide?: 'long' | 'short';
            isHedge?: boolean;
            sourceQuoteId?: string;
            openExecutionMode?: 'maker' | 'taker' | 'market';
            closeExecutionMode?: 'maker' | 'taker' | 'market';
            makerFeeRate?: number;
            takerFeeRate?: number;
            makerSlippagePercent?: number;
            takerSlippagePercent?: number;
            openFeeRate?: number;
            closeFeeRate?: number;
            openSlippagePercent?: number;
            closeSlippagePercent?: number;
            fundingRatePer8h?: number;
            fundingIntervalHours?: number;
            fundingFeeMode?: 'none' | 'signed' | 'absolute_cost';
        }>,
        isReal: boolean,
        balances?: any
    ): Promise<void> {
        await this.marginOperations.syncDesiredQuotesInstitutional(client, config, state, symbolInfo, desiredQuotes, isReal, balances);
    }

    private shouldEnforceRealExecutionChecks(
        config: MarketKingTradingConfig,
        isDemo: boolean,
        isReal: boolean
    ): boolean {
        if (isReal) return true;
        if (!isDemo) return false;
        return config.demoMirrorRealRiskChecksInDemo !== false;
    }

    /** Executa ordem na corretora (Margin/Future; Long e Short). Cálculo de empréstimos e juros. */
    private async executeMarginOrder(
        action: string,
        price: number,
        balances: any,
        symbolInfo: any,
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        dailyInterestRate: number = 0,
        isDemo: boolean = false, // o. Modo DEMO (passado como parametro)
        isReal: boolean = true // o. Modo REAL (passado como parametro)
    ): Promise<void> {
        // Crítico: Se nao passado como parametro, determinar do config
        const mode = config.opType?.toLowerCase().trim() || 'demo';
        const finalIsDemo = isDemo !== undefined ? isDemo : (mode === 'demo');
        const finalIsReal = isReal !== undefined ? isReal : (mode === 'real');
        const safeLeverage = Math.max(1, Number(config.leverage || 1));
        const isPerpRuntime = this.isFuturePerpetualTrade(config);
        // Quantidade padrao (quando e ABERTURA usando "amount" em moeda de cotacao).
        // Em FECHAMENTO, a quantidade deve ser a mesma da posicao aberta (nao "amount/preco atual").
        const hasExplicitAmountOverride = state._nextOrderAmountUsdt !== undefined;
        let requestedMarginUsdt = Number(state._nextOrderAmountUsdt ?? config.amount);
        const isMarginBase = this.resolveAmountSizingMode() === 'margin_base';
        const forceOpenLongEarly = state._forceOpenSide === 'long' && action === 'buy';
        const forceOpenShortEarly = state._forceOpenSide === 'short' && action === 'sell';
        const isClosingShortEarly = action === 'buy' && state.shortPositions && state.shortPositions.length > 0 && !forceOpenLongEarly;
        const isOpeningLongEarly = action === 'buy' && (forceOpenLongEarly || !isClosingShortEarly);
        const isClosingLongEarly = action === 'sell' && (state.buyPrices.length > 0 || state.activePositions.length > 0) && !forceOpenShortEarly;
        const parentSideCtxEarly = state._nextOrderParentSide;
        const isOpenRequestCtxEarly = parentSideCtxEarly === 'long' || parentSideCtxEarly === 'short';
        const isOpeningShortEarly = action === 'sell' && (
            forceOpenShortEarly
            || (isOpenRequestCtxEarly && !isClosingLongEarly)
            || (
                state.buyPrices.length === 0
                && state.activePositions.length === 0
                && (!state.shortPositions || state.shortPositions.length < config.maxOperations)
            )
        );
        const isOpeningOrderEarly = isOpeningLongEarly || isOpeningShortEarly;
        if (
            isPerpRuntime
            && isMarginBase
            && isOpeningOrderEarly
            && requestedMarginUsdt > 0
            && !hasExplicitAmountOverride
        ) {
            const exchangeMinMargin = this.resolveExchangeMinMarginPerOrderUsd(symbolInfo, price, safeLeverage);
            const presetMinMargin = this.resolvePresetMinMarginPerOrderUsd(config);
            const brokerMinMargin = Math.max(exchangeMinMargin, presetMinMargin);
            if (brokerMinMargin > 0 && requestedMarginUsdt + 1e-9 < brokerMinMargin) {
                this.logger.warn(
                    `[SIZING] Ordem recusada sem aumento automatico de risco: margem solicitada ` +
                    `${requestedMarginUsdt.toFixed(4)} USDT abaixo do minimo ${brokerMinMargin.toFixed(4)} USDT ` +
                    `(exchange=${exchangeMinMargin.toFixed(4)}, preset=${presetMinMargin.toFixed(4)})`
                );
                return;
            }
        }
        // Normalização por bankroll: em REAL, limitar margem da ordem ao saldo disponível (reserva 5%) para evitar 40762.
        if (finalIsReal && isMarginBase && requestedMarginUsdt > 0) {
            const availableUsdt = this.getAvailableBalanceByCoin(balances, config.pair2);
            const reserveFactor = 0.95;
            const maxMarginUsd = Math.max(0, availableUsdt * reserveFactor);
            const minOrderMarginUsd = Math.max(
                this.resolveExchangeMinMarginPerOrderUsd(symbolInfo, price, safeLeverage),
                this.resolvePresetMinMarginPerOrderUsd(config),
                Math.max(0, Number((config as any).minOrderMarginUsd ?? 0))
            );
            const requestedInitialMarginUsd = hasExplicitAmountOverride && isPerpRuntime
                ? requestedMarginUsdt / safeLeverage
                : requestedMarginUsdt;
            if (requestedInitialMarginUsd > maxMarginUsd && maxMarginUsd >= minOrderMarginUsd) {
                if (hasExplicitAmountOverride) {
                    this.logger.warn(
                        `[SIZING] Ordem institucional recusada por margem disponivel: ` +
                        `notional=${requestedMarginUsdt.toFixed(2)} USDT margem=${requestedInitialMarginUsd.toFixed(2)} USDT ` +
                        `disponivelComReserva=${maxMarginUsd.toFixed(2)} USDT`
                    );
                    return;
                }
                this.logger.warn(
                    `[SIZING] Margem limitada ao saldo: config=${requestedMarginUsdt.toFixed(2)} USDT -> ${maxMarginUsd.toFixed(2)} USDT (disponivel=${availableUsdt.toFixed(2)}, reserva ${((1 - reserveFactor) * 100).toFixed(0)}%)`
                );
                requestedMarginUsdt = maxMarginUsd;
            } else if (maxMarginUsd < minOrderMarginUsd) {
                this.logger.warn(
                    `[SIZING] Saldo insuficiente para uma ordem minima: disponivel=${availableUsdt.toFixed(2)} USDT, minimo sugerido=${minOrderMarginUsd} USDT. Ordem nao enviada.`
                );
                return;
            }
        }
        const requestedAmountUsdt = this.resolveConfiguredOpenNotionalUsdt(
            config,
            requestedMarginUsdt,
            hasExplicitAmountOverride
        );
        let quantity = this.quantityFromUsdtAmount(requestedAmountUsdt, price);
        const executionSlippagePercent = Number(config.slippagePercent ?? 0.00015);
        const executionSlippageModel: 'one_sided' | 'two_sided' =
            (String(config.slippageModel ?? 'two_sided').toLowerCase() === 'one_sided' ? 'one_sided' : 'two_sided');
        const institutionalMeta = state._nextOrderInstitutionalMeta;
        const orderPositionIdOverride = String(state._nextOrderPositionIdOverride || '').trim();
        const enforceOperationalRiskChecks = this.shouldEnforceRealExecutionChecks(config, finalIsDemo, finalIsReal);
        const demoCapitalSnapshot = finalIsDemo && enforceOperationalRiskChecks
            ? this.computeDemoCapitalSnapshot(
                state,
                config,
                price,
                Number(symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0.0006),
                dailyInterestRate
            )
            : null;
        const availableQuoteBalanceForExecution = finalIsDemo
            ? Math.max(0, Number(demoCapitalSnapshot?.availableQuote ?? 0))
            : this.getAvailableBalanceByCoin(balances, config.pair2);
        const applyPerpExecutionModelToCloseProfit = (
            side: 'long' | 'short',
            positionRef: MarketKingLongPosition | MarketKingShortPosition | null | undefined,
            currentProfit: number,
            closeQty: number
        ): { profit: number; fundingCost: number; executionAdjust: number } => {
            if (!isPerpRuntime || !positionRef) return { profit: currentProfit, fundingCost: 0, executionAdjust: 0 };
            const executionProfile = this.buildPositionExecutionCostProfile(
                config,
                symbolInfo,
                positionRef,
                state._nextCloseExecutionModeOverride,
                executionSlippagePercent
            );
            const qty = Math.max(0, Number(closeQty || 0));
            const entry = Math.max(0, Number(this.getEntryPrice(side, positionRef as any)));
            const exit = Math.max(0, Number(price || 0));
            let executionAdjust = 0;
            if (qty > 0 && entry > 0 && exit > 0) {
                const assumedFee = (entry * qty * executionProfile.takerFeeRate) + (exit * qty * executionProfile.takerFeeRate);
                const assumedSlipOpen = entry * qty * Math.max(0, executionSlippagePercent);
                const assumedSlipClose = exit * qty * Math.max(0, executionSlippagePercent);
                const assumedSlip = executionSlippageModel === 'two_sided'
                    ? (assumedSlipOpen + assumedSlipClose)
                    : assumedSlipClose;
                const desiredFee = (entry * qty * executionProfile.openFeeRate) + (exit * qty * executionProfile.closeFeeRate);
                const desiredSlip = (entry * qty * executionProfile.openSlippagePercent) + (exit * qty * executionProfile.closeSlippagePercent);
                executionAdjust = (assumedFee + assumedSlip) - (desiredFee + desiredSlip);
            }
            const fundingCost = this.estimateFundingCost(
                config,
                side,
                positionRef as any,
                price,
                closeQty,
                symbolInfo
            );
            const safeFunding = Number.isFinite(fundingCost) ? fundingCost : 0;
            return {
                profit: Number(currentProfit || 0) + executionAdjust - safeFunding,
                fundingCost: safeFunding,
                executionAdjust
            };
        };
        const estimateCloseNetWithRuntimeModel = (
            side: 'long' | 'short',
            positionRef: MarketKingLongPosition | MarketKingShortPosition | null | undefined,
            closeQty: number
        ): number => {
            return this.estimateRuntimeCloseNet(
                config,
                symbolInfo,
                positionRef,
                side,
                price,
                closeQty,
                dailyInterestRate,
                state._nextCloseExecutionModeOverride
            );
        };
        // Correção: Taxa de juros ja vem como parametro (dailyInterestRate) da API

        if (quantity <= 0) return;

        // o. Consolidação DEMO/REAL: abrir vs fechar por action e estado
        // - action='sell' + há long => FECHAR LONG (não abre short; ordem reversa não conflita com short aberto).
        // - action='buy' + há short => FECHAR SHORT (não abre long).
        // Managed futures hedge: close long = side=buy tradeSide=close; close short = side=sell tradeSide=close.
        const forcedOpenSide = state._forceOpenSide;
        const parentSideContext = state._nextOrderParentSide;
        const isOpenRequestContext = parentSideContext === 'long' || parentSideContext === 'short';
        const forceOpenLong = forcedOpenSide === 'long' && action === 'buy';
        const forceOpenShort = forcedOpenSide === 'short' && action === 'sell';
        const isClosingShort = action === 'buy' && state.shortPositions && state.shortPositions.length > 0 && !forceOpenLong;
        const isOpeningLong = action === 'buy' && (forceOpenLong || !isClosingShort);
        // Correção crítica: Verificar tanto buyPrices quanto activePositions para detectar fechamento de LONG
        const isClosingLong = action === 'sell' && (state.buyPrices.length > 0 || state.activePositions.length > 0) && !forceOpenShort;
        const isOpeningShort = action === 'sell' && (
            forceOpenShort ||
            (isOpenRequestContext && !isClosingLong) ||
            (
                state.buyPrices.length === 0 &&
                state.activePositions.length === 0 &&
                (!state.shortPositions || state.shortPositions.length < config.maxOperations)
            )
        );
        const useCounterBypass = isOpenRequestContext;

        // Mesmo fluxo para REAL e DEMO: as validações usam saldo real ou capital simulado.
        if (isOpeningLong) {
            if (enforceOperationalRiskChecks) {
                const requiredMargin = requestedAmountUsdt / safeLeverage;
                const availableBalance = availableQuoteBalanceForExecution;
                if (availableBalance < requiredMargin) {
                    this.logger.error(
                        `O [${finalIsReal ? 'REAL' : 'DEMO'}] Saldo insuficiente para abrir LONG: ` +
                                `$${availableBalance.toFixed(2)} < Margem necessaria: $${requiredMargin.toFixed(2)} (Leverage ${safeLeverage}x)`
                    );
                    return;
                }
            } else if (finalIsDemo) {
                this.logger.info(`YZ [DEMO] Simulando abertura LONG sem validacao de saldo (demoMirrorRealRiskChecksInDemo=false)`);
            }
        }

        if (isOpeningShort) {
            if (enforceOperationalRiskChecks) {
                const requiredMargin = requestedAmountUsdt / safeLeverage;
                const availableBalance = availableQuoteBalanceForExecution;
                if (availableBalance < requiredMargin) {
                    this.logger.error(
                        `O [${finalIsReal ? 'REAL' : 'DEMO'}] Saldo insuficiente para abrir SHORT: ` +
                                `$${availableBalance.toFixed(2)} < Margem necessaria: $${requiredMargin.toFixed(2)} (Leverage ${safeLeverage}x)`
                    );
                    return;
                }
            } else if (finalIsDemo) {
                this.logger.info(`YZ [DEMO] Simulando abertura SHORT sem validacao de saldo (demoMirrorRealRiskChecksInDemo=false)`);
            }
        }

        if (!isPerpRuntime && isClosingShort && state.shortPositions && state.shortPositions.length > 0) {
            if (enforceOperationalRiskChecks) {
                const lastShort = state.shortPositions[state.shortPositions.length - 1];
                const requestedCloseQtyForBalance = Number(state._nextCloseQuantity);
                const shortCloseQtyForBalance = Number.isFinite(requestedCloseQtyForBalance) && requestedCloseQtyForBalance > 0
                    ? Math.min(lastShort.quantity || quantity, requestedCloseQtyForBalance)
                    : (lastShort.quantity || quantity);
                const requiredBalance = shortCloseQtyForBalance * price;
                const availableBalance = availableQuoteBalanceForExecution;
                if (availableBalance < requiredBalance) {
                    this.logger.error(
                        `O [${finalIsReal ? 'REAL' : 'DEMO'}] Saldo insuficiente para fechar SHORT: ` +
                                `$${availableBalance.toFixed(2)} < Necessario: $${requiredBalance.toFixed(2)}`
                    );
                    return;
                }
            } else if (finalIsDemo) {
                this.logger.info(`YZ [DEMO] Simulando fechamento SHORT sem validacao de saldo (demoMirrorRealRiskChecksInDemo=false)`);
            }
        }

        // o. Ajustar quantity para FECHAMENTO (usar quantity real da posicao)
        const PRICE_TOLERANCE_ORDER = config.priceTolerance ?? 0.0001;
        const requirePositiveClose = config.requirePositiveClose !== undefined ? !!config.requirePositiveClose : true;
        const requestedCloseQuantity = Number(state._nextCloseQuantity);
        const closeReason = typeof state._nextCloseReason === 'string' ? state._nextCloseReason : undefined;
        if (isClosingLong && (state.buyPrices.length > 0 || state.activePositions.length > 0)) {
            // Correção: Priorizar activePositions, usar buyPrices como fallback
            if (state.activePositions.length > 0) {
                const lastPosition = state.activePositions[state.activePositions.length - 1];
                quantity = lastPosition.quantity;
            } else if (state.buyPrices.length > 0) {
                const lastBuyPrice = state.buyPrices[state.buyPrices.length - 1];
                const position = state.activePositions.find(p =>
                    p.side === 'long' && Math.abs(p.entryPrice - lastBuyPrice) < PRICE_TOLERANCE_ORDER
                );
                if (position) {
                    quantity = position.quantity;
                } else if (lastBuyPrice > 0) {
                    // Fallback: posição não encontrada (ex.: estado antigo)
                    quantity = config.amount / lastBuyPrice;
                }
            }
        } else if (isClosingShort && state.shortPositions && state.shortPositions.length > 0) {
            const lastShort = state.shortPositions[state.shortPositions.length - 1];
            if (lastShort?.quantity) {
                quantity = lastShort.quantity;
            }
        }

        const closeNotionalForThreshold = Math.max(0, Number(price || 0) * Number(quantity || 0));
        const minNetCloseUsdConfigured = this.resolveMinNetCloseUsd(config, closeNotionalForThreshold);
        const runtimeMinNetForReason = this.resolveRuntimeCloseMinNetForReason(
            config,
            closeReason,
            !!state.forceStopLossSell,
            closeNotionalForThreshold
        );
        // Minimum profit required to allow a close (in USDT). Can be configured per bot.
        const MIN_CLOSE_PROFIT = this.isInstitutionalHybridStrategy(config)
            ? Number(
                Number.isFinite(Number(runtimeMinNetForReason))
                    ? runtimeMinNetForReason
                    : minNetCloseUsdConfigured
            )
            : Math.max(
                Number(config.minCloseProfitUsd ?? 0.5),
                Number(minNetCloseUsdConfigured)
            );

        const ignorePerPositionPositiveCheck = !!state._nextCloseIgnorePositiveCheck;
        const closeReasonTargetMatch = closeReason?.match(/^partial_target_(\d+(?:\.\d+)?)x$/i) ?? null;
        const closeReasonTargetMultiple = closeReasonTargetMatch ? Number(closeReasonTargetMatch[1]) : null;
        const holdingMaxAllowedNegativeUsd = Math.max(0, Number(config.holdingMaxAllowedNegativeUsd ?? 0));
        const holdingCloseMinNetUsdRaw = Number(config.holdingCloseMinNetUsd);
        const holdingCloseThreshold = Number.isFinite(holdingCloseMinNetUsdRaw)
            ? Math.max(holdingCloseMinNetUsdRaw, -holdingMaxAllowedNegativeUsd)
            : Number.NaN;
        const isHoldingOrProlongedClose = !!closeReason && (
            closeReason.includes('max_holding') || closeReason.includes('prolonged_exposure')
        );
        const allowManagedCycleBasketClose = !!closeReason && (
            closeReason.includes('managed_cycle_basket_positive')
            || closeReason.includes('managed_cycle_reserve_')
        );
        const strictExceptionOnlyNegativeClose = !!requirePositiveClose;
        const bypassPositiveCloseByStrategy = strictExceptionOnlyNegativeClose
            ? (
                ignorePerPositionPositiveCheck
                || allowManagedCycleBasketClose
                || (!!closeReason && closeReason.includes('red_total_settlement'))
            )
            : (ignorePerPositionPositiveCheck || (!!closeReason && (
                closeReason.includes('grid_take_profit') ||
                closeReason.includes('red_recovery_exit') ||
                closeReason.includes('red_total_settlement') ||
                closeReason.includes('red_rebase_unlock') ||
                closeReason.includes('red_close_entire') ||
                closeReason.includes('red_dilution') ||
                closeReason.includes('atr_dilution') ||
                closeReason.includes('max_holding_cycle_close') ||
                closeReason.includes('prolonged_exposure_cycle_close') ||
                closeReason.includes('managed_cycle_basket_positive') ||
                closeReason.includes('managed_cycle_reserve_')
            )));
        // forceLoss nao e uma autorizacao generica para realizar prejuizo.
        // O flatten gerencial pode reduzir exposicao, mas deve respeitar o piso de net;
        // somente motivos de emergencia comprovada podem furar essa protecao.
        const institutionalEmergencyForceLossClose = this.isInstitutionalHybridStrategy(config)
            && ignorePerPositionPositiveCheck
            && !!state.forceStopLossSell
            && this.isEmergencyForceCloseReason(closeReason);
        const allowRuntimeNetBypass = allowManagedCycleBasketClose
            || institutionalEmergencyForceLossClose
            || (!this.isInstitutionalHybridStrategy(config) && bypassPositiveCloseByStrategy);
        /** Perdas so sao permitidas em emergencias reais; managed_cycle_risk_flatten respeita o piso. */
        const allowNegativeCloseForThisReason = (!!state.forceStopLossSell && this.isEmergencyForceCloseReason(closeReason));
        /** Institucional: piso de net (ex: -2 USDT ou % da margin). Não-institucional: MIN_CLOSE_PROFIT. */
        const minCloseProfitForReason = isHoldingOrProlongedClose && Number.isFinite(holdingCloseThreshold)
            ? holdingCloseThreshold
            : (this.isInstitutionalHybridStrategy(config) ? this.resolveMinNetFloor(config) : MIN_CLOSE_PROFIT);

        const currentLongPosition = isClosingLong && state.activePositions.length > 0
            ? state.activePositions[state.activePositions.length - 1]
            : null;
        const currentShortPosition = isClosingShort && state.shortPositions && state.shortPositions.length > 0
            ? state.shortPositions[state.shortPositions.length - 1]
            : null;

        if ((isClosingLong || isClosingShort) && Number.isFinite(requestedCloseQuantity) && requestedCloseQuantity > 0) {
            const baseCloseQty = isClosingLong
                ? Number(currentLongPosition?.quantity || quantity)
                : Number(currentShortPosition?.quantity || quantity);
            if (Number.isFinite(baseCloseQty) && baseCloseQty > 0) {
                quantity = Math.min(baseCloseQty, requestedCloseQuantity);
            }
        }

        const closeQtyPrecision = Math.max(2, Number(symbolInfo?.quantityPrecision ?? 8));
        const closeQtyTolerance = Math.pow(10, -closeQtyPrecision);
        const longCloseBaseQty = Number(currentLongPosition?.quantity || 0);
        const shortCloseBaseQty = Number(currentShortPosition?.quantity || 0);
        const isPartialLongClose = isClosingLong && longCloseBaseQty > 0 && quantity < (longCloseBaseQty - closeQtyTolerance);
        const isPartialShortClose = isClosingShort && shortCloseBaseQty > 0 && quantity < (shortCloseBaseQty - closeQtyTolerance);
        const isPartialClose = isPartialLongClose || isPartialShortClose;
        let closedLongFully = !isClosingLong;
        let closedShortFully = !isClosingShort;

        if (quantity <= 0) return;

        // o. REAL: evitar múltiplas aberturas simultâneas enquanto há ordem pendente.
        // Se já existe uma abertura REAL pendente (_lastPendingOpenOrderId), não enviar nova ordem de abertura
        // até que a corretora confirme fill/cancel (poll em fetchMarginOrderStatus ou sync de posições reais).
        if (finalIsReal && (isOpeningLong || isOpeningShort) && state._lastPendingOpenOrderId) {
            this.logger.execution(
                `Y" [REAL] Ordem de abertura pendente em andamento (orderId=${state._lastPendingOpenOrderId}). ` +
                `Aguardando confirmacao de fill/cancel antes de enviar nova abertura.`
            );
            return;
        }

        // Correção: Verificar se ha posicoes LONG (buyPrices ou activePositions)
        // Isso resolve problemas de dessincronizacao onde contador mostra compras mas buyPrices esta vazio
        const creditEntryReason = String(state._nextOrderReason || '').trim();
        const creditEntryIsHedge = !!state._nextOrderIsHedge;
        if (
            (isOpeningLong || isOpeningShort) &&
            this.safetyGates.shouldBlockNewEntryByCredits(config, {
                reason: creditEntryReason,
                isHedge: creditEntryIsHedge,
                reduceOnly: false,
            })
        ) {
            const now = Date.now();
            const lastTs = Number((state as any)._lastCreditEntryGateLogAt || 0);
            if (!Number.isFinite(lastTs) || lastTs <= 0 || (now - lastTs) >= Math.max(5000, Number(config.decisionVerboseIntervalMs ?? 5000))) {
                const creditsBalance = this.safetyGates.resolveRuntimeUserCredits(config);
                this.logger.warn(
                    `[MARKETKING][CREDITS LOCK] Nova entrada ${action.toUpperCase()} bloqueada por falta de creditos ` +
                    `(credits=${Number.isFinite(creditsBalance) ? creditsBalance.toFixed(4) : 'n/a'}, reason=${creditEntryReason || 'normal_entry'}, hedge=${creditEntryIsHedge}).`
                );
                (state as any)._lastCreditEntryGateLogAt = now;
            }
            (state as any)._creditEntryLockActive = true;
            (state as any)._creditEntryLockReason = creditEntryReason || 'normal_entry';
            return;
        }

        const hasLongPositions = state.buyPrices.length > 0 || state.activePositions.length > 0;

        // Fechamento não emergencial: net > 0 e piso de net projetado.
        // pairing_take_fallback / pairing_take_age_reduce nao sao emergencia: bloquear se net <= 0.
        if (isClosingLong && hasLongPositions && !allowNegativeCloseForThisReason && !allowRuntimeNetBypass) {
            // Correção: Usar activePositions como fonte primaria
            let lastBuyPrice = 0;
            let position: MarketKingLongPosition | null = null;

            if (state.activePositions.length > 0) {
                position = state.activePositions[state.activePositions.length - 1];
                lastBuyPrice = position.entryPrice;
            } else if (state.buyPrices.length > 0) {
                lastBuyPrice = state.buyPrices[state.buyPrices.length - 1];
                position = state.activePositions.find(p =>
                    p.side === 'long' && Math.abs(p.entryPrice - lastBuyPrice) < PRICE_TOLERANCE_ORDER
                ) || null;
            }

            if (position && position.quantity > 0) {
                const profitCheck = estimateCloseNetWithRuntimeModel('long', position, quantity);
                if (
                    Number.isFinite(profitCheck) &&
                    (profitCheck < minCloseProfitForReason)
                ) {
                    this.logger.warn(
                        `s Fechar LONG bloqueado (regra net > 0): Net projetado ${profitCheck.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                        `Entrada: ${lastBuyPrice.toFixed(4)} | Saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                    );
                    state.forceStopLossSell = false;
                    return;
                }
            } else {
                this.logger.warn(
                    `s Fechar LONG bloqueado: Posicao nao encontrada ou quantidade invalida.`
                );
                return;
            }
        }

        if (isClosingShort && state.shortPositions && state.shortPositions.length > 0 && !allowNegativeCloseForThisReason && !allowRuntimeNetBypass) {
            const lastShort = state.shortPositions[state.shortPositions.length - 1];
            const shortHasBorrowMetadata = Number(lastShort?.borrowedQuantity || 0) > 0 || Number(lastShort?.borrowedAmount || 0) > 0;
            if (lastShort && lastShort.quantity > 0 && lastShort.openTime && (isPerpRuntime || shortHasBorrowMetadata)) {
                const profitCheck = estimateCloseNetWithRuntimeModel('short', lastShort, quantity);
                if (
                    Number.isFinite(profitCheck) &&
                    (profitCheck < minCloseProfitForReason)
                ) {
                    this.logger.warn(
                        `s Fechar SHORT bloqueado (regra net > 0): Net projetado ${profitCheck.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                        `Entrada: ${lastShort.price.toFixed(4)} | Saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                    );
                    return;
                }
            } else {
                this.logger.warn(
                    `s Fechar SHORT bloqueado: Posicao nao encontrada ou dados invalidos.`
                );
                return;
            }
        }

        // Correção crítica: Verificar e incrementar ANTES de executar a ordem
        // Para operacoes de abertura (abrir long/short), verificar limite
        // Para operacoes de fechamento (fechar long/short), nao contar como nova operacao
        let canExecute = true;
        const shouldCountOpening = (isOpeningLong || isOpeningShort) && !state._nextOrderIsHedge;
        if (shouldCountOpening) {
            // CORRIGIDO: MaxOps nÃ£o deve interferir nas negociaÃ§Ãµes quando hÃ¡ RED ativo
            // Verificar se hÃ¡ RED ativo no lado oposto antes de verificar MaxOps
            const oppositeSide = action === 'buy' ? 'short' : 'long';
            const oppositeState = state.gridState?.[oppositeSide];
            const hasRedInOppositeSide = oppositeState && oppositeState.redActive;

            // Se hÃ¡ RED ativo no lado oposto, nÃ£o verificar MaxOps (permitir operaÃ§Ã£o)
            // MaxOps nÃ£o deve interferir nas negociaÃ§Ãµes quando hÃ¡ RED ativo
            if (hasRedInOppositeSide) {
                // Quando hÃ¡ RED ativo no lado oposto, permitir operaÃ§Ã£o mesmo se MaxOps estÃ¡ alto
                // MaxOps nÃ£o deve interferir nas negociaÃ§Ãµes conforme especificado
                canExecute = true;
                if (useCounterBypass) {
                    // Ainda contabilizar no banco, mas nÃ£o bloquear
                    await (action === 'buy' ? this.incrementBuyTrackOnlySafe() : this.incrementSellTrackOnlySafe());
                } else {
                    // Ainda incrementar contador, mas nÃ£o bloquear
                    await (action === 'buy' ? this.incrementBuySafe() : this.incrementSellSafe());
                }
            } else {
                // Quando nÃ£o hÃ¡ RED ativo, verificar MaxOps normalmente
                // o. Apenas operacoes de ABERTURA contam para o limite
                if (useCounterBypass) {
                    canExecute = action === 'buy'
                        ? await this.incrementBuyTrackOnlySafe()
                        : await this.incrementSellTrackOnlySafe();

                    if (!canExecute) {
                        this.logger.warn(
                            `[MARKETKING][DB COUNTER] Falha ao contabilizar ordem ${action.toUpperCase()} no banco. Ordem nao sera enviada para evitar divergencia.`
                        );
                        return;
                    }
                } else {
                    if (action === 'buy') {
                        canExecute = await this.incrementBuySafe(); // Verifica E incrementa atomicamente
                    } else {
                        canExecute = await this.incrementSellSafe(); // Verifica E incrementa atomicamente
                    }

                    if (!canExecute) {
                        const lado = action === 'buy' ? 'compras (long)' : 'vendas (short)';
                        this.logger.warn(
                            `s Operacao ${action.toUpperCase()} rejeitada: Limite de ${config.maxOperations} ${lado} atingido (max_operations e por contador, nao soma).`
                        );
                        return; // o. BLOQUEIA execucao se limite atingido
                    }
                }
            }
        } else if (isOpeningLong || isOpeningShort) {
            this.logger.counter(`[MARKETKING] Ordem hedge ${action.toUpperCase()} nao entra no contador operacional.`);
        }
        // o. Fechamento de posicoes NfO conta para o limite (e apenas encerrar posicao existente)
        let openingStateCommitted = false;

        try {
            // --- Persistência maker: pending → success quando a corretora confirma o fill ---
            // ABERTURA: ao colocar ordem de abertura maker (accepted && !filled) salvamos pending e _lastPendingOpenOrderId.
            //   Aqui verificamos se essa ordem já preencheu (poll status); se sim → saveOperation(..., 'success') e limpamos estado.
            // FECHAMENTO: idem com _lastPendingCloseOrderId; além disso o sync de posições (menos posições = fill) também persiste success.
            // Institutional (reduceOnly): pending em placeLimitOrderInstitutional; success no sync ao detectar posição reduzida.
            // Backend faz upsert por order_id: atualiza o registro existente (pending→success) em vez de criar duplicata.
            if (finalIsReal && state._lastPendingOpenOrderId && client) {
                try {
                    const openStatus = await this.fetchMarginOrderStatus(client, config, state._lastPendingOpenOrderId);
                    if (this.isFilledStatus(openStatus)) {
                        const openAction = state._lastPendingOpenAction ?? 'buy';
                        const openPrice = state._lastPendingOpenPrice ?? 0;
                        const openQty = state._lastPendingOpenQty ?? 0;
                        await this.saveOperation(config, openAction, openPrice, openQty, state._lastPendingOpenOrderId, 0, 'real', symbolInfo, 'success', {
                            operationPhase: 'open',
                            operationReason: state._lastPendingOpenReason,
                            executionMode: state._lastPendingOpenExecutionMode,
                            positionId: state._lastPendingOpenPositionId,
                            operationContext: {
                                sync_source: 'pending_order_poll',
                                ...(state._lastPendingOpenSourceQuoteId ? { source_quote_id: state._lastPendingOpenSourceQuoteId } : {}),
                            },
                        });
                        this.logger.execution(`Y" [REAL] Abertura pendente (${state._lastPendingOpenOrderId}) preenchida; persistido como success.`);
                        state._lastPendingOpenOrderId = undefined;
                        state._lastPendingOpenPrice = undefined;
                        state._lastPendingOpenQty = undefined;
                        state._lastPendingOpenAction = undefined;
                        state._lastPendingOpenCounted = undefined;
                        state._lastPendingOpenReason = undefined;
                        state._lastPendingOpenExecutionMode = undefined;
                        state._lastPendingOpenPositionId = undefined;
                        state._lastPendingOpenSourceQuoteId = undefined;
                    } else if (this.isRejectedStatus(openStatus)) {
                        const openAction = state._lastPendingOpenAction ?? 'buy';
                        const openPrice = state._lastPendingOpenPrice ?? 0;
                        const openQty = state._lastPendingOpenQty ?? 0;
                        await this.saveOperation(config, openAction, openPrice, openQty, state._lastPendingOpenOrderId, 0, 'real', symbolInfo, 'canceled', {
                            operationPhase: 'open',
                            operationReason: state._lastPendingOpenReason,
                            executionMode: state._lastPendingOpenExecutionMode,
                            positionId: state._lastPendingOpenPositionId,
                            operationContext: {
                                sync_source: 'pending_order_poll',
                                ...(state._lastPendingOpenSourceQuoteId ? { source_quote_id: state._lastPendingOpenSourceQuoteId } : {}),
                            },
                        });
                        if (state._lastPendingOpenCounted) {
                            if (openAction === 'buy') {
                                await this.decrementBuySafe();
                            } else {
                                await this.decrementSellSafe();
                            }
                        }
                        this.logger.execution(`Y" [REAL] Abertura pendente (${state._lastPendingOpenOrderId}) cancelada/rejeitada; persistido como canceled.`);
                        state._lastPendingOpenOrderId = undefined;
                        state._lastPendingOpenPrice = undefined;
                        state._lastPendingOpenQty = undefined;
                        state._lastPendingOpenAction = undefined;
                        state._lastPendingOpenCounted = undefined;
                        state._lastPendingOpenReason = undefined;
                        state._lastPendingOpenExecutionMode = undefined;
                        state._lastPendingOpenPositionId = undefined;
                        state._lastPendingOpenSourceQuoteId = undefined;
                    }
                } catch (e: any) {
                    this.logger.warn(`s [REAL] Nao foi possivel verificar ordem de abertura pendente ${state._lastPendingOpenOrderId}: ${e?.message || e}`);
                }
            }

            if (finalIsReal && state._lastPendingCloseOrderId && client) {
                try {
                    const pendingStatus = await this.fetchMarginOrderStatus(client, config, state._lastPendingCloseOrderId);
                    if (this.isFilledStatus(pendingStatus)) {
                        const pendingAction = state._lastPendingCloseAction ?? (state._lastPendingCloseSide === 'long' ? 'sell' : 'buy');
                        const pendingPrice = state._lastPendingClosePrice ?? price;
                        const pendingQty = state._lastPendingCloseQty ?? quantity;
                        let pendingProfit = 0;
                        const closeFeeRate = Math.max(0, Number(symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? (isPerpRuntime ? 0.0002 : 0)));
                        if (state._lastPendingCloseSide === 'long' && state.activePositions?.length > 0) {
                            const closedPos = state.activePositions[state.activePositions.length - 1];
                            const closeBorrowed = isPerpRuntime ? 0 : getBorrowedAmountForClose(
                                Number(closedPos.borrowedAmount || 0),
                                Number(closedPos.quantity || 0),
                                pendingQty
                            );
                            const accumulatedInterest = isPerpRuntime ? 0 : this.calculateAccumulatedInterest(
                                closeBorrowed,
                                closedPos.openTime,
                                closedPos.dailyInterestRate ?? dailyInterestRate,
                                config
                            );
                            pendingProfit = this.calculateNetProfitWithLoan(
                                closedPos.entryPrice,
                                pendingPrice,
                                pendingQty,
                                closeFeeRate,
                                accumulatedInterest,
                                'long',
                                0,
                                'one_sided'
                            );
                            const canonicalProfit = this.estimateRuntimeCloseNet(
                                config,
                                symbolInfo,
                                closedPos,
                                'long',
                                pendingPrice,
                                pendingQty,
                                this.resolveEffectiveCarryRate(config, dailyInterestRate),
                                state._lastPendingCloseExecutionMode ?? 'maker'
                            );
                            if (Number.isFinite(canonicalProfit)) pendingProfit = canonicalProfit;
                        } else if (state._lastPendingCloseSide === 'short' && state.shortPositions?.length > 0) {
                            const closedShort = state.shortPositions[state.shortPositions.length - 1];
                            const borrowedValueTotal = isPerpRuntime ? 0 : (Number.isFinite(Number(closedShort.borrowedAmount)) ? Number(closedShort.borrowedAmount) : (Number(closedShort.price || 0) * Number(closedShort.borrowedQuantity ?? (closedShort as any).quantity ?? 0)));
                            const closeBorrowed = isPerpRuntime ? 0 : getBorrowedAmountForClose(
                                borrowedValueTotal,
                                Number(closedShort.quantity || 0),
                                pendingQty
                            );
                            const accumulatedInterest = isPerpRuntime ? 0 : this.calculateAccumulatedInterest(
                                closeBorrowed,
                                closedShort.openTime,
                                closedShort.dailyInterestRate ?? dailyInterestRate,
                                config
                            );
                            pendingProfit = this.calculateNetProfitWithLoan(
                                closedShort.price,
                                pendingPrice,
                                pendingQty,
                                closeFeeRate,
                                accumulatedInterest,
                                'short',
                                0,
                                'one_sided'
                            );
                            const canonicalProfit = this.estimateRuntimeCloseNet(
                                config,
                                symbolInfo,
                                closedShort,
                                'short',
                                pendingPrice,
                                pendingQty,
                                this.resolveEffectiveCarryRate(config, dailyInterestRate),
                                state._lastPendingCloseExecutionMode ?? 'maker'
                            );
                            if (Number.isFinite(canonicalProfit)) pendingProfit = canonicalProfit;
                        }
                        await this.saveOperation(config, pendingAction, pendingPrice, pendingQty, state._lastPendingCloseOrderId, pendingProfit, 'real', symbolInfo, 'success', {
                            operationPhase: 'close',
                            operationReason: state._lastPendingCloseReason,
                            executionMode: state._lastPendingCloseExecutionMode,
                            positionId: state._lastPendingClosePositionId,
                            operationContext: {
                                sync_source: 'pending_order_poll',
                                ...(state._lastPendingCloseSourceQuoteId ? { source_quote_id: state._lastPendingCloseSourceQuoteId } : {}),
                                ...(state._lastPendingCloseCycleId ? { cycle_close_id: state._lastPendingCloseCycleId } : {}),
                                ...(state._lastPendingCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                            },
                        });
                        this.logger.execution(`Y" [REAL] Fechamento pendente (${state._lastPendingCloseOrderId}) preenchido; persistido como success (profit=${pendingProfit.toFixed(4)}).`);
                        state._lastPendingCloseSentAt = undefined;
                        state._lastPendingCloseSide = undefined;
                        state._lastPendingCloseOrderId = undefined;
                        state._lastPendingClosePrice = undefined;
                        state._lastPendingCloseQty = undefined;
                        state._lastPendingCloseAction = undefined;
                        state._lastPendingCloseReason = undefined;
                        state._lastPendingCloseExecutionMode = undefined;
                        state._lastPendingClosePositionId = undefined;
                        state._lastPendingCloseSourceQuoteId = undefined;
                        state._lastPendingCloseCycleId = undefined;
                        state._lastPendingCloseCycleNetApproved = undefined;
                    } else if (this.isRejectedStatus(pendingStatus)) {
                        const pendingAction = state._lastPendingCloseAction ?? (state._lastPendingCloseSide === 'long' ? 'sell' : 'buy');
                        const pendingPrice = state._lastPendingClosePrice ?? price;
                        const pendingQty = state._lastPendingCloseQty ?? quantity;
                        await this.saveOperation(config, pendingAction, pendingPrice, pendingQty, state._lastPendingCloseOrderId, 0, 'real', symbolInfo, 'canceled', {
                            operationPhase: 'close',
                            operationReason: state._lastPendingCloseReason,
                            executionMode: state._lastPendingCloseExecutionMode,
                            positionId: state._lastPendingClosePositionId,
                            operationContext: {
                                sync_source: 'pending_order_poll',
                                ...(state._lastPendingCloseSourceQuoteId ? { source_quote_id: state._lastPendingCloseSourceQuoteId } : {}),
                                ...(state._lastPendingCloseCycleId ? { cycle_close_id: state._lastPendingCloseCycleId } : {}),
                                ...(state._lastPendingCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                            },
                        });
                        this.logger.execution(`Y" [REAL] Fechamento pendente (${state._lastPendingCloseOrderId}) cancelado/rejeitado; persistido como canceled.`);
                        state._lastPendingCloseSentAt = undefined;
                        state._lastPendingCloseSide = undefined;
                        state._lastPendingCloseOrderId = undefined;
                        state._lastPendingClosePrice = undefined;
                        state._lastPendingCloseQty = undefined;
                        state._lastPendingCloseAction = undefined;
                        state._lastPendingCloseReason = undefined;
                        state._lastPendingCloseExecutionMode = undefined;
                        state._lastPendingClosePositionId = undefined;
                        state._lastPendingCloseSourceQuoteId = undefined;
                        state._lastPendingCloseCycleId = undefined;
                        state._lastPendingCloseCycleNetApproved = undefined;
                    }
                } catch (e: any) {
                    this.logger.warn(`s [REAL] Nao foi possivel verificar ordem pendente ${state._lastPendingCloseOrderId}: ${e?.message || e}`);
                }
            }

            // Cooldown apos ordem de FECHAMENTO pendente: evita sequencia de 40+ tentativas (mesma posicao, ordem nao preenche, reenvio a cada tick)
            if (finalIsReal && (isClosingLong || isClosingShort)) {
                const closeSide: 'long' | 'short' = isClosingLong ? 'long' : 'short';
                const cooldownMs = Math.max(0, Number(config.closeCooldownMs ?? 60000));
                const lastAt = state._lastPendingCloseSentAt;
                if (typeof lastAt === 'number' && state._lastPendingCloseSide === closeSide && cooldownMs > 0 && (Date.now() - lastAt) < cooldownMs) {
                    const remainingSec = Math.ceil((cooldownMs - (Date.now() - lastAt)) / 1000);
                    this.logger.execution(
                        `Y" [REAL] FECHAR ${closeSide.toUpperCase()} em cooldown (${remainingSec}s). Ordem de fechamento pendente recente; evita sequencia de tentativas.`
                    );
                    return;
                }
            }

            let orderId: string;
            let success = false;
            let executionType: 'simulated' | 'real' = 'simulated';
            let profit = 0;

            // Novo: Calcular emprestimo ao abrir posicao
            let borrowedAmount = 0;
            let borrowedQuantity = 0;
            let openTime = Date.now();
            let pendingLongPosition: MarketKingLongPosition | null = null;
            let pendingShortPosition: MarketKingShortPosition | null = null;
            let openingSideLabel: 'long' | 'short' | null = null;
            const parentSide: 'long' | 'short' | undefined = state._nextOrderParentSide;
            const isHedge = !!state._nextOrderIsHedge;

            if (isOpeningLong || isOpeningShort) {
                const MAX_LEVERAGE_CONFIG = config.maxLeverage ?? (isPerpRuntime ? 125 : 10);
                if (enforceOperationalRiskChecks && safeLeverage > MAX_LEVERAGE_CONFIG) {
                    this.logger.error(
                        `O [${finalIsReal ? 'REAL' : 'DEMO'}] Leverage ${safeLeverage}x excede maximo permitido (${MAX_LEVERAGE_CONFIG}x)`
                    );
                    return;
                } else if (finalIsDemo && safeLeverage > MAX_LEVERAGE_CONFIG) {
                    this.logger.warn(
                        `s [DEMO] Leverage ${safeLeverage}x acima do maximo real (${MAX_LEVERAGE_CONFIG}x) - apenas simulacao (demoMirrorRealRiskChecksInDemo=false)`
                    );
                }
            }

            if (isOpeningLong) {
                // LONG: Empresta USDT para comprar mais tokens
                const positionValue = price * quantity;
                borrowedAmount = isPerpRuntime ? 0 : this.calculateBorrowedAmountForLong(positionValue, safeLeverage);
                const initialMarginLong = positionValue / Math.max(1, safeLeverage);
                openingSideLabel = 'long';

                // Criar posicao LONG
                pendingLongPosition = {
                    positionId: orderPositionIdOverride || `LONG_${openTime}`,
                    entryPrice: price,
                    quantity: quantity,
                    borrowedAmount: borrowedAmount,
                    openTime: openTime,
                    side: 'long',
                    dailyInterestRate: dailyInterestRate,
                    initialMargin: initialMarginLong,
                    leverage: safeLeverage,
                    peakPrice: price,
                    _hardStopSkipCount: 0,
                    _maxHoldingSkipCount: 0,
                    _breakEvenArmed: false,
                    takePrice: Number.isFinite(Number(institutionalMeta?.takePrice)) ? Number(institutionalMeta?.takePrice) : undefined,
                    makerFeeRate: Number.isFinite(Number(institutionalMeta?.makerFeeRate)) ? Number(institutionalMeta?.makerFeeRate) : undefined,
                    takerFeeRate: Number.isFinite(Number(institutionalMeta?.takerFeeRate)) ? Number(institutionalMeta?.takerFeeRate) : undefined,
                    makerSlippagePercent: Number.isFinite(Number(institutionalMeta?.makerSlippagePercent)) ? Number(institutionalMeta?.makerSlippagePercent) : undefined,
                    takerSlippagePercent: Number.isFinite(Number(institutionalMeta?.takerSlippagePercent)) ? Number(institutionalMeta?.takerSlippagePercent) : undefined,
                    openFeeRate: Number.isFinite(Number(institutionalMeta?.openFeeRate)) ? Number(institutionalMeta?.openFeeRate) : undefined,
                    closeFeeRate: Number.isFinite(Number(institutionalMeta?.closeFeeRate)) ? Number(institutionalMeta?.closeFeeRate) : undefined,
                    openSlippagePercent: Number.isFinite(Number(institutionalMeta?.openSlippagePercent)) ? Number(institutionalMeta?.openSlippagePercent) : undefined,
                    closeSlippagePercent: Number.isFinite(Number(institutionalMeta?.closeSlippagePercent)) ? Number(institutionalMeta?.closeSlippagePercent) : undefined,
                    openExecutionMode: institutionalMeta?.openExecutionMode,
                    closeExecutionMode: institutionalMeta?.closeExecutionMode,
                    fundingRatePer8h: Number.isFinite(Number(institutionalMeta?.fundingRatePer8h)) ? Number(institutionalMeta?.fundingRatePer8h) : undefined,
                    fundingIntervalHours: Number.isFinite(Number(institutionalMeta?.fundingIntervalHours)) ? Number(institutionalMeta?.fundingIntervalHours) : undefined,
                    fundingFeeMode: institutionalMeta?.fundingFeeMode,
                    sourceQuoteId: institutionalMeta?.sourceQuoteId,
                    qgParentSide: parentSide ?? 'long',
                    qgHedge: isHedge,
                    qgReason: typeof state._nextOrderReason === 'string' ? state._nextOrderReason : undefined,
                    gridParentSide: parentSide ?? 'long',
                    gridIsHedge: isHedge,
                    gridOpenReason: typeof state._nextOrderReason === 'string' ? state._nextOrderReason : undefined
                };

                {this.logger.info(
                        isPerpRuntime
                            ? `Y' [LONG] FUTURE PERP: notional=$${positionValue.toFixed(2)} | margem inicial ~$${initialMarginLong.toFixed(2)} (Leverage ${safeLeverage}x)`
                            : `Y' [LONG] Emprestimo calculado: $${borrowedAmount.toFixed(2)} USDT (Leverage ${safeLeverage}x)`
                    );
                }
            } else if (isOpeningShort) {
                // SHORT: Empresta tokens para vender
                borrowedQuantity = isPerpRuntime ? 0 : this.calculateBorrowedQuantityForShort(quantity);
                const { initialMargin: shortInitialMargin, borrowedAmount: shortBorrowedAmount } =
                    this.computeNotionalAndMargin(price, quantity, safeLeverage);
                borrowedAmount = isPerpRuntime ? 0 : shortBorrowedAmount;

                // Adicionar a lista de short positions com dados de emprestimo
                openingSideLabel = 'short';
                pendingShortPosition = {
                    positionId: orderPositionIdOverride || `SHORT_${openTime}`,
                    price: price,
                    quantity: quantity,
                    borrowedQuantity: borrowedQuantity,
                    borrowedAmount: borrowedAmount,
                    initialMargin: shortInitialMargin,
                    leverage: safeLeverage,
                    openTime: openTime,
                    dailyInterestRate: dailyInterestRate,
                    peakPrice: price,
                    _hardStopSkipCount: 0,
                    _maxHoldingSkipCount: 0,
                    _breakEvenArmed: false,
                    takePrice: Number.isFinite(Number(institutionalMeta?.takePrice)) ? Number(institutionalMeta?.takePrice) : undefined,
                    makerFeeRate: Number.isFinite(Number(institutionalMeta?.makerFeeRate)) ? Number(institutionalMeta?.makerFeeRate) : undefined,
                    takerFeeRate: Number.isFinite(Number(institutionalMeta?.takerFeeRate)) ? Number(institutionalMeta?.takerFeeRate) : undefined,
                    makerSlippagePercent: Number.isFinite(Number(institutionalMeta?.makerSlippagePercent)) ? Number(institutionalMeta?.makerSlippagePercent) : undefined,
                    takerSlippagePercent: Number.isFinite(Number(institutionalMeta?.takerSlippagePercent)) ? Number(institutionalMeta?.takerSlippagePercent) : undefined,
                    openFeeRate: Number.isFinite(Number(institutionalMeta?.openFeeRate)) ? Number(institutionalMeta?.openFeeRate) : undefined,
                    closeFeeRate: Number.isFinite(Number(institutionalMeta?.closeFeeRate)) ? Number(institutionalMeta?.closeFeeRate) : undefined,
                    openSlippagePercent: Number.isFinite(Number(institutionalMeta?.openSlippagePercent)) ? Number(institutionalMeta?.openSlippagePercent) : undefined,
                    closeSlippagePercent: Number.isFinite(Number(institutionalMeta?.closeSlippagePercent)) ? Number(institutionalMeta?.closeSlippagePercent) : undefined,
                    openExecutionMode: institutionalMeta?.openExecutionMode,
                    closeExecutionMode: institutionalMeta?.closeExecutionMode,
                    fundingRatePer8h: Number.isFinite(Number(institutionalMeta?.fundingRatePer8h)) ? Number(institutionalMeta?.fundingRatePer8h) : undefined,
                    fundingIntervalHours: Number.isFinite(Number(institutionalMeta?.fundingIntervalHours)) ? Number(institutionalMeta?.fundingIntervalHours) : undefined,
                    fundingFeeMode: institutionalMeta?.fundingFeeMode,
                    sourceQuoteId: institutionalMeta?.sourceQuoteId,
                    qgParentSide: parentSide ?? 'short',
                    qgHedge: isHedge,
                    qgReason: typeof state._nextOrderReason === 'string' ? state._nextOrderReason : undefined,
                    gridParentSide: parentSide ?? 'short',
                    gridIsHedge: isHedge,
                    gridOpenReason: typeof state._nextOrderReason === 'string' ? state._nextOrderReason : undefined
                };

                {this.logger.info(
                        isPerpRuntime
                            ? `Y' [SHORT] FUTURE PERP: notional=$${(price * quantity).toFixed(2)} | margem inicial ~$${shortInitialMargin.toFixed(2)} (Leverage ${safeLeverage}x)`
                            : `Y' [SHORT] Emprestimo calculado: ${borrowedQuantity.toFixed(8)} ${config.pair1} | Borrowed efetivo: $${shortBorrowedAmount.toFixed(2)} USDT (Leverage ${safeLeverage}x)`
                    );
                }
            }

            // o. LOG DE ENTRADA: Registrar ordem antes de ser enviada/confirmada
            const positionTypeLabel = isOpeningLong ? 'ABRIR LONG' : isOpeningShort ? 'ABRIR SHORT' : isClosingLong ? 'FECHAR LONG' : 'FECHAR SHORT';
            const orderModeLabel = finalIsDemo ? '[DEMO]' : '[REAL]';
            {this.logger.execution(`Y" [ENTRADA] ${positionTypeLabel} ${orderModeLabel}: Enviando ordem de ${quantity.toFixed(8)} ${config.pair1} a ${price.toFixed(symbolInfo.pricePrecision)} ${config.pair2} | Par: ${config.market} | Leverage: ${safeLeverage}x`);
            }

            if (finalIsDemo) {
                // o. MODO DEMO: Simulacao pura sem contato com API
                orderId = `DEMO_MARGIN_${Date.now()}_${Math.random()}`;
                success = true;
                executionType = 'simulated';

                // Correção: Verificar se ha posicoes LONG (buyPrices ou activePositions)
                const hasLongPositions = state.buyPrices.length > 0 || state.activePositions.length > 0;

                // Correção: Calcular profit para fechamento considerando juros
                if (isClosingLong && hasLongPositions) {
                    // Correção: Priorizar activePositions, usar buyPrices como fallback
                    let lastBuyPrice = 0;
                    let position: MarketKingLongPosition | null = null;

                    if (state.activePositions.length > 0) {
                        position = state.activePositions[state.activePositions.length - 1];
                        lastBuyPrice = position.entryPrice;
                    } else if (state.buyPrices.length > 0) {
                        lastBuyPrice = state.buyPrices[state.buyPrices.length - 1];
                        const PRICE_TOLERANCE_REAL_CLOSE = config.priceTolerance ?? 0.0001;
                        position = state.activePositions.find(p =>
                            p.side === 'long' && Math.abs(p.entryPrice - lastBuyPrice) < PRICE_TOLERANCE_REAL_CLOSE
                        ) || null;
                    }

                    if (position) {
                        const closeBorrowedAmount = getBorrowedAmountForClose(
                            Number(position.borrowedAmount || 0),
                            Number(position.quantity || 0),
                            quantity
                        );
                        const accumulatedInterest = this.calculateAccumulatedInterest(
                            closeBorrowedAmount,
                            position.openTime,
                            position.dailyInterestRate || dailyInterestRate,
                            config // o. Passar config
                        );
                        profit = this.calculateNetProfitWithLoan(
                            lastBuyPrice,
                            price,
                            quantity,
                            symbolInfo.takerFeeRate,
                            accumulatedInterest,
                            'long',
                            executionSlippagePercent,
                            executionSlippageModel
                        );
                        const fundingAdj = applyPerpExecutionModelToCloseProfit('long', position, profit, quantity);
                        profit = fundingAdj.profit;

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s Fechar LONG bloqueado (regra net > 0): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastBuyPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | ` +
                                `Juros: ${accumulatedInterest.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                            );
                            return;
                        }

                        // Remover emprestimo do total
                        if (position) {
                            this.logger.execution(
                                isPerpRuntime
                                    ? `Y' [FECHAR LONG] Ajuste exec: $${fundingAdj.executionAdjust.toFixed(4)} | Funding: $${fundingAdj.fundingCost.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                                    : `Y' [FECHAR LONG] Juros acumulados: $${accumulatedInterest.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                            );
                            this.logger.profit(`Profit liquido apos custos: $${profit.toFixed(4)}`);
                        }
                    } else {
                        // Fallback: calcular sem juros
                        profit = this.calculateNetProfitWithFee(lastBuyPrice, price, quantity, symbolInfo.takerFeeRate);

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s Fechar LONG bloqueado (fallback final runtime): Profit liquido ${profit.toFixed(4)} ${config.pair2} < ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastBuyPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                            );
                            return;
                        }
                    }
                } else if (isClosingShort && state.shortPositions && state.shortPositions.length > 0) {
                    const lastShort = state.shortPositions[state.shortPositions.length - 1];
                    const lastShortPrice = lastShort.price;

                    const shortHasBorrowMetadata = Number(lastShort?.borrowedQuantity || 0) > 0 || Number(lastShort?.borrowedAmount || 0) > 0;
                    if (lastShort.openTime && (isPerpRuntime || shortHasBorrowMetadata)) {
                        // Em perp, borrowed/juro de margin nao se aplicam (funding e tratado separadamente).
                        const borrowedValueTotal = isPerpRuntime
                            ? 0
                            : (
                                Number.isFinite(Number(lastShort.borrowedAmount))
                                    ? Number(lastShort.borrowedAmount)
                                    : (lastShortPrice * Number(lastShort.borrowedQuantity || 0))
                            );
                        const closeBorrowedValue = isPerpRuntime
                            ? 0
                            : getBorrowedAmountForClose(
                                borrowedValueTotal,
                                Number(lastShort.quantity || 0),
                                quantity
                            );
                        const accumulatedInterest = isPerpRuntime
                            ? 0
                            : this.calculateAccumulatedInterest(
                                closeBorrowedValue,
                                lastShort.openTime,
                                lastShort.dailyInterestRate || dailyInterestRate
                            );

                        profit = this.calculateNetProfitWithLoan(
                            lastShortPrice,
                            price,
                            quantity,
                            symbolInfo.takerFeeRate,
                            accumulatedInterest,
                            'short',
                            executionSlippagePercent,
                            executionSlippageModel
                        );
                        const fundingAdj = applyPerpExecutionModelToCloseProfit('short', lastShort, profit, quantity);
                        profit = fundingAdj.profit;

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s Fechar SHORT bloqueado (regra net > 0): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Entrada: ${lastShortPrice.toFixed(4)} | Saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                            );
                            return;
                        }

                        this.logger.execution(
                            isPerpRuntime
                                ? `Y' [FECHAR SHORT] Ajuste exec: $${fundingAdj.executionAdjust.toFixed(4)} | Funding: $${fundingAdj.fundingCost.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                                : `Y' [FECHAR SHORT] Juros acumulados: $${accumulatedInterest.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                        );
                        this.logger.profit(`Profit liquido apos custos: $${profit.toFixed(4)}`);
                    } else {
                        // Fallback: calcular sem juros
                        profit = this.calculateNetProfitWithFee(lastShortPrice, price, quantity, symbolInfo.takerFeeRate);
                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s Fechar SHORT bloqueado (regra net > 0 fallback): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Entrada: ${lastShortPrice.toFixed(4)} | Saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'} | Modo: ${finalIsReal ? 'REAL' : 'DEMO'}`
                            );
                            return;
                        }
                    }
                }

                const positionType = isOpeningLong ? 'LONG' : isOpeningShort ? 'SHORT' : isClosingLong ? 'FECHAR LONG' : 'FECHAR SHORT';

                // Novo: Log detalhado em modo demo mostrando calculos de loan
                if (isOpeningLong || isOpeningShort) {
                    const loanInfo = isOpeningLong
                        ? `Y' Emprestimo: $${borrowedAmount.toFixed(2)} USDT | Taxa: ${(dailyInterestRate * 100).toFixed(4)}%/dia`
                        : `Y' Emprestimo: ${borrowedQuantity.toFixed(8)} ${config.pair1} ($${(price * borrowedQuantity).toFixed(2)} USDT) | Taxa: ${(dailyInterestRate * 100).toFixed(4)}%/dia`;
                    this.logger.execution(`YZ [DEMO] ${positionType}: ${quantity.toFixed(8)} ${config.pair1} em ${price.toFixed(4)} ${config.pair2} (Leverage ${safeLeverage}x) | ${loanInfo}`);
                } else {
                    const profitMsg = profit !== 0 ? ` | Profit Liquido (apos juros): $${profit.toFixed(4)}` : '';
                    this.logger.execution(`YZ [DEMO] ${positionType}: ${quantity.toFixed(8)} ${config.pair1} em ${price.toFixed(4)} ${config.pair2} (Leverage ${safeLeverage}x)${profitMsg}`);
                    if (profit !== 0) {
                        this.logger.profit(`Profit liquido: $${profit.toFixed(4)}`);
                    }
                }
            } else if (finalIsReal) {
                // o. MODO REAL: Todo o fluxo e APIs utilizam runtime gerenciado de futures para abertura de ordens,
                // verificacoes de risco (margin level, marginAccountInfo) e taxas (symbolInfo do contrato).
                executionType = 'real';
                const exchangeName = String((config as any)?.exchange || '').toLowerCase();
                const isFuturePerpRuntime = this.isFuturePerpetualTrade(config);
                const isManagedFutureRuntime = isManagedFuturesExchange(exchangeName) && isFuturePerpRuntime;
                const runtimeOpenExecutionMode = this.resolveOpenExecutionMode(config);
                const runtimeCloseExecutionMode = this.resolveCloseExecutionMode(config);
                const forcedOpenExecutionMode = (isOpeningLong || isOpeningShort) && state._nextOrderForceExecutionMode
                    ? this.normalizeExecutionMode(state._nextOrderForceExecutionMode, runtimeOpenExecutionMode)
                    : undefined;
                const forcedCloseExecutionMode = (isClosingLong || isClosingShort) && state._nextCloseExecutionModeOverride
                    ? this.normalizeExecutionMode(state._nextCloseExecutionModeOverride, runtimeCloseExecutionMode)
                    : undefined;
                const currentRuntimeExecutionMode = (isOpeningLong || isOpeningShort)
                    ? (forcedOpenExecutionMode ?? runtimeOpenExecutionMode)
                    : (forcedCloseExecutionMode ?? runtimeCloseExecutionMode);
                const useMakerExecution = isManagedFutureRuntime && currentRuntimeExecutionMode === 'maker';
                const useMarketExecution = isManagedFutureRuntime && (currentRuntimeExecutionMode === 'market' || currentRuntimeExecutionMode === 'taker');
                const runtimeMakerPostOnly = config.runtimeMakerPostOnly !== undefined ? !!config.runtimeMakerPostOnly : true;
                const runtimeMakerKeepPendingOnTimeout = config.runtimeMakerKeepPendingOnTimeout !== undefined
                    ? !!config.runtimeMakerKeepPendingOnTimeout
                    : false;
                let orderResponse: any;
                // o. [REAL] Validação de consolidação: fechamento só deve ser enviado se a posição correspondente existir (evita conflito com lado oposto).
                const hasLongPositionsForClose = state.buyPrices.length > 0 || state.activePositions.length > 0;
                const hasShortPositionsForClose = !!(state.shortPositions && state.shortPositions.length > 0);
                if (finalIsReal && isManagedFutureRuntime) {
                    if (isClosingLong && !hasLongPositionsForClose) {
                        this.logger.error(
                            `[REAL] BLOQUEADO: Fechar LONG sem posição long. activePositions=${state.activePositions.length} buyPrices=${state.buyPrices.length}. Não enviar ordem para evitar abrir short por engano.`
                        );
                        return;
                    }
                    if (isClosingShort && !hasShortPositionsForClose) {
                        this.logger.error(
                            `[REAL] BLOQUEADO: Fechar SHORT sem posição short. shortPositions=${(state.shortPositions || []).length}. Não enviar ordem para evitar abrir long por engano.`
                        );
                        return;
                    }
                    if ((isClosingLong || isClosingShort) && hasLongPositionsForClose && hasShortPositionsForClose) {
                        this.logger.execution(
                            `Y" [REAL] Hedge ativo: fechando apenas ${isClosingLong ? 'LONG' : 'SHORT'} (outro lado permanece aberto).`
                        );
                    }
                }
                if (client?.Order && typeof client.Order.placeOrder === 'function') {
                    const quantityPrecision = Number.isFinite(Number(symbolInfo?.quantityPrecision)) ? Number(symbolInfo.quantityPrecision) : 8;
                    const pricePrecision = Number.isFinite(Number(symbolInfo?.pricePrecision)) ? Number(symbolInfo.pricePrecision) : 8;
                    const qsOrBody: any = isManagedFutureRuntime
                        ? buildManagedFutureOrderPayload({
                            exchange: exchangeName,
                            config,
                            action,
                            isOpeningLong,
                            isOpeningShort,
                            isClosingLong,
                            isClosingShort,
                            price,
                            quantity,
                            quantityPrecision,
                            pricePrecision,
                            symbolInfo,
                            useMarketExecution,
                            useMakerExecution,
                            runtimeMakerPostOnly,
                            logger: this.logger,
                            resolveProductType: (runtimeConfig) => this.resolveManagedFuturesProductType(runtimeConfig),
                            resolveMarginMode: (runtimeConfig) => this.resolveManagedFuturesMarginMode(runtimeConfig),
                        })
                        : {
                            symbol: config.market,
                            side: action, // 'buy' = long/open, 'sell' = short/open
                            orderType: "limit",
                            force: "gtc",
                            baseSize: quantity.toFixed(quantityPrecision), // API v2 margin usa "baseSize"
                            price: price.toFixed(pricePrecision),
                            loanType: ((isOpeningLong || isOpeningShort) ? 'autoLoan' : 'autoRepay')
                        };
                    const orderDelayMs = Math.max(0, Number(config.orderDelayMs ?? 1500));
                    if (orderDelayMs > 0 && this.lastOrderSentAt > 0) {
                        const elapsed = Date.now() - this.lastOrderSentAt;
                        if (elapsed < orderDelayMs) {
                            const waitMs = orderDelayMs - elapsed;
                            this.logger.execution(`Y" [REAL] Aguardando ${waitMs}ms entre ordens (orderDelayMs=${orderDelayMs}) antes de enviar.`);
                            await this.sleep(waitMs);
                        }
                    }
                    const runtimeLabel = isManagedFutureRuntime ? `Future/Mix ${currentRuntimeExecutionMode.toUpperCase()}` : 'Margin';
                    const venueLabel = getManagedFuturesVenueLabel(exchangeName);
                    this.logger.execution(`Y" [REAL] Enviando ordem REAL ${runtimeLabel} para API (${venueLabel}): ${JSON.stringify(qsOrBody)}`);
                    orderResponse = await client.Order.placeOrder(qsOrBody);
                    this.lastOrderSentAt = Date.now();
                } else if (client?.restAPI && typeof client.restAPI.marginAccountNewOrder === 'function') {
                    const sideEffectType = (isOpeningLong || isOpeningShort) ? 'AUTO_BORROW_REPAY' : 'AUTO_REPAY';
                    const binancePayload: any = {
                        symbol: config.market,
                        side: action.toUpperCase(),
                        type: 'LIMIT',
                        timeInForce: 'GTC',
                        quantity: quantity.toFixed(symbolInfo.quantityPrecision),
                        price: price.toFixed(symbolInfo.pricePrecision),
                        sideEffectType,
                        isIsolated: config.marginMode === 'isolated' ? 'TRUE' : 'FALSE',
                    };
                    const orderDelayMs = Math.max(0, Number(config.orderDelayMs ?? 1500));
                    if (orderDelayMs > 0 && this.lastOrderSentAt > 0) {
                        const elapsed = Date.now() - this.lastOrderSentAt;
                        if (elapsed < orderDelayMs) {
                            const waitMs = orderDelayMs - elapsed;
                            this.logger.execution(`Y" [REAL] Aguardando ${waitMs}ms entre ordens (orderDelayMs=${orderDelayMs}) antes de enviar.`);
                            await this.sleep(waitMs);
                        }
                    }
                    this.logger.execution(`Y" [REAL] Enviando ordem REAL Margin para API (Binance): ${JSON.stringify(binancePayload)}`);
                    orderResponse = await client.restAPI.marginAccountNewOrder(binancePayload);
                    this.lastOrderSentAt = Date.now();
                } else {
                    throw new Error('Cliente de margin real nao suporta envio de ordens (placeOrder/marginAccountNewOrder ausente).');
                }
                const responsePayload = await this.unwrapResponsePayload(orderResponse);
                const responseData = typeof responsePayload === 'string' ? JSON.parse(responsePayload) : responsePayload;
                const result = responseData?.data ? responseData : { data: responseData, msg: responseData?.msg || responseData?.code, code: responseData?.code };
                orderId = this.extractOrderId(result?.data ?? result ?? responseData);
                const codeRaw = result?.code ?? responseData?.code ?? result?.data?.code;
                const code = codeRaw != null ? String(codeRaw) : '';
                const msg = String(result?.msg ?? responseData?.msg ?? responseData?.message ?? '').toLowerCase();
                const accepted = code === '' || code === '00000' || code === '0' || msg === 'success';
                const statusRaw = this.getOrderStatus(result?.data ?? result ?? responseData);
                let filled = this.isFilledStatus(statusRaw);

                if (!accepted) {
                    this.logger.execution(`? [REAL] Falha na ordem Real Margin: ${result?.msg || result?.code || responseData?.msg || JSON.stringify(result)}`);
                } else {
                    this.logger.execution(`? [REAL] Ordem aceita pela API. OrderID: ${orderId || 'N/A'} | status: ${statusRaw || 'desconhecido'}`);
                    if (!filled) {
                        if (!orderId) {
                            this.logger.execution(`? [REAL] Ordem aceita sem orderId e sem status filled. Bloqueando commit de estado.`);
                        } else {
                            filled = await this.waitMarginOrderFilledOrCancel(
                                client,
                                config,
                                orderId,
                                8,
                                500,
                                { keepPendingOnTimeout: isManagedFutureRuntime && useMakerExecution && runtimeMakerKeepPendingOnTimeout }
                            );
                            if (!filled) {
                                const pendingHint = isManagedFutureRuntime && useMakerExecution && runtimeMakerKeepPendingOnTimeout
                                    ? ' Ordem maker pode permanecer pendente aguardando toque.'
                                    : ' Ordem cancelada/nao confirmada; estado local nao sera atualizado.';
                                this.logger.execution(`? [REAL] Ordem no foi totalmente preenchida: orderId=${orderId}.${pendingHint}`);
                            }
                        }
                    }
                }

                // REAL: reverter contador apenas quando ordem REJEITADA; quando ACEITA (pending ou filled) persistir e manter contagem (alinhado ao DEMO no backend)
                if (!accepted) {
                    if (shouldCountOpening && isOpeningLong) {
                        await this.decrementBuySafe();
                    } else if (shouldCountOpening && isOpeningShort) {
                        await this.decrementSellSafe();
                    }
                    if (openingSideLabel) {
                        this.logger.warn(`?? [REAL] Ordem de abertura (${openingSideLabel}) rejeitada na exchange. Contador revertido.`);
                    }
                    return;
                }
                // Ordem aceita mas ainda nao preenchida (ex.: maker no book)
                if (accepted && !filled) {
                    if (isClosingLong || isClosingShort) {
                        state._lastPendingCloseSentAt = Date.now();
                        state._lastPendingCloseSide = isClosingLong ? 'long' : 'short';
                        // Guardar dados para persistir 1x success quando sync detectar fill (evita ordem mock e várias ordens).
                        if (orderId) {
                            state._lastPendingCloseOrderId = orderId;
                            state._lastPendingClosePrice = price;
                            state._lastPendingCloseQty = quantity;
                            state._lastPendingCloseAction = action as 'sell' | 'buy';
                            state._lastPendingCloseReason = typeof state._nextCloseReason === 'string' ? state._nextCloseReason : undefined;
                            state._lastPendingCloseExecutionMode = currentRuntimeExecutionMode;
                            state._lastPendingClosePositionId = String(state._nextOrderPositionIdOverride || '').trim() || undefined;
                            state._lastPendingCloseSourceQuoteId = String((state as any)?._nextOrderInstitutionalMeta?.sourceQuoteId || '').trim() || undefined;
                            state._lastPendingCloseCycleId = String((state as any)?._nextCloseCycleId || '').trim() || undefined;
                            state._lastPendingCloseCycleNetApproved = !!(state as any)?._nextCloseCycleNetApproved;
                            await this.saveOperation(config, action, price, quantity, orderId, 0, executionType, symbolInfo, 'pending', {
                                operationPhase: 'close',
                                operationReason: state._lastPendingCloseReason,
                                executionMode: currentRuntimeExecutionMode,
                                positionId: state._lastPendingClosePositionId,
                                operationContext: {
                                    sync_source: 'direct_pending_close',
                                    ...(state._lastPendingCloseSourceQuoteId ? { source_quote_id: state._lastPendingCloseSourceQuoteId } : {}),
                                    ...(state._lastPendingCloseCycleId ? { cycle_close_id: state._lastPendingCloseCycleId } : {}),
                                    ...(state._lastPendingCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                                },
                            });
                            this.logger.execution(`Y" [REAL] Ordem de FECHAMENTO aceita (pendente no book). Operacao salva. OrderID: ${orderId}. Persistência ao confirmar fill.`);
                        } else {
                            state._lastPendingCloseOrderId = undefined;
                            state._lastPendingCloseReason = undefined;
                            state._lastPendingCloseExecutionMode = undefined;
                            state._lastPendingClosePositionId = undefined;
                            state._lastPendingCloseSourceQuoteId = undefined;
                            state._lastPendingCloseCycleId = undefined;
                            state._lastPendingCloseCycleNetApproved = undefined;
                            this.logger.execution(`Y" [REAL] Ordem de FECHAMENTO aceita sem orderId (pendente no book). Nao persistida.`);
                        }
                    } else {
                        // Abertura (open) pendente: persistir só com orderId real (nunca mock); guardar estado para confirmar fill depois.
                        if (orderId) {
                            state._lastPendingOpenOrderId = orderId;
                            state._lastPendingOpenPrice = price;
                            state._lastPendingOpenQty = quantity;
                            state._lastPendingOpenAction = action as 'sell' | 'buy';
                            state._lastPendingOpenCounted = shouldCountOpening;
                            state._lastPendingOpenReason = typeof state._nextOrderReason === 'string' ? state._nextOrderReason : undefined;
                            state._lastPendingOpenExecutionMode = currentRuntimeExecutionMode;
                            state._lastPendingOpenPositionId = String(state._nextOrderPositionIdOverride || '').trim() || undefined;
                            state._lastPendingOpenSourceQuoteId = String((state as any)?._nextOrderInstitutionalMeta?.sourceQuoteId || '').trim() || undefined;
                            await this.saveOperation(config, action, price, quantity, orderId, profit, executionType, symbolInfo, 'pending', {
                                operationPhase: 'open',
                                operationReason: state._lastPendingOpenReason,
                                executionMode: currentRuntimeExecutionMode,
                                positionId: state._lastPendingOpenPositionId,
                                operationContext: {
                                    sync_source: 'direct_pending_open',
                                    ...(state._lastPendingOpenSourceQuoteId ? { source_quote_id: state._lastPendingOpenSourceQuoteId } : {}),
                                },
                            });
                            this.logger.execution(`Y" [REAL] Ordem de abertura (pendente). Operacao salva. OrderID: ${orderId}. Persistência ao confirmar fill.`);
                        } else {
                            if (shouldCountOpening) {
                                if (action === 'buy') {
                                    await this.decrementBuySafe();
                                } else {
                                    await this.decrementSellSafe();
                                }
                            }
                            this.logger.execution(`Y" [REAL] Ordem de abertura aceita sem orderId; nao persistida (evita ordem mock).`);
                        }
                    }
                    return;
                }
                if (isClosingLong || isClosingShort) {
                    state._lastPendingCloseSentAt = undefined;
                    state._lastPendingCloseSide = undefined;
                    state._lastPendingCloseOrderId = undefined;
                    state._lastPendingClosePrice = undefined;
                    state._lastPendingCloseQty = undefined;
                    state._lastPendingCloseAction = undefined;
                    state._lastPendingCloseReason = undefined;
                    state._lastPendingCloseExecutionMode = undefined;
                    state._lastPendingClosePositionId = undefined;
                    state._lastPendingCloseSourceQuoteId = undefined;
                    state._lastPendingCloseCycleId = undefined;
                    state._lastPendingCloseCycleNetApproved = undefined;
                }
                if (isOpeningLong || isOpeningShort) {
                    state._lastPendingOpenOrderId = undefined;
                    state._lastPendingOpenPrice = undefined;
                    state._lastPendingOpenQty = undefined;
                    state._lastPendingOpenAction = undefined;
                    state._lastPendingOpenCounted = undefined;
                }
                success = true; // accepted && filled: commit estado e salvar com status success

                const hasLongPositions = state.buyPrices.length > 0 || state.activePositions.length > 0;

                // Correção: Calcular profit para fechamento considerando juros
                if (isClosingLong && hasLongPositions) {
                    // Correção: Priorizar activePositions, usar buyPrices como fallback
                    let lastBuyPrice = 0;
                    let position: MarketKingLongPosition | null = null;

                    if (state.activePositions.length > 0) {
                        position = state.activePositions[state.activePositions.length - 1];
                        lastBuyPrice = position.entryPrice;
                    } else if (state.buyPrices.length > 0) {
                        lastBuyPrice = state.buyPrices[state.buyPrices.length - 1];
                        const PRICE_TOLERANCE_CLOSE_REAL = config.priceTolerance ?? 0.0001;
                        position = state.activePositions.find(p =>
                            p.side === 'long' && Math.abs(p.entryPrice - lastBuyPrice) < PRICE_TOLERANCE_CLOSE_REAL
                        ) || null;
                    }

                    if (position) {
                        const closeBorrowedAmount = getBorrowedAmountForClose(
                            Number(position.borrowedAmount || 0),
                            Number(position.quantity || 0),
                            quantity
                        );
                        const accumulatedInterest = this.calculateAccumulatedInterest(
                            closeBorrowedAmount,
                            position.openTime,
                            position.dailyInterestRate || dailyInterestRate,
                            config // o. Passar config
                        );

                        // Crítico: Verificar se ha saldo suficiente para pagar emprestimo + juros (APENAS em modo REAL)
                        if (finalIsReal && !isPerpRuntime) {
                            const repaymentAmount = closeBorrowedAmount + accumulatedInterest;
                            const positionValue = price * quantity;

                            if (positionValue < repaymentAmount) {
                                this.logger.error(
                                    `O [REAL] [FECHAR LONG] Valor da posicao ($${positionValue.toFixed(2)}) < Emprestimo + Juros ($${repaymentAmount.toFixed(2)}). Nao e possivel fechar sem perda significativa.`
                                );
                                // Continuar mesmo assim, mas logar aviso
                            }
                        }

                        profit = this.calculateNetProfitWithLoan(
                            lastBuyPrice,
                            price,
                            quantity,
                            symbolInfo.takerFeeRate,
                            accumulatedInterest,
                            'long',
                            executionSlippagePercent,
                            executionSlippageModel
                        );
                        const fundingAdj = applyPerpExecutionModelToCloseProfit('long', position, profit, quantity);
                        profit = fundingAdj.profit;

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s [PÓS-EXECUÇÃO] Fechar LONG bloqueado (regra net > 0): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastBuyPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | Juros: ${accumulatedInterest.toFixed(4)} | Motivo: ${closeReason || 'n/a'}`
                            );
                            return;
                        }

                        // Remover emprestimo do total
                        if (position) {
                            this.logger.execution(
                                isPerpRuntime
                                    ? `Y' [FECHAR LONG] Ajuste exec: $${fundingAdj.executionAdjust.toFixed(4)} | Funding: $${fundingAdj.fundingCost.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                                    : `Y' [FECHAR LONG] Emprestimo: $${closeBorrowedAmount.toFixed(2)} | Juros: $${accumulatedInterest.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                            );
                        }
                        this.logger.profit(`Profit liquido apos custos: $${profit.toFixed(4)}`);
                    } else {
                        // Fallback: calcular sem juros
                        profit = this.calculateNetProfitWithFee(lastBuyPrice, price, quantity, symbolInfo.takerFeeRate);

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s [PÓS-EXECUÇÃO] Fechar LONG bloqueado (regra net > 0 fallback): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastBuyPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'}`
                            );
                            return;
                        }
                    }
                } else if (isClosingShort && state.shortPositions && state.shortPositions.length > 0) {
                    const lastShort = state.shortPositions[state.shortPositions.length - 1];
                    const lastShortPrice = lastShort.price;

                    const shortHasBorrowMetadata = Number(lastShort?.borrowedQuantity || 0) > 0 || Number(lastShort?.borrowedAmount || 0) > 0;
                    if (lastShort.openTime && (isPerpRuntime || shortHasBorrowMetadata)) {
                        // Em perp, borrowed/juro de margin nao se aplicam (funding e tratado separadamente).
                        const borrowedValueTotal = isPerpRuntime
                            ? 0
                            : (
                                Number.isFinite(Number(lastShort.borrowedAmount))
                                    ? Number(lastShort.borrowedAmount)
                                    : (lastShortPrice * Number(lastShort.borrowedQuantity || 0))
                            );
                        const closeBorrowedValue = isPerpRuntime
                            ? 0
                            : getBorrowedAmountForClose(
                                borrowedValueTotal,
                                Number(lastShort.quantity || 0),
                                quantity
                            );
                        const accumulatedInterest = isPerpRuntime
                            ? 0
                            : this.calculateAccumulatedInterest(
                                closeBorrowedValue,
                                lastShort.openTime,
                                lastShort.dailyInterestRate || dailyInterestRate,
                                config // o. Passar config
                            );

                        // Crítico: Verificar se ha saldo suficiente para pagar emprestimo + juros (APENAS em modo REAL)
                        if (finalIsReal && !isPerpRuntime) {
                            // Para SHORT: precisa ter tokens suficientes para devolver + juros em USDT
                            const requiredUSDT = closeBorrowedValue + accumulatedInterest;
                            const availableUSDT = this.getAvailableBalanceByCoin(balances, config.pair2);

                            if (availableUSDT < requiredUSDT) {
                                this.logger.warn(
                                    `s [PÓS-EXECUÇÃO] [FECHAR SHORT] Saldo USDT ($${availableUSDT.toFixed(2)}) < referencia de emprestimo+juros ($${requiredUSDT.toFixed(2)}). Ordem ja executada; conciliando estado para evitar divergencia.`
                                );
                            }
                        }

                        profit = this.calculateNetProfitWithLoan(
                            lastShortPrice,
                            price,
                            quantity,
                            symbolInfo.takerFeeRate,
                            accumulatedInterest,
                            'short',
                            executionSlippagePercent,
                            executionSlippageModel
                        );
                        const fundingAdj = applyPerpExecutionModelToCloseProfit('short', lastShort, profit, quantity);
                        profit = fundingAdj.profit;

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s [PÓS-EXECUÇÃO] Fechar SHORT bloqueado (regra net > 0): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastShortPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | Juros: ${accumulatedInterest.toFixed(4)} | Motivo: ${closeReason || 'n/a'}`
                            );
                            return;
                        }

                        this.logger.execution(
                            isPerpRuntime
                                ? `Y' [FECHAR SHORT] Ajuste exec: $${fundingAdj.executionAdjust.toFixed(4)} | Funding: $${fundingAdj.fundingCost.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                                : `Y' [FECHAR SHORT] Emprestimo: $${closeBorrowedValue.toFixed(2)} | Juros: $${accumulatedInterest.toFixed(4)} | Profit liquido: $${profit.toFixed(4)}`
                        );
                        this.logger.profit(`Profit liquido apos custos: $${profit.toFixed(4)}`);
                    } else {
                        // Fallback: calcular sem juros
                        profit = this.calculateNetProfitWithFee(lastShortPrice, price, quantity, symbolInfo.takerFeeRate);

                        if (!allowNegativeCloseForThisReason && !allowRuntimeNetBypass && (profit < minCloseProfitForReason)) {
                            this.logger.warn(
                                `s [PÓS-EXECUÇÃO] Fechar SHORT bloqueado (regra net > 0 fallback): Profit liquido ${profit.toFixed(4)} ${config.pair2} < piso ${minCloseProfitForReason}. ` +
                                `Preco entrada: ${lastShortPrice.toFixed(4)} | Preco saida: ${price.toFixed(4)} | Motivo: ${closeReason || 'n/a'}`
                            );
                            return;
                        }
                    }
                }

                // Validação crítica final: Regra net >= piso (institucional: minNetFloor; senão profit > 0). Exceto emergência.
                const finalFloor = this.isInstitutionalHybridStrategy(config) ? this.resolveMinNetFloor(config) : 0;
                if (requirePositiveClose && (isClosingLong || isClosingShort) && !allowNegativeCloseForThisReason && !allowRuntimeNetBypass && profit < finalFloor) {
                    const positionType = isClosingLong ? 'FECHAR LONG' : 'FECHAR SHORT';
                    this.logger.warn(
                        `s [PÓS-EXECUÇÃO] ${positionType} bloqueado (regra net >= piso): profit liquido ${profit.toFixed(4)} ${config.pair2} < ${finalFloor}. Motivo: ${closeReason || 'n/a'}`
                    );
                    return;
                }

                const positionType = isOpeningLong ? 'LONG' : isOpeningShort ? 'SHORT' : isClosingLong ? 'FECHAR LONG' : 'FECHAR SHORT';
                this.logger.execution(`Y" [REAL] ${positionType} EXECUTADO: ${quantity.toFixed(8)} ${config.pair1} em ${(price * quantity).toFixed(2)} ${config.pair2} (Leverage ${safeLeverage}x)${profit !== 0 ? ` | Profit: ${profit.toFixed(4)}` : ''}`);
                if (profit !== 0) {
                    this.logger.profit(`Profit da operacao: ${profit.toFixed(4)}`);
                }
            } else {
                this.logger.error(`O Modo desconhecido: "${config.opType}". Operacao rejeitada.`);
                return;
            }

            if (success && (isClosingLong || isClosingShort)) {
                const modeledCloseNet = isClosingLong
                    ? estimateCloseNetWithRuntimeModel('long', currentLongPosition, quantity)
                    : estimateCloseNetWithRuntimeModel('short', currentShortPosition, quantity);
                if (Number.isFinite(modeledCloseNet)) {
                    profit = modeledCloseNet;
                }
            }

            if (success) {
                const nowTs = Date.now();
                // Pine/CSV: append por transação
                if (isOpeningLong && pendingLongPosition) {
                    state.borrowedAmount = Math.max(0, Number(state.borrowedAmount || 0) + borrowedAmount);
                    state.activePositions.push(pendingLongPosition);
                    state.buyPrices = Array.isArray(state.buyPrices) ? state.buyPrices : [];
                    state.buyPrices.push(price);
                    state.lastBuyPrice = price;
                    openingStateCommitted = true;
                    if (this.pineScriptService && typeof (this.pineScriptService as any).appendOpenAndUpdatePine === 'function') {
                        (this.pineScriptService as any).appendOpenAndUpdatePine('long', price, quantity, nowTs, 30, {
                            positionId: (pendingLongPosition as any).positionId,
                            qgReason: (pendingLongPosition as any).qgReason,
                            qgHedge: (pendingLongPosition as any).qgHedge,
                            qgParentSide: (pendingLongPosition as any).qgParentSide,
                            gridOpenReason: (pendingLongPosition as any).gridOpenReason ?? (pendingLongPosition as any).qgReason,
                            gridIsHedge: (pendingLongPosition as any).gridIsHedge ?? (pendingLongPosition as any).qgHedge,
                            gridParentSide: (pendingLongPosition as any).gridParentSide ?? (pendingLongPosition as any).qgParentSide
                        }).catch((e: any) => this.logger.warn(`[PINE] appendOpenAndUpdatePine: ${e?.message || e}`));
                    }
                } else if (isOpeningShort && pendingShortPosition) {
                    if (!state.shortPositions) state.shortPositions = [];
                    state.shortPositions.push(pendingShortPosition);
                    state.borrowedAmount = Math.max(0, Number(state.borrowedAmount || 0) + borrowedAmount);
                    state.lastSellPrice = price;
                    openingStateCommitted = true;
                    if (this.pineScriptService && typeof (this.pineScriptService as any).appendOpenAndUpdatePine === 'function') {
                        (this.pineScriptService as any).appendOpenAndUpdatePine('short', price, quantity, nowTs, 30, {
                            positionId: (pendingShortPosition as any).positionId,
                            qgReason: (pendingShortPosition as any).qgReason,
                            qgHedge: (pendingShortPosition as any).qgHedge,
                            qgParentSide: (pendingShortPosition as any).qgParentSide,
                            gridOpenReason: (pendingShortPosition as any).gridOpenReason ?? (pendingShortPosition as any).qgReason,
                            gridIsHedge: (pendingShortPosition as any).gridIsHedge ?? (pendingShortPosition as any).qgHedge,
                            gridParentSide: (pendingShortPosition as any).gridParentSide ?? (pendingShortPosition as any).qgParentSide
                        }).catch((e: any) => this.logger.warn(`[PINE] appendOpenAndUpdatePine: ${e?.message || e}`));
                    }
                }

                if (isClosingLong && currentLongPosition) {
                    const fullQty = Math.max(0, Number(currentLongPosition.quantity || 0));
                    const closeQty = Math.max(0, Math.min(quantity, fullQty));
                    const closeRatio = getCloseRatio(fullQty, closeQty);
                    const borrowedPortion = getProportionalValue(Number(currentLongPosition.borrowedAmount || 0), closeRatio);
                    const marginPortion = getProportionalValue(Number(currentLongPosition.initialMargin || 0), closeRatio);
                    const remainingQty = Math.max(0, fullQty - closeQty);

                    state.borrowedAmount = Math.max(0, Number(state.borrowedAmount || 0) - borrowedPortion);

                    if (remainingQty <= closeQtyTolerance) {
                        closedLongFully = true;
                        state.activePositions = state.activePositions.filter(p => p.positionId !== currentLongPosition.positionId);
                    } else {
                        closedLongFully = false;
                        const remainingPosition = state.activePositions.find(p => p.positionId === currentLongPosition.positionId);
                        if (remainingPosition) {
                            remainingPosition.quantity = remainingQty;
                            remainingPosition.borrowedAmount = Math.max(0, Number(remainingPosition.borrowedAmount || 0) - borrowedPortion);
                            if (remainingPosition.initialMargin != null) {
                                remainingPosition.initialMargin = Math.max(0, Number(remainingPosition.initialMargin) - marginPortion);
                            }

                            if (isPartialClose && closeReasonTargetMultiple !== null && Number.isFinite(closeReasonTargetMultiple)) {
                                remainingPosition.partialTargetsHit = Array.isArray(remainingPosition.partialTargetsHit)
                                    ? remainingPosition.partialTargetsHit
                                    : [];
                                if (!remainingPosition.partialTargetsHit.includes(closeReasonTargetMultiple)) {
                                    remainingPosition.partialTargetsHit.push(closeReasonTargetMultiple);
                                }
                            }
                        }
                    }
                    if (this.pineScriptService && typeof (this.pineScriptService as any).appendCloseAndUpdatePine === 'function') {
                        const closeQtyVal = Math.max(0, Math.min(quantity, Math.max(0, Number(currentLongPosition.quantity || 0))));
                        (this.pineScriptService as any).appendCloseAndUpdatePine('long', price, closeQtyVal, nowTs, 30, {
                            netPnL: profit,
                            qgHedge: (currentLongPosition as any).qgHedge,
                            qgParentSide: (currentLongPosition as any).qgParentSide,
                            gridIsHedge: (currentLongPosition as any).gridIsHedge ?? (currentLongPosition as any).qgHedge,
                            gridParentSide: (currentLongPosition as any).gridParentSide ?? (currentLongPosition as any).qgParentSide,
                            positionId: (currentLongPosition as any).positionId,
                            closeQty: closeQtyVal
                        }).catch((e: any) => this.logger.warn(`[PINE] appendCloseAndUpdatePine long: ${e?.message || e}`));
                    }
                }

                if (isClosingShort && currentShortPosition) {
                    const fullQty = Math.max(0, Number(currentShortPosition.quantity || 0));
                    const closeQty = Math.max(0, Math.min(quantity, fullQty));
                    const closeRatio = getCloseRatio(fullQty, closeQty);
                    const borrowedBase = Number.isFinite(Number(currentShortPosition.borrowedAmount))
                        ? Number(currentShortPosition.borrowedAmount)
                        : Number(currentShortPosition.price || 0) * Number(currentShortPosition.borrowedQuantity || currentShortPosition.quantity || 0);
                    const borrowedPortion = getProportionalValue(borrowedBase, closeRatio);
                    const marginPortion = getProportionalValue(Number(currentShortPosition.initialMargin || 0), closeRatio);
                    const borrowedQtyBase = Number(currentShortPosition.borrowedQuantity || currentShortPosition.quantity || 0);
                    const borrowedQtyPortion = getProportionalValue(borrowedQtyBase, closeRatio);
                    const remainingQty = Math.max(0, fullQty - closeQty);

                    state.borrowedAmount = Math.max(0, Number(state.borrowedAmount || 0) - borrowedPortion);

                    if (remainingQty <= closeQtyTolerance) {
                        closedShortFully = true;
                        if (state.shortPositions && state.shortPositions.length > 0) {
                            const shortIndex = state.shortPositions.findIndex((p: MarketKingShortPosition) => {
                                if (currentShortPosition.positionId && p.positionId) {
                                    return p.positionId === currentShortPosition.positionId;
                                }
                                return p === currentShortPosition;
                            });
                            if (shortIndex >= 0) {
                                state.shortPositions.splice(shortIndex, 1);
                            } else {
                                state.shortPositions.pop();
                            }
                        }
                    } else {
                        closedShortFully = false;
                        const remainingShort = state.shortPositions?.find((p: MarketKingShortPosition) => {
                            if (currentShortPosition.positionId && p.positionId) {
                                return p.positionId === currentShortPosition.positionId;
                            }
                            return p === currentShortPosition;
                        });
                        if (remainingShort) {
                            remainingShort.quantity = remainingQty;
                            if (remainingShort.borrowedQuantity != null) {
                                remainingShort.borrowedQuantity = Math.max(0, Number(remainingShort.borrowedQuantity) - borrowedQtyPortion);
                            }
                            if (remainingShort.borrowedAmount != null) {
                                remainingShort.borrowedAmount = Math.max(0, Number(remainingShort.borrowedAmount) - borrowedPortion);
                            }
                            if (remainingShort.initialMargin != null) {
                                remainingShort.initialMargin = Math.max(0, Number(remainingShort.initialMargin) - marginPortion);
                            }

                            if (isPartialClose && closeReasonTargetMultiple !== null && Number.isFinite(closeReasonTargetMultiple)) {
                                remainingShort.partialTargetsHit = Array.isArray(remainingShort.partialTargetsHit)
                                    ? remainingShort.partialTargetsHit
                                    : [];
                                if (!remainingShort.partialTargetsHit.includes(closeReasonTargetMultiple)) {
                                    remainingShort.partialTargetsHit.push(closeReasonTargetMultiple);
                                }
                            }
                        }
                    }
                    if (this.pineScriptService && typeof (this.pineScriptService as any).appendCloseAndUpdatePine === 'function') {
                        const closeQtyShort = Math.max(0, Math.min(quantity, Math.max(0, Number(currentShortPosition.quantity || 0))));
                        (this.pineScriptService as any).appendCloseAndUpdatePine('short', price, closeQtyShort, nowTs, 30, {
                            netPnL: profit,
                            qgHedge: (currentShortPosition as any).qgHedge,
                            qgParentSide: (currentShortPosition as any).qgParentSide,
                            gridIsHedge: (currentShortPosition as any).gridIsHedge ?? (currentShortPosition as any).qgHedge,
                            gridParentSide: (currentShortPosition as any).gridParentSide ?? (currentShortPosition as any).qgParentSide,
                            positionId: (currentShortPosition as any).positionId,
                            closeQty: closeQtyShort
                        }).catch((e: any) => this.logger.warn(`[PINE] appendCloseAndUpdatePine short: ${e?.message || e}`));
                    }
                }

                // Contadores representam POSICOES ATIVAS por lado:
                // - abertura de LONG  => incrementa BUY
                // - abertura de SHORT => incrementa SELL
                // - fechamento total de LONG  => decrementa BUY
                // - fechamento total de SHORT => decrementa SELL
                // IMPORTANTE: Cada posiÃ§Ã£o primÃ¡ria (Base + MG1-MG4) fechada completamente deve decrementar o contador
                // Fechamentos (sell de long / buy de short) NAO incrementam contador do lado da acao.

                // Verificar se a posiÃ§Ã£o fechada era primÃ¡ria (nÃ£o hedge) antes do fechamento
                const wasPrimaryPosition = !this.isGridHedgePosition(currentLongPosition as any) && !this.isGridHedgePosition(currentShortPosition as any);

                if (isClosingLong && closedLongFully) {
                    if (state.buyPrices.length > 0) state.buyPrices.pop();
                    // Registrar preco de saida apenas no estado em memoria para telemetria do fechamento LONG.
                    if (!state.sellPrices) state.sellPrices = [];
                    const PRICE_TOLERANCE = config.priceTolerance ?? 0.0001;
                    const priceExists = state.sellPrices.some(sp => Math.abs(sp - price) < PRICE_TOLERANCE);
                    if (!priceExists) {
                        state.sellPrices.push(price);
                        this.logger.info(`Y" [SELL PRICES] Adicionado preco de venda LONG: ${price.toFixed(4)}`);
                    }

                    // Decrementar contador apenas se era posiÃ§Ã£o primÃ¡ria (Base ou MG)
                    // Hedges nÃ£o decrementam contador pois nÃ£o foram contabilizadas na abertura
                    if (wasPrimaryPosition || !this.isGridHedgePosition(currentLongPosition as any)) {
                        await this.decrementBuySafe();
                        this.logger.counter(`o. [FECHAR LONG] Contador de compras decrementado (posiÃ§Ã£o primÃ¡ria fechada completamente)`);
                    } else {
                        this.logger.counter(`o. [FECHAR LONG] Contador NÃƒO decrementado (posiÃ§Ã£o hedge fechada)`);
                    }
                }
                if (isClosingShort && closedShortFully) {
                    if (!currentShortPosition && state.shortPositions && state.shortPositions.length > 0) {
                        state.shortPositions.pop();
                    }

                    // Decrementar contador apenas se era posiÃ§Ã£o primÃ¡ria (Base ou MG)
                    // Hedges nÃ£o decrementam contador pois nÃ£o foram contabilizadas na abertura
                    if (wasPrimaryPosition || !this.isGridHedgePosition(currentShortPosition as any)) {
                        await this.decrementSellSafe();
                        this.logger.counter(`o. [FECHAR SHORT] Contador de vendas decrementado (posiÃ§Ã£o primÃ¡ria fechada completamente)`);
                    } else {
                        this.logger.counter(`o. [FECHAR SHORT] Contador NÃƒO decrementado (posiÃ§Ã£o hedge fechada)`);
                    }
                }

                // o. NOTA: Reset de ciclo completo agora e feito no inicio do directOrder
                // quando ambos contadores atingem o maximo, permitindo reset mesmo com posicoes abertas
                // Este bloco foi removido para evitar reset duplo
                state.forceStopLossSell = false;

                // Correção crítica: Incrementar totalProfit quando venda/fechamento e executado com sucesso
                // Isso garante que stop loss e outras operacoes tambem atualizem o total_profit
                // O profit calculado aqui e mais preciso (considera juros reais da execucao)
                // IMPORTANTE: Em margin trading, tanto fechamento de LONG (venda) quanto fechamento de SHORT (compra) geram profit
                if ((isClosingLong || isClosingShort) && profit !== 0) {
                    state.totalProfit += profit;
                    this.logger.profit(`o. Total Profit atualizado: ${state.totalProfit.toFixed(4)} (profit da execucao: ${profit.toFixed(4)})`);
                }

                // o. Salvar operacao com profit calculado e fee real do par (REAL/DEMO: status success)
                // Fechamento: garantir orderId e profit definidos (evita dados do fill incorretos no backend)
                const orderIdForRecord = orderId || (isClosingLong || isClosingShort ? `LOCAL_RECORD_REAL_CLOSE_${Date.now()}_${Math.random().toString(36).slice(2, 9)}` : '');
                const profitForRecord = Number.isFinite(Number(profit)) ? Number(profit) : 0;
                const recordExecutionMode = (isClosingLong || isClosingShort)
                    ? (state._nextCloseExecutionModeOverride
                        ? this.normalizeExecutionMode(state._nextCloseExecutionModeOverride, this.resolveCloseExecutionMode(config))
                        : this.resolveCloseExecutionMode(config))
                    : (state._nextOrderForceExecutionMode
                        ? this.normalizeExecutionMode(state._nextOrderForceExecutionMode, this.resolveOpenExecutionMode(config))
                        : this.resolveOpenExecutionMode(config));
                const sourceQuoteId = String((state as any)?._nextOrderInstitutionalMeta?.sourceQuoteId || '').trim();
                const cycleCloseId = String((state as any)?._nextCloseCycleId || '').trim();
                await this.saveOperation(config, action, price, quantity, orderIdForRecord, profitForRecord, executionType, symbolInfo, 'success', {
                    operationPhase: isClosingLong || isClosingShort ? 'close' : 'open',
                    operationReason: isClosingLong || isClosingShort
                        ? (typeof state._nextCloseReason === 'string' ? state._nextCloseReason : null)
                        : (typeof state._nextOrderReason === 'string' ? state._nextOrderReason : null),
                    executionMode: recordExecutionMode,
                    positionId: String(state._nextOrderPositionIdOverride || '').trim() || null,
                    operationContext: {
                        sync_source: 'direct_execution',
                        ...(sourceQuoteId ? { source_quote_id: sourceQuoteId } : {}),
                        ...(cycleCloseId ? { cycle_close_id: cycleCloseId } : {}),
                        ...((state as any)?._nextCloseCycleNetApproved ? { cycle_net_approved: true } : {}),
                    },
                });
            } else if (isOpeningLong || isOpeningShort) {
                // o. Se a ordem falhou apos incrementar, tentar reverter o incremento
                // (decrementSafe usa GREATEST(0, ...) no DB e e seguro em memoria)
                try {
                    if (action === 'buy') {
                        await this.decrementBuySafe();
                        this.logger.counter(`? [REVERT] Incremento de BUY revertido apos falha de execucao`);
                    } else {
                        await this.decrementSellSafe();
                        this.logger.counter(`? [REVERT] Incremento de SELL revertido apos falha de execucao`);
                    }
                } catch (err: any) {
                    this.logger.warn(`s Falha ao reverter contador apos falha de ordem: ${err?.message || err}`);
                }
                this.logger.warn(`s Ordem ${action.toUpperCase()} falhou apos incrementar contador. Tentativa de reversao executada.`);
            }

        } catch (error: any) {
            if (!openingStateCommitted && (isOpeningLong || isOpeningShort)) {
                try {
                    if (isOpeningLong) {
                        await this.decrementBuySafe();
                    } else if (isOpeningShort) {
                        await this.decrementSellSafe();
                    }
                } catch (revertError: any) {
                    this.logger.warn(`s Falha ao reverter contador no catch: ${revertError?.message || revertError}`);
                }
            }
            this.logger.error(`Erro ao executar ordem ${this.isFuturePerpetualTrade(config) ? 'MarketKingTrade/Future' : 'MarketKingTrade/Margin'}: ${error.message}`);
        } finally {
            state.forceStopLossSell = false;
            if (state._nextCloseQuantity !== undefined) delete state._nextCloseQuantity;
            if (state._nextCloseReason !== undefined) delete state._nextCloseReason;
            if (state._nextCloseIgnorePositiveCheck !== undefined) delete state._nextCloseIgnorePositiveCheck;
            if (state._nextCloseExecutionModeOverride !== undefined) delete state._nextCloseExecutionModeOverride;
            if (state._forceOpenSide !== undefined) delete state._forceOpenSide;
            if (state._nextOrderForceExecutionMode !== undefined) delete state._nextOrderForceExecutionMode;
            if (state._nextOrderPositionIdOverride !== undefined) delete state._nextOrderPositionIdOverride;
            if (state._nextOrderInstitutionalMeta !== undefined) delete state._nextOrderInstitutionalMeta;
            if (state._nextOrderParentSide !== undefined) delete state._nextOrderParentSide;
            if (state._nextOrderIsHedge !== undefined) delete state._nextOrderIsHedge;
            if (state._nextOrderReason !== undefined) delete state._nextOrderReason;
            if (state._nextOrderAmountUsdt !== undefined) delete state._nextOrderAmountUsdt;
        }
    }

    private extractTickerPrice(tickerInfo: any): number {
        const rawPrice =
            tickerInfo?.lastPr ??
            tickerInfo?.lastPrice ??
            tickerInfo?.price ??
            tickerInfo?.last ??
            tickerInfo?.close;
        const parsed = Number(rawPrice);
        return Number.isFinite(parsed) ? parsed : NaN;
    }

    private parseBalanceAvailable(balanceEntry: any): number {
        if (balanceEntry == null) return 0;
        if (typeof balanceEntry === 'number' || typeof balanceEntry === 'string') {
            const parsed = Number(balanceEntry);
            return Number.isFinite(parsed) ? parsed : 0;
        }

        if (typeof balanceEntry === 'object') {
            const parsed = Number(
                balanceEntry.available ??
                balanceEntry.availableMargin ??
                balanceEntry.availableBalance ??
                balanceEntry.free ??
                balanceEntry.balance ??
                0
            );
            return Number.isFinite(parsed) ? parsed : 0;
        }

        return 0;
    }

    private parseBalanceFreeze(balanceEntry: any): number {
        if (!balanceEntry || typeof balanceEntry !== 'object') return 0;
        const parsed = Number(
            balanceEntry.freeze ??
            balanceEntry.frozen ??
            balanceEntry.locked ??
            0
        );
        return Number.isFinite(parsed) ? parsed : 0;
    }

    private getAvailableBalanceByCoin(balances: any, coin: string): number {
        if (!balances || typeof balances !== 'object') return 0;
        const target = String(coin || '').trim().toUpperCase();
        if (!target) return 0;
        const directKeys = [coin, String(coin || '').toUpperCase(), String(coin || '').toLowerCase()];
        for (const key of directKeys) {
            if (key != null && (balances as any)[key] !== undefined) {
                return Math.max(0, this.parseBalanceAvailable((balances as any)[key]));
            }
        }
        const matchedKey = Object.keys(balances).find((k) => String(k).trim().toUpperCase() === target);
        return matchedKey != null ? Math.max(0, this.parseBalanceAvailable((balances as any)[matchedKey])) : 0;
    }

    private getTotalBalanceByCoin(balances: any, coin: string): number {
        if (!balances || typeof balances !== 'object') return 0;
        const target = String(coin || '').trim().toUpperCase();
        if (!target) return 0;

        const directKeys = [coin, String(coin || '').toUpperCase(), String(coin || '').toLowerCase()];
        for (const key of directKeys) {
            if (key != null && (balances as any)[key] !== undefined) {
                const entry = (balances as any)[key];
                return Math.max(0, this.parseBalanceAvailable(entry) + this.parseBalanceFreeze(entry));
            }
        }

        const matchedKey = Object.keys(balances).find((key) => String(key).trim().toUpperCase() === target);
        if (!matchedKey) return 0;
        const entry = balances[matchedKey];
        return Math.max(0, this.parseBalanceAvailable(entry) + this.parseBalanceFreeze(entry));
    }

    private getAssetToQuoteRate(
        asset: string,
        config: MarketKingTradingConfig,
        currentPrice: number
    ): number | null {
        const normalizedAsset = String(asset || '').trim().toUpperCase();
        const quoteAsset = String(config.pair2 || '').trim().toUpperCase();
        const baseAsset = String(config.pair1 || '').trim().toUpperCase();

        if (!normalizedAsset) return null;
        if (normalizedAsset === quoteAsset) return 1;
        if (normalizedAsset === baseAsset) {
            return Number.isFinite(currentPrice) && currentPrice > 0 ? currentPrice : null;
        }
        return null;
    }

    private sumBalancesInQuote(
        balances: any,
        config: MarketKingTradingConfig,
        currentPrice: number
    ): number {
        if (!balances || typeof balances !== 'object') return 0;

        let total = 0;
        for (const [asset, rawBalance] of Object.entries(balances)) {
            const available = this.parseBalanceAvailable(rawBalance);
            const freeze = this.parseBalanceFreeze(rawBalance);
            const amount = Math.max(0, available + freeze);
            if (!(amount > 0)) continue;

            const rate = this.getAssetToQuoteRate(String(asset), config, currentPrice);
            if (!(rate != null && Number.isFinite(rate) && rate > 0)) continue;

            total += amount * rate;
        }

        return Math.max(0, total);
    }

    private extractApiMarginLiabilitiesInQuote(
        marginAccountInfo: any,
        config: MarketKingTradingConfig,
        currentPrice: number
    ): { borrowedPrincipalQuote?: number; totalLiabilitiesQuote?: number; unresolvedAssets: string[] } {
        const unresolvedAssets = new Set<string>();

        const normalizedBorrowedQuote = Number(marginAccountInfo?.totalBorrowedQuote);
        const normalizedLiabilityQuote = Number(marginAccountInfo?.totalLiabilityQuote);
        if (
            Number.isFinite(normalizedBorrowedQuote) && normalizedBorrowedQuote >= 0 &&
            Number.isFinite(normalizedLiabilityQuote) && normalizedLiabilityQuote >= 0
        ) {
            return {
                borrowedPrincipalQuote: normalizedBorrowedQuote,
                totalLiabilitiesQuote: normalizedLiabilityQuote,
                unresolvedAssets: [],
            };
        }

        const assets = Array.isArray(marginAccountInfo?.assets) ? marginAccountInfo.assets : [];
        if (assets.length === 0) {
            return { unresolvedAssets: [] };
        }

        let borrowedPrincipalQuote = 0;
        let totalLiabilitiesQuote = 0;

        for (const row of assets) {
            const asset =
                row?.coin ??
                row?.asset ??
                row?.currency ??
                '';

            const borrowed = Number(
                row?.borrowed ??
                row?.borrow ??
                row?.borrowAmount ??
                row?.principal ??
                0
            );
            const interest = Number(
                row?.interest ??
                row?.accruedInterest ??
                row?.interestAmount ??
                0
            );
            const explicitLiability = Number(
                row?.totalLiability ??
                row?.liability ??
                row?.totalDebt ??
                row?.debt ??
                0
            );

            const borrowedSafe = Number.isFinite(borrowed) && borrowed > 0 ? borrowed : 0;
            const interestSafe = Number.isFinite(interest) && interest > 0 ? interest : 0;
            const explicitLiabilitySafe = Number.isFinite(explicitLiability) && explicitLiability > 0 ? explicitLiability : 0;
            const totalLiabilityRaw = Math.max(explicitLiabilitySafe, borrowedSafe + interestSafe);

            if (!(borrowedSafe > 0 || totalLiabilityRaw > 0)) continue;

            const rate = this.getAssetToQuoteRate(String(asset), config, currentPrice);
            if (!(rate != null && Number.isFinite(rate) && rate > 0)) {
                unresolvedAssets.add(String(asset || '').toUpperCase() || 'UNKNOWN');
                continue;
            }

            borrowedPrincipalQuote += borrowedSafe * rate;
            totalLiabilitiesQuote += totalLiabilityRaw * rate;
        }

        const result: { borrowedPrincipalQuote?: number; totalLiabilitiesQuote?: number; unresolvedAssets: string[] } = {
            unresolvedAssets: Array.from(unresolvedAssets),
        };
        if (Number.isFinite(borrowedPrincipalQuote)) {
            result.borrowedPrincipalQuote = Math.max(0, borrowedPrincipalQuote);
        }
        if (Number.isFinite(totalLiabilitiesQuote)) {
            result.totalLiabilitiesQuote = Math.max(0, totalLiabilitiesQuote);
        }
        return result;
    }

    private normalizeSymbolInfo(config: MarketKingTradingConfig, symbolInfo: any): any {
        const safeSymbolInfo = symbolInfo && typeof symbolInfo === 'object' ? symbolInfo : {};
        const isPerp = this.isFuturePerpetualTrade(config);
        const defaultTakerFeeFallback = isPerp ? 0.0006 : 0.001;
        const defaultMakerFeeFallback = isPerp ? 0.0002 : Math.max(0, Number(config.makerFeeRate ?? 0));

        const parseFee = (value: any, fallback: number): number => {
            const parsed = typeof value === 'string' ? parseFloat(value) : Number(value);
            return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
        };
        const parseIntLike = (value: any, fallback: number): number => {
            const parsed = Number(value);
            return Number.isInteger(parsed) && parsed >= 0 ? parsed : fallback;
        };
        const parsePositive = (value: any): number | undefined => {
            const parsed = Number(value);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : undefined;
        };
        const parseFinite = (value: any): number | undefined => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : undefined;
        };

        const takerFeeRate = parseFee(
            safeSymbolInfo.takerFeeRate ?? safeSymbolInfo.takerFee ?? safeSymbolInfo.feeRate ?? config.defaultTakerFeeRate,
            defaultTakerFeeFallback
        );
        const makerFeeRate = parseFee(
            safeSymbolInfo.makerFeeRate ?? safeSymbolInfo.makerFee ?? config.makerFeeRate,
            defaultMakerFeeFallback
        );

        const pricePrecision = parseIntLike(
            safeSymbolInfo.pricePrecision ?? safeSymbolInfo.pricePlace,
            8
        );
        const quantityPrecision = parseIntLike(
            safeSymbolInfo.quantityPrecision ?? safeSymbolInfo.volumePlace,
            8
        );
        const quantityStep = parsePositive(
            safeSymbolInfo.quantityStep ?? safeSymbolInfo.qtyStep ?? safeSymbolInfo.sizeMultiplier
        );
        const minOrderQty = parsePositive(
            safeSymbolInfo.minOrderQty ?? safeSymbolInfo.minQty ?? safeSymbolInfo.minTradeNum
        );
        const fundingRatePer8h = parseFinite(
            safeSymbolInfo.fundingRatePer8h ??
            safeSymbolInfo.fundingRate ??
            safeSymbolInfo.capitalRate ??
            safeSymbolInfo.fundRate
        );
        const fundingIntervalHours = parsePositive(
            safeSymbolInfo.fundingIntervalHours ??
            safeSymbolInfo.fundingInterval ??
            safeSymbolInfo.fundIntervalHours ??
            safeSymbolInfo.fundingIntervalHour
        );

        return {
            ...safeSymbolInfo,
            takerFeeRate,
            makerFeeRate,
            pricePrecision,
            quantityPrecision,
            ...(quantityStep ? { quantityStep } : {}),
            ...(minOrderQty ? { minOrderQty } : {}),
            ...(fundingRatePer8h !== undefined ? { fundingRatePer8h } : {}),
            ...(fundingIntervalHours ? { fundingIntervalHours } : {}),
        };
    }

    private calculateAccountMargin(
        balances: any,
        state: MarketKingTradingState,
        config: MarketKingTradingConfig,
        tickerInfo: any,
        realBorrowedAmount?: number,
        realTotalLiabilitiesAmount?: number,
        realInterestRate?: number,
        isDemo: boolean = false
    ): MarketKingAccountMetrics {
        const currentPrice = this.extractTickerPrice(tickerInfo) || 1;
        const isPerpRuntime = this.isFuturePerpetualTrade(config);

        // Validação: Garantir que currentPrice e valido
        if (!isFinite(currentPrice) || currentPrice <= 0) {
            this.logger.warn(`[${this.botName}] s Preco invalido recebido: ${currentPrice}. Usando valor padrao.`);
        }

        const effectiveDailyRate = this.resolveEffectiveCarryRate(config, realInterestRate);

        if (isPerpRuntime) {
            const safePrice = Number.isFinite(currentPrice) && currentPrice > 0 ? currentPrice : 1;
            const leverageDefault = Math.max(1, Number(config.leverage || 1));
            const takerFeeRate = Number(config.defaultTakerFeeRate ?? 0.0006);
            const demoSnapshot = isDemo
                ? this.computeDemoCapitalSnapshot(
                    state,
                    config,
                    safePrice,
                    takerFeeRate,
                    effectiveDailyRate
                )
                : null;
            const availableQuote = isDemo
                ? Math.max(0, Number(demoSnapshot?.availableQuote || 0))
                : Math.max(
                    0,
                    Number(this.getAvailableBalanceByCoin(balances, config.pair2) || this.sumBalancesInQuote(balances, config, safePrice) || 0)
                );

            return this.riskManager.computePerpAccountMarginMetrics({
                currentPrice: safePrice,
                leverageDefault,
                isDemo,
                availableQuote,
                demoEquityOverride: isDemo ? Math.max(0, Number(demoSnapshot?.equity || 0)) : null,
                activePositions: state.activePositions,
                shortPositions: state.shortPositions,
                liquidationMarginLevelThreshold: config.liquidationMarginLevelThreshold,
                defensiveCloseMarginLevelThreshold: config.defensiveCloseMarginLevelThreshold,
            });
        }

        // Em DEMO, os saldos da exchange podem vir zerados. Portanto a conta de margem
        // deve ser derivada da banca simulada (equity) + passivos simulados (borrow+juros).
        if (isDemo) {
            const takerFeeRate = Number(config.defaultTakerFeeRate ?? 0.0006);
            const demoSnapshot = this.computeDemoCapitalSnapshot(
                state,
                config,
                currentPrice,
                takerFeeRate,
                effectiveDailyRate
            );

            let borrowedPrincipal = 0;
            let borrowedInterest = 0;

            for (const pos of Array.isArray(state.activePositions) ? state.activePositions : []) {
                const principal = Math.max(0, Number(pos.borrowedAmount || 0));
                borrowedPrincipal += principal;
                borrowedInterest += this.calculateAccumulatedInterest(
                    principal,
                    Number(pos.openTime || Date.now()),
                    Number(pos.dailyInterestRate || effectiveDailyRate),
                    config
                );
            }

            for (const sp of Array.isArray(state.shortPositions) ? state.shortPositions : []) {
                const fallbackBorrowed = Math.max(0, Number(sp.price || 0) * Number(sp.borrowedQuantity || sp.quantity || 0));
                const principal = Number.isFinite(Number(sp.borrowedAmount))
                    ? Math.max(0, Number(sp.borrowedAmount))
                    : fallbackBorrowed;
                borrowedPrincipal += principal;
                borrowedInterest += this.calculateAccumulatedInterest(
                    principal,
                    Number(sp.openTime || Date.now()),
                    Number(sp.dailyInterestRate || effectiveDailyRate),
                    config
                );
            }

            const totalLiabilities = Math.max(0, borrowedPrincipal + Math.max(0, borrowedInterest));
            const totalAssets = Math.max(0, Number(demoSnapshot.equity || 0) + totalLiabilities);
            const referenceEntryPrice = Number(state.lastBuyPrice || state.lastSellPrice || currentPrice || 1);
            const totalLongQuantity = (Array.isArray(state.activePositions) ? state.activePositions : []).reduce((sum, p) => {
                const qty = Number(p.quantity || 0);
                return sum + (Number.isFinite(qty) && qty > 0 ? qty : 0);
            }, 0);

            return this.riskManager.computeClassicAccountMarginMetrics({
                totalAssets,
                totalLiabilities,
                borrowedValue: borrowedPrincipal,
                entryPrice: referenceEntryPrice,
                currentPrice,
                totalQuantity: totalLongQuantity,
                leverage: config.leverage || 1,
                dailyInterestRate: effectiveDailyRate,
            });
        }

        // Crítico: Calcular valor total de posicoes LONG abertas
        const longPositionsValue = state.activePositions.reduce((sum, p) => {
            const positionValue = (isFinite(currentPrice) && currentPrice > 0 ? currentPrice : 1) * (p.quantity || 0);
            return sum + (isFinite(positionValue) ? positionValue : 0);
        }, 0);

        // Crítico: Total assets = saldo disponivel + valor das posicoes LONG
        // (SHORT nao adiciona ao total assets, apenas aumenta passivo)
        const balanceAssets = this.sumBalancesInQuote(balances, config, currentPrice);
        const baseBalanceQty = this.getTotalBalanceByCoin(balances, config.pair1);
        const baseBalanceQuoteValue = (Number.isFinite(currentPrice) && currentPrice > 0)
            ? Math.max(0, baseBalanceQty * currentPrice)
            : 0;
        // Se o saldo em base ja representa o LONG mantido na conta, evita dupla contagem.
        // Em caso de atraso/falta de saldo em base, complementa com a diferenca do runtime.
        const uncoveredLongPositionsValue = Math.max(0, Number(longPositionsValue) - baseBalanceQuoteValue);
        const totalAssets = Math.max(0, Number(balanceAssets) + uncoveredLongPositionsValue);

        // Correção: Usar valores reais da API quando disponiveis
        // Validação: Garantir valores validos
        const borrowedValue = (realBorrowedAmount !== undefined && isFinite(realBorrowedAmount) && realBorrowedAmount >= 0)
            ? realBorrowedAmount
            : ((state.borrowedAmount !== undefined && isFinite(state.borrowedAmount) && state.borrowedAmount >= 0)
                ? state.borrowedAmount
                : 0);
        const totalLiabilities = (realTotalLiabilitiesAmount !== undefined && isFinite(realTotalLiabilitiesAmount) && realTotalLiabilitiesAmount >= 0)
            ? realTotalLiabilitiesAmount
            : borrowedValue;

        const entryPrice = (state.lastBuyPrice && isFinite(state.lastBuyPrice) && state.lastBuyPrice > 0)
            ? state.lastBuyPrice
            : currentPrice;

        const totalQuantity = state.activePositions.reduce((sum, p) => {
            return sum + (isFinite(p.quantity) && p.quantity >= 0 ? p.quantity : 0);
        }, 0);

        return this.riskManager.computeClassicAccountMarginMetrics({
            totalAssets,
            totalLiabilities, // passivo total (principal + juros quando disponivel)
            borrowedValue,
            entryPrice,
            currentPrice,
            totalQuantity,
            leverage: config.leverage || 1,
            dailyInterestRate: effectiveDailyRate,
        });
    }

    private calculatePositionsRisk(
        state: MarketKingTradingState,
        config: MarketKingTradingConfig,
        tickerInfo: any
    ): PositionRisk[] {
        const markPrice = Number(tickerInfo?.markPrice);
        const currentPrice = Number.isFinite(markPrice) && markPrice > 0
            ? markPrice
            : (this.extractTickerPrice(tickerInfo) || 0);
        const isPerpRuntime = this.isFuturePerpetualTrade(config);
        return this.riskManager.calculatePositionsRisk({
            state,
            config,
            currentPrice,
            isPerpRuntime,
        });
    }

    private async handleRiskAlerts(
        alerts: RiskAlert[],
        config: MarketKingTradingConfig,
        isDemo: boolean = false
    ): Promise<void> {
        await this.safetyGates.handleRiskAlerts(alerts, config, isDemo, this);
    }

    private async maybeExecuteDemoCriticalRiskOff(
        alerts: RiskAlert[],
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        currentPrice: number,
        balances: any,
        symbolInfo: any,
        dailyInterestRate: number
    ): Promise<boolean> {
        const isDemo = getMode(this, true, config);
        if (!isDemo) return false;
        if (this.isInstitutionalHybridStrategy(config)) {
            if (!Number.isFinite(Number(config.minCloseProfitUsd))) {
                config.minCloseProfitUsd = this.resolveMinNetCloseUsd(config);
            }
            return false;
        }

        const { shouldExecute } = this.safetyGates.shouldExecuteDemoRiskOff(
            alerts,
            config,
            state,
            true
        );
        if (!shouldExecute) return false;

        state._lastDemoRiskOffAt = Date.now();
        this.logger.warn(
            `[DEMO][RISK-OFF] Alertas criticos detectados. Aplicando fechamento local para espelhar contenção do modo real.`
        );

        await this.closeAllPositions(
            client,
            config,
            state,
            true,
            currentPrice,
            balances,
            symbolInfo,
            dailyInterestRate,
            true
        );

        return true;
    }

    private async unwrapResponsePayload(response: any): Promise<any> {
        return this.brokerOperations.unwrapResponsePayload(response);
    }

    private extractOrderId(raw: any): string {
        return this.brokerOperations.extractOrderId(raw);
    }

    private getOrderStatus(raw: any): string {
        return this.brokerOperations.getOrderStatus(raw);
    }

    private isFilledStatus(status: string): boolean {
        return this.brokerOperations.isFilledStatus(status);
    }

    private isRejectedStatus(status: string): boolean {
        return this.brokerOperations.isRejectedStatus(status);
    }

    private async sleep(ms: number): Promise<void> {
        return this.brokerOperations.sleep(ms);
    }

    private async fetchMarginOrderStatus(client: any, config: MarketKingTradingConfig, orderId: string): Promise<string> {
        return this.brokerOperations.fetchOrderStatus(client, orderId, config.market, {
            productType: this.isFuturePerpetualTrade(config) ? this.resolveManagedFuturesProductType(config) : undefined,
            marginMode: config.marginMode,
            isMixPerp: this.isFuturePerpetualTrade(config),
        });
    }

    private async cancelMarginOrderBestEffort(client: any, config: MarketKingTradingConfig, orderId: string): Promise<void> {
        await this.brokerOperations.cancelOrder(client, {
            symbol: config.market,
            orderId: String(orderId),
            productType: this.isFuturePerpetualTrade(config) ? this.resolveManagedFuturesProductType(config) : undefined,
            marginMode: config.marginMode,
        });
    }

    private async waitMarginOrderFilledOrCancel(
        client: any,
        config: MarketKingTradingConfig,
        orderId: string,
        maxAttempts = 8,
        delayMs = 500,
        options?: { keepPendingOnTimeout?: boolean }
    ): Promise<boolean> {
        if (!orderId) return false;

        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            try {
                const status = await this.fetchMarginOrderStatus(client, config, orderId);
                if (this.isFilledStatus(status)) return true;
                if (this.isRejectedStatus(status)) return false;
            } catch (statusError: any) {
                this.logger.warn(
                    `[REAL] Falha ao consultar status da ordem ${orderId} (tentativa ${attempt + 1}/${maxAttempts}): ${statusError?.message || statusError}`
                );
            }
            await this.sleep(delayMs);
        }

        if (this.isFuturePerpetualTrade(config)) {
            // Em managed futures, permitir comportamento configuravel:
            // - keepPendingOnTimeout=true: deixa a ordem aberta (requer sync robusto de pending/fills)
            // - false (padrao seguro): cancela para evitar fill tardio sem commit de estado local
            if (options?.keepPendingOnTimeout) {
                return false;
            }
            await this.cancelMarginOrderBestEffort(client, config, orderId);
            return false;
        }
        await this.cancelMarginOrderBestEffort(client, config, orderId);
        return false;
    }

    private async cancelOpenOrdersBeforeShutdown(client: any, config: MarketKingTradingConfig): Promise<number> {
        return this.brokerOperations.cancelAllOpenOrders(client, {
            symbol: config.market,
            productType: this.isFuturePerpetualTrade(config) ? this.resolveManagedFuturesProductType(config) : undefined,
            marginMode: config.marginMode,
        });
    }

    private collectMarginLiabilities(marginAccountInfo: any): Array<{ asset: string; amount: number }> {
        return this.brokerOperations.collectMarginLiabilities(marginAccountInfo);
    }

    private sumLiabilityAmount(liabilities: Array<{ asset: string; amount: number }>): number {
        return this.brokerOperations.sumLiabilityAmount(liabilities);
    }

    private async repayResidualLiabilities(
        client: any,
        config: MarketKingTradingConfig,
        liabilities: Array<{ asset: string; amount: number }>
    ): Promise<number> {
        return this.brokerOperations.repayResidualLiabilities(
            client,
            config,
            liabilities,
            this.isFuturePerpetualTrade(config)
        );
    }

    public async shutdown(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        context: MarketKingShutdownContext = {}
    ): Promise<MarketKingShutdownResult> {
        this.validateAndSetDemoMode(config, state);
        const isDemo = getMode(this, config.opType?.toLowerCase() === 'demo', config);

        const symbolInfo = this.normalizeSymbolInfo(config, context.symbolInfo || {});
        const tickerInfo = context.tickerInfo || {};
        const currentPrice = this.extractTickerPrice(tickerInfo);
        const fallbackStatePrice = Number(
            state.lastBuyPrice ||
            state.lastSellPrice ||
            state.activePositions?.[state.activePositions.length - 1]?.entryPrice ||
            state.shortPositions?.[state.shortPositions.length - 1]?.price ||
            0
        );
        const markPrice = Number.isFinite(currentPrice) && currentPrice > 0
            ? currentPrice
            : (Number.isFinite(fallbackStatePrice) && fallbackStatePrice > 0 ? fallbackStatePrice : undefined);
        const balances = context.balances || {};
        const dailyInterestRate = this.resolveEffectiveCarryRate(config, context.interestRate);
        const shouldCloseOpenPositions = config.finalCloseOpenPositions !== false;

        let canceledOrders = 0;
        if (!isDemo) {
            try {
                canceledOrders = await this.cancelOpenOrdersBeforeShutdown(client, config);
            } catch (cancelError: any) {
                this.logger.warn(`[SHUTDOWN] Falha ao cancelar ordens pendentes: ${cancelError?.message || cancelError}`);
            }
        }

        if (shouldCloseOpenPositions) {
            await this.closeAllPositions(
                client,
                config,
                state,
                isDemo,
                markPrice,
                balances,
                symbolInfo,
                dailyInterestRate,
                true
            );
        } else {
            this.logger.info(
                '[SHUTDOWN] finalCloseOpenPositions=false. Preservando posicoes de margem e passivos para retomada.'
            );
        }

        let marginSnapshot = context.marginAccountInfo;
        if (!isDemo && typeof context.refreshUserConfig === 'function') {
            try {
                const refreshedConfig = await context.refreshUserConfig();
                marginSnapshot = refreshedConfig?.marginAccountInfo ?? marginSnapshot;
            } catch (refreshError: any) {
                this.logger.warn(`[SHUTDOWN] Falha ao atualizar snapshot de margem: ${refreshError?.message || refreshError}`);
            }
        }

        let liabilities = this.collectMarginLiabilities(marginSnapshot);
        let residualLiability = this.sumLiabilityAmount(liabilities);
        let repaidAssets = 0;

        if (shouldCloseOpenPositions && !isDemo && residualLiability > 1e-8) {
            repaidAssets = await this.repayResidualLiabilities(client, config, liabilities);

            if (typeof context.refreshUserConfig === 'function') {
                try {
                    const refreshedAfterRepay = await context.refreshUserConfig();
                    liabilities = this.collectMarginLiabilities(refreshedAfterRepay?.marginAccountInfo);
                    residualLiability = this.sumLiabilityAmount(liabilities);
                } catch (refreshAfterRepayError: any) {
                    this.logger.warn(
                        `[SHUTDOWN] Falha ao validar passivo apos quitacao: ${refreshAfterRepayError?.message || refreshAfterRepayError}`
                    );
                }
            }
        }

        const closedPositions =
            (state.activePositions?.length || 0) === 0 &&
            (state.buyPrices?.length || 0) === 0 &&
            (state.shortPositions?.length || 0) === 0;

        if (shouldCloseOpenPositions && !closedPositions) {
            this.logger.warn(
                `[SHUTDOWN] Encerramento incompleto: LONG=${state.activePositions?.length || state.buyPrices?.length || 0}, SHORT=${state.shortPositions?.length || 0}.`
            );
        } else if (!shouldCloseOpenPositions) {
            this.logger.info(
                `[SHUTDOWN] Encerramento sem fechamento forcado: LONG=${state.activePositions?.length || state.buyPrices?.length || 0}, SHORT=${state.shortPositions?.length || 0}.`
            );
        }

        if (shouldCloseOpenPositions && !isDemo && residualLiability > 1e-8) {
            this.logger.warn(
                `[SHUTDOWN] Passivo de margem residual detectado apos parada: ${residualLiability.toFixed(8)}.`
            );
        } else if (shouldCloseOpenPositions) {
            this.logger.info(
                `[SHUTDOWN] Encerramento concluido. Ordens canceladas: ${canceledOrders} | Ativos quitados: ${repaidAssets}.`
            );
        } else {
            this.logger.info(
                `[SHUTDOWN] Encerramento concluido sem liquidacao de posicoes. Ordens canceladas: ${canceledOrders}.`
            );
        }

        return {
            canceledOrders,
            closedPositions,
            residualLiability,
            repaidAssets,
        };
    }

    private async closeAllPositions(
        client: any,
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        isDemo: boolean = false,
        currentPrice?: number,
        balances?: any,
        symbolInfo?: any,
        dailyInterestRate: number = 0,
        resetCycleAfterClose: boolean = false
    ): Promise<void> {
        // Resolve modo operacional priorizando this.isDemo quando aplicavel
        isDemo = getMode(this, isDemo, config);
        const hasLongPositions = () => state.activePositions.length > 0 || state.buyPrices.length > 0;
        const hasShortPositions = () => !!(state.shortPositions && state.shortPositions.length > 0);
        const clearInMemoryPositions = () => {
            state.activePositions = [];
            state.shortPositions = [];
            state.buyPrices = [];
            state.sellPrices = [];
            state.borrowedAmount = 0;
            state.marginLevel = 0;
            state.lastBuyPrice = 0;
            state.lastSellPrice = 0;
        };

        const markPrice = Number(currentPrice || 0);
        const canExecuteCloseOrders =
            Number.isFinite(markPrice) &&
            markPrice > 0 &&
            balances &&
            symbolInfo &&
            typeof client === 'object' &&
            client !== null;

        if (!hasLongPositions() && !hasShortPositions()) {
            if (resetCycleAfterClose) {
                await this.resetCountsSafe();
                state._cycleReset = true;
                delete state._cyclePendingReset;
            }
            return;
        }

        if (!canExecuteCloseOrders) {
            if (isDemo) {
                clearInMemoryPositions();
                this.logger.warn('[DEMO] Fechamento total sem contexto de preco/API: estado limpo em memoria.');
                if (resetCycleAfterClose) {
                    await this.resetCountsSafe();
                    state._cycleReset = true;
                    delete state._cyclePendingReset;
                }
                return;
            }
            this.logger.error('[REAL] Nao foi possivel forcar fechamento total: contexto de preco/API indisponivel.');
            return;
        }

        const maxLongAttempts = Math.max(1, state.activePositions.length + state.buyPrices.length + 2);
        const maxShortAttempts = Math.max(1, (state.shortPositions?.length || 0) + 2);

        let attempts = 0;
        while (hasLongPositions() && attempts < maxLongAttempts) {
            attempts++;
            const before = state.activePositions.length > 0 ? state.activePositions.length : state.buyPrices.length;
            state.forceStopLossSell = true;
            await this.executeMarginOrder(
                'sell',
                markPrice,
                balances,
                symbolInfo,
                client,
                config,
                state,
                dailyInterestRate,
                isDemo,
                !isDemo
            );
            const after = state.activePositions.length > 0 ? state.activePositions.length : state.buyPrices.length;
            if (after >= before) {
                this.logger.warn(`[${isDemo ? 'DEMO' : 'REAL'}] Fechamento LONG nao progrediu (before=${before}, after=${after}).`);
                break;
            }
        }

        attempts = 0;
        while (hasShortPositions() && attempts < maxShortAttempts) {
            attempts++;
            const before = state.shortPositions?.length || 0;
            state.forceStopLossSell = true;
            await this.executeMarginOrder(
                'buy',
                markPrice,
                balances,
                symbolInfo,
                client,
                config,
                state,
                dailyInterestRate,
                isDemo,
                !isDemo
            );
            const after = state.shortPositions?.length || 0;
            if (after >= before) {
                this.logger.warn(`[${isDemo ? 'DEMO' : 'REAL'}] Fechamento SHORT nao progrediu (before=${before}, after=${after}).`);
                break;
            }
        }

        state.forceStopLossSell = false;

        if (!hasLongPositions() && !hasShortPositions()) {
            clearInMemoryPositions();
            this.logger.warn(`[${isDemo ? 'DEMO' : 'REAL'}] Fechamento total concluido. Nenhuma posicao de margem permanece aberta.`);
            if (resetCycleAfterClose) {
                await this.resetCountsSafe();
                state._cycleReset = true;
                delete state._cyclePendingReset;
                this.logger.info(`o. [CICLO] Reset concluido apos fechamento total de posicoes.`);
            }
            return;
        }

        this.logger.error(
            `[${isDemo ? 'DEMO' : 'REAL'}] Fechamento total incompleto: LONG=${state.activePositions.length || state.buyPrices.length}, SHORT=${state.shortPositions?.length || 0}.`
        );
    }

    /**
    * Calcula lucro líquido considerando taxas (consistente com SpotTrade).
    */
    private calculateNetProfitWithFee(
        entryPrice: number,
        exitPrice: number,
        quantity: number,
        takerFeeRate: number
    ): number {
        // o. Calcula lucro LIQUIDO considerando:
        // 1. Taxa de compra
        // 2. Taxa de venda
        // 3. Diferenca de preco

        const buyCost = entryPrice * quantity;
        const takerFeeOnBuy = buyCost * takerFeeRate;
        const totalBuyCost = buyCost + takerFeeOnBuy;

        const sellRevenue = exitPrice * quantity;
        const takerFeeOnSell = sellRevenue * takerFeeRate;
        const netSellRevenue = sellRevenue - takerFeeOnSell;

        const netProfit = netSellRevenue - totalBuyCost;

        return parseFloat(netProfit.toFixed(4));
    }

    /**
    * Otimização: Salvamento assincrono (fire-and-forget)
    * Nao bloqueia execucao do trading
    * Novo: Usa Batch Operations quando disponivel (reduz latencia de 5s para <10ms)
    * o. FEE: Usa takerFeeRate do par (symbolInfo) - nao dummy/hardcoded
    * o. MAKER: Backend (API e Batch) faz upsert por order_id: mesma ordem enviada como pending
    *    e depois como success atualiza o registro existente (evita duplicatas e garante 1 linha por ordem).
    */
    private async saveOperation(
        config: MarketKingTradingConfig,
        action: string,
        price: number,
        quantity: number,
        orderId: string,
        profit: number,
        executionType: 'simulated' | 'real' = 'real',
        symbolInfo?: any,
        operationStatus: 'pending' | 'success' | 'canceled' = 'success',
        metadata?: MarginOperationRecordMetadata | null
    ): Promise<void> {
        const normalizedTradeType = this.isFuturePerpetualTrade(config) ? 'future' : 'margin';
        const logScope = normalizedTradeType === 'future' ? '[MARKETKING/FUTURE]' : '[MARKETKING/MARGIN]';
        // Taxa persistida acompanha o modelo de execucao (maker/taker/market) quando aplicavel.
        const rawTakerRate = symbolInfo?.takerFeeRate ?? config.defaultTakerFeeRate ?? 0.0006;
        const rawMakerRate = symbolInfo?.makerFeeRate ?? config.makerFeeRate ?? rawTakerRate;
        const takerFeeRate = typeof rawTakerRate === 'string' ? parseFloat(rawTakerRate) : Number(rawTakerRate);
        const makerFeeRate = typeof rawMakerRate === 'string' ? parseFloat(rawMakerRate) : Number(rawMakerRate);
        if (symbolInfo && (symbolInfo.takerFeeRate == null || symbolInfo.takerFeeRate === undefined)) {
            this.logger.warn(`s ${logScope} takerFeeRate nao encontrado em symbolInfo para ${config.market}. Usando fallback: ${takerFeeRate}`);
        }
        const operationPhase: 'open' | 'close' = metadata?.operationPhase === 'close'
            ? 'close'
            : metadata?.operationPhase === 'open'
                ? 'open'
                : (Number.isFinite(Number(profit)) && Number(profit) !== 0 ? 'close' : 'open');
        const executionMode = metadata?.executionMode
            ? this.normalizeExecutionMode(
                metadata.executionMode,
                operationPhase === 'close' ? this.resolveCloseExecutionMode(config) : this.resolveOpenExecutionMode(config)
            )
            : (operationPhase === 'close' ? this.resolveCloseExecutionMode(config) : this.resolveOpenExecutionMode(config));
        const operationReason = String(metadata?.operationReason || '').trim() || null;
        const positionId = String(metadata?.positionId || '').trim() || null;
        const operationContext = metadata?.operationContext && typeof metadata.operationContext === 'object'
            ? metadata.operationContext
            : null;
        const feeRateForRecord = this.resolveFeeRateForMode(executionMode, takerFeeRate, makerFeeRate);
        const executedNotionalUsdt = Math.max(0, Number(price || 0) * Math.max(0, Number(quantity || 0)));
        const leverageForRecord = Math.max(1, Number(config.leverage || 1));
        const amountSizingMode = String(config.amountSizingMode || 'notional').toLowerCase();
        // Persistir valor base (margem) em cenÃ¡rios alavancados para evitar conflito visual no painel.
        const shouldPersistBaseAmount = amountSizingMode === 'margin_base' || normalizedTradeType === 'future';
        const persistedAmountUsdt = shouldPersistBaseAmount
            ? (executedNotionalUsdt / leverageForRecord)
            : executedNotionalUsdt;
        const fee = parseFloat((executedNotionalUsdt * feeRateForRecord).toFixed(8));

        const statusForRecord = operationStatus;
        const opTypeForBackend = String(config.opType || 'demo').toLowerCase().trim() === 'real' ? 'real' : 'demo';
        const shouldPersistProfit = operationPhase === 'close' && statusForRecord === 'success';
        const data = {
            bot_id: config.botId,
            symbol: config.market,
            side: action,
            trade_type: normalizedTradeType,
            order_id: String(orderId),
            price: price,
            size: quantity,
            amount_usdt: parseFloat(persistedAmountUsdt.toFixed(8)),
            amount_op: parseFloat(executedNotionalUsdt.toFixed(8)),
            fee: fee,
            profit: shouldPersistProfit && Number.isFinite(Number(profit)) ? Number(profit) : null,
            status: statusForRecord,
            op_type: opTypeForBackend,
            execution_type: executionType,
            is_demo: executionType === 'simulated',
            leverage: config.leverage,
            margin_mode: config.marginMode,
            operation_phase: operationPhase,
            operation_reason: operationReason,
            execution_mode: executionMode,
            position_id: positionId,
            operation_context: operationContext,
            timestamp: new Date().toISOString()
        };
        applyRuntimeCreditUsageEstimate(config, data);
        void emitRuntimeRobotOperation({
            robot: 'marketking',
            config,
            operation: data,
            source: 'marketking.saveOperation',
        }).catch(() => undefined);

        // Novo: Usar Batch Operations se disponivel (latencia <10ms vs 5s HTTP) - REAL e DEMO
        if (this.batchOps) {
            try {
                this.batchOps.addOperation({
                    bot_id: config.botId,
                    symbol: config.market,
                    side: action,
                    price: price,
                    size: quantity,
                    amount_usdt: parseFloat(persistedAmountUsdt.toFixed(8)), // Valor base (margem) em operaÃ§Ãµes alavancadas
                    amount_op: parseFloat(executedNotionalUsdt.toFixed(8)), // Valor operado/notional executado
                    fee: fee,
                    profit: shouldPersistProfit && Number.isFinite(Number(profit)) ? Number(profit) : null,
                    order_id: String(orderId),
                    trade_type: normalizedTradeType,
                    op_type: opTypeForBackend,
                    status: statusForRecord,
                    execution_type: executionType,
                    is_demo: executionType === 'simulated',
                    leverage: config.leverage,
                    margin_mode: config.marginMode,
                    operation_phase: operationPhase,
                    operation_reason: operationReason,
                    execution_mode: executionMode,
                    position_id: positionId,
                    operation_context: operationContext
                });
                this.logger.operation(`o. ${logScope} Operacao adicionada ao batch (${executionType.toUpperCase()}): Bot ${config.botId}, ${action} ${config.market} @ ${price}, ID=${orderId}, Profit=${profit !== 0 ? profit.toFixed(4) : 'null'}`);
                return; // Sucesso - batch sera processado assincronamente
            } catch (error: any) {
                this.logger.warn(`s ${logScope} Erro ao adicionar ao batch (${orderId}), usando fallback HTTP: ${error.message}`);
                // Fallback para HTTP se batch falhar
            }
        } else {
            this.logger.warn(`s ${logScope} BatchOperations nao disponivel para bot ${config.botId}. Usando fallback HTTP.`);
        }

        // Fallback HTTP aguardado para garantir persistencia de historico em demo/real.
        try {
            await this.saveOperationAsync(data, orderId, executionType);
        } catch (error: any) {
            this.logger.error(`O Erro ao salvar operacao ${normalizedTradeType === 'future' ? 'MarketKingTrade/Future' : 'MarketKingTrade/Margin'} (${orderId}): ${error.message}`);
        }
    }

    /**
    * Salvamento assíncrono com retry e timeout (fallback HTTP quando batch indisponível).
    */
    private async saveOperationAsync(
        data: any,
        orderId: string,
        executionType: 'simulated' | 'real',
        retries: number = 3
    ): Promise<void> {
        for (let attempt = 1; attempt <= retries; attempt++) {
            try {
                await axios.post((String(process.env.CRONBOT_API_URL || process.env.APP_URL || 'https://cronbot.win').replace(/\/$/, '') + '/api/trading-operations'), data, { timeout: 5000 });
                this.logger.operation(`o. Operacao de trading salva em DB (${executionType.toUpperCase()}): ID=${orderId}`);
                return; // Sucesso, sai do loop
            } catch (error: any) {
                const isLastAttempt = attempt === retries;
                const isTimeout = error.code === 'ECONNABORTED' || error.message?.includes('timeout');

                if (isLastAttempt) {
                    throw error; // Re-lanca erro na ultima tentativa
                }

                // Otimização: Exponential backoff
                const delay = Math.min(1000 * Math.pow(2, attempt - 1), 5000); // 1s, 2s, 4s (max 5s)

                if (isTimeout) {
                    this.logger.warn(`s Timeout ao salvar operacao (${orderId}), tentativa ${attempt}/${retries}. Retry em ${delay}ms...`);
                } else {
                    this.logger.warn(`s Erro ao salvar operacao (${orderId}), tentativa ${attempt}/${retries}: ${error.message}. Retry em ${delay}ms...`);
                }

                await new Promise(resolve => setTimeout(resolve, delay));
            }
        }
    }

    private logIndicators(
        config: MarketKingTradingConfig,
        state: MarketKingTradingState,
        indicators: any,
        currentPrice: number,
        action: string
    ): void {
        const tradeTypeLower = String(config?.tradeType || '').toLowerCase();
        const isGridTelemetryTrade =
            tradeTypeLower === LEGACY_GRID_PROFILE_PREFIX
            || tradeTypeLower.includes(LEGACY_GRID_PROFILE_PREFIX)
            || tradeTypeLower === 'marketking'
            || tradeTypeLower === 'margintrade'
            || tradeTypeLower.includes('marketking');
        const indicatorLogIntervalMs = Math.max(
            15000,
            Number(config.runtimeIndicatorLogIntervalMs ?? config.decisionVerboseIntervalMs ?? 30000)
        );
        const now = Date.now();
        const shouldLogIndicator = (() => {
            if (action !== 'loading') return true;
            if (!isGridTelemetryTrade) return true;
            const lastTs = Number(state._lastGridIndicatorLogAt || 0);
            const normalizedLastTs = Number.isFinite(lastTs) && lastTs > 0 && lastTs <= (now + 60000) ? lastTs : 0;
            if (normalizedLastTs > 0 && (now - normalizedLastTs) < indicatorLogIntervalMs) {
                return false;
            }
            state._lastGridIndicatorLogAt = now;
            return true;
        })();
        if (!shouldLogIndicator) return;

        const adxValue = parseFloat(indicators.adx || '0');
        const rsiValue = parseFloat(indicators.rsi || '0');
        const emaMsg = state.lastEMA ? ` | EMA: ${state.lastEMA.toFixed(4)}` : '';
        const ADX_STRONG_TREND_LOG = config.adxStrongTrendThreshold ?? 25;
        const trendMsg = adxValue > ADX_STRONG_TREND_LOG ? 'FORTE' : 'FRACA';
        // o. Log diferenciado com indicacao de Demo vs Real (igual ao Spot)
        const modePrefix = this.isDemo ? 'YZ [DEMO]' : 'Y" [REAL]';
        const venueLabel = this.isFuturePerpetualTrade(config) ? 'FUTURE' : 'MARGIN';
        const typeLabel = isGridTelemetryTrade ? `[MARKETKING/${venueLabel}]` : `[${venueLabel}]`;
        const longOpen = Array.isArray(state.activePositions) ? state.activePositions.length : 0;
        const shortOpen = Array.isArray(state.shortPositions) ? state.shortPositions.length : 0;
        const gridMartingaleLong = Number(state.gridState?.long?.martingaleCount || 0);
        const gridMartingaleShort = Number(state.gridState?.short?.martingaleCount || 0);
        const gridRedLong = state.gridState?.long?.redActive ? 1 : 0;
        const gridRedShort = state.gridState?.short?.redActive ? 1 : 0;
        const realizedProfit = Number(state.totalProfit || 0);
        const marginLevel = Number(state.marginLevel || 0);
        const emaDeltaPct = state.lastEMA && state.lastEMA > 0
            ? (((currentPrice - state.lastEMA) / state.lastEMA) * 100)
            : 0;
        const { buy: rsiLogBuy, sell: rsiLogSell } = resolveIndicatorLogRsiThresholds(config);
        const granLabel = resolveIndicatorLogGranularity(config, indicators);

        // Log compacto de contexto; status detalhado sai em [MARKETKING][STATUS].
        this.logger.indicator(
            `${modePrefix} ${typeLabel} ${config.market} | ` +
                `P=${currentPrice.toFixed(4)}${emaMsg} dEMA=${emaDeltaPct.toFixed(3)}% | ` +
                `RSI=${rsiValue.toFixed(2)} [${rsiLogBuy}/${rsiLogSell}] G=${granLabel} | ADX=${adxValue.toFixed(2)} (${trendMsg}) | ` +
                `Lev=${config.leverage}x | Pos L/S=${longOpen}/${shortOpen}` +
                ` | NetReal=${realizedProfit.toFixed(4)} | ML=${Number.isFinite(marginLevel) ? marginLevel.toFixed(3) : '0.000'}x`
        );

        // o. Log de decisao estrategica ?' bots/
        if (action !== 'loading') {
            const actionLabel = action === 'buy' ? 'LONG' : action === 'sell' ? 'SHORT' : action.toUpperCase();
            this.logger.decision(`${modePrefix} ${typeLabel} >>> DECISfO ESTRAT?GICA: ${actionLabel} <<<`);
        }
    }
}

// Default export to improve interop with other modules
export default MarketKingTradingService;
