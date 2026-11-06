import type { Candle } from '../exchange/types.ts';

function toNumberOrNull(value: any): number | null {
    const n = Number(value);
    return isFinite(n) ? n : null;
}

function normalizeBands(raw: any): { upperBand: number; lowerBand: number } | null {
    if (!raw || typeof raw !== 'object') return null;
    const upperBand = Number(raw.upperBand ?? raw.upper ?? NaN);
    const lowerBand = Number(raw.lowerBand ?? raw.lower ?? NaN);
    if (!isFinite(upperBand) || !isFinite(lowerBand)) return null;
    return { upperBand, lowerBand };
}

function computeRsiFromCloses(closes: number[], period: number): number | null {
    if (!Array.isArray(closes) || closes.length <= period) return null;
    let gains = 0;
    let losses = 0;
    for (let i = 1; i <= period; i++) {
        const delta = closes[i] - closes[i - 1];
        if (delta >= 0) gains += delta;
        else losses += Math.abs(delta);
    }
    let avgGain = gains / period;
    let avgLoss = losses / period;
    for (let i = period + 1; i < closes.length; i++) {
        const delta = closes[i] - closes[i - 1];
        const gain = delta > 0 ? delta : 0;
        const loss = delta < 0 ? Math.abs(delta) : 0;
        avgGain = ((avgGain * (period - 1)) + gain) / period;
        avgLoss = ((avgLoss * (period - 1)) + loss) / period;
    }
    if (avgLoss <= 0) return 100;
    const rs = avgGain / avgLoss;
    const rsi = 100 - (100 / (1 + rs));
    return isFinite(rsi) ? rsi : null;
}

function computeSma(closes: number[], period: number): number | null {
    if (!Array.isArray(closes) || closes.length < period || period <= 0) return null;
    const slice = closes.slice(closes.length - period);
    const sum = slice.reduce((acc, v) => acc + Number(v || 0), 0);
    const sma = sum / period;
    return isFinite(sma) ? sma : null;
}

function computeEmaTrail(closes: number[], period: number, emaFn?: any): { last: number | null; prev: number | null } {
    if (!Array.isArray(closes) || !closes.length || period <= 0) {
        return { last: null, prev: null };
    }
    if (typeof emaFn !== 'function') {
        const sma = computeSma(closes, Math.min(period, closes.length));
        return { last: sma, prev: sma };
    }

    let emaVal: number | null = null;
    const values: number[] = [];
    for (let i = Math.max(0, closes.length - 300); i < closes.length; i++) {
        emaVal = emaFn(closes[i], emaVal, period);
        if (isFinite(Number(emaVal))) values.push(Number(emaVal));
    }
    if (!values.length) return { last: null, prev: null };
    return {
        last: values[values.length - 1],
        prev: values.length >= 2 ? values[values.length - 2] : values[values.length - 1],
    };
}

function aggregateToM15Closes(window: Candle[], granularity: string): number[] {
    const gran = String(granularity || '').trim().toLowerCase();
    const match = gran.match(/^(\d+)\s*(m|min)$/i);
    const granMinutes = match ? Number(match[1]) : NaN;
    if (!Number.isFinite(granMinutes) || granMinutes <= 0 || granMinutes >= 15) {
        return window.map((c) => Number(c.close)).filter((v) => isFinite(v));
    }
    if (15 % granMinutes !== 0) {
        return window.map((c) => Number(c.close)).filter((v) => isFinite(v));
    }
    const bucketSize = Math.round(15 / granMinutes);
    if (bucketSize <= 1) {
        return window.map((c) => Number(c.close)).filter((v) => isFinite(v));
    }
    const closes: number[] = [];
    for (let i = bucketSize - 1; i < window.length; i += bucketSize) {
        const c = Number(window[i] && window[i].close);
        if (isFinite(c)) closes.push(c);
    }
    return closes;
}

function computeRsiSeries(closes: number[], period: number): Array<number | null> {
    const out: Array<number | null> = new Array(closes.length).fill(null);
    if (!Array.isArray(closes) || closes.length <= period || period <= 0) return out;
    let gains = 0;
    let losses = 0;
    for (let i = 1; i <= period; i++) {
        const delta = closes[i] - closes[i - 1];
        if (delta >= 0) gains += delta;
        else losses += Math.abs(delta);
    }
    let avgGain = gains / period;
    let avgLoss = losses / period;
    out[period] = avgLoss <= 0 ? 100 : (100 - (100 / (1 + (avgGain / avgLoss))));
    for (let i = period + 1; i < closes.length; i++) {
        const delta = closes[i] - closes[i - 1];
        const gain = delta > 0 ? delta : 0;
        const loss = delta < 0 ? Math.abs(delta) : 0;
        avgGain = ((avgGain * (period - 1)) + gain) / period;
        avgLoss = ((avgLoss * (period - 1)) + loss) / period;
        out[i] = avgLoss <= 0 ? 100 : (100 - (100 / (1 + (avgGain / avgLoss))));
    }
    return out.map((v) => (isFinite(Number(v)) ? Number(v) : null));
}

