import assert from 'node:assert/strict';
import StockMarketStrategyEngine from './StockMarketStrategyEngine';

const engine = new StockMarketStrategyEngine();
assert.equal(engine.evaluate({ price: 100, ema: 99, emaPrev: 98, rsi: 55, atr: 1 }, {}).allowLong, true);
assert.equal(engine.evaluate({ price: 98, ema: 99, emaPrev: 100, rsi: 45, atr: 1 }, {}).allowShort, true);
assert.equal(engine.evaluate({ price: 100, ema: 100, emaPrev: 100, rsi: 50, atr: 1 }, {}).enabled, false);
assert.equal(engine.evaluate({ price: 100, ema: 99, emaPrev: 98, rsi: 55, atr: 1, spreadBps: 30 }, {}).enabled, false);
console.log('StockMarketStrategyEngine: OK');
