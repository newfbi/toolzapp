import assert from 'node:assert/strict';
import StockMarketTradingService from './StockMarketTradingService';

const service = new StockMarketTradingService('StockMarketSmoke', 1);
const state: any = { activePositions: [], shortPositions: [], totalProfit: 0, bankroll: 250 };
const config: any = {
    botId: 1, market: 'NVDAUSDT', exchange: 'bitget', opType: 'demo', amount: 30,
    leverage: 3, leverageDefault: 3, maxMarginUsedPct: 0.70,
    minNetUsdPerClose: 0.30, positiveNetCloseMinUsd: 0.30,
    stockMarketMaxOpenPositions: 2, makerFeeRate: 0.0002, defaultTakerFeeRate: 0.0006,
    makerSlippagePercent: 0, takerSlippagePercent: 0.0003,
};
const symbolInfo: any = { quantityStep: 0.001, minOrderQty: 0.001, minNotional: 5, priceStep: 0.01, makerFeeRate: 0.0002, takerFeeRate: 0.0006 };
const indicators: any = { lastClose: 100, ema: 99, emaPrev: 98, atr: 1, rsi: 55, spreadBps: 1 };
const first = await service.executeIteration({}, config, state, {}, symbolInfo, { last: 100 }, indicators);
assert.equal(first.openPositions, 1);
assert.equal(first.action, 'open_long');
const second = await service.executeIteration({}, config, state, {}, symbolInfo, { last: 101 }, { ...indicators, price: 101 });
assert.equal(Number(state.totalProfit) > 0, true);
console.log('StockMarket independent service: OK', first.action, second.action, state.totalProfit);