function computeMomentumSeries(closes: number[], period: number): Array<number | null> {
    const out: Array<number | null> = new Array(closes.length).fill(null);
    if (!Array.isArray(closes) || closes.length <= period || period <= 0) return out;
    for (let i = period; i < closes.length; i++) {
        const now = Number(closes[i]);
        const prev = Number(closes[i - period]);
        if (!isFinite(now) || !isFinite(prev)) continue;
        out[i] = now - prev;
    }
    return out;
}

function detectSyncedQuinaSignals(
    closes: number[],
    opts?: {
        momentumPeriod?: number;
        momentumRisePoints?: number;
        momentumDropPoints?: number;
        momentumRiseLookbackCandles?: number;
        momentumDropLookaheadCandles?: number;
        rsiPeriod?: number;
        rsiSellThreshold?: number;
        rsiBuyThreshold?: number;
        rsiReversalPoints?: number;
        syncToleranceCandles?: number;
    }
) {
    const momentumPeriod = Math.max(1, Number(opts?.momentumPeriod ?? 14));
    const momentumRisePoints = Number(opts?.momentumRisePoints ?? 22);
    const momentumDropPoints = Number(opts?.momentumDropPoints ?? 15);
    const momentumRiseLookbackCandles = Math.max(1, Number(opts?.momentumRiseLookbackCandles ?? 5));
    const momentumDropLookaheadCandles = Math.max(1, Number(opts?.momentumDropLookaheadCandles ?? 3));
    const rsiPeriod = Math.max(2, Number(opts?.rsiPeriod ?? 14));
    const rsiSellThreshold = Number(opts?.rsiSellThreshold ?? 68);
    const rsiBuyThreshold = Number(opts?.rsiBuyThreshold ?? 32);
    const rsiReversalPoints = Number(opts?.rsiReversalPoints ?? 8);
    const syncToleranceCandles = Math.max(0, Number(opts?.syncToleranceCandles ?? 2));

    const momentumSeries = computeMomentumSeries(closes, momentumPeriod);
    const rsiSeries = computeRsiSeries(closes, rsiPeriod);
    const lastIdx = closes.length - 1;
    if (lastIdx <= Math.max(momentumPeriod, rsiPeriod) + 2) {
        return {
            momentumNow: null as number | null,
            rsiNow: null as number | null,
            quinaSellSync: false,
            quinaBuySync: false
        };
    }

    const momentumSellPeaks: number[] = [];
    const momentumBuyTroughs: number[] = [];
    const rsiSellPeaks: number[] = [];
    const rsiBuyTroughs: number[] = [];

    const fromIdx = Math.max(Math.max(momentumPeriod, rsiPeriod), lastIdx - 40);
    const toIdx = Math.max(fromIdx, lastIdx - 1);
    const getNumber = (v: number | null) => (v === null || !isFinite(Number(v)) ? null : Number(v));

    for (let k = fromIdx; k <= toIdx; k++) {
        const mk = getNumber(momentumSeries[k]);
        const mkPrev = getNumber(momentumSeries[k - 1]);
        const mkNext = getNumber(momentumSeries[k + 1]);
        if (mk !== null && mkPrev !== null && mkNext !== null) {
            const isPeak = mk >= mkPrev && mk >= mkNext;
            const isTrough = mk <= mkPrev && mk <= mkNext;
            if (isPeak) {
                let riseOk = false;
                const riseFrom = Math.max(momentumPeriod, k - momentumRiseLookbackCandles);
                for (let s = riseFrom; s < k; s++) {
                    const ms = getNumber(momentumSeries[s]);
                    if (ms === null) continue;
                    if ((mk - ms) >= momentumRisePoints) {
                        riseOk = true;
                        break;
                    }
                }
                let dropOk = false;
                const dropTo = Math.min(lastIdx, k + momentumDropLookaheadCandles);
                for (let t = k + 1; t <= dropTo; t++) {
                    const mt = getNumber(momentumSeries[t]);
                    if (mt === null) continue;
                    if ((mk - mt) >= momentumDropPoints) {
                        dropOk = true;
                        break;
                    }
                }
                if (riseOk && dropOk) momentumSellPeaks.push(k);
            }
            if (isTrough) {
                let fallOk = false;
                const from = Math.max(momentumPeriod, k - momentumRiseLookbackCandles);
                for (let s = from; s < k; s++) {
                    const ms = getNumber(momentumSeries[s]);
                    if (ms === null) continue;
                    if ((ms - mk) >= momentumRisePoints) {
                        fallOk = true;
                        break;
                    }
                }
                let reboundOk = false;
                const reboundTo = Math.min(lastIdx, k + momentumDropLookaheadCandles);
                for (let t = k + 1; t <= reboundTo; t++) {
                    const mt = getNumber(momentumSeries[t]);
                    if (mt === null) continue;
                    if ((mt - mk) >= momentumDropPoints) {
                        reboundOk = true;
                        break;
                    }
                }
                if (fallOk && reboundOk) momentumBuyTroughs.push(k);
            }
        }

        const rk = getNumber(rsiSeries[k]);
        const rkPrev = getNumber(rsiSeries[k - 1]);
        const rkNext = getNumber(rsiSeries[k + 1]);
        if (rk !== null && rkPrev !== null && rkNext !== null) {
            const rsiPeak = rk >= rkPrev && rk >= rkNext;
            const rsiTrough = rk <= rkPrev && rk <= rkNext;
            if (rsiPeak && rk >= rsiSellThreshold) {
                const dropTo = Math.min(lastIdx, k + momentumDropLookaheadCandles);
                let dropOk = false;
                for (let t = k + 1; t <= dropTo; t++) {
                    const rt = getNumber(rsiSeries[t]);
                    if (rt === null) continue;
                    if ((rk - rt) >= rsiReversalPoints) {
                        dropOk = true;
                        break;
                    }
                }
                if (dropOk) rsiSellPeaks.push(k);
            }
            if (rsiTrough && rk <= rsiBuyThreshold) {
                const riseTo = Math.min(lastIdx, k + momentumDropLookaheadCandles);
                let riseOk = false;
                for (let t = k + 1; t <= riseTo; t++) {
                    const rt = getNumber(rsiSeries[t]);
                    if (rt === null) continue;
                    if ((rt - rk) >= rsiReversalPoints) {
                        riseOk = true;
                        break;
                    }
                }
                if (riseOk) rsiBuyTroughs.push(k);
            }
        }
    }

    const hasSync = (a: number[], b: number[]) => a.some((x) => b.some((y) => Math.abs(x - y) <= syncToleranceCandles));
    return {
        momentumNow: getNumber(momentumSeries[lastIdx]),
        rsiNow: getNumber(rsiSeries[lastIdx]),
        quinaSellSync: hasSync(momentumSellPeaks, rsiSellPeaks),
        quinaBuySync: hasSync(momentumBuyTroughs, rsiBuyTroughs),
    };
}

