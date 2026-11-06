export type StockMarketSide = 'long' | 'short';

export interface StockMarketDecision {
    enabled: boolean;
    allowLong: boolean;
    allowShort: boolean;
    reason: string;
    regime: 'trend_up' | 'trend_down' | 'range' | 'blocked';
}

/**
 * Stock/TradFi entry policy. This module owns the market interpretation;
 * exchange execution, fees and persistence remain outside the strategy.
 */
export class StockMarketStrategyEngine {
    evaluate(indicators: any, config: any): StockMarketDecision {
        const price = Number(indicators?.price ?? indicators?.close ?? indicators?.lastClose ?? 0);
        const ema = Number(indicators?.ema ?? NaN);
        const emaPrev = Number(indicators?.emaPrev ?? NaN);
        const rsi = Number(indicators?.rsi ?? NaN);
        const atr = Number(indicators?.atr ?? NaN);
        const spreadBps = Number(indicators?.spreadBps ?? 0);
        const maxSpreadBps = Number(config?.stockMarketMaxSpreadBps ?? 20);

        if (!(price > 0)) return this.block('invalid_price');
        if (Number.isFinite(spreadBps) && spreadBps > maxSpreadBps) return this.block('spread_too_wide');

        const slope = Number.isFinite(ema) && Number.isFinite(emaPrev) && Math.abs(emaPrev) > 1e-8
            ? (ema - emaPrev) / emaPrev
            : 0;
        const slopeMin = Number(config?.stockMarketMinEmaSlopePct ?? 0.00005);
        const trendUp = Number.isFinite(ema) && price >= ema && slope >= slopeMin;
        const trendDown = Number.isFinite(ema) && price <= ema && slope <= -slopeMin;
        const rangeRsiLong = Number.isFinite(rsi) && rsi <= Number(config?.stockMarketRangeRsiLong ?? 35);
        const rangeRsiShort = Number.isFinite(rsi) && rsi >= Number(config?.stockMarketRangeRsiShort ?? 65);
        const atrMinPct = Number(config?.stockMarketMinAtrPct ?? 0);
        const atrPct = Number.isFinite(atr) ? Math.abs(atr / price) : 0;
        if (atrMinPct > 0 && atrPct < atrMinPct) return this.block('atr_below_minimum');

        if (trendUp) return { enabled: true, allowLong: true, allowShort: false, reason: 'trend_up', regime: 'trend_up' };
        if (trendDown) return { enabled: true, allowLong: false, allowShort: true, reason: 'trend_down', regime: 'trend_down' };
        if (rangeRsiLong) return { enabled: true, allowLong: true, allowShort: false, reason: 'range_rsi_long', regime: 'range' };
        if (rangeRsiShort) return { enabled: true, allowLong: false, allowShort: true, reason: 'range_rsi_short', regime: 'range' };
        return this.block('no_directional_edge');
    }

    private block(reason: string): StockMarketDecision {
        return { enabled: false, allowLong: false, allowShort: false, reason, regime: 'blocked' };
    }
}

export default StockMarketStrategyEngine;