function calculateAtrSimpleSeries(candles: Candle[], period: number): Array<number | null> {
    const out: Array<number | null> = new Array(candles.length).fill(null);
    if (!Array.isArray(candles) || candles.length < period + 1 || period <= 0) return out;

    const tr: Array<number | null> = new Array(candles.length).fill(null);
    for (let i = 1; i < candles.length; i++) {
        const high = Number(candles[i].high);
        const low = Number(candles[i].low);
        const prevClose = Number(candles[i - 1].close);
        if (!isFinite(high) || !isFinite(low) || !isFinite(prevClose)) continue;
        tr[i] = Math.max(
            high - low,
            Math.abs(high - prevClose),
            Math.abs(low - prevClose)
        );
    }

    for (let i = period; i < candles.length; i++) {
        let sum = 0;
        let count = 0;
        for (let k = i - period + 1; k <= i; k++) {
            const v = tr[k];
            if (v === null) continue;
            sum += v;
            count += 1;
        }
        if (count === period) out[i] = sum / period;
    }

    return out;
}

function averageWindow(
    series: Array<number | null>,
    endIndex: number,
    lookback: number
): number | null {
    if (!Array.isArray(series) || series.length === 0 || lookback <= 0 || endIndex < 0) return null;
    const from = Math.max(0, endIndex - lookback + 1);
    let sum = 0;
    let count = 0;
    for (let i = from; i <= endIndex && i < series.length; i++) {
        const v = series[i];
        if (v === null || !isFinite(v)) continue;
        sum += v;
        count += 1;
    }
    if (count <= 0) return null;
    return sum / count;
}

export interface IndicatorSliceInput {
    candles: Candle[];
    index: number;
    strategies: any;
    rsiPeriod?: number;
    mgQuinaMomentumPeriod?: number;
    mgQuinaMomentumRisePoints?: number;
    mgQuinaMomentumDropPoints?: number;
    mgQuinaMomentumRiseLookbackCandles?: number;
    mgQuinaMomentumDropLookaheadCandles?: number;
    mgQuinaRsiPeriod?: number;
    mgQuinaRsiSellThreshold?: number;
    mgQuinaRsiBuyThreshold?: number;
    mgQuinaRsiReversalPoints?: number;
    mgQuinaSyncToleranceCandles?: number;
    emaPeriod?: number;
    granularity?: string;
}

export async function computeIndicatorsSlice(input: IndicatorSliceInput) {
    const {
        candles,
        index,
        strategies,
        rsiPeriod = 14,
        mgQuinaMomentumPeriod = 14,
        mgQuinaMomentumRisePoints = 22,
        mgQuinaMomentumDropPoints = 15,
        mgQuinaMomentumRiseLookbackCandles = 5,
        mgQuinaMomentumDropLookaheadCandles = 3,
        mgQuinaRsiPeriod = 14,
        mgQuinaRsiSellThreshold = 68,
        mgQuinaRsiBuyThreshold = 32,
        mgQuinaRsiReversalPoints = 8,
        mgQuinaSyncToleranceCandles = 2,
        emaPeriod = 8,
        granularity = '5m'
    } = input;

    const sliceStart = Math.max(0, index - Math.max(rsiPeriod, emaPeriod) - 50);
    const window = candles.slice(sliceStart, index + 1);
    const closingPrices = window.map((c) => Number(c.close));
    const lastCandle = window.length ? window[window.length - 1] : null;
    const prevCandle = window.length >= 2 ? window[window.length - 2] : null;
    const rawForOhlc = window.map((c) => [
        String(c.ts),
        String(c.open),
        String(c.high),
        String(c.low),
        String(c.close),
        String(c.volume),
    ]);
    const volumeSeries = window
        .map((c) => Number(c.volume))
        .filter((v) => isFinite(v) && v >= 0);
    const volumeCurrent = volumeSeries.length ? volumeSeries[volumeSeries.length - 1] : null;
    const volumeLookback = Math.min(30, Math.max(1, volumeSeries.length - 1));
    const volumeBaseline = volumeSeries
        .slice(Math.max(0, volumeSeries.length - 1 - volumeLookback), volumeSeries.length - 1)
        .filter((v) => v > 0);
    const volumeAverage = volumeBaseline.length
        ? volumeBaseline.reduce((sum, v) => sum + v, 0) / volumeBaseline.length
        : null;
    const volumeRatio = (volumeCurrent !== null && volumeAverage !== null && volumeAverage > 0)
        ? (volumeCurrent / volumeAverage)
        : null;
    const atrSeriesWindow = calculateAtrSimpleSeries(window, 14);
    const atrAverage30 = averageWindow(atrSeriesWindow, atrSeriesWindow.length - 1, 30);
    const rsiSeriesWindow = computeRsiSeries(closingPrices, rsiPeriod);
    const quina = detectSyncedQuinaSignals(closingPrices, {
        momentumPeriod: mgQuinaMomentumPeriod,
        momentumRisePoints: mgQuinaMomentumRisePoints,
        momentumDropPoints: mgQuinaMomentumDropPoints,
        momentumRiseLookbackCandles: mgQuinaMomentumRiseLookbackCandles,
        momentumDropLookaheadCandles: mgQuinaMomentumDropLookaheadCandles,
        rsiPeriod: mgQuinaRsiPeriod,
        rsiSellThreshold: mgQuinaRsiSellThreshold,
        rsiBuyThreshold: mgQuinaRsiBuyThreshold,
        rsiReversalPoints: mgQuinaRsiReversalPoints,
        syncToleranceCandles: mgQuinaSyncToleranceCandles,
    });
    const lastOpen = lastCandle ? Number(lastCandle.open) : null;
    const lastHigh = lastCandle ? Number(lastCandle.high) : null;
    const lastLow = lastCandle ? Number(lastCandle.low) : null;
    const lastClose = lastCandle ? Number(lastCandle.close) : null;
    const prevClose = prevCandle ? Number(prevCandle.close) : null;
    const candleRange = (
        lastHigh !== null
        && lastLow !== null
        && isFinite(lastHigh)
        && isFinite(lastLow)
    ) ? Math.max(0, lastHigh - lastLow) : 0;
    const candleBody = (
        lastOpen !== null
        && lastClose !== null
        && isFinite(lastOpen)
        && isFinite(lastClose)
    ) ? Math.abs(lastClose - lastOpen) : 0;
    const upperWick = (
        lastHigh !== null
        && lastOpen !== null
        && lastClose !== null
        && isFinite(lastHigh)
        && isFinite(lastOpen)
        && isFinite(lastClose)
    ) ? Math.max(0, lastHigh - Math.max(lastOpen, lastClose)) : 0;
    const lowerWick = (
        lastLow !== null
        && lastOpen !== null
        && lastClose !== null
        && isFinite(lastLow)
        && isFinite(lastOpen)
        && isFinite(lastClose)
    ) ? Math.max(0, Math.min(lastOpen, lastClose) - lastLow) : 0;
    const lastCloseLocationInRange = candleRange > 1e-8 && lastClose !== null && lastLow !== null
        ? ((lastClose - lastLow) / candleRange)
        : null;
    const candleMeta = {
        rsiPrev: toNumberOrNull(rsiSeriesWindow.length >= 2 ? rsiSeriesWindow[rsiSeriesWindow.length - 2] : null),
        lastOpen: toNumberOrNull(lastOpen),
        lastHigh: toNumberOrNull(lastHigh),
        lastLow: toNumberOrNull(lastLow),
        lastClose: toNumberOrNull(lastClose),
        prevClose: toNumberOrNull(prevClose),
        lastCloseLocationInRange: toNumberOrNull(lastCloseLocationInRange),
        lastBodyPctOfRange: toNumberOrNull(candleRange > 1e-8 ? (candleBody / candleRange) : null),
        lastUpperWickPctOfRange: toNumberOrNull(candleRange > 1e-8 ? (upperWick / candleRange) : null),
        lastLowerWickPctOfRange: toNumberOrNull(candleRange > 1e-8 ? (lowerWick / candleRange) : null),
    };

    const rsiFn: any = strategies?.calculateRSI || null;
    const emaFn: any = strategies?.calculateEMA || null;
    const emaBase = computeEmaTrail(closingPrices, emaPeriod, emaFn);
    const ema20Trail = computeEmaTrail(closingPrices, 20, emaFn);
    const ema50Trail = computeEmaTrail(closingPrices, 50, emaFn);
    const lastEMA = emaBase.last;
    const prevEMA = emaBase.prev;

    const getStrategyFn: any = strategies?.getStrategy || null;
    if (typeof getStrategyFn === 'function') {
        try {
            const strategyIndicators = await getStrategyFn(
                { candles: rawForOhlc },
                'backtest',
                '',
                rsiPeriod,
                granularity,
                Math.max(window.length, rsiPeriod + 5),
                rawForOhlc
            );
            if (strategyIndicators && typeof strategyIndicators === 'object') {
                const ma50 = computeSma(closingPrices, 50);
                const lastCloseValue = closingPrices.length ? Number(closingPrices[closingPrices.length - 1]) : NaN;
                const ma50DistancePct = (isFinite(lastCloseValue) && isFinite(Number(ma50)) && Number(ma50) > 0)
                    ? ((lastCloseValue - Number(ma50)) / Number(ma50)) * 100
                    : null;
                const closesM15 = aggregateToM15Closes(window, granularity);
                const rsiM15 = computeRsiFromCloses(closesM15, rsiPeriod);
                return {
                    rsi: Number(strategyIndicators.rsi ?? NaN),
                    rsiM15: toNumberOrNull(rsiM15),
                    momentum14: toNumberOrNull(quina.momentumNow),
                    mgQuinaSyncSell: !!quina.quinaSellSync,
                    mgQuinaSyncBuy: !!quina.quinaBuySync,
                    ema: lastEMA,
                    emaPrev: prevEMA,
                    ema20: ema20Trail.last,
                    ema20Prev: ema20Trail.prev,
                    ema50: ema50Trail.last,
                    ema50Prev: ema50Trail.prev,
                    ma50: toNumberOrNull(ma50),
                    ma50DistancePct: toNumberOrNull(ma50DistancePct),
                    atr: toNumberOrNull(strategyIndicators.atr),
                    atrAverage: toNumberOrNull(strategyIndicators.atrAverage),
                    atrAverage30: toNumberOrNull(atrAverage30),
                    macd: strategyIndicators.macd ?? null,
                    adx: toNumberOrNull(strategyIndicators.adx),
                    bollingerBands: normalizeBands(strategyIndicators.bollingerBands),
                    vwma: toNumberOrNull(strategyIndicators.vwma),
                    stochastic: strategyIndicators.stochastic ?? null,
                    volumeCurrent: toNumberOrNull(strategyIndicators.volumeCurrent ?? volumeCurrent),
                    volumeAverage: toNumberOrNull(strategyIndicators.volumeAverage ?? volumeAverage),
                    volumeRatio: toNumberOrNull(strategyIndicators.volumeRatio ?? volumeRatio),
                    ...candleMeta,
                };
            }
        } catch (_) {
            // fallback to local calculations below
        }
    }

    const rsi = rsiFn ? parseFloat(rsiFn(closingPrices, rsiPeriod)) : NaN;
    const closesM15 = aggregateToM15Closes(window, granularity);
    const rsiM15 = computeRsiFromCloses(closesM15, rsiPeriod);
    const ma50 = computeSma(closingPrices, 50);
    const lastCloseValue = closingPrices.length ? Number(closingPrices[closingPrices.length - 1]) : NaN;
    const ma50DistancePct = (isFinite(lastCloseValue) && isFinite(Number(ma50)) && Number(ma50) > 0)
        ? ((lastCloseValue - Number(ma50)) / Number(ma50)) * 100
        : null;
    const atrFn: any = strategies?.calculateATR || null;
    let atr: number | null = null;
    if (atrFn && window.length > 0) {
        const atrRaw = atrFn(rawForOhlc, 14);
        const atrNum = Number(atrRaw);
        atr = isFinite(atrNum) ? atrNum : null;
    }

    const macdFn: any = strategies?.calculateMACD || null;
    let macd: any = null;
    if (macdFn && closingPrices.length) {
        macd = macdFn(closingPrices, 8, 21, 6);
    }

    const adxFn: any = strategies?.calculateADX || null;
    let adx: number | null = null;
    if (adxFn && rawForOhlc.length >= 20) {
        const adxRaw = adxFn(rawForOhlc, 14);
        const adxNum = Number(adxRaw && typeof adxRaw === 'object' ? adxRaw.adx : adxRaw);
        adx = isFinite(adxNum) ? adxNum : null;
    }

    const bbFn: any = strategies?.calculateBollingerBands || null;
    let bollingerBands: { upperBand: number; lowerBand: number } | null = null;
    if (bbFn && closingPrices.length >= 20) {
        const bbRaw = bbFn(closingPrices, 20, 2);
        if (bbRaw && typeof bbRaw === 'object') {
            const upperBand = Number(bbRaw.upperBand ?? bbRaw.upper ?? NaN);
            const lowerBand = Number(bbRaw.lowerBand ?? bbRaw.lower ?? NaN);
            if (isFinite(upperBand) && isFinite(lowerBand)) {
                bollingerBands = { upperBand, lowerBand };
            }
        }
    }

    let vwma: number | null = null;
    try {
        const vols = window.map((c) => Number(c.volume) || 0);
        const prs = window.map((c) => Number(c.close) || 0);
        const totVol = vols.reduce((s, v) => s + v, 0);
        if (totVol > 0) {
            const sum = prs.reduce((s, p, i) => s + (p * vols[i]), 0);
            vwma = sum / totVol;
        }
    } catch (_) {
        vwma = null;
    }

    return {
        rsi,
        rsiM15,
        momentum14: toNumberOrNull(quina.momentumNow),
        mgQuinaSyncSell: !!quina.quinaSellSync,
        mgQuinaSyncBuy: !!quina.quinaBuySync,
        ema: lastEMA,
        emaPrev: prevEMA,
        ema20: ema20Trail.last,
        ema20Prev: ema20Trail.prev,
        ema50: ema50Trail.last,
        ema50Prev: ema50Trail.prev,
        ma50,
        ma50DistancePct,
        atr,
        macd,
        adx,
        bollingerBands,
        vwma,
        stochastic: null,
        atrAverage: null,
        atrAverage30: toNumberOrNull(atrAverage30),
        volumeCurrent,
        volumeAverage,
        volumeRatio,
        ...candleMeta,
    };
}
